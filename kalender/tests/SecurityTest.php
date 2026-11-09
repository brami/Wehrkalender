<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\Security\PasswordService;
use Kalender\Security\Tokens;
use Kalender\Web\ClientIp;

final class SecurityTest extends TestCase
{
    public function testPasswordFormatAndEncryption(): void
    {
        $svc = $this->app->passwords();
        $pw = $svc->generate();
        self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz2-9]{5}(-[abcdefghjkmnpqrstuvwxyz2-9]{5}){3}$/', $pw);
        $enc = $svc->encrypt($pw);
        self::assertStringNotContainsString($pw, $enc);
        self::assertTrue($svc->verify($enc, $pw));
        self::assertTrue($svc->verify($enc, ' ' . strtoupper($pw) . ' '));
        self::assertFalse($svc->verify($enc, 'falsch'));
        $other = new PasswordService(base64_encode(str_repeat('x', 32)));
        self::assertNull($other->decrypt($enc), 'ohne APP_KEY nicht lesbar');
    }

    public function testTokens(): void
    {
        $t = Tokens::random();
        self::assertSame(43, strlen($t));
        self::assertTrue(Tokens::isWellFormed($t));
        self::assertSame(64, strlen(Tokens::hash($t)));
    }

    public function testWelcomeLinkFlow(): void
    {
        $u = $this->member('1', 'Lena', 'Fischer');
        $first = $this->app->accounts()->queueWelcome((int) $u['id']);
        $second = $this->app->accounts()->queueWelcome((int) $u['id']); // erneut senden
        self::assertSame('expired', $this->app->accounts()->checkLoginToken($first)['status'], 'alter Link ungültig');
        self::assertSame('ok', $this->app->accounts()->checkLoginToken($second)['status']);
        self::assertNull($this->app->db()->value('SELECT token_hash FROM login_tokens WHERE token_hash = ?', [$second]), 'nur Hash gespeichert');
        self::assertNotNull($this->app->accounts()->consumeLoginToken($second));
        self::assertSame('used', $this->app->accounts()->checkLoginToken($second)['status']);
        self::assertNull($this->app->accounts()->consumeLoginToken($second));
        self::assertNotNull($this->app->accounts()->find((int) $u['id'])['welcome_used_at']);
        $mail = $this->app->db()->one("SELECT * FROM mail_queue WHERE type = 'welcome' ORDER BY id DESC");
        self::assertStringContainsString('lena.fischer', $mail['body_text']);
        self::assertStringNotContainsString($this->password($u), $mail['body_text'], 'kein Passwort in E-Mails');
        self::assertStringNotContainsString($this->password($u), $mail['body_html']);
    }

    public function testRequestLinkExpiresAfter30Minutes(): void
    {
        $u = $this->member('1', 'Paul', 'Wagner');
        $t = $this->app->accounts()->queueLoginLink((int) $u['id']);
        $this->clock->fix($this->clock->now()->modify('+31 minutes'));
        self::assertSame('expired', $this->app->accounts()->checkLoginToken($t)['status']);
    }

    public function testClientIp(): void
    {
        $proxies = ['10.0.0.0/8'];
        self::assertSame('203.0.113.5', ClientIp::resolve(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], $proxies));
        self::assertSame('198.51.100.1', ClientIp::resolve(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], $proxies), 'XFF nur vom Proxy');
    }
}
