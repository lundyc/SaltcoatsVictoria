<?php

declare(strict_types=1);

// Club Shop — discount codes. Hub admin only.

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
        header('Location: /admin/shop_discount_codes.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_code') {
            shop_save_discount_code($pdo, (int) ($_POST['id'] ?? 0) ?: null, $_POST);
            auditLog($pdo, 'shop_discount_saved', 'Discount code "' . strtoupper((string) ($_POST['code'] ?? '')) . '"');
            $m = 'Discount code saved.';
        } elseif ($action === 'delete_code') {
            shop_delete_discount_code($pdo, (int) ($_POST['id'] ?? 0));
            $m = 'Discount code deleted.';
        } else {
            $m = '';
        }
        header('Location: /admin/shop_discount_codes.php' . ($m !== '' ? '?m=' . rawurlencode($m) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/shop_discount_codes.php?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Discount codes',
    'subtitle' => 'Create and manage checkout discount codes.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';


$codes = shop_discount_codes($pdo);
$editCode = null;
foreach ($codes as $c) {
    if ((int) $c['id'] === (int) ($_GET['code'] ?? 0)) {
        $editCode = $c;
    }
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_settings.php">Settings</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Discount codes</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel p-3">
                <h2 class="h6 fw-bold text-uppercase text-muted"><?= $editCode ? 'Edit discount code' : 'Add discount code' ?></h2>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_code">
                    <?php if ($editCode): ?><input type="hidden" name="id" value="<?= (int) $editCode['id'] ?>"><?php endif; ?>
                    <div class="col-7"><label class="form-label">Code</label>
                        <input class="form-control text-uppercase" name="code" required value="<?= h((string) ($editCode['code'] ?? '')) ?>" placeholder="SUPPORTER10"></div>
                    <div class="col-5"><label class="form-label">Type</label>
                        <select class="form-select" name="discount_type">
                            <option value="percent" <?= (string) ($editCode['discount_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent %</option>
                            <option value="fixed" <?= (string) ($editCode['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed £</option>
                        </select></div>
                    <div class="col-6"><label class="form-label">Value</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="discount_value" required value="<?= h((string) ($editCode['discount_value'] ?? '10')) ?>"></div>
                    <div class="col-6"><label class="form-label">Min spend (£)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="min_spend" value="<?= h((string) ($editCode['min_spend'] ?? '0')) ?>"></div>
                    <div class="col-6"><label class="form-label">Max uses</label>
                        <input class="form-control" type="number" min="1" name="max_uses" value="<?= $editCode && $editCode['max_uses'] !== null ? (int) $editCode['max_uses'] : '' ?>" placeholder="∞"></div>
                    <div class="col-6 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="dc_active" name="is_active" value="1" <?= (int) ($editCode['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="dc_active">Active</label></div></div>
                    <div class="col-6"><label class="form-label">Starts</label>
                        <input class="form-control" type="datetime-local" name="starts_at" value="<?= $editCode && $editCode['starts_at'] ? h((new DateTimeImmutable((string) $editCode['starts_at']))->format('Y-m-d\TH:i')) : '' ?>"></div>
                    <div class="col-6"><label class="form-label">Ends</label>
                        <input class="form-control" type="datetime-local" name="ends_at" value="<?= $editCode && $editCode['ends_at'] ? h((new DateTimeImmutable((string) $editCode['ends_at']))->format('Y-m-d\TH:i')) : '' ?>"></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-dark" type="submit"><?= $editCode ? 'Save code' : 'Add code' ?></button>
                        <?php if ($editCode): ?><a class="btn btn-outline-secondary" href="/admin/shop_discount_codes.php">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="card hub-panel">
                <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                    <thead><tr><th>Code</th><th>Discount</th><th>Min spend</th><th>Uses</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$codes): ?><tr><td colspan="6" class="text-muted text-center py-4">No discount codes.</td></tr><?php endif; ?>
                    <?php foreach ($codes as $c): ?>
                        <tr>
                            <td data-label="Code" class="fw-bold"><?= h((string) $c['code']) ?></td>
                            <td data-label="Discount"><?= (string) $c['discount_type'] === 'percent' ? h((string) (float) $c['discount_value']) . '%' : gbp((float) $c['discount_value']) ?></td>
                            <td data-label="Min spend"><?= (float) $c['min_spend'] > 0 ? gbp((float) $c['min_spend']) : '—' ?></td>
                            <td data-label="Uses"><?= (int) $c['used_count'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
                            <td data-label="Status"><?= (int) $c['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                            <td data-label="Actions" class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_discount_codes.php?code=<?= (int) $c['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" data-confirm="Delete this code?" data-confirm-action="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_code"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
