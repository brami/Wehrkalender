<?php

declare(strict_types=1);

namespace Kalender\Domain;

use Kalender\Clock;
use Kalender\Config;
use Kalender\Database\Db;
use Kalender\Mail\MailQueue;
use Throwable;

/**
 * Betriebsstatus: letzter Import, Überwachung (monitor:check, /health),
 * Warnungen an die Administratoren, Nutzungszahlen.
 */
final class StatusService
{
    public const STALE_HOURS = 24;

    public function __construct(private Db $db, private Clock $clock, private Config $config, private MailQueue $mail)
    {
    }

    public function getState(string $name): ?string
    {
        $v = $this->db->value('SELECT value FROM app_state WHERE name = ?', [$name]);
        return $v === null ? null : (string) $v;
    }

    public function setState(string $name, ?string $value): void
    {
        $now = $this->clock->nowDb();
        if ($this->db->update('app_state', ['value' => $value, 'updated_at' => $now], ['name' => $name]) === 0
            && $this->db->value('SELECT 1 FROM app_state WHERE name = ?', [$name]) === null) {
            $this->db->insert('app_state', ['name' => $name, 'value' => $value, 'updated_at' => $now]);
        }
    }

    /** @return array<string,mixed>|null */
    public function lastRun(): ?array
    {
        return $this->decorateRun($this->db->one('SELECT * FROM import_runs ORDER BY started_at DESC, id DESC LIMIT 1'));
    }

    /** @return array<string,mixed>|null */
    public function lastSuccessfulRun(): ?array
    {
        return $this->decorateRun($this->db->one("SELECT * FROM import_runs WHERE status = 'success' ORDER BY started_at DESC, id DESC LIMIT 1"));
    }

    /** @return array<string,mixed>|null */
    public function decorateRun(?array $run): ?array
    {
        if ($run === null) {
            return null;
        }
        $run['stats_data'] = json_decode((string) ($run['stats'] ?? ''), true) ?: [];
        $run['warnings'] = (int) $this->db->value("SELECT COUNT(*) FROM import_messages WHERE run_id = ? AND level = 'warning'", [$run['id']]);
        $run['errors'] = (int) $this->db->value("SELECT COUNT(*) FROM import_messages WHERE run_id = ? AND level = 'error'", [$run['id']]);
        $start = Clock::fromDb($run['started_at']);
        $end = Clock::fromDb($run['finished_at']);
        $run['duration'] = ($start && $end) ? $end->getTimestamp() - $start->getTimestamp() : null;
        return $run;
    }

    /**
     * Zeitpunkt des letzten eingegangenen Exports. Der Import setzt
     * app_state "import.last_export_seen_at" auch dann, wenn sich die Daten nicht
     * geändert haben (Prüfsumme gleich, kein Lauf protokolliert).
     */
    public function lastExportSeenAt(): ?string
    {
        $seen = $this->getState('import.last_export_seen_at');
        $run = $this->db->value('SELECT MAX(started_at) FROM import_runs');
        return max((string) $seen, (string) $run) ?: null;
    }

    /** @return list<string> Probleme für Überwachung und /health */
    public function problems(): array
    {
        $problems = [];
        $seen = $this->lastExportSeenAt();
        if ($seen === null) {
            $problems[] = 'Es wurde noch kein Export aus MP Feuer verarbeitet.';
        } elseif ($seen < $this->clock->db('-' . self::STALE_HOURS . ' hours')) {
            $problems[] = sprintf('Seit mehr als %d Stunden liegt kein neuer Export vor (letzter: %s).',
                self::STALE_HOURS, Clock::toLocal($seen)?->format('d.m.Y H:i'));
        }
        $last = $this->lastRun();
        if ($last !== null && in_array($last['status'], ['failed', 'aborted'], true)) {
            $problems[] = sprintf('Der letzte Import vom %s ist %s.',
                Clock::toLocal($last['started_at'])?->format('d.m.Y H:i'),
                $last['status'] === 'failed' ? 'fehlgeschlagen' : 'abgebrochen (Plausibilitätsprüfung)');
        }
        return $problems;
    }

    /** @return array{ok:bool,db:bool,problems:list<string>} */
    public function health(): array
    {
        try {
            $this->db->value('SELECT 1');
        } catch (Throwable) {
            return ['ok' => false, 'db' => false, 'problems' => ['Datenbank nicht erreichbar']];
        }
        $problems = $this->problems();
        return ['ok' => $problems === [], 'db' => true, 'problems' => $problems];
    }

    /** @return list<string> Empfänger für Warnungen */
    public function alertRecipients(): array
    {
        $list = $this->config->list('ADMIN_ALERT_EMAILS');
        foreach ($this->db->column("SELECT email FROM users WHERE is_admin = 1 AND status = 'active'") as $e) {
            $list[] = (string) $e;
        }
        return array_values(array_unique(array_map('strtolower', $list)));
    }

    /**
     * Warnung an alle Administratoren einreihen.
     * @param list<string> $lines
     */
    public function alertAdmins(string $subject, array $lines): int
    {
        $n = 0;
        foreach ($this->alertRecipients() as $to) {
            $this->mail->enqueue('admin_alert', $to, [
                'subject' => $subject,
                'lines' => $lines,
                'admin_url' => $this->config->baseUrl() . '/admin',
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Tägliche Prüfung (monitor:check). Höchstens eine Warnung je 20 Stunden.
     * @return array{problems:list<string>,sent:int}
     */
    public function monitor(bool $force = false): array
    {
        $problems = $this->problems();
        if ($problems === []) {
            return ['problems' => [], 'sent' => 0];
        }
        $last = $this->getState('monitor.last_alert_at');
        if (!$force && $last !== null && $last > $this->clock->db('-20 hours')) {
            return ['problems' => $problems, 'sent' => 0];
        }
        $sent = $this->alertAdmins('Kalenderserver: Problem beim Import aus MP Feuer', $problems);
        $this->setState('monitor.last_alert_at', $this->clock->nowDb());
        return ['problems' => $problems, 'sent' => $sent];
    }

    /** @return array<string,int> Nutzung insgesamt */
    public function usage(): array
    {
        $d7 = $this->clock->db('-7 days');
        $d30 = $this->clock->db('-30 days');
        $feedUsers = fn (string $since) => (int) $this->db->value(
            "SELECT COUNT(DISTINCT f.user_id) FROM feed_tokens f JOIN users u ON u.id = f.user_id
              WHERE u.status = 'active' AND f.last_access_at >= ?", [$since]);
        $davUsers = fn (string $since) => (int) $this->db->value(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND last_dav_at >= ?", [$since]);
        $anyUsers = fn (string $since) => (int) $this->db->value(
            "SELECT COUNT(*) FROM users u WHERE u.status = 'active' AND (u.last_dav_at >= ? OR EXISTS (
                SELECT 1 FROM feed_tokens f WHERE f.user_id = u.id AND f.last_access_at >= ?))", [$since, $since]);
        return [
            'active' => (int) $this->db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'locked' => (int) $this->db->value("SELECT COUNT(*) FROM users WHERE status = 'locked_email'"),
            'dav_7' => $davUsers($d7),
            'dav_30' => $davUsers($d30),
            'feed_7' => $feedUsers($d7),
            'feed_30' => $feedUsers($d30),
            'any_7' => $anyUsers($d7),
            'any_30' => $anyUsers($d30),
        ];
    }
}
