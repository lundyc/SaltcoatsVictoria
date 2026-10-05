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

if (!stripe_is_configured()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Stripe is not configured yet. Add a secret key under Settings > Payments.']);
    exit;
}

$agreementId = (int) ($_POST['agreement_id'] ?? 0);
$agreement = $agreementId > 0 ? getSponsorshipAgreement($pdo, $agreementId) : null;
if (!$agreement) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Agreement not found.']);
    exit;
}

$baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/sponsorship_agreement.php?id=' . $agreementId;

try {
    $currentUser = hub_auth_current_user();
    $link = stripe_create_checkout_session_for_agreement(
        $pdo,
        $agreement,
        $baseUrl . '&stripe=success&session_id={CHECKOUT_SESSION_ID}',
        $baseUrl . '&stripe=cancelled',
        (int) ($currentUser['id'] ?? 0) ?: null
    );

    auditLog($pdo, 'stripe_payment_link_created', "Created Stripe payment link of £" . number_format((float) $link['amount'], 2) . " for agreement #{$agreementId} ({$agreement['sponsor_name']})");

    echo json_encode([
        'ok' => true,
        'link' => [
            'id' => (int) $link['id'],
            'url' => stripe_payment_link_public_url($link),
            'amount' => (float) $link['amount'],
            'expires_at' => date('Y-m-d H:i:s', stripe_payment_link_public_expires_at($link)),
            'sponsor_contact_email' => (string) ($agreement['sponsor_contact_email'] ?? ''),
            'message' => stripe_build_payment_message((string) ($agreement['sponsor_name'] ?? ''), $agreement, $link),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
