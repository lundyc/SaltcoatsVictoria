<?php

declare(strict_types=1);

/** @return array<string, string> */
function hub_publishing_read_env(string $path): array
{
    $values = [];
    foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $values[$key] = trim($value, "\"'");
    }
    return $values;
}

/** @return array<string, array<string, mixed>> */
function hub_publishing_platform_health(string $socialsDirectory): array
{
    $env = hub_publishing_read_env($socialsDirectory . '/.env');
    $definitions = [
        'facebook' => ['label' => 'Facebook', 'keys' => ['PAGE_ID', 'PAGE_ACCESS_TOKEN', 'APP_SECRET'], 'log' => 'facebook_post.log'],
        'instagram' => ['label' => 'Instagram', 'keys' => ['IG_BUSINESS_ACCOUNT_ID', 'APP_SECRET'], 'log' => 'instagram_post.log'],
        'x' => ['label' => 'X', 'keys' => [], 'log' => 'twitter_share.log'],
    ];
    $health = [];
    foreach ($definitions as $key => $definition) {
        $missing = [];
        foreach ($definition['keys'] as $requiredKey) {
            if (trim((string)($env[$requiredKey] ?? '')) === '') {
                $missing[] = $requiredKey;
            }
        }
        $logPath = $socialsDirectory . '/logs/' . $definition['log'];
        $lastLine = '';
        if (is_file($logPath)) {
            $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lastLine = trim((string)end($lines));
        }
        $hasRecentError = $lastLine !== '' && preg_match('/ERROR|failed/i', $lastLine) === 1;
        $health[$key] = [
            'label' => $definition['label'],
            'configured' => $missing === [],
            'status' => $missing !== [] ? 'Not configured' : ($hasRecentError ? 'Needs attention' : ($key === 'x' ? 'Manual composer' : 'Configured')),
            'last_activity' => is_file($logPath) ? date('d/m/Y H:i', (int)filemtime($logPath)) : 'No activity',
            'missing' => $missing,
        ];
    }
    return $health;
}
