<?php
// Настройки в базе (сетка, сроки, контакты, тексты):
//   php bin/settings.php                       — показать все
//   php bin/settings.php set contact_telegram '"zhenya_tomash"'   — значение в JSON
//   php bin/settings.php set book_min_hours 12
//   php bin/settings.php set slot_grid '[["09:00","10:30"],["11:00","12:30"]]'
//   php bin/settings.php price single 2500     — цена продукта (trial, single, course10)
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;

$app = App::boot();
$cmd = $argv[1] ?? '';

if ($cmd === '') {
    foreach ($app->settings->all() as $name => $value) {
        printf("%-26s %s\n", $name, json_encode($value, JSON_UNESCAPED_UNICODE));
    }
    echo "\nПродукты:\n";
    foreach ($app->db->all('SELECT code, title, price, prepay, is_bookable, active FROM products ORDER BY sort') as $p) {
        printf("%-10s %s цена %6d  предоплата %6d  %s\n", $p['code'], mb_str_pad($p['title'], 20), $p['price'], $p['prepay'], $p['is_bookable'] ? 'запись через форму' : 'без формы');
    }
    exit;
}

if ($cmd === 'set' && isset($argv[2], $argv[3])) {
    $value = json_decode($argv[3], true);
    if ($value === null && $argv[3] !== 'null') {
        fwrite(STDERR, "Значение должно быть JSON: строка в кавычках '\"текст\"', число 12, список [..]\n");
        exit(1);
    }
    $app->settings->set($argv[2], $value);
    echo "$argv[2] = " . json_encode($value, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

if ($cmd === 'price' && isset($argv[2], $argv[3]) && ctype_digit($argv[3])) {
    $n = $app->db->update('products', ['price' => (int)$argv[3]], 'code = ?', [$argv[2]]);
    echo $n ? "Цена $argv[2] = $argv[3] ₽\n" : "Нет продукта $argv[2]\n";
    exit($n ? 0 : 1);
}

fwrite(STDERR, "Команды: (без аргументов) | set ИМЯ JSON | price КОД РУБЛИ\n");
exit(1);
