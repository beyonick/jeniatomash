<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Booking\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Booking\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Errors.php';
require __DIR__ . '/helpers.php';
