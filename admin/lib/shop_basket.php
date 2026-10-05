<?php

declare(strict_types=1);

// Club Shop basket — plain PHP session storage (the default session that
// csrf_field()/csrf_check() already use), since the shop is guest-checkout
// and nobody is identified before payment. Mirrors
// lib/season_ticket_basket.php.

require_once __DIR__ . '/shop.php';

const SHOP_BASKET_SESSION_KEY = 'shop_basket';
const SHOP_BASKET_DISCOUNT_KEY = 'shop_basket_discount';

function shop_basket_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION[SHOP_BASKET_SESSION_KEY]) || !is_array($_SESSION[SHOP_BASKET_SESSION_KEY])) {
        $_SESSION[SHOP_BASKET_SESSION_KEY] = [];
    }
}

/** @return list<array<string,mixed>> raw stored lines */
function shop_basket_lines(): array
{
    shop_basket_start();
    return array_values($_SESSION[SHOP_BASKET_SESSION_KEY]);
}

function shop_basket_count(): int
{
    $n = 0;
    foreach (shop_basket_lines() as $line) {
        $n += (int) ($line['quantity'] ?? 0);
    }
    return $n;
}

/**
 * Stable identity for "same product + same options" so re-adding stacks
 * quantity instead of duplicating a line.
 *
 * @param list<int> $optionIds
 */
function shop_basket_line_key(int $productId, array $optionIds): string
{
    sort($optionIds);
    return $productId . ':' . implode('-', $optionIds);
}

/**
 * Add a validated selection to the basket.
 *
 * @param array<int,int|array<int>> $selected  group_id => option_id(s)
 * @throws RuntimeException on any invalid selection / unavailable product
 */
function shop_basket_add(PDO $pdo, int $productId, array $selected, int $quantity): void
{
    shop_basket_start();
    $product = shop_get_product($pdo, $productId);
    if (!$product) {
        throw new RuntimeException('That product is no longer available.');
    }
    if (!shop_product_is_orderable($pdo, $product)) {
        throw new RuntimeException('Sorry — ' . $product['name'] . ' can no longer be ordered.');
    }

    $resolved = shop_resolve_selection($pdo, $product, $selected);
    $quantity = max(1, min((int) $product['max_per_order'], $quantity));

    $optionIds = array_map(static fn($o) => (int) $o['option_id'], $resolved['options']);
    $key = shop_basket_line_key($productId, $optionIds);

    $lines = $_SESSION[SHOP_BASKET_SESSION_KEY];
    if (isset($lines[$key])) {
        $lines[$key]['quantity'] = max(1, min((int) $product['max_per_order'], (int) $lines[$key]['quantity'] + $quantity));
    } else {
        $lines[$key] = [
            'key' => $key,
            'product_id' => $productId,
            'product_slug' => (string) $product['slug'],
            'product_name' => (string) $product['name'],
            'image_path' => (string) ($product['image_path'] ?? ''),
            'options' => $resolved['options'],
            'options_label' => $resolved['options_label'],
            'base_price' => $resolved['base_price'],
            'unit_price' => $resolved['unit_price'],
            'quantity' => $quantity,
            'is_preorder' => (int) $product['is_preorder'] === 1,
        ];
    }
    $_SESSION[SHOP_BASKET_SESSION_KEY] = $lines;
}

function shop_basket_set_quantity(PDO $pdo, string $key, int $quantity): void
{
    shop_basket_start();
    $lines = $_SESSION[SHOP_BASKET_SESSION_KEY];
    if (!isset($lines[$key])) {
        return;
    }
    if ($quantity <= 0) {
        unset($lines[$key]);
        $_SESSION[SHOP_BASKET_SESSION_KEY] = $lines;
        return;
    }
    $product = shop_get_product($pdo, (int) $lines[$key]['product_id']);
    $cap = $product ? (int) $product['max_per_order'] : 10;
    $lines[$key]['quantity'] = max(1, min($cap, $quantity));
    $_SESSION[SHOP_BASKET_SESSION_KEY] = $lines;
}

function shop_basket_remove(string $key): void
{
    shop_basket_start();
    $lines = $_SESSION[SHOP_BASKET_SESSION_KEY];
    unset($lines[$key]);
    $_SESSION[SHOP_BASKET_SESSION_KEY] = $lines;
}

function shop_basket_clear(): void
{
    shop_basket_start();
    $_SESSION[SHOP_BASKET_SESSION_KEY] = [];
    unset($_SESSION[SHOP_BASKET_DISCOUNT_KEY]);
}

function shop_basket_set_discount_code(string $code): void
{
    shop_basket_start();
    $code = strtoupper(trim($code));
    if ($code === '') {
        unset($_SESSION[SHOP_BASKET_DISCOUNT_KEY]);
        return;
    }
    $_SESSION[SHOP_BASKET_DISCOUNT_KEY] = $code;
}

function shop_basket_discount_code(): string
{
    shop_basket_start();
    return (string) ($_SESSION[SHOP_BASKET_DISCOUNT_KEY] ?? '');
}

/**
 * Re-price and re-validate every line against the live catalogue. Lines whose
 * product has been withdrawn are dropped; lines whose product can no longer
 * be ordered (pre-order window closed, out of stock) are flagged.
 *
 * @return array{
 *   items: list<array<string,mixed>>,
 *   subtotal: float, discount_code: string, discount_total: float, total: float,
 *   discount_error: string, count: int, has_blocked: bool, is_preorder: bool
 * }
 */
function shop_basket_summary(PDO $pdo): array
{
    shop_basket_start();
    $items = [];
    $subtotal = 0.0;
    $hasBlocked = false;
    $isPreorder = false;
    $changed = false;

    foreach ($_SESSION[SHOP_BASKET_SESSION_KEY] as $key => $line) {
        $product = shop_get_product($pdo, (int) $line['product_id']);
        if (!$product) {
            unset($_SESSION[SHOP_BASKET_SESSION_KEY][$key]);
            $changed = true;
            continue;
        }

        $blockedReason = '';
        try {
            $resolved = shop_resolve_selection($pdo, $product, shop_basket_selected_map($line));
        } catch (Throwable $e) {
            // Options changed underneath the customer — keep the stored snapshot
            // but block checkout until they re-pick.
            $resolved = [
                'base_price' => (float) $line['base_price'],
                'unit_price' => (float) $line['unit_price'],
                'options_label' => (string) $line['options_label'],
                'options' => $line['options'],
            ];
            $blockedReason = $e->getMessage();
        }

        if ($blockedReason === '' && !shop_product_is_orderable($pdo, $product)) {
            $close = shop_product_preorder_close($pdo, $product);
            $blockedReason = ($close !== null && new DateTimeImmutable('now') > $close)
                ? 'Pre-orders for this item have now closed.'
                : 'This item is currently unavailable.';
        }

        $qty = max(1, min((int) $product['max_per_order'], (int) $line['quantity']));
        if ($qty !== (int) $line['quantity']) {
            $_SESSION[SHOP_BASKET_SESSION_KEY][$key]['quantity'] = $qty;
            $changed = true;
        }
        // Refresh the stored price if the admin changed it.
        if (abs((float) $line['unit_price'] - (float) $resolved['unit_price']) > 0.001) {
            $_SESSION[SHOP_BASKET_SESSION_KEY][$key]['unit_price'] = $resolved['unit_price'];
            $_SESSION[SHOP_BASKET_SESSION_KEY][$key]['base_price'] = $resolved['base_price'];
            $changed = true;
        }

        $lineTotal = round((float) $resolved['unit_price'] * $qty, 2);
        $subtotal += $lineTotal;
        if ($blockedReason !== '') {
            $hasBlocked = true;
        }
        if ((int) $product['is_preorder'] === 1) {
            $isPreorder = true;
        }

        $items[] = [
            'key' => (string) $key,
            'product_id' => (int) $product['id'],
            'product_slug' => (string) $product['slug'],
            'product_name' => (string) $product['name'],
            'image_path' => (string) ($product['image_path'] ?? ''),
            'options' => $resolved['options'],
            'options_label' => (string) $resolved['options_label'],
            'base_price' => (float) $resolved['base_price'],
            'unit_price' => (float) $resolved['unit_price'],
            'quantity' => $qty,
            'line_total' => $lineTotal,
            'is_preorder' => (int) $product['is_preorder'] === 1,
            'max_per_order' => (int) $product['max_per_order'],
            'blocked_reason' => $blockedReason,
        ];
    }

    if ($changed) {
        $_SESSION[SHOP_BASKET_SESSION_KEY] = array_filter($_SESSION[SHOP_BASKET_SESSION_KEY]);
    }

    $subtotal = round($subtotal, 2);
    $discountCode = shop_basket_discount_code();
    $discount = shop_validate_discount($pdo, $discountCode, $subtotal);
    if ($discountCode !== '' && !$discount['ok']) {
        // Drop a now-invalid code so it doesn't wedge the basket.
        shop_basket_set_discount_code('');
    }
    $discountTotal = $discount['ok'] ? $discount['amount'] : 0.0;

    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'discount_code' => $discount['ok'] ? $discount['code'] : '',
        'discount_total' => $discountTotal,
        'discount_error' => $discountCode !== '' && !$discount['ok'] ? $discount['error'] : '',
        'total' => round(max(0.0, $subtotal - $discountTotal), 2),
        'count' => array_sum(array_map(static fn($i) => (int) $i['quantity'], $items)),
        'has_blocked' => $hasBlocked,
        'is_preorder' => $isPreorder,
    ];
}

/**
 * Rebuild the group_id => [option_ids] map from a stored basket line.
 *
 * @return array<int,list<int>>
 */
function shop_basket_selected_map(array $line): array
{
    $map = [];
    foreach (($line['options'] ?? []) as $opt) {
        $map[(int) $opt['group_id']][] = (int) $opt['option_id'];
    }
    return $map;
}

/**
 * Convert the validated basket summary into the line shape shop_create_order()
 * expects.
 *
 * @return list<array<string,mixed>>
 */
function shop_basket_order_lines(array $summary): array
{
    $lines = [];
    foreach ($summary['items'] as $item) {
        $lines[] = [
            'product_id' => (int) $item['product_id'],
            'product_name' => (string) $item['product_name'],
            'options_label' => (string) $item['options_label'],
            'options' => $item['options'],
            'base_price' => (float) $item['base_price'],
            'unit_price' => (float) $item['unit_price'],
            'quantity' => (int) $item['quantity'],
            'is_preorder' => (bool) $item['is_preorder'],
        ];
    }
    return $lines;
}
