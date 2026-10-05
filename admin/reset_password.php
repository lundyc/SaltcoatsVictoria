<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$statusType = '';
$statusMessage = '';
$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : (isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $statusType = 'error';
        $statusMessage = 'Your session expired. Please reload the page and try again.';
    } elseif ($password !== $passwordConfirm) {
        $statusType = 'error';
        $statusMessage = 'Passwords do not match.';
    } else {
        $result = resetAccountPasswordByToken($pdo, $token, $password);
        $statusType = $result['ok'] ? 'success' : 'error';
        $statusMessage = $result['message'];
        if ($result['ok']) {
            header('Location: index.php?status=' . urlencode('Password updated. Please log in again.'));
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password – <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-shell">
  <main class="auth-card">
    <span class="auth-card__mark" aria-hidden="true">✓</span>
    <h1>Reset password</h1>
    <p class="text-muted">Choose a new password for your Hub account.</p>
    <?php if ($statusMessage !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($statusType === 'success' ? 'success' : 'danger', ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($token !== '' && $statusType !== 'error'): ?>
      <form method="post" class="d-grid gap-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div><label class="form-label" for="newPassword">New password</label><input class="form-control" id="newPassword" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
        <div><label class="form-label" for="confirmPassword">Confirm password</label><input class="form-control" id="confirmPassword" name="password_confirm" type="password" minlength="8" autocomplete="new-password" required></div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-primary" type="submit">Update password</button>
          <a class="btn btn-outline-secondary" href="login.php">Back to sign in</a>
        </div>
      </form>
    <?php else: ?>
      <div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="forgot_password.php">Request a new reset link</a><a class="btn btn-outline-secondary" href="login.php">Back to sign in</a></div>
    <?php endif; ?>
  </main>
</body>
</html>
