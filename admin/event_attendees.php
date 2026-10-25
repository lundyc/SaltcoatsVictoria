<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Attendees',
    'subtitle' => 'Everyone with a ticket: search, fix names, resend tickets and refund.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events_admin.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventScanSchema($pdo);

$canManage = hub_auth_has_capability('events_manage');
$canRefund = hub_auth_has_capability('events_refund');
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? events_get($pdo, $eventId) : null;
if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">That event was not found. <a href="events.php">Back to events</a></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = '';
$filters = ['q' => trim((string) ($_GET['q'] ?? '')), 'show' => in_array($_GET['show'] ?? '', ['live', 'admitted', 'waiting', 'cancelled', 'access'], true) ? (string) $_GET['show'] : 'all', 'with_needs' => $canManage];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $acct = hub_auth_current_user();
    $acctId = !empty($acct['account_id']) ? (int) $acct['account_id'] : null;
    try {
        if (!csrf_check()) {
            throw new RuntimeException('Your session expired. Please reload and try again.');
        }
        if ($action === 'rename') {
            if (!$canManage) { throw new RuntimeException('You do not have permission to change names.'); }
            events_change_attendee_name($pdo, (int) $_POST['ticket_id'], (string) ($_POST['name'] ?? ''), 'staff', $acctId);
            auditLog($pdo, 'event_ticket_renamed', 'Renamed event ticket #' . (int) $_POST['ticket_id'] . ' to ' . trim((string) $_POST['name']));
            $success = 'Name changed.';
        } elseif ($action === 'resend') {
            if (!$canManage) { throw new RuntimeException('You do not have permission to resend tickets.'); }
            $ok = events_send_confirmation_email($pdo, (int) $_POST['order_id'], true);
            $success = $ok ? 'The tickets were emailed again.' : 'The email could not be sent (check the order has an email address and is paid).';
            auditLog($pdo, 'event_tickets_resent', 'Resent tickets for order #' . (int) $_POST['order_id'] . ($ok ? '' : ' (failed)'));
        } elseif ($action === 'refund_ticket') {
            if (!$canRefund) { throw new RuntimeException('Only people with refund permission can refund tickets.'); }
            if (empty($_POST['confirm_money'])) { throw new RuntimeException('Tick the box to confirm the refund.'); }
            $r = events_refund_ticket($pdo, (int) $_POST['ticket_id'], (string) ($_POST['reason'] ?? ''), $acctId);
            auditLog($pdo, 'event_ticket_refunded', 'Refunded event ticket #' . (int) $_POST['ticket_id'] . ' (' . gbp($r['refunded']) . '): ' . trim((string) $_POST['reason']));
            $success = $r['refunded'] > 0 ? 'Ticket cancelled and ' . gbp($r['refunded']) . ' refunded. The booking fee is not refunded for a single ticket.' : 'Ticket cancelled.';
        } elseif ($action === 'refund_order') {
            if (!$canRefund) { throw new RuntimeException('Only people with refund permission can refund orders.'); }
            if (empty($_POST['confirm_money'])) { throw new RuntimeException('Tick the box to confirm the refund.'); }
            $r = events_refund_order($pdo, (int) $_POST['order_id'], (string) ($_POST['reason'] ?? ''), $acctId);
            auditLog($pdo, 'event_order_refunded', 'Refunded event order #' . (int) $_POST['order_id'] . ' (' . gbp($r['refunded']) . '): ' . trim((string) $_POST['reason']));
            $success = $r['refunded'] > 0 ? 'Order cancelled and ' . gbp($r['refunded']) . ' refunded in full.' : 'Order cancelled.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$rows = events_attendee_rows($pdo, $eventId, $filters);
$eventNavActive = 'attendees';
$people = 0;
$in = 0;
foreach ($rows as $r) {
    if ($r['is_live']) {
        $people += (int) $r['admits_count'];
        $in += (int) $r['admitted'];
    }
}
$self = 'event_attendees.php?event_id=' . $eventId . '&q=' . rawurlencode($filters['q']) . '&show=' . $filters['show'];
$shows = ['all' => 'All', 'live' => 'Valid', 'waiting' => 'Not arrived', 'admitted' => 'Arrived', 'cancelled' => 'Cancelled / refunded'] + ($canManage ? ['access' => 'With access requirements'] : []);
?>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title'], 'href' => 'event_edit.php?id=' . $eventId], ['label' => 'Attendees']]); ?>
<div class="hub-section-commandbar">
    <div><h2><?= h((string) $event['title']) ?></h2><p><?= count($rows) ?> ticket<?= count($rows) === 1 ? '' : 's' ?> shown · <?= $in ?> of <?= $people ?> valid people have arrived</p></div>
    <div class="hub-local-actions">
        <?php if ($canManage): ?><a class="btn btn-outline-primary" href="event_attendees_export.php?event_id=<?= $eventId ?>&amp;show=<?= h($filters['show']) ?>&amp;q=<?= h(rawurlencode($filters['q'])) ?>"><i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>Download CSV</a><?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/partials/event_subnav.php'; ?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="get" class="d-flex flex-wrap gap-2 mb-3">
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <input class="form-control" style="max-width:22rem" type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search name, email, code or order number" aria-label="Search attendees">
    <select class="form-select" style="max-width:14rem" name="show" aria-label="Show" onchange="this.form.submit()">
        <?php foreach ($shows as $key => $label): ?><option value="<?= h($key) ?>" <?= $filters['show'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-brand" type="submit">Search</button>
    <?php if ($filters['q'] !== '' || $filters['show'] !== 'all'): ?><a class="btn btn-outline-secondary" href="event_attendees.php?event_id=<?= $eventId ?>">Clear</a><?php endif; ?>
</form>

<div class="card hub-list-card hub-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive mb-0">
                <caption class="visually-hidden">Ticket holders for this event</caption>
                <thead><tr><th>Attendee</th><th>Ticket</th><th>Buyer</th><th>Paid</th><th>Status</th><th>Entry</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php $lastOrder = 0; foreach ($rows as $r): ?>
                        <?php
                        $stateTone = $r['is_live'] ? 'success' : (in_array($r['order_status'], ['needs_refund'], true) ? 'danger' : 'secondary');
                        $stateText = $r['is_live'] ? 'Valid' : ($r['order_status'] === 'needs_refund' ? 'Needs refund' : ($r['order_status'] === 'pending_payment' ? 'Unpaid' : ucfirst(str_replace('_', ' ', $r['ticket_status'] === 'active' ? $r['order_status'] : $r['ticket_status']))));
                        ?>
                        <tr>
                            <td data-label="Attendee"><strong><?= h((string) ($r['attendee_name'] ?: $r['customer_name'])) ?></strong><?php if ($canManage && (int) $r['has_needs'] > 0): ?> <span class="badge bg-info text-dark" title="This booking has access requirements">Access</span><?php endif; ?><div class="small text-muted"><?= h((string) $r['manual_code']) ?></div></td>
                            <td data-label="Ticket"><?= h((string) $r['ticket_label']) ?><?= (int) $r['admits_count'] > 1 ? '<div class="small text-muted">admits ' . (int) $r['admits_count'] . '</div>' : '' ?></td>
                            <td data-label="Buyer"><?= h((string) $r['customer_name']) ?><div class="small text-muted"><?= h((string) $r['customer_email']) ?> · order #<?= (int) $r['order_id'] ?></div></td>
                            <td data-label="Paid"><?= (float) $r['unit_price'] > 0 ? h(gbp((float) $r['unit_price'])) : ($r['payment_method'] === 'complimentary' ? 'Complimentary' : 'Free') ?></td>
                            <td data-label="Status"><?php hub_render_badge($stateText, $stateTone); ?></td>
                            <td data-label="Entry"><?php if ($r['is_live']): ?><?= (int) $r['admitted'] ?> of <?= (int) $r['admits_count'] ?><?php else: ?>-<?php endif; ?></td>
                            <td data-label="Actions">
                                <?php if ($canManage || $canRefund): ?>
                                <details>
                                    <summary class="btn btn-sm btn-outline-secondary">Manage</summary>
                                    <div class="mt-2" style="min-width:17rem">
                                        <?php if ($canManage && !empty($r['access_needs'])): ?>
                                            <div class="alert alert-info small py-2"><strong>Access requirements</strong> (private, deleted 30 days after the event)<br><?= nl2br(h((string) $r['access_needs'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($canManage && $r['is_live']): ?>
                                            <form method="post" class="mb-2">
                                                <?= csrf_field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="ticket_id" value="<?= (int) $r['ticket_id'] ?>">
                                                <div class="input-group input-group-sm"><input class="form-control" name="name" value="<?= h((string) $r['attendee_name']) ?>" aria-label="Name on ticket" maxlength="150" required><button class="btn btn-outline-primary" type="submit">Save name</button></div>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canManage && $r['customer_email'] && in_array($r['order_status'], EVENT_PAID_ORDER_STATUSES, true)): ?>
                                            <form method="post" class="mb-2">
                                                <?= csrf_field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="order_id" value="<?= (int) $r['order_id'] ?>">
                                                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Email the tickets again</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canRefund && ($r['is_live'] || $r['order_status'] === 'needs_refund')): ?>
                                            <form method="post" class="border-top pt-2">
                                                <?= csrf_field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="ticket_id" value="<?= (int) $r['ticket_id'] ?>"><input type="hidden" name="order_id" value="<?= (int) $r['order_id'] ?>">
                                                <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" aria-label="Reason" maxlength="255" required>
                                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="m<?= (int) $r['ticket_id'] ?>" name="confirm_money" value="1"><label class="form-check-label small" for="m<?= (int) $r['ticket_id'] ?>">I understand this refunds real money</label></div>
                                                <?php if ($r['is_live']): ?><button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="refund_ticket">Refund this ticket<?= (float) $r['unit_price'] > 0 ? ' (' . h(gbp((float) $r['unit_price'])) . ')' : '' ?></button><?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit" name="action" value="refund_order">Refund whole order (<?= h(gbp((float) $r['total_amount'])) ?>)</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="7" class="hub-record-empty hub-empty-state"><?= $filters['q'] !== '' || $filters['show'] !== 'all' ? 'No tickets match.' : 'No tickets have been sold yet.' ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
