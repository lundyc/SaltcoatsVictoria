<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_birthdays.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (!csrf_check()) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Session expired - reload the page.']);
    exit;
}

$source = (string) ($_POST['source'] ?? '');
$recordId = (int) ($_POST['id'] ?? 0);
$verified = ($_POST['verified'] ?? '') === '1';
if (!in_array($source, ['player', 'person'], true) || $recordId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

players_ensure_dob_verifications_table($pdo);

if (!$verified) {
    $pdo->prepare('DELETE FROM dob_verifications WHERE source = ? AND record_id = ?')->execute([$source, $recordId]);
    echo json_encode(['ok' => true]);
    exit;
}

$table = $source === 'player' ? 'players' : 'people';
$stmt = $pdo->prepare("SELECT date_of_birth FROM {$table} WHERE id = ?");
$stmt->execute([$recordId]);
$dob = $stmt->fetchColumn();
if (!$dob) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$accountId = (int) ($_SESSION['hub_account_id'] ?? 0) ?: null;
$pdo->prepare('REPLACE INTO dob_verifications (source, record_id, verified_dob, verified_at, verified_by) VALUES (?, ?, ?, NOW(), ?)')
    ->execute([$source, $recordId, $dob, $accountId]);
echo json_encode(['ok' => true]);
