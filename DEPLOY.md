# Выкладка модуля записи на Timeweb (тариф Optimo)

Порядок важен: сначала сайт и база, потом почта, потом бот. Пока бот не настроен, все события приходят Жене на почту, так что запись можно запускать и без него.

Команды ниже выполняются по SSH на хостинге. `php` на Timeweb может оказаться старой версии. Проверьте `php -v`, и если версия ниже 8.1, используйте полный путь к PHP 8 (обычно `/opt/php83/bin/php`, точный путь — в справке Timeweb). Дальше он обозначен как `php`.

## 1. Домен и сайт

1. Адрес сайта — `jenyatomash.nicktmsh.ru` (поддомен домена Никиты). A-запись уже указывает на Timeweb (92.53.96.201).
2. В панели: **Сайты → Создать сайт** для `jenyatomash.nicktmsh.ru`. Чтобы открывался и адрес с www, добавить DNS-запись `www.jenyatomash.nicktmsh.ru` (CNAME на `jenyatomash.nicktmsh.ru`) и алиас в настройках сайта. `.htaccess` перенаправит www на адрес без www.
3. Версия PHP сайта — 8.2 или 8.3.
4. Корневую директорию сайта указать на `…/booking/public`, а не на `booking/`. Иначе через браузер станут доступны служебные файлы. В `booking/` лежит страховочный `.htaccess`, но правильный корень всё равно нужен.

## 2. Код из git

```bash
cd ~/jenyatomash.nicktmsh.ru   # папка сайта, см. в панели
git clone <адрес репозитория> booking
```

Обновление потом:

```bash
cd ~/jenyatomash.nicktmsh.ru/booking && git pull && php bin/migrate.php
```

## 3. База MySQL

1. В панели: **Базы данных → Создать**. Записать имя базы, пользователя и пароль.
2. Сделать конфиг:

```bash
cd ~/jenyatomash.nicktmsh.ru/booking
cp config.sample.php config.php
```

3. Заполнить `config.php`:
   - `env` → `'prod'`
   - `base_url` → `'https://jenyatomash.nicktmsh.ru'`
   - `db` → `mysql:host=localhost;dbname=ИМЯ;charset=utf8mb4`, пользователь и пароль
   - `secret` и `ics_key` — случайные строки:
     ```bash
     php -r 'echo bin2hex(random_bytes(24)), "\n";'
     ```
   - `admin.password_hash` — хеш пароля админки для Жени:
     ```bash
     php bin/hash-password.php 'пароль'
     ```
4. Создать таблицы:

```bash
php bin/migrate.php
```

Должно напечатать «Применено: 001_init, 002_contacts». Схема писалась под MySQL и SQLite, но локально проверялась только на SQLite. Поэтому эта команда — первая проверка схемы на MySQL. Если она упадёт, пришлите текст ошибки.

## 4. HTTPS

1. В панели: **SSL-сертификаты → Let's Encrypt** для `jenyatomash.nicktmsh.ru` (и `www.jenyatomash.nicktmsh.ru`, если заведён).
2. Когда сертификат выпущен, раскомментировать три строки «HTTP → HTTPS» в `public/.htaccess`.
3. Проверить: `http://jenyatomash.nicktmsh.ru` и `http://www.jenyatomash.nicktmsh.ru` ведут на `https://jenyatomash.nicktmsh.ru/zapis/`.

## 5. Почта

1. В панели: **Почта → Создать ящик** `zapis@jenyatomash.nicktmsh.ru`. Сейчас у nicktmsh.ru нет MX-записей, то есть почта на домене не настроена. Если Timeweb не даст завести почту на поддомене, заведите ящик на `nicktmsh.ru` (например, `zapis@nicktmsh.ru`) и впишите его в `mail.from` в config.php.
2. Если DNS на Timeweb, SPF и DKIM обычно добавляются сами. Проверить в **DNS** для домена ящика: должны быть TXT-записи `v=spf1 …` и DKIM. Добавить DMARC: TXT `_dmarc` → `v=DMARC1; p=none`.
3. Тестовое письмо на Gmail Жени и на любой ящик Яндекса или Mail.ru:

```bash
php bin/mail-test.php kosenkovlg@gmail.com
```

Если письмо пришло в «Спам», дело в SPF/DKIM. Без них письма ученикам будут теряться.

## 6. Cron

В панели: **Планировщик cron** → раз в 5 минут:

```
*/5 * * * * /opt/php83/bin/php /home/ПУТЬ/jenyatomash.nicktmsh.ru/booking/cron/run.php
```

Cron снимает неподтверждённые заявки за 3 часа до начала, напоминает Жене о заявках без ответа, в 20:00 присылает сводку на завтра, после занятия спрашивает «проведено / не пришёл» и повторяет неотправленные уведомления. Его отчёт пишется в `var/cron.log`, но только когда что-то произошло.

## 7. Telegram-бот

1. Женя создаёт бота через @BotFather (`/newbot`) и передаёт токен.
2. В `config.php`:
   - `telegram.token` — токен;
   - `telegram.webhook_secret` — случайная строка из латиницы и цифр (команда из п. 3).
3. **Проверить, доходит ли Telegram с хостинга** (это открытый вопрос из ТЗ):

```bash
php bin/telegram.php check
```

   - «Telegram доступен» — переходите к п. 4.
   - Ошибка или таймаут — запросы к api.telegram.org с Timeweb не проходят. Нужен прокси: адрес прокси вписать в `telegram.api_base` и повторить проверку. Учтите, что прокси видит токен бота, поэтому подходит только свой (например, свой VPS), а не случайный публичный.
4. Подключить webhook:

```bash
php bin/telegram.php set-webhook
php bin/telegram.php info          # pending_update_count и last_error_message
```

5. Женя пишет боту `/start`. Бот ответит её `chat_id`; вписать его в `telegram.chat_id`. После этого бот отвечает только ей.
6. Если `info` показывает ошибки доставки (Telegram не может достучаться до сайта), есть запасной вариант без webhook:

```bash
php bin/telegram.php delete-webhook
```

   и в cron раз в минуту:

```
* * * * * /opt/php83/bin/php /home/ПУТЬ/jenyatomash.nicktmsh.ru/booking/bin/telegram.php poll-once
```

   Кнопки тогда срабатывают с задержкой до минуты.

## 8. Календарь на телефоне Жени

Админка → **Календарь и бот** → ссылка подписки. На iPhone: Настройки → Календарь → Учётные записи → Новая → Другое → Подписной календарь. В событиях только имя, формат и ник, без телефона и почты.

## 9. Проверка после выкладки

- [ ] `https://jenyatomash.nicktmsh.ru/zapis/` открывается с телефона, шрифт Inter (не системный).
- [ ] Тестовая запись: письмо ученику, дубль Жене, сообщение в бот с кнопками.
- [ ] «Подтвердить» в боте → письмо ученику «Занятие подтверждено».
- [ ] «Предложить другое время» → письмо со ссылками; ссылка открывает страницу с кнопкой.
- [ ] Админка `https://jenyatomash.nicktmsh.ru/admin/`: вход, неделя, заявка, закрытие слота.
- [ ] `https://jenyatomash.nicktmsh.ru/config.php`, `/../config.php`, `/src/App.php` отдают 403 или 404.
- [ ] Через 5 минут после записи в `var/cron.log` нет ошибок.
- [ ] Тестовые заявки удалить (или отклонить в админке).

## 10. До запуска для учеников

- [ ] Вписать ИНН в `templates/privacy.php` и `templates/consent.php` (ФИО, почта и сроки уже стоят). Отдать юристу и убрать плашку «Черновик».
- [ ] Подать уведомление в Роскомнадзор (pd.rkn.gov.ru, онлайн, бесплатно). Спросить юриста про трансграничную передачу: уведомления Жене идут в Telegram и на Gmail, хоть и с минимумом данных.
- [ ] Уточнить у Жени, как проходит занятие (Zoom, звонок в Telegram…), и поменять строку в письме-подтверждении:
  ```bash
  php bin/settings.php set lesson_join_note '"Ссылку на Zoom Женя пришлёт за час до занятия."'
  ```

## Настройки без правки кода

```bash
php bin/settings.php                      # всё, что сейчас настроено, и цены
php bin/settings.php price trial 1200     # цена продукта
php bin/settings.php set book_min_hours 12
php bin/settings.php set slot_grid '[["09:00","10:30"],["11:00","12:30"],["15:00","16:30"],["16:45","18:15"],["18:30","20:00"]]'
```

Уже сделанные заявки при смене сетки сохраняют своё время.
