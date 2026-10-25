<?php

declare(strict_types=1);

// The "Kick off" graphic: the photo across the top with a gradient strip down the left, the title run
// across the bottom of the photo with the matchday sponsor sitting on the line between "Kick" and
// "Off.", and a greyscale blur of the photo below. Included by match_next_match_card.php with $data,
// $photo, $photoStyle, $featured, $sponsors, $e and $fontBase set.

$isSecondHalf = ($photoType ?? 'kickoff') === 'second_half';
$words = $isSecondHalf ? ['S', 'econd', 'Half.'] : ['K', 'ick', 'Off.'];
$left = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$right = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$mdSponsor = null;
foreach ($featured as $f) {
    if (($f['label'] ?? '') === 'Matchday sponsor') {
        $mdSponsor = $f;
        break;
    }
}
$kickoff = trim((string)($data['fixture']['kickoff_time'] ?? ''));
$details = array_filter([strtoupper((string)($data['date_label'] ?? '')), $kickoff !== '' ? 'KO ' . substr($kickoff, 0, 5) : '', strtoupper((string)($data['venue_label'] ?? ''))]);
if ($isSecondHalf) {
    require_once __DIR__ . '/matchday_record.php';
    $htr = $pdo->query('SELECT half_time_home_score h, half_time_away_score a FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($htr['h'] ?? null) !== null && ($htr['a'] ?? null) !== null) {
        $hh = (int)$htr['h'];
        $ha = (int)$htr['a'];
    } else {
        $sum = matchday_record_summary($pdo, $fixtureId);
        $hh = (int)$sum['home_score'];
        $ha = (int)$sum['away_score'];
    }
    $homeName = $data['home'] ? 'Saltcoats Victoria' : (string)($data['fixture']['opponent'] ?? '');
    $awayName = $data['home'] ? (string)($data['fixture']['opponent'] ?? '') : 'Saltcoats Victoria';
    $details = ['HT ' . strtoupper($homeName) . ' ' . $hh . ' - ' . $ha . ' ' . strtoupper($awayName)];
}
$dark = static fn(string $url, string $alt = ''): string => '<span class="ko-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt="' . $e($alt) . '"></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isSecondHalf ? 'Second half' : 'Kick off' ?></title>
<style>
@font-face { font-family: "NM Script"; src: url("<?= $fontBase ?>PinyonScript-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; --deep: #450A14; --char: #2F2F2F; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: var(--char); color: var(--cream); font-family: "NM Inter", Arial, sans-serif; }
.ko-photo-box { position: absolute; left: 113px; top: 0; width: 967px; height: 795px; overflow: hidden; background: radial-gradient(120% 90% at 55% 40%, #6E1F2D 0%, #2F2F2F 78%); }
.ko-photo-box img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.ko-photo-box::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(47,47,47,.6) 0%, rgba(47,47,47,0) 42%); }
.ko-strip { position: absolute; left: 0; top: 0; width: 113px; height: 795px; background: linear-gradient(180deg, #E0C07A 0%, #E2B72E 30%, #6E1F2D 72%, #450A14 100%); }
.ko-strip::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .85; }
.ko-badges { position: absolute; right: 46px; top: 38px; display: flex; gap: 18px; z-index: 3; }
.ko-tint { display: inline-block; line-height: 0; background-color: var(--cream); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.ko-tint img { opacity: 0; display: block; }
.ko-badges img { height: 88px; width: auto; max-width: 100px; object-fit: contain; }
.ko-title { position: absolute; left: 186px; right: 46px; top: 632px; height: 150px; display: flex; align-items: flex-end; justify-content: flex-end; gap: 16px; z-index: 3; font-weight: 700; font-size: 104px; line-height: 1; letter-spacing: -.045em; text-shadow: 0 4px 24px rgba(47,47,47,.55); white-space: nowrap; }
.ko-k { font-family: "NM Script", cursive; font-weight: 400; font-size: 1.5em; letter-spacing: 0; line-height: .6; margin-right: -.05em; vertical-align: -.02em; }
.ko-mid { position: relative; flex: 0 0 auto; width: .3em; height: 0; margin: 0 -.02em .3em; border-bottom: .07em solid var(--cream); }
.ko-mid img { position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%); max-height: 112px; max-width: 360px; width: auto; object-fit: contain; box-shadow: 0 6px 20px rgba(47,47,47,.5); }
.ko-sponsor { position: absolute; left: 48px; bottom: 48px; z-index: 3; }
.ko-sponsor img { display: block; width: 400px; height: auto; max-height: 220px; object-fit: contain; box-shadow: 0 8px 24px rgba(47,47,47,.5); }
.ko-blur { position: absolute; left: 0; top: 795px; width: 968px; height: 555px; overflow: hidden; background: #3a3a3a; }
.ko-blur img { position: absolute; inset: -40px; width: calc(100% + 80px); height: calc(100% + 80px); object-fit: cover; object-position: center 75%; transform: scale(1.5); transform-origin: 50% 90%; filter: grayscale(1) blur(22px) contrast(1.1) brightness(.85); }
.ko-blur::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(47,47,47,.7) 0%, rgba(47,47,47,0) 55%); }
.ko-side { position: absolute; left: 968px; top: 795px; width: 112px; height: 555px; background: var(--cream); display: flex; flex-direction: column; }
.ko-cell { flex: 1 1 0; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 14px 8px; }
.ko-side .ko-tint { background-color: var(--char); }
.ko-side .ko-tint { max-width: 100%; max-height: 100%; }
.ko-side .ko-tint img { width: auto; height: auto; max-width: 96px; max-height: 118px; object-fit: contain; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="ko-photo-box"><?php if ($photo !== ''): ?><img src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?></div>
  <div class="ko-strip"></div>
  <div class="ko-badges">
    <?php if ($left !== ''): ?><?= $dark((string)$left) ?><?php endif; ?>
    <?php if ($right !== ''): ?><?= $dark((string)$right) ?><?php endif; ?>
  </div>
  <div class="ko-title"<?= $isSecondHalf ? ' style="font-size: 84px"' : '' ?>>
    <span><span class="ko-k"><?= $e($words[0]) ?></span><?= $e($words[1]) ?></span>
    <?php if (!$isSecondHalf): ?><div class="ko-mid"></div><?php endif; ?>
    <span><?= $e($words[2]) ?></span>
  </div>
  <div class="ko-blur"><?php if ($photo !== ''): ?><img src="<?= $e($photo) ?>" alt=""><?php endif; ?></div>
  <?php if ($mdSponsor && $mdSponsor['url'] !== ''): ?><div class="ko-sponsor"><img src="<?= $e($mdSponsor['url']) ?>" alt="<?= $e($mdSponsor['name']) ?>"></div><?php endif; ?>
  <div class="ko-side"><?php foreach (array_slice($sponsors, 0, 4) as $s): ?><div class="ko-cell"><?= $dark($s['url'], $s['name']) ?></div><?php endforeach; ?></div>
</div>
</body>
</html>
