<?php

declare(strict_types=1);

// Goal graphic: textured grey poster with a warm glow, a giant spaced-out GOAL behind the scorer's
// cut-out photo, the name down the left edge (or big and centred when there is no photo), and the
// player's home / away sponsors on a panel at the bottom. Included from event_card_view.php with
// $ev, $person, $e, $fontBase.

$full = $ev !== null && trim((string)$ev['player_name']) !== '' ? trim((string)$ev['player_name']) : $person['name'];
$hasPhoto = $person['img'] !== '';
$sponsors = $person['sponsors'];
$single = count($sponsors) === 1;
$nameLen = mb_strlen($full);
$vertSize = $nameLen > 26 ? 46 : ($nameLen > 20 ? 52 : 57);
$bigSize = $nameLen > 22 ? 66 : 77;
// Place the photo by the visible subject (not the file edges): scaled so the subject is ~640px wide,
// head top at y=350, centred, and running down behind the sponsor panel. Works for cut-outs or plain photos.
$photoBox = null;
if ($hasPhoto) {
    $fileOnDisk = dirname(__DIR__) . '/uploads/players/' . basename(rawurldecode((string)$person['img']));
    $info = @getimagesize($fileOnDisk);
    if ($info) {
        [$iw, $ih] = $info;
        $x0 = 0; $y0 = 0; $x1 = $iw; $y1 = $ih;
        if ($info[2] === IMAGETYPE_PNG && ($im = @imagecreatefrompng($fileOnDisk))) {
            $sw = 120; $sh = max(1, (int)round($ih * $sw / $iw));
            $small = imagecreatetruecolor($sw, $sh);
            imagealphablending($small, false); imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagecopyresampled($small, $im, 0, 0, 0, 0, $sw, $sh, $iw, $ih);
            $minX = $sw; $minY = $sh; $maxX = -1; $maxY = -1;
            $colN = array_fill(0, $sw, 0); $rowN = array_fill(0, $sh, 0);
            for ($yy = 0; $yy < $sh; $yy++) {
                for ($xx = 0; $xx < $sw; $xx++) {
                    if (((imagecolorat($small, $xx, $yy) >> 24) & 0x7F) < 40) { // clearly opaque, ignores soft glows
                        $colN[$xx]++; $rowN[$yy]++;
                    }
                }
            }
            // ignore stray specks: a column / row only counts with a few solid pixels
            for ($xx = 0; $xx < $sw; $xx++) { if ($colN[$xx] >= 3) { $minX = min($minX, $xx); $maxX = max($maxX, $xx); } }
            for ($yy = 0; $yy < $sh; $yy++) { if ($rowN[$yy] >= 3) { $minY = min($minY, $yy); $maxY = max($maxY, $yy); } }
            if ($maxX >= 0 && $maxY >= 0) {
                $x0 = (int)floor($minX * $iw / $sw); $x1 = (int)ceil(($maxX + 1) * $iw / $sw);
                $y0 = (int)floor($minY * $ih / $sh); $y1 = (int)ceil(($maxY + 1) * $ih / $sh);
            }
        }
        $subW = max(1, $x1 - $x0); $subH = max(1, $y1 - $y0);
        $headTop = 350; $panelTop = $sponsors ? 1127 : 1350;
        $scale = max(640 / $subW, ($panelTop - $headTop) / $subH);
        $scale = min($scale, 920 / $subW);
        $photoBox = [
            'w' => (int)round($iw * $scale), 'h' => (int)round($ih * $scale),
            'left' => (int)round(540 - (($x0 + $x1) / 2) * $scale),
            'top' => (int)round($headTop - $y0 * $scale),
        ];
    }
}
// Split a long name over two lines for the big centred version.
$bigName = $full;
if (!$hasPhoto && mb_strpos($full, ' ') !== false) {
    $parts = explode(' ', $full);
    $last = array_pop($parts);
    $bigName = implode(' ', $parts) . "\n" . $last;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Goal</title>
<style>
@font-face { font-family: "NM Anton"; src: url("<?= $fontBase ?>Anton-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-Bold.ttf"); font-weight: 700; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-ExtraBold.ttf"); font-weight: 800; }
@font-face { font-family: "NM Mono"; src: url("<?= $fontBase ?>SpaceMono-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; --deep: #450A14; --char: #2F2F2F; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; font-family: "NM Sans", Arial, sans-serif;
  background:
    radial-gradient(62% 42% at 104% 36%, rgba(196,98,96,.62) 0%, rgba(196,98,96,0) 72%),
    radial-gradient(55% 38% at -6% 100%, rgba(226,150,60,.6) 0%, rgba(226,150,60,0) 72%),
    radial-gradient(40% 26% at 106% -4%, rgba(226,183,46,.5) 0%, rgba(226,183,46,0) 72%),
    linear-gradient(180deg, #9a9896 0%, #a7a5a3 100%); }
#nextMatchCard::before { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .75; pointer-events: none; z-index: 5; }
.gl-guide { position: absolute; border: 0 dashed rgba(226,183,46,.6); z-index: 1; }
.gl-own { position: absolute; left: 286px; top: 22px; width: 506px; height: 126px; display: flex; align-items: center; justify-content: center; font-family: "NM Anton", Impact, sans-serif; font-size: 112px; line-height: 1; letter-spacing: .3em; text-indent: .3em; text-transform: uppercase; color: #4a0e1a; opacity: .94; z-index: 2; }
.gl-arrow { position: absolute; left: 82px; top: 54px; width: 38px; height: 38px; z-index: 6; }
.gl-word { position: absolute; left: 0; right: 0; top: 190px; text-align: center; font-family: "NM Anton", Impact, sans-serif; font-weight: 400; line-height: .95; letter-spacing: .3em; text-indent: .3em; text-transform: uppercase; white-space: nowrap; color: #4a0e1a; opacity: .94; z-index: 2; }
.gl-photo { position: absolute; left: 50%; transform: translateX(-50%); bottom: <?= $sponsors ? 223 : 0 ?>px; height: <?= $sponsors ? 800 : 940 ?>px; width: auto; z-index: 3; }
.gl-vert { position: absolute; left: 14px; bottom: <?= $sponsors ? 30 : 40 ?>px; writing-mode: vertical-rl; font-weight: 700; font-size: <?= $vertSize ?>px; letter-spacing: .07em; text-transform: uppercase; color: #4a0e1a; line-height: 1; white-space: nowrap; z-index: 4; }
.gl-big { position: absolute; left: 60px; right: 60px; top: 760px; text-align: center; font-weight: 700; font-size: <?= $bigSize ?>px; line-height: .96; letter-spacing: .01em; text-transform: uppercase; color: #4a0e1a; white-space: pre-line; z-index: 4; }
.gl-sponsors { position: absolute; left: 261px; width: 565px; bottom: 0; height: 223px; display: flex; z-index: 6; }
.gl-tile { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; padding: 20px 18px 16px; gap: 10px; }
.gl-tile.is-home, .gl-tile.is-third { background: #6E1F2D; color: #E2B72E; }
.gl-tile.is-away { background: #E2B72E; color: #6E1F2D; }
.gl-lab { font-family: "NM Mono", monospace; font-weight: 400; font-size: 14px; letter-spacing: .26em; text-indent: .26em; text-transform: uppercase; white-space: nowrap; }
.gl-logo { flex: 1; min-height: 0; width: 100%; display: flex; align-items: center; justify-content: center; }
.gl-logo img { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; }
.gl-empty { position: absolute; left: 0; right: 0; top: 620px; text-align: center; font-size: 40px; font-weight: 700; color: #4a0e1a; z-index: 4; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="gl-guide" style="left: 286px; top: 22px; width: 506px; height: 126px; border-width: 1.5px;"></div>
  <div class="gl-guide" style="left: 271px; top: 440px; width: 540px; height: 0; border-top-width: 1.5px;"></div>
  <div class="gl-guide" style="left: 271px; top: 440px; width: 0; height: 690px; border-left-width: 1.5px;"></div>
  <div class="gl-guide" style="left: 810px; top: 440px; width: 0; height: 690px; border-left-width: 1.5px;"></div>
  <svg class="gl-arrow" viewBox="0 0 40 40" aria-hidden="true"><path d="M6 6 L26 26 M26 12 L26 26 L12 26" fill="none" stroke="#E2B72E" stroke-width="7" stroke-linecap="square"/></svg>
  <?php if ($ev === null): ?>
    <div class="gl-empty">Choose a goal</div>
  <?php else: ?>
    <?php if ($ev['own_goal']): ?><div class="gl-own">Own</div><?php endif; ?>
    <div class="gl-word" id="glWord">Goal</div>
    <?php if ($hasPhoto): ?>
      <img class="gl-photo"<?= $photoBox ? ' data-placed="1"' : '' ?> src="<?= $e($person['img']) ?>" alt=""<?= $photoBox ? ' style="left:' . $photoBox['left'] . 'px;top:' . $photoBox['top'] . 'px;width:' . $photoBox['w'] . 'px;height:' . $photoBox['h'] . 'px;transform:none;bottom:auto"' : '' ?>>
      <div class="gl-vert"><?= $e($full) ?></div>
    <?php else: ?>
      <img class="gl-photo" src="/admin/assets/images/player-silhouette.png" alt="" style="opacity:.0">
      <div class="gl-big"><?= $e($bigName) ?></div>
    <?php endif; ?>
    <?php if ($sponsors): ?>
      <div class="gl-sponsors">
        <?php foreach ($sponsors as $sp): $slot = $sp['slot'] === 'away' ? 'away' : ($sp['slot'] === 'home' ? 'home' : 'third'); ?>
          <div class="gl-tile is-<?= $slot ?>">
            <span class="gl-lab"><?= $single ? 'Proudly sponsored by' : ($slot === 'away' ? 'Away sponsor' : ($slot === 'home' ? 'Home sponsor' : 'Sponsor')) ?></span>
            <div class="gl-logo"><img src="<?= $e($sp['url']) ?>" alt="<?= $e($sp['name']) ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
// Fit GOAL to the poster width whatever the font renders at.
(function () {
  var el = document.getElementById('glWord');
  if (!el) return;
  function fit() {
    el.style.fontSize = '300px';
    var r = document.createRange(); r.selectNodeContents(el);
    var w = r.getBoundingClientRect().width;
    if (w > 0) el.style.fontSize = (300 * 840 / w) + 'px';
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
})();
</script>
</body>
</html>
