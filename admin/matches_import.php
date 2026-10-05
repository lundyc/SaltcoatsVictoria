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

$seasonId = (int)($_POST['season_id'] ?? getSelectedSeasonId($pdo));
if ($seasonId <= 0) {
          exit('Missing season.');
}

$season = getSeasonById($pdo, $seasonId);
if (!$season) {
          exit('Invalid season.');
}
if ((int)$season['is_locked'] === 1 && (string)($_POST['historical_import'] ?? '') !== '1') {
          exit('This season is locked. Confirm that this is a controlled historical import to continue.');
}

if (empty($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          exit('Please select a CSV or Excel file.');
}

$file = $_FILES['import_file'];
if ((int)$file['error'] !== UPLOAD_ERR_OK) {
          exit('File upload failed.');
}
if (!is_uploaded_file($file['tmp_name'])) {
          exit('Invalid file upload.');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'match_import_');
if ($tmpFile === false) {
          exit('Failed to create a temporary file.');
}

if (!move_uploaded_file($file['tmp_name'], $tmpFile)) {
          @unlink($tmpFile);
          exit('Failed to store uploaded file.');
}

$created = 0;
$updated = 0;
$errors = [];
$syncFixtureIds = [];

try {
          $rows = matchImportReadSpreadsheetRows($tmpFile, (string)$file['name']);
          if (!$rows) {
                    throw new RuntimeException('No data rows were found in the file.');
          }

          $pdo->beginTransaction();
          foreach ($rows as $index => $row) {
                    try {
                              $result = matchImportUpsertFixture($pdo, $seasonId, $row);
                              if (!empty($result['fixture_id'])) {
                                        $syncFixtureIds[] = (int) $result['fixture_id'];
                              }
                              if (($result['action'] ?? '') === 'created') {
                                        $created++;
                              } else {
                                        $updated++;
                              }
                    } catch (Throwable $rowError) {
                              $errors[] = 'Row ' . ($index + 2) . ': ' . $rowError->getMessage();
                    }
          }

          if ($errors) {
                    throw new RuntimeException(implode(' ', $errors));
          }

          $pdo->commit();

          auditLog($pdo, 'matches_imported', "Imported fixtures for season #{$seasonId}: {$created} created, {$updated} updated");

          $syncFixtureIds = array_values(array_unique(array_filter($syncFixtureIds, static fn(int $id): bool => $id > 0)));
          foreach ($syncFixtureIds as $syncFixtureId) {
                    $fixture = getMatchFixtureById($pdo, $syncFixtureId);
                    if ($fixture) {
                              match_google_calendar_sync_fixture($pdo, $fixture, 'upsert');
                    }
          }

          @unlink($tmpFile);

          header('Location: matches.php?imported=1&created=' . $created . '&updated=' . $updated . '&season_id=' . $seasonId);
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          @unlink($tmpFile);
          http_response_code(400);
          echo '<div>';
          echo '<h2>Import failed</h2>';
          echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
          echo '<p><a href="matches.php?season_id=' . (int)$seasonId . '">Back to fixtures</a></p>';
          echo '</div>';
}
