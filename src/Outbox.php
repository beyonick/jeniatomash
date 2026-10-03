<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/**
 * Очередь уведомлений. Всё сначала пишется в outbox, потом отправляется:
 * сразу после ответа пользователю и повторно из cron, если не вышло.
 * Сбой Telegram или почты никогда не теряет заявку и не ломает страницу.
 */
final class Outbox
{
    public const EMAIL = 'email';
    public const TELEGRAM = 'telegram';

    /** Паузы между попытками, минуты. После последней — failed. */
    private const BACKOFF = [1, 5, 15, 30, 60, 120, 240, 480];

    /** @var int[] поставленные в этом запросе — их отправит flushNew() */
    private array $new = [];

    public function __construct(private Db $db, private Mailer $mailer, private Telegram $telegram) {}

    public function email(string $to, string $subject, string $body, DateTimeImmutable $now): int
    {
        return $this->add(self::EMAIL, $to, $subject, $body, [], $now);
    }

    /** $meta: reply_markup, booking_id (сохранить message_id в заявку). */
    public function telegram(string $chatId, string $html, array $meta, DateTimeImmutable $now): int
    {
        return $this->add(self::TELEGRAM, $chatId, '', $html, $meta, $now);
    }

    /** Отправить поставленное в этом запросе. */
    public function flushNew(DateTimeImmutable $now): void
    {
        $ids = $this->new;
        $this->new = [];
        foreach ($ids as $id) {
            $this->deliver($id, $now);
        }
    }

    /** Для cron: всё, чему пора. @return array{sent:int,failed:int} */
    public function flushDue(DateTimeImmutable $now, int $limit = 50): array
    {
        // Захваченные, но так и не отправленные (процесс упал) — вернуть в очередь.
        $this->db->update('outbox', ['status' => 'pending'], "status = 'sending' AND next_attempt_at <= ?", [Time::fmt($now)]);
        $rows = $this->db->all(
            "SELECT id FROM outbox WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id LIMIT $limit",
            [Time::fmt($now)]
        );
        $stats = ['sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $this->deliver((int)$row['id'], $now) ? $stats['sent']++ : $stats['failed']++;
        }
        return $stats;
    }

    public function deliver(int $id, DateTimeImmutable $now): bool
    {
        // Захват: из запроса и cron'а одновременно сообщение уйдёт только один раз.
        $claimed = $this->db->update(
            'outbox',
            ['status' => 'sending', 'next_attempt_at' => Time::fmt($now->modify('+10 minutes'))],
            "id = ? AND status = 'pending' AND next_attempt_at <= ?",
            [$id, Time::fmt($now)]
        );
        $msg = $claimed ? $this->db->one('SELECT * FROM outbox WHERE id = ?', [$id]) : null;
        if ($msg === null) {
            return false;
        }
        $meta = $msg['meta'] ? json_decode($msg['meta'], true) : [];
        try {
            if ($msg['channel'] === self::EMAIL) {
                $this->mailer->send($msg['recipient'], $msg['subject'], $msg['body']);
            } else {
                $params = [
                    'chat_id'                  => $msg['recipient'],
                    'text'                     => $msg['body'],
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ];
                if (!empty($meta['reply_markup'])) {
                    $params['reply_markup'] = $meta['reply_markup'];
                }
                $res = $this->telegram->call('sendMessage', $params);
                if (!empty($meta['booking_id']) && !empty($res['message_id'])) {
                    $this->db->update('bookings', ['tg_message_id' => $res['message_id']], 'id = ?', [$meta['booking_id']]);
                }
            }
            $this->db->update('outbox', [
                'status'   => 'sent',
                'attempts' => (int)$msg['attempts'] + 1,
                'sent_at'  => Time::fmt($now),
            ], 'id = ?', [$id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$msg['attempts'] + 1;
            $wait = self::BACKOFF[$attempts - 1] ?? null;
            $this->db->update('outbox', [
                'status'          => $wait === null ? 'failed' : 'pending',
                'attempts'        => $attempts,
                'last_error'      => mb_substr($e->getMessage(), 0, 1000),
                'next_attempt_at' => Time::fmt($now->modify('+' . ($wait ?? 0) . ' minutes')),
            ], 'id = ?', [$id]);
            return false;
        }
    }

    /** Неотправленное и упавшее — для админки. */
    public function problems(): array
    {
        return $this->db->all("SELECT * FROM outbox WHERE status = 'failed' OR (status = 'pending' AND attempts > 0) ORDER BY id DESC LIMIT 20");
    }

    private function add(string $channel, string $to, string $subject, string $body, array $meta, DateTimeImmutable $now): int
    {
        $id = $this->db->insert('outbox', [
            'channel'         => $channel,
            'recipient'       => $to,
            'subject'         => $subject,
            'body'            => $body,
            'meta'            => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => Time::fmt($now),
            'created_at'      => Time::fmt($now),
        ]);
        $this->new[] = $id;
        return $id;
    }
}
