<?php

declare(strict_types=1);

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/season_passes.php';

/**
 * Create a single Stripe Checkout Session covering one or more season ticket
 * commerce orders bought together (e.g. a parent buying for themselves and
 * their kids in one visit), and attach the session id to every payment so the
 * webhook can mark them all paid together.
 *
 * @param list<array<string, mixed>> $orders freshly-created orders/pass bundles, each with holder/type snapshots
 * @return array{url: string, session_id: string}
 */
function season_ticket_stripe_create_checkout_session(PDO $pdo, array $orders, string $customerEmail, string $successUrl, string $cancelUrl): array
{
    if ($orders === []) {
        throw new RuntimeException('No orders to charge.');
    }

    $currency = stripe_default_currency();
    $lineItems = [];
    $orderIds = [];
    foreach ($orders as $order) {
        $price = (float) ($order['price'] ?? $order['line_total'] ?? $order['total_amount'] ?? 0);
        if ($price <= 0) {
            continue; // Free ticket types (e.g. Wee Vics) don't need a Stripe line item.
        }
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units($price, $currency),
                'product_data' => [
                    'name' => (string) $order['type_name'] . ' season ticket — ' . (string) $order['holder_name'],
                    'description' => 'Saltcoats Victoria FC season ticket',
                ],
            ],
        ];
        $orderIds[] = (int) $order['id'];
    }

    // Every ticket in this order was free (e.g. Wee Vics only) — nothing to charge, so
    // mark them paid directly rather than sending the buyer through Stripe for £0.
    if ($lineItems === []) {
        foreach ($orders as $order) {
            markSeasonPassOrderPaid($pdo, (int) $order['id']);
        }
        return ['url' => $successUrl, 'session_id' => ''];
    }

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'line_items' => $lineItems,
        'metadata' => [
            'kind' => 'season_ticket',
            'order_ids' => implode(',', $orderIds),
            'commerce_order_ids' => implode(',', $orderIds),
        ],
        'payment_intent_data' => [
            'metadata' => [
                'kind' => 'season_ticket',
                'order_ids' => implode(',', $orderIds),
                'commerce_order_ids' => implode(',', $orderIds),
            ],
        ],
    ];

    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $params['customer_email'] = $customerEmail;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) $session['id'];

    foreach ($orders as $order) {
        attachSeasonPassOrderStripeSession($pdo, (int) $order['id'], $sessionId);
    }

    return ['url' => (string) $session['url'], 'session_id' => $sessionId];
}

/**
 * @param array<string, mixed> $session decoded Stripe Checkout Session
 */
function season_ticket_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    $orders = getSeasonPassOrdersByStripeSession($pdo, $sessionId);
    foreach ($orders as $order) {
        markSeasonPassOrderPaid($pdo, (int) $order['id'], is_string($session['payment_intent'] ?? null) ? (string) $session['payment_intent'] : null);
        sendSeasonPassConfirmationIfNeeded($pdo, (int) $order['id'], false);
        stripe_send_payment_notification(
            $pdo,
            'Season tickets',
            (string) ($order['customer_name'] ?? $order['holder_name'] ?? ''),
            (string) ($order['customer_email'] ?? $order['holder_email'] ?? ''),
            (float) ($order['line_total'] ?? $order['total_amount'] ?? 0),
            (string) ($order['type_name'] ?? 'Season ticket') . ' order #' . (int) $order['id'],
            stripe_public_base_url() . '/season_ticket_orders.php?id=' . (int) $order['id']
        );
    }
}

/**
 * @param array<string, mixed> $session decoded Stripe Checkout Session
 */
function season_ticket_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }

    $orders = getSeasonPassOrdersByStripeSession($pdo, $sessionId);
    foreach ($orders as $order) {
        if ((string) ($order['payment_status'] ?? '') !== 'paid') {
            cancelSeasonPassOrder($pdo, (int) $order['id'], 'Stripe Checkout session expired');
        }
    }
}
