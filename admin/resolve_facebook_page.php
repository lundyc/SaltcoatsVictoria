<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/facebook_page_resolver.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['hub_user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session has expired.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!csrf_check()) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Your session has expired. Reload the page and try again.']);
    exit;
}

$inputUrl = trim((string)($_POST['facebook_page_url'] ?? ''));
$normalisedUrl = sponsor_normalise_facebook_url($inputUrl);
if ($normalisedUrl === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Enter a valid facebook.com Page URL.']);
    exit;
}

$resolved = sponsor_resolve_facebook_page($normalisedUrl);
if ($resolved === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'url' => $normalisedUrl,
        'message' => 'Facebook did not expose a Page ID for this URL. Check that the public Page address is correct.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'id' => $resolved['id'],
    'url' => $resolved['url'],
    'name' => $resolved['name'],
    'message' => 'Facebook Page ID found.',
], JSON_UNESCAPED_SLASHES);
