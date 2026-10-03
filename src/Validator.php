<?php
declare(strict_types=1);

namespace Booking;

/** Проверка и нормализация данных формы записи. */
final class Validator
{
    /**
     * @return array{name:string,email:string,telegram:?string,phone:?string}
     * @throws ValidationError
     */
    public static function client(array $in): array
    {
        $errors = [];

        $name = self::clean($in['name'] ?? '');
        if ($name === '') {
            $errors['name'] = 'Укажите имя.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Имя слишком длинное.';
        }

        $email = mb_strtolower(self::clean($in['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Укажите почту: на неё придёт подтверждение.';
        } elseif (mb_strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Проверьте адрес почты.';
        }

        $telegram = self::telegram(self::clean($in['telegram'] ?? ''));
        $phone = self::phone(self::clean($in['phone'] ?? ''));
        if ($telegram === false) {
            $errors['telegram'] = 'Ник в Telegram: латиница, цифры и _, от 5 символов.';
        }
        if ($phone === false) {
            $errors['phone'] = 'Проверьте номер телефона.';
        }
        if ($telegram === null && $phone === null && !isset($errors['telegram']) && !isset($errors['phone'])) {
            $errors['telegram'] = 'Укажите ник в Telegram или телефон, чтобы Женя могла с вами связаться.';
        }

        if (empty($in['consent'])) {
            $errors['consent'] = 'Нужно согласие на обработку персональных данных.';
        }

        if ($errors) {
            throw new ValidationError($errors);
        }
        return ['name' => $name, 'email' => $email, 'telegram' => $telegram ?: null, 'phone' => $phone ?: null];
    }

    /** '@Nick', 't.me/Nick', 'https://t.me/Nick' → 'Nick'. null — пусто, false — неверно. */
    public static function telegram(string $v): string|null|false
    {
        if ($v === '') {
            return null;
        }
        $v = preg_replace('~^(https?://)?(www\.)?(t\.me|telegram\.me)/~i', '', $v);
        $v = ltrim($v, '@');
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $v) === 1 ? $v : false;
    }

    /** Оставляем цифры и ведущий +; российские 8XXXXXXXXXX → +7XXXXXXXXXX. null — пусто, false — неверно. */
    public static function phone(string $v): string|null|false
    {
        if ($v === '') {
            return null;
        }
        if (preg_match('/[^\d\s()+\-.]/', $v)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7' . $digits;
        }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : false;
    }

    private static function clean(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        // Управляющие символы убираем, пробелы схлопываем.
        $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }
}
