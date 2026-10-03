<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/** Сборка зависимостей. Один экземпляр на запрос. */
final class App
{
    public readonly Settings $settings;
    public readonly Products $products;
    public readonly Slots $slots;
    public readonly Tokens $tokens;
    public readonly Bookings $bookings;
    public readonly RateLimit $rateLimit;
    public readonly Mailer $mailer;
    public readonly Telegram $telegram;
    public readonly Outbox $outbox;
    public readonly Texts $texts;
    public readonly Notify $notify;
    private \Closure $clock;

    /**
     * @param Notifier|null $notifier подмена уведомлений (тесты); по умолчанию — Notify
     * @param \Closure|null $clock    подмена часов (тесты): fn(): DateTimeImmutable
     */
    public function __construct(
        public readonly array $config,
        public readonly Db $db,
        ?Notifier $notifier = null,
        ?\Closure $clock = null,
        ?Mailer $mailer = null,
        ?Telegram $telegram = null,
    ) {
        $var = dirname(__DIR__) . '/var';
        $this->clock     = $clock ?? static fn() => Time::now();
        $this->settings  = new Settings($db);
        $this->products  = new Products($db);
        $this->slots     = new Slots($db, $this->settings);
        $this->tokens    = new Tokens($db);
        $this->rateLimit = new RateLimit($db, (string)($config['secret'] ?? ''));
        $this->mailer    = $mailer ?? new Mailer($config['mail'] ?? [], (string)($config['env'] ?? 'local'), "$var/mail.log");
        $this->telegram  = $telegram ?? new Telegram($config['telegram'] ?? [], "$var/telegram.log");
        $this->outbox    = new Outbox($db, $this->mailer, $this->telegram);
        $this->texts     = new Texts($this->settings, (string)($config['base_url'] ?? ''));
        $this->notify    = new Notify($this->outbox, $this->texts, $this->telegram, (string)($config['mail']['admin_copy'] ?? ''), $this->clock);
        $this->bookings  = new Bookings($db, $this->settings, $this->slots, $this->products, $this->tokens, $notifier ?? $this->notify);
    }

    /** Из config.php в корне модуля. */
    public static function boot(): self
    {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Нет config.php: скопируйте config.sample.php и заполните.');
        }
        $config = require $file;
        if (($config['env'] ?? 'local') === 'prod') {
            // Ошибки — в лог, а не на страницу.
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', dirname(__DIR__) . '/var/php-error.log');
        }
        Http::$noindex = !empty($config['noindex']);
        $db = new Db($config['db']['dsn'], $config['db']['user'] ?? null, $config['db']['pass'] ?? null);
        return new self($config, $db);
    }

    public function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    public function isProd(): bool
    {
        return ($this->config['env'] ?? 'local') === 'prod';
    }

    public function domain(): string
    {
        return (string)(parse_url((string)($this->config['base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost');
    }
}
