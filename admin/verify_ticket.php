<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_ticket_attendance.php';
require_once __DIR__ . '/lib/season_passes.php';

$token = season_ticket_extract_token((string) ($_GET['token'] ?? ''));
$pass = $token !== '' ? getSeasonPassByCredential($pdo, $token) : null;
$valid = $pass
    && (int) ($pass['credential_is_active'] ?? 0) === 1
    && (string) ($pass['entitlement_status'] ?? '') === 'active'
    && (string) ($pass['order_status'] ?? '') === 'paid';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Season Ticket Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <main class="container py-5" style="max-width:720px;">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h3"><?= $valid ? 'Valid Season Ticket' : 'Ticket Not Valid' ?></h1>
                <?php if (!$pass): ?>
                    <p class="text-muted mb-0">This season ticket could not be found.</p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Ticket</dt><dd class="col-sm-8"><?= h((string) $pass['type_name']) ?> Season Ticket</dd>
                        <dt class="col-sm-4">Season</dt><dd class="col-sm-8"><?= h((string) $pass['season_name']) ?></dd>
                        <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= $valid ? 'Active' : h((string) $pass['entitlement_status']) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
