<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/member_auth.php';

$clearHubSession = (string) ($_GET['all'] ?? '') === '1';
$hubSessionName = session_name();
$hubSessionId = session_id();

member_auth_logout();

if ($clearHubSession && $hubSessionName !== MEMBER_AUTH_SESSION_NAME && $hubSessionId !== '') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($hubSessionName);
    session_id($hubSessionId);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();
}

$returnTo = (string) ($_GET['return'] ?? '');
if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
    $returnTo = 'login.php';
}
header('Location: ' . $returnTo);
exit;
