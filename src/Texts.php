<?php
declare(strict_types=1);

namespace Booking;

/**
 * Тексты писем ученику и сообщений Жене. Ученику — на «вы».
 * Жене в Telegram и в дубль на почту — минимум данных: время, продукт, имя, ник.
 * Телефон и почта ученика — только в админке.
 */
final class Texts
{
    public function __construct(private Settings $settings, private string $baseUrl) {}

    // --- Письма ученику ---

    /** @return array{0:string,1:string}|null [тема, текст] или null — не писать */
    public function clientEmail(string $event, array $b, array $extra): ?array
    {
        $slot = Fmt::slot($b['starts_at'], $b['ends_at']);
        $when = Fmt::date($b['starts_at']) . ', ' . Fmt::time($b['starts_at']);
        $again = $this->url('/zapis/');

        [$subject, $body] = match ($event) {
            'created' => [
                'Заявка на занятие получена',
                "Вы записались на занятие:\n\n{$b['product_title']}\n$slot (время московское)\n\n"
                . "Женя посмотрит заявку и подтвердит время. Подтверждение придёт на эту почту.",
            ],
            'confirmed', 'proposal_accepted' => [
                "Занятие подтверждено: $when",
                "Женя подтвердила занятие:\n\n{$b['product_title']}\n$slot (время московское)\n\n"
                . $this->settings->get('lesson_join_note', ''),
            ],
            'proposed' => [
                'Женя предлагает другое время',
                'Время, которое вы выбрали (' . Fmt::slot($b['requested_starts_at'], $this->endFor($b['requested_starts_at'], $b))
                . "), не получится. Женя предлагает:\n\n{$b['product_title']}\n$slot (время московское)\n\n"
                . "Подходит:\n" . $this->url('/zapis/link.php?t=' . $extra['accept_token']) . "\n\n"
                . "Не подходит:\n" . $this->url('/zapis/link.php?t=' . $extra['reject_token']) . "\n\n"
                . 'Если не ответить, бронь снимется за ' . $this->settings->int('unconfirmed_expire_hours') . ' ч до начала.',
            ],
            'declined' => [
                'Заявка на занятие не подтверждена',
                "К сожалению, Женя не сможет провести занятие $slot.\n\n"
                . "Выберите, пожалуйста, другое время:\n$again",
            ],
            'cancelled' => [
                "Занятие отменено: $when",
                "К сожалению, Женя отменила занятие $slot.\n\n"
                . "Выбрать другое время:\n$again",
            ],
            'proposal_rejected' => [
                'Заявка отменена',
                "Вы ответили, что предложенное время ($slot) не подходит, поэтому заявка отменена.\n\n"
                . "Выбрать другое время:\n$again",
            ],
            'expired' => [
                'Бронь снята',
                "Заявка на $slot не была подтверждена вовремя, поэтому бронь снята. Извините за неудобство.\n\n"
                . "Выбрать другое время:\n$again",
            ],
            default => [null, null],
        };
        if ($subject === null) {
            return null;
        }
        $body = "Здравствуйте, {$b['client_name']}!\n\n$body\n\n"
            . "Если остались вопросы, просто ответьте на это письмо.\n\n"
            . $this->signature();
        return [$subject, $body];
    }

    // --- Жене ---

    /** Короткая строка о заявке: «ср, 7 окт, 11:00–12:30 · Разовое занятие, 1,5 часа · Анна · @anna_qi» */
    public function summaryLine(array $b): string
    {
        return Fmt::dayShort($b['starts_at']) . ', ' . Fmt::time($b['starts_at']) . '–' . Fmt::time($b['ends_at'])
            . ' · ' . $b['product_title'] . ' · ' . $b['client_name'] . ' · ' . $this->contactHint($b);
    }

    /** Заголовок события для Жени. */
    public function adminTitle(string $event, array $b, array $extra = []): string
    {
        return match ($event) {
            'created'           => 'Новая заявка',
            'confirmed'         => 'Заявка подтверждена',
            'proposed'          => 'Предложено другое время, ждём ответа ученика',
            'proposal_accepted' => 'Ученик согласился на предложенное время, занятие подтверждено',
            'proposal_rejected' => 'Ученику не подошло предложенное время, заявка отменена, слот свободен',
            'declined'          => 'Заявка отклонена',
            'cancelled'         => 'Занятие отменено',
            'expired'           => 'Заявка снята: не подтверждена за ' . $this->settings->int('unconfirmed_expire_hours') . ' ч до начала',
            'completed'         => 'Отмечено: проведено',
            'no_show'           => 'Отмечено: не пришёл',
            'reminder'          => 'Заявка ждёт ответа',
            default             => $event,
        };
    }

    /** Карточка заявки для бота (HTML Telegram). */
    public function adminCard(array $b, string $title): string
    {
        $lines = [
            '<b>' . e($title) . '</b>',
            '',
            '<b>' . e(Fmt::dayShort($b['starts_at']) . ', ' . Fmt::time($b['starts_at']) . '–' . Fmt::time($b['ends_at'])) . '</b>',
            e($b['product_title']),
            e($b['client_name']) . ' · ' . e($this->contactHint($b)),
        ];
        if (!empty($b['requested_starts_at']) && $b['requested_starts_at'] !== $b['starts_at']) {
            $lines[] = 'Изначально выбрано: ' . e(Fmt::dayShort($b['requested_starts_at']) . ', ' . Fmt::time($b['requested_starts_at']));
        }
        $lines[] = 'Статус: ' . e(Status::LABELS[$b['status']] ?? $b['status']);
        return implode("\n", $lines);
    }

    /** Дубль на почту Жени: [тема, текст]. */
    public function adminEmail(string $event, array $b, array $extra = []): array
    {
        $title = $this->adminTitle($event, $b, $extra);
        $subject = '[Запись] ' . $title . ': ' . Fmt::dayShort($b['starts_at']) . ', ' . Fmt::time($b['starts_at']) . ' — ' . $b['client_name'];
        $body = $title . "\n\n"
            . Fmt::slot($b['starts_at'], $b['ends_at']) . "\n"
            . $b['product_title'] . "\n"
            . $b['client_name'] . ' · ' . $this->contactHint($b) . "\n"
            . 'Статус: ' . (Status::LABELS[$b['status']] ?? $b['status']) . "\n\n"
            . "Открыть в админке:\n" . $this->url('/admin/?p=booking&id=' . $b['id']);
        return [$subject, $body];
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function contactHint(array $b): string
    {
        return $b['telegram'] ? '@' . $b['telegram'] : 'без Telegram, телефон в админке';
    }

    private function signature(): string
    {
        return $this->settings->get('brand_name', '') . "\n" . $this->settings->get('brand_tagline', '') . "\n" . $this->url('/');
    }

    /** Конец слота для времени, которое ученик выбирал изначально. */
    private function endFor(string $startsAt, array $b): string
    {
        $len = Time::parse($b['ends_at'])->getTimestamp() - Time::parse($b['starts_at'])->getTimestamp();
        return Time::fmt(Time::parse($startsAt)->modify("+$len seconds"));
    }
}
