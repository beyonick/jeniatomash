<?php
/** @var string|null $error */
use function Booking\e;
?>
<form class="login" method="post" action="/admin/">
  <h1>Вход</h1>
  <div class="field">
    <label for="password">Пароль</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
    <?php if ($error): ?><p class="field__error"><?= e($error) ?></p><?php endif ?>
  </div>
  <button class="button" type="submit" name="action" value="login">Войти</button>
</form>
