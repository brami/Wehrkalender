<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\Import\DnsUnavailable;
use Wehrkalender\Import\EmailValidator;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class EmailValidatorTest extends BaseTestCase
{
    public function testSyntaxAndDns(): void
    {
        $calls = 0;
        $v = new EmailValidator(function (string $host, string $type) use (&$calls): bool {
            $calls++;
            return in_array($host, ['example.org.', 'denic.de.'], true) && $type === 'MX';
        });
        self::assertTrue($v->isValid('anna@example.org'));
        self::assertTrue($v->isValid('ANNA@Example.org'));
        self::assertFalse($v->isValid('kein-at.example.org'));
        self::assertFalse($v->isValid(''));
        self::assertFalse($v->isValid(null));
        self::assertFalse($v->isValid('max@tippfehler.invalid'));
        $before = $calls;
        self::assertFalse($v->isValid('moritz@tippfehler.invalid'));
        self::assertSame($before, $calls, 'Ergebnis je Domain zwischengespeichert');
    }

    public function testDnsOutageThrows(): void
    {
        $v = new EmailValidator(fn () => false);
        $this->expectException(DnsUnavailable::class);
        $v->isValid('anna@example.org');
    }
}
