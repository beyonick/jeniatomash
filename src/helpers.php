<?php
declare(strict_types=1);

namespace Booking;

/** Экранирование вывода в HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
