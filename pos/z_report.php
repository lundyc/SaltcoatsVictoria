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

$days = $pdo->query('SELECT * FROM pos_trading_days ORDER BY business_date DESC, id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$activeDay = pos_trading_day_active($pdo);
$selectedDayId = (int) ($_GET['trading_day_id'] ?? ($activeDay['id'] ?? ($days[0]['id'] ?? 0)));
$selectedTillId = (int) ($_GET['till_session_id'] ?? 0);
$selectedDay = null;
foreach ($days as $day) {
    if ((int) $day['id'] === $selectedDayId) {
        $selectedDay = $day;
        break;
    }
}

$tills = $selectedDayId > 0 ? pos_till_sessions_for_day($pdo, $selectedDayId) : [];
$selectedTill = null;
foreach ($tills as $till) {
    if ((int) $till['id'] === $selectedTillId) {
        $selectedTill = $till;
        break;
    }
}

$scopeWhere = $selectedTill ? 's.till_session_id = :till' : 's.trading_day_id = :day';
$scopeParams = $selectedTill ? [':till' => (int) $selectedTill['id']] : [':day' => $selectedDayId];
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(CASE WHEN status = 'complete' THEN 1 END) AS sale_count,
        COALESCE(SUM(CASE WHEN status = 'complete' THEN total ELSE 0 END), 0) AS gross_sales,
        COALESCE(SUM(CASE WHEN status = 'refund' THEN total ELSE 0 END), 0) AS refunds,
        COALESCE(SUM(CASE WHEN payment_method = 'cash' AND status IN ('complete', 'refund') THEN total ELSE 0 END), 0) AS cash_total,
        COALESCE(SUM(CASE WHEN payment_method IN ('card', 'card_stripe_app') AND status IN ('complete', 'refund') THEN total ELSE 0 END), 0) AS card_total,
        COALESCE(SUM(CASE WHEN status = 'complete' THEN discount_total + points_discount ELSE 0 END), 0) AS discounts
    FROM pos_sales s
    WHERE {$scopeWhere}
");
$summaryStmt->execute($scopeParams);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$movementWhere = $selectedTill ? 'till_session_id = :till' : 'trading_day_id = :day';
$movementStmt = $pdo->prepare("
    SELECT movement_type, COUNT(*) AS movement_count, COALESCE(SUM(amount), 0) AS amount
    FROM pos_cash_movements
    WHERE {$movementWhere}
    GROUP BY movement_type
");
$movementStmt->execute($scopeParams);
$movements = [];
foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
    $movements[(string) $movement['movement_type']] = $movement;
}

$openingFloat = $selectedTill ? (float) $selectedTill['opening_float'] : array_sum(array_map(static fn(array $till): float => (float) $till['opening_float'], $tills));
$countedCash = $selectedTill ? ($selectedTill['counted_cash'] !== null ? (float) $selectedTill['counted_cash'] : null) : array_sum(array_map(static fn(array $till): float => $till['counted_cash'] !== null ? (float) $till['counted_cash'] : 0.0, $tills));
$expectedCash = $selectedTill ? ($selectedTill['expected_cash'] !== null ? (float) $selectedTill['expected_cash'] : pos_till_session_expected_cash($pdo, (int) $selectedTill['id'])) : array_sum(array_map(static fn(array $till): float => $till['expected_cash'] !== null ? (float) $till['expected_cash'] : pos_till_session_expected_cash($pdo, (int) $till['id']), $tills));
$variance = $countedCash !== null ? round((float) $countedCash - (float) $expectedCash, 2) : null;

$cashMovementNet = 0.0;
foreach ($movements as $type => $movement) {
    $amount = (float) $movement['amount'];
    $cashMovementNet += $type === 'paid_in' ? $amount : -$amount;
}

$salesStmt = $pdo->prepare("
    SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
    WHERE {$scopeWhere}
    ORDER BY s.created_at
");
$salesStmt->execute($scopeParams);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

$notice = '';
$error = '';
$snapshotStmt = $pdo->prepare('SELECT * FROM pos_z_report_snapshots WHERE trading_day_id = :day AND (till_session_id <=> :till) AND report_type = "z" ORDER BY id DESC LIMIT 1');
$snapshotStmt->execute([':day' => $selectedDayId, ':till' => $selectedTill ? (int) $selectedTill['id'] : null]);
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } elseif ($snapshot) {
        $error = 'A final Z snapshot already exists for this report scope.';
    } elseif ($selectedTill && (string) $selectedTill['status'] !== 'closed') {
        $error = 'Close this till before finalising its Z report.';
    } elseif (!$selectedTill && $selectedDay && (string) $selectedDay['status'] !== 'closed') {
        $error = 'Close the POS day before finalising the full day Z report.';
    } else {
        $reportData = [
            'scope' => $selectedTill ? 'till' : 'day',
            'trading_day' => $selectedDay,
            'till' => $selectedTill,
            'summary' => $summary,
            'movements' => $movements,
            'opening_float' => $openingFloat,
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'variance' => $variance,
            'cash_movement_net' => $cashMovementNet,
            'sales' => $sales,
        ];
        $insert = $pdo->prepare('INSERT INTO pos_z_report_snapshots (trading_day_id, till_session_id, report_type, report_data, created_by_account_id, created_by_operator_id, created_by_name) VALUES (:day, :till, "z", :report_data, :account, :operator, :actor_name)');
        $insert->execute([
            ':day' => $selectedDayId,
            ':till' => $selectedTill ? (int) $selectedTill['id'] : null,
            ':report_data' => json_encode($reportData, JSON_UNESCAPED_SLASHES),
            ':account' => pos_controls_actor_account_id($actor),
            ':operator' => pos_controls_actor_operator_id($actor),
            ':actor_name' => (string) ($actor['name'] ?? ''),
        ]);
        pos_audit_event($pdo, 'pos_z_report_finalised', 'Finalised Z report for ' . ($selectedTill ? 'till #' . (int) $selectedTill['id'] : 'POS day #' . $selectedDayId), [
            'trading_day_id' => $selectedDayId,
            'till_session_id' => $selectedTill ? (int) $selectedTill['id'] : null,
            'location_id' => $selectedTill ? (int) $selectedTill['location_id'] : null,
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
        ]);
        $notice = 'Z report snapshot finalised.';
        $snapshotStmt->execute([':day' => $selectedDayId, ':till' => $selectedTill ? (int) $selectedTill['id'] : null]);
        $snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if ($snapshot) {
    $snapshotData = json_decode((string) $snapshot['report_data'], true);
    if (is_array($snapshotData)) {
        $summary = is_array($snapshotData['summary'] ?? null) ? $snapshotData['summary'] : $summary;
        $movements = is_array($snapshotData['movements'] ?? null) ? $snapshotData['movements'] : $movements;
        $openingFloat = (float) ($snapshotData['opening_float'] ?? $openingFloat);
        $expectedCash = (float) ($snapshotData['expected_cash'] ?? $expectedCash);
        $countedCash = array_key_exists('counted_cash', $snapshotData) ? $snapshotData['counted_cash'] : $countedCash;
        $variance = array_key_exists('variance', $snapshotData) ? $snapshotData['variance'] : $variance;
        $cashMovementNet = (float) ($snapshotData['cash_movement_net'] ?? $cashMovementNet);
        $sales = is_array($snapshotData['sales'] ?? null) ? $snapshotData['sales'] : $sales;
    }
}

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'X/Z Reports',
    'subtitle' => 'Print till close reports and full POS day summaries.',
];
require_once __DIR__ . '/../admin/header.php';
?>
<style>
@media print {
    header, footer, .page-hero, .hub-breadcrumb, .hub-section-commandbar, .pos-report-filters, .btn { display: none !important; }
    body { background: #fff !important; }
    .hub-panel, .pos-card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>
<div class="pos-z-report-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">X/Z Reports</span></nav>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <section class="hub-section-commandbar" aria-labelledby="zReportTitle">
        <div>
            <h2 id="zReportTitle"><?= $selectedTill ? 'Till close report' : 'POS day report' ?></h2>
            <p><?= $selectedDay ? h(date('d/m/Y', strtotime((string) $selectedDay['business_date']))) . ' · ' . h((string) $selectedDay['status']) : 'No trading day selected' ?></p>
        </div>
        <div class="hub-local-actions">
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i>Print</button>
            <?php if (!$snapshot): ?><form method="post" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-lock" aria-hidden="true"></i>Finalise Z</button></form><?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/reports.php"><i class="fa-solid fa-table-list" aria-hidden="true"></i>Reports</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/tills.php"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Tills</a>
        </div>
    </section>
    <?php if ($snapshot): ?><div class="alert alert-info">Final Z snapshot locked on <?= h(date('d/m/Y H:i', strtotime((string) $snapshot['created_at']))) ?> by <?= h((string) ($snapshot['created_by_name'] ?: 'Manager')) ?>.</div><?php endif; ?>

    <form class="card hub-panel p-3 mb-3 pos-report-filters" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-md-5"><label class="form-label fw-bold" for="trading_day_id">Trading day</label><select class="form-select" id="trading_day_id" name="trading_day_id" onchange="this.form.submit()"><?php foreach ($days as $day): ?><option value="<?= (int) $day['id'] ?>" <?= (int) $day['id'] === $selectedDayId ? 'selected' : '' ?>><?= h(date('d/m/Y', strtotime((string) $day['business_date']))) ?> · <?= h((string) $day['status']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label fw-bold" for="till_session_id">Report scope</label><select class="form-select" id="till_session_id" name="till_session_id"><option value="0">Full POS day</option><?php foreach ($tills as $till): ?><option value="<?= (int) $till['id'] ?>" <?= (int) $till['id'] === $selectedTillId ? 'selected' : '' ?>><?= h((string) $till['location_name']) ?> #<?= (int) $till['id'] ?> · <?= h((string) $till['status']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><button class="btn btn-dark w-100" type="submit">View</button></div>
        </div>
    </form>

    <?php hub_render_metric_grid([
        ['label' => 'Gross sales', 'value' => gbp((float) ($summary['gross_sales'] ?? 0)), 'meta' => (int) ($summary['sale_count'] ?? 0) . ' completed sales', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
        ['label' => 'Refunds', 'value' => gbp((float) ($summary['refunds'] ?? 0)), 'meta' => 'Negative transactions', 'icon' => 'fa-rotate-left', 'tone' => 'warning'],
        ['label' => 'Cash', 'value' => gbp((float) ($summary['cash_total'] ?? 0)), 'meta' => 'Net cash sales', 'icon' => 'fa-money-bill-wave', 'tone' => 'primary'],
        ['label' => 'Card', 'value' => gbp((float) ($summary['card_total'] ?? 0)), 'meta' => 'Net card sales', 'icon' => 'fa-credit-card', 'tone' => 'info'],
    ], 'Z report summary'); ?>

    <section class="card hub-panel table-responsive mb-3">
        <table class="table align-middle mb-0">
            <tbody>
                <tr><th>Report scope</th><td><?= $selectedTill ? h((string) $selectedTill['location_name']) . ' till #' . (int) $selectedTill['id'] : 'Full POS day' ?></td><th>Opening float</th><td class="text-end fw-bold"><?= gbp($openingFloat) ?></td></tr>
                <tr><th>Opened by</th><td><?= $selectedTill ? h((string) ($selectedTill['opened_by_name'] ?: '-')) : h((string) ($selectedDay['opened_by_name'] ?? '-')) ?></td><th>Expected cash</th><td class="text-end fw-bold"><?= gbp((float) $expectedCash) ?></td></tr>
                <tr><th>Closed by</th><td><?= $selectedTill ? h((string) ($selectedTill['closed_by_name'] ?: '-')) : h((string) ($selectedDay['closed_by_name'] ?? '-')) ?></td><th>Counted cash</th><td class="text-end fw-bold"><?= $countedCash !== null ? gbp((float) $countedCash) : '-' ?></td></tr>
                <tr><th>Opened</th><td><?= $selectedTill ? h(date('d/m/Y H:i', strtotime((string) $selectedTill['opened_at']))) : h(date('d/m/Y H:i', strtotime((string) ($selectedDay['opened_at'] ?? 'now')))) ?></td><th>Variance</th><td class="text-end fw-bold"><?= $variance !== null ? gbp($variance) : '-' ?></td></tr>
                <tr><th>Closed</th><td><?= $selectedTill && $selectedTill['closed_at'] ? h(date('d/m/Y H:i', strtotime((string) $selectedTill['closed_at']))) : (!$selectedTill && !empty($selectedDay['closed_at']) ? h(date('d/m/Y H:i', strtotime((string) $selectedDay['closed_at']))) : '-') ?></td><th>Cash movement net</th><td class="text-end fw-bold"><?= gbp($cashMovementNet) ?></td></tr>
                <tr><th>Notes</th><td colspan="3"><?= h((string) ($selectedTill['closing_notes'] ?? $selectedDay['closing_notes'] ?? '')) ?></td></tr>
            </tbody>
        </table>
    </section>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel table-responsive h-100">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Cash movement</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach (['skim' => 'Skim', 'safe_drop' => 'Safe drop', 'paid_in' => 'Paid-in', 'paid_out' => 'Paid-out'] as $type => $label): ?>
                        <tr><td><?= h($label) ?></td><td class="text-end"><?= (int) ($movements[$type]['movement_count'] ?? 0) ?></td><td class="text-end fw-bold"><?= gbp((float) ($movements[$type]['amount'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="card hub-panel table-responsive h-100">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Time</th><th>Ref</th><th>Till</th><th>Payment</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <tr><td><?= h(date('H:i', strtotime((string) $sale['created_at']))) ?></td><td class="fw-semibold"><?= h((string) $sale['sale_ref']) ?></td><td><?= h((string) $sale['location_name']) ?></td><td><?= h(ucfirst(str_replace('_', ' ', (string) $sale['payment_method']))) ?></td><td><?= h(ucfirst(str_replace('_', ' ', (string) $sale['status']))) ?></td><td class="text-end fw-bold"><?= gbp((float) $sale['total']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="6" class="hub-empty-state">No transactions for this report scope.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../admin/footer.php'; ?>
