<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// players_save.php — Add new player
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';

if (session_status() === PHP_SESSION_NONE) {
          session_start();
}

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
          || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function playersSaveFail(string $message, bool $isAjax): void
{
          if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $message]);
                    exit;
          }

          $_SESSION['flash_toast'] = [
                    'type' => 'danger',
                    'message' => $message,
          ];
          header('Location: player_add.php');
          exit;
}

if (!csrf_check()) {
          playersSaveFail('Invalid CSRF token', $isAjax);
}

$name = trim($_POST['name'] ?? '');
$date_of_birth = trim((string) ($_POST['date_of_birth'] ?? ''));
$status = $_POST['status'] ?? 'current';
$joined_at = $_POST['joined_at'] ?? null;
$left_at = $_POST['left_at'] ?? null;
$active = isset($_POST['active']) ? 1 : 0;

if ($name === '') {
          playersSaveFail('Name is required', $isAjax);
}
if ($date_of_birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
          playersSaveFail('Date of birth must use the YYYY-MM-DD format', $isAjax);
}
if (!in_array($status, ['current', 'trialist', 'left', 'retired', 'loan', 'injured'], true)) {
          playersSaveFail('Choose a valid player status', $isAjax);
}

try {
          players_ensure_date_of_birth_column($pdo);
          $stmt = $pdo->prepare("INSERT INTO players (name, date_of_birth, status, joined_at, left_at, active) VALUES (:name, :date_of_birth, :status, :joined_at, :left_at, :active)");
          $stmt->execute([
                    ':name' => $name,
                    ':date_of_birth' => $date_of_birth ?: null,
                    ':status' => $status,
                    ':joined_at' => $joined_at ?: null,
                    ':left_at' => $left_at ?: null,
                    ':active' => $active
          ]);

          $newId = $pdo->lastInsertId();

          auditLog($pdo, 'player_created', "Created player '{$name}'");

          if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                              'success' => true,
                              'id' => $newId,
                              'redirect' => 'player_edit.php?id=' . $newId . '&created=1'
                    ]);
                    exit;
          }

          player_sponsors_sync_social_directory();
          header('Location: player_edit.php?id=' . $newId . '&created=1');
          exit;
} catch (Throwable $e) {
          playersSaveFail($e->getMessage(), $isAjax);
}
