<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\FormGuard;
use Booking\Http;
use Booking\Pages;
use Booking\View;

try {
    $app = App::boot();
    $now = $app->now();
    [$contactUrl, $contactLabel] = Pages::contact($app);

    header('Cache-Control: no-store');
    Http::html(View::page('booking', [
        'title'        => 'Запись на занятие',
        'script'       => '/assets/js/booking.js?v=1',
        'products'     => $app->products->active(),
        'calendar'     => $app->slots->freeByDay($now),
        'stamp'        => (new FormGuard((string)$app->config['secret']))->stamp($now->getTimestamp()),
        'contactUrl'   => $contactUrl,
        'contactLabel' => $contactLabel,
    ]));
} catch (\Throwable $e) {
    Pages::fail($e);
}
