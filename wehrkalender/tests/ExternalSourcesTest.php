<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wehrkalender\Import\IcalError;
use Wehrkalender\Import\SourceFetcher;
use Wehrkalender\Web\WebApp;

/** Verarbeitung externer Termine: Datei-Upload mit Vorschau, Feed-Abruf, Filter, Stand, Plausibilität */
final class ExternalSourcesTest extends TestCase
{
    private const KREIS = __DIR__ . '/fixtures/kreis-lehrgaenge-2027.ics';

    private function source(string $key, string $type = 'upload', array $extra = []): array
    {
        $now = $this->clock->nowDb();
        $id = $this->app->db()->insert('sources', $extra + [
            'source_key' => $key, 'type' => $type, 'name' => ucfirst($key), 'url' => $type === 'ical' ? "https://$key.example.org/f.ics" : null,
            'interval_minutes' => 360, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->app->db()->one('SELECT * FROM sources WHERE id = ?', [$id]);
    }

    private function feed(array $source, int $calendarId, ?string $startsWith = null, array $extra = []): int
    {
        $now = $this->clock->nowDb();
        return $this->app->db()->insert('calendar_feeds', $extra + [
            'calendar_id' => $calendarId, 'source_id' => $source['id'],
            'filter' => $startsWith === null ? null : json_encode(['conditions' => [['field' => 'summary', 'op' => 'starts', 'value' => $startsWith]]]),
            'source_note' => 0, 'show_stand' => 1, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function web(string $method, string $uri, array $body = [], array $files = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== [] || $files !== []) {
            $req = $req->withParsedBody($body + ['_csrf' => 'x'])->withUploadedFiles($files);
        }
        return WebApp::create($this->app)->handle($req);
    }

    private function admin(): void
    {
        $a = $this->member('9', 'Ada', 'Admin');
        $this->app->db()->execute('UPDATE users SET is_admin = 1 WHERE id = ?', [$a['id']]);
        $this->web('GET', '/login');
        $_SESSION = ['user_id' => (int) $a['id'], '_last' => time(), '_csrf' => 'x'];
    }

    private function upload(string $content, string $name = 'plan.ics'): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($content);
        return new UploadedFile($stream, $name, 'text/calendar', $stream->getSize(), UPLOAD_ERR_OK);
    }

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private function vevent(string $uid, string $summary, string $start, string $end = '', string $extra = ''): string
    {
        $end = $end ?: $start;
        return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261101T000000Z\r\nSUMMARY:$summary\r\n"
            . "DTSTART;TZID=Europe/Berlin:{$start}\r\nDTEND;TZID=Europe/Berlin:{$end}\r\n$extra" . "END:VEVENT\r\n";
    }

    public function testKreisFileThroughUploadPreviewAndApply(): void
    {
        $this->admin();
        $db = $this->app->db();
        $kreis = $this->source('kreis');
        $m2 = $this->calendar('maschinist-2-2027', 'Maschinist 2/2027');
        $tf = $this->calendar('truppfuehrer-1-2027', 'Truppführer 1/2027');
        $this->feed($kreis, $m2, 'Maschinist 2/2027', ['show_stand' => 0]);
        // Truppführer-Durchgang aus drei Bausteinen: zwei Befüllungen derselben Quelle (wirken als „oder“)
        $this->feed($kreis, $tf, 'Truppführer Theorie 1/2027');
        $this->feed($kreis, $tf, 'Truppführer Praxis 1/2027');

        $res = $this->web('POST', '/admin/quellen/' . $kreis['id'] . '/datei', ['x' => '1'], ['datei' => $this->upload((string) file_get_contents(self::KREIS), 'Lehrgaenge_Kreis_Dueren_2027.ics')]);
        self::assertSame(303, $res->getStatusCode());
        $pending = $db->one("SELECT * FROM source_files WHERE status = 'pending'");
        self::assertSame(105, (int) $pending['event_count']);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM events'), 'Vorschau ändert nichts');

        $preview = (string) $this->web('GET', '/admin/quellen/datei/' . $pending['id'])->getBody();
        self::assertStringContainsString('105 Termine erkannt', $preview);
        self::assertStringContainsString('<strong>4</strong> neu', $preview, 'Maschinist 2/2027: 4 Tage');
        self::assertStringContainsString('<strong>5</strong> neu', $preview, 'Truppführer Theorie: 5 Tage (Praxis: 4)');
        self::assertSame(3, substr_count($preview, '<h2 style="margin-top:0">'), 'eine Übersicht je Befüllung');

        $this->web('POST', '/admin/quellen/datei/' . $pending['id'] . '/uebernehmen', ['x' => '1']);
        self::assertSame(4, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$m2]));
        self::assertSame(9, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$tf]));
        $file = $db->one("SELECT * FROM source_files WHERE status = 'active'");
        self::assertSame((int) $pending['id'], (int) $file['id']);
        self::assertSame(13, (int) $db->value('SELECT COUNT(*) FROM events WHERE file_id = ?', [$file['id']]));
        $desc = (string) $db->value("SELECT description FROM events WHERE calendar_id = ? ORDER BY starts_at LIMIT 1", [$m2]);
        self::assertStringEndsWith('Stand 09.09.2026', $desc, 'kein eigener Stand, die Datei hat schon einen');
        self::assertStringContainsString('Stand ' . $this->clock->now()->format('d.m.Y'),
            (string) $db->value("SELECT description FROM events WHERE calendar_id = ? LIMIT 1", [$tf]));
        self::assertStringStartsWith('kreis-', (string) $db->value('SELECT uid FROM events LIMIT 1'));

        // neue Fassung: Maschinist 2/2027 Tag 4 verschoben, Truppführer Praxis 1 abgesagt (fehlt) → Vorschau zeigt es
        $v2 = str_replace(['DTSTART;TZID=Europe/Berlin:20270710T080000', 'SUMMARY:Truppführer Praxis 1/2027'],
            ['DTSTART;TZID=Europe/Berlin:20270717T080000', 'SUMMARY:entfallen Truppführer Praxis 1/2027'], (string) file_get_contents(self::KREIS));
        $tokenBefore = (int) $db->value('SELECT sync_token FROM calendars WHERE id = ?', [$m2]);
        $this->web('POST', '/admin/quellen/' . $kreis['id'] . '/datei', ['replaces_id' => (string) $file['id']], ['datei' => $this->upload($v2, 'Lehrgaenge_v2.ics')]);
        $p2 = $db->one("SELECT * FROM source_files WHERE status = 'pending'");
        $preview = (string) $this->web('GET', '/admin/quellen/datei/' . $p2['id'])->getBody();
        self::assertStringContainsString('neue Fassung von „Lehrgaenge_Kreis_Dueren_2027.ics“', $preview);
        self::assertStringContainsString('<strong>4</strong> entfernt', $preview);
        self::assertStringContainsString('Auffällig: Der Filter trifft keinen Termin mehr (bisher 4)', $preview, 'Plausibilität');
        self::assertStringContainsString('trotzdem übernehmen', $preview);
        $this->web('POST', '/admin/quellen/datei/' . $p2['id'] . '/uebernehmen', ['force' => '1']);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM source_files"), 'neue Fassung ersetzt die alte');
        self::assertSame('Lehrgaenge_v2.ics', (string) $db->value('SELECT name FROM source_files WHERE id = ?', [$file['id']]));
        self::assertSame(5, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$tf]));
        self::assertSame(1, (int) $db->value('SELECT sequence_no FROM events WHERE calendar_id = ? AND summary LIKE ?', [$m2, '%Tag 4/4']));
        self::assertGreaterThan($tokenBefore, (int) $db->value('SELECT sync_token FROM calendars WHERE id = ?', [$m2]));

        // gleiche Datei noch einmal: nichts ändert sich (auch nicht der Stand)
        $before = $db->column('SELECT description FROM events ORDER BY id');
        $this->clock->fix($this->clock->now()->modify('+3 days'));
        $this->web('POST', '/admin/quellen/' . $kreis['id'] . '/datei', ['replaces_id' => (string) $file['id']], ['datei' => $this->upload($v2, 'Lehrgaenge_v2.ics')]);
        $p3 = (int) $db->value("SELECT id FROM source_files WHERE status = 'pending'");
        self::assertStringContainsString('identisch', (string) $this->web('GET', "/admin/quellen/datei/$p3")->getBody());
        $this->web('POST', "/admin/quellen/datei/$p3/uebernehmen", ['x' => '1']);
        self::assertSame($before, $db->column('SELECT description FROM events ORDER BY id'));

        // Datei entfernen → Termine weg
        $this->web('POST', '/admin/quellen/datei/' . $file['id'] . '/loeschen', ['x' => '1']);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM events'));
    }

    public function testRejectedUploadsAndDiscard(): void
    {
        $this->admin();
        $db = $this->app->db();
        $src = $this->source('abfall');
        $this->web('POST', "/admin/quellen/{$src['id']}/datei", ['x' => '1'], ['datei' => $this->upload('<html><body>Bitte anmelden</body></html>')]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM source_files'));
        self::assertStringContainsString('Webseite', $_SESSION['_flash'][0]['message'] ?? $_SESSION['_flash'][0]['text'] ?? json_encode($_SESSION['_flash']));
        $this->web('POST', "/admin/quellen/{$src['id']}/datei", ['x' => '1'], ['datei' => $this->upload(str_repeat('X', 2 * 1024 * 1024 + 1))]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM source_files'), 'zu groß');

        $this->web('POST', "/admin/quellen/{$src['id']}/datei", ['x' => '1'], ['datei' => $this->upload($this->ics($this->vevent('a', 'Restmüll', '20270105T000000')))]);
        $id = (int) $db->value('SELECT id FROM source_files');
        self::assertStringContainsString('noch keinen Kalender', (string) $this->web('GET', "/admin/quellen/datei/$id")->getBody());
        $this->web('POST', "/admin/quellen/datei/$id/verwerfen", ['x' => '1']);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM source_files'));
    }

    public function testSeriesAllDayAndContentIds(): void
    {
        $proc = $this->app->icalProcessor();
        $ics = $this->ics(
            "BEGIN:VEVENT\r\nUID:r1\r\nDTSTART;VALUE=DATE:20261201\r\nDTEND;VALUE=DATE:20261202\r\nRRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=3\r\nSUMMARY:Restmüll\r\nEND:VEVENT\r\n",
            "BEGIN:VEVENT\r\nUID:w\r\nDTSTART;VALUE=DATE:20261219\r\nDTEND;VALUE=DATE:20261221\r\nSUMMARY:Wochenende\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n",
        );
        $events = $proc->parse($ics);
        self::assertCount(4, $events);
        self::assertSame(['2026-12-01', '2026-12-15', '2026-12-19', '2026-12-29'], array_map(fn ($e) => $e['start']->format('Y-m-d'), $events));
        self::assertTrue($events[0]['all_day']);
        self::assertSame('2026-12-01', $events[0]['end']->format('Y-m-d'), 'ganztägig: letzter Tag einschließlich');
        self::assertSame('2026-12-20', $events[2]['end']->format('Y-m-d'));
        self::assertTrue($events[2]['cancelled']);

        // Kennung aus Inhalt: neue UIDs bei jedem Export ändern nichts
        $src = $this->source('gemeinde', 'upload', ['id_from_content' => 1]);
        $cal = $this->calendar('abfall', 'Abfall');
        $this->feed($src, $cal);
        $proc->apply($proc->plan($src, $proc->parse($this->ics($this->vevent(uniqid(), 'Papier', '20270105T070000')))));
        $plan = $proc->plan($src, $proc->parse($this->ics($this->vevent(uniqid(), 'Papier', '20270105T070000'))));
        self::assertSame(1, $plan['feeds'][array_key_first($plan['feeds'])]['unchanged']);

        foreach (['', 'BEGIN:VCALENDAR' . "\r\n" . 'kaputt', "<!doctype html><html>"] as $bad) {
            try {
                $proc->parse($bad);
                self::fail('ungültige Datei akzeptiert');
            } catch (IcalError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFetchingFeedsWithPlausibilityAndStates(): void
    {
        $db = $this->app->db();
        $src = $this->source('kfv', 'ical');
        $cal = $this->calendar('kfv', 'Verband', [], true);
        $this->feed($src, $cal, null, ['source_note' => 1]);
        $events = [];
        for ($i = 1; $i <= 12; $i++) {
            $events[] = $this->vevent("t$i", "Termin $i", sprintf('202701%02dT190000', $i), sprintf('202701%02dT210000', $i));
        }
        $full = $this->ics(...$events);
        $responses = [
            new MockResponse($full, ['response_headers' => ['ETag' => '"v1"']]),
            new MockResponse('', ['http_code' => 304]),
            new MockResponse($this->ics(...array_slice($events, 0, 3))),   // 9 von 12 weg → verdächtig
            new MockResponse('Not found', ['http_code' => 404]),
            new MockResponse($this->ics()),                                // leer
            new MockResponse('<html>Wartung</html>'),
        ];
        $requests = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$responses, &$requests) {
            $requests[] = $options['normalized_headers'] ?? [];
            return array_shift($responses);
        });
        $fetcher = new SourceFetcher($this->app, $http);
        $reload = fn () => $db->one('SELECT * FROM sources WHERE id = ?', [$src['id']]);

        self::assertSame([$src['id']], array_map(fn ($s) => $s['id'], $fetcher->due()), 'noch nie abgerufen → fällig');
        $r = $fetcher->fetch($reload());
        self::assertSame('ok', $r['status'], $r['message']);
        self::assertSame(12, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$cal]));
        self::assertStringContainsString('Quelle: Kfv · Stand ', (string) $db->value('SELECT description FROM events LIMIT 1'));
        self::assertSame('"v1"', $reload()['http_etag']);
        self::assertSame('ok', $this->app->sourceState()->state($reload()));
        self::assertSame([], $fetcher->due(), 'Intervall noch nicht abgelaufen');

        self::assertSame('unchanged', $fetcher->fetch($reload())['status']);
        self::assertSame(['If-None-Match: "v1"'], $requests[1]['if-none-match'] ?? null, 'bedingter Abruf');

        $r = $fetcher->fetch($reload());
        self::assertSame('blocked', $r['status']);
        self::assertSame(12, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$cal]), 'nichts gelöscht');
        self::assertSame('error', $this->app->sourceState()->state($reload()));
        self::assertStringContainsString('9 von 12', (string) $reload()['last_error']);

        self::assertSame('HTTP 404', $fetcher->fetch($reload())['message']);
        self::assertStringContainsString('keine Termine', $fetcher->fetch($reload())['message']);
        self::assertStringContainsString('Webseite', $fetcher->fetch($reload())['message']);
        self::assertSame(12, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$cal]));
        self::assertContains('Quelle „Kfv“: letzter Abruf fehlgeschlagen (Statt eines Kalenders kam eine Webseite zurück (Fehlerseite oder Anmeldung?).).',
            $this->app->status()->problems(), 'Warnung in /health und E-Mail');

        // interne Adresse wird gar nicht erst abgerufen
        $db->update('sources', ['url' => 'https://192.168.0.10/x.ics'], ['id' => $src['id']]);
        self::assertStringContainsString('private', $fetcher->fetch($reload())['message']);
    }

    public function testCalendarStandInFeedCalDavAndHome(): void
    {
        $src = $this->source('abfall', 'ical', ['last_run_at' => $this->clock->db('-2 hours'), 'last_success_at' => $this->clock->db('-2 hours')]);
        $cal = $this->calendar('abfall', 'Abfall Drove', [], true);
        $this->feed($src, $cal);
        $anna = $this->member('1', 'Anna', 'Schmidt');
        $stand = $this->app->sourceState()->calendarStands()[$cal];
        self::assertSame('Quelle: Abfall · Stand ' . $this->clock->now()->modify('-2 hours')->format('d.m.Y'), $stand['text']);
        self::assertFalse($stand['stale']);

        $token = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ?', [$anna['id']]);
        self::assertStringContainsString('X-WR-CALDESC:Quelle: Abfall · Stand', (string) $this->web('GET', "/abo/$token.ics")->getBody());

        $this->web('GET', '/login');
        $_SESSION = ['user_id' => (int) $anna['id'], '_last' => time(), '_csrf' => 'x'];
        self::assertStringContainsString('Quelle: Abfall · Stand', (string) $this->web('GET', '/')->getBody());

        // veraltet: länger als zwei Intervalle (mind. 1 Tag) kein Abruf
        $this->app->db()->update('sources', ['last_success_at' => $this->clock->db('-3 days')], ['id' => $src['id']]);
        self::assertTrue($this->app->sourceState()->calendarStands()[$cal]['stale']);
        self::assertStringContainsString('cal-stand stale', (string) $this->web('GET', '/')->getBody());
    }
}
