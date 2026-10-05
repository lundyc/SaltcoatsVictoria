<?php
/**
 * Route: /club/results/{slug} — retired. Each archived match now has a live
 * fixture (history_matches.fixture_id, set by tools/merge_history_into_fixtures.php).
 * Redirect to its match centre; fall back to /results for anything unmatched.
 */
declare(strict_types=1);

$match = pub_history_match((string) ($slug ?? ''));
$fixtureId = (int) ($match['fixture_id'] ?? 0);

if ($fixtureId > 0) {
    header('Location: ' . url('match/' . $fixtureId), true, 301);
    exit;
}

header('Location: ' . url('results'), true, $match === null ? 302 : 301);
exit;
