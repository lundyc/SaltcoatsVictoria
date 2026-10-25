<?php
/** Route: /playersponsors/pay?access=<token> — the link in the approval email. Starts a fresh Stripe Checkout on each visit, so the link never goes stale. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/player_sponsorship_shop.php';
require_once HUB_ROOT . '/lib/stripe.php';

$pdo = db();
ensurePlayerSponsorshipShopSchema($pdo);
$order = player_sponsorship_shop_get_order_by_access_token($pdo, (string) ($_GET['access'] ?? ''));
set_meta(['title' => 'Pay for your sponsorship', 'robots' => 'noindex, nofollow']);

$problem = '';
if (!$order) {
    $problem = 'We could not find that payment link. Please check you used the full link from your email.';
} elseif (in_array((string) $order['status'], ['paid', 'partially_conflicted'], true)) {
    redirect(url('playersponsors/thankyou') . '?access=' . rawurlencode((string) $order['access_token']));
} elseif ((string) $order['status'] !== 'approved') {
    $problem = 'This sponsorship is not ready for payment. If you think that is a mistake, please contact the club.';
} elseif (!stripe_is_configured()) {
    $problem = 'Online payment is not available right now. Please contact the club.';
} else {
    try {
        $base = current_url_origin();
        $checkout = player_sponsorship_shop_stripe_create_checkout_session(
            $pdo, (int) $order['id'],
            $base . url('playersponsors/thankyou') . '?access=' . rawurlencode((string) $order['access_token']),
            $base . url('playersponsors/pay') . '?access=' . rawurlencode((string) $order['access_token']) . '&cancelled=1'
        );
        redirect($checkout['url']);
    } catch (Throwable $e) {
        error_log('[player_sponsorship] pay link failed for order #' . (int) $order['id'] . ': ' . $e->getMessage());
        $problem = 'Sorry, we could not start the payment. Please try again, or contact the club.';
    }
}
?>
<?php partial('page_hero', ['eyebrow' => 'Sponsorship', 'title' => 'Payment']); ?>
<div class="page"><div class="container"><div class="notice notice--err"><p><?= e($problem) ?></p></div>
<p><a class="btn btn--ghost" href="<?= e(url('contact')) ?>">Contact the club</a></p></div></div>
