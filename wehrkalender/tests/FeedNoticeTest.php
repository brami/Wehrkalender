<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\App;
use Wehrkalender\Config;
use Wehrkalender\Web\WebApp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Abo-Link im Browser: Hinweisseite statt .ics (FEED_BROWSER_NOTICE) */
final class FeedNoticeTest extends TestCase
{
    private const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private function fetch(App $app, string $token, string $accept, string $ua = 'Mozilla/5.0'): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/abo/' . $token . '.ics')
            ->withHeader('Accept', $accept)->withHeader('User-Agent', $ua);
        return WebApp::create($app)->handle($req);
    }

    private function appWithNotice(string $value): App
    {
        $config = new Config([
            'BASE_DIR' => dirname(__DIR__),
            'APP_ENV' => 'test',
            'APP_KEY' => base64_encode(str_repeat('k', 32)),
            'APP_DOMAIN' => 'kalender.example.de',
            'MAIL_DELIVERY' => 'database',
            'FEED_BROWSER_NOTICE' => $value,
        ]);
        return new App($config, $this->app->db(), $this->clock);
    }

    public function testNoticeOnlyForBrowsersAndOnlyWhenEnabled(): void
    {
        $lg = $this->calendar('lg-drove', 'LG Drove', ['LG-DROVE']);
        $user = $this->member('1', 'Anna', 'Schmidt', ['LG-DROVE']);
        $token = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ?', [$user['id'], $lg]);

        // Standard (aus): auch der Browser bekommt die Datei
        $off = $this->fetch($this->app, $token, self::BROWSER);
        self::assertStringStartsWith('text/calendar', $off->getHeaderLine('Content-Type'));

        $on = $this->appWithNotice('1');
        // Browser: Hinweisseite, Abruf wird nicht als Abo gezählt
        $this->app->db()->execute('UPDATE feed_tokens SET last_access_at = NULL');
        $page = $this->fetch($on, $token, self::BROWSER);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringStartsWith('text/html', $page->getHeaderLine('Content-Type'));
        $html = (string) $page->getBody();
        self::assertStringContainsString('Dies ist ein Abo-Link', $html);
        self::assertStringContainsString('/abo/' . $token . '.ics', $html);
        self::assertStringContainsString('webcal://', $html);
        self::assertNull($this->app->db()->value('SELECT last_access_at FROM feed_tokens WHERE token = ?', [$token]));
        // Android-Browser: kein webcal-Link
        $android = (string) $this->fetch($on, $token, self::BROWSER, 'Mozilla/5.0 (Linux; Android 14)')->getBody();
        self::assertStringNotContainsString('webcal://', $android);

        // Kalenderprogramme: weiterhin die Termine
        foreach (['*/*', 'text/calendar', 'text/calendar, text/html;q=0.5', ''] as $accept) {
            $ics = $this->fetch($on, $token, $accept, 'Microsoft Office/16.0');
            self::assertStringStartsWith('text/calendar', $ics->getHeaderLine('Content-Type'), "Accept: $accept");
            self::assertStringContainsString('BEGIN:VCALENDAR', (string) $ics->getBody());
        }

        // Ungültiger Link bleibt 404, auch im Browser
        self::assertSame(404, $this->fetch($on, str_repeat('x', 43), self::BROWSER)->getStatusCode());
    }
}
