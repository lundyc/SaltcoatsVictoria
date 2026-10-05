<?php

declare(strict_types=1);

// Shared HTML shell for the customer-facing Club Shop. Storefront pages roll
// their own document (they must not go through header.php, which forces a
// staff login) — this keeps that markup in one place.

require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/stripe.php';

/**
 * @param array{
 *   title?:string, description?:string, active?:string, basket_count?:int,
 *   settings?:array<string,string>, canonical?:string, product_image?:string, image_alt?:string
 * } $opts
 */
function shop_layout_top(array $opts = []): void
{
    $title = trim((string) ($opts['title'] ?? 'Club Shop'));
    $fullTitle = $title === 'Club Shop' ? 'Saltcoats Victoria FC Club Shop' : $title . ' · Saltcoats Victoria FC Club Shop';
    $description = (string) ($opts['description'] ?? 'Official Saltcoats Victoria FC merchandise. Pre-order the 2026/27 home and away kit.');
    $active = (string) ($opts['active'] ?? '');
    $basketCount = (int) ($opts['basket_count'] ?? 0);
    $baseUrl = rtrim(stripe_public_base_url(), '/');
    $canonical = trim((string) ($opts['canonical'] ?? '')) ?: $baseUrl . '/shop/';
    $image = trim((string) ($opts['product_image'] ?? ''));
    $imageAlt = trim((string) ($opts['image_alt'] ?? $fullTitle));
    if ($image === '') {
        $image = '/assets/images/Saltcoats%20Victoria%20FC.png';
        $imageAlt = 'Saltcoats Victoria FC crest';
    }
    if (str_starts_with($image, '//')) {
        $image = 'https:' . $image;
    } elseif (!preg_match('~^https?://~i', $image)) {
        $image = $baseUrl . '/' . ltrim($image, '/');
    }
    $image = str_replace(' ', '%20', $image);
    $cssVersion = (int) (@filemtime(__DIR__ . '/assets/shop.css') ?: time());
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($fullTitle) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <meta name="robots" content="index,follow">
    <meta property="og:title" content="<?= h($fullTitle) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Saltcoats Victoria FC Club Shop">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <meta property="og:image" content="<?= h($image) ?>">
    <meta property="og:image:alt" content="<?= h($imageAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($fullTitle) ?>">
    <meta name="twitter:description" content="<?= h($description) ?>">
    <meta name="twitter:image" content="<?= h($image) ?>">
    <meta name="twitter:image:alt" content="<?= h($imageAlt) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <link rel="icon" href="/Saltcoats Victoria FC -White_Transparent.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="/shop/assets/shop.css?v=<?= $cssVersion ?>" rel="stylesheet">
</head>
<body class="shop-body" data-shop>
<a class="shop-skip" href="#shopMain">Skip to content</a>
<header class="shop-header">
    <div class="shop-header__inner">
        <a class="shop-brand" href="/shop/">
            <img src="/Saltcoats Victoria FC -White_Transparent.png" alt="" width="40" height="40">
            <span>Club Shop</span>
        </a>
        <nav class="shop-nav" aria-label="Shop">
            <a href="/shop/" class="<?= $active === 'home' ? 'is-active' : '' ?>">Shop</a>
        </nav>
        <a class="shop-basket-btn" href="/shop/basket" aria-label="View basket">
            <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
            <span class="shop-basket-btn__count" data-basket-count<?= $basketCount === 0 ? ' hidden' : '' ?>><?= $basketCount ?></span>
        </a>
    </div>
</header>
<main id="shopMain" class="shop-main">
    <?php
}

function shop_layout_bottom(): void
{
    $jsVersion = (int) (@filemtime(__DIR__ . '/assets/shop.js') ?: time());
    ?>
</main>
<footer class="shop-footer">
    <div class="shop-footer__inner">
        <p><strong>Saltcoats Victoria FC Club Shop</strong></p>
        <p>Collection only from Campbell Park &middot; No delivery option available.</p>
        <p>Pre-order kit is manufactured by VSN &middot; allow 6&ndash;8 weeks from the order close date.</p>
        <p class="shop-footer__links"><a href="/index.php">Main site</a> &middot; <a href="/shop/">Shop home</a> &middot; <a href="/shop/basket">Basket</a></p>
    </div>
</footer>
<div class="shop-toast" data-toast role="status" aria-live="polite" hidden></div>
<script src="/shop/assets/shop.js?v=<?= $jsVersion ?>" defer></script>
</body>
</html>
    <?php
}

/**
 * The standing "how this works" notice. Shown on the shop home, product,
 * basket and checkout pages.
 */
function shop_render_preorder_notice(array $settings, string $variant = 'full'): void
{
    $close = trim((string) ($settings['preorder_close_at'] ?? '2026-09-14 23:59:59'));
    try {
        $closeLabel = (new DateTimeImmutable($close))->format('l j F Y');
    } catch (Throwable) {
        $closeLabel = '14 September 2026';
    }
    $lead = trim((string) ($settings['lead_time'] ?? '6–8 weeks')) ?: '6–8 weeks';
    $collection = trim((string) ($settings['collection_point'] ?? 'Campbell Park')) ?: 'Campbell Park';
    ?>
    <aside class="shop-preorder-notice shop-preorder-notice--<?= h($variant) ?>" aria-label="How pre-orders work">
        <div class="shop-preorder-notice__icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></div>
        <div class="shop-preorder-notice__body">
            <p><strong>Pay now &mdash; kit pre-orders close on <?= h($closeLabel) ?>.</strong></p>
            <p>After that date the full order is placed with <strong>VSN</strong>. VSN manufacturing typically takes
               <strong><?= h($lead) ?></strong> from the order close date.</p>
            <p>All orders are <strong>collected from <?= h($collection) ?></strong>. There is <strong>no delivery option</strong>.</p>
        </div>
    </aside>
    <?php
}
