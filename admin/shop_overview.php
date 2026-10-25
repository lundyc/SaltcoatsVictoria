<?php

declare(strict_types=1);

// Club Shop — admin overview. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);

// ---------------------------------------------------------------------------------------------
// Order progress tracker. Each order moves through Paid > Sent to VSN > Ready > Complete, and every
// step is a toggle: clicking a step that is on undoes it. Handled as a small JSON endpoint.
// ---------------------------------------------------------------------------------------------

/** Adds shop_orders.ready_at (the "ready for collection" step) if it does not exist yet. */
function so_ensure_ready_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $has = false;
    foreach ($pdo->query('SHOW COLUMNS FROM shop_orders') as $col) {
        if ($col['Field'] === 'ready_at') {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $pdo->exec('ALTER TABLE shop_orders ADD COLUMN ready_at DATETIME NULL DEFAULT NULL AFTER paid_at');
    }
}

/** @return array{paid:bool,vsn:bool,ready:bool,complete:bool,has_vsn:bool} */
function so_order_state(array $o, bool $hasVsn): array
{
    $status = (string) $o['status'];
    $complete = $status === 'collected';
    return [
        'paid' => in_array($status, ['paid', 'collected'], true),
        'vsn' => !empty($o['vsn_ordered_at']),
        'ready' => $complete || !empty($o['ready_at']),
        'complete' => $complete,
        'has_vsn' => $hasVsn,
    ];
}

/**
 * One tracker row: order details, the furthest step reached, and the four step buttons.
 *
 * @param array<string,mixed> $o
 * @param list<array<string,mixed>> $items
 */
function so_render_row(array $o, array $items): string
{
    $oid = (int) $o['id'];
    $ref = (string) $o['order_ref'];
    $hasVsn = (int) ($o['has_vsn'] ?? 0) === 1;
    $st = so_order_state($o, $hasVsn);
    $isDelivery = (string) ($o['fulfilment_method'] ?? '') === 'delivery';
    $readyLabel = $isDelivery ? 'Ready to dispatch' : 'Ready for collection';

    $dt = static fn(?string $v, string $fmt = 'j M'): string => $v ? (new DateTimeImmutable($v))->format($fmt) : '';
    $placed = new DateTimeImmutable((string) $o['created_at']);
    $ageDays = (int) $placed->diff(new DateTimeImmutable('now'))->format('%a');

    // Furthest step reached.
    if (!$st['paid']) { $stage = ['Awaiting payment', 'secondary']; }
    elseif ($st['complete']) { $stage = ['Complete', 'success']; }
    elseif ($st['ready']) { $stage = [$readyLabel, 'info']; }
    elseif ($st['has_vsn'] && $st['vsn']) { $stage = ['Sent to VSN', 'info']; }
    elseif ($st['paid']) { $stage = ['Paid', 'success']; }
    else { $stage = ['Awaiting payment', 'secondary']; }

    $timeline = ['Placed ' . $placed->format('j M')];
    if ($st['paid'] && !empty($o['paid_at'])) { $timeline[] = 'Paid ' . $dt((string) $o['paid_at']); }
    if ($st['vsn']) { $timeline[] = 'VSN ' . $dt((string) $o['vsn_ordered_at']); }
    if (!empty($o['ready_at']) || $st['ready']) { $timeline[] = 'Ready ' . $dt((string) ($o['ready_at'] ?: ($o['collected_at'] ?? ''))); }
    if ($st['complete'] && !empty($o['collected_at'])) { $timeline[] = 'Done ' . $dt((string) $o['collected_at']); }

    $fulfil = (string) ($o['fulfilment_method'] ?? '');
    $fulfilLabel = $isDelivery ? 'Delivery' . (($o['delivery_postcode'] ?? '') !== '' ? ' · ' . $o['delivery_postcode'] : '')
        : ($fulfil !== '' ? 'Collection' . (($o['collection_point'] ?? '') !== '' ? ' · ' . $o['collection_point'] : '') : '');

    $parts = array_map(static fn(array $it): string => (int) $it['quantity'] . ' &times; ' . h((string) $it['product_name']) . (($it['options_label'] ?? '') !== '' ? ' <span class="text-muted">(' . h((string) $it['options_label']) . ')</span>' : ''), array_slice($items, 0, 3));
    $more = max(0, count($items) - 3);

    // Step buttons: [key, icon, label, on, enabled, tip, confirm message, confirm label, confirm class]
    $paidCard = !empty($o['stripe_payment_intent_id']);
    $steps = [];
    $steps[] = ['paid', 'fa-sterling-sign', 'Paid', $st['paid'],
        !$st['paid'] || ((string) $o['status'] === 'paid' && !$st['ready'] && !$paidCard),
        $st['paid'] ? ($paidCard ? 'Paid by card: refund from the order page' : ($st['ready'] ? 'Undo the later steps first' : 'Paid. Click to undo')) : 'Mark as paid',
        $st['paid'] ? 'Undo payment on ' . $ref . '? It goes back to awaiting payment. The confirmation email already sent cannot be recalled.' : 'Mark order ' . $ref . ' as paid? Use this only if the money was received outside Stripe. The customer is emailed a confirmation.',
        $st['paid'] ? 'Undo payment' : 'Mark paid', $st['paid'] ? 'btn-danger' : 'btn-primary'];
    if ($hasVsn) {
        $steps[] = ['vsn', 'fa-shirt', 'Sent to VSN', $st['vsn'], !$st['complete'],
            $st['complete'] ? 'Reopen the order first' : ($st['vsn'] ? 'Sent to VSN. Click to undo' : 'Mark as sent to VSN'), '', '', ''];
    }
    $steps[] = ['ready', 'fa-box-open', $readyLabel, $st['ready'], $st['paid'] && !$st['complete'],
        !$st['paid'] ? 'Needs payment first' : ($st['complete'] ? 'Reopen the order first' : ($st['ready'] ? $readyLabel . '. Click to undo' : 'Mark as ' . strtolower($readyLabel))), '', '', ''];
    $steps[] = ['complete', 'fa-circle-check', 'Complete', $st['complete'], $st['paid'],
        !$st['paid'] ? 'Needs payment first' : ($st['complete'] ? 'Complete. Click to reopen' : 'Mark as complete'),
        $st['complete'] ? 'Reopen order ' . $ref . '? It goes back to paid. No email is sent.' : 'Mark order ' . $ref . ' as complete? The customer is emailed to say it has been collected.',
        $st['complete'] ? 'Reopen' : 'Mark complete', 'btn-primary'];

    ob_start();
    ?>
    <tr data-order="<?= $oid ?>" data-status="<?= h((string) $o['status']) ?>" data-open="<?= $st['complete'] ? '0' : '1' ?>" data-awaiting="<?= !$st['paid'] ? '1' : '0' ?>" data-vsn="<?= $hasVsn && !$st['vsn'] && !$st['complete'] ? '1' : '0' ?>" data-ready="<?= $st['ready'] && !$st['complete'] ? '1' : '0' ?>" data-complete="<?= $st['complete'] ? '1' : '0' ?>">
        <td>
            <a href="/admin/shop_order.php?id=<?= $oid ?>" class="fw-bold text-decoration-none"><?= h($ref) ?></a>
            <?php if ((int) $o['is_preorder'] === 1): ?><span class="badge text-bg-warning ms-1">Pre-order</span><?php endif; ?>
            <div class="small"><?= h((string) $o['customer_name']) ?></div>
            <?php if ($fulfilLabel !== ''): ?><div class="small text-muted"><?= h($fulfilLabel) ?></div><?php endif; ?>
        </td>
        <td class="small"><?= $parts ? implode('<br>', $parts) : '<span class="text-muted">&ndash;</span>' ?><?= $more > 0 ? '<div class="text-muted">+ ' . $more . ' more</div>' : '' ?></td>
        <td class="text-end fw-bold"><?= gbp((float) $o['total']) ?></td>
        <td>
            <span class="badge text-bg-<?= h($stage[1]) ?> so-pill" tabindex="0" data-tip="<?= h(implode("\n", $timeline)) ?>" aria-label="<?= h($stage[0] . '. ' . implode(', ', $timeline)) ?>"><?= h($stage[0]) ?></span>
            <?php if (!$st['paid'] && $ageDays >= 3): ?><div class="so-late small mt-1">unpaid <?= $ageDays ?> days</div><?php endif; ?>
        </td>
        <td>
            <div class="so-steps" role="group" aria-label="Progress for <?= h($ref) ?>">
                <?php foreach ($steps as [$key, $icon, $label, $on, $enabled, $tip, $msg, $confirmLabel, $confirmClass]): ?>
                    <button type="button" class="so-step <?= $on ? 'is-on' : 'is-off' ?>" data-step="<?= h($key) ?>" data-order="<?= $oid ?>" data-tip="<?= h($tip) ?>" aria-label="<?= h($label . ($on ? ' (done)' : '')) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
                        <?= $enabled ? '' : 'disabled' ?>
                        <?php if ($enabled && $msg !== ''): ?>data-msg="<?= h($msg) ?>" data-confirm-label="<?= h($confirmLabel) ?>" data-confirm-class="<?= h($confirmClass) ?>" data-title="Update order <?= h($ref) ?>"<?php endif; ?>>
                        <i class="fa-solid <?= h($icon) ?> so-step__icon" aria-hidden="true"></i>
                        <i class="fa-solid fa-rotate-left so-step__undo" aria-hidden="true"></i>
                    </button>
                <?php endforeach; ?>
                <?php if (!$st['paid']): ?>
                    <button type="button" class="so-step so-step--cancel is-off" data-step="cancel" data-order="<?= $oid ?>" data-tip="Cancel order" aria-label="Cancel order"
                        data-msg="<?= h('Cancel order ' . $ref . '? Reserved stock is released and the customer is emailed.') ?>" data-confirm-label="Cancel order" data-confirm-class="btn-danger" data-title="Update order <?= h($ref) ?>">
                        <i class="fa-solid fa-xmark so-step__icon" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

so_ensure_ready_column($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle_step') {
    header('Content-Type: application/json; charset=UTF-8');
    $reply = static function (bool $ok, string $message) {
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    };
    try {
        if (!csrf_check()) {
            throw new RuntimeException('Your session expired. Reload the page and try again.');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $step = (string) ($_POST['step'] ?? '');
        $order = shop_get_order($pdo, $orderId);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $ref = (string) $order['order_ref'];
        $status = (string) $order['status'];
        $hasVsn = (int) $pdo->query('SELECT COUNT(*) FROM shop_order_items WHERE is_vsn = 1 AND order_id = ' . $orderId)->fetchColumn() > 0;
        $st = so_order_state($order, $hasVsn);
        if (!in_array($status, ['pending_payment', 'paid', 'collected'], true)) {
            throw new RuntimeException('Order ' . $ref . ' is ' . str_replace('_', ' ', $status) . ' and can no longer be changed here.');
        }

        if ($step === 'paid') {
            if (!$st['paid']) {
                shop_mark_order_paid($pdo, $orderId, null);
                auditLog($pdo, 'shop_order_marked_paid', 'Shop order #' . $orderId . ' marked paid manually (overview)');
                $reply(true, 'Order ' . $ref . ' marked as paid.');
            }
            if ($status !== 'paid' || $st['ready']) {
                throw new RuntimeException('Undo the later steps on ' . $ref . ' before undoing payment.');
            }
            if (!empty($order['stripe_payment_intent_id'])) {
                throw new RuntimeException('Order ' . $ref . ' was paid by card. Use Refund on the order page instead.');
            }
            $pdo->prepare("UPDATE shop_orders SET status = 'pending_payment', paid_at = NULL WHERE id = :id AND status = 'paid'")->execute([':id' => $orderId]);
            if (!empty($order['discount_code'])) {
                $pdo->prepare('UPDATE shop_discount_codes SET used_count = GREATEST(used_count - 1, 0) WHERE code = :c')->execute([':c' => (string) $order['discount_code']]);
            }
            auditLog($pdo, 'shop_order_payment_undone', 'Shop order #' . $orderId . ' payment undone (overview)');
            $reply(true, 'Payment on ' . $ref . ' undone. It is awaiting payment again.');
        } elseif ($step === 'vsn') {
            if (!$hasVsn) {
                throw new RuntimeException('Order ' . $ref . ' has no VSN items.');
            }
            if ($st['complete']) {
                throw new RuntimeException('Reopen ' . $ref . ' before changing its VSN step.');
            }
            if (!$st['vsn']) {
                shop_mark_vsn_ordered($pdo, $orderId);
                auditLog($pdo, 'shop_order_vsn_ordered', 'Shop order #' . $orderId . ' flagged as sent to VSN (overview)');
                $reply(true, 'Order ' . $ref . ' marked as sent to VSN.');
            }
            $pdo->prepare('UPDATE shop_orders SET vsn_ordered_at = NULL WHERE id = :id')->execute([':id' => $orderId]);
            auditLog($pdo, 'shop_order_vsn_undone', 'Shop order #' . $orderId . ' VSN step undone (overview)');
            $reply(true, 'Order ' . $ref . ' is no longer marked as sent to VSN.');
        } elseif ($step === 'ready') {
            if (!$st['paid']) {
                throw new RuntimeException('Order ' . $ref . ' needs payment first.');
            }
            if ($st['complete']) {
                throw new RuntimeException('Reopen ' . $ref . ' before changing its ready step.');
            }
            if (empty($order['ready_at'])) {
                $pdo->prepare('UPDATE shop_orders SET ready_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
                shop_add_order_event($pdo, $orderId, 'Marked ready.');
                auditLog($pdo, 'shop_order_ready', 'Shop order #' . $orderId . ' marked ready (overview)');
                $reply(true, 'Order ' . $ref . ' marked as ready.');
            }
            $pdo->prepare('UPDATE shop_orders SET ready_at = NULL WHERE id = :id')->execute([':id' => $orderId]);
            auditLog($pdo, 'shop_order_ready_undone', 'Shop order #' . $orderId . ' ready step undone (overview)');
            $reply(true, 'Order ' . $ref . ' is no longer marked as ready.');
        } elseif ($step === 'complete') {
            if (!$st['paid']) {
                throw new RuntimeException('Order ' . $ref . ' needs payment first.');
            }
            if (!$st['complete']) {
                $pdo->prepare('UPDATE shop_orders SET ready_at = COALESCE(ready_at, NOW()) WHERE id = :id')->execute([':id' => $orderId]);
                shop_mark_collected($pdo, $orderId);
                auditLog($pdo, 'shop_order_collected', 'Shop order #' . $orderId . ' marked complete (overview)');
                $reply(true, 'Order ' . $ref . ' marked as complete.');
            }
            $pdo->prepare("UPDATE shop_orders SET status = 'paid', collected_at = NULL WHERE id = :id AND status = 'collected'")->execute([':id' => $orderId]);
            shop_add_order_event($pdo, $orderId, 'Reopened (completion undone).');
            auditLog($pdo, 'shop_order_reopened', 'Shop order #' . $orderId . ' reopened (overview)');
            $reply(true, 'Order ' . $ref . ' reopened.');
        } elseif ($step === 'cancel') {
            if ($status !== 'pending_payment') {
                throw new RuntimeException('Only orders awaiting payment can be cancelled here. Refund paid orders from the order page.');
            }
            shop_cancel_order($pdo, $orderId, 'Cancelled by admin');
            auditLog($pdo, 'shop_order_cancelled', 'Shop order #' . $orderId . ' cancelled (overview)');
            $reply(true, 'Order ' . $ref . ' cancelled.');
        }
        throw new RuntimeException('Unknown step.');
    } catch (Throwable $e) {
        $reply(false, $e->getMessage());
    }
}

$overviewMessage = '';
$overviewError = '';
if (isset($_GET['m'])) { $overviewMessage = (string) $_GET['m']; }
if (isset($_GET['e'])) { $overviewError = (string) $_GET['e']; }

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

$exportHref = '/admin/shop_overview.php?' . http_build_query(array_filter(['from' => $dateFrom, 'to' => $dateTo]) + ['export' => 'csv']);
$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Club Shop',
    'subtitle' => 'Sales, orders and stock for the online shop.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';


$settings = shop_get_settings($pdo);
$stripeReady = stripe_is_configured();
$filtered = $dateFrom !== '' || $dateTo !== '';

// Period figures (respect the date filter).
$totalsStmt = $pdo->prepare("SELECT
        COALESCE(SUM(status IN ('paid','collected','refunded')), 0) AS orders_paid,
        COALESCE(SUM(CASE WHEN status IN ('paid','collected','refunded') THEN total ELSE 0 END), 0) AS revenue,
        COALESCE(SUM(amount_refunded), 0) AS refunded
    FROM shop_orders o WHERE 1=1{$dateWhere}");
$totalsStmt->execute($dateParams);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$ordersPaid = (int) ($totals['orders_paid'] ?? 0);
$revenue = (float) ($totals['revenue'] ?? 0);
$avgOrder = $ordersPaid > 0 ? $revenue / $ordersPaid : 0.0;

// Units sold per product (paid orders in the period), grouped by product with the option split underneath.
$breakdownStmt = $pdo->prepare("SELECT i.product_name, i.options_label, SUM(i.quantity) AS qty, SUM(i.line_total) AS revenue
    FROM shop_order_items i JOIN shop_orders o ON o.id = i.order_id
    WHERE o.status IN ('paid','collected','refunded'){$dateWhere}
    GROUP BY i.product_name, i.options_label
    ORDER BY i.product_name, qty DESC");
$breakdownStmt->execute($dateParams);
$salesByProduct = [];
foreach ($breakdownStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $name = (string) $b['product_name'];
    $salesByProduct[$name] ??= ['qty' => 0, 'revenue' => 0.0, 'options' => []];
    $salesByProduct[$name]['qty'] += (int) $b['qty'];
    $salesByProduct[$name]['revenue'] += (float) $b['revenue'];
    if (($b['options_label'] ?? '') !== '') {
        $salesByProduct[$name]['options'][] = ['label' => (string) $b['options_label'], 'qty' => (int) $b['qty']];
    }
}
uasort($salesByProduct, static fn(array $x, array $y): int => $y['qty'] <=> $x['qty']);
$maxProductQty = $salesByProduct ? max(array_column($salesByProduct, 'qty')) : 1;

// Tracker orders (live, not date-filtered): everything still open, plus anything completed in the last 7 days
// so a step can still be undone. Oldest first.
$trackerOrders = $pdo->query("SELECT o.*,
        EXISTS(SELECT 1 FROM shop_order_items v WHERE v.order_id = o.id AND v.is_vsn = 1) AS has_vsn
    FROM shop_orders o
    WHERE o.status IN ('pending_payment','paid')
       OR (o.status = 'collected' AND o.collected_at >= (NOW() - INTERVAL 7 DAY))
    ORDER BY (o.status = 'collected') ASC, o.created_at ASC, o.id ASC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
$openItemsByOrder = [];
if ($trackerOrders) {
    $openIds = implode(',', array_map(static fn(array $o): int => (int) $o['id'], $trackerOrders));
    foreach ($pdo->query("SELECT order_id, product_name, options_label, quantity, is_vsn FROM shop_order_items WHERE order_id IN ({$openIds}) ORDER BY id") as $it) {
        $openItemsByOrder[(int) $it['order_id']][] = $it;
    }
}
$toCollect = 0;
$awaitingPayment = 0;
$readyCount = 0;
$completedCount = 0;
$vsnToSend = 0;
foreach ($trackerOrders as $o) {
    $stt = so_order_state($o, (int) $o['has_vsn'] === 1);
    if ($stt['complete']) { $completedCount++; continue; }
    if ($stt['paid']) { $toCollect++; } else { $awaitingPayment++; }
    if ($stt['ready']) { $readyCount++; }
    if ($stt['has_vsn'] && !$stt['vsn']) { $vsnToSend++; }
}
$openOrdersTotal = $toCollect + $awaitingPayment;

// Recently closed orders (so a finished order is still easy to find).
$closedOrders = $pdo->query("SELECT id, order_ref, customer_name, status, total, COALESCE(collected_at, cancelled_at, updated_at) AS closed_at
    FROM shop_orders WHERE status IN ('collected','cancelled','refunded')
    ORDER BY COALESCE(collected_at, cancelled_at, updated_at) DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// VSN: orders waiting to go on the next VSN order form, and units by product/size.
$pendingVsn = shop_orders_pending_vsn($pdo);
$vsnUnits = [];
$vsnUnitTotal = 0;
if ($pendingVsn) {
    $ids = implode(',', array_map(static fn(array $o): int => (int) $o['id'], $pendingVsn));
    $vsnUnits = $pdo->query("SELECT product_name, options_label, SUM(quantity) AS qty FROM shop_order_items
        WHERE order_id IN ({$ids}) AND is_vsn = 1 GROUP BY product_name, options_label ORDER BY product_name, options_label")->fetchAll(PDO::FETCH_ASSOC);
    $vsnUnitTotal = (int) array_sum(array_column($vsnUnits, 'qty'));
}

// Low-stock products (only those that track stock).
$lowThreshold = max(0, (int) ($settings['low_stock_threshold'] ?? 3));
$lowStock = array_values(array_filter(
    shop_get_products($pdo, ['active_only' => true]),
    static fn(array $p): bool => $p['stock_qty'] !== null && (int) $p['stock_qty'] <= ($p['low_stock_amount'] !== null ? (int) $p['low_stock_amount'] : $lowThreshold)
));

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
<style>
.so-topbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1rem; margin-bottom: 1rem; }
.so-topbar .so-topbar__notice { flex: 1 1 18rem; padding: .55rem .9rem; }
.so-topbar .shop-admin-toolbar { margin: 0 0 0 auto !important; flex: 0 0 auto; }
@media (min-width: 992px) { .so-table-wrap { overflow: visible; } }
.so-pill { cursor: default; position: relative; font-size: .78rem; padding: .4em .75em; }
.so-pill[data-tip]:hover::after, .so-pill[data-tip]:focus-visible::after { content: attr(data-tip); position: absolute; z-index: 30; bottom: calc(100% + 8px); left: 0; white-space: pre; text-align: left; padding: .4rem .7rem; border-radius: .4rem; background: #231418; color: #fff; font-size: .75rem; font-weight: 600; line-height: 1.5; pointer-events: none; box-shadow: 0 6px 16px rgba(0, 0, 0, .25); }
.so-pill[data-tip]:hover::before { content: ""; position: absolute; z-index: 31; bottom: calc(100% + 3px); left: 14px; border: 5px solid transparent; border-top-color: #231418; border-bottom: 0; pointer-events: none; }
.so-steps { display: flex; gap: .4rem; align-items: center; }
.so-step { position: relative; width: 2.2rem; height: 2.2rem; border-radius: 50%; border: 1.5px solid rgba(75, 8, 24, .25); background: #fff; color: #8a7f83; display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; cursor: pointer; transition: background .15s, color .15s, border-color .15s, transform .1s; padding: 0; }
.so-step:hover:not(:disabled) { border-color: #4b0818; color: #4b0818; transform: translateY(-1px); }
.so-step.is-on { background: #4b0818; border-color: #4b0818; color: #fff; }
.so-step.is-on:hover:not(:disabled) { background: #7a1a30; color: #fff; }
.so-step:disabled { opacity: .38; cursor: not-allowed; }
.so-step.is-on:disabled { opacity: .85; }
.so-step.is-busy { opacity: .6; cursor: progress; }
.so-step .so-step__undo { display: none; }
.so-step.is-on:hover:not(:disabled) .so-step__icon { display: none; }
.so-step.is-on:hover:not(:disabled) .so-step__undo { display: inline-block; }
.so-step--cancel { border-color: rgba(180, 35, 53, .4); color: #b42335; margin-left: .35rem; }
.so-step--cancel:hover:not(:disabled) { background: #b42335; border-color: #b42335; color: #fff; }
.so-step[data-tip]:hover::after, .so-step[data-tip]:focus-visible::after { content: attr(data-tip); position: absolute; z-index: 30; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%); white-space: nowrap; padding: .3rem .6rem; border-radius: .4rem; background: #231418; color: #fff; font-size: .75rem; font-weight: 600; pointer-events: none; box-shadow: 0 6px 16px rgba(0, 0, 0, .25); }
.so-step[data-tip]:hover::before { content: ""; position: absolute; z-index: 31; bottom: calc(100% + 3px); left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: #231418; border-bottom: 0; pointer-events: none; }
.so-step:nth-last-child(-n+2)[data-tip]:hover::after, .so-step:nth-last-child(-n+2)[data-tip]:focus-visible::after { left: auto; right: -4px; transform: none; }
.so-step:nth-last-child(-n+2)[data-tip]:hover::before { left: auto; right: 12px; transform: none; }
.so-legend { display: flex; flex-wrap: wrap; gap: .4rem 1.1rem; align-items: center; }
.so-legend i { margin-right: .3rem; color: #4b0818; }
.so-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
.so-chip { border: 1px solid rgba(75, 8, 24, .2); background: #fff; color: #4b0818; border-radius: 999px; padding: .2rem .75rem; font-size: .8rem; cursor: pointer; }
.so-chip.is-active { background: #4b0818; border-color: #4b0818; color: #fff; }
.so-chip .so-count { opacity: .7; margin-left: .25rem; }
.so-late { color: #b42335; font-weight: 600; }
.so-bar { height: .45rem; border-radius: 999px; background: rgba(75, 8, 24, .08); overflow: hidden; margin-top: .3rem; }
.so-bar > span { display: block; height: 100%; background: #4b0818; border-radius: 999px; }
.so-period { display: flex; flex-wrap: wrap; align-items: end; gap: .5rem; }
.so-period label { font-size: .75rem; color: #6c757d; display: block; margin-bottom: .1rem; }
</style>

<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><span aria-current="page">Shop</span></nav>
    <div class="so-topbar">
        <?php if (($settings['shop_enabled'] ?? '1') !== '1'): ?>
            <div class="alert alert-secondary so-topbar__notice mb-0">The storefront is currently switched <strong>off</strong> in
                <a href="/admin/shop_settings.php">Shop settings</a>.</div>
        <?php endif; ?>
        <?php shop_render_toolbar([
            ['label' => 'Add product', 'href' => '/admin/shop_product.php', 'icon' => 'fa-plus', 'primary' => true],
            ['label' => 'All orders', 'href' => '/admin/shop_orders.php', 'icon' => 'fa-receipt'],
            ['label' => 'View storefront', 'href' => '/shop/', 'icon' => 'fa-arrow-up-right-from-square', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']],
            hub_auth_is_admin() ? ['label' => 'Export sales CSV', 'href' => $exportHref, 'icon' => 'fa-download', 'menu' => true] : null,
        ]); ?>
    </div>

    <?php if ($overviewMessage !== ''): ?><div class="alert alert-success"><?= h($overviewMessage) ?></div><?php endif; ?>
    <?php if ($overviewError !== ''): ?><div class="alert alert-danger"><?= h($overviewError) ?></div><?php endif; ?>
    <?php if (!$stripeReady): ?>
        <div class="alert alert-warning">Stripe is not configured yet, so customers can't pay. Add a secret key under
            <a href="/admin/settings.php?tab=payments">Settings &rsaquo; Payments</a>.</div>
    <?php endif; ?>

    <div id="soKpis"><?php
    hub_render_metric_grid([
        ['label' => 'Open orders', 'value' => $openOrdersTotal, 'icon' => 'fa-box-open', 'tone' => $openOrdersTotal ? 'warning' : 'neutral',
            'meta' => $toCollect . ' to collect · ' . $awaitingPayment . ' awaiting payment', 'href' => '#open-orders'],
        ['label' => 'To send to VSN', 'value' => count($pendingVsn), 'icon' => 'fa-shirt', 'tone' => $pendingVsn ? 'primary' : 'neutral',
            'meta' => $pendingVsn ? $vsnUnitTotal . ' item' . ($vsnUnitTotal === 1 ? '' : 's') . ' on the next order form' : 'Nothing waiting', 'href' => '#vsn-order'],
        ['label' => 'Low stock', 'value' => count($lowStock), 'icon' => 'fa-triangle-exclamation', 'tone' => $lowStock ? 'danger' : 'neutral',
            'meta' => $lowStock ? 'at or below ' . $lowThreshold . ' left' : 'Nothing running low', 'href' => '/admin/shop_products.php'],
        ['label' => 'Revenue' . ($filtered ? ' (filtered)' : ''), 'value' => gbp($revenue), 'icon' => 'fa-sterling-sign', 'tone' => 'success',
            'meta' => $ordersPaid . ' paid order' . ($ordersPaid === 1 ? '' : 's') . ($ordersPaid ? ' · avg ' . gbp($avgOrder) : '') . ((float) ($totals['refunded'] ?? 0) > 0 ? ' · less ' . gbp((float) $totals['refunded']) . ' refunded' : '')],
    ], 'Shop summary');
    ?></div>

    <div class="row g-3">
        <div class="col-12">
            <section class="card hub-panel mb-3" id="open-orders">
                <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Orders</strong>
                    <div class="so-chips" id="soChips" role="group" aria-label="Filter orders">
                        <button type="button" class="so-chip is-active" data-filter="open">Open<span class="so-count"><?= $openOrdersTotal ?></span></button>
                        <button type="button" class="so-chip" data-filter="awaiting">Awaiting payment<span class="so-count"><?= $awaitingPayment ?></span></button>
                        <button type="button" class="so-chip" data-filter="vsn">VSN to send<span class="so-count"><?= $vsnToSend ?></span></button>
                        <button type="button" class="so-chip" data-filter="ready">Ready<span class="so-count"><?= $readyCount ?></span></button>
                        <button type="button" class="so-chip" data-filter="complete">Completed (7 days)<span class="so-count"><?= $completedCount ?></span></button>
                    </div>
                </div>
                <div id="soCsrf" hidden><?= csrf_field() ?></div>
                <div id="soNotice" class="px-3 pt-3 pb-3" hidden></div>
                <div class="table-responsive so-table-wrap">
                    <table class="table align-middle mb-0 hub-data-table" id="openOrdersTable">
                        <thead><tr><th>Order</th><th>Items</th><th class="text-end">Total</th><th>Status</th><th style="min-width:10.5rem">Progress</th></tr></thead>
                        <tbody>
                        <?php foreach ($trackerOrders as $o): ?>
                            <?= so_render_row($o, $openItemsByOrder[(int) $o['id']] ?? []) ?>
                        <?php endforeach; ?>
                        <tr id="openOrdersEmpty" <?= $trackerOrders ? 'hidden' : '' ?>><td colspan="5" class="text-muted text-center py-4"><i class="fa-solid fa-circle-check text-success me-1" aria-hidden="true"></i><span>No open orders. Everything has been collected.</span></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent small text-muted so-legend">
                    <span><i class="fa-solid fa-sterling-sign"></i> Paid</span>
                    <span><i class="fa-solid fa-shirt"></i> Sent to VSN</span>
                    <span><i class="fa-solid fa-box-open"></i> Ready</span>
                    <span><i class="fa-solid fa-circle-check"></i> Complete</span>
                </div>
            </section>

        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <section class="card hub-panel">
                <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-end gap-2">
                    <div><strong>Sales by product</strong> <span class="text-muted small">(paid orders<?= $filtered ? ', filtered' : '' ?>)</span></div>
                    <form method="get" class="so-period">
                        <div><label for="f_from">From</label><input class="form-control form-control-sm" id="f_from" type="date" name="from" value="<?= h($dateFrom) ?>"></div>
                        <div><label for="f_to">To</label><input class="form-control form-control-sm" id="f_to" type="date" name="to" value="<?= h($dateTo) ?>"></div>
                        <button class="btn btn-sm btn-dark" type="submit">Filter</button>
                        <?php if ($filtered): ?><a class="btn btn-sm btn-outline-secondary" href="/admin/shop_overview.php">Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="card-body">
                    <?php if (!$salesByProduct): ?>
                        <p class="text-muted text-center mb-0 py-3">Nothing sold<?= $filtered ? ' in this period' : ' yet' ?>.</p>
                    <?php endif; ?>
                    <?php foreach ($salesByProduct as $name => $p): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between gap-3">
                                <strong><?= h((string) $name) ?></strong>
                                <span class="text-nowrap"><strong><?= (int) $p['qty'] ?></strong> sold &middot; <?= gbp((float) $p['revenue']) ?></span>
                            </div>
                            <div class="so-bar" aria-hidden="true"><span style="width: <?= max(4, (int) round($p['qty'] / $maxProductQty * 100)) ?>%"></span></div>
                            <?php if ($p['options']): ?>
                                <div class="small text-muted mt-1"><?= implode(' &middot; ', array_map(static fn(array $op): string => h($op['label']) . ' <strong>' . (int) $op['qty'] . '</strong>', $p['options'])) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-4">
            <section class="card hub-panel mb-3" id="vsn-order">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong>VSN order</strong>
                    <span class="badge text-bg-<?= $pendingVsn ? 'secondary' : 'light border' ?>"><?= count($pendingVsn) ?> order<?= count($pendingVsn) === 1 ? '' : 's' ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$pendingVsn): ?>
                        <p class="small text-muted mb-0">Nothing waiting. VSN-made items appear here until they are sent to VSN.</p>
                    <?php else: ?>
                        <p class="small text-muted">VSN-made items not yet sent to VSN.</p>
                        <ul class="list-unstyled small mb-3">
                            <?php foreach ($vsnUnits as $u): ?>
                                <li class="d-flex justify-content-between border-bottom py-1"><span><?= h((string) $u['product_name']) ?><?= ($u['options_label'] ?? '') !== '' ? ' &middot; ' . h((string) $u['options_label']) : '' ?></span><strong><?= (int) $u['qty'] ?></strong></li>
                            <?php endforeach; ?>
                            <li class="d-flex justify-content-between pt-2"><span>Total items</span><strong><?= $vsnUnitTotal ?></strong></li>
                        </ul>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-brand btn-sm" href="/admin/shop_vsn_order_form_pdf.php?download=1"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Order form (PDF)</a>
                            <form method="post" action="/admin/shop_orders.php" data-confirm="Only do this after you've actually sent the order form to VSN. Orders still awaiting payment stay on the list." data-confirm-title="Mark paid orders as sent to VSN?" data-confirm-action="Mark as sent">
                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="action" value="mark_all_vsn_ordered">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Mark all as sent</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($lowStock): ?>
            <section class="card hub-panel mb-3">
                <div class="card-header bg-transparent"><strong>Low stock</strong></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lowStock as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <a class="text-decoration-none" href="/admin/shop_product.php?id=<?= (int) $p['id'] ?>"><?= h((string) $p['name']) ?></a>
                            <span class="badge text-bg-<?= (int) $p['stock_qty'] <= 0 ? 'danger' : 'warning' ?>"><?= (int) $p['stock_qty'] <= 0 ? 'Sold out' : (int) $p['stock_qty'] . ' left' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <section class="card hub-panel mb-3" id="soClosed">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong>Recently closed</strong>
                    <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_orders.php">All orders</a>
                </div>
                <ul class="list-group list-group-flush">
                    <?php if (!$closedOrders): ?><li class="list-group-item text-muted small">No collected, cancelled or refunded orders yet.</li><?php endif; ?>
                    <?php foreach ($closedOrders as $c): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <a href="/admin/shop_order.php?id=<?= (int) $c['id'] ?>" class="fw-bold text-decoration-none"><?= h((string) $c['order_ref']) ?></a>
                                <span class="small text-muted d-block"><?= h((string) $c['customer_name']) ?> &middot; <?= h((new DateTimeImmutable((string) $c['closed_at']))->format('j M')) ?></span>
                            </span>
                            <span class="text-end"><?= shop_status_badge((string) $c['status']) ?><span class="small d-block text-muted"><?= gbp((float) $c['total']) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    var table = document.getElementById('openOrdersTable');
    var notice = document.getElementById('soNotice');
    var filter = 'open';
    var touched = {};

    function rows() { return Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-order]')); }

    function matches(row) {
        if (touched[row.getAttribute('data-order')]) { return true; }
        switch (filter) {
            case 'open': return row.getAttribute('data-open') === '1';
            case 'awaiting': return row.getAttribute('data-awaiting') === '1' && row.getAttribute('data-open') === '1';
            case 'vsn': return row.getAttribute('data-vsn') === '1';
            case 'ready': return row.getAttribute('data-ready') === '1';
            case 'complete': return row.getAttribute('data-complete') === '1';
        }
        return true;
    }

    var emptyText = { open: 'No open orders. Everything has been collected.', awaiting: 'No orders awaiting payment.', vsn: 'Nothing waiting to be sent to VSN.', ready: 'No orders are marked ready.', complete: 'Nothing completed in the last 7 days.' };

    function applyFilter() {
        var shown = 0;
        rows().forEach(function (row) { var ok = matches(row); row.hidden = !ok; if (ok) { shown++; } });
        var empty = document.getElementById('openOrdersEmpty');
        empty.hidden = shown !== 0;
        empty.querySelector('span').textContent = emptyText[filter] || 'No orders in this view.';
    }

    function bindChips() {
        document.querySelectorAll('#soChips .so-chip').forEach(function (chip) {
            chip.classList.toggle('is-active', chip.getAttribute('data-filter') === filter);
            chip.addEventListener('click', function () {
                filter = chip.getAttribute('data-filter');
                touched = {};
                document.querySelectorAll('#soChips .so-chip').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
                applyFilter();
            });
        });
    }

    function showNotice(ok, text) {
        notice.hidden = false;
        notice.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' py-2 mb-0"></div>';
        notice.firstChild.textContent = text;
        clearTimeout(showNotice.t);
        showNotice.t = setTimeout(function () { notice.hidden = true; }, ok ? 4000 : 9000);
    }

    // After a change, pull the fresh page and swap in the parts that depend on order state.
    function refresh(orderId) {
        return fetch('/admin/shop_overview.php?_=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                ['soKpis', 'vsn-order', 'soClosed'].forEach(function (id) {
                    var fresh = doc.getElementById(id), old = document.getElementById(id);
                    if (fresh && old) { old.replaceWith(fresh); }
                });
                var freshRow = doc.querySelector('#openOrdersTable tbody tr[data-order="' + orderId + '"]');
                var oldRow = table.querySelector('tbody tr[data-order="' + orderId + '"]');
                if (oldRow && freshRow) { oldRow.replaceWith(freshRow); touched[orderId] = true; }
                else if (oldRow && !freshRow) { oldRow.remove(); }
                var chips = doc.getElementById('soChips');
                if (chips) { document.getElementById('soChips').innerHTML = chips.innerHTML; bindChips(); }
                applyFilter();
            });
    }

    function send(button) {
        var orderId = button.getAttribute('data-order');
        var body = new URLSearchParams();
        body.set('csrf_token', document.querySelector('#soCsrf [name=csrf_token]').value);
        body.set('action', 'toggle_step');
        body.set('order_id', orderId);
        body.set('step', button.getAttribute('data-step'));
        button.classList.add('is-busy');
        button.disabled = true;
        return fetch('/admin/shop_overview.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                showNotice(res.ok, res.message);
                return refresh(orderId);
            })
            .catch(function () { showNotice(false, 'Something went wrong. Reload the page and check the order.'); button.classList.remove('is-busy'); button.disabled = false; });
    }

    table.addEventListener('click', function (e) {
        var button = e.target.closest('.so-step');
        if (!button || button.disabled) { return; }
        var msg = button.getAttribute('data-msg');
        if (!msg) { send(button); return; }
        var run = function () { send(button); };
        if (window.hubConfirm) {
            window.hubConfirm(msg, { title: button.getAttribute('data-title'), actionLabel: button.getAttribute('data-confirm-label'), actionClass: button.getAttribute('data-confirm-class') || 'btn-primary' })
                .then(function (ok) { if (ok) { run(); } });
        } else if (window.confirm(msg)) { run(); }
    });

    bindChips();
    applyFilter();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
