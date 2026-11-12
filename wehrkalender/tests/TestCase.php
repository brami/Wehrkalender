<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use DateTimeImmutable;
use Wehrkalender\App;
use Wehrkalender\Clock;
use Wehrkalender\Config;
use Wehrkalender\Database\Db;
use PDO;

/**
 * Basis für Tests: frische SQLite-Datenbank im Speicher mit vollem Schema.
 * (Auf dem Server können dieselben Tests gegen MariaDB laufen, siehe README.)
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;
    protected Clock $clock;

    protected function setUp(): void
    {
        $_SESSION = [];
        $base = dirname(__DIR__);
        $config = new Config([
            'BASE_DIR' => $base,
            'APP_ENV' => 'test',
            'APP_KEY' => base64_encode(str_repeat('k', 32)),
            'APP_DOMAIN' => 'kalender.example.de',
            'MAIL_DELIVERY' => 'database',
            'TRUSTED_PROXIES' => '127.0.0.1/32',
        ]);
        $driver = getenv('TEST_DB_DSN');
        $pdo = $driver ? new PDO($driver, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASSWORD') ?: null) : new PDO('sqlite::memory:');
        $this->clock = new Clock();
        $this->clock->fix(new DateTimeImmutable('2027-01-10 12:00:00', Clock::localTz()));
        $this->app = new App($config, new Db($pdo, sys_get_temp_dir()), $this->clock);
        $this->app->migrator()->dropAll();
        $this->app->migrator()->migrate();
    }

    protected function calendar(string $slug, string $name, array $keys = [], bool $allMembers = false): int
    {
        $now = $this->clock->nowDb();
        $id = $this->app->db()->insert('calendars', [
            'slug' => $slug, 'display_name' => $name, 'color' => '#123456', 'sync_token' => 1,
            'active' => 1, 'all_members' => $allMembers ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($keys as $k) {
            $this->app->db()->insert('calendar_sources', ['mp_key' => $k, 'calendar_id' => $id, 'created_at' => $now]);
        }
        return $id;
    }

    /** @return array<string,mixed> */
    protected function member(string $mpId, string $first, string $last, array $keys = []): array
    {
        $u = $this->app->accounts()->createUser($mpId, $first, $last, strtolower($first) . '@example.org');
        $this->app->memberships()->replaceMemberships((int) $u['id'], array_map(fn ($k) => ['schluessel' => $k, 'bezeichnung' => $k], $keys));
        $this->app->memberships()->recompute((int) $u['id'], false);
        return $this->app->accounts()->find((int) $u['id']);
    }

    protected function event(int $calendarId, string $id, string $start, string $end, bool $allDay = false, bool $cancelled = false, string $title = 'Übung'): string
    {
        return $this->app->events()->save([
            'external_id' => $id, 'calendar_id' => $calendarId, 'summary' => $title,
            'start' => new DateTimeImmutable($start, Clock::localTz()), 'end' => new DateTimeImmutable($end, Clock::localTz()),
            'all_day' => $allDay, 'cancelled' => $cancelled,
        ]);
    }

    protected function password(array $user): string
    {
        return (string) $this->app->passwords()->decrypt($this->app->accounts()->find((int) $user['id'])['password_enc']);
    }
}
