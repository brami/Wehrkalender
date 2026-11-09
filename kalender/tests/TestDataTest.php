<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\Clock;
use Kalender\Domain\UsernamePolicy;
use Kalender\TestData\TestDataLoader;

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
        // Juergen sieht wie in der Projektbeschreibung FF KRZ, LG Drove, GJF, JF Gr1, KF
        self::assertSame(['FF KRZ', 'GJF', 'JF Gr1', 'KF', 'LG Drove'], $db->column(
            'SELECT c.display_name FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id WHERE uc.user_id = ? ORDER BY c.display_name',
            [$users['1002']['id']]));
        // Felix: LG Stockheim entzogen
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id WHERE uc.user_id = ? AND c.slug = 'lg-stockheim'", [$users['1020']['id']]));
        // Status-Prüfung meldet keine Probleme
        self::assertSame([], $this->app->status()->problems());
        self::assertNotEmpty(array_filter($result['logins'], fn ($l) => $l['password'] !== ''));
        // alle gespeicherten iCalendar-Texte sind gültig
        foreach ($db->column('SELECT ics FROM events') as $ics) {
            \Kalender\Calendar\IcsBuilder::parse($ics);
        }
        self::assertNotNull(Clock::fromDb($db->value('SELECT MAX(started_at) FROM import_runs')));
    }
}
