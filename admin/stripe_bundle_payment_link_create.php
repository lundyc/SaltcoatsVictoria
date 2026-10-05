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

$bundleId = (int) ($_POST['bundle_id'] ?? 0);
$bundle = $bundleId > 0 ? getSponsorshipBundle($pdo, $bundleId) : null;
if (!$bundle) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Bundle not found.']);
    exit;
}

$agreements = getSponsorshipAgreements($pdo, ['bundle_id' => $bundleId]);
if (!$agreements) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This bundle has no items yet.']);
    exit;
}

$baseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/sponsorship_bundle.php?id=' . $bundleId;

try {
    $currentUser = hub_auth_current_user();
    $result = stripe_create_checkout_session_for_bundle(
        $pdo,
        $agreements,
        $baseUrl . '&stripe=success&session_id={CHECKOUT_SESSION_ID}',
        $baseUrl . '&stripe=cancelled',
        (int) ($currentUser['id'] ?? 0) ?: null
    );

    auditLog($pdo, 'stripe_payment_link_created', "Created combined Stripe payment link of £" . number_format((float) $result['total_amount'], 2) . " for bundle #{$bundleId} ({$bundle['sponsor_name']}), covering " . count($result['agreement_ids']) . ' item(s)');

    echo json_encode([
        'ok' => true,
        'link' => [
            'url' => (string) ($result['short_url'] ?? $result['session_url']),
            'total_amount' => (float) $result['total_amount'],
            'agreement_ids' => $result['agreement_ids'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
