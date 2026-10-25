<?php

// Pushes every fixture in the CURRENT season to the club Google Calendar.
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/google_calendar.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
if (!csrf_check()) {
    http_response_code(400);
    exit('Invalid CSRF token.');
}

@set_time_limit(300);

$seasonId = (int) ($pdo->query('SELECT id FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
$done = 0;
$failed = 0;
$firstError = '';

if ($seasonId > 0) {
    $ids = $pdo->prepare('SELECT id FROM match_fixtures WHERE season_id = ? ORDER BY match_date, kickoff_time');
    $ids->execute([$seasonId]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $fixtureId) {
        $fixture = getMatchFixtureById($pdo, (int) $fixtureId);
        if (!$fixture) {
            continue;
        }
        $res = match_google_calendar_sync_fixture($pdo, $fixture, 'upsert');
        if (($res['ok'] ?? false) && empty($res['skipped'])) {
            $done++;
        } else {
            $failed++;
            if ($firstError === '') {
                $firstError = (string) ($res['message'] ?? 'Unknown error');
            }
        }
    }
    auditLog($pdo, 'matches_calendar_synced', "Synced current season fixtures to Google Calendar: {$done} done, {$failed} failed");
}

header('Location: matches.php?gcal_synced=' . $done . '&gcal_failed_count=' . $failed . '&season_id=' . $seasonId);
exit;
