<?php

declare(strict_types=1);

namespace Kalender\Domain;

use Kalender\Database\Db;
use Transliterator;

/**
 * Regeln für Benutzernamen (technische Doku, Abschnitt "Benutzernamen").
 *
 *  1. Kleinbuchstaben
 *  2. ä→ae, ö→oe, ü→ue, ß→ss; übrige Sonderzeichen per Transliterator nach ASCII
 *  3. Leerzeichen/Bindestriche innerhalb eines Namensteils → "-", sonst nur a–z, 0–9, "-"
 *  4. vorname.nachname, bei Belegung Ziffer ab 2
 */
final class UsernamePolicy
{
    private static ?Transliterator $transliterator = null;

    public function __construct(private Db $db)
    {
    }

    public static function normalizePart(string $part): string
    {
        $s = mb_strtolower(trim($part), 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'ss']);
        self::$transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII');
        if (self::$transliterator !== null) {
            $s = (string) self::$transliterator->transliterate($s);
        }
        $s = strtolower($s);
        $s = preg_replace('/[\s\-‐‑–—_]+/u', '-', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9-]/', '', $s) ?? $s;
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /** Soll-Stamm ohne Ziffer, z. B. "anna.becker" */
    public static function stem(string $firstName, string $lastName): string
    {
        $first = self::normalizePart($firstName);
        $last = self::normalizePart($lastName);
        if ($first === '' && $last === '') {
            return 'mitglied';
        }
        if ($first === '' || $last === '') {
            return $first . $last;
        }
        return $first . '.' . $last;
    }

    /** Angehängte Ziffer entfernen: max.mustermann2 → max.mustermann */
    public static function stripSuffix(string $username): string
    {
        return preg_replace('/\d+$/', '', $username) ?? $username;
    }

    /** Passt der Benutzername nicht mehr zum aktuellen Namen? */
    public static function isOutdated(string $username, string $firstName, string $lastName): bool
    {
        return self::stripSuffix($username) !== self::stem($firstName, $lastName);
    }

    /**
     * Ersten freien Benutzernamen zum Stamm finden. Belegt sind alle aktuellen
     * Benutzernamen und alle früheren Benutzernamen (username_history).
     * Frühere Namen desselben Mitglieds ($forUserId) dürfen wiederverwendet
     * werden – sie werden ja nicht an ein anderes Mitglied vergeben.
     */
    public function nextFree(string $stem, ?int $forUserId = null): string
    {
        $like = $stem . '%';
        $taken = [];
        foreach ($this->db->all('SELECT id, username FROM users WHERE username LIKE ?', [$like]) as $r) {
            if ((int) $r['id'] !== $forUserId) {
                $taken[$r['username']] = true;
            }
        }
        $sql = 'SELECT user_id, old_username AS name FROM username_history WHERE old_username LIKE ?
                UNION SELECT user_id, new_username AS name FROM username_history WHERE new_username LIKE ?';
        foreach ($this->db->all($sql, [$like, $like]) as $r) {
            if ((int) $r['user_id'] !== $forUserId) {
                $taken[$r['name']] = true;
            }
        }
        if (!isset($taken[$stem])) {
            return $stem;
        }
        for ($i = 2; ; $i++) {
            if (!isset($taken[$stem . $i])) {
                return $stem . $i;
            }
        }
    }

    /** Vorgeschlagener neuer Benutzername für ein Mitglied */
    public function suggestion(array $user): string
    {
        return $this->nextFree(self::stem($user['first_name'], $user['last_name']), (int) $user['id']);
    }
}
