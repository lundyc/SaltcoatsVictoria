<?php
declare(strict_types=1);

$pageStyles = ['people.css'];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'People & Users',
    'subtitle' => 'One record per real person. Season tickets, login accounts, club positions and dependants attach here.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/positions.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage people.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * Role badge shown in both directory tables. 'staff' and 'admin' both read
 * as "has a job in the club" for the grouping below; 'public' just means
 * "has a Hub login, nothing more".
 */
function peopleRoleBadge(string $roleCode): string
{
    $roles = [
        'admin' => ['label' => 'Admin', 'class' => 'text-bg-dark'],
        'staff' => ['label' => 'Staff', 'class' => 'text-bg-primary'],
        'volunteer' => ['label' => 'Volunteer', 'class' => 'text-bg-info'],
        'public' => ['label' => 'Member', 'class' => 'text-bg-light'],
    ];
    if (!isset($roles[$roleCode])) {
        return '<span class="text-muted">-</span>';
    }
    return '<span class="badge ' . $roles[$roleCode]['class'] . '">' . h($roles[$roleCode]['label']) . '</span>';
}

/**
 * @param array<string,mixed> $person
 */
function peoplePersonRow(array $person, bool $showPosition): string
{
    $roleCode = (string) ($person['role_codes'] ?? '');
    ob_start();
    ?>
    <tr class="people-row" role="link" tabindex="0" data-href="/admin/club_person.php?id=<?= (int) $person['id'] ?>" aria-label="Open person record: <?= h((string) $person['display_name']) ?>">
        <td class="fw-semibold">
            <?= h((string) $person['display_name']) ?>
            <?php if ((int) ($person['dependents_count'] ?? 0) > 0): ?><div class="small text-muted"><?= (int) $person['dependents_count'] ?> dependant<?= (int) $person['dependents_count'] === 1 ? '' : 's' ?></div><?php endif; ?>
        </td>
        <td>
            <?php if (!empty($person['account_id'])): ?>
                <span class="badge <?= (int) $person['account_is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $person['account_is_active'] === 1 ? 'Active' : 'Disabled' ?></span>
            <?php else: ?>
                <span class="badge text-bg-light">Person only</span>
                <?php if (!empty($person['position_names']) && empty($person['email'])): ?><div class="small text-muted">No login email</div><?php endif; ?>
            <?php endif; ?>
        </td>
        <td><?= peopleRoleBadge($roleCode) ?></td>
        <?php if ($showPosition): ?><td><?= h((string) ($person['position_names'] ?: '-')) ?></td><?php endif; ?>
        <td><?= h((string) ($person['email'] ?: $person['account_email'] ?: '-')) ?></td>
        <td><?= (int) $person['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Archived</span>' ?></td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

function peopleGroupHeaderRow(string $label, int $count, string $icon, int $colspan): string
{
    ob_start();
    ?>
    <tr class="people-group-row people-group-row--role">
        <td colspan="<?= $colspan ?>"><i class="fa-solid <?= h($icon) ?> me-2" aria-hidden="true"></i><?= h($label) ?> <span class="text-muted fw-normal">(<?= $count ?>)</span></td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'active');
$accountFilter = (string) ($_GET['account'] ?? '');
$positionFilter = (int) ($_GET['position_id'] ?? 0);
$roleFilter = (string) ($_GET['role'] ?? '');
$loginFilter = (string) ($_GET['login'] ?? '');
$positions = getHubPositions($pdo);

$people = getPeopleDirectory($pdo, [
    'search' => $search,
    'status' => in_array($statusFilter, ['active', 'archived', 'all'], true) ? $statusFilter : 'active',
    'account' => in_array($accountFilter, ['with', 'without'], true) ? $accountFilter : '',
    'position_id' => $positionFilter > 0 ? $positionFilter : null,
    'role' => in_array($roleFilter, ['admin', 'staff', 'volunteer', 'public'], true) ? $roleFilter : '',
    'login' => in_array($loginFilter, ['never', 'active'], true) ? $loginFilter : '',
]);

$statusMessages = [
    'created' => 'Person created.',
    'saved' => 'Person saved.',
    'archived' => 'Person archived.',
    'account_created' => 'Hub account created.',
];
$statusMessage = $statusMessages[(string) ($_GET['status_message'] ?? '')] ?? '';

// Split the directory into "has a job in the club" and "everyone else" (no
// account, no position), then group the first bucket into sections: Site
// Admins (the account-level "Site Administrator" flag, independent of any
// position), then Committee/Football Department/Other Roles by the
// department of their primary current position (lowest sort_order -- a
// person can hold more than one position, but only groups under one here).
$sectionOrder = ['committee', 'football', 'admin', 'other'];
$sectionLabels = ['admin' => 'Site Admins'] + HUB_POSITION_DEPARTMENTS;
$sectionIcons = [
    'committee' => 'fa-user-tie',
    'football' => 'fa-futbol',
    'admin' => 'fa-user-shield',
    'other' => 'fa-hand-holding-heart',
];
$positionsByName = array_column($positions, null, 'name');

$committee = [];
$members = [];

foreach ($people as $person) {
    $roleCode = (string) ($person['role_codes'] ?? '');
    $hasPosition = trim((string) ($person['position_names'] ?? '')) !== '';

    if ($roleCode !== 'admin' && $roleCode !== 'staff' && $roleCode !== 'volunteer' && !$hasPosition) {
        $members[] = $person;
        continue;
    }

    $primary = null;
    if ($hasPosition) {
        $names = array_filter(array_map('trim', explode(',', (string) $person['position_names'])));
        foreach ($names as $name) {
            $posRow = $positionsByName[$name] ?? null;
            $sort = $posRow ? (int) $posRow['sort_order'] : PHP_INT_MAX;
            if ($primary === null || $sort < $primary['sort_order']) {
                $department = $posRow ? (string) $posRow['department'] : 'other';
                $primary = [
                    'name' => $name,
                    'sort_order' => $sort,
                    'department' => array_key_exists($department, HUB_POSITION_DEPARTMENTS) ? $department : 'other',
                ];
            }
        }
    }

    $section = $roleCode === 'admin' ? 'admin' : ($primary['department'] ?? 'other');
    $posLabel = $section === 'admin' ? '' : ($primary['name'] ?? '');
    $posSort = $primary['sort_order'] ?? PHP_INT_MAX;

    if (!isset($committee[$section])) {
        $committee[$section] = ['positions' => []];
    }
    if (!isset($committee[$section]['positions'][$posLabel])) {
        $committee[$section]['positions'][$posLabel] = ['sort_order' => $posSort, 'people' => []];
    }
    $committee[$section]['positions'][$posLabel]['people'][] = $person;
}

foreach ($committee as &$sectionBucket) {
    uasort($sectionBucket['positions'], fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
}
unset($sectionBucket);

$committeeOrdered = [];
foreach ($sectionOrder as $code) {
    if (isset($committee[$code])) {
        $committeeOrdered[$code] = $committee[$code];
    }
}
$committeeCount = count($people) - count($members);
?>

<?php if ($statusMessage !== ''): ?><div class="alert alert-success"><?= h($statusMessage) ?></div><?php endif; ?>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-lg-3">
                <label class="form-label" for="peopleSearch">Search</label>
                <input class="form-control" id="peopleSearch" type="search" name="q" value="<?= h($search) ?>" placeholder="Name, email, phone">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="peopleStatus">Status</label>
                <select class="form-select" id="peopleStatus" name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="peopleRole">Role</label>
                <select class="form-select" id="peopleRole" name="role">
                    <option value="">Any</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Site Administrator</option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff / Committee</option>
                    <option value="volunteer" <?= $roleFilter === 'volunteer' ? 'selected' : '' ?>>Volunteer</option>
                    <option value="public" <?= $roleFilter === 'public' ? 'selected' : '' ?>>Member</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="peoplePosition">Position</label>
                <select class="form-select" id="peoplePosition" name="position_id">
                    <option value="0">Any</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= (int) $position['id'] ?>" <?= $positionFilter === (int) $position['id'] ? 'selected' : '' ?>><?= h((string) $position['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="peopleAccount">Account</label>
                <select class="form-select" id="peopleAccount" name="account">
                    <option value="">Any</option>
                    <option value="with" <?= $accountFilter === 'with' ? 'selected' : '' ?>>Has account</option>
                    <option value="without" <?= $accountFilter === 'without' ? 'selected' : '' ?>>No account</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="peopleLogin">Login activity</label>
                <select class="form-select" id="peopleLogin" name="login">
                    <option value="">Any</option>
                    <option value="never" <?= $loginFilter === 'never' ? 'selected' : '' ?>>Never logged in</option>
                    <option value="active" <?= $loginFilter === 'active' ? 'selected' : '' ?>>Has logged in</option>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <button class="btn btn-brand w-100" type="submit">Filter</button>
            </div>
        </div>
    </div>
</form>

<?php
$peopleFiltersActive = $search !== '' || $statusFilter !== 'active' || $accountFilter !== '' || $positionFilter !== 0 || $roleFilter !== '' || $loginFilter !== '';
?>
<div class="hub-section-commandbar">
    <div>
        <h2>People & Users</h2>
        <p><?= count($people) ?> person<?= count($people) === 1 ? '' : 's' ?> found &mdash; <?= $committeeCount ?> with a club role, <?= count($members) ?> without. A person only becomes a site user when they have a Hub account.
        <?php if ($peopleFiltersActive): ?> <a href="/admin/club_people.php">Clear filters</a><?php endif; ?>
        </p>
    </div>
    <div class="hub-local-actions"><a class="btn btn-brand btn-sm" href="/admin/club_person.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add person</a></div>
</div>

<div class="card shadow-sm border-0 hub-table-card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="mb-0">Club Roles &amp; Committee</h3>
        <span class="text-muted small"><?= $committeeCount ?> people</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle hub-data-table mb-0">
            <thead><tr><th>Name</th><th>Account</th><th>Role</th><th>Position</th><th>Email</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$committeeOrdered): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No one with a club role matches these filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($committeeOrdered as $sectionKey => $sectionBucket): ?>
                <?php
                $sectionCount = 0;
                foreach ($sectionBucket['positions'] as $bucket) {
                    $sectionCount += count($bucket['people']);
                }
                ?>
                <?= peopleGroupHeaderRow($sectionLabels[$sectionKey], $sectionCount, $sectionIcons[$sectionKey], 6) ?>
                <?php foreach ($sectionBucket['positions'] as $posBucket): ?>
                    <?php foreach ($posBucket['people'] as $person): ?>
                        <?= peoplePersonRow($person, true) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0 hub-table-card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="mb-0">Members &amp; Supporters</h3>
        <span class="text-muted small"><?= count($members) ?> people</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle hub-data-table mb-0">
            <thead><tr><th>Name</th><th>Account</th><th>Role</th><th>Email</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($members as $person): ?>
                <?= peoplePersonRow($person, false) ?>
            <?php endforeach; ?>
            <?php if (!$members): ?><tr><td colspan="5" class="text-center text-muted py-4">No one matches these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.people-row[data-href]').forEach(function (row) {
        function openPerson() {
            window.location.assign(row.dataset.href);
        }
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            openPerson();
        });
        row.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            openPerson();
        });
    });
}());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
