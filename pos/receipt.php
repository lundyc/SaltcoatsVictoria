<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';

pos_controls_ensure_schema($pdo);
pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$saleId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
    WHERE s.id = :id
    LIMIT 1');
$stmt->execute([':id' => $saleId]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
    http_response_code(404);
    exit('Receipt was not found.');
}
$receiptNumber = pos_assign_receipt_number($pdo, $saleId);
$itemStmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = :sale ORDER BY id');
$itemStmt->execute([':sale' => $saleId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentLabel = match ((string) $sale['payment_method']) {
    'cash' => 'Cash',
    'card', 'card_stripe_app' => 'Card',
    default => ucwords(str_replace('_', ' ', (string) $sale['payment_method'])),
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($receiptNumber) ?> - POS Receipt</title>
    <style>
        body{margin:0;background:#f6f2ec;color:#1f171a;font-family:Arial,sans-serif}
        .wrap{width:min(420px,100%);margin:0 auto;padding:1rem}
        .receipt{background:#fff;border:1px solid #ddd;padding:1rem}
        h1,h2,p{margin:.15rem 0}
        h1{font-size:1.25rem;text-align:center}
        h2{font-size:.95rem;text-align:center;font-weight:400}
        .meta,.total-row{display:flex;justify-content:space-between;gap:1rem}
        .meta{font-size:.86rem;border-top:1px dashed #bbb;padding-top:.55rem;margin-top:.55rem}
        table{width:100%;border-collapse:collapse;margin:.8rem 0}
        th,td{padding:.35rem 0;border-bottom:1px solid #eee;text-align:left;font-size:.9rem}
        th:last-child,td:last-child{text-align:right}
        .total-row{font-weight:700;padding:.22rem 0}
        .actions{display:flex;gap:.5rem;justify-content:center;margin:1rem 0}
        .actions a,.actions button{border:1px solid #4b0818;background:#fff;color:#4b0818;border-radius:.5rem;padding:.55rem .75rem;font-weight:700;text-decoration:none}
        .actions button{background:#4b0818;color:#fff}
        @media print{body{background:#fff}.wrap{padding:0}.actions{display:none}.receipt{border:0}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="actions"><button type="button" onclick="window.print()">Print</button><a href="/pos/sale.php?id=<?= (int) $saleId ?>">Back</a></div>
    <section class="receipt">
        <h1>MyClubHub POS</h1>
        <h2><?= h((string) $sale['location_name']) ?> · <?= h($receiptNumber) ?></h2>
        <div class="meta"><span>Date</span><strong><?= h(date('d/m/Y H:i', strtotime((string) ($sale['completed_at'] ?: $sale['created_at'])))) ?></strong></div>
        <div class="meta"><span>Operator</span><strong><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></strong></div>
        <div class="meta"><span>Payment</span><strong><?= h($paymentLabel) ?></strong></div>
        <?php if (!empty($sale['payment_reference'])): ?><div class="meta"><span>Reference</span><strong><?= h((string) $sale['payment_reference']) ?></strong></div><?php endif; ?>
        <?php if ((string) $sale['status'] === 'refund'): ?><div class="meta"><span>Type</span><strong>Refund receipt</strong></div><?php endif; ?>
        <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr><td><?= h((string) $item['product_name']) ?></td><td><?= h(number_format((float) $item['qty'], 2)) ?></td><td><?= gbp((float) $item['line_total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="total-row"><span>Subtotal</span><span><?= gbp((float) $sale['subtotal']) ?></span></div>
        <div class="total-row"><span>Discount</span><span><?= gbp((float) $sale['discount_total'] + (float) $sale['points_discount']) ?></span></div>
        <div class="total-row"><span>Total</span><span><?= gbp((float) $sale['total']) ?></span></div>
        <p style="text-align:center;margin-top:1rem;font-size:.82rem">Keep this receipt for your records.</p>
    </section>
</div>
</body>
</html>
