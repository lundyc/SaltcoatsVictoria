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

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session expired. Please reload and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $result = member_auth_issue_password_reset($pdo, $email);
        $message = $result['message'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password - <?= h(APP_NAME) ?></title>
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
        <h1 class="h3">Set up or reset your password</h1>
        <p class="login-card__intro">Enter the email address on your season ticket. If it's on file, we'll email you a link to set your password.</p>
        <?php if ($message !== ''): ?>
            <div class="alert alert-info" role="status"><?= h($message) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
            <div class="mb-3">
                <label class="form-label" for="forgotEmail">Email</label>
                <input type="email" id="forgotEmail" name="email" class="form-control" required autofocus>
            </div>
            <button type="submit" class="btn btn-login">Send link</button>
            <div class="text-center mt-3">
                <a href="login.php">Back to login</a>
            </div>
        </form>
    </div>
</body>
</html>
