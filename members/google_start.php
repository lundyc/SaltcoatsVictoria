<?php
declare(strict_types=1);

// Begins "Continue with Google". With ?token=<welcome link token> the Google account will be
// linked to the account that link was issued for; without it, an existing link or the
// verified-email match decides (see admin/lib/member_google.php).
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/member_google.php';

if (!member_google_configured()) {
    header('Location: login.php');
    exit;
}

$welcomeAccount = null;
$token = trim((string) ($_GET['token'] ?? ''));
if ($token !== '') {
    $welcomeAccount = member_welcome_account_by_token($pdo, $token);
    if ($welcomeAccount === null) {
        header('Location: welcome.php?token=' . rawurlencode($token));
        exit;
    }
}

header('Location: ' . member_google_begin($welcomeAccount));
exit;
