<?php
declare(strict_types=1);

namespace Booking;

use PDO;
use PDOException;
use PDOStatement;

/** Тонкая обёртка над PDO. SQL пишем общий для MySQL и SQLite, только подготовленные запросы. */
final class Db
{
    public readonly PDO $pdo;
    public readonly string $driver;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (str_starts_with($dsn, 'mysql:')) {
            // rowCount() у UPDATE — найденные строки, а не изменённые, как в SQLite.
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }
        $this->pdo = new PDO($dsn, $user, $pass, $options);
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($this->driver === 'mysql') {
            $this->pdo->exec("SET NAMES utf8mb4");
            $this->pdo->exec("SET time_zone = '+03:00'");
        }
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Имена таблицы и колонок — только из кода, не из ввода пользователя. */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    /** UPDATE с условием; возвращает число изменённых строк. */
    public function update(string $table, array $set, string $where, array $whereParams = []): int
    {
        $parts = [];
        foreach (array_keys($set) as $col) {
            $parts[] = "$col = ?";
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $parts), $where);
        return $this->run($sql, [...array_values($set), ...$whereParams])->rowCount();
    }

    /** @template T @param callable():T $fn @return T */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Нарушение уникального индекса. SQLSTATE 23000 у обоих драйверов. */
    public static function isUniqueViolation(PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23000'
            && preg_match('/unique|duplicate/i', $e->getMessage()) === 1;
    }

    /** Плейсхолдеры для IN (...). */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
