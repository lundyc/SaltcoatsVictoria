<?php

declare(strict_types=1);

// The "Substitution" graphic: one swap as two big On / Off panels, or several swaps as rows of
// On / Off tiles. Included by match_next_match_card.php with $pdo, $fixtureId, $data, $sponsors,
// $e and $fontBase set. The swaps are chosen on the Graphics page (substitution_graphic_json).

next_match_graphic_ensure_schema($pdo);
$raw = (string)$pdo->query('SELECT substitution_graphic_json FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetchColumn();
$pairs = json_decode($raw, true);
$pairs = is_array($pairs) ? array_slice($pairs, 0, 4) : [];

$lookup = [];
$stmt = $pdo->query('SELECT id, name, squad_number, avatar FROM players');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $lookup[(int)$r['id']] = $r;
}
// Number = shirt number saved on the line-up, else the squad number, else the player's starting
// position (1-11 in the order set on the Line-ups / Starting XI picker). Bench players have none.
$shirt = [];
$slot = 0;
foreach ($pdo->query('SELECT player_id, shirt_number, is_starting FROM matchday_lineups WHERE fixture_id = ' . (int)$fixtureId . " AND side = 'svfc' AND player_id IS NOT NULL ORDER BY is_starting DESC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ((int)$r['is_starting'] === 1) {
        $slot++;
    }
    if ($r['shirt_number'] !== null) {
        $shirt[(int)$r['player_id']] = (int)$r['shirt_number'];
    } elseif ((int)$r['is_starting'] === 1) {
        $shirt[(int)$r['player_id']] = $slot;
    }
}
// Each player's own sponsors (home / away / third shirt slots) for this fixture's season.
$seasonId = (int)($data['fixture']['season_id'] ?? 0);
$playerSponsors = static function (int $id) use ($pdo, $seasonId): array {
    $q = $pdo->prepare("SELECT sp.name, sp.logo_path, sp.white_logo_path FROM sponsorships s JOIN sponsors sp ON sp.id = s.sponsor_id
        WHERE s.player_id = :p AND s.season_id = :season AND s.ended_at IS NULL
        ORDER BY FIELD(LOWER(s.slot), 'home', 'away', 'third'), s.id DESC");
    $q->execute([':p' => $id, ':season' => $seasonId]);
    $out = [];
    $seen = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $white = basename(trim((string)($r['white_logo_path'] ?? '')));
        $colour = basename(trim((string)($r['logo_path'] ?? '')));
        // Colour logo on a cream chip: works whether or not the logo has a see-through background.
        $file = $colour !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $colour) ? $colour : ($white !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $white) ? $white : '');
        if ($file === '' || isset($seen[$file])) {
            continue;
        }
        $seen[$file] = true;
        $out[] = ['name' => (string)$r['name'], 'url' => '/uploads/sponsors/' . rawurlencode($file)];
        if (count($out) >= 3) {
            break;
        }
    }
    return $out;
};
$person = static function (int $id) use ($lookup, $shirt, $playerSponsors): ?array {
    if (!isset($lookup[$id])) {
        return null;
    }
    $r = $lookup[$id];
    $parts = preg_split('/\s+/', trim((string)$r['name'])) ?: [];
    $first = count($parts) > 1 ? array_shift($parts) : '';
    $name = ($first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) . '. ' : '') . implode(' ', $parts ?: [(string)$r['name']]);
    $avatar = trim((string)($r['avatar'] ?? ''));
    return [
        'name' => $name,
        'num' => $r['squad_number'] !== null && !isset($shirt[$id]) ? (int)$r['squad_number'] : ($shirt[$id] ?? null),
        'sponsors' => $playerSponsors($id),
        'img' => $avatar !== '' && is_file(dirname(__DIR__) . '/uploads/players/' . basename($avatar)) ? '/uploads/players/' . rawurlencode(basename($avatar)) : '',
    ];
};
$swaps = [];
foreach ($pairs as $pair) {
    $off = $person((int)($pair['off'] ?? 0));
    $on = $person((int)($pair['on'] ?? 0));
    if ($off && $on) {
        $swaps[] = ['on' => $on, 'off' => $off];
    }
}
$n = count($swaps);
$tint = static fn(string $url, string $alt = ''): string => '<span class="sb-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt="' . $e($alt) . '"></span>';
// Stand-in for players without a profile photo: a head-and-shoulders silhouette.
$silhouette = '<img class="sb-sil" src="/admin/assets/images/player-silhouette.png" alt="">';
$tile = static function (string $kind, array $p, int $n, int $idx) use ($e, $silhouette): string {
    $cls = 'sb-tile sb-tile--' . $kind . ($n === 1 ? ' sb-tile--single' : '');
    $num = $p['num'] !== null ? '<em>' . (int)$p['num'] . '</em>' : '';
    return '<div class="' . $cls . '"><div class="sb-frame">' . ($p['img'] !== '' ? '<img src="' . $e($p['img']) . '" alt="">' : $silhouette) . '</div>'
        . ($p['sponsors'] ? '<div class="sb-sponsors"><span class="sb-lab">Player sponsor' . (count($p['sponsors']) > 1 ? 's' : '') . '</span><div class="sb-chips">' . implode('', array_map(static fn(array $sp): string => '<span class="sb-chip"><img src="' . $e($sp['url']) . '" alt="' . $e($sp['name']) . '"></span>', $p['sponsors'])) . '</div></div>' : '')
        . '<div class="sb-word">' . ($kind === 'on' ? 'On' : 'Off') . '</div>'
        . '<div class="sb-name">' . $num . $e($p['name']) . '</div></div>';
};
// Several swaps: every player gets a full-width band, stacked vertically (On, Off, On, Off...).
$rowTile = static function (string $kind, array $p, int $ix) use ($e, $silhouette): string {
    $num = $p['num'] !== null ? '<em>' . (int)$p['num'] . '</em>' : '';
    $chips = $p['sponsors'] ? '<div class="sb-sponsors">' . implode('', array_map(static fn(array $sp): string => '<span class="sb-chip"><img src="' . $e($sp['url']) . '" alt="' . $e($sp['name']) . '"></span>', $p['sponsors'])) . '</div>' : '';
    return '<div class="sb-tile sb-row sb-row' . $ix . ' sb-tile--' . $kind . '"><div class="sb-frame">' . ($p['img'] !== '' ? '<img src="' . $e($p['img']) . '" alt="">' : $silhouette) . '</div>'
        . '<div class="sb-rc"><div class="sb-word">' . ($kind === 'on' ? 'On' : 'Off') . '</div><div class="sb-info"><div class="sb-name">' . $num . $e($p['name']) . '</div>' . $chips . '</div></div></div>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Substitution</title>
<style>
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; --deep: #450A14; --char: #2F2F2F; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: var(--char); color: var(--cream); font-family: "NM Inter", Arial, sans-serif; }
.sb-pattern { position: absolute; inset: 0; background: repeating-linear-gradient(60deg, rgba(255,243,227,.05) 0 1px, transparent 1px 9px), repeating-linear-gradient(-60deg, rgba(255,243,227,.03) 0 1px, transparent 1px 11px); }
.sb-tile { position: absolute; }
.sb-frame { position: absolute; inset: 0; overflow: hidden; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); }
.sb-tile--off .sb-frame { background: linear-gradient(135deg, #450A14 0%, #6E1F2D 30%, #E2B72E 70%, #E0C07A 100%); }
.sb-frame::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .8; pointer-events: none; }
.sb-frame img { position: absolute; bottom: 0; height: 112%; width: auto; max-width: none; filter: drop-shadow(0 8px 24px rgba(69,10,20,.45)); }
.sb-frame img.sb-sil { opacity: .6; filter: none; }
.sb-tile--on .sb-frame img { right: 5%; }
.sb-tile--off .sb-frame img { left: 5%; }
.sb-word { position: absolute; font-weight: 700; letter-spacing: -.05em; line-height: .8; color: var(--cream); text-shadow: 0 6px 30px rgba(47,47,47,.35); }
.sb-name { position: absolute; font-weight: 700; letter-spacing: -.02em; white-space: nowrap; color: var(--cream); }
.sb-name em { font-style: normal; color: var(--gold); margin-inline-end: .35em; }

/* one swap: two framed boxes, giant words, names down the side */
.sb-tile--single.sb-tile--on { left: 150px; top: 150px; width: 779px; height: 506px; }
.sb-tile--single.sb-tile--off { left: 150px; top: 693px; width: 779px; height: 507px; }
.sb-tile--single .sb-word { font-size: 250px; }
.sb-tile--single.sb-tile--on .sb-word { left: 0; top: -90px; }
.sb-tile--single.sb-tile--off .sb-word { right: -4px; bottom: -62px; }
.sb-tile--single .sb-name { font-size: 40px; writing-mode: vertical-rl; }
.sb-tile--single.sb-tile--on .sb-name { left: -86px; top: 0; transform: rotate(180deg); }
.sb-tile--single.sb-tile--off .sb-name { right: -86px; bottom: 0; }

.sb-footer { position: absolute; left: 150px; width: 400px; bottom: 36px; display: flex; align-items: center; justify-content: space-between; gap: 18px; }
.sb-sponsors { position: absolute; display: flex; flex-direction: column; align-items: flex-start; gap: 10px; z-index: 2; }
.sb-lab { font-size: 22px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--cream); text-shadow: 0 2px 10px rgba(47,47,47,.55); }
.sb-chips { display: flex; align-items: center; gap: 16px; }
/* every sponsor sits on the same white card so logos line up whatever their shape */
.sb-chip { --ch: 118px; display: inline-flex; align-items: center; justify-content: center; width: calc(var(--ch) * 1.9); height: var(--ch); padding: 7px 10px; background: #fff; border-radius: 14px; box-shadow: 0 8px 22px rgba(47,47,47,.4), inset 0 0 0 2px rgba(226,183,46,.55); }
.sb-chip img { display: block; width: auto; height: auto; max-width: 100%; max-height: 100%; object-fit: contain; }
.sb-tile--single.sb-tile--on .sb-sponsors { left: 24px; bottom: 24px; }
.sb-tile--single.sb-tile--off .sb-sponsors { right: 24px; top: 24px; }
.sb-tint { display: inline-block; line-height: 0; background-color: var(--cream); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.sb-tint img { opacity: 0; display: block; height: 40px; width: auto; max-width: 100px; object-fit: contain; }
.sb-empty { position: absolute; left: 0; right: 0; top: 600px; text-align: center; font-size: 34px; font-weight: 600; color: var(--gold); }
<?php if ($n > 1):
    $gap = 14; $top = 50; $areaH = 1230 - $top;
    $tiles = $n * 2;
    $H = (int)floor(($areaH - $gap * ($tiles - 1)) / $tiles);
    $wordSize = (int)round($H * 0.62);
    $nameSize = max(26, min(62, (int)round($H * 0.27)));
    $chipH = max(34, min(100, (int)round($H * 0.4)));
    for ($i = 0; $i < $tiles; $i++) {
        $y = $top + $i * ($H + $gap);
        echo ".sb-row{$i} { left: 60px; top: {$y}px; width: 960px; height: {$H}px; }\n";
    }
?>
.sb-row { overflow: hidden; }
.sb-row .sb-frame img { top: 4%; bottom: auto; height: 165%; right: 2%; left: auto; }
.sb-rc { position: absolute; inset: 0; display: flex; align-items: center; gap: 30px; padding: 0 26% 0 30px; z-index: 2; }
.sb-row .sb-word { position: absolute; left: 14px; top: 0; bottom: 0; display: flex; align-items: center; font-size: <?= (int)round($H * 1.26) ?>px; line-height: .72; transform: translateY(<?= (int)round($H * 0.03) ?>px); letter-spacing: -.06em; opacity: .3; text-shadow: none; z-index: 1; pointer-events: none; }
.sb-row .sb-name em { color: var(--deep); }
.sb-row.sb-tile--off .sb-name em { color: var(--gold); }
.sb-row .sb-name { position: relative; z-index: 2; font-size: <?= $nameSize ?>px; text-shadow: 0 2px 12px rgba(47,47,47,.45); }
.sb-info { position: relative; z-index: 2; display: flex; flex-direction: column; align-items: flex-start; gap: <?= max(6, (int)round($H * 0.05)) ?>px; }
.sb-row .sb-sponsors { position: static; flex-direction: row; gap: 12px; }
.sb-row .sb-chip { --ch: <?= $chipH ?>px; width: calc(var(--ch) * 1.9); padding: <?= max(3, (int)round($chipH * 0.07)) ?>px <?= max(5, (int)round($chipH * 0.1)) ?>px; border-radius: <?= max(8, (int)round($chipH * 0.2)) ?>px; box-shadow: 0 4px 12px rgba(47,47,47,.35), inset 0 0 0 1.5px rgba(226,183,46,.55); }
<?php endif; ?>
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="sb-pattern"></div>
  <?php if ($n === 0): ?>
    <div class="sb-empty">Choose a substitution</div>
  <?php elseif ($n === 1): ?>
    <?= $tile('on', $swaps[0]['on'], 1, 0) ?>
    <?= $tile('off', $swaps[0]['off'], 1, 0) ?>
  <?php else: ?>
    <?php foreach ($swaps as $i => $sw): ?>
      <?= $rowTile('on', $sw['on'], $i * 2) ?>
      <?= $rowTile('off', $sw['off'], $i * 2 + 1) ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($sponsors): ?><div class="sb-footer"><?php foreach (array_slice($sponsors, 0, 4) as $s): ?><?= $tint($s['url'], $s['name']) ?><?php endforeach; ?></div><?php endif; ?>
</div>
</body>
</html>
