<?php
declare(strict_types=1);

// Landing page for the one-time welcome link an admin sends to an existing account:
// set a password OR continue with Google. Either choice uses up the link.
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_shell.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/member_google.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$account = hub_auth_rate_limit('welcome:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'x'), 60, 900)
    ? member_welcome_account_by_token($pdo, $token)
    : null;
$error = '';

if ($account && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $error = 'Passwords do not match.';
        } else {
            $result = resetAccountPasswordByToken($pdo, $token, $password);
            if ($result['ok']) {
                member_auth_login_account((int) $account['id'], (int) $account['person_id'], (int) ($account['old_holder_id'] ?? 0));
                header('Location: index.php');
                exit;
            }
            $error = $result['message'];
        }
    }
}

$googleUrl = ($account && member_google_configured()) ? 'google_start.php?token=' . rawurlencode($token) : '';
member_auth_page_open($account ? 'Welcome' : 'Link expired');
?>
<div class="login-card">
    <?php if (!$account): ?>
        <h1 class="h3">This link has expired</h1>
        <p class="login-card__intro">Welcome links can only be used once and expire after 7 days. If you've already set up your sign-in, just log in. Otherwise, ask the club for a new link.</p>
        <a class="btn btn-login" href="login.php">Go to log in</a>
    <?php else: ?>
        <h1 class="h3">Welcome, <?= h((string) $account['display_name']) ?></h1>
        <p class="login-card__intro">Choose how you'd like to sign in to your Vics account.</p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>

        <?php if ($googleUrl !== ''): ?>
            <a class="btn-google" href="<?= h($googleUrl) ?>"><?= member_google_logo_svg() ?>Continue with Google</a>
            <div class="login-or"><span>or set a password</span></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div class="mb-3">
                <label class="form-label" for="newPassword">Choose a password</label>
                <input type="password" class="form-control" id="newPassword" name="password" minlength="8" autocomplete="new-password" required>
                <div class="form-text">At least 8 characters.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="confirmPassword">Confirm password</label>
                <input type="password" class="form-control" id="confirmPassword" name="password_confirm" minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-login">Set password &amp; log in</button>
        </form>
        <p class="login-card__intro" style="margin-top:1rem;font-size:.85rem">This link works once and expires after 7 days.</p>
    <?php endif; ?>
</div>
<?php member_auth_page_close(); ?>
