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

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

auth_require_json();

$fixtureId = isset($_POST['fixture_id']) ? (int) $_POST['fixture_id'] : 0;
if ($fixtureId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Fixture id is required.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$fixture = getMatchFixtureById($pdo, $fixtureId);
if ($fixture === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Fixture not found.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$rows = match_sponsor_share_rows($pdo, $fixtureId);
if ($rows === []) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'No confirmed Match Day or Match Ball sponsors are set for this fixture yet.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$captions = [
    'facebook' => match_sponsor_share_build_text($fixture, $rows, 'facebook'),
    'instagram' => match_sponsor_share_build_text($fixture, $rows, 'instagram'),
    'x' => match_sponsor_share_build_text($fixture, $rows, 'x'),
];
$text = $captions['facebook'];

$imageResult = match_sponsor_share_generate_image($fixture);
if (!$imageResult['ok']) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => isset($imageResult['error']) && is_string($imageResult['error'])
            ? $imageResult['error']
            : 'Sponsor image generation failed.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$composeUrl = 'https://x.com/intent/tweet?text=' . rawurlencode($text);

echo json_encode([
    'ok' => true,
    'summary' => 'Sponsor shoutout prepared.',
    'details' => [
        'Sponsor text and graphic are ready to review.',
    ],
    'compose_url' => $composeUrl,
    'download_url' => (string) ($imageResult['download_url'] ?? ''),
    'text' => $text,
    'captions' => $captions,
], JSON_UNESCAPED_SLASHES);
