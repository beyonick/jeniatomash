<?php
// Запуск раз в 5 минут: php /путь/к/booking/cron/run.php
// Снимает просроченные заявки, напоминает Жене, шлёт сводку на завтра,
// спрашивает «как прошло занятие», повторяет неотправленные уведомления.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Cron;

// Не запускать второй экземпляр, если предыдущий ещё работает.
$lock = fopen(__DIR__ . '/../var/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $app = App::boot();
    $done = (new Cron($app))->run($app->now());
    $line = date('Y-m-d H:i:s') . ' ' . json_encode($done, JSON_UNESCAPED_UNICODE) . "\n";
    // В лог — только если что-то произошло.
    if ($done['expired'] || $done['reminded'] || $done['summary'] || $done['outcome_asked'] || $done['outbox']['sent'] || $done['outbox']['failed']) {
        file_put_contents(__DIR__ . '/../var/cron.log', $line, FILE_APPEND | LOCK_EX);
    }
} catch (\Throwable $e) {
    file_put_contents(__DIR__ . '/../var/cron.log', date('Y-m-d H:i:s') . ' ОШИБКА ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
