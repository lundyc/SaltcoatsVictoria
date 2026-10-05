<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/match_sponsor_share.php';
require_once __DIR__ . '/lib/publishing_history.php';

header('Content-Type: application/json; charset=UTF-8');
$sponsorInstagramHistoryId = 0;

function sponsor_ig_respond(bool $ok, string $summary, array $details = [], int $status = 200): void
{
    global $pdo, $sponsorInstagramHistoryId;
    if (!$ok && $sponsorInstagramHistoryId > 0) {
        hub_publishing_history_finish($pdo, $sponsorInstagramHistoryId, false, '', implode("\n", $details !== [] ? $details : [$summary]));
        $sponsorInstagramHistoryId = 0;
    }
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
        'details' => array_values($details),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sponsor_ig_respond(false, 'Method not allowed. Use POST.', [], 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    sponsor_ig_respond(false, 'Your session expired. Reload the fixture and try again.', [], 419);
}
if (!social_publishing_platform_enabled('instagram')) {
    sponsor_ig_respond(false, 'Instagram publishing is disabled in Settings.', [], 403);
}

$fixtureId = isset($_POST['fixture_id']) ? (int) $_POST['fixture_id'] : 0;
$captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';

if ($fixtureId <= 0) {
    sponsor_ig_respond(false, 'Fixture id is required.', [], 422);
}

$fixture = getMatchFixtureById($pdo, $fixtureId);
if ($fixture === null) {
    sponsor_ig_respond(false, 'Fixture not found.', [], 404);
}

$rows = match_sponsor_share_rows($pdo, $fixtureId);
if ($rows === []) {
    sponsor_ig_respond(false, 'No confirmed Match Day or Match Ball sponsors are set for this fixture yet.', [], 422);
}

$imageResult = match_sponsor_share_generate_image($fixture);
if (empty($imageResult['ok'])) {
    sponsor_ig_respond(false, 'Instagram post failed.', [(string) ($imageResult['error'] ?? 'Sponsor image generation failed.')], 500);
}

$imagePath = (string) ($imageResult['path'] ?? '');
$publicImageUrl = (string) ($imageResult['download_url'] ?? '');
if ($imagePath === '' || $publicImageUrl === '' || !is_file($imagePath)) {
    sponsor_ig_respond(false, 'Instagram post failed.', ['Generated sponsor image is missing.'], 500);
}

$caption = $captionOverride !== '' ? $captionOverride : match_sponsor_share_build_text($fixture, $rows, 'instagram');
$history = hub_publishing_history_start($pdo, [
    'fixture_id' => $fixtureId,
    'event_id' => '',
    'post_type' => 'sponsor_shoutout',
    'platform' => 'instagram',
    'caption' => $caption,
    'image_url' => '/match.php?id=' . rawurlencode((string) $fixtureId) . '&tab=sponsorships',
]);
if ($history['duplicate']) {
    sponsor_ig_respond(false, 'A sponsor shoutout for this fixture has already been published to Instagram.', [], 409);
}
$sponsorInstagramHistoryId = $history['id'];
$phpCandidates = [PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', '/usr/bin/php', '/usr/local/bin/php'];
$phpBinary = '';
foreach ($phpCandidates as $candidate) {
    if (is_file($candidate) && is_executable($candidate) && stripos(basename($candidate), 'php-fpm') === false) {
        $phpBinary = $candidate;
        break;
    }
}
if ($phpBinary === '') {
    sponsor_ig_respond(false, 'Instagram post failed.', ['PHP CLI could not be found.'], 500);
}

$command = escapeshellarg($phpBinary)
    . ' ' . escapeshellarg(__DIR__ . '/post_to_instagram.php')
    . ' ' . escapeshellarg($imagePath)
    . ' ' . escapeshellarg($publicImageUrl)
    . ' ' . escapeshellarg('sponsor_shoutout')
    . ' ' . escapeshellarg((string) $fixtureId)
    . ' ' . escapeshellarg($caption)
    . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);

if ($exitCode !== 0) {
    $details = array_values(array_filter(array_map('trim', $output)));
    sponsor_ig_respond(false, 'Instagram post failed.', $details !== [] ? $details : ['Instagram did not return an error message.'], 500);
}

hub_publishing_history_finish($pdo, $sponsorInstagramHistoryId, true, implode(' ', $output));
$sponsorInstagramHistoryId = 0;
sponsor_ig_respond(true, 'Instagram sponsor shoutout completed.', ['Sponsor graphic posted to Instagram successfully.']);
