<?php
declare(strict_types=1);

namespace Booking;

// Ошибки предметной области. Сообщения можно показывать пользователю.
// Несколько классов в одном файле, поэтому он подключается из bootstrap.php напрямую.

/** Ошибки полей формы: ['email' => 'Проверьте адрес почты', ...]. */
final class ValidationError extends \RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Проверьте поля формы');
    }
}

/** Слот нельзя занять. $reason: not_in_grid, too_late, too_far, closed, taken. */
final class SlotUnavailable extends \RuntimeException
{
    public const MESSAGES = [
        'not_in_grid' => 'Такого времени нет в расписании.',
        'too_late'    => 'На это время записаться уже нельзя: запись закрывается за %d ч до начала.',
        'too_far'     => 'Запись на эту дату ещё не открыта.',
        'closed'      => 'Это время недоступно.',
        'taken'       => 'Это время только что заняли. Выберите, пожалуйста, другое.',
    ];

    public function __construct(public readonly string $reason, int $minHours = 12)
    {
        parent::__construct(sprintf(self::MESSAGES[$reason] ?? 'Это время недоступно.', $minHours));
    }
}

/** Действие невозможно в текущем статусе заявки. */
final class StateError extends \RuntimeException {}
