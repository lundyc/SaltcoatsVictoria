<?php
declare(strict_types=1);

// Public, token-gated JSON polling endpoint used by playersponsors_thankyou.php
// to detect once the async Stripe webhook has confirmed payment. Modelled on
// ticket_order_status.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
ensurePlayerSponsorshipShopSchema($pdo);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$accessToken = (string) ($_GET['access'] ?? '');
$order = $accessToken !== '' ? player_sponsorship_shop_get_order_by_access_token($pdo, $accessToken) : null;
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Sponsorship order not found.']);
    exit;
}

$items = player_sponsorship_shop_get_order_items($pdo, (int) $order['id']);
echo json_encode(player_sponsorship_shop_order_status_summary($order, $items));
