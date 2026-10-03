<?php
// Свободные слоты на горизонт записи. Страница запрашивает их заново, если выбранное время заняли.
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\Http;

try {
    $app = App::boot();
    Http::json(['ok' => true, 'calendar' => $app->slots->freeByDay($app->now())]);
} catch (\Throwable $e) {
    error_log((string)$e);
    Http::json(['ok' => false, 'error' => 'Не удалось загрузить расписание.'], 500);
}
