<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\App;
use Kalender\Config;
use Kalender\Web\Licenses;
use Kalender\Web\WebApp;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Impressum mit Betreiberangaben und Lizenzhinweisen; Verantwortlicher im Datenschutzhinweis */
final class ImprintTest extends TestCase
{
    private function appWith(array $values): App
    {
        return new App(new Config($values + [
            'BASE_DIR' => dirname(__DIR__),
            'APP_ENV' => 'test',
            'APP_KEY' => base64_encode(str_repeat('k', 32)),
            'APP_DOMAIN' => 'kalender.example.de',
            'MAIL_DELIVERY' => 'database',
        ]), $this->app->db(), $this->clock);
    }

    private function get(App $app, string $uri): ResponseInterface
    {
        return WebApp::create($app)->handle((new ServerRequestFactory())->createServerRequest('GET', $uri));
    }

    public function testImprintIsPublicAndShowsOperatorAndLicenses(): void
    {
        $app = $this->appWith([
            'BETREIBER_NAME' => 'Förderverein der Feuerwehr Muster e. V.',
            'BETREIBER_ANSCHRIFT' => 'Musterstraße 1; 12345 Musterstadt',
            'BETREIBER_VERTRETEN_DURCH' => 'den Vorstand',
            'BETREIBER_EMAIL' => 'vorstand@example.de',
            'BETREIBER_REGISTER' => 'Amtsgericht Musterstadt, VR 1234',
            'DATENSCHUTZ_KONTAKT' => 'Erika Muster, datenschutz@example.de',
            'SOURCE_URL' => 'https://github.com/beispiel/kalender-ff-krz',
        ]);
        $res = $this->get($app, '/impressum');
        self::assertSame(200, $res->getStatusCode(), 'ohne Anmeldung erreichbar');
        $html = (string) $res->getBody();
        self::assertStringContainsString('Förderverein der Feuerwehr Muster e. V.', $html);
        self::assertStringContainsString('Musterstraße 1<br>', $html);
        self::assertStringContainsString('12345 Musterstadt<br>', $html);
        self::assertStringContainsString('Vertreten durch: den Vorstand', $html);
        self::assertStringContainsString('VR 1234', $html);
        self::assertStringContainsString('AGPL-3.0-or-later', $html);
        self::assertStringContainsString('CC BY-SA 4.0', $html);
        self::assertStringContainsString('Michael Bramer', $html);
        self::assertStringContainsString('https://github.com/beispiel/kalender-ff-krz', $html);
        self::assertStringContainsString('sabre/dav', $html, 'Bibliotheken aus installed.json');
        self::assertStringNotContainsString('phpunit/phpunit', $html, 'keine Entwicklungswerkzeuge');
        self::assertStringNotContainsString('unvollständig', $html);

        $privacy = (string) $this->get($app, '/datenschutz')->getBody();
        self::assertStringContainsString('Förderverein der Feuerwehr Muster e. V.', $privacy);
        self::assertStringContainsString('Erika Muster', $privacy);
        self::assertStringContainsString('href="/impressum"', (string) $this->get($app, '/login')->getBody());
    }

    public function testIncompleteImprintShowsPlaceholderAndWarnsAdmins(): void
    {
        $app = $this->appWith([]);
        $html = (string) $this->get($app, '/impressum')->getBody();
        self::assertStringContainsString('werden zurzeit ergänzt', $html);
        self::assertStringNotContainsString('Impressum unvollständig', $html, 'Warnung nur für Admins');
        self::assertStringContainsString('auf Anfrage beim Anbieter', $html);

        $admin = $this->member('9', 'Max', 'Admin');
        $this->app->db()->execute('UPDATE users SET is_admin = 1 WHERE id = ?', [$admin['id']]);
        $this->get($app, '/login');
        $_SESSION = ['user_id' => (int) $admin['id'], '_last' => time(), '_csrf' => 'x'];
        self::assertStringContainsString('Impressum unvollständig', (string) $this->get($app, '/impressum')->getBody());
        self::assertStringContainsString('Impressum unvollständig', (string) $this->get($app, '/admin')->getBody());
    }

    public function testLicensesReaderSkipsDevPackagesAndHandlesMissingFile(): void
    {
        self::assertSame([], Licenses::fromInstalled('/nicht/vorhanden.json'));
        $f = tempnam(sys_get_temp_dir(), 'lic');
        file_put_contents($f, json_encode(['packages' => [
            ['name' => 'b/lib', 'version' => 'v2.0.1', 'license' => ['MIT'], 'source' => ['url' => 'https://github.com/b/lib.git']],
            ['name' => 'a/lib', 'version' => '1.0', 'license' => ['BSD-3-Clause', 'MIT'], 'homepage' => 'https://a.example'],
            ['name' => 'x/dev', 'version' => '1.0', 'license' => ['MIT']],
        ], 'dev-package-names' => ['x/dev']]));
        $list = Licenses::fromInstalled($f);
        unlink($f);
        self::assertSame(['a/lib', 'b/lib'], array_column($list, 'name'));
        self::assertSame('BSD-3-Clause / MIT', $list[0]['license']);
        self::assertSame('2.0.1', $list[1]['version']);
        self::assertSame('https://github.com/b/lib', $list[1]['url']);
    }
}
