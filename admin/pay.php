<?php
declare(strict_types=1);

/**
 * Public short-link resolver for Stripe payment links.
 *
 * A sponsor is handed https://saltcoatsvictoria.co.uk/p/<slug> (rewritten to pay.php?c=<slug>
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
  body { font-family: "Roboto", system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8f2e9; color: #3b3538; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 1.5rem; }
  .card { background: #fff; border: 1px solid #e7dccb; border-top: 4px solid #e0b42a; border-radius: 10px; box-shadow: 0 8px 24px rgba(58, 15, 25, .11); max-width: 28rem; width: 100%; padding: 2.2rem 2rem; text-align: center; }
  .card img { height: 64px; margin-bottom: 1rem; }
  h1 { font-family: "Montserrat", system-ui, sans-serif; font-size: 1.4rem; margin: .25rem 0 .75rem; color: #4c1521; }
  p { margin: 0 0 1.4rem; line-height: 1.55; color: #5c5256; }
  a.btn { display: inline-block; padding: .75rem 1.4rem; border-radius: 6px; background: #6d2231; color: #fff; font-weight: 700; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1><?= $status === 'complete' ? 'Payment complete' : 'Payment link unavailable' ?></h1>
    <p><?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="btn" href="/">Back to the club website</a>
  </div>
</body>
</html>
