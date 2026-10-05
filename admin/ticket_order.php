<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);

$accessToken = match_ticket_extract_token((string) ($_GET['access'] ?? ($_GET['group'] ?? '')));
$order = $accessToken !== '' ? getMatchTicketOrderByAccessToken($pdo, $accessToken) : null;
if (!$order) {
    http_response_code(404);
    exit('Ticket order not found.');
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <style>
        html, body { min-height:100%; overflow-y:auto; }
        body { background:#f6ecde; font-family:Inter,system-ui,sans-serif; }
        .ticket-wrap { max-width:960px; margin:0 auto; padding:1rem 1rem 4rem; }
        .ticket-hero { color:#fff; background:linear-gradient(135deg,#4b0818,#8d1b39); border-radius:18px; padding:2rem; margin:1rem 0; }
        .ticket-card { background:#fff; border:1px solid rgba(75,8,24,.1); border-radius:16px; box-shadow:0 12px 30px rgba(33,20,26,.08); overflow:hidden; margin-bottom:1rem; }
        .ticket-card__body { padding:1.2rem; }
        .ticket-qr { display:inline-grid; place-items:center; padding:18px; border:1px solid #eee; border-radius:14px; background:#fff; }
        .ticket-item.is-used { background:#08a85a; color:#fff; }
        .ticket-item.is-used .text-muted, .ticket-item.is-used small { color:rgba(255,255,255,.78) !important; }
        .ticket-carousel-shell { max-width:420px; margin:0 auto; }
        .ticket-carousel-viewport { overflow:hidden; border-radius:16px; }
        .ticket-carousel { display:flex; gap:0; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; -webkit-overflow-scrolling:touch; scrollbar-width:none; cursor:grab; touch-action:pan-y pinch-zoom; }
        .ticket-carousel.is-dragging { cursor:grabbing; scroll-snap-type:none; scroll-behavior:auto; }
        .ticket-carousel::-webkit-scrollbar { display:none; }
        .ticket-slide { flex:0 0 100%; scroll-snap-align:center; margin-bottom:0; }
        .ticket-carousel-controls { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin:.85rem 0 0; }
        .ticket-carousel-button { width:2.75rem; height:2.75rem; display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:999px; background:#4b0818; color:#fff; box-shadow:0 8px 20px rgba(75,8,24,.18); }
        .ticket-carousel-button:disabled { opacity:.35; }
        .ticket-carousel-count { color:#4b0818; font-weight:800; }
    </style>
</head>
<body>
<div class="ticket-wrap">
    <section class="ticket-hero">
        <div class="small text-uppercase fw-bold opacity-75">Digital Match Tickets</div>
        <h1 class="display-6 fw-bold mb-2">vs <?= h((string) $order['opponent']) ?></h1>
        <p class="mb-0"><?= h(date('D j M Y', strtotime((string) $order['match_date']))) ?><?= !empty($order['kickoff_time']) ? ' · ' . h(date('H:i', strtotime((string) $order['kickoff_time']))) : '' ?></p>
    </section>

    <?php if (isset($_GET['paid'])): ?><div class="alert alert-success">Payment received. Your digital tickets are ready.</div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="alert alert-info">Your ticket order has been saved.</div><?php endif; ?>
    <?php if ((int) $order['paid'] !== 1): ?><div class="alert alert-warning">This order is not marked as paid yet. The QR codes will scan only once payment is confirmed.</div><?php endif; ?>

    <section class="ticket-card">
        <div class="ticket-card__body">
            <h2 class="h5">Order Summary</h2>
            <?php foreach ($items as $item): ?>
                <div class="d-flex justify-content-between border-bottom py-2"><span><?= h((string) $item['package_name']) ?> x <?= (int) $item['quantity'] ?></span><span><?= gbp((float) $item['unit_price'] * (int) $item['quantity']) ?></span></div>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between fw-bold pt-2"><span>Total</span><span><?= gbp((float) $order['total_amount']) ?></span></div>
        </div>
    </section>

    <h2 class="h5 mt-4">Individual Tickets</h2>
    <div class="ticket-carousel-shell">
        <div class="ticket-carousel-viewport">
            <div class="ticket-carousel" aria-label="Individual tickets" data-ticket-carousel data-initial-index="<?= (int) $initialTicketIndex ?>">
                <?php foreach ($tickets as $index => $ticket): ?>
                    <?php $ticketUrl = $scheme . '://' . $host . '/ticket_order.php?' . $accessParam . '&ticket=' . rawurlencode((string) $ticket['ticket_token']); ?>
                    <article class="ticket-card ticket-slide ticket-item <?= !empty($ticket['checked_in_at']) ? 'is-used' : '' ?>" data-ticket-token="<?= h((string) $ticket['ticket_token']) ?>">
                        <div class="ticket-card__body text-center">
                            <div class="small text-uppercase fw-bold opacity-75">Ticket <?= $index + 1 ?> of <?= count($tickets) ?></div>
                            <h3 class="h5"><?= h((string) $ticket['ticket_label']) ?></h3>
                            <div class="ticket-qr" data-qr data-qr-url="<?= h($ticketUrl) ?>"></div>
                            <div class="small text-muted mt-2">Manual code</div>
                            <div class="fw-bold font-monospace fs-4"><?= h((string) $ticket['manual_code']) ?></div>
                            <div class="fw-bold mt-2 <?= !empty($ticket['checked_in_at']) ? '' : 'd-none' ?>" data-ticket-used-label><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Checked in</div>
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
    const updateControls = () => {
        currentIndex = Math.max(0, Math.min(slides.length - 1, Math.round(carousel.scrollLeft / slideWidth())));
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
    carousel.addEventListener('pointerdown', (event) => {
        isDragging = true;
        dragStartX = event.clientX;
        dragStartScrollLeft = carousel.scrollLeft;
        dragStartIndex = currentIndex;
        carousel.classList.add('is-dragging');
        carousel.setPointerCapture(event.pointerId);
    });
    carousel.addEventListener('pointermove', (event) => {
        if (!isDragging) {
            return;
        }
        carousel.scrollLeft = dragStartScrollLeft - (event.clientX - dragStartX);
    });
    const endDrag = (event) => {
        if (!isDragging) {
            return;
        }
        isDragging = false;
        carousel.classList.remove('is-dragging');
        const delta = event.clientX - dragStartX;
        const threshold = slideWidth() * 0.4;
        if (Math.abs(delta) >= threshold) {
            goTo(dragStartIndex + (delta < 0 ? 1 : -1));
        } else {
            goTo(dragStartIndex);
        }
    };
    carousel.addEventListener('pointerup', endDrag);
    carousel.addEventListener('pointercancel', endDrag);
    window.addEventListener('resize', () => goTo(currentIndex));
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
