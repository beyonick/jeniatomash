<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Время храним московским строкой 'Y-m-d H:i:s': в Москве нет перехода
 * на летнее время, поэтому одинаково для MySQL и SQLite и без конвертаций.
 */
final class Time
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone('Europe/Moscow');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    public static function parse(string $value): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, self::tz())
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, self::tz());
        if ($dt === false) {
            throw new \InvalidArgumentException("Неверный формат времени: $value");
        }
        return $dt;
    }

    public static function fmt(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::tz())->format(self::FORMAT);
    }

    public static function isDay(string $day): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $day, self::tz());
        return $dt !== false && $dt->format('Y-m-d') === $day;
    }
}
