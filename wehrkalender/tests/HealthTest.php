<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Wehrkalender\Web\WebApp;

/** Überwachung: /health/live (Dienst läuft) und /health (Daten in Ordnung, ohne Details) */
final class HealthTest extends TestCase
{
    private function get(string $uri): ResponseInterface
    {
        return WebApp::create($this->app)->handle((new ServerRequestFactory())->createServerRequest('GET', $uri));
    }

    public function testLiveIsOkWithoutImport(): void
    {
        $r = $this->get('/health/live');
        self::assertSame(200, $r->getStatusCode(), 'läuft, auch wenn noch kein Import aus MP Feuer vorliegt');
        self::assertSame(['status' => 'ok', 'db' => true], json_decode((string) $r->getBody(), true));
        self::assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('wehrkalender_session', $r->getHeaderLine('Set-Cookie'), 'keine Sitzung');
    }

    public function testHealthShowsOnlyCount(): void
    {
        // ohne Import: ein Problem („noch kein Export“) und eine fehlerhafte Quelle
        $now = $this->clock->nowDb();
        $this->app->db()->insert('sources', ['source_key' => 'kfv', 'type' => 'ical', 'name' => 'Kreisfeuerwehrverband',
            'url' => 'https://example.org/kfv.ics', 'interval_minutes' => 360, 'active' => 1, 'id_from_content' => 0,
            'last_run_at' => $now, 'last_error' => 'HTTP 404', 'created_at' => $now, 'updated_at' => $now]);
        $r = $this->get('/health');
        self::assertSame(503, $r->getStatusCode());
        $body = json_decode((string) $r->getBody(), true);
        self::assertSame('warning', $body['status']);
        self::assertSame(count($this->app->status()->problems()), $body['problems']);
        self::assertGreaterThanOrEqual(2, $body['problems']);
        self::assertStringNotContainsString('Kreisfeuerwehrverband', (string) $r->getBody(), 'keine Details öffentlich');
        self::assertStringNotContainsString('404', (string) $r->getBody());
    }
}
