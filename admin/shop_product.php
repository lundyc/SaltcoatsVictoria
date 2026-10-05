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
                'category_id' => (int) ($_POST['category_id'] ?? 0),
                'name' => (string) ($_POST['name'] ?? ''),
                'slug' => (string) ($_POST['slug'] ?? ''),
                'summary' => (string) ($_POST['summary'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'price' => (string) ($_POST['price'] ?? '0'),
                'stock_qty' => (string) ($_POST['stock_qty'] ?? ''),
                'max_per_order' => (int) ($_POST['max_per_order'] ?? 10),
                'is_active' => !empty($_POST['is_active']),
                'is_featured' => !empty($_POST['is_featured']),
                'is_preorder' => !empty($_POST['is_preorder']),
                'preorder_close_at' => (string) ($_POST['preorder_close_at'] ?? ''),
                'preorder_message' => (string) ($_POST['preorder_message'] ?? ''),
                'lead_time' => (string) ($_POST['lead_time'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'modifier_group_ids' => array_map('intval', (array) ($_POST['modifier_group_ids'] ?? [])),
                'modifier_group_required' => (array) ($_POST['modifier_group_required'] ?? []),
            ];
            $uploaded = shop_handle_image_upload($_FILES['image'] ?? [], 'products');
            if ($uploaded !== null) {
                $data['image_path'] = $uploaded;
            }
            $savedId = shop_save_product($pdo, $id, $data);
            auditLog($pdo, $id ? 'shop_product_updated' : 'shop_product_created', 'Shop product "' . $data['name'] . '" (#' . $savedId . ')');
            header('Location: /admin/shop_product.php?id=' . $savedId . '&m=' . rawurlencode('Product saved.'));
            exit;
        }
        if ($action === 'add_image' && $id) {
            $uploaded = shop_handle_image_upload($_FILES['gallery_image'] ?? [], 'products');
            if ($uploaded !== null) {
                $pdo->prepare('INSERT INTO shop_product_images (product_id, image_path, alt_text, sort_order) VALUES (:p, :img, :alt, :s)')
                    ->execute([
                        ':p' => $id,
                        ':img' => $uploaded,
                        ':alt' => trim((string) ($_POST['alt_text'] ?? '')),
                        ':s' => (int) ($_POST['sort_order'] ?? 0),
                    ]);
            }
            header('Location: /admin/shop_product.php?id=' . $id . '&m=' . rawurlencode($uploaded !== null ? 'Image added.' : 'That file was not a valid image.'));
            exit;
        }
        if ($action === 'delete_image' && $id) {
            $pdo->prepare('DELETE FROM shop_product_images WHERE id = :img AND product_id = :p')
                ->execute([':img' => (int) ($_POST['image_id'] ?? 0), ':p' => $id]);
            header('Location: /admin/shop_product.php?id=' . $id . '&m=' . rawurlencode('Image removed.'));
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
    'actions' => [['label' => 'All products', 'href' => '/shop_products.php', 'class' => 'btn btn-outline-light btn-sm']],
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
$gallery = $product ? shop_product_images($pdo, (int) $product['id']) : [];
$settings = shop_get_settings($pdo);

$v = static fn(string $key, $default = '') => h((string) ($product[$key] ?? $default));
$closeValue = '';
if ($product && !empty($product['preorder_close_at'])) {
    $closeValue = (new DateTimeImmutable((string) $product['preorder_close_at']))->format('Y-m-d\TH:i');
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_products.php">Products</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= $product ? h((string) $product['name']) : 'New' ?></span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <?php if (!$categories): ?>
        <div class="alert alert-warning">Create a <a href="/admin/shop_categories.php">category</a> first.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($product): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-8">
                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Details</h2>
                    <div class="row g-2">
                        <div class="col-md-8"><label class="form-label">Name</label>
                            <input class="form-control" name="name" required value="<?= $v('name') ?>"></div>
                        <div class="col-md-4"><label class="form-label">Category</label>
                            <select class="form-select" name="category_id" required>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($product['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= (int) ($c['parent_id'] ?? 0) > 0 ? '— ' : '' ?><?= h((string) $c['name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-8"><label class="form-label">Slug <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" name="slug" value="<?= $v('slug') ?>" placeholder="home-shirt-2026-27"></div>
                        <div class="col-md-4"><label class="form-label">Sort order</label>
                            <input class="form-control" type="number" name="sort_order" value="<?= (int) ($product['sort_order'] ?? 0) ?>"></div>
                        <div class="col-12"><label class="form-label">Short summary</label>
                            <input class="form-control" name="summary" maxlength="255" value="<?= $v('summary') ?>" placeholder="One line shown under the product name"></div>
                        <div class="col-12"><label class="form-label">Full description</label>
                            <textarea class="form-control" name="description" rows="6"><?= $v('description') ?></textarea>
                            <div class="form-text">Line breaks are kept. Shown in the "Product details" section.</div></div>
                    </div>
                </section>

                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Pricing &amp; stock</h2>
                    <div class="row g-2">
                        <div class="col-md-3"><label class="form-label">Price (£)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="price" required value="<?= h((string) ($product['price'] ?? '45.00')) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Stock</label>
                            <input class="form-control" type="number" min="0" name="stock_qty" value="<?= $product && $product['stock_qty'] !== null ? (int) $product['stock_qty'] : '' ?>" placeholder="∞ unlimited"></div>
                        <div class="col-md-3"><label class="form-label">Max per order</label>
                            <input class="form-control" type="number" min="1" name="max_per_order" value="<?= (int) ($product['max_per_order'] ?? 10) ?>"></div>
                        <div class="col-md-3 d-flex align-items-end"><div class="form-check">
                            <input class="form-check-input" type="checkbox" id="p_featured" name="is_featured" value="1" <?= (int) ($product['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="p_featured">Featured</label></div></div>
                    </div>
                </section>

                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Pre-order</h2>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="p_preorder" name="is_preorder" value="1" <?= (int) ($product['is_preorder'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="p_preorder">This is a pre-order item (pay now, made by VSN, collected later)</label>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-5"><label class="form-label">Orders close</label>
                            <input class="form-control" type="datetime-local" name="preorder_close_at" value="<?= h($closeValue) ?>">
                            <div class="form-text">Blank = use the shop default (<?= h((string) ($settings['preorder_close_at'] ?? SHOP_PREORDER_CLOSE_DEFAULT)) ?>). Ordering is blocked after this.</div></div>
                        <div class="col-md-3"><label class="form-label">Lead time</label>
                            <input class="form-control" name="lead_time" value="<?= $v('lead_time', (string) ($settings['lead_time'] ?? SHOP_LEAD_TIME_DEFAULT)) ?>" placeholder="6–8 weeks"></div>
                        <div class="col-12"><label class="form-label">Pre-order notice shown on the product</label>
                            <textarea class="form-control" name="preorder_message" rows="2" maxlength="500"><?= $v('preorder_message') ?></textarea></div>
                    </div>
                </section>

                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Size &amp; option modifiers</h2>
                    <?php if (!$allGroups): ?>
                        <p class="text-muted mb-0">No modifier groups yet. <a href="/admin/shop_modifiers.php">Create one</a> (e.g. "Kit Size") then come back.</p>
                    <?php else: ?>
                        <p class="text-muted small">Tick the option groups this product uses. "Required" forces the customer to choose before adding to basket.</p>
                        <?php foreach ($allGroups as $g): ?>
                            <?php $assigned = isset($assignedGroups[(int) $g['id']]); $reqResolved = $assigned ? (int) $assignedGroups[(int) $g['id']]['is_required'] : (int) $g['is_required']; ?>
                            <div class="d-flex align-items-center gap-3 border-bottom py-2">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="mg_<?= (int) $g['id'] ?>" name="modifier_group_ids[]" value="<?= (int) $g['id'] ?>" <?= $assigned ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="mg_<?= (int) $g['id'] ?>"><?= h((string) $g['name']) ?></label>
                                    <span class="text-muted small">· <?= (int) $g['option_count'] ?> options · <?= h((string) $g['selection_type']) ?></span>
                                </div>
                                <div class="form-check form-switch ms-auto mb-0">
                                    <input class="form-check-input" type="checkbox" id="mgr_<?= (int) $g['id'] ?>" name="modifier_group_required[<?= (int) $g['id'] ?>]" value="1" <?= $reqResolved === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="mgr_<?= (int) $g['id'] ?>">Required</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Visibility</h2>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="p_active" name="is_active" value="1" <?= (int) ($product['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="p_active">Visible in the storefront</label>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button class="btn btn-dark" type="submit"><?= $product ? 'Save changes' : 'Create product' ?></button>
                        <?php if ($product): ?>
                            <a class="btn btn-outline-secondary" href="/shop/p/<?= h((string) $product['slug']) ?>" target="_blank" rel="noopener">Preview storefront</a>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Main image</h2>
                    <?php if (!empty($product['image_path'])): ?>
                        <img src="<?= h((string) $product['image_path']) ?>" alt="" class="img-fluid rounded mb-2" style="max-height:220px;object-fit:cover;">
                    <?php endif; ?>
                    <input class="form-control" type="file" name="image" accept="image/*">
                    <div class="form-text">JPG / PNG / WebP, up to 6&nbsp;MB.</div>
                </section>
            </div>
        </div>
    </form>

    <?php if ($product): ?>
        <div class="row g-3 mt-1">
            <div class="col-lg-8">
                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Extra gallery images</h2>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <?php foreach ($gallery as $img): ?>
                            <div class="text-center">
                                <img src="<?= h((string) $img['image_path']) ?>" alt="" style="height:90px;border-radius:8px;object-fit:cover;">
                                <form method="post" data-confirm="Remove this image?" data-confirm-action="Remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_image">
                                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                    <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
                                    <button class="btn btn-sm btn-link text-danger p-0" type="submit">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$gallery): ?><p class="text-muted mb-0">No extra images yet.</p><?php endif; ?>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_image">
                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                        <div class="col-md-6"><label class="form-label">Add image</label><input class="form-control" type="file" name="gallery_image" accept="image/*" required></div>
                        <div class="col-md-4"><label class="form-label">Alt text</label><input class="form-control" name="alt_text"></div>
                        <div class="col-md-2"><button class="btn btn-outline-dark w-100" type="submit">Add</button></div>
                    </form>
                </section>
            </div>
            <div class="col-lg-4">
                <section class="card hub-panel p-3 border-danger-subtle">
                    <h2 class="h6 fw-bold text-uppercase text-danger">Danger zone</h2>
                    <form method="post" data-confirm="Delete this product? Paid orders keep their history and the product is hidden instead." data-confirm-action="Delete">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                        <button class="btn btn-outline-danger w-100" type="submit">Delete product</button>
                    </form>
                </section>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
