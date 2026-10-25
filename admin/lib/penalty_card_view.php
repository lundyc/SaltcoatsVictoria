<?php

declare(strict_types=1);

// "Penalty saved" and "Penalty missed" graphics. A dark maroon poster with a faint goal-net pattern, a big
// cutout of the player (our keeper for a save; our taker for one of ours that missed; the opponent's badge
// for an opposition miss), the minute and score, then PENALTY / SAVED (or MISSED) in big type and the name.
// Included from event_card_view.php with $ev, $pdo, $fixtureId, $data, $photoType, $seasonId, $e, $fontBase set.

require_once __DIR__ . '/event_graphic_data.php';

$isSaved = $photoType === 'penalty_saved';
$isAwarded = $photoType === 'penalty_awarded';
$title2 = $isAwarded ? 'Awarded' : ($isSaved ? 'Saved' : 'Missed');
// Awarded graphic colours: home team = maroon with yellow text, away team = yellow with maroon text.
$awardedAway = $isAwarded && $ev !== null && (($ev['side'] === 'svfc') !== (bool)$data['home']);
$home = $ev !== null ? $ev['home_name'] : '';
$away = $ev !== null ? $ev['away_name'] : '';

// Who is on the poster.
$keeperNum = null;
$subject = ['id' => 0, 'name' => '', 'img' => '', 'num' => null, 'sponsors' => []];
$bigBadge = '';
if ($ev !== null) {
    if ($isAwarded) {
        // Just the team the penalty went to: their badge, no player.
        $bigBadge = (string)($ev['side'] === 'svfc' ? $data['club_badge'] : $data['opponent_badge']);
    } elseif ($isSaved) {
        $keeper = nm_event_graphic_keeper($pdo, $fixtureId);
        $subject['id'] = $keeper['id'] ?? 0;
        $subject['name'] = $keeper['name'] ?? '';
        $keeperNum = $keeper['num'] ?? null;
    } elseif ($ev['side'] === 'svfc') {
        $subject['id'] = (int)$ev['player_id'];
        $subject['name'] = (string)$ev['player_name'];
    } else {
        $subject['name'] = (string)$ev['player_name'];
        $bigBadge = (string)$data['opponent_badge'];
    }
    if ($subject['id'] > 0) {
        $st = $pdo->prepare('SELECT squad_number, avatar FROM players WHERE id = :id');
        $st->execute([':id' => $subject['id']]);
        $pl = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $avatar = basename(trim((string)($pl['avatar'] ?? '')));
        if ($avatar !== '' && is_file(dirname(__DIR__) . '/uploads/players/' . $avatar)) {
            $subject['img'] = '/uploads/players/' . rawurlencode($avatar);
        }
        $subject['num'] = $keeperNum ?? (($pl['squad_number'] ?? null) !== null ? (int)$pl['squad_number'] : null);
        $q = $pdo->prepare("SELECT DISTINCT sp.name, sp.logo_path, sp.white_logo_path FROM sponsorships s JOIN sponsors sp ON sp.id = s.sponsor_id
            WHERE s.player_id = :p AND s.season_id = :season AND s.ended_at IS NULL ORDER BY FIELD(LOWER(s.slot), 'home', 'away', 'third'), s.id DESC");
        $q->execute([':p' => $subject['id'], ':season' => $seasonId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $white = basename(trim((string)($r['white_logo_path'] ?? '')));
            $file = $white !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $white) ? $white : '';
            if ($file !== '' && count($subject['sponsors']) < 3) {
                $subject['sponsors'][] = ['name' => (string)$r['name'], 'url' => '/uploads/sponsors/' . rawurlencode($file)];
            }
        }
    }
}
$homeBadge = $data['home'] ? $data['club_badge'] : $data['opponent_badge'];
$awayBadge = $data['home'] ? $data['opponent_badge'] : $data['club_badge'];
$caption = $ev === null ? '' : ($isAwarded ? 'To ' . ($ev['side'] === 'svfc' ? 'Saltcoats' : $ev['team']) : ($subject['name'] !== '' ? $subject['name'] : ($ev['side'] === 'svfc' ? 'Saltcoats Victoria' : $ev['team'])));
$capLen = mb_strlen($caption);
$capSize = $capLen > 26 ? 40 : ($capLen > 20 ? 48 : 58);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Penalty <?= $e(strtolower($title2)) ?></title>
<style>
@font-face { font-family: "NM Anton"; src: url("<?= $fontBase ?>Anton-Regular.ttf"); font-weight: 400; }
@font-face { font-family: "NM Inter"; src: url("<?= $fontBase ?>Inter-Variable.ttf"); font-weight: 100 900; }
:root { --cream: #FFF3E3; --gold: #E2B72E; --maroon: #6E1F2D; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #111; }
#nextMatchCard { position: relative; width: 1080px; height: 1350px; overflow: hidden; background: radial-gradient(110% 80% at 50% 38%, #8a2a3b 0%, #561322 46%, #1d070c 100%); color: var(--cream); font-family: "NM Inter", Arial, sans-serif; text-align: center; }
.pn-net { position: absolute; inset: 0; background:
  repeating-linear-gradient(45deg, rgba(255,243,227,.16) 0 3px, transparent 3px 46px),
  repeating-linear-gradient(-45deg, rgba(255,243,227,.16) 0 3px, transparent 3px 46px);
  -webkit-mask: linear-gradient(180deg, #000 0%, rgba(0,0,0,.65) 55%, rgba(0,0,0,0) 80%); mask: linear-gradient(180deg, #000 0%, rgba(0,0,0,.65) 55%, rgba(0,0,0,0) 80%); }
.pn-tex { position: absolute; inset: 0; background: url("/admin/assets/images/texture-halftone.png") 0 0 / 1080px 648px repeat; mix-blend-mode: multiply; opacity: .5; pointer-events: none; }
.pn-fixture { position: absolute; left: 0; right: 0; top: 56px; font-weight: 800; font-size: 24px; letter-spacing: .5em; text-indent: .5em; text-transform: uppercase; color: rgba(255,243,227,.85); }
.pn-shot { position: absolute; left: 50%; bottom: 270px; transform: translateX(-50%); height: 1010px; width: auto; filter: drop-shadow(0 20px 50px rgba(0,0,0,.55)); }
.pn-badge-big { position: absolute; left: 50%; top: 210px; transform: translateX(-50%); width: 560px; height: 560px; background-color: rgba(255,243,227,.88); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.pn-fade { position: absolute; left: 0; right: 0; bottom: 0; height: 640px; background: linear-gradient(0deg, rgba(25,6,10,.98) 0%, rgba(25,6,10,.88) 34%, rgba(25,6,10,0) 100%); }
.pn-score { position: absolute; left: 0; right: 0; top: 880px; display: flex; flex-direction: column; align-items: center; gap: 6px; z-index: 2; text-shadow: 0 2px 14px rgba(0,0,0,.7); }
.pn-min { font-weight: 800; font-size: 46px; line-height: 1; }
.pn-line { display: flex; align-items: center; justify-content: center; gap: 22px; font-weight: 800; font-size: 54px; line-height: 1; }
.pn-tint { display: inline-block; line-height: 0; background-color: var(--cream); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.pn-tint img { opacity: 0; display: block; height: 70px; width: auto; max-width: 80px; object-fit: contain; }
.pn-title { position: absolute; left: 0; right: 0; top: 1000px; font-family: "NM Anton", Impact, sans-serif; text-transform: uppercase; line-height: .98; white-space: nowrap; z-index: 2; }
.pn-title b { font-weight: 400; color: var(--gold); margin-right: .18em; }
.pn-title span { color: var(--cream); }
.pn-name { position: absolute; left: 0; right: 0; top: 1180px; font-weight: 800; font-size: <?= $capSize ?>px; letter-spacing: -.02em; text-transform: uppercase; white-space: nowrap; z-index: 2; }
.pn-name em { font-style: normal; color: var(--gold); margin-right: .25em; }
.pn-sponsors { position: absolute; left: 0; right: 0; bottom: 34px; display: flex; justify-content: center; gap: 36px; z-index: 2; }
.pn-sponsors .pn-tint img { height: 54px; max-width: 170px; }
.pn-aw { position: absolute; left: 0; right: 0; top: 842px; display: flex; flex-direction: column; align-items: center; z-index: 2; font-family: "NM Anton", Impact, sans-serif; text-transform: uppercase; white-space: nowrap; }
.pn-aw__pen { font-size: 190px; line-height: 1; color: var(--gold); }
.pn-aw__to { margin: 2px 0 6px; font-family: "NM Inter", Arial, sans-serif; font-weight: 800; font-size: 54px; line-height: 1; letter-spacing: .3em; text-indent: .3em; color: var(--cream); }
.pn-aw__team { line-height: 1; color: var(--gold); }
.pn-awarded:not(.is-away) .pn-fixture, .pn-awarded:not(.is-away) .pn-min { color: var(--gold); }
.pn-awarded:not(.is-away) .pn-badge-big { background-color: var(--gold); }
.pn-awarded .pn-badge-big { top: 130px; width: 480px; height: 480px; }
.pn-awarded .pn-score { top: 650px; }
#nextMatchCard.pn-awarded.is-away { background: linear-gradient(160deg, #F6C919 0%, #F2BE12 50%, #E5A912 100%); color: #6E1F2D; }
.pn-awarded.is-away .pn-net { background:
  repeating-linear-gradient(45deg, rgba(110,31,45,.16) 0 3px, transparent 3px 46px),
  repeating-linear-gradient(-45deg, rgba(110,31,45,.16) 0 3px, transparent 3px 46px); }
.pn-awarded.is-away .pn-fixture { color: rgba(110,31,45,.85); }
.pn-awarded.is-away .pn-badge-big { background-color: #6E1F2D; }
.pn-awarded.is-away .pn-fade { display: none; }
.pn-awarded.is-away .pn-score { text-shadow: none; color: #6E1F2D; }
.pn-awarded.is-away .pn-aw__pen { color: #6E1F2D; }
.pn-awarded.is-away .pn-aw__to { color: #A05563; }
.pn-awarded.is-away .pn-aw__team { color: #450A14; }
.pn-empty { position: absolute; left: 0; right: 0; top: 620px; font-size: 40px; font-weight: 700; }
</style>
</head>
<body>
<div id="nextMatchCard"<?= $isAwarded ? ' class="pn-awarded' . ($awardedAway ? ' is-away' : '') . '"' : '' ?>>
  <div class="pn-net"></div>
  <div class="pn-tex"></div>
  <?php if ($ev === null): ?>
    <div class="pn-empty">Choose a penalty <?= $isAwarded ? 'award' : ($isSaved ? 'save' : 'miss') ?></div>
  <?php else: ?>
    <div class="pn-fixture"><?= $e($home) ?> v <?= $e($away) ?></div>
    <?php if ($bigBadge !== ''): ?><div class="pn-badge-big" style="--m:url('<?= $e($bigBadge) ?>')"></div>
    <?php else: ?><img class="pn-shot" src="<?= $e($subject['img'] !== '' ? $subject['img'] : '/admin/assets/images/player-silhouette.png') ?>" alt=""><?php endif; ?>
    <div class="pn-fade"></div>
    <div class="pn-score">
      <?php if ($ev['minute'] !== ''): ?><div class="pn-min"><?= $e($ev['minute']) ?>'</div><?php endif; ?>
      <?php if (!$isAwarded): ?>
      <div class="pn-line">
        <?php if ($homeBadge !== ''): ?><span class="pn-tint" style="--m:url('<?= $e($homeBadge) ?>')"><img src="<?= $e($homeBadge) ?>" alt=""></span><?php endif; ?>
        <span><?= (int)$ev['score'][0] ?> - <?= (int)$ev['score'][1] ?></span>
        <?php if ($awayBadge !== ''): ?><span class="pn-tint" style="--m:url('<?= $e($awayBadge) ?>')"><img src="<?= $e($awayBadge) ?>" alt=""></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($isAwarded): ?>
      <div class="pn-aw"><div class="pn-aw__pen">Penalty</div><div class="pn-aw__to">To</div><div class="pn-aw__team" id="pnTeam"><?= $e($ev['side'] === 'svfc' ? 'Saltcoats' : $ev['team']) ?></div></div>
    <?php else: ?>
    <div class="pn-title" id="pnTitle"><b>Penalty</b><span><?= $e($title2) ?></span></div>
    <div class="pn-name"><?php if (!$isAwarded && $subject['num'] !== null && $subject['name'] !== ''): ?><em><?= (int)$subject['num'] ?></em><?php endif; ?><?= $e($caption) ?></div>
    <?php endif; ?>
    <?php if ($subject['sponsors']): ?>
      <div class="pn-sponsors"><?php foreach ($subject['sponsors'] as $sp): ?><span class="pn-tint" style="--m:url('<?= $e($sp['url']) ?>')"><img src="<?= $e($sp['url']) ?>" alt="<?= $e($sp['name']) ?>"></span><?php endforeach; ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
// Make PENALTY SAVED / MISSED as wide as the poster allows.
(function () {
  var team = document.getElementById('pnTeam');
  if (team) {
    (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () {
      team.style.fontSize = '200px';
      team.style.fontSize = Math.min(150, 200 * 940 / Math.max(team.scrollWidth, 1)) + 'px';
    });
  }
  var el = document.getElementById('pnTitle');
  if (!el) return;
  function fit() {
    el.style.fontSize = '200px';
    var w = el.scrollWidth;
    var s = Math.min(190, 200 * 960 / Math.max(w, 1));
    el.style.fontSize = s + 'px';
  }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(fit);
})();
</script>
</body>
</html>
