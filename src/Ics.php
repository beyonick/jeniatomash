<?php
declare(strict_types=1);

namespace Booking;

/**
 * Подписка на календарь (.ics) для телефона Жени.
 * Календарь телефона часто синхронизируется через iCloud/Google, поэтому
 * в событиях минимум данных: продукт, имя, ник. Без телефона и почты.
 */
final class Ics
{
    public function __construct(private Bookings $bookings, private string $domain) {}

    public function render(\DateTimeImmutable $now, int $pastDays = 30, int $futureDays = 120): string
    {
        $list = $this->bookings->between(
            Time::fmt($now->modify("-$pastDays days")->setTime(0, 0)),
            Time::fmt($now->modify("+$futureDays days")),
            [Status::PENDING, Status::PROPOSED, Status::CONFIRMED, Status::COMPLETED, Status::NO_SHOW]
        );
        $stamp = self::utc(Time::fmt($now));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//jenyatomash//zapis//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Занятия',
            'X-WR-TIMEZONE:Europe/Moscow',
            'REFRESH-INTERVAL;VALUE=DURATION:PT30M',
            'X-PUBLISHED-TTL:PT30M',
        ];
        foreach ($list as $b) {
            $pending = in_array($b['status'], [Status::PENDING, Status::PROPOSED], true);
            $summary = ($pending ? '? ' : '') . $b['client_name'] . ' — ' . $b['product_title'];
            $desc = Status::LABELS[$b['status']] . ($b['telegram'] ? "\nTelegram: @" . $b['telegram'] : '');
            $lines = [...$lines,
                'BEGIN:VEVENT',
                'UID:' . $b['public_id'] . '@' . $this->domain,
                'DTSTAMP:' . $stamp,
                'DTSTART:' . self::utc($b['starts_at']),
                'DTEND:' . self::utc($b['ends_at']),
                'SUMMARY:' . self::escape($summary),
                'DESCRIPTION:' . self::escape($desc),
                'STATUS:' . ($pending ? 'TENTATIVE' : 'CONFIRMED'),
                'END:VEVENT',
            ];
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function utc(string $moscow): string
    {
        return Time::parse($moscow)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
    }

    /** Строки длиннее 75 байт переносятся (RFC 5545), не разрывая UTF-8. */
    private static function fold(string $line): string
    {
        $out = '';
        $cur = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur) + strlen($ch) > 74) {
                $out .= $cur . "\r\n ";
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . $cur;
    }
}
