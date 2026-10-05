<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/google_calendar.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);

if ($fixtureId <= 0) {
          http_response_code(400);
          exit('Missing fixture.');
}

$fixture = getMatchFixtureById($pdo, $fixtureId);
if (!$fixture) {
          http_response_code(404);
          exit('Fixture not found.');
}
$fixtureForCalendar = $fixture;

if ($seasonId <= 0) {
          $seasonId = (int) ($fixture['season_id'] ?? 0);
}

if ($seasonId <= 0) {
          http_response_code(400);
          exit('Missing season.');
}

$season = getSeasonById($pdo, $seasonId);
if (!$season) {
          http_response_code(400);
          exit('Invalid season.');
}
if ((int) $season['is_locked'] === 1) {
          http_response_code(400);
          exit('This season is locked.');
}

$backgroundImage = trim((string) ($fixture['starting11_background_image'] ?? ''));
$nextMatchBackgroundImage = trim((string) ($fixture['next_match_background_image'] ?? ''));

$pdo->beginTransaction();
try {
          $delete = $pdo->prepare("DELETE FROM match_fixtures WHERE id = :id AND season_id = :season_id LIMIT 1");
          $delete->execute([
                    ':id' => $fixtureId,
                    ':season_id' => $seasonId,
          ]);
          if ($delete->rowCount() === 0) {
                    throw new RuntimeException('Fixture could not be deleted.');
          }

          $pdo->commit();
          auditLog($pdo, 'match_deleted', 'Deleted fixture vs ' . (string) ($fixture['opponent'] ?? '') . ' on ' . (string) ($fixture['match_date'] ?? ''));
          match_google_calendar_sync_fixture($pdo, $fixtureForCalendar, 'delete');
          if ($backgroundImage !== '' && str_starts_with($backgroundImage, 'uploads/matches/')) {
                    $absolutePath = __DIR__ . '/' . $backgroundImage;
                    if (is_file($absolutePath)) {
                              @unlink($absolutePath);
                    }
          }
          if ($nextMatchBackgroundImage !== '' && str_starts_with($nextMatchBackgroundImage, 'uploads/matches/')) {
                    $absolutePath = __DIR__ . '/' . $nextMatchBackgroundImage;
                    if (is_file($absolutePath)) {
                              @unlink($absolutePath);
                    }
          }
          header('Location: matches.php?season_id=' . $seasonId . '&deleted=1');
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
