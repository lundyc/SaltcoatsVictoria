<?php
declare(strict_types=1);

$pageStyles = [];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Roles',
    'subtitle' => 'Tick a capability to grant it to this role; leave it unticked to deny it.',
    'actions' => [['label' => 'Back to Roles', 'href' => '/admin/access_roles.php', 'class' => 'btn btn-outline-light btn-sm']],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_is_admin()) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage roles.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$roleId = (int) ($_GET['id'] ?? $_POST['role_id'] ?? 0);
$isNew = $roleId <= 0;
$role = $isNew ? null : getAccessRole($pdo, $roleId);
if (!$isNew && $role === null) {
    http_response_code(404);
    echo '<div><div class="alert alert-danger">That role no longer exists.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}
$isSystem = $role !== null && (int) $role['is_system'] === 1;
$bypassAll = $role !== null && (int) $role['bypass_all'] === 1;
$isMember = $role !== null && (string) $role['slug'] === 'member';

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
                $formErrors[] = 'Enter a role name.';
            } else {
                $newRoleId = createAccessRole($pdo, $name);
                setAccessRoleCapabilities($pdo, $newRoleId, $capabilityIds);
                header('Location: access_roles.php?status=created');
                exit;
            }
        } else {
            if (!$isSystem && $name === '') {
                $formErrors[] = 'Enter a role name.';
            } elseif ($bypassAll || $isMember) {
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
?>

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
    <div class="alert alert-info">The Administrator role always has every capability. There is nothing to grant or deny here.</div>
<?php elseif ($isMember): ?>
    <div class="alert alert-info">Member is the safe default login role. It deliberately has no administration capabilities and cannot be changed.</div>
<?php endif; ?>

<form method="post" class="access-role-edit-page">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="role_id" value="<?= $roleId ?>">

    <section class="hub-form-section mb-4">
        <header class="hub-form-section__header">
            <h2 class="hub-form-section__title"><?= $isNew ? 'New role' : htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></h2>
        </header>
        <div class="hub-form-section__body">
            <label class="form-label" for="roleName">Role name</label>
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
                <div class="form-text">System roles keep their name, because it is tied to how login access works.</div>
            <?php endif; ?>
        </div>
    </section>

    <div class="card shadow-sm border-0 hub-table-card mb-4">
        <div class="card-header bg-transparent">
            <h2 class="h5 mb-0">Capabilities</h2>
        </div>
        <div class="table-responsive">
            <table class="table align-middle hub-data-table">
                <caption class="visually-hidden">Capabilities and whether this role has access</caption>
                <thead>
                    <tr>
                        <th scope="col">Capability</th>
                        <th scope="col" class="text-center" style="width:140px;">Access</th>
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
                                        <?= ($bypassAll || $isMember) ? 'disabled' : '' ?>
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

    <?php if (!$bypassAll && !$isMember): ?>
        <div class="hub-actions justify-content-end mt-3">
            <a class="btn btn-outline-secondary" href="/admin/access_roles.php">Cancel</a>
            <button type="submit" class="btn btn-brand"><?= $isNew ? 'Create role' : 'Save role' ?></button>
        </div>
    <?php endif; ?>
</form>

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
