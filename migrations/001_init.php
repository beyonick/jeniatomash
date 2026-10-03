<?php
declare(strict_types=1);

use Booking\Db;

/**
 * Начальная схема. Одна схема для MySQL и SQLite: различаются только
 * автоинкремент и суффикс таблицы. Индексы создаются отдельными запросами.
 */
return function (Db $db): void {
    $sqlite = $db->driver === 'sqlite';
    $id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $ref  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $sql = [
        // Настройки: имя → JSON. Сетка, сроки, горизонт записи.
        "CREATE TABLE settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",

        "CREATE TABLE products (
            id $id,
            code VARCHAR(32) NOT NULL,
            title VARCHAR(191) NOT NULL,
            duration_min INT NOT NULL,
            price INT NOT NULL,
            prepay INT NOT NULL,
            is_bookable INT NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            active INT NOT NULL DEFAULT 1
        )$tail",
        "CREATE UNIQUE INDEX products_code ON products (code)",

        "CREATE TABLE clients (
            id $id,
            name VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            telegram VARCHAR(64) NULL,
            phone VARCHAR(32) NULL,
            tg_chat_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",
        "CREATE UNIQUE INDEX clients_email ON clients (email)",

        "CREATE TABLE bookings (
            id $id,
            public_id VARCHAR(32) NOT NULL,
            client_id $ref NOT NULL,
            product_id $ref NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            requested_starts_at DATETIME NULL,
            status VARCHAR(20) NOT NULL,
            slot_lock DATETIME NULL,
            amount INT NOT NULL DEFAULT 0,
            prepay_amount INT NOT NULL DEFAULT 0,
            paid_amount INT NOT NULL DEFAULT 0,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'none',
            hold_until DATETIME NULL,
            paid_at DATETIME NULL,
            package_id $ref NULL,
            consent_at DATETIME NULL,
            consent_ip VARCHAR(45) NULL,
            consent_version VARCHAR(20) NULL,
            admin_note TEXT NULL,
            tg_message_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            FOREIGN KEY (client_id) REFERENCES clients (id),
            FOREIGN KEY (product_id) REFERENCES products (id)
        )$tail",
        // slot_lock = starts_at, пока заявка занимает слот, иначе NULL.
        // Уникальный индекс запрещает двойную запись на уровне БД; NULL не ограничиваются.
        "CREATE UNIQUE INDEX bookings_slot_lock ON bookings (slot_lock)",
        "CREATE UNIQUE INDEX bookings_public_id ON bookings (public_id)",
        "CREATE INDEX bookings_starts_at ON bookings (starts_at)",
        "CREATE INDEX bookings_status ON bookings (status)",
        "CREATE INDEX bookings_client ON bookings (client_id)",

        // Закрытые слоты и дни. start_time = '' — закрыт весь день.
        "CREATE TABLE closures (
            id $id,
            day VARCHAR(10) NOT NULL,
            start_time VARCHAR(5) NOT NULL DEFAULT '',
            note VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE UNIQUE INDEX closures_day_time ON closures (day, start_time)",

        "CREATE TABLE booking_events (
            id $id,
            booking_id $ref NOT NULL,
            type VARCHAR(40) NOT NULL,
            actor VARCHAR(20) NOT NULL,
            data TEXT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (booking_id) REFERENCES bookings (id)
        )$tail",
        "CREATE INDEX booking_events_booking ON booking_events (booking_id, type)",

        // В базе только sha256 токена; сам токен есть только в письме.
        "CREATE TABLE action_tokens (
            id $id,
            booking_id $ref NOT NULL,
            action VARCHAR(40) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (booking_id) REFERENCES bookings (id)
        )$tail",
        "CREATE UNIQUE INDEX action_tokens_hash ON action_tokens (token_hash)",
        "CREATE INDEX action_tokens_booking ON action_tokens (booking_id)",

        // Очередь уведомлений: Telegram и почта. Неотправленное повторяет cron.
        "CREATE TABLE outbox (
            id $id,
            channel VARCHAR(20) NOT NULL,
            recipient VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            meta TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            next_attempt_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL
        )$tail",
        "CREATE INDEX outbox_queue ON outbox (status, next_attempt_at)",

        "CREATE TABLE rate_hits (
            id $id,
            ip_hash CHAR(64) NOT NULL,
            action VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX rate_hits_lookup ON rate_hits (ip_hash, action, created_at)",
    ];
    foreach ($sql as $q) {
        $db->pdo->exec($q);
    }

    // Начальные настройки и продукты. Дальше меняются в базе, без правки кода.
    $now = date('Y-m-d H:i:s');
    $settings = [
        'slot_grid' => [
            ['09:00', '10:30'],
            ['11:00', '12:30'],
            ['15:00', '16:30'],
            ['16:45', '18:15'],
            ['18:30', '20:00'],
        ],
        'weekdays'                 => [1, 2, 3, 4, 5, 6, 7], // ISO: 1 — понедельник
        'book_min_hours'           => 12,  // запись не позже чем за N часов до начала
        'horizon_days'             => 30,  // на сколько дней вперёд открыта запись
        'hold_hours'               => 12,  // этап 2: удержание слота до оплаты
        'free_cancel_hours'        => 24,  // бесплатная отмена/перенос
        'pending_remind_hours'     => 3,   // напомнить Жене о неотвеченной заявке
        'unconfirmed_expire_hours' => 3,   // снять неподтверждённую заявку, если до начала меньше N часов
        'evening_summary_time'     => '20:00',
        'consent_version'          => 'draft-1',
    ];
    foreach ($settings as $name => $value) {
        $db->insert('settings', [
            'name'       => $name,
            'value'      => json_encode($value, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);
    }

    $products = [
        ['trial',    'Пробное занятие',          90, 1000,  1000,  1, 10],
        ['single',   'Разовое занятие',           90, 2000,  1000,  1, 20],
        // Курс на этапе 1 не бронируется через форму — ведёт к Жене напрямую.
        ['course10', 'Курс из 10 занятий',        90, 20000, 20000, 0, 30],
    ];
    foreach ($products as [$code, $title, $dur, $price, $prepay, $bookable, $sort]) {
        $db->insert('products', [
            'code'         => $code,
            'title'        => $title,
            'duration_min' => $dur,
            'price'        => $price,
            'prepay'       => $prepay,
            'is_bookable'  => $bookable,
            'sort'         => $sort,
            'active'       => 1,
        ]);
    }
};
