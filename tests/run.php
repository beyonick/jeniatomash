<?php
// Тесты ядра: php tests/run.php
// Каждый тест — функция test_*, получает свежую SQLite-базу в памяти.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Db;
use Booking\Migrator;
use Booking\RecordingNotifier;
use Booking\SlotUnavailable;
use Booking\StateError;
use Booking\Status;
use Booking\Time;
use Booking\ValidationError;
use Booking\Validator;

// --- Мини-раннер ---

final class AssertionFailed extends Exception {}

function ok(bool $cond, string $msg = 'ожидалось true'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

/** Проверить, что $fn бросает $class; вернуть исключение. */
function throws(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("ожидалось $class, брошено " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("ожидалось $class, ничего не брошено");
}

/** @return array{App, RecordingNotifier} */
function fresh(): array
{
    $db = new Db('sqlite::memory:');
    (new Migrator($db, __DIR__ . '/../migrations'))->migrate();
    $notifier = new RecordingNotifier();
    return [new App(['secret' => 'test'], $db, $notifier), $notifier];
}

function at(string $s): DateTimeImmutable
{
    return Time::parse($s);
}

function form(App $app, string $startsAt, array $over = []): array
{
    return $over + [
        'name'       => 'Анна',
        'email'      => 'anna@example.com',
        'telegram'   => '@anna_qi',
        'phone'      => '',
        'consent'    => '1',
        'product_id' => (int)$app->products->byCode('single')['id'],
        'starts_at'  => $startsAt,
    ];
}

// Понедельник, 5 октября 2026, 20:00 по Москве.
const NOW = '2026-10-05 20:00:00';

// --- Сетка ---

function test_grid_has_five_slots_every_day(): void
{
    [$app] = fresh();
    foreach (['2026-10-05', '2026-10-10', '2026-10-11'] as $day) { // пн, сб, вс
        $times = array_column($app->slots->daySlots($day), 'time');
        eq(['09:00', '11:00', '15:00', '16:45', '18:30'], $times, $day);
    }
    $slot = $app->slots->findSlot('2026-10-07 16:45:00');
    eq('2026-10-07 18:15:00', $slot['ends_at']);
}

function test_time_outside_grid_is_rejected(): void
{
    [$app] = fresh();
    eq('not_in_grid', $app->slots->unavailableReason('2026-10-07 10:00:00', at(NOW)));
    eq('not_in_grid', $app->slots->unavailableReason('мусор', at(NOW)));
    $e = throws(SlotUnavailable::class, fn() => $app->bookings->create(form($app, '2026-10-07 13:00:00'), at(NOW)));
    eq('not_in_grid', $e->reason);
}

function test_grid_comes_from_settings(): void
{
    [$app] = fresh();
    $app->settings->set('slot_grid', [['10:00', '11:30']]);
    $app->settings->set('weekdays', [1, 2, 3, 4, 5]);
    eq(['10:00'], array_column($app->slots->daySlots('2026-10-07'), 'time'));
    eq([], $app->slots->daySlots('2026-10-10'), 'суббота выключена');
}

// --- Правило 12 часов и горизонт ---

function test_twelve_hour_rule(): void
{
    [$app] = fresh();
    // Завтра 9:00 — через 13 часов: можно.
    eq(null, $app->slots->unavailableReason('2026-10-06 09:00:00', at('2026-10-05 20:00:00')));
    // Ровно 12 часов — можно.
    eq(null, $app->slots->unavailableReason('2026-10-06 09:00:00', at('2026-10-05 21:00:00')));
    // 11 ч 59 мин — нельзя.
    eq('too_late', $app->slots->unavailableReason('2026-10-06 09:00:00', at('2026-10-05 21:01:00')));
    // Прошедший слот — нельзя.
    eq('too_late', $app->slots->unavailableReason('2026-10-05 09:00:00', at(NOW)));

    $free = array_column($app->slots->freeSlots('2026-10-06', at('2026-10-05 22:00:00')), 'time');
    eq(['11:00', '15:00', '16:45', '18:30'], $free, '9:00 уже закрыт правилом');

    $e = throws(SlotUnavailable::class, fn() => $app->bookings->create(form($app, '2026-10-06 09:00:00'), at('2026-10-05 22:00:00')));
    eq('too_late', $e->reason);
    ok(str_contains($e->getMessage(), '12 ч'), 'в сообщении часы из настроек');
}

function test_horizon(): void
{
    [$app] = fresh();
    eq(null, $app->slots->unavailableReason('2026-11-04 18:30:00', at(NOW)), 'последний день горизонта');
    eq('too_far', $app->slots->unavailableReason('2026-11-05 09:00:00', at(NOW)));
    $days = $app->slots->availableDays(at(NOW));
    eq('2026-10-06', $days[0], 'сегодня слотов уже нет');
    eq('2026-11-04', end($days));
    eq(30, count($days));
}

// --- Двойная запись ---

function test_double_booking_is_rejected(): void
{
    [$app] = fresh();
    $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $e = throws(SlotUnavailable::class, fn() => $app->bookings->create(
        form($app, '2026-10-07 11:00:00', ['email' => 'boris@example.com']), at(NOW)
    ));
    eq('taken', $e->reason);
    eq(1, (int)$app->db->value('SELECT COUNT(*) FROM bookings'));
}

function test_double_booking_blocked_by_database_itself(): void
{
    // Мимо кода: даже прямая вставка второй активной заявки на тот же слот падает.
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $row = $app->db->one('SELECT * FROM bookings WHERE id = ?', [$b['id']]);
    unset($row['id']);
    $row['public_id'] = 'other';
    $e = throws(PDOException::class, fn() => $app->db->insert('bookings', $row));
    ok(Db::isUniqueViolation($e), 'нарушение уникального индекса');

    // А неактивных (slot_lock = NULL) на это время может быть сколько угодно.
    $row['slot_lock'] = null;
    $row['status'] = Status::CANCELLED;
    $app->db->insert('bookings', $row);
    $row['public_id'] = 'third';
    $app->db->insert('bookings', $row);
    eq(3, (int)$app->db->value('SELECT COUNT(*) FROM bookings'));
}

function test_race_between_check_and_insert_is_caught(): void
{
    // Гонка: проверка в коде прошла, но между ней и нашей вставкой слот занял
    // другой запрос. Симулируем триггером, который вставляет «чужую» заявку
    // на тот же слот прямо перед нашей. Спасает уникальный индекс.
    [$app] = fresh();
    $app->db->run("CREATE TRIGGER rival BEFORE INSERT ON bookings WHEN NEW.public_id <> 'rival' BEGIN
        INSERT INTO bookings (public_id, client_id, product_id, starts_at, ends_at, status, slot_lock, created_at, updated_at)
        VALUES ('rival', NEW.client_id, NEW.product_id, NEW.starts_at, NEW.ends_at, 'pending', NEW.slot_lock, NEW.created_at, NEW.updated_at);
    END");
    eq(null, $app->slots->unavailableReason('2026-10-07 11:00:00', at(NOW)), 'по проверке слот свободен');
    $e = throws(SlotUnavailable::class, fn() => $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW)));
    eq('taken', $e->reason);
    eq(0, (int)$app->db->value("SELECT COUNT(*) FROM bookings WHERE public_id <> 'rival'"), 'наша заявка не записалась');
}

function test_slot_frees_after_decline_and_can_be_rebooked(): void
{
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    eq(Status::PENDING, $b['status']);
    eq('2026-10-07 11:00:00', $b['slot_lock']);
    ok(!in_array('11:00', array_column($app->slots->freeSlots('2026-10-07', at(NOW)), 'time')), 'слот занят');

    $b = $app->bookings->decline((int)$b['id'], at(NOW));
    eq(Status::DECLINED, $b['status']);
    eq(null, $b['slot_lock']);
    ok(in_array('11:00', array_column($app->slots->freeSlots('2026-10-07', at(NOW)), 'time')), 'слот снова свободен');

    $b2 = $app->bookings->create(form($app, '2026-10-07 11:00:00', ['email' => 'boris@example.com']), at(NOW));
    eq(Status::PENDING, $b2['status']);
}

// --- Закрытые слоты и дни ---

function test_closed_slot(): void
{
    [$app] = fresh();
    $app->slots->close('2026-10-07', '15:00');
    eq('closed', $app->slots->unavailableReason('2026-10-07 15:00:00', at(NOW)));
    eq(['09:00', '11:00', '16:45', '18:30'], array_column($app->slots->freeSlots('2026-10-07', at(NOW)), 'time'));
    eq('closed', throws(SlotUnavailable::class, fn() => $app->bookings->create(form($app, '2026-10-07 15:00:00'), at(NOW)))->reason);

    $app->slots->open('2026-10-07', '15:00');
    eq(null, $app->slots->unavailableReason('2026-10-07 15:00:00', at(NOW)));
}

function test_closed_day(): void
{
    [$app] = fresh();
    $app->slots->close('2026-10-08');
    $app->slots->close('2026-10-08'); // повторно — без ошибки
    eq([], $app->slots->freeSlots('2026-10-08', at(NOW)));
    ok(!in_array('2026-10-08', $app->slots->availableDays(at(NOW))), 'день не предлагается');
    eq('closed', $app->slots->unavailableReason('2026-10-08 18:30:00', at(NOW)));

    // Открыть один слот в закрытом дне: остальные остаются закрытыми.
    $app->slots->open('2026-10-08', '18:30');
    eq(['18:30'], array_column($app->slots->freeSlots('2026-10-08', at(NOW)), 'time'));
    ok(!$app->slots->isDayClosed('2026-10-08'));

    $app->slots->open('2026-10-08');
    eq(5, count($app->slots->freeSlots('2026-10-08', at(NOW))));
}

function test_day_status_for_admin(): void
{
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-06 11:00:00'), at(NOW));
    $app->slots->close('2026-10-06', '15:00');
    $states = array_column($app->slots->dayStatus('2026-10-06', at('2026-10-05 22:00:00')), 'state', 'time');
    eq(['09:00' => 'too_late', '11:00' => 'booked', '15:00' => 'closed', '16:45' => 'free', '18:30' => 'free'], $states);
    $slots = array_column($app->slots->dayStatus('2026-10-06', at(NOW)), 'booking_id', 'time');
    eq((int)$b['id'], $slots['11:00']);
}

// --- Форма ---

function test_validation(): void
{
    [$app] = fresh();
    $e = throws(ValidationError::class, fn() => $app->bookings->create(
        form($app, '2026-10-07 11:00:00', ['name' => ' ', 'email' => 'не-почта', 'telegram' => '', 'phone' => '', 'consent' => '']),
        at(NOW)
    ));
    eq(['name', 'email', 'telegram', 'consent'], array_keys($e->errors));

    // Курс на этапе 1 через форму не бронируется.
    $course = (int)$app->products->byCode('course10')['id'];
    $e = throws(ValidationError::class, fn() => $app->bookings->create(form($app, '2026-10-07 11:00:00', ['product_id' => $course]), at(NOW)));
    eq(['product_id'], array_keys($e->errors));

    // Ничего не сохранилось.
    eq(0, (int)$app->db->value('SELECT COUNT(*) FROM bookings'));
}

function test_contact_normalization(): void
{
    eq('anna_qi', Validator::telegram('@anna_qi'));
    eq('anna_qi', Validator::telegram('https://t.me/anna_qi'));
    eq(false, Validator::telegram('ан'));
    eq(null, Validator::telegram(''));
    eq('+79161234567', Validator::phone('8 (916) 123-45-67'));
    eq('+79161234567', Validator::phone('+7 916 123 45 67'));
    eq(false, Validator::phone('123'));
    eq(false, Validator::phone('позвоните мне'));

    // Телефон вместо ника — достаточно.
    $c = Validator::client(['name' => 'Анна', 'email' => 'Anna@Example.com', 'phone' => '89161234567', 'consent' => 'on']);
    eq('anna@example.com', $c['email']);
    eq(null, $c['telegram']);
}

function test_returning_client_is_matched_by_email(): void
{
    [$app] = fresh();
    $a = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $b = $app->bookings->create(form($app, '2026-10-08 11:00:00', ['email' => 'ANNA@example.com', 'telegram' => '', 'phone' => '+79161234567']), at(NOW));
    eq($a['client_id'], $b['client_id']);
    eq('anna_qi', $b['telegram'], 'пустой ник не затирает сохранённый');
    eq('+79161234567', $b['phone']);
}

function test_booking_fields_for_later_stages(): void
{
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW), '203.0.113.5');
    eq(2000, (int)$b['amount']);
    eq(1000, (int)$b['prepay_amount']);
    eq('none', $b['payment_status']);
    eq(null, $b['hold_until']);
    eq('203.0.113.5', $b['consent_ip']);
    eq('draft-1', $b['consent_version']);
    eq(16, strlen($b['public_id']));
}

// --- Подтверждение и предложение другого времени ---

function test_confirm(): void
{
    [$app, $n] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    eq('created', $n->last()['event']);
    $b = $app->bookings->confirm((int)$b['id'], at(NOW));
    eq(Status::CONFIRMED, $b['status']);
    eq('2026-10-07 11:00:00', $b['slot_lock'], 'подтверждённая держит слот');
    eq('confirmed', $n->last()['event']);
    // Повторное подтверждение — ошибка статуса, не тихий успех.
    throws(StateError::class, fn() => $app->bookings->confirm((int)$b['id'], at(NOW)));
    throws(StateError::class, fn() => $app->bookings->decline((int)$b['id'], at(NOW)));
}

function test_propose_other_time_and_accept(): void
{
    [$app, $n] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $b = $app->bookings->propose((int)$b['id'], '2026-10-08 16:45:00', at(NOW));

    eq(Status::PROPOSED, $b['status']);
    eq('2026-10-08 16:45:00', $b['starts_at']);
    eq('2026-10-08 18:15:00', $b['ends_at']);
    eq('2026-10-07 11:00:00', $b['requested_starts_at']);
    eq(null, $app->slots->unavailableReason('2026-10-07 11:00:00', at(NOW)), 'исходный слот свободен');
    eq('taken', $app->slots->unavailableReason('2026-10-08 16:45:00', at(NOW)), 'новый слот занят');

    $event = $n->last();
    eq('proposed', $event['event']);
    $accept = $event['extra']['accept_token'];
    $reject = $event['extra']['reject_token'];

    $r = $app->bookings->useToken($accept, at(NOW));
    eq(Status::CONFIRMED, $r['booking']['status']);
    eq('proposal_accepted', $n->last()['event']);

    // Токены одноразовые, и «не подходит» после «подходит» уже не работает.
    throws(StateError::class, fn() => $app->bookings->useToken($accept, at(NOW)));
    throws(StateError::class, fn() => $app->bookings->useToken($reject, at(NOW)));
    eq(Status::CONFIRMED, $app->bookings->get((int)$b['id'])['status']);
}

function test_propose_other_time_and_reject(): void
{
    [$app, $n] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $app->bookings->propose((int)$b['id'], '2026-10-08 16:45:00', at(NOW));
    $r = $app->bookings->useToken($n->last()['extra']['reject_token'], at(NOW));
    eq(Status::CANCELLED, $r['booking']['status']);
    eq(null, $r['booking']['slot_lock']);
    eq(null, $app->slots->unavailableReason('2026-10-08 16:45:00', at(NOW)), 'предложенный слот освободился');
}

function test_propose_twice_keeps_original_request_and_old_links_die(): void
{
    [$app, $n] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $app->bookings->propose((int)$b['id'], '2026-10-08 16:45:00', at(NOW));
    $oldAccept = $n->last()['extra']['accept_token'];
    $b = $app->bookings->propose((int)$b['id'], '2026-10-09 09:00:00', at(NOW));
    eq('2026-10-07 11:00:00', $b['requested_starts_at']);
    eq(null, $app->slots->unavailableReason('2026-10-08 16:45:00', at(NOW)));
    throws(StateError::class, fn() => $app->bookings->useToken($oldAccept, at(NOW)));
}

function test_propose_to_unavailable_slot_changes_nothing(): void
{
    [$app] = fresh();
    $a = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $app->bookings->create(form($app, '2026-10-08 16:45:00', ['email' => 'boris@example.com']), at(NOW));
    $app->slots->close('2026-10-09');

    eq('taken', throws(SlotUnavailable::class, fn() => $app->bookings->propose((int)$a['id'], '2026-10-08 16:45:00', at(NOW)))->reason);
    eq('closed', throws(SlotUnavailable::class, fn() => $app->bookings->propose((int)$a['id'], '2026-10-09 09:00:00', at(NOW)))->reason);
    $a = $app->bookings->get((int)$a['id']);
    eq(Status::PENDING, $a['status']);
    eq('2026-10-07 11:00:00', $a['slot_lock']);
}

function test_expired_token(): void
{
    [$app, $n] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $app->bookings->propose((int)$b['id'], '2026-10-08 16:45:00', at(NOW));
    $accept = $n->last()['extra']['accept_token'];
    throws(StateError::class, fn() => $app->bookings->useToken($accept, at('2026-10-08 16:45:00')));
    throws(StateError::class, fn() => $app->bookings->useToken('не-токен', at(NOW)));
}

// --- После занятия и cron ---

function test_mark_completed_and_no_show(): void
{
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $app->bookings->confirm((int)$b['id'], at(NOW));
    throws(StateError::class, fn() => $app->bookings->markCompleted((int)$b['id'], at(NOW)));
    eq(Status::COMPLETED, $app->bookings->markCompleted((int)$b['id'], at('2026-10-07 12:30:00'))['status']);
    // Можно исправить отметку.
    eq(Status::NO_SHOW, $app->bookings->markNoShow((int)$b['id'], at('2026-10-07 13:00:00'))['status']);

    $p = $app->bookings->create(form($app, '2026-10-08 11:00:00'), at(NOW));
    throws(StateError::class, fn() => $app->bookings->markCompleted((int)$p['id'], at('2026-10-08 12:30:00')));
}

function test_expire_unconfirmed(): void
{
    [$app, $n] = fresh();
    $soon = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    $later = $app->bookings->create(form($app, '2026-10-08 11:00:00'), at(NOW));
    $confirmed = $app->bookings->create(form($app, '2026-10-07 15:00:00'), at(NOW));
    $app->bookings->confirm((int)$confirmed['id'], at(NOW));

    eq([], $app->bookings->expireUnconfirmed(at('2026-10-07 08:00:00')), 'ровно 3 часа — ещё ждём');
    $expired = $app->bookings->expireUnconfirmed(at('2026-10-07 08:01:00')); // до 7-го 11:00 — 2 ч 59 мин
    eq([(int)$soon['id']], array_map(fn($b) => (int)$b['id'], $expired));
    eq(Status::EXPIRED, $app->bookings->get((int)$soon['id'])['status']);
    eq(null, $app->bookings->get((int)$soon['id'])['slot_lock']);
    eq(Status::PENDING, $app->bookings->get((int)$later['id'])['status']);
    eq(Status::CONFIRMED, $app->bookings->get((int)$confirmed['id'])['status']);
    eq('expired', $n->last()['event']);
}

function test_pending_reminder(): void
{
    [$app] = fresh();
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    eq([], $app->bookings->needingReminder(at('2026-10-05 22:59:00')));
    $due = $app->bookings->needingReminder(at('2026-10-05 23:00:00'));
    eq([(int)$b['id']], array_map(fn($x) => (int)$x['id'], $due));
    $app->bookings->markReminded((int)$b['id'], at('2026-10-05 23:00:00'));
    eq([], $app->bookings->needingReminder(at('2026-10-06 08:00:00')), 'напоминаем один раз');
}

function test_notifier_failure_does_not_break_booking(): void
{
    $db = new Db('sqlite::memory:');
    (new Migrator($db, __DIR__ . '/../migrations'))->migrate();
    $broken = new class implements Booking\Notifier {
        public function notify(string $event, array $booking, array $extra = []): void
        {
            throw new RuntimeException('api.telegram.org недоступен');
        }
    };
    $app = new App(['secret' => 'test'], $db, $broken);
    $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    $b = $app->bookings->create(form($app, '2026-10-07 11:00:00'), at(NOW));
    ini_set('error_log', (string)$prev);
    eq(Status::PENDING, $app->bookings->get((int)$b['id'])['status']);
}

// --- Защита формы ---

function test_rate_limit(): void
{
    [$app] = fresh();
    $now = at(NOW);
    for ($i = 0; $i < 5; $i++) {
        ok($app->rateLimit->attempt('203.0.113.5', 'book', 5, 600, $now));
    }
    ok(!$app->rateLimit->attempt('203.0.113.5', 'book', 5, 600, $now), 'шестая попытка');
    ok($app->rateLimit->attempt('198.51.100.7', 'book', 5, 600, $now), 'другой IP');
    ok($app->rateLimit->attempt('203.0.113.5', 'book', 5, 600, $now->modify('+601 seconds')), 'окно прошло');
    eq(0, (int)$app->db->value("SELECT COUNT(*) FROM rate_hits WHERE ip_hash = '203.0.113.5'"), 'IP не хранится в открытом виде');
}

require __DIR__ . '/channels.php';

// --- Запуск ---

$only = $argv[1] ?? '';
$tests = array_filter(get_defined_functions()['user'], fn($f) => str_starts_with($f, 'test_') && str_contains($f, $only));
$failed = 0;
foreach ($tests as $test) {
    try {
        $test();
        echo "  ok  $test\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $test\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n" . (count($tests) - $failed) . ' из ' . count($tests) . " прошли\n";
exit($failed ? 1 : 0);
