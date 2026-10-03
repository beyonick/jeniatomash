<?php
/**
 * Маленькая форма-кнопка для действия админки.
 * @var string $csrf
 * @var string $action
 * @var string $label
 * @var array  $fields  скрытые поля
 * @var string $back    куда вернуться
 * @var string|null $class
 * @var string|null $confirm текст подтверждения (через атрибут, без инлайн-JS)
 */
use function Booking\e;
?>
<form method="post" action="/admin/" class="inline-form">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <?php foreach ($fields as $k => $v): ?>
    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
  <?php endforeach ?>
  <button type="submit" name="action" value="<?= e($action) ?>" class="<?= e($class ?? 'chip') ?>"<?= !empty($confirm) ? ' data-confirm="' . e($confirm) . '"' : '' ?>><?= e($label) ?></button>
</form>
