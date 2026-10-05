<?php

declare(strict_types=1);

// Club Shop — admin overview. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');

shop_ensure_schema($pdo);

$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$validDate = static fn(string $d): bool => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) !== false;
$dateFrom = $validDate($dateFrom) ? $dateFrom : '';
$dateTo = $validDate($dateTo) ? $dateTo : '';
$dateWhere = '';
$dateParams = [];
if ($dateFrom !== '') {
    $dateWhere .= ' AND o.created_at >= :date_from';
    $dateParams[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $dateWhere .= ' AND o.created_at <= :date_to';
    $dateParams[':date_to'] = $dateTo . ' 23:59:59';
}

if (($_GET['export'] ?? '') === 'csv') {
    if (!hub_auth_is_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
    $stmt = $pdo->prepare("SELECT i.product_name, i.options_label, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
        FROM shop_order_items i JOIN shop_orders o ON o.id = i.order_id
        WHERE o.status IN ('paid','collected','refunded'){$dateWhere}
        GROUP BY i.product_name, i.options_label
        ORDER BY i.product_name, qty DESC");
    $stmt->execute($dateParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="club-shop-sales-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Product', 'Options', 'Qty sold', 'Revenue'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['product_name'], $r['options_label'], (int) $r['qty'], number_format((float) $r['revenue'], 2, '.', '')], ',', '"', '');
    }
    fclose($out);
    exit;
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Club Shop',
    'subtitle' => 'Orders, revenue and pre-order progress for the online shop.',
    'actions' => [
        ['label' => 'View storefront', 'href' => '/shop/', 'class' => 'btn btn-outline-light btn-sm', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']],
        ['label' => 'Orders', 'href' => '/shop_orders.php', 'class' => 'btn btn-outline-light btn-sm'],
        ['label' => 'Add product', 'href' => '/shop_product.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';


$settings = shop_get_settings($pdo);
$stripeReady = stripe_is_configured();

$totalsStmt = $pdo->prepare("SELECT
        COUNT(*) AS orders_total,
        COALESCE(SUM(status IN ('paid','collected','refunded')), 0) AS orders_paid,
        COALESCE(SUM(CASE WHEN status IN ('paid','collected','refunded') THEN total ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(amount_refunded), 0) AS refunded,
        COALESCE(SUM(status = 'pending_payment'), 0) AS pending,
        COALESCE(SUM(status = 'paid'), 0) AS awaiting_collection,
        COALESCE(SUM(status = 'collected'), 0) AS collected
    FROM shop_orders o WHERE 1=1{$dateWhere}");
$totalsStmt->execute($dateParams);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Units sold (paid) per product + option.
$breakdownStmt = $pdo->prepare("SELECT i.product_name, i.options_label, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
    FROM shop_order_items i JOIN shop_orders o ON o.id = i.order_id
    WHERE o.status IN ('paid','collected','refunded'){$dateWhere}
    GROUP BY i.product_name, i.options_label
    ORDER BY i.product_name, qty DESC");
$breakdownStmt->execute($dateParams);
$breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

$recentFilters = ['limit' => 12];
if ($dateFrom !== '') { $recentFilters['date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $recentFilters['date_to'] = $dateTo . ' 23:59:59'; }
$recent = shop_order_list($pdo, $recentFilters);

$closeAt = null;
try {
    $closeAt = new DateTimeImmutable((string) ($settings['preorder_close_at'] ?? SHOP_PREORDER_CLOSE_DEFAULT));
} catch (Throwable) {
}
$now = new DateTimeImmutable('now');
$windowOpen = $closeAt !== null && $now <= $closeAt;
$daysLeft = $closeAt !== null ? (int) $now->diff($closeAt)->format('%r%a') : null;

function shop_status_badge(string $status): string
{
    $map = [
        'pending_payment' => 'secondary',
        'paid' => 'success',
        'collected' => 'primary',
        'cancelled' => 'dark',
        'refunded' => 'warning',
    ];
    $cls = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge text-bg-' . $cls . '">' . h($label) . '</span>';
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><span aria-current="page">Shop overview</span></nav>

    <?php if (!$stripeReady): ?>
        <div class="alert alert-warning">Stripe is not configured yet, so customers can't pay. Add a secret key under
            <a href="/admin/settings.php?tab=payments">Settings &rsaquo; Payments</a>.</div>
    <?php endif; ?>
    <?php if (($settings['shop_enabled'] ?? '1') !== '1'): ?>
        <div class="alert alert-secondary">The storefront is currently switched <strong>off</strong> in
            <a href="/admin/shop_settings.php">Shop settings</a>.</div>
    <?php endif; ?>

    <form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div><label class="form-label small mb-1">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= h($dateFrom) ?>"></div>
        <div><label class="form-label small mb-1">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="<?= h($dateTo) ?>"></div>
        <button class="btn btn-sm btn-dark" type="submit">Filter</button>
        <?php if ($dateFrom !== '' || $dateTo !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="/admin/shop_overview.php">Clear</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="/admin/shop_overview.php?<?= h(http_build_query(array_filter(['from' => $dateFrom, 'to' => $dateTo]) + ['export' => 'csv'])) ?>"><i class="fa-solid fa-download me-1"></i>Export sales CSV</a>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="card hub-panel p-3 h-100"><div class="text-muted small text-uppercase fw-bold">Revenue (paid)<?= ($dateFrom !== '' || $dateTo !== '') ? ' — filtered' : '' ?></div><div class="h3 mb-0"><?= gbp((float) ($totals['revenue'] ?? 0)) ?></div><?php if ((float) ($totals['refunded'] ?? 0) > 0): ?><div class="small text-muted">less <?= gbp((float) $totals['refunded']) ?> refunded</div><?php endif; ?></div></div>
        <div class="col-6 col-lg-3"><div class="card hub-panel p-3 h-100"><div class="text-muted small text-uppercase fw-bold">Paid orders</div><div class="h3 mb-0"><?= (int) ($totals['orders_paid'] ?? 0) ?></div><div class="small text-muted"><?= (int) ($totals['orders_total'] ?? 0) ?> total · <?= (int) ($totals['pending'] ?? 0) ?> pending</div></div></div>
        <div class="col-6 col-lg-3"><div class="card hub-panel p-3 h-100"><div class="text-muted small text-uppercase fw-bold">Awaiting collection</div><div class="h3 mb-0"><?= (int) ($totals['awaiting_collection'] ?? 0) ?></div><div class="small text-muted"><?= (int) ($totals['collected'] ?? 0) ?> collected</div></div></div>
        <div class="col-6 col-lg-3"><div class="card hub-panel p-3 h-100"><div class="text-muted small text-uppercase fw-bold">Pre-order window</div>
            <?php if ($closeAt === null): ?>
                <div class="h5 mb-0">Not set</div>
            <?php elseif ($windowOpen): ?>
                <div class="h3 mb-0"><?= max(0, (int) $daysLeft) ?> <span class="fs-6 text-muted">days left</span></div>
                <div class="small text-muted">closes <?= h($closeAt->format('d/m/Y')) ?></div>
            <?php else: ?>
                <div class="h5 mb-0 text-danger">Closed</div>
                <div class="small text-muted"><?= h($closeAt->format('d/m/Y')) ?> — place the VSN order</div>
            <?php endif; ?>
        </div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card hub-panel">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong>Recent orders</strong>
                    <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_orders.php">All orders</a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 hub-data-table">
                        <thead><tr><th>Ref</th><th>Customer</th><th>Placed</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$recent): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">No orders yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recent as $o): ?>
                            <tr>
                                <td><a href="/admin/shop_order.php?id=<?= (int) $o['id'] ?>" class="fw-bold text-decoration-none"><?= h((string) $o['order_ref']) ?></a></td>
                                <td><?= h((string) $o['customer_name']) ?><div class="small text-muted"><?= h((string) $o['customer_email']) ?></div></td>
                                <td class="small text-muted"><?= h((new DateTimeImmutable((string) $o['created_at']))->format('d/m/Y H:i')) ?></td>
                                <td class="text-end fw-bold"><?= gbp((float) $o['total']) ?></td>
                                <td><?= shop_status_badge((string) $o['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card hub-panel">
                <div class="card-header bg-transparent"><strong>Units sold by size</strong> <span class="text-muted small">(paid orders)</span></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 hub-data-table">
                        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                        <?php if (!$breakdown): ?>
                            <tr><td colspan="3" class="text-muted text-center py-4">Nothing sold yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($breakdown as $b): ?>
                            <tr>
                                <td><?= h((string) $b['product_name']) ?><?php if (($b['options_label'] ?? '') !== ''): ?><div class="small text-muted"><?= h((string) $b['options_label']) ?></div><?php endif; ?></td>
                                <td class="text-end fw-bold"><?= (int) $b['qty'] ?></td>
                                <td class="text-end"><?= gbp((float) $b['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="d-grid gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="/admin/shop_products.php"><i class="fa-solid fa-shirt me-1"></i>Manage products</a>
                <a class="btn btn-outline-secondary" href="/admin/shop_modifiers.php"><i class="fa-solid fa-sliders me-1"></i>Manage modifiers &amp; sizes</a>
                <a class="btn btn-outline-secondary" href="/admin/shop_settings.php"><i class="fa-solid fa-gear me-1"></i>Shop settings &amp; pre-order window</a>
                <a class="btn btn-outline-secondary" href="/admin/shop_discount_codes.php"><i class="fa-solid fa-tag me-1"></i>Discount codes</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
