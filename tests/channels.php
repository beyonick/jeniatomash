<?php
// Тесты уведомлений, бота, календаря, защиты формы и cron. Подключается из run.php.
declare(strict_types=1);

use Booking\App;
use Booking\Bot;
use Booking\Cron;
use Booking\Db;
use Booking\FormGuard;
use Booking\Ics;
use Booking\Mailer;
use Booking\Migrator;
use Booking\Status;
use Booking\Telegram;

/** Последний элемент результата функции (end() требует переменную). */
function lastOf(array $a): mixed
{
    return $a[array_key_last($a)] ?? null;
}

// --- Подмены ---

final class FakeTelegram extends Telegram
{
    public array $calls = [];
    public bool $down = false;

    public function __construct(string $chatId = '42')
    {
        parent::__construct(['token' => 'test', 'chat_id' => $chatId], 'php://memory');
    }

    public function call(string $method, array $params = [], int $timeout = 8): array
    {
        if ($this->down) {
            throw new RuntimeException('Telegram недоступен: timeout');
        }
        $this->calls[] = [$method, $params];
        return ['message_id' => 100 + count($this->calls)];
    }

    /** @return array[] параметры вызовов метода */
    public function calls(string $method): array
    {
        return array_values(array_map(fn($c) => $c[1], array_filter($this->calls, fn($c) => $c[0] === $method)));
    }
}

final class FakeMailer extends Mailer
{
    public array $sent = [];

    public function __construct()
    {
        parent::__construct([], 'local', 'php://memory');
    }

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = compact('to', 'subject', 'body');
    }
}

final class Env
{
    public DateTimeImmutable $now;
    public App $app;
    public FakeTelegram $tg;
    public FakeMailer $mail;

    public function __construct(string $now = NOW, string $chatId = '42')
    {
        $this->now = at($now);
        $db = new Db('sqlite::memory:');
        (new Migrator($db, __DIR__ . '/../migrations'))->migrate();
        $this->tg = new FakeTelegram($chatId);
        $this->mail = new FakeMailer();
        $config = ['secret' => 'test', 'base_url' => 'https://jenyatomash.nicktmsh.ru', 'mail' => ['admin_copy' => 'zhenya@example.com']];
        $this->app = new App($config, $db, null, fn() => $this->now, $this->mail, $this->tg);
    }

    public function book(string $startsAt, array $over = []): array
    {
        $b = $this->app->bookings->create(form($this->app, $startsAt, $over), $this->now, '203.0.113.5');
        $this->app->outbox->flushNew($this->now);
        return $b;
    }

    /** Нажатие кнопки в боте. */
    public function press(string $data, string $fromChat = '42'): void
    {
        $this->app->telegram instanceof FakeTelegram || throw new LogicException();
        (new Bot($this->app))->handle([
            'callback_query' => [
                'id'      => 'cb1',
                'from'    => ['id' => (int)$fromChat],
                'data'    => $data,
                'message' => ['message_id' => 7, 'chat' => ['id' => (int)$fromChat]],
            ],
        ], $this->now);
        $this->app->outbox->flushNew($this->now);
    }

    public function mailTo(string $to): array
    {
        return array_values(array_filter($this->mail->sent, fn($m) => $m['to'] === $to));
    }
}

// --- Уведомления ---

function test_new_booking_notifies_everyone_with_minimal_data_in_bot(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00', ['phone' => '+7 916 123-45-67']);

    $client = $env->mailTo('anna@example.com');
    eq(1, count($client));
    eq('Заявка на занятие получена', $client[0]['subject']);
    ok(str_contains($client[0]['body'], 'среда, 7 октября, 11:00–12:30'));

    $admin = $env->mailTo('zhenya@example.com');
    eq(1, count($admin), 'дубль Жене');

    $sent = $env->tg->calls('sendMessage');
    eq(1, count($sent));
    eq('42', $sent[0]['chat_id']);
    ok(str_contains($sent[0]['text'], 'Новая заявка'));
    ok(str_contains($sent[0]['text'], '@anna_qi'));
    eq('c:' . $b['id'], $sent[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);

    // Телефон и почта ученика — только в админке.
    foreach ([$sent[0]['text'], $admin[0]['body'], $admin[0]['subject']] as $text) {
        ok(!str_contains($text, 'anna@example.com'), 'почты нет в уведомлении Жене');
        ok(!str_contains($text, '916'), 'телефона нет в уведомлении Жене');
    }
    eq(101, (int)$env->app->bookings->get((int)$b['id'])['tg_message_id'], 'id сообщения сохранён');
}

function test_telegram_down_does_not_lose_anything_and_cron_retries(): void
{
    $env = new Env();
    $env->tg->down = true;
    $b = $env->book('2026-10-07 11:00:00');

    eq(Status::PENDING, $env->app->bookings->get((int)$b['id'])['status'], 'заявка сохранена');
    eq(2, count($env->mail->sent), 'письма ушли');
    $row = $env->app->db->one("SELECT * FROM outbox WHERE channel = 'telegram'");
    eq('pending', $row['status']);
    eq(1, (int)$row['attempts']);
    ok(str_contains($row['last_error'], 'недоступен'));

    // Через минуту Telegram ожил — cron дошлёт.
    $env->tg->down = false;
    $env->now = $env->now->modify('+2 minutes');
    eq(['sent' => 1, 'failed' => 0], $env->app->outbox->flushDue($env->now));
    eq(1, count($env->tg->calls('sendMessage')));
    eq('sent', $env->app->db->value("SELECT status FROM outbox WHERE channel = 'telegram'"));
}

function test_outbox_gives_up_after_many_attempts(): void
{
    $env = new Env();
    $env->tg->down = true;
    $env->book('2026-10-07 11:00:00');
    for ($i = 0; $i < 20; $i++) {
        $env->now = $env->now->modify('+9 hours');
        $env->app->outbox->flushDue($env->now);
    }
    $row = $env->app->db->one("SELECT * FROM outbox WHERE channel = 'telegram'");
    eq('failed', $row['status']);
    eq(9, (int)$row['attempts']);
    eq(1, count($env->app->outbox->problems()));
}

function test_outbox_message_is_sent_once(): void
{
    $env = new Env();
    $id = $env->app->outbox->email('x@example.com', 'Тема', 'Текст', $env->now);
    ok($env->app->outbox->deliver($id, $env->now));
    ok(!$env->app->outbox->deliver($id, $env->now), 'второй раз не отправляется');
    eq(1, count($env->mail->sent));
}

function test_proposal_email_has_both_links(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $env->app->bookings->propose((int)$b['id'], '2026-10-08 16:45:00', $env->now);
    $env->app->outbox->flushNew($env->now);
    $mail = $env->mailTo('anna@example.com');
    $last = end($mail);
    eq('Женя предлагает другое время', $last['subject']);
    eq(2, preg_match_all('~https://jenyatomash\.nicktmsh\.ru/zapis/link\.php\?t=[a-f0-9]{48}~', $last['body']));
    ok(str_contains($last['body'], 'среда, 7 октября, 11:00–12:30'), 'упомянуто исходное время');
    ok(str_contains($last['body'], 'четверг, 8 октября, 16:45–18:15'));
}

// --- Бот ---

function test_bot_confirm_button(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $env->tg->calls = [];
    $env->mail->sent = [];

    $env->press('c:' . $b['id']);
    eq(Status::CONFIRMED, $env->app->bookings->get((int)$b['id'])['status']);

    $edit = $env->tg->calls('editMessageText');
    eq(1, count($edit));
    ok(str_contains($edit[0]['text'], 'Заявка подтверждена'));
    eq([['text' => 'Отменить занятие', 'callback_data' => 'x:' . $b['id']]], $edit[0]['reply_markup']['inline_keyboard'][0]);
    eq(0, count($env->tg->calls('sendMessage')), 'своё действие Жене новым сообщением не дублируется');
    eq(1, count($env->mailTo('anna@example.com')), 'ученику письмо');
    eq(1, count($env->mailTo('zhenya@example.com')), 'дубль на почту');
    eq(1, count($env->tg->calls('answerCallbackQuery')));
}

function test_bot_ignores_other_chats(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $env->tg->calls = [];
    $env->press('c:' . $b['id'], '999');
    eq(Status::PENDING, $env->app->bookings->get((int)$b['id'])['status']);
    eq([], $env->tg->calls);
}

function test_bot_decline_needs_second_tap(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $env->press('d:' . $b['id']);
    eq(Status::PENDING, $env->app->bookings->get((int)$b['id'])['status'], 'первое нажатие только спрашивает');
    $edit = $env->tg->calls('editMessageText');
    eq('D:' . $b['id'], end($edit)['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    $env->press('D:' . $b['id']);
    eq(Status::DECLINED, $env->app->bookings->get((int)$b['id'])['status']);
}

function test_bot_propose_other_time(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $id = $b['id'];

    $env->press("p:$id");
    $days = lastOf($env->tg->calls('editMessageText'))['reply_markup']['inline_keyboard'];
    eq("pd:$id:20261006", $days[0][0]['callback_data'], 'первый свободный день');

    $env->press("pd:$id:20261008");
    $times = lastOf($env->tg->calls('editMessageText'))['reply_markup']['inline_keyboard'];
    eq("pt:$id:202610080900", $times[0][0]['callback_data']);

    $env->press("pt:$id:202610081645");
    $after = $env->app->bookings->get((int)$id);
    eq(Status::PROPOSED, $after['status']);
    eq('2026-10-08 16:45:00', $after['starts_at']);
    $mail = $env->mailTo('anna@example.com');
    eq('Женя предлагает другое время', end($mail)['subject']);
}

function test_bot_stale_button_shows_alert_and_refreshes_card(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00');
    $env->app->bookings->decline((int)$b['id'], $env->now); // например, из админки
    $env->tg->calls = [];

    $env->press('c:' . $b['id']);
    $answer = $env->tg->calls('answerCallbackQuery')[0];
    ok($answer['show_alert']);
    ok(str_contains($answer['text'], 'другом статусе'));
    $edit = $env->tg->calls('editMessageText')[0];
    ok(str_contains($edit['text'], 'Отклонена'));
    eq([], $edit['reply_markup']['inline_keyboard'], 'кнопки убраны');
}

function test_bot_schedule_close_and_open(): void
{
    $env = new Env();
    $env->press('v:20261009');
    $kb = lastOf($env->tg->calls('editMessageText'))['reply_markup']['inline_keyboard'];
    eq('t:20261009:0900', $kb[0][0]['callback_data']);

    $env->press('t:20261009:0900');
    eq('closed', $env->app->slots->unavailableReason('2026-10-09 09:00:00', $env->now));
    $env->press('t:20261009:0900');
    eq(null, $env->app->slots->unavailableReason('2026-10-09 09:00:00', $env->now));

    $env->press('cd:20261009');
    ok($env->app->slots->isDayClosed('2026-10-09'));
    ok(str_contains(lastOf($env->tg->calls('editMessageText'))['text'], 'день закрыт'));
    $env->press('od:20261009');
    ok(!$env->app->slots->isDayClosed('2026-10-09'));
}

function test_bot_escapes_html(): void
{
    $env = new Env();
    $env->book('2026-10-07 11:00:00', ['name' => '<b>Хакер</b> & Co']);
    $text = $env->tg->calls('sendMessage')[0]['text'];
    ok(str_contains($text, '&lt;b&gt;Хакер&lt;/b&gt; &amp; Co'));
    ok(!str_contains($text, '<b>Хакер'));
}

function test_bot_setup_reveals_chat_id_only_when_not_configured(): void
{
    $env = new Env(NOW, '');
    (new Bot($env->app))->handle(['message' => ['chat' => ['id' => 555], 'text' => '/start']], $env->now);
    ok(str_contains($env->tg->calls('sendMessage')[0]['text'], '555'));

    $env2 = new Env();
    (new Bot($env2->app))->handle(['message' => ['chat' => ['id' => 555], 'text' => '/start']], $env2->now);
    eq([], $env2->tg->calls, 'чужому чату не отвечаем');
}

function test_bot_commands(): void
{
    $env = new Env();
    $b = $env->book('2026-10-06 11:00:00');
    $env->tg->calls = [];
    (new Bot($env->app))->handle(['message' => ['chat' => ['id' => 42], 'text' => '/tomorrow']], $env->now);
    $text = $env->tg->calls('sendMessage')[0]['text'];
    ok(str_contains($text, 'Завтра, вт, 6 окт'));
    ok(str_contains($text, '11:00–12:30 · Анна'));
    ok(str_contains($text, 'Свободно: 09:00, 15:00, 16:45, 18:30'));

    (new Bot($env->app))->handle(['message' => ['chat' => ['id' => 42], 'text' => '/pending']], $env->now);
    $cards = $env->tg->calls('sendMessage');
    eq('c:' . $b['id'], end($cards)['reply_markup']['inline_keyboard'][0][0]['callback_data']);
}

// --- Cron ---

function test_cron_evening_summary_once_a_day(): void
{
    $env = new Env('2026-10-05 19:55:00');
    $env->book('2026-10-06 11:00:00');
    $env->tg->calls = [];
    $cron = new Cron($env->app);

    ok(!$cron->run($env->now)['summary'], 'до 20:00 рано');
    $env->now = at('2026-10-05 20:00:00');
    ok($cron->run($env->now)['summary']);
    $env->now = at('2026-10-05 20:05:00');
    ok(!$cron->run($env->now)['summary'], 'второй раз за день не шлём');

    $summaries = array_filter($env->tg->calls('sendMessage'), fn($m) => str_contains($m['text'], 'Завтра'));
    eq(1, count($summaries));
    $mails = array_filter($env->mailTo('zhenya@example.com'), fn($m) => str_contains($m['subject'], 'Сводка'));
    eq(1, count($mails), 'сводка дублируется на почту');
    ok(!str_contains(reset($mails)['body'], '<b>'), 'в письме без HTML');
}

function test_cron_reminds_expires_and_asks_outcome(): void
{
    $env = new Env();
    $pending = $env->book('2026-10-07 11:00:00');
    $confirmed = $env->book('2026-10-06 11:00:00', ['email' => 'boris@example.com']);
    $env->app->bookings->confirm((int)$confirmed['id'], $env->now);
    $cron = new Cron($env->app);

    $env->now = at('2026-10-05 23:00:00');
    eq(1, $cron->run($env->now)['reminded']);
    eq(0, $cron->run($env->now)['reminded'], 'напоминание одно');

    $env->now = at('2026-10-06 12:30:00'); // занятие Бориса закончилось
    $done = $cron->run($env->now);
    eq(1, $done['outcome_asked']);
    $ask = lastOf($env->tg->calls('sendMessage'));
    ok(str_contains($ask['text'], 'Как прошло занятие?'));
    eq('ok:' . $confirmed['id'], $ask['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    eq(0, $cron->run($env->now)['outcome_asked']);

    $env->now = at('2026-10-07 08:30:00'); // до заявки Анны 2,5 часа
    eq(1, $cron->run($env->now)['expired']);
    eq(Status::EXPIRED, $env->app->bookings->get((int)$pending['id'])['status']);
    $mail = $env->mailTo('anna@example.com');
    eq('Бронь снята', end($mail)['subject']);
}

function test_old_clients_are_deleted_after_retention_period(): void
{
    $env = new Env();
    $old = $env->book('2026-10-07 11:00:00');
    $fresh = $env->book('2026-10-08 11:00:00', ['email' => 'boris@example.com']);
    $cron = new Cron($env->app);

    eq(0, $cron->purgeOldClients(at('2029-10-07 10:00:00')), 'три года ещё не прошли');
    eq(1, $cron->purgeOldClients(at('2029-10-07 12:00:00')));
    eq(null, $env->app->bookings->get((int)$old['id']));
    eq(0, (int)$env->app->db->value("SELECT COUNT(*) FROM clients WHERE email = 'anna@example.com'"));
    eq(0, (int)$env->app->db->value('SELECT COUNT(*) FROM booking_events WHERE booking_id = ?', [$old['id']]));
    ok($env->app->bookings->get((int)$fresh['id']) !== null, 'у Бориса последнее занятие позже');

    // Новая запись продлевает срок.
    $env->now = at('2026-10-05 20:00:00');
    $again = $env->book('2026-10-09 11:00:00', ['email' => 'boris@example.com']);
    eq(0, $cron->purgeOldClients(at('2029-10-08 12:00:00')));
    ok($env->app->bookings->get((int)$again['id']) !== null);
}

// --- Календарь ---

function test_ics_feed(): void
{
    $env = new Env();
    $b = $env->book('2026-10-07 11:00:00', ['phone' => '+79161234567', 'name' => 'Анна Очень-Длинная-Фамилия Для Проверки Переноса Строк']);
    $gone = $env->book('2026-10-08 11:00:00', ['email' => 'boris@example.com']);
    $env->app->bookings->decline((int)$gone['id'], $env->now);

    $ics = (new Ics($env->app->bookings, 'jenyatomash.nicktmsh.ru'))->render($env->now);
    ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\n"));
    eq(1, substr_count($ics, 'BEGIN:VEVENT'), 'отклонённая не попадает');
    ok(str_contains($ics, 'DTSTART:20261007T080000Z'), 'UTC = Москва − 3 ч');
    ok(str_contains($ics, 'UID:' . $b['public_id'] . '@jenyatomash.nicktmsh.ru'));
    ok(str_contains($ics, 'STATUS:TENTATIVE'), 'неподтверждённая — под вопросом');
    ok(!str_contains($ics, 'anna@example.com') && !str_contains($ics, '916'), 'без почты и телефона');
    foreach (explode("\r\n", $ics) as $line) {
        ok(strlen($line) <= 75, 'строки не длиннее 75 байт');
        ok(mb_check_encoding($line, 'UTF-8'), 'перенос не рвёт UTF-8');
    }
}

// --- Защита формы ---

function test_form_guard(): void
{
    $g = new FormGuard('secret');
    $t = 1_800_000_000;
    $stamp = $g->stamp($t);
    eq(null, $g->check(['stamp' => $stamp], $t + 10));
    eq('too_fast', $g->check(['stamp' => $stamp], $t + 1));
    eq('stale', $g->check(['stamp' => $stamp], $t + 90000));
    eq('honeypot', $g->check(['stamp' => $stamp, 'website' => 'http://spam'], $t + 10));
    eq('bad_stamp', $g->check(['stamp' => ($t - 100) . '.' . explode('.', $stamp)[1]], $t + 10));
    eq('no_stamp', $g->check([], $t + 10));
    eq('bad_stamp', $g->check(['stamp' => (new FormGuard('другой'))->stamp($t)], $t + 10));
}
