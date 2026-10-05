<?php

declare(strict_types=1);

require_once __DIR__ . '/../env.php';

function hub_settings_env_path(): string
{
    return __DIR__ . '/../.env';
}

/**
 * @return array<string, string>
 */
function hub_settings_load_env(): array
{
    return app_parse_env_file(hub_settings_env_path());
}

/**
 * @param array<string, string> $fileEnv
 */
function hub_settings_value(array $fileEnv, string $key, string $default = ''): string
{
    $runtime = getenv($key);
    if ($runtime !== false && trim((string) $runtime) !== '') {
        return (string) $runtime;
    }

    if (isset($fileEnv[$key])) {
        return (string) $fileEnv[$key];
    }

    return $default;
}

function hub_settings_env_format(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', trim($value));
    if ($value === '') {
        return '';
    }

    if (preg_match('/[\s#"]/', $value) || str_contains($value, '=')) {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    return $value;
}

/**
 * @param array<string, string> $values
 */
function hub_settings_save_env(array $values): bool
{
    $path = hub_settings_env_path();
    $existing = hub_settings_load_env();
    $merged = array_merge($existing, $values);

    $lines = [];
    foreach ($merged as $key => $value) {
        $key = trim((string) $key);
        if ($key === '') {
            continue;
        }

        $lines[] = $key . '=' . hub_settings_env_format((string) $value);
    }

    $content = implode(PHP_EOL, $lines) . PHP_EOL;
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    return file_put_contents($path, $content, LOCK_EX) !== false;
}
