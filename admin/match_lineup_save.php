<?php

declare(strict_types=1);

/**
 * Save one side's whole line-up (starting XI + subs + captain + formation)
 * for a fixture. POST target of match_lineups.php. Writes the normalised
 * matchday_* tables via lib/matchday_record.php; the projection keeps the
 * legacy stores in sync.
 */

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/matchday_record.php';
require_once __DIR__ . '/lib/audit.php';

$embedded = (string) ($_POST['embedded'] ?? '') === '1';
$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$side = matchday_record_side((string) ($_POST['side'] ?? ''));

function ml_back(int $fixtureId, int $seasonId, bool $embedded, string $key, string $value): never
{
    $params = ['fixture_id' => $fixtureId, 'season_id' => $seasonId, $key => $value];
    if ($embedded) {
        $params['embedded'] = '1';
    }
    header('Location: match_lineups.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(400);
    exit('Invalid request.');
}

$fixture = $fixtureId > 0 ? matchday_record_fixture($pdo, $fixtureId) : null;
if ($fixture === null || ($seasonId > 0 && (int) $fixture['season_id'] !== $seasonId)) {
    http_response_code(404);
    exit('Fixture not found.');
}
$seasonId = (int) $fixture['season_id'];

$season = function_exists('getSeasonById') ? getSeasonById($pdo, $seasonId) : null;
if (is_array($season) && (int) ($season['is_locked'] ?? 0) === 1) {
    ml_back($fixtureId, $seasonId, $embedded, 'error', 'This season is locked.');
}

$squadById = [];
foreach ($pdo->query("SELECT id, name FROM players WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $squadById[(int) $row['id']] = (string) $row['name'];
}

$starterPlayerIds = (array) ($_POST['starter_player_id'] ?? []);
$starterNames = (array) ($_POST['starter_name'] ?? []);
$starterNumbers = (array) ($_POST['starter_number'] ?? []);
$starterPositions = (array) ($_POST['starter_position'] ?? []);
$captainSlot = ($_POST['captain_slot'] ?? '') === '' ? -1 : (int) $_POST['captain_slot'];

$subPlayerIds = (array) ($_POST['sub_player_id'] ?? []);
$subNames = (array) ($_POST['sub_name'] ?? []);
$subNumbers = (array) ($_POST['sub_number'] ?? []);
$subPositions = (array) ($_POST['sub_position'] ?? []);

/**
 * Resolve one input row to a player payload for matchday_lineup_replace(),
 * or null if the row is blank.
 */
$resolveRow = static function (
    string $side,
    array $squadById,
    $playerId,
    $name,
    $number,
    $position,
    bool $isStarting,
    bool $isCaptain,
    int $sort
): ?array {
    if ($side === 'svfc') {
        $pid = (int) $playerId;
        if ($pid <= 0 || !isset($squadById[$pid])) {
            return null;
        }
        $playerName = $squadById[$pid];
    } else {
        $pid = null;
        $playerName = trim((string) $name);
        if ($playerName === '') {
            return null;
        }
    }
    $shirt = trim((string) $number);
    return [
        'player_id' => $pid,
        'player_name' => $playerName,
        'shirt_number' => $shirt === '' ? null : (int) $shirt,
        'position_label' => trim((string) $position) ?: null,
        'is_starting' => $isStarting,
        'is_captain' => $isCaptain,
        'sort_order' => $sort,
    ];
};

$players = [];
$seenSvfc = [];
$errorMsg = '';

for ($i = 0; $i < 11; $i++) {
    $row = $resolveRow(
        $side,
        $squadById,
        $starterPlayerIds[$i] ?? '',
        $starterNames[$i] ?? '',
        $starterNumbers[$i] ?? '',
        $starterPositions[$i] ?? '',
        true,
        $i === $captainSlot,
        ($i + 1) * 10
    );
    if ($row === null) {
        continue;
    }
    if ($side === 'svfc') {
        if (isset($seenSvfc[$row['player_id']])) {
            $errorMsg = 'A player can only be selected once.';
            break;
        }
        $seenSvfc[$row['player_id']] = true;
    }
    $players[] = $row;
}

if ($errorMsg === '') {
    $subCount = max(count($subPlayerIds), count($subNames), count($subNumbers), count($subPositions));
    for ($j = 0; $j < $subCount; $j++) {
        $row = $resolveRow(
            $side,
            $squadById,
            $subPlayerIds[$j] ?? '',
            $subNames[$j] ?? '',
            $subNumbers[$j] ?? '',
            $subPositions[$j] ?? '',
            false,
            false,
            200 + ($j + 1) * 10
        );
        if ($row === null) {
            continue;
        }
        if ($side === 'svfc') {
            if (isset($seenSvfc[$row['player_id']])) {
                $errorMsg = 'A player cannot be both a starter and a substitute.';
                break;
            }
            $seenSvfc[$row['player_id']] = true;
        }
        $players[] = $row;
    }
}

if ($errorMsg !== '') {
    ml_back($fixtureId, $seasonId, $embedded, 'error', $errorMsg);
}

try {
    matchday_lineup_replace($pdo, $fixtureId, $side, $players);
    matchday_formation_set_key($pdo, $fixtureId, $side, (string) ($_POST['formation_key'] ?? ''));
    matchday_periods_ensure($pdo, $fixtureId);
} catch (Throwable $e) {
    error_log('match_lineup_save: ' . $e->getMessage());
    ml_back($fixtureId, $seasonId, $embedded, 'error', 'The line-up could not be saved.');
}

$label = $side === 'svfc' ? 'Saltcoats Victoria' : trim((string) ($fixture['opponent'] ?? 'opponent'));
auditLog($pdo, 'matchday_lineup_saved', 'Saved ' . $label . ' line-up for fixture #' . $fixtureId);

ml_back($fixtureId, $seasonId, $embedded, 'saved', $side);
