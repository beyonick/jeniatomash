<?php
/**
 * Общий макет публичных страниц.
 * @var string $title
 * @var string $content
 * @var string|null $script  путь к JS страницы
 * @var bool|null $noindex
 */
use function Booking\e;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Женя Томаш</title>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif ?>
<meta name="theme-color" content="#ffffff">
<link rel="preload" href="/assets/fonts/inter-cyrillic-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/base.css?v=1">
<?php if (!empty($script)): ?><script src="<?= e($script) ?>" defer></script><?php endif ?>
</head>
<body>
<header class="site-header">
  <a class="brand" href="/">
    <span class="brand__name">Женя Томаш</span>
    <span class="brand__tagline">звуковой цигун</span>
  </a>
</header>
<main class="site-main">
<?= $content ?>
</main>
<footer class="site-footer">
  <a href="/privacy.php">Политика обработки персональных данных</a>
  <a href="/consent.php">Согласие на обработку данных</a>
</footer>
</body>
</html>
