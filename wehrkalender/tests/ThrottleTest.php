<?php

declare(strict_types=1);

namespace Wehrkalender\Tests;

use Wehrkalender\Security\LoginThrottle;

/** A9: gesperrte Anmeldungen erkennen, anzeigen und zurücksetzen */
final class ThrottleTest extends TestCase
{
    public function testMaskIp(): void
    {
        self::assertSame('203.0.113.x', LoginThrottle::maskIp('203.0.113.57'));
        self::assertSame('2001:db8:1234:x', LoginThrottle::maskIp('2001:0db8:1234:5678::1'));
        self::assertSame('unbekannt', LoginThrottle::maskIp('kein-ip'));
    }

    public function testBlockedListAndReset(): void
    {
        $u = $this->member('1', 'Leon', 'Braun');
        $t = $this->app->throttle();
        $ip = $t->ipKey('203.0.113.57');
        for ($i = 0; $i < 10; $i++) {
            $t->hit('user:' . $u['id'], 'leon.braun');
            $t->hit('dav:leon.braun', 'leon.braun');
        }
        for ($i = 0; $i < 29; $i++) {
            $t->hit($ip, '203.0.113.x');
        }
        $t->hit('link:' . $u['id'], 'leon.braun'); // 1 von 3 – nicht gesperrt

        $blocked = $t->blocked();
        $kinds = array_column($blocked, 'kind');
        sort($kinds);
        self::assertSame(['dav', 'user'], $kinds, 'IP mit 29 von 30 und Link mit 1 von 3 sind nicht gesperrt');
        foreach ($blocked as $b) {
            self::assertSame((int) $u['id'], $b['user_id']);
            self::assertSame('leon.braun', $b['info']);
            self::assertSame(10, $b['count']);
        }

        $t->hit($ip, '203.0.113.x');
        $ipBlock = array_values(array_filter($t->blocked(), fn ($b) => $b['kind'] === 'ip'))[0];
        self::assertSame('203.0.113.x', $ipBlock['info']);
        self::assertNull($ipBlock['user_id']);
        self::assertSame($this->clock->db('+1 hour'), $ipBlock['until'], 'alle Versuche zur selben Zeit → frei in einer Stunde');

        // Zurücksetzen einzeln und insgesamt
        $t->clear('user:' . $u['id']);
        self::assertFalse($t->tooMany('user:' . $u['id'], LoginThrottle::USER_FAILS_PER_HOUR));
        self::assertCount(2, $t->blocked());
        $t->clearAll();
        self::assertSame([], $t->blocked());
    }

    public function testBlockExpiresAfterOneHour(): void
    {
        $t = $this->app->throttle();
        for ($i = 0; $i < 10; $i++) {
            $t->hit('dav:max.mustermann', 'max.mustermann');
        }
        self::assertCount(1, $t->blocked());
        $this->clock->fix($this->clock->now()->modify('+61 minutes'));
        self::assertSame([], $t->blocked());
    }
}
