<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$action = isset($_GET['action']) && is_string($_GET['action']) ? trim($_GET['action']) : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php?error=' . urlencode('Method not allowed.'));
    exit;
}

$csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!hub_auth_verify_csrf_token($csrfToken)) {
    header('Location: index.php?error=' . urlencode('Session validation failed. Please reload and try again.'));
    exit;
}

if ($action === 'login') {
    $identifier = isset($_POST['identifier']) && is_string($_POST['identifier']) ? trim($_POST['identifier']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $result = hub_auth_attempt_login($identifier, $password);

    if (!$result['ok']) {
        header('Location: index.php?error=' . urlencode((string) ($result['error'] ?? 'Login failed.')));
        exit;
    }

    header('Location: index.php?status=' . urlencode('Login successful.'));
    exit;
}

if ($action === 'logout') {
    hub_auth_logout();
    header('Location: login.php?status=' . urlencode('Logged out.'));
    exit;
}

if ($action === 'bootstrap') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $installSecret = isset($_POST['install_secret']) && is_string($_POST['install_secret']) ? $_POST['install_secret'] : '';
    $result = hub_auth_bootstrap_admin($username, $email, $password, $installSecret);

    if (!$result['ok']) {
        header('Location: index.php?error=' . urlencode((string) ($result['error'] ?? 'Bootstrap failed.')));
        exit;
    }

    header('Location: index.php?status=' . urlencode('Hub admin account created.'));
    exit;
}

header('Location: index.php?error=' . urlencode('Unknown authentication action.'));
