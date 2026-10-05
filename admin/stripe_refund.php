<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/stripe.php';

header('Content-Type: application/json');

if (!hub_auth_has_capability('finance_manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to issue refunds.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!csrf_check()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token. Reload the page and try again.']);
    exit;
}

ensureStripeSchema($pdo);

$transactionId = (int) ($_POST['transaction_id'] ?? 0);
$amount = (float) ($_POST['amount'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

$stmt = $pdo->prepare('SELECT * FROM stripe_transactions WHERE id = :id');
$stmt->execute([':id' => $transactionId]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transaction) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Transaction not found.']);
    exit;
}

try {
    $currentUser = hub_auth_current_user();
    stripe_create_refund($pdo, $transaction, $amount, $reason ?: null, (int) ($currentUser['id'] ?? 0) ?: null);
    auditLog($pdo, 'stripe_refund_issued', "Refunded £" . number_format($amount, 2) . " via Stripe for transaction #{$transactionId}" . ($reason !== '' ? " ({$reason})" : ''));
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
