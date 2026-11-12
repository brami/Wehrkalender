<?php

declare(strict_types=1);

namespace Wehrkalender\Dav;

use DateTimeInterface;
use Wehrkalender\Clock;
use Wehrkalender\Database\Db;
use Wehrkalender\Domain\CalendarChoice;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;

/**
 * Nur lesendes Kalender-Backend auf Basis der Tabellen calendars,
 * user_calendars (nur eingeschaltete Kalender, siehe CalendarChoice), events und calendar_changes.
 */
final class CalendarBackend extends AbstractBackend implements SyncSupport
{
    private const READ_ONLY = 'Die Kalender sind schreibgeschützt. Termine werden nur in MP Feuer gepflegt.';

    public function __construct(private Db $db)
    {
    }

    public function getCalendarsForUser($principalUri)
    {
        $principalUri = trim((string) $principalUri, '/');
        if (!str_starts_with($principalUri, 'principals/')) {
            return [];
        }
        $username = substr($principalUri, strlen('principals/'));
        $rows = $this->db->all(
            "SELECT c.* FROM calendars c
               JOIN user_calendars uc ON uc.calendar_id = c.id
               JOIN users u ON u.id = uc.user_id
              WHERE u.username = ? AND u.status = 'active' AND c.active = 1
                AND " . CalendarChoice::enabledSql() . "
              ORDER BY c.display_name",
            [$username]
        );
        $calendars = [];
        foreach ($rows as $i => $c) {
            $calendars[] = [
                'id' => (int) $c['id'],
                'uri' => $c['slug'],
                'principaluri' => $principalUri,
                '{DAV:}displayname' => $c['display_name'],
                '{urn:ietf:params:xml:ns:caldav}calendar-description' => (string) ($c['description'] ?? ''),
                '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => null,
                '{http://apple.com/ns/ical/}calendar-color' => $c['color'],
                '{http://apple.com/ns/ical/}calendar-order' => (string) ($i + 1),
                '{http://calendarserver.org/ns/}getctag' => 'wk-' . $c['sync_token'],
                '{http://sabredav.org/ns}sync-token' => (string) $c['sync_token'],
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
                '{http://sabredav.org/ns}read-only' => true,
            ];
        }
        return $calendars;
    }

    public function getCalendarObjects($calendarId)
    {
        $rows = $this->db->all(
            'SELECT id, uid, etag, updated_at, LENGTH(ics) AS size FROM events WHERE calendar_id = ?',
            [$this->calendarIdOf($calendarId)]
        );
        return array_map(fn ($r) => $this->objectInfo($r, false), $rows);
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $uid = $this->uidFromUri((string) $objectUri);
        if ($uid === null) {
            return null;
        }
        $row = $this->db->one(
            'SELECT id, uid, etag, updated_at, ics FROM events WHERE calendar_id = ? AND uid = ?',
            [$this->calendarIdOf($calendarId), $uid]
        );
        return $row === null ? null : $this->objectInfo($row, true);
    }

    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        $uids = array_values(array_filter(array_map(fn ($u) => $this->uidFromUri((string) $u), $uris)));
        if ($uids === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($uids, 200) as $chunk) {
            $rows = $this->db->all(
                'SELECT id, uid, etag, updated_at, ics FROM events WHERE calendar_id = ? AND uid IN (' . Db::in($chunk) . ')',
                array_merge([$this->calendarIdOf($calendarId)], $chunk)
            );
            foreach ($rows as $row) {
                $result[] = $this->objectInfo($row, true);
            }
        }
        return $result;
    }

    /**
     * Zeitbereichsfilter direkt per SQL; alle übrigen Filter über die
     * Standardprüfung von sabre/dav (validateFilterForObject).
     */
    public function calendarQuery($calendarId, array $filters)
    {
        $sql = 'SELECT id, uid, etag, updated_at, ics FROM events WHERE calendar_id = ?';
        $params = [$this->calendarIdOf($calendarId)];
        $postFilter = !empty($filters['prop-filters']) || !empty($filters['is-not-defined']);
        $compFilters = $filters['comp-filters'] ?? [];
        if (count($compFilters) === 1 && strtoupper($compFilters[0]['name']) === 'VEVENT') {
            $cf = $compFilters[0];
            if (!empty($cf['time-range'])) {
                $start = $cf['time-range']['start'] ?? null;
                $end = $cf['time-range']['end'] ?? null;
                if ($start instanceof DateTimeInterface) {
                    $sql .= ' AND ends_at > ?';
                    $params[] = Clock::toDb(\DateTimeImmutable::createFromInterface($start));
                }
                if ($end instanceof DateTimeInterface) {
                    $sql .= ' AND starts_at < ?';
                    $params[] = Clock::toDb(\DateTimeImmutable::createFromInterface($end));
                }
            }
            if (!empty($cf['comp-filters']) || !empty($cf['prop-filters']) || !empty($cf['is-not-defined'])) {
                $postFilter = true;
            }
        } elseif ($compFilters !== []) {
            // z. B. VTODO: gibt es hier nicht – Standardprüfung entscheidet
            $postFilter = true;
        }
        $uris = [];
        foreach ($this->db->all($sql, $params) as $row) {
            $object = $this->objectInfo($row, true);
            if ($postFilter && !$this->validateFilterForObject($object, $filters)) {
                continue;
            }
            $uris[] = $object['uri'];
        }
        return $uris;
    }

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        $calId = $this->calendarIdOf($calendarId);
        $current = $this->db->value('SELECT sync_token FROM calendars WHERE id = ?', [$calId]);
        if ($current === null) {
            return null;
        }
        $current = (int) $current;
        $result = ['syncToken' => (string) $current, 'added' => [], 'modified' => [], 'deleted' => []];
        if ($syncToken === null || $syncToken === '') {
            foreach ($this->db->column('SELECT uid FROM events WHERE calendar_id = ?', [$calId]) as $uid) {
                $result['added'][] = $uid . '.ics';
            }
            return $result;
        }
        if (!ctype_digit((string) $syncToken) || (int) $syncToken > $current) {
            return null; // ungültiger Token → Client macht vollständige Synchronisation
        }
        $rows = $this->db->all(
            'SELECT uri, operation FROM calendar_changes WHERE calendar_id = ? AND sync_token >= ? AND sync_token < ? ORDER BY sync_token, id',
            [$calId, (int) $syncToken, $current]
        );
        // Je URI zählt die letzte Änderung
        $changes = [];
        foreach ($rows as $r) {
            $prev = $changes[$r['uri']] ?? null;
            $op = (int) $r['operation'];
            if ($prev === 1 && $op === 2) {
                $op = 1; // neu und danach geändert → neu
            }
            $changes[$r['uri']] = $op;
        }
        foreach ($changes as $uri => $op) {
            $key = match ($op) { 1 => 'added', 2 => 'modified', default => 'deleted' };
            $result[$key][] = $uri;
        }
        return $result;
    }

    // ------------------------------------------------------- nur lesend

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        throw new Forbidden(self::READ_ONLY);
    }

    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        // Eigenschaften (Name, Farbe) werden nur im Admin-Bereich gepflegt
        $propPatch->handleRemaining(fn () => false);
    }

    public function deleteCalendar($calendarId)
    {
        throw new Forbidden(self::READ_ONLY);
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        throw new Forbidden(self::READ_ONLY);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        throw new Forbidden(self::READ_ONLY);
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        throw new Forbidden(self::READ_ONLY);
    }

    // ------------------------------------------------------- Hilfen

    private function calendarIdOf(mixed $calendarId): int
    {
        return (int) (is_array($calendarId) ? $calendarId[0] : $calendarId);
    }

    private function uidFromUri(string $uri): ?string
    {
        return str_ends_with($uri, '.ics') ? substr($uri, 0, -4) : null;
    }

    /** @return array<string,mixed> */
    private function objectInfo(array $row, bool $withData): array
    {
        $info = [
            'id' => (int) $row['id'],
            'uri' => $row['uid'] . '.ics',
            'lastmodified' => Clock::fromDb($row['updated_at'])?->getTimestamp(),
            'etag' => $row['etag'],
            'component' => 'vevent',
        ];
        if ($withData) {
            $info['calendardata'] = $row['ics'];
            $info['size'] = strlen($row['ics']);
        } else {
            $info['size'] = (int) $row['size'];
        }
        return $info;
    }
}
