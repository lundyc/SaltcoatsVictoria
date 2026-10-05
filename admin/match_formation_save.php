<?php

declare(strict_types=1);

/**
 * Save a side's formation key + optional dragged pitch layout for a fixture.
 * POST target of the formation pitch on match_lineups.php.
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

function mf_back(int $fixtureId, int $seasonId, bool $embedded, string $key, string $value): never
{
    $params = ['fixture_id' => $fixtureId, 'season_id' => $seasonId, $key => $value];
    if ($embedded) {
        $params['embedded'] = '1';
    }
    header('Location: match_lineups.php?' . http_build_query($params) . '#pitch-' . ($_POST['side'] ?? ''));
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

$season = getSeasonById($pdo, $seasonId);
if (is_array($season) && (int) ($season['is_locked'] ?? 0) === 1) {
    mf_back($fixtureId, $seasonId, $embedded, 'error', 'This season is locked.');
}

$formationKey = trim((string) ($_POST['formation_key'] ?? ''));

$layout = null;
$layoutRaw = (string) ($_POST['layout_json'] ?? '');
if ($layoutRaw !== '') {
    $decoded = json_decode($layoutRaw, true);
    if (is_array($decoded)) {
        $clean = [];
        foreach ($decoded as $slot => $pos) {
            $slot = (int) $slot;
            if ($slot < 0 || $slot > 10 || !is_array($pos)) {
                continue;
            }
            $clean[$slot] = [
                'x' => max(2, min(98, (float) ($pos['x'] ?? 50))),
                'y' => max(2, min(98, (float) ($pos['y'] ?? 50))),
            ];
        }
        if ($clean !== []) {
            $layout = $clean;
        }
    }
}

try {
    matchday_formation_save($pdo, $fixtureId, $side, $formationKey, $layout);
} catch (Throwable $e) {
    error_log('match_formation_save: ' . $e->getMessage());
    mf_back($fixtureId, $seasonId, $embedded, 'error', 'The formation could not be saved.');
}

auditLog($pdo, 'matchday_formation_saved', "Saved {$side} formation ({$formationKey}) for fixture #{$fixtureId}");
mf_back($fixtureId, $seasonId, $embedded, 'saved', $side);
