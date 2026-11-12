<?php

declare(strict_types=1);

namespace Kalender\Calendar;

/**
 * Schulferien in Nordrhein-Westfalen (landesweit einheitliche Ferien, ohne
 * bewegliche Ferientage der einzelnen Schulen).
 *
 * Quelle: Ordnung der Ferien für die Schuljahre 2024/25 bis 2029/30,
 * Runderlass des Ministeriums für Schule und Bildung NRW (BASS 12-65 Nr. 1),
 * https://bass.schule.nrw/19662.htm, sowie die dort veröffentlichte
 * Sommerferienregelung bis 2030 (https://www.schulministerium.nrw).
 * Stand: 12.11.2026. Neue Schuljahre bei Veröffentlichung hier ergänzen und
 * COVERED_UNTIL anpassen.
 */
final class NrwSchoolHolidays
{
    /** erster Tag, für den die Tabelle vollständig ist */
    public const COVERED_FROM = '2024-07-08';
    /** letzter Tag, für den die Tabelle vollständig ist */
    public const COVERED_UNTIL = '2030-08-06';

    /** [Name, erster Ferientag, letzter Ferientag] */
    private const PERIODS = [
        // Schuljahr 2024/25
        ['Sommerferien', '2024-07-08', '2024-08-20'],
        ['Herbstferien', '2024-10-14', '2024-10-26'],
        ['Weihnachtsferien', '2024-12-23', '2025-01-06'],
        ['Osterferien', '2025-04-14', '2025-04-26'],
        ['Pfingstferien', '2025-06-10', '2025-06-10'],
        // Schuljahr 2025/26
        ['Sommerferien', '2025-07-14', '2025-08-26'],
        ['Herbstferien', '2025-10-13', '2025-10-25'],
        ['Weihnachtsferien', '2025-12-22', '2026-01-06'],
        ['Osterferien', '2026-03-30', '2026-04-11'],
        ['Pfingstferien', '2026-05-26', '2026-05-26'],
        // Schuljahr 2026/27
        ['Sommerferien', '2026-07-20', '2026-09-01'],
        ['Herbstferien', '2026-10-17', '2026-10-31'],
        ['Weihnachtsferien', '2026-12-23', '2027-01-06'],
        ['Osterferien', '2027-03-22', '2027-04-03'],
        ['Pfingstferien', '2027-05-18', '2027-05-18'],
        // Schuljahr 2027/28 (keine Pfingstferien)
        ['Sommerferien', '2027-07-19', '2027-08-31'],
        ['Herbstferien', '2027-10-23', '2027-11-06'],
        ['Weihnachtsferien', '2027-12-24', '2028-01-08'],
        ['Osterferien', '2028-04-10', '2028-04-22'],
        // Schuljahr 2028/29
        ['Sommerferien', '2028-07-10', '2028-08-22'],
        ['Herbstferien', '2028-10-23', '2028-11-04'],
        ['Weihnachtsferien', '2028-12-21', '2029-01-05'],
        ['Osterferien', '2029-03-26', '2029-04-07'],
        ['Pfingstferien', '2029-05-22', '2029-05-22'],
        // Schuljahr 2029/30 (keine Pfingstferien)
        ['Sommerferien', '2029-07-02', '2029-08-14'],
        ['Herbstferien', '2029-10-15', '2029-10-27'],
        ['Weihnachtsferien', '2029-12-20', '2030-01-04'],
        ['Osterferien', '2030-04-15', '2030-04-27'],
        // Schuljahr 2030/31 (bisher nur Sommerferienregelung bekannt)
        ['Sommerferien', '2030-06-24', '2030-08-06'],
    ];

    /** Name der Ferien an diesem Tag ('Y-m-d') oder null */
    public static function get(string $date): ?string
    {
        foreach (self::PERIODS as [$name, $from, $to]) {
            if ($date >= $from && $date <= $to) {
                return $name;
            }
        }
        return null;
    }

    /** Ist der Zeitraum ('Y-m-d', jeweils einschließlich) vollständig abgedeckt? */
    public static function covers(string $from, string $to): bool
    {
        return $from >= self::COVERED_FROM && $to <= self::COVERED_UNTIL;
    }
}
