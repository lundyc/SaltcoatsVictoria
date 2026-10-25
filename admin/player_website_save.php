<?php

declare(strict_types=1);

// Saves the public-website fields of a player (squad number, nationality, bio, URL slug, show-on-website).
// The form lives on player_edit.php; this endpoint redirects back there.
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/squad_public.php';
require_once __DIR__ . '/lib/audit.php';

$id = (int) ($_POST['player_id'] ?? 0);
function player_website_back(int $id, string $flag): never
{
    header('Location: player_edit.php?id=' . $id . '&' . $flag . '#website-profile');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $id <= 0) {
    header('Location: players.php');
    exit;
}
if (!csrf_check()) {
    player_website_back($id, 'website_error=1');
}

squad_public_ensure_schema($pdo);

$stmt = $pdo->prepare('SELECT id, name FROM players WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    header('Location: players.php');
    exit;
}

$squadNumber = trim((string) ($_POST['squad_number'] ?? ''));
$squadNumber = $squadNumber === '' ? null : max(1, min(999, (int) $squadNumber));
$nationality = trim((string) ($_POST['nationality'] ?? ''));
$bio = trim((string) ($_POST['bio'] ?? ''));
$slugInput = trim((string) ($_POST['website_slug'] ?? ''));
$slug = $slugInput !== ''
    ? squad_public_slug($slugInput, $id)
    : squad_public_slug((string) $player['name'], $id);
$onWebsite = isset($_POST['on_website']) ? 1 : 0;

$dupe = $pdo->prepare('SELECT id FROM players WHERE website_slug = :s AND id <> :id LIMIT 1');
$dupe->execute([':s' => $slug, ':id' => $id]);
if ($dupe->fetchColumn()) {
    $slug .= '-' . $id;
}

$pdo->prepare(
    'UPDATE players SET squad_number = :num, nationality = :nat, bio = :bio, website_slug = :slug WHERE id = :id'
)->execute([':num' => $squadNumber, ':nat' => $nationality, ':bio' => $bio, ':slug' => $slug, ':id' => $id]);

$pdo->prepare(
    'INSERT INTO player_website_photos (player_id, uploaded_to_website) VALUES (:id, :w)
     ON DUPLICATE KEY UPDATE uploaded_to_website = VALUES(uploaded_to_website)'
)->execute([':id' => $id, ':w' => $onWebsite]);

auditLog($pdo, 'player_website_profile_updated', "Updated website profile for player #{$id} ({$player['name']})");
player_website_back($id, 'website_saved=1');
