<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Member sign-in links',
    'subtitle' => 'Send existing members a one-time link to set a password or sign in with Google. Links work once and expire after 7 days.',
    'actions' => [['label' => 'Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm']],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/member_google.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to send member sign-in links.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

ensureMemberGoogleSchema($pdo);
$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$scope = in_array($_GET['scope'] ?? '', ['all', 'everyone'], true) ? (string) $_GET['scope'] : 'holders';
$onlyHolders = $scope === 'holders';
$everyone = $scope === 'everyone'; // include people who already have a password / Google (e.g. to link Google)

function member_invite_email_body(string $name, string $url): string
{
    return implode("\n", [
        'Hi ' . $name . ',',
        '',
        'Your Saltcoats Victoria FC members account is ready. Use the link below to choose how you sign in - either set a password, or continue with your Google account:',
        '',
        $url,
        '',
        'This link can only be used once and expires in 7 days. If it has expired, just ask the club for a new one.',
        '',
        'Saltcoats Victoria FC',
    ]);
}

$notice = '';
$errors = [];
$shownLinks = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $ids = $action === 'email_selected'
            ? array_values(array_unique(array_map('strval', (array) ($_POST['account_ids'] ?? []))))
            : [(string) ($_POST['account_id'] ?? '')];
        $sent = 0;
        $failed = [];
        foreach ($ids as $key) {
            if (!preg_match('/^([ap])(\d+)$/', $key, $km) || (int) $km[2] <= 0) {
                continue;
            }
            try {
                if ($km[1] === 'p') {
                    // Season ticket holder with no login account yet: create one from their record.
                    $person = getPerson($pdo, (int) $km[2]);
                    if (!$person || trim((string) ($person['email'] ?? '')) === '') {
                        $failed[] = 'person #' . (int) $km[2] . ' (no email)';
                        continue;
                    }
                    $accountId = getAccountByPersonId($pdo, (int) $km[2])['id'] ?? createAccountForPerson($pdo, (int) $km[2], (string) $person['email'], true);
                    $accountId = (int) $accountId;
                } else {
                    $accountId = (int) $km[2];
                }
                $account = getAccount($pdo, $accountId);
                if (!$account || (int) ($account['is_active'] ?? 0) !== 1) {
                    continue;
                }
                $url = member_create_welcome_link($pdo, $accountId);
                if ($action === 'show_link') {
                    $shownLinks[] = [(string) $account['display_name'], $url];
                    identityAuditLog($pdo, 'member_welcome_link_created', 'Welcome link created for account #' . $accountId);
                    continue;
                }
                $email = trim((string) ($account['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failed[] = (string) $account['display_name'] . ' (no valid email)';
                    continue;
                }
                if (hub_send_mail($email, 'Set up your Saltcoats Victoria FC members login', member_invite_email_body((string) $account['display_name'], $url), false)) {
                    member_mark_welcome_sent($pdo, $accountId);
                    identityAuditLog($pdo, 'member_welcome_link_sent', 'Welcome link emailed for account #' . $accountId);
                    $sent++;
                } else {
                    $failed[] = (string) $account['display_name'] . ' (mail failed)';
                    $shownLinks[] = [(string) $account['display_name'] . ' - email failed, copy this link', $url];
                }
            } catch (Throwable $e) {
                $failed[] = (string) $account['display_name'] . ' (' . $e->getMessage() . ')';
            }
        }
        if ($sent > 0) {
            $notice = $sent . ' link' . ($sent === 1 ? '' : 's') . ' emailed.';
        }
        if ($failed) {
            $errors[] = 'Not sent: ' . implode('; ', $failed) . '.';
        }
    }
}

$ticketJoin = $seasonId > 0
    ? "EXISTS (SELECT 1 FROM entitlements e JOIN season_passes sp ON sp.entitlement_id = e.id
         WHERE e.person_id = a.person_id AND sp.season_id = :season_id
           AND e.status IN ('active','pending') AND sp.status IN ('active','pending'))"
    : '0';
$signInFilter = $everyone ? '' : "AND (a.password_hash IS NULL OR a.password_hash = '')
          AND NOT EXISTS (SELECT 1 FROM account_identities i WHERE i.account_id = a.id AND i.provider = 'google')";
$sql = "SELECT a.id, a.email, p.display_name, w.last_sent_at, w.send_count, w.last_created_at,
               (a.password_hash IS NOT NULL AND a.password_hash <> '') AS has_pw,
               EXISTS (SELECT 1 FROM account_identities i2 WHERE i2.account_id = a.id AND i2.provider = 'google') AS has_google,
               ($ticketJoin) AS has_ticket
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN account_welcome_links w ON w.account_id = a.id
        WHERE a.is_active = 1 AND p.is_active = 1
          {$signInFilter}
        ORDER BY p.display_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($seasonId > 0 ? [':season_id' => $seasonId] : []);
$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $r['key'] = 'a' . $r['id'];
    $r['new_account'] = false;
    $rows[] = $r;
}
// Season ticket holders who have no login account at all yet (an account is created when you send the link).
if ($seasonId > 0) {
    $pstmt = $pdo->prepare("SELECT p.id, p.email, p.display_name
        FROM people p
        WHERE p.is_active = 1 AND p.email IS NOT NULL AND p.email <> ''
          AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.person_id = p.id)
          AND EXISTS (SELECT 1 FROM entitlements e JOIN season_passes sp ON sp.entitlement_id = e.id
                      WHERE e.person_id = p.id AND sp.season_id = :season_id
                        AND e.status IN ('active','pending') AND sp.status IN ('active','pending'))
        ORDER BY p.display_name");
    $pstmt->execute([':season_id' => $seasonId]);
    foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = ['key' => 'p' . $r['id'], 'new_account' => true, 'email' => $r['email'], 'display_name' => $r['display_name'],
                   'last_sent_at' => null, 'send_count' => 0, 'last_created_at' => null, 'has_ticket' => 1, 'has_pw' => 0, 'has_google' => 0];
    }
}
if ($onlyHolders) {
    $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['has_ticket'] === 1));
}
usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a['display_name'], (string) $b['display_name']));
$googleOn = member_google_configured();
?>
<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>
<?php foreach ($shownLinks as [$who, $link]): ?>
    <div class="alert alert-info"><strong><?= h($who) ?>:</strong> <input type="text" class="form-control form-control-sm mt-1" readonly value="<?= h($link) ?>" onclick="this.select()"></div>
<?php endforeach; ?>
<?php if (!$googleOn): ?>
    <div class="alert alert-warning">Google sign-in isn't switched on yet (no Google client ID/secret in <code>admin/.env</code>), so welcome links currently offer a password only.</div>
<?php endif; ?>
<div class="alert alert-light border small">Some recipients (Outlook/Hotmail addresses in particular) may reject mail from this server. If an email fails, the link is shown above so you can send it another way. Creating a new link for someone cancels their previous one.</div>

<div class="card shadow-sm border-0 mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><strong><?= count($rows) ?></strong> member<?= count($rows) === 1 ? '' : 's' ?><?= $everyone ? ' (every active account, including those who already sign in)' : ' with no password and no Google sign-in' . ($onlyHolders ? ' (current season ticket holders)' : '') ?>.</div>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-<?= $scope === 'holders' ? 'dark' : 'outline-dark' ?>" href="?scope=holders">Season ticket holders</a>
        <a class="btn btn-<?= $scope === 'all' ? 'dark' : 'outline-dark' ?>" href="?scope=all">All without a sign-in</a>
        <a class="btn btn-<?= $scope === 'everyone' ? 'dark' : 'outline-dark' ?>" href="?scope=everyone">Everyone</a>
    </div>
</div></div>

<form method="post">
    <?= csrf_field() ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th style="width:2rem"><input type="checkbox" id="selAll" aria-label="Select all"></th>
                    <th>Name</th><th>Email</th><th>Ticket</th><th>Sign-in</th><th>Last link</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nobody to invite &mdash; everyone here already has a password or Google sign-in.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="account_ids[]" value="<?= h((string) $r['key']) ?>" class="rowSel" <?= trim((string) $r['email']) === '' ? 'disabled' : '' ?>></td>
                        <td><?= h((string) $r['display_name']) ?><?= !empty($r['new_account']) ? ' <span class="badge text-bg-warning">No login yet</span>' : '' ?></td>
                        <td class="small"><?= $r['email'] !== null && $r['email'] !== '' ? h((string) $r['email']) : '<span class="text-muted">none</span>' ?></td>
                        <td><?= (int) $r['has_ticket'] === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
                        <td class="small"><?= (int) $r['has_google'] === 1 ? '<span class="badge text-bg-info">Google</span> ' : '' ?><?= (int) $r['has_pw'] === 1 ? '<span class="badge text-bg-light border">Password</span>' : ((int) $r['has_google'] === 1 ? '' : '<span class="text-muted">None</span>') ?></td>
                        <td class="small text-muted"><?= $r['last_sent_at'] ? 'Emailed ' . h(date('j M Y', strtotime((string) $r['last_sent_at']))) . ((int) $r['send_count'] > 1 ? ' (' . (int) $r['send_count'] . '×)' : '') : ($r['last_created_at'] ? 'Link made ' . h(date('j M Y', strtotime((string) $r['last_created_at']))) : '—') ?></td>
                        <td class="text-end text-nowrap">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" name="action" value="show_link" formaction="?scope=<?= h($scope) ?>" onclick="this.form.account_id.value='<?= h((string) $r['key']) ?>'">Get link</button>
                            <button type="submit" class="btn btn-sm btn-brand" name="action" value="email_link" onclick="this.form.account_id.value='<?= h((string) $r['key']) ?>'" <?= trim((string) $r['email']) === '' ? 'disabled' : '' ?>>Email link</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <input type="hidden" name="account_id" value="">
    <?php if ($rows): ?>
        <div class="mt-3"><button type="submit" class="btn btn-brand" name="action" value="email_selected" onclick="return confirm('Email a sign-in link to every ticked member?');" onmousedown="this.form.account_id.value=''">Email links to selected</button></div>
    <?php endif; ?>
</form>
<script>
document.getElementById('selAll')?.addEventListener('change', function () {
    document.querySelectorAll('.rowSel:not(:disabled)').forEach(function (c) { c.checked = this.checked; }, this);
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
