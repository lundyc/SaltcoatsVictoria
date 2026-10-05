<?php
// Run from CLI only. Temporary tables isolate all test orders and codes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/shop.php';
shop_ensure_schema($pdo);
foreach (['shop_orders', 'shop_order_items', 'shop_discount_codes'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/^  CONSTRAINT .*\n/m', '', $ddl);
    $ddl = preg_replace('/,\n\)/', "\n)", $ddl);
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$customer = ['name' => 'Test customer', 'email' => 'test@example.invalid', 'phone' => '', 'note' => 'Manual order. Payment method: bank transfer.', 'marketing_opt_in' => false];
$lines = [['product_id' => 0, 'product_name' => 'Test item', 'options_label' => 'Size: L', 'options' => [], 'base_price' => 25, 'unit_price' => 25, 'quantity' => 2, 'is_preorder' => false]];
shop_save_discount_code($pdo, null, ['code' => 'TEST10', 'discount_type' => 'percent', 'discount_value' => 10, 'is_active' => 1]);
$order = shop_create_order($pdo, $customer, $lines, ['discount_code' => 'TEST10', 'manual_credit' => '10', 'credit_reason' => 'Previous sale not refunded']);
check((float) $order['subtotal'] === 50.0 && (float) $order['discount_total'] === 15.0 && (float) $order['total'] === 35.0, 'Combined discount and credit total');
check($order['status'] === 'pending_payment' && !$order['paid_at'] && !$order['stripe_checkout_session_id'], 'Order must remain unpaid without Stripe');
check(str_contains($order['customer_note'], 'Previous sale not refunded') && str_contains($order['customer_note'], '£10.00'), 'Credit reason and amount persisted');
check(count(shop_order_items($pdo, (int) $order['id'])) === 1, 'Item saved');
check($order['terms_accepted_at'] === null, 'Do not claim customer accepted online terms');
foreach ([['-1', 'Reason', 50], ['51', 'Reason', 50], ['10', '', 50], ['1.234', 'Reason', 50], ['NaN', 'Reason', 50]] as $case) {
    $rejected = false;
    try { shop_manual_credit(...$case); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Invalid credit must fail');
}
check(shop_manual_credit('50', 'Full credit', 50) === 50.0, 'Full credit allowed');
$normal = shop_create_order($pdo, $customer, $lines);
check((float) $normal['total'] === 50.0 && (float) $normal['discount_total'] === 0.0, 'Ordinary checkout unchanged');
echo "PASS: saved manual order, credit + discount arithmetic, pending status, item persistence, invalid credits, normal checkout. Temporary tables only.\n";
shop_order_event_schema($pdo);
$ddl = $pdo->query('SHOW CREATE TABLE shop_order_events')->fetch(PDO::FETCH_NUM)[1];
$pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
$message = shop_order_email_content($pdo, $order);
check(str_contains($message['html'], 'Amount to pay: £35.00'), 'Pending email shows balance after credit');
check(str_contains($message['html'], 'Payment is still outstanding') && !str_contains($message['html'], 'received your payment in full'), 'Pending email must not claim payment');
$paidCopy = $order; $paidCopy['status'] = 'paid';
$message = shop_order_email_content($pdo, $paidCopy);
check(str_contains($message['html'], 'Total paid: £35.00') && str_contains($message['html'], 'received your payment in full'), 'Paid email retains receipt wording');
shop_add_order_event($pdo, (int) $order['id'], 'Transfer arranged');
shop_update_order_dates($pdo, (int) $order['id'], ['created_at' => '2026-09-01T12:00']);
$updated = shop_get_order($pdo, (int) $order['id']);
check($updated['created_at'] === '2026-09-01 12:00:00' && $updated['status'] === 'pending_payment', 'Timeline date changed without payment');
shop_update_order_dates($pdo, (int) $order['id'], ['paid_at' => '2026-09-01T12:00']);
check(shop_get_order($pdo, (int) $order['id'])['paid_at'] === null, 'Timeline cannot bypass payment action');
$rejected = false;
try { shop_update_order_dates($pdo, (int) $order['id'], ['created_at' => '2026-02-31T12:00']); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Invalid timeline date rejected');
check((int) $pdo->query('SELECT COUNT(*) FROM shop_order_events')->fetchColumn() === 2, 'Note and date edit recorded');
echo "PASS: pending/paid email content, timeline notes, date editing and validation. No emails sent.\n";
