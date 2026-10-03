<?php
declare(strict_types=1);

namespace Booking;

/** Шаблоны из templates/. Всё, что выводится в HTML, проходит через e(). */
final class View
{
    public static function render(string $template, array $vars = []): string
    {
        $file = dirname(__DIR__) . '/templates/' . $template . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    /** Страница внутри общего макета. */
    public static function page(string $template, array $vars = [], string $layout = 'layout'): string
    {
        $vars['content'] = self::render($template, $vars);
        return self::render($layout, $vars);
    }
}
