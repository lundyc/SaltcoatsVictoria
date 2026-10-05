<?php
declare(strict_types=1);

/**
 * Public short-link resolver for Stripe payment links.
 *
 * A sponsor is handed https://myclubhub.co.uk/p/<slug> (rewritten to pay.php?c=<slug>
 * by .htaccess). The public Hub link can live longer than Stripe's 24-hour
 * Checkout Session limit; when the sponsor clicks an otherwise-valid Hub link
 * after the underlying Checkout Session has expired, a fresh Session is created
 * and saved against the same Hub link row before redirecting.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/stripe.php';

ensureStripeSchema($pdo);

$slug = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? ''));

$link = null;
if ($slug !== '' && strlen($slug) <= 24) {
    try {
        // The slug column is created by ensureStripeSchema() the first time a link is
        // generated; if it somehow isn't there yet, the catch below just 404s.
        $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE slug = :slug ORDER BY id DESC LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $link = null;
    }
}

$publicLinkIsLive = $link && stripe_payment_link_public_expires_at($link) > time();
$stripeSessionIsLive = $link
    && (string) $link['status'] === 'open'
    && !empty($link['url'])
    && (empty($link['expires_at']) || strtotime((string) $link['expires_at']) > time());

if ($stripeSessionIsLive) {
    header('Location: ' . (string) $link['url'], true, 302);
    exit;
}

if ($publicLinkIsLive && $link && in_array((string) $link['status'], ['open', 'expired'], true)) {
    try {
        $link = stripe_refresh_payment_link_checkout_session($pdo, $link);
        if ((string) $link['status'] === 'open' && !empty($link['url'])) {
            header('Location: ' . (string) $link['url'], true, 302);
            exit;
        }
    } catch (Throwable $e) {
        error_log('[stripe] Could not refresh public payment link ' . $slug . ': ' . $e->getMessage());
    }
}

$status = $link ? (string) $link['status'] : '';
http_response_code($link ? 410 : 404);
header('Content-Type: text/html; charset=utf-8');

$reason = $status === 'complete'
    ? 'This payment has already been completed — thank you!'
    : 'This payment link is no longer active. Please contact the club and we will send you a new one.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment link &mdash; Saltcoats Victoria FC</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f4f5; color: #2d2b2c; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 1.5rem; }
  .card { background: #fff; border-radius: 14px; box-shadow: 0 10px 40px rgba(0, 0, 0, .08); max-width: 26rem; width: 100%; padding: 2rem; text-align: center; }
  h1 { font-size: 1.25rem; margin: .25rem 0 .75rem; }
  p { margin: 0; line-height: 1.5; color: #555; }
</style>
</head>
<body>
  <div class="card">
    <h1>Payment link unavailable</h1>
    <p><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</body>
</html>
