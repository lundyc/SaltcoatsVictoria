<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json; charset=UTF-8');

function sponsor_facebook_spotlight_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sponsor_facebook_spotlight_fail('Method not allowed. Use POST.', 405);
}
if (!hub_auth_is_authenticated()) {
    sponsor_facebook_spotlight_fail('Authentication required.', 401);
}
if (!csrf_check()) {
    sponsor_facebook_spotlight_fail('Your session expired. Reload the sponsors page and try again.', 419);
}

$seasonId = (int)($_POST['season_id'] ?? 0);
$sponsorId = (int)($_POST['sponsor_id'] ?? 0);
$posted = (string)($_POST['posted'] ?? '') === '1';

if ($seasonId <= 0) {
    sponsor_facebook_spotlight_fail('Season is required.', 422);
}
if ($sponsorId <= 0) {
    sponsor_facebook_spotlight_fail('Sponsor is required.', 422);
}

try {
    ensureSponsorSeasonSchema($pdo);

    $seasonStmt = $pdo->prepare('SELECT is_locked FROM seasons WHERE id = :id');
    $seasonStmt->execute([':id' => $seasonId]);
    $season = $seasonStmt->fetch(PDO::FETCH_ASSOC);
    if (!$season) {
        sponsor_facebook_spotlight_fail('Season not found.', 404);
    }
    if ((int)$season['is_locked'] === 1) {
        sponsor_facebook_spotlight_fail('This season is locked.', 423);
    }

    $sponsorStmt = $pdo->prepare('SELECT id, name, is_active FROM sponsors WHERE id = :id');
    $sponsorStmt->execute([':id' => $sponsorId]);
    $sponsor = $sponsorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sponsor) {
        sponsor_facebook_spotlight_fail('Sponsor not found.', 404);
    }

    $stmt = $pdo->prepare("
        INSERT INTO sponsor_seasons
            (season_id, sponsor_id, is_active, facebook_spotlight_posted, facebook_spotlight_posted_at)
        VALUES
            (:season_id, :sponsor_id, :is_active, :posted, CASE WHEN :posted_for_date = 1 THEN NOW() ELSE NULL END)
        ON DUPLICATE KEY UPDATE
            facebook_spotlight_posted = VALUES(facebook_spotlight_posted),
            facebook_spotlight_posted_at = CASE
                WHEN VALUES(facebook_spotlight_posted) = 1 THEN COALESCE(sponsor_seasons.facebook_spotlight_posted_at, NOW())
                ELSE NULL
            END
    ");
    $stmt->execute([
        ':season_id' => $seasonId,
        ':sponsor_id' => $sponsorId,
        ':is_active' => (int)$sponsor['is_active'],
        ':posted' => $posted ? 1 : 0,
        ':posted_for_date' => $posted ? 1 : 0,
    ]);

    auditLog(
        $pdo,
        'sponsor_facebook_spotlight_toggled',
        ($posted ? 'Marked' : 'Unmarked') . " Facebook sponsor spotlight for '" . (string)$sponsor['name'] . "' in season #{$seasonId}"
    );

    echo json_encode([
        'ok' => true,
        'posted' => $posted,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    sponsor_facebook_spotlight_fail($e->getMessage(), 400);
}
