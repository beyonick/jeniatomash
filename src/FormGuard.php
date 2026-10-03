<?php
declare(strict_types=1);

namespace Booking;

/**
 * Защита формы от ботов без внешних сервисов:
 * - honeypot: скрытое поле, которое заполняют только боты;
 * - подписанная метка времени: форма отправлена не раньше чем через N секунд
 *   после загрузки страницы и не позже чем через сутки.
 */
final class FormGuard
{
    public const HONEYPOT = 'website';
    public const STAMP = 'stamp';

    public function __construct(private string $secret, private int $minSeconds = 3) {}

    public function stamp(int $now): string
    {
        return $now . '.' . substr(hash_hmac('sha256', (string)$now, $this->secret), 0, 32);
    }

    /** null — всё в порядке, иначе причина (в лог, не пользователю). */
    public function check(array $in, int $now): ?string
    {
        if (trim((string)($in[self::HONEYPOT] ?? '')) !== '') {
            return 'honeypot';
        }
        $parts = explode('.', (string)($in[self::STAMP] ?? ''), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return 'no_stamp';
        }
        $expected = substr(hash_hmac('sha256', $parts[0], $this->secret), 0, 32);
        if (!hash_equals($expected, $parts[1])) {
            return 'bad_stamp';
        }
        $age = $now - (int)$parts[0];
        if ($age < $this->minSeconds) {
            return 'too_fast';
        }
        if ($age > 86400) {
            return 'stale';
        }
        return null;
    }
}
