<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// Server-side PNG/PDF export for match_poster.php. Uses the same headless-
// Chrome render pipeline as the other social/graphic exports (render_lib.php
// + render.js), screenshotting match_poster_fragment.php — which shares
// match_poster_render_partial.php with the live preview — so the download
// can never visually differ from what's shown on screen.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/match_poster_render_config.php';
require_once __DIR__ . '/render_lib.php';

if (!hub_auth_is_authenticated()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Authentication required.';
    exit;
}

$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Fixture not found.';
    exit;
}

$format = (string)($_GET['format'] ?? 'png');
if (!in_array($format, ['png', 'print'], true)) {
    $format = 'png';
}
$adultPrice = trim((string)($_GET['adult'] ?? '6.00'));
$concessionPrice = trim((string)($_GET['concession'] ?? '3.00'));
$under16Price = trim((string)($_GET['under16'] ?? 'FREE'));

function matchPosterRenderBaseUrl(): string
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'saltcoatsvictoria.co.uk')));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    if (!preg_match('/^(?:www\.)?saltcoatsvictoria\.co\.uk$/', $host)) {
        $host = 'saltcoatsvictoria.co.uk';
    }

    return 'https://' . $host;
}

if ($format === 'print') {
    // The print window loads its own <img> pointed back at this same
    // endpoint with format=png — one real render happens either way, this
    // branch just needs to be fast and needs no headless browser itself.
    $imgParams = http_build_query([
        'fixture_id' => $fixtureId,
        'adult' => $adultPrice,
        'concession' => $concessionPrice,
        'under16' => $under16Price,
        'format' => 'png',
    ]);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html>
<head>
<title>Fixture <?= (int)$fixtureId ?> Poster</title>
<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-poster-2.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/player-sponsors-match-poster-2.css') ?: time()) ?>">
</head>
<body>
<img id="posterPrintImage" src="match_poster_render.php?<?= h($imgParams) ?>" alt="Fixture poster">
<script>
  const image = document.getElementById('posterPrintImage');
  const printPoster = () => { window.focus(); window.print(); };
  image.complete && image.naturalWidth > 0 ? setTimeout(printPoster, 100) : image.addEventListener('load', printPoster, { once: true });
  image.addEventListener('error', () => { document.body.textContent = 'The poster could not be generated.'; });
  window.addEventListener('afterprint', () => window.close(), { once: true });
</script>
</body>
</html>
    <?php
    exit;
}

$outputPath = sys_get_temp_dir() . '/match-poster-' . $fixtureId . '-' . bin2hex(random_bytes(8)) . '.png';
$fragmentUrl = matchPosterRenderBaseUrl() . '/match_poster_fragment.php?' . http_build_query([
    'fixture_id' => $fixtureId,
    'adult' => $adultPrice,
    'concession' => $concessionPrice,
    'under16' => $under16Price,
    '_token' => MATCH_POSTER_RENDER_SECRET,
]);

// The poster is a fixed 794x1123 CSS-pixel card; deviceScaleFactor=2 gives a
// crisp ~1588x2246 export suitable for printing, matching what the old
// client-side html2canvas export used to produce.
$result = render_capture_image($fragmentUrl, $outputPath, '#matchPoster', 900, 1300, '#matchPoster', 2);

if (!$result['ok']) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $result['error'];
    if ($result['output'] !== []) {
        echo "\n\n" . implode("\n", $result['output']);
    }
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($outputPath));
header('Content-Disposition: attachment; filename="fixture-' . $fixtureId . '-poster.png"');
readfile($outputPath);
@unlink($outputPath);
exit;
