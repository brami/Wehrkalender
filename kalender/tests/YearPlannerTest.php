<?php

declare(strict_types=1);

namespace Kalender\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Kalender\Calendar\NrwHolidays;
use Kalender\Calendar\NrwSchoolHolidays;
use Kalender\Clock;
use Kalender\Pdf\YearPlannerPdf;
use Kalender\Web\WebApp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class YearPlannerTest extends TestCase
{
    private function get(string $uri): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        return WebApp::create($this->app)->handle($req);
    }

    /** @param array<string,mixed> $user */
    private function loginAs(array $user): void
    {
        $_SESSION = ['user_id' => (int) $user['id'], '_last' => time(), '_csrf' => 'x'];
    }

    public function testHolidaysNrw(): void
    {
        self::assertSame('2027-03-28', NrwHolidays::easterSunday(2027)->format('Y-m-d'));
        self::assertSame('2026-04-05', NrwHolidays::easterSunday(2026)->format('Y-m-d'));
        $h = NrwHolidays::forYear(2027);
        self::assertCount(11, $h);
        self::assertSame('Karfreitag', $h['2027-03-26'][0]);
        self::assertSame('Ostermontag', $h['2027-03-29'][0]);
        self::assertSame('Christi Himmelfahrt', $h['2027-05-06'][0]);
        self::assertSame('Pfingstmontag', $h['2027-05-17'][0]);
        self::assertSame('Fronleichnam', $h['2027-05-27'][0]);
        self::assertSame('Allerheiligen', NrwHolidays::get('2026-11-01')[0]);
        self::assertNull(NrwHolidays::get('2026-11-02'));
    }

    public function testSchoolHolidays(): void
    {
        self::assertSame('Weihnachtsferien', NrwSchoolHolidays::get('2026-12-23'));
        self::assertSame('Weihnachtsferien', NrwSchoolHolidays::get('2027-01-06'));
        self::assertNull(NrwSchoolHolidays::get('2027-01-07'));
        self::assertSame('Pfingstferien', NrwSchoolHolidays::get('2027-05-18'));
        self::assertSame('Sommerferien', NrwSchoolHolidays::get('2030-08-06'));
        self::assertTrue(NrwSchoolHolidays::covers('2026-11-01', '2027-10-31'));
        self::assertFalse(NrwSchoolHolidays::covers('2030-01-01', '2030-12-31'));
    }

    public function testBuildOnlyOwnCalendarsAndCompactEntries(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $jf = $this->calendar('jf-gr1', 'JF Gr1', ['JF-GR1']);
        $kf = $this->calendar('kf', 'KF', ['KF']);
        $user = $this->member('1', 'Anna', 'Schmidt', ['LG-DROVE', 'JF-GR1']);

        $this->event($lg, 'a', '2027-03-04 19:00', '2027-03-04 21:00');
        $this->event($jf, 'b', '2027-03-04 18:00', '2027-03-04 19:30');
        $this->event($lg, 'c', '2027-03-06', '2027-03-08', true);            // ganztägig, 3 Tage
        $this->event($jf, 'd', '2027-03-11 19:00', '2027-03-11 21:00', false, true); // abgesagt
        $this->event($lg, 'e', '2027-03-20 22:00', '2027-03-21 02:00');     // über Mitternacht
        $this->event($kf, 'f', '2027-03-04 10:00', '2027-03-04 12:00');     // fremder Kalender
        $this->event($lg, 'g', '2027-02-27', '2027-03-02', true);            // beginnt vor dem Zeitraum
        $this->event($lg, 'h', '2027-04-01 19:00', '2027-04-01 21:00');     // nach dem Zeitraum

        $plan = $this->app->yearPlanner()->build((int) $user['id'], new DateTimeImmutable('2027-03-15', Clock::localTz()), 1, ['lg-drove', 'jf-gr1', 'kf']);

        self::assertSame(['jf-gr1', 'lg-drove'], array_column($plan['calendars'], 'slug'), 'KF ist nicht zugeordnet');
        self::assertSame('2027-03-01', $plan['start']->format('Y-m-d'));
        self::assertSame('2027-04-01', $plan['end']->format('Y-m-d'));
        $days = $plan['days'];

        // nach Startzeit sortiert, ohne den fremden Kalender
        self::assertSame(['18:00', '19:00'], array_column($days['2027-03-04'], 'time'));
        // ganztägig über drei Tage, ohne Uhrzeit
        foreach (['2027-03-06', '2027-03-07', '2027-03-08'] as $d) {
            self::assertCount(1, $days[$d]);
            self::assertNull($days[$d][0]['time']);
        }
        self::assertArrayNotHasKey('2027-03-09', $days);
        // abgesagt
        self::assertTrue($days['2027-03-11'][0]['cancelled']);
        // über Mitternacht: Startzeit am ersten Tag, Folgetag ohne Uhrzeit
        self::assertSame('22:00', $days['2027-03-20'][0]['time']);
        self::assertNull($days['2027-03-21'][0]['time']);
        // vor dem Zeitraum begonnen: erscheint ab dem 1., ohne Uhrzeit
        self::assertArrayNotHasKey('2027-02-28', $days);
        self::assertCount(1, $days['2027-03-01']);
        self::assertArrayHasKey('2027-03-02', $days);
        self::assertArrayNotHasKey('2027-04-01', $days);

        // Nur ein Kalender gewählt
        $only = $this->app->yearPlanner()->build((int) $user['id'], new DateTimeImmutable('2027-03-01'), 1, ['jf-gr1']);
        self::assertSame(['18:00'], array_column($only['days']['2027-03-04'], 'time'));
        self::assertArrayNotHasKey('2027-03-06', $only['days']);
    }

    public function testPdfRendersAllSizesAndManyEntries(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $user = $this->member('1', 'Jürgen', 'Müller', ['LG-DROVE']);
        foreach (['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'] as $i => $t) {
            $this->event($lg, "x$i", "2027-01-15 $t", "2027-01-15 $t", false, $i === 2);
        }
        $now = new DateTimeImmutable('2026-11-12 14:00', new DateTimeZone('Europe/Berlin'));
        foreach ([1, 4, 12] as $months) {
            $plan = $this->app->yearPlanner()->build((int) $user['id'], new DateTimeImmutable('2027-01-01'), $months, ['lg-drove']);
            $pdf = YearPlannerPdf::render($plan, 'Jürgen Müller', $now, 'Kalender der FF KRZ');
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertSame(1, preg_match_all('#/Type /Page\b#', $pdf), 'genau eine Seite');
        }
        $two = YearPlannerPdf::render($plan, 'Jürgen Müller', $now, 'Kalender der FF KRZ', 2);
        self::assertSame(2, preg_match_all('#/Type /Page\b#', $two), 'zwei Seiten');
        $one = $this->app->yearPlanner()->build((int) $user['id'], new DateTimeImmutable('2027-01-01'), 1, ['lg-drove']);
        self::assertSame(1, preg_match_all('#/Type /Page\b#', YearPlannerPdf::render($one, 'X', $now, 'K', 2)), 'ein Monat bleibt eine Seite');
        self::assertSame('Termine Januar 2027 – Dezember 2027', YearPlannerPdf::rangeTitle($plan['start'], $plan['end']));
        self::assertSame('termine-2027-01-bis-2027-12.pdf', YearPlannerPdf::fileName($plan['start'], $plan['end']));
        self::assertSame('termine-2027-01.pdf', YearPlannerPdf::fileName(new DateTimeImmutable('2027-01-01'), new DateTimeImmutable('2027-02-01')));
    }

    public function testPlannerPageAndDownloadRequireLogin(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $this->calendar('kf', 'KF', ['KF']);
        $user = $this->member('1', 'Anna', 'Schmidt', ['LG-DROVE']);
        $this->event($lg, 'a', '2027-03-04 19:00', '2027-03-04 21:00');

        $anon = $this->get('/terminuebersicht.pdf?start=2027-01&monate=12&k%5B%5D=lg-drove');
        self::assertSame(303, $anon->getStatusCode());
        self::assertSame('/login', $anon->getHeaderLine('Location'));

        $this->loginAs($user);
        $page = (string) $this->get('/terminuebersicht')->getBody();
        self::assertStringContainsString('value="lg-drove"', $page);
        self::assertStringNotContainsString('value="kf"', $page);

        self::assertMatchesRegularExpression('#<option value="\d{4}-01" selected>Januar#', $page, 'Vorgabe: Januar');
        $two = $this->get('/terminuebersicht.pdf?start=2027-01&monate=12&seiten=2&k%5B%5D=lg-drove');
        self::assertSame(2, preg_match_all('#/Type /Page\b#', (string) $two->getBody()));
        $res = $this->get('/terminuebersicht.pdf?start=2027-01&monate=12&k%5B%5D=lg-drove');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $res->getBody());

        foreach (['start=2027-13&monate=12&k%5B%5D=lg-drove', 'start=2027-01&monate=13&k%5B%5D=lg-drove', 'start=2027-01&monate=12&k%5B%5D=kf'] as $q) {
            $bad = $this->get('/terminuebersicht.pdf?' . $q);
            self::assertSame(303, $bad->getStatusCode(), $q);
            self::assertSame('/terminuebersicht', $bad->getHeaderLine('Location'));
        }
    }
}
