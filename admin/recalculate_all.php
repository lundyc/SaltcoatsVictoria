<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('finance_manage')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

$stmt = $pdo->query("
    SELECT DISTINCT season_id, player_id, sponsor_id
    FROM sponsorships
    WHERE season_id IS NOT NULL
    ORDER BY season_id ASC, player_id ASC, sponsor_id ASC
");
$combos = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($combos as $combo) {
    recalculateSponsorAmounts(
        $pdo,
        (int)$combo['player_id'],
        (int)$combo['sponsor_id'],
        (int)$combo['season_id']
    );
}

echo "All sponsorships recalculated successfully.";
