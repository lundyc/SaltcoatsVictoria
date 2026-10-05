<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// players_active.php - returns array of active players
require_once __DIR__ . '/db.php';
$players = $pdo->query("SELECT id, name FROM players WHERE active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($players);
