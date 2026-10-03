<?php
/**
 * Подписка на календарь, состояние бота, неотправленные уведомления.
 * @var string|null $icsUrl
 * @var array $problems
 * @var bool  $tgOn
 */
use Booking\Fmt;
use function Booking\e;
?>
<h1>Календарь и бот</h1>

<section class="card">
  <h2>Подписка на календарь</h2>
  <?php if ($icsUrl): ?>
    <p>Добавьте эту ссылку в календарь телефона как подписку. На iPhone: Настройки → Календарь → Учётные записи → Новая → Другое → Подписной календарь. Календарь обновляется сам, обычно раз в 15–60 минут.</p>
    <p class="mono"><input class="copy" type="text" readonly value="<?= e($icsUrl) ?>" aria-label="Ссылка на календарь"></p>
    <p class="field__hint">Ссылка секретная: по ней видны имена учеников. Если она попала не туда, поменяйте ics_key в config.php.</p>
  <?php else: ?>
    <p>Не задан ics_key в config.php (нужно не короче 16 символов).</p>
  <?php endif ?>
</section>

<section class="card">
  <h2>Telegram-бот</h2>
  <p><?= $tgOn ? 'Подключён.' : 'Не настроен: нужны токен бота и chat_id в config.php. Пока бот не подключён, все события приходят на почту.' ?></p>
  <p>Команды бота: /days — расписание, /pending — заявки без ответа, /today и /tomorrow — занятия.</p>
</section>

<section class="card">
  <h2>Неотправленные уведомления</h2>
  <?php if (!$problems): ?>
    <p>Всё отправлено.</p>
  <?php else: ?>
    <p class="field__hint">Сайт повторяет отправку сам. Если сообщения в Telegram копятся, возможно, Telegram недоступен с хостинга — события всё равно уходят на почту.</p>
    <ul class="problems">
      <?php foreach ($problems as $m): ?>
        <li>
          <strong><?= $m['channel'] === 'telegram' ? 'Telegram' : 'Письмо' ?></strong>
          · <?= e(Fmt::date($m['created_at'])) ?>, <?= e(Fmt::time($m['created_at'])) ?>
          · попыток: <?= (int)$m['attempts'] ?><?= $m['status'] === 'failed' ? ' · больше не повторяется' : '' ?>
          <br><span class="field__hint"><?= e($m['last_error']) ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>
