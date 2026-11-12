<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\Clock;
use Wehrkalender\Domain\UsernamePolicy;
use Wehrkalender\TestData\TestDataLoader;

/** Das Testdaten-Programm muss durchlaufen und alle vorgesehenen Fälle erzeugen */
final class TestDataTest extends TestCase
{
    public function testLoadsAllCases(): void
    {
        $this->clock->fix(null);
        $result = (new TestDataLoader($this->app))->load(['import' => 'ok']);
        $db = $this->app->db();
        $users = [];
        foreach ($db->all('SELECT * FROM users') as $u) {
            $users[$u['mp_id']] = $u;
        }
        self::assertCount(30, $users);
        self::assertSame('anna.schmidt', $users['1003']['username']);
        self::assertTrue(UsernamePolicy::isOutdated($users['1003']['username'], $users['1003']['first_name'], $users['1003']['last_name']));
        self::assertSame('max.mustermann2', $users['1005']['username']);
        self::assertSame('dieter.langer2', $users['1023']['username']);
        self::assertSame('anna.schmidt2', $users['1024']['username']);
        self::assertSame('locked_email', $users['1010']['status']);
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM users WHERE is_admin = 1"));
        self::assertNull($users['1012']['first_web_login_at']);
        self::assertGreaterThan(300, $result['counts']['events']);
        self::assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE status = 'CANCELLED'"));
        self::assertGreaterThan(0, (int) $db->value('SELECT COUNT(*) FROM events WHERE all_day = 1'));
        self::assertSame(['aborted', 'failed', 'success'], $db->column('SELECT DISTINCT status FROM import_runs ORDER BY status'));
        self::assertSame(['failed', 'queued', 'sent'], $db->column('SELECT DISTINCT status FROM mail_queue ORDER BY status'));
        // Jürgen: freigeschaltet wie in der Projektbeschreibung (FF KRZ, LG Drove, GJF, JF Gr1, KF)
        // plus die Kalender für alle (Verband, Abfall je Ort)
        self::assertSame(['Abfall Drove', 'Abfall Kreuzau', 'Abfall Stockheim', 'FF KRZ', 'GJF', 'JF Gr1', 'KF', 'Kreisfeuerwehrverband', 'LG Drove'], $db->column(
            'SELECT c.display_name FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id WHERE uc.user_id = ? ORDER BY c.display_name',
            [$users['1002']['id']]));
        // … eingeschaltet: Abfall Drove selbst eingeschaltet, Verband selbst ausgeschaltet, übrige Abfallkalender Vorgabe aus
        self::assertSame(['Abfall Drove', 'FF KRZ', 'GJF', 'JF Gr1', 'KF', 'LG Drove'], $db->column(
            'SELECT c.display_name FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND ' . \Wehrkalender\Domain\CalendarChoice::enabledSql() . ' ORDER BY c.display_name',
            [$users['1002']['id']]));
        // Quellen: TM1 2027 kommt aus dem Kreis-Feed (Teilnahme aus MP Feuer schaltet nur frei)
        self::assertSame(['abfall-drove', 'abfall-kreuzau', 'abfall-stockheim', 'kfv', 'kreis', 'mp', 'ortsverein-drove'],
            $db->column('SELECT source_key FROM sources ORDER BY source_key'));
        self::assertSame(['kreis'], $db->column(
            "SELECT DISTINCT s.source_key FROM events e JOIN sources s ON s.id = e.source_id JOIN calendars c ON c.id = e.calendar_id WHERE c.slug = 'tm1-2027'"));
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE uid NOT LIKE 'mp-%' AND source_id = (SELECT id FROM sources WHERE source_key = 'mp')"));
        self::assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE uid LIKE 'kreis-%'"));
        // gemischter Kalender LG Drove: MP Feuer + Ortsverein
        self::assertSame(2, (int) $db->value(
            "SELECT COUNT(DISTINCT e.source_id) FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE c.slug = 'lg-drove'"));
        // Felix: LG Stockheim entzogen
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id WHERE uc.user_id = ? AND c.slug = 'lg-stockheim'", [$users['1020']['id']]));
        // Status-Prüfung meldet keine Probleme
        self::assertSame([], $this->app->status()->problems());
        self::assertNotEmpty(array_filter($result['logins'], fn ($l) => $l['password'] !== ''));
        // alle gespeicherten iCalendar-Texte sind gültig
        foreach ($db->column('SELECT ics FROM events') as $ics) {
            \Wehrkalender\Calendar\IcsBuilder::parse($ics);
        }
        self::assertNotNull(Clock::fromDb($db->value('SELECT MAX(started_at) FROM import_runs')));
    }
}
