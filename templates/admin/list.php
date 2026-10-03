<?php
/**
 * Список заявок с контактами.
 * @var string $filter
 * @var array  $list
 */
use Booking\Fmt;
use Booking\Status;
use function Booking\e;

$filters = ['upcoming' => 'Предстоящие', 'pending' => 'Ждут ответа', 'past' => 'Прошедшие', 'all' => 'Все'];
?>
<div class="admin-toolbar">
  <h1>Заявки</h1>
  <div class="admin-toolbar__nav">
    <?php foreach ($filters as $key => $label): ?>
      <a class="chip<?= $filter === $key ? ' is-active' : '' ?>" href="/admin/?p=list&f=<?= $key ?>"><?= e($label) ?></a>
    <?php endforeach ?>
  </div>
</div>

<?php if (!$list): ?>
  <p class="empty">Заявок нет.</p>
<?php else: ?>
  <ul class="bookings">
    <?php foreach ($list as $b): ?>
      <li class="booking-row">
        <a class="booking-row__main" href="/admin/?p=booking&id=<?= (int)$b['id'] ?>">
          <span class="booking-row__when"><?= e(Fmt::dayShort($b['starts_at'])) ?>, <?= e(Fmt::time($b['starts_at'])) ?></span>
          <span class="booking-row__name"><?= e($b['client_name']) ?></span>
          <span class="booking-row__meta"><?= e($b['product_title']) ?></span>
        </a>
        <span class="badge status--<?= e($b['status']) ?>"><?= e(Status::LABELS[$b['status']]) ?></span>
        <span class="booking-row__contacts">
          <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a>
          <?php if ($b['telegram']): ?><a href="https://t.me/<?= e(rawurlencode($b['telegram'])) ?>">@<?= e($b['telegram']) ?></a><?php endif ?>
          <?php if ($b['phone']): ?><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a><?php endif ?>
        </span>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
