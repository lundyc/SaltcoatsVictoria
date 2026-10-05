<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$statusType = '';
$statusMessage = '';
$loginValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $loginValue = isset($_POST['login']) && is_string($_POST['login']) ? trim($_POST['login']) : '';
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $statusType = 'error';
        $statusMessage = 'Your session expired. Please try again.';
    } else {
        $result = issueAccountPasswordReset($pdo, $loginValue, '/reset_password.php');
        $statusType = $result['ok'] ? 'success' : 'error';
        $statusMessage = $result['message'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot password – <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-shell">
  <main class="auth-card">
    <span class="auth-card__mark" aria-hidden="true">?</span>
    <h1>Forgot password</h1>
    <p class="text-muted">Enter your username or email and we’ll help you regain access.</p>
    <?php if ($statusMessage !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($statusType === 'success' ? 'success' : 'danger', ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" class="d-grid gap-3">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label class="form-label" for="resetLogin">Username or email</label>
        <input class="form-control" id="resetLogin" name="login" type="text" value="<?= htmlspecialchars($loginValue, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="submit">Send reset link</button>
        <a class="btn btn-outline-secondary" href="login.php">Back to sign in</a>
      </div>
    </form>
  </main>
</body>
</html>
