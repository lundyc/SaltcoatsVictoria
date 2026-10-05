<?php
declare(strict_types=1);

// Combined VSN kit order form — every paid pre-order not yet flagged sent to
// VSN, as one PDF: a manufacturing summary (product/size -> quantity) plus
// per-customer detail. Reached only from shop_orders.php's "Download VSN
// order form" button.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');

shop_ensure_schema($pdo);

$orders = shop_orders_pending_vsn($pdo);
if ($orders === []) {
    http_response_code(404);
    exit('No orders are currently awaiting a VSN order.');
}

shop_vsn_order_form_pdf_render($pdo, $orders, isset($_GET['download']));
exit;
