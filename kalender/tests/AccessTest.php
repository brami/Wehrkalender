<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\Dav\DavServerFactory;
use Kalender\Web\WebApp;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Zugriffsschutz von CalDAV und iCal-Feed: fremde Kalender, entzogene
 * Zuordnung, gesperrte Benutzer, widerrufene Tokens, Schreibversuche.
 */
final class AccessTest extends TestCase
{
    private int $drove;
    private int $kf;
    private array $juergen;
    private array $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar('ff-krz', 'FF KRZ', [], true);
        $this->drove = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $this->kf = $this->calendar('kf', 'KF', ['KF']);
        $this->event($this->drove, 'D1', '2027-01-14 19:00', '2027-01-14 21:00');
        $this->event($this->kf, 'K1', '2027-02-06 10:00', '2027-02-06 12:00');
        $this->juergen = $this->member('1', 'Jürgen', 'Müller', ['LG-DROVE', 'KF']);
        $this->anna = $this->member('2', 'Anna', 'Becker', ['LG-DROVE']);
    }

    private function dav(string $method, string $path, ?array $user, string $body = '', array $headers = []): Response
    {
        if ($user !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($user['username'] . ':' . $this->password($user));
        }
        return self::runDav($this->app, new Request($method, $path, $headers, $body));
    }

    /** wie im Betrieb über Server::start(), nur ohne Ausgabe */
    public static function runDav(\Kalender\App $app, Request $request): Response
    {
        $server = DavServerFactory::create($app, '127.0.0.1', 'PHPUnit');
        $server->sapi = new class extends \Sabre\HTTP\Sapi {
            public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void
            {
            }
        };
        $server->httpRequest = $request;
        $server->httpResponse = new Response();
        $server->start();
        return $server->httpResponse;
    }

    private function feed(string $token): int
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/abo/' . $token . '.ics');
        return WebApp::create($this->app)->handle($req)->getStatusCode();
    }

    private function token(array $user, int $calendarId): string
    {
        return (string) $this->app->db()->value(
            'SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? ORDER BY id DESC', [$user['id'], $calendarId]);
    }

    public function testOwnCalendarsVisible(): void
    {
        $r = $this->dav('PROPFIND', '/dav/calendars/juergen.mueller/', $this->juergen, '', ['Depth' => '1']);
        self::assertSame(207, $r->getStatus());
        $body = $r->getBodyAsString();
        foreach (['ff-krz', 'lg-drove', 'kf'] as $slug) {
            self::assertStringContainsString("/dav/calendars/juergen.mueller/$slug/", $body);
        }
    }

    public function testForeignCalendarsAndPrincipalsInvisible(): void
    {
        self::assertSame(404, $this->dav('PROPFIND', '/dav/calendars/anna.becker/', $this->juergen, '', ['Depth' => '1'])->getStatus());
        self::assertSame(404, $this->dav('GET', '/dav/calendars/anna.becker/lg-drove/mp-D1@kalender.example.de.ics', $this->juergen)->getStatus());
        self::assertSame(404, $this->dav('PROPFIND', '/dav/calendars/anna.becker/kf/', $this->anna, '', ['Depth' => '0'])->getStatus(), 'Anna hat KF nicht');
        $list = $this->dav('PROPFIND', '/dav/principals/', $this->juergen, '', ['Depth' => '1'])->getBodyAsString();
        self::assertStringNotContainsString('anna.becker', $list);
    }

    public function testWritesAreForbidden(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20270101T100000Z\r\nDTEND:20270101T110000Z\r\nSUMMARY:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        self::assertSame(403, $this->dav('PUT', '/dav/calendars/juergen.mueller/lg-drove/neu.ics', $this->juergen, $ics)->getStatus());
        self::assertSame(403, $this->dav('PUT', '/dav/calendars/juergen.mueller/lg-drove/mp-D1@kalender.example.de.ics', $this->juergen, $ics)->getStatus());
        self::assertSame(403, $this->dav('DELETE', '/dav/calendars/juergen.mueller/lg-drove/mp-D1@kalender.example.de.ics', $this->juergen)->getStatus());
        self::assertSame(403, $this->dav('DELETE', '/dav/calendars/juergen.mueller/lg-drove/', $this->juergen)->getStatus());
        self::assertSame(403, $this->dav('MKCALENDAR', '/dav/calendars/juergen.mueller/neu/', $this->juergen)->getStatus());
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM events WHERE mp_event_id = 'D1'"));
    }

    public function testWrongPasswordAndLockedUser(): void
    {
        $bad = $this->juergen;
        $r = new Request('PROPFIND', '/dav/', ['Authorization' => 'Basic ' . base64_encode('juergen.mueller:falsch')]);
        self::assertSame(401, self::runDav($this->app, $r)->getStatus());

        $token = $this->token($bad, $this->drove);
        self::assertSame(200, $this->feed($token));
        $this->app->db()->update('users', ['status' => 'locked_email'], ['id' => $bad['id']]);
        self::assertSame(401, $this->dav('PROPFIND', '/dav/', $bad)->getStatus());
        self::assertSame(404, $this->feed($token));
        $this->app->db()->update('users', ['status' => 'active'], ['id' => $bad['id']]);
        self::assertSame(207, $this->dav('PROPFIND', '/dav/', $bad)->getStatus(), 'nach Korrektur wieder gültig');
        self::assertSame(200, $this->feed($token));
    }

    public function testRemovedAssignment(): void
    {
        $token = $this->token($this->juergen, $this->kf);
        self::assertSame(200, $this->feed($token));
        $this->app->memberships()->replaceMemberships((int) $this->juergen['id'], [['schluessel' => 'LG-DROVE', 'bezeichnung' => 'LG Drove']]);
        $this->app->memberships()->recompute((int) $this->juergen['id']);
        self::assertSame(404, $this->feed($token));
        self::assertSame(404, $this->dav('PROPFIND', '/dav/calendars/juergen.mueller/kf/', $this->juergen, '', ['Depth' => '0'])->getStatus());
        // Zuordnung kommt zurück → alter Link gilt wieder
        $this->app->memberships()->replaceMemberships((int) $this->juergen['id'], [['schluessel' => 'LG-DROVE', 'bezeichnung' => 'x'], ['schluessel' => 'KF', 'bezeichnung' => 'y']]);
        $added = $this->app->memberships()->recompute((int) $this->juergen['id']);
        self::assertSame([$this->kf], $added);
        self::assertSame(200, $this->feed($token));
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE type = 'new_calendar'"));

        // Hinweis-E-Mail: kein Abo-Link, aber ein Anmeldelink (14 Tage)
        $mail = $this->app->db()->one("SELECT * FROM mail_queue WHERE type = 'new_calendar'");
        self::assertStringNotContainsString('/abo/', $mail['body_text'] . $mail['body_html']);
        self::assertSame(1, preg_match('#/login/link/([A-Za-z0-9_-]{43})#', $mail['body_text'], $m));
        $check = $this->app->accounts()->checkLoginToken($m[1]);
        self::assertSame('ok', $check['status']);
        self::assertSame('notice', $check['token']['purpose']);
        $this->clock->fix($this->clock->now()->modify('+13 days'));
        self::assertSame('ok', $this->app->accounts()->checkLoginToken($m[1])['status']);
        $this->clock->fix($this->clock->now()->modify('+2 days'));
        self::assertSame('expired', $this->app->accounts()->checkLoginToken($m[1])['status']);
    }

    public function testRegeneratedFeedTokens(): void
    {
        $old = $this->token($this->juergen, $this->drove);
        $this->app->accounts()->regenerateFeedTokens((int) $this->juergen['id']);
        $new = $this->token($this->juergen, $this->drove);
        self::assertNotSame($old, $new);
        self::assertSame(404, $this->feed($old));
        self::assertSame(200, $this->feed($new));
        self::assertSame(404, $this->feed('gibtsnicht'));
    }

    public function testRegeneratedPasswordAndRenamedUser(): void
    {
        $old = $this->juergen;
        $oldPw = $this->password($old);
        $this->app->accounts()->regeneratePassword((int) $old['id']);
        $r = new Request('PROPFIND', '/dav/', ['Authorization' => 'Basic ' . base64_encode('juergen.mueller:' . $oldPw)]);
        self::assertSame(401, self::runDav($this->app, $r)->getStatus());
        self::assertSame(207, $this->dav('PROPFIND', '/dav/', $old)->getStatus());

        $this->app->db()->update('users', ['last_name' => 'Meier'], ['id' => $old['id']]);
        $feed = $this->token($old, $this->drove);
        $this->app->accounts()->changeUsername((int) $old['id'], (int) $old['id']);
        self::assertSame(401, $this->dav('PROPFIND', '/dav/', $old)->getStatus(), 'alter Benutzername');
        $renamed = $this->app->accounts()->find((int) $old['id']);
        self::assertSame(207, $this->dav('PROPFIND', '/dav/calendars/juergen.meier/', $renamed, '', ['Depth' => '1'])->getStatus());
        self::assertSame(200, $this->feed($feed), 'Abo-Links hängen nicht am Benutzernamen');
    }

    public function testSyncCollection(): void
    {
        $body = '<d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';
        $r = $this->dav('REPORT', '/dav/calendars/juergen.mueller/lg-drove/', $this->juergen, $body);
        self::assertSame(207, $r->getStatus());
        preg_match('#<d:sync-token>([^<]+)#', $r->getBodyAsString(), $m);
        $this->event($this->drove, 'D2', '2027-01-21 19:00', '2027-01-21 21:00');
        $this->app->events()->delete('D1');
        $body = '<d:sync-collection xmlns:d="DAV:"><d:sync-token>' . $m[1] . '</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';
        $xml = $this->dav('REPORT', '/dav/calendars/juergen.mueller/lg-drove/', $this->juergen, $body)->getBodyAsString();
        self::assertStringContainsString('mp-D2@kalender.example.de.ics', $xml);
        self::assertMatchesRegularExpression('#mp-D1@kalender.example.de.ics</d:href>\s*<d:status>HTTP/1.1 404#', $xml);
    }

    public function testTimeRangeQuery(): void
    {
        $this->event($this->drove, 'D2', '2027-03-04 19:00', '2027-03-04 21:00');
        $body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="20270301T000000Z" end="20270401T000000Z"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        $xml = $this->dav('REPORT', '/dav/calendars/juergen.mueller/lg-drove/', $this->juergen, $body, ['Depth' => '1'])->getBodyAsString();
        self::assertStringContainsString('mp-D2@', $xml);
        self::assertStringNotContainsString('mp-D1@', $xml);
    }
}
