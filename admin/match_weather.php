<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/match_overview.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=1800');

if (!hub_auth_is_authenticated()) {
          http_response_code(401);
          echo json_encode(['ok' => false, 'message' => 'Your session has expired. Refresh the page and sign in again.']);
          exit;
}

$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          http_response_code(404);
          echo json_encode(['ok' => false, 'message' => 'Fixture not found.']);
          exit;
}

try {
          echo json_encode(matchOverviewWeather($pdo, $fixture), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
          http_response_code(503);
          echo json_encode(['ok' => false, 'message' => 'Weather data is temporarily unavailable.']);
}
