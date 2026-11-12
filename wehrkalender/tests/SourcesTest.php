<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Wehrkalender\Calendar\FeedFilter;
use Wehrkalender\Clock;
use Wehrkalender\Web\SourcesController;
use Wehrkalender\Web\WebApp;

/** Quellen, Befüllungen und Filter (Konzept E7; der iCal-Importer folgt) */
final class SourcesTest extends TestCase
{
    private function source(string $key): int
    {
        $now = $this->clock->nowDb();
        return $this->app->db()->insert('sources', [
            'source_key' => $key, 'type' => 'ical', 'name' => ucfirst($key), 'url' => "https://$key.example.org/f.ics",
            'interval_minutes' => 360, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function admin(): void
    {
        $a = $this->member('9', 'Ada', 'Admin');
        $this->app->db()->execute('UPDATE users SET is_admin = 1 WHERE id = ?', [$a['id']]);
        $this->post('/login', []);
        $_SESSION = ['user_id' => (int) $a['id'], '_last' => time(), '_csrf' => 'x'];
    }

    private function post(string $uri, array $body): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($body === [] ? 'GET' : 'POST', $uri);
        if ($body !== []) {
            $req = $req->withParsedBody($body + ['_csrf' => 'x']);
        }
        return WebApp::create($this->app)->handle($req);
    }

    private function ev(string $title, string $start, string $end, ?string $cat = null): array
    {
        $tz = Clock::localTz();
        return ['summary' => $title, 'categories' => $cat, 'start' => new DateTimeImmutable($start, $tz), 'end' => new DateTimeImmutable($end, $tz)];
    }

    public function testFilterMatchesDescribesAndValidates(): void
    {
        $f = FeedFilter::fromForm([
            'field' => ['summary', 'summary', 'location'], 'op' => ['contains', 'regex', 'contains'], 'value' => ['tm1', '^TM1 (Aus|Prü)', ''],
            'from' => '2027-01-01', 'to' => '2027-12-31',
        ]);
        self::assertCount(2, $f->conditions, 'leere Zeile ignoriert');
        self::assertTrue($f->matches($this->ev('TM1 Ausbildungstag 1', '2027-01-16 08:00', '2027-01-16 17:00')));
        self::assertFalse($f->matches($this->ev('TM1 Lehrgangswochenende', '2027-02-19', '2027-02-21')), 'Regex trifft nicht');
        self::assertFalse($f->matches($this->ev('TM1 Ausbildungstag 1', '2026-11-07 08:00', '2026-11-07 17:00')), 'außerhalb des Zeitraums');
        self::assertFalse($f->matches($this->ev('TH Ausbildungstag', '2027-01-16 08:00', '2027-01-16 17:00')));
        self::assertTrue($f->matches($this->ev('TM1 Prüfung', '2027-12-31 08:00', '2027-12-31 14:00')), 'letzter Tag zählt mit');
        self::assertSame('Titel enthält „tm1“ · Titel regulärer Ausdruck „^TM1 (Aus|Prü)“ · 01.01.2027–31.12.2027', $f->describe());
        self::assertSame($f->describe(), FeedFilter::fromJson($f->toJson())->describe(), 'JSON hin und zurück');

        $starts = new FeedFilter([['field' => 'categories', 'op' => 'starts', 'value' => 'feuerwehr']]);
        self::assertTrue($starts->matches($this->ev('Dorffest', '2027-05-01', '2027-05-02', 'Feuerwehr, Fest')));
        self::assertFalse($starts->matches($this->ev('Konzert', '2027-05-01', '2027-05-02', 'Musik')));

        self::assertSame('alle Termine', FeedFilter::fromJson(null)->describe());
        self::assertNull((new FeedFilter())->toJson());
        foreach ([
            fn () => new FeedFilter([['field' => 'summary', 'op' => 'regex', 'value' => '(']]),
            fn () => new FeedFilter([['field' => 'uid', 'op' => 'contains', 'value' => 'x']]),
            fn () => new FeedFilter([], '2027-12-31', '2027-01-01'),
            fn () => new FeedFilter([], '31.12.2027'),
        ] as $bad) {
            try {
                $bad();
                self::fail('Ungültiger Filter wurde akzeptiert');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventsAreKeptApartPerSource(): void
    {
        $this->source('kreis');
        $cal = $this->calendar('tm1', 'TM1');
        $tz = Clock::localTz();
        $events = $this->app->events();
        $base = ['calendar_id' => $cal, 'summary' => 'Ausbildungstag', 'start' => new DateTimeImmutable('2027-01-16 08:00', $tz), 'end' => new DateTimeImmutable('2027-01-16 17:00', $tz)];
        self::assertSame('added', $events->save($base + ['external_id' => '4711']));
        self::assertSame('added', $events->save($base + ['external_id' => '4711', 'source' => 'kreis']), 'gleiche ID, andere Quelle');
        self::assertSame(['kreis-4711@kalender.example.de', 'mp-4711@kalender.example.de'], $this->app->db()->column('SELECT uid FROM events ORDER BY uid'));
        self::assertTrue($events->delete('4711', 'kreis'));
        self::assertSame(['mp-4711@kalender.example.de'], $this->app->db()->column('SELECT uid FROM events'), 'Löschen nur in der eigenen Quelle');
        $this->expectException(\RuntimeException::class);
        $events->save($base + ['external_id' => '1', 'source' => 'gibt-es-nicht']);
    }

    public function testUrlCheck(): void
    {
        self::assertNull(SourcesController::urlError('https://kreis-dueren.example.org/lehrgaenge.ics'));
        foreach (['http://kreis.example.org/a.ics', 'webcal://kreis.example.org/a.ics', 'https://user:pw@kreis.example.org/a.ics',
                  'https://192.168.1.10/a.ics', 'https://10.0.0.5/a.ics', 'https://127.0.0.1/a.ics', 'https://[::1]/a.ics',
                  'https://localhost/a.ics', 'https://db/a.ics', 'https://nas.local/a.ics', ''] as $url) {
            self::assertNotNull(SourcesController::urlError($url), $url);
        }
    }

    public function testAdminManagesSourcesAndFeeds(): void
    {
        $this->admin();
        $cal = $this->calendar('tm1', 'TM1 2027');
        $db = $this->app->db();

        $this->post('/admin/quellen', ['source_key' => 'kreis', 'name' => 'Lehrgänge Kreis', 'url' => 'http://kreis.example.org/a.ics', 'interval_minutes' => '360', 'active' => '1']);
        self::assertNull($db->value("SELECT id FROM sources WHERE source_key = 'kreis'"), 'nur HTTPS');
        $this->post('/admin/quellen', ['source_key' => 'mp', 'name' => 'X', 'url' => 'https://x.example.org/a.ics', 'interval_minutes' => '360']);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM sources WHERE source_key = 'mp'"), 'Schlüssel mp ist vergeben');

        $this->post('/admin/quellen', ['source_key' => 'kreis', 'name' => 'Lehrgänge Kreis', 'url' => 'https://kreis.example.org/a.ics', 'interval_minutes' => '360', 'active' => '1']);
        $src = (int) $db->value("SELECT id FROM sources WHERE source_key = 'kreis'");
        self::assertGreaterThan(0, $src);

        $this->post('/admin/befuellung', ['source_id' => (string) $src, 'calendar_id' => (string) $cal, 'field' => ['summary'], 'op' => ['regex'], 'value' => ['(']]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM calendar_feeds'), 'ungültiger Filter abgelehnt');
        $this->post('/admin/befuellung', ['source_id' => (string) $src, 'calendar_id' => (string) $cal, 'active' => '1',
            'field' => ['summary', 'summary', 'summary'], 'op' => ['contains', 'contains', 'contains'], 'value' => ['TM1', '', ''],
            'from' => '2027-01-01', 'to' => '2027-12-31']);
        $feed = $db->one('SELECT * FROM calendar_feeds');
        self::assertSame('{"conditions":[{"field":"summary","op":"contains","value":"TM1"}],"from":"2027-01-01","to":"2027-12-31"}', $feed['filter']);

        $page = (string) $this->post('/admin/quellen', [])->getBody();
        self::assertStringContainsString('Lehrgänge Kreis', $page);
        self::assertStringContainsString('Titel enthält „TM1“ · 01.01.2027–31.12.2027', $page);
        self::assertStringContainsString('noch nie abgerufen', $page);
        self::assertStringContainsString('noch nie erfolgreich abgerufen', (string) $this->post('/admin', [])->getBody(), 'Warnung auf der Statusseite');

        // Termin der Befüllung anlegen (wie später der Importer); Quelle mit Terminen lässt sich nicht löschen
        $tz = Clock::localTz();
        $this->app->events()->save(['source' => 'kreis', 'feed_id' => (int) $feed['id'], 'external_id' => 'abc', 'calendar_id' => $cal,
            'summary' => 'TM1 Tag 1', 'start' => new DateTimeImmutable('2027-01-16 08:00', $tz), 'end' => new DateTimeImmutable('2027-01-16 17:00', $tz)]);
        $this->post("/admin/quellen/$src/loeschen", ['x' => '1']);
        self::assertNotNull($db->value('SELECT id FROM sources WHERE id = ?', [$src]));

        // Befüllung entfernen entfernt ihre Termine (mit Sync-Protokoll), danach lässt sich die Quelle löschen
        $token = (int) $db->value('SELECT sync_token FROM calendars WHERE id = ?', [$cal]);
        $this->post('/admin/befuellung/' . $feed['id'] . '/loeschen', ['x' => '1']);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM events'));
        self::assertGreaterThan($token, (int) $db->value('SELECT sync_token FROM calendars WHERE id = ?', [$cal]));
        $this->post("/admin/quellen/$src/loeschen", ['x' => '1']);
        self::assertNull($db->value('SELECT id FROM sources WHERE id = ?', [$src]));
        self::assertNotNull($db->value("SELECT id FROM sources WHERE source_key = 'mp'"), 'MP Feuer bleibt');
    }

    public function testCalendarDefaultAndMappingFlagsInAdmin(): void
    {
        $this->admin();
        $this->post('/admin/kalender', ['display_name' => 'Abfall Drove', 'slug' => 'abfall-drove', 'color' => '#78716C', 'active' => '1', 'all_members' => '1']);
        $db = $this->app->db();
        self::assertSame(0, (int) $db->value("SELECT default_enabled FROM calendars WHERE slug = 'abfall-drove'"), 'Vorgabe aus, wenn nicht angehakt');
        $cal = (int) $db->value("SELECT id FROM calendars WHERE slug = 'abfall-drove'");
        $this->post('/admin/zuordnung', ['mp_key' => 'X', 'calendar_id' => (string) $cal]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM calendar_sources'), 'weder freischalten noch befüllen');
        $this->post('/admin/zuordnung', ['mp_key' => 'LEHRGANG', 'calendar_id' => (string) $cal, 'grants_access' => '1']);
        self::assertSame(['1', '0'], array_map('strval', array_values($db->one('SELECT grants_access, fills FROM calendar_sources'))));
        $page = (string) $this->post('/admin/kalender', [])->getBody();
        self::assertStringContainsString('Vorgabe aus', $page);
        self::assertStringContainsString('freischalten', $page);
    }
}
