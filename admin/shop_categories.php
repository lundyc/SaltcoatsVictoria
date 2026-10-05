<?php

declare(strict_types=1);

// Club Shop — categories admin. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $redirect = '/shop_categories.php';
    if (!csrf_check()) {
        header('Location: ' . $redirect . '?m=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0) ?: null;
            $data = [
                'name' => (string) ($_POST['name'] ?? ''),
                'slug' => (string) ($_POST['slug'] ?? ''),
                'parent_id' => (int) ($_POST['parent_id'] ?? 0),
                'description' => (string) ($_POST['description'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'is_active' => !empty($_POST['is_active']),
            ];
            $uploaded = shop_handle_image_upload($_FILES['image'] ?? [], 'categories');
            if ($uploaded !== null) {
                $data['image_path'] = $uploaded;
            }
            $savedId = shop_save_category($pdo, $id, $data);
            auditLog($pdo, $id ? 'shop_category_updated' : 'shop_category_created', 'Shop category "' . $data['name'] . '" (#' . $savedId . ')');
            $msg = 'Category saved.';
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            shop_delete_category($pdo, $id);
            auditLog($pdo, 'shop_category_deleted', 'Shop category #' . $id);
            $msg = 'Category deleted.';
        } else {
            $msg = '';
        }
        header('Location: ' . $redirect . ($msg !== '' ? '?m=' . rawurlencode($msg) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $redirect . '?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$editId = (int) ($_GET['edit'] ?? 0);

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Categories',
    'subtitle' => 'Group products into sections of the storefront.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';


$categories = shop_get_categories($pdo, true);
$editing = $editId > 0 ? shop_get_category($pdo, $editId) : null;
// Only top-level categories can be chosen as a parent, and a category can't
// be parented under itself.
$parentOptions = array_filter($categories, static fn($c) => (int) ($c['parent_id'] ?? 0) === 0 && (int) $c['id'] !== $editId);
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Categories</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel p-3">
                <h2 class="h5 fw-bold"><?= $editing ? 'Edit category' : 'Add category' ?></h2>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="col-12"><label class="form-label">Name</label>
                        <input class="form-control" name="name" required value="<?= h((string) ($editing['name'] ?? '')) ?>"></div>
                    <div class="col-12"><label class="form-label">Slug <span class="text-muted small">(optional — auto from name)</span></label>
                        <input class="form-control" name="slug" value="<?= h((string) ($editing['slug'] ?? '')) ?>" placeholder="matchday-kit-2026-27"></div>
                    <div class="col-12"><label class="form-label">Parent category <span class="text-muted small">(optional — makes this a subcategory)</span></label>
                        <select class="form-select" name="parent_id">
                            <option value="0">None (top-level category)</option>
                            <?php foreach ($parentOptions as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($editing['parent_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= h((string) $p['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-12"><label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?= h((string) ($editing['description'] ?? '')) ?></textarea></div>
                    <div class="col-6"><label class="form-label">Sort order</label>
                        <input class="form-control" type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>"></div>
                    <div class="col-6 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="cat_active" name="is_active" value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cat_active">Visible in shop</label>
                    </div></div>
                    <div class="col-12"><label class="form-label">Image <span class="text-muted small">(optional)</span></label>
                        <input class="form-control" type="file" name="image" accept="image/*">
                        <?php if (!empty($editing['image_path'])): ?><div class="small mt-1"><img src="<?= h((string) $editing['image_path']) ?>" alt="" style="height:48px;border-radius:6px"></div><?php endif; ?></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-dark" type="submit"><?= $editing ? 'Save changes' : 'Add category' ?></button>
                        <?php if ($editing): ?><a class="btn btn-outline-secondary" href="/admin/shop_categories.php">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="card hub-panel">
                <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                    <thead><tr><th>Name</th><th>Slug</th><th class="text-end">Products</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$categories): ?><tr><td colspan="5" class="text-muted text-center py-4">No categories yet.</td></tr><?php endif; ?>
                    <?php
                    $childCounts = [];
                    foreach ($categories as $c) {
                        $pid = (int) ($c['parent_id'] ?? 0);
                        if ($pid > 0) {
                            $childCounts[$pid] = ($childCounts[$pid] ?? 0) + 1;
                        }
                    }
                    ?>
                    <?php foreach ($categories as $c): ?>
                        <?php $isSub = (int) ($c['parent_id'] ?? 0) > 0; $hasChildren = ($childCounts[(int) $c['id']] ?? 0) > 0; ?>
                        <tr>
                            <td data-label="Name" class="fw-bold"><?= $isSub ? '<span class="text-muted">↳</span> ' : '' ?><?= h((string) $c['name']) ?></td>
                            <td data-label="Slug" class="small text-muted"><?= h((string) $c['slug']) ?></td>
                            <td data-label="Products" class="text-end"><?= (int) $c['product_count'] ?></td>
                            <td data-label="Status"><?= (int) $c['is_active'] === 1 ? '<span class="badge text-bg-success">Visible</span>' : '<span class="badge text-bg-secondary">Hidden</span>' ?></td>
                            <td data-label="Actions" class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_categories.php?edit=<?= (int) $c['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" data-confirm="Delete this category?" data-confirm-action="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= ((int) $c['product_count'] > 0 || $hasChildren) ? 'disabled title="Move its products/subcategories first"' : '' ?>>Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
