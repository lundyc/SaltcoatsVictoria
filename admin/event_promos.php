<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Promo Codes',
    'subtitle' => 'Discount codes, and invite codes that release hidden tickets for guests, sponsors and committee.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events_orders.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventOrdersSchema($pdo);

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (!$canManage) {
        $errors[] = 'You do not have permission to change promo codes.';
    } else {
        try {
            $postedId = (int) ($_POST['id'] ?? 0) ?: null;
            $savedId = events_save_promo($pdo, $eventId, $postedId, $_POST);
            auditLog($pdo, $postedId ? 'event_promo_updated' : 'event_promo_created', 'Saved promo code #' . $savedId . ' (' . events_promo_normalise((string) $_POST['code']) . ') for event #' . $eventId);
            $success = 'Code saved.';
            $editingId = 0;
            $adding = false;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $editingId = (int) ($_POST['id'] ?? 0);
            $adding = $editingId === 0;
        }
    }
}

$promos = events_promos($pdo, $eventId);
$types = events_ticket_types($pdo, $eventId);
$hiddenTypes = array_values(array_filter($types, static fn (array $t): bool => $t['visibility'] === 'hidden'));
$editing = null;
foreach ($promos as $pr) {
    if ((int) $pr['id'] === $editingId) {
        $editing = $pr;
    }
}
$showForm = $canManage && ($editing || $adding);
$dt = static fn ($v): string => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$form = $editing ?? ['id' => 0, 'code' => '', 'kind' => 'percent', 'value' => '', 'ticket_type_id' => '', 'unlock_type_id' => '', 'max_uses' => '', 'valid_from' => '', 'valid_to' => '', 'is_active' => 1, 'note' => ''];
if ($errors && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form = array_merge($form, array_intersect_key($_POST, $form), ['is_active' => isset($_POST['is_active']) ? 1 : 0]);
}
$eventNavActive = 'promos';
$publicBase = rtrim(function_exists('stripe_public_base_url') ? stripe_public_base_url() : 'https://saltcoatsvictoria.co.uk', '/');
?>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title'], 'href' => 'event_edit.php?id=' . $eventId], ['label' => 'Promo codes']]); ?>
<div class="hub-section-commandbar">
    <div><h2><?= h((string) $event['title']) ?></h2><p>Customers enter a code at checkout, or you can send them a link that has it filled in.</p></div>
    <div class="hub-local-actions"><?php if ($canManage && !$showForm): ?><a href="event_promos.php?event_id=<?= $eventId ?>&amp;add=1" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add code</a><?php endif; ?></div>
</div>
<?php require __DIR__ . '/partials/event_subnav.php'; ?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($showForm): ?>
<div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
    <h3 class="h5 mb-3"><?= $editing ? 'Edit ' . h((string) $editing['code']) : 'Add a code' ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="pcCode">Code</label><input class="form-control text-uppercase" id="pcCode" name="code" value="<?= h((string) $form['code']) ?>" maxlength="30" placeholder="e.g. SPEAKER10" required></div>
            <div class="col-md-4"><label class="form-label" for="pcKind">What it does</label>
                <select class="form-select" id="pcKind" name="kind"><?php foreach (EVENT_PROMO_KINDS as $k => $label): ?><option value="<?= h($k) ?>" <?= $form['kind'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4" data-pc="discount"><label class="form-label" for="pcValue">Value (% or £ off each ticket)</label><input class="form-control" id="pcValue" type="number" step="0.01" min="0" name="value" value="<?= h((string) $form['value']) ?>"></div>
            <div class="col-md-6" data-pc="discount"><label class="form-label" for="pcType">Only for this ticket</label>
                <select class="form-select" id="pcType" name="ticket_type_id"><option value="">All tickets</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $form['ticket_type_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= h((string) $t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6" data-pc="unlock"><label class="form-label" for="pcUnlock">Hidden ticket it unlocks</label>
                <select class="form-select" id="pcUnlock" name="unlock_type_id"><option value="">Choose...</option><?php foreach ($hiddenTypes as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $form['unlock_type_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= h((string) $t['name']) ?></option><?php endforeach; ?></select>
                <?php if (!$hiddenTypes): ?><div class="form-text text-danger">No hidden tickets yet. Set a ticket type to "Hidden (code only)" on the Tickets page.</div><?php endif; ?></div>
            <div class="col-md-3"><label class="form-label" for="pcMax">Most times it can be used</label><input class="form-control" id="pcMax" type="number" min="1" name="max_uses" value="<?= h((string) ($form['max_uses'] ?? '')) ?>" placeholder="No limit"></div>
            <div class="col-md-3"><label class="form-label" for="pcFrom">Valid from</label><input class="form-control" id="pcFrom" type="datetime-local" name="valid_from" value="<?= h($dt($form['valid_from'])) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="pcTo">Valid until</label><input class="form-control" id="pcTo" type="datetime-local" name="valid_to" value="<?= h($dt($form['valid_to'])) ?>"></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pcActive" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pcActive">Active</label></div></div>
            <div class="col-12"><label class="form-label" for="pcNote">Note <span class="text-muted">(who it is for, internal)</span></label><input class="form-control" id="pcNote" name="note" value="<?= h((string) ($form['note'] ?? '')) ?>" maxlength="200"></div>
        </div>
        <div class="d-flex gap-2 mt-3"><button class="btn btn-brand" type="submit">Save code</button><a class="btn btn-outline-secondary" href="event_promos.php?event_id=<?= $eventId ?>">Cancel</a></div>
    </form>
</div>
<script>
(function () {
    var kind = document.getElementById('pcKind');
    function sync() {
        var unlock = kind.value === 'unlock';
        document.querySelectorAll('[data-pc="discount"]').forEach(function (el) { el.style.display = unlock ? 'none' : ''; });
        document.querySelectorAll('[data-pc="unlock"]').forEach(function (el) { el.style.display = unlock ? '' : 'none'; });
    }
    kind.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>

<div class="card hub-list-card hub-table-card">
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table align-middle hub-data-table hub-data-table--responsive mb-0">
            <caption class="visually-hidden">Promo codes for this event</caption>
            <thead><tr><th>Code</th><th>What it does</th><th>Used</th><th>Valid</th><th>Link to send</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($promos as $pr): $problem = events_promo_problem($pr, (int) $pr['uses']); ?>
                    <tr class="<?= $problem !== '' ? 'text-muted' : '' ?>">
                        <td data-label="Code"><strong><?= h((string) $pr['code']) ?></strong><?php if ($pr['note']): ?><div class="small text-muted"><?= h((string) $pr['note']) ?></div><?php endif; ?></td>
                        <td data-label="What it does"><?php
                            if ($pr['kind'] === 'percent') { echo h(rtrim(rtrim(number_format((float) $pr['value'], 2), '0'), '.')) . '% off ' . ($pr['type_name'] ? h((string) $pr['type_name']) : 'all tickets'); }
                            elseif ($pr['kind'] === 'amount') { echo h(gbp((float) $pr['value'])) . ' off each ' . ($pr['type_name'] ? h((string) $pr['type_name']) . ' ticket' : 'ticket'); }
                            else { echo 'Unlocks <strong>' . h((string) $pr['unlock_name']) . '</strong>'; } ?></td>
                        <td data-label="Used"><?= (int) $pr['uses'] ?><?= $pr['max_uses'] !== null ? ' of ' . (int) $pr['max_uses'] : '' ?></td>
                        <td data-label="Valid"><?= $problem !== '' ? '<span class="badge bg-secondary">' . h(ucfirst(rtrim($problem, '.'))) . '</span>' : '<span class="badge bg-success">Working</span>' ?>
                            <?php if ($pr['valid_to']): ?><div class="small text-muted">until <?= h(date('j M, H:i', strtotime((string) $pr['valid_to']))) ?></div><?php endif; ?></td>
                        <td data-label="Link to send"><input class="form-control form-control-sm" readonly value="<?= h($publicBase . '/events/' . $event['slug'] . '?code=' . rawurlencode((string) $pr['code'])) ?>" onclick="this.select()" aria-label="Link with this code"></td>
                        <td data-label="Actions"><div class="hub-row-actions hub-actions"><?php if ($canManage): ?><a href="event_promos.php?event_id=<?= $eventId ?>&amp;id=<?= (int) $pr['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php endif; ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$promos): ?><tr><td colspan="6" class="hub-record-empty hub-empty-state">No codes yet. Add one to offer a discount or release an invite-only ticket.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
