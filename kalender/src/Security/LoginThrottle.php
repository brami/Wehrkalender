<?php

declare(strict_types=1);

namespace Kalender\Security;

use Kalender\Clock;
use Kalender\Database\Db;

/**
 * Begrenzung von Anmeldeversuchen und Link-Anforderungen über login_attempts.
 *
 * Schlüssel:
 *   user:<id>        Fehlversuche Web-Anmeldung je Benutzer   (10 / Stunde)
 *   dav:<username>   Fehlversuche Smartphone/Tablet je Benutzer (10 / Stunde)
 *   ip:<hash>        Fehlversuche je IP-Adresse, Web und CalDAV (30 / Stunde)
 *   link:<id>        Anmeldelink-Anforderungen je Benutzer      (3 / Stunde)
 *   linkip:<hash>    Anmeldelink-Anforderungen je IP-Adresse    (30 / Stunde)
 *
 * Die IP-Adresse wird nur als HMAC gespeichert; für die Anzeige im
 * Admin-Bereich zusätzlich gekürzt in "info" (IPv4 /24, IPv6 /48).
 */
final class LoginThrottle
{
    public const USER_FAILS_PER_HOUR = 10;
    public const IP_FAILS_PER_HOUR = 30;
    public const LINK_REQUESTS_PER_HOUR = 3;
    public const WINDOW = 3600;

    /** Präfix => [Grenze, Bezeichnung] */
    public const KINDS = [
        'user' => [self::USER_FAILS_PER_HOUR, 'Benutzer – Anmeldung im Web'],
        'dav' => [self::USER_FAILS_PER_HOUR, 'Benutzer – Smartphone/Tablet'],
        'ip' => [self::IP_FAILS_PER_HOUR, 'IP-Adresse – Anmeldung'],
        'link' => [self::LINK_REQUESTS_PER_HOUR, 'Benutzer – Anmeldelinks'],
        'linkip' => [self::IP_FAILS_PER_HOUR, 'IP-Adresse – Anmeldelinks'],
    ];

    public function __construct(private Db $db, private Clock $clock, private string $secret)
    {
    }

    public function ipKey(string $ip): string
    {
        return 'ip:' . substr(hash_hmac('sha256', $ip, $this->secret), 0, 32);
    }

    /** Gekürzte IP-Adresse für die Anzeige (IPv4 /24, IPv6 /48) */
    public static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return "{$p[0]}.{$p[1]}.{$p[2]}.x";
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'unbekannt';
        }
        $groups = str_split(bin2hex(substr($bin, 0, 6)), 4);
        return implode(':', array_map(fn ($g) => ltrim($g, '0') ?: '0', $groups)) . ':x';
    }

    public function count(string $key, int $windowSeconds = self::WINDOW): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE throttle_key = ? AND attempted_at > ?',
            [$key, $this->clock->db("-{$windowSeconds} seconds")]
        );
    }

    public function tooMany(string $key, int $max, int $windowSeconds = self::WINDOW): bool
    {
        return $this->count($key, $windowSeconds) >= $max;
    }

    public function hit(string $key, ?string $info = null): void
    {
        $this->db->insert('login_attempts', [
            'throttle_key' => $key,
            'attempted_at' => $this->clock->nowDb(),
            'info' => $info === null ? null : mb_substr($info, 0, 100),
        ]);
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE throttle_key = ?', [$key]);
    }

    /** Alle Sperren aufheben */
    public function clearAll(): int
    {
        return $this->db->execute('DELETE FROM login_attempts');
    }

    /**
     * Derzeit gesperrte Schlüssel (Grenze in der letzten Stunde erreicht).
     *
     * @return list<array{key:string,kind:string,label:string,info:string,count:int,max:int,first:string,until:string,user_id:?int}>
     */
    public function blocked(): array
    {
        $since = $this->clock->db('-' . self::WINDOW . ' seconds');
        $rows = $this->db->all(
            'SELECT throttle_key, attempted_at, info FROM login_attempts WHERE attempted_at > ? ORDER BY throttle_key, attempted_at',
            [$since]
        );
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['throttle_key']][] = $r;
        }
        $result = [];
        foreach ($byKey as $key => $attempts) {
            $kind = explode(':', $key, 2)[0];
            if (!isset(self::KINDS[$kind])) {
                continue;
            }
            [$max, $label] = self::KINDS[$kind];
            $n = count($attempts);
            if ($n < $max) {
                continue;
            }
            // Sperre endet, sobald nur noch max-1 Versuche in der letzten Stunde liegen
            $releaseAttempt = $attempts[$n - $max]['attempted_at'];
            $until = Clock::fromDb($releaseAttempt)->modify('+' . self::WINDOW . ' seconds');
            $info = '';
            foreach (array_reverse($attempts) as $a) {
                if ($a['info'] !== null && $a['info'] !== '') {
                    $info = $a['info'];
                    break;
                }
            }
            $result[] = [
                'key' => $key,
                'kind' => $kind,
                'label' => $label,
                'info' => $info !== '' ? $info : '(unbekannt)',
                'count' => $n,
                'max' => $max,
                'first' => $attempts[0]['attempted_at'],
                'until' => Clock::toDb($until),
                'user_id' => $this->userIdFor($kind, $key),
            ];
        }
        usort($result, fn ($a, $b) => strcmp($b['until'], $a['until']));
        return $result;
    }

    private function userIdFor(string $kind, string $key): ?int
    {
        $value = explode(':', $key, 2)[1] ?? '';
        if ($kind === 'user' || $kind === 'link') {
            return ctype_digit($value) ? (int) $value : null;
        }
        if ($kind === 'dav') {
            $id = $this->db->value('SELECT id FROM users WHERE username = ?', [$value]);
            return $id === null ? null : (int) $id;
        }
        return null;
    }
}
