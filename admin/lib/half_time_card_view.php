<?php

declare(strict_types=1);

// The "Half Time" graphic: white score header over a full-bleed photo, with a gradient block
// and a greyscale sponsor strip down the right. Included by match_next_match_card.php with
// $pdo, $fixtureId, $data, $photo, $photoStyle, $sponsors, $e and $fontBase set.

require_once __DIR__ . '/matchday_record.php';

$htRow = $pdo->query('SELECT half_time_home_score h, half_time_away_score a FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: [];
if (($htRow['h'] ?? null) !== null && ($htRow['a'] ?? null) !== null) {
    $homeScore = (int)$htRow['h'];
    $awayScore = (int)$htRow['a'];
} else {
    $sum = matchday_record_summary($pdo, $fixtureId);
    $homeScore = (int)$sum['home_score'];
    $awayScore = (int)$sum['away_score'];
}
// The header is white, so tint the white badges dark grey.
$homeBadge = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$awayBadge = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$badgeHtml = static fn(string $url) => $url === '' ? '<span></span>' : '<span class="ht-badge" style="--m:url(\'' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '\')"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt=""></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Half time</title>
<style>
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-Bold.ttf"); font-weight: 700; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: #2F2F2F; font-family: "NM Inter", Arial, sans-serif; }
.ht-photo { position: absolute; left: 0; right: 0; top: 200px; bottom: 0; width: 100%; height: calc(100% - 200px); object-fit: cover; object-position: center 22%; }
.ht-photo-wrap { position: absolute; left: 0; right: 0; top: 200px; bottom: 0; overflow: hidden; background: radial-gradient(120% 90% at 40% 40%, #6E1F2D 0%, #2F2F2F 78%); }
.ht-photo-wrap .ht-photo { top: 0; height: 100%; }
.ht-header { position: absolute; left: 0; top: 0; width: 880px; height: 200px; background: #FFF3E3; }
.ht-title { position: absolute; left: 52px; top: 50%; transform: translateY(-50%); font-family: "NM Sans", Arial, sans-serif; font-weight: 700; font-size: 63px; line-height: .98; letter-spacing: -.02em; color: #2F2F2F; }
.ht-score { position: absolute; left: 722px; top: 50%; transform: translateY(-50%); display: grid; grid-template-columns: 66px 56px; row-gap: 8px; align-items: center; }
.ht-badge { display: block; justify-self: center; line-height: 0; background-color: #2F2F2F; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.ht-badge img { opacity: 0; display: block; height: 54px; width: auto; max-width: 62px; object-fit: contain; }
.ht-score b { padding-left: 20px; font-weight: 800; font-size: 58px; line-height: 1; letter-spacing: -.03em; color: #2F2F2F; }
.ht-gradient { position: absolute; right: 0; top: 0; width: 200px; height: 200px; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); }
.ht-gradient::after, .ht-strip::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .85; }
.ht-strip { position: absolute; right: 0; top: 200px; bottom: 0; width: 200px; -webkit-backdrop-filter: grayscale(1) brightness(.5) contrast(1.15); backdrop-filter: grayscale(1) brightness(.5) contrast(1.15); background: rgba(47,47,47,.35); }
.ht-strip::after { opacity: .5; }
.ht-sponsors { position: absolute; right: 0; top: 232px; width: 200px; display: flex; flex-direction: column; align-items: center; gap: 30px; z-index: 2; }
.md-tint { display: inline-block; line-height: 0; background-color: #FFF3E3; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.md-tint img { opacity: 0; display: block; width: 150px; height: auto; max-height: 90px; object-fit: contain; }
.md-sponsor { position: absolute; z-index: 3; width: 400px; text-align: left; }
.md-sponsor small { display: block; margin-bottom: 10px; color: #E2B72E; font-weight: 800; font-size: 20px; letter-spacing: .14em; text-transform: uppercase; text-shadow: 0 2px 10px rgba(0,0,0,.7); }
.md-sponsor img { display: block; width: 400px; height: auto; max-height: 220px; max-width: 400px; object-fit: contain; box-shadow: 0 8px 24px rgba(47,47,47,.5); }
.md-sponsor img.is-mono { filter: brightness(0) invert(1) drop-shadow(0 4px 14px rgba(0,0,0,.55)); }
.md-sponsor strong { display: block; font-size: 30px; font-weight: 800; color: #FFF3E3; text-shadow: 0 3px 14px rgba(0,0,0,.7); }
.md-sponsor--bl { left: 48px; bottom: 48px; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="ht-photo-wrap">
    <?php if ($photo !== ''): ?><img class="ht-photo" src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?>
  </div>
  <div class="ht-strip"></div>
  <div class="ht-header">
    <div class="ht-title">Half<br>time.</div>
    <div class="ht-score">
      <?= $badgeHtml((string)$homeBadge) ?><b><?= $homeScore ?></b>
      <?= $badgeHtml((string)$awayBadge) ?><b><?= $awayScore ?></b>
    </div>
  </div>
  <div class="ht-gradient"></div>
  <?php if ($sponsors): ?>
    <div class="ht-sponsors">
      <?php foreach ($sponsors as $s): ?><span class="md-tint" style="--m:url('<?= $e($s['url']) ?>')"><img src="<?= $e($s['url']) ?>" alt="<?= $e($s['name']) ?>"></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php $mdSponsor = null; foreach (($data['featured'] ?? []) as $f) { if (($f['label'] ?? '') === 'Matchday sponsor') { $mdSponsor = $f; break; } } ?>
  <?php if ($mdSponsor !== null): ?>
    <div class="md-sponsor md-sponsor--bl"><?php if ($mdSponsor['url'] !== ''): ?><img<?= !empty($mdSponsor['mono']) ? ' class="is-mono"' : '' ?> src="<?= $e($mdSponsor['url']) ?>" alt="<?= $e($mdSponsor['name']) ?>"><?php else: ?><strong><?= $e($mdSponsor['name']) ?></strong><?php endif; ?></div>
  <?php endif; ?>
</div>
</body>
</html>
