<?php

declare(strict_types=1);

namespace Kalender;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Zeitquelle. Alle Zeitstempel in der Datenbank sind UTC im Format
 * "Y-m-d H:i:s". Tests können die Zeit festsetzen.
 */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';
    public const LOCAL_TZ = 'Europe/Berlin';

    private ?DateTimeImmutable $fixed = null;

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function fix(?DateTimeImmutable $at): void
    {
        $this->fixed = $at?->setTimezone(new DateTimeZone('UTC'));
    }

    /** Jetzt als DB-String (UTC) */
    public function nowDb(): string
    {
        return $this->now()->format(self::DB_FORMAT);
    }

    /** Relativ zu jetzt, z. B. "-30 minutes", als DB-String */
    public function db(string $modify): string
    {
        return $this->now()->modify($modify)->format(self::DB_FORMAT);
    }

    public static function toDb(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }

    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function localTz(): DateTimeZone
    {
        return new DateTimeZone(self::LOCAL_TZ);
    }

    /** DB-String (UTC) in lokale Zeit umrechnen */
    public static function toLocal(?string $value): ?DateTimeImmutable
    {
        return self::fromDb($value)?->setTimezone(self::localTz());
    }
}
