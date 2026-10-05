<?php
use RuntimeException;

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('render')) {
    function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        view($template, $data, $layout);
    }
}

if (!function_exists('abort')) {
    function abort(int $status = 404, array $data = [], string $layout = 'layouts/auth'): void
    {
        http_response_code($status);
        $template = 'errors/' . $status;
        $path = base_path('app/Views/' . $template . '.php');
        if (!file_exists($path)) {
            throw new RuntimeException('Error view not found for status ' . $status);
        }

        render($template, $data, $layout);
        exit;
    }
}
