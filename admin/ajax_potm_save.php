<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!csrf_check()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.']);
    exit;
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$player = trim((string) ($_POST['player'] ?? ''));

$stmt = $pdo->prepare('SELECT id, season_id, opponent, starting11_starters_json, starting11_substitutes_json FROM match_fixtures WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $fixtureId]);
$fixture = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fixture) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Fixture not found.']);
    exit;
}

$season = getSeasonById($pdo, (int) $fixture['season_id']);
if (!$season) {
    echo json_encode(['success' => false, 'error' => 'Season not found.']);
    exit;
}
if ((int) ($season['is_locked'] ?? 0) === 1) {
    echo json_encode(['success' => false, 'error' => 'This season is locked.']);
    exit;
}

if ($player === '') {
    echo json_encode(['success' => false, 'error' => 'Select a player.']);
    exit;
}

$starters = json_decode((string) ($fixture['starting11_starters_json'] ?? '[]'), true);
$subs = json_decode((string) ($fixture['starting11_substitutes_json'] ?? '[]'), true);
$lineupNames = array_values(array_unique(array_filter(array_map(
    static fn ($v): string => trim((string) $v),
    array_merge(is_array($starters) ? $starters : [], is_array($subs) ? $subs : [])
), static fn (string $v): bool => $v !== '')));

if ($lineupNames !== [] && !in_array($player, $lineupNames, true)) {
    echo json_encode(['success' => false, 'error' => 'That player was not part of this match\'s recorded line-up.']);
    exit;
}

$match = matches_find_by_id(matches_load_all(), (string) $fixtureId);
$existingEvent = null;
if ($match !== null && isset($match['events']) && is_array($match['events'])) {
    foreach ($match['events'] as $candidateEvent) {
        if (is_array($candidateEvent) && (string) ($candidateEvent['type'] ?? '') === 'player_of_match') {
            $existingEvent = $candidateEvent;
        }
    }
}

$eventPost = [
    'match_id' => (string) $fixtureId,
    'event_id' => (string) ($existingEvent['id'] ?? ''),
    'event_type' => 'player_of_match',
    'event_team' => 'svfc',
    'event_minute' => '',
    'event_player' => $player,
    'event_note' => 'Player of the Match',
];
$result = $existingEvent !== null
    ? matches_handle_update_event($eventPost)
    : matches_handle_add_event($eventPost);

if (empty($result['ok'])) {
    $message = isset($result['errors']) && is_array($result['errors']) && $result['errors']
        ? implode(' ', array_map('strval', $result['errors']))
        : (string) ($result['message'] ?? 'Player of the Match could not be saved.');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

auditLog(
    $pdo,
    'match_player_of_match_set',
    ($existingEvent !== null ? 'Updated' : 'Set') . ' Player of the Match for fixture vs '
        . trim((string) ($fixture['opponent'] ?? 'Opponent')) . ': ' . $player
);

echo json_encode(['success' => true, 'player' => $player]);
