<?php
// Админка: вход по паролю, неделя, заявки, карточка заявки, календарь и бот.
// Все действия — POST с CSRF-токеном, после действия — редирект (PRG).
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Booking\App;
use Booking\Auth;
use Booking\Http;
use Booking\Pages;
use Booking\SlotUnavailable;
use Booking\StateError;
use Booking\Status;
use Booking\Time;
use Booking\View;

try {
    $app = App::boot();
    $now = $app->now();
    $auth = new Auth((string)($app->config['admin']['password_hash'] ?? ''), $app->isProd());
    $auth->start();
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $page = (string)($_GET['p'] ?? 'week');

    // --- Вход ---
    if (!$auth->check()) {
        $error = null;
        if (Http::isPost() && ($_POST['action'] ?? '') === 'login') {
            if (!$app->rateLimit->attempt(Http::ip(), 'admin_login', 10, 900, $now)) {
                $error = 'Слишком много попыток. Подождите 15 минут.';
            } elseif ($auth->login((string)($_POST['password'] ?? ''))) {
                Http::redirect('/admin/');
            } else {
                $error = ($app->config['admin']['password_hash'] ?? '') === ''
                    ? 'Пароль админки не задан в config.php.'
                    : 'Неверный пароль.';
            }
        }
        Http::html(View::page('admin/login', ['title' => 'Вход', 'error' => $error], 'admin/layout'), $error ? 401 : 200);
    }

    // --- Действия ---
    if (Http::isPost()) {
        if (!$auth->checkCsrf($_POST['csrf'] ?? null)) {
            Http::html('Форма устарела. Вернитесь назад и обновите страницу.', 400);
        }
        $back = (string)($_POST['back'] ?? '/admin/');
        if (!str_starts_with($back, '/admin/')) {
            $back = '/admin/';
        }
        $id = (int)($_POST['id'] ?? 0);
        $bk = $app->bookings;
        $sl = $app->slots;
        $day = (string)($_POST['day'] ?? '');
        $time = (string)($_POST['time'] ?? '');

        if (($_POST['action'] ?? '') === 'logout') {
            $auth->logout();
            Http::redirect('/admin/');
        }
        try {
            switch ((string)($_POST['action'] ?? '')) {
                case 'confirm':
                    $bk->confirm($id, $now);
                    $flash = 'Заявка подтверждена, ученику ушло письмо.';
                    break;
                case 'decline':
                    $bk->decline($id, $now);
                    $flash = 'Заявка отклонена, ученику ушло письмо.';
                    break;
                case 'cancel':
                    $bk->cancel($id, $now, 'admin', (string)($_POST['reason'] ?? ''));
                    $flash = 'Занятие отменено, ученику ушло письмо.';
                    break;
                case 'propose':
                    $bk->propose($id, (string)($_POST['starts_at'] ?? ''), $now);
                    $flash = 'Предложено другое время, ученику ушло письмо со ссылками.';
                    break;
                case 'completed':
                    $bk->markCompleted($id, $now);
                    $flash = 'Отмечено: проведено.';
                    break;
                case 'no_show':
                    $bk->markNoShow($id, $now);
                    $flash = 'Отмечено: не пришёл.';
                    break;
                case 'note':
                    $app->db->update('bookings', ['admin_note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000)], 'id = ?', [$id]);
                    $flash = 'Заметка сохранена.';
                    break;
                case 'close_slot':
                    $sl->close($day, $time);
                    $flash = 'Слот закрыт.';
                    break;
                case 'open_slot':
                    $sl->open($day, $time);
                    $flash = 'Слот открыт.';
                    break;
                case 'close_day':
                    $sl->close($day);
                    $flash = 'День закрыт. Существующие записи остаются в силе.';
                    break;
                case 'open_day':
                    $sl->open($day);
                    $flash = 'День открыт.';
                    break;
                default:
                    throw new StateError('Неизвестное действие.');
            }
            $_SESSION['flash'] = ['ok', $flash];
        } catch (SlotUnavailable|StateError|\InvalidArgumentException $e) {
            $_SESSION['flash'] = ['error', $e->getMessage()];
        }

        // Редирект сразу, письма и сообщения — после.
        session_write_close();
        header('Location: ' . $back, true, 303);
        header('Content-Length: 0');
        header('Connection: close');
        Http::finishRequest();
        try {
            $app->outbox->flushNew($now);
        } catch (\Throwable $e) {
            error_log((string)$e);
        }
        exit;
    }

    // --- Страницы ---
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $common = ['flash' => $flash, 'csrf' => $auth->csrf(), 'page' => $page, 'now' => $now, 'app' => $app];

    switch ($page) {
        case 'week':
            $start = (string)($_GET['start'] ?? '');
            // Семь дней начиная с сегодняшнего (или с выбранного): ближайшие занятия всегда на экране.
            $weekStart = Time::isDay($start) ? Time::parse("$start 00:00:00") : $now->setTime(0, 0);
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $weekStart->modify("+$i day")->format('Y-m-d');
                $slots = $app->slots->dayStatus($d, $now);
                foreach ($slots as &$s) {
                    $s['booking'] = $s['booking_id'] ? $app->bookings->get($s['booking_id']) : null;
                }
                unset($s);
                $days[$d] = ['slots' => $slots, 'closed' => $app->slots->isDayClosed($d)];
            }
            Http::html(View::page('admin/week', $common + [
                'title'  => 'Неделя',
                'weekStart' => $weekStart,
                'days'   => $days,
            ], 'admin/layout'));

        case 'list':
            $filter = (string)($_GET['f'] ?? 'upcoming');
            $today = Time::fmt($now->setTime(0, 0));
            [$from, $to, $statuses] = match ($filter) {
                'pending' => [$today, '9999-12-31 00:00:00', [Status::PENDING, Status::PROPOSED]],
                'past'    => [Time::fmt($now->modify('-90 days')), Time::fmt($now), null],
                'all'     => ['0000-01-01 00:00:00', '9999-12-31 00:00:00', null],
                default   => [$today, '9999-12-31 00:00:00', [Status::PENDING, Status::PROPOSED, Status::CONFIRMED]],
            };
            $list = $app->bookings->between($from, $to, $statuses);
            if ($filter === 'past') {
                $list = array_reverse($list);
            }
            Http::html(View::page('admin/list', $common + [
                'title'  => 'Заявки',
                'filter' => $filter,
                'list'   => $list,
            ], 'admin/layout'));

        case 'booking':
            $b = $app->bookings->get((int)($_GET['id'] ?? 0));
            if ($b === null) {
                Http::html(View::page('admin/missing', $common + ['title' => 'Не найдено'], 'admin/layout'), 404);
            }
            $free = [];
            if (in_array($b['status'], [Status::PENDING, Status::PROPOSED], true)) {
                foreach ($app->slots->freeByDay($now)['days'] as $d => $slots) {
                    if ($slots) {
                        $free[$d] = $slots;
                    }
                }
            }
            Http::html(View::page('admin/booking', $common + [
                'title'  => $b['client_name'],
                'b'      => $b,
                'free'   => $free,
                'events' => $app->db->all('SELECT * FROM booking_events WHERE booking_id = ? ORDER BY id', [$b['id']]),
            ], 'admin/layout'));

        case 'info':
            $ics = $app->texts->url('/calendar.php?key=' . rawurlencode((string)($app->config['ics_key'] ?? '')));
            Http::html(View::page('admin/info', $common + [
                'title'    => 'Календарь и бот',
                'icsUrl'   => strlen((string)($app->config['ics_key'] ?? '')) >= 16 ? $ics : null,
                'problems' => $app->outbox->problems(),
                'tgOn'     => $app->telegram->enabled() && $app->telegram->adminChatId() !== '',
                'cronLast' => is_file($f = dirname(__DIR__, 2) . '/var/cron.last') ? filemtime($f) : null,
            ], 'admin/layout'));
    }
    Http::redirect('/admin/');
} catch (\Throwable $e) {
    Pages::fail($e);
}
