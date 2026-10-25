<?php

declare(strict_types=1);

// The "Next Match" graphic as a bare 1080x1350 page. Headless Chrome screenshots
// #nextMatchCard (see lib/next_match_graphic.php); the Hub page previews it in an
// iframe. The render token lets the server fetch it with nobody logged in.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/render_access_token.php';
$isTokenRender = RENDER_ACCESS_TOKEN !== ''
    && ($_GET['render'] ?? '') === '1'
    && hash_equals(RENDER_ACCESS_TOKEN, (string)($_GET['_token'] ?? ''));
if (!$isTokenRender && !hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/next_match_graphic.php';

$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$data = $fixtureId > 0 ? next_match_graphic_data($pdo, $fixtureId) : null;
if ($data === null) {
    http_response_code(404);
    exit('Fixture not found');
}

// Photo: an explicit ?bg= (a path under /uploads/), else the fixture's uploaded
// background, else the chosen player's action shot.
next_match_graphic_ensure_schema($pdo);
$photoType = next_match_graphic_type($_GET['type'] ?? '');
$pc = next_match_graphic_cols($photoType);
$photo = '';
$bg = (string)($_GET['bg'] ?? '');
if ($bg !== '' && preg_match('#^/uploads/[A-Za-z0-9._/%-]+$#', $bg) === 1 && !str_contains($bg, '..')) {
    $photo = $bg;
} elseif (($ownBg = trim((string)$pdo->query('SELECT ' . $pc['bg'] . ' FROM match_fixtures WHERE id = ' . $fixtureId)->fetchColumn())) !== '') {
    $photo = '/' . ltrim($ownBg, '/');
}

next_match_graphic_ensure_schema($pdo);
$view = $pdo->prepare('SELECT ' . $pc['zoom'] . ' AS z, ' . $pc['x'] . ' AS x, ' . $pc['y'] . ' AS y FROM match_fixtures WHERE id = :id');
$view->execute([':id' => $fixtureId]);
$view = $view->fetch(PDO::FETCH_ASSOC) ?: ['z' => 100, 'x' => 50, 'y' => 22];
$photoStyle = sprintf('object-position:%d%% %d%%;transform:scale(%.2F);transform-origin:%d%% %d%%;',
    (int)$view['x'], (int)$view['y'], max(100, min(300, (int)$view['z'])) / 100, (int)$view['x'], (int)$view['y']);

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sponsors = array_slice($data['sponsors'], 0, 5);
$featured = $data['featured'];
$isMatchday = ($_GET['type'] ?? '') === 'matchday';
$fontBase = '/admin/assets/fonts/';
if (in_array($photoType, ['goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed'], true)) {
    require __DIR__ . '/lib/event_card_view.php';
    exit;
}
if (in_array($photoType, ['kickoff', 'second_half'], true)) {
    require __DIR__ . '/lib/kickoff_card_view.php';
    exit;
}
if ($photoType === 'substitution') {
    require __DIR__ . '/lib/substitution_card_view.php';
    exit;
}
if ($photoType === 'full_time') {
    require __DIR__ . '/lib/full_time_card_view.php';
    exit;
}
if ($photoType === 'half_time') {
    require __DIR__ . '/lib/half_time_card_view.php';
    exit;
}
if ($photoType === 'starting_xi') {
    require __DIR__ . '/lib/starting_xi_card_view.php';
    exit;
}
if ($photoType === 'next_match') {
    require __DIR__ . '/lib/next_match_2_card_view.php';
    exit;
}
if ($isMatchday) {
    require __DIR__ . '/lib/matchday_card_view.php';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Next match</title>
<style>
@font-face { font-family: "NM Display"; src: url("<?= $fontBase ?>Montserrat-BlackItalic.ttf"); font-weight: 900; font-style: italic; }
@font-face { font-family: "NM Serif"; src: url("<?= $fontBase ?>Cinzel-Bold.ttf"); font-weight: 700; }
@font-face { font-family: "NM Anton"; src: url("<?= $fontBase ?>Anton-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Script"; src: url("<?= $fontBase ?>PinyonScript-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-SemiBold.ttf"); font-weight: 600; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-Medium.ttf"); font-weight: 500; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-MediumItalic.ttf"); font-weight: 500; font-style: italic; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-ExtraBold.ttf"); font-weight: 800; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: #450A14; color: var(--cream); font-family: "NM Sans", Arial, sans-serif; }
.nm-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; filter: sepia(.35) saturate(1.15) contrast(1.05) brightness(.92); }
.nm-fallback { position: absolute; inset: 0; background: radial-gradient(120% 90% at 50% 40%, #6E1F2D 0%, #450A14 60%, #2F2F2F 100%); }
.nm-texture { position: absolute; inset: 0; background: url("/admin/assets/images/texture-concrete.jpg") center / cover no-repeat; mix-blend-mode: overlay; opacity: .5; filter: contrast(1.35) brightness(1.05); pointer-events: none; }
.nm-tone { position: absolute; inset: 0; background:
  radial-gradient(85% 38% at 50% 26%, rgba(69,10,20,.55) 0%, rgba(69,10,20,.3) 55%, rgba(69,10,20,0) 100%),
  linear-gradient(180deg, rgba(69,10,20,.82) 0%, rgba(110,31,45,.48) 24%, rgba(69,10,20,.34) 52%, rgba(47,47,47,.62) 78%, rgba(69,10,20,.92) 100%); }
.nm-tone::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(226,183,46,.16) 0%, rgba(226,183,46,0) 22%); mix-blend-mode: soft-light; }
.nm-grain { position: absolute; inset: 0; opacity: .32; mix-blend-mode: overlay; pointer-events: none; }
.nm-top { position: absolute; right: 70px; top: 64px; z-index: 3; }
.nm-info { display: flex; justify-content: space-between; margin: -21.6px 55px 0; font-weight: 1; font-size: 24px; letter-spacing: .04em; text-transform: uppercase; text-shadow: 0 2px 14px rgba(0,0,0,.6); }
.nm-badges { display: flex; align-items: center; gap: 18px; }
.nm-badges span { display: block; height: 96px; width: 112px; background-color: var(--cream); -webkit-mask: var(--b) center / contain no-repeat; mask: var(--b) center / contain no-repeat; filter: drop-shadow(0 4px 12px rgba(0,0,0,.5)); }
.nm-stack { position: absolute; left: 0; right: 0; top: 70px; text-align: center; }
.nm-title { text-align: center; }
.nm-next { position: relative; top: -13px; z-index: 2; display: block; font-family: "NM Sans", Arial, sans-serif; font-weight: 500; font-style: italic; font-size: 120px; line-height: 1; letter-spacing: 0; color: var(--gold); text-transform: none; margin-bottom: -48px; text-align: left; padding-left: 46px; text-shadow: 0 6px 24px rgba(0,0,0,.45); }
.nm-match { display: inline-block; font-family: "NM Sans", Arial, sans-serif; font-weight: 800; font-style: normal; text-transform: uppercase; font-size: 330px; line-height: .95; white-space: nowrap; color: var(--cream); text-shadow: 0 10px 34px rgba(0,0,0,.4); transform-origin: center top; }
.nm-versus { margin: -4px 55px 0; text-align: center; font-weight: 1; font-size: 24px; letter-spacing: .12em; text-transform: uppercase; text-shadow: 0 2px 6px rgba(0,0,0,.8), 0 2px 22px rgba(0,0,0,.8); }
.nm-feature { position: absolute; left: 40px; right: 40px; bottom: 190px; display: flex; gap: 30px; justify-content: center; align-items: flex-end; }
.nm-feature__item { flex: 1 1 0; min-width: 0; text-align: center; }
.nm-feature strong { display: block; color: var(--cream); font-size: 44px; font-weight: 800; text-shadow: 0 3px 14px rgba(0,0,0,.7); }
.nm-feature small { display: block; color: var(--gold); font-size: 22px; letter-spacing: .14em; text-transform: uppercase; text-shadow: 0 2px 12px rgba(0,0,0,.75); }
.nm-feature img { max-width: 100%; max-height: 330px; object-fit: contain; filter: drop-shadow(0 6px 18px rgba(0,0,0,.55)); }
.nm-feature img.is-mono { filter: brightness(0) invert(1) drop-shadow(0 6px 18px rgba(0,0,0,.55)); }
.nm-sponsors { position: absolute; left: 60px; right: 60px; bottom: 56px; display: flex; align-items: center; justify-content: center; gap: 56px; }
.nm-sponsors img { height: 82px; width: auto; max-width: 220px; object-fit: contain; opacity: .94; filter: drop-shadow(0 4px 10px rgba(0,0,0,.45)); }
</style>
</head>
<body>
<div id="nextMatchCard">
  <?php if ($photo !== ''): ?>
    <img class="nm-photo" src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt="">
  <?php else: ?>
    <div class="nm-fallback"></div>
  <?php endif; ?>
  <div class="nm-texture"></div>
  <div class="nm-tone"></div>
  <svg class="nm-grain" width="1080" height="1350" aria-hidden="true"><filter id="g"><feTurbulence type="fractalNoise" baseFrequency=".85" numOctaves="2" stitchTiles="stitch"/><feColorMatrix values="0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.1 -.2"/></filter><rect width="100%" height="100%" filter="url(#g)"/></svg>

  <div class="nm-top">
    <div class="nm-badges">
      <?php $left = $data['home'] ? $data['club_badge'] : $data['opponent_badge']; $right = $data['home'] ? $data['opponent_badge'] : $data['club_badge']; ?>
      <?php if ($left !== ''): ?><span style="--b:url('<?= $e($left) ?>')"></span><?php endif; ?>
      <?php if ($right !== ''): ?><span style="--b:url('<?= $e($right) ?>')"></span><?php endif; ?>
    </div>
  </div>

  <div class="nm-stack">
  <div class="nm-title">
    <span class="nm-next"><?= $isMatchday ? 'Today' : 'Next' ?></span>
    <span class="nm-match" id="nmMatch"><?= $isMatchday ? 'Matchday' : 'Match' ?></span>
  </div>
  <div class="nm-info"><span><?= $e($data['date_label']) ?></span><span><?= $e($data['venue_label']) ?></span><span><?= $e($data['ko_label']) ?></span></div>
  <div class="nm-versus"><?= $e($data['versus']) ?></div>
  </div>

  <?php if ($featured): ?>
    <div class="nm-feature">
      <?php foreach ($featured as $f): ?>
        <div class="nm-feature__item"><small><?= $e($f['label']) ?></small>
          <?php if ($f['url'] !== ''): ?><img<?= !empty($f['mono']) ? ' class="is-mono"' : '' ?> src="<?= $e($f['url']) ?>" alt="<?= $e($f['name']) ?>"><?php else: ?><strong><?= $e($f['name']) ?></strong><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($sponsors): ?>
    <div class="nm-sponsors">
      <?php foreach ($sponsors as $s): ?><img src="<?= $e($s['url']) ?>" alt="<?= $e($s['name']) ?>"><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script>
// Fit MATCH to the card width whatever the font renders at.
(function () {
  var el = document.getElementById('nmMatch');
  function fit() {
    el.style.transform = 'none';
    var fs = parseFloat(getComputedStyle(el).fontSize);
    var w = el.getBoundingClientRect().width;
    if (!(w > 0)) return;
    var size = 1000 / (w / fs);
    el.style.fontSize = size + 'px';
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
})();
</script>
</body>
</html>
