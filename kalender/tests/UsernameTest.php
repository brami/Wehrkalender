<?php

declare(strict_types=1);

namespace Kalender\Tests;

use Kalender\Domain\UsernamePolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class UsernameTest extends TestCase
{
    public static function names(): array
    {
        return [
            ['Jürgen', 'Müller', 'juergen.mueller'],
            ['Sabine', 'Weiß', 'sabine.weiss'],
            ['ÄÖÜ', 'Straße', 'aeoeue.strasse'],
            ['Karl-Heinz', 'Müller-Lüdenscheidt', 'karl-heinz.mueller-luedenscheidt'],
            ['Hans Peter', 'von der Heide', 'hans-peter.von-der-heide'],
            ['François', 'Đorđević', 'francois.dordevic'],
            ['Ömer', 'Yılmaz', 'oemer.yilmaz'],
            ['  Max ', " O'Brien ", 'max.obrien'],
            ['Anna-  Lena', 'Schmidt', 'anna-lena.schmidt'],
            ['', 'Nur Nachname', 'nur-nachname'],
        ];
    }

    #[DataProvider('names')]
    public function testStem(string $first, string $last, string $expected): void
    {
        self::assertSame($expected, UsernamePolicy::stem($first, $last));
    }

    public function testDigitSuffixForDuplicates(): void
    {
        $a = $this->member('1', 'Max', 'Mustermann');
        $b = $this->member('2', 'Max', 'Mustermann');
        $c = $this->member('3', 'Max', 'Mustermann');
        self::assertSame(['max.mustermann', 'max.mustermann2', 'max.mustermann3'], [$a['username'], $b['username'], $c['username']]);
        self::assertFalse(UsernamePolicy::isOutdated('max.mustermann2', 'Max', 'Mustermann'));
    }

    public function testOutdatedDetection(): void
    {
        self::assertTrue(UsernamePolicy::isOutdated('anna.schmidt', 'Anna', 'Becker'));
        self::assertTrue(UsernamePolicy::isOutdated('anna.schmidt2', 'Anna', 'Becker'));
        self::assertFalse(UsernamePolicy::isOutdated('juergen.mueller', 'Jürgen', 'Müller'));
    }

    public function testChangeUsernameReservesOldName(): void
    {
        $anna = $this->member('4711', 'Anna', 'Schmidt');
        $this->app->db()->update('users', ['last_name' => 'Becker'], ['id' => $anna['id']]);
        $pwBefore = $this->password($anna);
        $new = $this->app->accounts()->changeUsername((int) $anna['id'], (int) $anna['id']);
        self::assertSame('anna.becker', $new);
        self::assertSame($pwBefore, $this->password($anna), 'Passwort bleibt');
        // Der alte Name ist reserviert: eine neue Anna Schmidt bekommt anna.schmidt2
        $neu = $this->member('4712', 'Anna', 'Schmidt');
        self::assertSame('anna.schmidt2', $neu['username']);
        // Mail an das Mitglied
        self::assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE type = 'username_changed' AND user_id = ?", [$anna['id']]));
        // nicht noch einmal umstellbar
        $this->expectException(\RuntimeException::class);
        $this->app->accounts()->changeUsername((int) $anna['id'], (int) $anna['id']);
    }

    public function testSuggestionSkipsTakenNames(): void
    {
        $this->member('1', 'Marie', 'Koch');
        $vogel = $this->member('2', 'Marie', 'Vogel');
        $this->app->db()->update('users', ['last_name' => 'Koch'], ['id' => $vogel['id']]);
        self::assertSame('marie.koch2', $this->app->accounts()->changeUsername((int) $vogel['id'], 99));
    }

    public function testOwnFormerNameMayBeReused(): void
    {
        $u = $this->member('1', 'Anna', 'Schmidt');
        $this->app->db()->update('users', ['last_name' => 'Becker'], ['id' => $u['id']]);
        $this->app->accounts()->changeUsername((int) $u['id'], (int) $u['id']);
        $this->app->db()->update('users', ['last_name' => 'Schmidt'], ['id' => $u['id']]);
        self::assertSame('anna.schmidt', $this->app->accounts()->changeUsername((int) $u['id'], (int) $u['id']));
    }
}
