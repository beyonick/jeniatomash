<?php
declare(strict_types=1);

namespace Booking;

/**
 * Миграции — файлы migrations/NNN_name.php, каждый возвращает function (Db $db): void.
 * Применённые записываются в schema_migrations.
 */
final class Migrator
{
    public function __construct(private Db $db, private string $dir) {}

    /** @return string[] применённые сейчас миграции */
    public function migrate(): array
    {
        $this->db->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );
        $done = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');

        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (in_array($version, $done, true)) {
                continue;
            }
            $fn = require $file;
            $fn($this->db);
            $this->db->insert('schema_migrations', [
                'version'    => $version,
                'applied_at' => Time::fmt(Time::now()),
            ]);
            $applied[] = $version;
        }
        return $applied;
    }
}
