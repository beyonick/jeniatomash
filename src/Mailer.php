<?php
declare(strict_types=1);

namespace Booking;

/**
 * Письма. Локально (env=local) не отправляются, а дописываются в var/mail.log.
 * На хостинге — mail() через sendmail Timeweb, отправитель — ящик на домене.
 */
class Mailer
{
    public function __construct(private array $mail, private string $env, private string $logFile) {}

    /** @throws \RuntimeException при неудаче — Outbox повторит позже */
    public function send(string $to, string $subject, string $body): void
    {
        if ($this->env !== 'prod') {
            $entry = sprintf(
                "===== %s\nTo: %s\nReply-To: %s\nSubject: %s\n\n%s\n\n",
                Time::fmt(Time::now()), $to, $this->mail['reply_to'] ?? '', $subject, $body
            );
            if (file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Не удалось записать mail.log');
            }
            return;
        }

        $from = $this->mail['from'];
        $headers = [
            'From: ' . self::encode($this->mail['from_name'] ?? '') . " <$from>",
            'Reply-To: ' . ($this->mail['reply_to'] ?? $from),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>',
        ];
        $ok = mail($to, self::encode($subject), chunk_split(base64_encode($body)), implode("\r\n", $headers), '-f' . $from);
        if (!$ok) {
            throw new \RuntimeException('mail() вернул false');
        }
    }

    private static function encode(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}
