<?php

declare(strict_types=1);

// Club Shop — email preview / test-send endpoint (JSON). Nothing here saves
// anything: it renders a shop email with sample data using whatever template
// and look values the form currently holds, optionally sending it to the
// signed-in user as a test.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_check()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Your session expired — reload the page and try again.']);
    exit;
}

shop_ensure_schema($pdo);
$key = (string) ($_POST['key'] ?? '');
if (!isset(shop_email_definitions()[$key])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown email.']);
    exit;
}

$cfg = isset($_POST['cfg']) && is_array($_POST['cfg']) ? array_map('strval', $_POST['cfg']) : null;
$style = isset($_POST['style']) && is_array($_POST['style']) ? array_map('strval', $_POST['style']) : null;

try {
    $message = shop_email_preview($pdo, $key, $cfg, $style);
} catch (Throwable $e) {
    error_log('[shop email preview] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not build the preview.']);
    exit;
}

$out = ['ok' => true, 'subject' => $message['subject'], 'html' => $message['html']];

if ((string) ($_POST['send_test'] ?? '') === '1') {
    $to = trim((string) (hub_auth_current_user()['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $out['sent'] = false;
        $out['error'] = 'Your account has no email address to send the test to.';
    } else {
        $out['sent'] = shop_send_mail($to, '[TEST] ' . $message['subject'], $message['html']);
        $out['sent_to'] = $to;
        if (!$out['sent']) {
            $out['error'] = 'The test email could not be sent. Check the mail settings.';
        }
    }
}

echo json_encode($out);
