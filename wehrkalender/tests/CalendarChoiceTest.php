<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Psr\Http\Message\ResponseInterface;
use Sabre\HTTP\Request as DavRequest;
use Slim\Psr7\Factory\ServerRequestFactory;
use Wehrkalender\Web\WebApp;

/** Kalenderauswahl durch Mitglieder und Vorgabe des Admins (Konzept E7) */
final class CalendarChoiceTest extends TestCase
{
    private function web(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== []) {
            $req = $req->withParsedBody($body + ['_csrf' => 'x']);
        }
        return WebApp::create($this->app)->handle($req);
    }

    private function login(array $user): void
    {
        $this->web('GET', '/login');
        $_SESSION = ['user_id' => (int) $user['id'], '_last' => time(), '_csrf' => 'x'];
    }

    /** @return list<string> Kalender, die das Gerät per CalDAV sieht */
    private function davCalendars(array $user): array
    {
        $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>';
        $res = AccessTest::runDav($this->app, new DavRequest('PROPFIND', '/dav/calendars/' . $user['username'] . '/', [
            'Authorization' => 'Basic ' . base64_encode($user['username'] . ':' . $this->password($user)),
            'Depth' => '1', 'Content-Type' => 'application/xml',
        ], $body));
        preg_match_all('#<d:displayname>([^<]+)</d:displayname>#', $res->getBodyAsString(), $m);
        sort($m[1]);
        return $m[1];
    }

    private function feedBody(array $user, int $calendarId): string
    {
        $token = (string) $this->app->db()->value(
            'SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL', [$user['id'], $calendarId]);
        $res = $this->web('GET', '/abo/' . $token . '.ics');
        self::assertSame(200, $res->getStatusCode());
        self::assertStringStartsWith('text/calendar', $res->getHeaderLine('Content-Type'));
        return (string) $res->getBody();
    }

    private function setDefault(int $calendarId, bool $on): void
    {
        $this->app->db()->execute('UPDATE calendars SET default_enabled = ? WHERE id = ?', [$on ? 1 : 0, $calendarId]);
    }

    public function testSwitchingOffHidesCalendarEverywhereAndKeepsTheFeedLink(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $ff = $this->calendar('ff', 'FF', [], true);
        $this->event($lg, 'a', '2027-01-14 19:00', '2027-01-14 21:00');
        $anna = $this->member('1', 'Anna', 'Schmidt', ['LG-DROVE']);
        self::assertSame(['FF', 'LG Drove'], $this->davCalendars($anna));
        self::assertStringContainsString('BEGIN:VEVENT', $this->feedBody($anna, $lg));

        $this->login($anna);
        $res = $this->web('POST', '/kalender/lg-drove/aus', ['x' => '1']);
        self::assertSame(303, $res->getStatusCode());

        self::assertSame(['FF'], $this->davCalendars($anna), 'nicht mehr auf dem Gerät');
        $feed = $this->feedBody($anna, $lg);
        self::assertStringContainsString('BEGIN:VCALENDAR', $feed, 'gültige Kalenderdatei');
        self::assertStringNotContainsString('BEGIN:VEVENT', $feed, 'aber ohne Termine');

        $feeds = (string) $this->web('GET', '/abonnieren/outlook')->getBody();
        self::assertStringContainsString('Ausgeschaltete Kalender', $feeds);
        $ffToken = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ?', [$anna['id'], $ff]);
        $lgToken = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ?', [$anna['id'], $lg]);
        self::assertStringContainsString($ffToken, $feeds);
        self::assertStringNotContainsString($lgToken, $feeds, 'kein Abo-Link für ausgeschaltete Kalender');

        $home = (string) $this->web('GET', '/')->getBody();
        self::assertStringContainsString('Weitere Kalender', $home);
        self::assertStringContainsString('/kalender/lg-drove/ein', $home);

        // PDF: ausgeschaltete Kalender sind angeboten, aber nicht vorausgewählt
        $cals = $this->app->yearPlanner()->calendarsFor((int) $anna['id']);
        self::assertSame(['FF' => true, 'LG Drove' => false], array_column($cals, 'enabled', 'display_name'));

        // wieder einschalten: dasselbe Abo liefert wieder Termine
        $this->web('POST', '/kalender/lg-drove/ein', ['x' => '1']);
        self::assertStringContainsString('BEGIN:VEVENT', $this->feedBody($anna, $lg));
        self::assertSame(['FF', 'LG Drove'], $this->davCalendars($anna));
    }

    public function testDefaultOffCalendarsAndLaterChangesOfTheDefault(): void
    {
        $abfall = $this->calendar('abfall', 'Abfall Drove', [], true);
        $this->setDefault($abfall, false);
        $anna = $this->member('1', 'Anna', 'Schmidt');
        $ben = $this->member('2', 'Ben', 'Becker');
        $choice = $this->app->calendarChoice();
        self::assertFalse($choice->isEnabled((int) $anna['id'], $abfall), 'Vorgabe aus');
        self::assertSame([], $this->davCalendars($anna));

        $choice->set((int) $anna['id'], $abfall, true);
        self::assertTrue($choice->isEnabled((int) $anna['id'], $abfall));
        self::assertSame(['Abfall Drove'], $this->davCalendars($anna));

        // Admin ändert die Vorgabe: Ben (nie geschaltet) folgt, Anna (selbst gewählt) behält ihre Wahl
        $this->setDefault($abfall, true);
        self::assertTrue($choice->isEnabled((int) $ben['id'], $abfall));
        $choice->set((int) $anna['id'], $abfall, false);
        self::assertFalse($choice->isEnabled((int) $anna['id'], $abfall));
        $this->setDefault($abfall, false);
        self::assertFalse($choice->isEnabled((int) $ben['id'], $abfall));
        $choice->reset((int) $anna['id'], $abfall);
        self::assertFalse($choice->isEnabled((int) $anna['id'], $abfall), 'nach Zurücksetzen gilt die Vorgabe');
    }

    public function testOnlyUnlockedCalendarsCanBeSwitched(): void
    {
        $this->calendar('jf', 'JF', ['JF']);
        $anna = $this->member('1', 'Anna', 'Schmidt');
        self::assertFalse($this->app->calendarChoice()->set((int) $anna['id'], (int) $this->app->db()->value("SELECT id FROM calendars WHERE slug = 'jf'"), true));
        $this->login($anna);
        $this->web('POST', '/kalender/jf/ein', ['x' => '1']);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM user_calendar_choice'));
        self::assertSame('error', $_SESSION['_flash'][0]['type']);
    }

    public function testChoiceIsDroppedWhenAccessEnds(): void
    {
        $lg = $this->calendar('lg', 'LG', ['LG']);
        $anna = $this->member('1', 'Anna', 'Schmidt', ['LG']);
        $this->app->calendarChoice()->set((int) $anna['id'], $lg, false);
        $this->app->memberships()->replaceMemberships((int) $anna['id'], []);
        $this->app->memberships()->recompute((int) $anna['id'], false);
        self::assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM user_calendar_choice'));
        // kommt die Mitgliedschaft wieder, gilt die Vorgabe (an)
        $this->app->memberships()->replaceMemberships((int) $anna['id'], [['schluessel' => 'LG', 'bezeichnung' => 'LG']]);
        $this->app->memberships()->recompute((int) $anna['id'], false);
        self::assertTrue($this->app->calendarChoice()->isEnabled((int) $anna['id'], $lg));
    }

    public function testNewCalendarMailOnlyForDefaultOn(): void
    {
        $anna = $this->member('1', 'Anna', 'Schmidt');
        $on = $this->calendar('kfv', 'Verband', [], true);
        $off = $this->calendar('abfall', 'Abfall', [], true);
        $this->setDefault($off, false);
        $added = $this->app->memberships()->recompute((int) $anna['id'], true);
        sort($added);
        self::assertSame([$on, $off], $added, 'beide freigeschaltet');
        $mail = (string) $this->app->db()->value("SELECT body_text FROM mail_queue WHERE user_id = ? AND type = 'new_calendar'", [$anna['id']]);
        self::assertStringContainsString('Verband', $mail);
        self::assertStringNotContainsString('Abfall', $mail);
    }

    public function testAccessOnlyMappingDoesNotFillAndFillOnlyDoesNotUnlock(): void
    {
        $tm1 = $this->calendar('tm1', 'TM1 2027');
        $now = $this->clock->nowDb();
        $db = $this->app->db();
        $db->insert('calendar_sources', ['mp_key' => 'LEHRGANG-TM1', 'calendar_id' => $tm1, 'grants_access' => 1, 'fills' => 0, 'created_at' => $now]);
        $db->insert('calendar_sources', ['mp_key' => 'NUR-TERMINE', 'calendar_id' => $tm1, 'grants_access' => 0, 'fills' => 1, 'created_at' => $now]);
        $tn = $this->member('1', 'Tim', 'Teilnehmer', ['LEHRGANG-TM1']);
        $other = $this->member('2', 'Olga', 'Other', ['NUR-TERMINE']);
        self::assertSame([$tm1], $this->app->memberships()->desiredCalendars((int) $tn['id']));
        self::assertSame([], $this->app->memberships()->desiredCalendars((int) $other['id']));
    }
}
