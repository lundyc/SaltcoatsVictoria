<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_locations.php';
require_once __DIR__ . '/../admin/lib/audit.php';

pos_ensure_schema($pdo);
pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_location') {
                $locationId = pos_location_save($pdo, $_POST);
                auditLog($pdo, 'pos_location_saved', 'Saved POS location #' . $locationId);
                $notice = 'Location saved.';
            } elseif ($action === 'delete_location') {
                $locationId = (int) ($_POST['id'] ?? 0);
                pos_location_deactivate($pdo, $locationId);
                auditLog($pdo, 'pos_location_deleted', 'Deactivated POS location #' . $locationId);
                $notice = 'Location removed from active use.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$locations = $pdo->query('SELECT * FROM pos_locations ORDER BY is_active DESC, sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Locations',
    'subtitle' => 'Add, edit, and remove the tills available from POS Overview.',
];

require_once __DIR__ . '/../admin/header.php';
?>

<div class="pos-locations-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Locations</span></nav>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="hub-section-commandbar" aria-labelledby="posLocationsActionsTitle">
        <div>
            <h2 id="posLocationsActionsTitle">Location setup</h2>
            <p>Active locations are selectable when opening the till from POS Overview.</p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/pos_overview.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i>Overview</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/products.php"><i class="fa-solid fa-box-open" aria-hidden="true"></i>Products</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/operators.php"><i class="fa-solid fa-users-gear" aria-hidden="true"></i>Operators</a>
        </div>
    </section>

    <section class="card hub-panel mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Add location</h2>
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_location">
                <input type="hidden" name="is_active" value="1">
                <div class="col-md-3"><label class="form-label" for="new_name">Name</label><input class="form-control" id="new_name" name="name" required></div>
                <div class="col-md-2"><label class="form-label" for="new_code">Code</label><input class="form-control" id="new_code" name="code" placeholder="Auto from name"></div>
                <div class="col-md-5"><label class="form-label" for="new_description">Description</label><input class="form-control" id="new_description" name="description" maxlength="255"></div>
                <div class="col-md-2"><label class="form-label" for="new_sort_order">Sort order</label><input class="form-control" id="new_sort_order" name="sort_order" type="number" min="0" value="0"></div>
                <div class="col-12"><button class="btn btn-brand" type="submit">Save location</button></div>
            </form>
        </div>
    </section>

    <section class="card hub-panel">
        <div class="card-header bg-transparent">
            <h2 class="h5 mb-0">Existing locations</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle hub-data-table mb-0">
                    <thead><tr><th>Name</th><th>Code</th><th>Description</th><th>Sort</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($locations as $location): ?>
                        <?php $saveFormId = 'save_location_' . (int) $location['id']; ?>
                        <tr>
                            <td><input class="form-control form-control-sm" form="<?= h($saveFormId) ?>" name="name" value="<?= h((string) $location['name']) ?>" required></td>
                            <td><input class="form-control form-control-sm" form="<?= h($saveFormId) ?>" name="code" value="<?= h((string) $location['code']) ?>"></td>
                            <td><input class="form-control form-control-sm" form="<?= h($saveFormId) ?>" name="description" value="<?= h((string) ($location['description'] ?? '')) ?>" maxlength="255"></td>
                            <td><input class="form-control form-control-sm" form="<?= h($saveFormId) ?>" name="sort_order" type="number" min="0" value="<?= (int) $location['sort_order'] ?>"></td>
                            <td>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" form="<?= h($saveFormId) ?>" type="checkbox" name="is_active" value="1" <?= (int) $location['is_active'] === 1 ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= (int) $location['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                </label>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <form id="<?= h($saveFormId) ?>" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="save_location">
                                        <input type="hidden" name="id" value="<?= (int) $location['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Save</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Remove this POS location from active use?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_location">
                                        <input type="hidden" name="id" value="<?= (int) $location['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$locations): ?><tr><td colspan="6" class="hub-empty-state">No POS locations have been created yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../admin/footer.php'; ?>
