<?php
declare(strict_types=1);

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage access templates.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$roleId = (int) ($_GET['id'] ?? $_POST['role_id'] ?? 0);
$isNew = $roleId <= 0;
$role = $isNew ? null : getAccessRole($pdo, $roleId);
if (!$isNew && $role === null) {
    http_response_code(404);
    echo '<div><div class="alert alert-danger">That access template no longer exists.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}
$isSystem = $role !== null && (int) $role['is_system'] === 1;
$bypassAll = $role !== null && (int) $role['bypass_all'] === 1;

$formErrors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $formErrors[] = 'Your session could not be verified. Reload the page and try again.';
    } else {
        $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
        $capabilityIds = isset($_POST['capabilities']) && is_array($_POST['capabilities'])
            ? array_map('intval', $_POST['capabilities'])
            : [];

        if ($isNew) {
            if ($name === '') {
                $formErrors[] = 'Enter an access template name.';
            } else {
                $newRoleId = createAccessRole($pdo, $name);
                setAccessRoleCapabilities($pdo, $newRoleId, $capabilityIds);
                header('Location: access_roles.php?status=created');
                exit;
            }
        } else {
            if (!$isSystem && $name === '') {
                $formErrors[] = 'Enter an access template name.';
            } elseif ($bypassAll) {
                header('Location: access_roles.php?status=saved');
                exit;
            } else {
                if (!$isSystem) {
                    renameAccessRole($pdo, $roleId, $name);
                }
                setAccessRoleCapabilities($pdo, $roleId, $capabilityIds);
                header('Location: access_roles.php?status=saved');
                exit;
            }
        }
    }
}

$capabilities = getCapabilitiesCatalog($pdo);
$capabilitySlugById = array_column($capabilities, 'slug', 'id');

if ($formErrors !== [] && isset($_POST['capabilities']) && is_array($_POST['capabilities'])) {
    // Redisplaying after a validation error — keep what the admin just
    // ticked rather than reloading the saved (or empty, for a new role) state.
    $grantedSlugs = array_values(array_filter(array_map(
        static fn(string $id): ?string => $capabilitySlugById[(int) $id] ?? null,
        $_POST['capabilities']
    )));
} elseif ($bypassAll) {
    $grantedSlugs = array_column($capabilities, 'slug');
} elseif ($isNew) {
    $grantedSlugs = [];
} else {
    $grantedSlugs = getAccessRoleCapabilitySlugs($pdo, $roleId);
}
$roleName = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : (string) ($role['name'] ?? '');

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => $isNew ? 'Add access template' : 'Edit access template: ' . $roleName,
    'subtitle' => 'Tick a capability to grant it to this template; leave it unticked to deny it.',
    'actions' => [
        ['label' => 'Back to Access templates', 'href' => '/admin/access_roles.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];
?>

<div class="access-role-edit-page">

    <?php if ($formErrors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($formErrors as $formError): ?>
                    <li><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($bypassAll): ?>
        <div class="alert alert-info">The Administrator template always has every capability — there's nothing to grant or deny here.</div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="role_id" value="<?= $roleId ?>">

        <div class="card shadow-sm border-0 hub-form-card mb-4">
            <div class="card-body">
                <label class="form-label" for="roleName">Access template name</label>
                <input
                    class="form-control"
                    type="text"
                    id="roleName"
                    name="name"
                    maxlength="120"
                    value="<?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $isSystem ? 'readonly' : 'required' ?>
                >
                <?php if ($isSystem): ?>
                    <div class="form-text">System templates keep their name — it's tied to how login access works.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0 hub-table-card">
            <div class="card-header bg-white border-0 px-4 pt-4 pb-2">
                <h3 class="h5 mb-0">Capabilities</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-striped hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Capability</th>
                            <th class="text-center" style="width:140px;">Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($capabilities as $capability): ?>
                            <?php $granted = in_array((string) $capability['slug'], $grantedSlugs, true); ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $capability['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-flex m-0">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            name="capabilities[]"
                                            value="<?= (int) $capability['id'] ?>"
                                            id="cap_<?= (int) $capability['id'] ?>"
                                            <?= $granted ? 'checked' : '' ?>
                                            <?= $bypassAll ? 'disabled' : '' ?>
                                        >
                                        <label class="form-check-label ms-2" for="cap_<?= (int) $capability['id'] ?>"><?= $granted ? 'Access' : 'Deny' ?></label>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$bypassAll): ?>
            <div class="hub-actions justify-content-end mt-3">
                <a class="btn btn-outline-secondary" href="/admin/access_roles.php">Cancel</a>
                <button type="submit" class="btn btn-brand"><?= $isNew ? 'Create access template' : 'Save access template' ?></button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
    (function () {
        document.querySelectorAll('.access-role-edit-page input[type="checkbox"]').forEach(function (box) {
            box.addEventListener('change', function () {
                var label = box.closest('.form-check').querySelector('.form-check-label');
                if (label) {
                    label.textContent = box.checked ? 'Access' : 'Deny';
                }
            });
        });
    }());
</script>

<?php require __DIR__ . '/footer.php'; ?>
