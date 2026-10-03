<?php
declare(strict_types=1);

namespace Booking;

/** Запоминает события. Для тестов и пока нет настоящих уведомлений. */
final class RecordingNotifier implements Notifier
{
    /** @var array<array{event:string,booking:array,extra:array}> */
    public array $events = [];

    public function notify(string $event, array $booking, array $extra = []): void
    {
        $this->events[] = ['event' => $event, 'booking' => $booking, 'extra' => $extra];
    }

    public function last(): ?array
    {
        return $this->events[array_key_last($this->events)] ?? null;
    }
}
