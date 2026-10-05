<?php

declare(strict_types=1);

// Club Shop — modifier groups & options (e.g. "Kit Size"). Hub admin only.

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
        header('Location: /admin/shop_modifiers.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = '/shop_modifiers.php' . ($groupId ? '?group=' . $groupId : '');
    try {
        if ($action === 'save_group') {
            $savedId = shop_save_modifier_group($pdo, (int) ($_POST['id'] ?? 0) ?: null, $_POST);
            auditLog($pdo, 'shop_modifier_group_saved', 'Modifier group "' . (string) ($_POST['name'] ?? '') . '" (#' . $savedId . ')');
            header('Location: /admin/shop_modifiers.php?group=' . $savedId . '&m=' . rawurlencode('Modifier saved.'));
            exit;
        }
        if ($action === 'delete_group') {
            shop_delete_modifier_group($pdo, (int) ($_POST['id'] ?? 0));
            auditLog($pdo, 'shop_modifier_group_deleted', 'Modifier group #' . (int) ($_POST['id'] ?? 0));
            header('Location: /admin/shop_modifiers.php?m=' . rawurlencode('Modifier deleted.'));
            exit;
        }
        if ($action === 'save_option') {
            shop_save_modifier_option($pdo, (int) ($_POST['id'] ?? 0) ?: null, $groupId, $_POST);
            header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'm=' . rawurlencode('Option saved.'));
            exit;
        }
        if ($action === 'delete_option') {
            shop_delete_modifier_option($pdo, (int) ($_POST['id'] ?? 0));
            header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'm=' . rawurlencode('Option removed.'));
            exit;
        }
        header('Location: /admin/shop_modifiers.php');
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Modifiers',
    'subtitle' => 'Reusable option groups such as sizes, colours or personalisation.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';


$groups = shop_get_modifier_groups($pdo);
$selectedId = (int) ($_GET['group'] ?? 0);
$group = $selectedId > 0 ? shop_get_modifier_group($pdo, $selectedId) : null;
$options = $group ? shop_group_options($pdo, (int) $group['id']) : [];
$editOptionId = (int) ($_GET['option'] ?? 0);
$editOption = null;
foreach ($options as $o) {
    if ((int) $o['id'] === $editOptionId) {
        $editOption = $o;
    }
}
$newGroup = isset($_GET['new']);
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Modifiers</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <section class="card hub-panel">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong>Modifier groups</strong>
                    <a class="btn btn-sm btn-dark" href="/admin/shop_modifiers.php?new=1">New</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (!$groups): ?><div class="list-group-item text-muted">None yet.</div><?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= (int) $g['id'] === $selectedId ? 'active' : '' ?>" href="/admin/shop_modifiers.php?group=<?= (int) $g['id'] ?>">
                            <span><?= h((string) $g['name']) ?><br><small class="<?= (int) $g['id'] === $selectedId ? 'text-white-50' : 'text-muted' ?>"><?= h((string) $g['selection_type']) ?> · <?= (int) $g['option_count'] ?> options<?= (int) $g['is_required'] === 1 ? ' · required' : '' ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-8">
            <?php if ($newGroup || $group): ?>
                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted"><?= $group ? 'Edit group' : 'New group' ?></h2>
                    <form method="post" class="row g-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_group">
                        <?php if ($group): ?><input type="hidden" name="id" value="<?= (int) $group['id'] ?>"><?php endif; ?>
                        <div class="col-md-6"><label class="form-label">Name</label>
                            <input class="form-control" name="name" required value="<?= h((string) ($group['name'] ?? '')) ?>" placeholder="Kit Size"></div>
                        <div class="col-md-3"><label class="form-label">Type</label>
                            <select class="form-select" name="selection_type">
                                <option value="single" <?= (string) ($group['selection_type'] ?? 'single') === 'single' ? 'selected' : '' ?>>Pick one</option>
                                <option value="multi" <?= (string) ($group['selection_type'] ?? '') === 'multi' ? 'selected' : '' ?>>Pick many</option>
                            </select></div>
                        <div class="col-md-3"><label class="form-label">Sort order</label>
                            <input class="form-control" type="number" name="sort_order" value="<?= (int) ($group['sort_order'] ?? 0) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Min select</label>
                            <input class="form-control" type="number" min="0" name="min_select" value="<?= (int) ($group['min_select'] ?? 1) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Max select</label>
                            <input class="form-control" type="number" min="1" name="max_select" value="<?= $group && $group['max_select'] !== null ? (int) $group['max_select'] : '' ?>" placeholder="∞"></div>
                        <div class="col-md-6 d-flex align-items-end"><div class="form-check">
                            <input class="form-check-input" type="checkbox" id="mg_req" name="is_required" value="1" <?= (int) ($group['is_required'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mg_req">Required by default</label></div></div>
                        <div class="col-12"><label class="form-label">Help text</label>
                            <input class="form-control" name="help_text" value="<?= h((string) ($group['help_text'] ?? '')) ?>" placeholder="Shown under the option group on the product page"></div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-dark" type="submit"><?= $group ? 'Save group' : 'Create group' ?></button>
                            <?php if ($group): ?>
                                <form method="post" class="d-inline" data-confirm="Delete this group and its options? It will be removed from any products." data-confirm-action="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                                    <button class="btn btn-outline-danger" type="submit">Delete group</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($group): ?>
                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Options in "<?= h((string) $group['name']) ?>"</h2>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0 hub-data-table">
                            <thead><tr><th>Group</th><th>Label</th><th class="text-end">Price change</th><th>SKU</th><th class="text-end">Stock</th><th>Active</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$options): ?><tr><td colspan="7" class="text-muted text-center py-3">No options yet.</td></tr><?php endif; ?>
                            <?php foreach ($options as $o): ?>
                                <tr>
                                    <td class="small text-muted"><?= ($o['option_group'] ?? '') !== '' ? h((string) $o['option_group']) : '—' ?></td>
                                    <td class="fw-bold"><?= h((string) $o['label']) ?></td>
                                    <td class="text-end"><?= (float) $o['price_delta'] == 0.0 ? '—' : (($o['price_delta'] > 0 ? '+' : '') . gbp((float) $o['price_delta'])) ?></td>
                                    <td class="small text-muted"><?= h((string) $o['sku_suffix']) ?></td>
                                    <td class="text-end"><?= $o['stock_qty'] === null ? '∞' : (int) $o['stock_qty'] ?></td>
                                    <td><?= (int) $o['is_active'] === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_modifiers.php?group=<?= (int) $group['id'] ?>&option=<?= (int) $o['id'] ?>">Edit</a>
                                        <form method="post" class="d-inline" data-confirm="Remove this option?" data-confirm-action="Remove">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_option"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">×</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="h6 fw-bold"><?= $editOption ? 'Edit option' : 'Add option' ?></h3>
                    <form method="post" class="row g-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_option">
                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                        <?php if ($editOption): ?><input type="hidden" name="id" value="<?= (int) $editOption['id'] ?>"><?php endif; ?>
                        <div class="col-md-3"><label class="form-label">Group / heading</label>
                            <input class="form-control" name="option_group" list="shopOptionGroups" value="<?= h((string) ($editOption['option_group'] ?? '')) ?>" placeholder="e.g. Adult">
                            <datalist id="shopOptionGroups">
                                <?php foreach (array_unique(array_filter(array_map(static fn($x) => (string) ($x['option_group'] ?? ''), $options))) as $og): ?>
                                    <option value="<?= h($og) ?>"></option>
                                <?php endforeach; ?>
                                <option value="Adult"></option><option value="Kids"></option>
                            </datalist>
                            <div class="form-text">2+ headings turns the storefront into "choose fit, then size".</div></div>
                        <div class="col-md-3"><label class="form-label">Label</label>
                            <input class="form-control" name="label" required value="<?= h((string) ($editOption['label'] ?? '')) ?>" placeholder="L"></div>
                        <div class="col-md-2"><label class="form-label">Price change (£)</label>
                            <input class="form-control" type="number" step="0.01" name="price_delta" value="<?= h((string) ($editOption['price_delta'] ?? '0.00')) ?>"></div>
                        <div class="col-md-2"><label class="form-label">SKU suffix</label>
                            <input class="form-control" name="sku_suffix" value="<?= h((string) ($editOption['sku_suffix'] ?? '')) ?>"></div>
                        <div class="col-md-1"><label class="form-label">Stock</label>
                            <input class="form-control" type="number" min="0" name="stock_qty" value="<?= $editOption && $editOption['stock_qty'] !== null ? (int) $editOption['stock_qty'] : '' ?>" placeholder="∞"></div>
                        <div class="col-md-1"><label class="form-label">Sort</label>
                            <input class="form-control" type="number" name="sort_order" value="<?= (int) ($editOption['sort_order'] ?? (count($options) + 1) * 10) ?>"></div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="opt_active" name="is_active" value="1" <?= (int) ($editOption['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="opt_active">Available to customers</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-dark" type="submit"><?= $editOption ? 'Save option' : 'Add option' ?></button>
                            <?php if ($editOption): ?><a class="btn btn-outline-secondary" href="/admin/shop_modifiers.php?group=<?= (int) $group['id'] ?>">Cancel</a><?php endif; ?>
                        </div>
                    </form>
                </section>
            <?php elseif (!$newGroup): ?>
                <div class="card hub-panel p-4 text-center text-muted">Select a modifier group on the left, or create a new one.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
