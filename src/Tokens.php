<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/**
 * Одноразовые токены для ссылок из писем. В базе только sha256,
 * сам токен существует только в письме.
 */
final class Tokens
{
    public function __construct(private Db $db) {}

    public function issue(int $bookingId, string $action, DateTimeImmutable $expiresAt, DateTimeImmutable $now): string
    {
        $raw = bin2hex(random_bytes(24));
        $this->db->insert('action_tokens', [
            'booking_id' => $bookingId,
            'action'     => $action,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => Time::fmt($expiresAt),
            'created_at' => Time::fmt($now),
        ]);
        return $raw;
    }

    /** Действующий токен без погашения — для страницы «подтвердите действие». */
    public function find(string $raw, DateTimeImmutable $now): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM action_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $raw), Time::fmt($now)]
        );
    }

    /**
     * Погасить токен. Атомарно: из двух одновременных запросов пройдёт один.
     * Вместе с ним гасятся остальные токены заявки («подходит» гасит «не подходит»).
     */
    public function consume(string $raw, DateTimeImmutable $now): ?array
    {
        $token = $this->find($raw, $now);
        if ($token === null) {
            return null;
        }
        $won = $this->db->update(
            'action_tokens',
            ['used_at' => Time::fmt($now)],
            'id = ? AND used_at IS NULL',
            [$token['id']]
        );
        if ($won === 0) {
            return null;
        }
        $this->revokeAll((int)$token['booking_id'], $now);
        return $token;
    }

    public function revokeAll(int $bookingId, DateTimeImmutable $now): void
    {
        $this->db->update(
            'action_tokens',
            ['used_at' => Time::fmt($now)],
            'booking_id = ? AND used_at IS NULL',
            [$bookingId]
        );
    }
}
