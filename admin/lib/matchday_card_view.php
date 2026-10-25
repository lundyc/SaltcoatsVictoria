<?php

declare(strict_types=1);

// The "Matchday" graphic: a grid of white and colour blocks, a greyscale crop of the photo
// top right, a big black title on a white band, and the photo in full colour at the bottom.
// Included by match_next_match_card.php with $data, $photo, $photoStyle, $featured, $sponsors,
// $e and $fontBase set.

$fx = $data['fixture'];
$opponent = trim((string)($fx['opponent'] ?? ''));
$kickoff = trim((string)($fx['kickoff_time'] ?? ''));
$kickoffLabel = $kickoff !== '' ? 'Kick off ' . substr($kickoff, 0, 5) : '';
// Home team on top, away team below.
$homeBadge = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$awayBadge = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$mdSponsor = null;
foreach ($featured as $f) {
    if (($f['label'] ?? '') === 'Matchday sponsor') {
        $mdSponsor = $f;
        break;
    }
}
$dark = static fn(string $url, string $colour): string => $url === '' ? '' : '<span class="md-badge" style="--m:url(\'' . $e($url) . '\');background-color:' . $colour . '"><img src="' . $e($url) . '" alt=""></span>';
$tint = static fn(string $url, string $alt = ''): string => '<span class="md-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt="' . $e($alt) . '"></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Matchday</title>
<style>
@font-face { font-family: "NM Script"; src: url("<?= $fontBase ?>PinyonScript-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: #FFF3E3; color: #2F2F2F; font-family: "NM Inter", Arial, sans-serif; }
.md-white { position: absolute; left: 0; top: 0; width: 242px; height: 245px; background: #FFF3E3; }
.md-colour { position: absolute; left: 0; top: 245px; width: 242px; height: 244px; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); overflow: hidden; }
.md-colour::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .85; }
.md-badge { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); display: block; line-height: 0; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.md-badge img { opacity: 0; display: block; height: 142px; width: auto; max-width: 160px; object-fit: contain; }
.md-grey { position: absolute; left: 242px; top: 0; width: 838px; height: 489px; overflow: hidden; background: #2F2F2F; }
.md-grey::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(0,0,0,.92) 0%, rgba(0,0,0,.7) 34%, rgba(0,0,0,0) 70%); }
.md-grey img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 30%; transform: scale(1.35); transform-origin: 50% 35%; filter: grayscale(1) contrast(1.1) brightness(.9); }
.md-band { position: absolute; left: 0; right: 0; top: 489px; height: 352px; background: #FFF3E3; }
.md-title { position: absolute; left: 14px; top: 548px; white-space: nowrap; line-height: 1; letter-spacing: -.035em; }
.md-title__line { display: inline-block; font-size: 220px; font-weight: 700; }
.md-title__init { font-family: "NM Script", cursive; font-weight: 400; letter-spacing: 0; font-size: 1.5em; line-height: .6; vertical-align: -.02em; margin-right: -.03em; }
.md-ko { position: absolute; right: 56px; top: 788px; font-weight: 700; font-size: 25px; letter-spacing: -.01em; text-transform: uppercase; white-space: nowrap; }
.md-versus { position: absolute; left: 60px; top: 788px; font-weight: 700; font-size: 25px; letter-spacing: -.01em; text-transform: uppercase; white-space: nowrap; }
.md-versus b { color: var(--maroon); }
.md-photo-box { position: absolute; left: 0; top: 841px; width: 1080px; height: 509px; overflow: hidden; background: radial-gradient(120% 90% at 50% 40%, #6E1F2D 0%, #2F2F2F 78%); }
.md-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.md-photo-box::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(0,0,0,.78) 0%, rgba(0,0,0,.4) 32%, rgba(0,0,0,0) 62%); }
.md-sponsor { position: absolute; left: 0; right: 0; bottom: 36px; display: flex; flex-direction: column; align-items: center; gap: 12px; z-index: 2; color: #FFF3E3; text-shadow: 0 2px 10px rgba(0,0,0,.7); }
.md-sponsor span { font-weight: 700; font-size: 22px; letter-spacing: .14em; text-transform: uppercase; }
.md-sponsor img { display: block; width: 300px; height: 150px; object-fit: cover; box-shadow: 0 6px 20px rgba(0,0,0,.45); }
.md-footer { position: absolute; left: 242px; width: 838px; top: 389px; height: 100px; display: grid; grid-template-columns: repeat(4, 1fr); align-items: center; padding: 0 30px; gap: 14px; z-index: 2; }
.md-footer .md-tint { display: inline-block; line-height: 0; justify-self: center; background-color: #FFF3E3; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.md-tint img { opacity: 0; display: block; width: auto; height: auto; max-width: 150px; max-height: 70px; object-fit: contain; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="md-white"><?= $dark((string)$homeBadge, '#2F2F2F') ?></div>
  <div class="md-colour"><?= $dark((string)$awayBadge, '#2F2F2F') ?></div>
  <div class="md-grey"><?php if ($photo !== ''): ?><img src="<?= $e($photo) ?>" alt=""><?php endif; ?></div>
  <?php if ($sponsors): ?><div class="md-footer"><?php foreach (array_slice($sponsors, 0, 4) as $s): ?><?= $tint($s['url'], $s['name']) ?><?php endforeach; ?></div><?php endif; ?>
  <div class="md-band"></div>

  <div class="md-title"><span class="md-title__line" id="mdTitle"><span class="md-title__init">M</span>atchday</span></div>
  <div class="md-versus"><b>VS</b> <?= $e($opponent) ?> @ <?= $e($data['venue_label']) ?></div>
  <?php if ($kickoffLabel !== ''): ?><div class="md-ko"><?= $e($kickoffLabel) ?></div><?php endif; ?>

  <div class="md-photo-box"><?php if ($photo !== ''): ?><img class="md-photo" src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?></div>
  <?php if ($mdSponsor && $mdSponsor['url'] !== ''): ?><div class="md-sponsor"><span>Matchday sponsor</span><img src="<?= $e($mdSponsor['url']) ?>" alt="<?= $e($mdSponsor['name']) ?>"></div><?php endif; ?>
</div>
<script>
// Fit the title to the card width whatever the fonts render at.
(function () {
  var el = document.getElementById('mdTitle');
  function fit() {
    var fs = parseFloat(getComputedStyle(el).fontSize);
    var w = el.getBoundingClientRect().width;
    if (w > 0) { el.style.fontSize = (fs * 1040 / w) + 'px'; }
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
})();
</script>
</body>
</html>
