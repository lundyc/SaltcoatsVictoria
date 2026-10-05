<?php

declare(strict_types=1);

// Club Shop — orders list, filtering and CSV export. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
require_once __DIR__ . '/lib/audit.php';
hub_auth_require_capability('shop');

shop_ensure_schema($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'mark_all_vsn_ordered') {
    if (!hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        header('Location: shop_orders.php?vsn_error=1');
        exit;
    }
    $marked = shop_mark_all_vsn_ordered($pdo);
    auditLog($pdo, 'shop_order_vsn_ordered_bulk', $marked . ' shop order(s) flagged as sent to VSN');
    header('Location: shop_orders.php?vsn_marked=' . $marked);
    exit;
}

$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$preorderOnly = !empty($_GET['preorder']);
$filters = array_filter([
    'status' => $status,
    'search' => $search,
    'preorder_only' => $preorderOnly,
]);

// CSV export bypasses the HTML shell entirely.
if (($_GET['export'] ?? '') === 'csv') {
    if (!hub_auth_is_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
    $rows = shop_order_list($pdo, $filters);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="club-shop-orders-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Ref', 'Placed', 'Status', 'Customer', 'Email', 'Phone', 'Items', 'Subtotal', 'Discount', 'Total', 'Refunded', 'Pre-order', 'Paid at', 'Collected at', 'Item detail'], ',', '"', '');
    foreach ($rows as $o) {
        $detail = [];
        foreach (shop_order_items($pdo, (int) $o['id']) as $it) {
            $detail[] = $it['quantity'] . '× ' . $it['product_name'] . ($it['options_label'] ? ' (' . $it['options_label'] . ')' : '');
        }
        fputcsv($out, [
            $o['order_ref'],
            $o['created_at'],
            $o['status'],
            $o['customer_name'],
            $o['customer_email'],
            $o['customer_phone'],
            $o['item_count'],
            number_format((float) $o['subtotal'], 2, '.', ''),
            number_format((float) $o['discount_total'], 2, '.', ''),
            number_format((float) $o['total'], 2, '.', ''),
            number_format((float) $o['amount_refunded'], 2, '.', ''),
            (int) $o['is_preorder'] === 1 ? 'yes' : 'no',
            (string) ($o['paid_at'] ?? ''),
            (string) ($o['collected_at'] ?? ''),
            implode(' | ', $detail),
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Orders',
    'subtitle' => 'Online and manually recorded shop orders.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';


$orders = shop_order_list($pdo, $filters);
$statuses = ['' => 'All statuses', 'pending_payment' => 'Pending payment', 'paid' => 'Paid', 'collected' => 'Collected', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'];

$exportQuery = http_build_query(array_merge($_GET, ['export' => 'csv']));
$pendingVsnOrders = shop_orders_pending_vsn($pdo);

function shop_status_badge(string $status): string
{
    $map = ['pending_payment' => 'secondary', 'paid' => 'success', 'collected' => 'primary', 'cancelled' => 'dark', 'refunded' => 'warning'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . h(ucwords(str_replace('_', ' ', $status))) . '</span>';
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Orders</span></nav>

    <?php if (isset($_GET['vsn_marked'])): ?>
        <div class="alert alert-success"><?= (int) $_GET['vsn_marked'] ?> order(s) flagged as sent to VSN.</div>
    <?php elseif (isset($_GET['vsn_error'])): ?>
        <div class="alert alert-danger">Your session could not be verified. Reload the page and try again.</div>
    <?php endif; ?>

    <?php if ($pendingVsnOrders !== []): ?>
        <div class="card hub-panel p-3 mb-3 d-flex flex-row flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <strong><?= count($pendingVsnOrders) ?> pre-order<?= count($pendingVsnOrders) === 1 ? '' : 's' ?> not yet sent to VSN</strong>
                <div class="small text-muted">Download the combined order form for VSN — product, size and customer details for every order below.</div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-brand" href="/admin/shop_vsn_order_form_pdf.php?download=1"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download VSN order form (PDF)</a>
                <form method="post" data-confirm="Only do this after you've actually sent the order form to VSN." data-confirm-title="Mark these orders as sent to VSN?" data-confirm-action="Mark as sent">
                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_vsn_ordered">
                    <button type="submit" class="btn btn-outline-secondary">Mark all as sent to VSN</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex gap-2 mb-3">
        <a class="btn btn-dark" href="/admin/shop_order_new.php">Add manual order</a>
        <a class="btn btn-outline-secondary" href="/admin/shop_discount_codes.php">Discount codes</a>
    </div>
    <form class="card hub-panel p-3 mb-3" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Status</label>
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Ref, name or email"></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check">
                <input class="form-check-input" type="checkbox" id="f_preorder" name="preorder" value="1" <?= $preorderOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label" for="f_preorder">Pre-orders only</label></div></div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-dark" type="submit">Filter</button>
            </div>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_orders.php?<?= h($exportQuery) ?>"><i class="fa-solid fa-download me-1"></i>Export CSV</a>
            <span class="text-muted small ms-2"><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?></span>
        </div>
    </form>

    <?php $showScheduleCol = shop_scheduling_enabled($pdo); ?>
    <section class="card hub-panel">
        <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
            <thead><tr><th>Ref</th><th>Placed</th><th>Customer</th><?php if ($showScheduleCol): ?><th>Requested date</th><?php endif; ?><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$orders): ?><tr><td colspan="<?= $showScheduleCol ? 8 : 7 ?>" class="text-muted text-center py-4">No orders match.</td></tr><?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td data-label="Ref" class="fw-bold"><a class="text-decoration-none" href="/admin/shop_order.php?id=<?= (int) $o['id'] ?>"><?= h((string) $o['order_ref']) ?></a>
                        <?php if ((int) $o['is_preorder'] === 1): ?><span class="badge text-bg-warning ms-1">Pre-order</span><?php endif; ?></td>
                    <td data-label="Placed" class="small text-muted"><?= h((new DateTimeImmutable((string) $o['created_at']))->format('d/m/Y H:i')) ?></td>
                    <td data-label="Customer"><?= h((string) $o['customer_name']) ?><div class="small text-muted"><?= h((string) $o['customer_email']) ?></div></td>
                    <?php if ($showScheduleCol): ?><td data-label="Requested date" class="small"><?= !empty($o['requested_date']) ? h((new DateTimeImmutable((string) $o['requested_date']))->format('d/m/Y')) : '—' ?></td><?php endif; ?>
                    <td data-label="Items" class="text-end"><?= (int) $o['item_count'] ?></td>
                    <td data-label="Total" class="text-end fw-bold"><?= gbp((float) $o['total']) ?><?php if ((float) $o['amount_refunded'] > 0): ?><div class="small text-warning">−<?= gbp((float) $o['amount_refunded']) ?></div><?php endif; ?></td>
                    <td data-label="Status"><?= shop_status_badge((string) $o['status']) ?></td>
                    <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/shop_order.php?id=<?= (int) $o['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
