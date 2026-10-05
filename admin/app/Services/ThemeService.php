<?php
namespace App\Services;

class ThemeService
{
    public static function palette(): array
    {
        $configTheme = app_config('theme', []);
        $clubTheme = request_context('club')['theme'] ?? [];

        return array_merge([
            'background' => '#020617',
            'panel' => '#111827',
            'muted' => '#64748b',
            'accent' => '#22d3ee',
            'highlight' => '#38bdf8',
        ], $configTheme, $clubTheme);
    }
}
