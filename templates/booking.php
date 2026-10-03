<?php
/**
 * Страница записи. Работает на JS (booking.js): данные о слотах — в JSON ниже.
 * @var array  $products
 * @var array  $calendar  Slots::freeByDay()
 * @var string $stamp     метка FormGuard
 * @var string $contactUrl ссылка «написать Жене»
 * @var string $contactLabel
 */
use Booking\Fmt;
use Booking\FormGuard;
use function Booking\e;

$json = json_encode(
    ['products' => array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title']], $products), 'calendar' => $calendar],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$hours = static function (int $min): string {
    $h = $min / 60;
    return ($h == (int)$h ? (string)(int)$h : str_replace('.', ',', (string)$h)) . "\u{00A0}" . ($h == 1 ? 'час' : 'часа');
};
?>
<div class="booking" id="booking">
  <div class="booking__intro">
    <h1>Запись на занятие</h1>
    <p class="lead">Занятия проходят онлайн. Выберите формат, день и время — Женя подтвердит запись письмом.</p>
  </div>

  <noscript>
    <p class="notice">Для записи нужен включённый JavaScript. Или напишите Жене: <a href="<?= e($contactUrl) ?>"><?= e($contactLabel) ?></a>.</p>
  </noscript>

  <form class="booking__form" id="booking-form" method="post" action="/api/book.php" novalidate>
    <input type="hidden" name="<?= FormGuard::STAMP ?>" value="<?= e($stamp) ?>">
    <input type="hidden" name="starts_at" value="">
    <div class="hp" aria-hidden="true">
      <label>Сайт <input type="text" name="<?= FormGuard::HONEYPOT ?>" tabindex="-1" autocomplete="off"></label>
    </div>

    <section class="step" data-step="product" aria-labelledby="step-product">
      <h2 class="step__title" id="step-product"><span class="step__num">1</span>Формат</h2>
      <div class="products" role="radiogroup" aria-labelledby="step-product">
        <?php foreach ($products as $p): ?>
          <?php if ($p['is_bookable']): ?>
            <label class="product">
              <input type="radio" name="product_id" value="<?= (int)$p['id'] ?>" required>
              <span class="product__title"><?= e($p['title']) ?></span>
              <span class="product__meta"><?= e($hours((int)$p['duration_min'])) ?></span>
              <span class="product__price"><?= e(Fmt::money((int)$p['price'])) ?></span>
            </label>
          <?php else: ?>
            <div class="product product--info">
              <span class="product__title"><?= e($p['title']) ?></span>
              <span class="product__meta">Чтобы записаться на курс, <a href="<?= e($contactUrl) ?>">напишите Жене</a></span>
              <span class="product__price"><?= e(Fmt::money((int)$p['price'])) ?></span>
            </div>
          <?php endif ?>
        <?php endforeach ?>
      </div>
    </section>

    <div class="pick">
      <section class="step" data-step="day" aria-labelledby="step-day">
        <h2 class="step__title" id="step-day"><span class="step__num">2</span>День</h2>
        <div class="calendar" id="calendar"></div>
      </section>

      <section class="step" data-step="time" aria-labelledby="step-time">
        <h2 class="step__title" id="step-time"><span class="step__num">3</span>Время</h2>
        <p class="hint">Время московское.</p>
        <div class="times" id="times" role="radiogroup" aria-labelledby="step-time">
          <p class="placeholder">Сначала выберите день.</p>
        </div>
        <p class="notice notice--error" id="times-error" hidden></p>
      </section>
    </div>

    <section class="step" data-step="contacts" aria-labelledby="step-contacts">
      <h2 class="step__title" id="step-contacts"><span class="step__num">4</span>Ваши данные</h2>

      <div class="fields">
        <div class="field">
          <label for="f-name">Имя</label>
          <input id="f-name" name="name" type="text" autocomplete="name" maxlength="100" required>
          <p class="field__error" data-error="name"></p>
        </div>
        <div class="field">
          <label for="f-email">Почта</label>
          <input id="f-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="191" required aria-describedby="f-email-hint">
          <p class="field__hint" id="f-email-hint">Сюда придёт подтверждение.</p>
          <p class="field__error" data-error="email"></p>
        </div>
        <div class="field">
          <label for="f-telegram">Ник в Telegram</label>
          <input id="f-telegram" name="telegram" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="@nickname" maxlength="64" aria-describedby="f-telegram-hint">
          <p class="field__hint" id="f-telegram-hint">Если Telegram нет — укажите телефон.</p>
          <p class="field__error" data-error="telegram"></p>
        </div>
        <div class="field">
          <label for="f-phone">Телефон</label>
          <input id="f-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="32">
          <p class="field__error" data-error="phone"></p>
        </div>
      </div>

      <div class="field field--check">
        <label class="check">
          <input type="checkbox" name="consent" value="1" required>
          <span>Даю <a href="/consent.php" target="_blank">согласие на обработку персональных данных</a> в соответствии с <a href="/privacy.php" target="_blank">политикой</a>.</span>
        </label>
        <p class="field__error" data-error="consent"></p>
      </div>

      <div class="summary" id="summary" hidden></div>

      <p class="notice notice--error" id="form-error" hidden></p>
      <button class="button" type="submit" id="submit">Отправить заявку</button>
    </section>
  </form>

  <section class="done" id="done" hidden tabindex="-1">
    <h2>Заявка отправлена</h2>
    <p id="done-text"></p>
    <p>Если письма нет, проверьте папку «Спам».</p>
    <p><a href="/zapis/">Записаться ещё на одно занятие</a></p>
  </section>
</div>
<script type="application/json" id="booking-data"><?= $json ?></script>
