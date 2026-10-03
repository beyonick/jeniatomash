<?php
// Приём формы записи.
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\FormGuard;
use Booking\Http;
use Booking\SlotUnavailable;
use Booking\ValidationError;

if (!Http::isPost()) {
    Http::json(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

try {
    $app = App::boot();
    $now = $app->now();
    $ip = Http::ip();

    $guard = (new FormGuard((string)$app->config['secret']))->check($_POST, $now->getTimestamp());
    if ($guard === 'stale') {
        Http::json(['ok' => false, 'error' => 'Страница была открыта слишком долго. Обновите её и выберите время заново.'], 400);
    }
    if ($guard !== null) {
        // Похоже на бота. Отвечаем «успехом», чтобы не подсказывать, что именно не так.
        error_log("Форма записи отклонена: $guard");
        Http::json(['ok' => true, 'email' => '']);
    }

    if (!$app->rateLimit->attempt($ip, 'book', 10, 600, $now) || !$app->rateLimit->attempt($ip, 'book_day', 20, 86400, $now)) {
        Http::json(['ok' => false, 'error' => 'Слишком много попыток. Попробуйте позже.'], 429);
    }

    $booking = $app->bookings->create($_POST, $now, $ip);
} catch (ValidationError $e) {
    Http::json(['ok' => false, 'errors' => $e->errors], 422);
} catch (SlotUnavailable $e) {
    Http::json(['ok' => false, 'reason' => $e->reason, 'error' => $e->getMessage()], 409);
} catch (\Throwable $e) {
    error_log((string)$e);
    Http::json(['ok' => false, 'error' => 'Не удалось отправить заявку. Попробуйте ещё раз через минуту.'], 500);
}

// Ответ браузеру сразу, письма и сообщение в бот — после.
Http::jsonAndContinue(['ok' => true, 'email' => $booking['email']]);
try {
    $app->outbox->flushNew($now);
} catch (\Throwable $e) {
    error_log((string)$e); // не отправилось — повторит cron
}
