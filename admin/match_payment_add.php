<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments'])) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$id = (int)($_POST['match_sponsorship_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim($_POST['method'] ?? '');
$note = trim($_POST['note'] ?? '');

try {
          $row = addMatchPayment($pdo, $id, $amount, $method !== '' ? $method : null, $note !== '' ? $note : null);
          auditLog($pdo, 'match_sponsorship_payment_added', "Added payment of £" . number_format($amount, 2) . " for {$row['sponsor_name']} sponsorship (vs {$row['opponent']})");
          header('Location: match.php?id=' . (int)$row['fixture_id'] . '&season_id=' . (int)$row['season_id'] . '&paid=1');
          exit;
} catch (Throwable $e) {
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
