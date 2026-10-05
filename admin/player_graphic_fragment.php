<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// player_graphic_fragment.php — returns the sponsor-graphic markup for one
// player, used by the export JS to render players other than the one
// currently on screen (see player_graphics_editor.js).
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/player_graphic_html.php';

$playerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : 0;
if ($playerId <= 0) {
  http_response_code(400);
  exit('Missing player_id');
}

$seasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : getSelectedSeasonId($pdo);

$graphic = buildPlayerSponsorGraphic($pdo, $playerId, $seasonId);
if (!$graphic) {
  http_response_code(404);
  exit('Player not found');
}

header('Content-Type: text/html; charset=utf-8');
echo '<div class="sponsor-graphic">' . $graphic['html'] . '</div>';
