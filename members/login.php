<?php
declare(strict_types=1);

// Standalone page (no header.php/footer.php site chrome) so it can match
// hub/login.php's look exactly. Re-does the slice of header.php's bootstrap
// this page actually needs: staff-session capture + auto-login, and the
// already-authenticated redirect.
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/functions.php';

// Capture the staff identity (if any) from the default/staff session *before*
// member_auth_* functions switch $_SESSION over to the isolated member
// cookie — the two sessions are deliberately separate, so this is the only
// point where both can be read in the same request.
hub_auth_start_session();
$staffUserForAutoLogin = hub_auth_is_authenticated() ? hub_auth_current_user() : null;

if (!member_auth_is_authenticated() && $staffUserForAutoLogin !== null) {
    member_auth_login_as_staff($pdo, $staffUserForAutoLogin);
}

if (member_auth_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identifier = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!member_auth_verify_csrf_token($csrfToken)) {
        $error = 'Your session expired. Please reload and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $result = member_auth_attempt_login($identifier, $password);
        if ($result['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = (string) ($result['error'] ?? 'Incorrect email or password.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/login.css">
</head>
<body>
    <div class="login-card">
        <img
            src="/admin/Saltcoats Victoria FC -White_Transparent.png"
            alt="Saltcoats Victoria FC"
            class="login-card__logo"
            loading="eager"
        >
        <h1 class="h3">Season Ticket account</h1>
        <p class="login-card__intro">Sign in to manage your Vics account.</p>
        <?php if (isset($_GET['reset'])): ?>
            <div class="alert alert-success" role="status">Password set. Please log in.</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" id="loginError" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
            <div class="mb-3">
                <label class="form-label" for="loginEmail">Email</label>
                <input type="email" id="loginEmail" name="email" class="form-control" value="<?= h((string) ($_POST['email'] ?? '')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" <?= $error !== '' ? 'aria-describedby="loginError" aria-invalid="true"' : '' ?> required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="loginPassword">Password</label>
                <div class="login-password">
                    <input type="password" id="loginPassword" name="password" class="form-control" autocomplete="current-password" <?= $error !== '' ? 'aria-describedby="loginError" aria-invalid="true"' : '' ?> required>
                    <button type="button" class="login-password__toggle" id="passwordToggle" aria-controls="loginPassword" aria-pressed="false">Show</button>
                </div>
                <div class="login-caps-lock" id="capsLockNotice" role="status" hidden>Caps Lock is on.</div>
            </div>
            <button type="submit" class="btn btn-login">Log in</button>
            <div class="text-center mt-3">
                <a href="forgot_password.php">Set up or reset your password</a>
            </div>
            <div class="text-center mt-2">
                <a href="register.php">New here? Create an account</a>
            </div>
            <div class="text-center mt-3 small">
                <a href="matches.php">Browse fixtures, results &amp; the league table</a>
            </div>
            <div class="text-center mt-2 small">
                Club staff? <a href="/admin/login.php">Sign in to the Hub</a>
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
