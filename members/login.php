<?php
declare(strict_types=1);

// Standalone page (no header.php/footer.php site chrome) so it can match
// hub/login.php's look exactly. Re-does the slice of header.php's bootstrap
// this page actually needs: staff-session capture + auto-login, and the
// already-authenticated redirect.
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_shell.php';
require_once __DIR__ . '/../admin/lib/member_google.php';
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
if (!empty($_SESSION['member_flash_error'])) {
    $error = (string) $_SESSION['member_flash_error'];
    unset($_SESSION['member_flash_error']);
}
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
<?php member_auth_page_open('Log in'); ?>
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
            <?php if (member_google_configured()): ?>
                <div class="login-or"><span>or</span></div>
                <a class="btn-google" href="google_start.php"><?= member_google_logo_svg() ?>Continue with Google</a>
            <?php endif; ?>
            <div class="text-center mt-3">
                <a href="forgot_password.php">Forgot password?</a>
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
<?php member_auth_page_close(); ?>
