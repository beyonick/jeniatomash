<?php
// Применить миграции: php bin/migrate.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Booking\App;
use Booking\Migrator;

$app = App::boot();
$applied = (new Migrator($app->db, __DIR__ . '/../migrations'))->migrate();
echo $applied ? 'Применено: ' . implode(', ', $applied) . PHP_EOL : 'Новых миграций нет.' . PHP_EOL;
