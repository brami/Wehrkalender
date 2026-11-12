<?php

declare(strict_types=1);

namespace Wehrkalender\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;
use Wehrkalender\Clock;
use Wehrkalender\Database\Db;

/**
 * Daten für die Terminübersicht als PDF (Jahresplan): nur die eigenen,
 * aktiven Kalender eines Mitglieds; je Tag nur Startzeit, Farbe und ob abgesagt.
 */
final class YearPlanner
{
    public const MAX_MONTHS = 12;

    public function __construct(private Db $db)
    {
    }

    /** @return list<array{id:int,slug:string,display_name:string,color:string}> */
    public function calendarsFor(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.slug, c.display_name, c.color
               FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND c.active = 1
              ORDER BY c.display_name',
            [$userId]
        );
        return array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'display_name' => (string) $r['display_name'],
            'color' => self::color((string) $r['color']),
        ], $rows);
    }

    /**
     * @param list<string> $slugs gewünschte Kalender; fremde werden ignoriert
     * @return array{
     *   start:DateTimeImmutable, end:DateTimeImmutable, months:int,
     *   calendars:list<array{id:int,slug:string,display_name:string,color:string}>,
     *   days:array<string,list<array{time:?string,color:string,cancelled:bool,calendar:string}>>
     * }
     */
    public function build(int $userId, DateTimeImmutable $startMonth, int $months, array $slugs): array
    {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('Zeitraum: 1 bis ' . self::MAX_MONTHS . ' Monate.');
        }
        $tz = Clock::localTz();
        $start = new DateTimeImmutable($startMonth->setTimezone($tz)->format('Y-m-01'), $tz);
        $end = $start->modify("+{$months} months");

        $wanted = array_flip($slugs);
        $calendars = array_values(array_filter($this->calendarsFor($userId), fn ($c) => isset($wanted[$c['slug']])));
        $result = ['start' => $start, 'end' => $end, 'months' => $months, 'calendars' => $calendars, 'days' => []];
        if ($calendars === []) {
            return $result;
        }

        $byId = [];
        $order = [];
        foreach ($calendars as $i => $c) {
            $byId[$c['id']] = $c;
            $order[$c['slug']] = $i;
        }
        $in = implode(',', array_fill(0, count($byId), '?'));
        $rows = $this->db->all(
            "SELECT calendar_id, starts_at, ends_at, all_day, status FROM events
              WHERE calendar_id IN ($in) AND starts_at < ? AND ends_at > ?
              ORDER BY starts_at",
            [...array_keys($byId), Clock::toDb($end), Clock::toDb($start)]
        );

        $firstDay = $start->format('Y-m-d');
        $lastDay = $end->modify('-1 day')->format('Y-m-d');
        $days = [];
        foreach ($rows as $r) {
            $cal = $byId[(int) $r['calendar_id']];
            $s = Clock::toLocal($r['starts_at']);
            $e = Clock::toLocal($r['ends_at']);
            $allDay = (bool) $r['all_day'];
            $sDay = $s->format('Y-m-d');
            // letzter Tag: ganztägig ist ends_at exklusiv (00:00 des Folgetags)
            $eDay = ($allDay ? $e->modify('-1 day') : $e->modify('-1 second'))->format('Y-m-d');
            if ($eDay < $sDay) {
                $eDay = $sDay;
            }
            $cancelled = strtoupper((string) $r['status']) === 'CANCELLED';
            $d = $sDay < $firstDay ? new DateTimeImmutable($firstDay, $tz) : $s->setTime(0, 0);
            $until = min($eDay, $lastDay);
            while (($key = $d->format('Y-m-d')) <= $until) {
                $days[$key][] = [
                    'time' => (!$allDay && $key === $sDay) ? $s->format('H:i') : null,
                    'color' => $cal['color'],
                    'cancelled' => $cancelled,
                    'calendar' => $cal['slug'],
                ];
                $d = $d->modify('+1 day');
            }
        }
        foreach ($days as &$list) {
            usort($list, fn ($a, $b) => [$a['time'] ?? '', $order[$a['calendar']]] <=> [$b['time'] ?? '', $order[$b['calendar']]]);
        }
        unset($list);
        ksort($days);
        $result['days'] = $days;
        return $result;
    }

    /** Farbe auf #RRGGBB normalisieren */
    public static function color(string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#6b7280';
    }
}
