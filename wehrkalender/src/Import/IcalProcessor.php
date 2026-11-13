<?php

declare(strict_types=1);

namespace Wehrkalender\Import;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;
use Wehrkalender\App;
use Wehrkalender\Calendar\FeedFilter;
use Wehrkalender\Clock;

/**
 * Verarbeitung externer Termine (iCal-Feeds und hochgeladene Dateien).
 *
 *   parse()  iCal lesen, Serien auflösen, in das interne Format bringen
 *   plan()   je Befüllung filtern und mit dem Bestand abgleichen (neu/geändert/entfernt),
 *            Plausibilität prüfen – ändert nichts (Vorschau)
 *   apply()  Plan übernehmen (EventStore, mit Sync-Protokoll)
 *
 * Kennung eines Termins: Hash aus UID (+ RECURRENCE-ID) bzw. – wenn die Quelle
 * instabile UIDs hat – aus Beginn, Ende und Titel, ergänzt um den Kalender
 * („-c<id>“). So kann ein Termin in mehreren Kalendern stehen, in einem Kalender
 * aber nur einmal. UID auf den Geräten: <quelle>-<kennung>@<domain>.
 *
 * Gelöscht wird je Befüllung (und bei Uploads je Datei): Termine dieser
 * Befüllung, die nicht mehr vorkommen. Andere Quellen und Dateien bleiben unberührt.
 */
final class IcalProcessor
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_EVENTS = 5000;
    /** Fenster für die Auflösung von Serien */
    public const EXPAND_PAST = '-12 months';
    public const EXPAND_FUTURE = '+18 months';
    /** Ab so vielen bisherigen Treffern gilt ein Wegfall von mehr als der Hälfte als verdächtig */
    private const PLAUSIBLE_MIN = 10;

    public function __construct(private App $app)
    {
    }

    /**
     * @return list<array{uid:string,recurrence:?string,summary:string,location:?string,description:?string,
     *                    categories:string,start:DateTimeImmutable,end:DateTimeImmutable,all_day:bool,cancelled:bool}>
     * @throws IcalError
     */
    public function parse(string $ics, int $maxBytes = self::MAX_BYTES): array
    {
        if (strlen($ics) > $maxBytes) {
            throw new IcalError(sprintf('Die Datei ist zu groß (höchstens %d MB).', intdiv($maxBytes, 1024 * 1024)));
        }
        if (trim($ics) === '') {
            throw new IcalError('Die Datei ist leer.');
        }
        if (!str_contains(substr($ics, 0, 2048), 'BEGIN:VCALENDAR')) {
            throw new IcalError(stripos($ics, '<html') !== false
                ? 'Statt eines Kalenders kam eine Webseite zurück (Fehlerseite oder Anmeldung?).'
                : 'Das ist keine iCal-Datei (BEGIN:VCALENDAR fehlt).');
        }
        try {
            $cal = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            throw new IcalError('Die iCal-Datei ist fehlerhaft: ' . $e->getMessage());
        }
        if (!$cal instanceof VCalendar) {
            throw new IcalError('Das ist keine iCal-Datei.');
        }
        $hasSeries = false;
        foreach ($cal->select('VEVENT') as $ev) {
            if (isset($ev->RRULE) || isset($ev->RDATE)) {
                $hasSeries = true;
                break;
            }
        }
        if ($hasSeries) {
            $now = $this->app->clock()->now();
            try {
                $cal = $cal->expand(
                    $now->modify(self::EXPAND_PAST)->setTimezone(new DateTimeZone('UTC')),
                    $now->modify(self::EXPAND_FUTURE)->setTimezone(new DateTimeZone('UTC')),
                    Clock::localTz()
                );
            } catch (Throwable $e) {
                throw new IcalError('Die Serientermine lassen sich nicht auflösen: ' . $e->getMessage());
            }
        }
        $tz = Clock::localTz();
        $events = [];
        foreach ($cal->select('VEVENT') as $ev) {
            /** @var VEvent $ev */
            if (!isset($ev->DTSTART)) {
                continue;
            }
            if (count($events) >= self::MAX_EVENTS) {
                throw new IcalError(sprintf('Die Datei enthält mehr als %d Termine.', self::MAX_EVENTS));
            }
            $allDay = !$ev->DTSTART->hasTime();
            $start = DateTimeImmutable::createFromInterface($ev->DTSTART->getDateTime($tz))->setTimezone($tz);
            if (isset($ev->DTEND)) {
                $end = DateTimeImmutable::createFromInterface($ev->DTEND->getDateTime($tz))->setTimezone($tz);
            } elseif (isset($ev->DURATION)) {
                $end = $start->add($ev->DURATION->getDateInterval());
            } else {
                $end = $allDay ? $start->modify('+1 day') : $start;
            }
            if ($allDay) {
                // iCal: DTEND ist bei ganztägigen Terminen der Tag danach; intern der letzte Tag
                $end = $end->modify('-1 day');
                if ($end < $start) {
                    $end = $start;
                }
            }
            $categories = [];
            foreach ($ev->select('CATEGORIES') as $c) {
                foreach ($c->getParts() as $part) {
                    $categories[] = trim((string) $part);
                }
            }
            $events[] = [
                'uid' => isset($ev->UID) ? trim((string) $ev->UID) : '',
                'recurrence' => isset($ev->{'RECURRENCE-ID'})
                    ? $ev->{'RECURRENCE-ID'}->getDateTime(new DateTimeZone('UTC'))->format('Ymd\THis\Z') : null,
                'summary' => trim((string) ($ev->SUMMARY ?? '')),
                'location' => isset($ev->LOCATION) ? trim((string) $ev->LOCATION) : null,
                'description' => isset($ev->DESCRIPTION) ? trim(str_replace("\r\n", "\n", (string) $ev->DESCRIPTION)) : null,
                'categories' => implode(', ', array_filter($categories, fn ($c) => $c !== '')),
                'start' => $start,
                'end' => $end,
                'all_day' => $allDay,
                'cancelled' => strtoupper((string) ($ev->STATUS ?? '')) === 'CANCELLED',
            ];
        }
        usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $events;
    }

    /**
     * Abgleich einer Quelle (bzw. einer hochgeladenen Datei) mit dem Bestand. Ändert nichts.
     *
     * @param array<string,mixed> $source Zeile aus sources
     * @param list<array<string,mixed>> $events Ergebnis von parse()
     * @param int|null $fileId Datei, deren Termine ersetzt werden (Upload); null bei Feeds
     * @return array<string,mixed>
     */
    public function plan(array $source, array $events, ?int $fileId = null): array
    {
        $db = $this->app->db();
        $feeds = $db->all(
            'SELECT f.*, c.display_name FROM calendar_feeds f JOIN calendars c ON c.id = f.calendar_id
              WHERE f.source_id = ? AND f.active = 1 ORDER BY f.id',
            [$source['id']]
        );
        $plan = [
            'source' => $source,
            'file_id' => $fileId,
            'events_total' => count($events),
            'first_start' => $events === [] ? null : min(array_map(fn ($e) => $e['start'], $events)),
            'last_end' => $events === [] ? null : max(array_map(fn ($e) => $e['end'], $events)),
            'feeds' => [],
            'warnings' => [],
            'unmatched' => [],
            'unmatched_count' => 0,
        ];
        if ($feeds === []) {
            $plan['warnings'][] = 'Diese Quelle befüllt noch keinen Kalender. Legen Sie zuerst eine Befüllung an.';
        }
        $hit = [];      // Index des Termins => von mindestens einer Befüllung getroffen
        $claimed = [];  // Kalender-ID => [Kennung => true]: ein Termin je Kalender nur einmal
        $today = $this->app->clock()->now()->format('d.m.Y');
        foreach ($feeds as $feed) {
            $filter = FeedFilter::fromJson($feed['filter']);
            $calId = (int) $feed['calendar_id'];
            $existing = [];
            $sql = 'SELECT external_id, summary, starts_at, source_hash, description, file_id FROM events WHERE source_id = ? AND feed_id = ?';
            $params = [$source['id'], $feed['id']];
            if ($fileId !== null) {
                $sql .= ' AND file_id = ?';
                $params[] = $fileId;
            }
            foreach ($db->all($sql, $params) as $r) {
                $existing[$r['external_id']] = $r;
            }
            $p = ['feed' => $feed, 'calendar' => $feed['display_name'], 'matched' => 0, 'save' => [],
                  'added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0, 'skipped_other_file' => 0,
                  // Vergleichswert: bei Dateien die bisherigen Termine dieser Datei (eine weitere Datei
                  // beginnt bei 0), bei Feeds die Treffer des letzten Abrufs
                  'previous' => $fileId !== null ? count($existing) : ($feed['last_match_count'] !== null ? (int) $feed['last_match_count'] : null),
                  'previous_scope' => $fileId !== null ? 'file' : 'feed', 'blocked' => null];
            foreach ($events as $i => $e) {
                if (!$filter->matches($e)) {
                    continue;
                }
                $hit[$i] = true;
                $p['matched']++;
                $base = (int) $source['id_from_content'] === 1 || $e['uid'] === ''
                    ? 'k' . substr(hash('sha256', $e['start']->format('c') . '|' . $e['end']->format('c') . '|' . mb_strtolower($e['summary'])), 0, 23)
                    : substr(hash('sha256', $e['uid'] . '|' . ($e['recurrence'] ?? '')), 0, 24);
                $externalId = $base . '-c' . $calId;
                if (isset($claimed[$calId][$externalId])) {
                    continue;
                }
                $claimed[$calId][$externalId] = true;
                $raw = hash('sha256', json_encode([
                    $e['summary'], $e['location'], $e['description'], $e['start']->format('c'), $e['end']->format('c'),
                    $e['all_day'], $e['cancelled'], (int) $feed['source_note'], (int) $feed['show_stand'], $source['name'],
                ], JSON_THROW_ON_ERROR));
                $old = $existing[$externalId] ?? null;
                unset($existing[$externalId]);
                if ($old === null && $fileId !== null) {
                    // in einer anderen Datei derselben Quelle schon vorhanden?
                    $other = $db->value('SELECT file_id FROM events WHERE source_id = ? AND external_id = ?', [$source['id'], $externalId]);
                    if ($other !== null && (int) $other !== $fileId) {
                        $p['skipped_other_file']++;
                        continue;
                    }
                }
                if ($old !== null && $old['source_hash'] === $raw) {
                    $p['unchanged']++;
                    continue;
                }
                $note = [];
                if ((int) $feed['source_note'] === 1) {
                    $note[] = 'Quelle: ' . $source['name'];
                }
                if ((int) $feed['show_stand'] === 1) {
                    $note[] = 'Stand ' . $today;
                }
                $description = trim(($e['description'] ?? '') . ($note !== [] ? "\n\n" . implode(' · ', $note) : ''));
                $p['save'][] = [
                    'source' => $source['source_key'], 'external_id' => $externalId, 'feed_id' => (int) $feed['id'],
                    'file_id' => $fileId, 'source_hash' => $raw, 'calendar_id' => $calId,
                    'summary' => $e['summary'], 'location' => $e['location'], 'description' => $description,
                    'start' => $e['start'], 'end' => $e['end'], 'all_day' => $e['all_day'], 'cancelled' => $e['cancelled'],
                ];
                $p[$old === null ? 'added' : 'changed'][] = $this->label($e);
            }
            foreach ($existing as $externalId => $r) {
                $p['removed'][] = ['external_id' => (string) $externalId,
                    'label' => Clock::toLocal($r['starts_at'])?->format('d.m.Y') . ' ' . $r['summary']];
            }
            // Plausibilität: Filter trifft plötzlich nichts mehr bzw. mehr als die Hälfte fällt weg
            $before = $p['matched'] - count($p['added']) + count($p['removed']);
            if ($p['matched'] === 0 && $before > 0) {
                $p['blocked'] = "Der Filter trifft keinen Termin mehr (bisher {$before}). Hat der Anbieter seine Titel geändert?";
            } elseif ($before >= self::PLAUSIBLE_MIN && count($p['removed']) > $before / 2) {
                $p['blocked'] = sprintf('%d von %d Terminen würden entfernt.', count($p['removed']), $before);
            }
            if ($p['skipped_other_file'] > 0) {
                $plan['warnings'][] = sprintf('%s: %d Termine stehen schon in einer anderen Datei dieser Quelle und werden übersprungen.',
                    $feed['display_name'], $p['skipped_other_file']);
            }
            $plan['feeds'][(int) $feed['id']] = $p;
        }
        // Termine, die keine Befüllung trifft (nur Anzeige in der Vorschau)
        $plan['unmatched'] = [];
        if ($feeds !== []) {
            $rest = array_values(array_filter($events, fn ($e, $i) => !isset($hit[$i]), ARRAY_FILTER_USE_BOTH));
            $plan['unmatched'] = self::groupByTitle($rest);
            $plan['unmatched_count'] = count($rest);
        }
        return $plan;
    }

    /**
     * Plan übernehmen. Befüllungen mit Plausibilitätsproblem werden ausgelassen, außer $force.
     *
     * @param array<string,mixed> $plan
     * @return array{added:int,changed:int,removed:int,unchanged:int,blocked:list<string>}
     */
    public function apply(array $plan, bool $force = false, ?int $fileId = null): array
    {
        $events = $this->app->events();
        $db = $this->app->db();
        $stats = ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0, 'blocked' => []];
        foreach ($plan['feeds'] as $feedId => $p) {
            if ($p['blocked'] !== null && !$force) {
                $stats['blocked'][] = $p['calendar'] . ': ' . $p['blocked'];
                continue;
            }
            foreach ($p['removed'] as $r) {
                $stats['removed'] += $events->delete($r['external_id'], (string) $plan['source']['source_key']) ? 1 : 0;
            }
            foreach ($p['save'] as $data) {
                if ($fileId !== null) {
                    $data['file_id'] = $fileId;
                }
                $result = $events->save($data);
                $stats[$result === 'added' ? 'added' : ($result === 'modified' ? 'changed' : 'unchanged')]++;
            }
            $stats['unchanged'] += $p['unchanged'];
            // bei Dateien: Treffer über alle Dateien der Quelle (sonst stünde nach einer weiteren
            // Datei ohne Treffer „zuletzt 0“, obwohl der Kalender Termine aus der anderen Datei hat)
            $count = $fileId !== null
                ? (int) $db->value('SELECT COUNT(*) FROM events WHERE feed_id = ?', [$feedId])
                : $p['matched'];
            $db->update('calendar_feeds', ['last_match_count' => $count], ['id' => $feedId]);
        }
        return $stats;
    }

    /**
     * Titel ohne Zählung am Ende: „Maschinist 2/2027 – Tag 1/4“ → „Maschinist 2/2027“,
     * ebenso „– Teil 2“, „Termin 3“, „Block 1/2“, „(3/5)“. Eine Lehrgangsnummer wie „2/2027“
     * ohne vorangestelltes Wort bleibt erhalten.
     */
    public static function baseTitle(string $summary): string
    {
        $t = preg_replace('/\s*[–—-]?\s*(?:(?:Tag|Teil|Termin|Block|Abschnitt)\s+\d+(?:\s*\/\s*\d+)?|\(\d+\s*\/\s*\d+\))\s*$/iu', '', $summary);
        $t = trim((string) $t);
        return $t === '' ? trim($summary) : $t;
    }

    /**
     * Termine nach Titel (ohne Zählung) gruppieren, Gruppen alphabetisch mit natürlicher
     * Zahlenfolge (1/2027 vor 10/2027), innerhalb einer Gruppe nach Beginn.
     *
     * @param list<array<string,mixed>> $events
     * @return list<array{title:string,count:int,first:\DateTimeImmutable,last:\DateTimeImmutable,items:list<array{when:string,summary:string,location:?string}>}>
     */
    public static function groupByTitle(array $events): array
    {
        $groups = [];
        usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);
        foreach ($events as $e) {
            $title = self::baseTitle((string) $e['summary']);
            $key = mb_strtolower($title);
            $groups[$key] ??= ['title' => $title, 'count' => 0, 'first' => $e['start'], 'last' => $e['end'], 'items' => []];
            $g = &$groups[$key];
            $g['count']++;
            $g['last'] = max($g['last'], $e['end']);
            $g['items'][] = [
                'when' => $e['start']->format($e['all_day'] ? 'd.m.Y' : 'd.m.Y H:i'),
                'summary' => (string) $e['summary'],
                'location' => $e['location'] !== null && $e['location'] !== '' ? (string) $e['location'] : null,
            ];
            unset($g);
        }
        uasort($groups, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return array_values($groups);
    }

    /** @param array<string,mixed> $e */
    private function label(array $e): string
    {
        return $e['start']->format($e['all_day'] ? 'd.m.Y' : 'd.m.Y H:i') . ' ' . $e['summary'];
    }
}
