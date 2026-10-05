<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Ticket Packages',
    'subtitle' => 'Set the public ticket types, default prices, and admission counts used for home games.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_tickets.php';
require_once __DIR__ . '/lib/audit.php';
ensureMatchTicketSchema($pdo);

$errors = [];
$success = '';
$editingId = (int) ($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } else {
        try {
            $isNewPackage = empty($_POST['id']);
            $groupChoice = trim((string) ($_POST['package_group'] ?? 'General'));
            if ($groupChoice === '__new__') {
                $groupChoice = trim((string) ($_POST['package_group_new'] ?? ''));
            }
            saveMatchTicketPackage($pdo, !empty($_POST['id']) ? (int) $_POST['id'] : null, [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'description' => $_POST['description'] ?? '',
                'default_price' => $_POST['default_price'] ?? 0,
                'package_group' => $groupChoice !== '' ? $groupChoice : 'General',
                'admits_count' => $_POST['admits_count'] ?? 1,
                'sort_order' => $_POST['sort_order'] ?? 0,
                'is_active' => isset($_POST['is_active']),
            ]);
            auditLog($pdo, $isNewPackage ? 'ticket_package_created' : 'ticket_package_updated', ($isNewPackage ? "Created ticket package '" : "Updated ticket package '") . (string) ($_POST['name'] ?? '') . "'");
            $success = 'Ticket package saved.';
            $editingId = 0;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$packages = getMatchTicketPackages($pdo);
$editing = $editingId > 0 ? getMatchTicketPackage($pdo, $editingId) : null;
$form = $editing ?: ['id' => 0, 'name' => '', 'code' => '', 'description' => '', 'default_price' => '0.00', 'package_group' => 'General', 'admits_count' => 1, 'sort_order' => 0, 'is_active' => 1];

$currentGroup = trim((string) ($form['package_group'] ?? '')) ?: 'General';
$groupOptions = [];
foreach ($packages as $pkg) {
    $existingGroup = trim((string) ($pkg['package_group'] ?? ''));
    if ($existingGroup !== '') {
        $groupOptions[$existingGroup] = true;
    }
}
foreach (['General', 'Hospitality', $currentGroup] as $g) {
    $groupOptions[$g] = true;
}
$groupOptions = array_keys($groupOptions);
usort($groupOptions, static function (string $a, string $b): int {
    $rank = ['General' => 0, 'Hospitality' => 1];
    return (($rank[$a] ?? 2) <=> ($rank[$b] ?? 2)) ?: strcasecmp($a, $b);
});
?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0"><?= $editing ? 'Edit Package' : 'Add Package' ?></h2></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                    <div class="mb-3"><label class="form-label" for="ticketName">Name</label><input class="form-control" id="ticketName" name="name" value="<?= h((string) $form['name']) ?>" required></div>
                    <div class="mb-3"><label class="form-label" for="ticketCode">Code</label><input class="form-control" id="ticketCode" name="code" value="<?= h((string) $form['code']) ?>" required></div>
                    <div class="mb-3"><label class="form-label" for="ticketDescription">Description</label><textarea class="form-control" id="ticketDescription" name="description" rows="2"><?= h((string) ($form['description'] ?? '')) ?></textarea></div>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label" for="ticketPrice">Default price</label><input class="form-control" id="ticketPrice" type="number" step="0.01" min="0" name="default_price" value="<?= h((string) $form['default_price']) ?>"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="ticketGroup">Group</label>
                            <select class="form-select" id="ticketGroup" name="package_group" data-group-select>
                                <?php foreach ($groupOptions as $groupOption): ?>
                                    <option value="<?= h($groupOption) ?>"<?= $groupOption === $currentGroup ? ' selected' : '' ?>><?= h($groupOption) ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">Other (new group)…</option>
                            </select>
                            <input class="form-control mt-2 d-none" id="ticketGroupNew" name="package_group_new" maxlength="60" placeholder="New group name" data-group-new>
                            <div class="form-text">Each group is its own section on the public tickets page.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="ticketAdmits">Admits count</label><input class="form-control" id="ticketAdmits" type="number" min="1" name="admits_count" value="<?= (int) $form['admits_count'] ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="ticketSort">Sort order</label><input class="form-control" id="ticketSort" type="number" min="0" name="sort_order" value="<?= (int) $form['sort_order'] ?>"></div>
                    </div>
                    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="ticketActive" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="ticketActive">Package is active</label></div>
                    <button class="btn btn-brand mt-3" type="submit">Save Package</button>
                    <?php if ($editing): ?><a class="btn btn-outline-secondary mt-3" href="/admin/ticket_packages.php">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Packages</h2></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0 hub-data-table">
                        <thead><tr><th>Name</th><th>Group</th><th>Price</th><th>Admits</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($packages as $package): ?>
                                <tr>
                                    <td class="fw-semibold"><?= h((string) $package['name']) ?><div class="small text-muted"><?= h((string) $package['code']) ?></div></td>
                                    <td><?= h((string) $package['package_group']) ?></td>
                                    <td><?= gbp((float) $package['default_price']) ?></td>
                                    <td><?= (int) $package['admits_count'] ?></td>
                                    <td><?= (int) $package['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/ticket_packages.php?id=<?= (int) $package['id'] ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sel = document.querySelector('[data-group-select]');
    var box = document.querySelector('[data-group-new]');
    if (!sel || !box) { return; }
    var sync = function () {
        var isNew = sel.value === '__new__';
        box.classList.toggle('d-none', !isNew);
        box.required = isNew;
        if (isNew) { box.focus(); }
    };
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
