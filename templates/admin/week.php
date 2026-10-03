<?php
/**
 * Неделя: слоты по дням, занятые — со ссылкой на заявку.
 * @var DateTimeImmutable $monday
 * @var array  $days  [Y-m-d => ['slots' => [...], 'closed' => bool]]
 * @var DateTimeImmutable $now
 * @var string $csrf
 */
use Booking\Fmt;
use Booking\Slots;
use Booking\Status;
use Booking\View;
use function Booking\e;

$back = '/admin/?p=week&start=' . $monday->format('Y-m-d');
$sunday = $monday->modify('+6 days');
$today = $now->format('Y-m-d');
$form = static fn(string $action, string $label, array $fields, ?string $class = null) =>
    View::render('admin/_slot_form', ['csrf' => $csrf, 'action' => $action, 'label' => $label, 'fields' => $fields, 'back' => $back, 'class' => $class]);
?>
<div class="admin-toolbar">
  <h1><?= e(Fmt::date($monday->format('Y-m-d'))) ?> — <?= e(Fmt::date($sunday->format('Y-m-d'))) ?></h1>
  <div class="admin-toolbar__nav">
    <a class="chip" href="/admin/?p=week&start=<?= $monday->modify('-7 days')->format('Y-m-d') ?>" aria-label="Предыдущая неделя">←</a>
    <a class="chip" href="/admin/?p=week">Сегодня</a>
    <a class="chip" href="/admin/?p=week&start=<?= $monday->modify('+7 days')->format('Y-m-d') ?>" aria-label="Следующая неделя">→</a>
  </div>
</div>

<div class="week">
  <?php foreach ($days as $day => $info): ?>
    <section class="week-day<?= $day === $today ? ' is-today' : '' ?><?= $info['closed'] ? ' is-closed' : '' ?>">
      <header class="week-day__head">
        <h2><?= e(Fmt::dayShort($day)) ?></h2>
        <?php if ($day >= $today && $info['slots']): ?>
          <?= $info['closed']
              ? $form('open_day', 'Открыть день', ['day' => $day], 'chip chip--small')
              : $form('close_day', 'Закрыть день', ['day' => $day], 'chip chip--small') ?>
        <?php endif ?>
      </header>
      <?php if ($info['closed']): ?><p class="week-day__note">День закрыт для записи</p><?php endif ?>
      <ul class="slots">
        <?php foreach ($info['slots'] as $s): ?>
          <li class="slot slot--<?= e($s['state']) ?>">
            <span class="slot__time"><?= e($s['time']) ?>–<?= e($s['end_time']) ?></span>
            <?php if ($s['state'] === Slots::BOOKED && $s['booking']): $b = $s['booking']; ?>
              <a class="slot__booking status--<?= e($b['status']) ?>" href="/admin/?p=booking&id=<?= (int)$b['id'] ?>">
                <span class="slot__name"><?= e($b['client_name']) ?></span>
                <span class="slot__meta"><?= e($b['product_title']) ?> · <?= e(mb_strtolower(Status::LABELS[$b['status']])) ?></span>
              </a>
            <?php elseif ($s['state'] === Slots::CLOSED): ?>
              <span class="slot__label">закрыто</span>
              <?php if (!$info['closed'] && $day >= $today): ?>
                <?= $form('open_slot', 'Открыть', ['day' => $day, 'time' => $s['time']], 'chip chip--small') ?>
              <?php endif ?>
            <?php elseif ($s['state'] === Slots::FREE): ?>
              <span class="slot__label">свободно</span>
              <?= $form('close_slot', 'Закрыть', ['day' => $day, 'time' => $s['time']], 'chip chip--small') ?>
            <?php else: ?>
              <span class="slot__label">—</span>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
      <?php if (!$info['slots']): ?><p class="week-day__note">По сетке занятий нет</p><?php endif ?>
    </section>
  <?php endforeach ?>
</div>
