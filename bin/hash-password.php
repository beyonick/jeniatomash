<?php
// Хеш пароля админки для config.php: php bin/hash-password.php 'пароль'
declare(strict_types=1);

if (($argv[1] ?? '') === '') {
    fwrite(STDERR, "Использование: php bin/hash-password.php 'пароль'\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_DEFAULT), PHP_EOL;
