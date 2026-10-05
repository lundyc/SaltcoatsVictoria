<?php
/**
 * Match-details sidebar for a news article that's linked to a fixture
 * (news_articles.fixture_id) — Saltcoats' starting XI/bench and a compact
 * match-events list, so a match report reads alongside the actual data
 * without sending the reader off to the match centre. Renders nothing if
 * the fixture isn't found or hasn't been played yet.
 *
 * @var int $fixture_id
 */
declare(strict_types=1);

$fixture = pub_fixture((int) $fixture_id);
if ($fixture === null || !pub_fixture_is_played($fixture)) {
    return;
}

$record = pub_match_record((int) $fixture['id']);
if ($record === null || !$record['lineups']['us']) {
    return;
}

$outcome = pub_fixture_outcome($fixture);
$teams = pub_fixture_teams($fixture);
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
$ourCrest = club_crest();

$starters = array_values(array_filter($record['lineups']['us'], static fn(array $p): bool => $p['starting']));
$subs = array_values(array_filter($record['lineups']['us'], static fn(array $p): bool => !$p['starting']));

$squadByName = [];
foreach (squad_public_players(db()) as $sp) {
    $squadByName[mb_strtolower(trim((string) $sp['name']))] = $sp;
}

/* --- per-player goals/cards/sub-on/sub-off, our side only ----------------- */
$goalsByPlayer = [];
$cardsByPlayer = [];
$offMinuteByPlayer = [];
$onMinuteByPlayer = [];
$replacedByPlayer = []; // on-player name => the off-player name they replaced
$timeline = $record['events'];
foreach ($timeline as $ev) {
    if ($ev['side'] !== 'us') {
        continue;
    }
    $isCard = stripos($ev['label'], 'card') !== false || stripos($ev['label'], 'yellow') !== false || stripos($ev['label'], 'red') !== false;
    if ($ev['type'] === 'goal' && $ev['player'] !== '') {
        $goalsByPlayer[$ev['player']][] = $ev;
    } elseif (($ev['type'] === 'substitution' || $ev['type'] === 'sub')) {
        if ($ev['player'] !== '') { $offMinuteByPlayer[$ev['player']] = $ev['minute']; }
        if ($ev['detail'] !== '') {
            $onMinuteByPlayer[$ev['detail']] = $ev['minute'];
            $replacedByPlayer[$ev['detail']] = $ev['player'];
        }
    } elseif ($isCard && $ev['player'] !== '') {
        $cardsByPlayer[$ev['player']][] = $ev;
    }
}

if (!function_exists('mc_glyph')) {
    /** Timeline glyph. kind: goal | own_goal | sub | yellow | red | other. Duplicated from pages/match.php (function_exists-guarded there too) since this partial can render on a page match.php never loads. */
    function mc_glyph(string $kind): string
    {
        if ($kind === 'goal' || $kind === 'own_goal') {
            return '<svg class="mc-glyph mc-glyph--goal' . ($kind === 'own_goal' ? ' mc-glyph--og' : '') . '" viewBox="0 0 24 24" aria-hidden="true">'
                . '<circle cx="12" cy="12" r="9.3" fill="#fff" stroke="currentColor" stroke-width="1.4"/>'
                . '<path d="M12 6.6l4.1 3-1.6 4.8H9.5L7.9 9.6z" fill="currentColor"/>'
                . '<path d="M12 6.6V3.3M4.9 9.6 2 8.3M9.5 14.4 7.5 17M14.5 14.4 16.5 17M19.1 9.6 22 8.3" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
        }
        if ($kind === 'sub') {
            return '<svg class="mc-glyph mc-glyph--sub" viewBox="0 0 24 24" aria-hidden="true">'
                . '<path d="M8 4.5v10m0 0-3-3m3 3 3-3" fill="none" stroke="#c0392b" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>'
                . '<path d="M16 19.5v-10m0 0-3 3m3-3 3 3" fill="none" stroke="#1b8a4b" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        if ($kind === 'yellow' || $kind === 'red') {
            return '<span class="mc-glyph mc-card mc-card--' . $kind . '" aria-hidden="true"></span>';
        }
        return '<span class="mc-glyph mc-glyph--dot" aria-hidden="true"></span>';
    }
}
if (!function_exists('mc_arrow')) {
    /** Small substitution arrow: 'on' = green/up, 'off' = red/down. */
    function mc_arrow(string $dir): string
    {
        $on = $dir === 'on';
        $d = $on ? 'M6 11V2.6m0 0L2.8 5.8M6 2.6l3.2 3.2' : 'M6 1v8.4m0 0L2.8 6.2M6 9.4l3.2-3.2';
        return '<svg class="mc-arrow mc-arrow--' . ($on ? 'on' : 'off') . '" viewBox="0 0 12 12" aria-hidden="true">'
            . '<path d="' . $d . '" fill="none" stroke="' . ($on ? '#1b8a4b' : '#c0392b') . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
}

$squadRow = static function (array $p, bool $isSub) use ($squadByName, $goalsByPlayer, $cardsByPlayer, $offMinuteByPlayer, $onMinuteByPlayer, $replacedByPlayer): void {
    $name = $p['name'];
    $sq = $squadByName[mb_strtolower(trim($name))] ?? null;
    $avatar = trim((string) ($sq['avatar'] ?? ''));
    $photo = $avatar !== '' ? uploads('players/' . $avatar) : '';
    $number = $p['number'] !== null ? (int) $p['number'] : ($sq['squad_number'] !== null ? (int) $sq['squad_number'] : null);
    $slug = trim((string) ($sq['slug'] ?? ''));
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) { $initials .= mb_substr($part, 0, 1); }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2));

    $goals = $goalsByPlayer[$name] ?? [];
    $cards = $cardsByPlayer[$name] ?? [];
    $off = $offMinuteByPlayer[$name] ?? null;
    $on = $onMinuteByPlayer[$name] ?? null;
    ?>
    <li class="mrs-row">
      <span class="mrs-row__media">
        <?php if ($number !== null): ?><span class="mrs-row__num"><?= $number ?></span><?php endif; ?>
        <?php if ($photo !== ''): ?>
          <img src="<?= e($photo) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="mrs-row__initials" aria-hidden="true"><?= e($initials) ?></span>
        <?php endif; ?>
      </span>
      <span class="mrs-row__body">
        <span class="mrs-row__name">
          <?php if ($slug !== ''): ?><a href="<?= e(url('team/' . $slug)) ?>"><?= e($name) ?></a><?php else: ?><?= e($name) ?><?php endif; ?>
          <?php if ($p['captain']): ?><span class="mrs-row__c" title="Captain">C</span><?php endif; ?>
        </span>
        <?php if ($isSub && $on !== null): ?>
          <span class="mrs-row__sub"><?= mc_arrow('on') ?> on for <?= e((string) ($replacedByPlayer[$name] ?? '')) ?><?= e($on !== '' ? ', ' . $on . "'" : '') ?></span>
        <?php elseif (!$isSub && $off !== null): ?>
          <span class="mrs-row__sub"><?= mc_arrow('off') ?> <?= e($off !== '' ? $off . "'" : '') ?></span>
        <?php endif; ?>
      </span>
      <?php if ($goals || $cards): ?>
        <span class="mrs-row__events">
          <?php foreach ($goals as $g): ?>
            <?= mc_glyph(strcasecmp($g['label'], 'Own goal') === 0 ? 'own_goal' : 'goal') ?>
          <?php endforeach; ?>
          <?php foreach ($cards as $c): $cm = stripos($c['label'], 'red') !== false ? 'red' : 'yellow'; ?>
            <span class="mc-card mc-card--<?= $cm ?>" aria-label="<?= e($c['label']) ?>"></span>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    </li>
    <?php
};
?>
<aside class="article__sidebar mrs">
  <div class="mrs__panel">
    <?php
    $homeCrest = $fixture['is_home'] ? $ourCrest : $oppCrest;
    $awayCrest = $fixture['is_home'] ? $oppCrest : $ourCrest;
    ?>
    <div class="mrs__score">
      <div class="mrs__score-team">
        <?php if ($homeCrest): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
        <span class="mrs__score-name"><?= e($teams['home']) ?></span>
      </div>
      <span class="mrs__score-value"><?= (int) $fixture['full_time_home_score'] ?>&ndash;<?= (int) $fixture['full_time_away_score'] ?></span>
      <div class="mrs__score-team">
        <?php if ($awayCrest): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
        <span class="mrs__score-name"><?= e($teams['away']) ?></span>
      </div>
    </div>
    <?php if ($outcome['decided_by_penalties']): ?>
      <div class="mrs__pens">(Pens <?= (int) $fixture['home_penalties'] ?>&ndash;<?= (int) $fixture['away_penalties'] ?>)</div>
    <?php endif; ?>
    <a class="btn mrs__mclink" href="<?= e(url('match/' . (int) $fixture['id'])) ?>">View full match centre <span aria-hidden="true">&rarr;</span></a>
  </div>

  <?php $matchSponsors = pub_match_sponsors((int) $fixture['id']); ?>
  <?php if ($matchSponsors): ?>
    <div class="mrs__panel mrs__sponsors">
      <h3 class="mrs__heading">Match sponsors</h3>
      <ul class="mrs__sponsor-list">
        <?php foreach ($matchSponsors as $sp): ?>
          <li class="mrs__sponsor">
            <span class="mrs__sponsor-role"><?= e($sp['label']) ?></span>
            <?php $inner = $sp['logo'] !== ''
                ? '<img src="' . e(uploads('sponsors/' . $sp['logo'])) . '" alt="' . e($sp['name']) . '" loading="lazy">'
                : '<span class="mrs__sponsor-name">' . e($sp['name']) . '</span>'; ?>
            <?php if ($sp['link'] !== ''): ?>
              <a class="mrs__sponsor-logo" href="<?= e($sp['link']) ?>" target="_blank" rel="noopener sponsored"><?= $inner ?></a>
            <?php else: ?>
              <span class="mrs__sponsor-logo"><?= $inner ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="mrs__panel">
    <h3 class="mrs__heading">Saltcoats Starting XI</h3>
    <ul class="mrs__list">
      <?php foreach ($starters as $p) { $squadRow($p, false); } ?>
    </ul>
    <?php if ($subs): ?>
      <h3 class="mrs__heading mrs__heading--sub">On the Bench</h3>
      <ul class="mrs__list">
        <?php foreach ($subs as $p) { $squadRow($p, true); } ?>
      </ul>
    <?php endif; ?>
  </div>
</aside>
