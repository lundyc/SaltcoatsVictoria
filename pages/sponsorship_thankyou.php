<?php
/** Route: /sponsorship/thankyou/<checkout session id> — where Stripe returns a sponsor after paying a sponsorship link. No login. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/stripe.php';

$pdo = db();
ensureStripeSchema($pdo);

// The Checkout session id is the only key here, and it is unguessable. A paid session can
// also show up here before the webhook has marked its rows complete, so Stripe is asked
// directly as a fallback.
$sessionId = (string) ($checkout ?? '');
$links = [];
if (preg_match('/^cs_[A-Za-z0-9_]{10,}$/', $sessionId)) {
    $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE stripe_checkout_session_id = :session_id ORDER BY id');
    $stmt->execute([':session_id' => $sessionId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$paid = false;
foreach ($links as $link) {
    if ((string) $link['status'] === 'complete') {
        $paid = true;
    }
}
if ($links && !$paid) {
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
        $paid = (string) ($session['payment_status'] ?? '') === 'paid';
    } catch (Throwable $e) {
        $paid = false;
    }
}

$items = [];
foreach ($links as $link) {
    $agreement = getSponsorshipAgreement($pdo, (int) $link['agreement_id']);
    if ($agreement) {
        $items[] = ['agreement' => $agreement, 'amount' => (float) $link['amount']];
    }
}

set_meta(['title' => $paid ? 'Thank you' : 'Payment confirmation', 'robots' => 'noindex, nofollow']);
if (!$items) {
    http_response_code(404);
    partial('page_hero', ['title' => 'Payment not found']);
    echo '<div class="page"><div class="container"><div class="emptystate"><p>We couldn\'t find that payment.</p><p><a class="btn btn--sm" href="' . e(url('commercial')) . '">Sponsorship</a></p></div></div></div>';
    return;
}

$total = 0.0;
foreach ($items as $item) {
    $total += $item['amount'];
}
$title = $paid ? 'Thank you!' : 'Payment received';
$sub = $paid
    ? 'Your sponsorship payment has been received.'
    : 'We are still confirming your payment with Stripe. A receipt will be emailed to you once it is confirmed.';
?>
<?php partial('page_hero', ['eyebrow' => 'Sponsorship', 'title' => $title, 'sub' => $sub]); ?>
<div class="page">
  <div class="container">
    <aside class="osummary psthanks">
      <h2>Your payment</h2>
      <?php foreach ($items as $item): ?>
        <?php foreach (stripe_agreement_context_lines($item['agreement']) as $line): ?>
          <div class="osummary__row"><span><?= e($line['label']) ?></span><span><?= e($line['value']) ?></span></div>
        <?php endforeach; ?>
        <div class="osummary__row"><span>Amount paid</span><span><?= e(gbp($item['amount'])) ?></span></div>
      <?php endforeach; ?>
      <?php if (count($items) > 1): ?>
        <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e(gbp($total)) ?></span></div>
      <?php endif; ?>
    </aside>
    <div class="notice notice--ok psthanks"><p>Thanks for your support of <?= e(club('club_name')) ?>.</p></div>
    <p class="psthanks"><a class="btn btn--ghost" href="<?= e(url('commercial')) ?>">Back to sponsorship</a></p>
  </div>
</div>
