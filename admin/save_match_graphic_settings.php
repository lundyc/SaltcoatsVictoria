<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

header('Content-Type: application/json');

try {
          if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new Exception('Invalid request method.');
          }

          if (!csrf_check()) {
                    throw new Exception('Invalid CSRF token.');
          }

          $seasonId = (int)($_POST['season_id'] ?? 0);
          $fixtureId = (int)($_POST['fixture_id'] ?? 0);
          if ($seasonId <= 0 || $fixtureId <= 0) {
                    throw new Exception('Missing season or fixture.');
          }

          $fixture = getMatchFixtureById($pdo, $fixtureId);
          if (!$fixture || (int)$fixture['season_id'] !== $seasonId) {
                    throw new Exception('Invalid fixture selected.');
          }

          saveMatchGraphicLayout($pdo, $fixtureId, $seasonId, $_POST);

          echo json_encode(['ok' => true]);
} catch (Throwable $e) {
          http_response_code(400);
          echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
