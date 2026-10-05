<?php
declare(strict_types=1);

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('finance_manage')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/stripe.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in again.']);
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

$linkId = (int) ($_POST['link_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
$stmt->execute([':id' => $linkId]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$link) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Payment link not found.']);
    exit;
}

try {
    stripe_expire_payment_link($pdo, $link);
    auditLog($pdo, 'stripe_payment_link_cancelled', "Cancelled Stripe payment link #{$linkId} for agreement #" . (int) $link['agreement_id'] . ' (£' . number_format((float) $link['amount'], 2) . ')');
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
