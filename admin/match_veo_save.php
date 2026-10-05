<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/member_matches.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    http_response_code(403);
    exit('Forbidden.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_check()) {
    http_response_code(400);
    exit('Invalid request.');
}

ensureMemberMatchSchema($pdo);

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$veoUrl = trim((string) ($_POST['veo_url'] ?? ''));

if ($fixtureId <= 0) {
    http_response_code(404);
    exit('Fixture not found.');
}
if ($veoUrl !== '' && !filter_var($veoUrl, FILTER_VALIDATE_URL)) {
    header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&veo_error=1');
    exit;
}

$pdo->prepare('UPDATE match_fixtures SET veo_url = :veo_url WHERE id = :id')
    ->execute([':veo_url' => $veoUrl ?: null, ':id' => $fixtureId]);

auditLog($pdo, 'match_veo_url_saved', $veoUrl !== '' ? "Set Veo URL for fixture #{$fixtureId} to {$veoUrl}" : "Cleared Veo URL for fixture #{$fixtureId}");

header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&veo_saved=1');
exit;
