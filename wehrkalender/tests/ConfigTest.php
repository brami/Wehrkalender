<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Wehrkalender\Config;

/** Im Container kommen alle Einstellungen als Umgebungsvariablen an, nicht aus einer .env */
final class ConfigTest extends BaseTestCase
{
    private const KEYS = ['FEED_BROWSER_NOTICE', 'SOURCE_URL', 'WEHR_NAME', 'BETREIBER_NAME', 'DATENSCHUTZ_KONTAKT', 'APP_NAME', 'UNBEKANNT_X'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public function testSettingsFromEnvironmentVariables(): void
    {
        foreach (self::KEYS as $k) {
            $_ENV[$k] = 'wert-' . $k;
        }
        $empty = tempnam(sys_get_temp_dir(), 'env');
        $c = Config::load(sys_get_temp_dir(), $empty);
        unlink($empty);
        foreach (array_diff(self::KEYS, ['UNBEKANNT_X']) as $k) {
            self::assertSame('wert-' . $k, $c->get($k), $k);
        }
        self::assertSame('', $c->get('UNBEKANNT_X'), 'fremde Variablen werden ignoriert');
        self::assertSame('wert-WEHR_NAME', $c->wehrName());
        self::assertSame('wert-SOURCE_URL', $c->sourceUrl());
    }

    public function testNeutralDefaults(): void
    {
        $c = new Config([]);
        self::assertSame('Wehrkalender', $c->appName());
        self::assertSame('Feuerwehr', $c->wehrName());
    }
}
