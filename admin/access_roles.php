<?php
declare(strict_types=1);

$pageStyles = [];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Roles',
    'subtitle' => 'Each role is a named set of Hub permissions. Assign roles to a person from their Users page.',
    'actions' => [['label' => 'Club positions', 'href' => '/admin/positions.php', 'class' => 'btn btn-outline-light btn-sm']],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_is_admin()) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage roles.</div></div>';
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

        if ($action === 'delete_role') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $result = deleteAccessRole($pdo, $roleId);
            if ($result['ok']) {
                header('Location: access_roles.php?status=deleted');
                exit;
            }
            $formErrors[] = $result['error'];
        }
    }
}

$statusMessages = [
    'saved' => 'Role saved.',
    'created' => 'Role created.',
    'deleted' => 'Role deleted.',
];
$statusKey = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statusMessage = $statusMessages[$statusKey] ?? '';

$capabilityCount = count(getCapabilitiesCatalog($pdo));
$roles = getAccessRoles($pdo);
?>

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
    <div>
        <h2>All roles</h2>
        <p>Click a role to grant or deny each of the <?= $capabilityCount ?> capabilities. Each person has one role, and changing a role changes it for everyone on it.</p>
    </div>
    <div class="hub-local-actions">
        <a class="btn btn-brand" href="/admin/access_role_edit.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add New Role</a>
    </div>
</div>

<div class="card shadow-sm border-0 hub-table-card">
    <div class="table-responsive">
        <table class="table align-middle hub-data-table hub-data-table--responsive">
            <caption class="visually-hidden">Roles, capabilities, people and club positions</caption>
            <thead>
                <tr>
                    <th scope="col">Role</th>
                    <th scope="col">Capabilities</th>
                    <th scope="col">People</th>
                    <th scope="col">Club positions</th>
                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php
                    $roleId = (int) $role['id'];
                    $bypassAll = (int) $role['bypass_all'] === 1;
                    $isSystem = (int) $role['is_system'] === 1;
                    $grantedCount = $bypassAll ? $capabilityCount : count(getAccessRoleCapabilitySlugs($pdo, $roleId));
                    $usage = getAccessRoleUsageCounts($pdo, $roleId);
                    ?>
                    <tr>
                        <td data-label="Role">
                            <a class="fw-semibold" href="/admin/access_role_edit.php?id=<?= $roleId ?>"><?= htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            <?php if ($isSystem): ?><span class="badge hub-status text-bg-light ms-1">System</span><?php endif; ?>
                        </td>
                        <td data-label="Capabilities">
                            <?php if ($bypassAll): ?>
                                <span class="badge hub-status text-bg-success">All capabilities</span>
                            <?php else: ?>
                                <?= $grantedCount ?> of <?= $capabilityCount ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="People"><?= (int) $usage['people'] ?></td>
                        <td data-label="Club positions"><?= (int) $usage['positions'] ?></td>
                        <td class="text-end" data-label="Actions">
                            <div class="hub-row-actions hub-actions justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/admin/access_role_edit.php?id=<?= $roleId ?>">Edit</a>
                                <?php if (!$isSystem): ?>
                                    <form method="post" class="d-inline" data-confirm="This cannot be undone." data-confirm-title="Delete this role?" data-confirm-action="Delete">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= $roleId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
