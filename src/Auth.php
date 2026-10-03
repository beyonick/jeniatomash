<?php
declare(strict_types=1);

namespace Booking;

/** Вход в админку по паролю (один пользователь — Женя) и CSRF. */
final class Auth
{
    private const IDLE = 60 * 60 * 24 * 14; // сессия живёт две недели без действий

    public function __construct(private string $passwordHash, private bool $https) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('jt_admin');
        session_set_cookie_params([
            'lifetime' => self::IDLE,
            'path'     => '/admin/',
            'secure'   => $this->https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.gc_maxlifetime', (string)self::IDLE);
        ini_set('session.use_strict_mode', '1');
        session_start();
        if (!empty($_SESSION['admin']) && time() - ($_SESSION['seen'] ?? 0) > self::IDLE) {
            $_SESSION = [];
        }
        $_SESSION['seen'] = time();
    }

    public function check(): bool
    {
        return !empty($_SESSION['admin']);
    }

    public function login(string $password): bool
    {
        if ($this->passwordHash === '' || !password_verify($password, $this->passwordHash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function csrf(): string
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
