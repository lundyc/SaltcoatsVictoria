<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';
require_once __DIR__ . '/../admin/lib/pos_reconciliation.php';

pos_controls_ensure_schema($pdo);
pos_reconciliation_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}
$days = $pdo->query('SELECT * FROM pos_trading_days ORDER BY business_date DESC, id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$activeDay = pos_trading_day_active($pdo);
$selectedTradingDayId = (int) ($_GET['trading_day_id'] ?? ($activeDay['id'] ?? ($days[0]['id'] ?? 0)));
$selectedTradingDay = null;
foreach ($days as $day) {
    if ((int) $day['id'] === $selectedTradingDayId) {
        $selectedTradingDay = $day;
        break;
    }
}
$date = $selectedTradingDay ? (string) $selectedTradingDay['business_date'] : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d'));
$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            pos_reconciliation_save($pdo, [
                'location_id' => (int) ($_POST['location_id'] ?? 0),
                'business_date' => (string) ($_POST['business_date'] ?? $date),
                'payment_method' => (string) ($_POST['payment_method'] ?? 'cash'),
                'counted_amount' => (float) ($_POST['counted_amount'] ?? 0),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], (int) ($actor['account_id'] ?? 0) ?: null);
            $date = pos_reconciliation_business_date((string) ($_POST['business_date'] ?? $date));
            $notice = 'Cash-up recorded.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$summaryStmt = $selectedTradingDay
    ? $pdo->prepare('SELECT COUNT(CASE WHEN status = "complete" THEN 1 END) AS sales_count, COALESCE(SUM(CASE WHEN status = "complete" THEN total ELSE 0 END),0) AS total, COALESCE(SUM(CASE WHEN status = "refund" THEN total ELSE 0 END),0) AS refunds, COALESCE(SUM(CASE WHEN status IN ("complete","refund") AND payment_method = "cash" THEN total ELSE 0 END),0) AS cash_total, COALESCE(SUM(CASE WHEN status IN ("complete","refund") AND payment_method IN ("card","card_stripe_app") THEN total ELSE 0 END),0) AS card_total, COALESCE(SUM(CASE WHEN status = "complete" THEN payment_method = "cash" ELSE 0 END),0) AS cash_count, COALESCE(SUM(CASE WHEN status = "complete" THEN payment_method IN ("card","card_stripe_app") ELSE 0 END),0) AS card_count FROM pos_sales WHERE trading_day_id = :trading_day_id')
    : $pdo->prepare('SELECT COUNT(CASE WHEN status = "complete" THEN 1 END) AS sales_count, COALESCE(SUM(CASE WHEN status = "complete" THEN total ELSE 0 END),0) AS total, COALESCE(SUM(CASE WHEN status = "refund" THEN total ELSE 0 END),0) AS refunds, COALESCE(SUM(CASE WHEN status IN ("complete","refund") AND payment_method = "cash" THEN total ELSE 0 END),0) AS cash_total, COALESCE(SUM(CASE WHEN status IN ("complete","refund") AND payment_method IN ("card","card_stripe_app") THEN total ELSE 0 END),0) AS card_total, COALESCE(SUM(CASE WHEN status = "complete" THEN payment_method = "cash" ELSE 0 END),0) AS cash_count, COALESCE(SUM(CASE WHEN status = "complete" THEN payment_method IN ("card","card_stripe_app") ELSE 0 END),0) AS card_count FROM pos_sales WHERE DATE(created_at) = :date');
$summaryStmt->execute($selectedTradingDay ? [':trading_day_id' => (int) $selectedTradingDay['id']] : [':date' => $date]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'refunds' => 0, 'cash_total' => 0, 'card_total' => 0, 'cash_count' => 0, 'card_count' => 0];
$locations = $pdo->query('SELECT * FROM pos_locations WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$selectedCashupLocationId = (int) ($_GET['cashup_location_id'] ?? ($locations[0]['id'] ?? 0));
$selectedCashupLocationName = 'Location';
foreach ($locations as $location) {
    if ((int) $location['id'] === $selectedCashupLocationId) {
        $selectedCashupLocationName = (string) $location['name'];
        break;
    }
}
$cashupTotals = $selectedCashupLocationId > 0 ? pos_reconciliation_sales_totals($pdo, $date, $selectedCashupLocationId) : [];
if (!$cashupTotals) {
    $cashupTotals['cash'] = [
        'payment_method' => 'cash',
        'label' => 'Cash',
        'sales_count' => 0,
        'amount' => 0.0,
    ];
}
$cashupRows = pos_reconciliation_recent($pdo, $date);
$stmt = $pdo->prepare('SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
    WHERE ' . ($selectedTradingDay ? 's.trading_day_id = :trading_day_id' : 'DATE(s.created_at) = :date') . '
    ORDER BY s.created_at DESC, s.id DESC');
$stmt->execute($selectedTradingDay ? [':trading_day_id' => (int) $selectedTradingDay['id']] : [':date' => $date]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Reports',
    'subtitle' => 'Review daily takings, payment mix, cash-ups, and transaction history.',
];
require_once __DIR__ . '/../admin/header.php';
?>
<style>.variance-balanced{color:#0d7a4a}.variance-over{color:#8a5b00}.variance-short{color:#b42318}</style>
<div class="pos-reports-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Daily Reports</span></nav>
    <section class="hub-section-commandbar" aria-labelledby="posReportsActionsTitle">
        <div>
            <h2 id="posReportsActionsTitle">Trading day reporting</h2>
            <p>Use this view for POS day reports, cash-up reconciliation, and transaction history.</p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/pos_overview.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i>Overview</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/z_report.php<?= $selectedTradingDay ? '?trading_day_id=' . (int) $selectedTradingDay['id'] : '' ?>"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i>X/Z Reports</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/cash_movements.php"><i class="fa-solid fa-vault" aria-hidden="true"></i>Cash</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/audit.php<?= $selectedTradingDay ? '?trading_day_id=' . (int) $selectedTradingDay['id'] : '' ?>"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Audit</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/products.php"><i class="fa-solid fa-box-open" aria-hidden="true"></i>Products</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/operators.php"><i class="fa-solid fa-users-gear" aria-hidden="true"></i>Operators</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/locations.php"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Locations</a>
        </div>
    </section>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <form class="card hub-panel p-3 mb-3 d-flex gap-2 align-items-end flex-wrap" method="get">
        <div><label class="form-label fw-bold" for="trading_day_id">Trading day</label><select class="form-select" id="trading_day_id" name="trading_day_id"><?php foreach ($days as $day): ?><option value="<?= (int) $day['id'] ?>" <?= (int) $day['id'] === $selectedTradingDayId ? 'selected' : '' ?>><?= h(date('d/m/Y', strtotime((string) $day['business_date']))) ?> · <?= h((string) $day['status']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-dark" type="submit">View</button>
    </form>

    <?php hub_render_metric_grid([
        ['label' => 'Sales', 'value' => (int) $summary['sales_count'], 'meta' => 'Completed sales', 'icon' => 'fa-receipt', 'tone' => 'neutral'],
        ['label' => 'Gross takings', 'value' => gbp((float) $summary['total']), 'meta' => 'Completed sales', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
        ['label' => 'Refunds', 'value' => gbp((float) $summary['refunds']), 'meta' => 'Negative transactions', 'icon' => 'fa-rotate-left', 'tone' => 'warning'],
        ['label' => 'Net cash/card', 'value' => gbp((float) $summary['cash_total'] + (float) $summary['card_total']), 'meta' => 'Trading day net', 'icon' => 'fa-cash-register', 'tone' => 'info'],
    ], 'Report summary'); ?>

    <section class="card hub-panel p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Cash-up reconciliation</h2>
                <div class="text-muted small">Record counted takings against completed POS sales for each location.</div>
            </div>
            <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
                <input type="hidden" name="trading_day_id" value="<?= (int) $selectedTradingDayId ?>">
                <div>
                    <label class="form-label small fw-bold" for="cashup_location_id">Location</label>
                    <select class="form-select form-select-sm" id="cashup_location_id" name="cashup_location_id" onchange="this.form.submit()">
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= (int) $location['id'] ?>" <?= (int) $location['id'] === $selectedCashupLocationId ? 'selected' : '' ?>><?= h((string) $location['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-sm btn-outline-dark" type="submit">Refresh</button>
            </form>
        </div>
        <div class="row g-3">
            <div class="col-lg-5">
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="business_date" value="<?= h($date) ?>">
                    <div class="col-12">
                        <label class="form-label fw-bold" for="location_id">Cash-up location</label>
                        <select class="form-select" id="location_id" name="location_id" required>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int) $location['id'] ?>" <?= (int) $location['id'] === $selectedCashupLocationId ? 'selected' : '' ?>><?= h((string) $location['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="payment_method">Payment method</label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <?php foreach ($cashupTotals as $method => $row): ?>
                                <option value="<?= h((string) $method) ?>"><?= h((string) $row['label']) ?> expected <?= gbp((float) $row['amount']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" for="counted_amount">Counted amount</label>
                        <input class="form-control" id="counted_amount" name="counted_amount" type="number" min="0" step="0.01" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold" for="notes">Notes</label>
                        <input class="form-control" id="notes" name="notes" maxlength="255" placeholder="Optional handover notes">
                    </div>
                    <div class="col-12"><button class="btn btn-dark" type="submit">Record cash-up</button></div>
                </form>
            </div>
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Location</th><th>Payment</th><th class="text-end">Expected</th><th class="text-end">Sales</th></tr></thead>
                        <tbody>
                        <?php foreach ($cashupTotals as $row): ?>
                            <tr>
                                <td><?= h($selectedCashupLocationName) ?></td>
                                <td><?= h((string) $row['label']) ?></td>
                                <td class="text-end fw-bold"><?= gbp((float) $row['amount']) ?></td>
                                <td class="text-end"><?= (int) $row['sales_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <hr>
        <h3 class="h6 fw-bold">Recent cash-ups for <?= h(date('d/m/Y', strtotime($date))) ?></h3>
        <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0">
                <thead><tr><th>Time</th><th>Location</th><th>Payment</th><th class="text-end">Expected</th><th class="text-end">Counted</th><th class="text-end">Variance</th><th>Counted by</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($cashupRows as $row): ?>
                    <tr>
                        <td><?= h(date('H:i', strtotime((string) $row['created_at']))) ?></td>
                        <td><?= h((string) $row['location_name']) ?></td>
                        <td><?= h(pos_reconciliation_payment_label((string) $row['payment_method'])) ?></td>
                        <td class="text-end"><?= gbp((float) $row['expected_amount']) ?></td>
                        <td class="text-end"><?= gbp((float) $row['counted_amount']) ?></td>
                        <td class="text-end fw-bold variance-<?= h((string) $row['variance_state']) ?>"><?= gbp((float) $row['variance_amount']) ?></td>
                        <td><?= h((string) ($row['counted_by_name'] ?: $row['counted_by_email'] ?: '-')) ?></td>
                        <td><?= h((string) ($row['notes'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$cashupRows): ?><tr><td colspan="8" class="text-center text-muted py-3">No cash-ups recorded for this trading day.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card hub-panel table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead><tr><th>Time</th><th>Ref</th><th>Location</th><th>Operator</th><th>Member</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($sales as $sale): ?>
                <tr>
                    <td><?= h(date('H:i', strtotime((string) $sale['created_at']))) ?></td>
                    <td class="fw-bold"><?= h((string) $sale['sale_ref']) ?></td>
                    <td><?= h((string) $sale['location_name']) ?></td>
                    <td><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></td>
                    <td><?= h((string) ($sale['holder_name'] ?: '-')) ?></td>
                    <td><?= h(ucfirst((string) $sale['payment_method'])) ?></td>
                    <td class="text-end fw-bold"><?= gbp((float) $sale['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sales): ?><tr><td colspan="7" class="text-center text-muted py-4">No POS sales for this trading day.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/../admin/footer.php'; ?>
