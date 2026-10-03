<?php
/**
 * Песочница: чат Жени с ботом и письма. Только для локальной разработки.
 * @var array $messages
 * @var string|null $toast
 * @var array $mails
 */
use function Booking\e;

// Текст бота — Telegram-HTML, собранный нашим кодом: данные внутри уже экранированы.
$tgHtml = static fn(string $t): string => nl2br(strip_tags($t, '<b><i><code>'));
$linkify = static fn(string $t): string => preg_replace(
    '~(https?://[^\s<]+)~',
    '<a href="$1">$1</a>',
    nl2br(e($t))
);
?>
<link rel="stylesheet" href="/assets/css/dev.css?v=1">
<div class="dev">
  <p class="dev__note">Локальная песочница. Здесь видно то, что на хостинге уйдёт в Telegram Жени и на почту. Кнопки бота работают по-настоящему.
    <a href="/zapis/">Страница записи</a> · <a href="/admin/">Админка</a></p>

  <div class="dev__cols">
    <section class="tg" aria-label="Чат Жени с ботом">
      <header class="tg__head">
        <strong>Бот записи</strong>
        <span>так Женя видит его в Telegram</span>
      </header>
      <div class="tg__chat">
        <?php if (!$messages): ?>
          <p class="tg__empty">Пока пусто. Запишитесь на <a href="/zapis/">странице записи</a> — сюда придёт заявка с кнопками.</p>
        <?php endif ?>
        <?php foreach ($messages as $m): ?>
          <div class="tg__msg tg__msg--<?= e($m['from']) ?>">
            <div class="tg__bubble">
              <div class="tg__text"><?= $tgHtml($m['text']) ?></div>
              <div class="tg__time"><?= $m['edited'] ? 'изменено · ' : '' ?><?= e(substr($m['time'], 11, 5)) ?></div>
            </div>
            <?php foreach ($m['markup'] as $row): ?>
              <div class="tg__row">
                <?php foreach ($row as $btn): ?>
                  <form method="post" action="/dev/">
                    <input type="hidden" name="action" value="press">
                    <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="data" value="<?= e($btn['callback_data'] ?? '') ?>">
                    <button type="submit" class="tg__btn"><?= e($btn['text']) ?></button>
                  </form>
                <?php endforeach ?>
              </div>
            <?php endforeach ?>
          </div>
        <?php endforeach ?>
        <?php if ($toast): ?><div class="tg__toast" role="status"><?= e($toast) ?></div><?php endif ?>
        <span id="chat-end"></span>
      </div>
      <form class="tg__input" method="post" action="/dev/">
        <input type="hidden" name="action" value="say">
        <div class="tg__cmds">
          <?php foreach (['/days', '/pending', '/today', '/tomorrow'] as $c): ?>
            <button type="submit" name="text" value="<?= $c ?>" class="tg__cmd"><?= $c ?></button>
          <?php endforeach ?>
        </div>
      </form>
    </section>

    <section class="mails" aria-label="Письма">
      <div class="mails__head">
        <h2>Письма</h2>
        <form method="post" action="/dev/" class="mails__tools">
          <button type="submit" name="action" value="cron" class="chip chip--small">Запустить cron</button>
          <button type="submit" name="action" value="clear" class="chip chip--small">Очистить</button>
        </form>
      </div>
      <?php if (!$mails): ?><p class="tg__empty">Писем пока нет.</p><?php endif ?>
      <?php foreach ($mails as $mail): ?>
        <details class="mail"<?= $mail === $mails[0] ? ' open' : '' ?>>
          <summary>
            <span class="mail__to"><?= e($mail['to'] ?? '') ?></span>
            <span class="mail__subject"><?= e($mail['subject'] ?? '') ?></span>
            <span class="mail__time"><?= e(substr($mail['time'], 11, 5)) ?></span>
          </summary>
          <div class="mail__body"><?= $linkify($mail['body']) ?></div>
        </details>
      <?php endforeach ?>
    </section>
  </div>
</div>
