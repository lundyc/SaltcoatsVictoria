<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
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
        if ($action === 'save_operator') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = in_array((string) ($_POST['role'] ?? 'operator'), ['operator', 'manager'], true) ? (string) $_POST['role'] : 'operator';
            if ($name === '' || $username === '') {
                $error = 'Name and username are required.';
            } elseif ($id <= 0 && $password === '') {
                $error = 'A password or PIN is required for new operators.';
            } else {
                $isNewOperator = $id <= 0;
                if ($id > 0) {
                    $params = [':id' => $id, ':name' => $name, ':username' => $username, ':role' => $role, ':active' => !empty($_POST['is_active']) ? 1 : 0];
                    $sql = 'UPDATE pos_operators SET name=:name, username=:username, role=:role, is_active=:active';
                    if ($password !== '') {
                        $sql .= ', password_hash=:password_hash';
                        $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id=:id';
                    $pdo->prepare($sql)->execute($params);
                } else {
                    $pdo->prepare('INSERT INTO pos_operators (name, username, password_hash, role, is_active) VALUES (:name, :username, :password_hash, :role, 1)')
                        ->execute([':name' => $name, ':username' => $username, ':password_hash' => password_hash($password, PASSWORD_DEFAULT), ':role' => $role]);
                    $id = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM pos_operator_locations WHERE operator_id = :id')->execute([':id' => $id]);
                $link = $pdo->prepare('INSERT IGNORE INTO pos_operator_locations (operator_id, location_id) VALUES (:operator, :location)');
                foreach (array_map('intval', (array) ($_POST['location_ids'] ?? [])) as $locationId) {
                    $link->execute([':operator' => $id, ':location' => $locationId]);
                }
                auditLog($pdo, $isNewOperator ? 'pos_operator_created' : 'pos_operator_updated', ($isNewOperator ? "Created POS operator '{$name}'" : "Updated POS operator '{$name}' (#{$id})"));
                $notice = 'Operator saved.';
            }
        }
    }
}

$locations = $pdo->query('SELECT * FROM pos_locations ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$operators = $pdo->query('SELECT * FROM pos_operators ORDER BY is_active DESC, name')->fetchAll(PDO::FETCH_ASSOC);
$assigned = [];
foreach ($pdo->query('SELECT operator_id, location_id FROM pos_operator_locations') as $row) {
    $assigned[(int) $row['operator_id']][] = (int) $row['location_id'];
}
$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Operators',
    'subtitle' => 'Manage operator access, roles, and assigned till locations.',
];
require_once __DIR__ . '/../admin/header.php';
?>
<div class="pos-operators-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Operators</span></nav>
    <section class="hub-section-commandbar" aria-labelledby="posOperatorsActionsTitle">
        <div>
            <h2 id="posOperatorsActionsTitle">Operator setup</h2>
            <p>Assign each operator only to the tills they should use.</p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/pos_overview.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i>Overview</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/reports.php"><i class="fa-solid fa-table-list" aria-hidden="true"></i>Daily report</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/products.php"><i class="fa-solid fa-box-open" aria-hidden="true"></i>Products</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/locations.php"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Locations</a>
        </div>
    </section>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <section class="card hub-panel p-3 mb-3">
        <h2 class="h5 fw-bold">Add operator</h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_operator">
            <div class="col-md-3"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
            <div class="col-md-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
            <div class="col-md-2"><label class="form-label">Password/PIN</label><input class="form-control" name="password" required></div>
            <div class="col-md-2"><label class="form-label">Role</label><select class="form-select" name="role"><option value="operator">Operator</option><option value="manager">Manager</option></select></div>
            <div class="col-md-12"><label class="form-label">Locations</label><div class="d-flex gap-3 flex-wrap"><?php foreach ($locations as $location): ?><label><input type="checkbox" name="location_ids[]" value="<?= (int) $location['id'] ?>" checked> <?= h((string) $location['name']) ?></label><?php endforeach; ?></div></div>
            <div class="col-12"><button class="btn btn-dark" type="submit">Save operator</button></div>
        </form>
    </section>
    <section class="card hub-panel table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Locations</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($operators as $operator): ?><tr><td class="fw-bold"><?= h((string) $operator['name']) ?></td><td><?= h((string) $operator['username']) ?></td><td><?= h((string) $operator['role']) ?></td><td><?php $ids = $assigned[(int) $operator['id']] ?? []; echo h(implode(', ', array_map(static fn($l) => (string) $l['name'], array_filter($locations, static fn($l) => in_array((int) $l['id'], $ids, true))))); ?></td><td><?= (int) $operator['is_active'] === 1 ? 'Active' : 'Inactive' ?></td></tr><?php endforeach; ?></tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/../admin/footer.php'; ?>
