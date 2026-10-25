<?php

declare(strict_types=1);

// In-game graphics: goal, yellow card, red card, penalty. One big framed player photo with a giant word,
// the minute, and the score at that moment. Included by match_next_match_card.php with $pdo, $fixtureId,
// $data, $sponsors, $photoType, $e and $fontBase set.

require_once __DIR__ . '/event_graphic_data.php';

$ev = nm_event_graphic_resolve($pdo, $fixtureId, $photoType);
$seasonId = (int)($data['fixture']['season_id'] ?? 0);

$word = ['goal' => 'Goal', 'yellow_card' => 'Yellow', 'red_card' => 'Red', 'penalty' => 'Penalty'][$photoType] ?? 'Goal';
$wordSize = ['goal' => 280, 'yellow_card' => 236, 'red_card' => 300, 'penalty' => 210][$photoType] ?? 260;

$person = ['name' => '', 'num' => null, 'img' => '', 'sponsors' => []];
if ($ev !== null) {
    $parts = preg_split('/\s+/', trim($ev['player_name'])) ?: [];
    $first = count($parts) > 1 ? array_shift($parts) : '';
    $person['name'] = $ev['player_name'] === '' ? '' : (($first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) . '. ' : '') . implode(' ', $parts ?: [$ev['player_name']]));
    if ($ev['player_id'] > 0) {
        try { $pdo->query('SELECT celebration_image FROM players LIMIT 1'); } catch (Throwable $e) { $pdo->exec('ALTER TABLE players ADD COLUMN celebration_image VARCHAR(255) NULL DEFAULT NULL'); }
        $st = $pdo->prepare('SELECT squad_number, avatar, celebration_image FROM players WHERE id = :id');
        $st->execute([':id' => $ev['player_id']]);
        $pl = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $avatar = basename(trim((string)($pl['avatar'] ?? '')));
        $celeb = basename(trim((string)($pl['celebration_image'] ?? '')));
        $useCeleb = $photoType === 'goal' && $celeb !== '' && is_file(dirname(__DIR__) . '/uploads/players/' . $celeb);
        $person['img'] = $useCeleb ? '/uploads/players/' . rawurlencode($celeb) : ($avatar !== '' && is_file(dirname(__DIR__) . '/uploads/players/' . $avatar) ? '/uploads/players/' . rawurlencode($avatar) : '');
        $num = null;
        $slot = 0;
        foreach ($pdo->query('SELECT player_id, shirt_number, is_starting FROM matchday_lineups WHERE fixture_id = ' . (int)$fixtureId . " AND side = 'svfc' AND player_id IS NOT NULL ORDER BY is_starting DESC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['is_starting'] === 1) {
                $slot++;
            }
            if ((int)$r['player_id'] === $ev['player_id']) {
                $num = $r['shirt_number'] !== null ? (int)$r['shirt_number'] : ((int)$r['is_starting'] === 1 ? $slot : null);
            }
        }
        $person['num'] = $num ?? ($pl['squad_number'] !== null ? (int)$pl['squad_number'] : null);
        $q = $pdo->prepare("SELECT s.slot, sp.name, sp.logo_path, sp.white_logo_path FROM sponsorships s JOIN sponsors sp ON sp.id = s.sponsor_id
            WHERE s.player_id = :p AND s.season_id = :season AND s.ended_at IS NULL ORDER BY FIELD(LOWER(s.slot), 'home', 'away', 'third'), s.id DESC");
        $q->execute([':p' => $ev['player_id'], ':season' => $seasonId]);
        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $white = basename(trim((string)($r['white_logo_path'] ?? '')));
            $colour = basename(trim((string)($r['logo_path'] ?? '')));
            $file = $colour !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $colour) ? $colour : ($white !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $white) ? $white : '');
            if ($file !== '' && !isset($seen[$file]) && count($person['sponsors']) < 3) {
                $seen[$file] = true;
                $person['sponsors'][] = ['name' => (string)$r['name'], 'slot' => strtolower((string)$r['slot']), 'url' => '/uploads/sponsors/' . rawurlencode($file), 'text_only' => function_exists('next_match_graphic_is_cutout') && next_match_graphic_is_cutout(dirname(__DIR__) . '/uploads/sponsors/' . $file)];
            }
        }
    }
}
$tag = '';
if ($ev !== null) {
    if ($photoType === 'penalty') {
        $tag = strtoupper(str_replace('_', ' ', $ev['outcome'] !== '' ? $ev['outcome'] : 'scored'));
    } elseif ($photoType === 'goal') {
        $tag = $ev['own_goal'] ? 'OWN GOAL' : ($ev['type'] === 'penalty_scored' ? 'PENALTY' : '');
    } elseif ($photoType === 'red_card') {
        $tag = $ev['type'] === 'second_yellow' ? 'SECOND YELLOW' : 'RED CARD';
    } elseif ($photoType === 'yellow_card') {
        $tag = 'YELLOW CARD';
    }
}
if (in_array($photoType, ['penalty_awarded', 'penalty_saved', 'penalty_missed'], true)) {
    require __DIR__ . '/penalty_card_view.php';
    exit;
}
if (in_array($photoType, ['yellow_card', 'red_card', 'penalty_awarded'], true)) {
    require __DIR__ . '/card_event_view.php';
    exit;
}
if ($photoType === 'goal') {
    require __DIR__ . '/goal_card_view.php';
    exit;
}
$tint = static fn(string $url, string $alt = ''): string => '<span class="ec-tint" style="--m:url(\'' . $e($url) . '\')"><img src="' . $e($url) . '" alt="' . $e($alt) . '"></span>';
$highlightHome = $ev !== null && (($ev['side'] === 'svfc') === $ev['is_home']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($word) ?></title>
<style>
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; --deep: #450A14; --char: #2F2F2F; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: var(--char); color: var(--cream); font-family: "NM Inter", Arial, sans-serif; }
.ec-pattern { position: absolute; inset: 0; background: repeating-linear-gradient(60deg, rgba(255,243,227,.05) 0 1px, transparent 1px 9px), repeating-linear-gradient(-60deg, rgba(255,243,227,.03) 0 1px, transparent 1px 11px); }
.ec-frame { position: absolute; left: 150px; top: 200px; width: 780px; height: 620px; overflow: hidden; background: linear-gradient(135deg, #E0C07A 0%, #E2B72E 32%, #6E1F2D 72%, #450A14 100%); }
.ec-frame::after { content: ""; position: absolute; inset: 0; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.35'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); mix-blend-mode: overlay; opacity: .8; pointer-events: none; }
.ec-frame img.ec-photo { position: absolute; right: 5%; bottom: 0; height: 112%; width: auto; filter: drop-shadow(0 8px 24px rgba(69,10,20,.45)); }
.ec-frame img.ec-sil { opacity: .6; }
.ec-word { position: absolute; left: 150px; top: 200px; transform: translateY(-62%); font-weight: 700; letter-spacing: -.05em; line-height: .8; color: var(--cream); text-shadow: 0 6px 30px rgba(47,47,47,.35); font-size: <?= $wordSize ?>px; z-index: 3; }
.ec-name { position: absolute; left: 64px; top: 200px; writing-mode: vertical-rl; transform: rotate(180deg); font-weight: 700; font-size: 40px; letter-spacing: -.02em; white-space: nowrap; z-index: 3; }
.ec-name em { font-style: normal; color: var(--gold); margin-inline-end: .35em; }
.ec-card { position: absolute; right: 36px; top: 34px; width: 120px; height: 168px; border-radius: 12px; transform: rotate(8deg); box-shadow: 0 10px 26px rgba(47,47,47,.45); z-index: 3; }
.ec-sponsors { position: absolute; left: 24px; bottom: 24px; display: flex; flex-direction: column; align-items: flex-start; gap: 10px; z-index: 3; }
.ec-lab { font-size: 22px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; text-shadow: 0 2px 10px rgba(47,47,47,.55); }
.ec-chips { display: flex; gap: 16px; }
.ec-chip { width: 200px; height: 108px; display: inline-flex; align-items: center; justify-content: center; padding: 7px 10px; background: #fff; border-radius: 14px; box-shadow: 0 8px 22px rgba(47,47,47,.4), inset 0 0 0 2px rgba(226,183,46,.55); }
.ec-chip img { width: auto; height: auto; max-width: 100%; max-height: 100%; object-fit: contain; }
.ec-info { position: absolute; left: 150px; width: 780px; top: 872px; height: 330px; }
.ec-minute { position: absolute; left: 0; top: 0; font-weight: 700; font-size: 190px; letter-spacing: -.06em; line-height: .9; color: var(--gold); }
.ec-minute small { font-size: .45em; letter-spacing: 0; vertical-align: top; margin-left: 4px; }
.ec-tag { position: absolute; left: 4px; top: 200px; padding: 8px 20px; border-radius: 999px; background: var(--cream); color: var(--deep); font-weight: 800; font-size: 28px; letter-spacing: .12em; }
.ec-score { position: absolute; right: 0; top: 6px; width: 470px; }
.ec-row { display: flex; justify-content: space-between; align-items: baseline; gap: 20px; padding: 10px 0; border-bottom: 2px solid rgba(255,243,227,.18); font-weight: 600; font-size: 36px; letter-spacing: -.01em; opacity: .75; }
.ec-row b { font-size: 84px; line-height: 1; letter-spacing: -.04em; }
.ec-row.is-hl { opacity: 1; color: var(--gold); }
.ec-empty { position: absolute; left: 0; right: 0; top: 600px; text-align: center; font-size: 34px; font-weight: 600; color: var(--gold); }
.ec-footer { position: absolute; left: 150px; width: 400px; bottom: 36px; display: flex; align-items: center; justify-content: space-between; gap: 18px; }
.ec-tint { display: inline-block; line-height: 0; background-color: var(--cream); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.ec-tint img { opacity: 0; display: block; height: 40px; width: auto; max-width: 100px; object-fit: contain; }
</style>
</head>
<body>
<div id="nextMatchCard">
  <div class="ec-pattern"></div>
  <?php if ($ev === null): ?>
    <div class="ec-empty">Choose a <?= $e(strtolower(str_replace('_', ' ', $photoType))) ?></div>
  <?php else: ?>
    <div class="ec-frame">
      <?php if ($person['img'] !== ''): ?><img class="ec-photo" src="<?= $e($person['img']) ?>" alt=""><?php else: ?><img class="ec-photo ec-sil" src="/admin/assets/images/player-silhouette.png" alt=""><?php endif; ?>
      <?php if ($photoType === 'yellow_card' || $photoType === 'red_card'): ?><div class="ec-card" style="background: <?= $photoType === 'yellow_card' ? '#F5C400' : '#D8232A' ?>"></div><?php endif; ?>
      <?php if ($person['sponsors']): ?>
        <div class="ec-sponsors"><span class="ec-lab">Player sponsor<?= count($person['sponsors']) > 1 ? 's' : '' ?></span><div class="ec-chips"><?php foreach ($person['sponsors'] as $sp): ?><span class="ec-chip"><img src="<?= $e($sp['url']) ?>" alt="<?= $e($sp['name']) ?>"></span><?php endforeach; ?></div></div>
      <?php endif; ?>
    </div>
    <div class="ec-word"><?= $e($word) ?></div>
    <?php if ($person['name'] !== ''): ?><div class="ec-name"><?php if ($person['num'] !== null): ?><em><?= (int)$person['num'] ?></em><?php endif; ?><?= $e($person['name']) ?></div><?php endif; ?>
    <div class="ec-info">
      <?php if ($ev['minute'] !== ''): ?><div class="ec-minute" style="font-size: <?= strlen($ev['minute']) > 2 ? 118 : 190 ?>px"><?= $e($ev['minute']) ?><small>'</small></div><?php endif; ?>
      <?php if ($tag !== ''): ?><div class="ec-tag"><?= $e($tag) ?></div><?php endif; ?>
      <div class="ec-score">
        <div class="ec-row<?= $highlightHome && in_array($photoType, ['goal', 'penalty'], true) ? ' is-hl' : '' ?>"><span><?= $e($ev['home_name']) ?></span><b><?= (int)$ev['score'][0] ?></b></div>
        <div class="ec-row<?= !$highlightHome && in_array($photoType, ['goal', 'penalty'], true) ? ' is-hl' : '' ?>"><span><?= $e($ev['away_name']) ?></span><b><?= (int)$ev['score'][1] ?></b></div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($sponsors): ?><div class="ec-footer"><?php foreach (array_slice($sponsors, 0, 4) as $s): ?><?= $tint($s['url'], $s['name']) ?><?php endforeach; ?></div><?php endif; ?>
</div>
</body>
</html>
