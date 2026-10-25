<?php
declare(strict_types=1);

// Google redirects here after sign-in. Registered redirect URI: {APP_ORIGIN}/members/google_callback.php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/member_google.php';

if (!member_google_configured()) {
    header('Location: login.php');
    exit;
}

$result = member_google_finish($pdo, $_GET);
if (!$result['ok']) {
    member_auth_start_session();
    $_SESSION['member_flash_error'] = (string) ($result['error'] ?? 'Google sign-in failed.');
    header('Location: login.php');
    exit;
}

$account = $result['account'];
member_auth_login_account((int) $account['id'], (int) $account['person_id'], (int) ($account['old_holder_id'] ?? 0));
header('Location: index.php');
exit;
