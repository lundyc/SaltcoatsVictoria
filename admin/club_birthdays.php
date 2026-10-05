<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'People',
    'title' => 'Club birthdays',
    'subtitle' => 'Every recorded date of birth, so you can check they are all correct. Click a row to edit the profile.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_birthdays.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view birthdays.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$birthdays = players_all_birthdays($pdo);
$missingDobs = players_all_birthdays($pdo, 0, true);
usort($birthdays, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$verifiedDobs = players_verified_dobs($pdo);
$allRows = array_merge(array_map(static fn (array $b): array => $b + ['missing' => true], $missingDobs), $birthdays);

function birthdayGroup(array $b): string
{
    if (birthdaySource($b) === 'player') {
        return 'Players';
    }
    $role = strtolower((string) $b['role_label']);
    if (str_contains($role, 'coach') || str_contains($role, 'manager') && !str_contains($role, 'committee')) {
        return 'Coaches';
    }
    foreach (['chairman', 'secretary', 'treasurer', 'committee'] as $word) {
        if (str_contains($role, $word)) {
            return 'Committee';
        }
    }
    return 'Other';
}

function birthdaySource(array $b): string
{
    return str_starts_with((string) $b['href'], '/club_person.php') ? 'person' : 'player';
}

function birthdayEditHref(array $b): string
{
    if (str_starts_with((string) $b['href'], '/club_person.php')) {
        return '/admin/club_person.php?id=' . (int) $b['id'];
    }
    return '/admin/player_edit.php?id=' . (int) $b['id'];
}
$groups = ['Players' => [], 'Coaches' => [], 'Committee' => [], 'Other' => []];
foreach ($allRows as $row) {
    $groups[birthdayGroup($row)][] = $row;
}
?>
<div>
    <div class="mb-3"><a href="/admin/index.php"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to dashboard</a></div>
    <div class="card shadow-sm border-0 hub-panel">
        <div class="card-body p-3">
            <?php if ($allRows === []): ?>
                <div class="alert alert-light border mb-0">No dates of birth have been recorded yet.</div>
            <?php else: ?>
            <?php $roles = array_values(array_unique(array_column($allRows, 'role_label'))); sort($roles, SORT_NATURAL | SORT_FLAG_CASE); ?>
            <?= csrf_field() ?>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6">
                    <input type="search" id="birthdaysSearch" class="form-control" placeholder="Search name, role, date..." aria-label="Search birthdays">
                </div>
                <div class="col-8 col-md-4">
                    <select id="birthdaysRole" class="form-select" aria-label="Filter by role">
                        <option value="">All roles</option>
                        <?php foreach ($roles as $role): ?><option value="<?= h(strtolower($role)) ?>"><?= h($role) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 col-md-2 d-flex align-items-center text-muted small" id="birthdaysCount" aria-live="polite"></div>
            </div>
            <p class="small text-muted mb-2"><span class="badge text-bg-danger">Missing DOB</span> = no date of birth recorded yet. <span class="badge text-bg-warning">Check</span> = not yet confirmed and the age looks wrong (under 10). Tick the box once you know the date of birth is correct. Changing a date of birth un-ticks it.</p>
            <?php foreach ($groups as $groupName => $groupRows): if ($groupRows === []) continue; ?>
            <section class="birthdays-group mb-4">
            <h2 class="h5 mb-2"><?= h($groupName) ?> <span class="text-muted small fw-normal birthdays-group-count"></span></h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 birthdays-table">
                    <thead>
                        <tr>
                            <th class="text-center" data-sort="check" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0" title="Confirmed correct">Verified <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="" data-sort="text" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Name <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="" data-sort="text" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Role <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="" data-sort="num" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Date of birth <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="text-end" data-sort="num" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Current age <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="" data-sort="num" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Next birthday <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                            <th class="text-end" data-sort="num" role="columnheader" aria-sort="none" style="cursor:pointer;white-space:nowrap" tabindex="0">Days until <i class="fa-solid fa-sort small text-muted" aria-hidden="true"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupRows as $b): ?>
                        <?php
                        $isMissing = !empty($b['missing']);
                        $age = (int) $b['age_turning'] - 1 + ((int) $b['days_until'] === 0 ? 1 : 0);
                        $source = birthdaySource($b);
                        $isVerified = !$isMissing && isset($verifiedDobs[$source . ':' . (int) $b['id']]);
                        $needsCheck = !$isMissing && !$isVerified && $age < 10;
                        ?>
                        <tr style="position:relative" class="<?= $isMissing ? 'table-danger' : ($isVerified ? 'table-success' : ($needsCheck ? 'table-warning' : '')) ?>" data-source="<?= $source ?>" data-id="<?= (int) $b['id'] ?>" data-role="<?= h(strtolower($b['role_label'])) ?>">
                            <td class="text-center" style="position:relative;z-index:2" data-order="<?= $isVerified ? 1 : 0 ?>">
                                <?php if (!$isMissing): ?><input type="checkbox" class="form-check-input dob-verify" <?= $isVerified ? 'checked' : '' ?> aria-label="DOB confirmed correct for <?= h($b['name']) ?>"><?php endif; ?>
                            </td>
                            <td><a class="fw-semibold text-decoration-none stretched-link" href="<?= h(birthdayEditHref($b)) ?>"><?= h($b['name']) ?></a></td>
                            <td><?= h($b['role_label']) ?></td>
                            <td data-order="<?= $isMissing ? 0 : h(str_replace('-', '', $b['date_of_birth'])) ?>"><?= $isMissing ? '<span class="badge text-bg-danger">Missing DOB</span>' : h(date('d M Y', strtotime($b['date_of_birth']))) ?></td>
                            <td class="text-end" data-order="<?= $isMissing ? -1 : $age ?>"><?= $isMissing ? '-' : $age ?><?= $needsCheck ? ' <span class="badge text-bg-warning">Check</span>' : '' ?></td>
                            <td data-order="<?= $isMissing ? 0 : h(str_replace('-', '', $b['next_birthday'])) ?>"><?php if ($isMissing): ?>-<?php else: ?><?= h(date('d M Y', strtotime($b['next_birthday']))) ?> <small class="text-muted">(turns <?= (int) $b['age_turning'] ?>)</small><?php endif; ?></td>
                            <td class="text-end" data-order="<?= $isMissing ? -1 : (int) $b['days_until'] ?>"><?= $isMissing ? '-' : ((int) $b['days_until'] === 0 ? '🎉 Today' : (int) $b['days_until']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </section>
            <?php endforeach; ?>
            <p class="text-muted small mt-3 mb-0"><?= count($birthdays) ?> with a recorded date of birth, <?= count($missingDobs) ?> missing.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var tables = Array.prototype.slice.call(document.querySelectorAll('.birthdays-table'));
    if (!tables.length) return;
    var search = document.getElementById('birthdaysSearch'), role = document.getElementById('birthdaysRole'), count = document.getElementById('birthdaysCount');
    var total = document.querySelectorAll('.birthdays-table tbody tr').length;
    function filter() {
        var q = search.value.trim().toLowerCase(), r = role.value, n = 0;
        tables.forEach(function (table) {
            var shown = 0, rows = table.tBodies[0].rows;
            Array.prototype.forEach.call(rows, function (row) {
                var show = (!r || row.dataset.role === r) && (!q || row.textContent.toLowerCase().indexOf(q) !== -1);
                row.hidden = !show;
                if (show) shown++;
            });
            var section = table.closest('.birthdays-group');
            section.hidden = shown === 0;
            section.querySelector('.birthdays-group-count').textContent = '(' + shown + ')';
            n += shown;
        });
        count.textContent = n + ' of ' + total;
    }
    function sortBy(table, th) {
        var body = table.tBodies[0], rows = Array.prototype.slice.call(body.rows);
        var idx = th.cellIndex, num = th.dataset.sort === 'num';
        var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
        Array.prototype.forEach.call(table.tHead.rows[0].cells, function (c) {
            c.setAttribute('aria-sort', 'none');
            c.querySelector('i').className = 'fa-solid fa-sort small text-muted';
        });
        th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
        th.querySelector('i').className = 'fa-solid fa-sort-' + (dir === 1 ? 'up' : 'down') + ' small';
        function val(row) {
            var c = row.cells[idx], v = c.dataset.order !== undefined ? c.dataset.order : c.textContent.trim();
            return num ? parseFloat(v) : v.toLowerCase();
        }
        rows.sort(function (a, b) {
            var x = val(a), y = val(b);
            return (x < y ? -1 : x > y ? 1 : 0) * dir;
        });
        rows.forEach(function (row) { body.appendChild(row); });
    }
    var csrf = document.querySelector('input[name=csrf_token]').value;
    tables.forEach(function (table) {
        Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th) {
            th.addEventListener('click', function () { sortBy(table, th); });
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortBy(table, th); } });
        });
        table.tBodies[0].addEventListener('change', function (e) {
            var box = e.target;
            if (!box.classList.contains('dob-verify')) return;
            var row = box.closest('tr'), fd = new URLSearchParams();
            fd.set('csrf_token', csrf); fd.set('source', row.dataset.source); fd.set('id', row.dataset.id); fd.set('verified', box.checked ? '1' : '0');
            box.disabled = true;
            fetch('/admin/club_birthdays_verify.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                .then(function () {
                    var ageCell = row.cells[4], suspicious = ageCell.querySelector('.badge') !== null || parseInt(ageCell.dataset.order, 10) < 10;
                    row.cells[0].dataset.order = box.checked ? 1 : 0;
                    row.classList.toggle('table-success', box.checked);
                    row.classList.toggle('table-warning', !box.checked && suspicious);
                    var badge = ageCell.querySelector('.badge');
                    if (box.checked && badge) badge.remove();
                    if (!box.checked && suspicious && !badge) ageCell.insertAdjacentHTML('beforeend', ' <span class="badge text-bg-warning">Check</span>');
                })
                .catch(function () { box.checked = !box.checked; alert('Could not save - please reload and try again.'); })
                .then(function () { box.disabled = false; });
        });
    });
    search.addEventListener('input', filter);
    role.addEventListener('change', filter);
    filter();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
