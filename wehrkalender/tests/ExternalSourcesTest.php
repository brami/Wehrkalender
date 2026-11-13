<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\Clock;
use Wehrkalender\Import\IcalProcessor;

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
    private const KREIS_2026 = __DIR__ . '/fixtures/kreis-lehrgaenge-2026.ics';

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
        self::assertSame(4, substr_count($preview, '<h2 style="margin-top:0">'), 'eine Übersicht je Befüllung + „Nicht übernommen“');
        self::assertStringContainsString('92 von 105 Terminen (32 verschiedene Titel) trifft keine Befüllung', $preview);
        self::assertStringContainsString('<summary>Atemschutzgeräteträger 1/2027</summary>', $preview, 'je Lehrgang eine Zeile');
        self::assertStringContainsString('13.02.–06.03.2027', $preview, 'Zeitraum der Gruppe');
        // alphabetisch mit natürlicher Zahlenfolge
        self::assertLessThan(strpos($preview, '<summary>Atemschutzgeräteträger 1/2027'), strpos($preview, '<summary>ABC 1 Modul 1'));
        self::assertLessThan(strpos($preview, '<summary>Truppführer Theorie 2/2027'), strpos($preview, '<summary>Atemschutzgeräteträger 4/2027'));

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

        // neue Befüllung, Datei „erneut übernehmen“ ohne neuen Upload: Vorschau zeigt nur die neue Befüllung als neu
        $agt = $this->calendar('atemschutz-2-2027', 'Atemschutz 2/2027');
        $this->feed($kreis, $agt, 'Atemschutzgeräteträger 2/2027');
        $uploadedAt = (string) $file['uploaded_at'];
        $this->clock->fix($this->clock->now()->modify('+1 day'));
        self::assertSame(303, $this->web('POST', '/admin/quellen/datei/' . $file['id'] . '/erneut', ['x' => '1'])->getStatusCode());
        $re = $db->one("SELECT * FROM source_files WHERE status = 'pending'");
        self::assertSame((int) $file['id'], (int) $re['replaces_id']);
        $preview = (string) $this->web('GET', '/admin/quellen/datei/' . $re['id'])->getBody();
        self::assertStringContainsString('identisch', $preview);
        self::assertSame(1, substr_count($preview, '<strong>4</strong> neu'), 'nur Atemschutz neu');
        self::assertStringContainsString('88 von 105 Terminen (31 verschiedene Titel)', $preview);
        $this->web('POST', '/admin/quellen/datei/' . $re['id'] . '/uebernehmen', ['x' => '1']);
        self::assertSame(4, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$agt]));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM source_files'));
        $file = $db->one('SELECT * FROM source_files WHERE id = ?', [$file['id']]);
        self::assertSame($uploadedAt, (string) $file['uploaded_at'], 'Hochladedatum bleibt beim erneuten Übernehmen');
        self::assertSame('Lehrgaenge_Kreis_Dueren_2027.ics', (string) $file['name']);

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

    /** Plan des Vorjahres als weitere Datei derselben Quelle (echter Kreisplan 2026) */
    public function testSecondYearAsAdditionalFile(): void
    {
        $this->admin();
        $db = $this->app->db();
        $kreis = $this->source('kreis');
        $m2 = $this->calendar('maschinist-2-2027', 'Maschinist 2/2027');
        $alle = $this->calendar('maschinisten', 'Maschinisten (alle Lehrgänge)');
        $this->feed($kreis, $m2, 'Maschinist 2/2027', ['show_stand' => 0]);
        $this->feed($kreis, $alle, 'Maschinist', ['show_stand' => 0]);

        $this->web('POST', "/admin/quellen/{$kreis['id']}/datei", ['x' => '1'], ['datei' => $this->upload((string) file_get_contents(self::KREIS), 'Lehrgaenge_Kreis_Dueren_2027.ics')]);
        $p = (int) $db->value("SELECT id FROM source_files WHERE status = 'pending'");
        $this->web('POST', "/admin/quellen/datei/$p/uebernehmen", ['x' => '1']);
        self::assertSame(12, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$alle]), '3 Maschinisten-Lehrgänge 2027 à 4 Tage');

        // 2026 als weitere Datei: Vorschau ohne Entfernungen und ohne Plausibilitätswarnung
        $this->web('POST', "/admin/quellen/{$kreis['id']}/datei", ['replaces_id' => ''], ['datei' => $this->upload((string) file_get_contents(self::KREIS_2026), 'Lehrgaenge_Kreis_Dueren_2026.ics')]);
        $p26 = $db->one("SELECT * FROM source_files WHERE status = 'pending'");
        self::assertNull($p26['replaces_id']);
        self::assertSame(93, (int) $p26['event_count']);
        $preview = (string) $this->web('GET', '/admin/quellen/datei/' . $p26['id'])->getBody();
        self::assertStringContainsString('weitere Datei', $preview);
        self::assertStringContainsString('93 Termine erkannt, 17.01.2026 – 12.12.2026', $preview);
        self::assertStringNotContainsString('Auffällig', $preview);
        self::assertStringNotContainsString('zuletzt', $preview, 'weitere Datei: kein Vergleich mit den Treffern der anderen Datei');
        self::assertSame(0, substr_count($preview, '</strong> entfernt') - substr_count($preview, '<strong>0</strong> entfernt'), 'nichts wird entfernt');
        self::assertStringContainsString('<strong>12</strong> neu', $preview, 'Maschinisten 2026');
        self::assertStringContainsString('81 von 93 Terminen', $preview);
        self::assertStringContainsString('<summary>Technische Hilfeleistung Wald 1/2026</summary>', $preview);
        $this->web('POST', '/admin/quellen/datei/' . $p26['id'] . '/uebernehmen', ['x' => '1']);

        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM source_files WHERE status = 'active'"));
        self::assertSame(24, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$alle]), '2026 und 2027 nebeneinander');
        self::assertSame(4, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$m2]), 'Maschinist 2/2027 unberührt');
        self::assertSame([4, 24], array_map('intval', $db->column('SELECT last_match_count FROM calendar_feeds WHERE calendar_id IN (?, ?) ORDER BY id', [$m2, $alle])),
            'Treffer über alle Dateien, nicht 0 aus der Datei 2026');
        self::assertSame([], array_values(array_filter($this->app->status()->problems(), fn ($m) => str_contains($m, 'enden'))),
            'Dateien enden erst 2027 – keine Warnung, obwohl die Datei 2026 bald endet');

        // neue Fassung 2027 lässt die Termine aus 2026 stehen
        $f27 = (int) $db->value("SELECT id FROM source_files WHERE name LIKE '%2027%'");
        $this->web('POST', "/admin/quellen/datei/$f27/erneut", ['x' => '1']);
        $re = (int) $db->value("SELECT id FROM source_files WHERE status = 'pending'");
        $prev = (string) $this->web('GET', "/admin/quellen/datei/$re")->getBody();
        self::assertStringContainsString('<strong>0</strong> entfernt', $prev);
        self::assertStringContainsString('(bisher 12 aus dieser Datei)', $prev);
        $this->web('POST', "/admin/quellen/datei/$re/uebernehmen", ['x' => '1']);
        self::assertSame(24, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$alle]));

        // Datei 2026 entfernen: nur ihre Termine verschwinden
        $f26 = (int) $db->value("SELECT id FROM source_files WHERE name LIKE '%2026%'");
        $this->web('POST', "/admin/quellen/datei/$f26/loeschen", ['x' => '1']);
        self::assertSame(12, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$alle]));
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM events WHERE summary LIKE '%/2026%'"));
    }

    /** Echter Abfallkalender 2026 (Ortsteil Kreuzau): ganztägige Abfuhren und Termine mit Uhrzeit, auf zwei Kalender verteilt */
    public function testAbfallkalenderFile(): void
    {
        $this->admin();
        $db = $this->app->db();
        $src = $this->source('abfall-kreuzau');
        $abfall = $this->calendar('abfall-kreuzau', 'Abfall Kreuzau');
        $schad = $this->calendar('schadstoffe', 'Schadstoffannahme');
        $now = $this->clock->nowDb();
        $f = fn (int $cal, array $cond) => $db->insert('calendar_feeds', ['calendar_id' => $cal, 'source_id' => $src['id'],
            'filter' => json_encode(['conditions' => [$cond]]), 'source_note' => 0, 'show_stand' => 1, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $f($abfall, ['field' => 'categories', 'op' => 'not_contains', 'value' => 'Schadstoffe']);   // alles außer Schadstoffen
        $f($schad, ['field' => 'categories', 'op' => 'contains', 'value' => 'Schadstoffe']);

        $this->web('POST', "/admin/quellen/{$src['id']}/datei", ['x' => '1'], ['datei' => $this->upload((string) file_get_contents(__DIR__ . '/fixtures/abfall-kreuzau-2026.ics'), 'Abfallkalender_Kreuzau_2026.ics')]);
        $p = (int) $db->value("SELECT id FROM source_files WHERE status = 'pending'");
        $preview = (string) $this->web('GET', "/admin/quellen/datei/$p")->getBody();
        self::assertStringContainsString('122 Termine erkannt, 02.01.2026 – 31.12.2026', $preview);
        self::assertStringContainsString('<strong>92</strong> neu', $preview);
        self::assertStringContainsString('<strong>30</strong> neu', $preview);
        self::assertStringNotContainsString('Nicht übernommen', $preview, 'jeder Termin hat einen Kalender');
        $this->web('POST', "/admin/quellen/datei/$p/uebernehmen", ['x' => '1']);

        self::assertSame(92, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND all_day = 1', [$abfall]));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ? AND all_day = 0', [$abfall]));
        self::assertSame(30, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$schad]));

        // ganztägig: als Datum, ein Tag, „frei“ (blockiert in Outlook nicht den ganzen Tag)
        $ics = (string) $db->value("SELECT ics FROM events WHERE calendar_id = ? ORDER BY starts_at DESC LIMIT 1", [$abfall]);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20261231', $ics, 'letzte Abfuhr am Jahresende');
        self::assertStringContainsString('DTEND;VALUE=DATE:20270101', $ics);
        self::assertStringContainsString('TRANSP:TRANSPARENT', $ics);
        // Schadstoffmobil: mehrzeilige Beschreibung und Ort mit Kommas bleiben erhalten
        $mobil = $db->one("SELECT * FROM events WHERE summary = 'Schadstoffmobil' ORDER BY starts_at LIMIT 1");
        self::assertSame('Windener Weg, Kreuzau', $mobil['location']);
        self::assertStringContainsString("Stockheim 14:00–14:45 Uhr, Parkplatz „An der Tränke“\n", (string) $mobil['description']);
        self::assertStringEndsWith('Stand ' . $this->clock->now()->format('d.m.Y'), (string) $mobil['description']);
        $vcal = \Sabre\VObject\Reader::read((string) $mobil['ics']);
        self::assertSame('Windener Weg, Kreuzau', (string) $vcal->VEVENT->LOCATION);
        self::assertSame('20260304T100000', $vcal->VEVENT->DTSTART->getDateTime()->format('Ymd\THis'));
        self::assertSame('Europe/Berlin', $vcal->VEVENT->DTSTART->getDateTime()->getTimezone()->getName());
    }

    /**
     * Echte Abfallkalender 2027 für Drove und Boich/Thum: gleicher Abfuhrbezirk, nur das Altpapier
     * unterscheidet sich. Je Ort eine Quelle und ein Kalender; gleiche Termine kollidieren nicht.
     */
    public function testAbfallTwoOrtsteile(): void
    {
        $this->admin();
        $db = $this->app->db();
        $cals = [];
        foreach (['abfall-drove' => 'Abfall Drove', 'abfall-boich-thum' => 'Abfall Boich/Thum'] as $key => $name) {
            $src = $this->source($key);
            $cals[$key] = $this->calendar($key, $name);
            $this->feed($src, $cals[$key]);
            $this->web('POST', "/admin/quellen/{$src['id']}/datei", ['x' => '1'],
                ['datei' => $this->upload((string) file_get_contents(__DIR__ . "/fixtures/$key-2027.ics"), "$key-2027.ics")]);
            $p = (int) $db->value("SELECT id FROM source_files WHERE status = 'pending'");
            self::assertStringContainsString('121 Termine erkannt, 04.01.2027 – 30.12.2027', (string) $this->web('GET', "/admin/quellen/datei/$p")->getBody());
            $this->web('POST', "/admin/quellen/datei/$p/uebernehmen", ['x' => '1']);
        }
        foreach ($cals as $cal) {
            self::assertSame(121, (int) $db->value('SELECT COUNT(*) FROM events WHERE calendar_id = ?', [$cal]));
        }
        // Restmüll am selben Tag in beiden Kalendern, aber mit eigener UID (kein Konflikt auf den Geräten)
        $uids = $db->column("SELECT uid FROM events WHERE summary = 'Restmülltonne' AND starts_at = ? ORDER BY uid", [
            (string) $db->value("SELECT MIN(starts_at) FROM events WHERE summary = 'Restmülltonne'")]);
        self::assertCount(2, $uids);
        self::assertNotSame($uids[0], $uids[1]);
        // Altpapier unterscheidet sich je Ort
        $papier = fn (int $cal) => $db->column("SELECT starts_at FROM events WHERE calendar_id = ? AND summary = 'Altpapiersammlung' ORDER BY starts_at", [$cal]);
        self::assertCount(12, $papier($cals['abfall-drove']));
        self::assertSame([], array_intersect($papier($cals['abfall-drove']), $papier($cals['abfall-boich-thum'])));
        // Schadstoffmobil hält in Drove um 12:45
        $mobil = Clock::toLocal((string) $db->value("SELECT starts_at FROM events WHERE calendar_id = ? AND summary = 'Schadstoffmobil' ORDER BY starts_at", [$cals['abfall-drove']]));
        self::assertSame('03.03.2027 12:45', $mobil?->format('d.m.Y H:i'));
    }

    public function testUnmatchedGroupedByTitle(): void
    {
        foreach ([
            'Maschinist 2/2027 – Tag 1/4' => 'Maschinist 2/2027',
            'ABC 1 Modul 1 - Grundlagen 1/2027 – Tag 2/3' => 'ABC 1 Modul 1 - Grundlagen 1/2027',
            'Truppführer Zulassungstest 1/2027' => 'Truppführer Zulassungstest 1/2027',
            'Erste Hilfe - Teil 2' => 'Erste Hilfe',
            'Sprechfunk (3/5)' => 'Sprechfunk',
            'Seminar Termin 3' => 'Seminar',
            'Tag 1/2' => 'Tag 1/2',
            'Restmüll' => 'Restmüll',
        ] as $in => $out) {
            self::assertSame($out, IcalProcessor::baseTitle($in), $in);
        }
        $d = fn (string $s) => new \DateTimeImmutable($s, new \DateTimeZone('Europe/Berlin'));
        $ev = fn (string $t, string $start) => ['summary' => $t, 'start' => $d($start), 'end' => $d($start . ' +2 hours'), 'all_day' => false, 'location' => 'Düren'];
        $groups = IcalProcessor::groupByTitle([
            $ev('Kurs 10/2027 – Tag 1/2', '2027-03-01 08:00'), $ev('Kurs 2/2027 – Tag 2/2', '2027-02-08 08:00'),
            $ev('Kurs 2/2027 – Tag 1/2', '2027-02-01 08:00'), $ev('kurs 2/2027 – Tag 3/3', '2027-02-15 08:00'),
        ]);
        self::assertSame(['Kurs 2/2027', 'Kurs 10/2027'], array_column($groups, 'title'), 'natürliche Zahlenfolge, Groß-/Kleinschreibung egal');
        self::assertSame(3, $groups[0]['count']);
        self::assertSame(['01.02.2027 08:00', '08.02.2027 08:00', '15.02.2027 08:00'], array_column($groups[0]['items'], 'when'));
        self::assertSame('Düren', $groups[0]['items'][0]['location']);
        $utc = IcalProcessor::groupByTitle([['summary' => 'X', 'start' => new \DateTimeImmutable('2027-01-05 07:00', new \DateTimeZone('UTC')),
            'end' => new \DateTimeImmutable('2027-01-05 09:00', new \DateTimeZone('UTC')), 'all_day' => false, 'location' => null]]);
        self::assertSame('05.01.2027 08:00', $utc[0]['items'][0]['when'], 'Zeit in UTC wird in deutscher Zeit angezeigt');
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
