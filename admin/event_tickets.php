<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Event Tickets',
    'subtitle' => 'Ticket types, prices and how many of each can be sold.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/events_mail.php';
ensureEventsSchema($pdo);

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
$success = '';
$editingId = (int) ($_GET['id'] ?? 0);
$adding = isset($_GET['add']);
$compMode = isset($_GET['comp']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (!$canManage) {
        $errors[] = 'You do not have permission to change ticket types.';
    } elseif (($_POST['action'] ?? '') === 'comp') {
        // Complimentary tickets: free, issued by staff with a recorded reason.
        $compMode = true;
        try {
            $acct = hub_auth_current_user();
            $compNames = array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['attendees'] ?? '')) ?: []), static fn ($n) => $n !== '');
            $issued = events_issue_complimentary($pdo, $eventId, (int) ($_POST['ticket_type_id'] ?? 0), (int) ($_POST['qty'] ?? 0),
                ['name' => (string) ($_POST['recipient_name'] ?? ''), 'email' => (string) ($_POST['recipient_email'] ?? '')],
                (string) ($_POST['reason'] ?? ''), !empty($acct['account_id']) ? (int) $acct['account_id'] : null, $compNames);
            auditLog($pdo, 'event_comp_tickets_issued', 'Issued ' . (int) $_POST['qty'] . ' complimentary ticket(s) for event #' . $eventId . ' to ' . trim((string) $_POST['recipient_name']) . ': ' . trim((string) $_POST['reason']) . ' (order #' . $issued['order_id'] . ')');
            $sentNote = '';
            if (!empty($_POST['send_email']) && trim((string) ($_POST['recipient_email'] ?? '')) !== '') {
                $sentNote = events_send_confirmation_email($pdo, $issued['order_id']) ? ' The tickets were emailed.' : ' The email could not be sent.';
            }
            $success = 'Complimentary tickets issued.' . $sentNote . ' <a href="' . h(events_public_order_url($issued['access_token'])) . '" target="_blank" rel="noopener">Open the ticket page</a>';
            $compMode = false;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } else {
        try {
            $postedId = (int) ($_POST['id'] ?? 0) ?: null;
            $override = !empty($_POST['allow_over_capacity']);
            $savedId = events_save_ticket_type($pdo, $eventId, $postedId, $_POST, $override);
            $overSummary = events_capacity_summary($pdo, $event);
            auditLog($pdo, $postedId ? 'event_ticket_type_updated' : 'event_ticket_type_created',
                'Saved ticket type #' . $savedId . ' (' . trim((string) ($_POST['name'] ?? '')) . ') for event #' . $eventId
                . ($overSummary['over'] ? ' - OVER CAPACITY by override: ' . $overSummary['allocated'] . ' of ' . $overSummary['capacity'] . ' seats' : ''));
            $success = 'Ticket type saved.';
            $editingId = 0;
            $adding = false;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $editingId = (int) ($_POST['id'] ?? 0);
            $adding = $editingId === 0;
        }
    }
}

$types = events_ticket_types($pdo, $eventId);
$summary = events_capacity_summary($pdo, $event);
$editing = $editingId > 0 ? events_ticket_type($pdo, $editingId) : null;
if ($editing && (int) $editing['event_id'] !== $eventId) {
    $editing = null;
}
$showForm = $canManage && ($editing || $adding);
$form = $editing ?? ['id' => 0, 'pool' => 'general', 'name' => '', 'description' => '', 'price' => '', 'allocation' => '', 'admits_count' => 1, 'max_per_order' => '', 'sale_starts_at' => '', 'sale_ends_at' => '', 'audience' => 'everyone', 'member_discount_pct' => '', 'visibility' => 'public', 'sort_order' => count($types), 'is_active' => 1];
if ($errors && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form = array_merge($form, array_intersect_key($_POST, $form), ['is_active' => isset($_POST['is_active']) ? 1 : 0]);
}
$dt = static fn ($v): string => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$pct = $summary['capacity'] > 0 ? min(100, (int) round($summary['allocated'] / $summary['capacity'] * 100)) : 0;
$onSaleTypes = array_filter($types, static fn (array $t): bool => (int) $t['is_active'] === 1);
$potential = 0.0;
foreach ($onSaleTypes as $t) {
    $potential += (float) $t['price'] * (int) $t['allocation'];
}
$statusTone = ['draft' => 'secondary', 'on_sale' => 'success', 'sold_out' => 'warning', 'postponed' => 'warning', 'cancelled' => 'danger', 'finished' => 'dark'];
?>
<style>
.et-panel { padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.et-cap { height: 10px; }
</style>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title'], 'href' => 'event_edit.php?id=' . (int) $eventId], ['label' => 'Tickets']]); ?>

<?php hub_render_metric_grid([
    ['label' => 'Seats allocated', 'value' => $summary['allocated'] . ' / ' . $summary['capacity'], 'meta' => $summary['over'] ? 'Over capacity by ' . abs((int) $summary['remaining']) : max(0, (int) $summary['remaining']) . ' not yet allocated', 'icon' => 'fa-chair', 'tone' => $summary['over'] ? 'danger' : ($pct >= 90 ? 'warning' : 'success')],
    ['label' => 'Ticket types', 'value' => (string) count($onSaleTypes), 'meta' => count($types) - count($onSaleTypes) > 0 ? (count($types) - count($onSaleTypes)) . ' off sale' : 'all on sale', 'icon' => 'fa-ticket', 'tone' => 'primary'],
    ['label' => 'Ticket value', 'value' => gbp($potential), 'meta' => 'if every ticket sells (before booking fee)', 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
], 'Ticket summary'); ?>

<?php $eventNavActive = 'tickets'; require __DIR__ . '/partials/event_subnav.php'; ?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= $success === 'Ticket type saved.' ? h($success) : $success ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="hub-section-commandbar">
    <div>
        <h2><?= h((string) $event['title']) ?> <?php hub_render_badge(EVENT_STATUSES[$event['status']] ?? (string) $event['status'], $statusTone[$event['status']] ?? 'secondary'); ?></h2>
        <p><?= h(date('D j M Y, H:i', strtotime((string) $event['starts_at']))) ?> · <?= h((string) $event['venue_name']) ?> · holds <?= (int) $summary['capacity'] ?></p>
    </div>
    <div class="hub-local-actions">
        <a href="event_edit.php?id=<?= (int) $eventId ?>" class="btn btn-outline-secondary">Event details</a>
        <?php if ($canManage && !$showForm && !$compMode): ?>
            <a href="event_tickets.php?event_id=<?= (int) $eventId ?>&amp;comp=1" class="btn btn-outline-primary"><i class="fa-solid fa-gift me-1" aria-hidden="true"></i>Issue complimentary tickets</a>
            <a href="event_tickets.php?event_id=<?= (int) $eventId ?>&amp;add=1" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add ticket type</a>
        <?php endif; ?>
    </div>
</div>

<div class="hub-panel et-panel">
    <div class="d-flex justify-content-between"><strong>Seats allocated</strong><span><?= (int) $summary['allocated'] ?> of <?= (int) $summary['capacity'] ?><?= $summary['over'] ? ' <span class="badge bg-danger">Over capacity</span>' : '' ?></span></div>
    <div class="progress mt-2 et-cap" role="progressbar" aria-label="Seats allocated" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $summary['over'] ? 'bg-danger' : ($pct >= 90 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $pct ?>%"></div></div>
    <div class="small text-muted mt-2">A ticket that admits several people counts as that many seats (a Table of 4 uses 4).</div>
    <?php if ((int) $summary['access_places'] > 0): ?>
        <div class="small mt-2">General seats: <strong><?= (int) $summary['general_allocated'] ?> of <?= (int) $summary['general_capacity'] ?></strong><?= $summary['general_over'] ? ' <span class="badge bg-danger">over</span>' : '' ?>
            · Accessible places: <strong><?= (int) $summary['access_allocated'] ?> of <?= (int) $summary['access_places'] ?></strong><?= $summary['access_over'] ? ' <span class="badge bg-danger">over</span>' : '' ?>
            <span class="text-muted">· <?= events_access_released($event) ? 'unsold accessible places are open to everyone' : 'unsold accessible places are held back from general sale' ?></span></div>
    <?php endif; ?>
</div>

<?php if ($canManage && $compMode): ?>
<div class="hub-panel et-panel">
    <h3 class="h5 mb-1">Issue complimentary tickets</h3>
    <p class="text-muted small mb-3">Free tickets for guests, speakers or sponsors. They use up seats like any other ticket and the reason is kept in the record.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <input type="hidden" name="action" value="comp">
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label" for="cpType">Ticket</label>
                <select class="form-select" id="cpType" name="ticket_type_id" required>
                    <?php foreach ($types as $t): if ((int) $t['is_active'] !== 1) { continue; } ?>
                        <option value="<?= (int) $t['id'] ?>" <?= (int) ($_POST['ticket_type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= h((string) $t['name']) ?><?= $t['visibility'] === 'hidden' ? ' (hidden)' : '' ?><?= (int) $t['admits_count'] > 1 ? ' — admits ' . (int) $t['admits_count'] : '' ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-2"><label class="form-label" for="cpQty">Quantity</label><input class="form-control" id="cpQty" type="number" min="1" max="50" name="qty" value="<?= h((string) ($_POST['qty'] ?? '1')) ?>" required></div>
            <div class="col-md-5"><label class="form-label" for="cpReason">Reason</label><input class="form-control" id="cpReason" name="reason" value="<?= h((string) ($_POST['reason'] ?? '')) ?>" maxlength="255" placeholder="e.g. Speaker's guests" required></div>
            <div class="col-md-5"><label class="form-label" for="cpName">Who are they for?</label><input class="form-control" id="cpName" name="recipient_name" value="<?= h((string) ($_POST['recipient_name'] ?? '')) ?>" maxlength="190" required></div>
            <div class="col-md-5"><label class="form-label" for="cpEmail">Email <span class="text-muted">(optional)</span></label><input class="form-control" id="cpEmail" type="email" name="recipient_email" value="<?= h((string) ($_POST['recipient_email'] ?? '')) ?>"></div>
            <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="cpSend" name="send_email" value="1" <?= !empty($_POST['send_email']) ? 'checked' : '' ?>><label class="form-check-label" for="cpSend">Email them</label></div></div>
            <div class="col-12"><label class="form-label" for="cpAtt">Names on the tickets <span class="text-muted">(optional, one per line)</span></label><textarea class="form-control" id="cpAtt" name="attendees" rows="2"><?= h((string) ($_POST['attendees'] ?? '')) ?></textarea></div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-brand" type="submit">Issue tickets</button>
            <a class="btn btn-outline-secondary" href="event_tickets.php?event_id=<?= (int) $eventId ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="hub-panel et-panel">
    <h3 class="h5 mb-3"><?= $editing ? 'Edit ' . h((string) $editing['name']) : 'Add ticket type' ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="ttName">Name</label><input class="form-control" id="ttName" name="name" value="<?= h((string) $form['name']) ?>" maxlength="120" placeholder="Standard, Under-16, Table of 8" required></div>
            <div class="col-md-5"><label class="form-label" for="ttDesc">Description</label><input class="form-control" id="ttDesc" name="description" value="<?= h((string) ($form['description'] ?? '')) ?>" maxlength="255"></div>
            <div class="col-md-3"><label class="form-label" for="ttSort">Order in list</label><input class="form-control" id="ttSort" type="number" name="sort_order" value="<?= (int) $form['sort_order'] ?>"></div>

            <div class="col-md-3"><label class="form-label" for="ttPrice">Price (£)</label><input class="form-control" id="ttPrice" type="number" step="0.01" min="0" name="price" value="<?= h((string) $form['price']) ?>" placeholder="0 = free" required></div>
            <div class="col-md-3"><label class="form-label" for="ttAlloc">Number available</label><input class="form-control" id="ttAlloc" type="number" min="1" name="allocation" value="<?= h((string) $form['allocation']) ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="ttAdmits">People per ticket</label><input class="form-control" id="ttAdmits" type="number" min="1" max="50" name="admits_count" value="<?= (int) $form['admits_count'] ?>"><div class="form-text">8 for a table of 8.</div></div>
            <div class="col-md-3"><label class="form-label" for="ttMax">Max per order</label><input class="form-control" id="ttMax" type="number" min="1" name="max_per_order" value="<?= h((string) ($form['max_per_order'] ?? '')) ?>" placeholder="No limit"></div>

            <div class="col-md-4"><label class="form-label" for="ttAud">Who can buy</label>
                <select class="form-select" id="ttAud" name="audience">
                    <?php foreach (EVENT_AUDIENCES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === $form['audience'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">Members are active season ticket holders. "Members first" opens <?= (int) EVENT_MEMBER_EARLY_ACCESS_HOURS ?> hours early.</div>
            </div>
            <div class="col-md-2"><label class="form-label" for="ttDisc">Member discount (%)</label><input class="form-control" id="ttDisc" type="number" step="0.01" min="0" max="100" name="member_discount_pct" value="<?= h((string) ($form['member_discount_pct'] ?? '')) ?>" placeholder="<?= h((string) EVENT_DEFAULT_MEMBER_DISCOUNT_PCT) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="ttFrom">Sale starts</label><input class="form-control" id="ttFrom" type="datetime-local" name="sale_starts_at" value="<?= h($dt($form['sale_starts_at'])) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="ttTo">Sale ends</label><input class="form-control" id="ttTo" type="datetime-local" name="sale_ends_at" value="<?= h($dt($form['sale_ends_at'])) ?>"></div>

            <div class="col-md-4"><label class="form-label" for="ttVis">Visibility</label><select class="form-select" id="ttVis" name="visibility"><option value="public" <?= $form['visibility'] === 'public' ? 'selected' : '' ?>>Public</option><option value="hidden" <?= $form['visibility'] === 'hidden' ? 'selected' : '' ?>>Hidden (code only)</option></select></div>
            <div class="col-md-4"><label class="form-label" for="ttPool">Seats come from</label>
                <select class="form-select" id="ttPool" name="pool"><?php foreach (EVENT_POOLS as $k => $label): ?><option value="<?= h($k) ?>" <?= ($form['pool'] ?? 'general') === $k ? 'selected' : '' ?>><?= h($label) ?><?= $k === 'access' ? ' (' . (int) ($event['access_places'] ?? 0) . ' held back)' : '' ?></option><?php endforeach; ?></select>
                <div class="form-text">Use "Accessible places" for wheelchair spaces and carer tickets. A ticket that admits 2 gives a wheelchair user a free companion.</div></div>
            <div class="col-md-4">
                <div class="form-check form-switch mt-md-4"><input class="form-check-input" type="checkbox" role="switch" id="ttActive" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="ttActive">On sale (counts towards capacity)</label></div>
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="ttOver" name="allow_over_capacity" value="1"><label class="form-check-label" for="ttOver">Allow going over the event's capacity (override, recorded in the audit log)</label></div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-brand" type="submit">Save ticket type</button>
            <a class="btn btn-outline-secondary" href="event_tickets.php?event_id=<?= (int) $eventId ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card hub-list-card hub-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive">
                <caption class="visually-hidden">Ticket types for this event: price, number available, seats, audience and actions</caption>
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Price</th>
                        <th>Available</th>
                        <th>Seats</th>
                        <th>Who can buy</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $type): ?>
                        <tr class="<?= (int) $type['is_active'] === 1 ? '' : 'text-muted' ?>">
                            <td data-label="Ticket">
                                <strong><?= h((string) $type['name']) ?></strong>
                                <?php if ((int) $type['is_active'] !== 1): ?><span class="badge bg-secondary ms-1">Off sale</span><?php endif; ?>
                                <?php if ($type['visibility'] === 'hidden'): ?><span class="badge bg-dark ms-1">Hidden</span><?php endif; ?>
                                <?php if ($type['description']): ?><div class="small text-muted"><?= h((string) $type['description']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Price">
                                <strong><?= (float) $type['price'] > 0 ? gbp((float) $type['price']) : 'Free' ?></strong>
                                <?php if ((float) $type['price'] > 0): ?><div class="small text-muted">Members <?= gbp(events_member_price($type)) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Available"><?= (int) $type['allocation'] ?></td>
                            <td data-label="Seats"><?= events_type_seats($type) ?><?= ($type['pool'] ?? 'general') === 'access' ? ' <span class="badge bg-info text-dark">Accessible</span>' : '' ?><?php if ((int) $type['admits_count'] > 1): ?><div class="small text-muted"><?= (int) $type['admits_count'] ?> per ticket</div><?php endif; ?></td>
                            <td data-label="Who can buy"><?= h(EVENT_AUDIENCES[$type['audience']] ?? (string) $type['audience']) ?></td>
                            <td data-label="Actions" class="text-nowrap">
                                <div class="hub-row-actions hub-actions"><?php if ($canManage): ?><a href="event_tickets.php?event_id=<?= (int) $eventId ?>&amp;id=<?= (int) $type['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php endif; ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$types): ?>
                        <tr><td colspan="6" class="hub-record-empty hub-empty-state">No ticket types yet. Add the first one to start selling.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="small text-muted mt-3">A booking fee of 1.5% + 20p is added once to each paid order at checkout (not per ticket). Free orders pay no fee. The rates are settings and can change when Stripe's prices do.</p>

<?php require_once __DIR__ . '/footer.php'; ?>
