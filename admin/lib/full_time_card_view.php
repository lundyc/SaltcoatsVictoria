<?php

declare(strict_types=1);

// The "Full Time" graphic: full-bleed photo with a dark score band along the bottom, a gradient
// block bottom-left and a greyscale sponsor strip up the left. Same title, badge and score styles
// as the Half Time graphic. Included by match_next_match_card.php with $pdo, $fixtureId, $data,
// $photo, $photoStyle, $sponsors, $e and $fontBase set.

require_once __DIR__ . '/matchday_record.php';

$mode = in_array($photoType ?? 'full_time', ['kickoff', 'second_half'], true) ? $photoType : 'full_time';
if ($mode === 'kickoff') {
    $homeScore = 0;
    $awayScore = 0;
} else {
    $scoreCols = $mode === 'second_half' ? ['half_time_home_score', 'half_time_away_score'] : ['full_time_home_score', 'full_time_away_score'];
    $ftRow = $pdo->query('SELECT ' . $scoreCols[0] . ' h, ' . $scoreCols[1] . ' a FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($ftRow['h'] ?? null) !== null && ($ftRow['a'] ?? null) !== null) {
        $homeScore = (int)$ftRow['h'];
        $awayScore = (int)$ftRow['a'];
    } else {
        $sum = matchday_record_summary($pdo, $fixtureId);
        $homeScore = (int)$sum['home_score'];
        $awayScore = (int)$sum['away_score'];
    }
}
$titleHtml = ['kickoff' => 'Kick<br>off.', 'second_half' => 'Second<br>half.', 'full_time' => 'Full<br>time.'][$mode];
$homeBadge = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$awayBadge = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$badgeHtml = static fn(string $url) => $url === '' ? '<span></span>' : '<span class="ft-badge" style="--m:url(\'' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '\')"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt=""></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e(strip_tags(str_replace('<br>', ' ', $titleHtml))) ?></title>
<style>
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-Bold.ttf"); font-weight: 700; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: #2F2F2F; font-family: "NM Inter", Arial, sans-serif; }
.ft-photo-wrap { position: absolute; left: 0; right: 0; top: 0; bottom: 200px; overflow: hidden; background: radial-gradient(120% 90% at 55% 40%, #6E1F2D 0%, #2F2F2F 78%); }
.ft-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.ft-photo-wrap::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(47,47,47,.25) 0%, rgba(47,47,47,0) 40%, rgba(47,47,47,.35) 100%); }
.ft-strip { position: absolute; left: 0; top: 0; bottom: 200px; width: 200px; -webkit-backdrop-filter: grayscale(1) brightness(.45) contrast(1.15); backdrop-filter: grayscale(1) brightness(.45) contrast(1.15); background: rgba(47,47,47,.4); }
.ft-band { position: absolute; left: 200px; right: 0; bottom: 0; height: 200px; background: #2F2F2F; }
.ft-band::before { content: ""; position: absolute; inset: 0; background: radial-gradient(90% 140% at 35% 50%, rgba(255,243,227,.07) 0%, rgba(255,243,227,0) 70%); }
.ft-gradient { position: absolute; left: 0; bottom: 0; width: 200px; height: 200px; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); }
.ft-gradient::after, .ft-strip::after, .ft-band::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .85; pointer-events: none; }
.ft-strip::after { opacity: .5; }
.ft-band::after { opacity: .35; }
.ft-title { position: absolute; left: 252px; bottom: 100px; transform: translateY(50%); font-family: "NM Sans", Arial, sans-serif; font-weight: 700; font-size: 63px; line-height: .98; letter-spacing: -.02em; color: #FFF3E3; z-index: 2; }
.ft-score { position: absolute; left: 878px; bottom: 100px; transform: translateY(50%); display: grid; grid-template-columns: 66px 56px; row-gap: 8px; align-items: center; z-index: 2; }
.ft-badge { display: block; justify-self: center; line-height: 0; background-color: #FFF3E3; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.ft-badge img { opacity: 0; display: block; height: 54px; width: auto; max-width: 62px; object-fit: contain; }
.ft-score b { padding-left: 20px; font-weight: 800; font-size: 58px; line-height: 1; letter-spacing: -.03em; color: #FFF3E3; }
.ft-sponsors { position: absolute; left: 0; bottom: 230px; width: 200px; display: flex; flex-direction: column; align-items: center; gap: 28px; z-index: 2; }
.md-tint { display: inline-block; line-height: 0; background-color: #FFF3E3; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.md-tint img { opacity: 0; display: block; width: 150px; height: auto; max-height: 90px; object-fit: contain; }
.md-sponsor { position: absolute; z-index: 3; width: 300px; text-align: right; }
.md-sponsor small { display: block; margin-bottom: 10px; color: #E2B72E; font-weight: 800; font-size: 20px; letter-spacing: .14em; text-transform: uppercase; text-shadow: 0 2px 10px rgba(0,0,0,.7); }
.md-sponsor img { display: block; max-width: 100%; max-height: 130px; width: auto; height: auto; object-fit: contain; margin-left: auto; filter: drop-shadow(0 4px 14px rgba(0,0,0,.55)); }
.md-sponsor img.is-mono { filter: brightness(0) invert(1) drop-shadow(0 4px 14px rgba(0,0,0,.55)); }
.md-sponsor strong { display: block; font-size: 30px; font-weight: 800; color: #FFF3E3; text-shadow: 0 3px 14px rgba(0,0,0,.7); }
.md-sponsor--tr { right: 50px; top: 50px; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="ft-photo-wrap">
    <?php if ($photo !== ''): ?><img class="ft-photo" src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?>
  </div>
  <div class="ft-strip"></div>
  <div class="ft-band"></div>
  <div class="ft-gradient"></div>
  <?php if ($sponsors): ?>
    <div class="ft-sponsors">
      <?php foreach ($sponsors as $s): ?><span class="md-tint" style="--m:url('<?= $e($s['url']) ?>')"><img src="<?= $e($s['url']) ?>" alt="<?= $e($s['name']) ?>"></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="ft-title"><?= $titleHtml ?></div>
  <div class="ft-score">
    <?= $badgeHtml((string)$homeBadge) ?><b><?= $homeScore ?></b>
    <?= $badgeHtml((string)$awayBadge) ?><b><?= $awayScore ?></b>
  </div>
  <?php $mdSponsor = null; foreach (($data['featured'] ?? []) as $f) { if (($f['label'] ?? '') === 'Matchday sponsor') { $mdSponsor = $f; break; } } ?>
  <?php if ($mdSponsor !== null): ?>
    <div class="md-sponsor md-sponsor--tr"><small>Matchday sponsor</small><?php if ($mdSponsor['url'] !== ''): ?><img<?= !empty($mdSponsor['mono']) ? ' class="is-mono"' : '' ?> src="<?= $e($mdSponsor['url']) ?>" alt="<?= $e($mdSponsor['name']) ?>"><?php else: ?><strong><?= $e($mdSponsor['name']) ?></strong><?php endif; ?></div>
  <?php endif; ?>
</div>
</body>
</html>
