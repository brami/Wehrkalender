<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\Web\WebApp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MemberPagesTest extends TestCase
{
    private function get(string $uri): ResponseInterface
    {
        return WebApp::create($this->app)->handle((new ServerRequestFactory())->createServerRequest('GET', $uri));
    }

    public function testStartPageListsCalendarsWithCountsButNoLinks(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $this->calendar('ff-krz', 'FF KRZ', [], true);
        $user = $this->member('1', 'Anna', 'Schmidt', ['LG-DROVE', 'ATEMSCHUTZ']);
        $year = (int) $this->app->clock()->now()->setTimezone(\Wehrkalender\Clock::localTz())->format('Y');
        $this->event($lg, 'a', "$year-03-04 19:00", "$year-03-04 21:00");
        $this->event($lg, 'b', "$year-03-11 19:00", "$year-03-11 21:00");
        $this->event($lg, 'c', "$year-03-18 19:00", "$year-03-18 21:00", false, true); // abgesagt
        $this->event($lg, 'd', ($year + 1) . '-01-10 19:00', ($year + 1) . '-01-10 21:00'); // Folgejahr
        $this->get('/login'); // Sitzung starten
        $_SESSION = ['user_id' => (int) $user['id'], '_last' => time(), '_csrf' => 'x'];

        $home = (string) $this->get('/')->getBody();
        self::assertStringContainsString('LG Drove', $home);
        self::assertStringContainsString("2 Termine in $year", $home);
        self::assertStringNotContainsString('/abo/', $home, 'keine Abo-Links auf der Startseite');
        self::assertStringContainsString('href="/abonnieren"', $home);
        self::assertStringContainsString('aus: LG-DROVE', $home, 'Herkunft des Kalenders');
        self::assertStringContainsString('für alle Mitglieder', $home);
        self::assertStringContainsString('ohne eigenen Kalender: ATEMSCHUTZ', $home);
        self::assertStringNotContainsString('href="/mitgliedschaften"', $home);

        $sub = (string) $this->get('/abonnieren')->getBody();
        self::assertStringContainsString('/abonnieren/geraete', $sub);
        self::assertStringContainsString('/abonnieren/outlook', $sub);

        $feeds = (string) $this->get('/abonnieren/outlook')->getBody();
        self::assertMatchesRegularExpression('#/abo/[A-Za-z0-9_-]{43}\.ics#', $feeds);
        self::assertStringContainsString('/konto/abo-links', $feeds);

        $dev = (string) $this->get('/abonnieren/geraete')->getBody();
        self::assertStringContainsString('anna.schmidt', $dev);
        self::assertStringContainsString('/konto/passwort', $dev);
        self::assertStringNotContainsString('/abo/', $dev);
        self::assertStringContainsString('/abonnieren/geraete/wehrkalender.mobileconfig', $dev);

        $profile = $this->get('/abonnieren/geraete/wehrkalender.mobileconfig');
        self::assertSame('application/x-apple-aspen-config', $profile->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<string>Feuerwehr</string>', (string) $profile->getBody(), 'Organisation aus WEHR_NAME');
    }
}
