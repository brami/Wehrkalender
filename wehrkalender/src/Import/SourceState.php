<?php

declare(strict_types=1);

namespace Wehrkalender\Import;

use Wehrkalender\Clock;
use Wehrkalender\Database\Db;

/**
 * Zustand externer Quellen (iCal-Feeds und hochgeladene Dateien) für Verwaltung,
 * Statusseite, /health, Warn-E-Mails und den „Stand“ beim Kalender.
 */
final class SourceState
{
    /** Hochgeladene Dateien gelten als „läuft aus“, wenn sie in weniger Tagen enden */
    public const FILES_END_WARN_DAYS = 30;

    public function __construct(private Db $db, private Clock $clock)
    {
    }

    /**
     * ok | warn | error | never | off | mp | ending | empty
     * @param array<string,mixed> $s Zeile aus sources
     */
    public function state(array $s): string
    {
        if ($s['type'] === 'mpfeuer') {
            return 'mp';
        }
        if ((int) $s['active'] !== 1) {
            return 'off';
        }
        if ($s['type'] === 'upload') {
            $end = $this->db->value("SELECT MAX(last_end) FROM source_files WHERE source_id = ? AND status = 'active'", [$s['id']]);
            if ($end === null) {
                return 'empty';
            }
            return $end < $this->clock->db('+' . self::FILES_END_WARN_DAYS . ' days') ? 'ending' : 'ok';
        }
        if ($s['last_success_at'] === null) {
            return $s['last_error'] !== null ? 'error' : 'never';
        }
        if ($s['last_error'] !== null && $s['last_run_at'] !== null && $s['last_run_at'] >= $s['last_success_at']) {
            return 'error';
        }
        // länger als zwei Intervalle (mind. 1 Tag) kein erfolgreicher Abruf
        $limit = $this->clock->db('-' . max(1440, 2 * (int) $s['interval_minutes']) . ' minutes');
        return $s['last_success_at'] < $limit ? 'warn' : 'ok';
    }

    /** Problem einer Quelle als Satz, oder null */
    public function problem(array $s): ?string
    {
        $state = $this->state($s);
        $name = '„' . $s['name'] . '“';
        return match ($state) {
            'never' => "Quelle {$name} wurde noch nie erfolgreich abgerufen.",
            'error' => "Quelle {$name}: letzter Abruf fehlgeschlagen" . ($s['last_error'] ? " ({$s['last_error']})" : '') . '.',
            'warn' => sprintf('Quelle %s: seit %s kein erfolgreicher Abruf.', $name, Clock::toLocal($s['last_success_at'])?->format('d.m.Y H:i')),
            'empty' => "Quelle {$name}: noch keine Datei hochgeladen.",
            'ending' => sprintf('Quelle %s: die hochgeladenen Dateien enden am %s – neue Datei hochladen oder Quelle deaktivieren.', $name,
                Clock::toLocal((string) $this->db->value("SELECT MAX(last_end) FROM source_files WHERE source_id = ? AND status = 'active'", [$s['id']]))?->format('d.m.Y')),
            default => null,
        };
    }

    /** @return list<array<string,mixed>> aktive externe Quellen mit Problem (Feld problem) */
    public function problems(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM sources WHERE type <> 'mpfeuer' AND active = 1 ORDER BY name") as $s) {
            if (($p = $this->problem($s)) !== null) {
                $out[] = $s + ['problem' => $p, 'state' => $this->state($s)];
            }
        }
        return $out;
    }

    /**
     * Stand der externen Quellen eines Kalenders (null, wenn nur MP Feuer befüllt):
     * text z. B. „Quelle: Abfallkalender Drove · Stand 12.11.2026“, stale bei Problemen.
     *
     * @return array<int,array{text:string,date:?string,stale:bool}> je Kalender-ID
     */
    public function calendarStands(): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT f.calendar_id, s.* FROM calendar_feeds f JOIN sources s ON s.id = f.source_id
              WHERE f.active = 1 AND s.active = 1 AND s.type <> 'mpfeuer' ORDER BY s.name"
        );
        $by = [];
        foreach ($rows as $s) {
            $date = $s['type'] === 'upload'
                ? $this->db->value("SELECT MAX(applied_at) FROM source_files WHERE source_id = ? AND status = 'active'", [$s['id']])
                : $s['last_success_at'];
            $by[(int) $s['calendar_id']][] = ['name' => $s['name'], 'date' => $date, 'stale' => in_array($this->state($s), ['warn', 'error', 'never', 'ending', 'empty'], true)];
        }
        $out = [];
        foreach ($by as $calId => $list) {
            $dates = array_filter(array_column($list, 'date'));
            $date = $dates === [] ? null : min($dates);   // der älteste Stand zählt
            $out[$calId] = [
                'text' => 'Quelle: ' . implode(', ', array_column($list, 'name'))
                    . ($date !== null ? ' · Stand ' . Clock::toLocal($date)?->format('d.m.Y') : ' · noch nicht abgerufen'),
                'date' => $date,
                'stale' => in_array(true, array_column($list, 'stale'), true) || $date === null,
            ];
        }
        return $out;
    }
}
