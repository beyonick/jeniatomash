<?php
declare(strict_types=1);

namespace Booking;

/**
 * Получатель событий заявки: бот, письма, дубль на почту Жени.
 * Реализация — на этапе 4. Ошибка уведомления никогда не ломает заявку:
 * Bookings ловит исключения отсюда.
 *
 * События: created, confirmed, proposed, proposal_accepted, proposal_rejected,
 * declined, cancelled, expired, completed, no_show.
 */
interface Notifier
{
    /** @param array $booking заявка из Bookings::get(); $extra — например, токены ссылок */
    public function notify(string $event, array $booking, array $extra = []): void;
}
