<?php
declare(strict_types=1);

namespace Booking;

/** Настройки из таблицы settings: имя → значение в JSON. */
final class Settings
{
    private ?array $cache = null;

    public function __construct(private Db $db) {}

    public function get(string $name, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function int(string $name): int
    {
        $v = $this->get($name);
        if (!is_int($v)) {
            throw new \RuntimeException("Настройка $name не задана или не число");
        }
        return $v;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT name, value FROM settings') as $row) {
                $this->cache[$row['name']] = json_decode($row['value'], true);
            }
        }
        return $this->cache;
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $now = Time::fmt(Time::now());
        $updated = $this->db->update('settings', ['value' => $json, 'updated_at' => $now], 'name = ?', [$name]);
        if ($updated === 0) {
            $this->db->insert('settings', ['name' => $name, 'value' => $json, 'updated_at' => $now]);
        }
        $this->cache = null;
    }
}
