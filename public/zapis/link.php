<?php
// Ссылки из писем («подходит / не подходит»).
// GET только показывает вопрос: почтовые сканеры открывают ссылки сами,
// и действие не должно срабатывать без нажатия кнопки. Действие — по POST.
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\Bookings;
use Booking\Fmt;
use Booking\Http;
use Booking\Pages;
use Booking\StateError;
use Booking\View;

try {
    $app = App::boot();
    $now = $app->now();
    $raw = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    header('Cache-Control: no-store');

    if (Http::isPost()) {
        try {
            $result = $app->bookings->useToken($raw, $now);
        } catch (StateError $e) {
            Http::html(View::page('message', [
                'title' => 'Ссылка не действует', 'heading' => 'Ссылка не действует', 'noindex' => true,
                'text'  => 'Эта ссылка уже использована или устарела. Если что-то не так, ответьте на письмо Жени.',
                'linkUrl' => '/zapis/', 'linkText' => 'Выбрать время',
            ]));
        }
        $b = $result['booking'];
        $slot = Fmt::slot($b['starts_at'], $b['ends_at']);
        $page = $result['action'] === Bookings::TOKEN_ACCEPT
            ? ['heading' => 'Занятие подтверждено', 'text' => "Ждём вас: $slot (время московское). Подтверждение ушло на почту."]
            : ['heading' => 'Заявка отменена', 'text' => 'Спасибо, что ответили. Вы можете выбрать другое время.', 'linkUrl' => '/zapis/', 'linkText' => 'Выбрать время'];
        Http::htmlAndContinue(View::page('message', $page + ['title' => $page['heading'], 'noindex' => true]));
        try {
            $app->outbox->flushNew($now);
        } catch (\Throwable $e) {
            error_log((string)$e); // повторит cron
        }
        exit;
    }

    $token = $app->tokens->find($raw, $now);
    $b = $token ? $app->bookings->get((int)$token['booking_id']) : null;
    if ($b === null) {
        Http::html(View::page('message', [
            'title' => 'Ссылка не действует', 'heading' => 'Ссылка не действует', 'noindex' => true,
            'text'  => 'Эта ссылка уже использована или устарела. Если что-то не так, ответьте на письмо Жени.',
            'linkUrl' => '/zapis/', 'linkText' => 'Выбрать время',
        ]));
    }
    Http::html(View::page('link', [
        'title'   => 'Ответ на предложение',
        'noindex' => true,
        'booking' => $b,
        'action'  => $token['action'],
        'token'   => $raw,
    ]));
} catch (\Throwable $e) {
    Pages::fail($e);
}
