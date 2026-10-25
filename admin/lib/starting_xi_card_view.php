<?php

declare(strict_types=1);

// The "Starting XI" graphic. Included by match_next_match_card.php with $pdo, $fixtureId, $data,
// $photo, $photoStyle, $sponsors, $e and $fontBase set. Same look as the Matchday graphic,
// with the saved Saltcoats line-up listed down the left.

require_once __DIR__ . '/matchday_record.php';

$fx = $data['fixture'];
$opponent = trim((string)($fx['opponent'] ?? ''));
$left = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$right = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];

$record = matchday_record_load($pdo, $fixtureId);
$starters = [];
$bench = [];
foreach (($record['lineups']['svfc'] ?? []) as $row) {
    $name = trim((string)$row['player_name']);
    if ($name === '') {
        continue;
    }
    $entry = ['name' => $name, 'captain' => !empty($row['is_captain']), 'num' => $row['shirt_number'] !== null ? (int)$row['shirt_number'] : null];
    if ((int)$row['is_starting'] === 1) {
        $starters[] = $entry;
    } else {
        $bench[] = $entry;
    }
}
$starters = array_slice($starters, 0, 11);

/** Surname for each player; "J. Gomes" style only where two players in the squad share a surname. */
$surnameOf = static function (string $name): array {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = count($parts) > 1 ? array_shift($parts) : '';
    return [$first, implode(' ', $parts) !== '' ? implode(' ', $parts) : $name];
};
$allNames = array_merge($starters, $bench);
$count = [];
foreach ($allNames as $p) {
    $sn = mb_strtolower($surnameOf($p['name'])[1]);
    $count[$sn] = ($count[$sn] ?? 0) + 1;
}
$display = static function (string $name, bool $always = false) use ($surnameOf, $count, $e): string {
    [$first, $sur] = $surnameOf($name);
    if ($first !== '' && ($always || ($count[mb_strtolower($sur)] ?? 0) > 1)) {
        return '<span class="ini">' . $e(mb_strtoupper(mb_substr($first, 0, 1))) . '.</span> ' . $e($sur);
    }
    return $e($sur);
};
$mdSponsor = null;
foreach ($featured as $f) {
    if (($f['label'] ?? '') === 'Matchday sponsor') {
        $mdSponsor = $f;
        break;
    }
}
$subNames = array_map(static fn(array $p): string => $display($p['name']), $bench);
$subRows = array_chunk($subNames, 3);
$tint = static fn(string $url, string $alt = ''): string => '<span class="md-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt="' . $e($alt) . '"></span>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Starting XI</title>
<style>
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-Bold.ttf"); font-weight: 700; }
@font-face { font-family: "NM Sans"; src: url("<?= $fontBase ?>Montserrat-SemiBold.ttf"); font-weight: 600; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: #2F2F2F; color: #FFF3E3; font-family: "NM Sans", Arial, sans-serif; }
.xi-pattern { position: absolute; inset: 0; background: repeating-linear-gradient(60deg, rgba(255,243,227,.045) 0 1px, transparent 1px 9px), repeating-linear-gradient(-60deg, rgba(255,243,227,.03) 0 1px, transparent 1px 11px); }
.xi-photo-box { position: absolute; left: 477px; top: 0; width: 603px; height: 873px; overflow: hidden; background: radial-gradient(100% 80% at 50% 30%, #6E1F2D 0%, #2F2F2F 80%); }
.xi-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.xi-gradient { position: absolute; left: 0; top: 873px; width: 477px; height: 235px; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); }
.xi-gradient::after, .xi-grain::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .85; pointer-events: none; }
.xi-grey { position: absolute; left: 477px; top: 1108px; width: 603px; height: 242px; overflow: hidden; background: #2F2F2F; }
.xi-grey::after { content: ""; position: absolute; inset: 0; background: linear-gradient(0deg, rgba(0,0,0,.92) 0%, rgba(0,0,0,.72) 40%, rgba(0,0,0,.18) 100%); }
.xi-grey img { position: absolute; left: 0; top: 0; width: 100%; height: 100%; object-fit: cover; object-position: 60% 60%; transform: scale(2.4); transform-origin: 62% 70%; filter: grayscale(1) contrast(1.15) brightness(.85); }
.xi-badges { position: absolute; left: 44px; top: 40px; display: flex; align-items: center; gap: 14px; }
.md-tint { display: inline-block; line-height: 0; background-color: #FFF3E3; -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.md-tint img { opacity: 0; display: block; }
.xi-badges img { height: 84px; width: auto; max-width: 96px; object-fit: contain; }
.xi-title { position: absolute; left: 44px; top: 138px; font-weight: 700; font-size: 54px; line-height: .98; letter-spacing: -.035em; }
.xi-list { position: absolute; left: 0; width: 440px; top: 276px; list-style: none; text-align: right; }
.xi-list li { position: relative; display: flex; align-items: flex-start; justify-content: flex-end; height: 53px; font-weight: 700; font-size: 50px; line-height: 1; letter-spacing: -.045em; white-space: nowrap; }
.xi-list li i { position: absolute; left: 44px; top: 18px; width: 56px; text-align: left; font-style: normal; font-weight: 700; font-size: 28px; letter-spacing: 0; color: var(--gold); line-height: 1; }
.xi-list .ini { font-size: 28px; font-weight: 700; letter-spacing: 0; color: var(--gold); margin-right: 3px; align-self: flex-start; }
.xi-subs .ini { font-size: 17px; color: var(--gold); letter-spacing: 0; }
.xi-cap { display: inline-block; width: 12px; height: 26px; margin: 12px 0 0 12px; background: linear-gradient(180deg, var(--maroon) 0 50%, var(--gold) 50% 100%); }
.xi-subs-h { position: absolute; left: 517px; top: 906px; font-weight: 700; font-size: 44px; line-height: 1; letter-spacing: -.03em; color: var(--gold); }
.xi-subs { position: absolute; left: 517px; top: 976px; font-weight: 700; font-size: 25px; line-height: 33px; letter-spacing: -.01em; white-space: nowrap; }
.xi-subs b { color: var(--gold); font-weight: 700; margin: 0 6px; }
.xi-sponsor { position: absolute; left: 0; top: 1108px; width: 477px; height: 242px; overflow: hidden; }
.xi-sponsor img { display: block; width: 100%; height: 100%; object-fit: cover; }
.xi-sponsors { position: absolute; left: 477px; top: 1108px; width: 603px; height: 242px; display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; z-index: 2; filter: drop-shadow(0 2px 8px rgba(0,0,0,.6)); }
.xi-cell { display: flex; align-items: center; justify-content: center; min-width: 0; min-height: 0; padding: 22px 30px; }
.xi-cell .md-tint { max-width: 100%; max-height: 100%; }
.xi-cell .md-tint img { width: auto; height: auto; max-width: 240px; max-height: 76px; object-fit: contain; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="xi-pattern"></div>
  <div class="xi-photo-box"><?php if ($photo !== ''): ?><img class="xi-photo" src="<?= $e($photo) ?>" style="<?= $e($photoStyle) ?>" alt=""><?php endif; ?></div>
  <div class="xi-gradient"></div>
  <div class="xi-grey"><?php if ($photo !== ''): ?><img src="<?= $e($photo) ?>" alt=""><?php endif; ?></div>

  <div class="xi-badges">
    <?php if ($left !== ''): ?><?= $tint($left) ?><?php endif; ?>
    <?php if ($right !== ''): ?><?= $tint($right) ?><?php endif; ?>
  </div>
  <div class="xi-title">Starting<br>eleven.</div>

  <ul class="xi-list">
    <?php foreach ($starters as $slotIx => $p): $shownNum = $p['num'] ?? ($slotIx + 1); ?>
      <li><i><?= (int)$shownNum ?></i><span><?= $display($p['name'], true) ?></span><?php if ($p['captain']): ?><b class="xi-cap"></b><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($bench): ?>
    <div class="xi-subs-h">Substitutes</div>
    <div class="xi-subs"><?php foreach ($subRows as $row): ?><div><?= implode('<b>+</b>', $row) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($mdSponsor): ?>
    <div class="xi-sponsor"><?php if ($mdSponsor['url'] !== ''): ?><img src="<?= $e($mdSponsor['url']) ?>" alt="<?= $e($mdSponsor['name']) ?>"><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($sponsors): ?>
    <div class="xi-sponsors"><?php foreach (array_slice($sponsors, 0, 4) as $s): ?><div class="xi-cell"><?= $tint($s['url'], $s['name']) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
</div>
</body>
</html>
