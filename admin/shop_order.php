<?php

declare(strict_types=1);

// Club Shop — single order detail and fulfilment actions. Requires Shop access.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/invoices.php';

shop_ensure_schema($pdo);
invoices_ensure_schema($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = '/admin/shop_order.php?id=' . $id;
    if (!csrf_check()) {
        header('Location: ' . $back . '&e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!shop_get_order($pdo, $id)) { throw new RuntimeException('Order not found.'); }
        if ($action === 'add_note') {
            shop_add_order_event($pdo, $id, (string) ($_POST['timeline_note'] ?? ''));
            auditLog($pdo, 'shop_order_timeline_note', 'Timeline note added to shop order #' . $id);
            $m = 'Timeline note added.';
        } elseif ($action === 'update_dates') {
            shop_update_order_dates($pdo, $id, $_POST);
            auditLog($pdo, 'shop_order_timeline_updated', 'Timeline dates updated for shop order #' . $id);
            $m = 'Timeline updated.';
        } elseif ($action === 'mark_paid') {
            shop_mark_order_paid($pdo, $id, null);
            auditLog($pdo, 'shop_order_marked_paid', 'Shop order #' . $id . ' marked paid manually');
            $m = 'Order marked as paid.';
        } elseif ($action === 'mark_collected') {
            shop_mark_collected($pdo, $id);
            auditLog($pdo, 'shop_order_collected', 'Shop order #' . $id . ' marked collected');
            $m = 'Marked as collected.';
        } elseif ($action === 'mark_vsn_ordered') {
            shop_mark_vsn_ordered($pdo, $id);
            auditLog($pdo, 'shop_order_vsn_ordered', 'Shop order #' . $id . ' flagged as sent to VSN');
            $m = 'Flagged as sent to VSN.';
        } elseif ($action === 'resend_email') {
            $ok = shop_send_confirmation_email($pdo, $id, true);
            if (!$ok) { throw new RuntimeException('Could not send the email. Please check the customer email address and mail settings.'); }
            $m = 'Order email sent to the customer.';
        } elseif ($action === 'cancel') {
            shop_cancel_order($pdo, $id, trim((string) ($_POST['reason'] ?? 'Cancelled by admin')));
            auditLog($pdo, 'shop_order_cancelled', 'Shop order #' . $id . ' cancelled');
            $m = 'Order cancelled.';
        } elseif ($action === 'refund') {
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            shop_refund_order($pdo, $id, $amount, (string) ($_POST['reason'] ?? ''));
            auditLog($pdo, 'shop_order_refunded', 'Shop order #' . $id . ' refunded ' . gbp($amount));
            $m = 'Refund of ' . gbp($amount) . ' processed.';
        } elseif ($action === 'generate_invoice') {
            $currentAdmin = hub_auth_current_user();
            $currentAdminLabel = (string) ($currentAdmin['display_name'] ?? $currentAdmin['username'] ?? $currentAdmin['email'] ?? '');
            $result = invoices_generate_for_shop_order($pdo, $id, $currentAdminLabel);
            if ($result['errors']) { throw new RuntimeException(implode(' ', $result['errors'])); }
            auditLog($pdo, 'invoice_generated', 'Generated invoice for shop order #' . $id);
            $m = 'Invoice generated.';
        } else {
            $m = '';
        }
        header('Location: ' . $back . ($m !== '' ? '&m=' . rawurlencode($m) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $back . '&e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Order',
    'subtitle' => 'Order detail and fulfilment.',
    'actions' => [['label' => 'All orders', 'href' => '/shop_orders.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';


$order = shop_get_order($pdo, $id);
if (!$order) {
    echo '<div class="alert alert-danger">Order not found.</div>';
    require __DIR__ . '/footer.php';
    exit;
}
$items = shop_order_items($pdo, $id);
$settings = shop_get_settings($pdo);
shop_order_event_schema($pdo);
$eventQuery = $pdo->prepare('SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY id DESC');
$eventQuery->execute([$id]);
$events = $eventQuery->fetchAll(PDO::FETCH_ASSOC);
$lastEmailAt = $order['confirmation_email_sent_at'];
foreach ($events as $event) {
    if (in_array($event['note'], ['Order summary emailed to customer (awaiting payment).', 'Paid order confirmation emailed to customer.'], true)) {
        $lastEmailAt = $event['created_at'];
        break;
    }
}
$remainingRefund = round((float) $order['total'] - (float) $order['amount_refunded'], 2);
$fmt = static fn($dt) => $dt ? (new DateTimeImmutable((string) $dt))->format('d/m/Y H:i') : '—';

function shop_status_badge(string $status): string
{
    $map = ['pending_payment' => 'secondary', 'paid' => 'success', 'collected' => 'primary', 'cancelled' => 'dark', 'refunded' => 'warning'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . h(ucwords(str_replace('_', ' ', $status))) . '</span>';
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_orders.php">Orders</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= h((string) $order['order_ref']) ?></span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="d-flex align-items-center gap-3 mb-3">
        <h1 class="h4 fw-bold mb-0"><?= h((string) $order['order_ref']) ?></h1>
        <?= shop_status_badge((string) $order['status']) ?>
        <?php if ((int) $order['is_preorder'] === 1): ?><span class="badge text-bg-warning">Pre-order</span><?php endif; ?>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card hub-panel p-3 mb-3">
                <h2 class="h6 fw-bold text-uppercase text-muted">Items</h2>
                <div class="table-responsive">
                <table class="table align-middle mb-0 hub-data-table">
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= h((string) $it['product_name']) ?></div>
                                <?php if (($it['options_label'] ?? '') !== ''): ?><div class="small text-muted"><?= h((string) $it['options_label']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= gbp((float) $it['unit_price']) ?> × <?= (int) $it['quantity'] ?></td>
                            <td class="text-end fw-bold"><?= gbp((float) $it['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="2" class="text-end">Subtotal</td><td class="text-end"><?= gbp((float) $order['subtotal']) ?></td></tr>
                        <?php if ((float) $order['discount_total'] > 0): ?>
                            <tr><td colspan="2" class="text-end">Discount / credit <?= h((string) $order['discount_code']) ?></td><td class="text-end">−<?= gbp((float) $order['discount_total']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="fw-bold"><td colspan="2" class="text-end">Total</td><td class="text-end"><?= gbp((float) $order['total']) ?></td></tr>
                        <?php if ((float) $order['amount_refunded'] > 0): ?>
                            <tr class="text-warning"><td colspan="2" class="text-end">Refunded</td><td class="text-end">−<?= gbp((float) $order['amount_refunded']) ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                </div>
            </section>

            <section class="card hub-panel p-3">
                <h2 class="h6 fw-bold text-uppercase text-muted">Timeline</h2>
                <div class="row">
                    <div class="col-sm-6"><dl class="row mb-0 small">
                        <dt class="col-6 text-muted">Placed</dt><dd class="col-6"><?= h($fmt($order['created_at'])) ?></dd>
                        <dt class="col-6 text-muted">Paid</dt><dd class="col-6"><?= h($fmt($order['paid_at'])) ?></dd>
                        <dt class="col-6 text-muted">Sent to VSN</dt><dd class="col-6"><?= h($fmt($order['vsn_ordered_at'])) ?></dd>
                    </dl></div>
                    <div class="col-sm-6"><dl class="row mb-0 small">
                        <dt class="col-6 text-muted">Collected</dt><dd class="col-6"><?= h($fmt($order['collected_at'])) ?></dd>
                        <dt class="col-6 text-muted">Cancelled</dt><dd class="col-6"><?= h($fmt($order['cancelled_at'])) ?></dd>
                        <dt class="col-6 text-muted">Email sent</dt><dd class="col-6"><?= h($fmt($lastEmailAt)) ?></dd>
                    </dl></div>
                </div>
                <hr>
                <details class="mb-3"><summary>Edit timeline dates</summary>
                    <p class="small text-muted mt-2">Correct recorded dates below. Use the order actions to record payment, collection or sending to VSN.</p>
                    <form method="post" class="row g-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="update_dates">
                        <?php foreach (['created_at' => 'Placed', 'paid_at' => 'Paid', 'vsn_ordered_at' => 'Sent to VSN', 'collected_at' => 'Collected', 'cancelled_at' => 'Cancelled'] as $field => $label): ?>
                            <?php if (!empty($order[$field])): ?><label class="col-sm-6"><?= h($label) ?><input class="form-control" type="datetime-local" name="<?= h($field) ?>" value="<?= h((new DateTimeImmutable($order[$field]))->format('Y-m-d\TH:i')) ?>" required></label><?php endif; ?>
                        <?php endforeach; ?>
                        <div class="col-12"><button class="btn btn-outline-secondary">Save timeline dates</button></div>
                    </form>
                </details>
                <form method="post" class="mb-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_note">
                    <label class="form-label" for="timeline-note">Add internal timeline note</label>
                    <textarea class="form-control mb-2" id="timeline-note" name="timeline_note" rows="2" maxlength="4000" required placeholder="e.g. Bank transfer arranged; awaiting receipt"></textarea>
                    <button class="btn btn-outline-secondary">Add timeline note</button>
                </form>
                <?php foreach ($events as $event): ?>
                    <div class="border-top py-2 small"><strong><?= h($fmt($event['created_at'])) ?></strong><div style="white-space:pre-line"><?= h($event['note']) ?></div></div>
                <?php endforeach; ?>
                <?php if (($order['customer_note'] ?? '') !== ''): ?>
                    <hr><div class="small"><strong>Note:</strong> <span style="white-space:pre-line"><?= h((string) $order['customer_note']) ?></span></div>
                <?php endif; ?>
                <hr>
                <div class="small text-muted">
                    Stripe session: <code><?= h((string) ($order['stripe_checkout_session_id'] ?: '—')) ?></code><br>
                    Payment intent: <code><?= h((string) ($order['stripe_payment_intent_id'] ?: '—')) ?></code>
                    <?php if (!empty($order['stripe_refund_id'])): ?><br>Refund: <code><?= h((string) $order['stripe_refund_id']) ?></code><?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="card hub-panel p-3 mb-3">
                <h2 class="h6 fw-bold text-uppercase text-muted">Customer</h2>
                <p class="mb-1 fw-bold"><?= h((string) $order['customer_name']) ?></p>
                <p class="mb-1"><a href="mailto:<?= h((string) $order['customer_email']) ?>"><?= h((string) $order['customer_email']) ?></a></p>
                <p class="mb-1"><?= h((string) $order['customer_phone']) ?></p>
                <p class="mb-0 small text-muted">Marketing opt-in: <?= (int) $order['marketing_opt_in'] === 1 ? 'yes' : 'no' ?></p>
                <hr>
                <?php if ((string) ($order['fulfilment_method'] ?? 'collection') === 'delivery'): ?>
                    <p class="mb-1 small"><strong>Fulfilment:</strong> <span class="badge bg-info text-dark">Delivery</span> — fee charged: £<?= h(number_format((float) ($order['delivery_fee'] ?? 0), 2)) ?></p>
                    <p class="mb-0 small"><strong>Delivery address:</strong><br><span style="white-space:pre-line"><?= h((string) ($order['delivery_address'] ?? '')) ?></span></p>
                <?php else: ?>
                    <p class="mb-1 small"><strong>Fulfilment:</strong> Collection only</p>
                    <p class="mb-0 small"><strong>Collection point:</strong> <?= h((string) $order['collection_point']) ?></p>
                <?php endif; ?>
                <?php if (!empty($order['requested_date'])): ?>
                    <p class="mb-0 small mt-1"><strong><?= (string) ($order['fulfilment_method'] ?? 'collection') === 'delivery' ? 'Requested delivery date' : 'Requested collection date' ?>:</strong> <?= h((new DateTimeImmutable((string) $order['requested_date']))->format('l j F Y')) ?></p>
                <?php endif; ?>
                <?php if (($order['batch_note'] ?? '') !== ''): ?><p class="mb-0 small text-muted mt-1"><?= h((string) $order['batch_note']) ?></p><?php endif; ?>
                <p class="mt-2 mb-0 small"><a href="/shop/order/<?= h((string) $order['access_token']) ?>" target="_blank" rel="noopener">Customer confirmation page ↗</a></p>
            </section>

            <section class="card hub-panel p-3 mb-3">
                <h2 class="h6 fw-bold text-uppercase text-muted">Actions</h2>
                <div class="d-grid gap-2">
                    <?php if ((string) $order['status'] === 'pending_payment'): ?>
                        <form method="post" data-confirm="Mark this order as paid without taking a Stripe payment?" data-confirm-action="Mark paid" data-confirm-class="btn-primary">
                            <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-success w-100" type="submit">Mark as paid (manual)</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-outline-dark w-100" type="submit">Cancel order</button>
                        </form>
                    <?php endif; ?>

                    <?php if ((string) $order['status'] === 'paid'): ?>
                        <form method="post">
                            <?= csrf_field() ?><input type="hidden" name="action" value="mark_collected"><input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-primary w-100" type="submit">Mark as collected</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array((string) $order['status'], ['pending_payment', 'paid', 'collected'], true)): ?>
                        <?php if (empty($order['vsn_ordered_at'])): ?>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="mark_vsn_ordered"><input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn btn-outline-secondary w-100" type="submit">Flag as sent to VSN</button>
                            </form>
                        <?php endif; ?>
                        <form method="post">
                            <?= csrf_field() ?><input type="hidden" name="action" value="resend_email"><input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-outline-secondary w-100" type="submit"><?= $order['status'] === 'pending_payment' ? 'Email order summary (awaiting payment)' : 'Email payment confirmation' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card hub-panel p-3 mb-3">
                <?= invoices_render_admin_section(invoices_list_for_source($pdo, 'shop_order', $id), '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="generate_invoice"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
            </section>

            <?php if (in_array((string) $order['status'], ['paid', 'collected'], true) && !empty($order['stripe_payment_intent_id']) && $remainingRefund > 0): ?>
                <section class="card hub-panel p-3 border-danger-subtle">
                    <h2 class="h6 fw-bold text-uppercase text-danger">Refund</h2>
                    <form method="post" data-confirm="Process this refund via Stripe?" data-confirm-action="Process refund" class="row g-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= $id ?>">
                        <div class="col-7"><label class="form-label">Amount (£)</label>
                            <input class="form-control" type="number" step="0.01" min="0.01" max="<?= h((string) $remainingRefund) ?>" name="amount" value="<?= h((string) $remainingRefund) ?>" required></div>
                        <div class="col-5"><label class="form-label">Reason</label>
                            <select class="form-select" name="reason">
                                <option value="requested_by_customer">Customer request</option>
                                <option value="duplicate">Duplicate</option>
                                <option value="fraudulent">Fraudulent</option>
                                <option value="">Other</option>
                            </select></div>
                        <div class="col-12"><button class="btn btn-outline-danger w-100" type="submit">Refund up to <?= gbp($remainingRefund) ?></button></div>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
