<?php

declare(strict_types=1);

namespace Kalender\Domain;

use Kalender\Clock;
use Kalender\Config;
use Kalender\Database\Db;
use Kalender\Mail\MailQueue;
use Kalender\Security\Tokens;

/**
 * Mitgliedschaften und daraus abgeleitete Kalenderzuordnungen
 * (memberships ⋈ calendar_sources → user_calendars) samt Abo-Links und
 * Hinweis-E-Mail über neue Kalender.
 */
final class MembershipService
{
    public function __construct(
        private Db $db,
        private Clock $clock,
        private Config $config,
        private MailQueue $mail,
    ) {
    }

    /** @param list<array{schluessel:string,bezeichnung:string}> $memberships */
    public function replaceMemberships(int $userId, array $memberships): void
    {
        $this->db->transaction(function () use ($userId, $memberships) {
            $this->db->execute('DELETE FROM memberships WHERE user_id = ?', [$userId]);
            $seen = [];
            foreach ($memberships as $m) {
                $key = trim($m['schluessel']);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->db->insert('memberships', [
                    'user_id' => $userId,
                    'mp_key' => $key,
                    'label' => trim($m['bezeichnung'] ?? '') !== '' ? trim($m['bezeichnung']) : $key,
                ]);
            }
        });
    }

    /** @return list<int> Kalender, die dem Mitglied laut Mitgliedschaften zustehen */
    public function desiredCalendars(int $userId): array
    {
        $ids = $this->db->column(
            'SELECT DISTINCT cs.calendar_id FROM memberships m
               JOIN calendar_sources cs ON cs.mp_key = m.mp_key
               JOIN calendars c ON c.id = cs.calendar_id
              WHERE m.user_id = ? AND c.active = 1
             UNION
             SELECT id FROM calendars WHERE all_members = 1 AND active = 1',
            [$userId]
        );
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    /**
     * user_calendars neu berechnen. Neue Zuordnungen erhalten since = jetzt und
     * einen Abo-Link (ein früherer, nicht widerrufener Link gilt wieder).
     *
     * @param bool $notify Hinweis-E-Mail über neue Kalender einreihen
     * @return list<int> neu hinzugekommene Kalender
     */
    public function recompute(int $userId, bool $notify = true): array
    {
        return $this->db->transaction(function () use ($userId, $notify) {
            $desired = $this->desiredCalendars($userId);
            $current = array_map('intval', $this->db->column('SELECT calendar_id FROM user_calendars WHERE user_id = ?', [$userId]));
            $added = array_values(array_diff($desired, $current));
            $removed = array_values(array_diff($current, $desired));
            $now = $this->clock->nowDb();
            foreach ($added as $calId) {
                $this->db->insert('user_calendars', ['user_id' => $userId, 'calendar_id' => $calId, 'since' => $now]);
                $this->ensureFeedToken($userId, $calId);
            }
            if ($removed !== []) {
                $this->db->execute(
                    'DELETE FROM user_calendars WHERE user_id = ? AND calendar_id IN (' . Db::in($removed) . ')',
                    array_merge([$userId], $removed)
                );
            }
            if ($notify && $added !== []) {
                $this->notifyNewCalendars($userId, $added);
            }
            return $added;
        });
    }

    /**
     * Alle Mitglieder neu berechnen (z. B. nach Änderung der Zuordnung
     * Bereich → Kalender im Admin-Bereich).
     *
     * @return array{users:int,added:int}
     */
    public function recomputeAll(bool $notify = true): array
    {
        $stats = ['users' => 0, 'added' => 0];
        foreach ($this->db->column('SELECT id FROM users ORDER BY id') as $id) {
            $added = $this->recompute((int) $id, $notify);
            $stats['users']++;
            $stats['added'] += count($added);
        }
        return $stats;
    }

    public function ensureFeedToken(int $userId, int $calendarId): string
    {
        $existing = $this->db->value(
            'SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL ORDER BY id DESC',
            [$userId, $calendarId]
        );
        if ($existing !== null) {
            return (string) $existing;
        }
        $token = Tokens::random();
        $this->db->insert('feed_tokens', [
            'user_id' => $userId,
            'calendar_id' => $calendarId,
            'token' => $token,
            'created_at' => $this->clock->nowDb(),
        ]);
        return $token;
    }

    /** Eine E-Mail je Mitglied, auch wenn mehrere Kalender hinzukommen */
    public function notifyNewCalendars(int $userId, array $calendarIds): void
    {
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active' || $calendarIds === []) {
            return;
        }
        $calendars = [];
        foreach ($calendarIds as $calId) {
            $cal = $this->db->one('SELECT id, display_name, color FROM calendars WHERE id = ?', [$calId]);
            if ($cal !== null) {
                $cal['feed_url'] = $this->config->baseUrl() . '/abo/' . $this->ensureFeedToken($userId, (int) $calId) . '.ics';
                $calendars[] = $cal;
            }
        }
        $this->mail->enqueue('new_calendar', $user['email'], [
            'user' => $user,
            'calendars' => $calendars,
            'login_url' => $this->config->baseUrl() . '/login',
        ], $userId);
    }
}
