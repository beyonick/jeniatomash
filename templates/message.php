<?php
/**
 * Простая страница с сообщением.
 * @var string $heading
 * @var string $text
 * @var string|null $linkUrl
 * @var string|null $linkText
 */
use function Booking\e;
?>
<div class="message">
  <h1><?= e($heading) ?></h1>
  <p><?= e($text) ?></p>
  <?php if (!empty($linkUrl)): ?>
    <p><a class="button button--secondary" href="<?= e($linkUrl) ?>"><?= e($linkText ?? 'Продолжить') ?></a></p>
  <?php endif ?>
</div>
