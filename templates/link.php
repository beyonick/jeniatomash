<?php
/**
 * Вопрос перед действием по ссылке из письма.
 * @var array  $booking
 * @var string $action
 * @var string $token
 */
use Booking\Bookings;
use Booking\Fmt;
use function Booking\e;

$accept = $action === Bookings::TOKEN_ACCEPT;
?>
<div class="message">
  <h1><?= $accept ? 'Подтвердить новое время?' : 'Отказаться от предложенного времени?' ?></h1>
  <p class="slot-card">
    <span class="slot-card__when"><?= e(Fmt::slot($booking['starts_at'], $booking['ends_at'])) ?></span>
    <span class="slot-card__what"><?= e($booking['product_title']) ?> · время московское</span>
  </p>
  <p><?= $accept
      ? 'Нажмите кнопку, и занятие будет подтверждено.'
      : 'Заявка будет отменена, а время освободится. Потом можно выбрать другое время.' ?></p>
  <form method="post" action="/zapis/link.php">
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <button class="button<?= $accept ? '' : ' button--secondary' ?>" type="submit"><?= $accept ? 'Да, время подходит' : 'Да, отменить заявку' ?></button>
  </form>
</div>
