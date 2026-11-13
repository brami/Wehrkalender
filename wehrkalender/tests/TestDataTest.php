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
        // Quellen: Maschinist 2/2027 kommt aus der hochgeladenen Kreisdatei (Teilnahme aus MP Feuer schaltet nur frei)
        self::assertSame(['abfall-drove', 'abfall-kreuzau', 'abfall-stockheim', 'kfv', 'kreis', 'mp', 'ortsverein-drove'],
            $db->column('SELECT source_key FROM sources ORDER BY source_key'));
        self::assertSame(['Maschinist 2/2027 – Tag 1/4', 'Maschinist 2/2027 – Tag 2/4', 'Maschinist 2/2027 – Tag 3/4', 'Maschinist 2/2027 – Tag 4/4'], $db->column(
            "SELECT e.summary FROM events e JOIN sources s ON s.id = e.source_id JOIN calendars c ON c.id = e.calendar_id
              WHERE c.slug = 'maschinist-2-2027' AND s.source_key = 'kreis' ORDER BY e.starts_at"));
        self::assertSame(105, (int) $db->value("SELECT event_count FROM source_files WHERE status = 'active'"));
        // dieselbe Datei befüllt weitere Kalender; Truppführer über zwei Befüllungen (Theorie + Praxis)
        foreach (['atemschutz-2-2027' => 4, 'truppfuehrer-1-2027' => 9] as $slug => $n) {
            self::assertSame($n, (int) $db->value("SELECT COUNT(*) FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE c.slug = ?", [$slug]), $slug);
        }
        self::assertSame(45, (int) $db->value("SELECT COUNT(*) FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE c.slug = 'abfall-drove'"), 'Serien aufgelöst');
        self::assertStringContainsString('Quelle: Ortsverein Drove · Stand ', (string) $db->value("SELECT description FROM events WHERE summary LIKE 'Dorffest%'"));
        self::assertNull($db->value("SELECT id FROM events WHERE summary LIKE 'Konzert%'"), 'vom Filter ausgeschlossen');
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE uid NOT LIKE 'mp-%' AND source_id = (SELECT id FROM sources WHERE source_key = 'mp')"));
        self::assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE uid LIKE 'kreis-%'"));
        // gemischter Kalender LG Drove: MP Feuer + Ortsverein
        self::assertSame(2, (int) $db->value(
            "SELECT COUNT(DISTINCT e.source_id) FROM events e JOIN calendars c ON c.id = e.calendar_id WHERE c.slug = 'lg-drove'"));
        // Felix: LG Stockheim entzogen
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id WHERE uc.user_id = ? AND c.slug = 'lg-stockheim'", [$users['1020']['id']]));
        // Status-Prüfung meldet nur die absichtlich fehlerhafte Quelle
        self::assertSame(['Quelle „Kreisfeuerwehrverband“: letzter Abruf fehlgeschlagen (HTTP 404).'], $this->app->status()->problems());
        self::assertNotEmpty(array_filter($result['logins'], fn ($l) => $l['password'] !== ''));
        // alle gespeicherten iCalendar-Texte sind gültig
        foreach ($db->column('SELECT ics FROM events') as $ics) {
            \Wehrkalender\Calendar\IcsBuilder::parse($ics);
        }
        self::assertNotNull(Clock::fromDb($db->value('SELECT MAX(started_at) FROM import_runs')));
    }
}
