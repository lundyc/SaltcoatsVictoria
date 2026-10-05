<?php
declare(strict_types=1);

require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('admin_settings');

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Access review',
    'subtitle' => 'Who has Hub access and why. Read-only; check once a season.',
    'actions' => [
        ['label' => 'Roles & Capabilities', 'href' => '/admin/access_roles.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/access.php';

/**
 * One row per person with an account, a template assignment or an override.
 *
 * @return list<array<string,mixed>>
 */
function access_review_rows(PDO $pdo): array
{
    $today = date('Y-m-d');
    $people = $pdo->query(
        'SELECT p.id, p.display_name, p.is_active,
                a.id AS account_id, a.is_active AS account_active, a.last_login_at,
                (SELECT COUNT(*) FROM account_roles ar JOIN roles r ON r.id = ar.role_id
                  WHERE ar.account_id = a.id AND r.code = \'admin\') AS is_admin_account
         FROM people p
         LEFT JOIN accounts a ON a.person_id = p.id
         WHERE a.id IS NOT NULL
            OR EXISTS (SELECT 1 FROM person_access_templates x WHERE x.person_id = p.id)
            OR EXISTS (SELECT 1 FROM person_access_overrides o WHERE o.person_id = p.id)
         GROUP BY p.id, a.id
         ORDER BY p.display_name, p.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $roleStmt = $pdo->prepare(
        'SELECT cr.name FROM person_club_roles pcr
         JOIN club_roles cr ON cr.id = pcr.club_role_id
         LEFT JOIN seasons s ON s.id = pcr.season_id
         WHERE pcr.person_id = :p
           AND COALESCE(pcr.start_date, s.start_date, DATE(pcr.assigned_at)) <= CURDATE()
           AND (COALESCE(pcr.end_date, s.end_date) IS NULL OR COALESCE(pcr.end_date, s.end_date) >= CURDATE())
         ORDER BY cr.sort_order, cr.name'
    );
    $tplStmt = $pdo->prepare(
        'SELECT t.name, t.bypass_all FROM person_access_templates pat
         JOIN access_templates t ON t.id = pat.template_id
         WHERE pat.person_id = :p ORDER BY t.sort_order, t.name'
    );
    $ovStmt = $pdo->prepare(
        'SELECT c.label, o.effect, o.expires_at, o.note FROM person_access_overrides o
         JOIN capabilities c ON c.id = o.capability_id
         WHERE o.person_id = :p ORDER BY c.label'
    );
    $capLabels = [];
    foreach ($pdo->query('SELECT slug, label FROM capabilities') as $c) {
        $capLabels[$c['slug']] = $c['label'];
    }

    $rows = [];
    foreach ($people as $p) {
        $pid = (int) $p['id'];
        $roleStmt->execute([':p' => $pid]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
        $tplStmt->execute([':p' => $pid]);
        $templates = $tplStmt->fetchAll(PDO::FETCH_ASSOC);
        $ovStmt->execute([':p' => $pid]);
        $overrides = $ovStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($overrides as &$o) {
            $o['expired'] = $o['expires_at'] !== null && substr((string) $o['expires_at'], 0, 10) < $today;
        }
        unset($o);

        $exp = access_explain($pdo, $pid, false);
        $hasAccount = $p['account_id'] !== null;
        $accountOn = $hasAccount && (int) $p['account_active'] === 1;
        $personOn = (int) $p['is_active'] === 1;
        if (!$hasAccount) {
            $login = 'No account';
        } elseif (!$accountOn) {
            $login = 'Account off';
        } elseif (!$personOn) {
            $login = 'Person inactive';
        } else {
            $login = 'Active';
        }
        $lastLogin = $p['last_login_at'] ? (string) $p['last_login_at'] : null;
        $hasAccess = $exp['bypass'] || $exp['capabilities'] !== [] || $templates !== [] || $overrides !== [];
        $hasTplOrOv = $templates !== [] || $overrides !== [];
        $isAdminTpl = false;
        foreach ($templates as $t) {
            if ((int) $t['bypass_all'] === 1) {
                $isAdminTpl = true;
            }
        }
        $isAdmin = $exp['bypass'];

        $flags = [];
        if ($hasTplOrOv && $login !== 'Active') {
            $flags[] = ['warn', 'Access but no active login'];
        }
        if ($login === 'Active' && !$hasAccess) {
            $flags[] = ['info', 'Login, no access'];
        }
        if ($hasAccount && $accountOn && !$personOn) {
            $flags[] = ['danger', 'Person inactive, account still on'];
        }
        foreach ($overrides as $o) {
            if ($o['expired']) {
                $flags[] = ['warn', 'Expired override stored'];
                break;
            }
        }
        if ($isAdminTpl && $roles === []) {
            $stale = $lastLogin === null || strtotime($lastLogin) < strtotime('-180 days');
            if ($stale) {
                $flags[] = ['review', 'Administrator: no club role, no login 180d+'];
            }
        }

        $rows[] = [
            'id' => $pid,
            'name' => (string) $p['display_name'],
            'roles' => $roles,
            'templates' => $templates,
            'overrides' => $overrides,
            'bypass' => $isAdmin,
            'caps' => $exp['capabilities'],
            'sources' => $exp['sources'],
            'cap_labels' => $capLabels,
            'login' => $login,
            'last_login' => $lastLogin,
            'flags' => $flags,
            'attention' => count(array_filter($flags, static fn ($f) => $f[0] !== 'info')),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return [$a['attention'] > 0 ? 0 : 1, $a['bypass'] ? 0 : 1, strtolower($a['name'])]
            <=> [$b['attention'] > 0 ? 0 : 1, $b['bypass'] ? 0 : 1, strtolower($b['name'])];
    });
    return $rows;
}

$rows = access_review_rows($pdo);
$withAccess = 0;
$admins = 0;
$attention = 0;
foreach ($rows as $r) {
    if ($r['bypass'] || $r['caps'] !== [] || $r['templates'] !== [] || $r['overrides'] !== []) {
        $withAccess++;
    }
    if ($r['bypass']) {
        $admins++;
    }
    if ($r['attention'] > 0) {
        $attention++;
    }
}
$badgeClass = ['danger' => 'text-bg-danger', 'warn' => 'text-bg-warning', 'review' => 'text-bg-warning', 'info' => 'text-bg-info'];
?>

<div class="access-review-page">
    <div class="row g-3 mb-3">
        <div class="col-4"><div class="card shadow-sm border-0"><div class="card-body py-2"><div class="text-muted small">People with access</div><div class="fs-4 fw-semibold"><?= $withAccess ?></div></div></div></div>
        <div class="col-4"><div class="card shadow-sm border-0"><div class="card-body py-2"><div class="text-muted small">Administrators</div><div class="fs-4 fw-semibold"><?= $admins ?></div></div></div></div>
        <div class="col-4"><div class="card shadow-sm border-0"><div class="card-body py-2"><div class="text-muted small">Need attention</div><div class="fs-4 fw-semibold <?= $attention > 0 ? 'text-danger' : '' ?>"><?= $attention ?></div></div></div></div>
    </div>

    <div class="card shadow-sm border-0 hub-table-card">
        <div class="table-responsive">
            <table class="table table-striped hub-data-table align-middle mb-0">
                <thead>
                    <tr><th>Name</th><th>Club roles</th><th>Access templates</th><th>Extras / removals</th><th>Effective access</th><th>Login</th><th>Flags</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/admin/club_person.php?id=<?= (int) $r['id'] ?>"><?= h($r['name']) ?></a></td>
                        <td><?= $r['roles'] === [] ? '<span class="text-muted">-</span>' : h(implode(', ', $r['roles'])) ?></td>
                        <td><?php if ($r['templates'] === []): ?><span class="text-muted">-</span><?php else: foreach ($r['templates'] as $t): ?>
                            <div><?= h((string) $t['name']) ?></div>
                        <?php endforeach; endif; ?></td>
                        <td><?php if ($r['overrides'] === []): ?><span class="text-muted">-</span><?php else: foreach ($r['overrides'] as $o): ?>
                            <div class="small<?= $o['expired'] ? ' text-muted text-decoration-line-through' : '' ?>">
                                <span class="badge <?= $o['effect'] === 'deny' ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $o['effect'] === 'deny' ? 'Deny' : 'Grant' ?></span>
                                <?= h((string) $o['label']) ?>
                                <?php if ($o['expires_at'] !== null): ?>
                                    <span class="<?= $o['expired'] ? 'text-danger' : 'text-muted' ?>">(<?= $o['expired'] ? 'expired ' : 'until ' ?><?= h(substr((string) $o['expires_at'], 0, 10)) ?>)</span>
                                <?php endif; ?>
                                <?php if (trim((string) ($o['note'] ?? '')) !== ''): ?><span class="text-muted">- <?= h((string) $o['note']) ?></span><?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?></td>
                        <td>
                            <?php if ($r['bypass']): ?>
                                <span class="badge text-bg-dark">Administrator</span> <span class="small text-muted">all access</span>
                            <?php elseif ($r['caps'] === []): ?>
                                <span class="text-muted">None</span>
                            <?php else: ?>
                                <details>
                                    <summary><?= count($r['caps']) ?> capabilit<?= count($r['caps']) === 1 ? 'y' : 'ies' ?></summary>
                                    <ul class="small mb-0 ps-3">
                                    <?php foreach ($r['caps'] as $slug): ?>
                                        <li><?= h((string) ($r['cap_labels'][$slug] ?? $slug)) ?> <span class="text-muted">(<?= h(implode(', ', $r['sources'][$slug] ?? [])) ?>)</span></li>
                                    <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($r['login']) ?>
                            <div class="small text-muted"><?= $r['last_login'] ? h(substr($r['last_login'], 0, 10)) : 'never' ?></div>
                        </td>
                        <td><?php foreach ($r['flags'] as $f): ?>
                            <div><span class="badge <?= h($badgeClass[$f[0]]) ?>"><?= h($f[1]) ?></span></div>
                        <?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No people with access.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
