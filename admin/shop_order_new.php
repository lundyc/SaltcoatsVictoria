<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';
shop_ensure_schema($pdo);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION['shop_manual_nonce'] ??= bin2hex(random_bytes(24));
$value = static fn(string $key, string $default = ''): string => trim((string) ($_POST[$key] ?? $default));
$catalogue = [];
foreach (shop_get_products($pdo, ['active_only' => true]) as $product) {
    if (!shop_product_is_orderable($pdo, $product)) { continue; }
    $product['groups'] = shop_product_groups($pdo, (int) $product['id']);
    $catalogue[(int) $product['id']] = $product;
}
$rows = is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [['product_id' => '', 'quantity' => 1]];
$error = ''; $preview = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!csrf_check()) { throw new RuntimeException('Session expired — please try again.'); }
        $nonce = $value('nonce');
        if (isset($_SESSION['shop_manual_saved'][$nonce])) {
            header('Location: /admin/shop_order.php?id=' . (int) $_SESSION['shop_manual_saved'][$nonce]); exit;
        }
        if (!hash_equals($_SESSION['shop_manual_nonce'], $nonce)) { throw new RuntimeException('This form has expired. Reload the page.'); }
        if ($value('name') === '' || strlen($value('name')) > 160) { throw new RuntimeException('Enter a customer name (up to 160 characters).'); }
        if (!filter_var($value('email'), FILTER_VALIDATE_EMAIL) || strlen($value('email')) > 190) { throw new RuntimeException('Enter a valid customer email.'); }
        if (strlen($value('phone')) > 40) { throw new RuntimeException('Phone number is too long.'); }
        $lines = []; $quantities = []; $optionQuantities = []; $subtotal = 0;
        if (count($rows) > 50) { throw new RuntimeException('Use no more than 50 order lines.'); }
        foreach ($rows as $row) {
            $product = $catalogue[(int) ($row['product_id'] ?? 0)] ?? null;
            if (!$product) { throw new RuntimeException('Choose an available product for every line.'); }
            $qty = filter_var($row['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
            if ($qty === false) { throw new RuntimeException('Enter a whole quantity between 1 and 999.'); }
            $pid = (int) $product['id'];
            $quantities[$pid] = ($quantities[$pid] ?? 0) + $qty;
            if ($quantities[$pid] > (int) $product['max_per_order'] || ($product['stock_qty'] !== null && $quantities[$pid] > (int) $product['stock_qty'])) {
                throw new RuntimeException('Quantity exceeds the order limit or available stock for ' . $product['name'] . '.');
            }
            $resolved = shop_resolve_selection($pdo, $product, is_array($row['options'] ?? null) ? $row['options'] : []);
            foreach ($resolved['options'] as $chosen) {
                $oid = (int) $chosen['option_id'];
                $optionQuantities[$oid] = ($optionQuantities[$oid] ?? 0) + $qty;
                foreach ($product['groups'] as $group) { foreach ($group['options'] as $option) {
                    if ((int) $option['id'] === $oid && $option['stock_qty'] !== null && $optionQuantities[$oid] > (int) $option['stock_qty']) {
                        throw new RuntimeException('Not enough stock for ' . $option['label'] . '.');
                    }
                } }
            }
            $lines[] = array_merge($resolved, ['product_id' => $pid, 'product_name' => $product['name'], 'quantity' => $qty, 'is_preorder' => (bool) $product['is_preorder']]);
            $subtotal += round($resolved['unit_price'] * $qty, 2);
        }
        if (!$lines) { throw new RuntimeException('Add at least one item.'); }
        $subtotal = round($subtotal, 2);
        $discount = shop_validate_discount($pdo, $value('discount_code'), $subtotal);
        if ($value('discount_code') !== '' && !$discount['ok']) { throw new RuntimeException($discount['error']); }
        $credit = shop_manual_credit($value('manual_credit', '0'), $value('credit_reason'), round($subtotal - $discount['amount'], 2));
        $delivery = $value('fulfilment_method') === 'delivery' && shop_delivery_enabled($pdo);
        if ($delivery && $value('delivery_address') === '') { throw new RuntimeException('Enter the delivery address.'); }
        $fee = $delivery ? shop_delivery_fee($pdo) : 0;
        $preview = ['lines' => $lines, 'subtotal' => $subtotal, 'discount' => $discount['amount'], 'credit' => $credit, 'fee' => $fee, 'total' => round($subtotal - $discount['amount'] - $credit + $fee, 2)];
        if ($value('action') === 'create') {
            $payment = $value('payment_method', 'bank_transfer');
            if (!in_array($payment, ['bank_transfer', 'cash', 'other'], true)) { throw new RuntimeException('Choose a payment method.'); }
            $order = shop_create_order($pdo, ['name' => $value('name'), 'email' => $value('email'), 'phone' => $value('phone'),
                'note' => 'Manual order. Payment method: ' . str_replace('_', ' ', $payment) . ".\n" . $value('note'), 'marketing_opt_in' => false], $lines,
                ['discount_code' => $value('discount_code'), 'manual_credit' => $value('manual_credit', '0'), 'credit_reason' => $value('credit_reason'),
                 'fulfilment_method' => $delivery ? 'delivery' : 'collection', 'delivery_address' => $value('delivery_address'),
                 'requested_date' => $value('requested_date')]);
            $_SESSION['shop_manual_saved'][$nonce] = (int) $order['id'];
            $_SESSION['shop_manual_saved'] = array_slice($_SESSION['shop_manual_saved'], -20, null, true);
            $_SESSION['shop_manual_nonce'] = bin2hex(random_bytes(24));
            try { auditLog($pdo, 'shop_order_created_manually', 'Shop order #' . $order['id'] . ' created; credit ' . gbp($credit)); }
            catch (Throwable $e) { error_log('[shop manual audit] ' . $e->getMessage()); }
            header('Location: /admin/shop_order.php?id=' . (int) $order['id'] . '&m=' . rawurlencode('Manual order recorded. Mark it paid when payment has been received.')); exit;
        }
    } catch (Throwable $e) { $error = $e instanceof PDOException ? 'The order could not be saved. Please try again.' : $e->getMessage(); }
}
$pageHero = ['eyebrow' => 'Shop', 'title' => 'Add manual order', 'subtitle' => 'Record an offline order and any credit owed to the customer.'];
require __DIR__ . '/header.php';
?>
<div class="shop-admin-page">
<a href="/admin/shop_orders.php">← All orders</a>
<?php if ($error): ?><div class="alert alert-danger mt-3"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="card hub-panel p-3 mt-3" id="manual-order">
<?= csrf_field() ?><input type="hidden" name="nonce" value="<?= h($_SESSION['shop_manual_nonce']) ?>">
<div class="row g-3">
<?php foreach (['name' => 'Customer name', 'email' => 'Email', 'phone' => 'Phone (optional)'] as $key => $label): ?>
<label class="col-md-4"><?= h($label) ?><input class="form-control" name="<?= $key ?>" type="<?= $key === 'email' ? 'email' : 'text' ?>" value="<?= h($value($key)) ?>" <?= $key !== 'phone' ? 'required' : '' ?>></label>
<?php endforeach; ?>
</div>
<h2 class="h5 mt-4">Items</h2><div id="order-items"></div>
<button class="btn btn-outline-secondary align-self-start" type="button" id="add-item">Add another item</button>
<div class="row g-3 mt-2">
<label class="col-md-4">Payment method<select class="form-select" name="payment_method"><?php foreach (['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'] as $key => $label): ?><option value="<?= $key ?>" <?= $value('payment_method', 'bank_transfer') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label class="col-md-4">Discount code (optional)<input class="form-control" name="discount_code" maxlength="40" value="<?= h($value('discount_code')) ?>"><a href="/admin/shop_discount_codes.php" target="_blank" rel="noopener">Manage discount codes</a></label>
<label class="col-md-4">Credit / adjustment (£)<input class="form-control" type="number" min="0" step="0.01" name="manual_credit" value="<?= h($value('manual_credit', '0')) ?>" required></label>
<label class="col-12">Credit reason<input class="form-control" name="credit_reason" value="<?= h($value('credit_reason')) ?>" placeholder="e.g. £10 owed from a previous sale"><span class="form-text">Deducted after any discount code. This records the credit on this order; it does not change the previous sale.</span></label>
<label class="col-md-4">Fulfilment<select class="form-select" name="fulfilment_method"><option value="collection">Collection</option><?php if (shop_delivery_enabled($pdo)): ?><option value="delivery" <?= $value('fulfilment_method') === 'delivery' ? 'selected' : '' ?>>Delivery (<?= gbp(shop_delivery_fee($pdo)) ?>)</option><?php endif; ?></select></label>
<label class="col-md-8">Delivery address (if delivering)<textarea class="form-control" name="delivery_address"><?= h($value('delivery_address')) ?></textarea></label>
<?php if (shop_scheduling_enabled($pdo)): ?>
<label class="col-md-4">Requested date <span class="text-muted small">(optional)</span><input class="form-control" type="date" name="requested_date" value="<?= h($value('requested_date')) ?>"><span class="form-text">Not restricted to the storefront's available dates — set whatever was agreed with the customer.</span></label>
<?php endif; ?>
<label class="col-12">Order note<textarea class="form-control" name="note"><?= h($value('note')) ?></textarea></label>
</div>
<p class="text-muted mt-3">Orders are saved as pending payment, with stock reserved immediately. Open the order and mark it paid once the bank transfer or cash is received.</p>
<?php if ($preview && !$error): ?>
<section class="alert alert-info mt-3" id="order-preview"><h2 class="h5">Review order</h2>
<?php foreach ($preview['lines'] as $line): ?><div><?= h($line['product_name'] . ' — ' . $line['options_label']) ?> × <?= (int) $line['quantity'] ?>: <?= gbp($line['unit_price'] * $line['quantity']) ?></div><?php endforeach; ?>
<hr><?php foreach (['subtotal' => 'Subtotal', 'discount' => 'Discount code deduction', 'credit' => 'Credit deduction', 'fee' => 'Delivery', 'total' => 'Amount to pay'] as $key => $label): ?><div><strong><?= $label ?>:</strong> <?= gbp($preview[$key]) ?></div><?php endforeach; ?>
<button class="btn btn-success mt-3" name="action" value="create">Create pending order</button></section>
<?php endif; ?>
<button class="btn btn-dark align-self-start mt-3" name="action" value="preview">Review total</button>
</form></div>
<script>
(() => {
const catalogue = <?= json_encode($catalogue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const initial = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const container = document.getElementById('order-items'); let index = 0;
function invalidate() { document.getElementById('order-preview')?.remove(); }
function add(row = {}) {
 const i = index++, box = document.createElement('div'); box.className = 'border rounded p-3 mb-3';
 const label = document.createElement('label'); label.className = 'd-block'; label.textContent = 'Product';
 const select = document.createElement('select'); select.className = 'form-select'; select.name = `items[${i}][product_id]`; select.required = true;
 select.add(new Option('Choose product', ''));
 Object.values(catalogue).forEach(p => select.add(new Option(`${p.name} — £${Number(p.price).toFixed(2)}`, p.id)));
 select.value = row.product_id || ''; label.append(select); box.append(label);
 const qtyLabel = document.createElement('label'); qtyLabel.textContent = 'Quantity'; qtyLabel.className = 'mt-2';
 const qty = document.createElement('input'); Object.assign(qty, {type:'number', min:'1', max:'999', required:true, name:`items[${i}][quantity]`, value:row.quantity || 1, className:'form-control'}); qtyLabel.append(qty); box.append(qtyLabel);
 const options = document.createElement('div'); box.append(options);
 function render(saved = {}) {
  options.replaceChildren();
  (catalogue[select.value]?.groups || []).forEach(g => {
   const field = document.createElement('fieldset'); field.className = 'mt-2';
   const legend = document.createElement('legend'); legend.className = 'fs-6'; legend.textContent = g.name + (Number(g.min_select) > 0 ? ' (required)' : ' (optional)'); field.append(legend);
   const chosen = [].concat(saved[g.id] || []).map(String);
   g.options.forEach(o => {
    const l = document.createElement('label'); l.className = 'me-3 mb-2';
    const input = document.createElement('input'); input.type = g.selection_type === 'single' ? 'radio' : 'checkbox'; input.name = `items[${i}][options][${g.id}][]`; input.value = o.id; input.checked = chosen.includes(String(o.id));
    l.append(input, document.createTextNode(' ' + [o.option_group, o.label].filter(Boolean).join(' ') + (Number(o.price_delta) ? ` (${Number(o.price_delta) >= 0 ? '+' : ''}£${Number(o.price_delta).toFixed(2)})` : ''))); field.append(l);
   });
   if (g.selection_type === 'single' && Number(g.min_select) === 0) {
    const l = document.createElement('label'), input = document.createElement('input'); input.type = 'radio'; input.name = `items[${i}][options][${g.id}][]`; input.value = ''; input.checked = !chosen.some(Boolean); l.append(input, ' None'); field.append(l);
   }
   options.append(field);
  });
 }
 select.addEventListener('change', () => render()); render(row.options || {});
 const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger mt-2'; remove.textContent = 'Remove item'; remove.onclick = () => { box.remove(); invalidate(); }; box.append(remove); container.append(box);
}
initial.forEach(add);
document.getElementById('add-item').onclick = () => { add(); invalidate(); };
document.getElementById('manual-order').addEventListener('input', invalidate);
document.getElementById('manual-order').addEventListener('change', invalidate);
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
