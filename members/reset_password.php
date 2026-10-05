<?php
require_once __DIR__ . '/header.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = member_auth_reset_password_by_token($pdo, $token, $password);
            if ($result['ok']) {
                header('Location: login.php?reset=1');
                exit;
            }
            $error = $result['message'];
        }
    }
}
?>
<div class="card shadow-sm border-0 mx-auto" style="max-width:420px;">
    <div class="card-body p-4">
        <h1 class="h4 mb-3">Set your password</h1>
        <?php if ($token === ''): ?>
            <div class="alert alert-danger">Missing reset link. Please request a new one.</div>
            <a class="d-block text-center small" href="forgot_password.php">Request a new link</a>
        <?php else: ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <div class="mb-3">
                    <label class="form-label" for="newPassword">New password</label>
                    <input type="password" class="form-control" id="newPassword" name="password" minlength="8" required autofocus>
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="confirmPassword">Confirm password</label>
                    <input type="password" class="form-control" id="confirmPassword" name="password_confirm" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-brand w-100">Set password</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
