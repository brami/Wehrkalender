<?php

declare(strict_types=1);

namespace Kalender\Mail;

use Kalender\Clock;
use Kalender\Config;
use Kalender\Database\Db;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Arbeitet die Mail-Warteschlange ab (bin/console mail:send).
 *
 * MAIL_DELIVERY=database: nichts wird verschickt; E-Mails erhalten den Status
 * "suppressed" und sind im Admin-Bereich als Vorschau sichtbar (Testbetrieb).
 * MAIL_DELIVERY=smtp: Versand über MAILER_DSN, höchstens 60 je Lauf,
 * bis zu 5 Versuche mit wachsendem Abstand.
 */
final class MailSender
{
    public const BATCH = 60;
    public const MAX_ATTEMPTS = 5;
    /** Wartezeit nach Versuch n (Minuten) */
    private const BACKOFF = [1 => 5, 2 => 15, 3 => 60, 4 => 240];

    private ?MailerInterface $mailer = null;

    public function __construct(private Db $db, private Config $config, private Clock $clock, ?MailerInterface $mailer = null)
    {
        $this->mailer = $mailer;
    }

    public function deliveryMode(): string
    {
        return $this->config->get('MAIL_DELIVERY', 'database') === 'smtp' ? 'smtp' : 'database';
    }

    /** @return array{sent:int,failed:int,suppressed:int} */
    public function run(): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'suppressed' => 0];
        $rows = $this->db->all(
            "SELECT * FROM mail_queue WHERE status IN ('queued', 'failed') AND attempts < ? AND next_attempt_at <= ?
             ORDER BY next_attempt_at, id LIMIT " . self::BATCH,
            [self::MAX_ATTEMPTS, $this->clock->nowDb()]
        );
        foreach ($rows as $row) {
            if ($this->deliveryMode() === 'database') {
                $this->db->update('mail_queue', ['status' => 'suppressed'], ['id' => $row['id']]);
                $result['suppressed']++;
                continue;
            }
            try {
                $this->send($row);
                $this->db->update('mail_queue', [
                    'status' => 'sent',
                    'attempts' => (int) $row['attempts'] + 1,
                    'sent_at' => $this->clock->nowDb(),
                    'last_error' => null,
                ], ['id' => $row['id']]);
                $result['sent']++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $wait = self::BACKOFF[$attempts] ?? 240;
                $this->db->update('mail_queue', [
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    'next_attempt_at' => $this->clock->db("+{$wait} minutes"),
                ], ['id' => $row['id']]);
                $result['failed']++;
            }
        }
        return $result;
    }

    /** E-Mail erneut einreihen (Schaltfläche im Admin-Bereich) */
    public function retry(int $id): void
    {
        $this->db->update('mail_queue', [
            'status' => 'queued',
            'attempts' => 0,
            'next_attempt_at' => $this->clock->nowDb(),
            'last_error' => null,
        ], ['id' => $id]);
    }

    /**
     * Test-E-Mail direkt über MAILER_DSN senden, unabhängig von MAIL_DELIVERY
     * und ohne Warteschlange (bin/console mail:test). Wirft bei Fehlern.
     */
    public function sendTest(string $to): void
    {
        if (trim((string) $this->config->get('MAILER_DSN', '')) === '') {
            throw new \RuntimeException('MAILER_DSN ist leer.');
        }
        $this->send([
            'recipient' => $to,
            'subject' => 'Test-E-Mail vom ' . $this->config->appName(),
            'body_text' => "Diese E-Mail bestätigt, dass der Versand vom Kalenderserver funktioniert.\n\n"
                . 'Server: https://' . $this->config->domain() . "\n",
            'body_html' => '<p>Diese E-Mail bestätigt, dass der Versand vom Kalenderserver funktioniert.</p>'
                . '<p>Server: https://' . htmlspecialchars($this->config->domain()) . '</p>',
        ]);
    }

    /** MAILER_DSN mit ausgeblendetem Passwort, für Ausgaben */
    public function maskedDsn(): string
    {
        $dsn = (string) $this->config->get('MAILER_DSN', '');
        return preg_replace('#^([a-z+]+://[^:@/]*):[^@]*@#i', '$1:****@', $dsn) ?? '';
    }

    /** @param array<string,mixed> $row */
    private function send(array $row): void
    {
        $this->mailer ??= new Mailer(Transport::fromDsn($this->config->get('MAILER_DSN')));
        $from = new Address(
            $this->config->get('MAIL_FROM', 'kalender@' . $this->config->domain()),
            $this->config->get('MAIL_FROM_NAME', $this->config->appName())
        );
        $email = (new Email())
            ->from($from)
            ->to($row['recipient'])
            ->subject($row['subject'])
            ->text($row['body_text'])
            ->html($row['body_html']);
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $this->mailer->send($email);
    }
}
