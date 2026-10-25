<?php
/** Route: /shop/shipping-quote?country=GB&postcode=KA21 — JSON delivery options for the current basket. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$country = strtoupper(trim((string) ($_GET['country'] ?? '')));
$postcode = trim((string) ($_GET['postcode'] ?? ''));
$summary = shop_basket_summary(db());

if (!shop_delivery_enabled(db()) || !$summary['items'] || !isset(shop_countries()[$country])) {
    echo json_encode(['ok' => false, 'methods' => [], 'message' => 'Choose a delivery country.']);
    exit;
}

$items = array_map(static fn(array $i): array => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']], $summary['items']);
$quote = shop_shipping_quote(db(), $country, $postcode, $items, (float) $summary['total']);

echo json_encode([
    'ok' => $quote['methods'] !== [],
    'zone' => $quote['zone']['name'] ?? null,
    'methods' => $quote['methods'],
    'message' => $quote['methods'] === [] ? "Sorry, we can't deliver to that address. Please choose collection or contact the club." : '',
]);
exit;
