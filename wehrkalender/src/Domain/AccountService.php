<?php

declare(strict_types=1);

namespace Wehrkalender\Domain;

use Wehrkalender\Clock;
use Wehrkalender\Config;
use Wehrkalender\Database\Db;
use Wehrkalender\Mail\MailQueue;
use Wehrkalender\Security\PasswordService;
use Wehrkalender\Security\Tokens;
use RuntimeException;

/**
 * Zugänge: Anlegen, Begrüßung, Einmal-Links, Passwort, Abo-Links und
 * Umstellen des Benutzernamens. Wird vom Web-Frontend, vom Testdaten-Programm
 * und später vom Import genutzt.
 */
final class AccountService
{
    public const WELCOME_TTL = '+14 days';
    public const REQUEST_TTL = '+30 minutes';

    public function __construct(
        private Db $db,
        private Clock $clock,
        private Config $config,
        private PasswordService $passwords,
        private UsernamePolicy $usernames,
        private MailQueue $mail,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> neuer Benutzer */
    public function createUser(string $mpId, string $firstName, string $lastName, string $email, string $status = 'active'): array
    {
        return $this->db->transaction(function () use ($mpId, $firstName, $lastName, $email, $status) {
            $now = $this->clock->nowDb();
            $username = $this->usernames->nextFree(UsernamePolicy::stem($firstName, $lastName));
            $id = $this->db->insert('users', [
                'mp_id' => $mpId,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'status' => $status,
                'password_enc' => $this->passwords->encrypt($this->passwords->generate()),
                'password_changed_at' => $now,
                'is_admin' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $this->find($id);
        });
    }

    /** Begrüßung nötig: neue oder geänderte (gültige) Adresse */
    public function needsWelcome(array $user): bool
    {
        return $user['status'] === 'active' && $user['welcome_sent_for'] !== $user['email'];
    }

    /**
     * Begrüßungs-E-Mail einreihen. Alte, ungenutzte Begrüßungslinks werden
     * ungültig, der neue Link gilt 14 Tage. Passwort und Abo-Links bleiben.
     */
    public function queueWelcome(int $userId): string
    {
        return $this->db->transaction(function () use ($userId) {
            $user = $this->find($userId) ?? throw new RuntimeException('Benutzer nicht gefunden');
            $now = $this->clock->nowDb();
            $this->db->execute(
                "UPDATE login_tokens SET expires_at = ? WHERE user_id = ? AND purpose = 'welcome' AND used_at IS NULL AND expires_at > ?",
                [$now, $userId, $now]
            );
            $token = $this->createLoginToken($userId, 'welcome');
            $this->db->update('users', [
                'welcome_sent_for' => $user['email'],
                'welcome_sent_at' => $now,
                'updated_at' => $now,
            ], ['id' => $userId]);
            $this->mail->enqueue('welcome', $user['email'], [
                'user' => $user,
                'link' => $this->config->baseUrl() . '/login/link/' . $token,
                'valid_days' => 14,
                'calendars' => $this->calendarNames($userId),
            ], $userId);
            return $token;
        });
    }

    /** Einmal-Link auf Anforderung (30 Minuten gültig) */
    public function queueLoginLink(int $userId): string
    {
        return $this->db->transaction(function () use ($userId) {
            $user = $this->find($userId) ?? throw new RuntimeException('Benutzer nicht gefunden');
            $token = $this->createLoginToken($userId, 'request');
            $this->mail->enqueue('login_link', $user['email'], [
                'user' => $user,
                'link' => $this->config->baseUrl() . '/login/link/' . $token,
                'valid_minutes' => 30,
            ], $userId);
            return $token;
        });
    }

    /** Zweck: welcome (Begrüßung, 14 Tage), notice (Hinweis-E-Mail, 14 Tage), request (angefordert, 30 Minuten) */
    public function createLoginToken(int $userId, string $purpose): string
    {
        $token = Tokens::random();
        $this->db->insert('login_tokens', [
            'user_id' => $userId,
            'token_hash' => Tokens::hash($token),
            'purpose' => $purpose,
            'created_at' => $this->clock->nowDb(),
            'expires_at' => $this->clock->db(in_array($purpose, ['welcome', 'notice'], true) ? self::WELCOME_TTL : self::REQUEST_TTL),
        ]);
        return $token;
    }

    /**
     * Prüft einen Einmal-Link ohne ihn zu verbrauchen.
     *
     * @return array{status:string,user:?array,token:?array} status: ok|invalid|used|expired|locked
     */
    public function checkLoginToken(string $raw): array
    {
        if (!Tokens::isWellFormed($raw)) {
            return ['status' => 'invalid', 'user' => null, 'token' => null];
        }
        $token = $this->db->one('SELECT * FROM login_tokens WHERE token_hash = ?', [Tokens::hash($raw)]);
        if ($token === null) {
            return ['status' => 'invalid', 'user' => null, 'token' => null];
        }
        $user = $this->find((int) $token['user_id']);
        $status = match (true) {
            $token['used_at'] !== null => 'used',
            $token['expires_at'] <= $this->clock->nowDb() => 'expired',
            $user === null => 'invalid',
            $user['status'] !== 'active' => 'locked',
            default => 'ok',
        };
        return ['status' => $status, 'user' => $user, 'token' => $token];
    }

    /** Einmal-Link verbrauchen; liefert den Benutzer oder null */
    public function consumeLoginToken(string $raw): ?array
    {
        return $this->db->transaction(function () use ($raw) {
            $check = $this->checkLoginToken($raw);
            if ($check['status'] !== 'ok') {
                return null;
            }
            $now = $this->clock->nowDb();
            // Gegen doppelte Verwendung bei gleichzeitigen Anfragen
            $updated = $this->db->execute(
                'UPDATE login_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
                [$now, $check['token']['id']]
            );
            if ($updated !== 1) {
                return null;
            }
            if ($check['token']['purpose'] === 'welcome' && $check['user']['welcome_used_at'] === null) {
                $this->db->update('users', ['welcome_used_at' => $now], ['id' => $check['user']['id']]);
            }
            return $this->find((int) $check['user']['id']);
        });
    }

    public function recordWebLogin(int $userId): void
    {
        $now = $this->clock->nowDb();
        $this->db->execute(
            'UPDATE users SET last_web_login_at = ?, first_web_login_at = COALESCE(first_web_login_at, ?) WHERE id = ?',
            [$now, $now, $userId]
        );
    }

    public function plainPassword(array $user): ?string
    {
        return $this->passwords->decrypt($user['password_enc']);
    }

    public function regeneratePassword(int $userId): string
    {
        $plain = $this->passwords->generate();
        $now = $this->clock->nowDb();
        $this->db->update('users', [
            'password_enc' => $this->passwords->encrypt($plain),
            'password_changed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $userId]);
        return $plain;
    }

    /** Alle Abo-Links widerrufen und für die aktuellen Kalender neu erzeugen */
    public function regenerateFeedTokens(int $userId): int
    {
        return $this->db->transaction(function () use ($userId) {
            $now = $this->clock->nowDb();
            $this->db->execute('UPDATE feed_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $userId]);
            $count = 0;
            foreach ($this->db->column('SELECT calendar_id FROM user_calendars WHERE user_id = ?', [$userId]) as $calId) {
                $this->db->insert('feed_tokens', [
                    'user_id' => $userId,
                    'calendar_id' => (int) $calId,
                    'token' => Tokens::random(),
                    'created_at' => $now,
                ]);
                $count++;
            }
            return $count;
        });
    }

    public function isUsernameOutdated(array $user): bool
    {
        return UsernamePolicy::isOutdated($user['username'], $user['first_name'], $user['last_name']);
    }

    public function suggestedUsername(array $user): string
    {
        return $this->usernames->suggestion($user);
    }

    /**
     * Benutzernamen auf den Soll-Stamm umstellen (nur wenn veraltet).
     * Passwort, Abo-Links und Kalender bleiben unverändert.
     */
    public function changeUsername(int $userId, int $changedBy): string
    {
        return $this->db->transaction(function () use ($userId, $changedBy) {
            $user = $this->find($userId) ?? throw new RuntimeException('Benutzer nicht gefunden');
            if (!$this->isUsernameOutdated($user)) {
                throw new RuntimeException('Der Benutzername passt zum aktuellen Namen und kann nicht umgestellt werden.');
            }
            $new = $this->usernames->suggestion($user);
            $now = $this->clock->nowDb();
            $this->db->update('users', ['username' => $new, 'updated_at' => $now], ['id' => $userId]);
            $this->db->insert('username_history', [
                'user_id' => $userId,
                'old_username' => $user['username'],
                'new_username' => $new,
                'changed_by' => $changedBy,
                'changed_at' => $now,
            ]);
            $this->mail->enqueue('username_changed', $user['email'], [
                'user' => array_merge($user, ['username' => $new]),
                'old_username' => $user['username'],
                'new_username' => $new,
                'by_admin' => $changedBy !== $userId,
            ], $userId);
            return $new;
        });
    }

    public function setAdmin(string $username, bool $admin): bool
    {
        return $this->db->execute('UPDATE users SET is_admin = ? WHERE username = ?', [$admin ? 1 : 0, $username]) > 0;
    }

    /** @return list<string> */
    private function calendarNames(int $userId): array
    {
        return $this->db->column(
            'SELECT c.display_name FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
             WHERE uc.user_id = ? AND c.active = 1 AND ' . CalendarChoice::enabledSql() . ' ORDER BY c.display_name',
            [$userId]
        );
    }
}
