<?php

declare(strict_types=1);

// Club Shop — add / edit a single product. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        header('Location: /admin/shop_products.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0) ?: null;
    try {
        if ($action === 'save') {
            $data = [
                'category_ids' => array_map('intval', (array) ($_POST['category_ids'] ?? [])),
                'allow_backorder' => !empty($_POST['manage_stock']) ? (int) ($_POST['allow_backorder'] ?? 0) : 0,
                'shipping_class_id' => (int) ($_POST['shipping_class_id'] ?? 0),
                'low_stock_amount' => !empty($_POST['manage_stock']) ? (string) ($_POST['low_stock_amount'] ?? '') : '',
                'gtin' => (string) ($_POST['gtin'] ?? ''),
                'weight' => (string) ($_POST['weight'] ?? ''),
                'length' => (string) ($_POST['length'] ?? ''),
                'width' => (string) ($_POST['width'] ?? ''),
                'height' => (string) ($_POST['height'] ?? ''),
                'upsell_ids' => array_map('intval', (array) ($_POST['upsell_ids'] ?? [])),
                'crosssell_ids' => array_map('intval', (array) ($_POST['crosssell_ids'] ?? [])),
                'name' => (string) ($_POST['name'] ?? ''),
                'slug' => (string) ($_POST['slug'] ?? ''),
                'summary' => (string) ($_POST['summary'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'price' => (string) ($_POST['price'] ?? '0'),
                // Unticking "track stock" means unlimited (NULL), as before.
                'stock_qty' => !empty($_POST['manage_stock']) ? (string) ($_POST['stock_qty'] ?? '0') : '',
                'max_per_order' => !empty($_POST['sold_individually']) ? 1 : 99,
                'is_active' => !empty($_POST['is_active']),
                'is_featured' => !empty($_POST['is_featured']),
                'is_preorder' => !empty($_POST['is_preorder']),
                'is_vsn' => !empty($_POST['is_vsn']),
                'sku' => (string) ($_POST['sku'] ?? ''),
                'sale_price' => (string) ($_POST['sale_price'] ?? ''),
                'sale_from' => (string) ($_POST['sale_from'] ?? ''),
                'sale_to' => (string) ($_POST['sale_to'] ?? ''),
                'stock_status' => (string) ($_POST['stock_status'] ?? 'instock'),
                'preorder_close_at' => (string) ($_POST['preorder_close_at'] ?? ''),
                'preorder_message' => (string) ($_POST['preorder_message'] ?? ''),
                'lead_time' => (string) ($_POST['lead_time'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'modifier_group_ids' => array_map('intval', (array) ($_POST['modifier_group_ids'] ?? [])),
                'modifier_group_required' => (array) ($_POST['modifier_group_required'] ?? []),
                'modifier_options' => (array) ($_POST['modifier_options'] ?? ['_' => []]),
                'modifier_options_present' => (array) ($_POST['modifier_options_present'] ?? []),
            ];
            $uploaded = shop_handle_image_upload($_FILES['image'] ?? [], 'products');
            if ($uploaded !== null) {
                $data['image_path'] = $uploaded;
            }
            $savedId = shop_save_product($pdo, $id, $data);

            // Gallery: remove ticked images, then add any newly chosen files.
            foreach ((array) ($_POST['remove_images'] ?? []) as $imgId) {
                $pdo->prepare('DELETE FROM shop_product_images WHERE id = :img AND product_id = :p')
                    ->execute([':img' => (int) $imgId, ':p' => $savedId]);
            }
            $files = $_FILES['gallery_images'] ?? null;
            if ($files && is_array($files['name'] ?? null)) {
                foreach ($files['name'] as $i => $_n) {
                    $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                    $path = shop_handle_image_upload($one, 'products');
                    if ($path !== null) {
                        $pdo->prepare('INSERT INTO shop_product_images (product_id, image_path, alt_text, sort_order) VALUES (:p, :img, :alt, :s)')
                            ->execute([':p' => $savedId, ':img' => $path, ':alt' => $data['name'], ':s' => (int) $i]);
                    }
                }
            }
            auditLog($pdo, $id ? 'shop_product_updated' : 'shop_product_created', 'Shop product "' . $data['name'] . '" (#' . $savedId . ')');
            header('Location: /admin/shop_product.php?id=' . $savedId . '&m=' . rawurlencode('Product saved.'));
            exit;
        }
        if ($action === 'delete' && $id) {
            shop_delete_product($pdo, $id);
            auditLog($pdo, 'shop_product_deleted', 'Shop product #' . $id);
            header('Location: /admin/shop_products.php?m=' . rawurlencode('Product deleted.'));
            exit;
        }
        header('Location: /admin/shop_products.php');
        exit;
    } catch (HubFieldValidationException $e) {
        header('Location: /admin/shop_product.php?' . ($id ? 'id=' . $id . '&' : '') . 'e=' . rawurlencode($e->getMessage()));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/shop_product.php?' . ($id ? 'id=' . $id . '&' : '') . 'e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$editId = (int) ($_GET['id'] ?? 0);

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => $editId > 0 ? 'Edit product' : 'Add product',
    'subtitle' => 'Details, pricing, images, pre-order settings and size options.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';


$product = $editId > 0 ? shop_get_product($pdo, $editId) : null;
if ($editId > 0 && !$product) {
    echo '<div class="alert alert-danger">Product not found.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

$categories = shop_get_categories($pdo, true);
$allGroups = shop_get_modifier_groups($pdo);
$assignedGroups = [];
if ($product) {
    foreach (shop_product_groups($pdo, (int) $product['id'], false) as $g) {
        $assignedGroups[(int) $g['id']] = $g;
    }
}
// Groups shown on the Sizes & options tab: those assigned, plus one arriving
// via ?add_group= (from the modifier page) which is only saved with the form.
$groupsById = [];
foreach ($allGroups as $g) {
    $groupsById[(int) $g['id']] = $g;
}
$shownGroups = [];
foreach ($assignedGroups as $gid => $_) {
    if (isset($groupsById[$gid])) {
        $shownGroups[$gid] = $groupsById[$gid];
    }
}
$pendingAdd = (int) ($_GET['add_group'] ?? 0);
if ($pendingAdd > 0 && isset($groupsById[$pendingAdd])) {
    $shownGroups[$pendingAdd] = $groupsById[$pendingAdd];
}
$excludedForProduct = $product ? array_map('intval', $pdo->query('SELECT option_id FROM shop_product_excluded_options WHERE product_id = ' . (int) $product['id'])->fetchAll(PDO::FETCH_COLUMN)) : [];
$groupsJson = [];
foreach ($allGroups as $g) {
    $opts = [];
    foreach (shop_group_options($pdo, (int) $g['id'], true) as $o) {
        $heading = trim((string) ($o['option_group'] ?? ''));
        $opts[] = ['id' => (int) $o['id'], 'label' => ($heading !== '' ? $heading . ' ' : '') . (string) $o['label']];
    }
    $groupsJson[] = ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'multi' => (string) $g['selection_type'] === 'multi',
        'required' => (int) $g['is_required'] === 1, 'options' => $opts];
}
$initialGroups = [];
foreach ($shownGroups as $gid => $g) {
    $initialGroups[] = ['id' => $gid,
        'required' => isset($assignedGroups[$gid]) ? (int) $assignedGroups[$gid]['is_required'] === 1 : (int) $g['is_required'] === 1,
        'excluded' => $excludedForProduct, 'pending' => !isset($assignedGroups[$gid])];
}
$startTab = (string) ($_GET['tab'] ?? '') === 'options' || $pendingAdd > 0 ? 'options' : '';
$selectedCats = $product ? shop_product_category_ids($pdo, (int) $product['id']) : [];
$otherProducts = [];
foreach (shop_get_products($pdo, []) as $op) {
    if (!$product || (int) $op['id'] !== (int) $product['id']) {
        $otherProducts[] = ['id' => (int) $op['id'], 'name' => (string) $op['name']];
    }
}
$linkSets = [
    'upsell' => $product ? shop_product_link_ids($pdo, (int) $product['id'], 'upsell') : [],
    'crosssell' => $product ? shop_product_link_ids($pdo, (int) $product['id'], 'crosssell') : [],
];
$gallery = $product ? shop_product_images($pdo, (int) $product['id']) : [];
$settings = shop_get_settings($pdo);

$v = static fn(string $key, $default = '') => h((string) ($product[$key] ?? $default));
$closeValue = '';
if ($product && !empty($product['preorder_close_at'])) {
    $closeValue = (new DateTimeImmutable((string) $product['preorder_close_at']))->format('Y-m-d\TH:i');
}
?>
<?php
$onSale = $product && shop_product_on_sale($product);
$saleFrom = !empty($product['sale_from']) ? (new DateTimeImmutable((string) $product['sale_from']))->format('Y-m-d\TH:i') : '';
$saleTo = !empty($product['sale_to']) ? (new DateTimeImmutable((string) $product['sale_to']))->format('Y-m-d\TH:i') : '';
$tracksStock = $product && $product['stock_qty'] !== null;
$soldIndividually = $product && (int) $product['max_per_order'] === 1;
$tip = static fn(string $text): string => ' <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="' . h($text) . '" aria-label="' . h($text) . '" style="cursor:help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>';
$backorderMode = (int) ($product['allow_backorder'] ?? 0);
$row = static fn(string $label, string $for, string $field, string $help = ''): string =>
    '<div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="' . $for . '">' . $label . '</label><div class="col-sm-9">' . $field
    . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div></div>';
?>
<style>
    .pd-tabs .tab-pane { display: none; }
    .pd-tabs .tab-pane.active { display: block; }
    .pd-layout { display: flex; min-height: 320px; }
    .pd-nav { flex: 0 0 200px; background: #f8f6f6; border-right: 1px solid var(--bs-border-color, #dee2e6); }
    .pd-nav .nav-link { display: flex; align-items: center; gap: .5rem; border-radius: 0; color: #4b0818; border-bottom: 1px solid var(--bs-border-color, #dee2e6); padding: .75rem 1rem; text-align: left; font-size: .9rem; }
    .pd-nav .nav-link.active { background: #fff; color: #21141a; font-weight: 700; margin-right: -1px; }
    .pd-content { flex: 1; padding: 0 1.25rem; min-width: 0; }
    .pd-row:last-child { border-bottom: 0 !important; }
    .pd-title { font-size: 1.25rem; font-weight: 700; }
    .pd-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: .5rem; }
    .pd-gallery label { position: relative; display: block; cursor: pointer; }
    .pd-gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: .4rem; border: 1px solid #dee2e6; }
    .pd-gallery input:checked + img { opacity: .35; outline: 2px solid #dc3545; }
    .pd-sticky { position: sticky; top: 1rem; }
    @media (max-width: 767px) {
        .pd-layout { flex-direction: column; }
        .pd-nav { flex-basis: auto; border-right: 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); display: flex; flex-direction: row !important; overflow-x: auto; }
        .pd-nav .nav-link { white-space: nowrap; border-bottom: 0; }
    }
</style>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_products.php">Products</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= $product ? h((string) $product['name']) : 'New' ?></span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <?php if (!$categories): ?>
        <div class="alert alert-warning">Create a <a href="/admin/shop_categories.php">category</a> first.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="productForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($product): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-9">
                <section class="card hub-panel p-3 mb-3">
                    <label class="form-label small fw-semibold" for="p_name">Product name</label>
                    <input class="form-control form-control-lg pd-title mb-2" id="p_name" name="name" required placeholder="e.g. Home Shirt 2026/27" value="<?= $v('name') ?>">
                    <div class="small text-muted mb-3">Web address: <span class="text-body">/shop/p/</span><input class="form-control form-control-sm d-inline-block w-auto" style="min-width:220px" name="slug" aria-label="Slug" value="<?= $v('slug') ?>" placeholder="auto-generated"></div>
                    <label class="form-label small fw-semibold" for="p_desc">Product description</label>
                    <textarea class="form-control mb-3" id="p_desc" name="description" rows="7"><?= $v('description') ?></textarea>
                    <label class="form-label small fw-semibold" for="p_summary">Short description</label>
                    <input class="form-control" id="p_summary" name="summary" maxlength="255" value="<?= $v('summary') ?>" placeholder="One line shown under the product name">
                </section>

                <section class="card hub-panel mb-3 pd-tabs" id="productTabs">
                    <div class="card-header bg-transparent fw-bold">Product data</div>
                    <div class="pd-layout">
                        <ul class="nav flex-column pd-nav" role="tablist">
                            <li role="presentation"><button class="nav-link active w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-general"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i>General</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-inventory"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i>Inventory</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-shipping"><i class="fa-solid fa-ruler-combined" aria-hidden="true"></i>Shipping</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-linked"><i class="fa-solid fa-link" aria-hidden="true"></i>Linked products</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-options"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Attributes</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-supply"><i class="fa-solid fa-truck-ramp-box" aria-hidden="true"></i>Pre-order &amp; supplier</button></li>
                            <li role="presentation"><button class="nav-link w-100 bg-transparent border-0" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#pd-advanced"><i class="fa-solid fa-gear" aria-hidden="true"></i>Advanced</button></li>
                        </ul>
                        <div class="pd-content tab-content">
                            <div class="tab-pane active" id="pd-general" role="tabpanel">
                                <?= $row('Regular price (£)', 'p_price', '<input class="form-control" id="p_price" type="number" step="0.01" min="0" name="price" required value="' . h((string) ($product['price'] ?? '')) . '">') ?>
                                <?= $row('Sale price (£)', 'p_sale', '<input class="form-control" id="p_sale" type="number" step="0.01" min="0" name="sale_price" value="' . h((string) ($product['sale_price'] ?? '')) . '" placeholder="Leave blank for no sale">'
                                    . '<a href="#" class="small" id="saleScheduleLink"' . ($saleFrom !== '' || $saleTo !== '' ? ' hidden' : '') . '>Schedule</a>'
                                    . '<div class="row g-2 mt-1" id="saleSchedule"' . ($saleFrom === '' && $saleTo === '' ? ' hidden' : '') . '>'
                                    . '<div class="col-md-6"><label class="form-label small mb-0" for="p_sale_from">Sale starts</label><input class="form-control" id="p_sale_from" type="datetime-local" name="sale_from" value="' . h($saleFrom) . '"></div>'
                                    . '<div class="col-md-6"><label class="form-label small mb-0" for="p_sale_to">Sale ends</label><input class="form-control" id="p_sale_to" type="datetime-local" name="sale_to" value="' . h($saleTo) . '"></div>'
                                    . '<div class="col-12"><a href="#" class="small" id="saleScheduleCancel">Cancel schedule</a></div></div>'
                                    . ($onSale ? '<div class="small text-success mt-1">On sale now at ' . h(gbp(shop_product_price($product))) . '.</div>' : '')) ?>
                            </div>

                            <div class="tab-pane" id="pd-inventory" role="tabpanel">
                                <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_sku"><abbr title="Stock Keeping Unit">SKU</abbr><?= $tip('SKU refers to a Stock-keeping unit, a unique identifier for each distinct product and service that can be purchased.') ?></label>
                                    <div class="col-sm-9"><input class="form-control" id="p_sku" name="sku" maxlength="60" value="<?= $v('sku') ?>"></div></div>
                                <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_gtin"><abbr title="Global Trade Item Number">GTIN</abbr>, <abbr title="Universal Product Code">UPC</abbr>, <abbr title="European Article Number">EAN</abbr>, or <abbr title="International Standard Book Number">ISBN</abbr><?= $tip('Enter a barcode or any other identifier unique to this product. It can help you list this product on other channels or marketplaces.') ?></label>
                                    <div class="col-sm-9"><input class="form-control" id="p_gtin" name="gtin" maxlength="40" value="<?= $v('gtin') ?>"></div></div>

                                <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_manage">Stock management</label>
                                    <div class="col-sm-9 d-flex align-items-center"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="p_manage" name="manage_stock" value="1" <?= $tracksStock ? 'checked' : '' ?>><label class="form-check-label" for="p_manage">Track stock quantity for this product</label></div></div></div>

                                <div id="stockFields" <?= $tracksStock ? '' : 'hidden' ?>>
                                    <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_stock">Quantity<?= $tip('Stock quantity. When this reaches zero the product is out of stock, unless backorders are allowed.') ?></label>
                                        <div class="col-sm-9"><input class="form-control w-auto" id="p_stock" type="number" min="0" name="stock_qty" value="<?= $tracksStock ? (int) $product['stock_qty'] : 0 ?>"></div></div>
                                    <div class="row g-2 py-3 border-bottom pd-row"><span class="col-sm-3 fw-semibold small pt-1">Allow backorders?</span>
                                        <div class="col-sm-9">
                                            <div class="form-check"><input class="form-check-input" type="radio" id="bo_0" name="allow_backorder" value="0" <?= $backorderMode === 0 ? 'checked' : '' ?>><label class="form-check-label" for="bo_0">Do not allow</label></div>
                                            <div class="form-check"><input class="form-check-input" type="radio" id="bo_2" name="allow_backorder" value="2" <?= $backorderMode === 2 ? 'checked' : '' ?>><label class="form-check-label" for="bo_2">Allow, but notify customer</label></div>
                                            <div class="form-check"><input class="form-check-input" type="radio" id="bo_1" name="allow_backorder" value="1" <?= $backorderMode === 1 ? 'checked' : '' ?>><label class="form-check-label" for="bo_1">Allow</label></div>
                                        </div></div>
                                    <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_low">Low stock threshold<?= $tip('When product stock reaches this amount you will be notified by email. Leave blank to use the shop-wide value from Shop settings.') ?></label>
                                        <div class="col-sm-9"><input class="form-control w-auto" id="p_low" type="number" min="0" name="low_stock_amount" value="<?= h((string) ($product['low_stock_amount'] ?? '')) ?>" placeholder="Store-wide threshold (<?= (int) ($settings['low_stock_threshold'] ?? 3) ?>)"></div></div>
                                </div>

                                <div class="row g-2 py-3 border-bottom pd-row" id="stockStatusWrap" <?= $tracksStock ? 'hidden' : '' ?>><span class="col-sm-3 fw-semibold small pt-1">Stock status<?= $tip('Controls whether or not the product is listed as “in stock” or “out of stock” on the storefront.') ?></span>
                                    <div class="col-sm-9">
                                        <div class="form-check"><input class="form-check-input" type="radio" id="ss_in" name="stock_status" value="instock" <?= !in_array($product['stock_status'] ?? '', ['outofstock', 'onbackorder'], true) ? 'checked' : '' ?>><label class="form-check-label" for="ss_in">In stock</label></div>
                                        <div class="form-check"><input class="form-check-input" type="radio" id="ss_out" name="stock_status" value="outofstock" <?= ($product['stock_status'] ?? '') === 'outofstock' ? 'checked' : '' ?>><label class="form-check-label" for="ss_out">Out of stock</label></div>
                                        <div class="form-check"><input class="form-check-input" type="radio" id="ss_back" name="stock_status" value="onbackorder" <?= ($product['stock_status'] ?? '') === 'onbackorder' ? 'checked' : '' ?>><label class="form-check-label" for="ss_back">On backorder</label></div>
                                    </div></div>

                                <div class="row g-2 py-3 pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_single">Sold individually<?= $tip('Check to let customers purchase only 1 item in a single order. This is particularly useful for items that have limited quantity, for example art or handmade goods.') ?></label>
                                    <div class="col-sm-9 d-flex align-items-center"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="p_single" name="sold_individually" value="1" <?= $soldIndividually ? 'checked' : '' ?>><label class="form-check-label" for="p_single">Limit purchases to 1 item per order</label></div></div></div>
                            </div>

                            <div class="tab-pane" id="pd-shipping" role="tabpanel">
                                <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_weight">Weight (kg)<?= $tip('Weight in decimal form') ?></label>
                                    <div class="col-sm-9"><input class="form-control w-auto" id="p_weight" type="number" step="0.01" min="0" name="weight" value="<?= h((string) ($product['weight'] ?? '')) ?>" placeholder="0"></div></div>
                                <div class="row g-2 py-3 border-bottom pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_length">Dimensions (cm)<?= $tip('LxWxH in decimal form') ?></label>
                                    <div class="col-sm-9 d-flex flex-wrap gap-2">
                                        <input class="form-control w-auto" style="max-width:120px" id="p_length" type="number" step="0.01" min="0" name="length" placeholder="Length" aria-label="Length" value="<?= h((string) ($product['length'] ?? '')) ?>">
                                        <input class="form-control w-auto" style="max-width:120px" type="number" step="0.01" min="0" name="width" placeholder="Width" aria-label="Width" value="<?= h((string) ($product['width'] ?? '')) ?>">
                                        <input class="form-control w-auto" style="max-width:120px" type="number" step="0.01" min="0" name="height" placeholder="Height" aria-label="Height" value="<?= h((string) ($product['height'] ?? '')) ?>">
                                    </div></div>
                                <div class="row g-2 py-3 pd-row"><label class="col-sm-3 col-form-label fw-semibold small" for="p_shipclass">Shipping class<?= $tip('Shipping classes are used to group similar products. A class can add a delivery surcharge when a customer chooses delivery.') ?></label>
                                    <div class="col-sm-9">
                                        <select class="form-select w-auto" id="p_shipclass" name="shipping_class_id">
                                            <option value="0">No shipping class</option>
                                            <?php foreach (shop_shipping_classes($pdo) as $sc): ?>
                                                <option value="<?= (int) $sc['id'] ?>" <?= (int) ($product['shipping_class_id'] ?? 0) === (int) $sc['id'] ? 'selected' : '' ?>><?= h((string) $sc['name']) ?><?= (float) $sc['delivery_surcharge'] > 0 ? ' (+' . h(gbp((float) $sc['delivery_surcharge'])) . ' delivery)' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text"><a href="/admin/shop_shipping_classes.php">Manage shipping classes</a> &middot; Weight and dimensions are shown on the product page; delivery is off unless enabled in Shop settings.</div>
                                    </div></div>
                            </div>

                            <div class="tab-pane" id="pd-linked" role="tabpanel">
                                <?php foreach ([
                                    'upsell' => ['Upsells', 'Upsells are products which you recommend instead of the currently viewed product, for example, products that are more profitable or better quality or more expensive. Shown on the product page as &ldquo;You might also like&rdquo; (replacing the automatic same-category picks).'],
                                    'crosssell' => ['Cross-sells', 'Cross-sells are products which you promote in the basket, based on the current product.'],
                                ] as $lt => [$ltLabel, $ltHelp]): ?>
                                    <div class="row g-2 py-3 border-bottom pd-row link-set" data-link-type="<?= $lt ?>">
                                        <span class="col-sm-3 fw-semibold small pt-1"><?= $ltLabel ?>
                                            <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="<?= h(html_entity_decode($ltHelp, ENT_QUOTES, 'UTF-8')) ?>" aria-label="<?= h(html_entity_decode($ltHelp, ENT_QUOTES, 'UTF-8')) ?>" style="cursor:help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                                        </span>
                                        <div class="col-sm-9">
                                            <div class="position-relative">
                                                <input type="text" class="form-control" data-link-search autocomplete="off" placeholder="Search for a product&hellip;" aria-label="Search for <?= strtolower($ltLabel) ?>">
                                                <div class="list-group position-absolute w-100 shadow-sm" data-link-results role="listbox" style="z-index:20;max-height:240px;overflow:auto" hidden></div>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 mt-2" data-link-chips></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="tab-pane" id="pd-options" role="tabpanel">
                                <div class="py-3">
                                    <div class="alert alert-light border d-flex justify-content-between align-items-start gap-2 small mb-3" id="attrHelp">
                                        <span>Add the choices customers make before buying &mdash; such as <strong>Size</strong> or <strong>Colour</strong>. Each attribute is a group of values; tick which values apply to this product. Groups and their values are managed once and reused across products.</span>
                                        <button type="button" class="btn-close" aria-label="Dismiss" onclick="document.getElementById('attrHelp').remove()"></button>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <a class="btn btn-outline-primary btn-sm" href="/admin/shop_modifiers.php?new=1<?= $product ? '&amp;return_product=' . (int) $product['id'] : '' ?>"<?= $product ? '' : ' target="_blank" rel="noopener"' ?>>Add new</a>
                                        <select class="form-select form-select-sm w-auto" id="attrAddExisting" aria-label="Add existing attribute"></select>
                                        <span class="ms-auto small fst-italic"><a href="#" id="attrExpand">Expand</a> / <a href="#" id="attrCollapse">Close</a></span>
                                    </div>
                                    <div id="attrList" class="border-top"></div>
                                    <p class="text-muted mt-3 mb-0" id="attrEmpty" hidden>No attributes on this product yet. Customers simply add it to their basket.</p>
                                </div>
                            </div>

                            <div class="tab-pane" id="pd-supply" role="tabpanel">
                                <div class="row g-2 py-3 border-bottom pd-row"><span class="col-sm-3 fw-semibold small pt-1">Manufacturer</span><div class="col-sm-9">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="p_vsn" name="is_vsn" value="1" <?= (int) ($product['is_vsn'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="p_vsn">Manufactured by VSN</label></div>
                                    <div class="form-text">Only VSN items appear on the VSN order form and can be flagged as sent to VSN.</div>
                                </div></div>
                                <div class="row g-2 py-3 border-bottom pd-row"><span class="col-sm-3 fw-semibold small pt-1">Pre-order</span><div class="col-sm-9">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="p_preorder" name="is_preorder" value="1" <?= (int) ($product['is_preorder'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="p_preorder">Sell this item as a pre-order (pay now, collected once stock arrives)</label></div>
                                </div></div>
                                <div id="vsnFields" <?= (int) ($product['is_vsn'] ?? 0) === 1 ? '' : 'hidden' ?>>
                                    <?= $row('VSN lead time', 'p_lead', '<input class="form-control" id="p_lead" name="lead_time" value="' . $v('lead_time', SHOP_LEAD_TIME_DEFAULT) . '" placeholder="6–8 weeks">', 'How long VSN typically takes to make this once the club places its order. Shown to customers on pre-order items.') ?>
                                </div>
                                <div id="preorderFields">
                                    <?= $row('Orders close', 'p_close', '<input class="form-control" id="p_close" type="datetime-local" name="preorder_close_at" value="' . h($closeValue) . '">', 'Ordering is blocked after this date and time. Leave blank for no closing date.') ?>
                                    <?= $row('Pre-order notice', 'p_premsg', '<textarea class="form-control" id="p_premsg" name="preorder_message" rows="2" maxlength="500">' . $v('preorder_message') . '</textarea>', 'Optional. Shown on the product page. Blank = a standard message built from the closing date' . ((int) ($product['is_vsn'] ?? 0) === 1 ? ' and VSN lead time' : '') . '.') ?>
                                </div>
                            </div>

                            <div class="tab-pane" id="pd-advanced" role="tabpanel">
                                <?= $row('Menu order', 'p_sort', '<input class="form-control w-auto" id="p_sort" type="number" name="sort_order" value="' . (int) ($product['sort_order'] ?? 0) . '">', 'Lower numbers appear first within a category.') ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-3">
                <div class="pd-sticky">
                    <section class="card hub-panel mb-3">
                        <div class="card-header bg-transparent fw-bold">Publish</div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="p_active" name="is_active" value="1" <?= (int) ($product['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="p_active">Visible in the storefront</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="p_featured" name="is_featured" value="1" <?= (int) ($product['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="p_featured">Featured product</label>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-dark" type="submit"><?= $product ? 'Save changes' : 'Create product' ?></button>
                                <?php if ($product): ?><a class="btn btn-outline-secondary btn-sm" href="/shop/p/<?= h((string) $product['slug']) ?>" target="_blank" rel="noopener">Preview storefront</a><?php endif; ?>
                            </div>
                            <?php if ($product): ?>
                                <button class="btn btn-link btn-sm text-danger p-0 mt-3" type="submit" name="action" value="delete" formnovalidate
                                        onclick="return confirm('Delete this product? Paid orders keep their history and the product is hidden instead.');">Delete product</button>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card hub-panel mb-3">
                        <div class="card-header bg-transparent fw-bold">Product category</div>
                        <div class="card-body">
                            <div class="border rounded p-2" style="max-height:200px;overflow:auto" id="catList">
                                <?php foreach ($categories as $c): ?>
                                    <?php $isChild = (int) ($c['parent_id'] ?? 0) > 0; ?>
                                    <div class="form-check<?= $isChild ? ' ms-3' : '' ?>">
                                        <input class="form-check-input" type="checkbox" name="category_ids[]" id="cat_<?= (int) $c['id'] ?>" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $selectedCats, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="cat_<?= (int) $c['id'] ?>"><?= h((string) $c['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Tick every category this product belongs to. The first one ticked in the list is its main category.</div>
                            <a class="small d-inline-block mt-2" href="/admin/shop_categories.php">+ Add new category</a>
                        </div>
                    </section>

                    <section class="card hub-panel mb-3">
                        <div class="card-header bg-transparent fw-bold">Product image</div>
                        <div class="card-body">
                            <?php if (!empty($product['image_path'])): ?>
                                <img src="<?= h((string) $product['image_path']) ?>" alt="" class="img-fluid rounded mb-2" style="max-height:200px;object-fit:cover;">
                            <?php endif; ?>
                            <input class="form-control form-control-sm" type="file" name="image" accept="image/*" aria-label="Product image">
                            <div class="form-text">JPG / PNG / WebP, up to 6&nbsp;MB.</div>
                        </div>
                    </section>

                    <section class="card hub-panel">
                        <div class="card-header bg-transparent fw-bold">Product gallery</div>
                        <div class="card-body">
                            <?php if ($gallery): ?>
                                <div class="pd-gallery mb-2">
                                    <?php foreach ($gallery as $img): ?>
                                        <label title="Tick to remove on save"><input type="checkbox" class="visually-hidden" name="remove_images[]" value="<?= (int) $img['id'] ?>"><img src="<?= h((string) $img['image_path']) ?>" alt=""></label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text mb-2">Click an image to mark it for removal, then save.</div>
                            <?php endif; ?>
                            <input class="form-control form-control-sm" type="file" name="gallery_images[]" accept="image/*" multiple aria-label="Add gallery images">
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </form>
    <script>
    (function () {
            var groupsData = <?= json_encode($groupsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var initial = <?= json_encode($initialGroups) ?>;
            var returnProduct = <?= $product ? (int) $product['id'] : 0 ?>;
            var list = document.getElementById('attrList'), picker = document.getElementById('attrAddExisting'), emptyMsg = document.getElementById('attrEmpty');
            function byId(id) { return groupsData.filter(function (g) { return g.id === id; })[0]; }
            function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html !== undefined) e.innerHTML = html; return e; }
            function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

            function buildPanel(state, open) {
                var g = byId(state.id); if (!g) return null;
                var excluded = {}; (state.excluded || []).forEach(function (x) { excluded[x] = true; });
                var panel = el('div', 'attr-panel border-bottom'); panel.dataset.groupId = g.id;
                var head = el('div', 'd-flex align-items-center gap-3 px-1 py-2 attr-head');
                head.style.cursor = 'pointer';
                head.innerHTML = '<strong class="flex-grow-1">' + esc(g.name) + (state.pending ? ' <span class="badge text-bg-info fw-normal ms-1">Not saved yet</span>' : '') + ' <span class="text-muted fw-normal small attr-count"></span></strong>' +
                    '<a href="#" class="text-danger small text-decoration-none" data-remove>Remove</a><i class="fa-solid fa-chevron-down small text-muted attr-caret" aria-hidden="true"></i>';
                var body = el('div', 'row g-3 px-1 pb-3'); body.hidden = !open;
                var left = el('div', 'col-md-3');
                left.innerHTML = '<div class="small text-muted">Name:</div><div class="fw-bold mb-2">' + esc(g.name) + '</div>' +
                    '<div class="small text-muted mb-2">' + (g.multi ? 'Customer can choose several' : 'Customer chooses one') + '</div>' +
                    '<div class="form-check"><input class="form-check-input" type="checkbox" id="mgr_' + g.id + '" name="modifier_group_required[' + g.id + ']" value="1"' + (state.required ? ' checked' : '') + '>' +
                    '<label class="form-check-label small" for="mgr_' + g.id + '">Required</label></div>';
                var right = el('div', 'col-md-9');
                right.appendChild(el('div', 'small text-muted mb-1', 'Value(s):'));
                var box = el('div', 'border rounded p-2 d-flex flex-wrap gap-1'); box.style.minHeight = '44px';
                right.appendChild(box);
                var btns = el('div', 'd-flex flex-wrap gap-2 mt-2');
                btns.innerHTML = '<button type="button" class="btn btn-outline-primary btn-sm" data-all>Select all</button><button type="button" class="btn btn-outline-primary btn-sm" data-none>Select none</button>' +
                    '<a class="btn btn-outline-primary btn-sm ms-auto" href="/admin/shop_modifiers.php?group=' + g.id + (returnProduct ? '&return_product=' + returnProduct : '') + '"' + (returnProduct ? '' : ' target="_blank" rel="noopener"') + '>Create value</a>';
                right.appendChild(btns);
                body.appendChild(left); body.appendChild(right);
                var marker = el('input'); marker.type = 'hidden'; marker.name = 'modifier_options_present[' + g.id + ']'; marker.value = '1';
                var gid = el('input'); gid.type = 'hidden'; gid.name = 'modifier_group_ids[]'; gid.value = g.id;
                panel.appendChild(gid); panel.appendChild(marker); panel.appendChild(head); panel.appendChild(body);

                var selected = {}; g.options.forEach(function (o) { selected[o.id] = !excluded[o.id]; });
                function draw() {
                    box.innerHTML = '';
                    var n = 0;
                    g.options.forEach(function (o) {
                        var on = selected[o.id]; if (on) n++;
                        var chip = el('button', 'btn btn-sm ' + (on ? 'btn-secondary' : 'btn-outline-secondary text-decoration-line-through'));
                        chip.type = 'button'; chip.style.fontSize = '.8rem';
                        chip.innerHTML = (on ? '&times; ' : '+ ') + esc(o.label);
                        chip.title = on ? 'Click to remove from this product' : 'Click to add to this product';
                        chip.addEventListener('click', function () { selected[o.id] = !selected[o.id]; draw(); });
                        box.appendChild(chip);
                        if (on) { var h = el('input'); h.type = 'hidden'; h.name = 'modifier_options[' + g.id + '][]'; h.value = o.id; box.appendChild(h); }
                    });
                    if (!g.options.length) box.innerHTML = '<span class="text-muted small">This attribute has no values yet &mdash; use &ldquo;Create value&rdquo;.</span>';
                    head.querySelector('.attr-count').textContent = '· ' + n + ' of ' + g.options.length + ' values';
                }
                btns.querySelector('[data-all]').addEventListener('click', function () { g.options.forEach(function (o) { selected[o.id] = true; }); draw(); });
                btns.querySelector('[data-none]').addEventListener('click', function () { g.options.forEach(function (o) { selected[o.id] = false; }); draw(); });
                head.addEventListener('click', function (e) {
                    if (e.target.closest('[data-remove]')) { e.preventDefault(); panel.remove(); refresh(); return; }
                    body.hidden = !body.hidden;
                    head.querySelector('.attr-caret').className = 'fa-solid small text-muted attr-caret ' + (body.hidden ? 'fa-chevron-down' : 'fa-chevron-up');
                });
                head.querySelector('.attr-caret').className = 'fa-solid small text-muted attr-caret ' + (open ? 'fa-chevron-up' : 'fa-chevron-down');
                draw();
                return panel;
            }
            function refresh() {
                var used = {};
                list.querySelectorAll('.attr-panel').forEach(function (p) { used[p.dataset.groupId] = true; });
                picker.innerHTML = '<option value="">Add existing</option>';
                groupsData.forEach(function (g) { if (!used[g.id]) { var o = document.createElement('option'); o.value = g.id; o.textContent = g.name; picker.appendChild(o); } });
                picker.disabled = picker.options.length < 2;
                emptyMsg.hidden = !!list.querySelector('.attr-panel');
            }
            initial.forEach(function (st) { var p = buildPanel(st, !!st.pending); if (p) list.appendChild(p); });
            picker.addEventListener('change', function () {
                var g = byId(parseInt(picker.value, 10)); if (!g) return;
                list.appendChild(buildPanel({ id: g.id, required: g.required, excluded: [], pending: true }, true));
                refresh();
            });
            function setAll(open) { list.querySelectorAll('.attr-panel').forEach(function (p) {
                p.querySelector('.row').hidden = !open;
                p.querySelector('.attr-caret').className = 'fa-solid small text-muted attr-caret ' + (open ? 'fa-chevron-up' : 'fa-chevron-down'); }); }
            document.getElementById('attrExpand').addEventListener('click', function (e) { e.preventDefault(); setAll(true); });
            document.getElementById('attrCollapse').addEventListener('click', function (e) { e.preventDefault(); setAll(false); });
            refresh();
            <?php if ($startTab === 'options'): ?>
            document.addEventListener('DOMContentLoaded', function () {
                var optBtn = document.querySelector('[data-bs-target="#pd-options"]');
                if (optBtn && window.bootstrap) bootstrap.Tab.getOrCreateInstance(optBtn).show();
            });
            <?php endif; ?>

        var otherProducts = <?= json_encode($otherProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var linkSets = <?= json_encode($linkSets) ?>;
        document.querySelectorAll('.link-set').forEach(function (box) {
            var type = box.dataset.linkType, input = box.querySelector('[data-link-search]'), results = box.querySelector('[data-link-results]'), chips = box.querySelector('[data-link-chips]');
            var chosen = (linkSets[type] || []).slice(), active = -1, shown = [];
            function nameOf(id) { var p = otherProducts.filter(function (x) { return x.id === id; })[0]; return p ? p.name : '#' + id; }
            function drawChips() {
                chips.innerHTML = '';
                chosen.forEach(function (id) {
                    var c = document.createElement('span');
                    c.className = 'badge text-bg-light border d-inline-flex align-items-center gap-2 fw-normal py-2';
                    c.innerHTML = '<input type="hidden" name="' + type + '_ids[]" value="' + id + '"><span></span><button type="button" class="btn-close" style="font-size:.6rem" aria-label="Remove"></button>';
                    c.querySelector('span').textContent = nameOf(id);
                    c.querySelector('button').addEventListener('click', function () { chosen = chosen.filter(function (x) { return x !== id; }); drawChips(); });
                    chips.appendChild(c);
                });
            }
            function add(id) { if (chosen.indexOf(id) === -1) chosen.push(id); input.value = ''; closeResults(); drawChips(); input.focus(); }
            function closeResults() { results.hidden = true; results.innerHTML = ''; active = -1; shown = []; }
            function highlight() { Array.prototype.forEach.call(results.children, function (el, i) { el.classList.toggle('active', i === active); }); }
            function search() {
                var q = input.value.trim().toLowerCase();
                if (!q) { closeResults(); return; }
                shown = otherProducts.filter(function (p) { return chosen.indexOf(p.id) === -1 && p.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
                results.innerHTML = '';
                if (!shown.length) {
                    var none = document.createElement('div'); none.className = 'list-group-item text-muted small'; none.textContent = 'No matching products'; results.appendChild(none);
                }
                shown.forEach(function (p) {
                    var b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.setAttribute('role', 'option');
                    var i = p.name.toLowerCase().indexOf(q);
                    b.appendChild(document.createTextNode(p.name.slice(0, i)));
                    var m = document.createElement('strong'); m.textContent = p.name.slice(i, i + q.length); b.appendChild(m);
                    b.appendChild(document.createTextNode(p.name.slice(i + q.length)));
                    b.addEventListener('mousedown', function (e) { e.preventDefault(); add(p.id); });
                    results.appendChild(b);
                });
                active = shown.length ? 0 : -1; highlight(); results.hidden = false;
            }
            input.addEventListener('input', search);
            input.addEventListener('focus', search);
            input.addEventListener('blur', function () { setTimeout(closeResults, 100); });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown' && shown.length) { e.preventDefault(); active = (active + 1) % shown.length; highlight(); }
                else if (e.key === 'ArrowUp' && shown.length) { e.preventDefault(); active = (active - 1 + shown.length) % shown.length; highlight(); }
                else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0 && shown[active]) add(shown[active].id); }
                else if (e.key === 'Escape') { closeResults(); }
            });
            drawChips();
        });
        document.addEventListener('DOMContentLoaded', function () {
            if (window.bootstrap) document.querySelectorAll('#productTabs [data-bs-toggle="tooltip"]').forEach(function (t) { new bootstrap.Tooltip(t, { container: 'body', trigger: 'hover focus' }); });
        });
        var wrap = document.getElementById('productTabs');
        var form = document.getElementById('productForm');
        function toggle(id, hide) { var el = document.getElementById(id); if (el) el.hidden = hide; }
        document.getElementById('p_manage').addEventListener('change', function () { toggle('stockFields', !this.checked); toggle('stockStatusWrap', this.checked); });
        var pre = document.getElementById('p_preorder');
        function syncPre() { toggle('preorderFields', !pre.checked); }
        pre.addEventListener('change', syncPre); syncPre();
        document.getElementById('p_vsn').addEventListener('change', function () { toggle('vsnFields', !this.checked); });
        var link = document.getElementById('saleScheduleLink');
        if (link) link.addEventListener('click', function (e) { e.preventDefault(); link.hidden = true; toggle('saleSchedule', false); });
        var cancel = document.getElementById('saleScheduleCancel');
        if (cancel) cancel.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('p_sale_from').value = ''; document.getElementById('p_sale_to').value = '';
            toggle('saleSchedule', true); if (link) link.hidden = false;
        });
        // A required field on a hidden tab would otherwise fail silently: jump to its tab.
        form.addEventListener('invalid', function (e) {
            var pane = e.target.closest('.tab-pane');
            if (!pane || pane.classList.contains('active')) return;
            var btn = wrap.querySelector('[data-bs-target="#' + pane.id + '"]');
            if (btn && window.bootstrap) bootstrap.Tab.getOrCreateInstance(btn).show();
        }, true);
    })();
    </script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
