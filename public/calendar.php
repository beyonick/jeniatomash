<?php
// Подписка на календарь: /calendar.php?key=СЕКРЕТ (ключ — ics_key в config.php).
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Ics;

try {
    $app = App::boot();
    $key = (string)($app->config['ics_key'] ?? '');
    if (strlen($key) < 16 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="zanyatiya.ics"');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo (new Ics($app->bookings, $app->domain()))->render($app->now());
} catch (\Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
}
