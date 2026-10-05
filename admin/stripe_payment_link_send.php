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
require_once __DIR__ . '/lib/sponsorship_catalog.php';
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

ensureSponsorshipCatalogSchema($pdo);
ensureStripeSchema($pdo);

$linkId = (int) ($_POST['link_id'] ?? 0);
$email = trim((string) ($_POST['email'] ?? ''));

if ($linkId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Enter a valid email address.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
$stmt->execute([':id' => $linkId]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$link) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Payment link not found.']);
    exit;
}

if ((string) $link['status'] !== 'open') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This payment link is no longer open.']);
    exit;
}

$agreement = getSponsorshipAgreement($pdo, (int) $link['agreement_id']);
if (!$agreement) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Agreement not found.']);
    exit;
}

$sent = stripe_send_payment_link_email($email, (string) ($agreement['sponsor_name'] ?? ''), $agreement, $link);
if (!$sent) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The email could not be sent. You can still copy the link and send it manually.']);
    exit;
}

$pdo->prepare('UPDATE stripe_payment_links SET sent_to_email = :email, sent_at = NOW() WHERE id = :id')
    ->execute([':email' => $email, ':id' => $linkId]);

auditLog($pdo, 'stripe_payment_link_sent', "Emailed Stripe payment link #{$linkId} for {$agreement['sponsor_name']} to {$email}");

echo json_encode(['ok' => true]);
