<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/** Ограничение частоты по IP. IP не храним, только HMAC от него. */
final class RateLimit
{
    public function __construct(private Db $db, private string $secret) {}

    public function key(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->secret);
    }

    /** true — можно; попытка засчитывается. false — лимит исчерпан. */
    public function attempt(string $ip, string $action, int $limit, int $windowSec, DateTimeImmutable $now): bool
    {
        $key = $this->key($ip);
        $since = Time::fmt($now->modify("-$windowSec seconds"));
        $count = (int)$this->db->value(
            'SELECT COUNT(*) FROM rate_hits WHERE ip_hash = ? AND action = ? AND created_at > ?',
            [$key, $action, $since]
        );
        if ($count >= $limit) {
            return false;
        }
        $this->db->insert('rate_hits', [
            'ip_hash'    => $key,
            'action'     => $action,
            'created_at' => Time::fmt($now),
        ]);
        return true;
    }

    public function purge(DateTimeImmutable $now, int $olderThanSec = 86400): void
    {
        $this->db->run('DELETE FROM rate_hits WHERE created_at < ?', [Time::fmt($now->modify("-$olderThanSec seconds"))]);
    }
}
