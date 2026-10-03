<?php
declare(strict_types=1);

namespace Booking;

/**
 * Уведомления о событиях заявки. Ставит в очередь:
 * - письмо ученику (если событие его касается);
 * - дубль на почту Жени — по каждому событию;
 * - сообщение в бот — если событие сделала не сама Женя (её действия бот показывает правкой карточки).
 */
final class Notify implements Notifier
{
    public function __construct(
        private Outbox $outbox,
        private Texts $texts,
        private Telegram $telegram,
        private string $adminEmail,
        private \Closure $clock,
    ) {}

    public function notify(string $event, array $booking, array $extra = []): void
    {
        $now = ($this->clock)();
        $actor = $extra['actor'] ?? 'system';

        $client = $this->texts->clientEmail($event, $booking, $extra);
        if ($client !== null) {
            $this->outbox->email($booking['email'], $client[0], $client[1], $now);
        }

        if ($this->adminEmail !== '') {
            [$subject, $body] = $this->texts->adminEmail($event, $booking, $extra);
            $this->outbox->email($this->adminEmail, $subject, $body, $now);
        }

        if (!in_array($actor, ['admin', 'bot'], true)) {
            $this->toBot($booking, $this->texts->adminTitle($event, $booking, $extra), $now, $event === 'created');
        }
    }

    /** Карточка заявки в бот с кнопками по текущему статусу. */
    public function toBot(array $booking, string $title, \DateTimeImmutable $now, bool $remember = false): void
    {
        $chat = $this->telegram->adminChatId();
        if ($chat === '' && $this->telegram->enabled()) {
            return; // бот подключён, но чат Жени ещё не указан
        }
        $meta = ['reply_markup' => Bot::bookingKeyboard($booking, $now)];
        if ($remember) {
            $meta['booking_id'] = (int)$booking['id'];
        }
        $this->outbox->telegram($chat ?: 'local', $this->texts->adminCard($booking, $title), $meta, $now);
    }
}
