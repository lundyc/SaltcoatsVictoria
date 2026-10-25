<?php
declare(strict_types=1);

/** Route: /playersponsors/thankyou?access=<token> — token-gated post-payment page.
 *  Presentation is the public-site design; logic mirrors admin/playersponsors_thankyou.php.
   No login required. The Stripe webhook that actually confirms payment runs
   asynchronously, so this page may render before the order's status has
   flipped from pending_payment to paid — it polls playersponsors_status.php
   client-side to catch up. */

require_once HUB_ROOT . '/lib/player_sponsorship_shop.php';
$pdo = db();
ensurePlayerSponsorshipShopSchema($pdo);

$accessToken = (string) ($_GET['access'] ?? '');
$order = $accessToken !== '' ? player_sponsorship_shop_get_order_by_access_token($pdo, $accessToken) : null;
if (!$order && isset($_GET['sent'])) {
    set_meta(['title' => 'Request received', 'robots' => 'noindex, nofollow']);
    partial('page_hero', ['eyebrow' => 'Sponsor a player', 'title' => 'Request received', 'sub' => 'We will be in touch within 3 working days. Nothing has been charged.']);
    echo '<div class="page"><div class="container"><div class="notice notice--ok psthanks"><p>Thank you for your interest in sponsoring a player. The club checks every request and you will only be asked to pay once it is approved.</p></div><p class="psthanks"><a class="btn btn--ghost" href="' . e(url('playersponsors')) . '">Back to sponsor a player</a></p></div></div>';
    return;
}
if (!$order) {
    http_response_code(404);
    set_meta(['title' => 'Order not found', 'robots' => 'noindex, nofollow']);
    partial('page_hero', ['title' => 'Order not found']);
    echo '<div class="page"><div class="container"><div class="emptystate"><p>Sponsorship order not found.</p><p><a class="btn btn--sm" href="' . e(url('playersponsors')) . '">Sponsor a player</a></p></div></div></div>';
    return;
}
$items = player_sponsorship_shop_get_order_items($pdo, (int) $order['id']);
if (in_array((string) $order['status'], ['enquiry', 'approved', 'declined'], true)) {
    $reqStatus = (string) $order['status'];
    set_meta(['title' => 'Request received', 'robots' => 'noindex, nofollow']);
    partial('page_hero', ['eyebrow' => 'Sponsor a player',
        'title' => $reqStatus === 'approved' ? 'Your request is approved' : ($reqStatus === 'declined' ? 'About your request' : 'Request received'),
        'sub' => $reqStatus === 'approved' ? 'Check your email for a secure link to pay.' : ($reqStatus === 'declined' ? 'The club was not able to go ahead with this request.' : 'We will be in touch within 3 working days. Nothing has been charged.')]);
    ?>
<div class="page"><div class="container">
  <aside class="osummary psthanks"><h2>Your request</h2>
    <?php foreach ($items as $item): ?>
      <div class="osummary__row"><span><?= e((string) $item['player_name_snapshot']) ?> &mdash; <?= e(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></span><span><?= e(gbp((float) $item['unit_amount'])) ?></span></div>
    <?php endforeach; ?>
    <div class="osummary__row osummary__row--total"><span><?= $reqStatus === 'enquiry' ? 'Guide total' : 'Total' ?></span><span><?= e(gbp((float) $order['total_amount'])) ?></span></div>
  </aside>
  <?php if ($reqStatus === 'enquiry'): ?><div class="notice notice--ok psthanks"><p>Thank you for your interest in sponsoring a player. The club checks every request and we will email you within 3 working days. You will only be asked to pay once your request has been approved.</p></div><?php endif; ?>
  <?php if ($reqStatus === 'approved'): ?><div class="notice notice--ok psthanks"><p>Good news &mdash; the club has approved your sponsorship. We have emailed you a link to pay securely by card.</p></div><?php endif; ?>
  <p class="psthanks"><a class="btn btn--ghost" href="<?= e(url('playersponsors')) ?>">Back to sponsor a player</a></p>
</div></div>
<?php
    return;
}
$statusUrl = '/playersponsors_status.php?access=' . rawurlencode($accessToken);
?>
<?php set_meta(['title' => 'Thank you', 'robots' => 'noindex, nofollow']); seo_breadcrumbs([['Commercial', url('commercial')], ['Sponsor a player', url('playersponsors')], ['Thank you', url('playersponsors/thankyou')]]); ?>
<section class="pagehero" id="heroBlock">
  <div class="container">
    <span class="eyebrow">Sponsor a player</span>
    <h1 id="heroTitle">Confirming your payment&hellip;</h1>
    <p class="pagehero__sub" id="heroSubtitle">Please wait a moment while we confirm this with Stripe.</p>
  </div>
</section>

<div class="page">
  <div class="container">
    <aside class="osummary psthanks">
      <h2>Order #<?= (int) $order['id'] ?></h2>
      <div id="itemsList">
        <?php foreach ($items as $item): ?>
          <div class="osummary__row" data-item-row>
            <span><?= e((string) $item['player_name_snapshot']) ?> &mdash; <?= e(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></span>
            <span><?= e(gbp((float) $item['unit_amount'])) ?> <span class="pstag pstag--wait" data-item-status>Confirming&hellip;</span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e(gbp((float) $order['total_amount'])) ?></span></div>
    </aside>
    <div class="notice notice--ok psthanks" id="thankYouNote" hidden><p>A payment receipt and a thank-you email are on their way to <strong><?= e((string) $order['buyer_email']) ?></strong>. On behalf of everyone at the club — thank you for your support!</p></div>
    <div class="notice notice--warn psthanks" id="conflictNote" hidden><p>One or more items in this order had just been sponsored by someone else moments before your payment. You were not charged for those, and the club will be in touch to sort out a refund or an alternative.</p></div>
    <p class="psthanks"><a class="btn btn--ghost" href="<?= e(url('playersponsors')) ?>">Sponsor another player</a></p>
  </div>
</div>
<script>(() => {
    const statusUrl = <?= json_encode($statusUrl) ?>;
    const heroTitle = document.getElementById('heroTitle');
    const heroSubtitle = document.getElementById('heroSubtitle');
    const thankYouNote = document.getElementById('thankYouNote');
    const conflictNote = document.getElementById('conflictNote');
    const rows = Array.from(document.querySelectorAll('[data-item-row]'));

    const applyStatus = (payload) => {
        if (payload.status === 'pending_payment') {
            return false;
        }
        const items = payload.items || [];
        rows.forEach((row, index) => {
            const badge = row.querySelector('[data-item-status]');
            const item = items[index];
            if (!badge || !item) return;
            if (item.status === 'confirmed') {
                badge.textContent = 'Confirmed';
                badge.className = 'pstag pstag--added';
            } else if (item.status === 'conflict_refund_due') {
                badge.textContent = 'Not available';
                badge.className = 'pstag pstag--taken';
            }
        });
        const anyConfirmed = items.some((item) => item.status === 'confirmed');
        const anyConflict = items.some((item) => item.status === 'conflict_refund_due');
        heroTitle.textContent = anyConfirmed ? 'Thank you!' : 'We hit a snag';
        heroSubtitle.textContent = anyConfirmed
            ? 'Your sponsorship is confirmed.'
            : 'None of the items in this order could be completed — see below.';
        if (anyConfirmed) thankYouNote.hidden = false;
        if (anyConflict) conflictNote.hidden = false;
        return true;
    };

    let attempts = 0;
    const poll = async () => {
        attempts += 1;
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (response.ok && payload.ok && applyStatus(payload)) {
                return; // Settled — stop polling.
            }
        } catch (error) {
            // Network hiccup — just try again on the next tick.
        }
        if (attempts < 40) {
            setTimeout(poll, 2500);
        } else {
            heroSubtitle.textContent = 'Still confirming — refresh this page in a moment, or check your email for the receipt.';
        }
    };
    poll();
})();</script>
