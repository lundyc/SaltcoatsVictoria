<?php
declare(strict_types=1);

/*
 * Access snapshot — records every person's EFFECTIVE Hub access, and where it
 * comes from, using the same rules hub_auth_has_capability() applies today.
 *
 *   php admin/database/access_snapshot.php snapshot <label> [--core]   write /root/backups/access-snapshot-<label>.json
 *                                       (--core computes via lib/access.php instead of the legacy rules)
 *   php admin/database/access_snapshot.php compare <a> <b>    diff two snapshots (exit 1 if anything differs)
 *
 * Used by the access-control refactor: snapshot before, snapshot after each
 * phase, and compare — effective access must not change for anyone unless a
 * change was intended. Reads only; writes nothing to the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SNAPSHOT_DIR = '/root/backups';

function snapshot_path(string $label): string
{
    return SNAPSHOT_DIR . '/access-snapshot-' . preg_replace('/[^A-Za-z0-9_.-]/', '', $label) . '.json';
}

$command = $argv[1] ?? '';

if ($command === 'compare') {
    $a = json_decode((string) file_get_contents(snapshot_path($argv[2] ?? '')), true);
    $b = json_decode((string) file_get_contents(snapshot_path($argv[3] ?? '')), true);
    if (!is_array($a) || !is_array($b)) {
        fwrite(STDERR, "Could not read both snapshots.\n");
        exit(2);
    }
    $differences = 0;
    foreach (array_unique(array_merge(array_keys($a['people']), array_keys($b['people']))) as $key) {
        $pa = $a['people'][$key] ?? null;
        $pb = $b['people'][$key] ?? null;
        $ea = $pa['effective'] ?? null;
        $eb = $pb['effective'] ?? null;
        if ($ea !== $eb) {
            $differences++;
            $name = ($pa ?? $pb)['name'] ?? $key;
            echo "DIFF {$name} (person {$key})\n";
            echo '  before: ' . json_encode($ea) . "\n";
            echo '  after : ' . json_encode($eb) . "\n";
        }
    }
    echo $differences === 0 ? "IDENTICAL — effective access unchanged for every person.\n" : "{$differences} person(s) differ.\n";
    exit($differences === 0 ? 0 : 1);
}

if ($command !== 'snapshot') {
    fwrite(STDERR, "Usage: access_snapshot.php snapshot <label> | compare <a> <b>\n");
    exit(2);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../account_auth.php';

$capabilitySlugs = $pdo->query('SELECT slug FROM capabilities ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN);
sort($capabilitySlugs);

// Everyone who could hold access: any account, any assigned access role,
// any current position, and any legacy season-ticket-holder with a staff role.
$personIds = array_map('intval', $pdo->query(
    'SELECT person_id FROM accounts
     UNION SELECT person_id FROM person_access_templates
     UNION SELECT person_id FROM person_club_roles'
)->fetchAll(PDO::FETCH_COLUMN));
sort($personIds);

$developerEmail = defined('DEVELOPER_EMAIL') ? strtolower(trim((string) DEVELOPER_EMAIL)) : '';
$sponsorEditorEmail = defined('SPONSORSHIP_EDITOR_EMAIL') ? strtolower(trim((string) SPONSORSHIP_EDITOR_EMAIL)) : '';

$people = [];
foreach ($personIds as $personId) {
    $person = $pdo->prepare('SELECT id, display_name, email, is_active FROM people WHERE id = :id');
    $person->execute([':id' => $personId]);
    $person = $person->fetch();
    if (!$person) {
        continue;
    }

    $accountStmt = $pdo->prepare('SELECT id, email, is_active FROM accounts WHERE person_id = :id LIMIT 1');
    $accountStmt->execute([':id' => $personId]);
    $account = $accountStmt->fetch() ?: null;

    $primaryRole = $account ? accountPrimaryRole($pdo, (int) $account['id']) : null;
    $canLogIn = $account !== null
        && (int) $account['is_active'] === 1
        && (int) $person['is_active'] === 1
        && in_array($primaryRole, ACCOUNT_STAFF_ROLES, true);

    $sources = [];   // capability => list of sources
    $isAdmin = $primaryRole === ACCOUNT_ROLE_ADMIN;
    $bypass = false;

    $accessRoles = getPersonAccessRoles($pdo, $personId);
    foreach ($accessRoles as $role) {
        if ((int) ($role['bypass_all'] ?? 0) === 1) {
            $bypass = true;
        }
        foreach (getAccessRoleCapabilitySlugs($pdo, (int) $role['id']) as $slug) {
            $sources[$slug][] = 'access_role:' . $role['slug'];
        }
    }

    $positions = getCurrentPersonPositions($pdo, $personId);
    foreach ($positions as $position) {
        foreach (hub_position_capabilities($pdo, $position) as $slug) {
            $sources[$slug][] = 'position:' . $position['name'];
        }
    }

    $effective = [];
    if ($canLogIn) {
        if (in_array('--core', $argv, true)) {
            // Ask the real access core (lib/access.php) instead of replaying the old rules.
            $effective = access_effective($pdo, $personId, false)['capabilities'];
        } else {
            $effective = ($isAdmin || $bypass) ? $capabilitySlugs : array_keys($sources);
        }
        sort($effective);
    }

    $email = strtolower(trim((string) ($account['email'] ?? $person['email'] ?? '')));
    foreach ($sources as &$list) {
        sort($list);
        $list = array_values(array_unique($list));
    }
    unset($list);
    ksort($sources);

    $people[(string) $personId] = [
        'name' => $person['display_name'],
        'can_log_in' => $canLogIn,
        'account_role' => $primaryRole,
        'is_admin' => $isAdmin,
        'bypass_all' => $bypass,
        'access_roles' => array_column($accessRoles, 'slug'),
        'current_positions' => array_column($positions, 'name'),
        'sources' => $sources,
        // The comparison key: what the person can actually do, nothing else.
        'effective' => [
            'can_log_in' => $canLogIn,
            'capabilities' => $effective,
            'developer' => $canLogIn && $developerEmail !== '' && $email === $developerEmail,
            'sponsorship_editor' => $canLogIn && $sponsorEditorEmail !== '' && $email === $sponsorEditorEmail,
        ],
    ];
}

$out = [
    'taken_at' => date('c'),
    'capabilities' => $capabilitySlugs,
    'people' => $people,
];
$path = snapshot_path($argv[2] ?? 'unnamed');
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($path, 0600);

echo "Snapshot written: {$path}\n\n";
printf("%-22s %-6s %-9s %s\n", 'PERSON', 'LOGIN', 'CAPS', 'SOURCES');
foreach ($people as $p) {
    printf("%-22s %-6s %-9s %s\n", $p['name'], $p['can_log_in'] ? 'yes' : 'no',
        $p['is_admin'] || $p['bypass_all'] ? 'ALL' : (string) count($p['effective']['capabilities']),
        $p['is_admin'] ? 'admin account' : (implode(', ', array_merge($p['access_roles'], $p['current_positions'])) ?: '-'));
}
