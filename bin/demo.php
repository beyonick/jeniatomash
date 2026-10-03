<?php
// Демо-данные для локального показа: php bin/demo.php
// Пересоздаёт локальную SQLite-базу, очищает логи и заводит несколько заявок в разных статусах.
// На хостинге (env=prod) и на MySQL не работает.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Db;
use Booking\Migrator;
use Booking\RecordingNotifier;
use Booking\Time;

$config = require __DIR__ . '/../config.php';
if (($config['env'] ?? '') === 'prod' || !str_starts_with($config['db']['dsn'], 'sqlite:')) {
    fwrite(STDERR, "Только для локальной SQLite-базы.\n");
    exit(1);
}

$var = __DIR__ . '/../var';
@unlink(substr($config['db']['dsn'], strlen('sqlite:')));
foreach (['mail.log', 'telegram.log', 'cron.log'] as $f) {
    @unlink("$var/$f");
}

$db = new Db($config['db']['dsn']);
(new Migrator($db, __DIR__ . '/../migrations'))->migrate();
// Уведомления при создании не пишем — ниже отправим в бот только новые заявки.
$app = new App($config, $db, new RecordingNotifier());
$bk = $app->bookings;
$now = $app->now();
$trial = (int)$app->products->byCode('trial')['id'];
$single = (int)$app->products->byCode('single')['id'];

/** Ближайший день через $days дней в формате Y-m-d. */
$day = static fn(int $days) => $now->modify("+$days day")->format('Y-m-d');
$book = static function (string $name, string $email, string $tg, string $phone, int $product, string $startsAt, ?DateTimeImmutable $at = null) use ($bk, $now) {
    return $bk->create([
        'name' => $name, 'email' => $email, 'telegram' => $tg, 'phone' => $phone,
        'consent' => 1, 'product_id' => $product, 'starts_at' => $startsAt,
    ], $at ?? $now, '127.0.0.1');
};

// Прошедшее и проведённое.
$past = Time::parse($day(-1) . ' 15:00:00');
$vera = $book('Вера', 'vera@example.com', 'vera_qigong', '', $trial, $day(-1) . ' 15:00:00', $past->modify('-2 days'));
$bk->confirm((int)$vera['id'], $past->modify('-2 days'));
$bk->markCompleted((int)$vera['id'], $past->modify('+2 hours'));

// Подтверждённое.
$anna = $book('Анна', 'anna@example.com', 'anna_sound', '', $single, $day(2) . ' 11:00:00');
$bk->confirm((int)$anna['id'], $now);

// Женя предложила другое время, ждём ответа ученицы.
$dasha = $book('Дарья', 'dasha@example.com', 'dasha_d', '', $single, $day(4) . ' 09:00:00');
$bk->propose((int)$dasha['id'], $day(5) . ' 11:00:00', $now);

// Новые заявки — придут в бот с кнопками.
$boris = $book('Борис', 'boris@example.com', '', '+7 916 000-00-00', $trial, $day(3) . ' 16:45:00');
$gleb = $book('Глеб', 'gleb@example.com', 'gleb_breath', '', $single, $day(3) . ' 18:30:00');

// Закрытый слот и закрытый день.
$app->slots->close($day(2), '18:30');
$app->slots->close($day(6));

foreach ([$boris, $gleb] as $b) {
    $app->notify->toBot($bk->get((int)$b['id']), 'Новая заявка', $now);
}
$app->outbox->flushNew($now);

echo "Готово: 5 заявок, закрыт слот {$day(2)} 18:30 и день {$day(6)}.\n";
echo "Запись: /zapis/   Админка: /admin/   Бот и письма: /dev/\n";
