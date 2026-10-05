<?php

declare(strict_types=1);

// Club Shop — product list. Hub admin only.

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
        header('Location: /admin/shop_products.php?m=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'toggle_active' && $id > 0) {
            $pdo->prepare('UPDATE shop_products SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
            auditLog($pdo, 'shop_product_toggled', 'Shop product #' . $id . ' visibility toggled');
            $msg = 'Product visibility updated.';
        } elseif ($action === 'delete' && $id > 0) {
            shop_delete_product($pdo, $id);
            auditLog($pdo, 'shop_product_deleted', 'Shop product #' . $id);
            $msg = 'Product deleted.';
        } else {
            $msg = '';
        }
        header('Location: /admin/shop_products.php' . ($msg !== '' ? '?m=' . rawurlencode($msg) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/shop_products.php?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Products',
    'subtitle' => 'Everything on sale in the storefront.',
    'actions' => [
        ['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm'],
        ['label' => 'Add product', 'href' => '/shop_product.php', 'class' => 'btn btn-light btn-sm'],
    ],
];
require_once __DIR__ . '/header.php';


$products = shop_get_products($pdo, []);
$now = new DateTimeImmutable('now');
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Products</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-warning"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <section class="card hub-panel">
        <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
            <thead><tr><th></th><th>Product</th><th>Category</th><th class="text-end">Price</th><th class="text-end">Stock</th><th>Flags</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$products): ?>
                <tr><td colspan="8" class="text-muted text-center py-4">No products yet. <a href="/admin/shop_product.php">Add one</a>.</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
                <?php
                $close = shop_product_preorder_close($pdo, $p);
                $closed = $close !== null && $now > $close;
                ?>
                <tr>
                    <td data-label="Image" style="width:52px;">
                        <?php if (!empty($p['image_path'])): ?>
                            <img src="<?= h((string) $p['image_path']) ?>" alt="" style="width:44px;height:52px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            <span class="text-muted"><i class="fa-solid fa-shirt"></i></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Product" class="fw-bold"><a class="text-decoration-none" href="/admin/shop_product.php?id=<?= (int) $p['id'] ?>"><?= h((string) $p['name']) ?></a>
                        <div class="small text-muted">/shop/p/<?= h((string) $p['slug']) ?></div></td>
                    <td data-label="Category"><?= h((string) $p['category_name']) ?></td>
                    <td data-label="Price" class="text-end"><?= gbp((float) $p['price']) ?></td>
                    <td data-label="Stock" class="text-end"><?= $p['stock_qty'] === null ? '<span class="text-muted">∞</span>' : (int) $p['stock_qty'] ?></td>
                    <td data-label="Flags" class="small">
                        <?php if ((int) $p['is_preorder'] === 1): ?><span class="badge text-bg-warning">Pre-order<?= $closed ? ' (closed)' : '' ?></span><?php endif; ?>
                        <?php if ((int) $p['is_featured'] === 1): ?><span class="badge text-bg-info">Featured</span><?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= (int) $p['is_active'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?>">
                                <?= (int) $p['is_active'] === 1 ? 'Visible' : 'Hidden' ?>
                            </button>
                        </form>
                    </td>
                    <td data-label="Actions" class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_product.php?id=<?= (int) $p['id'] ?>">Edit</a>
                        <form method="post" class="d-inline" data-confirm="Delete this product? Orders keep their history." data-confirm-action="Delete">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
