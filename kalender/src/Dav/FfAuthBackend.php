<?php

declare(strict_types=1);

namespace Kalender\Dav;

use Kalender\Clock;
use Kalender\Database\Db;
use Kalender\Security\LoginThrottle;
use Kalender\Security\PasswordService;
use Sabre\DAV\Auth\Backend\AbstractBasic;

/**
 * HTTP-Basic-Anmeldung für CalDAV mit Benutzername und Systempasswort.
 * Nur aktive Benutzer; Fehlversuche begrenzt; last_dav_at höchstens einmal
 * pro Minute aktualisiert.
 */
final class FfAuthBackend extends AbstractBasic
{
    private ?array $user = null;

    public function __construct(
        private Db $db,
        private PasswordService $passwords,
        private LoginThrottle $throttle,
        private Clock $clock,
        private string $clientIp,
        private string $userAgent,
    ) {
        $this->realm = 'Kalender FF KRZ';
        $this->principalPrefix = 'principals/';
    }

    protected function validateUserPass($username, $password)
    {
        $username = strtolower(trim((string) $username));
        $ipKey = $this->throttle->ipKey($this->clientIp);
        $userKey = 'dav:' . $username;
        if ($this->throttle->tooMany($ipKey, LoginThrottle::IP_FAILS_PER_HOUR)
            || $this->throttle->tooMany($userKey, LoginThrottle::USER_FAILS_PER_HOUR)) {
            return false;
        }
        $user = $username === '' ? null : $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user === null || $user['status'] !== 'active' || !$this->passwords->verify($user['password_enc'], (string) $password)) {
            $this->throttle->hit($ipKey);
            $this->throttle->hit($userKey);
            return false;
        }
        $this->user = $user;
        $this->touch($user);
        return true;
    }

    public function currentUser(): ?array
    {
        return $this->user;
    }

    private function touch(array $user): void
    {
        $now = $this->clock->now();
        $last = Clock::fromDb($user['last_dav_at']);
        $ua = mb_substr($this->userAgent, 0, 255);
        if ($last === null || $now->getTimestamp() - $last->getTimestamp() >= 60 || $user['last_dav_client'] !== $ua) {
            $this->db->update('users', ['last_dav_at' => Clock::toDb($now), 'last_dav_client' => $ua], ['id' => $user['id']]);
        }
    }
}
