<?php
declare(strict_types=1);

namespace Booking;

/** Даты и деньги по-русски. */
final class Fmt
{
    private const MONTHS_GEN = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    private const MONTHS_NOM = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    private const WEEKDAYS = [1 => 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
    private const WEEKDAYS_SHORT = [1 => 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

    /** «среда, 7 октября» */
    public static function dayLong(string $dayOrDateTime): string
    {
        $d = Time::parse(substr($dayOrDateTime, 0, 10) . ' 00:00:00');
        return self::WEEKDAYS[(int)$d->format('N')] . ', ' . (int)$d->format('j') . ' ' . self::MONTHS_GEN[(int)$d->format('n')];
    }

    /** «ср, 7 окт» — для кнопок бота */
    public static function dayShort(string $dayOrDateTime): string
    {
        $d = Time::parse(substr($dayOrDateTime, 0, 10) . ' 00:00:00');
        return self::WEEKDAYS_SHORT[(int)$d->format('N')] . ', ' . (int)$d->format('j') . ' ' . mb_substr(self::MONTHS_GEN[(int)$d->format('n')], 0, 3);
    }

    /** «7 октября» */
    public static function date(string $dayOrDateTime): string
    {
        $d = Time::parse(substr($dayOrDateTime, 0, 10) . ' 00:00:00');
        return (int)$d->format('j') . ' ' . self::MONTHS_GEN[(int)$d->format('n')];
    }

    public static function month(int $n): string
    {
        return self::MONTHS_NOM[$n];
    }

    public static function weekdayShort(int $isoN): string
    {
        return self::WEEKDAYS_SHORT[$isoN];
    }

    /** «11:00» */
    public static function time(string $dateTime): string
    {
        return substr($dateTime, 11, 5);
    }

    /** «среда, 7 октября, 11:00–12:30» */
    public static function slot(string $startsAt, string $endsAt): string
    {
        return self::dayLong($startsAt) . ', ' . self::time($startsAt) . '–' . self::time($endsAt);
    }

    /** «20 000 ₽» с неразрывными пробелами */
    public static function money(int $rub): string
    {
        return number_format($rub, 0, ',', "\u{00A0}") . "\u{00A0}₽";
    }
}
