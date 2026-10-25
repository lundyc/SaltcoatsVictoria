<?php

declare(strict_types=1);

// Club Shop — self-contained catalogue + basket + Stripe checkout module.
//
// Mirrors the structure of the other commerce modules in this app
// (lib/pos.php, lib/season_passes.php, lib/player_sponsorship_shop.php):
// its own shop_* tables, an idempotent shop_ensure_schema(), a small set
// of domain helpers, and Stripe Checkout Session handlers dispatched from
// stripe_handle_webhook_event() on metadata.kind === 'shop'.
//
// Customer storefront:  /shop/*          (public, guest checkout)
// Admin:                /shop_*.php      (hub admin, "Shop" nav section)

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/stripe.php';

const SHOP_CURRENCY = 'gbp';
const SHOP_COLLECTION_POINT_DEFAULT = 'Campbell Park';
const SHOP_LEAD_TIME_DEFAULT = '6–8 weeks';
const SHOP_PREORDER_CLOSE_DEFAULT = '2026-09-14 23:59:59';

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/shop_shipping.php';
require_once __DIR__ . '/shop_email.php';

function shop_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value MEDIUMTEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_settings_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(140) NOT NULL,
        slug VARCHAR(160) NOT NULL,
        description TEXT NULL,
        image_path VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_categories_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Subcategories — one level deep (a category with a parent can't itself
    // be chosen as a parent). Added after the table shipped.
    $categoryColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_categories') as $row) {
        $categoryColumns[(string) $row['Field']] = true;
    }
    if (!isset($categoryColumns['parent_id'])) {
        $pdo->exec("ALTER TABLE shop_categories ADD COLUMN parent_id INT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE shop_categories ADD KEY idx_shop_categories_parent (parent_id)");
        $pdo->exec("ALTER TABLE shop_categories ADD CONSTRAINT fk_shop_categories_parent
            FOREIGN KEY (parent_id) REFERENCES shop_categories (id) ON DELETE SET NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        slug VARCHAR(200) NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        description MEDIUMTEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_path VARCHAR(255) NULL,
        stock_qty INT NULL,
        max_per_order INT UNSIGNED NOT NULL DEFAULT 10,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        preorder_close_at DATETIME NULL,
        preorder_message VARCHAR(500) NOT NULL DEFAULT '',
        lead_time VARCHAR(120) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_products_slug (slug),
        KEY idx_shop_products_category (category_id, is_active),
        CONSTRAINT fk_shop_products_category FOREIGN KEY (category_id) REFERENCES shop_categories (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_images (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        alt_text VARCHAR(180) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_product_images_product (product_id, sort_order),
        CONSTRAINT fk_shop_product_images_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_modifier_groups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(140) NOT NULL,
        slug VARCHAR(160) NOT NULL,
        help_text VARCHAR(255) NOT NULL DEFAULT '',
        selection_type VARCHAR(10) NOT NULL DEFAULT 'single',
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        min_select INT UNSIGNED NOT NULL DEFAULT 1,
        max_select INT UNSIGNED NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_modifier_groups_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_modifier_options (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id INT UNSIGNED NOT NULL,
        option_group VARCHAR(60) NOT NULL DEFAULT '',
        label VARCHAR(140) NOT NULL,
        price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sku_suffix VARCHAR(40) NOT NULL DEFAULT '',
        stock_qty INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_modifier_options_group (group_id, sort_order),
        CONSTRAINT fk_shop_modifier_options_group FOREIGN KEY (group_id) REFERENCES shop_modifier_groups (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // option_group is a heading *within* a modifier group (e.g. Adult / Kids
    // under "Kit Size") that the storefront turns into a two-step
    // choose-fit-then-size control. Added after the table shipped, so backfill
    // it from any legacy "Adult …" / "Youth …" labels the first time through.
    $modifierOptionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_modifier_options') as $row) {
        $modifierOptionColumns[(string) $row['Field']] = true;
    }
    if (!isset($modifierOptionColumns['option_group'])) {
        $pdo->exec("ALTER TABLE shop_modifier_options ADD COLUMN option_group VARCHAR(60) NOT NULL DEFAULT '' AFTER group_id");
        $pdo->exec("UPDATE shop_modifier_options SET option_group = 'Adult', label = TRIM(SUBSTRING(label, 7))
            WHERE option_group = '' AND label LIKE 'Adult %'");
        $pdo->exec("UPDATE shop_modifier_options SET option_group = 'Kids', label = TRIM(SUBSTRING(label, 7)),
            sort_order = sort_order + 1000
            WHERE option_group = '' AND (label LIKE 'Youth %' OR label LIKE 'Kids %' OR label LIKE 'Junior %')");
        $pdo->exec("UPDATE shop_modifier_groups SET help_text = 'Choose Adult or Kids, then pick your size. Kit is a slim, athletic fit — size up for a looser fit.'
            WHERE slug = 'kit-size'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_modifier_groups (
        product_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_required TINYINT(1) NULL,
        PRIMARY KEY (product_id, group_id),
        KEY idx_shop_pmg_group (group_id),
        CONSTRAINT fk_shop_pmg_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_pmg_group FOREIGN KEY (group_id) REFERENCES shop_modifier_groups (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_discount_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(40) NOT NULL,
        discount_type VARCHAR(10) NOT NULL DEFAULT 'percent',
        discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        min_spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        max_uses INT UNSIGNED NULL,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_discount_codes_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_ref VARCHAR(32) NOT NULL,
        access_token VARCHAR(64) NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        customer_phone VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_code VARCHAR(40) NOT NULL DEFAULT '',
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'GBP',
        amount_refunded DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        fulfilment_method VARCHAR(20) NOT NULL DEFAULT 'collection',
        collection_point VARCHAR(190) NOT NULL DEFAULT '',
        batch_note VARCHAR(255) NOT NULL DEFAULT '',
        customer_note TEXT NULL,
        marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
        terms_accepted_at DATETIME NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        stripe_payment_intent_id VARCHAR(190) NULL,
        stripe_refund_id VARCHAR(190) NULL,
        confirmation_email_sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        collected_at DATETIME NULL,
        vsn_ordered_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_orders_ref (order_ref),
        UNIQUE KEY uq_shop_orders_token (access_token),
        KEY idx_shop_orders_status (status, created_at),
        KEY idx_shop_orders_session (stripe_checkout_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL,
        product_name VARCHAR(190) NOT NULL,
        options_label VARCHAR(255) NOT NULL DEFAULT '',
        options_json MEDIUMTEXT NULL,
        base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_order_items_order (order_id),
        KEY idx_shop_order_items_product (product_id),
        CONSTRAINT fk_shop_order_items_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_order_items_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Delivery (added after shop_orders shipped — off by default, toggled in
    // shop_settings). fulfilment_method/collection_point already existed but
    // were never actually set to anything but 'collection'.
    $orderColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_orders') as $row) {
        $orderColumns[(string) $row['Field']] = true;
    }
    if (!isset($orderColumns['delivery_fee'])) {
        $pdo->exec("ALTER TABLE shop_orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER collection_point");
    }
    if (!isset($orderColumns['delivery_address'])) {
        $pdo->exec("ALTER TABLE shop_orders ADD COLUMN delivery_address TEXT NULL AFTER delivery_fee");
    }

    // Legacy: the optional collection/delivery date picker was removed. The
    // column is kept (always empty) so older databases and backups stay compatible.
    if (!isset($orderColumns['requested_date'])) {
        $pdo->exec("ALTER TABLE shop_orders ADD COLUMN requested_date DATE NULL AFTER delivery_address");
        $pdo->exec("ALTER TABLE shop_orders ADD KEY idx_shop_orders_requested_date (requested_date)");
    }

    // "Made by VSN" — only these products/order items go on the VSN order form
    // and get the "flag as sent to VSN" controls. Pre-order and VSN are
    // independent: a product can be a pre-order without VSN (or vice versa).
    $productColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_products') as $row) {
        $productColumns[(string) $row['Field']] = true;
    }
    if (!isset($productColumns['is_vsn'])) {
        $pdo->exec("ALTER TABLE shop_products ADD COLUMN is_vsn TINYINT(1) NOT NULL DEFAULT 0 AFTER is_preorder");
        // Until now every pre-order item was a VSN-manufactured kit.
        $pdo->exec("UPDATE shop_products SET is_vsn = 1 WHERE is_preorder = 1");
    }
    foreach ([
        'sku' => "VARCHAR(60) NOT NULL DEFAULT '' AFTER slug",
        'sale_price' => 'DECIMAL(10,2) NULL AFTER price',
        'sale_from' => 'DATETIME NULL AFTER sale_price',
        'sale_to' => 'DATETIME NULL AFTER sale_from',
        'stock_status' => "VARCHAR(12) NOT NULL DEFAULT 'instock' AFTER stock_qty",
        'allow_backorder' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_status', // 0 no, 1 allow, 2 allow + notify customer
        'low_stock_amount' => 'INT NULL',
        'shipping_class_id' => 'INT UNSIGNED NULL',
        'gtin' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'weight' => 'DECIMAL(8,2) NULL',
        'length' => 'DECIMAL(8,2) NULL',
        'width' => 'DECIMAL(8,2) NULL',
        'height' => 'DECIMAL(8,2) NULL',
    ] as $col => $def) {
        if (!isset($productColumns[$col])) {
            $pdo->exec("ALTER TABLE shop_products ADD COLUMN {$col} {$def}");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_shipping_classes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(120) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        delivery_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_shipping_classes_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // The per-product "max per order" setting was retired: only "sold individually" (1) remains.
    $pdo->exec('UPDATE shop_products SET max_per_order = 99 WHERE max_per_order > 1 AND max_per_order < 99');

    // Products can sit in several categories; shop_products.category_id stays
    // as the primary one (breadcrumbs, cards, SEO).
    $hadCategoryTable = $pdo->query("SHOW TABLES LIKE 'shop_product_categories'")->fetchColumn() !== false;
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_categories (
        product_id INT UNSIGNED NOT NULL,
        category_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (product_id, category_id),
        KEY idx_shop_pc_category (category_id),
        CONSTRAINT fk_shop_pc_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_pc_category FOREIGN KEY (category_id) REFERENCES shop_categories (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$hadCategoryTable) {
        $pdo->exec('INSERT IGNORE INTO shop_product_categories (product_id, category_id) SELECT id, category_id FROM shop_products');
    }
    // Per-product value selection for a shared option group: options listed
    // here are hidden for that one product (e.g. no 4XL on the keyring).
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_excluded_options (
        product_id INT UNSIGNED NOT NULL,
        option_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (product_id, option_id),
        CONSTRAINT fk_shop_peo_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_peo_option FOREIGN KEY (option_id) REFERENCES shop_modifier_options (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Upsells (shown on the product page) and cross-sells (shown in the basket).
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_links (
        product_id INT UNSIGNED NOT NULL,
        linked_id INT UNSIGNED NOT NULL,
        link_type VARCHAR(10) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (product_id, linked_id, link_type),
        KEY idx_shop_pl_linked (linked_id),
        CONSTRAINT fk_shop_pl_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_pl_linked FOREIGN KEY (linked_id) REFERENCES shop_products (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $itemColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_order_items') as $row) {
        $itemColumns[(string) $row['Field']] = true;
    }
    if (!isset($itemColumns['is_vsn'])) {
        $pdo->exec("ALTER TABLE shop_order_items ADD COLUMN is_vsn TINYINT(1) NOT NULL DEFAULT 0 AFTER is_preorder");
        $pdo->exec("UPDATE shop_order_items SET is_vsn = 1 WHERE is_preorder = 1");
    }

    shop_shipping_ensure_schema($pdo);
    shop_email_ensure_schema($pdo);

    // One-off: pre-order dates / lead time / explainer used to be shop-wide settings.
    // They now live on each product, so fold the old values onto the products
    // (keeping current behaviour) and retire the settings.
    $legacyClose = $pdo->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'preorder_close_at'")->fetchColumn();
    if ($legacyClose !== false) {
        if (trim((string) $legacyClose) !== '') {
            $pdo->prepare('UPDATE shop_products SET preorder_close_at = :c WHERE is_preorder = 1 AND preorder_close_at IS NULL')->execute([':c' => $legacyClose]);
        }
        $legacyLead = $pdo->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'lead_time'")->fetchColumn();
        if ($legacyLead !== false && trim((string) $legacyLead) !== '') {
            $pdo->prepare("UPDATE shop_products SET lead_time = :l WHERE is_vsn = 1 AND lead_time = ''")->execute([':l' => $legacyLead]);
        }
        $pdo->exec("DELETE FROM shop_settings WHERE setting_key IN ('preorder_close_at', 'lead_time', 'preorder_intro')");
    }
    shop_seed_defaults($pdo);
}

function shop_seed_defaults(PDO $pdo): void
{
    $defaults = [
        'shop_enabled'        => '1',
        'shop_name'           => 'Club Shop',
        'shop_intro'          => 'Official Saltcoats Victoria FC merchandise. Every order is made to order by our kit manufacturer VSN.',
        'contact_email'       => '',
        'collection_point'    => SHOP_COLLECTION_POINT_DEFAULT,
        'collection_details'  => 'You will be emailed as soon as your order is ready to collect from Campbell Park. Please bring your order confirmation.',
        'delivery_note'       => 'Collection only — there is no delivery option for this shop. All orders are collected from ' . SHOP_COLLECTION_POINT_DEFAULT . '.',
        'delivery_enabled'    => '0',
        'delivery_fee'        => '3.50',
        'low_stock_alerts_enabled' => '1',
        'low_stock_threshold' => '3',
        'terms'               => "By placing this order you agree that:\n"
            . "• Payment is taken in full today.\n"
            . "• Pre-order kit orders close on 14 September 2026. After that date the combined order is placed with VSN and items can no longer be added or changed.\n"
            . "• VSN manufacturing typically takes " . SHOP_LEAD_TIME_DEFAULT . " from the order closing date.\n"
            . "• All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . ". There is no delivery option.\n"
            . "• You will be contacted by email when your order is ready to collect.",
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO shop_settings (setting_key, setting_value) VALUES (:k, :v)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    // Only seed the catalogue on a truly empty shop.
    if ((int) $pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn() > 0) {
        return;
    }

    $pdo->prepare('INSERT IGNORE INTO shop_categories (name, slug, description, sort_order, is_active) VALUES (:n, :s, :d, :o, 1)')
        ->execute([
            ':n' => 'Matchday Kit 2026/27',
            ':s' => 'matchday-kit-2026-27',
            ':d' => 'This season\'s official home and away playing kit, made to order by VSN.',
            ':o' => 10,
        ]);
    $categoryId = (int) $pdo->query("SELECT id FROM shop_categories WHERE slug = 'matchday-kit-2026-27'")->fetchColumn();
    if ($categoryId <= 0) {
        return;
    }

    // Shared "Kit Size" modifier group. option_group ("Adult" / "Kids") drives
    // the storefront's choose-fit-then-size control.
    $pdo->prepare("INSERT IGNORE INTO shop_modifier_groups (name, slug, help_text, selection_type, is_required, min_select, max_select, sort_order)
        VALUES ('Kit Size', 'kit-size', 'Choose Adult or Kids, then pick your size. Kit is a slim, athletic fit — size up for a looser fit.', 'single', 1, 1, 1, 10)")
        ->execute();
    $sizeGroupId = (int) $pdo->query("SELECT id FROM shop_modifier_groups WHERE slug = 'kit-size'")->fetchColumn();
    if ($sizeGroupId > 0 && (int) $pdo->query('SELECT COUNT(*) FROM shop_modifier_options WHERE group_id = ' . $sizeGroupId)->fetchColumn() === 0) {
        $sizes = [
            ['Adult', 'XS'], ['Adult', 'S'], ['Adult', 'M'], ['Adult', 'L'],
            ['Adult', 'XL'], ['Adult', '2XL'], ['Adult', '3XL'], ['Adult', '4XL'],
            ['Kids', '5-6 yrs'], ['Kids', '7-8 yrs'], ['Kids', '9-10 yrs'], ['Kids', '11-12 yrs'], ['Kids', '13-14 yrs'],
        ];
        $optStmt = $pdo->prepare('INSERT INTO shop_modifier_options (group_id, option_group, label, sku_suffix, sort_order) VALUES (:g, :grp, :l, :sku, :o)');
        foreach ($sizes as $i => [$grp, $label]) {
            $optStmt->execute([
                ':g' => $sizeGroupId,
                ':grp' => $grp,
                ':l' => $label,
                ':sku' => strtoupper($grp[0] . (preg_replace('/[^A-Za-z0-9]+/', '', $label) ?? '')),
                ':o' => ($i + 1) * 10,
            ]);
        }
    }

    $close = SHOP_PREORDER_CLOSE_DEFAULT;
    $lead = SHOP_LEAD_TIME_DEFAULT;
    $preMsg = 'Pre-order — orders close 14 September 2026, then placed with VSN (allow ' . $lead . '). Collection only from '
        . SHOP_COLLECTION_POINT_DEFAULT . '.';

    $productStmt = $pdo->prepare('INSERT INTO shop_products
        (category_id, name, slug, summary, description, price, is_active, is_featured, is_preorder, is_vsn, preorder_close_at, preorder_message, lead_time, sort_order)
        VALUES (:cat, :name, :slug, :summary, :description, :price, 1, 1, 1, 1, :close, :premsg, :lead, :sort)');
    $kits = [
        [
            'name' => 'Home Shirt 2026/27',
            'slug' => 'home-shirt-2026-27',
            'summary' => 'Official home playing shirt for the 2026/27 season.',
            'description' => "The official Saltcoats Victoria FC home shirt for season 2026/27, manufactured by VSN.\n\n"
                . "This is a pre-order. Pay today; your order goes into the club's bulk order to VSN when pre-orders close on 14 September 2026. "
                . "VSN manufacturing then typically takes " . $lead . ".\n\n"
                . "All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . " — there is no delivery option. You'll be emailed when your kit is ready.",
            'price' => 45.00,
            'sort' => 10,
        ],
        [
            'name' => 'Away Shirt 2026/27',
            'slug' => 'away-shirt-2026-27',
            'summary' => 'Official away playing shirt for the 2026/27 season.',
            'description' => "The official Saltcoats Victoria FC away shirt for season 2026/27, manufactured by VSN.\n\n"
                . "This is a pre-order. Pay today; your order goes into the club's bulk order to VSN when pre-orders close on 14 September 2026. "
                . "VSN manufacturing then typically takes " . $lead . ".\n\n"
                . "All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . " — there is no delivery option. You'll be emailed when your kit is ready.",
            'price' => 45.00,
            'sort' => 20,
        ],
    ];
    foreach ($kits as $kit) {
        $productStmt->execute([
            ':cat' => $categoryId,
            ':name' => $kit['name'],
            ':slug' => $kit['slug'],
            ':summary' => $kit['summary'],
            ':description' => $kit['description'],
            ':price' => $kit['price'],
            ':close' => $close,
            ':premsg' => $preMsg,
            ':lead' => $lead,
            ':sort' => $kit['sort'],
        ]);
        $productId = (int) $pdo->lastInsertId();
        if ($productId > 0 && $sizeGroupId > 0) {
            $pdo->prepare('INSERT IGNORE INTO shop_product_modifier_groups (product_id, group_id, sort_order, is_required) VALUES (:p, :g, 10, 1)')
                ->execute([':p' => $productId, ':g' => $sizeGroupId]);
        }
    }
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/** @return array<string,string> */
function shop_get_settings(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    $out = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM shop_settings') as $row) {
        $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $out;
}

function shop_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = shop_get_settings($pdo);
    }
    $value = $cache[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

function shop_save_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO shop_settings (setting_key, setting_value) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([':k' => $key, ':v' => $value]);
}

function shop_is_enabled(PDO $pdo): bool
{
    return shop_setting($pdo, 'shop_enabled', '1') === '1';
}

function shop_delivery_enabled(PDO $pdo): bool
{
    return shop_setting($pdo, 'delivery_enabled', '0') === '1';
}

function shop_delivery_fee(PDO $pdo): float
{
    return max(0.0, round((float) shop_setting($pdo, 'delivery_fee', '0.00'), 2));
}

/**
 * Delivery fee for a basket: the flat shop fee plus the surcharge of each
 * distinct shipping class among the products (a class counts once however
 * many of its products are in the basket).
 *
 * @param list<int> $productIds
 */
function shop_delivery_fee_for_products(PDO $pdo, array $productIds): float
{
    $fee = shop_delivery_fee($pdo);
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    if ($productIds === []) {
        return $fee;
    }
    $in = implode(',', $productIds);
    $extra = (float) $pdo->query("SELECT COALESCE(SUM(c.delivery_surcharge), 0) FROM shop_shipping_classes c
        WHERE c.id IN (SELECT DISTINCT shipping_class_id FROM shop_products WHERE id IN ({$in}) AND shipping_class_id IS NOT NULL)")->fetchColumn();
    return round($fee + max(0.0, $extra), 2);
}

/** @return list<array<string,mixed>> */
function shop_shipping_classes(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.shipping_class_id = c.id) AS product_count
        FROM shop_shipping_classes c ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array<string,mixed> $data */
function shop_save_shipping_class(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Shipping class name is required.');
    }
    $params = [
        ':name' => mb_substr($name, 0, 100),
        ':slug' => shop_unique_slug($pdo, 'shop_shipping_classes', trim((string) ($data['slug'] ?? '')) ?: $name, $id),
        ':desc' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
        ':fee' => max(0, round((float) ($data['delivery_surcharge'] ?? 0), 2)),
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_shipping_classes SET name=:name, slug=:slug, description=:desc, delivery_surcharge=:fee WHERE id=:id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_shipping_classes (name, slug, description, delivery_surcharge) VALUES (:name, :slug, :desc, :fee)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_shipping_class(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE shop_products SET shipping_class_id = NULL WHERE shipping_class_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM shop_shipping_classes WHERE id = :id')->execute([':id' => $id]);
}

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

function shop_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function shop_unique_slug(PDO $pdo, string $table, string $base, ?int $ignoreId = null): string
{
    $base = shop_slugify($base);
    if ($base === '') {
        $base = 'item';
    }
    $slug = $base;
    $n = 1;
    $sql = "SELECT COUNT(*) FROM {$table} WHERE slug = :slug" . ($ignoreId !== null ? ' AND id <> :id' : '');
    $stmt = $pdo->prepare($sql);
    while (true) {
        $params = [':slug' => $slug];
        if ($ignoreId !== null) {
            $params[':id'] = $ignoreId;
        }
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
    }
}

function shop_money(float $amount): string
{
    return gbp($amount);
}

function shop_generate_token(): string
{
    return bin2hex(random_bytes(16));
}

function shop_generate_order_ref(PDO $pdo): string
{
    $next = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM shop_orders')->fetchColumn();
    for ($i = 0; $i < 20; $i++) {
        $ref = 'SVFC-' . str_pad((string) ($next + $i), 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT 1 FROM shop_orders WHERE order_ref = :r');
        $check->execute([':r' => $ref]);
        if (!$check->fetchColumn()) {
            return $ref;
        }
    }
    return 'SVFC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/**
 * Validate an uploaded image and move it into uploads/shop/<subdir>/.
 * Returns the public web path, or null on any problem.
 *
 * @param array<string,mixed> $file a single $_FILES entry
 */
function shop_handle_image_upload(array $file, string $subdir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return null;
    }
    if ((int) ($file['size'] ?? 0) > 6 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];
    if ($info === false || !isset($allowed[$info[2]])) {
        return null;
    }
    $subdir = preg_replace('/[^a-z0-9_-]/', '', strtolower($subdir)) ?: 'misc';
    $dir = dirname(__DIR__) . '/uploads/shop/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
        return null;
    }
    @chmod($dest, 0644);
    return '/uploads/shop/' . $subdir . '/' . $name;
}

/* -------------------------------------------------------------------------
 * Categories
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_get_categories(PDO $pdo, bool $includeInactive = false): array
{
    shop_ensure_schema($pdo);
    $sql = 'SELECT c.*, parent.name AS parent_name,
            (SELECT COUNT(DISTINCT p.id) FROM shop_products p WHERE p.category_id = c.id OR EXISTS (SELECT 1 FROM shop_product_categories pc WHERE pc.product_id = p.id AND pc.category_id = c.id)) AS product_count
            FROM shop_categories c
            LEFT JOIN shop_categories parent ON parent.id = c.parent_id';
    if (!$includeInactive) {
        $sql .= ' WHERE c.is_active = 1';
    }
    $sql .= ' ORDER BY COALESCE(parent.sort_order, c.sort_order), COALESCE(parent.name, c.name), c.parent_id IS NULL DESC, c.sort_order, c.name';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Top-level categories, each with its active subcategories nested under
 * 'children'. Used for the storefront category nav.
 * @return list<array<string,mixed>>
 */
function shop_category_tree(PDO $pdo, bool $includeInactive = false): array
{
    $flat = shop_get_categories($pdo, $includeInactive);
    $byId = [];
    foreach ($flat as $cat) {
        $cat['children'] = [];
        $byId[(int) $cat['id']] = $cat;
    }
    $tree = [];
    foreach ($byId as $id => $cat) {
        $parentId = (int) ($cat['parent_id'] ?? 0);
        if ($parentId > 0 && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = $cat;
        } else {
            $tree[] = $cat;
        }
    }
    // Rebuild top-level list with the (now populated) children from $byId.
    $result = [];
    foreach ($tree as $cat) {
        $cat['children'] = $byId[(int) $cat['id']]['children'];
        $result[] = $cat;
    }
    return $result;
}

/**
 * A category id plus every subcategory id beneath it (or just itself, for a
 * subcategory / leaf category) — used so filtering by a parent category also
 * shows its subcategories' products.
 * @return list<int>
 */
function shop_category_ids_with_children(PDO $pdo, int $categoryId): array
{
    $ids = [$categoryId];
    $stmt = $pdo->prepare('SELECT id FROM shop_categories WHERE parent_id = :p');
    $stmt->execute([':p' => $categoryId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        $ids[] = (int) $childId;
    }
    return $ids;
}

function shop_get_category(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_category_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_categories WHERE slug = :s');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @param array<string,mixed> $data */
function shop_save_category(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Category name is required.');
    }
    $slugInput = trim((string) ($data['slug'] ?? ''));
    $slug = shop_unique_slug($pdo, 'shop_categories', $slugInput !== '' ? $slugInput : $name, $id);

    $parentId = (int) ($data['parent_id'] ?? 0);
    if ($parentId > 0) {
        if ($id && $parentId === $id) {
            throw new HubFieldValidationException('parent_id', 'A category cannot be its own parent.');
        }
        $parent = shop_get_category($pdo, $parentId);
        if (!$parent) {
            throw new HubFieldValidationException('parent_id', 'Choose a valid parent category.');
        }
        if ((int) ($parent['parent_id'] ?? 0) > 0) {
            throw new HubFieldValidationException('parent_id', 'A subcategory cannot itself have subcategories.');
        }
    }

    $params = [
        ':name' => $name,
        ':slug' => $slug,
        ':parent_id' => $parentId > 0 ? $parentId : null,
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':image_path' => trim((string) ($data['image_path'] ?? '')) ?: null,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $params[':id'] = $id;
        $sql = 'UPDATE shop_categories SET name=:name, slug=:slug, parent_id=:parent_id, description=:description, image_path=:image_path,
                sort_order=:sort_order, is_active=:is_active WHERE id=:id';
        // Keep the existing image if none supplied.
        if ($params[':image_path'] === null) {
            unset($params[':image_path']);
            $sql = str_replace(', image_path=:image_path', '', $sql);
        }
        $pdo->prepare($sql)->execute($params);
        // A category with children can't itself become a subcategory.
        if ($parentId > 0) {
            $childCount = $pdo->prepare('SELECT COUNT(*) FROM shop_categories WHERE parent_id = :id');
            $childCount->execute([':id' => $id]);
            if ((int) $childCount->fetchColumn() > 0) {
                $pdo->prepare('UPDATE shop_categories SET parent_id = NULL WHERE id = :id')->execute([':id' => $id]);
                throw new RuntimeException('This category has its own subcategories, so it cannot be made a subcategory itself.');
            }
        }
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_categories (name, slug, parent_id, description, image_path, sort_order, is_active)
        VALUES (:name, :slug, :parent_id, :description, :image_path, :sort_order, :is_active)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_category(PDO $pdo, int $id): void
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM shop_products p WHERE p.category_id = :id
        OR EXISTS (SELECT 1 FROM shop_product_categories pc WHERE pc.product_id = p.id AND pc.category_id = :id2)');
    $count->execute([':id' => $id, ':id2' => $id]);
    if ((int) $count->fetchColumn() > 0) {
        throw new RuntimeException('Move or delete this category\'s products first.');
    }
    $children = $pdo->prepare('SELECT COUNT(*) FROM shop_categories WHERE parent_id = :id');
    $children->execute([':id' => $id]);
    if ((int) $children->fetchColumn() > 0) {
        throw new RuntimeException('Move or delete this category\'s subcategories first.');
    }
    $pdo->prepare('DELETE FROM shop_categories WHERE id = :id')->execute([':id' => $id]);
}

/* -------------------------------------------------------------------------
 * Products
 * ---------------------------------------------------------------------- */

/**
 * @param array<string,mixed> $filters  category_id, active_only, featured_only, search
 * @return list<array<string,mixed>>
 */
function shop_get_products(PDO $pdo, array $filters = []): array
{
    shop_ensure_schema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['active_only'])) {
        $where[] = 'p.is_active = 1 AND c.is_active = 1';
    }
    if (!empty($filters['featured_only'])) {
        $where[] = 'p.is_featured = 1';
    }
    if (!empty($filters['category_id'])) {
        $categoryIds = shop_category_ids_with_children($pdo, (int) $filters['category_id']);
        $placeholders = [];
        $placeholdersX = [];
        foreach ($categoryIds as $i => $catId) {
            $placeholders[] = ':cat' . $i;
            $placeholdersX[] = ':catx' . $i;
            $params[':cat' . $i] = $catId;
            $params[':catx' . $i] = $catId;
        }
        $where[] = '(p.category_id IN (' . implode(',', $placeholders) . ') OR EXISTS (SELECT 1 FROM shop_product_categories pc
            WHERE pc.product_id = p.id AND pc.category_id IN (' . implode(',', $placeholdersX) . ')))';
    }
    if (!empty($filters['search'])) {
        $where[] = '(p.name LIKE :q OR p.summary LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }
    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM shop_products p JOIN shop_categories c ON c.id = p.category_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.sort_order, p.sort_order, p.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shop_get_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM shop_products p JOIN shop_categories c ON c.id = p.category_id WHERE p.id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_product_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM shop_products p JOIN shop_categories c ON c.id = p.category_id WHERE p.slug = :s');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_product_images(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_product_images WHERE product_id = :id ORDER BY sort_order, id');
    $stmt->execute([':id' => $productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every image for a product's gallery, main image first.
 * @return list<array{path:string,alt:string}>
 */
function shop_product_gallery(PDO $pdo, array $product): array
{
    $gallery = [];
    $main = trim((string) ($product['image_path'] ?? ''));
    if ($main !== '') {
        $gallery[] = ['path' => $main, 'alt' => (string) $product['name']];
    }
    foreach (shop_product_images($pdo, (int) $product['id']) as $img) {
        $gallery[] = ['path' => (string) $img['image_path'], 'alt' => (string) ($img['alt_text'] ?: $product['name'])];
    }
    return $gallery;
}

/** @param array<string,mixed> $data */
function shop_save_product(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Product name is required.');
    }
    $categoryIds = array_values(array_unique(array_filter(
        array_map('intval', (array) ($data['category_ids'] ?? [(int) ($data['category_id'] ?? 0)])),
        static fn(int $c): bool => $c > 0 && shop_get_category($pdo, $c) !== null
    )));
    if ($categoryIds === []) {
        throw new HubFieldValidationException('category_id', 'Choose at least one category.');
    }
    $categoryId = $categoryIds[0]; // primary
    $slugInput = trim((string) ($data['slug'] ?? ''));
    $slug = shop_unique_slug($pdo, 'shop_products', $slugInput !== '' ? $slugInput : $name, $id);

    $stockRaw = trim((string) ($data['stock_qty'] ?? ''));
    $salePriceRaw = trim((string) ($data['sale_price'] ?? ''));
    if ($salePriceRaw !== '' && (float) $salePriceRaw >= (float) ($data['price'] ?? 0)) {
        throw new HubFieldValidationException('sale_price', 'The sale price must be lower than the regular price.');
    }
    $preorderClose = trim((string) ($data['preorder_close_at'] ?? ''));

    $params = [
        ':category_id' => $categoryId,
        ':name' => $name,
        ':slug' => $slug,
        ':summary' => trim((string) ($data['summary'] ?? '')),
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':price' => max(0, round((float) ($data['price'] ?? 0), 2)),
        ':stock_qty' => $stockRaw === '' ? null : max(0, (int) $stockRaw),
        ':max_per_order' => max(1, (int) ($data['max_per_order'] ?? 10)),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':is_featured' => !empty($data['is_featured']) ? 1 : 0,
        ':is_preorder' => !empty($data['is_preorder']) ? 1 : 0,
        ':is_vsn' => !empty($data['is_vsn']) ? 1 : 0,
        ':sku' => mb_substr(trim((string) ($data['sku'] ?? '')), 0, 60),
        ':sale_price' => trim((string) ($data['sale_price'] ?? '')) === '' ? null : max(0, round((float) $data['sale_price'], 2)),
        ':sale_from' => trim((string) ($data['sale_from'] ?? '')) !== '' ? date('Y-m-d H:i:s', strtotime((string) $data['sale_from'])) : null,
        ':sale_to' => trim((string) ($data['sale_to'] ?? '')) !== '' ? date('Y-m-d H:i:s', strtotime((string) $data['sale_to'])) : null,
        ':stock_status' => in_array($data['stock_status'] ?? 'instock', ['outofstock', 'onbackorder'], true) ? (string) $data['stock_status'] : 'instock',
        ':allow_backorder' => max(0, min(2, (int) ($data['allow_backorder'] ?? 0))),
        ':shipping_class_id' => ((int) ($data['shipping_class_id'] ?? 0)) > 0 ? (int) $data['shipping_class_id'] : null,
        ':low_stock_amount' => trim((string) ($data['low_stock_amount'] ?? '')) === '' ? null : max(0, (int) $data['low_stock_amount']),
        ':gtin' => mb_substr(trim((string) ($data['gtin'] ?? '')), 0, 40),
        ':weight' => trim((string) ($data['weight'] ?? '')) === '' ? null : max(0, round((float) $data['weight'], 2)),
        ':length' => trim((string) ($data['length'] ?? '')) === '' ? null : max(0, round((float) $data['length'], 2)),
        ':width' => trim((string) ($data['width'] ?? '')) === '' ? null : max(0, round((float) $data['width'], 2)),
        ':height' => trim((string) ($data['height'] ?? '')) === '' ? null : max(0, round((float) $data['height'], 2)),
        ':preorder_close_at' => $preorderClose !== '' ? date('Y-m-d H:i:s', strtotime($preorderClose)) : null,
        ':preorder_message' => trim((string) ($data['preorder_message'] ?? '')),
        ':lead_time' => trim((string) ($data['lead_time'] ?? '')),
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    $hasImage = array_key_exists('image_path', $data) && trim((string) $data['image_path']) !== '';
    if ($hasImage) {
        $params[':image_path'] = trim((string) $data['image_path']);
    }

    if ($id) {
        $params[':id'] = $id;
        $set = 'category_id=:category_id, name=:name, slug=:slug, summary=:summary, description=:description,
            price=:price, stock_qty=:stock_qty, max_per_order=:max_per_order, is_active=:is_active,
            is_featured=:is_featured, is_preorder=:is_preorder, is_vsn=:is_vsn, sku=:sku, sale_price=:sale_price, sale_from=:sale_from, sale_to=:sale_to, stock_status=:stock_status, allow_backorder=:allow_backorder, low_stock_amount=:low_stock_amount, shipping_class_id=:shipping_class_id, gtin=:gtin, weight=:weight, length=:length, width=:width, height=:height, preorder_close_at=:preorder_close_at,
            preorder_message=:preorder_message, lead_time=:lead_time, sort_order=:sort_order';
        if ($hasImage) {
            $set .= ', image_path=:image_path';
        }
        $pdo->prepare("UPDATE shop_products SET {$set} WHERE id=:id")->execute($params);
        $productId = $id;
    } else {
        $cols = array_keys($params);
        $fields = implode(', ', array_map(static fn($c) => ltrim($c, ':'), $cols));
        $placeholders = implode(', ', $cols);
        $pdo->prepare("INSERT INTO shop_products ({$fields}) VALUES ({$placeholders})")->execute($params);
        $productId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM shop_product_categories WHERE product_id = :p')->execute([':p' => $productId]);
    $catIns = $pdo->prepare('INSERT INTO shop_product_categories (product_id, category_id) VALUES (:p, :c)');
    foreach ($categoryIds as $cid) {
        $catIns->execute([':p' => $productId, ':c' => $cid]);
    }
    foreach (['upsell' => 'upsell_ids', 'crosssell' => 'crosssell_ids'] as $type => $key) {
        if (array_key_exists($key, $data)) {
            shop_set_product_links($pdo, $productId, $type, array_map('intval', (array) $data[$key]));
        }
    }

    if (isset($data['modifier_options']) && is_array($data['modifier_options'])) {
        // Groups the form reported on ('present') get excluded = all options minus those ticked.
        $pdo->prepare('DELETE FROM shop_product_excluded_options WHERE product_id = :p')->execute([':p' => $productId]);
        $exIns = $pdo->prepare('INSERT IGNORE INTO shop_product_excluded_options (product_id, option_id) VALUES (:p, :o)');
        foreach ((array) ($data['modifier_group_ids'] ?? []) as $gid) {
            if (empty($data['modifier_options_present'][$gid])) {
                continue;
            }
            $keep = array_map('intval', (array) ($data['modifier_options'][$gid] ?? []));
            foreach (shop_group_options($pdo, (int) $gid, true) as $opt) {
                if (!in_array((int) $opt['id'], $keep, true)) {
                    $exIns->execute([':p' => $productId, ':o' => (int) $opt['id']]);
                }
            }
        }
    }

    if (array_key_exists('modifier_group_ids', $data) && is_array($data['modifier_group_ids'])) {
        shop_set_product_groups(
            $pdo,
            $productId,
            array_map('intval', $data['modifier_group_ids']),
            is_array($data['modifier_group_required'] ?? null) ? $data['modifier_group_required'] : []
        );
    }

    return $productId;
}

function shop_delete_product(PDO $pdo, int $id): void
{
    $paid = $pdo->prepare("SELECT COUNT(*) FROM shop_order_items oi JOIN shop_orders o ON o.id = oi.order_id
        WHERE oi.product_id = :id AND o.status IN ('paid','collected','refunded')");
    $paid->execute([':id' => $id]);
    if ((int) $paid->fetchColumn() > 0) {
        // Preserve order history: retire instead of hard delete.
        $pdo->prepare('UPDATE shop_products SET is_active = 0, is_featured = 0 WHERE id = :id')->execute([':id' => $id]);
        throw new RuntimeException('This product has paid orders, so it has been hidden from the shop rather than deleted.');
    }
    $pdo->prepare('DELETE FROM shop_products WHERE id = :id')->execute([':id' => $id]);
}

/**
 * Other active products in the same category, for the product page's
 * "You might also like" strip.
 * @return list<array<string,mixed>>
 */
function shop_related_products(PDO $pdo, array $product, int $limit = 4): array
{
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM shop_products p JOIN shop_categories c ON c.id = p.category_id
        WHERE p.category_id = :cat AND p.id <> :id AND p.is_active = 1 AND c.is_active = 1
        ORDER BY p.sort_order, p.name
        LIMIT ' . max(1, $limit));
    $stmt->execute([':cat' => (int) $product['category_id'], ':id' => (int) $product['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* -------------------------------------------------------------------------
 * Modifier groups & options
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_get_modifier_groups(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query('SELECT g.*, (SELECT COUNT(*) FROM shop_modifier_options o WHERE o.group_id = g.id) AS option_count
        FROM shop_modifier_groups g ORDER BY g.sort_order, g.name')->fetchAll(PDO::FETCH_ASSOC);
}

function shop_get_modifier_group(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_modifier_groups WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_group_options(PDO $pdo, int $groupId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM shop_modifier_options WHERE group_id = :g';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':g' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array<string,mixed> $data */
function shop_save_modifier_group(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Modifier name is required.');
    }
    $type = ($data['selection_type'] ?? 'single') === 'multi' ? 'multi' : 'single';
    $isRequired = !empty($data['is_required']) ? 1 : 0;
    $min = max(0, (int) ($data['min_select'] ?? ($isRequired ? 1 : 0)));
    $maxRaw = trim((string) ($data['max_select'] ?? ''));
    if ($type === 'single') {
        $min = $isRequired ? 1 : 0;
        $max = 1;
    } else {
        $max = $maxRaw === '' ? null : max(1, (int) $maxRaw);
    }
    $slug = shop_unique_slug($pdo, 'shop_modifier_groups', trim((string) ($data['slug'] ?? '')) ?: $name, $id);
    $params = [
        ':name' => $name,
        ':slug' => $slug,
        ':help_text' => trim((string) ($data['help_text'] ?? '')),
        ':selection_type' => $type,
        ':is_required' => $isRequired,
        ':min_select' => $min,
        ':max_select' => $max,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_modifier_groups SET name=:name, slug=:slug, help_text=:help_text,
            selection_type=:selection_type, is_required=:is_required, min_select=:min_select,
            max_select=:max_select, sort_order=:sort_order WHERE id=:id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_modifier_groups (name, slug, help_text, selection_type, is_required, min_select, max_select, sort_order)
        VALUES (:name, :slug, :help_text, :selection_type, :is_required, :min_select, :max_select, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_modifier_group(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_modifier_groups WHERE id = :id')->execute([':id' => $id]);
}

/** @param array<string,mixed> $data */
function shop_save_modifier_option(PDO $pdo, ?int $id, int $groupId, array $data): int
{
    if (!shop_get_modifier_group($pdo, $groupId)) {
        throw new RuntimeException('Unknown modifier group.');
    }
    $label = trim((string) ($data['label'] ?? ''));
    if ($label === '') {
        throw new HubFieldValidationException('label', 'Option label is required.');
    }
    $stockRaw = trim((string) ($data['stock_qty'] ?? ''));
    $params = [
        ':group_id' => $groupId,
        ':option_group' => trim((string) ($data['option_group'] ?? '')),
        ':label' => $label,
        ':price_delta' => round((float) ($data['price_delta'] ?? 0), 2),
        ':sku_suffix' => trim((string) ($data['sku_suffix'] ?? '')),
        ':stock_qty' => $stockRaw === '' ? null : max(0, (int) $stockRaw),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_modifier_options SET option_group=:option_group, label=:label, price_delta=:price_delta, sku_suffix=:sku_suffix,
            stock_qty=:stock_qty, is_active=:is_active, sort_order=:sort_order WHERE id=:id AND group_id=:group_id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_modifier_options (group_id, option_group, label, price_delta, sku_suffix, stock_qty, is_active, sort_order)
        VALUES (:group_id, :option_group, :label, :price_delta, :sku_suffix, :stock_qty, :is_active, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_modifier_option(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_modifier_options WHERE id = :id')->execute([':id' => $id]);
}

/**
 * Modifier groups attached to a product, each with its resolved required flag
 * and its (active) options. Used by the storefront product page and the
 * server-side basket validation.
 *
 * @return list<array<string,mixed>>
 */
function shop_product_groups(PDO $pdo, int $productId, bool $activeOptionsOnly = true): array
{
    $stmt = $pdo->prepare('SELECT g.*, pmg.sort_order AS link_sort, pmg.is_required AS link_required
        FROM shop_product_modifier_groups pmg
        JOIN shop_modifier_groups g ON g.id = pmg.group_id
        WHERE pmg.product_id = :p
        ORDER BY pmg.sort_order, g.sort_order, g.name');
    $stmt->execute([':p' => $productId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $exStmt = $pdo->prepare('SELECT option_id FROM shop_product_excluded_options WHERE product_id = :p');
    $exStmt->execute([':p' => $productId]);
    $excluded = array_map('intval', $exStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($groups as &$group) {
        $group['is_required'] = $group['link_required'] === null
            ? (int) $group['is_required']
            : (int) $group['link_required'];
        $group['options'] = shop_group_options($pdo, (int) $group['id'], $activeOptionsOnly);
        $group['excluded_ids'] = $excluded;
        if ($activeOptionsOnly && $excluded) {
            // Storefront / checkout only ever see the values enabled for this product.
            $group['options'] = array_values(array_filter(
                $group['options'],
                static fn(array $o): bool => !in_array((int) $o['id'], $excluded, true)
            ));
        }
        if ((string) $group['selection_type'] === 'single') {
            $group['min_select'] = $group['is_required'] ? 1 : 0;
            $group['max_select'] = 1;
        }
    }
    unset($group);
    return $groups;
}

/**
 * If every active option in a single-select group carries an option_group
 * heading and there are 2+ distinct headings, the storefront renders a
 * two-step "choose fit, then size" control. Otherwise it falls back to a flat
 * row of chips. Returns the ordered list of headings, or [] when not sectioned.
 *
 * @param array<string,mixed> $group a row from shop_product_groups()
 * @return list<string>
 */
function shop_group_sections(array $group): array
{
    if ((string) ($group['selection_type'] ?? 'single') !== 'single') {
        return [];
    }
    $sections = [];
    foreach (($group['options'] ?? []) as $opt) {
        $heading = trim((string) ($opt['option_group'] ?? ''));
        if ($heading === '') {
            return []; // partly-tagged group — not safe to render as sections
        }
        if (!in_array($heading, $sections, true)) {
            $sections[] = $heading;
        }
    }
    return count($sections) >= 2 ? $sections : [];
}

/**
 * @param list<int> $groupIds
 * @param array<int,mixed> $requiredMap  group_id => truthy when required for this product
 */
function shop_set_product_groups(PDO $pdo, int $productId, array $groupIds, array $requiredMap = []): void
{
    $pdo->prepare('DELETE FROM shop_product_modifier_groups WHERE product_id = :p')->execute([':p' => $productId]);
    if (!$groupIds) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO shop_product_modifier_groups (product_id, group_id, sort_order, is_required)
        VALUES (:p, :g, :sort, :req)');
    $sort = 0;
    foreach (array_values(array_unique($groupIds)) as $groupId) {
        if ($groupId <= 0 || !shop_get_modifier_group($pdo, $groupId)) {
            continue;
        }
        $ins->execute([
            ':p' => $productId,
            ':g' => $groupId,
            ':sort' => ($sort += 10),
            ':req' => array_key_exists($groupId, $requiredMap) ? (!empty($requiredMap[$groupId]) ? 1 : 0) : null,
        ]);
    }
}

/* -------------------------------------------------------------------------
 * Pre-order window
 * ---------------------------------------------------------------------- */

function shop_product_preorder_close(PDO $pdo, array $product): ?DateTimeImmutable
{
    if ((int) ($product['is_preorder'] ?? 0) !== 1) {
        return null;
    }
    $raw = trim((string) ($product['preorder_close_at'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return null; // no closing date set on the product: open until pre-order is switched off
    }
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

/** @return list<int> every category the product is in (primary first). */
function shop_product_category_ids(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT p.category_id AS primary_id, pc.category_id FROM shop_products p
        LEFT JOIN shop_product_categories pc ON pc.product_id = p.id WHERE p.id = :id');
    $stmt->execute([':id' => $productId]);
    $ids = [];
    $primary = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $primary = (int) $r['primary_id'];
        if ($r['category_id'] !== null) {
            $ids[] = (int) $r['category_id'];
        }
    }
    if ($primary > 0) {
        $ids = array_values(array_unique(array_merge([$primary], $ids)));
    }
    return $ids;
}

/** @param list<int> $ids */
function shop_set_product_links(PDO $pdo, int $productId, string $type, array $ids): void
{
    $type = $type === 'crosssell' ? 'crosssell' : 'upsell';
    $pdo->prepare('DELETE FROM shop_product_links WHERE product_id = :p AND link_type = :t')->execute([':p' => $productId, ':t' => $type]);
    $ins = $pdo->prepare('INSERT IGNORE INTO shop_product_links (product_id, linked_id, link_type, sort_order) VALUES (:p, :l, :t, :s)');
    $n = 0;
    foreach (array_unique($ids) as $linkedId) {
        if ($linkedId > 0 && $linkedId !== $productId) {
            $ins->execute([':p' => $productId, ':l' => $linkedId, ':t' => $type, ':s' => $n++]);
        }
    }
}

/** @return list<int> linked product ids (any status) for the admin form. */
function shop_product_link_ids(PDO $pdo, int $productId, string $type): array
{
    $stmt = $pdo->prepare('SELECT linked_id FROM shop_product_links WHERE product_id = :p AND link_type = :t ORDER BY sort_order');
    $stmt->execute([':p' => $productId, ':t' => $type]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Active products linked as upsells/cross-sells. For cross-sells pass several
 * source product ids (the basket); $excludeIds are never returned.
 *
 * @param list<int> $productIds
 * @param list<int> $excludeIds
 * @return list<array<string,mixed>>
 */
function shop_linked_products(PDO $pdo, array $productIds, string $type, array $excludeIds = [], int $limit = 4): array
{
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }
    $in = implode(',', $productIds);
    $ex = $excludeIds ? ' AND p.id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')' : '';
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug, MIN(l.sort_order) AS link_sort
        FROM shop_product_links l JOIN shop_products p ON p.id = l.linked_id JOIN shop_categories c ON c.id = p.category_id
        WHERE l.product_id IN ({$in}) AND l.link_type = :t AND p.is_active = 1 AND c.is_active = 1{$ex}
        GROUP BY p.id ORDER BY link_sort, p.name LIMIT " . max(1, $limit));
    $stmt->execute([':t' => $type === 'crosssell' ? 'crosssell' : 'upsell']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 'instock' | 'outofstock' | 'onbackorder' as a customer should see it. */
function shop_product_availability(array $product): string
{
    if (($product['stock_qty'] ?? null) !== null) {
        if ((int) $product['stock_qty'] > 0) {
            return 'instock';
        }
        return match ((int) ($product['allow_backorder'] ?? 0)) {
            2 => 'onbackorder',   // allow, but notify customer
            1 => 'instock',       // allow quietly
            default => 'outofstock',
        };
    }
    $status = (string) ($product['stock_status'] ?? 'instock');
    return in_array($status, ['outofstock', 'onbackorder'], true) ? $status : 'instock';
}

/** True while a sale price is set and today falls inside its optional schedule. */
function shop_product_on_sale(array $product): bool
{
    if (($product['sale_price'] ?? null) === null || $product['sale_price'] === '') {
        return false;
    }
    if ((float) $product['sale_price'] >= (float) ($product['price'] ?? 0)) {
        return false;
    }
    $now = time();
    if (!empty($product['sale_from']) && strtotime((string) $product['sale_from']) > $now) {
        return false;
    }
    if (!empty($product['sale_to']) && strtotime((string) $product['sale_to']) < $now) {
        return false;
    }
    return true;
}

/** The price a customer pays before option modifiers: sale price if on sale, else regular. */
function shop_product_price(array $product): float
{
    return round((float) (shop_product_on_sale($product) ? $product['sale_price'] : $product['price']), 2);
}

function shop_product_is_orderable(PDO $pdo, array $product): bool
{
    if ((int) ($product['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (shop_product_availability($product) === 'outofstock') {
        return false;
    }
    $close = shop_product_preorder_close($pdo, $product);
    if ($close !== null && new DateTimeImmutable('now') > $close) {
        return false;
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Selection resolution / pricing  (server-authoritative)
 * ---------------------------------------------------------------------- */

/**
 * Validate a customer's modifier choices for a product and compute the unit
 * price. $selected is  group_id => (int option_id | list<int> option_ids).
 *
 * @return array{
 *   base_price: float, unit_price: float, options_label: string,
 *   options: list<array{group_id:int,group_name:string,option_id:int,option_label:string,price_delta:float}>
 * }
 * @throws RuntimeException on any invalid / incomplete selection
 */
function shop_resolve_selection(PDO $pdo, array $product, array $selected): array
{
    $base = shop_product_price($product);
    $unit = $base;
    $chosen = [];
    $labels = [];

    foreach (shop_product_groups($pdo, (int) $product['id'], true) as $group) {
        $groupId = (int) $group['id'];
        $raw = $selected[$groupId] ?? [];
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw])));
        $ids = array_values(array_unique($ids));

        $optionById = [];
        foreach ($group['options'] as $opt) {
            $optionById[(int) $opt['id']] = $opt;
        }
        foreach ($ids as $optId) {
            if (!isset($optionById[$optId])) {
                throw new RuntimeException('Please choose a valid ' . $group['name'] . '.');
            }
        }

        $min = (int) $group['min_select'];
        $max = $group['max_select'] === null ? PHP_INT_MAX : (int) $group['max_select'];
        if (count($ids) < $min) {
            throw new RuntimeException($min === 1
                ? 'Please choose a ' . strtolower((string) $group['name']) . '.'
                : 'Please choose at least ' . $min . ' ' . $group['name'] . ' options.');
        }
        if (count($ids) > $max) {
            throw new RuntimeException('Please choose no more than ' . $max . ' ' . $group['name'] . ' options.');
        }

        foreach ($ids as $optId) {
            $opt = $optionById[$optId];
            if (($opt['stock_qty'] ?? null) !== null && (int) $opt['stock_qty'] <= 0) {
                throw new RuntimeException($opt['label'] . ' is out of stock.');
            }
            $delta = round((float) $opt['price_delta'], 2);
            $unit += $delta;
            $heading = trim((string) ($opt['option_group'] ?? ''));
            $displayLabel = $heading !== '' ? $heading . ' ' . (string) $opt['label'] : (string) $opt['label'];
            $chosen[] = [
                'group_id' => $groupId,
                'group_name' => (string) $group['name'],
                'option_id' => $optId,
                'option_group' => $heading,
                'option_label' => (string) $opt['label'],
                'price_delta' => $delta,
            ];
            $labels[] = $group['name'] . ': ' . $displayLabel;
        }
    }

    return [
        'base_price' => $base,
        'unit_price' => round($unit, 2),
        'options_label' => implode(' · ', $labels),
        'options' => $chosen,
    ];
}

/* -------------------------------------------------------------------------
 * Discount codes
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_discount_codes(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query('SELECT * FROM shop_discount_codes ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array<string,mixed> $data */
function shop_save_discount_code(PDO $pdo, ?int $id, array $data): int
{
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code) ?? '';
    if ($code === '') {
        throw new HubFieldValidationException('code', 'Enter a code.');
    }
    $maxUsesRaw = trim((string) ($data['max_uses'] ?? ''));
    $params = [
        ':code' => $code,
        ':discount_type' => ($data['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
        ':discount_value' => max(0, round((float) ($data['discount_value'] ?? 0), 2)),
        ':min_spend' => max(0, round((float) ($data['min_spend'] ?? 0), 2)),
        ':max_uses' => $maxUsesRaw === '' ? null : max(1, (int) $maxUsesRaw),
        ':starts_at' => trim((string) ($data['starts_at'] ?? '')) ?: null,
        ':ends_at' => trim((string) ($data['ends_at'] ?? '')) ?: null,
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_discount_codes SET code=:code, discount_type=:discount_type, discount_value=:discount_value,
            min_spend=:min_spend, max_uses=:max_uses, starts_at=:starts_at, ends_at=:ends_at, is_active=:is_active WHERE id=:id')
            ->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_discount_codes (code, discount_type, discount_value, min_spend, max_uses, starts_at, ends_at, is_active)
        VALUES (:code, :discount_type, :discount_value, :min_spend, :max_uses, :starts_at, :ends_at, :is_active)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_discount_code(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_discount_codes WHERE id = :id')->execute([':id' => $id]);
}

/**
 * @return array{ok:bool, error:string, code:string, amount:float}
 */
function shop_validate_discount(PDO $pdo, string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'code' => '', 'amount' => 0.0];
    if ($code === '') {
        return $fail('');
    }
    $stmt = $pdo->prepare('SELECT * FROM shop_discount_codes WHERE code = :c');
    $stmt->execute([':c' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['is_active'] !== 1) {
        return $fail('That code is not valid.');
    }
    $now = new DateTimeImmutable('now');
    if (!empty($row['starts_at']) && $now < new DateTimeImmutable((string) $row['starts_at'])) {
        return $fail('That code is not active yet.');
    }
    if (!empty($row['ends_at']) && $now > new DateTimeImmutable((string) $row['ends_at'])) {
        return $fail('That code has expired.');
    }
    if ($row['max_uses'] !== null && (int) $row['used_count'] >= (int) $row['max_uses']) {
        return $fail('That code has already been fully redeemed.');
    }
    if ($subtotal < (float) $row['min_spend']) {
        return $fail('Spend at least ' . gbp((float) $row['min_spend']) . ' to use that code.');
    }
    $amount = (string) $row['discount_type'] === 'fixed'
        ? min($subtotal, (float) $row['discount_value'])
        : round($subtotal * ((float) $row['discount_value'] / 100), 2);
    $amount = min($subtotal, max(0.0, $amount));
    return ['ok' => true, 'error' => '', 'code' => $code, 'amount' => $amount];
}

/* -------------------------------------------------------------------------
 * Orders
 * ---------------------------------------------------------------------- */

/**
 * @param array{name:string,email:string,phone:string,note:string,marketing_opt_in:bool} $customer
 * @param list<array{
 *   product_id:int, product_name:string, options_label:string, options:array,
 *   base_price:float, unit_price:float, quantity:int, is_preorder:bool
 * }> $lines  already validated & priced server-side
 * @param array{discount_code?:string, terms_accepted?:bool} $meta
 */
function shop_manual_credit(string $raw, string $reason, float $available): float
{
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $raw)) {
        throw new RuntimeException('Enter a valid credit amount with up to two decimal places.');
    }
    $credit = round((float) $raw, 2);
    if ($credit > $available) {
        throw new RuntimeException('Credit cannot exceed the items total after discount.');
    }
    if ($credit > 0 && trim($reason) === '') {
        throw new RuntimeException('Enter a reason for the credit.');
    }
    return $credit;
}

/**
 * Atomically reserve stock for one product line, inside the caller's
 * transaction (uses a locking read so two simultaneous checkouts for the
 * last item can't both succeed). Unlimited-stock items (stock_qty IS NULL)
 * are a no-op. Throws if there isn't enough left.
 */
function shop_reserve_product_stock(PDO $pdo, int $productId, int $qty, string $label): void
{
    $row = $pdo->prepare('SELECT stock_qty, allow_backorder, low_stock_amount FROM shop_products WHERE id = :id FOR UPDATE');
    $row->execute([':id' => $productId]);
    $found = $row->fetch(PDO::FETCH_NUM);
    if ($found === false) {
        throw new RuntimeException($label . ' is no longer available.');
    }
    $current = $found[0];
    if ($current === null) {
        return;
    }
    $before = (int) $current;
    $lowOverride = $found[2] === null ? null : (int) $found[2];
    if ((int) $found[1] > 0) {
        // Backorders allowed: stock may go negative (the number owed).
        $pdo->prepare('UPDATE shop_products SET stock_qty = stock_qty - :q WHERE id = :id')->execute([':q' => $qty, ':id' => $productId]);
        return;
    }
    $stmt = $pdo->prepare('UPDATE shop_products SET stock_qty = stock_qty - :q WHERE id = :id AND stock_qty >= :q2');
    $stmt->execute([':q' => $qty, ':id' => $productId, ':q2' => $qty]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Sorry, there isn\'t enough stock left of ' . $label . '.');
    }
    shop_maybe_send_low_stock_alert($pdo, $label, $before, $before - $qty, $lowOverride);
}

/** Same as shop_reserve_product_stock() but for a modifier option's own stock. */
function shop_reserve_option_stock(PDO $pdo, int $optionId, int $qty, string $label): void
{
    $row = $pdo->prepare('SELECT stock_qty FROM shop_modifier_options WHERE id = :id FOR UPDATE');
    $row->execute([':id' => $optionId]);
    $current = $row->fetchColumn();
    if ($current === false) {
        throw new RuntimeException($label . ' is no longer available.');
    }
    if ($current === null) {
        return;
    }
    $before = (int) $current;
    $stmt = $pdo->prepare('UPDATE shop_modifier_options SET stock_qty = stock_qty - :q WHERE id = :id AND stock_qty >= :q2');
    $stmt->execute([':q' => $qty, ':id' => $optionId, ':q2' => $qty]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Sorry, there isn\'t enough stock left of ' . $label . '.');
    }
    shop_maybe_send_low_stock_alert($pdo, $label, $before, $before - $qty);
}

/**
 * Reverse a stock reservation (order cancelled/expired before payment).
 * Safe to call even for unlimited-stock items (stock_qty IS NOT NULL guard).
 */
function shop_release_order_stock(PDO $pdo, int $orderId): void
{
    foreach (shop_order_items($pdo, $orderId) as $item) {
        if ($item['product_id'] !== null) {
            $pdo->prepare('UPDATE shop_products SET stock_qty = stock_qty + :q WHERE id = :id AND stock_qty IS NOT NULL')
                ->execute([':q' => (int) $item['quantity'], ':id' => (int) $item['product_id']]);
        }
        foreach ((array) json_decode((string) ($item['options_json'] ?? '[]'), true) as $opt) {
            if (!empty($opt['option_id'])) {
                $pdo->prepare('UPDATE shop_modifier_options SET stock_qty = stock_qty + :q WHERE id = :id AND stock_qty IS NOT NULL')
                    ->execute([':q' => (int) $item['quantity'], ':id' => (int) $opt['option_id']]);
            }
        }
    }
}

function shop_create_order(PDO $pdo, array $customer, array $lines, array $meta = []): array
{
    if (!$lines) {
        throw new RuntimeException('Your basket is empty.');
    }
    $subtotal = 0.0;
    $isPreorder = false;
    foreach ($lines as $line) {
        $subtotal += round((float) $line['unit_price'] * (int) $line['quantity'], 2);
        $isPreorder = $isPreorder || !empty($line['is_preorder']);
    }
    $subtotal = round($subtotal, 2);

    $discount = shop_validate_discount($pdo, (string) ($meta['discount_code'] ?? ''), $subtotal);
    $discountAmount = $discount['ok'] ? $discount['amount'] : 0.0;
    // Only the authenticated admin entry point supplies manual_credit.
    $credit = shop_manual_credit((string) ($meta['manual_credit'] ?? '0'),
        (string) ($meta['credit_reason'] ?? ''), round($subtotal - $discountAmount, 2));
    if ($credit > 0) {
        $customer['note'] = trim((string) ($customer['note'] ?? '')) . "\nCredit applied: " . gbp($credit)
            . ' — ' . trim((string) $meta['credit_reason']);
        $discountAmount = round($discountAmount + $credit, 2);
    }


    // Delivery only ever applies if the setting is actually on — even if a
    // stale/tampered form posts fulfilment_method=delivery while it's off,
    // the order is still created as a collection with no fee.
    $wantsDelivery = (string) ($meta['fulfilment_method'] ?? 'collection') === 'delivery';
    $fulfilmentMethod = $wantsDelivery && shop_delivery_enabled($pdo) ? 'delivery' : 'collection';
    $deliveryAddress = $fulfilmentMethod === 'delivery' ? trim((string) ($meta['delivery_address'] ?? '')) : '';
    if ($fulfilmentMethod === 'delivery' && $deliveryAddress === '') {
        throw new RuntimeException('A delivery address is required.');
    }
    $deliveryFee = 0.0;
    $shipZoneName = '';
    $shipMethodName = '';
    $deliveryCountry = null;
    $deliveryPostcode = '';
    if ($fulfilmentMethod === 'delivery') {
        $shipItems = array_map(static fn(array $l): array => ['product_id' => (int) ($l['product_id'] ?? 0), 'quantity' => max(1, (int) $l['quantity'])], $lines);
        $shipBasis = round(max(0.0, $subtotal - $discountAmount), 2);
        $deliveryCountry = strtoupper(trim((string) ($meta['delivery_country'] ?? ''))) ?: null;
        $deliveryPostcode = mb_substr(trim((string) ($meta['delivery_postcode'] ?? '')), 0, 20);
        $methodId = (int) ($meta['shipping_method_id'] ?? 0);
        $picked = null;
        if (!empty($meta['admin_any_method'])) {
            // Staff-entered order: any active method may be used regardless of zone.
            $m = $methodId > 0 ? shop_shipping_method($pdo, $methodId) : null;
            $cost = $m && (int) $m['is_active'] === 1 ? shop_shipping_method_cost($pdo, $m, $shipItems, $shipBasis) : null;
            if ($m && $cost !== null) {
                $picked = ['name' => (string) $m['name'], 'cost' => $cost, 'zone' => (string) $m['zone_name']];
            }
        } else {
            if ($deliveryCountry === null || !isset(shop_countries()[$deliveryCountry])) {
                throw new RuntimeException('Please choose a delivery country.');
            }
            $quote = shop_shipping_quote($pdo, $deliveryCountry, $deliveryPostcode, $shipItems, $shipBasis);
            if ($quote['zone'] === null || $quote['methods'] === []) {
                throw new RuntimeException('Sorry, we can\'t deliver to that address. Please choose collection or contact the club.');
            }
            foreach ($quote['methods'] as $qm) {
                if ($qm['id'] === $methodId) {
                    $picked = ['name' => $qm['name'], 'cost' => $qm['cost'], 'zone' => (string) $quote['zone']['name']];
                }
            }
        }
        if ($picked === null) {
            throw new RuntimeException('Please choose a delivery option.');
        }
        $deliveryFee = (float) $picked['cost'];
        $shipZoneName = $picked['zone'];
        $shipMethodName = $picked['name'];
    }

    $total = round(max(0.0, $subtotal - $discountAmount) + $deliveryFee, 2);

    $collectionPoint = shop_setting($pdo, 'collection_point', SHOP_COLLECTION_POINT_DEFAULT);
    $fulfilmentNote = $fulfilmentMethod === 'delivery' ? 'Deliver to customer address.' : 'Collect from ' . $collectionPoint . '.';
    $batchNote = $fulfilmentNote;
    if ($isPreorder) {
        $preInfo = shop_preorder_info($pdo, array_map(static fn(array $l): int => (int) ($l['product_id'] ?? 0), $lines));
        $batchNote = 'Contains pre-order item(s)'
            . ($preInfo['close'] !== null ? ' — pre-orders close ' . $preInfo['close']->format('j M Y') : '')
            . ($preInfo['lead'] !== null ? '; VSN lead time ' . $preInfo['lead'] : '')
            . '. ' . $fulfilmentNote;
    }

    $pdo->beginTransaction();
    try {
        $ref = shop_generate_order_ref($pdo);
        $token = shop_generate_token();
        $pdo->prepare('INSERT INTO shop_orders
            (order_ref, access_token, customer_name, customer_email, customer_phone, status, subtotal,
             discount_code, discount_total, total, currency, is_preorder, fulfilment_method, collection_point,
             delivery_fee, delivery_address, batch_note, customer_note, marketing_opt_in, terms_accepted_at,
             shipping_zone, shipping_method, delivery_country, delivery_postcode)
            VALUES
            (:ref, :token, :name, :email, :phone, \'pending_payment\', :subtotal,
             :discount_code, :discount_total, :total, \'GBP\', :is_preorder, :fulfilment_method, :collection_point,
             :delivery_fee, :delivery_address, :batch_note, :note, :marketing, :terms_at,
             :ship_zone, :ship_method, :del_country, :del_postcode)')
            ->execute([
                ':ref' => $ref,
                ':token' => $token,
                ':name' => trim((string) $customer['name']),
                ':email' => trim((string) $customer['email']),
                ':phone' => trim((string) ($customer['phone'] ?? '')),
                ':subtotal' => $subtotal,
                ':discount_code' => $discount['ok'] ? $discount['code'] : '',
                ':discount_total' => $discountAmount,
                ':total' => $total,
                ':is_preorder' => $isPreorder ? 1 : 0,
                ':fulfilment_method' => $fulfilmentMethod,
                ':collection_point' => $collectionPoint,
                ':delivery_fee' => $deliveryFee,
                ':delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
                ':batch_note' => $batchNote,
                ':note' => trim((string) ($customer['note'] ?? '')) ?: null,
                ':marketing' => !empty($customer['marketing_opt_in']) ? 1 : 0,
                ':terms_at' => !empty($meta['terms_accepted']) ? date('Y-m-d H:i:s') : null,
                ':ship_zone' => $shipZoneName,
                ':ship_method' => $shipMethodName,
                ':del_country' => $deliveryCountry,
                ':del_postcode' => $deliveryPostcode,
            ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO shop_order_items
            (order_id, product_id, product_name, options_label, options_json, base_price, unit_price, quantity, line_total, is_preorder, is_vsn)
            VALUES (:order_id, :product_id, :product_name, :options_label, :options_json, :base_price, :unit_price, :quantity, :line_total, :is_preorder, :is_vsn)');
        $vsnLookup = $pdo->prepare('SELECT is_vsn FROM shop_products WHERE id = :id');
        foreach ($lines as $line) {
            $isVsnLine = 0;
            if (!empty($line['product_id'])) {
                $vsnLookup->execute([':id' => (int) $line['product_id']]);
                $isVsnLine = (int) $vsnLookup->fetchColumn() === 1 ? 1 : 0;
            }
            $qty = max(1, (int) $line['quantity']);
            if (!empty($line['product_id'])) {
                shop_reserve_product_stock($pdo, (int) $line['product_id'], $qty, (string) $line['product_name']);
            }
            foreach (($line['options'] ?? []) as $opt) {
                if (!empty($opt['option_id'])) {
                    shop_reserve_option_stock($pdo, (int) $opt['option_id'], $qty,
                        (string) $line['product_name'] . ' — ' . (string) ($opt['option_label'] ?? ''));
                }
            }
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => (int) $line['product_id'] ?: null,
                ':product_name' => (string) $line['product_name'],
                ':options_label' => (string) ($line['options_label'] ?? ''),
                ':options_json' => json_encode($line['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':base_price' => round((float) $line['base_price'], 2),
                ':unit_price' => round((float) $line['unit_price'], 2),
                ':quantity' => $qty,
                ':line_total' => round((float) $line['unit_price'] * $qty, 2),
                ':is_preorder' => !empty($line['is_preorder']) ? 1 : 0,
                ':is_vsn' => $isVsnLine,
            ]);
        }

        if ($deliveryFee > 0) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => null,
                ':product_name' => $shipMethodName !== '' ? 'Delivery — ' . $shipMethodName : 'Delivery',
                ':options_label' => '',
                ':options_json' => '[]',
                ':base_price' => $deliveryFee,
                ':unit_price' => $deliveryFee,
                ':quantity' => 1,
                ':line_total' => $deliveryFee,
                ':is_preorder' => 0,
                ':is_vsn' => 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return shop_get_order($pdo, $orderId);
}

function shop_get_order(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_order_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE access_token = :t');
    $stmt->execute([':t' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_order_by_session(PDO $pdo, string $sessionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE stripe_checkout_session_id = :s');
    $stmt->execute([':s' => $sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array<string,mixed> $filters  status, search, preorder_only
 * @return list<array<string,mixed>>
 */
function shop_order_list(PDO $pdo, array $filters = []): array
{
    shop_ensure_schema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'o.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['preorder_only'])) {
        $where[] = 'o.is_preorder = 1';
    }
    if (!empty($filters['search'])) {
        $where[] = '(o.order_ref LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'o.created_at >= :date_from';
        $params[':date_from'] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'o.created_at <= :date_to';
        $params[':date_to'] = (string) $filters['date_to'];
    }
    $sql = 'SELECT o.*,
            (SELECT COALESCE(SUM(quantity),0) FROM shop_order_items i WHERE i.order_id = o.id) AS item_count,
            EXISTS(SELECT 1 FROM shop_order_items v WHERE v.order_id = o.id AND v.is_vsn = 1) AS has_vsn
            FROM shop_orders o';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.created_at DESC, o.id DESC';
    if (!empty($filters['limit'])) {
        $sql .= ' LIMIT ' . (int) $filters['limit'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shop_attach_stripe_session(PDO $pdo, int $orderId, string $sessionId): void
{
    $pdo->prepare('UPDATE shop_orders SET stripe_checkout_session_id = :s WHERE id = :id')
        ->execute([':s' => $sessionId, ':id' => $orderId]);
}

/**
 * Idempotent — safe to call from the webhook, the confirmation page fallback,
 * or an admin "mark paid". Decrements stock, bumps discount usage and sends
 * the confirmation email exactly once.
 */
function shop_mark_order_paid(PDO $pdo, int $orderId, ?string $paymentIntentId = null): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        return;
    }
    if (in_array((string) $order['status'], ['paid', 'collected', 'refunded'], true)) {
        if ($paymentIntentId && empty($order['stripe_payment_intent_id'])) {
            $pdo->prepare('UPDATE shop_orders SET stripe_payment_intent_id = :pi WHERE id = :id')
                ->execute([':pi' => $paymentIntentId, ':id' => $orderId]);
        }
        return;
    }

    $pdo->prepare("UPDATE shop_orders SET status = 'paid', paid_at = COALESCE(paid_at, NOW()),
        stripe_payment_intent_id = COALESCE(:pi, stripe_payment_intent_id) WHERE id = :id AND status <> 'paid'")
        ->execute([':pi' => $paymentIntentId, ':id' => $orderId]);

    // Stock was already reserved when the order was created (see
    // shop_reserve_product_stock()/shop_reserve_option_stock() in
    // shop_create_order()) — paying just confirms the reservation, it
    // doesn't decrement again. Only discount usage is counted here.
    if (!empty($order['discount_code'])) {
        $pdo->prepare('UPDATE shop_discount_codes SET used_count = used_count + 1 WHERE code = :c')
            ->execute([':c' => (string) $order['discount_code']]);
    }

    try {
        shop_send_confirmation_email($pdo, $orderId);
        shop_send_admin_notification($pdo, $orderId);
    } catch (Throwable $e) {
        error_log('[shop] confirmation email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function shop_cancel_order(PDO $pdo, int $orderId, string $reason = ''): void
{
    $order = shop_get_order($pdo, $orderId);
    // Already-cancelled is excluded too — otherwise a second cancel call
    // (e.g. a retried webhook) would release the reserved stock twice.
    if (!$order || in_array((string) $order['status'], ['paid', 'collected', 'refunded', 'cancelled'], true)) {
        return;
    }
    $pdo->prepare("UPDATE shop_orders SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()),
        customer_note = TRIM(CONCAT(COALESCE(customer_note,''), :note)) WHERE id = :id")
        ->execute([':note' => $reason !== '' ? "\n[cancelled] " . $reason : '', ':id' => $orderId]);
    shop_release_order_stock($pdo, $orderId);
    try {
        shop_send_status_email($pdo, $orderId, 'cancelled', $reason);
    } catch (Throwable $e) {
        error_log('[shop] cancellation email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function shop_mark_collected(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE shop_orders SET status = 'collected', collected_at = COALESCE(collected_at, NOW())
        WHERE id = :id AND status = 'paid'")->execute([':id' => $orderId]);
    try {
        shop_send_status_email($pdo, $orderId, 'collected');
    } catch (Throwable $e) {
        error_log('[shop] collected email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function shop_mark_vsn_ordered(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE shop_orders SET vsn_ordered_at = COALESCE(vsn_ordered_at, NOW()) WHERE id = :id')
        ->execute([':id' => $orderId]);
}

/**
 * Orders containing VSN-manufactured items not yet sent to VSN — the set the VSN order form PDF
 * covers. Includes both paid and pending-payment orders (so the club can
 * still plan the manufacturing run around someone who hasn't paid yet), but
 * not cancelled/refunded/collected ones. Only pre-order items (VSN
 * manufactures to order; regular in-stock products don't go through them)
 * that haven't already been flagged sent.
 *
 * @return list<array<string, mixed>>
 */
function shop_orders_pending_vsn(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM shop_orders
        WHERE status IN ('paid', 'pending_payment') AND vsn_ordered_at IS NULL
          AND EXISTS (SELECT 1 FROM shop_order_items v WHERE v.order_id = shop_orders.id AND v.is_vsn = 1)
        ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Marks every currently-pending order (shop_orders_pending_vsn()) as sent —
 * the bulk equivalent of shop_mark_vsn_ordered() for one order, used after
 * downloading the combined VSN order form PDF.
 */
function shop_mark_all_vsn_ordered(PDO $pdo): int
{
    $stmt = $pdo->prepare("UPDATE shop_orders SET vsn_ordered_at = NOW()
        WHERE status = 'paid' AND vsn_ordered_at IS NULL
          AND EXISTS (SELECT 1 FROM shop_order_items v WHERE v.order_id = shop_orders.id AND v.is_vsn = 1)");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Renders one combined VSN kit order form PDF covering every order in
 * $orders (normally shop_orders_pending_vsn()) — per-customer detail (who
 * ordered what, sizes, contact details) for the order sent to VSN. Same
 * dompdf pattern as invoice_pdf_render().
 *
 * @param list<array<string, mixed>> $orders
 */
function shop_vsn_order_form_pdf_render(PDO $pdo, array $orders, bool $forceDownload = false, bool $returnBytesOnly = false): string
{
    require_once __DIR__ . '/site_settings.php';
    $settings = site_settings_all($pdo);
    $clubName = $settings['club_name'] ?: 'Saltcoats Victoria';

    $orderItems = []; // order id => items
    foreach ($orders as $order) {
        // Only the VSN-manufactured lines belong on the VSN form.
        $orderItems[(int) $order['id']] = array_values(array_filter(
            shop_order_items($pdo, (int) $order['id']),
            static fn(array $it): bool => (int) ($it['is_vsn'] ?? 0) === 1
        ));
    }

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color:#21141a; font-size:12px; margin:0; }
        h1 { margin:0 0 2px; color:#4b0818; font-size:22px; }
        h2 { margin:24px 0 8px; color:#4b0818; font-size:15px; }
        .muted { color:#6f6470; }
        table.items { width:100%; border-collapse:collapse; margin-top:6px; }
        table.items th { background:#4b0818; color:#fff; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.03em; padding:6px 8px; }
        table.items td { border-bottom:1px solid #eadfdf; padding:6px 8px; }
        table.items td.num, table.items th.num { text-align:right; }
        .order-block { margin-top:16px; page-break-inside:avoid; }
        .order-block .ref { font-weight:bold; }
        .order-block .contact { color:#6f6470; }
        .status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:9px; text-transform:uppercase; font-weight:bold; margin-left:6px; }
        .status-paid { background:#d7f0dd; color:#146c2e; }
        .status-pending_payment { background:#fbe7c6; color:#8a5a00; }
    </style></head><body>';

    $paidCount = count(array_filter($orders, static fn(array $o): bool => (string) $o['status'] === 'paid'));
    $pendingCount = count($orders) - $paidCount;

    $html .= '<div><strong>' . h($clubName) . '</strong></div>';
    $html .= '<h1>VSN Kit Order Form</h1>';
    $html .= '<div class="muted">Generated ' . h(date('d/m/Y H:i')) . ' — ' . count($orders) . ' order' . (count($orders) === 1 ? '' : 's')
        . ' (' . $paidCount . ' paid, ' . $pendingCount . ' awaiting payment)</div>';

    $html .= '<h2>Order detail</h2>';
    foreach ($orders as $order) {
        $orderStatus = (string) $order['status'];
        $statusLabel = $orderStatus === 'paid' ? 'Paid' : 'Awaiting payment';
        $html .= '<div class="order-block">';
        $html .= '<div class="ref">' . h((string) $order['order_ref']) . '<span class="status-badge status-' . h($orderStatus) . '">' . h($statusLabel) . '</span></div>';
        $html .= '<div class="contact">' . h((string) $order['customer_name']);
        if (!empty($order['customer_email'])) {
            $html .= ' &middot; ' . h((string) $order['customer_email']);
        }
        if (!empty($order['customer_phone'])) {
            $html .= ' &middot; ' . h((string) $order['customer_phone']);
        }
        $html .= '</div>';
        $html .= '<table class="items"><thead><tr><th>Product</th><th>Size / options</th><th class="num">Qty</th></tr></thead><tbody>';
        foreach ($orderItems[(int) $order['id']] as $item) {
            $html .= '<tr><td>' . h((string) $item['product_name']) . '</td><td>' . h((string) $item['options_label'] ?: '—') . '</td><td class="num">' . (int) $item['quantity'] . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '</body></html>';

    if (!class_exists(Dompdf\Dompdf::class)) {
        $autoloaders = [dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/project_1/vendor/autoload.php'];
        foreach ($autoloaders as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;
                break;
            }
        }
    }
    if (!class_exists(Dompdf\Dompdf::class)) {
        throw new RuntimeException('PDF export is unavailable.');
    }

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    if ($returnBytesOnly) {
        return (string) $dompdf->output();
    }

    if (ob_get_length() !== false) {
        ob_clean();
    }
    $dompdf->stream('vsn-order-form-' . date('Ymd-His') . '.pdf', ['Attachment' => $forceDownload]);
    return '';
}

/**
 * Refund via Stripe (full or partial) and record it on the order.
 */
function shop_refund_order(PDO $pdo, int $orderId, float $amount, string $reason = ''): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $paymentIntent = (string) ($order['stripe_payment_intent_id'] ?? '');
    if ($paymentIntent === '') {
        throw new RuntimeException('This order has no Stripe payment to refund.');
    }
    $already = (float) $order['amount_refunded'];
    $remaining = round((float) $order['total'] - $already, 2);
    $amount = round(min($amount, $remaining), 2);
    if ($amount <= 0) {
        throw new RuntimeException('Nothing left to refund on this order.');
    }

    $params = [
        'payment_intent' => $paymentIntent,
        'amount' => stripe_amount_to_minor_units($amount, SHOP_CURRENCY),
        'metadata' => ['kind' => 'shop', 'order_id' => (string) $orderId, 'order_ref' => (string) $order['order_ref']],
    ];
    if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
        $params['reason'] = $reason;
    }
    $refund = stripe_request('POST', '/refunds', $params);

    $newRefunded = round($already + $amount, 2);
    $fullyRefunded = $newRefunded >= (float) $order['total'] - 0.001;
    $pdo->prepare('UPDATE shop_orders SET amount_refunded = :amt, stripe_refund_id = :rid,
        status = IF(:full = 1, \'refunded\', status) WHERE id = :id')
        ->execute([
            ':amt' => $newRefunded,
            ':rid' => (string) ($refund['id'] ?? ''),
            ':full' => $fullyRefunded ? 1 : 0,
            ':id' => $orderId,
        ]);

    try {
        shop_send_status_email($pdo, $orderId, 'refunded', '', $amount);
    } catch (Throwable $e) {
        error_log('[shop] refund email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * Stripe Checkout
 * ---------------------------------------------------------------------- */

/**
 * @return array{url:string, session_id:string}
 */
function shop_create_checkout_session(PDO $pdo, array $order, string $successUrl, string $cancelUrl): array
{
    $items = shop_order_items($pdo, (int) $order['id']);
    if (!$items) {
        throw new RuntimeException('This order has no items.');
    }

    $lineItems = [];
    foreach ($items as $item) {
        $lineItems[] = [
            'quantity' => (int) $item['quantity'],
            'price_data' => [
                'currency' => SHOP_CURRENCY,
                'unit_amount' => stripe_amount_to_minor_units((float) $item['unit_price'], SHOP_CURRENCY),
                'product_data' => array_filter([
                    'name' => (string) $item['product_name'],
                    'description' => (string) ($item['options_label'] ?: null),
                ]),
            ],
        ];
    }

    // A basket-level discount becomes a Stripe coupon so Stripe's total matches ours.
    $discounts = [];
    if ((float) $order['discount_total'] > 0) {
        $coupon = stripe_request('POST', '/coupons', [
            'amount_off' => stripe_amount_to_minor_units((float) $order['discount_total'], SHOP_CURRENCY),
            'currency' => SHOP_CURRENCY,
            'duration' => 'once',
            'name' => 'Discount ' . (string) $order['discount_code'],
        ]);
        $discounts[] = ['coupon' => (string) $coupon['id']];
    }

    $metadata = [
        'kind' => 'shop',
        'order_id' => (string) $order['id'],
        'order_ref' => (string) $order['order_ref'],
    ];
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'line_items' => $lineItems,
        'client_reference_id' => (string) $order['order_ref'],
        'metadata' => $metadata,
        'payment_intent_data' => ['metadata' => $metadata],
    ];
    if ($discounts) {
        $params['discounts'] = $discounts;
    }
    $email = (string) $order['customer_email'];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $params['customer_email'] = $email;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) $session['id'];
    shop_attach_stripe_session($pdo, (int) $order['id'], $sessionId);

    return ['url' => (string) $session['url'], 'session_id' => $sessionId];
}

/** @param array<string,mixed> $session decoded Stripe Checkout Session */
function shop_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    shop_ensure_schema($pdo);
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $order = shop_get_order_by_session($pdo, $sessionId);
    if (!$order) {
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);
        $order = $orderId > 0 ? shop_get_order($pdo, $orderId) : null;
    }
    if (!$order) {
        error_log('[shop] checkout.session.completed for unknown session ' . $sessionId);
        return;
    }
    $paymentIntent = is_string($session['payment_intent'] ?? null) ? (string) $session['payment_intent'] : null;
    shop_mark_order_paid($pdo, (int) $order['id'], $paymentIntent);
}

/** @param array<string,mixed> $session decoded Stripe Checkout Session */
function shop_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    shop_ensure_schema($pdo);
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }
    $order = shop_get_order_by_session($pdo, $sessionId);
    if ($order && (string) $order['status'] === 'pending_payment') {
        shop_cancel_order($pdo, (int) $order['id'], 'Stripe Checkout session expired');
    }
}

/**
 * Confirmation-page fallback for when the webhook hasn't landed yet: pull the
 * session straight from Stripe and finalise if it's paid.
 */
function shop_finalize_pending_order_from_stripe(PDO $pdo, array $order): array
{
    if ((string) $order['status'] !== 'pending_payment') {
        return $order;
    }
    $sessionId = (string) ($order['stripe_checkout_session_id'] ?? '');
    if ($sessionId === '' || !stripe_is_configured()) {
        return $order;
    }
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
        if ((string) ($session['payment_status'] ?? '') === 'paid') {
            $pi = is_string($session['payment_intent'] ?? null) ? (string) $session['payment_intent'] : null;
            shop_mark_order_paid($pdo, (int) $order['id'], $pi);
        } elseif ((string) ($session['status'] ?? '') === 'expired') {
            shop_stripe_handle_checkout_expired($pdo, $session);
        }
    } catch (Throwable $e) {
        error_log('[shop] finalize fallback failed for order ' . $order['id'] . ': ' . $e->getMessage());
    }
    return shop_get_order($pdo, (int) $order['id']) ?? $order;
}

/* -------------------------------------------------------------------------
 * Email
 * ---------------------------------------------------------------------- */

function shop_email_wrapper(string $subject, string $preheader, string $heroTitle, string $heroSubtitle, string $bodyHtml, ?PDO $pdo = null, ?array $styleOverride = null): string
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    $st = $pdo instanceof PDO ? shop_email_style($pdo, $styleOverride) : shop_email_style_defaults();
    $vars = $pdo instanceof PDO ? shop_email_base_vars($pdo) : ['club_name' => 'Saltcoats Victoria FC', 'shop_name' => 'Saltcoats Victoria FC Club Shop', 'site_url' => ''];
    $footer = h(shop_email_fill($st['email_footer_text'], $vars));
    $header = $st['email_header_image'] !== ''
        ? '<img src="' . h($st['email_header_image']) . '" alt="" width="' . (int) $st['email_header_image_width'] . '" style="display:block;width:' . (int) $st['email_header_image_width'] . 'px;max-width:100%;height:auto;margin:0 ' . ($st['email_header_align'] === 'right' ? '0 14px auto' : ($st['email_header_align'] === 'center' ? 'auto 14px' : '0 14px 0')) . ';border:0;">'
        : '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.74);">' . h($vars['club_name'] ?? 'Saltcoats Victoria FC') . '</div>';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($subject) . '</title></head>
    <body style="margin:0;padding:0;background:' . $st['email_bg_color'] . ';font-family:Inter,Arial,sans-serif;color:' . $st['email_text_color'] . ';">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . h($preheader) . '</div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . $st['email_bg_color'] . ';padding:28px 12px;">
            <tr><td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:' . $st['email_body_bg_color'] . ';border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.12);">
                    <tr>
                        <td style="background:' . $st['email_base_color'] . ';padding:28px 26px;color:#ffffff;text-align:' . $st['email_header_align'] . ';">
                            ' . $header . '
                            <h1 style="margin:8px 0 8px;font-size:26px;line-height:1.15;color:#ffffff;">' . h($heroTitle) . '</h1>
                            <p style="margin:0;color:rgba(255,255,255,.84);font-size:15px;">' . h($heroSubtitle) . '</p>
                        </td>
                    </tr>
                    <tr><td style="padding:26px;color:' . $st['email_text_color'] . ';">' . $bodyHtml . '</td></tr>
                    <tr>
                        <td style="padding:18px 26px;background:' . $st['email_footer_bg_color'] . ';color:' . $st['email_footer_text_color'] . ';font-size:13px;text-align:center;">
                            ' . $footer . '
                        </td>
                    </tr>
                </table>
            </td></tr>
        </table>
    </body></html>';
}

function shop_send_mail(string $toEmail, string $subject, string $html): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $replyTo = null;
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $candidate = trim(shop_setting($GLOBALS['pdo'], 'email_reply_to', ''));
        $replyTo = filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : null;
    }
    return hub_send_mail($toEmail, $subject, $html, true, $replyTo);
}

function shop_order_items_email_rows(array $items): string
{
    $rows = '';
    foreach ($items as $item) {
        $name = h((string) $item['product_name']);
        if (($item['options_label'] ?? '') !== '') {
            $name .= '<br><span style="color:#7a6f74;font-size:13px;">' . h((string) $item['options_label']) . '</span>';
        }
        $rows .= '<tr>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;font-weight:700;">' . $name . '</td>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:center;">&times;' . (int) $item['quantity'] . '</td>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:right;font-weight:700;">' . h(gbp((float) $item['line_total'])) . '</td>
        </tr>';
    }
    return $rows;
}

function shop_order_email_content(PDO $pdo, array $order, ?array $items = null, ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    $pending = (string) $order['status'] === 'pending_payment';
    $items = $items ?? shop_order_items($pdo, (int) $order['id']);
    $collectionPoint = (string) ($order['collection_point'] ?: shop_setting($pdo, 'collection_point', SHOP_COLLECTION_POINT_DEFAULT));
    $collectionDetails = shop_setting($pdo, 'collection_details', '');

    $summaryRows = shop_order_items_email_rows($items);
    if ((float) $order['discount_total'] > 0) {
        $summaryRows .= '<tr><td colspan="2" style="padding:8px 0;color:#4a4046;">Discount / credit ' . h((string) $order['discount_code']) . '</td>
            <td style="padding:8px 0;text-align:right;color:#4a4046;">&minus;' . h(gbp((float) $order['discount_total'])) . '</td></tr>';
    }

    $timeline = '';
    if ((int) $order['is_preorder'] === 1) {
        $preInfo = shop_preorder_info($pdo, array_map(static fn(array $it): int => (int) ($it['product_id'] ?? 0), array_filter($items, static fn(array $it): bool => (int) $it['is_preorder'] === 1)));
        $preorderFulfilmentLine = (string) ($order['fulfilment_method'] ?? 'collection') === 'delivery'
            ? 'Your order will be delivered once ready.'
            : 'All orders are collected from <strong>' . h($collectionPoint) . '</strong> — there is no delivery option.';
        $timeline = '<div style="margin:18px 0;padding:16px 18px;border-radius:12px;background:#fff4e5;color:#7a4a06;font-size:14px;line-height:1.6;">
            <strong>This is a pre-order.</strong><br>
            ' . ($preInfo['close'] !== null ? 'Pre-orders close on <strong>' . h($preInfo['close']->format('j F Y')) . '</strong>. After that date the club places the order with the supplier. ' : '')
            . ($preInfo['lead'] !== null ? 'VSN manufacturing then typically takes <strong>' . h($preInfo['lead']) . '</strong>.' : '') . '<br>
            ' . $preorderFulfilmentLine . '
            We will email you as soon as your order is ready.
        </div>';
    }

    $isDelivery = (string) ($order['fulfilment_method'] ?? 'collection') === 'delivery';
    $fulfilmentBlock = $isDelivery
        ? '<div style="margin:18px 0 0;padding:16px 18px;border-radius:12px;background:#f6ecde;color:#3c2f34;font-size:14px;line-height:1.6;">
            <strong>Delivery' . (trim((string) ($order['shipping_method'] ?? '')) !== '' ? ' — ' . h((string) $order['shipping_method']) : '') . '</strong><br>' . nl2br(h((string) ($order['delivery_address'] ?? '')))
            . '<br>' . h(trim((string) ($order['delivery_postcode'] ?? '') . ' ' . (shop_countries()[(string) ($order['delivery_country'] ?? '')] ?? ''))) 
            . (trim(shop_setting($pdo, 'dispatch_time', '')) !== '' ? '<br>' . h(shop_setting($pdo, 'dispatch_time', '')) : '') . '</div>'
        : '<div style="margin:18px 0 0;padding:16px 18px;border-radius:12px;background:#f6ecde;color:#3c2f34;font-size:14px;line-height:1.6;">
            <strong>Collection</strong><br>' . h($collectionPoint)
        . ($collectionDetails !== '' ? '<br>' . h($collectionDetails) : '') . '</div>';

    $details = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 12px;">' . $summaryRows . '</table>'
        . '<p style="margin:0 0 4px;color:#4b0818;font-size:18px;font-weight:900;">' . ($pending ? 'Amount to pay: ' : 'Total paid: ') . h(gbp((float) $order['total'])) . '</p>'
        . $timeline
        . $fulfilmentBlock;

    return shop_email_render(
        $pdo,
        $pending ? 'order_awaiting_payment' : 'order_confirmation',
        ['order_ref' => (string) $order['order_ref'], 'customer_name' => (string) $order['customer_name'], 'order_total' => gbp((float) $order['total']), 'collection_point' => $collectionPoint],
        $details,
        $cfgOverride,
        $styleOverride
    );
}

function shop_send_confirmation_email(PDO $pdo, int $orderId, bool $force = false): bool
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order || !in_array((string) $order['status'], ['pending_payment', 'paid', 'collected'], true)) {
        return false;
    }
    if ($order['status'] === 'pending_payment' && !$force) { return false; }
    if (!$force && !empty($order['confirmation_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['customer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    // A switched-off email is skipped, except when staff explicitly press "email the customer".
    if (!$force && !shop_email_enabled($pdo, $order['status'] === 'pending_payment' ? 'order_awaiting_payment' : 'order_confirmation')) {
        return false;
    }

    $message = shop_order_email_content($pdo, $order);
    $subject = $message['subject'];
    $html = $message['html'];
    $sent = shop_send_mail($email, $subject, $html);
    if ($sent && $order['status'] !== 'pending_payment') {
        $pdo->prepare('UPDATE shop_orders SET confirmation_email_sent_at = COALESCE(confirmation_email_sent_at, NOW()) WHERE id = :id')
            ->execute([':id' => $orderId]);
    }
    if ($sent) { shop_add_order_event($pdo, $orderId, $order['status'] === 'pending_payment' ? 'Order summary emailed to customer (awaiting payment).' : 'Paid order confirmation emailed to customer.'); }
    return $sent;
}

/**
 * Fires once as stock crosses down through the low-stock threshold (not on
 * every subsequent sale below it), to the shared payment-notification
 * recipients list.
 */
function shop_maybe_send_low_stock_alert(PDO $pdo, string $itemLabel, int $before, int $after, ?int $thresholdOverride = null): void
{
    if (shop_setting($pdo, 'low_stock_alerts_enabled', '1') !== '1') {
        return;
    }
    $threshold = $thresholdOverride ?? max(0, (int) shop_setting($pdo, 'low_stock_threshold', '3'));
    if ($before <= $threshold || $after > $threshold) {
        return; // was already low, or didn't cross the threshold this time
    }
    $recipients = function_exists('stripe_notification_recipients') ? stripe_notification_recipients($pdo) : [];
    if ($recipients === []) {
        return;
    }
    if (!shop_email_enabled($pdo, 'low_stock')) {
        return;
    }
    $message = shop_low_stock_message($pdo, $itemLabel, $after, $threshold);
    foreach ($recipients as $to) {
        shop_send_mail($to, $message['subject'], $message['html']);
    }
}

/** @return array{subject:string,html:string} */
function shop_low_stock_message(PDO $pdo, string $itemLabel, int $after, int $threshold, ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    $details = '<p style="margin:0;font-size:14px;color:#4a4046;">Update stock or restock in the <a href="https://saltcoatsvictoria.co.uk/admin/shop_products.php">Shop admin</a>.</p>';
    return shop_email_render($pdo, 'low_stock', ['item' => $itemLabel, 'stock_left' => (string) $after, 'threshold' => (string) $threshold], $details, $cfgOverride, $styleOverride);
}

/**
 * Customer-facing email for an order status change other than "paid" (which
 * shop_send_confirmation_email already covers): collected, cancelled, or a
 * refund. Best-effort — never throws to the caller.
 */
function shop_send_status_email(PDO $pdo, int $orderId, string $kind, string $reason = '', float $refundAmount = 0.0): bool
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order || !in_array($kind, ['collected', 'cancelled', 'refunded'], true)) {
        return false;
    }
    $email = trim((string) $order['customer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !shop_email_enabled($pdo, 'order_' . $kind)) {
        return false;
    }
    $message = shop_status_email_message($pdo, $order, $kind, $reason, $refundAmount);
    $sent = shop_send_mail($email, $message['subject'], $message['html']);
    if ($sent) {
        shop_add_order_event($pdo, $orderId, ucfirst($kind) . ' email sent to customer.');
    }
    return $sent;
}

/** @return array{subject:string,html:string} */
function shop_status_email_message(PDO $pdo, array $order, string $kind, string $reason = '', float $refundAmount = 0.0, ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    return shop_email_render(
        $pdo,
        'order_' . $kind,
        [
            'order_ref' => (string) $order['order_ref'], 'customer_name' => (string) $order['customer_name'],
            'order_total' => gbp((float) $order['total']), 'reason' => trim($reason), 'refund_amount' => gbp($refundAmount),
        ],
        '',
        $cfgOverride,
        $styleOverride
    );
}

function shop_send_admin_notification(PDO $pdo, int $orderId): void
{
    if (!shop_email_enabled($pdo, 'new_order')) {
        return;
    }
    $recipients = function_exists('stripe_notification_recipients') ? stripe_notification_recipients($pdo) : [];
    if ($recipients === []) {
        return;
    }
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        return;
    }
    $message = shop_admin_notification_message($pdo, $order, shop_order_items($pdo, $orderId));
    foreach ($recipients as $to) {
        shop_send_mail($to, $message['subject'], $message['html']);
    }
}

/** @return array{subject:string,html:string} */
function shop_admin_notification_message(PDO $pdo, array $order, array $items, ?array $cfgOverride = null, ?array $styleOverride = null): array
{
    $isDelivery = (string) ($order['fulfilment_method'] ?? 'collection') === 'delivery';
    $deliveryLine = $isDelivery
        ? '<p style="margin:0 0 12px;font-size:14px;color:#7a4a06;font-weight:700;">Deliver to: ' . nl2br(h((string) ($order['delivery_address'] ?? ''))) . '</p>'
        : '';
    $details = '<p style="margin:0 0 12px;font-size:14px;color:#4a4046;">'
        . h((string) $order['customer_name']) . ' &middot; ' . h((string) $order['customer_email'])
        . ($order['customer_phone'] ? ' &middot; ' . h((string) $order['customer_phone']) : '') . '</p>'
        . $deliveryLine
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
        . shop_order_items_email_rows($items) . '</table>'
        . '<p style="margin:12px 0 0;font-weight:900;color:#4b0818;">Total paid: ' . h(gbp((float) $order['total'])) . '</p>';
    return shop_email_render(
        $pdo,
        'new_order',
        ['order_ref' => (string) $order['order_ref'], 'customer_name' => (string) $order['customer_name'], 'order_total' => gbp((float) $order['total'])],
        $details,
        $cfgOverride,
        $styleOverride
    );
}


/** Internal order timeline, separate from customer-facing notes. */
function shop_order_event_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_order_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        note TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_shop_order_events (order_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function shop_add_order_event(PDO $pdo, int $orderId, string $note): void
{
    $note = trim($note);
    if ($note === '' || mb_strlen($note) > 4000) { throw new RuntimeException('Enter a timeline note of up to 4,000 characters.'); }
    shop_order_event_schema($pdo);
    $pdo->prepare('INSERT INTO shop_order_events (order_id, note) VALUES (?, ?)')->execute([$orderId, $note]);
}

function shop_update_order_dates(PDO $pdo, int $orderId, array $dates): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order) { throw new RuntimeException('Order not found.'); }
    $changes = []; $params = []; $details = [];
    foreach (['created_at', 'paid_at', 'vsn_ordered_at', 'collected_at', 'cancelled_at'] as $field) {
        if (empty($order[$field]) || !array_key_exists($field, $dates)) { continue; }
        $raw = (string) $dates[$field];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
        if (!$date || $date->format('Y-m-d\TH:i') !== $raw || $date > new DateTimeImmutable('now')) {
            throw new RuntimeException('Enter valid timeline dates that are not in the future.');
        }
        $new = $date->format('Y-m-d H:i:s');
        if (substr((string) $order[$field], 0, 16) === substr($new, 0, 16)) { continue; }
        $changes[] = "$field = ?"; $params[] = $new;
        $details[] = str_replace('_', ' ', $field) . ': ' . $order[$field] . ' → ' . $new;
    }
    if ($changes) {
        shop_order_event_schema($pdo);
        $pdo->beginTransaction();
        try {
            $params[] = $orderId;
            $pdo->prepare('UPDATE shop_orders SET ' . implode(', ', $changes) . ' WHERE id = ?')->execute($params);
            shop_add_order_event($pdo, $orderId, "Timeline dates updated.\n" . implode("\n", $details));
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }
}


/**
 * Shared tab bar for every Club Shop admin page, so section switching lives
 * in one place instead of loose buttons on each page.
 *
 * @param string $active overview|orders|products|categories|modifiers|discounts|settings
 */
function shop_render_admin_tabs(string $active): void
{
    $tabs = [
        'overview'   => ['Overview', '/admin/shop_overview.php', 'fa-gauge-high'],
        'orders'     => ['Orders', '/admin/shop_orders.php', 'fa-receipt'],
        'products'   => ['Products', '/admin/shop_products.php', 'fa-shirt'],
        'categories' => ['Categories', '/admin/shop_categories.php', 'fa-layer-group'],
        'modifiers'  => ['Sizes & options', '/admin/shop_modifiers.php', 'fa-sliders'],
        'discounts'  => ['Discount codes', '/admin/shop_discount_codes.php', 'fa-tag'],
        'settings'   => ['Settings', '/admin/shop_settings.php', 'fa-gear'],
    ];
    echo '<ul class="nav nav-tabs mb-3 shop-admin-tabs" aria-label="Shop sections">';
    foreach ($tabs as $key => [$label, $href, $icon]) {
        $isActive = $key === $active;
        echo '<li class="nav-item"><a class="nav-link' . ($isActive ? ' active' : '') . '"' . ($isActive ? ' aria-current="page"' : '')
            . ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid ' . $icon . ' me-1" aria-hidden="true"></i>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    echo '</ul>';
}


/**
 * In-page action toolbar (replaces buttons that used to sit in the page hero).
 * Each action: label, href, optional icon, primary (bool), attrs (array).
 * Anything marked 'menu' => true goes into a "More" dropdown.
 *
 * @param list<array<string,mixed>> $actions
 */
function shop_render_toolbar(array $actions): void
{
    $actions = array_values(array_filter($actions));
    if ($actions === []) {
        return;
    }
    $render = static function (array $a, bool $menu): string {
        $attrs = '';
        foreach (($a['attrs'] ?? []) as $k => $v) {
            $attrs .= ' ' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        $icon = !empty($a['icon']) ? '<i class="fa-solid ' . htmlspecialchars((string) $a['icon'], ENT_QUOTES, 'UTF-8') . ' me-1" aria-hidden="true"></i>' : '';
        $class = $menu ? 'dropdown-item' : 'btn btn-sm ' . (!empty($a['primary']) ? 'btn-brand' : 'btn-outline-secondary');
        return '<a class="' . $class . '" href="' . htmlspecialchars((string) $a['href'], ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>' . $icon . htmlspecialchars((string) $a['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    };
    echo '<div class="shop-admin-toolbar d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">';
    $more = [];
    foreach ($actions as $a) {
        if (!empty($a['menu'])) { $more[] = $a; continue; }
        echo $render($a, false);
    }
    if ($more) {
        echo '<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa-solid fa-ellipsis me-1" aria-hidden="true"></i>More</button><div class="dropdown-menu dropdown-menu-end">';
        foreach ($more as $a) { echo $render($a, true); }
        echo '</div></div>';
    }
    echo '</div>';
}


/**
 * Pre-order details for a set of products: the latest closing date (null if
 * none has one) and, for VSN-manufactured ones only, the lead time.
 *
 * @param list<int> $productIds
 * @return array{close: ?DateTimeImmutable, lead: ?string}
 */
function shop_preorder_info(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    $info = ['close' => null, 'lead' => null];
    if ($productIds === []) {
        return $info;
    }
    $in = implode(',', $productIds);
    foreach ($pdo->query("SELECT * FROM shop_products WHERE id IN ({$in}) AND is_preorder = 1")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $close = shop_product_preorder_close($pdo, $p);
        if ($close !== null && ($info['close'] === null || $close > $info['close'])) {
            $info['close'] = $close;
        }
        if ((int) $p['is_vsn'] === 1 && $info['lead'] === null) {
            $info['lead'] = trim((string) $p['lead_time']) !== '' ? trim((string) $p['lead_time']) : SHOP_LEAD_TIME_DEFAULT;
        }
    }
    return $info;
}

/** Customer-facing pre-order message: the product's own notice, else a plain one built from its settings. */
function shop_preorder_notice(PDO $pdo, array $product): string
{
    $custom = trim((string) ($product['preorder_message'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $close = shop_product_preorder_close($pdo, $product);
    $text = 'Pre-order now and pay today.';
    if ($close !== null) {
        $text .= ' Orders close on ' . $close->format('j F Y') . '.';
    }
    if ((int) ($product['is_vsn'] ?? 0) === 1) {
        $lead = trim((string) ($product['lead_time'] ?? '')) !== '' ? trim((string) $product['lead_time']) : SHOP_LEAD_TIME_DEFAULT;
        $text .= ' After that the club places its order with VSN, and manufacturing typically takes ' . $lead . '.';
    }
    return $text . " We'll email you as soon as it's ready to collect.";
}
