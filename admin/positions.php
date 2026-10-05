<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Club roles',
    'subtitle' => 'Club roles with season-dated history. They are labels only and grant no access.',
    'actions' => [
        ['label' => 'Access templates', 'href' => '/admin/access_roles.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage club roles.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$formErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $formErrors[] = 'Your session could not be verified. Reload the page and try again.';
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $positionId = (int) ($_POST['position_id'] ?? 0);

        if ($action === 'delete') {
            $result = deleteHubPosition($pdo, $positionId);
            if ($result['ok']) {
                header('Location: positions.php?status=deleted');
                exit;
            }
            $formErrors[] = $result['error'];
        } elseif ($action === 'save') {
            $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
            $department = isset($_POST['department']) && is_string($_POST['department']) ? $_POST['department'] : 'other';
            $accessRoleId = (int) ($_POST['access_role_id'] ?? 0);

            if ($name === '') {
                $formErrors[] = 'Enter a club role name.';
            } else {
                $duplicate = $pdo->prepare('SELECT COUNT(*) FROM club_roles WHERE name = :name AND id <> :id');
                $duplicate->execute([':name' => $name, ':id' => $positionId]);
                if ((int) $duplicate->fetchColumn() > 0) {
                    $formErrors[] = 'A club role with that name already exists.';
                }
            }

            if ($formErrors === []) {
                saveHubPosition($pdo, $positionId > 0 ? $positionId : null, [
                    'name' => $name,
                    'department' => $department,
                    'access_role_id' => $accessRoleId,
                ]);
                header('Location: positions.php?status=' . ($positionId > 0 ? 'updated' : 'created'));
                exit;
            }
        }
    }
}

$statusMessages = [
    'created' => 'Club role created successfully.',
    'updated' => 'Club role updated successfully.',
    'deleted' => 'Club role deleted successfully.',
];
$statusKey = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statusMessage = $statusMessages[$statusKey] ?? '';

$positions = getHubPositions($pdo);
$positionsByDepartment = array_fill_keys(array_keys(HUB_POSITION_DEPARTMENTS), []);
foreach ($positions as $position) {
    $department = (string) ($position['department'] ?? 'other');
    if (!array_key_exists($department, HUB_POSITION_DEPARTMENTS)) {
        $department = 'other';
    }
    $positionsByDepartment[$department][] = $position;
}

$holderCounts = [];
foreach ($pdo->query('SELECT club_role_id AS position_id, COUNT(*) AS c FROM person_club_roles GROUP BY club_role_id') as $row) {
    $holderCounts[(int) $row['position_id']] = (int) $row['c'];
}

$accessRoles = getAccessRoles($pdo);
$accessRoleNameById = [];
foreach ($accessRoles as $role) {
    $accessRoleNameById[(int) $role['id']] = (string) $role['name'];
}
?>

<div class="positions-page">

    <?php if ($statusMessage !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($formErrors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($formErrors as $formError): ?>
                    <li><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="hub-section-commandbar">
        <div><h2>Club roles</h2><p>Assign these to people from <a href="/admin/club_people.php">People &amp; Users</a>, with season dates. Club roles are labels only — they do not give anyone access to the Hub. Set access for each person under Admin access on their profile.</p></div>
        <div class="hub-local-actions"><button class="btn btn-brand" type="button" id="addPositionBtn" data-bs-toggle="modal" data-bs-target="#positionEditorModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add club role</button></div>
    </div>

    <div class="d-grid gap-4">
        <?php foreach (HUB_POSITION_DEPARTMENTS as $departmentKey => $departmentLabel): ?>
            <?php $departmentPositions = $positionsByDepartment[$departmentKey] ?? []; ?>
            <div class="card shadow-sm border-0 hub-table-card">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center gap-2 px-4 pt-4 pb-2">
                    <h3 class="h5 mb-0"><?= htmlspecialchars($departmentLabel, ENT_QUOTES, 'UTF-8') ?></h3>
                    <span class="badge text-bg-light"><?= count($departmentPositions) ?> club role<?= count($departmentPositions) === 1 ? '' : 's' ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped hub-data-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Club role</th>
                                <th>Suggested access template</th>
                                <th>People</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($departmentPositions === []): ?>
                                <tr>
                                    <td colspan="4" class="text-muted">No club roles in this department yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($departmentPositions as $position): ?>
                                    <?php $accessRoleId = (int) ($position['access_template_id'] ?? 0); ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $position['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if ($accessRoleId <= 0 || !isset($accessRoleNameById[$accessRoleId])): ?>
                                                <span class="text-muted">None — no suggestion</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary"><?= htmlspecialchars($accessRoleNameById[$accessRoleId], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int) ($holderCounts[(int) $position['id']] ?? 0) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2 hub-actions hub-actions--end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary js-edit-position"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#positionEditorModal"
                                                    data-position="<?= htmlspecialchars(json_encode([
                                                        'id' => (int) $position['id'],
                                                        'name' => (string) $position['name'],
                                                        'department' => (string) ($position['department'] ?? 'other'),
                                                        'access_role_id' => $accessRoleId,
                                                    ], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                                    title="Edit club role"
                                                ><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                                                <?php if (($holderCounts[(int) $position['id']] ?? 0) === 0): ?>
                                                    <form method="post" data-confirm="This cannot be undone." data-confirm-title="Delete this club role?" data-confirm-action="Delete">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="position_id" value="<?= (int) $position['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete club role"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="positionEditorModal" tabindex="-1" aria-labelledby="positionEditorModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content hub-form-card">
            <form method="post" id="positionEditorForm" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="positionEditorModalTitle">Add club role</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="position_id" id="positionEditorId" value="0">

                    <div class="mb-3">
                        <label class="form-label" for="positionEditorName">Name</label>
                        <input class="form-control" type="text" id="positionEditorName" name="name" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="positionEditorDepartment">Department</label>
                        <select class="form-select" id="positionEditorDepartment" name="department">
                            <?php foreach (HUB_POSITION_DEPARTMENTS as $deptKey => $deptLabel): ?>
                                <option value="<?= htmlspecialchars($deptKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($deptLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Groups this club role on People &amp; Users. Site Admin access is set per-person on the Account tab, not here.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="positionEditorAccessRole">Suggested access template (only a suggestion when this role is assigned; it grants nothing by itself)</label>
                        <select class="form-select" id="positionEditorAccessRole" name="access_role_id">
                            <option value="0">None — no suggestion</option>
                            <?php foreach ($accessRoles as $role): ?>
                                <?php if ((int) $role['bypass_all'] === 1) { continue; } ?>
                                <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Edit what a template includes on <a href="/admin/access_roles.php" target="_blank" rel="noopener">Access templates</a>.</div>
                    </div>
                </div>
                <div class="modal-footer hub-actions">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">Save club role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var form = document.getElementById('positionEditorForm');
        var addButton = document.getElementById('addPositionBtn');
        var title = document.getElementById('positionEditorModalTitle');
        var idInput = document.getElementById('positionEditorId');
        var nameInput = document.getElementById('positionEditorName');
        var departmentInput = document.getElementById('positionEditorDepartment');
        var accessRoleInput = document.getElementById('positionEditorAccessRole');

        function resetEditor() {
            form.reset();
            idInput.value = '0';
            title.textContent = 'Add club role';
        }

        if (addButton) {
            addButton.addEventListener('click', resetEditor);
        }

        document.querySelectorAll('.js-edit-position').forEach(function (button) {
            button.addEventListener('click', function () {
                resetEditor();
                var position = JSON.parse(button.getAttribute('data-position') || '{}');
                idInput.value = String(position.id || 0);
                nameInput.value = position.name || '';
                departmentInput.value = position.department || 'other';
                accessRoleInput.value = String(position.access_role_id || 0);
                title.textContent = 'Edit club role';
            });
        });
    }());
</script>

<?php require __DIR__ . '/footer.php'; ?>
