<?php

declare(strict_types=1);

namespace Wehrkalender\Security;

use RuntimeException;

/**
 * Systemerzeugte Passwörter (4 Gruppen à 5 Zeichen, ~99 Bit), gespeichert
 * verschlüsselt mit libsodium secretbox. Der Schlüssel (APP_KEY) liegt nur in
 * der Server-Konfiguration, nie in der Datenbank.
 */
final class PasswordService
{
    /** ohne 0/o, 1/l/i */
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private string $key;

    public function __construct(string $appKeyBase64)
    {
        $key = base64_decode($appKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY fehlt oder ist ungültig (32 Byte, Base64 erwartet).');
        }
        $this->key = $key;
    }

    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $s = '';
            for ($i = 0; $i < 5; $i++) {
                $s .= $alphabet[random_int(0, $max)];
            }
            $groups[] = $s;
        }
        return implode('-', $groups);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): ?string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        return $plain === false ? null : $plain;
    }

    public function verify(string $encrypted, string $input): bool
    {
        $plain = $this->decrypt($encrypted);
        if ($plain === null) {
            return false;
        }
        // Eingaben vom Smartphone: Leerzeichen am Rand und Großschreibung tolerieren
        return hash_equals($plain, strtolower(trim($input)));
    }
}
