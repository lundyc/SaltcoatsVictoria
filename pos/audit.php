<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';

pos_controls_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$days = $pdo->query('SELECT id, business_date, status FROM pos_trading_days ORDER BY business_date DESC, id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$selectedDayId = (int) ($_GET['trading_day_id'] ?? ($days[0]['id'] ?? 0));
$tills = $selectedDayId > 0 ? pos_till_sessions_for_day($pdo, $selectedDayId) : [];
$selectedTillId = (int) ($_GET['till_session_id'] ?? 0);
$selectedOperatorId = (int) ($_GET['operator_id'] ?? 0);
$selectedAction = trim((string) ($_GET['action_name'] ?? ''));
$operators = $pdo->query('SELECT id, name FROM pos_operators ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$actions = $pdo->query('SELECT DISTINCT action FROM pos_audit_events ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$params = [];
if ($selectedDayId > 0) {
    $where[] = 'e.trading_day_id = :day';
    $params[':day'] = $selectedDayId;
}
if ($selectedTillId > 0) {
    $where[] = 'e.till_session_id = :till';
    $params[':till'] = $selectedTillId;
}
if ($selectedOperatorId > 0) {
    $where[] = 'e.operator_id = :operator';
    $params[':operator'] = $selectedOperatorId;
}
if ($selectedAction !== '') {
    $where[] = 'e.action = :action';
    $params[':action'] = $selectedAction;
}
$sql = 'SELECT e.*, l.name AS location_name, o.name AS operator_name, a.email AS account_email
    FROM pos_audit_events e
    LEFT JOIN pos_locations l ON l.id = e.location_id
    LEFT JOIN pos_operators o ON o.id = e.operator_id
    LEFT JOIN accounts a ON a.id = e.hub_account_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY e.created_at DESC, e.id DESC LIMIT 250';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Audit Log',
    'subtitle' => 'Review till, refund, cash movement, and close-count activity.',
];
require_once __DIR__ . '/../admin/header.php';
?>
<div class="pos-audit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Audit Log</span></nav>
    <section class="card hub-panel p-3 mb-3">
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-3"><label class="form-label fw-bold" for="trading_day_id">Trading day</label><select class="form-select" id="trading_day_id" name="trading_day_id"><?php foreach ($days as $day): ?><option value="<?= (int) $day['id'] ?>" <?= (int) $day['id'] === $selectedDayId ? 'selected' : '' ?>><?= h(date('d/m/Y', strtotime((string) $day['business_date']))) ?> · <?= h((string) $day['status']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label fw-bold" for="till_session_id">Till</label><select class="form-select" id="till_session_id" name="till_session_id"><option value="0">All tills</option><?php foreach ($tills as $till): ?><option value="<?= (int) $till['id'] ?>" <?= (int) $till['id'] === $selectedTillId ? 'selected' : '' ?>><?= h((string) $till['location_name']) ?> #<?= (int) $till['id'] ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label fw-bold" for="operator_id">Operator</label><select class="form-select" id="operator_id" name="operator_id"><option value="0">All</option><?php foreach ($operators as $operator): ?><option value="<?= (int) $operator['id'] ?>" <?= (int) $operator['id'] === $selectedOperatorId ? 'selected' : '' ?>><?= h((string) $operator['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label fw-bold" for="action_name">Action</label><select class="form-select" id="action_name" name="action_name"><option value="">All</option><?php foreach ($actions as $action): ?><option value="<?= h((string) $action) ?>" <?= (string) $action === $selectedAction ? 'selected' : '' ?>><?= h((string) $action) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><button class="btn btn-dark w-100" type="submit">Filter</button></div>
        </form>
    </section>
    <section class="card hub-panel table-responsive">
        <table class="table align-middle hub-data-table mb-0">
            <thead><tr><th>Time</th><th>Action</th><th>Till</th><th>Operator</th><th class="text-end">Amount</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr><td><?= h(date('d/m/Y H:i', strtotime((string) $event['created_at']))) ?></td><td class="fw-semibold"><?= h((string) $event['action']) ?></td><td><?= h((string) ($event['location_name'] ?: '-')) ?></td><td><?= h((string) ($event['operator_name'] ?: $event['account_email'] ?: '-')) ?></td><td class="text-end"><?= $event['amount'] !== null ? gbp((float) $event['amount']) : '-' ?></td><td><?= h((string) ($event['details'] ?? '')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$events): ?><tr><td colspan="6" class="hub-empty-state">No POS audit events match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/../admin/footer.php'; ?>
