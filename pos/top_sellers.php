<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_trading_days.php';

pos_ensure_schema($pdo);
pos_trading_day_ensure_schema($pdo);
pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
$tradingDayId = (int) ($_GET['trading_day_id'] ?? 0);
$tradingDay = null;
if ($tradingDayId > 0) {
    $dayStmt = $pdo->prepare('SELECT * FROM pos_trading_days WHERE id = :id LIMIT 1');
    $dayStmt->execute([':id' => $tradingDayId]);
    $tradingDay = $dayStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($tradingDay) {
        $date = (string) $tradingDay['business_date'];
    }
}

$stmt = $pdo->prepare("
    SELECT i.product_name, i.category_name, SUM(i.qty) AS qty, COALESCE(SUM(i.line_total), 0) AS total
    FROM pos_sale_items i
    JOIN pos_sales s ON s.id = i.sale_id
    WHERE s.status = 'complete'
      AND " . ($tradingDay ? 's.trading_day_id = :trading_day_id' : 'DATE(s.created_at) = :date') . "
    GROUP BY i.product_name, i.category_name
    ORDER BY qty DESC, total DESC, i.product_name
");
$stmt->execute($tradingDay ? [':trading_day_id' => (int) $tradingDay['id']] : [':date' => $date]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'Top Sellers',
    'subtitle' => 'Review product sales for a selected trading day.',
];
require_once __DIR__ . '/../admin/header.php';
?>

<div class="pos-top-sellers-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Top sellers</span></nav>
    <section class="hub-section-commandbar" aria-labelledby="topSellersActionsTitle">
        <div>
            <h2 id="topSellersActionsTitle">Product report</h2>
            <p>Ranked by quantity sold, then value taken<?= $tradingDay ? ' for POS day #' . (int) $tradingDay['id'] : '' ?>.</p>
        </div>
        <form class="hub-local-actions" method="get">
            <label class="visually-hidden" for="date">Report date</label>
            <input class="form-control form-control-sm" id="date" name="date" type="date" value="<?= h($date) ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit">View</button>
        </form>
    </section>

    <section class="card hub-panel table-responsive">
        <table class="table align-middle hub-data-table mb-0">
            <thead><tr><th>Product</th><th>Category</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td class="fw-semibold"><?= h((string) $product['product_name']) ?></td>
                    <td><?= h((string) $product['category_name']) ?></td>
                    <td class="text-end"><?= (int) $product['qty'] ?></td>
                    <td class="text-end fw-bold"><?= gbp((float) $product['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$products): ?><tr><td colspan="4" class="hub-empty-state">No product sales for this date.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<?php require __DIR__ . '/../admin/footer.php'; ?>
