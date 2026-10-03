<?php
declare(strict_types=1);

namespace Booking;

/** Общее для публичных страниц. */
final class Pages
{
    /** Ссылка «написать Жене»: Telegram, если указан, иначе почта. @return array{0:string,1:string} [url, подпись] */
    public static function contact(App $app): array
    {
        $tg = trim((string)$app->settings->get('contact_telegram', ''), '@ ');
        if ($tg !== '') {
            return ['https://t.me/' . rawurlencode($tg), '@' . $tg];
        }
        $email = (string)$app->settings->get('contact_email', '');
        return ['mailto:' . $email, $email];
    }

    /** Страница ошибки без подробностей. */
    public static function fail(\Throwable $e): never
    {
        error_log((string)$e);
        Http::html(View::page('message', [
            'title'   => 'Что-то пошло не так',
            'heading' => 'Что-то пошло не так',
            'text'    => 'Попробуйте обновить страницу через минуту.',
            'noindex' => true,
        ]), 500);
    }
}
