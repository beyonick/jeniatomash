<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Http;
use Booking\Pages;
use Booking\View;

try {
    $app = App::boot();
    Http::html(View::page('consent', [
        'title'   => 'Согласие на обработку персональных данных',
        'noindex' => true,
        'site'    => $app->domain(),
        'email'   => (string)$app->settings->get('contact_email', ''),
    ]));
} catch (\Throwable $e) {
    Pages::fail($e);
}
