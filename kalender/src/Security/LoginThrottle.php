<?php

declare(strict_types=1);

namespace Kalender\Security;

use Kalender\Clock;
use Kalender\Database\Db;

/**
 * Begrenzung von Anmeldeversuchen und Link-Anforderungen über login_attempts.
 * Schlüssel: "user:<id>", "ip:<hash>", "link:<id>", "linkip:<hash>", "dav:<username>".
 */
final class LoginThrottle
{
    public const USER_FAILS_PER_HOUR = 10;
    public const IP_FAILS_PER_HOUR = 30;
    public const LINK_REQUESTS_PER_HOUR = 3;

    public function __construct(private Db $db, private Clock $clock, private string $secret)
    {
    }

    public function ipKey(string $ip): string
    {
        return 'ip:' . substr(hash_hmac('sha256', $ip, $this->secret), 0, 32);
    }

    public function count(string $key, int $windowSeconds = 3600): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE throttle_key = ? AND attempted_at > ?',
            [$key, $this->clock->db("-{$windowSeconds} seconds")]
        );
    }

    public function tooMany(string $key, int $max, int $windowSeconds = 3600): bool
    {
        return $this->count($key, $windowSeconds) >= $max;
    }

    public function hit(string $key): void
    {
        $this->db->insert('login_attempts', ['throttle_key' => $key, 'attempted_at' => $this->clock->nowDb()]);
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE throttle_key = ?', [$key]);
    }
}
