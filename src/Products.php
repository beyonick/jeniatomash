<?php
declare(strict_types=1);

namespace Booking;

final class Products
{
    public function __construct(private Db $db) {}

    /** Активные продукты по порядку — для страницы записи. */
    public function active(): array
    {
        return $this->db->all('SELECT * FROM products WHERE active = 1 ORDER BY sort, id');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public function byCode(string $code): ?array
    {
        return $this->db->one('SELECT * FROM products WHERE code = ?', [$code]);
    }
}
