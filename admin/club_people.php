<?php
declare(strict_types=1);

$pageStyles = ['people.css'];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Users',
    'subtitle' => 'One record per real person. Season tickets, login accounts, club positions and dependants attach here.',
    'actions' => [['label' => 'Member sign-in links', 'href' => '/member_invites.php', 'class' => 'btn btn-outline-light btn-sm']],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/positions.php';
require_once __DIR__ . '/lib/access_roles.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage people.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

// Bulk actions post here and redirect back, so a refresh never repeats them.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $redirect = [];
    if (!hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $redirect = ['status_message' => 'session'];
    } else {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['person_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $templateId = (int) ($_POST['bulk_access_template_id'] ?? 0);

        if ($ids === [] || !in_array($bulkAction, ['change_role', 'archive'], true)) {
            $redirect = ['status_message' => 'nothing'];
        } elseif ($bulkAction === 'change_role' && !hub_auth_is_admin()) {
            $redirect = ['status_message' => 'not_admin'];
        } elseif ($bulkAction === 'change_role' && getAccessRole($pdo, $templateId) === null) {
            $redirect = ['status_message' => 'no_role'];
        } else {
            $done = 0;
            $failed = 0;
            foreach ($ids as $id) {
                try {
                    $pdo->beginTransaction();
                    if ($bulkAction === 'archive') {
                        assertPersonMayLoseLoginOrAdministrator($pdo, $id);
                        archivePerson($pdo, $id);
                    } else {
                        person_apply_access_role($pdo, $id, $templateId);
                    }
                    $pdo->commit();
                    $done++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $failed++;
                }
            }
            $redirect = ['status_message' => $bulkAction, 'done' => $done, 'failed' => $failed];
        }
    }
    header('Location: /admin/club_people.php?' . http_build_query($redirect));
    exit;
}

function users_format_last_login(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'Never';
    }
    return date('j M Y', strtotime($datetime) ?: time());
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = ($_GET['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
$roleFilter = (int) ($_GET['role_id'] ?? 0);

$people = getPeopleDirectory($pdo, ['search' => $search, 'status' => $statusFilter]);
$activeCount = count(getPeopleDirectory($pdo, ['search' => $search, 'status' => 'active']));
$archivedCount = count(getPeopleDirectory($pdo, ['search' => $search, 'status' => 'archived']));

// Counts for the role links come from the people in the current status view.
$roleCounts = [];
foreach ($people as $person) {
    $key = (int) ($person['access_role_id'] ?? 0);
    $roleCounts[$key] = ($roleCounts[$key] ?? 0) + 1;
}

$roleLinks = [];
foreach (getAccessRoles($pdo) as $role) {
    $roleId = (int) $role['id'];
    if (($roleCounts[$roleId] ?? 0) > 0) {
        $roleLinks[] = ['id' => $roleId, 'name' => (string) $role['name'], 'count' => $roleCounts[$roleId]];
    }
}
$noRoleCount = $roleCounts[0] ?? 0;

$visible = $people;
if ($roleFilter > 0) {
    $visible = array_values(array_filter($people, static fn(array $p): bool => (int) ($p['access_role_id'] ?? 0) === $roleFilter));
} elseif ($roleFilter === -1) {
    $visible = array_values(array_filter($people, static fn(array $p): bool => (int) ($p['access_role_id'] ?? 0) === 0));
}

$allRoles = getAccessRoles($pdo);

$statusMessages = [
    'created' => 'Person created.',
    'saved' => 'Person saved.',
    'archived' => 'Person archived.',
    'account_created' => 'Hub account created.',
    'session' => 'Your session could not be verified. Reload the page and try again.',
    'nothing' => 'Choose people and an action first.',
    'not_admin' => 'Only an Administrator can change roles.',
    'no_role' => 'Choose a role to apply.',
];
$statusKey = (string) ($_GET['status_message'] ?? '');
$statusMessage = '';
if (isset($statusMessages[$statusKey])) {
    $statusMessage = $statusMessages[$statusKey];
} elseif ($statusKey === 'change_role' || $statusKey === 'archive') {
    $done = (int) ($_GET['done'] ?? 0);
    $failed = (int) ($_GET['failed'] ?? 0);
    $statusMessage = $statusKey === 'archive'
        ? "Archived {$done} " . ($done === 1 ? 'person' : 'people') . '.'
        : "Changed the role of {$done} " . ($done === 1 ? 'person' : 'people') . '.';
    if ($failed > 0) {
        $statusMessage .= " {$failed} could not be changed (for example, the last Administrator cannot be removed).";
    }
}

$baseQuery = ['q' => $search, 'status' => $statusFilter];
$queryFor = static function (array $extra) use ($baseQuery): string {
    return '/admin/club_people.php?' . http_build_query(array_merge(array_filter($baseQuery, static fn($v) => $v !== ''), $extra));
};
$isAllView = $statusFilter === 'active' && $roleFilter === 0;
?>

<?php if ($statusMessage !== ''): ?><div class="alert alert-success"><?= h($statusMessage) ?></div><?php endif; ?>

<div class="hub-section-commandbar">
    <div>
        <h2>All users</h2>
        <p><?= $activeCount ?> active <?= $activeCount === 1 ? 'person' : 'people' ?>. A person only becomes a user when they have a Hub login.</p>
    </div>
    <div class="hub-local-actions">
        <a class="btn btn-brand" href="/admin/club_person.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add New User</a>
    </div>
</div>

<nav class="hub-section-tabs" aria-label="Filter users">
    <ul class="nav">
        <li class="nav-item"><a class="nav-link<?= $isAllView ? ' active' : '' ?>"<?= $isAllView ? ' aria-current="page"' : '' ?> href="<?= h($queryFor(['status' => 'active'])) ?>">All <span class="badge rounded-pill text-bg-light ms-1"><?= $activeCount ?></span></a></li>
        <?php foreach ($roleLinks as $link): ?>
            <li class="nav-item"><a class="nav-link<?= $roleFilter === $link['id'] ? ' active' : '' ?>"<?= $roleFilter === $link['id'] ? ' aria-current="page"' : '' ?> href="<?= h($queryFor(['status' => $statusFilter, 'role_id' => $link['id']])) ?>"><?= h($link['name']) ?> <span class="badge rounded-pill text-bg-light ms-1"><?= $link['count'] ?></span></a></li>
        <?php endforeach; ?>
        <?php if ($noRoleCount > 0): ?>
            <li class="nav-item"><a class="nav-link<?= $roleFilter === -1 ? ' active' : '' ?>"<?= $roleFilter === -1 ? ' aria-current="page"' : '' ?> href="<?= h($queryFor(['status' => $statusFilter, 'role_id' => -1])) ?>">No role <span class="badge rounded-pill text-bg-light ms-1"><?= $noRoleCount ?></span></a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link<?= $statusFilter === 'archived' ? ' active' : '' ?>"<?= $statusFilter === 'archived' ? ' aria-current="page"' : '' ?> href="<?= h($queryFor(['status' => 'archived'])) ?>">Archived <span class="badge rounded-pill text-bg-light ms-1"><?= $archivedCount ?></span></a></li>
    </ul>
</nav>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
        <?php if ($roleFilter !== 0): ?><input type="hidden" name="role_id" value="<?= $roleFilter ?>"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-6">
                <label class="form-label" for="userSearch">Search</label>
                <input id="userSearch" class="form-control" type="search" name="q" value="<?= h($search) ?>" placeholder="Name, email or phone">
            </div>
            <div class="col-12 col-lg-2">
                <button class="btn btn-brand w-100" type="submit">Search</button>
            </div>
        </div>
    </div>
</form>

<form method="post" id="bulkForm" action="/admin/club_people.php" class="card shadow-sm border-0 hub-table-card">
    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
    <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
        <label class="visually-hidden" for="bulkAction">Bulk actions</label>
        <select id="bulkAction" name="bulk_action" class="form-select form-select-sm w-auto">
            <option value="">Bulk actions</option>
            <option value="change_role">Change role to&hellip;</option>
            <option value="archive">Archive</option>
        </select>
        <label class="visually-hidden" for="bulkRole">Role</label>
        <select id="bulkRole" name="bulk_access_template_id" class="form-select form-select-sm w-auto">
            <?php foreach ($allRoles as $role): ?>
                <option value="<?= (int) $role['id'] ?>" data-bypass="<?= (int) $role['bypass_all'] ?>"><?= h((string) $role['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit" id="bulkApply">Apply</button>
        <span class="text-muted small ms-auto"><?= count($visible) ?> <?= count($visible) === 1 ? 'user' : 'users' ?></span>
    </div>

    <div class="table-responsive">
        <table class="table align-middle hub-data-table hub-data-table--responsive">
            <caption class="visually-hidden">Users with role, club positions, login status and last login</caption>
            <thead>
                <tr>
                    <th scope="col" class="check-column"><input type="checkbox" class="form-check-input" id="bulkSelectAll" aria-label="Select all users"></th>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Club positions</th>
                    <th scope="col">Login</th>
                    <th scope="col">Last login</th>
                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($visible === []): ?>
                <tr><td colspan="8" class="hub-record-empty hub-empty-state">No users match these filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($visible as $person): ?>
                <?php $personId = (int) $person['id']; ?>
                <tr class="people-row" data-href="/admin/club_person.php?id=<?= $personId ?>">
                    <td class="check-column" data-label="Select"><input class="form-check-input" type="checkbox" name="person_ids[]" value="<?= $personId ?>" aria-label="Select <?= h((string) $person['display_name']) ?>"></td>
                    <td data-label="Name">
                        <a class="fw-semibold" href="/admin/club_person.php?id=<?= $personId ?>"><?= h((string) $person['display_name']) ?></a>
                        <?php if ((int) $person['is_active'] === 0): ?><span class="badge hub-status text-bg-secondary ms-1">Archived</span><?php endif; ?>
                    </td>
                    <td data-label="Email"><?= h((string) ($person['email'] ?: $person['account_email'] ?: '')) ?></td>
                    <td data-label="Role"><?= $person['access_role_name'] !== null ? '<span class="badge hub-status text-bg-light">' . h((string) $person['access_role_name']) . '</span>' : '<span class="text-muted">No role</span>' ?></td>
                    <td data-label="Club positions"><?= h((string) ($person['position_names'] ?: '-')) ?></td>
                    <td data-label="Login">
                        <?php if (!empty($person['account_id'])): ?>
                            <span class="badge hub-status <?= (int) $person['account_is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $person['account_is_active'] === 1 ? 'Active' : 'Disabled' ?></span>
                        <?php else: ?>
                            <span class="text-muted">No login</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Last login"><?= h(users_format_last_login($person['last_login_at'] ?? null)) ?></td>
                    <td class="text-end" data-label="Actions">
                        <div class="hub-row-actions hub-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/club_person.php?id=<?= $personId ?>">Edit</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
(function () {
    var selectAll = document.getElementById('bulkSelectAll');
    var form = document.getElementById('bulkForm');
    var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('input[name="person_ids[]"]')); };

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            boxes().forEach(function (box) { box.checked = selectAll.checked; });
        });
    }

    form.addEventListener('submit', function (event) {
        var action = document.getElementById('bulkAction').value;
        var checked = boxes().filter(function (box) { return box.checked; }).length;
        if (action === '' || checked === 0) {
            event.preventDefault();
            window.alert(checked === 0 ? 'Choose at least one person.' : 'Choose a bulk action.');
            return;
        }
        var role = document.getElementById('bulkRole');
        var noun = checked === 1 ? 'person' : 'people';
        if (action === 'archive' && !window.confirm('Archive ' + checked + ' ' + noun + '? Their history is kept.')) {
            event.preventDefault();
            return;
        }
        if (action === 'change_role') {
            if (role.selectedOptions[0].dataset.bypass === '1' && !window.confirm('Give ' + checked + ' ' + noun + ' full Administrator access? Continue?')) {
                event.preventDefault();
                return;
            }
            if (!window.confirm('Change the role of ' + checked + ' ' + noun + ' to ' + role.selectedOptions[0].textContent + '?')) {
                event.preventDefault();
            }
        }
    });

    document.querySelectorAll('.people-row[data-href]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            window.location.assign(row.dataset.href);
        });
    });
}());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
