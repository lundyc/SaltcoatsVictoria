<?php
declare(strict_types=1);

// Public, token-gated post-payment landing page — /playersponsors_thankyou.php?access=<token>.
// No login required. The Stripe webhook that actually confirms payment runs
// asynchronously, so this page may render before the order's status has
// flipped from pending_payment to paid — it polls playersponsors_status.php
// client-side to catch up, same pattern as ticket_order.php / ticket_order_status.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
ensurePlayerSponsorshipShopSchema($pdo);

$accessToken = (string) ($_GET['access'] ?? '');
$order = $accessToken !== '' ? player_sponsorship_shop_get_order_by_access_token($pdo, $accessToken) : null;
if (!$order) {
    http_response_code(404);
    exit('Sponsorship order not found.');
}
$items = player_sponsorship_shop_get_order_items($pdo, (int) $order['id']);
$statusUrl = '/playersponsors_status.php?access=' . rawurlencode($accessToken);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank You - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <style>
        :root { --ps-maroon: #4b0818; --ps-gold: #e0b42a; --ps-cream: #f6ecde; }
        body { background: var(--ps-cream); font-family: 'Inter', sans-serif; margin: 0; }
        .ps-hero { background: linear-gradient(135deg, var(--ps-maroon), #6a2036); color: #fff; padding: 2.5rem 1rem; text-align: center; }
        .ps-hero i { font-size: 2.4rem; margin-bottom: .5rem; }
        .ps-wrap { max-width: 720px; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
        .ps-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1.25rem; margin-bottom: 1rem; }
        .ps-item-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px solid #eee; gap: .5rem; }
        .ps-item-row:last-child { border-bottom: 0; }
        .ps-total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; margin-top: .5rem; color: var(--ps-maroon); }
        .ps-status-badge { display: inline-block; padding: .3rem .7rem; border-radius: 999px; font-size: .8rem; font-weight: 700; }
        .ps-status-confirmed { background: #e6f6ec; color: #1b7a3e; }
        .ps-status-conflict { background: #fff4e5; color: #7a4a06; }
        .ps-status-pending { background: #eee; color: #666; }
    </style>
</head>
<body>
<div class="ps-hero" id="heroBlock">
    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
    <h1 class="h3 mb-1" id="heroTitle">Confirming your payment&hellip;</h1>
    <p class="mb-0" id="heroSubtitle">Please wait a moment while we confirm this with Stripe.</p>
</div>
<div class="ps-wrap">
    <div class="ps-card">
        <h2 class="h5" style="color:var(--ps-maroon);">Order #<?= (int) $order['id'] ?></h2>
        <div id="itemsList">
            <?php foreach ($items as $item): ?>
                <div class="ps-item-row" data-item-row>
                    <span><?= h((string) $item['player_name_snapshot']) ?> &mdash; <?= h(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></span>
                    <span class="d-flex align-items-center gap-2">
                        <?= gbp((float) $item['unit_amount']) ?>
                        <span class="ps-status-badge ps-status-pending" data-item-status>Confirming&hellip;</span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ps-total-row"><span>Total</span><span><?= gbp((float) $order['total_amount']) ?></span></div>
    </div>
    <div class="ps-card" id="thankYouNote" style="display:none;">
        <p class="mb-0">A payment receipt and a thank-you email are on their way to <strong><?= h((string) $order['buyer_email']) ?></strong>. On behalf of everyone at the club — thank you for your support!</p>
    </div>
    <div class="ps-card" id="conflictNote" style="display:none;">
        <p class="mb-0 text-warning-emphasis">One or more items in this order had just been sponsored by someone else moments before your payment. You were not charged for those, and the club will be in touch to sort out a refund or an alternative.</p>
    </div>
    <a href="/playersponsors" class="btn btn-outline-secondary">Sponsor another player</a>
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
                badge.className = 'ps-status-badge ps-status-confirmed';
            } else if (item.status === 'conflict_refund_due') {
                badge.textContent = 'Not available';
                badge.className = 'ps-status-badge ps-status-conflict';
            }
        });
        const anyConfirmed = items.some((item) => item.status === 'confirmed');
        const anyConflict = items.some((item) => item.status === 'conflict_refund_due');
        heroTitle.textContent = anyConfirmed ? 'Thank you!' : 'We hit a snag';
        heroSubtitle.textContent = anyConfirmed
            ? 'Your sponsorship is confirmed.'
            : 'None of the items in this order could be completed — see below.';
        if (anyConfirmed) thankYouNote.style.display = '';
        if (anyConflict) conflictNote.style.display = '';
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
</body>
</html>
