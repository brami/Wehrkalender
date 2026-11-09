<?php

declare(strict_types=1);

namespace Kalender\Security;

final class Tokens
{
    /** 32 zufällige Bytes, Base64-URL ohne Auffüllung (43 Zeichen) */
    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }
}
