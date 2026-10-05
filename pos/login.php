<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';

pos_ensure_schema($pdo);

if (hub_auth_is_authenticated()) {
    header('Location: /pos/');
    exit;
}

$error = '';
$status = trim((string) ($_GET['status'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $result = pos_attempt_operator_login($pdo, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!empty($result['ok'])) {
            header('Location: /pos/');
            exit;
        }
        $error = (string) ($result['error'] ?? 'Login failed.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Login - Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <style>
        body { min-height:100vh; display:grid; place-items:center; margin:0; font-family:Inter,system-ui,sans-serif; background:linear-gradient(135deg,#4b0818,#8a1538 52%,#ba8f2a); color:#24151a; }
        .pos-login { width:min(440px, calc(100vw - 2rem)); background:#fff; border-radius:1rem; padding:2rem; box-shadow:0 24px 80px rgba(24,7,12,.35); }
        .pos-login__mark { width:54px; height:54px; display:grid; place-items:center; border-radius:1rem; background:#4b0818; color:#fff; font-size:1.35rem; margin-bottom:1rem; }
        .btn-brand { --bs-btn-bg:#4b0818; --bs-btn-border-color:#4b0818; --bs-btn-hover-bg:#650c23; --bs-btn-hover-border-color:#650c23; color:#fff; }
    </style>
</head>
<body>
    <main class="pos-login">
        <div class="pos-login__mark"><i class="fa-solid fa-cash-register" aria-hidden="true"></i></div>
        <h1 class="h3 fw-bold mb-1">POS Login</h1>
        <p class="text-muted mb-4">Operator access for matchday sales.</p>
        <?php if ($status !== ''): ?><div class="alert alert-success"><?= h($status) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="username">Username</label>
                <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold" for="password">Password or PIN</label>
                <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-brand btn-lg w-100" type="submit">Log in</button>
        </form>
        <div class="mt-4 small text-muted">Hub admins can use the main Hub login and open POS from the navigation.</div>
        <div class="mt-3"><a class="small fw-bold text-decoration-none" href="/admin/login.php">Log in as Hub admin instead</a></div>
    </main>
</body>
</html>
