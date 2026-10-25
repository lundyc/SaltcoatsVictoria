<?php
/** Route: /team/{slug} — player profile. */
declare(strict_types=1);

$player = squad_public_find(db(), (string) ($slug ?? ''));
if ($player === null) {
    http_response_code(404);
    set_meta(['title' => 'Player not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Player not found</h1><p><a class="linkarrow" href="' . e(url('team')) . '">Back to the squad</a></p></div></div>';
    return;
}

$avatar = trim((string) ($player['avatar'] ?? ''));
$photoReal = $avatar !== '' ? uploads('players/' . $avatar) : '';
$photo  = $photoReal !== '' ? $photoReal : asset('img/player-placeholder.png'); // silhouette when there is no photo
$number = $player['squad_number'] !== null ? (int) $player['squad_number'] : null;
$age    = squad_age($player['date_of_birth'] ?? null);
if ($age !== null && ($age < 14 || $age > 60)) { $age = null; } // implausible => treat as unknown
$bio    = trim((string) ($player['bio'] ?? ''));
$nat    = trim((string) ($player['nationality'] ?? ''));
$dob    = trim((string) ($player['date_of_birth'] ?? ''));
$joined = trim((string) ($player['joined_at'] ?? ''));
$hasDob    = $dob !== '' && $dob !== '0000-00-00' && $age !== null;
$hasJoined = $joined !== '' && $joined !== '0000-00-00';

$initials = '';
foreach (preg_split('/\s+/', trim((string) $player['name'])) ?: [] as $part) {
    $initials .= mb_substr($part, 0, 1);
}
$initials = mb_strtoupper(mb_substr($initials, 0, 2));

// Facts shown as cards below the hero (only the ones we actually hold).
$facts = [];
$facts[] = ['Position', $player['position_label']];
if ($number !== null)  { $facts[] = ['Squad number', '#' . $number]; }
if ($age !== null)     { $facts[] = ['Age', (string) $age]; }
if ($nat !== '')       { $facts[] = ['Nationality', $nat]; }
if ($hasJoined)        { $facts[] = ['Joined', format_date($joined, 'M Y')]; }

$sponsorSlots = pub_player_sponsors((int) $player['id']);

// Whole squad in squad order, for the "Select a player" strip.
$squadAll = squad_public_players(db());
$contactEmail = club('contact_email');

$clubNm = club('club_name');
$descBits = [$player['name'] . ' — ' . strtolower((string) $player['position_label']) . ($number !== null ? ' (No. ' . $number . ')' : '') . ' for ' . $clubNm . '.'];
if ($nat !== '') { $descBits[] = 'Nationality: ' . $nat . '.'; }
if ($hasJoined) { $descBits[] = 'Joined ' . format_date($joined, 'F Y') . '.'; }
$descBits[] = $bio !== '' ? excerpt(strip_tags($bio), 90) : 'Player profile, squad information and more.';
set_meta([
    'title' => $player['name'] . ' | ' . $clubNm,
    'title_full' => '1',
    'description' => implode(' ', $descBits),
    'image' => $photoReal !== '' ? (current_url_origin() . $photoReal) : '',
    'og_type' => 'profile',
]);
seo_breadcrumbs([['First team', url('team')], [(string) $player['name'], url('team/' . $player['slug'])]]);
pub_jsonld([
    '@type' => 'Person',
    'name' => (string) $player['name'],
    'url' => seo_canonical(),
    'image' => $photoReal !== '' ? current_url_origin() . $photoReal : null,
    'jobTitle' => 'Footballer — ' . $player['position_label'],
    'nationality' => $nat,
    'birthDate' => $hasDob ? $dob : null,
    'memberOf' => ['@id' => current_url_origin() . '/#club'],
]);

// Season record for the stat rings and the Stats tab (current season, from the match records).
$seasonStats = ['appearances' => 0, 'starts' => 0, 'substitute_appearances' => 0, 'goals' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'minutes_played' => 0];
try {
    require_once PUBLIC_ROOT . '/admin/lib/player_match_stats.php';
    require_once PUBLIC_ROOT . '/admin/lib/stats.php';
    $seasonStats = array_merge($seasonStats, hub_player_match_stats(db(), pub_current_season_id(), (string) $player['name'], ''));
} catch (Throwable) {
    // stats are optional: the rings simply show 0
}
$seasonLabel = seo_season_label();
// Which statistics this club shows publicly (Settings > General > Player profiles).
$showGoals = club('player_show_goals', '0') === '1';
$showCards = club('player_show_cards', '0') === '1';
$showShootout = club('player_show_shootout', '0') === '1';

// Penalty-shootout kicks this season (kept apart from goals: a shootout kick is not a goal).
$shootoutTaken = 0;
$shootoutScored = 0;
try {
    $so = db()->prepare(
        "SELECT e.outcome FROM matchday_events e
           JOIN match_fixtures f ON f.id = e.fixture_id
          WHERE e.type = 'shootout_penalty' AND e.side = 'svfc' AND f.season_id = :s
            AND LOWER(TRIM(e.player_name)) = LOWER(:n)"
    );
    $so->execute([':s' => pub_current_season_id(), ':n' => trim((string) $player['name'])]);
    foreach ($so->fetchAll(PDO::FETCH_COLUMN) as $outcome) {
        $shootoutTaken++;
        if ($outcome === 'scored') { $shootoutScored++; }
    }
} catch (Throwable) {
    // optional
}

$nameParts = preg_split('/\s+/', trim((string) $player['name'])) ?: [(string) $player['name']];
$surname = count($nameParts) > 1 ? (string) array_pop($nameParts) : (string) $player['name'];
$firstName = count($nameParts) > 0 && $surname !== $player['name'] ? implode(' ', $nameParts) : '';

$bioParas = [];
foreach (preg_split('/\n\s*\n/', $bio) ?: [] as $para) {
    $para = trim((string) $para);
    if ($para !== '') { $bioParas[] = $para; }
}

$infoItems = [];
if ($nat !== '')   { $infoItems[] = ['pin', 'Nationality', $nat]; }
if ($hasJoined)    { $infoItems[] = ['join', 'Joined club', format_date($joined, 'F Y')]; }
if ($hasDob)       { $infoItems[] = ['cake', 'Date of birth', format_date($dob, 'd/m/Y')]; }
$infoItems[] = ['shirt', 'Position', (string) $player['position_label']];

$pfIcons = [
    'pin'   => '<path d="M12 21s-7-6.1-7-11a7 7 0 1 1 14 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'join'  => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 15l2 2 4-4"/>',
    'cake'  => '<path d="M4 20h16v-7H4zM6 13V9h12v4M12 9V6M12 3c1 1 1 2 0 3-1-1-1-2 0-3zM4 17c2 1.5 3 1.5 4 0s2-1.5 4 0 3 1.5 4 0 2-1.5 4 0"/>',
    'shirt' => '<path d="M8 4 3 7l2 4 3-1v10h8V10l3 1 2-4-5-3c-.5 1.5-2 2.3-4 2.3S8.5 5.5 8 4z"/>',
];
$pfIcon = static fn (string $name): string => '<svg class="pf-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($pfIcons[$name] ?? '') . '</svg>';
$crestUrl = club_crest();
?>
<div class="pf-top">
  <section class="pf-hero" aria-label="<?= e($player['name']) ?>">
    <div class="container pf-hero__inner">
      <div class="pf-id">
        <span class="pf-pos"><?= e($player['position_label']) ?></span>
        <div class="pf-name">
          <?php if ($number !== null): ?><span class="pf-num"><?= $number ?></span><?php endif; ?>
          <h1>
            <?php if ($firstName !== ''): ?><span class="pf-name__first"><?= e($firstName) ?></span><?php endif; ?>
            <span class="pf-name__last"><?= e($surname) ?></span>
          </h1>
        </div>
      </div>
      <ul class="pf-rings" aria-label="<?= e(($seasonLabel !== '' ? $seasonLabel . ' ' : '') . 'season record') ?>">
        <?php if (!$showGoals): ?>
          <li><span class="pf-ring"><?= (int) $seasonStats['appearances'] ?></span><span class="pf-ring__k">Apps</span></li>
        <?php endif; ?>
        <li><span class="pf-ring"><?= (int) $seasonStats['starts'] ?></span><span class="pf-ring__k">Starts</span></li>
        <li><span class="pf-ring"><?= (int) $seasonStats['substitute_appearances'] ?></span><span class="pf-ring__k">Subs</span></li>
        <?php if ($showGoals): ?>
          <li><span class="pf-ring"><?= (int) $seasonStats['goals'] ?></span><span class="pf-ring__k">Goals</span></li>
        <?php endif; ?>
      </ul>
    </div>
  </section>

  <div class="pf-photowrap" aria-hidden="<?= $photoReal !== '' ? 'false' : 'true' ?>">
    <div class="pf-photo<?= $photoReal === '' ? ' pf-photo--placeholder' : '' ?>">
      <img src="<?= e($photo) ?>" alt="<?= $photoReal !== '' ? e($player['name']) : '' ?>" fetchpriority="high">
    </div>
  </div>

  <div class="container pf-main">
    <div class="pf-main__col">
      <?php if ($bioParas): ?>
        <div class="pf-bio">
          <?php foreach ($bioParas as $i => $para): ?>
            <p<?= $i === 0 ? ' class="pf-bio__lead"' : '' ?>><?= nl2br(e($para)) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="pf-tabs" data-pf-tabs>
        <div class="pf-tabs__list" role="tablist">
          <button type="button" role="tab" class="pf-tabs__tab is-active" id="pf-tab-about" aria-selected="true" aria-controls="pf-panel-about" data-pf-tab="about">About</button>
          <button type="button" role="tab" class="pf-tabs__tab" id="pf-tab-stats" aria-selected="false" aria-controls="pf-panel-stats" data-pf-tab="stats" tabindex="-1">Stats</button>
        </div>
        <div class="pf-tabs__panel" role="tabpanel" id="pf-panel-about" aria-labelledby="pf-tab-about" data-pf-panel="about">
          <ul class="pf-info">
            <?php foreach ($infoItems as [$ico, $k, $v]): ?>
              <li><?= $pfIcon($ico) ?><span><strong><?= e($k) ?></strong><?= e($v) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="pf-tabs__panel" role="tabpanel" id="pf-panel-stats" aria-labelledby="pf-tab-stats" data-pf-panel="stats" hidden>
          <ul class="pf-info pf-info--stats">
            <?php
            $statRows = ['Appearances' => (int) $seasonStats['appearances'], 'Starts' => (int) $seasonStats['starts'], 'Sub appearances' => (int) $seasonStats['substitute_appearances']];
            if ($showGoals) { $statRows['Goals'] = (int) $seasonStats['goals']; }
            if ($showCards) { $statRows['Yellow cards'] = (int) $seasonStats['yellow_cards']; $statRows['Red cards'] = (int) $seasonStats['red_cards']; }
            $statRows['Minutes played'] = (int) $seasonStats['minutes_played'];
            foreach ($statRows as $k => $v): ?>
              <li><span><strong><?= e($k) ?></strong><?= number_format($v) ?></span></li>
            <?php endforeach; ?>
            <?php if ($showShootout && $shootoutTaken > 0): ?>
              <li><span><strong>Shootout penalties</strong><?= $shootoutScored ?>/<?= $shootoutTaken ?></span></li>
            <?php endif; ?>
          </ul>
          <?php if ($seasonLabel !== ''): ?><p class="pf-note"><?= e($seasonLabel) ?> season, all competitions.<?php if ($showShootout && $shootoutTaken > 0): ?> Shootout penalties are shown as scored/taken and are not counted as goals.<?php endif; ?></p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// One sponsor holding both the home and away shirt shows as a single wide tile.
$sponsorTiles = $sponsorSlots;
$spHome = $sponsorSlots['home']['sponsors'][0] ?? null;
$spAway = $sponsorSlots['away']['sponsors'][0] ?? null;
if ($spHome !== null && $spAway !== null && strcasecmp($spHome['name'], $spAway['name']) === 0) {
    $sponsorTiles = ['both' => ['label' => 'Home & away shirt', 'sponsors' => [$spHome], 'merged' => true]];
    foreach ($sponsorSlots as $k => $v) {
        if ($k !== 'home' && $k !== 'away') { $sponsorTiles[$k] = $v; }
    }
}
?>
<?php if ($sponsorSlots): ?>
<section class="pf-kit" aria-label="Player sponsors">
  <div class="container pf-kit__grid">
    <?php foreach ($sponsorTiles as $slotKey => $slot): ?>
      <?php
      $sp = $slot['sponsors'][0] ?? null;
      $tileClass = !empty($slot['merged']) ? ' pf-tile--home pf-tile--both' : ($slot['label'] !== '' && stripos((string) $slot['label'], 'away') !== false ? ' pf-tile--away' : ' pf-tile--home');
      $tag = 'div'; $attrs = '';
      if ($sp !== null && $sp['link'] !== '') { $tag = 'a'; $attrs = ' href="' . e($sp['link']) . '" target="_blank" rel="noopener"'; }
      ?>
      <?php $tileClass .= ' pf-tile--side'; if ($sp !== null) { $tileClass .= ' pf-tile--logo'; } ?>
      <<?= $tag ?> class="pf-tile<?= $tileClass ?>"<?= $attrs ?>>
        <span class="pf-tile__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="M8 4 3 7l2 4 3-1v10h8V10l3 1 2-4-5-3c-.5 1.5-2 2.3-4 2.3S8.5 5.5 8 4z"/></svg></span>
        <span class="pf-tile__body">
          <span class="pf-tile__k"><?= e(str_ireplace('shirt', 'kit sponsor', (string) $slot['label'])) ?></span>
          <?php if ($sp !== null): ?>
            <?php if ($sp['logo'] !== ''): ?>
              <span class="pf-tile__logo"><img src="<?= e(uploads('sponsors/' . $sp['logo'])) ?>" alt="<?= e($sp['name']) ?>" loading="lazy"></span>
            <?php else: ?>
              <span class="pf-tile__logo pf-tile__logo--text"><span class="pf-tile__name"><?= e($sp['name']) ?></span></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="pf-tile__v">Available</span>
            <form method="post" action="<?= e(url('playersponsors')) ?>" class="pf-tile__form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
              <input type="hidden" name="package_code" value="player_<?= e((string) $slotKey) ?>">
              <button type="submit" class="pf-tile__cta">Become a sponsor</button>
            </form>
          <?php endif; ?>
        </span>
      </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (count($squadAll) > 1): ?>
<section class="pf-select" aria-labelledby="pf-select-h">
  <div class="container">
    <div class="pf-select__head">
      <h2 id="pf-select-h" class="pf-label">Select a player</h2>
      <div class="pf-select__nav">
        <button type="button" class="pf-select__btn" data-pf-prev aria-label="Previous players">&#8249;</button>
        <button type="button" class="pf-select__btn" data-pf-next aria-label="Next players">&#8250;</button>
      </div>
    </div>
    <div class="pf-select__track" data-pf-track>
      <?php foreach ($squadAll as $sq): ?>
        <?php
        $sqAvatar = trim((string) ($sq['avatar'] ?? ''));
        $sqPhoto = $sqAvatar !== '' && is_file(PUBLIC_ROOT . '/uploads/players/' . $sqAvatar) ? uploads('players/' . $sqAvatar) : '';
        $sqNum = $sq['squad_number'] !== null ? (int) $sq['squad_number'] : null;
        $sqParts = preg_split('/\s+/', trim((string) $sq['name'])) ?: [(string) $sq['name']];
        $sqLast = count($sqParts) > 1 ? (string) array_pop($sqParts) : (string) $sq['name'];
        $sqFirst = count($sqParts) > 0 && $sqLast !== $sq['name'] ? implode(' ', $sqParts) : '';
        $sqInit = mb_strtoupper(mb_substr($sqFirst !== '' ? $sqFirst : $sqLast, 0, 1) . mb_substr($sqLast, 0, 1));
        $isCurrent = (int) $sq['id'] === (int) $player['id'];
        ?>
        <a class="pf-card<?= $isCurrent ? ' is-current' : '' ?>" href="<?= e(url('team/' . $sq['slug'])) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
          <span class="pf-card__media">
            <?php if ($sqPhoto !== ''): ?>
              <img src="<?= e($sqPhoto) ?>" alt="" loading="lazy" data-sz="260">
            <?php else: ?>
              <img class="pf-card__ph" src="<?= e(asset('img/player-placeholder.png')) ?>" alt="" loading="lazy">
            <?php endif; ?>
          </span>
          <span class="pf-card__name"><?php if ($sqFirst !== ''): ?><small><?= e($sqFirst) ?></small><?php endif; ?><?= e($sqLast) ?></span>
          <?php if ($sqNum !== null): ?><span class="pf-card__num"><?= $sqNum ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="pf-select__back"><a href="<?= e(url('team')) ?>">Back to full squad</a></p>
  </div>
</section>
<?php endif; ?>
