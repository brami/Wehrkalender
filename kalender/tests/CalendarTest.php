<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\Calendar\IcsBuilder;

final class CalendarTest extends TestCase
{
    public function testSummerAndWinterTime(): void
    {
        $cal = $this->calendar('lg-drove', 'LG Drove');
        $this->event($cal, 'S', '2027-07-01 19:00', '2027-07-01 21:00');
        $this->event($cal, 'W', '2027-01-14 19:00', '2027-01-14 21:00');
        $s = $this->app->db()->one("SELECT * FROM events WHERE mp_event_id = 'S'");
        $w = $this->app->db()->one("SELECT * FROM events WHERE mp_event_id = 'W'");
        self::assertSame('2027-07-01 17:00:00', $s['starts_at'], 'Sommerzeit UTC+2');
        self::assertSame('2027-01-14 18:00:00', $w['starts_at'], 'Winterzeit UTC+1');
        $v = IcsBuilder::parse($s['ics']);
        self::assertSame('Europe/Berlin', (string) $v->VEVENT->DTSTART['TZID']);
        self::assertSame('20270701T190000', $v->VEVENT->DTSTART->getValue());
        self::assertNotNull($v->VTIMEZONE);
        self::assertSame('mp-S@kalender.example.de', (string) $v->VEVENT->UID);
        self::assertSame('LG Drove', (string) $v->VEVENT->CATEGORIES);
        self::assertNull($v->VEVENT->VALARM, 'keine Erinnerungen');
    }

    public function testNightAcrossDstChange(): void
    {
        $cal = $this->calendar('ff', 'FF');
        $this->event($cal, 'N', '2027-03-27 23:00', '2027-03-28 04:00');
        $e = $this->app->db()->one("SELECT * FROM events WHERE mp_event_id = 'N'");
        self::assertSame('2027-03-27 22:00:00', $e['starts_at']);
        self::assertSame('2027-03-28 02:00:00', $e['ends_at'], 'nur 4 echte Stunden');
    }

    public function testAllDayEventEndIsExclusive(): void
    {
        $cal = $this->calendar('gjf', 'GJF');
        $this->event($cal, 'Z', '2027-07-09', '2027-07-11', true);
        $v = IcsBuilder::parse($this->app->db()->value("SELECT ics FROM events WHERE mp_event_id = 'Z'"));
        self::assertSame('DATE', (string) $v->VEVENT->DTSTART['VALUE']);
        self::assertSame('20270709', $v->VEVENT->DTSTART->getValue());
        self::assertSame('20270712', $v->VEVENT->DTEND->getValue(), 'letzter Tag + 1');
    }

    public function testCancelledAndSequenceAndChanges(): void
    {
        $cal = $this->calendar('kf', 'KF');
        self::assertSame('added', $this->event($cal, 'K', '2027-02-06 10:00', '2027-02-06 12:00'));
        self::assertSame('unchanged', $this->event($cal, 'K', '2027-02-06 10:00', '2027-02-06 12:00'));
        $etag = $this->app->db()->value("SELECT etag FROM events WHERE mp_event_id = 'K'");
        self::assertSame('modified', $this->event($cal, 'K', '2027-02-06 10:00', '2027-02-06 12:00', false, true));
        $e = $this->app->db()->one("SELECT * FROM events WHERE mp_event_id = 'K'");
        $v = IcsBuilder::parse($e['ics']);
        self::assertSame('CANCELLED', (string) $v->VEVENT->STATUS);
        self::assertSame('Abgesagt: Übung', (string) $v->VEVENT->SUMMARY);
        self::assertSame('1', (string) $v->VEVENT->SEQUENCE);
        self::assertNotSame($etag, $e['etag']);
        self::assertTrue($this->app->events()->delete('K'));
        $ops = $this->app->db()->column('SELECT operation FROM calendar_changes WHERE calendar_id = ? ORDER BY sync_token', [$cal]);
        self::assertSame([1, 2, 3], array_map('intval', $ops));
        self::assertSame(4, (int) $this->app->db()->value('SELECT sync_token FROM calendars WHERE id = ?', [$cal]));
    }

    public function testEventMovedToOtherCalendar(): void
    {
        $a = $this->calendar('a', 'A');
        $b = $this->calendar('b', 'B');
        $this->event($a, 'M', '2027-02-06 10:00', '2027-02-06 12:00');
        $this->event($b, 'M', '2027-02-06 10:00', '2027-02-06 12:00');
        self::assertSame([3], array_map('intval', $this->app->db()->column('SELECT operation FROM calendar_changes WHERE calendar_id = ? AND operation = 3', [$a])));
        self::assertSame($b, (int) $this->app->db()->value("SELECT calendar_id FROM events WHERE mp_event_id = 'M'"));
    }

    public function testEscapingInLocation(): void
    {
        $cal = $this->calendar('agt', 'Atemschutz');
        $this->app->events()->save([
            'mp_event_id' => 'L', 'calendar_id' => $cal, 'summary' => 'Belastung; Übung, Teil 1',
            'location' => 'Halle 2, Am Feuerwehrhaus 1; Düren', 'description' => "Zeile 1\nZeile 2",
            'start' => new \DateTimeImmutable('2027-02-09 18:30'), 'end' => new \DateTimeImmutable('2027-02-09 21:00'),
        ]);
        $v = IcsBuilder::parse($this->app->db()->value("SELECT ics FROM events WHERE mp_event_id = 'L'"));
        self::assertSame('Halle 2, Am Feuerwehrhaus 1; Düren', (string) $v->VEVENT->LOCATION);
        self::assertSame("Zeile 1\nZeile 2", (string) $v->VEVENT->DESCRIPTION);
    }

    public function testFeedIsValidCalendar(): void
    {
        $cal = $this->calendar('lg', 'LG, Drove');
        $this->event($cal, 'A', '2027-02-06 10:00', '2027-02-06 12:00');
        $this->event($cal, 'B', '2027-02-07', '2027-02-07', true);
        $ics = $this->app->db()->column('SELECT ics FROM events');
        $v = IcsBuilder::parse($this->app->ics()->buildFeed('LG, Drove', '#123456', $ics));
        self::assertCount(2, $v->VEVENT);
        self::assertSame('LG, Drove', (string) $v->{'X-WR-CALNAME'});
        self::assertSame('PT1H', (string) $v->{'REFRESH-INTERVAL'});
    }
}
