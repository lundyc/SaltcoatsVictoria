<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';
require_once __DIR__ . '/../admin/lib/invoices.php';

pos_ensure_schema($pdo);
pos_controls_ensure_schema($pdo);
invoices_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$saleId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'refund_sale') {
                pos_refund_sale($pdo, $saleId, $actor, (string) ($_POST['refund_reason'] ?? ''), (float) ($_POST['refund_amount'] ?? 0));
                $notice = 'Refund transaction recorded.';
            } elseif ($action === 'refund_items') {
                pos_refund_sale_items($pdo, $saleId, $actor, (string) ($_POST['refund_reason'] ?? ''), (array) ($_POST['refund_qty'] ?? []));
                $notice = 'Item refund transaction recorded.';
            } elseif ($action === 'complete_card_pending') {
                pos_complete_pending_card_sale($pdo, $saleId, $actor, (string) ($_POST['payment_reference'] ?? ''));
                pos_audit_event($pdo, 'pos_sale_completed', 'Completed pending card POS sale #' . $saleId);
                $notice = 'Pending card sale completed.';
            } elseif ($action === 'complete_cash_pending') {
                pos_complete_pending_cash_sale($pdo, $saleId, $actor);
                pos_audit_event($pdo, 'pos_sale_completed', 'Completed pending cash POS sale #' . $saleId);
                $notice = 'Pending cash sale completed.';
            } elseif ($action === 'cancel_card_pending') {
                pos_cancel_pending_card_sale($pdo, $saleId, $actor);
                pos_audit_event($pdo, 'pos_sale_cancelled', 'Cancelled pending card POS sale #' . $saleId);
                $notice = 'Pending card sale cancelled.';
            } elseif ($action === 'cancel_cash_pending') {
                pos_cancel_pending_cash_sale($pdo, $saleId, $actor);
                pos_audit_event($pdo, 'pos_sale_cancelled', 'Cancelled pending cash POS sale #' . $saleId);
                $notice = 'Pending cash sale cancelled.';
            } elseif ($action === 'generate_invoice') {
                $result = invoices_generate_for_pos_sale($pdo, $saleId, (string) ($actor['name'] ?? ''));
                if ($result['errors']) {
                    throw new RuntimeException(implode(' ', $result['errors']));
                }
                pos_audit_event($pdo, 'invoice_generated', 'Generated invoice for POS sale #' . $saleId);
                $notice = 'Invoice generated.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

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
    exit('POS sale was not found.');
}

$itemStmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = :sale ORDER BY id');
$itemStmt->execute([':sale' => $saleId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
$status = (string) $sale['status'];
$refundableItems = in_array($status, ['complete', 'refunded'], true) ? pos_refundable_items($pdo, $saleId) : [];
$statusClass = match ($status) {
    'complete' => 'text-bg-success',
    'pending_card', 'pending_cash' => 'text-bg-warning',
    'cancelled_card', 'cancelled_cash' => 'text-bg-secondary',
    'refunded', 'refund' => 'text-bg-danger',
    default => 'text-bg-light',
};
$paymentLabel = match ((string) $sale['payment_method']) {
    'cash' => 'Cash',
    'card', 'card_stripe_app' => 'Card',
    default => ucwords(str_replace('_', ' ', (string) $sale['payment_method'])),
};

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Transaction',
    'subtitle' => (string) $sale['sale_ref'],
];
require_once __DIR__ . '/../admin/header.php';
?>

<div class="pos-sale-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string) $sale['sale_ref']) ?></span></nav>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="hub-section-commandbar" aria-labelledby="saleActionsTitle">
        <div>
            <h2 id="saleActionsTitle">Transaction actions</h2>
            <p><?= h(date('d/m/Y H:i', strtotime((string) $sale['created_at']))) ?> · <?= h((string) $sale['location_name']) ?> · <?= h($paymentLabel) ?></p>
        </div>
        <div class="hub-local-actions">
            <?php if ($status === 'complete'): ?>
                <a class="btn btn-sm btn-outline-secondary" href="/pos/receipt.php?id=<?= (int) $saleId ?>"><i class="fa-solid fa-print" aria-hidden="true"></i>Receipt</a>
            <?php elseif ($status === 'pending_card'): ?>
                <form method="post" class="d-flex gap-2 align-items-end flex-wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $saleId ?>"><input type="hidden" name="action" value="complete_card_pending"><div><label class="form-label small fw-bold" for="payment_reference">Stripe reference</label><input class="form-control form-control-sm" id="payment_reference" name="payment_reference" maxlength="120" required></div><button class="btn btn-sm btn-brand" type="submit">Complete card</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $saleId ?>"><input type="hidden" name="action" value="cancel_card_pending"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel card</button></form>
            <?php elseif ($status === 'pending_cash'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $saleId ?>"><input type="hidden" name="action" value="complete_cash_pending"><button class="btn btn-sm btn-brand" type="submit">Complete cash</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $saleId ?>"><input type="hidden" name="action" value="cancel_cash_pending"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel cash</button></form>
            <?php endif; ?>
            <?php if ($status === 'refund'): ?><a class="btn btn-sm btn-outline-secondary" href="/pos/receipt.php?id=<?= (int) $saleId ?>"><i class="fa-solid fa-print" aria-hidden="true"></i>Refund receipt</a><?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/pos_overview.php">Back to overview</a>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-4">
            <section class="card hub-panel h-100">
                <div class="card-body">
                    <p class="page-kicker mb-1">Summary</p>
                    <h2 class="h5 mb-3"><?= h((string) $sale['sale_ref']) ?></h2>
                    <dl class="row mb-0">
                        <dt class="col-5">Status</dt><dd class="col-7"><span class="badge hub-status <?= h($statusClass) ?>"><?= h(ucfirst(str_replace('_', ' ', $status))) ?></span></dd>
                        <dt class="col-5">Total</dt><dd class="col-7 fw-bold"><?= gbp((float) $sale['total']) ?></dd>
                        <dt class="col-5">Subtotal</dt><dd class="col-7"><?= gbp((float) $sale['subtotal']) ?></dd>
                        <dt class="col-5">Discount</dt><dd class="col-7"><?= gbp((float) $sale['discount_total'] + (float) $sale['points_discount']) ?></dd>
                        <dt class="col-5">Operator</dt><dd class="col-7"><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></dd>
                        <dt class="col-5">Member</dt><dd class="col-7"><?= h((string) ($sale['holder_name'] ?: '-')) ?></dd>
                        <dt class="col-5">Receipt</dt><dd class="col-7"><?= h((string) ($sale['receipt_number'] ?: '-')) ?></dd>
                        <dt class="col-5">Payment ref</dt><dd class="col-7"><?= h((string) ($sale['payment_reference'] ?: '-')) ?></dd>
                    </dl>
                </div>
            </section>
            <section class="card hub-panel mt-3">
                <div class="card-body">
                    <?= invoices_render_admin_section(invoices_list_for_source($pdo, 'pos_sale', $saleId), '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $saleId . '"><input type="hidden" name="action" value="generate_invoice"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
                </div>
            </section>
        </div>
        <div class="col-lg-8">
            <section class="card hub-panel table-responsive h-100">
                <table class="table align-middle hub-data-table mb-0">
                    <thead><tr><th>Item</th><th>Category</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Discount</th><th class="text-end">Line total</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="fw-semibold"><?= h((string) $item['product_name']) ?></td>
                            <td><?= h((string) $item['category_name']) ?></td>
                            <td class="text-end"><?= h(number_format((float) $item['qty'], 2)) ?></td>
                            <td class="text-end"><?= gbp((float) $item['unit_price']) ?></td>
                            <td class="text-end"><?= gbp((float) $item['discount_amount']) ?></td>
                            <td class="text-end fw-bold"><?= gbp((float) $item['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
    <?php if ($refundableItems && $status === 'complete'): ?>
        <section class="card hub-panel p-3 mt-3">
            <h2 class="h5 fw-bold">Refund items</h2>
            <form method="post" class="row g-3" onsubmit="return confirm('Record this item refund?');">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $saleId ?>"><input type="hidden" name="action" value="refund_items">
                <div class="col-12 table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Refundable</th><th class="text-end">Refund qty</th></tr></thead>
                        <tbody>
                        <?php foreach ($refundableItems as $item): ?>
                            <?php if ((float) $item['refundable_qty'] <= 0) { continue; } ?>
                            <tr>
                                <td class="fw-semibold"><?= h((string) $item['product_name']) ?><br><small class="text-muted"><?= gbp((float) $item['line_total']) ?></small></td>
                                <td class="text-end"><?= h(number_format((float) $item['qty'], 2)) ?></td>
                                <td class="text-end"><?= h(number_format((float) $item['refundable_qty'], 2)) ?></td>
                                <td class="text-end"><input class="form-control form-control-sm ms-auto" style="max-width:120px" name="refund_qty[<?= (int) $item['id'] ?>]" type="number" min="0" max="<?= h((string) $item['refundable_qty']) ?>" step="0.01" value="0"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-8"><label class="form-label fw-bold" for="refund_reason">Refund reason</label><input class="form-control" id="refund_reason" name="refund_reason" maxlength="255" required></div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-danger w-100" type="submit"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Refund selected items</button></div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../admin/footer.php'; ?>
