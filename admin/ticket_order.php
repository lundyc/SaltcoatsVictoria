<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);
require_once __DIR__ . '/lib/google_wallet.php';
require_once __DIR__ . '/lib/site_settings.php';
$walletOn = wallet_is_configured($pdo);
$walletCfg = $walletOn ? wallet_config($pdo) : [];

$accessToken = match_ticket_extract_token((string) ($_GET['access'] ?? ($_GET['group'] ?? '')));
$order = $accessToken !== '' ? getMatchTicketOrderByAccessToken($pdo, $accessToken) : null;
if (!$order) {
    http_response_code(404);
    exit('Ticket order not found.');
}
// This is a public page: send people (and old QR/email links) to its proper address outside /admin.
if (!empty($order['access_token']) && isset($_GET['access'])) {
    $ticketToken = match_ticket_extract_token((string) ($_GET['ticket'] ?? ''));
    $qs = [];
    foreach (['paid', 'saved'] as $flag) {
        if (isset($_GET[$flag])) { $qs[] = $flag . '=1'; }
    }
    if ($ticketToken !== '') { $qs[] = 'ticket=' . rawurlencode($ticketToken); }
    header('Location: /tickets/order/' . rawurlencode((string) $order['access_token']) . ($qs ? '?' . implode('&', $qs) : ''), true, 301);
    exit;
}
$items = getMatchTicketOrderItems($pdo, (int) $order['id']);
$tickets = getMatchTicketsForOrder($pdo, (int) $order['id']);
$selectedTicketToken = match_ticket_extract_token((string) ($_GET['ticket'] ?? ''));
$initialTicketIndex = 0;
foreach ($tickets as $index => $ticket) {
    if ($selectedTicketToken !== '' && hash_equals((string) $ticket['ticket_token'], $selectedTicketToken)) {
        $initialTicketIndex = $index;
        break;
    }
}
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
$accessParam = !empty($order['access_token']) ? 'access=' . rawurlencode((string) $order['access_token']) : 'group=' . rawurlencode((string) $order['group_token']);
$statusUrl = '/ticket_order_status.php?' . $accessParam;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4b0818">
    <title>Digital Match Tickets - <?= h(APP_NAME) ?></title>
    <link href="/admin/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <style>
        html, body { min-height:100%; overflow-y:auto; }
        body { background:#f6ecde; font-family:Inter,system-ui,sans-serif; }
        .ticket-wrap { max-width:960px; margin:0 auto; padding:1.25rem 1rem 4rem; }
        .ticket-hero { color:#fff; background:linear-gradient(135deg,#4b0818,#8d1b39); border-radius:0; padding:2rem 0; margin:0; width:100%; }
        .ticket-hero__inner { max-width:960px; margin:0 auto; padding:0 1rem; }
        .ticket-card { background:#fff; border:1px solid rgba(75,8,24,.1); border-radius:16px; box-shadow:0 12px 30px rgba(33,20,26,.08); overflow:hidden; margin-bottom:1rem; }
        .ticket-card__body { padding:1.2rem; }
        .ticket-qr { display:inline-grid; place-items:center; padding:18px; border:1px solid #eee; border-radius:14px; background:#fff; }
        /* Matchday ticket: maroon header, QR, perforation with notches, stub with the manual code */
        .tk { border:0; border-radius:18px; box-shadow:0 14px 34px rgba(33,20,26,.14); }
        .tk-top { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.95rem 1.2rem; color:#fff; background:linear-gradient(135deg,#4b0818,#8d1b39); }
        .tk-type { font-weight:800; font-size:1.2rem; letter-spacing:.01em; }
        .tk-num { font-size:.72rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; padding:.3rem .65rem; border-radius:999px; background:rgba(255,255,255,.16); white-space:nowrap; }
        /* Per-type accent colours, used by the moving QR border */
        .tk { --tk-a:#4b0818; --tk-b:#8d1b39; }
        .tk--child { --tk-a:#0b5d68; --tk-b:#1a9aa8; }
        .tk--concession { --tk-a:#7a4f06; --tk-b:#b97d0c; }
        .tk-scan { padding:1.4rem 1.2rem 1.2rem; text-align:center; background:#fff; }
        /* QR code framed by a light that travels round the edge, so the live ticket is easy to tell from a still screenshot */
        .tk-scan .ticket-qr { position:relative; overflow:hidden; isolation:isolate; border:0; border-radius:18px; padding:21px; background:var(--tk-a); }
        .tk-scan .ticket-qr::before { content:""; position:absolute; left:50%; top:50%; width:220%; aspect-ratio:1; z-index:-2; transform:translate(-50%,-50%) rotate(0deg); background:conic-gradient(var(--tk-a) 0deg 110deg, var(--tk-b) 190deg, #fff 285deg, var(--tk-b) 335deg, var(--tk-a) 360deg); animation:tk-spin 2.4s linear infinite; }
        .tk-scan .ticket-qr::after { content:""; position:absolute; inset:6px; z-index:-1; border-radius:14px; background:#fff; }
        .tk-scan .ticket-qr > * { position:relative; z-index:1; }
        @keyframes tk-spin { to { transform:translate(-50%,-50%) rotate(360deg); } }
        @media (prefers-reduced-motion:reduce) { .tk-scan .ticket-qr::before { animation:none; } }
        .tk-hint { margin-top:.8rem; color:#6f6470; font-size:.82rem; font-weight:600; }
        .tk-perf { position:relative; height:0; border-top:2px dashed #d9cbcd; margin:0 1.1rem; }
        .tk-perf::before, .tk-perf::after { content:""; position:absolute; top:-13px; width:24px; height:24px; border-radius:50%; background:#f6ecde; box-shadow:inset 0 0 0 1px rgba(75,8,24,.08); }
        .tk-perf::before { left:-1.1rem; margin-left:-12px; }
        .tk-perf::after { right:-1.1rem; margin-right:-12px; }
        .tk-stub { padding:1rem 1.2rem 1.25rem; text-align:center; background:#fff; }
        .tk-stub-label { color:#6f6470; font-size:.7rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        .tk-code { margin-top:.15rem; color:#4b0818; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-weight:800; font-size:1.7rem; letter-spacing:.06em; }
        .tk-used { margin-top:.5rem; font-weight:800; }
        /* Ticket colours by type: adult maroon (default), child teal, concession gold */
        .tk--child .tk-top { background:linear-gradient(135deg,#0b5d68,#1a9aa8); }
        .tk--child .tk-scan, .tk--child .tk-stub { background:#e6f5f7; }
        .tk--child .tk-code { color:#0b5d68; }
        .tk--child .tk-perf { border-top-color:#9fcfd5; }
        .tk--concession .tk-top { background:linear-gradient(135deg,#7a4f06,#b97d0c); }
        .tk--concession .tk-scan, .tk--concession .tk-stub { background:#fbf0d4; }
        .tk--concession .tk-code { color:#7a4f06; }
        .tk--concession .tk-perf { border-top-color:#e2c47b; }
        .ticket-item.is-used .tk-top { background:#067a42; }
        .ticket-item.is-used .tk-scan, .ticket-item.is-used .tk-stub { background:#08a85a; color:#fff; }
        .ticket-item.is-used .tk-hint, .ticket-item.is-used .tk-stub-label, .ticket-item.is-used .tk-code { color:#fff; }
        .ticket-item.is-used .tk-scan .ticket-qr { --tk-a:#067a42; --tk-b:#5dd69a; }
        .tk-heading { margin:1.6rem 0 .9rem; text-align:center; }
        .tk-heading h2 { margin:0; font-size:1.35rem; font-weight:800; color:#4b0818; }
        .tk-heading p { margin:.25rem 0 0; color:#6f6470; font-size:.9rem; }
        .ticket-carousel-shell { max-width:420px; margin:0 auto; }
        .ticket-carousel-viewport { overflow:hidden; border-radius:16px; }
        .ticket-carousel { display:flex; gap:0; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; -webkit-overflow-scrolling:touch; scrollbar-width:none; cursor:grab; touch-action:auto; overscroll-behavior-x:contain; }
        .ticket-carousel.is-dragging { cursor:grabbing; scroll-snap-type:none; scroll-behavior:auto; }
        .ticket-carousel::-webkit-scrollbar { display:none; }
        .ticket-slide { flex:0 0 100%; scroll-snap-align:center; scroll-snap-stop:always; margin-bottom:0; }
        .ticket-carousel-controls { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin:.85rem 0 0; }
        .ticket-carousel-button { width:2.75rem; height:2.75rem; display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:999px; background:#4b0818; color:#fff; box-shadow:0 8px 20px rgba(75,8,24,.18); }
        .ticket-carousel-button:disabled { opacity:.35; }
        .ticket-carousel-count { color:#4b0818; font-weight:800; }
    </style>
</head>
<body>
<?php /* hero spans the full width, edge to edge and flush with the top */ ?>
<section class="ticket-hero">
        <div class="ticket-hero__inner">
        <div class="small text-uppercase fw-bold opacity-75">Digital Match Tickets</div>
        <h1 class="display-6 fw-bold mb-2">vs <?= h((string) $order['opponent']) ?></h1>
        <p class="mb-0"><?= h(date('D j M Y', strtotime((string) $order['match_date']))) ?><?= !empty($order['kickoff_time']) ? ' · ' . h(date('H:i', strtotime((string) $order['kickoff_time']))) : '' ?></p>
        </div>
</section>
<div class="ticket-wrap">

    <?php if (isset($_GET['paid'])): ?><div class="alert alert-success">Payment received. Your digital tickets are ready.</div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="alert alert-info">Your ticket order has been saved.</div><?php endif; ?>
    <?php if ((int) $order['paid'] !== 1): ?><div class="alert alert-warning">This order is not marked as paid yet. The QR codes will scan only once payment is confirmed.</div><?php endif; ?>

    <div class="tk-heading">
        <h2>Your Matchday Tickets</h2>
        <p><?= count($tickets) > 1 ? 'Swipe to see each ticket. Show the QR code at the turnstile.' : 'Show the QR code at the turnstile.' ?></p>
    </div>
    <div class="ticket-carousel-shell">
        <div class="ticket-carousel-viewport">
            <div class="ticket-carousel" aria-label="Your matchday tickets" data-ticket-carousel data-initial-index="<?= (int) $initialTicketIndex ?>">
                <?php foreach ($tickets as $index => $ticket): ?>
                    <?php $ticketUrl = $scheme . '://' . $host . '/ticket_order.php?' . $accessParam . '&ticket=' . rawurlencode((string) $ticket['ticket_token']); ?>
                    <?php $tkLabel = strtolower((string) $ticket['ticket_label']); $tkKind = str_contains($tkLabel, 'child') ? 'child' : (str_contains($tkLabel, 'concession') ? 'concession' : 'adult'); ?>
                    <article class="ticket-card tk tk--<?= $tkKind ?> ticket-slide ticket-item <?= !empty($ticket['checked_in_at']) ? 'is-used' : '' ?>" data-ticket-token="<?= h((string) $ticket['ticket_token']) ?>">
                        <div class="tk-top">
                            <span class="tk-type"><?= h((string) $ticket['ticket_label']) ?></span>
                            <span class="tk-num">Ticket <?= $index + 1 ?> of <?= count($tickets) ?></span>
                        </div>
                        <div class="tk-scan">
                            <div class="ticket-qr" data-qr data-qr-url="<?= h($ticketUrl) ?>"></div>
                            <div class="tk-hint">Scan at the turnstile</div>
                        </div>
                        <div class="tk-perf" aria-hidden="true"></div>
                        <div class="tk-stub">
                            <div class="tk-stub-label">Entry code</div>
                            <div class="tk-code"><?= h((string) $ticket['manual_code']) ?></div>
                            <div class="tk-used <?= !empty($ticket['checked_in_at']) ? '' : 'd-none' ?>" data-ticket-used-label><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Checked in</div>
                            <?php if ($walletOn && (int) $order['paid'] === 1 && empty($ticket['checked_in_at'])): ?>
                                <?php try { $walletUrl = wallet_save_url(wallet_build_match_jwt($walletCfg, $order, $ticket, $ticketUrl, site_setting($pdo, 'club_short_name', 'Saltcoats Vics'), site_setting($pdo, 'ground_name', 'Campbell Park'), $scheme . '://' . $host . '/scan/icon-512.png')); } catch (Throwable) { $walletUrl = ''; } ?>
                                <?php if ($walletUrl !== ''): ?><?= wallet_button_html($walletUrl) ?><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (count($tickets) > 1): ?>
            <div class="ticket-carousel-controls" aria-label="Ticket navigation">
                <button class="ticket-carousel-button" type="button" data-ticket-prev aria-label="Previous ticket"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <div class="ticket-carousel-count" data-ticket-count>1 / <?= count($tickets) ?></div>
                <button class="ticket-carousel-button" type="button" data-ticket-next aria-label="Next ticket"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            </div>
        <?php endif; ?>
    </div>

    <section class="ticket-card mt-4">
        <div class="ticket-card__body">
            <h2 class="h5">Order Summary</h2>
            <?php foreach ($items as $item): ?>
                <div class="d-flex justify-content-between border-bottom py-2"><span><?= h((string) $item['package_name']) ?> x <?= (int) $item['quantity'] ?></span><span><?= gbp((float) $item['unit_price'] * (int) $item['quantity']) ?></span></div>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between fw-bold pt-2"><span>Total</span><span><?= gbp((float) $order['total_amount']) ?></span></div>
        </div>
    </section>
</div>
<script src="/admin/assets/js/vendor/qrcode.min.js"></script>
<script>
document.querySelectorAll('[data-qr]').forEach((el) => {
    new QRCode(el, { text: el.dataset.qrUrl, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.H });
});
const carousel = document.querySelector('[data-ticket-carousel]');
if (carousel) {
    const slides = Array.from(carousel.querySelectorAll('.ticket-slide'));
    const previousButton = document.querySelector('[data-ticket-prev]');
    const nextButton = document.querySelector('[data-ticket-next]');
    const countLabel = document.querySelector('[data-ticket-count]');
    let currentIndex = Math.max(0, Math.min(slides.length - 1, Number(carousel.dataset.initialIndex || 0)));
    let dragStartIndex = currentIndex;
    let dragStartX = 0;
    let dragStartScrollLeft = 0;
    let isDragging = false;

    const slideWidth = () => carousel.clientWidth || 1;
    let isResizing = false;
    let resizeTimer = null;
    const updateControls = () => {
        if (!isResizing) {
            currentIndex = Math.max(0, Math.min(slides.length - 1, Math.round(carousel.scrollLeft / slideWidth())));
        }
        if (countLabel) {
            countLabel.textContent = `${currentIndex + 1} / ${slides.length}`;
        }
        if (previousButton) {
            previousButton.disabled = currentIndex <= 0;
        }
        if (nextButton) {
            nextButton.disabled = currentIndex >= slides.length - 1;
        }
    };
    const goTo = (index) => {
        currentIndex = Math.max(0, Math.min(slides.length - 1, index));
        carousel.scrollTo({ left: currentIndex * slideWidth(), behavior: 'smooth' });
        updateControls();
    };

    previousButton?.addEventListener('click', () => goTo(currentIndex - 1));
    nextButton?.addEventListener('click', () => goTo(currentIndex + 1));
    carousel.addEventListener('scroll', () => window.requestAnimationFrame(updateControls), { passive: true });
    // Touch screens swipe the carousel natively (scroll-snap), so scrolling the page up and down never moves it.
    // Only a mouse drag is handled here, for desktop; a cancelled drag never changes ticket.
    let lastX = 0;
    carousel.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'mouse' || event.button !== 0) {
            return;
        }
        isDragging = true;
        dragStartX = event.clientX;
        lastX = event.clientX;
        dragStartScrollLeft = carousel.scrollLeft;
        dragStartIndex = currentIndex;
        carousel.classList.add('is-dragging');
        carousel.setPointerCapture(event.pointerId);
    });
    carousel.addEventListener('pointermove', (event) => {
        if (!isDragging) {
            return;
        }
        lastX = event.clientX;
        carousel.scrollLeft = dragStartScrollLeft - (event.clientX - dragStartX);
    });
    const endDrag = (event) => {
        if (!isDragging) {
            return;
        }
        isDragging = false;
        carousel.classList.remove('is-dragging');
        const delta = event.type === 'pointercancel' ? 0 : lastX - dragStartX;
        const threshold = slideWidth() * 0.4;
        if (Math.abs(delta) >= threshold) {
            goTo(dragStartIndex + (delta < 0 ? 1 : -1));
        } else {
            goTo(dragStartIndex);
        }
    };
    carousel.addEventListener('pointerup', endDrag);
    carousel.addEventListener('pointercancel', endDrag);
    // A resize (phone rotated, address bar shown or hidden) keeps the same ticket in view instead of re-deriving it mid-resize.
    window.addEventListener('resize', () => {
        isResizing = true;
        carousel.style.scrollBehavior = 'auto';
        carousel.scrollLeft = currentIndex * slideWidth();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            isResizing = false;
            carousel.style.scrollBehavior = '';
            updateControls();
        }, 250);
    });
    goTo(currentIndex);
}
const statusUrl = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
const checkedTickets = new Set(Array.from(document.querySelectorAll('.ticket-item.is-used')).map((ticket) => ticket.dataset.ticketToken || ''));
const markTicketUsed = (token) => {
    if (!token || checkedTickets.has(token)) {
        return;
    }
    checkedTickets.add(token);
    const ticket = document.querySelector(`[data-ticket-token="${CSS.escape(token)}"]`);
    if (!ticket) {
        return;
    }
    ticket.classList.add('is-used');
    ticket.querySelector('[data-ticket-used-label]')?.classList.remove('d-none');
};
const pollTicketStatus = async () => {
    try {
        const response = await fetch(statusUrl, { cache: 'no-store', credentials: 'same-origin' });
        const payload = await response.json();
        if (!response.ok || !payload || !payload.ok || !Array.isArray(payload.tickets)) {
            return;
        }
        payload.tickets.forEach((ticket) => {
            if (ticket.checked_in) {
                markTicketUsed(ticket.token);
            }
        });
    } catch (error) {
        // Keep the digital ticket usable offline or on weak matchday signal.
    }
};
pollTicketStatus();
setInterval(pollTicketStatus, 2500);
</script>
</body>
</html>
