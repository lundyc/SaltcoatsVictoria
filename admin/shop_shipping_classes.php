<?php

declare(strict_types=1);

// Club Shop — shipping classes (group products; optional delivery surcharge). Hub admin only.

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
        header('Location: /admin/shop_shipping_classes.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_class') {
            shop_save_shipping_class($pdo, (int) ($_POST['id'] ?? 0) ?: null, $_POST);
            auditLog($pdo, 'shop_shipping_class_saved', 'Shipping class "' . (string) ($_POST['name'] ?? '') . '"');
            $m = 'Shipping class saved.';
        } elseif ($action === 'delete_class') {
            shop_delete_shipping_class($pdo, (int) ($_POST['id'] ?? 0));
            auditLog($pdo, 'shop_shipping_class_deleted', 'Shipping class #' . (int) ($_POST['id'] ?? 0));
            $m = 'Shipping class deleted.';
        } else {
            $m = '';
        }
        header('Location: /admin/shop_shipping_classes.php' . ($m !== '' ? '?m=' . rawurlencode($m) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/shop_shipping_classes.php?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Shipping classes',
    'subtitle' => 'Group products and optionally add a delivery surcharge.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';

$classes = shop_shipping_classes($pdo);
$edit = null;
foreach ($classes as $c) {
    if ((int) $c['id'] === (int) ($_GET['class'] ?? 0)) {
        $edit = $c;
    }
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_settings.php">Settings</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Shipping classes</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel p-3">
                <h2 class="h6 fw-bold text-uppercase text-muted"><?= $edit ? 'Edit shipping class' : 'Add shipping class' ?></h2>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_class">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
                    <div class="col-12"><label class="form-label" for="sc_name">Name</label>
                        <input class="form-control" id="sc_name" name="name" required value="<?= h((string) ($edit['name'] ?? '')) ?>" placeholder="e.g. Bulky items"></div>
                    <div class="col-12"><label class="form-label" for="sc_desc">Description</label>
                        <input class="form-control" id="sc_desc" name="description" maxlength="255" value="<?= h((string) ($edit['description'] ?? '')) ?>"></div>
                    <div class="col-12"><label class="form-label" for="sc_fee">Delivery surcharge (£)</label>
                        <input class="form-control w-auto" id="sc_fee" type="number" step="0.01" min="0" name="delivery_surcharge" value="<?= h((string) ($edit['delivery_surcharge'] ?? '0.00')) ?>">
                        <div class="form-text">Added once to the delivery fee when an order contains any product in this class. Only applies if delivery is switched on in Shop settings.</div></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-dark" type="submit"><?= $edit ? 'Save class' : 'Add class' ?></button>
                        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="/admin/shop_shipping_classes.php">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="card hub-panel">
                <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                    <thead><tr><th>Name</th><th class="text-end">Surcharge</th><th class="text-end">Products</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$classes): ?><tr><td colspan="4" class="text-muted text-center py-4">No shipping classes yet.</td></tr><?php endif; ?>
                    <?php foreach ($classes as $c): ?>
                        <tr>
                            <td data-label="Name" class="fw-bold"><?= h((string) $c['name']) ?><?php if ($c['description'] !== ''): ?><div class="small text-muted fw-normal"><?= h((string) $c['description']) ?></div><?php endif; ?></td>
                            <td data-label="Surcharge" class="text-end"><?= (float) $c['delivery_surcharge'] > 0 ? gbp((float) $c['delivery_surcharge']) : '—' ?></td>
                            <td data-label="Products" class="text-end"><?= (int) $c['product_count'] ?></td>
                            <td data-label="Actions" class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_shipping_classes.php?class=<?= (int) $c['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" data-confirm="Delete this class? Its products become &quot;No shipping class&quot;." data-confirm-action="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_class"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">×</button>
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
