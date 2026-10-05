<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/pos.php';
require_once __DIR__ . '/lib/pos_controls.php';

pos_controls_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'open_pos_day') {
                $dayId = pos_trading_day_open($pdo, $actor, (string) ($_POST['business_date'] ?? date('Y-m-d')));
                pos_audit_event($pdo, 'pos_trading_day_opened', 'Opened POS trading day #' . $dayId, [
                    'trading_day_id' => $dayId,
                    'operator_id' => pos_controls_actor_operator_id($actor),
                    'hub_account_id' => pos_controls_actor_account_id($actor),
                ]);
                $notice = 'POS day opened.';
            } elseif ($action === 'close_pos_day') {
                pos_trading_day_close($pdo, (int) ($_POST['trading_day_id'] ?? 0), $actor, (string) ($_POST['closing_notes'] ?? ''));
                pos_audit_event($pdo, 'pos_trading_day_closed', 'Closed POS trading day #' . (int) ($_POST['trading_day_id'] ?? 0), [
                    'trading_day_id' => (int) ($_POST['trading_day_id'] ?? 0),
                    'operator_id' => pos_controls_actor_operator_id($actor),
                    'hub_account_id' => pos_controls_actor_account_id($actor),
                ]);
                $notice = 'POS day closed. Tills are now locked.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Overview',
    'subtitle' => 'Review till activity, transactions, locations, operators, and product setup.',
];

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$activeTradingDay = pos_trading_day_active($pdo);
$businessDate = $activeTradingDay ? (string) $activeTradingDay['business_date'] : $today;

$salesSummary = static function (PDO $pdo, string $from, ?string $to = null): array {
    $to = $to ?: $from;
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(total), 0) AS total,
            COALESCE(SUM(discount_total + points_discount), 0) AS discounts,
            COALESCE(SUM(payment_method = 'cash'), 0) AS cash_count,
            COALESCE(SUM(payment_method IN ('card', 'card_stripe_app')), 0) AS card_count
        FROM pos_sales
        WHERE DATE(created_at) BETWEEN :from_date AND :to_date
          AND status = 'complete'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'discounts' => 0, 'cash_count' => 0, 'card_count' => 0];
};

$tradingDaySummary = static function (PDO $pdo, ?array $activeTradingDay, string $businessDate): array {
    if ($activeTradingDay) {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS sales_count,
                COALESCE(SUM(total), 0) AS total,
                COALESCE(SUM(discount_total + points_discount), 0) AS discounts,
                COALESCE(SUM(payment_method = 'cash'), 0) AS cash_count,
                COALESCE(SUM(payment_method IN ('card', 'card_stripe_app')), 0) AS card_count
            FROM pos_sales
            WHERE trading_day_id = :trading_day_id
              AND status = 'complete'
        ");
        $stmt->execute([':trading_day_id' => (int) $activeTradingDay['id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'discounts' => 0, 'cash_count' => 0, 'card_count' => 0];
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(total), 0) AS total,
            COALESCE(SUM(discount_total + points_discount), 0) AS discounts,
            COALESCE(SUM(payment_method = 'cash'), 0) AS cash_count,
            COALESCE(SUM(payment_method IN ('card', 'card_stripe_app')), 0) AS card_count
        FROM pos_sales
        WHERE DATE(created_at) = :business_date
          AND status = 'complete'
    ");
    $stmt->execute([':business_date' => $businessDate]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'discounts' => 0, 'cash_count' => 0, 'card_count' => 0];
};

$todaySummary = $tradingDaySummary($pdo, $activeTradingDay, $businessDate);
$monthSummary = $salesSummary($pdo, $monthStart, $today);

if ($activeTradingDay) {
    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE trading_day_id = :trading_day_id AND status IN ('pending_card', 'pending_cash')");
    $pendingStmt->execute([':trading_day_id' => (int) $activeTradingDay['id']]);
    $pendingCount = (int) $pendingStmt->fetchColumn();
} else {
    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE DATE(created_at) = :business_date AND status IN ('pending_card', 'pending_cash')");
    $pendingStmt->execute([':business_date' => $businessDate]);
    $pendingCount = (int) $pendingStmt->fetchColumn();
}
$productCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_products WHERE is_active = 1')->fetchColumn();
$locationCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_locations WHERE is_active = 1')->fetchColumn();
$operatorCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_operators WHERE is_active = 1')->fetchColumn();

$recentSql = "
    SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
";
$recentParams = [];
if ($activeTradingDay) {
    $recentSql .= ' WHERE s.trading_day_id = :trading_day_id';
    $recentParams[':trading_day_id'] = (int) $activeTradingDay['id'];
}
$recentSql .= "
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT 100
";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recentSales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$locationSql = "
    SELECT l.id, l.name, l.description,
           COUNT(s.id) AS sales_count,
           COALESCE(SUM(CASE WHEN s.status = 'complete' THEN s.total ELSE 0 END), 0) AS total
    FROM pos_locations l
    LEFT JOIN pos_sales s ON s.location_id = l.id AND " . ($activeTradingDay ? 's.trading_day_id = :trading_day_id' : 'DATE(s.created_at) = :business_date') . "
    WHERE l.is_active = 1
    GROUP BY l.id, l.name, l.description
    ORDER BY l.sort_order, l.name
";
$locationStmt = $pdo->prepare($locationSql);
$locationStmt->execute($activeTradingDay ? [':trading_day_id' => (int) $activeTradingDay['id']] : [':business_date' => $businessDate]);
$locations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);
$tillSessions = $activeTradingDay ? pos_till_sessions_for_day($pdo, (int) $activeTradingDay['id']) : [];
$activeTillSessionsByLocation = [];
foreach ($tillSessions as $session) {
    if ((string) $session['status'] === 'open') {
        $activeTillSessionsByLocation[(int) $session['location_id']] = $session;
    }
}
foreach ($locations as &$location) {
    $location['till_session'] = $activeTillSessionsByLocation[(int) $location['id']] ?? null;
}
unset($location);
$openTillLocations = array_values(array_filter($locations, static fn(array $location): bool => !empty($location['till_session'])));
$openTillCount = count($openTillLocations);

$topProductsSql = "
    SELECT i.product_name, i.category_name, SUM(i.qty) AS qty, COALESCE(SUM(i.line_total), 0) AS total
    FROM pos_sale_items i
    JOIN pos_sales s ON s.id = i.sale_id
    WHERE s.status = 'complete'
      AND " . ($activeTradingDay ? 's.trading_day_id = :trading_day_id' : 'DATE(s.created_at) = :business_date') . "
    GROUP BY i.product_name, i.category_name
    ORDER BY qty DESC, total DESC
    LIMIT 3
";
$topProductsStmt = $pdo->prepare($topProductsSql);
$topProductsStmt->execute($activeTradingDay ? [':trading_day_id' => (int) $activeTradingDay['id']] : [':business_date' => $businessDate]);
$topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

$hourlyTrendSql = "
    SELECT HOUR(created_at) AS sale_hour,
           COUNT(*) AS sales_count,
           COALESCE(SUM(total), 0) AS total
    FROM pos_sales
    WHERE " . ($activeTradingDay ? 'trading_day_id = :trading_day_id' : 'DATE(created_at) = :business_date') . "
      AND status = 'complete'
    GROUP BY HOUR(created_at)
    ORDER BY sale_hour
";
$hourlyTrendStmt = $pdo->prepare($hourlyTrendSql);
$hourlyTrendStmt->execute($activeTradingDay ? [':trading_day_id' => (int) $activeTradingDay['id']] : [':business_date' => $businessDate]);
$hourlyTrend = [];
foreach ($hourlyTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hour = (int) $row['sale_hour'];
    $hourlyTrend[] = [
        'hour' => $hour,
        'label' => sprintf('%02d:00', $hour),
        'sales_count' => (int) ($row['sales_count'] ?? 0),
        'total' => (float) ($row['total'] ?? 0),
    ];
}
$maxHourlyTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['total'], $hourlyTrend));
$maxLocationTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['total'], $locations ?: [['total' => 0]]));
$maxProductTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['total'], $topProducts ?: [['total' => 0]]));
$cashCount = (int) $todaySummary['cash_count'];
$cardCount = (int) $todaySummary['card_count'];
$paymentCountTotal = max(1, $cashCount + $cardCount);

$paymentLabel = static function (string $method): string {
    return match ($method) {
        'cash' => 'Cash',
        'card', 'card_stripe_app' => 'Card',
        default => ucfirst(str_replace('_', ' ', $method)),
    };
};

$paymentIcon = static function (string $method): string {
    return match ($method) {
        'cash' => 'fa-money-bill-wave',
        'card', 'card_stripe_app' => 'fa-credit-card',
        default => 'fa-circle-question',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete' => 'text-bg-success',
        'pending_card', 'pending_cash' => 'text-bg-warning',
        'cancelled_card', 'cancelled_cash' => 'text-bg-secondary',
        'refunded', 'refund' => 'text-bg-danger',
        default => 'text-bg-light',
    };
};

$statusIcon = static function (string $status): string {
    return match ($status) {
        'complete' => 'fa-circle-check',
        'pending_card', 'pending_cash' => 'fa-clock',
        'cancelled_card', 'cancelled_cash' => 'fa-ban',
        'refunded', 'refund' => 'fa-rotate-left',
        default => 'fa-circle-question',
    };
};

require_once __DIR__ . '/header.php';
?>

<div class="pos-overview-page">
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="pos-kpi-strip" aria-label="POS summary">
        <article class="pos-kpi-card pos-kpi-card--primary"><span><?= $activeTradingDay ? 'Open POS day' : 'POS day closed' ?></span><strong><?= gbp((float) $todaySummary['total']) ?></strong><small><?= (int) $todaySummary['sales_count'] ?> completed sales<?= $activeTradingDay ? ' · ' . h(date('d/m/Y', strtotime($businessDate))) : '' ?></small></article>
        <article class="pos-kpi-card"><span>This month</span><strong><?= gbp((float) $monthSummary['total']) ?></strong><small><?= (int) $monthSummary['sales_count'] ?> completed sales</small></article>
        <article class="pos-kpi-card"><span>Products</span><strong><?= $productCount ?></strong><small>Active products</small></article>
        <article class="pos-kpi-card <?= $pendingCount > 0 ? 'pos-kpi-card--warning' : '' ?>"><span>Pending</span><strong><?= $pendingCount ?></strong><small>Sales awaiting completion</small></article>
    </section>

    <div class="pos-action-grid pos-action-grid--full" aria-label="POS management links">
        <a class="pos-action-tile" href="/pos/reports.php"><i class="fa-solid fa-table-list" aria-hidden="true"></i><span><strong>Reports</strong><small>Daily takings</small></span></a>
        <a class="pos-action-tile" href="/pos/tills.php"><i class="fa-solid fa-cash-register" aria-hidden="true"></i><span><strong>Tills</strong><small><?= $openTillCount ?> open</small></span></a>
        <a class="pos-action-tile" href="/pos/z_report.php"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i><span><strong>X/Z</strong><small>Close reports</small></span></a>
        <a class="pos-action-tile" href="/pos/cash_movements.php"><i class="fa-solid fa-vault" aria-hidden="true"></i><span><strong>Cash</strong><small>Movements</small></span></a>
        <a class="pos-action-tile" href="/pos/audit.php"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span><strong>Audit</strong><small>Event log</small></span></a>
        <a class="pos-action-tile" href="/pos/products.php"><i class="fa-solid fa-box-open" aria-hidden="true"></i><span><strong>Products</strong><small><?= $productCount ?> active</small></span></a>
        <a class="pos-action-tile" href="/pos/operators.php"><i class="fa-solid fa-users-gear" aria-hidden="true"></i><span><strong>Operators</strong><small><?= $operatorCount ?> active</small></span></a>
        <a class="pos-action-tile" href="/pos/locations.php"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span><strong>Locations</strong><small><?= $locationCount ?> active</small></span></a>
    </div>

    <section class="pos-dashboard-grid" aria-label="POS operating dashboard">
        <div class="pos-card pos-actions-panel" aria-labelledby="posActionsTitle">
            <div class="pos-card__head">
                <div><p class="page-kicker mb-1">Operations</p><h2 id="posActionsTitle">Open and manage tills</h2></div>
            </div>
            <?php if ($activeTradingDay): ?>
                <div class="pos-day-status pos-day-status--open">
                    <strong>POS day open</strong>
                    <span><?= $openTillCount ?> of <?= $locationCount ?> tills open · Opened <?= h(date('d/m/Y H:i', strtotime((string) $activeTradingDay['opened_at']))) ?></span>
                </div>
                <div class="pos-open-till-control">
                    <label for="overview_location_id">Launch till</label>
                    <select class="form-select pos-open-till-select" id="overview_location_id" onchange="if (this.value) { window.open('/pos/?location_id=' + encodeURIComponent(this.value), '_blank'); this.value = ''; }">
                        <option value=""><?= $openTillLocations ? 'Choose open till...' : 'No tills open' ?></option>
                        <?php foreach ($openTillLocations as $location): ?>
                            <option value="<?= (int) $location['id'] ?>"><?= h((string) $location['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a class="btn btn-sm btn-outline-secondary w-100 mb-3" href="/pos/tills.php"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Manage till sessions</a>
                <form method="post" class="pos-day-form" data-confirm="Close the POS day and lock tills?" data-confirm-action="Close day" data-confirm-class="btn-primary">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close_pos_day">
                    <input type="hidden" name="trading_day_id" value="<?= (int) $activeTradingDay['id'] ?>">
                    <label class="visually-hidden" for="closing_notes">Closing notes</label>
                    <input class="form-control form-control-sm" id="closing_notes" name="closing_notes" maxlength="255" placeholder="Closing notes">
                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= $openTillCount > 0 ? 'disabled' : '' ?>>Close POS day</button>
                    <?php if ($openTillCount > 0): ?><small class="text-muted">Close all tills before closing the POS day.</small><?php endif; ?>
                </form>
            <?php else: ?>
                <div class="pos-day-status pos-day-status--closed">
                    <strong>No POS day open</strong>
                    <span>Tills are locked until a manager opens the day.</span>
                </div>
                <form method="post" class="pos-day-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="open_pos_day">
                    <label class="visually-hidden" for="business_date">Business date</label>
                    <input class="form-control form-control-sm" id="business_date" name="business_date" type="date" value="<?= h($today) ?>">
                    <button class="btn btn-sm btn-brand" type="submit">Open POS day</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="pos-card pos-card--wide">
            <div class="pos-card__head">
                <div><p class="page-kicker mb-1">Matchday flow</p><h2>Takings by hour</h2></div>
                <strong><?= gbp((float) $todaySummary['total']) ?></strong>
            </div>
            <div class="pos-trend-chart" aria-label="Takings by hour">
                <?php foreach ($hourlyTrend as $hour): ?>
                    <?php $height = max(4, (int) round(((float) $hour['total'] / $maxHourlyTotal) * 100)); ?>
                    <div class="pos-trend-chart__bar" title="<?= h((string) $hour['label']) ?> <?= h(gbp((float) $hour['total'])) ?>">
                        <span><i style="height: <?= $height ?>%"></i></span>
                        <b><?= gbp((float) $hour['total']) ?></b>
                        <small><?= h(substr((string) $hour['label'], 0, 2)) ?></small>
                    </div>
                <?php endforeach; ?>
                <?php if (!$hourlyTrend): ?><div class="hub-empty-state">No sales have been recorded for this POS day yet.</div><?php endif; ?>
            </div>
        </div>

        <div class="pos-card">
            <div class="pos-card__head">
                <div><p class="page-kicker mb-1">Payments</p><h2>Payment mix</h2></div>
            </div>
            <div class="pos-payment-bars">
                <article><span>Cash</span><strong><?= $cashCount ?></strong><i style="width: <?= (int) round(($cashCount / $paymentCountTotal) * 100) ?>%"></i></article>
                <article><span>Card</span><strong><?= $cardCount ?></strong><i style="width: <?= (int) round(($cardCount / $paymentCountTotal) * 100) ?>%"></i></article>
                <article><span>Discounts</span><strong><?= gbp((float) $todaySummary['discounts']) ?></strong><i style="width: <?= min(100, (int) round(((float) $todaySummary['discounts'] / max(1.0, (float) $todaySummary['total'])) * 100)) ?>%"></i></article>
            </div>
        </div>
    </section>

    <section class="pos-insight-grid" aria-label="POS insights">
        <div class="pos-card">
            <div class="pos-card__head">
                <div><p class="page-kicker mb-1">Locations</p><h2>By till</h2></div>
            </div>
            <div class="pos-bar-list">
                <?php foreach ($locations as $location): ?>
                    <?php $width = max(4, (int) round(((float) $location['total'] / $maxLocationTotal) * 100)); ?>
                    <?php if (!empty($location['till_session'])): ?>
                        <a class="pos-bar-row" href="/pos/?location_id=<?= (int) $location['id'] ?>">
                            <span><strong><?= h((string) $location['name']) ?></strong><small><?= (int) $location['sales_count'] ?> sales</small></span>
                            <b><?= gbp((float) $location['total']) ?></b>
                            <i style="width: <?= $width ?>%"></i>
                        </a>
                    <?php else: ?>
                        <article class="pos-bar-row pos-bar-row--disabled">
                            <span><strong><?= h((string) $location['name']) ?></strong><small>Closed</small></span>
                            <b><?= gbp((float) $location['total']) ?></b>
                            <i style="width: <?= $width ?>%"></i>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$locations): ?><div class="hub-empty-state">No active POS locations are available.</div><?php endif; ?>
            </div>
        </div>

        <div class="pos-card">
            <div class="pos-card__head">
                <div><p class="page-kicker mb-1">Products</p><h2>Top sellers</h2></div>
                <a class="btn btn-sm btn-outline-secondary" href="/pos/top_sellers.php?<?= $activeTradingDay ? 'trading_day_id=' . (int) $activeTradingDay['id'] : 'date=' . h($businessDate) ?>">View all</a>
            </div>
            <div class="pos-bar-list">
                <?php foreach ($topProducts as $product): ?>
                    <?php $width = max(4, (int) round(((float) $product['total'] / $maxProductTotal) * 100)); ?>
                    <article class="pos-bar-row">
                        <span><strong><?= h((string) $product['product_name']) ?></strong><small><?= h((string) $product['category_name']) ?> · <?= (int) $product['qty'] ?> sold</small></span>
                        <b><?= gbp((float) $product['total']) ?></b>
                        <i style="width: <?= $width ?>%"></i>
                    </article>
                <?php endforeach; ?>
                <?php if (!$topProducts): ?><div class="hub-empty-state">No product sales for this POS day.</div><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="pos-card pos-transactions-card">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <p class="page-kicker mb-1">Transactions</p>
                        <h2 class="h5 mb-0">Latest POS sales</h2>
                    </div>
                    <span class="badge text-bg-light">Latest <?= count($recentSales) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle hub-data-table mb-0">
                            <thead><tr><th>Sold</th><th>Sale</th><th>Operator</th><th>Member</th><th class="text-center">Payment</th><th class="text-center">Status</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                                <tr class="pos-sale-row" tabindex="0" role="link" onclick="window.location.href='/pos/sale.php?id=<?= (int) $sale['id'] ?>'" onkeydown="if (event.key === 'Enter') { window.location.href='/pos/sale.php?id=<?= (int) $sale['id'] ?>'; }">
                                    <td data-label="Sold"><span class="pos-table-date"><strong><?= h(date('g:i a', strtotime((string) $sale['created_at']))) ?></strong><small><?= h(date('d/m/Y', strtotime((string) $sale['created_at']))) ?></small></span></td>
                                    <td data-label="Sale" class="fw-semibold"><span class="pos-sale-ref"><?= h((string) $sale['sale_ref']) ?><small><?= h((string) $sale['location_name']) ?></small></span></td>
                                    <td data-label="Operator"><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></td>
                                    <td data-label="Member"><?= h((string) ($sale['holder_name'] ?: '-')) ?></td>
                                    <td data-label="Payment" class="text-center"><i class="fa-solid <?= h($paymentIcon((string) $sale['payment_method'])) ?> pos-table-icon" title="<?= h($paymentLabel((string) $sale['payment_method'])) ?>" aria-label="<?= h($paymentLabel((string) $sale['payment_method'])) ?>"></i></td>
                                    <td data-label="Status" class="text-center"><span class="badge hub-status <?= h($statusBadge((string) $sale['status'])) ?>" title="<?= h(ucfirst(str_replace('_', ' ', (string) $sale['status']))) ?>"><i class="fa-solid <?= h($statusIcon((string) $sale['status'])) ?>" aria-hidden="true"></i><span class="visually-hidden"><?= h(ucfirst(str_replace('_', ' ', (string) $sale['status']))) ?></span></span></td>
                                    <td data-label="Total" class="text-end fw-bold"><?= gbp((float) $sale['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentSales): ?><tr><td colspan="8" class="hub-empty-state">No POS transactions have been recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
    </section>
</div>

<?php require __DIR__ . '/footer.php'; ?>
