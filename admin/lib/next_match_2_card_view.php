<?php

declare(strict_types=1);

// The second "Next Match" design, in the same family as Kick off / Half time / Matchday: a
// photo with a gradient strip down the left, a cream panel along the bottom with the big title,
// fixture details and club badges, and the matchday / match ball sponsors sitting on the photo.
// Included by match_next_match_card.php with $data, $photo, $photoStyle, $featured, $sponsors,
// $e and $fontBase set.

$fx = $data['fixture'];
$opponent = trim((string)($fx['opponent'] ?? ''));
$left = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$right = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$kickoff = trim((string)($fx['kickoff_time'] ?? ''));
$koLabel = $kickoff !== '' ? str_replace(':', '.', substr($kickoff, 0, 5)) : '';
$mdSponsor = null;
$ballSponsor = null;
foreach ($featured as $f) {
    if (($f['label'] ?? '') === 'Matchday sponsor' && $mdSponsor === null) {
        $mdSponsor = $f;
    } elseif (($f['label'] ?? '') === 'Match ball sponsor' && $ballSponsor === null) {
        $ballSponsor = $f;
    }
}
$cream = static fn(string $url): string => $url === '' ? '<strong class="n2-badge-name">' . $e($opponent) . '</strong>' : '<span class="n2-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt=""></span>';
$sponsorBlock = static function (?array $s, string $cls) use ($e): string {
    if ($s === null) {
        return '';
    }
    $inner = $s['url'] !== ''
        ? '<img' . (!empty($s['mono']) ? ' class="is-mono"' : '') . ' src="' . $e($s['url']) . '" alt="' . $e($s['name']) . '">'
        : '<strong>' . $e($s['name']) . '</strong>';
    return '<div class="n2-sponsor ' . $cls . '">' . $inner . '</div>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Next match</title>
<style>
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
@font-face { font-family: "NM Serif"; src: url("<?= $fontBase ?>PlayfairDisplay-Italic.ttf"); font-weight: 400 900; font-style: italic; }
@font-face { font-family: "NM Georgia"; src: url("<?= $fontBase ?>georgia.ttf"); font-weight: 400; }
:root { --red: #6E1F2D; --maroon: #6E1F2D; --gold: #E2B72E; --cream: #FFF3E3; --ink: #2F2F2F; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background-color: #FFF8EE; color: var(--ink); font-family: "NM Inter", Arial, sans-serif;
  background-image: linear-gradient(rgba(110,31,45,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(110,31,45,.08) 1px, transparent 1px); background-size: 27px 27px; }
.n2-swoosh { position: absolute; inset: 0; width: 1080px; height: 1350px; }
.n2-photo-box { position: absolute; left: 590px; top: 0; width: 490px; height: 1350px; overflow: hidden; -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 75%); mask-image: linear-gradient(90deg, transparent 0, #000 75%); }
.n2-photo-box img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.n2-badges { position: absolute; left: 36px; top: 30px; display: flex; align-items: center; gap: 14px; z-index: 3; }
.n2-badges .n2-v { font-family: "NM Georgia", serif; font-style: italic; font-size: 22px; color: var(--ink); opacity: .7; }
.n2-badge-name { font-size: 26px; font-weight: 800; text-transform: uppercase; color: var(--ink); }
.n2-tint { display: inline-block; line-height: 0; background-color: var(--ink); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.n2-tint img { opacity: 0; display: block; height: 96px; width: auto; max-width: 110px; object-fit: contain; }
.n2-title { position: absolute; left: 40px; top: 120px; z-index: 3; color: var(--maroon); white-space: nowrap; }
.n2-title .n2-next { display: block; font-family: "NM Serif", Georgia, serif; font-style: italic; font-weight: 400; font-size: 200px; line-height: .7; letter-spacing: -.05em; margin-left: 0; position: relative; z-index: 1; }
.n2-title .n2-match { display: block; font-family: "NM Inter", Arial, sans-serif; font-weight: 800; font-size: 238px; line-height: .86; letter-spacing: -.07em; margin-top: -28px; margin-left: -8px; }
.n2-info { position: absolute; left: 40px; top: 425px; z-index: 3; display: flex; align-items: center; gap: 18px; font-family: "NM Inter", Arial, sans-serif; font-weight: 700; font-size: 32px; letter-spacing: .01em; text-transform: uppercase; white-space: nowrap; color: var(--ink); }
.n2-info i { display: block; width: 3px; height: 28px; background: var(--gold); }
.n2-sponsor { position: absolute; top: 975px; z-index: 4; width: 480px; text-align: center; }
.n2-sponsor--md { left: 40px; }
.n2-sponsor--ball { left: 560px; }
.n2-sponsor img { display: block; margin: 0 auto; width: auto; height: 190px; max-width: 100%; object-fit: contain; filter: drop-shadow(0 6px 18px rgba(0,0,0,.45)); }
.n2-sponsor img.is-mono { filter: brightness(0) invert(1) drop-shadow(0 6px 18px rgba(0,0,0,.45)); }
.n2-sponsor strong { display: block; font-size: 32px; font-weight: 800; color: var(--cream); }
.n2-sponsors { position: absolute; left: 0; right: 0; bottom: 0; height: 470px; padding: 0 40px 44px; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; z-index: 3; overflow: hidden;
  background:
    radial-gradient(60% 55% at 8% 100%, rgba(226,183,46,.75) 0%, rgba(226,183,46,0) 70%),
    radial-gradient(55% 60% at 96% 78%, rgba(150,38,58,.9) 0%, rgba(150,38,58,0) 70%),
    radial-gradient(45% 40% at 55% 105%, rgba(226,183,46,.35) 0%, rgba(226,183,46,0) 75%),
    linear-gradient(180deg, rgba(47,12,20,0) 0%, rgba(69,10,20,.78) 26%, rgba(47,12,20,.97) 100%); }
.n2-sponsors::before { content: ""; position: absolute; left: -10%; right: -10%; bottom: 70px; height: 150px; background: linear-gradient(100deg, rgba(226,183,46,0) 10%, rgba(226,183,46,.28) 40%, rgba(255,243,227,.12) 60%, rgba(226,183,46,0) 90%); transform: skewY(-7deg); filter: blur(14px); pointer-events: none; }
.n2-sponsors > * { position: relative; }
.n2-sponsors .n2-light { display: inline-block; line-height: 0; background-color: var(--cream); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.n2-sponsors .n2-light img { opacity: 0; display: block; height: 84px; width: auto; max-width: 200px; object-fit: contain; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <svg class="n2-swoosh" viewBox="0 0 1080 1350" aria-hidden="true"><defs><filter id="bl" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="9"/></filter></defs>
    <g fill="#6E1F2D" fill-opacity=".14" filter="url(#bl)">
      <path fill="#E2B72E" d="M520 0 C600 50 700 90 800 100 L860 0 Z"/>
      <path d="M-20 820 C120 740 330 700 520 640 C430 720 340 790 260 860 C170 930 80 990 -20 1020 Z"/>
      
    </g></svg>
  <div class="n2-photo-box"><?php if ($photo !== ''): ?><img src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?></div>
  <div class="n2-badges"><?= $cream((string)$left) ?><span class="n2-v">v</span><?= $cream((string)$right) ?></div>
  <div class="n2-title"><span class="n2-next">next</span><span class="n2-match">match</span></div>
  <div class="n2-info">
    <span><?= $e($data['date_label']) ?></span>
    <?php if ($koLabel !== ''): ?><i></i><span><?= $e($koLabel) ?></span><?php endif; ?>
    <i></i><span><?= $e($data['venue_label']) ?></span>
  </div>
  <?= $sponsorBlock($mdSponsor, 'n2-sponsor--md') ?>
  <?= $sponsorBlock($ballSponsor, 'n2-sponsor--ball') ?>
  <?php if ($sponsors): ?><div class="n2-sponsors"><?php foreach (array_slice($sponsors, 0, 5) as $s): ?><span class="n2-light" style="--m:url('<?= $e($s['url']) ?>')"><img src="<?= $e($s['url']) ?>" alt="<?= $e($s['name']) ?>"></span><?php endforeach; ?></div><?php endif; ?>
</div>
</body>
</html>
