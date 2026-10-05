<?php
declare(strict_types=1);

// Bare render target for the server-side PNG/PDF export — no header.php, no
// admin chrome, just the poster itself plus the CSS that styles it, so a
// headless Chrome launched from match_poster_render.php can screenshot it
// pixel-for-pixel identical to the live preview on match_poster.php. Both
// pages include the exact same match_poster_render_partial.php, so the two
// can never draw the fixture differently.
//
// Gated by a shared secret rather than a login session, since the request
// comes from a local headless browser process, not a logged-in browser.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/match_poster_render_config.php';

if (MATCH_POSTER_RENDER_SECRET === '' || !hash_equals(MATCH_POSTER_RENDER_SECRET, (string)($_GET['_token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
    http_response_code(404);
    exit('Fixture not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Fixture <?= (int)$fixtureId ?> Poster</title>
<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-poster.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/player-sponsors-match-poster.css') ?: time()) ?>">
<style>
  /* match_poster.php (the live admin page) loads Bootstrap, whose global
     reset (box-sizing: border-box) is what keeps .match-poster's declared
     794x1123 size including its own padding. This bare fragment loads no
     Bootstrap, so it needs the same reset explicitly or the poster renders
     larger than intended (padding added on top of 794x1123 instead of
     inside it) and no longer matches true A4 proportions. */
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
</style>
</head>
<body>
<?php
if (!defined('MATCH_POSTER_PARTIAL_ALLOWED')) {
    define('MATCH_POSTER_PARTIAL_ALLOWED', true);
}
require __DIR__ . '/match_poster_render_partial.php';
?>
</body>
</html>
