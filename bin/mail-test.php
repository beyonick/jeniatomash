<?php
// Проверка почты: php bin/mail-test.php адрес@example.com
// Отправляет письмо сразу (минуя очередь). На проде — настоящее письмо, локально — в var/mail.log.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Использование: php bin/mail-test.php адрес@example.com\n");
    exit(1);
}
$app = App::boot();
try {
    $app->mailer->send($to, 'Проверка почты сайта', "Это тестовое письмо модуля записи.\nЕсли оно пришло не в «Спам» — почта настроена.\n\n" . $app->texts->url('/'));
    echo $app->isProd() ? "Отправлено на $to. Проверьте входящие и «Спам».\n" : "env=local: письмо записано в var/mail.log\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
