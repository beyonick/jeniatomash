<?php
/**
 * Карточка заявки: контакты, действия, журнал.
 * @var array $b
 * @var array $free   свободные слоты [день => [...]] — для «предложить другое время»
 * @var array $events
 * @var DateTimeImmutable $now
 * @var string $csrf
 */
use Booking\Fmt;
use Booking\Status;
use Booking\Time;
use Booking\View;
use function Booking\e;

$back = '/admin/?p=booking&id=' . (int)$b['id'];
$form = static fn(string $action, string $label, ?string $class = null, ?string $confirm = null) =>
    View::render('admin/_slot_form', ['csrf' => $csrf, 'action' => $action, 'label' => $label, 'fields' => ['id' => $b['id']], 'back' => $back, 'class' => $class, 'confirm' => $confirm]);
$started = Time::parse($b['starts_at']) <= $now;
$eventLabels = [
    'created' => 'Заявка создана', 'confirmed' => 'Подтверждена', 'proposed' => 'Предложено другое время',
    'proposal_accepted' => 'Ученик согласился на новое время', 'proposal_rejected' => 'Ученику не подошло новое время',
    'declined' => 'Отклонена', 'cancelled' => 'Отменена', 'expired' => 'Снята автоматически', 'completed' => 'Проведено',
    'no_show' => 'Не пришёл', 'admin_reminded' => 'Напоминание Жене', 'outcome_asked' => 'Вопрос «как прошло?» в бот',
];
$actors = ['client' => 'ученик', 'admin' => 'админка', 'bot' => 'бот', 'system' => 'автоматически'];
?>
<p class="back-link"><a href="/admin/?p=week&start=<?= e(substr($b['starts_at'], 0, 10)) ?>">← К неделе</a></p>

<div class="card">
  <div class="card__head">
    <h1><?= e($b['client_name']) ?></h1>
    <span class="badge status--<?= e($b['status']) ?>"><?= e(Status::LABELS[$b['status']]) ?></span>
  </div>
  <dl class="props">
    <dt>Когда</dt><dd><?= e(Fmt::slot($b['starts_at'], $b['ends_at'])) ?></dd>
    <?php if ($b['requested_starts_at'] && $b['requested_starts_at'] !== $b['starts_at']): ?>
      <dt>Изначально</dt><dd><?= e(Fmt::dayLong($b['requested_starts_at'])) ?>, <?= e(Fmt::time($b['requested_starts_at'])) ?></dd>
    <?php endif ?>
    <dt>Формат</dt><dd><?= e($b['product_title']) ?> · <?= e(Fmt::money((int)$b['amount'])) ?></dd>
    <dt>Почта</dt><dd><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></dd>
    <dt>Telegram</dt><dd><?= $b['telegram'] ? '<a href="https://t.me/' . e(rawurlencode($b['telegram'])) . '">@' . e($b['telegram']) . '</a>' : '—' ?></dd>
    <dt>Телефон</dt><dd><?= $b['phone'] ? '<a href="tel:' . e($b['phone']) . '">' . e($b['phone']) . '</a>' : '—' ?></dd>
    <dt>Заявка</dt><dd><?= e(Fmt::date($b['created_at'])) ?>, <?= e(Fmt::time($b['created_at'])) ?></dd>
  </dl>

  <div class="actions">
    <?php if ($b['status'] === Status::PENDING): ?>
      <?= $form('confirm', 'Подтвердить', 'button') ?>
      <?= $form('decline', 'Отклонить', 'button button--secondary', 'Отклонить заявку? Ученику уйдёт письмо.') ?>
    <?php elseif ($b['status'] === Status::PROPOSED): ?>
      <?= $form('decline', 'Отклонить', 'button button--secondary', 'Отклонить заявку? Ученику уйдёт письмо.') ?>
    <?php elseif ($b['status'] === Status::CONFIRMED && !$started): ?>
      <?= $form('cancel', 'Отменить занятие', 'button button--secondary', 'Отменить занятие? Ученику уйдёт письмо.') ?>
    <?php elseif ($b['status'] === Status::CONFIRMED && $started): ?>
      <?= $form('completed', 'Проведено', 'button') ?>
      <?= $form('no_show', 'Не пришёл', 'button button--secondary') ?>
    <?php elseif ($b['status'] === Status::COMPLETED): ?>
      <?= $form('no_show', 'Исправить: не пришёл', 'button button--secondary') ?>
    <?php elseif ($b['status'] === Status::NO_SHOW): ?>
      <?= $form('completed', 'Исправить: проведено', 'button button--secondary') ?>
    <?php endif ?>
  </div>

  <?php if ($free): ?>
    <form class="propose" method="post" action="/admin/">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <label for="propose-slot">Предложить другое время</label>
      <div class="propose__row">
        <select id="propose-slot" name="starts_at" required>
          <option value="">Выберите время</option>
          <?php foreach ($free as $day => $slots): ?>
            <optgroup label="<?= e(Fmt::dayLong($day)) ?>">
              <?php foreach ($slots as $s): ?>
                <option value="<?= e($s['starts_at']) ?>"><?= e($s['time']) ?>–<?= e($s['end']) ?></option>
              <?php endforeach ?>
            </optgroup>
          <?php endforeach ?>
        </select>
        <button class="button button--secondary" type="submit" name="action" value="propose">Предложить</button>
      </div>
      <p class="field__hint">Ученику уйдёт письмо со ссылками «подходит / не подходит». Новое время сразу занимается.</p>
    </form>
  <?php endif ?>
</div>

<form class="card note" method="post" action="/admin/">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
  <label for="note">Заметка (видна только вам)</label>
  <textarea id="note" name="note" rows="3" maxlength="2000"><?= e($b['admin_note']) ?></textarea>
  <button class="chip" type="submit" name="action" value="note">Сохранить заметку</button>
</form>

<section class="card">
  <h2>История</h2>
  <ol class="events">
    <?php foreach ($events as $ev): ?>
      <li>
        <span class="events__when"><?= e(Fmt::date($ev['created_at'])) ?>, <?= e(Fmt::time($ev['created_at'])) ?></span>
        <?= e($eventLabels[$ev['type']] ?? $ev['type']) ?>
        <span class="events__actor">· <?= e($actors[$ev['actor']] ?? $ev['actor']) ?></span>
      </li>
    <?php endforeach ?>
  </ol>
</section>
