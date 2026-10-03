<?php
// Webhook бота. Telegram присылает заголовок X-Telegram-Bot-Api-Secret-Token
// со значением, заданным при установке webhook (bin/telegram.php set-webhook).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Bot;
use Booking\Http;

$app = App::boot();
$secret = (string)($app->config['telegram']['webhook_secret'] ?? '');
$given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($secret === '' || !hash_equals($secret, $given) || !Http::isPost()) {
    http_response_code(403);
    exit;
}

$update = json_decode((string)file_get_contents('php://input'), true);

// Ответить Telegram сразу, чтобы он не слал повторы; обработать после.
Http::jsonAndContinue([]);
if (!is_array($update)) {
    exit;
}
$now = $app->now();
try {
    (new Bot($app))->handle($update, $now);
} catch (\Throwable $e) {
    error_log((string)$e);
}
try {
    $app->outbox->flushNew($now);
} catch (\Throwable $e) {
    error_log((string)$e);
}
