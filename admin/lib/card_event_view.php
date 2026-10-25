<?php

declare(strict_types=1);

// Yellow / red card graphic: a solid card-coloured poster with a giant condensed word behind a
// rounded photo, the player's name under it, and a cropped club name along the bottom edge.
// Included from event_card_view.php with $ev, $person, $tag, $photoType, $data, $sponsors, $e, $fontBase.

$isRed = $photoType === 'red_card';
$isPenalty = $photoType === 'penalty_awarded';
$word = $isRed ? 'RED' : ($isPenalty ? 'PENALTY' : 'YELLOW');
$bgCss = $isRed ? 'linear-gradient(160deg, #8C1424 0%, #6E1F2D 45%, #4F0B18 100%)' : 'linear-gradient(160deg, #F6C919 0%, #F2BE12 50%, #E5A912 100%)';
$teamBadge = $ev !== null ? (($ev['side'] === 'svfc') ? $data['club_badge'] : $data['opponent_badge']) : '';
$teamName = $ev !== null ? $ev['team'] : '';
$scoreLine = $ev !== null ? strtoupper($ev['home_name']) . '  ' . (int)$ev['score'][0] . ' - ' . (int)$ev['score'][1] . '  ' . strtoupper($ev['away_name']) : '';
$sub = $ev !== null ? trim(($ev['minute'] !== '' ? $ev['minute'] . "'  ·  " : '') . ($tag !== '' ? $tag : ($isRed ? 'RED CARD' : ($isPenalty ? 'PENALTY' : 'YELLOW CARD')))) : '';
$fullName = $ev !== null && trim((string)$ev['player_name']) !== '' ? trim((string)$ev['player_name']) : $person['name'];
$nameLen = mb_strlen($fullName);
$nameSize = $nameLen > 24 ? 62 : ($nameLen > 19 ? 74 : ($nameLen > 15 ? 88 : 104));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($word) ?></title>
<style>
@font-face { font-family: "NM Anton"; src: url("<?= $fontBase ?>Anton-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --deep: #450A14; --char: #2F2F2F; --ink: #2F2F2F; }
.is-red { --ink: #FFF3E3; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: <?= $bgCss ?>; color: var(--ink); font-family: "NM Inter", Arial, sans-serif; text-align: center; }
#nextMatchCard::before { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .55; pointer-events: none; }
.cd-tex { position: absolute; inset: 0; background: url("/admin/assets/images/texture-halftone.png") 0 0 / 1080px 648px repeat; mix-blend-mode: multiply; opacity: .55; pointer-events: none; }
.is-red .cd-tex { filter: invert(1); mix-blend-mode: screen; opacity: .22; }
.cd-team { position: absolute; left: 0; right: 0; top: 70px; font-weight: 800; font-size: 25px; letter-spacing: .5em; text-indent: .5em; text-transform: uppercase; }
.cd-badge { position: absolute; left: 50%; top: 112px; transform: translateX(-50%); }
.cd-tint { display: inline-block; line-height: 0; background-color: var(--ink); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.cd-tint img { opacity: 0; display: block; }
.cd-badge .cd-tint img { height: 84px; width: auto; max-width: 96px; }
.cd-word { position: absolute; left: 0; right: 0; top: 250px; font-family: "NM Anton", Impact, sans-serif; font-weight: 400; line-height: .9; white-space: nowrap; text-transform: uppercase; }
.cd-main { position: absolute; left: 0; right: 0; top: 340px; display: flex; flex-direction: column; align-items: center; gap: 26px; }
.cd-photo { position: relative; display: inline-flex; flex-direction: column; }
/* The coloured panel starts 30% (190px) lower than the top of the photo, so the player's head and shoulders stand proud of it. */
.cd-photo::before { content: ""; position: absolute; left: 0; right: 0; bottom: 0; top: 190px; border-radius: 30px; background: #e9e6e1; box-shadow: 0 14px 40px rgba(47,47,47,.28); }
.cd-photo-img { position: relative; z-index: 1; height: 643px; }
.cd-sponsors { position: relative; z-index: 1; width: 0; min-width: 100%; overflow: hidden; border-radius: 0 0 30px 30px; }
.cd-photo-img img { display: block; height: 100%; width: auto; }
.cd-photo-img img.cd-sil { opacity: .7; }
.cd-name { font-weight: 800; font-size: <?= $nameSize ?>px; line-height: 1; letter-spacing: -.055em; white-space: nowrap; }
.cd-name em { font-style: normal; opacity: .6; margin-right: .18em; }
.cd-giant { position: absolute; left: 0; right: 0; top: 1285px; font-family: "NM Anton", Impact, sans-serif; font-size: 250px; line-height: 1; letter-spacing: .02em; text-transform: uppercase; white-space: nowrap; color: var(--ink); }
.cd-sponsors { display: flex; }
.cd-chip { flex: 1 1 0; min-width: 0; height: 155px; display: flex; align-items: center; justify-content: center; padding: 8px; }
.cd-chip.is-home { background: #6E1F2D; }
.cd-chip.is-away { background: #F6C919; }
.cd-chip.is-third { background: #FFF3E3; }
.cd-text { display: block; width: calc(100% - 28px); height: 100%; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.cd-text.is-home { background-color: #F6C919; }
.cd-text.is-away { background-color: #6E1F2D; }
.cd-chip img { display: block; width: 100%; height: 100%; object-fit: contain; border-radius: 8px; }
.cd-footer { position: absolute; left: 0; right: 0; top: 1262px; display: none; }
.cd-empty { position: absolute; left: 0; right: 0; top: 620px; font-size: 40px; font-weight: 700; }
</style>
</head>
<body>
<div id="nextMatchCard"<?= $isRed ? ' class="is-red"' : '' ?>>
  <div class="cd-tex"></div>
  <?php if ($ev === null): ?>
    <div class="cd-empty">Choose a <?= $isPenalty ? 'penalty' : ($isRed ? 'red card' : 'yellow card') ?></div>
  <?php else: ?>
    <div class="cd-team"><?= $e($teamName) ?></div>
    <?php if ($teamBadge !== ''): ?><div class="cd-badge"><span class="cd-tint" style="--m:url('<?= $e($teamBadge) ?>')"><img src="<?= $e($teamBadge) ?>" alt=""></span></div><?php endif; ?>
    <div class="cd-word" id="cdWord"<?= $isRed ? ' style="top: 222px"' : '' ?>><?= $e($word) ?></div>
    <div class="cd-main">
      <div class="cd-photo">
        <div class="cd-photo-img"><?php if ($person['img'] !== ''): ?><img src="<?= $e($person['img']) ?>" alt=""><?php else: ?><img class="cd-sil" src="/admin/assets/images/player-silhouette.png" alt=""><?php endif; ?></div>
        <?php if ($person['sponsors']): ?>
          <div class="cd-sponsors"><?php foreach ($person['sponsors'] as $sp): ?><div class="cd-chip is-<?= $e(in_array($sp['slot'], ['home', 'away'], true) ? $sp['slot'] : 'third') ?>"><?php if (!empty($sp['text_only']) && in_array($sp['slot'], ['home', 'away'], true)): ?><span class="cd-text is-<?= $e($sp['slot']) ?>" style="--m:url('<?= $e($sp['url']) ?>')" role="img" aria-label="<?= $e($sp['name']) ?>"></span><?php else: ?><img src="<?= $e($sp['url']) ?>" alt="<?= $e($sp['name']) ?>"><?php endif; ?></div><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <?php if ($person['name'] !== ''): ?><div class="cd-name"><?php if ($person['num'] !== null): ?><em><?= (int)$person['num'] ?></em><?php endif; ?><?= $e($fullName) ?></div><?php endif; ?>
    </div>
    <div class="cd-giant"><?= $e($teamName !== '' ? explode(' ', $teamName)[0] : 'Saltcoats') ?></div>
  <?php endif; ?>
</div>
<script>
// Size the giant word: as wide as the poster allows, but never taller than ~420px.
(function () {
  var el = document.getElementById('cdWord');
  if (!el) return;
  function fit() {
    el.style.fontSize = '400px';
    var w = el.getBoundingClientRect().width;
    var size = Math.min(<?= $isRed ? 500 : 420 ?>, 400 * 900 / Math.max(w, 1));
    el.style.fontSize = size + 'px';
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
})();
</script>
</body>
</html>
