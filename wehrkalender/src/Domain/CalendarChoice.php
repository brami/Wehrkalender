<?php

declare(strict_types=1);

namespace Wehrkalender\Domain;

use Wehrkalender\Clock;
use Wehrkalender\Database\Db;

/**
 * Kalenderauswahl durch das Mitglied.
 *
 * Freigeschaltet (user_calendars) entscheidet MP Feuer bzw. „für alle Mitglieder“.
 * Innerhalb davon kann das Mitglied jeden Kalender ein- und ausschalten. Ohne
 * eigene Wahl gilt die Vorgabe des Kalenders (calendars.default_enabled).
 *
 * Wirksam = freigeschaltet, Kalender aktiv, eingeschaltet. Nur wirksame Kalender
 * erscheinen auf CalDAV-Geräten, als Abo-Link und in der PDF-Vorauswahl; ein
 * Abo-Link eines ausgeschalteten Kalenders liefert eine leere Kalenderdatei.
 */
final class CalendarChoice
{
    public function __construct(private Db $db, private Clock $clock)
    {
    }

    /**
     * SQL-Ausdruck „eingeschaltet“ für eine Zeile aus user_calendars (Alias $uc)
     * und calendars (Alias $c).
     */
    public static function enabledSql(string $uc = 'uc', string $c = 'c'): string
    {
        return "COALESCE((SELECT ch.enabled FROM user_calendar_choice ch
                           WHERE ch.user_id = $uc.user_id AND ch.calendar_id = $uc.calendar_id), $c.default_enabled) = 1";
    }

    /** Ist der Kalender für das Mitglied freigeschaltet und eingeschaltet? (Aktivität des Kalenders nicht geprüft) */
    public function isEnabled(int $userId, int $calendarId): bool
    {
        return $this->db->value(
            'SELECT 1 FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND uc.calendar_id = ? AND ' . self::enabledSql(),
            [$userId, $calendarId]
        ) !== null;
    }

    /**
     * Wahl des Mitglieds speichern. Nur für freigeschaltete Kalender.
     * Entspricht die Wahl der Vorgabe, wird sie trotzdem gespeichert: Wer selbst
     * gewählt hat, behält seine Wahl, wenn der Admin die Vorgabe später ändert.
     */
    public function set(int $userId, int $calendarId, bool $enabled): bool
    {
        if ($this->db->value('SELECT 1 FROM user_calendars WHERE user_id = ? AND calendar_id = ?', [$userId, $calendarId]) === null) {
            return false;
        }
        $this->db->transaction(function () use ($userId, $calendarId, $enabled): void {
            $this->db->execute('DELETE FROM user_calendar_choice WHERE user_id = ? AND calendar_id = ?', [$userId, $calendarId]);
            $this->db->insert('user_calendar_choice', [
                'user_id' => $userId,
                'calendar_id' => $calendarId,
                'enabled' => $enabled ? 1 : 0,
                'changed_at' => $this->clock->nowDb(),
            ]);
        });
        return true;
    }

    /** Eigene Wahl löschen, danach gilt wieder die Vorgabe des Kalenders */
    public function reset(int $userId, int $calendarId): void
    {
        $this->db->execute('DELETE FROM user_calendar_choice WHERE user_id = ? AND calendar_id = ?', [$userId, $calendarId]);
    }
}
