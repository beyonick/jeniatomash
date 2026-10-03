<?php
// Песочница для локальной разработки: письма из var/mail.log и чат Жени с ботом
// из var/telegram.log, с работающими кнопками. Только env=local и только с этого компьютера.
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\Bot;
use Booking\Cron;
use Booking\Http;
use Booking\View;

$app = App::boot();
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if ($app->isProd() || !$local || $app->telegram->enabled()) {
    http_response_code(404);
    exit;
}

$var = dirname(__DIR__, 2) . '/var';
$tgLog = "$var/telegram.log";
$mailLog = "$var/mail.log";
$chat = $app->telegram->adminChatId() ?: 'local';

if (Http::isPost()) {
    $now = $app->now();
    $bot = new Bot($app);
    switch ($_POST['action'] ?? '') {
        case 'press':
            $bot->handle(['callback_query' => [
                'id'      => 'dev',
                'from'    => ['id' => $chat],
                'data'    => (string)($_POST['data'] ?? ''),
                'message' => ['message_id' => (int)($_POST['message_id'] ?? 0), 'chat' => ['id' => $chat]],
            ]], $now);
            break;
        case 'say':
            $text = trim((string)($_POST['text'] ?? ''));
            if ($text !== '') {
                file_put_contents($tgLog, $app->now()->format('Y-m-d H:i:s') . ' userMessage ' . json_encode(['text' => $text], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                $bot->handle(['message' => ['chat' => ['id' => $chat], 'text' => $text]], $now);
            }
            break;
        case 'cron':
            (new Cron($app))->run($now);
            break;
        case 'clear':
            @unlink($tgLog);
            @unlink($mailLog);
            break;
    }
    $app->outbox->flushNew($now);
    Http::redirect('/dev/#chat-end');
}

// --- Чат: восстановить сообщения с учётом правок ---
$messages = [];
$toast = null;
$lines = is_file($tgLog) ? file($tgLog, FILE_IGNORE_NEW_LINES) : [];
foreach ($lines as $i => $line) {
    if (!preg_match('/^(\S+ \S+) (\w+) (.*)$/', $line, $m)) {
        continue;
    }
    [, $time, $method, $json] = $m;
    $p = json_decode($json, true) ?: [];
    switch ($method) {
        case 'sendMessage':
            $messages[$i + 1] = ['id' => $i + 1, 'time' => $time, 'text' => $p['text'] ?? '', 'markup' => $p['reply_markup']['inline_keyboard'] ?? [], 'from' => 'bot', 'edited' => false];
            $toast = null;
            break;
        case 'userMessage':
            $messages[$i + 1] = ['id' => $i + 1, 'time' => $time, 'text' => htmlspecialchars($p['text'] ?? ''), 'markup' => [], 'from' => 'me', 'edited' => false];
            $toast = null;
            break;
        case 'editMessageText':
            $id = (int)($p['message_id'] ?? 0);
            if (isset($messages[$id])) {
                $messages[$id]['text'] = $p['text'] ?? '';
                $messages[$id]['markup'] = $p['reply_markup']['inline_keyboard'] ?? [];
                $messages[$id]['edited'] = true;
            }
            $toast = null;
            break;
        case 'answerCallbackQuery':
            $toast = $p['text'] ?? null;
            break;
    }
}

// --- Почта: записи «===== время» ---
$mails = [];
$raw = is_file($mailLog) ? (string)file_get_contents($mailLog) : '';
foreach (preg_split('/^===== /m', $raw, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
    [$head, $body] = array_pad(explode("\n\n", $chunk, 2), 2, '');
    $h = ['time' => strtok($head, "\n")];
    foreach (explode("\n", $head) as $hl) {
        if (preg_match('/^(To|Subject): (.*)$/', $hl, $m)) {
            $h[strtolower($m[1])] = $m[2];
        }
    }
    $mails[] = $h + ['body' => trim($body)];
}
$mails = array_reverse($mails);

Http::html(View::page('dev', [
    'title'    => 'Песочница',
    'messages' => array_values($messages),
    'toast'    => $toast,
    'mails'    => $mails,
], 'admin/layout'));
