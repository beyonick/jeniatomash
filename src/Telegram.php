<?php
declare(strict_types=1);

namespace Booking;

/**
 * Клиент Bot API. Адрес API — из конфига (можно подставить прокси).
 * Без токена запросы не отправляются, а пишутся в var/telegram.log.
 * Таймауты короткие: недоступный Telegram не должен подвешивать запросы.
 */
class Telegram
{
    public function __construct(private array $cfg, private string $logFile) {}

    public function enabled(): bool
    {
        return ($this->cfg['token'] ?? '') !== '';
    }

    public function adminChatId(): string
    {
        return (string)($this->cfg['chat_id'] ?? '');
    }

    /** @throws \RuntimeException если Telegram недоступен или вернул ошибку */
    public function call(string $method, array $params = [], int $timeout = 8): array
    {
        if (!$this->enabled()) {
            // Без токена — в лог. Номер строки служит id сообщения: по нему
            // локальный просмотр (/dev/) применяет правки сообщений, как в Telegram.
            $line = substr_count((string)@file_get_contents($this->logFile), "\n") + 1;
            file_put_contents(
                $this->logFile,
                Time::fmt(Time::now()) . " $method " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
            return ['message_id' => $line];
        }

        $url = rtrim($this->cfg['api_base'] ?? 'https://api.telegram.org', '/') . '/bot' . $this->cfg['token'] . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("Telegram недоступен: $err");
        }
        $res = json_decode((string)$raw, true);
        if (!is_array($res) || empty($res['ok'])) {
            // Токен в сообщение об ошибке не попадает.
            throw new \RuntimeException('Telegram: ' . ($res['description'] ?? 'неожиданный ответ'));
        }
        return is_array($res['result']) ? $res['result'] : ['value' => $res['result']];
    }

    /** То же, но без исключений — для необязательных вызовов (снять «часики» с кнопки и т. п.). */
    public function tryCall(string $method, array $params = []): ?array
    {
        try {
            return $this->call($method, $params, 5);
        } catch (\Throwable $e) {
            error_log("Telegram $method: " . $e->getMessage());
            return null;
        }
    }
}
