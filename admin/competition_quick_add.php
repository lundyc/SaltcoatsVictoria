<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$name = trim((string)($_POST['name'] ?? ''));
$fixtureId = (int)($_POST['return_to_fixture_id'] ?? 0);
$seasonId = (int)($_POST['return_to_season_id'] ?? 0);
$action = trim((string)($_POST['return_action'] ?? 'view'));

if ($name === '') {
          http_response_code(400);
          exit('Competition name is required.');
}

$existing = getMatchCompetitionByName($pdo, $name);
if ($existing) {
          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'competition_added' => (int)$existing['id'],
          ]);
          header('Location: ' . $redirect);
          exit;
}

try {
          $savedId = saveMatchCompetition($pdo, null, $name);
          auditLog($pdo, 'competition_created', "Created competition '{$name}'");
          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'competition_added' => $savedId,
          ]);
          header('Location: ' . $redirect);
          exit;
} catch (Throwable $e) {
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
