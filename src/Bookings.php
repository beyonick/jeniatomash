<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;
use PDOException;

/**
 * Заявки: создание и смена статусов. Все переходы — условным UPDATE
 * (WHERE status IN ...), поэтому два одновременных действия не испортят заявку.
 */
final class Bookings
{
    public const TOKEN_ACCEPT = 'proposal_accept';
    public const TOKEN_REJECT = 'proposal_reject';

    public function __construct(
        private Db $db,
        private Settings $settings,
        private Slots $slots,
        private Products $products,
        private Tokens $tokens,
        private Notifier $notifier,
    ) {}

    /**
     * Новая заявка от ученика. Сразу занимает слот со статусом «ожидает подтверждения».
     *
     * @param array $in name, email, telegram, phone, consent, product_id, starts_at
     * @throws ValidationError|SlotUnavailable
     */
    public function create(array $in, DateTimeImmutable $now, string $ip = ''): array
    {
        $client = Validator::client($in);

        $product = $this->products->find((int)($in['product_id'] ?? 0));
        if ($product === null || !$product['active'] || !$product['is_bookable']) {
            throw new ValidationError(['product_id' => 'Выберите формат занятия.']);
        }

        $id = $this->db->tx(function () use ($in, $now, $ip, $client, $product) {
            $slot = $this->slots->assertBookable((string)($in['starts_at'] ?? ''), $now);
            $clientId = $this->upsertClient($client, $now);
            $ts = Time::fmt($now);
            try {
                $id = $this->db->insert('bookings', [
                    'public_id'       => bin2hex(random_bytes(8)),
                    'client_id'       => $clientId,
                    'product_id'      => $product['id'],
                    'starts_at'       => $slot['starts_at'],
                    'ends_at'         => $slot['ends_at'],
                    'status'          => Status::PENDING,
                    'slot_lock'       => $slot['starts_at'],
                    'amount'          => $product['price'],
                    'prepay_amount'   => $product['prepay'],
                    'consent_at'      => $ts,
                    'consent_ip'      => $ip !== '' ? $ip : null,
                    'consent_version' => (string)$this->settings->get('consent_version', ''),
                    'created_at'      => $ts,
                    'updated_at'      => $ts,
                ]);
            } catch (PDOException $e) {
                // Слот заняли между проверкой и вставкой — ловит уникальный индекс slot_lock.
                if (Db::isUniqueViolation($e)) {
                    throw new SlotUnavailable('taken');
                }
                throw $e;
            }
            $this->logEvent($id, 'created', 'client', [], $now);
            return $id;
        });

        $booking = $this->get($id);
        $this->emit('created', $booking, 'client');
        return $booking;
    }

    /** Заявка с данными ученика и продукта. */
    public function get(int $id): ?array
    {
        return $this->db->one(
            'SELECT b.*, c.name AS client_name, c.email, c.telegram, c.phone,
                    p.code AS product_code, p.title AS product_title
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN products p ON p.id = b.product_id
             WHERE b.id = ?',
            [$id]
        );
    }

    public function byPublicId(string $publicId): ?array
    {
        $id = $this->db->value('SELECT id FROM bookings WHERE public_id = ?', [$publicId]);
        return $id === null ? null : $this->get((int)$id);
    }

    /** Заявки за период по времени начала, для админки и календаря. */
    public function between(string $from, string $to, ?array $statuses = null): array
    {
        $sql = 'SELECT b.*, c.name AS client_name, c.email, c.telegram, c.phone,
                       p.code AS product_code, p.title AS product_title
                FROM bookings b
                JOIN clients c ON c.id = b.client_id
                JOIN products p ON p.id = b.product_id
                WHERE b.starts_at >= ? AND b.starts_at < ?';
        $params = [$from, $to];
        if ($statuses) {
            $sql .= ' AND b.status IN (' . Db::in($statuses) . ')';
            $params = [...$params, ...$statuses];
        }
        return $this->db->all($sql . ' ORDER BY b.starts_at, b.id', $params);
    }

    // --- Действия Жени (бот и админка) ---

    public function confirm(int $id, DateTimeImmutable $now, string $actor = 'admin'): array
    {
        return $this->transition($id, [Status::PENDING], Status::CONFIRMED, ['confirmed_at' => Time::fmt($now)], $actor, 'confirmed', $now);
    }

    public function decline(int $id, DateTimeImmutable $now, string $actor = 'admin'): array
    {
        return $this->transition($id, [Status::PENDING, Status::PROPOSED], Status::DECLINED, ['cancelled_at' => Time::fmt($now)], $actor, 'declined', $now);
    }

    /** Отмена со стороны Жени, в том числе подтверждённой заявки. */
    public function cancel(int $id, DateTimeImmutable $now, string $actor = 'admin', string $reason = ''): array
    {
        return $this->transition(
            $id,
            [Status::PENDING, Status::PROPOSED, Status::CONFIRMED],
            Status::CANCELLED,
            ['cancelled_at' => Time::fmt($now)],
            $actor,
            'cancelled',
            $now,
            ['reason' => $reason]
        );
    }

    /**
     * Предложить другое время. Новый слот занимается сразу, исходный освобождается.
     * Ученику уходят ссылки «подходит / не подходит» (токены в $extra события).
     *
     * @throws SlotUnavailable|StateError
     */
    public function propose(int $id, string $newStartsAt, DateTimeImmutable $now, string $actor = 'admin'): array
    {
        [$booking, $accept, $reject] = $this->db->tx(function () use ($id, $newStartsAt, $now, $actor) {
            $booking = $this->require($id);
            if (!in_array($booking['status'], [Status::PENDING, Status::PROPOSED], true)) {
                throw new StateError('Предложить другое время можно только для неподтверждённой заявки.');
            }
            $slot = $this->slots->assertBookable($newStartsAt, $now);
            try {
                $changed = $this->db->update('bookings', [
                    'starts_at'           => $slot['starts_at'],
                    'ends_at'             => $slot['ends_at'],
                    'slot_lock'           => $slot['starts_at'],
                    'requested_starts_at' => $booking['requested_starts_at'] ?? $booking['starts_at'],
                    'status'              => Status::PROPOSED,
                    'updated_at'          => Time::fmt($now),
                ], 'id = ? AND status IN (?, ?)', [$id, Status::PENDING, Status::PROPOSED]);
            } catch (PDOException $e) {
                if (Db::isUniqueViolation($e)) {
                    throw new SlotUnavailable('taken');
                }
                throw $e;
            }
            if ($changed === 0) {
                throw new StateError('Заявка уже изменилась.');
            }
            $this->tokens->revokeAll($id, $now);
            $expires = Time::parse($slot['starts_at']);
            $accept = $this->tokens->issue($id, self::TOKEN_ACCEPT, $expires, $now);
            $reject = $this->tokens->issue($id, self::TOKEN_REJECT, $expires, $now);
            $this->logEvent($id, 'proposed', $actor, ['from' => $booking['starts_at'], 'to' => $slot['starts_at']], $now);
            return [$this->get($id), $accept, $reject];
        });

        $this->emit('proposed', $booking, $actor, ['accept_token' => $accept, 'reject_token' => $reject]);
        return $booking;
    }

    /** После занятия: «проведено». */
    public function markCompleted(int $id, DateTimeImmutable $now, string $actor = 'admin'): array
    {
        $this->assertStarted($id, $now);
        return $this->transition($id, [Status::CONFIRMED, Status::NO_SHOW], Status::COMPLETED, [], $actor, 'completed', $now);
    }

    /** После занятия: «не пришёл». */
    public function markNoShow(int $id, DateTimeImmutable $now, string $actor = 'admin'): array
    {
        $this->assertStarted($id, $now);
        return $this->transition($id, [Status::CONFIRMED, Status::COMPLETED], Status::NO_SHOW, [], $actor, 'no_show', $now);
    }

    // --- Действия ученика по ссылкам из писем ---

    /**
     * Погасить токен из письма и выполнить действие.
     * @return array{action:string,booking:array}
     * @throws StateError если ссылка недействительна или заявка уже изменилась
     */
    public function useToken(string $raw, DateTimeImmutable $now): array
    {
        $token = $this->tokens->consume($raw, $now);
        if ($token === null) {
            throw new StateError('Ссылка уже использована или устарела.');
        }
        $id = (int)$token['booking_id'];
        $booking = match ($token['action']) {
            self::TOKEN_ACCEPT => $this->transition(
                $id, [Status::PROPOSED], Status::CONFIRMED, ['confirmed_at' => Time::fmt($now)], 'client', 'proposal_accepted', $now
            ),
            self::TOKEN_REJECT => $this->transition(
                $id, [Status::PROPOSED], Status::CANCELLED, ['cancelled_at' => Time::fmt($now)], 'client', 'proposal_rejected', $now
            ),
            default => throw new StateError('Неизвестное действие.'),
        };
        return ['action' => $token['action'], 'booking' => $booking];
    }

    // --- Для cron ---

    /**
     * Снять неподтверждённые заявки (ожидает / предложено другое время),
     * если до начала осталось меньше unconfirmed_expire_hours.
     * @return array снятые заявки
     */
    public function expireUnconfirmed(DateTimeImmutable $now): array
    {
        $hours = $this->settings->int('unconfirmed_expire_hours');
        $ids = $this->db->all(
            'SELECT id FROM bookings WHERE status IN (?, ?) AND starts_at < ?',
            [Status::PENDING, Status::PROPOSED, Time::fmt($now->modify("+$hours hours"))]
        );
        $expired = [];
        foreach (array_column($ids, 'id') as $id) {
            try {
                $expired[] = $this->transition(
                    (int)$id, [Status::PENDING, Status::PROPOSED], Status::EXPIRED,
                    ['cancelled_at' => Time::fmt($now)], 'system', 'expired', $now, ['reason' => 'unconfirmed']
                );
            } catch (StateError) {
                // Женя успела ответить между выборкой и обновлением.
            }
        }
        return $expired;
    }

    /** Заявки без ответа дольше pending_remind_hours, о которых ещё не напоминали. */
    public function needingReminder(DateTimeImmutable $now): array
    {
        $hours = $this->settings->int('pending_remind_hours');
        $rows = $this->db->all(
            "SELECT b.id FROM bookings b
             WHERE b.status = ? AND b.created_at <= ?
               AND NOT EXISTS (SELECT 1 FROM booking_events e WHERE e.booking_id = b.id AND e.type = 'admin_reminded')
             ORDER BY b.starts_at",
            [Status::PENDING, Time::fmt($now->modify("-$hours hours"))]
        );
        return array_map(fn($r) => $this->get((int)$r['id']), $rows);
    }

    public function markReminded(int $id, DateTimeImmutable $now): void
    {
        $this->logEvent($id, 'admin_reminded', 'system', [], $now);
    }

    public function logEvent(int $bookingId, string $type, string $actor, array $data, DateTimeImmutable $now): void
    {
        $this->db->insert('booking_events', [
            'booking_id' => $bookingId,
            'type'       => $type,
            'actor'      => $actor,
            'data'       => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => Time::fmt($now),
        ]);
    }

    // --- Внутреннее ---

    private function transition(
        int $id,
        array $from,
        string $to,
        array $set,
        string $actor,
        string $event,
        DateTimeImmutable $now,
        array $data = [],
    ): array {
        $set['status'] = $to;
        $set['updated_at'] = Time::fmt($now);
        if (!Status::holdsSlot($to)) {
            $set['slot_lock'] = null;
        }
        $changed = $this->db->update('bookings', $set, 'id = ? AND status IN (' . Db::in($from) . ')', [$id, ...$from]);
        if ($changed === 0) {
            $this->require($id);
            throw new StateError('Заявка уже в другом статусе.');
        }
        if (!Status::holdsSlot($to) || $to === Status::CONFIRMED) {
            $this->tokens->revokeAll($id, $now);
        }
        $this->logEvent($id, $event, $actor, $data, $now);
        $booking = $this->get($id);
        $this->emit($event, $booking, $actor, $data);
        return $booking;
    }

    private function require(int $id): array
    {
        return $this->get($id) ?? throw new StateError('Заявка не найдена.');
    }

    private function assertStarted(int $id, DateTimeImmutable $now): void
    {
        if (Time::parse($this->require($id)['starts_at']) > $now) {
            throw new StateError('Занятие ещё не началось.');
        }
    }

    /** Ученик определяется по почте. Пустые контакты не затирают сохранённые. */
    private function upsertClient(array $c, DateTimeImmutable $now): int
    {
        $ts = Time::fmt($now);
        $existing = $this->db->one('SELECT * FROM clients WHERE email = ?', [$c['email']]);
        if ($existing === null) {
            return $this->db->insert('clients', [
                'name'       => $c['name'],
                'email'      => $c['email'],
                'telegram'   => $c['telegram'],
                'phone'      => $c['phone'],
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }
        $this->db->update('clients', [
            'name'       => $c['name'],
            'telegram'   => $c['telegram'] ?? $existing['telegram'],
            'phone'      => $c['phone'] ?? $existing['phone'],
            'updated_at' => $ts,
        ], 'id = ?', [$existing['id']]);
        return (int)$existing['id'];
    }

    /** $extra['actor'] — кто сделал: client / admin / bot / system. */
    private function emit(string $event, array $booking, string $actor, array $extra = []): void
    {
        try {
            $this->notifier->notify($event, $booking, ['actor' => $actor] + $extra);
        } catch (\Throwable $e) {
            error_log("Уведомление $event по заявке {$booking['id']} не отправлено: " . $e->getMessage());
        }
    }
}
