<?php
/** Route: /events/order/{token} — digital event tickets (QR) and order status. Reuses the match-ticket
 *  card carousel styling. Orders are found by their private access token only. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/events_orders.php';
require_once HUB_ROOT . '/lib/events_mail.php';
require_once HUB_ROOT . '/lib/events_admin.php';
require_once HUB_ROOT . '/lib/google_wallet.php';

ensureEventOrdersSchema(db());
$accessToken = (string) ($token ?? '');
$order = preg_match('/^[a-f0-9]{32,64}$/i', $accessToken) ? events_order_by_token(db(), strtolower($accessToken)) : null;
if (!$order) {
    http_response_code(404);
    set_meta(['title' => 'Ticket order not found', 'robots' => 'noindex, nofollow']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Ticket order not found</h1><p><a class="linkarrow" href="' . e(url('events')) . '">See events</a></p></div></div>';
    return;
}

$renameError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    try {
        if (!csrf_check()) {
            throw new RuntimeException('Your session expired. Please try again.');
        }
        events_change_attendee_name(db(), (int) ($_POST['ticket_id'] ?? 0), (string) ($_POST['name'] ?? ''), 'buyer', null, (int) $order['id']);
        redirect(url('events/order/' . rawurlencode($accessToken)) . '?renamed=1');
    } catch (Throwable $ex) {
        $renameError = $ex->getMessage();
    }
}

$tickets = events_order_tickets(db(), (int) $order['id']);
if (isset($_GET['status'])) { // polled by the ticket page so a ticket turns green once scanned
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'tickets' => array_map(static fn (array $t): array => [
        'token' => (string) $t['ticket_token'], 'admitted' => (int) $t['admitted_qty'], 'admits' => (int) $t['admits_count'],
        'checked_in' => (int) $t['admitted_qty'] >= max(1, (int) $t['admits_count']),
    ], $tickets)]);
    exit;
}
$items = events_order_items(db(), (int) $order['id']);
$event = $tickets ? events_get(db(), (int) $tickets[0]['event_id']) : null;
$isPaid = in_array($order['status'], ['paid', 'complete', 'partially_refunded'], true);
$pending = $order['status'] === 'pending_payment' && $order['hold_state'] === 'held' && strtotime((string) $order['hold_expires_at']) > time();
$needsReview = $order['status'] === 'needs_refund';
$accessParam = rawurlencode((string) $accessToken);
$walletOn = wallet_is_configured(db());
$walletCfg = $walletOn ? wallet_config(db()) : [];

set_meta(['title' => 'Your tickets' . ($event ? ' — ' . $event['title'] : ''), 'robots' => 'noindex, nofollow']);
seo_breadcrumbs([['Events', url('events')], ['Your tickets', url('events')]]);
if ($pending && isset($_GET['paid'])) {
    // The payment confirmation can take a few seconds to arrive from Stripe.
    header('Refresh: 5');
}
?>
<section class="mc-hero">
  <div class="container">
    <p class="mc-hero__comp">Digital event tickets</p>
    <div class="mc-hero__mid" style="margin-inline:auto">
      <b class="mc-hero__ko"><?= e($event ? (string) $event['title'] : 'Event tickets') ?></b>
      <?php if ($event): ?><span class="mc-hero__date"><?= e(format_date($event['starts_at'], 'D j M Y')) ?> · <?= e(format_time($event['starts_at'])) ?> · <?= e((string) $event['venue_name']) ?></span><?php endif; ?>
    </div>
  </div>
</section>

<div class="page">
  <div class="container" style="max-width:720px">
    <?php if (isset($_GET['renamed'])): ?><div class="notice notice--ok"><p>The name on the ticket has been changed.</p></div><?php endif; ?>
    <?php if ($renameError !== ''): ?><div class="notice notice--err"><p><?= e($renameError) ?></p></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice--ok"><p>Your tickets are confirmed. We have emailed them to you too.</p></div><?php endif; ?>
    <?php if ($isPaid && isset($_GET['paid'])): ?><div class="notice notice--ok"><p>Payment received. Your digital tickets are ready.</p></div><?php endif; ?>
    <?php if ($pending): ?><div class="notice notice--warn"><p>We are waiting for your payment to be confirmed. This page refreshes by itself. The tickets below only work once payment is confirmed.</p></div><?php endif; ?>
    <?php if ($needsReview): ?><div class="notice notice--err"><p>Your payment arrived after your tickets had been released and the event no longer has room. We will refund you in full — please contact the club if you do not hear from us within a few days.</p></div><?php endif; ?>
    <?php if (in_array($order['status'], ['expired', 'cancelled'], true)): ?><div class="notice notice--warn"><p>This order was not completed, so there are no tickets on it. <a href="<?= e($event ? url('events/' . $event['slug']) : url('events')) ?>">Start again</a>.</p></div><?php endif; ?>
    <?php if ($event && in_array($event['status'], ['cancelled'], true)): ?><div class="notice notice--err"><p><strong>This event has been cancelled.</strong> You will be refunded in full.</p></div><?php endif; ?>
    <?php if ($event && $event['status'] === 'postponed'): ?><div class="notice notice--warn"><p><strong>This event has been postponed.</strong> Your tickets stay valid for the new date.</p></div><?php endif; ?>

    <?php if ($isPaid && $tickets): ?>
      <?php if ($event && (int) $event['min_age'] > 0): ?><div class="notice notice--warn"><p><strong>Age limit: <?= e(EVENT_MIN_AGES[(int) $event['min_age']]) ?>.</strong> <?= e((string) ($event['age_note'] ?? '')) ?></p></div><?php endif; ?>
      <h2>Your tickets</h2>
      <div class="tcarousel-shell">
        <div class="tcarousel-viewport">
          <div class="tcarousel" data-ticket-carousel>
            <?php foreach ($tickets as $index => $ticket): ?>
              <?php $ticketUrl = current_url_origin() . url('events/order/' . $accessParam) . '?ticket=' . rawurlencode((string) $ticket['ticket_token']); ?>
              <?php $tkDone = (int) $ticket['admitted_qty'] >= max(1, (int) $ticket['admits_count']); ?>
              <article class="tk tk-slide<?= $tkDone ? ' is-used' : '' ?>" data-ticket-token="<?= e((string) $ticket['ticket_token']) ?>" data-admits="<?= (int) $ticket['admits_count'] ?>">
                <div class="tk-top">
                  <span class="tk-type"><?= e((string) $ticket['ticket_label']) ?></span>
                  <span class="tk-num">Ticket <?= $index + 1 ?> of <?= count($tickets) ?></span>
                </div>
                <?php if (!empty($ticket['attendee_name']) || (int) $ticket['admits_count'] > 1): ?><div class="tk-sub"><?= e((string) $ticket['attendee_name']) ?><?= (int) $ticket['admits_count'] > 1 ? ((!empty($ticket['attendee_name']) ? ' · ' : '') . 'Admits ' . (int) $ticket['admits_count']) : '' ?></div><?php endif; ?>
                <div class="tk-scan">
                  <div class="tk-qr" data-qr data-qr-url="<?= e($ticketUrl) ?>"></div>
                  <div class="tk-hint">Scan at the door</div>
                </div>
                <div class="tk-perf" aria-hidden="true"></div>
                <div class="tk-stub">
                  <div class="tk-stub-label">Entry code</div>
                  <div class="tk-code"><?= e((string) $ticket['manual_code']) ?></div>
                  <div class="tk-used<?= $tkDone ? '' : ' is-hidden' ?>" data-ticket-used-label>&#10003; Checked in</div>
                </div>
                <div class="tk-extra">
                <?php if ($event && $walletOn && !$tkDone): ?>
                  <?php try { $walletUrl = wallet_save_url(wallet_build_jwt($walletCfg, $event, $ticket, $ticketUrl, current_url_origin() . '/scan/icon-512.png')); } catch (Throwable) { $walletUrl = ''; } ?>
                  <?php if ($walletUrl !== ''): ?><?= wallet_button_html($walletUrl) ?><?php endif; ?>
                <?php endif; ?>
                
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (count($tickets) > 1): ?>
          <div class="tcarousel-controls">
            <button type="button" data-ticket-prev aria-label="Previous ticket">‹</button>
            <span data-ticket-count>1 / <?= count($tickets) ?></span>
            <button type="button" data-ticket-next aria-label="Next ticket">›</button>
          </div>
        <?php endif; ?>
      </div>
      <p class="tkt-summary__note">Keep this page: bookmark it or find the link in your confirmation email. Show the QR code on your phone at the door.</p>
    <?php endif; ?>

    <div class="mc-panel" style="margin-top:1.5rem">
      <h2>Order summary</h2>
      <?php foreach ($items as $item): ?>
        <div class="kv"><span><?= $item['product_type'] === 'booking_fee' ? 'Booking fee' : e((string) $item['description_snapshot']) . ' ×' . (int) $item['quantity'] ?></span><span><?= e(gbp((float) $item['line_total'])) ?></span></div>
      <?php endforeach; ?>
      <div class="kv kv--total"><span>Total</span><span><?= e(gbp((float) $order['total_amount'])) ?></span></div>
    </div>
  </div>
</div>

<?php if ($isPaid && $tickets): ?>
<script src="<?= e(asset('js/vendor/qrcode.min.js')) ?>"></script>
<script>
(function () {
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, { text: el.dataset.qrUrl, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.H });
  });
  var carousel = document.querySelector('[data-ticket-carousel]');
  if (carousel) {
    var slides = Array.prototype.slice.call(carousel.querySelectorAll('.tk-slide'));
    var prev = document.querySelector('[data-ticket-prev]');
    var next = document.querySelector('[data-ticket-next]');
    var count = document.querySelector('[data-ticket-count]');
    var i = 0;
    var width = function () { return carousel.clientWidth || 1; };
    var update = function () {
      i = Math.max(0, Math.min(slides.length - 1, Math.round(carousel.scrollLeft / width())));
      if (count) count.textContent = (i + 1) + ' / ' + slides.length;
      if (prev) prev.disabled = i <= 0;
      if (next) next.disabled = i >= slides.length - 1;
    };
    var go = function (n) { i = Math.max(0, Math.min(slides.length - 1, n)); carousel.scrollTo({ left: i * width(), behavior: 'smooth' }); update(); };
    if (prev) prev.addEventListener('click', function () { go(i - 1); });
    if (next) next.addEventListener('click', function () { go(i + 1); });
    carousel.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
    window.addEventListener('resize', function () { go(i); });
    update();
  }
  var statusUrl = <?= json_encode(url('events/order/' . $accessParam) . '?status=1', JSON_UNESCAPED_SLASHES) ?>;
  var poll = function () {
    fetch(statusUrl, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (p) {
      if (!p || !p.ok) return;
      p.tickets.forEach(function (t) {
        var card = document.querySelector('[data-ticket-token="' + t.token + '"]');
        if (!card) return;
        card.classList.toggle('is-used', !!t.checked_in);
        var label = card.querySelector('[data-ticket-used-label]');
        if (label) {
          label.classList.toggle('is-hidden', !(t.checked_in || t.admitted > 0));
          label.textContent = t.checked_in ? '\u2713 Checked in' : (t.admitted + ' of ' + t.admits + ' people in');
        }
        var wb = card.querySelector('.gwallet-btn'); if (wb && t.checked_in) wb.parentNode.style.display = 'none';
      });
    }).catch(function () {});
  };
  poll();
  setInterval(poll, 3000);
})();
</script>
<?php endif; ?>
