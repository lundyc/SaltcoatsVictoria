<?php
/** Route: /tickets/order/{token} — digital match tickets. Mirrors live
 *  ticket_order.php (QR carousel), in the public-site design. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/match_tickets.php';
ensureMatchTicketSchema(db());
require_once HUB_ROOT . '/lib/google_wallet.php';

$accessToken = match_ticket_extract_token((string) ($token ?? ''));
$order = $accessToken !== '' ? getMatchTicketOrderByAccessToken(db(), $accessToken) : null;
if (!$order) {
    http_response_code(404);
    set_meta(['title' => 'Ticket order not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Ticket order not found</h1><p><a class="linkarrow" href="' . e(url('tickets')) . '">Buy match tickets</a></p></div></div>';
    return;
}

$items = getMatchTicketOrderItems(db(), (int) $order['id']);
$tickets = getMatchTicketsForOrder(db(), (int) $order['id']);
if (isset($_GET['status'])) { // polled by this page so a ticket turns green once it has been scanned
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'tickets' => array_map(static fn (array $t): array => [
        'token' => (string) $t['ticket_token'], 'checked_in' => !empty($t['checked_in_at']),
    ], $tickets)]);
    exit;
}
$accessPath = 'tickets/order/' . rawurlencode((string) $order['access_token']);
$statusUrl = url($accessPath) . '?status=1';
$selectedToken = match_ticket_extract_token((string) ($_GET['ticket'] ?? ''));
$initialIndex = 0;
foreach ($tickets as $k => $t) {
    if ($selectedToken !== '' && hash_equals((string) $t['ticket_token'], $selectedToken)) { $initialIndex = $k; break; }
}
$walletOn = wallet_is_configured(db());
$walletCfg = $walletOn ? wallet_config(db()) : [];

set_meta(['title' => 'Tickets — ' . club('club_short_name') . ' v ' . (string) $order['opponent']]);
seo_breadcrumbs([['Match tickets', url('tickets')], ['Your order', url('tickets')]]);
?>
<section class="mc-hero">
  <div class="container">
    <p class="mc-hero__comp">Digital match tickets</p>
    <div class="mc-hero__mid" style="margin-inline:auto">
      <b class="mc-hero__ko"><?= e(club('club_short_name')) ?> v <?= e((string) $order['opponent']) ?></b>
      <span class="mc-hero__date"><?= e(format_date($order['match_date'], 'D j M Y')) ?><?= !empty($order['kickoff_time']) ? ' · ' . e(format_time($order['kickoff_time'])) : '' ?></span>
    </div>
  </div>
</section>

<div class="page">
  <div class="container" style="max-width:720px">
    <?php if (isset($_GET['paid'])): ?><div class="notice notice--ok"><p>Payment received. Your digital tickets are ready.</p></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice--ok"><p>Your ticket order has been saved.</p></div><?php endif; ?>
    <?php if ((int) $order['paid'] !== 1): ?><div class="notice notice--warn"><p>This order is not marked as paid yet. The QR codes will scan only once payment is confirmed.</p></div><?php endif; ?>

    <h2>Your tickets</h2>
    <div class="tcarousel-shell">
      <div class="tcarousel-viewport">
        <div class="tcarousel" data-ticket-carousel data-initial-index="<?= (int) $initialIndex ?>">
          <?php foreach ($tickets as $index => $ticket): ?>
            <?php $ticketUrl = current_url_origin() . url($accessPath) . '?ticket=' . rawurlencode((string) $ticket['ticket_token']); ?>
            <?php $tkLabel = strtolower((string) $ticket['ticket_label']); $tkKind = str_contains($tkLabel, 'child') ? 'child' : (str_contains($tkLabel, 'concession') ? 'concession' : 'adult'); ?>
            <?php $tkDone = !empty($ticket['checked_in_at']); ?>
            <article class="tk tk--<?= $tkKind ?> tk-slide<?= $tkDone ? ' is-used' : '' ?>" data-ticket-token="<?= e((string) $ticket['ticket_token']) ?>">
              <div class="tk-top">
                <span class="tk-type"><?= e((string) $ticket['ticket_label']) ?></span>
                <span class="tk-num">Ticket <?= $index + 1 ?> of <?= count($tickets) ?></span>
              </div>
              <div class="tk-scan">
                <div class="tk-qr" data-qr data-qr-url="<?= e($ticketUrl) ?>"></div>
                <div class="tk-hint">Scan at the turnstile</div>
              </div>
              <div class="tk-perf" aria-hidden="true"></div>
              <div class="tk-stub">
                <div class="tk-stub-label">Entry code</div>
                <div class="tk-code"><?= e((string) $ticket['manual_code']) ?></div>
                <div class="tk-used<?= $tkDone ? '' : ' is-hidden' ?>" data-ticket-used-label>&#10003; Checked in</div>
              </div>
              <?php if ($walletOn && (int) $order['paid'] === 1 && !$tkDone): ?>
                <div class="tk-extra">
                  <?php try { $walletUrl = wallet_save_url(wallet_build_match_jwt($walletCfg, $order, $ticket, $ticketUrl, club('club_short_name'), club('ground_name'), current_url_origin() . '/scan/icon-512.png')); } catch (Throwable) { $walletUrl = ''; } ?>
                  <?php if ($walletUrl !== ''): ?><?= wallet_button_html($walletUrl) ?><?php endif; ?>
                </div>
              <?php endif; ?>
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

    <div class="mc-panel" style="margin-top:1.5rem">
      <h2>Order summary</h2>
      <?php foreach ($items as $item): ?>
        <div class="kv"><span><?= e((string) $item['package_name']) ?> ×<?= (int) $item['quantity'] ?></span><span><?= e(gbp((float) $item['unit_price'] * (int) $item['quantity'])) ?></span></div>
      <?php endforeach; ?>
      <div class="kv kv--total"><span>Total</span><span><?= e(gbp((float) $order['total_amount'])) ?></span></div>
    </div>
  </div>
</div>

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
    var i = Math.max(0, Math.min(slides.length - 1, Number(carousel.dataset.initialIndex || 0)));
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
    carousel.style.scrollBehavior = 'auto'; carousel.scrollLeft = i * width(); carousel.style.scrollBehavior = ''; update();
  }
  var statusUrl = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
  var seen = new Set();
  var poll = function () {
    fetch(statusUrl, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (p) {
      if (!p || !p.ok || !Array.isArray(p.tickets)) return;
      p.tickets.forEach(function (t) {
        if (!t.checked_in || seen.has(t.token)) return;
        seen.add(t.token);
        var card = document.querySelector('[data-ticket-token="' + (window.CSS && CSS.escape ? CSS.escape(t.token) : t.token) + '"]');
        if (card) { card.classList.add('is-used'); var l = card.querySelector('[data-ticket-used-label]'); if (l) l.classList.remove('is-hidden'); var wb = card.querySelector('.tk-extra'); if (wb) wb.style.display = 'none'; }
      });
    }).catch(function () {});
  };
  poll();
  setInterval(poll, 3000);
})();
</script>
