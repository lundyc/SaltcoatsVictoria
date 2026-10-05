<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/render_lib.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

$socialBaseUrl = APP_ORIGIN . '/admin';
$matchId = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;

if ($match === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Match not found.';
    exit;
}

$baseName = matches_slugify(matches_fixture_label($match)) . '-' . ((string) ($match['match_date'] ?? date('Y-m-d')));
$outputPath = MATCHES_EXPORT_DIR . '/' . $baseName . '.png';
$renderUrl = $socialBaseUrl . '/match_graphic.php?id=' . rawurlencode((string) $match['id'])
    . '&render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);
$result = render_capture_image($renderUrl, $outputPath, '.match-preview-wrap', 1080, 1080, '.match-preview-wrap');
if (!$result['ok']) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $result['error'];
    if ($result['output'] !== []) {
        echo "\n\n";
        echo implode("\n", $result['output']);
    }
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($outputPath));
header('Content-Disposition: attachment; filename="' . basename($outputPath) . '"');
readfile($outputPath);
exit;
