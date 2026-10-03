<?php
declare(strict_types=1);

namespace Booking;

/** Мелочи HTTP: ответы, IP, заголовки безопасности. */
final class Http
{
    /** Временный адрес на время разработки: не индексировать (config.php → noindex). */
    public static bool $noindex = false;

    public static function securityHeaders(): void
    {
        if (self::$noindex) {
            header('X-Robots-Tag: noindex, nofollow');
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        self::securityHeaders();
        echo $html;
        exit;
    }

    public static function redirect(string $url, int $status = 303): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }

    /** На shared-хостинге без прокси — REMOTE_ADDR. Заголовкам X-Forwarded-For не доверяем. */
    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Отдать JSON и продолжить работу, не заставляя браузер ждать, —
     * чтобы отправка в Telegram и почту не задерживала ответ.
     */
    public static function jsonAndContinue(array $data, int $status = 200): void
    {
        header('Cache-Control: no-store');
        if (self::$noindex) {
            header('X-Robots-Tag: noindex, nofollow');
        }
        self::sendAndContinue(json_encode($data, JSON_UNESCAPED_UNICODE), 'application/json', $status);
    }

    /** То же для HTML-страницы. */
    public static function htmlAndContinue(string $html, int $status = 200): void
    {
        self::securityHeaders();
        self::sendAndContinue($html, 'text/html', $status);
    }

    private static function sendAndContinue(string $body, string $type, int $status): void
    {
        ignore_user_abort(true);
        http_response_code($status);
        header("Content-Type: $type; charset=utf-8");
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        self::finishRequest();
    }

    /** Закрыть соединение с клиентом; дальше скрипт работает в фоне. */
    public static function finishRequest(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }
}
