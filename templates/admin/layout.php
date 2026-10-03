<?php
/**
 * Макет админки.
 * @var string $title
 * @var string $content
 * @var string|null $page
 * @var string|null $csrf
 * @var array|null $flash [тип, текст]
 */
use function Booking\e;

$nav = ['week' => 'Неделя', 'list' => 'Заявки', 'info' => 'Календарь и бот'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — запись, админка</title>
<link rel="stylesheet" href="/assets/css/base.css?v=1">
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
<script src="/assets/js/admin.js?v=1" defer></script>
</head>
<body class="admin">
<header class="admin-header">
  <a class="brand" href="/admin/"><span class="brand__name">Запись</span><span class="brand__tagline">админка</span></a>
  <?php if (!empty($csrf)): ?>
    <nav class="admin-nav">
      <?php foreach ($nav as $key => $label): ?>
        <a href="/admin/?p=<?= $key ?>"<?= ($page ?? '') === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach ?>
      <form method="post" action="/admin/" class="admin-nav__logout">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" name="action" value="logout" class="link-button">Выйти</button>
      </form>
    </nav>
  <?php endif ?>
</header>
<main class="admin-main">
  <?php if (!empty($flash)): ?>
    <p class="flash flash--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p>
  <?php endif ?>
  <?= $content ?>
</main>
</body>
</html>
