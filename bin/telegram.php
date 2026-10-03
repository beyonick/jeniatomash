<?php
// Настройка бота:
//   php bin/telegram.php check        — доступен ли Telegram с этого сервера, кто бот
//   php bin/telegram.php set-webhook  — направить бота на {base_url}/tg-webhook.php
//   php bin/telegram.php info         — состояние webhook (ошибки доставки и т. п.)
//   php bin/telegram.php delete-webhook
//   php bin/telegram.php poll         — локальная проверка без webhook: читает обновления и обрабатывает их
//   php bin/telegram.php poll-once    — запасной вариант для хостинга, если webhook не доходит:
//                                       ставится в cron раз в минуту вместо webhook
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Bot;

$app = App::boot();
$tg = $app->telegram;
$cmd = $argv[1] ?? '';

if (!$tg->enabled()) {
    fwrite(STDERR, "В config.php не задан telegram.token\n");
    exit(1);
}

function out(mixed $v): void
{
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}

try {
    switch ($cmd) {
        case 'check':
            $t = microtime(true);
            $me = $tg->call('getMe');
            printf("Telegram доступен (%.1f с). Бот: @%s\n", microtime(true) - $t, $me['username'] ?? '?');
            echo $tg->adminChatId() === ''
                ? "chat_id не задан: напишите боту /start, он пришлёт chat_id (после set-webhook или poll).\n"
                : "chat_id задан.\n";
            break;

        case 'set-webhook':
            $secret = (string)($app->config['telegram']['webhook_secret'] ?? '');
            if (strlen($secret) < 16) {
                fwrite(STDERR, "Задайте telegram.webhook_secret (латиница и цифры, от 16 символов)\n");
                exit(1);
            }
            $url = $app->texts->url('/tg-webhook.php');
            $tg->call('setWebhook', [
                'url'             => $url,
                'secret_token'    => $secret,
                'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => true,
            ]);
            $tg->call('setMyCommands', ['commands' => [
                ['command' => 'days', 'description' => 'Расписание: закрыть или открыть слот или день'],
                ['command' => 'pending', 'description' => 'Заявки без ответа'],
                ['command' => 'today', 'description' => 'Занятия сегодня'],
                ['command' => 'tomorrow', 'description' => 'Занятия завтра'],
            ]]);
            echo "Webhook установлен: $url\n";
            break;

        case 'info':
            out($tg->call('getWebhookInfo'));
            break;

        case 'delete-webhook':
            $tg->call('deleteWebhook');
            echo "Webhook удалён.\n";
            break;

        case 'poll':
            $tg->call('deleteWebhook');
            echo "Читаю обновления (Ctrl+C — выход)…\n";
            $offset = 0;
            $bot = new Bot($app);
            while (true) {
                $updates = $tg->call('getUpdates', ['offset' => $offset, 'timeout' => 25], 35);
                foreach ($updates as $u) {
                    $offset = $u['update_id'] + 1;
                    echo date('H:i:s'), ' ', isset($u['callback_query']) ? 'кнопка ' . $u['callback_query']['data'] : 'сообщение', PHP_EOL;
                    $bot->handle($u, $app->now());
                    $app->outbox->flushNew($app->now());
                }
            }

        case 'poll-once':
            // Без webhook: забрать накопившиеся обновления и обработать. Позиция — в настройках.
            $offset = (int)$app->settings->get('tg_poll_offset', 0);
            $updates = $tg->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message', 'callback_query']]);
            $bot = new Bot($app);
            foreach ($updates as $u) {
                $app->settings->set('tg_poll_offset', $u['update_id'] + 1);
                $bot->handle($u, $app->now());
            }
            $app->outbox->flushNew($app->now());
            break;

        default:
            echo "Команды: check, set-webhook, info, delete-webhook, poll, poll-once\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
