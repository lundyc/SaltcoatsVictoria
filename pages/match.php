<?php
/** Route: /match/{id} — match centre. */
declare(strict_types=1);

$fixture = pub_fixture((int) ($id ?? 0));
if ($fixture === null) {
    http_response_code(404);
    set_meta(['title' => 'Match not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Match not found</h1><p><a class="linkarrow" href="' . e(url('fixtures')) . '">All fixtures</a></p></div></div>';
    return;
}

$teams = pub_fixture_teams($fixture);
$played = pub_fixture_is_played($fixture);
$outcome = pub_fixture_outcome($fixture);
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
$ourCrest = club_crest();
$homeCrest = $fixture['is_home'] ? $ourCrest : $oppCrest;
$awayCrest = $fixture['is_home'] ? $oppCrest : $ourCrest;
$venue = trim((string) ($fixture['venue'] ?? '')) ?: ($fixture['is_home'] ? club('ground_name') : '');
$ko = format_time($fixture['kickoff_time'] ?? '');

/* --- match data: prefer the normalised both-teams record, else legacy ---- */
$record = $played ? pub_match_record((int) $fixture['id']) : null;
$legacyEvents = $played ? pub_match_events((int) $fixture['id']) : [];

// Player of the match — only the legacy store carries it.
$potm = null;
foreach ($legacyEvents as $ev) {
    if (in_array($ev['type'], ['player_of_match', 'motm', 'man_of_the_match'], true)) {
        $potm = $ev;
        break;
    }
}

$motmSponsor = ($played && $fixture['is_home']) ? pub_motm_sponsor((int) $fixture['id']) : null;

$legacyLineup = pub_match_lineup((int) $fixture['id']);
$mkPlayers = static function (array $names, string $captain): array {
    $out = [];
    foreach ($names as $n) {
        $n = trim((string) $n);
        if ($n === '') { continue; }
        $out[] = ['name' => $n, 'number' => null, 'pos' => '', 'captain' => $n === $captain, 'starting' => true];
    }
    return $out;
};

if ($record) {
    $split = static fn (array $rows, bool $starting): array =>
        array_values(array_filter($rows, static fn (array $p): bool => $p['starting'] === $starting));
    $xi = [
        'us' => [
            'starters' => $split($record['lineups']['us'], true),
            'subs' => $split($record['lineups']['us'], false),
            'captain' => $record['captain']['us'],
        ],
        'opp' => [
            'starters' => $split($record['lineups']['opp'], true),
            'subs' => $split($record['lineups']['opp'], false),
            'captain' => $record['captain']['opp'],
        ],
    ];
    $timeline = $record['events'];
} else {
    $xi = [
        'us' => [
            'starters' => $mkPlayers($legacyLineup['starters'], $legacyLineup['captain']),
            'subs' => $mkPlayers($legacyLineup['substitutes'], $legacyLineup['captain']),
            'captain' => $legacyLineup['captain'],
        ],
        'opp' => ['starters' => [], 'subs' => [], 'captain' => ''],
    ];
    $timeline = array_values(array_filter(
        $legacyEvents,
        static fn (array $e): bool => !in_array($e['type'], ['player_of_match', 'motm', 'man_of_the_match'], true)
    ));
}

$haveOppXI = (bool) $xi['opp']['starters'];

$photos  = pub_match_photos((int) $fixture['id']);
$album   = pub_gallery_for_fixture((int) $fixture['id']);
$related = news_for_fixture(db(), (int) $fixture['id']);
$report   = $related[0] ?? null;           // newest linked article == match report
$moreNews = array_slice($related, 1);
$archiveReport = $played ? pub_fixture_history_report((int) $fixture['id']) : null;   // old written report, migrated from the results archive
$veo = trim((string) ($fixture['veo_url'] ?? ''));

/* --- upcoming-fixture preview extras (weather / H2H / league / form) ----- */
$preview = null;
if (!$played) {
    $ourRow = pub_league_our_row();
    $oppRow = pub_league_find_row((string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
    $showLeagueTable = pub_is_league_fixture((string) ($fixture['competition'] ?? '')) && $ourRow && $oppRow;
    $preview = [
        'weather'  => pub_weather_forecast($fixture),
        'h2h'      => pub_head_to_head($fixture, 10),
        'showLeagueTable' => $showLeagueTable,
        'ourRow'   => $ourRow,
        'oppRow'   => $oppRow,
        'ourForm'  => array_slice((array) ($ourRow['form'] ?? []), -5),
        'oppForm'  => array_slice((array) ($oppRow['form'] ?? []), -5),
        'ourFormLinks' => pub_our_form(5),
    ];
}

/* --- our squad, keyed by name, for links / photos / positions ------------ */
$squadByName = [];
foreach (squad_public_players(db()) as $sp) {
    $squadByName[mb_strtolower(trim((string) $sp['name']))] = $sp;
}
$findPlayer = static function (string $name) use ($squadByName): ?array {
    return $squadByName[mb_strtolower(trim($name))] ?? null;
};
$renderName = static function (string $name, bool $ours) use ($findPlayer): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $p = $ours ? $findPlayer($name) : null;
    return $p
        ? '<a class="mc-plink" href="' . e(url('team/' . $p['slug'])) . '">' . e($name) . '</a>'
        : e($name);
};

/* --- per-side sub on/off (+ minute) and card chips, from the timeline ---- */
$evMap = [
    'us'  => ['on' => [], 'off' => [], 'cards' => []],
    'opp' => ['on' => [], 'off' => [], 'cards' => []],
];
$evMap['us']['goals'] = [];
$evMap['opp']['goals'] = [];
$oppSubs = []; // only used for the placeholder column (opponent XI unknown)
foreach ($timeline as $ev) {
    $side = $ev['side'] === 'us' ? 'us' : 'opp';
    $isCard = stripos($ev['label'], 'card') !== false || stripos($ev['label'], 'yellow') !== false;
  if ($ev['type'] === 'goal') {
    if ($ev['player'] !== '') { $evMap[$side]['goals'][$ev['player']][] = $ev; }
  } elseif ($ev['type'] === 'substitution' || $ev['type'] === 'sub') {
        if ($ev['player'] !== '') { $evMap[$side]['off'][$ev['player']] = $ev['minute']; }
        if ($ev['detail'] !== '') { $evMap[$side]['on'][$ev['detail']] = $ev['minute']; }
        if ($side === 'opp') {
            $oppSubs[] = ['on' => $ev['detail'], 'off' => $ev['player'], 'minute' => $ev['minute']];
        }
    } elseif ($isCard && $ev['player'] !== '') {
        $evMap[$side]['cards'][$ev['player']][] = $ev;
    }
}

  $ftHome = $fixture['full_time_home_score'];
  $ftAway = $fixture['full_time_away_score'];
  if (($ftHome === null || $ftAway === null) && $timeline) {
    $derivedHome = $derivedAway = 0;
    foreach ($timeline as $ev) {
      if ($ev['type'] !== 'goal') { continue; }
      if (($ev['side'] === 'us') === (bool) $fixture['is_home']) {
        $derivedHome++;
      } else {
        $derivedAway++;
      }
    }
    $ftHome ??= $derivedHome;
    $ftAway ??= $derivedAway;
  }

/* --- do we hold any shirt numbers for the XI we're about to show? -------- */
$anyNums = false;
foreach (['us', 'opp'] as $s) {
    if ($s === 'opp' && !$haveOppXI) { continue; }
    foreach (array_merge($xi[$s]['starters'], $xi[$s]['subs']) as $p) {
        if ($p['number'] !== null) { $anyNums = true; break 2; }
    }
}

/* --- one row of a rich line-up column ----------------------------------- */
$lineupRow = static function (array $p, string $who, bool $anyNums, bool $subList) use ($findPlayer, $renderName, $evMap): void {
    $isUs = $who === 'us';
    $name = $p['name'];
    $goals = $evMap[$who]['goals'][$name] ?? [];
    $cards = $evMap[$who]['cards'][$name] ?? [];
    $off = $evMap[$who]['off'][$name] ?? null;
    $on = $evMap[$who]['on'][$name] ?? null;
    ?>
    <div class="match-lineups__row<?= $p['captain'] ? ' is-captain' : '' ?><?= ($subList && $on !== null) ? ' is-used' : '' ?>">
      <?php if ($anyNums): ?><span class="match-lineups__num"><?= $p['number'] !== null ? (int) $p['number'] : '' ?></span><?php endif; ?>
      <span class="match-lineups__name">
        <span class="match-lineups__name-text"><?= $renderName($name, $isUs) ?></span>
        <?php if ($p['captain']): ?><span class="match-lineups__c" title="Captain">C</span><?php endif; ?>
      </span>
      <?php if ($goals || $cards || ($subList ? $on !== null : $off !== null)): ?>
        <span class="match-lineups__events">
          <?php if ($subList && $on !== null): ?>
            <span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($on !== '' ? $on . "'" : '') ?></span><?= mc_arrow('on') ?><span class="sr-only">Substituted on</span></span>
          <?php endif; ?>
          <?php foreach ($goals as $goal): ?>
            <span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($goal['minute'] !== '' ? $goal['minute'] . "'" : '') ?></span><?= mc_glyph(strcasecmp($goal['label'], 'Own goal') === 0 ? 'own_goal' : 'goal') ?><span class="sr-only"><?= e($goal['label']) ?></span></span>
          <?php endforeach; ?>
          <?php foreach ($cards as $c): $cm = stripos($c['label'], 'red') !== false ? 'red' : 'yellow'; ?>
            <span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($c['minute'] !== '' ? $c['minute'] . "'" : '') ?></span><span class="mc-card mc-card--<?= $cm ?>" aria-label="<?= e($c['label']) ?>"></span></span>
          <?php endforeach; ?>
          <?php if (!$subList && $off !== null): ?>
            <span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($off !== '' ? $off . "'" : '') ?></span><?= mc_arrow('off') ?><span class="sr-only">Substituted off</span></span>
          <?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
    <?php
};

/* --- half-time score: from the fixture, else derived from goals <= 45 ---- */
$htHome = $fixture['half_time_home_score'];
$htAway = $fixture['half_time_away_score'];
if (($htHome === null || $htAway === null) && $timeline) {
    $h = $a = 0;
    foreach ($timeline as $ev) {
        if ($ev['type'] !== 'goal' || $ev['minute'] === '') {
            continue;
        }
        if ((int) preg_replace('/\D+/', '', $ev['minute']) > 45) {
            continue;
        }
        (($ev['side'] === 'us') === (bool) $fixture['is_home']) ? $h++ : $a++;
    }
    $htHome = $h;
    $htAway = $a;
}

/* --- one-off view helpers --------------------------------------------------- */
if (!function_exists('mc_initials')) {
    function mc_initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            $out .= mb_substr($part, 0, 1);
        }
        return mb_strtoupper(mb_substr($out, 0, 2));
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
if (!function_exists('mc_glyph')) {
    /** Timeline glyph. kind: goal | own_goal | sub | yellow | red | other. */
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

/** Our first-team manager (for the line-up card). */
$ourManager = '';
foreach (pub_staff_management() as $person) {
    $pos = strtolower((string) ($person['position'] ?? ''));
    if (str_contains($pos, 'manager') && !str_contains($pos, 'assistant')) {
        $ourManager = (string) $person['name'];
        break;
    }
}

$ftHome ??= null;
$ftAway ??= null;
$compLabel = (string) ($fixture['competition'] ?: 'Fixture');
$dateLong = format_date($fixture['match_date'], 'j M Y');
if ($played && $ftHome !== null && $ftAway !== null) {
    $matchTitle = $teams['home'] . ' ' . (int) $ftHome . '–' . (int) $ftAway . ' ' . $teams['away'] . ' | Match Report, ' . $dateLong;
    $matchDesc = 'Match report: ' . $teams['home'] . ' ' . (int) $ftHome . '–' . (int) $ftAway . ' ' . $teams['away']
        . ' in the ' . $compLabel . ' on ' . $dateLong . ($venue !== '' ? ' at ' . $venue : '') . '. Full-time result, line-ups and match events.';
} else {
    $matchTitle = $teams['home'] . ' v ' . $teams['away'] . ' | ' . $compLabel . ', ' . $dateLong;
    $matchDesc = $compLabel . ': ' . $teams['home'] . ' v ' . $teams['away'] . ' on ' . format_date($fixture['match_date'], 'l j F Y')
        . ($ko !== '' ? ', kick-off ' . $ko : '') . ($venue !== '' ? ' at ' . $venue : '') . '.';
}
// A past fixture with no recorded result has nothing to show — keep it out of the index.
$matchThin = !$played && $fixture['match_date'] < date('Y-m-d');
set_meta([
    'title' => $matchTitle,
    'title_full' => '1',
    'description' => $matchDesc,
    'robots' => $matchThin ? 'noindex, follow' : '',
]);
seo_breadcrumbs([
    [$played ? 'Results' : 'Fixtures', url($played ? 'results' : 'fixtures')],
    [$teams['home'] . ' v ' . $teams['away'], url('match/' . (int) $fixture['id'])],
]);

$tz = new DateTimeZone('Europe/London');
$startIso = (new DateTimeImmutable($fixture['match_date'] . ' ' . ($fixture['kickoff_time'] ?: '15:00:00'), $tz))->format('c');
$fxStatus = strtolower((string) ($fixture['status'] ?? ''));
$eventStatus = match (true) {
    str_contains($fxStatus, 'postpon') => 'https://schema.org/EventPostponed',
    str_contains($fxStatus, 'cancel'), str_contains($fxStatus, 'abandon'), str_contains($fxStatus, 'off') => 'https://schema.org/EventCancelled',
    default => 'https://schema.org/EventScheduled',
};
$homeNode = ['@type' => 'SportsTeam', 'name' => $teams['home']];
$awayNode = ['@type' => 'SportsTeam', 'name' => $teams['away']];
pub_jsonld([
    '@type' => 'SportsEvent',
    'name' => $teams['home'] . ' v ' . $teams['away'],
    'url' => seo_canonical(),
    'sport' => 'Football',
    'startDate' => $startIso,
    'eventStatus' => $eventStatus,
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'location' => $venue !== '' ? ['@type' => 'Place', 'name' => $venue,
        'address' => $fixture['is_home'] ? club('ground_address') : null] : null,
    'homeTeam' => $homeNode,
    'awayTeam' => $awayNode,
    'superEvent' => $compLabel !== 'Fixture' ? ['@type' => 'SportsEvent', 'name' => $compLabel] : null,
    'description' => $matchDesc,
]);
?>
<section class="mc-hero">
  <div class="container">
    <h1 class="sr-only"><?= e($played && $ftHome !== null && $ftAway !== null ? $teams['home'] . ' ' . (int) $ftHome . '–' . (int) $ftAway . ' ' . $teams['away'] : $teams['home'] . ' v ' . $teams['away']) ?> — <?= e($compLabel) ?>, <?= e($dateLong) ?></h1>
    <p class="mc-hero__comp"><?= e($fixture['competition'] ?: 'Fixture') ?><?php if ($fixture['competition_stage']): ?> · <?= e($fixture['competition_stage']) ?><?php endif; ?></p>
    <div class="mc-hero__grid">
      <div class="mc-hero__team">
        <?php if ($homeCrest): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
        <span><?= e($teams['home']) ?></span>
      </div>
      <div class="mc-hero__mid">
        <?php if ($played): ?>
          <b class="mc-hero__score"><?= (int) $ftHome ?> &ndash; <?= (int) $ftAway ?></b>
          <?php if ($outcome['decided_by_penalties']): ?>
            <span class="mc-hero__pens">(Pens <?= (int) $fixture['home_penalties'] ?>&ndash;<?= (int) $fixture['away_penalties'] ?>)</span>
          <?php elseif ($fixture['half_time_home_score'] !== null): ?>
            <span class="mc-hero__ht">HT <?= (int) $fixture['half_time_home_score'] ?>&ndash;<?= (int) $fixture['half_time_away_score'] ?></span>
          <?php endif; ?>
        <?php else: ?>
          <b class="mc-hero__ko"><?= e($ko ?: 'TBC') ?></b>
        <?php endif; ?>
        <span class="mc-hero__date"><?= e(format_date($fixture['match_date'], 'D j M Y')) ?></span>
      </div>
      <div class="mc-hero__team">
        <?php if ($awayCrest): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
        <span><?= e($teams['away']) ?></span>
      </div>
    </div>
    <?php if ($venue): ?><p class="mc-hero__venue"><?= e($venue) ?></p><?php endif; ?>
  </div>
</section>

<div class="container mc-body">
  <div class="mc-main">
    <?php if (!$played):
        $usShort = club('club_short_name', 'Saltcoats Vics');
        $oppLabel = (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? 'their opponents');
        $h2h = $preview['h2h'] ?? null;
    ?>
      <div class="mc-panel">
        <h2>Preview</h2>
        <p><?= e($usShort) ?> <?= $fixture['is_home'] ? 'host ' . e($oppLabel) : 'travel to ' . e($oppLabel) ?>
           in the <?= e($fixture['competition'] ?: 'league') ?>
           on <?= e(format_date($fixture['match_date'], 'l j F')) ?><?= $ko ? ', ' . e($ko) . ' kick-off' : '' ?><?= $venue ? ' at ' . e($venue) : '' ?>.</p>
        <p><a class="btn btn--sm" href="/tickets">Match tickets</a></p>
      </div>

      <?php if ($preview['showLeagueTable']):
          $lrows = [$preview['ourRow'], $preview['oppRow']];
          usort($lrows, static fn ($a, $b) => (int) $a['pos'] <=> (int) $b['pos']);
      ?>
        <div class="mc-panel mc-ltable">
          <h2><?= e(pub_league_config()['title']) ?></h2>
          <table class="mc-ltable__grid">
            <thead>
              <tr><th class="c">#</th><th>Club</th><th class="c">P</th><th class="c">W</th><th class="c">D</th><th class="c">L</th><th class="c">GD</th><th class="c">Pts</th></tr>
            </thead>
            <tbody>
              <?php foreach ($lrows as $r): $mine = pub_league_is_us($r); $lg = trim((string) ($r['logo'] ?? '')); ?>
                <tr class="<?= $mine ? 'is-us' : '' ?>">
                  <td class="c"><?= (int) $r['pos'] ?></td>
                  <td class="club">
                    <?php if ($lg !== ''): ?><img src="/<?= e(ltrim($lg, '/')) ?>" alt="" loading="lazy"><?php endif; ?>
                    <span><?= e($r['club']) ?></span>
                  </td>
                  <td class="c"><?= e($r['p']) ?></td>
                  <td class="c"><?= e($r['w']) ?></td>
                  <td class="c"><?= e($r['d']) ?></td>
                  <td class="c"><?= e($r['l']) ?></td>
                  <td class="c"><?= e($r['gd']) ?></td>
                  <td class="c"><b><?= e($r['pts']) ?></b></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><a class="linkarrow" href="<?= e(url('table')) ?>">Full league table</a></p>
        </div>
      <?php endif; ?>

      <?php
      // Keep both teams' form from the same source when we can: use the league
      // table's form for both if the opponent is in it, else our recent results.
      $ourFormChips = $preview['oppForm'] && $preview['ourForm'] ? $preview['ourForm'] : [];
      if ($ourFormChips || $preview['ourFormLinks'] || $preview['oppForm']): ?>
        <div class="mc-panel mc-form">
          <h2>Form</h2>
          <div class="mc-form__team">
            <span class="mc-form__name"><?= e(club('club_short_name', 'Saltcoats Vics')) ?></span>
            <span class="mc-form__chips">
              <?php if ($ourFormChips): ?>
                <?php foreach ($ourFormChips as $g): ?><span class="chip chip--<?= e($g) ?>"><?= e($g) ?></span><?php endforeach; ?>
              <?php else: ?>
                <?php foreach ($preview['ourFormLinks'] as $g): ?>
                  <a class="chip chip--<?= e($g['outcome']) ?>" href="<?= e($g['href']) ?>" title="<?= e($g['label']) ?>"><?= e($g['outcome']) ?></a>
                <?php endforeach; ?>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($preview['oppForm']): ?>
            <div class="mc-form__team">
              <span class="mc-form__name"><?= e($oppLabel) ?></span>
              <span class="mc-form__chips">
                <?php foreach ($preview['oppForm'] as $g): ?><span class="chip chip--<?= e($g) ?>"><?= e($g) ?></span><?php endforeach; ?>
              </span>
            </div>
          <?php endif; ?>
          <p class="mc-form__note">Oldest left, most recent right<?= $preview['oppForm'] ? ' · league form' : '' ?>.</p>
        </div>
      <?php endif; ?>

      <?php if ($h2h): ?>
        <div class="mc-panel mc-h2h">
          <h2>Head-to-head</h2>
          <div class="mc-h2h__head">
            <span class="mc-h2h__crest"><?php if ($ourCrest): ?><img src="<?= e($ourCrest) ?>" alt="<?= e($usShort) ?>" width="44" height="44" loading="lazy"><?php endif; ?></span>
            <div class="mc-h2h__tally">
              <span class="mc-h2h__stat mc-h2h__stat--w"><b><?= (int) $h2h['w'] ?></b><span>Wins</span></span>
              <span class="mc-h2h__stat mc-h2h__stat--d"><b><?= (int) $h2h['d'] ?></b><span>Draws</span></span>
              <span class="mc-h2h__stat mc-h2h__stat--l"><b><?= (int) $h2h['l'] ?></b><span>Wins</span></span>
            </div>
            <span class="mc-h2h__crest"><?php if ($oppCrest): ?><img src="<?= e($oppCrest) ?>" alt="<?= e($oppLabel) ?>" width="44" height="44" loading="lazy"><?php endif; ?></span>
          </div>
          <p class="mc-h2h__meta"><?= (int) $h2h['played'] ?> meeting<?= $h2h['played'] === 1 ? '' : 's' ?> &middot; goals <?= (int) $h2h['gf'] ?>&ndash;<?= (int) $h2h['ga'] ?></p>
          <ul class="mc-h2h__list">
            <?php foreach ($h2h['meetings'] as $m):
                $homeName  = $m['is_home'] ? $usShort : $oppLabel;
                $awayName  = $m['is_home'] ? $oppLabel : $usShort;
                $homeCrestU = $m['is_home'] ? $ourCrest : $oppCrest;
                $awayCrestU = $m['is_home'] ? $oppCrest : $ourCrest;
                $hs = $m['is_home'] ? (int) $m['us'] : (int) $m['them'];
                $as = $m['is_home'] ? (int) $m['them'] : (int) $m['us'];
                $res = strtolower((string) $m['result']) ?: 'd';
                $mUrl = (string) ($m['url'] ?? '');
                $tag = $mUrl !== '' ? 'a' : 'div';
                $href = $mUrl !== '' ? ' href="' . e($mUrl) . '"' : '';
            ?>
              <li class="mc-h2h__match">
                <<?= $tag ?> class="mc-h2h__link"<?= $href ?>>
                  <div class="mc-h2h__when">
                    <span class="mc-h2h__date"><?= e(format_date($m['date'], 'j M Y')) ?></span>
                    <span class="mc-h2h__comp"><?= e($m['competition'] ?: 'Match') ?></span>
                  </div>
                  <div class="mc-h2h__row">
                    <div class="mc-h2h__side mc-h2h__side--home">
                      <span class="mc-h2h__tname"><?= e($homeName) ?></span>
                      <?php if ($homeCrestU): ?><img class="mc-h2h__ticon" src="<?= e($homeCrestU) ?>" alt="" width="22" height="22" loading="lazy"><?php endif; ?>
                    </div>
                    <span class="mc-h2h__result mc-h2h__result--<?= e($res) ?>"><?= $hs ?> - <?= $as ?></span>
                    <div class="mc-h2h__side mc-h2h__side--away">
                      <?php if ($awayCrestU): ?><img class="mc-h2h__ticon" src="<?= e($awayCrestU) ?>" alt="" width="22" height="22" loading="lazy"><?php endif; ?>
                      <span class="mc-h2h__tname"><?= e($awayName) ?></span>
                    </div>
                  </div>
                </<?= $tag ?>>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($potm):
        $pp = $findPlayer($potm['player']);
        $ppAvatar = $pp ? trim((string) $pp['avatar']) : '';
        $ppPhoto  = $ppAvatar !== '' ? uploads('players/' . $ppAvatar) : '';
        $ppNote   = trim((string) $potm['detail']);
        if ($ppNote !== '' && stripos($ppNote, 'match') !== false) { $ppNote = ''; }
    ?>
      <div class="mc-panel mc-motm">
        <div class="mc-motm__media">
          <?php if ($ppPhoto !== ''): ?>
            <img src="<?= e($ppPhoto) ?>" alt="<?= e($potm['player']) ?>" loading="lazy">
          <?php else: ?>
            <span class="mc-motm__initials" aria-hidden="true"><?= e(mc_initials($potm['player'])) ?></span>
          <?php endif; ?>
        </div>
        <div class="mc-motm__body">
          <span class="mc-motm__label">&#9733; Player of the Match</span>
          <?php if ($motmSponsor): ?><span class="mc-motm__sponsor">Sponsored by <?= e($motmSponsor['name']) ?></span><?php endif; ?>
          <span class="mc-motm__name">
            <?php if ($pp): ?>
              <a href="<?= e(url('team/' . $pp['slug'])) ?>"><?= e($potm['player']) ?></a>
            <?php else: ?>
              <?= e($potm['player']) ?>
            <?php endif; ?>
          </span>
          <?php if ($pp && $pp['position_label'] !== ''): ?><span class="mc-motm__pos"><?= e($pp['position_label']) ?></span><?php endif; ?>
          <?php if ($ppNote !== ''): ?><p class="mc-motm__note"><?= e($ppNote) ?></p><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($timeline): ?>
      <div class="mc-panel">
        <h2>Match events</h2>
        <div class="match-timeline">
          <?php
          $rh = $ra = 0; // running score, home – away
          $htShown = false;
          foreach ($timeline as $ev):
              $evIsHome = (($ev['side'] === 'us') === (bool) $fixture['is_home']);
              $mnum = $ev['minute'] === '' ? null : (int) preg_replace('/\D+/', '', $ev['minute']);

              if ($played && !$htShown && $mnum !== null && $mnum > 45):
              ?>
                <div class="match-timeline__break"><span>Half-time &middot; <?= (int) $htHome ?> &ndash; <?= (int) $htAway ?></span></div>
              <?php
              $htShown = true;
              endif;

              $isGoal = $ev['type'] === 'goal';
              $isOG = strcasecmp($ev['label'], 'Own goal') === 0;
              $isSub = $ev['type'] === 'substitution' || $ev['type'] === 'sub';
              if ($isGoal) {
                  $evIsHome ? $rh++ : $ra++;
              }
              $kind = $isGoal ? ($isOG ? 'own_goal' : 'goal')
                  : ($isSub ? 'sub'
                  : (stripos($ev['label'], 'red') !== false ? 'red'
                  : (stripos($ev['label'], 'card') !== false || stripos($ev['label'], 'yellow') !== false ? 'yellow' : 'other')));
              $crest = $evIsHome ? $homeCrest : $awayCrest;
              $crestAlt = $evIsHome ? $teams['home'] : $teams['away'];
              $ours = $ev['side'] === 'us';
          ?>
            <div class="match-timeline__event match-timeline__event--<?= $evIsHome ? 'home' : 'away' ?>" data-side="<?= $evIsHome ? 'home' : 'away' ?>">
              <div class="match-timeline__icon">
                <?= mc_glyph($kind) ?>
                <span class="match-timeline__minute"><?= e($ev['minute'] !== '' ? $ev['minute'] . "'" : '') ?></span>
              </div>
              <?php if ($crest): ?><img class="match-timeline__crest" src="<?= e($crest) ?>" alt="<?= e($crestAlt) ?>" width="26" height="26" loading="lazy"><?php endif; ?>
              <?php if ($isSub): ?>
                <div class="match-timeline__text match-timeline__text--sub">
                  <span class="match-timeline__sub-line"><span class="sr-only">On: </span><span class="match-timeline__name"><?= $renderName($ev['detail'], $ours) ?></span></span>
                  <span class="match-timeline__sub-line"><span class="sr-only">Off: </span><span class="match-timeline__name match-timeline__name--off"><?= $renderName($ev['player'], $ours) ?></span></span>
                </div>
              <?php else: ?>
                <div class="match-timeline__text">
                  <span class="match-timeline__name"><?= $renderName($ev['player'], $ours && !$isOG) ?></span>
                  <?php if ($isGoal): ?>
                    <span class="match-timeline__score"><?= $rh ?>&ndash;<?= $ra ?></span>
                    <?php if ($isOG): ?><span class="match-timeline__tag">Own goal</span><?php endif; ?>
                  <?php elseif ($kind === 'yellow' || $kind === 'red'): ?>
                    <span class="match-timeline__tag"><?= e($ev['label']) ?></span>
                  <?php elseif ($ev['label'] !== ''): ?>
                    <span class="match-timeline__tag"><?= e($ev['label']) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($played): ?>
            <div class="match-timeline__break match-timeline__break--ft"><span>Full-time &middot; <?= (int) $ftHome ?> &ndash; <?= (int) $ftAway ?></span></div>
            <?php if ($outcome['decided_by_penalties']): ?>
              <div class="match-timeline__break match-timeline__break--pens"><span>(PEN <?= (int) $fixture['home_penalties'] ?>-<?= (int) $fixture['away_penalties'] ?>)</span></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($xi['us']['starters'] || $haveOppXI):
        // Column order follows the hero: home team left, away team right.
        $cols = [
            'home' => $fixture['is_home'] ? 'us' : 'opp',
            'away' => $fixture['is_home'] ? 'opp' : 'us',
        ];
    ?>
      <div class="mc-panel">
        <h2>Line-ups</h2>
        <div class="match-lineups">
          <div class="match-lineups__grid">
            <?php foreach ($cols as $sideName => $who):
                $isUs = $who === 'us';
                $col = $xi[$who];
                $rich = $isUs || $haveOppXI;
                $badge = $isUs ? $ourCrest : $oppCrest;
            ?>
              <div class="match-lineups__team<?= $rich ? '' : ' match-lineups__team--opp' ?>" data-side="<?= e($sideName) ?>">
                <div class="match-lineups__head">
                  <?php if ($badge): ?><img class="match-lineups__badge" src="<?= e($badge) ?>" alt="" width="34" height="34" loading="lazy"><?php endif; ?>
                  <span class="match-lineups__teamname"><?= e($isUs ? club('club_short_name', 'Saltcoats Vics') : $teams[$sideName]) ?></span>
                </div>
                <div class="match-lineups__body<?= $anyNums ? '' : ' match-lineups__body--nonums' ?>">
                  <?php if ($rich): ?>
                    <div class="match-lineups__label">Starting XI</div>
                    <?php foreach ($col['starters'] as $p) { $lineupRow($p, $who, $anyNums, false); } ?>

                    <?php if ($col['subs']): ?>
                      <div class="match-lineups__label match-lineups__label--subs">Substitutes</div>
                      <?php foreach ($col['subs'] as $p) { $lineupRow($p, $who, $anyNums, true); } ?>
                    <?php endif; ?>

                    <?php if ($isUs && $ourManager !== ''): ?>
                      <div class="match-lineups__manager">
                        <span class="match-lineups__manager-label">Manager</span>
                        <span class="match-lineups__manager-value"><?= e($ourManager) ?></span>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($oppSubs): ?>
                      <div class="match-lineups__label">Substitutes used</div>
                      <?php foreach ($oppSubs as $s): ?>
                        <div class="match-lineups__row match-lineups__row--subline">
                          <span class="match-lineups__name">
                            <span class="match-lineups__name-text"><?= e($s['on']) ?></span>
                            <?php if ($s['off'] !== ''): ?><span class="match-lineups__sub-for">for <?= e($s['off']) ?></span><?php endif; ?>
                          </span>
                          <span class="match-lineups__events"><span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($s['minute'] !== '' ? $s['minute'] . "'" : '') ?></span><?= mc_arrow('on') ?></span></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <?php $scorers = []; foreach ($evMap[$who]['goals'] as $playerGoals) { $scorers = array_merge($scorers, $playerGoals); } ?>
                    <?php if ($scorers): ?>
                      <div class="match-lineups__label">Goals</div>
                      <?php foreach ($scorers as $scorer): ?>
                        <div class="match-lineups__row match-lineups__row--subline">
                          <span class="match-lineups__name">
                            <span class="match-lineups__name-text"><?= e($scorer['player']) ?></span>
                          </span>
                          <span class="match-lineups__events"><span class="match-lineups__event"><span class="match-lineups__event-minute"><?= e($scorer['minute'] !== '' ? $scorer['minute'] . "'" : '') ?></span><?= mc_glyph(strcasecmp($scorer['label'], 'Own goal') === 0 ? 'own_goal' : 'goal') ?><span class="sr-only"><?= e($scorer['label']) ?></span></span></span>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <p class="match-lineups__empty">Full line-up not published for this match.</p>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($report):
        $rHero = trim((string) ($report['hero_image_path'] ?? ''));
    ?>
      <div class="mc-panel mc-report">
        <h2>Match report</h2>
        <a class="mc-report__card" href="<?= e(url('news/' . $report['slug'])) ?>">
          <?php if ($rHero !== ''): ?>
            <span class="mc-report__media"><img src="<?= e(news_image_url($rHero)) ?>" alt="" loading="lazy"></span>
          <?php endif; ?>
          <span class="mc-report__text">
            <span class="mc-report__title"><?= e($report['title']) ?></span>
            <?php if (trim((string) ($report['excerpt'] ?? '')) !== ''): ?><span class="mc-report__excerpt"><?= e($report['excerpt']) ?></span><?php endif; ?>
            <span class="mc-report__more">Read the full report</span>
          </span>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($archiveReport):
        $arScorers = trim((string) $archiveReport['scorers_text']);
        $arHtml = trim((string) $archiveReport['report_html']);
    ?>
      <div class="mc-panel mc-report mc-report--archive">
        <h2>Match report</h2>
        <?php if ($arScorers !== ''): ?>
          <p class="mc-report__scorers"><strong><?= e(club('club_short_name', 'Saltcoats Vics')) ?> scorers:</strong> <?= nl2br(e($arScorers)) ?></p>
        <?php endif; ?>
        <?php if ($arHtml !== ''): ?>
          <div class="prose">
            <?php if ($archiveReport['report_by'] !== ''): ?><p class="mc-report__by">Report by <?= e($archiveReport['report_by']) ?></p><?php endif; ?>
            <?= $arHtml /* sanitised at import by tools/import_svfc_history.php */ ?>
          </div>
        <?php endif; ?>
        <p class="mc-report__note">From the club’s results archive.</p>
      </div>
    <?php endif; ?>

    <?php if ($photos || $album): ?>
      <div class="mc-panel">
        <h2>Gallery</h2>
        <?php if ($photos): ?>
          <div class="mc-gallery">
            <?php foreach ($photos as $phi => $ph): ?>
              <img src="<?= e(uploads('matches/gallery/' . (int) $fixture['id'] . '/' . $ph['filename'])) ?>" alt="<?= e('Match photo ' . ($phi + 1) . ' of ' . count($photos) . ' v ' . (string) ($fixture['opponent_name'] ?: ($fixture['opponent'] ?? ''))) ?>" loading="lazy">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($album): ?>
          <p<?= $photos ? ' style="margin-top:1rem"' : '' ?>>
            <a class="linkarrow" href="<?= e(url('gallery/' . $album['slug'])) ?>">
              <?= $photos ? 'Full photo album' : e($album['title']) ?> &middot; <?= (int) $album['photo_count'] ?> photo<?= (int) $album['photo_count'] === 1 ? '' : 's' ?>
            </a>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($veo !== ''): ?>
      <p><a class="btn btn--ghost btn--sm" href="<?= e($veo) ?>" target="_blank" rel="noopener">Watch highlights on Veo</a></p>
    <?php endif; ?>
  </div>

  <aside class="mc-side">
    <div class="mc-panel">
      <h2>Match details</h2>
      <dl class="mc-facts">
        <div><dt>Competition</dt><dd><?= e($fixture['competition'] ?: 'Fixture') ?><?php if ($fixture['competition_stage']): ?> <span class="mc-facts__sub"><?= e($fixture['competition_stage']) ?></span><?php endif; ?></dd></div>
        <div><dt>Date</dt><dd><?= e(format_date($fixture['match_date'], 'l j F Y')) ?></dd></div>
        <div><dt>Kick-off</dt><dd><?= e($ko ?: 'TBC') ?></dd></div>
        <?php if ($venue): ?><div><dt>Venue</dt><dd><?= e($venue) ?><?php if (!$fixture['is_home']): ?> <span class="mc-facts__sub">Away</span><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($played && $outcome['outcome']): ?><div><dt>Result</dt><dd><?= e($outcome['label']) ?> <?= (int) $outcome['us'] ?>&ndash;<?= (int) $outcome['them'] ?></dd></div><?php endif; ?>
      </dl>
    </div>

    <?php if (!$played && ($w = $preview['weather'] ?? null)): ?>
      <div class="mc-panel mc-weather">
        <h2>Weather forecast</h2>
        <div class="mc-weather__head">
          <span class="mc-weather__emoji" aria-hidden="true"><?= $w['emoji'] ?></span>
          <span class="mc-weather__temp"><?= $w['temp'] !== null ? (int) $w['temp'] : (int) $w['temp_max'] ?>&deg;</span>
          <span class="mc-weather__cond"><?= e($w['label']) ?></span>
        </div>
        <dl class="mc-weather__stats">
          <div><dt>High / Low</dt><dd><?= (int) $w['temp_max'] ?>&deg; / <?= (int) $w['temp_min'] ?>&deg;</dd></div>
          <div><dt>Chance of rain</dt><dd><?= (int) $w['precip'] ?>%</dd></div>
          <div><dt>Wind</dt><dd><?= (int) $w['wind'] ?> mph</dd></div>
        </dl>
        <p class="mc-weather__note"><?= $w['at_kickoff'] ? 'Around kick-off' : 'On the day' ?> &middot; <?= e($w['place']) ?> &middot; forecast, may change</p>
      </div>
    <?php endif; ?>

    <?php if ($moreNews): ?>
      <div class="mc-panel">
        <h2>Related news</h2>
        <ul class="mc-related">
          <?php foreach ($moreNews as $r): ?>
            <li><a href="<?= e(url('news/' . $r['slug'])) ?>"><?= e($r['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php $links = pub_fixture_links($fixture); ?>
    <?php if ($links['prev'] || $links['next']): ?>
      <div class="mc-panel">
        <h2>Previous &amp; next match</h2>
        <ul class="mc-related">
          <?php if ($links['prev']): ?><li><a href="<?= e(url('match/' . (int) $links['prev']['id'])) ?>" rel="prev">&larr; <?= e(pub_fixture_link_label($links['prev'])) ?></a></li><?php endif; ?>
          <?php if ($links['next']): ?><li><a href="<?= e(url('match/' . (int) $links['next']['id'])) ?>" rel="next"><?= e(pub_fixture_link_label($links['next'])) ?> &rarr;</a></li><?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($links['meetings']): ?>
      <div class="mc-panel">
        <h2>Other games v <?= e($fixture['opponent_name'] ?: $fixture['opponent']) ?></h2>
        <ul class="mc-related">
          <?php foreach ($links['meetings'] as $mt): ?>
            <li><a href="<?= e(url('match/' . (int) $mt['id'])) ?>"><?= e(pub_fixture_link_label($mt)) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="mc-panel">
      <h2>More</h2>
      <ul class="mc-related">
        <li><a href="<?= e(url('fixtures')) ?>">All fixtures</a></li>
        <li><a href="<?= e(url('results')) ?>">All results</a></li>
        <li><a href="<?= e(url('table')) ?>">League table</a></li>
      </ul>
    </div>
  </aside>
</div>
