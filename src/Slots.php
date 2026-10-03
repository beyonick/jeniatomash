<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/**
 * Слоты: сетка из настроек, закрытые слоты и дни, правило «не позже чем
 * за N часов», горизонт записи. Занятость — по bookings.slot_lock.
 */
final class Slots
{
    public const FREE     = 'free';
    public const BOOKED   = 'booked';
    public const CLOSED   = 'closed';
    public const TOO_LATE = 'too_late'; // прошло или до начала меньше book_min_hours

    public function __construct(private Db $db, private Settings $settings) {}

    /** @return array<array{0:string,1:string}> [['09:00','10:30'], ...] */
    public function grid(): array
    {
        return $this->settings->get('slot_grid', []);
    }

    /** Слоты дня по сетке, без учёта занятости. Пусто, если день недели не рабочий. */
    public function daySlots(string $day): array
    {
        if (!Time::isDay($day)) {
            throw new \InvalidArgumentException("Неверная дата: $day");
        }
        $weekday = (int)Time::parse("$day 00:00:00")->format('N');
        if (!in_array($weekday, $this->settings->get('weekdays', []), true)) {
            return [];
        }
        $slots = [];
        foreach ($this->grid() as [$start, $end]) {
            $slots[] = [
                'day'       => $day,
                'time'      => $start,
                'end_time'  => $end,
                'starts_at' => "$day $start:00",
                'ends_at'   => "$day $end:00",
            ];
        }
        return $slots;
    }

    /** Слот сетки, начинающийся в $startsAt, или null. */
    public function findSlot(string $startsAt): ?array
    {
        try {
            $dt = Time::parse($startsAt);
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($this->daySlots($dt->format('Y-m-d')) as $slot) {
            if ($slot['starts_at'] === Time::fmt($dt)) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Слоты дня с состоянием: free / booked / closed / too_late.
     * Для админки и бота; у занятых — booking_id.
     */
    public function dayStatus(string $day, DateTimeImmutable $now): array
    {
        $slots = $this->daySlots($day);
        if (!$slots) {
            return [];
        }
        $locks = [];
        foreach ($this->db->all(
            'SELECT id, slot_lock FROM bookings WHERE slot_lock >= ? AND slot_lock <= ?',
            ["$day 00:00:00", "$day 23:59:59"]
        ) as $row) {
            $locks[$row['slot_lock']] = (int)$row['id'];
        }
        $closed = $this->closedTimes($day);

        foreach ($slots as &$slot) {
            $slot['booking_id'] = $locks[$slot['starts_at']] ?? null;
            $slot['state'] = match (true) {
                $slot['booking_id'] !== null                                => self::BOOKED,
                isset($closed['']) || isset($closed[$slot['time']])         => self::CLOSED,
                !$this->farEnough($slot['starts_at'], $now)                  => self::TOO_LATE,
                default                                                     => self::FREE,
            };
        }
        return $slots;
    }

    /** Свободные слоты дня для записи (с учётом горизонта). */
    public function freeSlots(string $day, DateTimeImmutable $now): array
    {
        if (!$this->withinHorizon($day, $now)) {
            return [];
        }
        return array_values(array_filter(
            $this->dayStatus($day, $now),
            static fn(array $s) => $s['state'] === self::FREE
        ));
    }

    /** Дни от сегодня до конца горизонта, где есть хотя бы один свободный слот. */
    public function availableDays(DateTimeImmutable $now): array
    {
        $days = [];
        $horizon = $this->settings->int('horizon_days');
        for ($i = 0; $i <= $horizon; $i++) {
            $day = $now->modify("+$i day")->format('Y-m-d');
            if ($this->freeSlots($day, $now)) {
                $days[] = $day;
            }
        }
        return $days;
    }

    /**
     * Все свободные слоты на горизонт записи — для страницы записи.
     * @return array{today:string,last:string,days:array<string,array>}
     */
    public function freeByDay(DateTimeImmutable $now): array
    {
        $horizon = $this->settings->int('horizon_days');
        $days = [];
        for ($i = 0; $i <= $horizon; $i++) {
            $day = $now->modify("+$i day")->format('Y-m-d');
            $days[$day] = array_map(
                static fn(array $s) => ['starts_at' => $s['starts_at'], 'time' => $s['time'], 'end' => $s['end_time']],
                $this->freeSlots($day, $now)
            );
        }
        return ['today' => $now->format('Y-m-d'), 'last' => array_key_last($days), 'days' => $days];
    }

    /** Почему слот нельзя занять: null — можно, иначе код причины (см. SlotUnavailable). */
    public function unavailableReason(string $startsAt, DateTimeImmutable $now): ?string
    {
        $slot = $this->findSlot($startsAt);
        if ($slot === null) {
            return 'not_in_grid';
        }
        if (!$this->farEnough($slot['starts_at'], $now)) {
            return 'too_late';
        }
        if (!$this->withinHorizon($slot['day'], $now)) {
            return 'too_far';
        }
        $closed = $this->closedTimes($slot['day']);
        if (isset($closed['']) || isset($closed[$slot['time']])) {
            return 'closed';
        }
        if ($this->db->value('SELECT id FROM bookings WHERE slot_lock = ?', [$slot['starts_at']]) !== null) {
            return 'taken';
        }
        return null;
    }

    /** Проверка перед занятием слота; бросает SlotUnavailable. Возвращает слот сетки. */
    public function assertBookable(string $startsAt, DateTimeImmutable $now): array
    {
        $reason = $this->unavailableReason($startsAt, $now);
        if ($reason !== null) {
            throw new SlotUnavailable($reason, $this->settings->int('book_min_hours'));
        }
        return $this->findSlot($startsAt);
    }

    // --- Закрытие и открытие ---

    /** Закрыть слот ($time = '09:00') или весь день ($time = null). */
    public function close(string $day, ?string $time = null, string $note = ''): void
    {
        $this->assertDayTime($day, $time);
        $exists = $this->db->value(
            'SELECT id FROM closures WHERE day = ? AND start_time = ?',
            [$day, $time ?? '']
        );
        if ($exists === null) {
            $this->db->insert('closures', [
                'day'        => $day,
                'start_time' => $time ?? '',
                'note'       => $note,
                'created_at' => Time::fmt(Time::now()),
            ]);
        }
    }

    /**
     * Открыть слот или весь день. Если открывают один слот в закрытом дне,
     * закрытие дня заменяется закрытием остальных слотов.
     */
    public function open(string $day, ?string $time = null): void
    {
        $this->assertDayTime($day, $time);
        $this->db->tx(function () use ($day, $time) {
            if ($time === null) {
                $this->db->run('DELETE FROM closures WHERE day = ?', [$day]);
                return;
            }
            $dayClosure = $this->db->one("SELECT * FROM closures WHERE day = ? AND start_time = ''", [$day]);
            if ($dayClosure !== null) {
                $this->db->run('DELETE FROM closures WHERE id = ?', [$dayClosure['id']]);
                foreach ($this->daySlots($day) as $slot) {
                    if ($slot['time'] !== $time) {
                        $this->close($day, $slot['time'], $dayClosure['note']);
                    }
                }
            }
            $this->db->run('DELETE FROM closures WHERE day = ? AND start_time = ?', [$day, $time]);
        });
    }

    public function isDayClosed(string $day): bool
    {
        return isset($this->closedTimes($day)['']);
    }

    // --- Внутреннее ---

    /** @return array<string,true> ключи: '' (весь день) или '09:00' */
    private function closedTimes(string $day): array
    {
        $rows = $this->db->all('SELECT start_time FROM closures WHERE day = ?', [$day]);
        return array_fill_keys(array_column($rows, 'start_time'), true);
    }

    private function farEnough(string $startsAt, DateTimeImmutable $now): bool
    {
        $minHours = $this->settings->int('book_min_hours');
        return Time::parse($startsAt)->getTimestamp() - $now->getTimestamp() >= $minHours * 3600;
    }

    private function withinHorizon(string $day, DateTimeImmutable $now): bool
    {
        $last = $now->modify('+' . $this->settings->int('horizon_days') . ' day')->format('Y-m-d');
        return $day >= $now->format('Y-m-d') && $day <= $last;
    }

    private function assertDayTime(string $day, ?string $time): void
    {
        if (!Time::isDay($day)) {
            throw new \InvalidArgumentException("Неверная дата: $day");
        }
        if ($time !== null && !in_array($time, array_column($this->grid(), 0), true)) {
            throw new \InvalidArgumentException("Такого слота нет в сетке: $time");
        }
    }
}
