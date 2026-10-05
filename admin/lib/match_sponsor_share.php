<?php

declare(strict_types=1);

require_once __DIR__ . '/match_sponsorship.php';
require_once __DIR__ . '/../matches_lib.php';
require_once __DIR__ . '/../render_lib.php';
require_once __DIR__ . '/../event_share_lib.php';
require_once __DIR__ . '/../social_post_settings.php';

/**
 * Match Day / Match Ball sponsorship rows for a fixture that are cleared to
 * appear publicly (paid, complimentary, or zero-value placements).
 *
 * @return list<array<string, mixed>>
 */
function match_sponsor_share_rows(PDO $pdo, int $fixtureId): array
{
    $rows = getMatchSponsorshipRows($pdo, $fixtureId);
    $filtered = [];
    foreach ($rows as $row) {
        if (!in_array((string) ($row['sponsorship_role'] ?? ''), ['match_day', 'match_ball'], true)) {
            continue;
        }
        if (!matchSponsorshipCanBeDisplayed($row)) {
            continue;
        }
        $filtered[] = $row;
    }
    return $filtered;
}

function match_sponsor_share_has_content(PDO $pdo, int $fixtureId): bool
{
    return match_sponsor_share_rows($pdo, $fixtureId) !== [];
}

/**
 * @param array<string, mixed> $row
 */
function match_sponsor_share_logo_url(array $row, bool $preferWhite = false): string
{
    $logo = trim((string) ($row['sponsor_logo'] ?? ''));
    if ($preferWhite) {
        $white = trim((string) ($row['sponsor_white_logo'] ?? ''));
        if ($white !== '') {
            $logo = $white;
        }
    }
    if ($logo === '') {
        return '';
    }
    $absolute = __DIR__ . '/../uploads/sponsors/' . $logo;
    $url = '/uploads/sponsors/' . rawurlencode($logo);
    return is_file($absolute) ? $url . '?v=' . rawurlencode((string) filemtime($absolute)) : $url;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, string>
 */
function match_sponsor_share_caption_context(array $match, array $rows, string $channel = 'facebook'): array
{
    $byRole = [];
    foreach ($rows as $row) {
        $role = (string) ($row['sponsorship_role'] ?? '');
        if (isset($byRole[$role])) {
            continue;
        }
        $byRole[$role] = trim((string) ($row['sponsor_name'] ?? ''));
    }

    return [
        'match_day_sponsor' => $byRole['match_day'] ?? '',
        'match_ball_sponsor' => $byRole['match_ball'] ?? '',
        'sponsor_shoutout_lines' => event_share_fixture_sponsor_credit($match, $channel),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function match_sponsor_share_build_text(array $match, array $rows, string $channel = 'facebook'): string
{
    $context = match_sponsor_share_caption_context($match, $rows, $channel);
    return social_post_resolve_caption($channel, 'sponsor_shoutout', $match, $context);
}

function match_sponsor_share_export_dir(): string
{
    return __DIR__ . '/../export/matches/sponsors';
}

function match_sponsor_share_export_public_base_url(): string
{
    return 'https://lundy.me.uk/export/matches/sponsors';
}

function match_sponsor_share_image_basename(array $match): string
{
    $fixture = matches_slugify(matches_fixture_label($match));
    $date = trim((string) ($match['match_date'] ?? ''));
    $dateSlug = $date !== '' ? matches_slugify($date) : date('Y-m-d');
    return $fixture . '-' . $dateSlug . '-sponsors';
}

function match_sponsor_share_unique_suffix(): string
{
    return date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

function match_sponsor_share_delete_previous_exports(string $exportDir, string $basePrefix): void
{
    $matches = glob($exportDir . '/' . $basePrefix . '-*.png');
    if ($matches === false) {
        return;
    }
    foreach ($matches as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @return array{ok: bool, error?: string, path?: string, download_url?: string}
 */
function match_sponsor_share_generate_image(array $match): array
{
    $exportDir = match_sponsor_share_export_dir();
    if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
        return ['ok' => false, 'error' => 'Sponsor export directory could not be created.'];
    }

    $basePrefix = match_sponsor_share_image_basename($match);
    match_sponsor_share_delete_previous_exports($exportDir, $basePrefix);

    $basename = $basePrefix . '-' . match_sponsor_share_unique_suffix();
    $outputPath = $exportDir . '/' . $basename . '.png';
    $renderUrl = 'https://lundy.me.uk/match_sponsors_graphic.php?id='
        . rawurlencode((string) ($match['id'] ?? ''))
        . '&render=1&v=' . rawurlencode((string) time());

    $result = render_capture_image($renderUrl, $outputPath, '.sponsor-share-card', 1080, 1350, '.sponsor-share-card');
    if (!$result['ok']) {
        return [
            'ok' => false,
            'error' => 'Sponsor image generation failed.' . ($result['output'] !== [] ? ' ' . implode(' ', $result['output']) : ''),
        ];
    }

    return [
        'ok' => true,
        'path' => $outputPath,
        'download_url' => match_sponsor_share_export_public_base_url() . '/' . rawurlencode($basename) . '.png?v=' . rawurlencode((string) time()),
    ];
}
