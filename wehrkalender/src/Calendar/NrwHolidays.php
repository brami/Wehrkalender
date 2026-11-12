<?php

declare(strict_types=1);

namespace Wehrkalender\Calendar;

use DateTimeImmutable;

/**
 * Gesetzliche Feiertage in Nordrhein-Westfalen, berechnet (ohne externe Daten).
 */
final class NrwHolidays
{
    /** @var array<int,array<string,array{0:string,1:string}>> */
    private static array $cache = [];

    /**
     * @return array<string,array{0:string,1:string}> 'Y-m-d' => [Name, Kurzname]
     */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }
        $easter = self::easterSunday($year);
        $rel = fn (int $days) => $easter->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        $list = [
            sprintf('%04d-01-01', $year) => ['Neujahr', 'Neujahr'],
            $rel(-2) => ['Karfreitag', 'Karfr.'],
            $rel(1) => ['Ostermontag', 'Ostermo.'],
            sprintf('%04d-05-01', $year) => ['Tag der Arbeit', '1. Mai'],
            $rel(39) => ['Christi Himmelfahrt', 'Himmelf.'],
            $rel(50) => ['Pfingstmontag', 'Pfingstmo.'],
            $rel(60) => ['Fronleichnam', 'Fronl.'],
            sprintf('%04d-10-03', $year) => ['Tag der Deutschen Einheit', 'Dt. Einheit'],
            sprintf('%04d-11-01', $year) => ['Allerheiligen', 'Allerh.'],
            sprintf('%04d-12-25', $year) => ['1. Weihnachtstag', '1. Weihn.'],
            sprintf('%04d-12-26', $year) => ['2. Weihnachtstag', '2. Weihn.'],
        ];
        ksort($list);
        return self::$cache[$year] = $list;
    }

    /** @return array{0:string,1:string}|null [Name, Kurzname] */
    public static function get(string $date): ?array
    {
        return self::forYear((int) substr($date, 0, 4))[$date] ?? null;
    }

    /** Ostersonntag nach der Gaußschen Osterformel (gregorianisch) */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
