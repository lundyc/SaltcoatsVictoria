<?php
declare(strict_types=1);

// Standalone page (no header.php/footer.php site chrome) so it matches
// login.php's look. Re-does the slice of header.php's bootstrap this page
// actually needs: the already-authenticated redirect.
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/functions.php';

if (member_auth_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') {
            $error = 'Please choose a password.';
        } else {
            $result = member_account_register($pdo, [
                'name' => (string) ($_POST['name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'password' => $password,
                'marketing_opt_in' => isset($_POST['marketing_opt_in']),
            ]);
            if ($result['ok']) {
                header('Location: index.php?welcome=1');
                exit;
            }
            $error = (string) ($result['error'] ?? 'Something went wrong. Please try again.');
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account - <?= h(APP_NAME) ?></title>
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
        <h1 class="h3">Create your account</h1>
        <p class="login-card__intro">Follow fixtures and results. Season ticket holders get access to the league table, announcements, sponsorship and more once their ticket is on file.</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" id="registerError" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
            <div class="mb-3">
                <label class="form-label" for="regName">Name</label>
                <input type="text" id="regName" name="name" class="form-control" value="<?= h((string) ($_POST['name'] ?? '')) ?>" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="regEmail">Email</label>
                <input type="email" id="regEmail" name="email" class="form-control" value="<?= h((string) ($_POST['email'] ?? '')) ?>" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="regPhone">Phone (optional)</label>
                <input type="text" id="regPhone" name="phone" class="form-control" value="<?= h((string) ($_POST['phone'] ?? '')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="regPassword">Password</label>
                <div class="login-password">
                    <input type="password" id="regPassword" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
                    <button type="button" class="login-password__toggle" id="passwordToggle" aria-controls="regPassword" aria-pressed="false">Show</button>
                </div>
                <div class="login-caps-lock" id="capsLockNotice" role="status" hidden>Caps Lock is on.</div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="regMarketing" name="marketing_opt_in" value="1" checked>
                <label class="form-check-label small" for="regMarketing">Keep me updated by email</label>
            </div>
            <button type="submit" class="btn btn-login">Create account</button>
            <div class="text-center mt-3">
                <a href="login.php">Already have an account? Log in</a>
            </div>
            <div class="text-center mt-3 small">
                <a href="matches.php">Browse fixtures, results &amp; the league table</a>
            </div>
        </form>
    </div>
    <script>
        (function () {
            var password = document.getElementById('regPassword');
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
