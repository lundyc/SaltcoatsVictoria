<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (hub_auth_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

// Capture the staff-session CSRF token now, while the default session is the
// active one. The member-login fallback below calls member_auth_start_session()
// which switches $_SESSION to the isolated member cookie, so calling
// hub_auth_csrf_token() again when rendering the form would write the token to
// the wrong session and break the next submit.
$loginCsrfToken = hub_auth_csrf_token();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $error = 'Your session expired. Please reload and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $result = hub_auth_attempt_login($email, $password);
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        // Not a staff account. The same credentials may still be a valid
        // supporter / season-ticket account — route them to the member
        // portal (its own separate session) instead of a misleading error.
        if (member_auth_attempt_login($email, $password)['ok']) {
            header('Location: /members/index.php?hub_login=1');
            exit;
        }
        $error = (string) ($result['error'] ?? 'Invalid email or password.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/login.css">
</head>
<body>
    <div class="login-card">
        <img
            src="Saltcoats Victoria FC -White_Transparent.png"
            alt="Saltcoats Victoria FC"
            class="login-card__logo"
            loading="eager"
        >
        <h1 class="h3"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="login-card__intro">Sign in to manage the club.</p>
        <?php if (isset($_GET['status']) && trim((string) $_GET['status']) !== ''): ?>
            <div class="alert alert-success" role="status"><?= htmlspecialchars(trim((string) $_GET['status']), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" id="loginError" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($loginCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label class="form-label" for="loginIdentifier">Email</label>
                <input type="email" id="loginIdentifier" name="email" class="form-control" value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" <?= $error !== '' ? 'aria-describedby="loginError" aria-invalid="true"' : '' ?> required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="loginPassword">Password</label>
                <div class="login-password">
                    <input type="password" id="loginPassword" name="password" class="form-control" autocomplete="current-password" <?= $error !== '' ? 'aria-describedby="loginError" aria-invalid="true"' : '' ?> required>
                    <button type="button" class="login-password__toggle" id="passwordToggle" aria-controls="loginPassword" aria-pressed="false">Show</button>
                </div>
                <div class="login-caps-lock" id="capsLockNotice" role="status" hidden>Caps Lock is on.</div>
            </div>
            <button type="submit" class="btn btn-login">Sign in</button>
            <div class="text-center mt-3">
                <a href="forgot_password.php">Forgot password?</a>
            </div>
            <div class="text-center mt-3 small">
                Supporter or season ticket holder? <a href="/members/login.php">Sign in here</a>
            </div>
        </form>
    </div>
    <script>
        (function () {
            var password = document.getElementById('loginPassword');
            var toggle = document.getElementById('passwordToggle');
            var capsLock = document.getElementById('capsLockNotice');
            toggle.addEventListener('click', function () {
                var show = password.type === 'password';
                password.type = show ? 'text' : 'password';
                toggle.textContent = show ? 'Hide' : 'Show';
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                password.focus();
            });
            password.addEventListener('keyup', function (event) {
                capsLock.hidden = !event.getModifierState || !event.getModifierState('CapsLock');
            });
        }());
    </script>
</body>
</html>
