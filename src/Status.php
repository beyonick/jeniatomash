<?php
declare(strict_types=1);

namespace Booking;

/** Статусы заявки. Подробно — в «Структура и БД — предложение.md». */
final class Status
{
    public const PENDING   = 'pending';
    public const PROPOSED  = 'proposed';
    public const CONFIRMED = 'confirmed';
    public const COMPLETED = 'completed';
    public const NO_SHOW   = 'no_show';
    public const DECLINED  = 'declined';
    public const CANCELLED = 'cancelled';
    public const EXPIRED   = 'expired';

    /** Статусы, при которых заявка занимает слот (slot_lock заполнен). */
    public const HOLDS_SLOT = [self::PENDING, self::PROPOSED, self::CONFIRMED, self::COMPLETED, self::NO_SHOW];

    public const LABELS = [
        self::PENDING   => 'Ожидает подтверждения',
        self::PROPOSED  => 'Предложено другое время',
        self::CONFIRMED => 'Подтверждена',
        self::COMPLETED => 'Проведено',
        self::NO_SHOW   => 'Не пришёл',
        self::DECLINED  => 'Отклонена',
        self::CANCELLED => 'Отменена',
        self::EXPIRED   => 'Бронь снята',
    ];

    public static function holdsSlot(string $status): bool
    {
        return in_array($status, self::HOLDS_SLOT, true);
    }
}
