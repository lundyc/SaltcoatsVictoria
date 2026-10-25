<?php
declare(strict_types=1);

// Mailboxes — create and manage @saltcoatsvictoria.co.uk email addresses without logging in to Plesk.
// Forwarding addresses (e.g. enquiries@ -> several personal inboxes) and normal mailboxes, plus
// which address receives each website enquiry department. Site owner (developer login) only.

$pageHero = [
    'eyebrow' => 'Admin',
    'title' => 'Mailboxes',
    'subtitle' => 'Club email addresses and who they forward to.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/plesk_mail.php';
require_once __DIR__ . '/lib/enquiries.php';
require_once __DIR__ . '/lib/site_settings.php';

if (!hub_auth_is_developer()) {
    http_response_code(403);
    echo '<div class="alert alert-warning">This page is limited to the site owner.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

$msg = '';
$err = '';
$newPassword = '';
$domain = PLESK_MAIL_DOMAIN;

/** Split a pasted list of emails. @return array{0:list<string>,1:list<string>} [valid, invalid] */
$parseEmails = static function (string $raw): array {
    $ok = [];
    $bad = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $e) {
        if ($e === '') {
            continue;
        }
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $ok[] = $e;
        } else {
            $bad[] = $e;
        }
    }
    return [array_values(array_unique($ok)), $bad];
};
$genPassword = static function (): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 16; $i++) {
        $p .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $p;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $err = 'Your session expired — please try again.';
    } elseif (!plesk_mail_configured()) {
        $err = 'The Plesk connection is not set up yet.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $local = strtolower(trim((string) ($_POST['local'] ?? '')));
        $user = hub_auth_current_user();
        $who = (string) ($user['email'] ?? 'owner');

        if ($action === 'create_forwarder') {
            [$targets, $bad] = $parseEmails((string) ($_POST['targets'] ?? ''));
            if ($bad) {
                $err = 'Not a valid email: ' . implode(', ', $bad);
            } elseif (!$targets) {
                $err = 'Add at least one address to forward to.';
            } else {
                $res = plesk_mail_create_forwarder($local, $targets);
                if ($res['ok']) {
                    $msg = plesk_mail_address($local) . ' created and forwarding to ' . implode(', ', $targets) . '.';
                    auditLog($pdo, 'mailbox_forwarder_created', $who . ' created ' . plesk_mail_address($local) . ' -> ' . implode(', ', $targets));
                } else {
                    $err = $res['stderr'] ?: 'Plesk could not create the address.';
                }
            }
        } elseif ($action === 'create_mailbox' || $action === 'set_password') {
            $newPassword = trim((string) ($_POST['password'] ?? '')) ?: $genPassword();
            if (strlen($newPassword) < 10) {
                $err = 'Passwords need at least 10 characters.';
                $newPassword = '';
            } else {
                $res = $action === 'create_mailbox' ? plesk_mail_create_mailbox($local, $newPassword) : plesk_mail_set_password($local, $newPassword);
                if ($res['ok']) {
                    $msg = $action === 'create_mailbox' ? plesk_mail_address($local) . ' created.' : 'Password changed for ' . plesk_mail_address($local) . '.';
                    auditLog($pdo, $action === 'create_mailbox' ? 'mailbox_created' : 'mailbox_password_changed', $who . ' — ' . plesk_mail_address($local));
                } else {
                    $err = $res['stderr'] ?: 'Plesk could not do that.';
                    $newPassword = '';
                }
            }
        } elseif ($action === 'add_forwards' || $action === 'remove_forward') {
            [$targets, $bad] = $parseEmails((string) ($_POST['targets'] ?? ''));
            if ($bad || !$targets) {
                $err = $bad ? 'Not a valid email: ' . implode(', ', $bad) : 'Enter an address.';
            } else {
                $res = $action === 'add_forwards' ? plesk_mail_update_forwards($local, $targets, []) : plesk_mail_update_forwards($local, [], $targets);
                if ($res['ok']) {
                    $msg = 'Updated ' . plesk_mail_address($local) . '.';
                    auditLog($pdo, 'mailbox_forward_changed', $who . ' ' . $action . ' ' . plesk_mail_address($local) . ': ' . implode(', ', $targets));
                } else {
                    $err = $res['stderr'] ?: 'Plesk could not update that address.';
                }
            }
        } elseif ($action === 'remove') {
            if (strtolower(trim((string) ($_POST['confirm'] ?? ''))) !== $local) {
                $err = 'Type the address name to confirm removal.';
            } else {
                $res = plesk_mail_remove($local);
                if ($res['ok']) {
                    $msg = plesk_mail_address($local) . ' removed.';
                    auditLog($pdo, 'mailbox_removed', $who . ' removed ' . plesk_mail_address($local));
                } else {
                    $err = $res['stderr'] ?: 'Plesk could not remove it.';
                }
            }
        } elseif ($action === 'save_departments') {
            site_settings_ensure_schema($pdo);
            $save = [];
            foreach (['commercial', 'hire', 'media', 'supporters', 'volunteers'] as $dep) {
                $v = trim((string) ($_POST['dept_' . $dep] ?? ''));
                $save[enquiry_setting_key($dep)] = ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : '';
            }
            $general = trim((string) ($_POST['dept_general'] ?? ''));
            if ($general !== '' && filter_var($general, FILTER_VALIDATE_EMAIL)) {
                $save['contact_email'] = $general;
            }
            site_settings_save($pdo, $save);
            auditLog($pdo, 'enquiry_recipients_changed', $who . ' changed where website enquiries are sent');
            $msg = 'Enquiry recipients saved.';
        }
    }
}

$list = [];
$listError = '';
if (plesk_mail_configured()) {
    try {
        $list = plesk_mail_list();
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}
site_settings_ensure_schema($pdo);
$settings = site_settings_all($pdo);
$deps = enquiry_departments();
?>
<div class="container-fluid px-0">
    <?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
    <?php if ($newPassword !== ''): ?>
        <div class="alert alert-warning py-2">Password: <code><?= h($newPassword) ?></code> — copy it now. It is not stored anywhere and cannot be shown again.</div>
    <?php endif; ?>

    <?php if (!plesk_mail_configured()): ?>
        <div class="alert alert-warning">
            <strong>Not connected to Plesk yet.</strong> An API key needs adding to the Hub&rsquo;s <code>.env</code> as <code>PLESK_API_KEY</code>. Until then this page cannot list or change addresses.
        </div>
    <?php elseif ($listError !== ''): ?>
        <div class="alert alert-danger">Could not read the address list: <?= h($listError) ?></div>
    <?php endif; ?>

    <section class="card hub-panel mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">Email addresses on @<?= h($domain) ?></h2>
            <?php if (!$list): ?>
                <p class="text-muted mb-0">No addresses to show.</p>
            <?php else: ?>
            <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                <thead><tr><th>Address</th><th>Type</th><th>Forwards to</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $m): ?>
                    <tr>
                        <td data-label="Address" class="fw-semibold"><?= h($m['address']) ?></td>
                        <td data-label="Type"><span class="badge text-bg-light border"><?= h($m['type']) ?></span><?= $m['protected'] ? ' <span class="small text-muted">used by the Hub</span>' : '' ?></td>
                        <td data-label="Forwards to" class="small">
                            <?php foreach ($m['forwards'] as $f): ?>
                                <form method="post" class="d-inline-flex align-items-center gap-1 me-2">
                                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="remove_forward">
                                    <input type="hidden" name="local" value="<?= h($m['local']) ?>">
                                    <input type="hidden" name="targets" value="<?= h($f) ?>">
                                    <?= h($f) ?>
                                    <?php if (!$m['protected']): ?><button class="btn btn-sm btn-link text-danger p-0" type="submit" title="Stop forwarding to <?= h($f) ?>">&times;</button><?php endif; ?>
                                </form>
                            <?php endforeach; ?>
                            <?php if (!$m['forwards']): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td data-label="Actions" class="text-end">
                            <?php if (!$m['protected']): ?>
                                <details class="d-inline-block text-start">
                                    <summary class="btn btn-sm btn-outline-secondary">Manage</summary>
                                    <div class="border rounded p-2 mt-1 bg-white" style="min-width:290px;position:absolute;right:1rem;z-index:5">
                                        <form method="post" class="mb-2">
                                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="add_forwards">
                                            <input type="hidden" name="local" value="<?= h($m['local']) ?>">
                                            <label class="form-label small mb-1">Add forward-to addresses</label>
                                            <input class="form-control form-control-sm mb-1" name="targets" placeholder="name@example.com, ...">
                                            <button class="btn btn-sm btn-brand" type="submit">Add</button>
                                        </form>
                                        <?php if (str_contains($m['type'], 'mailbox')): ?>
                                        <form method="post" class="mb-2">
                                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="set_password">
                                            <input type="hidden" name="local" value="<?= h($m['local']) ?>">
                                            <label class="form-label small mb-1">New password (blank = generate one)</label>
                                            <input class="form-control form-control-sm mb-1" name="password" autocomplete="new-password">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Change password</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="local" value="<?= h($m['local']) ?>">
                                            <label class="form-label small mb-1 text-danger">Remove — type <strong><?= h($m['local']) ?></strong> to confirm. Stored mail is deleted.</label>
                                            <input class="form-control form-control-sm mb-1" name="confirm" autocomplete="off">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove address</button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <section class="card hub-panel h-100"><div class="card-body">
                <h2 class="h6">New forwarding address</h2>
                <p class="small text-muted">Anything sent to it is copied to each address below. It has no inbox of its own.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_forwarder">
                    <label class="form-label" for="fwl">Address</label>
                    <div class="input-group mb-2"><input class="form-control" id="fwl" name="local" placeholder="enquiries" required><span class="input-group-text">@<?= h($domain) ?></span></div>
                    <label class="form-label" for="fwt">Forward to (separate with commas or new lines)</label>
                    <textarea class="form-control mb-2" id="fwt" name="targets" rows="3" required></textarea>
                    <button class="btn btn-brand" type="submit">Create forwarding address</button>
                </form>
            </div></section>
        </div>
        <div class="col-lg-6">
            <section class="card hub-panel h-100"><div class="card-body">
                <h2 class="h6">New mailbox</h2>
                <p class="small text-muted">A real inbox, read in webmail or an email app. The password is shown once.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_mailbox">
                    <label class="form-label" for="mbl">Address</label>
                    <div class="input-group mb-2"><input class="form-control" id="mbl" name="local" placeholder="treasurer" required><span class="input-group-text">@<?= h($domain) ?></span></div>
                    <label class="form-label" for="mbp">Password (blank = generate one)</label>
                    <input class="form-control mb-2" id="mbp" name="password" autocomplete="new-password">
                    <button class="btn btn-brand" type="submit">Create mailbox</button>
                </form>
                <p class="small text-muted mt-2 mb-0">Webmail: <a href="https://webmail.<?= h($domain) ?>" target="_blank" rel="noopener">webmail.<?= h($domain) ?></a></p>
            </div></section>
        </div>
    </div>

    <section class="card hub-panel">
        <div class="card-body">
            <h2 class="h6">Where website enquiries go</h2>
            <p class="small text-muted">Each type of enquiry is emailed to the address you choose. Blank = the general address.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_departments">
                <datalist id="addrs"><?php foreach ($list as $m): if ($m['protected']) { continue; } ?><option value="<?= h($m['address']) ?>"><?php endforeach; ?></datalist>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">General (contact page and fallback)</label>
                        <input class="form-control" name="dept_general" list="addrs" value="<?= h((string) ($settings['contact_email'] ?? '')) ?>">
                    </div>
                    <?php foreach (['commercial', 'hire', 'media', 'supporters', 'volunteers'] as $dep): ?>
                        <div class="col-md-6">
                            <label class="form-label small"><?= h($deps[$dep]['label']) ?></label>
                            <input class="form-control" name="dept_<?= h($dep) ?>" list="addrs" value="<?= h((string) ($settings[enquiry_setting_key($dep)] ?? '')) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-brand mt-3" type="submit">Save recipients</button>
            </form>
        </div>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
