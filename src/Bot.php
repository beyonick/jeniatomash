<?php
declare(strict_types=1);

namespace Booking;

use DateTimeImmutable;

/**
 * Бот для Жени. Отвечает только чату из конфига.
 *
 * Кнопки (callback_data, до 64 байт):
 *   c:ID   подтвердить           d:ID / D:ID  отклонить (с подтверждением)
 *   p:ID   другое время: дни     pd:ID:Ymd    другое время: часы
 *   pt:ID:YmdHi  предложить      x:ID / X:ID  отменить занятие (с подтверждением)
 *   ok:ID  проведено             ns:ID        не пришёл
 *   b:ID   карточка заявки       s:ID         карточка новым сообщением
 *   vd:N   список дней с N       v:Ymd        день
 *   t:Ymd:Hi  закрыть/открыть слот   cd:Ymd / od:Ymd  закрыть/открыть день
 */
final class Bot
{
    private const DAYS_PAGE = 14;

    public function __construct(private App $app) {}

    public function handle(array $update, DateTimeImmutable $now): void
    {
        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query'], $now);
        } elseif (isset($update['message'])) {
            $this->onMessage($update['message'], $now);
        }
    }

    // --- Клавиатуры ---

    /** Кнопки карточки заявки по её статусу. */
    public static function bookingKeyboard(array $b, DateTimeImmutable $now): array
    {
        $id = $b['id'];
        $started = Time::parse($b['starts_at']) <= $now;
        $rows = match ($b['status']) {
            Status::PENDING => [
                [self::btn('Подтвердить', "c:$id"), self::btn('Отклонить', "d:$id")],
                [self::btn('Предложить другое время', "p:$id")],
            ],
            Status::PROPOSED => [
                [self::btn('Другое время', "p:$id"), self::btn('Отклонить', "d:$id")],
            ],
            Status::CONFIRMED => $started
                ? [[self::btn('Проведено', "ok:$id"), self::btn('Не пришёл', "ns:$id")]]
                : [[self::btn('Отменить занятие', "x:$id")]],
            Status::COMPLETED => [[self::btn('Исправить: не пришёл', "ns:$id")]],
            Status::NO_SHOW   => [[self::btn('Исправить: проведено', "ok:$id")]],
            default           => [],
        };
        return ['inline_keyboard' => $rows];
    }

    private static function btn(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }

    // --- Сообщения ---

    private function onMessage(array $msg, DateTimeImmutable $now): void
    {
        $chatId = (string)($msg['chat']['id'] ?? '');
        $text = trim((string)($msg['text'] ?? ''));
        $admin = $this->app->telegram->adminChatId();

        if ($admin === '') {
            // Первичная настройка: подсказать chat_id, больше ничего не делать.
            if (str_starts_with($text, '/start')) {
                $this->send($chatId, "Бот ещё не настроен.\nВаш chat_id: <code>" . e($chatId) . "</code>\nВпишите его в config.php → telegram.chat_id.");
            }
            return;
        }
        if ($chatId !== $admin) {
            return;
        }

        $cmd = strtolower(explode(' ', explode('@', $text)[0])[0]);
        match ($cmd) {
            '/days', '/schedule' => $this->send($admin, ...$this->daysView(0, $now)),
            '/pending'           => $this->sendPending($now),
            '/tomorrow'          => $this->send($admin, $this->daySummary($now->modify('+1 day')->format('Y-m-d'), $now, 'Завтра')),
            '/today'             => $this->send($admin, $this->daySummary($now->format('Y-m-d'), $now, 'Сегодня')),
            default              => $this->send($admin, $this->help()),
        };
    }

    private function help(): string
    {
        return "<b>Запись на занятия</b>\n\n"
            . "/days — расписание: закрыть или открыть слот или день\n"
            . "/pending — заявки, которые ждут ответа\n"
            . "/today, /tomorrow — занятия на сегодня и завтра\n\n"
            . 'Новые заявки приходят сюда сами, с кнопками.';
    }

    private function sendPending(DateTimeImmutable $now): void
    {
        $list = $this->app->bookings->between(Time::fmt($now->setTime(0, 0)), '9999-12-31 00:00:00', [Status::PENDING, Status::PROPOSED]);
        if (!$list) {
            $this->send($this->app->telegram->adminChatId(), 'Неотвеченных заявок нет.');
            return;
        }
        foreach ($list as $b) {
            $this->sendCard($b, Status::LABELS[$b['status']], $now);
        }
    }

    // --- Кнопки ---

    private function onCallback(array $cb, DateTimeImmutable $now): void
    {
        $admin = $this->app->telegram->adminChatId();
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $fromId = (string)($cb['from']['id'] ?? '');
        if ($admin === '' || ($chatId !== $admin && $fromId !== $admin)) {
            return;
        }
        $messageId = (int)($cb['message']['message_id'] ?? 0);
        $parts = explode(':', (string)($cb['data'] ?? ''));
        $action = $parts[0];
        $notice = null;

        try {
            [$text, $markup] = $this->dispatch($action, $parts, $now, $notice);
            if ($text !== null) {
                $this->edit($admin, $messageId, $text, $markup);
            }
        } catch (SlotUnavailable|StateError $e) {
            $notice = $e->getMessage();
            // Карточка могла устареть (например, заявку уже обработали в админке) — обновить.
            if (isset($parts[1]) && ctype_digit($parts[1]) && !in_array($action, ['v', 'vd', 't', 'cd', 'od'], true)) {
                $b = $this->app->bookings->get((int)$parts[1]);
                if ($b !== null) {
                    $this->edit($admin, $messageId, $this->app->texts->adminCard($b, Status::LABELS[$b['status']]), self::bookingKeyboard($b, $now));
                }
            }
        }

        $this->app->telegram->tryCall('answerCallbackQuery', array_filter([
            'callback_query_id' => $cb['id'] ?? '',
            'text'              => $notice,
            'show_alert'        => $notice !== null,
        ]));
    }

    /** @return array{0:?string,1:?array} новый текст и клавиатура сообщения */
    private function dispatch(string $action, array $p, DateTimeImmutable $now, ?string &$notice): array
    {
        $bk = $this->app->bookings;
        $id = isset($p[1]) && ctype_digit($p[1]) ? (int)$p[1] : 0;

        switch ($action) {
            case 'c':
                return $this->card($bk->confirm($id, $now, 'bot'), 'Заявка подтверждена', $now);
            case 'd':
                return $this->confirmStep($id, 'Отклонить заявку? Ученику уйдёт письмо.', 'Да, отклонить', "D:$id");
            case 'D':
                return $this->card($bk->decline($id, $now, 'bot'), 'Заявка отклонена', $now);
            case 'x':
                return $this->confirmStep($id, 'Отменить занятие? Ученику уйдёт письмо.', 'Да, отменить', "X:$id");
            case 'X':
                return $this->card($bk->cancel($id, $now, 'bot'), 'Занятие отменено', $now);
            case 'ok':
                return $this->card($bk->markCompleted($id, $now, 'bot'), 'Проведено', $now);
            case 'ns':
                return $this->card($bk->markNoShow($id, $now, 'bot'), 'Не пришёл', $now);
            case 'b':
                $b = $this->mustGet($id);
                return $this->card($b, Status::LABELS[$b['status']], $now);
            case 's':
                $b = $this->mustGet($id);
                $this->sendCard($b, Status::LABELS[$b['status']], $now);
                return [null, null];
            case 'p':
                return $this->proposeDays($this->mustGet($id), $now);
            case 'pd':
                return $this->proposeTimes($this->mustGet($id), self::day($p[2] ?? ''), $now);
            case 'pt':
                $at = self::dateTime($p[2] ?? '');
                $b = $bk->propose($id, $at, $now, 'bot');
                $notice = 'Ученику ушло письмо с предложением.';
                return $this->card($b, 'Предложено другое время, ждём ответа ученика', $now);
            case 'vd':
                return $this->daysView(max(0, (int)($p[1] ?? 0)), $now);
            case 'v':
                return $this->dayView(self::day($p[1] ?? ''), $now);
            case 't':
                $day = self::day($p[1] ?? '');
                $time = self::hm($p[2] ?? '');
                $slot = array_values(array_filter($this->app->slots->dayStatus($day, $now), fn($s) => $s['time'] === $time))[0] ?? null;
                if ($slot === null || $slot['state'] === Slots::BOOKED) {
                    throw new StateError('Этот слот нельзя переключить.');
                }
                $slot['state'] === Slots::CLOSED ? $this->app->slots->open($day, $time) : $this->app->slots->close($day, $time);
                return $this->dayView($day, $now);
            case 'cd':
                $this->app->slots->close(self::day($p[1] ?? ''));
                return $this->dayView(self::day($p[1] ?? ''), $now);
            case 'od':
                $this->app->slots->open(self::day($p[1] ?? ''));
                return $this->dayView(self::day($p[1] ?? ''), $now);
        }
        throw new StateError('Неизвестная кнопка.');
    }

    private function card(array $b, string $title, DateTimeImmutable $now): array
    {
        return [$this->app->texts->adminCard($b, $title), self::bookingKeyboard($b, $now)];
    }

    private function confirmStep(int $id, string $question, string $yes, string $data): array
    {
        $b = $this->mustGet($id);
        return [
            $this->app->texts->adminCard($b, $question),
            ['inline_keyboard' => [[self::btn($yes, $data), self::btn('Назад', "b:$id")]]],
        ];
    }

    private function proposeDays(array $b, DateTimeImmutable $now): array
    {
        if (!in_array($b['status'], [Status::PENDING, Status::PROPOSED], true)) {
            throw new StateError('Заявка уже в другом статусе.');
        }
        $days = array_slice($this->app->slots->availableDays($now), 0, 21);
        $rows = [];
        foreach (array_chunk($days, 3) as $chunk) {
            $rows[] = array_map(fn($d) => self::btn(Fmt::dayShort($d), "pd:{$b['id']}:" . str_replace('-', '', $d)), $chunk);
        }
        $rows[] = [self::btn('Назад', "b:{$b['id']}")];
        $text = $this->app->texts->adminCard($b, 'Другое время: выберите день');
        if (!$days) {
            $text .= "\n\nСвободных слотов нет.";
        }
        return [$text, ['inline_keyboard' => $rows]];
    }

    private function proposeTimes(array $b, string $day, DateTimeImmutable $now): array
    {
        $slots = $this->app->slots->freeSlots($day, $now);
        $buttons = array_map(
            fn($s) => self::btn($s['time'] . '–' . $s['end_time'], "pt:{$b['id']}:" . str_replace(['-', ' ', ':'], '', substr($s['starts_at'], 0, 16))),
            $slots
        );
        $rows = array_chunk($buttons, 2);
        $rows[] = [self::btn('К дням', "p:{$b['id']}")];
        $text = $this->app->texts->adminCard($b, 'Другое время: ' . Fmt::dayShort($day));
        if (!$slots) {
            $text .= "\n\nВ этот день свободных слотов уже нет.";
        }
        return [$text, ['inline_keyboard' => $rows]];
    }

    /** Список дней для расписания. */
    private function daysView(int $offset, DateTimeImmutable $now): array
    {
        $horizon = $this->app->settings->int('horizon_days');
        $rows = [];
        $buttons = [];
        for ($i = $offset; $i < min($offset + self::DAYS_PAGE, $horizon + 1); $i++) {
            $day = $now->modify("+$i day")->format('Y-m-d');
            $mark = $this->app->slots->isDayClosed($day) ? ' ✕' : '';
            $buttons[] = self::btn(Fmt::dayShort($day) . $mark, 'v:' . str_replace('-', '', $day));
        }
        foreach (array_chunk($buttons, 2) as $chunk) {
            $rows[] = $chunk;
        }
        $nav = [];
        if ($offset > 0) {
            $nav[] = self::btn('← Раньше', 'vd:' . max(0, $offset - self::DAYS_PAGE));
        }
        if ($offset + self::DAYS_PAGE <= $horizon) {
            $nav[] = self::btn('Дальше →', 'vd:' . ($offset + self::DAYS_PAGE));
        }
        if ($nav) {
            $rows[] = $nav;
        }
        return ["<b>Расписание</b>\nВыберите день. ✕ — день закрыт.", ['inline_keyboard' => $rows]];
    }

    /** День: слоты с состоянием и кнопки закрыть/открыть. */
    private function dayView(string $day, DateTimeImmutable $now): array
    {
        $slots = $this->app->slots->dayStatus($day, $now);
        $dayClosed = $this->app->slots->isDayClosed($day);
        $lines = ['<b>' . e(Fmt::dayShort($day)) . '</b>' . ($dayClosed ? ' — день закрыт' : ''), ''];
        $rows = [];
        $hasBookings = false;
        foreach ($slots as $s) {
            $label = $s['time'] . '–' . $s['end_time'];
            switch ($s['state']) {
                case Slots::BOOKED:
                    $hasBookings = true;
                    $b = $this->app->bookings->get($s['booking_id']);
                    $lines[] = e($label) . ' — ' . e($b['client_name']) . ', ' . e(mb_strtolower(Status::LABELS[$b['status']]));
                    $rows[] = [self::btn($s['time'] . ' ' . $b['client_name'], "s:{$b['id']}")];
                    break;
                case Slots::CLOSED:
                    $lines[] = e($label) . ' — закрыто';
                    if (!$dayClosed) {
                        $rows[] = [self::btn('Открыть ' . $s['time'], 't:' . str_replace('-', '', $day) . ':' . str_replace(':', '', $s['time']))];
                    }
                    break;
                case Slots::FREE:
                    $lines[] = e($label) . ' — свободно';
                    $rows[] = [self::btn('Закрыть ' . $s['time'], 't:' . str_replace('-', '', $day) . ':' . str_replace(':', '', $s['time']))];
                    break;
                default:
                    $lines[] = e($label) . ' — запись уже закрыта';
            }
        }
        if (!$slots) {
            $lines[] = 'По сетке в этот день занятий нет.';
        }
        $ymd = str_replace('-', '', $day);
        $rows[] = [$dayClosed ? self::btn('Открыть весь день', "od:$ymd") : self::btn('Закрыть весь день', "cd:$ymd")];
        $rows[] = [self::btn('← К дням', 'vd:0')];
        if ($dayClosed && $hasBookings) {
            $lines[] = '';
            $lines[] = 'Записи на этот день остаются в силе.';
        }
        return [implode("\n", $lines), ['inline_keyboard' => $rows]];
    }

    /** Сводка на день: для вечернего сообщения и команд /today, /tomorrow. */
    public function daySummary(string $day, DateTimeImmutable $now, string $label): string
    {
        $list = $this->app->bookings->between("$day 00:00:00", Time::fmt(Time::parse("$day 00:00:00")->modify('+1 day')), [Status::PENDING, Status::PROPOSED, Status::CONFIRMED, Status::COMPLETED, Status::NO_SHOW]);
        $lines = ['<b>' . e($label . ', ' . Fmt::dayShort($day)) . '</b>', ''];
        if (!$list) {
            $lines[] = 'Занятий нет.';
        }
        foreach ($list as $b) {
            $lines[] = e(Fmt::time($b['starts_at']) . '–' . Fmt::time($b['ends_at']) . ' · ' . $b['client_name'] . ' · ' . $b['product_title'])
                . ' · <i>' . e(mb_strtolower(Status::LABELS[$b['status']])) . '</i>';
        }
        $free = array_column(array_filter($this->app->slots->dayStatus($day, $now), fn($s) => $s['state'] === Slots::FREE), 'time');
        if ($free) {
            $lines[] = '';
            $lines[] = 'Свободно: ' . e(implode(', ', $free));
        }
        return implode("\n", $lines);
    }

    // --- Отправка ---

    private function sendCard(array $b, string $title, DateTimeImmutable $now): void
    {
        $this->send($this->app->telegram->adminChatId(), $this->app->texts->adminCard($b, $title), self::bookingKeyboard($b, $now));
    }

    private function send(string $chatId, string $text, ?array $markup = null): void
    {
        $this->app->telegram->tryCall('sendMessage', array_filter([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $markup,
        ]));
    }

    private function edit(string $chatId, int $messageId, string $text, ?array $markup): void
    {
        $this->app->telegram->tryCall('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $markup ?? ['inline_keyboard' => []],
        ]);
    }

    private function mustGet(int $id): array
    {
        return $this->app->bookings->get($id) ?? throw new StateError('Заявка не найдена.');
    }

    private static function day(string $ymd): string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) || !Time::isDay("$m[1]-$m[2]-$m[3]")) {
            throw new StateError('Неверная дата.');
        }
        return "$m[1]-$m[2]-$m[3]";
    }

    private static function hm(string $hi): string
    {
        if (!preg_match('/^(\d{2})(\d{2})$/', $hi, $m)) {
            throw new StateError('Неверное время.');
        }
        return "$m[1]:$m[2]";
    }

    private static function dateTime(string $ymdhi): string
    {
        if (strlen($ymdhi) !== 12) {
            throw new StateError('Неверное время.');
        }
        return self::day(substr($ymdhi, 0, 8)) . ' ' . self::hm(substr($ymdhi, 8, 4)) . ':00';
    }
}
