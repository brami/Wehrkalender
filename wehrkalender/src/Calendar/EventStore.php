<?php

declare(strict_types=1);

namespace Wehrkalender\Calendar;

use DateTimeImmutable;
use Wehrkalender\Clock;
use Wehrkalender\Database\Db;
use Wehrkalender\Config;

/**
 * Schreibt Termine (events) samt iCalendar, ETag, Sync-Token und
 * calendar_changes. Wird vom späteren Import und vom Testdaten-Programm genutzt.
 *
 * Sync-Token-Konvention (wie sabre/dav-PDO-Backend): Eine Änderung wird mit dem
 * aktuellen Token des Kalenders protokolliert, danach wird der Token erhöht.
 * Änderungen seit Token T sind alle Einträge mit sync_token >= T.
 */
final class EventStore
{
    public const OP_ADDED = 1;
    public const OP_MODIFIED = 2;
    public const OP_DELETED = 3;
    public const SOURCE_MP = 'mp';

    public function __construct(
        private Db $db,
        private IcsBuilder $ics,
        private Clock $clock,
        private Config $config,
    ) {
    }

    /** @var array<string,int> */
    private array $sourceIds = [];

    /**
     * UID auf den Geräten: <quelle>-<id>@<domain>, für MP Feuer also mp-<id>@<domain>.
     * Quellenschlüssel und IDs dürfen sich nach dem Echtbetrieb nicht mehr ändern.
     */
    public function uidFor(string $externalId, string $sourceKey = self::SOURCE_MP): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $externalId);
        return $sourceKey . '-' . $safe . '@' . preg_replace('/:\d+$/', '', $this->config->domain());
    }

    /** ID einer Quelle zu ihrem Schlüssel (z. B. "mp") */
    public function sourceId(string $sourceKey): int
    {
        if (!isset($this->sourceIds[$sourceKey])) {
            $id = $this->db->value('SELECT id FROM sources WHERE source_key = ?', [$sourceKey]);
            if ($id === null) {
                throw new \RuntimeException('Unbekannte Quelle: ' . $sourceKey);
            }
            $this->sourceIds[$sourceKey] = (int) $id;
        }
        return $this->sourceIds[$sourceKey];
    }

    /**
     * Termin anlegen oder aktualisieren (internes Format, Zeiten lokal Europe/Berlin).
     *
     * @param array{external_id:string,source?:string,feed_id?:?int,calendar_id:int,summary:string,
     *              location?:?string,description?:?string,start:DateTimeImmutable,end:DateTimeImmutable,
     *              all_day?:bool,cancelled?:bool} $data
     *        source: Schlüssel der Quelle (Vorgabe "mp"); external_id: Kennung des Termins in der Quelle.
     *        Bei ganztägigen Terminen ist "end" der letzte Tag (einschließlich).
     * @return string "added" | "modified" | "unchanged"
     */
    public function save(array $data): string
    {
        $allDay = (bool) ($data['all_day'] ?? false);
        $tz = Clock::localTz();
        $start = $data['start']->setTimezone($tz);
        $end = $data['end']->setTimezone($tz);
        if ($allDay) {
            $start = $start->setTime(0, 0);
            $end = $end->setTime(0, 0)->modify('+1 day');
        } elseif ($end <= $start) {
            $end = $start->modify('+1 hour');
        }
        $row = [
            'calendar_id' => (int) $data['calendar_id'],
            'summary' => trim($data['summary']) !== '' ? trim($data['summary']) : '(ohne Titel)',
            'location' => $this->nullIfEmpty($data['location'] ?? null),
            'description' => $this->nullIfEmpty($data['description'] ?? null),
            'starts_at' => Clock::toDb($start),
            'ends_at' => Clock::toDb($end),
            'all_day' => $allDay ? 1 : 0,
            'status' => !empty($data['cancelled']) ? 'CANCELLED' : 'CONFIRMED',
        ];
        $hash = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        $sourceKey = $data['source'] ?? self::SOURCE_MP;
        $sourceId = $this->sourceId($sourceKey);
        $row['feed_id'] = isset($data['feed_id']) ? (int) $data['feed_id'] : null;
        return $this->db->transaction(function () use ($data, $row, $hash, $sourceKey, $sourceId): string {
            $existing = $this->db->one('SELECT * FROM events WHERE source_id = ? AND external_id = ?', [$sourceId, $data['external_id']]);
            $now = $this->clock->nowDb();
            if ($existing !== null && $existing['content_hash'] === $hash) {
                return 'unchanged';
            }
            $calendarName = (string) $this->db->value('SELECT display_name FROM calendars WHERE id = ?', [$row['calendar_id']]);
            if ($existing === null) {
                $event = $row + [
                    'source_id' => $sourceId,
                    'external_id' => $data['external_id'],
                    'uid' => $this->uidFor($data['external_id'], $sourceKey),
                    'sequence_no' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $event['ics'] = $this->ics->buildEvent($event, $calendarName);
                $event['etag'] = '"' . md5($event['ics']) . '"';
                $event['content_hash'] = $hash;
                $this->db->insert('events', $event);
                $this->recordChange($row['calendar_id'], $event['uid'] . '.ics', self::OP_ADDED);
                return 'added';
            }
            $event = array_merge($existing, $row, [
                'sequence_no' => (int) $existing['sequence_no'] + 1,
                'updated_at' => $now,
            ]);
            $event['ics'] = $this->ics->buildEvent($event, $calendarName);
            $event['etag'] = '"' . md5($event['ics']) . '"';
            $this->db->update('events', [
                'calendar_id' => $row['calendar_id'],
                'feed_id' => $row['feed_id'],
                'summary' => $row['summary'],
                'location' => $row['location'],
                'description' => $row['description'],
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'all_day' => $row['all_day'],
                'status' => $row['status'],
                'ics' => $event['ics'],
                'etag' => $event['etag'],
                'content_hash' => $hash,
                'sequence_no' => $event['sequence_no'],
                'updated_at' => $now,
            ], ['id' => $existing['id']]);
            $uri = $existing['uid'] . '.ics';
            if ((int) $existing['calendar_id'] !== $row['calendar_id']) {
                // Termin ist in einen anderen Kalender gewandert
                $this->recordChange((int) $existing['calendar_id'], $uri, self::OP_DELETED);
                $this->recordChange($row['calendar_id'], $uri, self::OP_ADDED);
            } else {
                $this->recordChange($row['calendar_id'], $uri, self::OP_MODIFIED);
            }
            return 'modified';
        });
    }

    /** Termin entfernen (nicht mehr im Export dieser Quelle) */
    public function delete(string $externalId, string $sourceKey = self::SOURCE_MP): bool
    {
        $sourceId = $this->sourceId($sourceKey);
        return $this->db->transaction(function () use ($externalId, $sourceId): bool {
            $existing = $this->db->one('SELECT id, uid, calendar_id FROM events WHERE source_id = ? AND external_id = ?', [$sourceId, $externalId]);
            if ($existing === null) {
                return false;
            }
            $this->db->execute('DELETE FROM events WHERE id = ?', [$existing['id']]);
            $this->recordChange((int) $existing['calendar_id'], $existing['uid'] . '.ics', self::OP_DELETED);
            return true;
        });
    }

    /**
     * Alle Termine eines Kalenders neu erzeugen, z. B. nach Umbenennung
     * (CATEGORIES enthält den Kalendernamen).
     */
    public function rebuildCalendar(int $calendarId): int
    {
        return $this->db->transaction(function () use ($calendarId): int {
            $name = (string) $this->db->value('SELECT display_name FROM calendars WHERE id = ?', [$calendarId]);
            $count = 0;
            foreach ($this->db->all('SELECT * FROM events WHERE calendar_id = ?', [$calendarId]) as $event) {
                $event['sequence_no'] = (int) $event['sequence_no'] + 1;
                $event['updated_at'] = $this->clock->nowDb();
                $ics = $this->ics->buildEvent($event, $name);
                $this->db->update('events', [
                    'ics' => $ics,
                    'etag' => '"' . md5($ics) . '"',
                    'sequence_no' => $event['sequence_no'],
                    'updated_at' => $event['updated_at'],
                ], ['id' => $event['id']]);
                $this->recordChange($calendarId, $event['uid'] . '.ics', self::OP_MODIFIED);
                $count++;
            }
            return $count;
        });
    }

    public function recordChange(int $calendarId, string $uri, int $operation): void
    {
        $token = (int) $this->db->value('SELECT sync_token FROM calendars WHERE id = ?', [$calendarId]);
        $this->db->insert('calendar_changes', [
            'calendar_id' => $calendarId,
            'sync_token' => $token,
            'uri' => $uri,
            'operation' => $operation,
            'changed_at' => $this->clock->nowDb(),
        ]);
        $this->db->execute('UPDATE calendars SET sync_token = sync_token + 1, updated_at = ? WHERE id = ?', [$this->clock->nowDb(), $calendarId]);
    }

    private function nullIfEmpty(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);
        return $s === '' ? null : $s;
    }
}
