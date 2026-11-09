<?php

declare(strict_types=1);

namespace Kalender\Mail;

use Kalender\Clock;
use Kalender\Database\Db;
use Twig\Environment;

/**
 * Reiht E-Mails in mail_queue ein. Text- und HTML-Version kommen aus
 * templates/mail/<typ>.twig (Blöcke "subject", "text" und das HTML-Layout).
 * Verschickt wird später vom Mail-Job (mail:send).
 */
final class MailQueue
{
    public const TYPES = ['welcome', 'login_link', 'new_calendar', 'username_changed', 'admin_alert'];

    public function __construct(private Db $db, private Environment $twig, private Clock $clock)
    {
    }

    /** @param array<string,mixed> $vars */
    public function enqueue(string $type, string $recipient, array $vars, ?int $userId = null): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unbekannter Mail-Typ: $type");
        }
        $template = $this->twig->load("mail/$type.twig");
        $subject = trim(preg_replace('/\s+/', ' ', $template->renderBlock('subject', $vars)) ?? '');
        $text = trim($template->renderBlock('text', $vars)) . "\n";
        $html = $template->render($vars);
        $now = $this->clock->nowDb();
        return $this->db->insert('mail_queue', [
            'user_id' => $userId,
            'type' => $type,
            'recipient' => $recipient,
            'subject' => $subject,
            'body_text' => $text,
            'body_html' => $html,
            'status' => 'queued',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
        ]);
    }
}
