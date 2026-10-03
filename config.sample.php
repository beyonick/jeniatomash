<?php
// Образец конфига. Скопировать в config.php и заполнить. config.php в git не попадает.
// Цены, продукты, сетка слотов и сроки — не здесь, а в таблицах settings и products.

return [
    // local — письма пишутся в var/mail.log, Telegram — в var/telegram.log
    // prod  — настоящая отправка
    'env' => 'local',

    'base_url' => 'http://localhost:8000',

    // true — закрыть сайт от поисковиков (временный адрес на время разработки).
    'noindex' => true,

    'db' => [
        // Локально:
        'dsn'  => 'sqlite:' . __DIR__ . '/var/booking.sqlite',
        'user' => null,
        'pass' => null,
        // На Timeweb:
        // 'dsn'  => 'mysql:host=localhost;dbname=XXX;charset=utf8mb4',
        // 'user' => 'XXX',
        // 'pass' => 'XXX',
    ],

    'mail' => [
        'from'       => 'zapis@jenyatomash.nicktmsh.ru', // ящик на домене сайта (Timeweb)
        'from_name'  => 'Женя Томаш',
        'reply_to'   => 'kosenkovlg@gmail.com',
        'admin_copy' => 'kosenkovlg@gmail.com', // дубль всех событий для Жени
    ],

    'telegram' => [
        'api_base'       => 'https://api.telegram.org', // можно подставить адрес прокси
        'token'          => '',
        'chat_id'        => '',  // чат Жени; бот отвечает только ему
        'webhook_secret' => '',  // сверяется с заголовком X-Telegram-Bot-Api-Secret-Token
    ],

    'admin' => [
        // php bin/hash-password.php 'пароль'
        'password_hash' => '',
    ],

    // Случайная строка не короче 32 символов: хеширование IP для ограничения частоты.
    'secret' => '',

    // Секретная часть ссылки на подписку в календарь (.ics).
    'ics_key' => '',
];
