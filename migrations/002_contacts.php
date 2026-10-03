<?php
declare(strict_types=1);

use Booking\Db;

/** Контакты и тексты, которые меняются без правки кода. */
return function (Db $db): void {
    $now = date('Y-m-d H:i:s');
    $settings = [
        'brand_name'       => 'Женя Томаш',
        'brand_tagline'    => 'звуковой цигун',
        // Куда писать про курс и по вопросам: ник Жени в Telegram (без @) и почта.
        'contact_telegram' => 'jeniatomash',
        'contact_email'    => 'kosenkovlg@gmail.com',
        // Строка в письме-подтверждении. Черновик — уточнить у Жени.
        'lesson_join_note' => 'Как подключиться к занятию, Женя напишет вам заранее.',
        // Срок хранения из политики: данные ученика удаляются через N лет после его последнего занятия.
        'retention_years'  => 3,
    ];
    foreach ($settings as $name => $value) {
        $db->insert('settings', [
            'name'       => $name,
            'value'      => json_encode($value, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);
    }
};
