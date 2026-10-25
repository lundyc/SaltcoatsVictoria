<?php

declare(strict_types=1);

/**
 * Club Shop — editable email templates.
 *
 * Every email the shop sends is described in shop_email_definitions(): its
 * defaults for subject, heading, intro text and so on. The club can override
 * any of those on the Shop settings → Emails tab (stored in
 * shop_email_templates; a blank stored value means "use the default"), and the
 * overall look (colours, header image, footer) lives in shop_settings under
 * email_* keys.
 *
 * Text fields are plain text with {placeholders} and **bold**; they are
 * HTML-escaped on output, so nothing typed here can inject markup.
 */

/** @return array<string, array<string,mixed>> */
function shop_email_definitions(): array
{
    return [
        'new_order' => [
            'name' => 'New order',
            'description' => 'Sent to the shop notification recipients when a customer pays for an order.',
            'to' => 'admin',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url'],
            'subject' => '[Club Shop] New order {order_ref} — {order_total}',
            'heading' => 'New order',
            'subheading' => '{order_ref}',
            'intro' => 'New paid Club Shop order **{order_ref}**.',
        ],
        'order_confirmation' => [
            'name' => 'Order confirmed (paid)',
            'description' => 'Sent to the customer once their payment is received, with the order details.',
            'to' => 'customer',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url', 'collection_point'],
            'subject' => 'Your {shop_name} order {order_ref}',
            'heading' => 'Order confirmed',
            'subheading' => '{order_ref}',
            'intro' => "Hi {customer_name},\n\nThanks for your order. We've received your payment in full. Here are the details for **{order_ref}**.",
        ],
        'order_awaiting_payment' => [
            'name' => 'Order received (awaiting payment)',
            'description' => 'Sent to the customer when an order is created or re-sent before payment has been received.',
            'to' => 'customer',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url', 'collection_point'],
            'subject' => 'Your {shop_name} order {order_ref}',
            'heading' => 'Awaiting payment',
            'subheading' => '{order_ref}',
            'intro' => "Hi {customer_name},\n\nThanks for your order. Payment is still outstanding. Please pay using the method agreed with the club. Here are the details for **{order_ref}**.",
        ],
        'order_collected' => [
            'name' => 'Order collected',
            'description' => 'Sent to the customer when you mark their order as collected.',
            'to' => 'customer',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url'],
            'subject' => 'Your order {order_ref} has been collected',
            'heading' => 'Order collected',
            'subheading' => '{order_ref}',
            'intro' => "Hi {customer_name},\n\nThanks — we've marked order **{order_ref}** as collected. If that's not right, get in touch and we'll sort it out.",
        ],
        'order_cancelled' => [
            'name' => 'Order cancelled',
            'description' => 'Sent to the customer when an order is cancelled.',
            'to' => 'customer',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url', 'reason'],
            'subject' => 'Your order {order_ref} has been cancelled',
            'heading' => 'Order cancelled',
            'subheading' => '{order_ref}',
            'intro' => "Hi {customer_name},\n\nYour order **{order_ref}** has been cancelled. {reason} If you've already paid, any refund due will follow separately.",
        ],
        'order_refunded' => [
            'name' => 'Order refunded',
            'description' => 'Sent to the customer when a full or partial refund is issued.',
            'to' => 'customer',
            'placeholders' => ['order_ref', 'customer_name', 'order_total', 'shop_name', 'site_url', 'refund_amount'],
            'subject' => 'Refund issued for your order {order_ref}',
            'heading' => 'Refund issued',
            'subheading' => '{order_ref}',
            'intro' => "Hi {customer_name},\n\nWe've refunded **{refund_amount}** for order **{order_ref}**. It can take a few days to appear back on your card.",
        ],
        'low_stock' => [
            'name' => 'Low stock alert',
            'description' => 'Sent to the shop notification recipients when an item drops to its low-stock threshold.',
            'to' => 'admin',
            'placeholders' => ['item', 'stock_left', 'threshold', 'shop_name', 'site_url'],
            'subject' => '[Club Shop] Low stock — {item}',
            'heading' => 'Low stock alert',
            'subheading' => '{item}',
            'intro' => 'Stock for **{item}** has dropped to **{stock_left} left** (alert threshold: {threshold}).',
        ],
    ];
}

/** @return array<string,string> */
function shop_email_placeholder_help(): array
{
    return [
        'order_ref' => 'The order reference, e.g. CS-1042',
        'customer_name' => "The customer's name",
        'order_total' => 'The order total, e.g. £45.00',
        'shop_name' => 'The club and shop name',
        'site_url' => "The website address",
        'collection_point' => 'Where orders are collected from',
        'reason' => 'The cancellation reason (blank if none)',
        'refund_amount' => 'The amount refunded',
        'item' => 'The product or option running low',
        'stock_left' => 'How many are left',
        'threshold' => 'The low-stock alert level',
    ];
}

function shop_email_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_email_templates (
        email_key VARCHAR(40) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        subject VARCHAR(255) NOT NULL DEFAULT '',
        heading VARCHAR(255) NOT NULL DEFAULT '',
        subheading VARCHAR(255) NOT NULL DEFAULT '',
        intro TEXT NULL,
        additional TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (email_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Effective settings for one email: stored overrides on top of the defaults.
 * @return array{enabled:bool,subject:string,heading:string,subheading:string,intro:string,additional:string}
 */
function shop_email_config(PDO $pdo, string $key): array
{
    $defs = shop_email_definitions();
    $def = $defs[$key] ?? null;
    if ($def === null) {
        throw new InvalidArgumentException('Unknown email: ' . $key);
    }
    $cfg = ['enabled' => true, 'subject' => $def['subject'], 'heading' => $def['heading'], 'subheading' => $def['subheading'], 'intro' => $def['intro'], 'additional' => ''];
    $stmt = $pdo->prepare('SELECT * FROM shop_email_templates WHERE email_key = :k');
    $stmt->execute([':k' => $key]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cfg['enabled'] = (int) $row['enabled'] === 1;
        foreach (['subject', 'heading', 'subheading', 'intro', 'additional'] as $f) {
            if (trim((string) ($row[$f] ?? '')) !== '') {
                $cfg[$f] = (string) $row[$f];
            }
        }
        $cfg['additional'] = (string) ($row['additional'] ?? '');
    }
    return $cfg;
}

function shop_email_enabled(PDO $pdo, string $key): bool
{
    return shop_email_config($pdo, $key)['enabled'];
}

/** @return array<string,string> */
function shop_email_style_defaults(): array
{
    return [
        'email_header_image' => '',
        'email_header_image_width' => '140',
        'email_header_align' => 'left',
        'email_base_color' => '#4b0818',
        'email_bg_color' => '#f6ecde',
        'email_body_bg_color' => '#ffffff',
        'email_text_color' => '#21141a',
        'email_footer_text' => '{shop_name} · Thank you for your support',
        'email_footer_text_color' => '#d8d2d5',
        'email_footer_bg_color' => '#21141a',
    ];
}

/**
 * Template look, from settings (with defaults), optionally overlaid with
 * unsaved values for the live preview.
 * @param array<string,mixed>|null $override
 * @return array<string,string>
 */
function shop_email_style(PDO $pdo, ?array $override = null): array
{
    $style = shop_email_style_defaults();
    foreach ($style as $k => $default) {
        $v = $override !== null && array_key_exists($k, $override) ? (string) $override[$k] : shop_setting($pdo, $k, '');
        if (trim($v) !== '') {
            $style[$k] = trim($v);
        } elseif ($k === 'email_header_image' || $k === 'email_footer_text') {
            // a blank image means none; a blank footer falls back to the default text
            $style[$k] = $k === 'email_footer_text' ? $default : '';
        }
    }
    foreach (['email_base_color', 'email_bg_color', 'email_body_bg_color', 'email_text_color', 'email_footer_text_color', 'email_footer_bg_color'] as $k) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $style[$k])) {
            $style[$k] = shop_email_style_defaults()[$k];
        }
    }
    if ($style['email_header_image'] !== '' && !preg_match('#^https://#i', $style['email_header_image'])) {
        $style['email_header_image'] = '';
    }
    $style['email_header_image_width'] = (string) max(40, min(600, (int) $style['email_header_image_width'] ?: 140));
    if (!in_array($style['email_header_align'], ['left', 'center', 'right'], true)) {
        $style['email_header_align'] = 'left';
    }
    return $style;
}

/** @return array<string,string> placeholder values common to every email */
function shop_email_base_vars(PDO $pdo): array
{
    require_once __DIR__ . '/site_settings.php';
    $club = trim((string) (site_settings_all($pdo)['club_name'] ?? '')) ?: 'Saltcoats Victoria';
    $shop = trim(shop_setting($pdo, 'shop_name', 'Club Shop')) ?: 'Club Shop';
    return [
        'club_name' => $club,
        'shop_name' => $club . ' ' . $shop,
        'site_url' => defined('APP_ORIGIN') ? (string) APP_ORIGIN : 'https://saltcoatsvictoria.co.uk',
        'collection_point' => shop_setting($pdo, 'collection_point', SHOP_COLLECTION_POINT_DEFAULT),
    ];
}

/** Replace {placeholders}; unknown ones are left untouched. */
function shop_email_fill(string $text, array $vars): string
{
    $out = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($vars): string {
        return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
    }, $text) ?? $text;
    return trim(preg_replace('/[ \t]{2,}/', ' ', $out) ?? $out);
}

/** Plain text → safe HTML paragraphs, with **bold** and line breaks. */
function shop_email_text_html(string $text, string $style = 'margin:0 0 16px;font-size:16px;line-height:1.55;'): string
{
    $out = '';
    foreach (preg_split('/\n{2,}/', trim(str_replace("\r", '', $text))) ?: [] as $para) {
        if (trim($para) === '') {
            continue;
        }
        $html = nl2br(h($para));
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
        $out .= '<p style="' . $style . '">' . $html . '</p>';
    }
    return $out;
}

/**
 * Build one email from its template.
 *
 * @param array<string,string>     $vars         placeholder values for this email
 * @param string                   $detailsHtml  ready-made HTML inserted between the intro and the additional text
 * @param array<string,string>|null $cfgOverride unsaved template values (preview)
 * @param array<string,mixed>|null  $styleOverride unsaved look values (preview)
 * @return array{subject:string,html:string}
 */
function shop_email_render(PDO $pdo, string $key, array $vars, string $detailsHtml = '', ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    $cfg = shop_email_config($pdo, $key);
    if ($cfgOverride !== null) {
        $def = shop_email_definitions()[$key];
        foreach (['subject', 'heading', 'subheading', 'intro'] as $f) {
            if (array_key_exists($f, $cfgOverride)) {
                $cfg[$f] = trim((string) $cfgOverride[$f]) !== '' ? (string) $cfgOverride[$f] : $def[$f];
            }
        }
        if (array_key_exists('additional', $cfgOverride)) {
            $cfg['additional'] = (string) $cfgOverride['additional'];
        }
    }
    $vars += shop_email_base_vars($pdo);
    $subject = preg_replace('/\s+/', ' ', shop_email_fill($cfg['subject'], $vars)) ?? '';
    $body = shop_email_text_html(shop_email_fill($cfg['intro'], $vars))
        . $detailsHtml
        . ($cfg['additional'] !== '' ? '<div style="margin-top:18px;">' . shop_email_text_html(shop_email_fill($cfg['additional'], $vars), 'margin:0 0 12px;font-size:15px;line-height:1.55;') . '</div>' : '');
    $html = shop_email_wrapper(
        $subject,
        $subject,
        shop_email_fill($cfg['heading'], $vars),
        shop_email_fill($cfg['subheading'], $vars),
        $body,
        $pdo,
        $styleOverride
    );
    return ['subject' => $subject, 'html' => $html];
}

/** @return array<string,mixed> a believable order for previews and test emails */
function shop_email_sample_order(): array
{
    return [
        'id' => 0, 'order_ref' => 'CS-PREVIEW', 'status' => 'paid', 'customer_name' => 'Alex Sample', 'customer_email' => 'alex@example.com',
        'customer_phone' => '07700 900123', 'subtotal' => 52.0, 'discount_total' => 0.0, 'discount_code' => '', 'total' => 52.0, 'amount_refunded' => 0.0,
        'is_preorder' => 0, 'fulfilment_method' => 'collection', 'collection_point' => '', 'delivery_address' => '', 'delivery_postcode' => '',
        'delivery_country' => null, 'shipping_method' => '', 'shipping_zone' => '',
    ];
}

/** @return list<array<string,mixed>> */
function shop_email_sample_items(): array
{
    return [
        ['product_id' => 0, 'product_name' => 'Home Shirt 2026/27', 'options_label' => 'Kit Size: Adult L', 'quantity' => 1, 'unit_price' => 45.0, 'line_total' => 45.0, 'is_preorder' => 0, 'is_vsn' => 0],
        ['product_id' => 0, 'product_name' => 'Pin Badge', 'options_label' => '', 'quantity' => 2, 'unit_price' => 3.5, 'line_total' => 7.0, 'is_preorder' => 0, 'is_vsn' => 0],
    ];
}

/**
 * Render any shop email with sample data (nothing is sent or saved).
 *
 * @param array<string,string>|null $cfgOverride
 * @param array<string,mixed>|null  $styleOverride
 * @return array{subject:string,html:string}
 */
function shop_email_preview(PDO $pdo, string $key, ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    $order = shop_email_sample_order();
    $items = shop_email_sample_items();
    switch ($key) {
        case 'order_confirmation':
        case 'order_awaiting_payment':
            $order['status'] = $key === 'order_awaiting_payment' ? 'pending_payment' : 'paid';
            return shop_order_email_content($pdo, $order, $items, $cfgOverride, $styleOverride);
        case 'new_order':
            return shop_admin_notification_message($pdo, $order, $items, $cfgOverride, $styleOverride);
        case 'low_stock':
            return shop_low_stock_message($pdo, 'Home Shirt 2026/27 — Adult L', 2, 3, $cfgOverride, $styleOverride);
        case 'order_collected':
        case 'order_cancelled':
        case 'order_refunded':
            return shop_status_email_message($pdo, $order, str_replace('order_', '', $key), 'Sorry — the item is out of stock.', 12.5, $cfgOverride, $styleOverride);
    }
    throw new InvalidArgumentException('Unknown email: ' . $key);
}
