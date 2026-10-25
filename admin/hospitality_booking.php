<?php
declare(strict_types=1);

// One Big Js hospitality booking: who, which game, what they owe / paid, dietary needs, and the rest of the game's guest list.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/hospitality.php';
require_once __DIR__ . '/account_auth.php';

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Hospitality booking',
    'subtitle' => 'Big Js hospitality suite.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';

if (!hub_auth_has_capability('tickets_ops')) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You do not have permission to manage hospitality.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

hospitality_ensure_schema($pdo);
$id = (int) ($_GET['id'] ?? 0);
$b = $id > 0 ? hospitality_booking($pdo, $id) : null;
if (!$b) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Booking not found. <a href="/admin/inbox.php?type=hospitality">Back to the Inbox</a></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$settings = hospitality_settings($pdo);
$capacity = (int) $settings['capacity'];
$fixtureId = (int) $b['fixture_id'];

$stmt = $pdo->prepare("SELECT id, contact_name, company, guests, total_amount, status, booking_type, created_at, paid_at
    FROM hospitality_bookings WHERE fixture_id = :f AND status IN ('paid','pending_payment') ORDER BY created_at");
$stmt->execute([':f' => $fixtureId]);
$gameBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$paidPlaces = 0;
foreach ($gameBookings as $g) {
    $paidPlaces += $g['status'] === 'paid' ? (int) $g['guests'] : 0;
}

$stmt = $pdo->prepare("SELECT b.id, b.guests, b.total_amount, b.status, f.opponent, f.match_date
    FROM hospitality_bookings b JOIN match_fixtures f ON f.id = b.fixture_id
    WHERE LOWER(b.contact_email) = LOWER(:e) AND b.id <> :id ORDER BY f.match_date");
$stmt->execute([':e' => (string) $b['contact_email'], ':id' => $id]);
$others = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kickoff = !empty($b['kickoff_time']) ? date('H:i', strtotime((string) $b['kickoff_time'])) : 'TBC';
$arrive = hospitality_arrival_time($settings, (string) ($b['kickoff_time'] ?? ''));
$gameDate = date('l j F Y', strtotime((string) $b['match_date']));
$isPast = (string) $b['match_date'] < date('Y-m-d');
$fmt = static fn(?string $dt): string => $dt ? date('j M Y, H:i', strtotime($dt)) : '';
$phoneDigits = preg_replace('/[^0-9+]/', '', (string) $b['contact_phone']);
$statusMap = ['paid' => ['success', 'Paid'], 'pending_payment' => ['warning', 'Awaiting payment'], 'expired' => ['muted', 'Expired']];
[$tone, $statusLabel] = $statusMap[(string) $b['status']] ?? ['muted', ucfirst(str_replace('_', ' ', (string) $b['status']))];
$holdEnds = $b['status'] === 'pending_payment' ? strtotime((string) $b['created_at']) + HOSPITALITY_HOLD_MINUTES * 60 : 0;
$initials = '';
foreach (array_slice(preg_split('/\s+/', trim((string) $b['contact_name'])) ?: [], 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}

$timeline = [['Booking started', (string) $b['created_at'], 'fa-circle-plus']];
if (!empty($b['paid_at'])) {
    $timeline[] = ['Payment received', (string) $b['paid_at'], 'fa-sterling-sign'];
}
if (!empty($b['confirmation_sent_at'])) {
    $timeline[] = ['Confirmation email sent to customer', (string) $b['confirmation_sent_at'], 'fa-envelope-circle-check'];
}
usort($timeline, static fn(array $x, array $y): int => strcmp($x[1], $y[1]));
?>
<style>
    .hb-pill { display: inline-block; padding: 0.18rem 0.65rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; border: 1px solid transparent; }
    .hb-pill--success { background: #d1f0df; color: #0b5c33; border-color: #a6dfc0; }
    .hb-pill--warning { background: #fff0c2; color: #7a5600; border-color: #f2d98a; }
    .hb-pill--muted { background: #e9e6e7; color: #4d4548; border-color: #d3cfd1; }
    .hb-pill--dept { background: #f9e4ef; color: #8a1f55; border-color: #efbfd6; }
    .hb-avatar { flex: none; width: 3rem; height: 3rem; border-radius: 50%; display: grid; place-items: center; font-weight: 700; color: #fff; background: #d63384; }
    .hb-stat { text-align: center; padding: 0.6rem 0.5rem; border: 1px solid rgba(31, 26, 29, 0.1); border-radius: 0.6rem; background: #faf7f5; }
    .hb-stat strong { display: block; font-size: 1.35rem; line-height: 1.2; }
    .hb-stat span { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--hub-muted, #70676b); font-weight: 700; }
    .hb-card h3 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--hub-muted, #70676b); margin-bottom: 0.85rem; }
    .hb-card dl { margin: 0; }
    .hb-card dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--hub-muted, #70676b); font-weight: 700; }
    .hb-card dd { margin: 0 0 0.65rem; overflow-wrap: anywhere; }
    .hb-flag { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.6rem 0.8rem; border-radius: 0.6rem; margin-bottom: 0.6rem; }
    .hb-flag--warn { background: #fff0c2; color: #5c4200; }
    .hb-flag--ok { background: #f1f3f2; color: #4d4548; }
    .hb-timeline { list-style: none; margin: 0; padding: 0 0 0 0.9rem; position: relative; }
    .hb-timeline::before { content: ""; position: absolute; left: 0.28rem; top: 0.5rem; bottom: 0.5rem; width: 2px; background: rgba(31, 26, 29, 0.15); }
    .hb-timeline li { position: relative; padding: 0.3rem 0 0.3rem 0.6rem; }
    .hb-timeline li::before { content: ""; position: absolute; left: -0.9rem; top: 0.7rem; width: 0.65rem; height: 0.65rem; border-radius: 50%; background: var(--brand-primary, #6a2036); }
    .hb-timeline small { display: block; color: var(--hub-muted, #70676b); }
    .hb-guests tr.is-current > td { background: rgba(75, 8, 24, 0.07); font-weight: 650; }
    .hb-capacity { height: 0.55rem; border-radius: 999px; background: #e9e6e7; overflow: hidden; }
    .hb-capacity > span { display: block; height: 100%; background: var(--brand-primary, #6a2036); }
</style>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a class="small text-decoration-none" href="/admin/inbox.php?type=hospitality"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the Inbox</a>
        <a class="small text-decoration-none" href="/admin/hospitality.php">All hospitality bookings <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a>
    </div>

    <section class="card hub-panel mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="hb-avatar" aria-hidden="true"><?= h($initials !== '' ? $initials : '?') ?></span>
            <div class="me-auto">
                <h2 class="h5 mb-1"><?= h((string) $b['contact_name']) ?><?= $b['company'] ? ' <span class="text-muted fw-normal">· ' . h((string) $b['company']) . '</span>' : '' ?></h2>
                <div class="small d-flex flex-wrap gap-3">
                    <a href="mailto:<?= h((string) $b['contact_email']) ?>"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i><?= h((string) $b['contact_email']) ?></a>
                    <?php if ($phoneDigits !== ''): ?><a href="tel:<?= h($phoneDigits) ?>"><i class="fa-solid fa-phone me-1" aria-hidden="true"></i><?= h((string) $b['contact_phone']) ?></a><?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="hb-pill hb-pill--<?= $tone ?>"><?= h($statusLabel) ?></span>
                <span class="hb-pill hb-pill--dept"><?= h(ucfirst((string) $b['booking_type'])) ?> booking</span>
                <a class="btn btn-sm btn-brand" href="mailto:<?= h((string) $b['contact_email']) ?>?subject=<?= rawurlencode('Your Big Js hospitality booking - v ' . $b['opponent']) ?>"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Email</a>
                <?php if ($phoneDigits !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="tel:<?= h($phoneDigits) ?>"><i class="fa-solid fa-phone me-1" aria-hidden="true"></i>Call</a><?php endif; ?>
            </div>
        </div>
        <div class="row g-2 mt-3">
            <div class="col-6 col-md-3"><div class="hb-stat"><strong><?= (int) $b['guests'] ?></strong><span>Places</span></div></div>
            <div class="col-6 col-md-3"><div class="hb-stat"><strong><?= h(gbp((float) $b['total_amount'])) ?></strong><span>Total</span></div></div>
            <div class="col-6 col-md-3"><div class="hb-stat"><strong><?= h(date('j M', strtotime((string) $b['match_date']))) ?></strong><span>Game day</span></div></div>
            <div class="col-6 col-md-3"><div class="hb-stat"><strong><?= h($arrive !== '' ? $arrive : '—') ?></strong><span>Arrive by</span></div></div>
        </div>
    </div></section>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card hub-panel mb-3 hb-card"><div class="card-body">
                <h3>Needs and notes</h3>
                <?php if (trim((string) $b['dietary_requirements']) !== ''): ?>
                    <div class="hb-flag hb-flag--warn"><i class="fa-solid fa-utensils mt-1" aria-hidden="true"></i><div><strong>Dietary requirements</strong><div><?= nl2br(h((string) $b['dietary_requirements'])) ?></div></div></div>
                <?php else: ?>
                    <div class="hb-flag hb-flag--ok"><i class="fa-solid fa-utensils mt-1" aria-hidden="true"></i><div><strong>Dietary requirements</strong><div>None declared<?= (int) $b['dietary_declared'] === 1 ? ' (customer confirmed they have checked)' : '' ?></div></div></div>
                <?php endif; ?>
                <?php if (trim((string) $b['notes']) !== ''): ?>
                    <div class="hb-flag hb-flag--ok"><i class="fa-solid fa-note-sticky mt-1" aria-hidden="true"></i><div><strong>Customer note</strong><div><?= nl2br(h((string) $b['notes'])) ?></div></div></div>
                <?php else: ?>
                    <div class="hb-flag hb-flag--ok"><i class="fa-solid fa-note-sticky mt-1" aria-hidden="true"></i><div><strong>Customer note</strong><div>No note left</div></div></div>
                <?php endif; ?>
                <div class="small text-muted"><i class="fa-solid fa-<?= (int) $b['agreed_no_refunds'] === 1 ? 'check text-success' : 'xmark text-danger' ?> me-1" aria-hidden="true"></i><?= (int) $b['agreed_no_refunds'] === 1 ? 'Customer agreed that bookings are non-refundable.' : 'Non-refundable terms were not recorded for this booking.' ?></div>
            </div></section>

            <section class="card hub-panel mb-3 hb-card"><div class="card-body">
                <h3>Guest list for this game <span class="badge text-bg-light border"><?= count($gameBookings) ?> booking<?= count($gameBookings) === 1 ? '' : 's' ?></span></h3>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1"><span><?= $paidPlaces ?> of <?= $capacity ?> places paid</span><span class="text-muted"><?= max(0, $capacity - $paidPlaces) ?> left</span></div>
                    <div class="hb-capacity" role="img" aria-label="<?= $paidPlaces ?> of <?= $capacity ?> places paid"><span style="width: <?= $capacity > 0 ? min(100, (int) round($paidPlaces / $capacity * 100)) : 0 ?>%"></span></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 hb-guests">
                        <thead><tr><th>Guest</th><th class="text-end">Places</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($gameBookings as $g): ?>
                            <tr class="<?= (int) $g['id'] === $id ? 'is-current' : '' ?>">
                                <td><?= (int) $g['id'] === $id ? h((string) $g['contact_name']) . ' <span class="text-muted">(this booking)</span>' : '<a href="?id=' . (int) $g['id'] . '">' . h((string) $g['contact_name']) . '</a>' ?><?= $g['company'] ? '<div class="small text-muted">' . h((string) $g['company']) . '</div>' : '' ?></td>
                                <td class="text-end"><?= (int) $g['guests'] ?></td>
                                <td class="text-end"><?= h(gbp((float) $g['total_amount'])) ?></td>
                                <td><span class="hb-pill hb-pill--<?= $g['status'] === 'paid' ? 'success' : 'warning' ?>"><?= $g['status'] === 'paid' ? 'Paid' : 'Awaiting' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (hub_auth_has_capability('tickets_ops')): ?>
                    <a class="btn btn-sm btn-outline-secondary mt-3" href="/admin/hospitality.php?export=csv&amp;fixture=<?= $fixtureId ?>"><i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>Download guest list (CSV)</a>
                <?php endif; ?>
            </div></section>
        </div>

        <div class="col-lg-5">
            <section class="card hub-panel mb-3 hb-card"><div class="card-body">
                <h3>The game</h3>
                <dl>
                    <dt>Fixture</dt><dd>Saltcoats Victoria v <?= h((string) $b['opponent']) ?><?= $b['competition'] ? ' <span class="text-muted">(' . h((string) $b['competition']) . ')</span>' : '' ?></dd>
                    <dt>Date</dt><dd><?= h($gameDate) ?><?= $isPast ? ' <span class="text-muted">(played)</span>' : '' ?></dd>
                    <dt>Kick-off</dt><dd><?= h($kickoff) ?></dd>
                    <dt>Arrival</dt><dd class="mb-0"><?= $arrive !== '' ? h($arrive) . ' <span class="text-muted">(' . (int) $settings['arrival_minutes_before'] . ' minutes before kick-off)</span>' : 'TBC' ?></dd>
                </dl>
            </div></section>

            <section class="card hub-panel mb-3 hb-card"><div class="card-body">
                <h3>Payment</h3>
                <dl>
                    <dt>Price</dt><dd><?= (int) $b['guests'] ?> &times; <?= h(gbp((float) $b['unit_price'])) ?> = <strong><?= h(gbp((float) $b['total_amount'])) ?></strong><?= (int) $b['is_cup'] === 1 ? ' <span class="text-muted">(cup game price)</span>' : '' ?></dd>
                    <dt>Status</dt><dd><span class="hb-pill hb-pill--<?= $tone ?>"><?= h($statusLabel) ?></span><?php if (!empty($b['paid_at'])): ?> <span class="small text-muted ms-1"><?= h($fmt((string) $b['paid_at'])) ?></span><?php endif; ?></dd>
                    <?php if ($holdEnds > 0): ?><dt>Places held until</dt><dd><?= h(date('H:i', $holdEnds)) ?> <span class="text-muted"><?= $holdEnds > time() ? '(still holding)' : '(hold has ended)' ?></span></dd><?php endif; ?>
                    <dt>Stripe session</dt><dd class="mb-0 small"><?= (string) ($b['stripe_checkout_session_id'] ?? '') !== '' ? '<code>' . h((string) $b['stripe_checkout_session_id']) . '</code>' : '<span class="text-muted">None recorded</span>' ?></dd>
                </dl>
            </div></section>

            <section class="card hub-panel mb-3 hb-card"><div class="card-body">
                <h3>Activity</h3>
                <ul class="hb-timeline">
                    <?php foreach ($timeline as [$label, $when, $icon]): ?>
                        <li><i class="fa-solid <?= h($icon) ?> me-1 text-muted" aria-hidden="true"></i><?= h($label) ?><small><?= h($fmt($when)) ?></small></li>
                    <?php endforeach; ?>
                    <?php if (empty($b['confirmation_sent_at']) && $b['status'] === 'paid'): ?><li><i class="fa-solid fa-triangle-exclamation me-1 text-danger" aria-hidden="true"></i>Confirmation email not sent<small>Contact them directly to confirm.</small></li><?php endif; ?>
                </ul>
                <div class="small text-muted mt-2">Booking #<?= $id ?></div>
            </div></section>

            <?php if ($others !== []): ?>
                <section class="card hub-panel hb-card"><div class="card-body">
                    <h3>Other bookings from <?= h((string) $b['contact_email']) ?></h3>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($others as $o): ?>
                            <li class="mb-1"><a href="?id=<?= (int) $o['id'] ?>">v <?= h((string) $o['opponent']) ?></a> &middot; <?= h(date('j M Y', strtotime((string) $o['match_date']))) ?> &middot; <?= (int) $o['guests'] ?> places &middot; <?= h(gbp((float) $o['total_amount'])) ?> &middot; <?= $o['status'] === 'paid' ? 'Paid' : 'Awaiting payment' ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div></section>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
