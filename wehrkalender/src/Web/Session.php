<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

/**
 * PHP-Session mit Secure/HttpOnly/SameSite=Lax, neuer ID nach Anmeldung,
 * Ablauf nach 8 Stunden Inaktivität und CSRF-Token.
 */
final class Session
{
    public const IDLE_SECONDS = 8 * 3600;

    private bool $started = false;

    public function __construct(private bool $secure = true)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('wehrkalender_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
            session_start();
        }
        $this->started = true;
        $last = $_SESSION['_last'] ?? null;
        if ($last !== null && time() - (int) $last > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_last'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    /** Anmeldung: neue Session-ID, Benutzer an user_id binden */
    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $userId, '_last' => time(), '_csrf' => bin2hex(random_bytes(32))];
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function csrfValid(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        if (!empty($_SESSION['_expired'])) {
            unset($_SESSION['_expired']);
            $f[] = ['type' => 'info', 'message' => 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.'];
        }
        return $f;
    }
}
