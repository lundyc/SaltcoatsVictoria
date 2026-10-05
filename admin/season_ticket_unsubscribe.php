<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/people.php';
ensureSeasonTicketSchema($pdo);

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$contact = $token !== '' ? findMarketingContactByUnsubscribeToken($pdo, $token) : null;

$done = false;
if ($contact && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $contact = unsubscribeMarketingContact($pdo, $token);
    $done = true;
}
$displayName = $contact ? marketingContactDisplayName($contact) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/login.css">
</head>
<body>
    <div class="login-card">
        <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" class="login-card__logo" loading="eager">
        <h1 class="h3">Mailing list</h1>
        <?php if (!$contact): ?>
            <p class="login-card__intro">That unsubscribe link isn't valid. If you'd like to be removed from our mailing list, please contact the club directly.</p>
        <?php elseif ($done): ?>
            <div class="alert alert-success" role="status">You've been unsubscribed, <?= h($displayName) ?>. You won't receive any more club offers or deals by email.</div>
            <p class="login-card__intro">Changed your mind? Contact the club to be added back.</p>
        <?php elseif (marketingContactIsUnsubscribed($contact)): ?>
            <div class="alert alert-info" role="status">You're already unsubscribed, <?= h($displayName) ?>.</div>
        <?php else: ?>
            <p class="login-card__intro">Hi <?= h($displayName) ?>, click below to stop receiving club offers and deals by email. You'll still hear from us about important season ticket matters.</p>
            <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button type="submit" class="btn btn-brand w-100">Unsubscribe me</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
