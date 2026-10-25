<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Event Sales',
    'subtitle' => 'Tickets sold, money in, who has arrived, and what is still available.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events_admin.php';
require_once __DIR__ . '/lib/events_followups.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventScanSchema($pdo);

$canRefund = hub_auth_has_capability('events_refund');
$canManage = hub_auth_has_capability('events_manage');
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? events_get($pdo, $eventId) : null;
if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">That event was not found. <a href="events.php">Back to events</a></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$notices = [];
$batchResults = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (in_array($action, ['waitlist_notify', 'waitlist_remove'], true)) {
        if (!$canManage) {
            $errors[] = 'You do not have permission to manage the waiting list.';
        } elseif ($action === 'waitlist_notify') {
            $n = events_process_waitlist($pdo, $eventId);
            $notices[] = $n > 0 ? 'Emailed ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' on the waiting list.' : 'Nobody was emailed: there are no tickets free right now, or everyone free has already been told.';
        } else {
            $pdo->prepare("UPDATE event_waitlist SET status = 'cancelled' WHERE id = :id AND event_id = :e")->execute([':id' => (int) ($_POST['entry_id'] ?? 0), ':e' => $eventId]);
            $notices[] = 'Removed from the waiting list.';
        }
    } elseif (!$canRefund) {
        $errors[] = 'Only people with refund permission can cancel an event or refund orders.';
    } else {
        $acct = hub_auth_current_user();
        $acctId = !empty($acct['account_id']) ? (int) $acct['account_id'] : null;
        try {
            if ($action === 'cancel_event') {
                if (empty($_POST['confirm'])) {
                    throw new RuntimeException('Tick the box to confirm you want to cancel the event.');
                }
                $res = events_cancel_event($pdo, $eventId);
                auditLog($pdo, 'event_cancelled', 'Cancelled event #' . $eventId . ' (' . $event['title'] . '). ' . $res['released'] . ' unpaid order(s) released, ' . $res['refundable'] . ' paid order(s) to refund.');
                $notices[] = 'The event is cancelled and no more tickets can be sold. ' . $res['refundable'] . ' paid order(s) now need refunding below, and you should message ticket holders.';
            } elseif ($action === 'refund_batch') {
                if (empty($_POST['confirm_money'])) {
                    throw new RuntimeException('Tick the box to confirm you want to send real refunds to customers\' cards.');
                }
                if ($event['status'] !== 'cancelled') {
                    throw new RuntimeException('Cancel the event first. Refunding everyone is only for a cancelled event.');
                }
                foreach (array_slice(events_orders_to_refund($pdo, $eventId), 0, 20) as $oid) {
                    try {
                        $r = events_refund_order($pdo, $oid, 'Event cancelled', $acctId);
                        $batchResults[] = ['order' => $oid, 'ok' => true, 'text' => $r['refunded'] > 0 ? 'Refunded ' . gbp($r['refunded']) : 'Cancelled (free ticket)'];
                    } catch (Throwable $e) {
                        $batchResults[] = ['order' => $oid, 'ok' => false, 'text' => $e->getMessage()];
                    }
                }
                $done = count(array_filter($batchResults, static fn ($r) => $r['ok']));
                auditLog($pdo, 'event_refund_batch', 'Refund batch for event #' . $eventId . ': ' . $done . ' of ' . count($batchResults) . ' orders refunded.');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        $event = events_get($pdo, $eventId) ?? $event;
    }
}

$d = events_dashboard($pdo, $eventId);
$cap = max(1, (int) $d['capacity']);
$seatPct = min(100, (int) round($d['seats_taken'] / $cap * 100));
$maxDay = 1;
foreach ($d['daily'] as $row) {
    $maxDay = max($maxDay, $row['tickets']);
}
$toRefund = count(events_orders_to_refund($pdo, $eventId));
$statusTone = ['draft' => 'secondary', 'on_sale' => 'success', 'sold_out' => 'warning', 'postponed' => 'warning', 'cancelled' => 'danger', 'finished' => 'dark'];
$eventNavActive = 'sales';
?>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title'], 'href' => 'event_edit.php?id=' . $eventId], ['label' => 'Sales']]); ?>

<div class="hub-section-commandbar">
    <div>
        <h2><?= h((string) $event['title']) ?> <?php hub_render_badge(EVENT_STATUSES[$event['status']] ?? (string) $event['status'], $statusTone[$event['status']] ?? 'secondary'); ?></h2>
        <p><?= h(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?> · <?= h((string) $event['venue_name']) ?> · holds <?= (int) $d['capacity'] ?></p>
    </div>
</div>
<?php require __DIR__ . '/partials/event_subnav.php'; ?>

<?php foreach ($notices as $n): ?><div class="alert alert-success"><?= h($n) ?></div><?php endforeach; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($d['needs_refund'] > 0): ?>
    <div class="alert alert-danger"><strong><?= (int) $d['needs_refund'] ?> order(s) need refunding.</strong> A payment arrived after the tickets had been released and the seats were gone. See <a href="event_attendees.php?event_id=<?= $eventId ?>&amp;show=cancelled">Attendees</a> and refund them.</div>
<?php endif; ?>

<?php hub_render_metric_grid([
    ['label' => 'Seats taken', 'value' => $d['seats_taken'] . ' / ' . $d['capacity'], 'meta' => $d['orders_pending'] > 0 ? $d['orders_pending'] . ' order(s) held, awaiting payment' : max(0, $d['capacity'] - $d['seats_taken']) . ' seats left', 'icon' => 'fa-chair', 'tone' => $seatPct >= 90 ? 'warning' : 'success'],
    ['label' => 'Money in (net)', 'value' => gbp($d['net']), 'meta' => 'tickets + fees - refunds', 'icon' => 'fa-sterling-sign', 'tone' => 'primary'],
    ['label' => 'Arrived', 'value' => $d['admitted'] . ' / ' . $d['people_expected'], 'meta' => $d['no_shows'] > 0 ? $d['no_shows'] . ' did not arrive' : 'people through the door', 'icon' => 'fa-door-open', 'tone' => 'info'],
    ['label' => 'Paid orders', 'value' => (string) $d['orders_paid'], 'meta' => $d['tickets_sold'] . ' tickets' . ($d['comps'] > 0 ? ' (' . $d['comps'] . ' complimentary)' : ''), 'icon' => 'fa-receipt', 'tone' => 'neutral'],
], 'Sales summary'); ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card hub-list-card hub-table-card mb-3">
            <div class="card-header"><h2 class="h5 mb-0">By ticket type</h2></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle hub-data-table hub-data-table--responsive mb-0">
                        <thead><tr><th>Ticket</th><th>Price</th><th>Sold</th><th>Held</th><th>Left</th><th>Money in</th></tr></thead>
                        <tbody>
                            <?php foreach ($d['types'] as $t): $pct = (int) $t['allocation'] > 0 ? min(100, (int) round($t['sold'] / (int) $t['allocation'] * 100)) : 0; ?>
                                <tr class="<?= (int) $t['is_active'] === 1 ? '' : 'text-muted' ?>">
                                    <td data-label="Ticket"><strong><?= h((string) $t['name']) ?></strong><?= (int) $t['is_active'] !== 1 ? ' <span class="badge bg-secondary">Off sale</span>' : '' ?>
                                        <div class="progress mt-1" style="height:5px;max-width:180px" role="progressbar" aria-label="Sold" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div></td>
                                    <td data-label="Price"><?= (float) $t['price'] > 0 ? h(gbp((float) $t['price'])) : 'Free' ?></td>
                                    <td data-label="Sold"><strong><?= (int) $t['sold'] ?></strong> <span class="text-muted small">of <?= (int) $t['allocation'] ?></span><?= $t['comps'] > 0 ? '<div class="small text-muted">' . (int) $t['comps'] . ' complimentary</div>' : '' ?></td>
                                    <td data-label="Held"><?= (int) $t['held'] ?></td>
                                    <td data-label="Left"><?= (int) $t['remaining'] ?></td>
                                    <td data-label="Money in"><?= h(gbp((float) $t['revenue'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$d['types']): ?><tr><td colspan="6" class="hub-record-empty hub-empty-state">No ticket types yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-3">Tickets sold per day</h3>
            <?php if (!$d['daily']): ?>
                <p class="text-muted mb-0">No sales yet.</p>
            <?php else: ?>
                <?php foreach ($d['daily'] as $row): ?>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="small text-muted" style="width:5.5rem"><?= h(date('D j M', strtotime($row['day']))) ?></span>
                        <div class="flex-grow-1"><div class="bg-success rounded" style="height:14px;width:<?= max(2, (int) round($row['tickets'] / $maxDay * 100)) ?>%"></div></div>
                        <span class="small" style="width:5rem;text-align:right"><strong><?= (int) $row['tickets'] ?></strong> <span class="text-muted">(<?= (int) $row['orders'] ?> orders)</span></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-3">Money</h3>
            <div class="d-flex justify-content-between py-1"><span>Ticket sales</span><strong><?= h(gbp($d['revenue_tickets'])) ?></strong></div>
            <div class="d-flex justify-content-between py-1"><span>Booking fees</span><strong><?= h(gbp($d['fees'])) ?></strong></div>
            <div class="d-flex justify-content-between py-1"><span>Refunded</span><strong>- <?= h(gbp($d['refunded'])) ?></strong></div>
            <div class="d-flex justify-content-between py-2 mt-1 border-top"><span><strong>Net received</strong></span><strong><?= h(gbp($d['net'])) ?></strong></div>
            <p class="small text-muted mb-0">Before Stripe's own card fees. Booking fees are collected to cover them.</p>
        </div>

        <?php $waiting = events_waitlist_rows($pdo, $eventId); if ($waiting): ?>
        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-2">Waiting list <span class="badge bg-secondary"><?= count($waiting) ?></span></h3>
            <ul class="list-unstyled small mb-2">
                <?php foreach (array_slice($waiting, 0, 12) as $w): ?>
                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <span><strong><?= h((string) $w['name']) ?></strong> · <?= (int) $w['qty'] ?> ticket<?= (int) $w['qty'] === 1 ? '' : 's' ?><?= $w['status'] === 'notified' ? ' <span class="badge bg-warning text-dark">told ' . h(date('H:i', strtotime((string) $w['notified_at']))) . '</span>' : '' ?><div class="text-muted"><?= h((string) $w['email']) ?></div></span>
                        <?php if ($canManage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="waitlist_remove"><input type="hidden" name="entry_id" value="<?= (int) $w['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Remove from the waiting list">&times;</button></form><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($waiting) > 12): ?><p class="small text-muted">and <?= count($waiting) - 12 ?> more</p><?php endif; ?>
            <?php if ($canManage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="waitlist_notify"><button class="btn btn-sm btn-outline-primary" type="submit">Email the next people now</button></form><?php endif; ?>
            <p class="small text-muted mt-2 mb-0">When tickets come back, the next people are emailed automatically, never more than there are tickets, and each has <?= (int) EVENT_WAITLIST_CLAIM_HOURS ?> hours to buy.</p>
        </div>
        <?php endif; ?>

        <?php if ($d['access_places'] > 0): ?>
        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-2">Accessible places</h3>
            <p class="mb-1"><strong><?= (int) $d['access_taken'] ?></strong> of <?= (int) $d['access_places'] ?> taken.</p>
            <p class="small text-muted mb-1"><?= $d['access_released'] ? 'Unsold ones are open to everyone.' : 'Unsold ones are held back from general sale until they are released.' ?></p>
            <?php if (hub_auth_has_capability('events_manage') && $d['access_needs'] > 0): ?><a class="btn btn-sm btn-outline-secondary mt-1" href="event_attendees.php?event_id=<?= $eventId ?>&amp;show=access"><?= (int) $d['access_needs'] ?> booking<?= $d['access_needs'] === 1 ? '' : 's' ?> with access requirements</a><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-2">Door entry</h3>
            <p class="mb-1"><strong><?= (int) $d['admitted'] ?></strong> of <?= (int) $d['people_expected'] ?> people have been scanned in.</p>
            <?php if ($d['no_shows'] > 0): ?><p class="mb-1"><?= (int) $d['no_shows'] ?> ticket holder(s) did not arrive.</p><?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary mt-1" href="event_attendees.php?event_id=<?= $eventId ?>&amp;show=waiting">Who has not arrived</a>
        </div>

        <?php if ($canRefund && $event['status'] !== 'cancelled' && $event['status'] !== 'finished'): ?>
        <div class="hub-panel mb-3" style="padding:1.15rem 1.25rem;border-left:4px solid #b42335">
            <h3 class="h5 mb-2">Cancel this event</h3>
            <p class="small text-muted">Stops sales and releases unpaid orders. You then refund every paid order in one go, and message ticket holders.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="action" value="cancel_event">
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="cxl" name="confirm" value="1"><label class="form-check-label" for="cxl">Yes, cancel "<?= h((string) $event['title']) ?>"</label></div>
                <button class="btn btn-outline-danger btn-sm" type="submit">Cancel event</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canRefund && $event['status'] === 'cancelled'): ?>
<div class="hub-panel mb-3" style="padding:1.15rem 1.25rem;border-left:4px solid #b42335">
    <h3 class="h5 mb-2">Refund everyone</h3>
    <?php if ($batchResults): ?>
        <ul class="list-unstyled mb-3">
            <?php foreach ($batchResults as $r): ?><li class="py-1 border-bottom"><span class="badge bg-<?= $r['ok'] ? 'success' : 'danger' ?> me-2">Order #<?= (int) $r['order'] ?></span><?= h($r['text']) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php $toRefund = count(events_orders_to_refund($pdo, $eventId)); ?>
    <?php if ($toRefund === 0): ?>
        <p class="mb-0 text-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Every paid order has been refunded or cancelled.</p>
    <?php else: ?>
        <p><strong><?= $toRefund ?> order(s)</strong> still to refund (ticket price and booking fee, in full). Each batch does up to 20 orders so nothing times out; run it again for the rest. Refunds go to the customers' cards through Stripe and cannot be undone.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="action" value="refund_batch">
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="money" name="confirm_money" value="1"><label class="form-check-label" for="money">I understand this sends real refunds to customers' cards</label></div>
            <button class="btn btn-danger" type="submit">Refund next <?= min(20, $toRefund) ?> order(s)</button>
            <a class="btn btn-outline-secondary" href="event_message.php?event_id=<?= $eventId ?>">Message ticket holders</a>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
