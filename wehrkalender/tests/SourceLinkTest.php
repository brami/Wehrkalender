<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\App;
use Wehrkalender\Config;
use Wehrkalender\Web\WebApp;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Link zum Quellcode im Seitenfuß (AGPL-3.0 §13), gesteuert über SOURCE_URL */
final class SourceLinkTest extends TestCase
{
    private function loginPage(string $sourceUrl): string
    {
        $app = new App(new Config([
            'BASE_DIR' => dirname(__DIR__),
            'APP_ENV' => 'test',
            'APP_KEY' => base64_encode(str_repeat('k', 32)),
            'APP_DOMAIN' => 'kalender.example.de',
            'MAIL_DELIVERY' => 'database',
            'SOURCE_URL' => $sourceUrl,
        ]), $this->app->db(), $this->clock);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        return (string) WebApp::create($app)->handle($req)->getBody();
    }

    public function testFooterLinksToSourceOnlyWhenConfigured(): void
    {
        self::assertStringNotContainsString('Quellcode', $this->loginPage(''));
        $html = $this->loginPage('https://github.com/beispiel/kalender-ff-krz');
        self::assertStringContainsString('<a href="https://github.com/beispiel/kalender-ff-krz">Quellcode</a> (AGPL-3.0)', $html);
    }
}
