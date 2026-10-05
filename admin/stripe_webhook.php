<?php
declare(strict_types=1);

// Public endpoint — Stripe calls this directly, so it deliberately does not
// go through auth.php's session gate. Authenticity is established purely by
// verifying the Stripe-Signature header against the webhook signing secret
// (see stripe_verify_webhook_signature() in lib/stripe.php), the same way
// auth_endpoint.php sits outside the session gate for its own reasons.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/stripe.php';

ensureStripeSchema($pdo);

$payload = file_get_contents('php://input') ?: '';
$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$webhookSecret = stripe_webhook_secret();

if ($webhookSecret === '' || !stripe_verify_webhook_signature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    echo 'Invalid signature.';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || !isset($event['id'], $event['type'])) {
    http_response_code(400);
    echo 'Malformed event.';
    exit;
}

// Idempotency: Stripe retries webhook deliveries. Check for a prior
// successful delivery first, but only record the event as processed *after*
// stripe_handle_webhook_event() succeeds — recording it beforehand would
// permanently mark a failed attempt as "done" and prevent Stripe's retry
// from ever actually reprocessing it.
$existing = $pdo->prepare('SELECT id FROM stripe_webhook_events WHERE stripe_event_id = :event_id');
$existing->execute([':event_id' => (string) $event['id']]);
if ($existing->fetchColumn()) {
    http_response_code(200);
    echo 'Already processed.';
    exit;
}

try {
    stripe_handle_webhook_event($pdo, $event);
} catch (Throwable $e) {
    error_log('[stripe_webhook] Failed to process event ' . $event['id'] . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Processing failed.';
    exit;
}

try {
    $pdo->prepare('INSERT INTO stripe_webhook_events (stripe_event_id, type) VALUES (:event_id, :type)')
        ->execute([':event_id' => (string) $event['id'], ':type' => (string) $event['type']]);
} catch (Throwable $e) {
    // A concurrent duplicate delivery already recorded it; the work above is
    // already idempotent (checkout completion / refund delta checks), so this is harmless.
}

http_response_code(200);
echo 'ok';
