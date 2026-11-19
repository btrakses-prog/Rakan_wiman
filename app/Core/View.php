<?php
declare(strict_types=1);
namespace RakanWiman\Core;

final class View
{
    public static function render(string $name, array $data = [], string $layout = 'layouts/main'): void
    {
        $root = dirname(__DIR__, 2) . '/resources/views/';
        extract($data, EXTR_SKIP);
        ob_start();
        require $root . $name . '.php';
        $content = ob_get_clean();
        require $root . $layout . '.php';
    }
}
