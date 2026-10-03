<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/** Регулярные задачи. Запускается cron'ом раз в 5 минут (cron/run.php). */
final class Cron
{
    public function __construct(private App $app) {}

    /** @return array<string,mixed> что сделано — в лог */
    public function run(DateTimeImmutable $now): array
    {
        $done = [];
        $done['expired'] = count($this->app->bookings->expireUnconfirmed($now));
        $done['reminded'] = $this->remindPending($now);
        $done['summary'] = $this->eveningSummary($now);
        $done['outcome_asked'] = $this->askOutcome($now);
        $done['outbox'] = $this->app->outbox->flushDue($now);
        $this->cleanup($now);
        return $done;
    }

    /** Напомнить Жене о заявках без ответа. */
    private function remindPending(DateTimeImmutable $now): int
    {
        $list = $this->app->bookings->needingReminder($now);
        foreach ($list as $b) {
            $this->app->notify->toBot($b, $this->app->texts->adminTitle('reminder', $b), $now);
            [$subject, $body] = $this->app->texts->adminEmail('reminder', $b);
            $this->adminEmail($subject, $body, $now);
            $this->app->bookings->markReminded((int)$b['id'], $now);
        }
        return count($list);
    }

    /** Сводка на завтра — один раз в день, после evening_summary_time. */
    private function eveningSummary(DateTimeImmutable $now): bool
    {
        $at = (string)$this->app->settings->get('evening_summary_time', '20:00');
        $today = $now->format('Y-m-d');
        if ($now->format('H:i') < $at || $this->app->settings->get('last_summary_day') === $today) {
            return false;
        }
        $this->app->settings->set('last_summary_day', $today);

        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $html = (new Bot($this->app))->daySummary($tomorrow, $now, 'Завтра');
        $chat = $this->app->telegram->adminChatId();
        if ($chat !== '' || !$this->app->telegram->enabled()) {
            $this->app->outbox->telegram($chat ?: 'local', $html, [], $now);
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->adminEmail('[Запись] Сводка на завтра, ' . Fmt::dayShort($tomorrow), $text . "\n\n" . $this->app->texts->url('/admin/'), $now);
        return true;
    }

    /** После занятия спросить: проведено или не пришёл. Один раз, в пределах двух суток. */
    private function askOutcome(DateTimeImmutable $now): int
    {
        $rows = $this->app->db->all(
            "SELECT b.id FROM bookings b
             WHERE b.status = ? AND b.ends_at <= ? AND b.ends_at >= ?
               AND NOT EXISTS (SELECT 1 FROM booking_events e WHERE e.booking_id = b.id AND e.type = 'outcome_asked')",
            [Status::CONFIRMED, Time::fmt($now), Time::fmt($now->modify('-2 days'))]
        );
        foreach ($rows as $row) {
            $b = $this->app->bookings->get((int)$row['id']);
            $this->app->notify->toBot($b, 'Как прошло занятие?', $now);
            $this->app->bookings->logEvent((int)$b['id'], 'outcome_asked', 'system', [], $now);
        }
        return count($rows);
    }

    private function cleanup(DateTimeImmutable $now): void
    {
        $this->app->rateLimit->purge($now);
        // В письмах персональные данные: отправленное храним 30 дней.
        $this->app->db->run("DELETE FROM outbox WHERE status = 'sent' AND sent_at < ?", [Time::fmt($now->modify('-30 days'))]);
        $this->app->db->run('DELETE FROM action_tokens WHERE expires_at < ?', [Time::fmt($now->modify('-30 days'))]);
        $this->purgeOldClients($now);
    }

    /**
     * Срок хранения из политики: ученик и все его заявки удаляются,
     * если с его последнего занятия прошло больше retention_years лет.
     * @return int сколько учеников удалено
     */
    public function purgeOldClients(DateTimeImmutable $now): int
    {
        $years = (int)$this->app->settings->get('retention_years', 3);
        $db = $this->app->db;
        $ids = array_column($db->all(
            'SELECT c.id FROM clients c
             WHERE NOT EXISTS (SELECT 1 FROM bookings b WHERE b.client_id = c.id AND b.starts_at >= ?)',
            [Time::fmt($now->modify("-$years years"))]
        ), 'id');
        foreach ($ids as $id) {
            $db->tx(function () use ($db, $id) {
                $db->run('DELETE FROM action_tokens WHERE booking_id IN (SELECT id FROM bookings WHERE client_id = ?)', [$id]);
                $db->run('DELETE FROM booking_events WHERE booking_id IN (SELECT id FROM bookings WHERE client_id = ?)', [$id]);
                $db->run('DELETE FROM bookings WHERE client_id = ?', [$id]);
                $db->run('DELETE FROM clients WHERE id = ?', [$id]);
            });
        }
        return count($ids);
    }

    private function adminEmail(string $subject, string $body, DateTimeImmutable $now): void
    {
        $to = (string)($this->app->config['mail']['admin_copy'] ?? '');
        if ($to !== '') {
            $this->app->outbox->email($to, $subject, $body, $now);
        }
    }
}
