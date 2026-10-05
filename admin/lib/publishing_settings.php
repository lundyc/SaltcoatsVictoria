<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function hub_publishing_default_league_config(): array
{
    return [
        'league_title' => 'WOSFL Fourth Division',
        'wosfl_table_url' => 'https://www.wosfl.co.uk/standingsForDate/847802708/2/-1/-1.html',
        'league_banner_image' => 'assets/images/header.png',
        'total_games_per_team' => 30,
        'promotion_spots' => 9,
        'relegation_spots' => 0,
        'show_table_lines' => true,
    ];
}

/** @return array<string, mixed> */
function hub_publishing_load_league_config(string $path): array
{
    $config = hub_publishing_default_league_config();
    if (is_file($path)) {
        $json = file_get_contents($path);
        if (is_string($json)) {
            $json = preg_replace('~/\*.*?\*/~s', '', $json) ?? $json;
            $json = preg_replace('/^\s*(?:\/\/|#).*$/m', '', $json) ?? $json;
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $config = array_merge($config, $decoded);
            }
        }
    }

    $config['league_title'] = trim((string) ($config['league_title'] ?? '')) ?: 'WOSFL Fourth Division';
    $config['wosfl_table_url'] = hub_publishing_normalize_url((string) ($config['wosfl_table_url'] ?? ''), (string) hub_publishing_default_league_config()['wosfl_table_url']);
    $config['league_banner_image'] = trim((string) ($config['league_banner_image'] ?? '')) ?: 'assets/images/header.png';
    $config['promotion_spots'] = max(1, (int) ($config['promotion_spots'] ?? 9));
    $config['relegation_spots'] = max(0, (int) ($config['relegation_spots'] ?? 0));
    $config['show_table_lines'] = !empty($config['show_table_lines']);

    return $config;
}

/** @param array<string, mixed> $config */
function hub_publishing_save_league_config(string $path, array $config): bool
{
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== false && file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/** @return list<array{pos: string, club: string, logo: string}> */
function hub_publishing_load_league_teams(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $teams = [];
    foreach ($decoded as $row) {
        if (!is_array($row) || trim((string) ($row['club'] ?? '')) === '') {
            continue;
        }
        $teams[] = [
            'pos' => trim((string) ($row['pos'] ?? '')),
            'club' => trim((string) $row['club']),
            'logo' => trim((string) ($row['logo'] ?? '')),
        ];
    }
    usort($teams, static fn(array $a, array $b): int => ((int) $a['pos'] <=> (int) $b['pos']) ?: strcasecmp($a['club'], $b['club']));
    return $teams;
}

/** @return list<string> */
function hub_publishing_load_badges(string $directory): array
{
    $files = array_map('basename', glob(rtrim($directory, '/') . '/*.png') ?: []);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

/** @param list<string> $badgeFiles */
function hub_publishing_selected_badge(string $club, array $overrides, array $badgeFiles): string
{
    if (isset($overrides[$club]) && in_array((string) $overrides[$club], $badgeFiles, true)) {
        return (string) $overrides[$club];
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($club)), '-');
    $candidate = $slug . '.png';
    return in_array($candidate, $badgeFiles, true) ? $candidate : '';
}

function hub_publishing_normalize_url(string $value, string $fallback): string
{
    $value = trim($value);
    return $value !== '' && preg_match('#^https?://#i', $value) ? $value : $fallback;
}

function hub_publishing_post_bool(array $source, string $key): bool
{
    return in_array(strtolower(trim((string) ($source[$key] ?? ''))), ['1', 'true', 'yes', 'on'], true);
}

/** @return array{path: string, error: string} */
function hub_publishing_upload_banner(string $directory, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'The banner upload failed.'];
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $imageInfo = $tmpName !== '' && is_uploaded_file($tmpName) ? @getimagesize($tmpName) : false;
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    if (!isset($extensions[$mime])) {
        return ['path' => '', 'error' => 'Please upload a JPG, PNG, or WebP banner image.'];
    }
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['path' => '', 'error' => 'The banner upload directory could not be created.'];
    }
    $filename = 'league-banner-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpName, $directory . '/' . $filename)) {
        return ['path' => '', 'error' => 'The uploaded banner could not be saved.'];
    }
    return ['path' => 'uploads/league/' . $filename, 'error' => ''];
}

function hub_publishing_delete_banner(string $socialsDirectory, string $path): void
{
    if (str_starts_with($path, 'uploads/league/')) {
        $absolutePath = $socialsDirectory . '/' . $path;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
