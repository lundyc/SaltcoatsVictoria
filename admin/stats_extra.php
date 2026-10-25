<?php
declare(strict_types=1);

/**
 * Extra Stats sections modelled on NonLeagueHQ's Scotland pages: league table + form,
 * next fixtures / latest results, fixture finder, competitions, past season tables,
 * career tracking and records. Included from stats.php, so it shares that file's scope
 * ($pdo, $esc, $url, $badge, $tab, $seasonId, $seasonContext, $eventsByFixture, $dataFile).
 */

require_once __DIR__ . '/lib/league_table_history.php';

$seasonNames = [];
foreach ($seasonContext['seasons'] as $s) {
    $seasonNames[(int)$s['id']] = (string)$s['name'];
}
$seasonLabel = static fn(int $id): string => $seasonNames[$id] ?? ('Season ' . $id);
$fmtDate = static fn(?string $d): string => $d ? date('D j M Y', strtotime($d)) : '';
$isUs = static fn(string $club): bool => stripos($club, 'saltcoats') !== false;

/** @return list<array<string,mixed>> */
$loadFixtures = static function (PDO $pdo, ?int $season = null): array {
    $sql = 'SELECT * FROM match_fixtures' . ($season ? ' WHERE season_id = ?' : '') . ' ORDER BY match_date ASC, kickoff_time ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($season ? [$season] : []);
    return hub_stats_exclude_friendlies($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
};
$scoreText = static function (array $f) use ($esc): string {
    $r = hub_stats_result($f);
    if ($r === null) return $f['status'] === 'played' ? 'Played' : '';
    $home = (int)$f['is_home'] === 1;
    return (int)$f['full_time_home_score'] . ' – ' . (int)$f['full_time_away_score'];
};
$fixtureRow = static function (array $f, bool $withResult) use ($esc, $fmtDate, $seasonLabel, $badge, $scoreText): string {
    $r = hub_stats_result($f);
    $out = '<tr><td class="text-nowrap">' . $esc($fmtDate($f['match_date'])) . ($f['kickoff_time'] && !$withResult ? ' <span class="text-muted">' . $esc(substr((string)$f['kickoff_time'], 0, 5)) . '</span>' : '') . '</td>';
    $out .= '<td>' . ((int)$f['is_home'] ? 'H' : 'A') . '</td><th scope="row">' . $esc($f['opponent']) . '</th><td>' . $esc($f['competition'] ?? '') . '</td>';
    if ($withResult) {
        $out .= '<td class="text-nowrap">' . $esc($scoreText($f)) . '</td><td>' . ($r ? '<span class="badge text-bg-' . $badge($r['result']) . '">' . $r['result'] . '</span>' : '') . '</td>';
    }
    return $out . '</tr>';
};
/** Shared Season / Competition / Venue filter card used by the extra tabs. */
$extraFilter = static function (array $opts) use ($esc, $tab, $seasonContext, $seasonId, $competition, $venue, $competitions): void {
    ?>
    <form method="get" class="rpt-filter-card">
        <input type="hidden" name="tab" value="<?= $esc($tab) ?>">
        <div class="row g-3 align-items-end">
            <?php if (!empty($opts['season'])): ?>
            <div class="col-md-3"><label class="form-label" for="x-season">Season</label><select class="form-select form-select-sm" id="x-season" name="season_id"><?php foreach ($seasonContext['seasons'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $seasonId ? 'selected' : '' ?>><?= $esc($s['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <?php if (!empty($opts['competition'])): ?>
            <div class="col-md-4"><label class="form-label" for="x-comp">Competition</label><select class="form-select form-select-sm" id="x-comp" name="competition"><option value="">All competitions</option><?php foreach ($opts['competition_list'] ?? $competitions as $c): ?><option <?= $c === $competition ? 'selected' : '' ?>><?= $esc($c) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <?php if (!empty($opts['venue'])): ?>
            <div class="col-md-2"><label class="form-label" for="x-venue">Venue</label><select class="form-select form-select-sm" id="x-venue" name="venue"><option value="">Home &amp; away</option><option value="home" <?= $venue === 'home' ? 'selected' : '' ?>>Home</option><option value="away" <?= $venue === 'away' ? 'selected' : '' ?>>Away</option></select></div>
            <?php endif; ?>
            <?= $opts['extra'] ?? '' ?>
            <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">Apply</button></div>
        </div>
    </form>
    <?php
};
$venueFilter = static fn(array $f): bool => $venue === '' || (int)$f['is_home'] === ($venue === 'home' ? 1 : 0);
$compFilter = static fn(array $f): bool => $competition === '' || ($f['competition'] ?? '') === $competition;
$tableHead = static fn(array $cols): string => '<thead><tr>' . implode('', array_map(static fn($c) => '<th scope="col"' . (is_array($c) ? ' class="text-center"' : '') . '>' . htmlspecialchars((string)(is_array($c) ? $c[0] : $c), ENT_QUOTES, 'UTF-8') . '</th>', $cols)) . '</tr></thead>';

/** W/D/L/GF/GA/Pts summary over a list of fixtures. */
$record = static function (array $fixtures): array {
    $s = hub_stats_summary($fixtures);
    $s['pts'] = $s['wins'] * 3 + $s['draws'];
    return $s;
};

$emitLeagueCss = static function (): void {
    static $styled = false;
    if ($styled) { return; }
    $styled = true;
    echo '<style>'
        . '.stand-pos{display:inline-grid;place-items:center;min-width:1.7rem;height:1.7rem;border-radius:50%;font-weight:700;font-variant-numeric:tabular-nums}'
        . '.stand-pos--promo{background:rgba(47,125,85,.14);color:#23653f}.stand-pos--releg{background:rgba(178,59,59,.13);color:#a32a3a}'
        . '.stand-key{display:inline-flex;align-items:center;gap:.35rem;margin-right:1rem}.stand-key i{width:.7rem;height:.7rem;border-radius:50%;display:inline-block}'
        . '.split-row th,.split-row td{background:rgba(75,8,24,.035)}.split-head th{font-size:.68rem;letter-spacing:.07em;text-transform:uppercase;color:var(--hub-muted,#70676b);padding-top:.8rem}'
        . '.form-seg{display:inline-flex;flex-wrap:wrap;padding:.15rem;gap:.1rem;margin:0 0 .9rem;background:rgba(75,8,24,.06);border-radius:.65rem}'
        . '.form-seg button{border:0;background:transparent;padding:.4rem .95rem;border-radius:.5rem;font-size:.85rem;font-weight:600;color:var(--hub-ink,#1f1a1d);white-space:nowrap}'
        . '.form-seg button[aria-pressed=true]{background:#fff;color:#6a2036;box-shadow:0 1px 3px rgba(31,26,29,.18)}'
        . '.form-panel[hidden]{display:none}'
        . '.form-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}'
        . '@media(max-width:767.98px){.form-cards{grid-template-columns:1fr}}'
        . '.form-card{padding:.85rem .95rem;border:1px solid var(--hub-line,#eadfdf);border-radius:.85rem;background:#fff}'
        . '.form-card--season{background:rgba(75,8,24,.035)}.form-card--split{background:rgba(75,8,24,.02)}'
        . '.form-card__head{display:flex;align-items:baseline;justify-content:space-between;gap:.5rem;margin-bottom:.6rem}'
        . '.form-card__head h3{margin:0;font-size:.95rem;font-weight:800}.form-card__pts{color:var(--hub-muted,#70676b);font-size:.82rem}.form-card__pts strong{font-size:1.4rem;color:#6a2036;font-variant-numeric:tabular-nums}'
        . '.form-card__stats{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem;margin:0}.form-card__stats div{text-align:center;padding:.4rem 0;border-radius:.55rem;background:rgba(75,8,24,.05)}'
        . '.form-card__stats dt{font-size:.62rem;font-weight:800;letter-spacing:.06em;color:var(--hub-muted,#70676b)}.form-card__stats dd{margin:0;font-weight:700;font-variant-numeric:tabular-nums}'
        . '.form-card__form{display:flex;align-items:center;gap:.6rem;margin-top:.65rem}.form-card__form>span{font-size:.62rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--hub-muted,#70676b)}'
        . '@media(max-width:575.98px){.form-cards{grid-template-columns:1fr}}'
        . '@media(max-width:991.98px){.stand-table{table-layout:auto!important;width:100%}.stand-table .stand-club{white-space:nowrap}}'
        . '@media(max-width:575.98px){'
        . '.stand-table,.form-table{table-layout:auto!important;width:100%}'
        . '.stand-table.table>:not(caption)>*>*,.form-table.table>:not(caption)>*>*{padding:.4rem .22rem!important;font-size:.8rem;word-break:normal;overflow-wrap:normal;white-space:nowrap}'
        . '.stand-table .stand-club{padding-left:.4rem!important;white-space:normal;min-width:6rem}.stand-pos{min-width:1.5rem;height:1.5rem}'
        . '.form-table th:first-child{padding-left:.5rem}.form-table td:last-child{white-space:normal!important;min-width:5.5rem}.form-table .hub-form-row{flex-wrap:wrap;gap:.15rem}.form-table .badge{padding:.3em .45em}'
        . '}</style>';
};

$standingsTable = static function (array $rows, ?int $promo, ?int $releg) use ($esc, $isUs, $emitLeagueCss): void {
    $n = count($rows);
    $emitLeagueCss();
    ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table stand-table">
        <thead><tr><th class="text-center">#</th><th>Club</th><?php foreach (['P', 'W', 'D', 'L', 'F', 'A', 'GD', 'Pts'] as $h): ?><th class="text-center<?= in_array($h, ['F', 'A'], true) ? ' d-none d-sm-table-cell' : '' ?>"><?= $h ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r):
            $cls = $isUs((string)$r['club']) ? 'table-warning fw-semibold' : '';
            $zone = '';
            if ($promo && $r['pos'] <= $promo) $zone = 'promo';
            elseif ($releg && $r['pos'] > $n - $releg) $zone = 'releg'; ?>
            <tr class="<?= $esc($cls) ?>"><td class="text-center"><span class="stand-pos<?= $zone !== '' ? ' stand-pos--' . $zone : '' ?>"<?= $zone === 'promo' ? ' title="Promotion place"' : ($zone === 'releg' ? ' title="Relegation place"' : '') ?>><?= (int)$r['pos'] ?></span></td><td class="stand-club"><?= $esc($r['club']) ?></td>
            <?php foreach (['p', 'w', 'd', 'l', 'f', 'a'] as $k): ?><td class="text-center<?= in_array($k, ['f', 'a'], true) ? ' d-none d-sm-table-cell' : '' ?>"><?= (int)$r[$k] ?></td><?php endforeach; ?>
            <td class="text-center"><?= ((int)$r['gd'] > 0 ? '+' : '') . (int)$r['gd'] ?></td><td class="text-center fw-bold"><?= (int)$r['pts'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php
};

/* ====================================================================== */
if ($tab === 'league'):
    $seasonFixtures = array_values(array_filter($loadFixtures($pdo, $seasonId), static fn($f) => $f['status'] === 'played'));
    $seasonComps = array_values(array_unique(array_filter(array_column($seasonFixtures, 'competition'))));
    sort($seasonComps);
    // This tab is league-only: always use the competition we have played most in.
    $counts = array_count_values(array_column($seasonFixtures, 'competition'));
    arsort($counts);
    $competition = (string)(array_key_first($counts) ?? '');
    $played = array_values(array_filter($seasonFixtures, static fn($f) => ($f['competition'] ?? '') === $competition && hub_stats_result($f) !== null));
    $extraFilter(['season' => true]);

    $saved = leagueTableHistoryGet($pdo, $seasonId);
    $currentRow = $pdo->prepare('SELECT is_current FROM seasons WHERE id = ?');
    $currentRow->execute([$seasonId]);
    $standings = null;
    if ($saved && $saved['rows']) {
        $standings = ['rows' => $saved['rows'], 'title' => (string)$saved['competition_title'], 'promo' => $saved['promotion_spots'] !== null ? (int)$saved['promotion_spots'] : null, 'releg' => $saved['relegation_spots'] !== null ? (int)$saved['relegation_spots'] : null, 'note' => 'Final table saved for this season.'];
    } elseif ((int)$currentRow->fetchColumn() === 1) {
        $live = leagueTableHistoryLiveSnapshot($pdo, ['id' => $seasonId, 'competition_id' => $pdo->query('SELECT competition_id FROM seasons WHERE id = ' . (int)$seasonId)->fetchColumn() ?: null]);
        if ($live['rows']) $standings = ['rows' => $live['rows'], 'title' => $live['title'], 'promo' => $live['promotion'], 'releg' => $live['relegation'], 'note' => $live['updated'] ? 'Live table, last refreshed ' . date('j M Y H:i', $live['updated']) . '.' : 'Live table.'];
    }
    ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5 mb-1"><?= $esc($standings['title'] ?? 'League table') ?> <small class="text-muted">· <?= $esc($seasonLabel($seasonId)) ?></small></h2>
        <?php if ($standings): ?>
            <p class="text-muted small"><?= $esc($standings['note']) ?>
                <?php if ($standings['promo'] || $standings['releg']): ?><span class="ms-2">
                    <?php if ($standings['promo']): ?><span class="stand-key"><i style="background:#2f7d55"></i>Promotion</span><?php endif; ?>
                    <?php if ($standings['releg']): ?><span class="stand-key"><i style="background:#b23b3b"></i>Relegation</span><?php endif; ?>
                </span><?php endif; ?></p>
            <?php $standingsTable($standings['rows'], $standings['promo'], $standings['releg']); ?>
        <?php else: ?>
            <p class="text-muted mb-0">No league table saved for this season. Add one under League table history.</p>
        <?php endif; ?>
    </div></section>

    <section class="card shadow-sm"><div class="card-body">
        <?php $emitLeagueCss(); ?><h2 class="h5">Form guide</h2>
        <p class="text-muted small">Our results in <?= $esc($competition ?: 'all competitions') ?>. Choose how many recent games to look at.</p>
        <?php if (!$played): ?><p class="text-muted mb-0">No scored matches for this competition yet.</p><?php else:
            $windows = ['3' => ['Last 3', 3], '6' => ['Last 6', 6], '10' => ['Last 10', 10], 'all' => ['Season', 0]];
            $defaultWindow = '6';
            $formBadges = static function (array $games) use ($badge): string {
                $out = '';
                foreach ($games as $f) { $r = hub_stats_result($f); $out .= '<span class="badge text-bg-' . $badge($r['result']) . '">' . $r['result'] . '</span> '; }
                return $out;
            };
        ?>
        <div class="form-seg" role="group" aria-label="Number of games" id="formWindowSeg">
            <?php foreach ($windows as $key => [$wLabel, $wN]): ?>
                <button type="button" data-window="<?= $esc($key) ?>" aria-pressed="<?= $key === $defaultWindow ? 'true' : 'false' ?>"><?= $esc($wLabel) ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($windows as $key => [$wLabel, $wN]):
            $games = $wN > 0 ? array_slice($played, -$wN) : $played;
            $parts = [
                ['label' => $wN > 0 ? $wLabel . ' games' : 'All games', 'games' => $games, 'kind' => 'all'],
                ['label' => 'Home', 'games' => array_values(array_filter($games, static fn($f) => (int)$f['is_home'] === 1)), 'kind' => 'split'],
                ['label' => 'Away', 'games' => array_values(array_filter($games, static fn($f) => (int)$f['is_home'] === 0)), 'kind' => 'split'],
            ]; ?>
        <div class="form-panel" data-window="<?= $esc($key) ?>"<?= $key === $defaultWindow ? '' : ' hidden' ?>>
            <div class="table-responsive d-none d-lg-block"><table class="table table-sm align-middle mb-0 hub-data-table form-table">
                <?= $tableHead(['Games', ['P'], ['W'], ['D'], ['L'], ['GF'], ['GA'], ['Pts'], 'Form']) ?>
                <tbody>
                <?php foreach ($parts as $part): $s = $record($part['games']); ?>
                    <tr class="<?= $part['kind'] === 'split' ? 'split-row' : 'table-light' ?>"><th scope="row"><?= $esc($part['label']) ?></th><?php foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'pts'] as $k): ?><td class="text-center"><?= (int)$s[$k] ?></td><?php endforeach; ?>
                    <td><div class="hub-form-row" style="justify-content:flex-start"><?= $formBadges($part['games']) ?></div></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>

            <div class="form-cards d-lg-none">
                <?php foreach ($parts as $part): $s = $record($part['games']); ?>
                    <article class="form-card form-card--<?= $part['kind'] === 'all' ? 'season' : 'split' ?>">
                        <header class="form-card__head">
                            <h3><?= $esc($part['label']) ?></h3>
                            <span class="form-card__pts"><strong><?= (int)$s['pts'] ?></strong> pts</span>
                        </header>
                        <dl class="form-card__stats">
                            <?php foreach (['P' => 'played', 'W' => 'wins', 'D' => 'draws', 'L' => 'losses', 'GF' => 'goals_for', 'GA' => 'goals_against'] as $lbl => $k): ?>
                                <div><dt><?= $lbl ?></dt><dd><?= (int)$s[$k] ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                        <?php if ($part['games']): ?><div class="form-card__form"><span>Form</span><div class="hub-form-row" style="justify-content:flex-start"><?= $formBadges($part['games']) ?></div></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <script>
        (function () {
            var seg = document.getElementById('formWindowSeg');
            if (!seg) { return; }
            seg.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-window]');
                if (!b) { return; }
                seg.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                document.querySelectorAll('.form-panel').forEach(function (p) { p.hidden = p.dataset.window !== b.dataset.window; });
            });
        }());
        </script>
        <?php endif; ?>
    </div></section>

    <?php if (count($played) > 1):
        $labels = $points = $gd = $ppg = [];
        $pts = $diff = 0;
        foreach ($played as $i => $f) {
            $r = hub_stats_result($f);
            $pts += ['W' => 3, 'D' => 1, 'L' => 0][$r['result']];
            $diff += $r['for'] - $r['against'];
            $labels[] = date('j M', strtotime($f['match_date'])) . ' v ' . $f['opponent'];
            $points[] = $pts;
            $gd[] = $diff;
            $ppg[] = round($pts / ($i + 1), 2);
        } ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5">Points &amp; goal difference</h2>
        <p class="text-muted small">Running totals after each match. A league-position graph needs a saved table after every matchday, which isn't recorded, so this tracks our own trajectory instead.</p>
        <div style="position:relative;height:300px"><canvas id="statsPointsChart" aria-label="Points progression chart" role="img"></canvas></div>
    </div></section>
    <script src="/admin/assets/vendor/chartjs/chart.umd.min.js"></script>
    <script>
    new Chart(document.getElementById('statsPointsChart'), {
        type: 'line',
        data: { labels: <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP) ?>, datasets: [
            { label: 'Points', data: <?= json_encode($points) ?>, borderColor: '#6a2036', backgroundColor: '#6a2036', tension: .2 },
            { label: 'Goal difference', data: <?= json_encode($gd) ?>, borderColor: '#276884', backgroundColor: '#276884', tension: .2 },
            { label: 'Points per game', data: <?= json_encode($ppg) ?>, borderColor: '#9c6b1f', backgroundColor: '#9c6b1f', borderDash: [5, 4], yAxisID: 'y2', tension: .2 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: { x: { ticks: { display: false } }, y2: { position: 'right', grid: { drawOnChartArea: false }, min: 0, max: 3 } } }
    });
    </script>
    <?php endif; ?>

<?php
/* ====================================================================== */
elseif ($tab === 'fixtures'):
    $seasonFixtures = $loadFixtures($pdo, $seasonId);
    $seasonComps = array_values(array_unique(array_filter(array_column($seasonFixtures, 'competition'))));
    sort($seasonComps);
    if (!in_array($competition, $seasonComps, true)) $competition = '';
    $seasonFixtures = array_values(array_filter($seasonFixtures, fn($f) => $compFilter($f) && $venueFilter($f)));
    $today = date('Y-m-d');
    $next = array_values(array_filter($seasonFixtures, static fn($f) => $f['status'] !== 'played' && $f['status'] !== 'cancelled' && $f['match_date'] >= $today));
    $latest = array_reverse(array_values(array_filter($seasonFixtures, static fn($f) => $f['status'] === 'played')));
    $extraFilter(['season' => true, 'competition' => true, 'competition_list' => $seasonComps, 'venue' => true]);
    ?>
    <div class="row g-3">
        <div class="col-xl-6"><section class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Next fixtures</h2>
            <?php if (!$next): ?><p class="text-muted mb-0">No upcoming fixtures for this selection.</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table"><?= $tableHead(['Date', 'H/A', 'Opponent', 'Competition']) ?><tbody>
            <?php foreach (array_slice($next, 0, 10) as $f) echo $fixtureRow($f, false); ?></tbody></table></div><?php endif; ?>
        </div></section></div>
        <div class="col-xl-6"><section class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Latest results</h2>
            <?php if (!$latest): ?><p class="text-muted mb-0">No results for this selection.</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table"><?= $tableHead(['Date', 'H/A', 'Opponent', 'Competition', 'Score', '']) ?><tbody>
            <?php foreach (array_slice($latest, 0, 10) as $f) echo $fixtureRow($f, true); ?></tbody></table></div><?php endif; ?>
        </div></section></div>
    </div>

<?php
/* ====================================================================== */
elseif ($tab === 'finder'):
    $q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
    $from = is_string($_GET['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
    $to = is_string($_GET['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';
    $resultFilter = is_string($_GET['result'] ?? null) && in_array($_GET['result'], ['W', 'D', 'L'], true) ? $_GET['result'] : '';
    $all = $loadFixtures($pdo);
    $allComps = array_values(array_unique(array_filter(array_column($all, 'competition'))));
    sort($allComps);
    if (!in_array($competition, $allComps, true)) $competition = '';
    $found = array_values(array_filter($all, function ($f) use ($q, $from, $to, $resultFilter, $compFilter, $venueFilter) {
        if ($q !== '' && stripos((string)$f['opponent'], $q) === false) return false;
        if ($from !== '' && $f['match_date'] < $from) return false;
        if ($to !== '' && $f['match_date'] > $to) return false;
        if ($resultFilter !== '') { $r = hub_stats_result($f); if (!$r || $r['result'] !== $resultFilter) return false; }
        return $compFilter($f) && $venueFilter($f) && $f['status'] !== 'cancelled';
    }));
    $found = array_reverse($found);
    $extra = '<div class="col-md-3"><label class="form-label" for="x-q">Opponent</label><input class="form-control form-control-sm" id="x-q" name="q" value="' . $esc($q) . '" placeholder="e.g. Largs"></div>'
        . '<div class="col-md-2"><label class="form-label" for="x-from">From</label><input type="date" class="form-control form-control-sm" id="x-from" name="from" value="' . $esc($from) . '"></div>'
        . '<div class="col-md-2"><label class="form-label" for="x-to">To</label><input type="date" class="form-control form-control-sm" id="x-to" name="to" value="' . $esc($to) . '"></div>'
        . '<div class="col-md-2"><label class="form-label" for="x-res">Result</label><select class="form-select form-select-sm" id="x-res" name="result"><option value="">Any</option>' . implode('', array_map(fn($r, $l) => '<option value="' . $r . '"' . ($r === $resultFilter ? ' selected' : '') . '>' . $l . '</option>', ['W', 'D', 'L'], ['Win', 'Draw', 'Loss'])) . '</select></div>';
    $extraFilter(['competition' => true, 'competition_list' => $allComps, 'venue' => true, 'extra' => $extra]);
    $played = array_filter($found, static fn($f) => hub_stats_result($f) !== null);
    $s = $record($played);
    ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5">Fixture finder <small class="text-muted">· all seasons</small></h2>
        <p class="text-muted small"><?= count($found) ?> match<?= count($found) === 1 ? '' : 'es' ?> found<?php if ($played): ?> · P<?= $s['scored'] ?> W<?= $s['wins'] ?> D<?= $s['draws'] ?> L<?= $s['losses'] ?> · GF <?= $s['goals_for'] ?> GA <?= $s['goals_against'] ?><?php endif; ?></p>
        <?php if (!$found): ?><p class="text-muted mb-0">No matches match those filters.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table"><?= $tableHead(['Date', 'H/A', 'Opponent', 'Competition', 'Score', '']) ?><tbody>
        <?php foreach (array_slice($found, 0, 300) as $f) echo $fixtureRow($f, true); ?></tbody></table></div>
        <?php if (count($found) > 300): ?><p class="text-muted small mt-2 mb-0">Showing the latest 300. Narrow the filters to see the rest.</p><?php endif; endif; ?>
    </div></section>

<?php
/* ====================================================================== */
elseif ($tab === 'competitions'):
    $seasonFixtures = array_values(array_filter($loadFixtures($pdo, $seasonId), static fn($f) => $f['status'] === 'played'));
    $byComp = [];
    foreach ($seasonFixtures as $f) $byComp[(string)($f['competition'] ?: 'Other')][] = $f;
    ksort($byComp);
    $extraFilter(['season' => true]);
    ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5">Competitions <small class="text-muted">· <?= $esc($seasonLabel($seasonId)) ?></small></h2>
        <?php if (!$byComp): ?><p class="text-muted mb-0">No played matches this season.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table">
            <?= $tableHead(['Competition', ['P'], ['W'], ['D'], ['L'], ['GF'], ['GA'], ['Pts'], 'Latest stage']) ?><tbody>
            <?php foreach ($byComp as $name => $fx): $s = $record($fx); $last = end($fx); ?>
            <tr><th scope="row"><?= $esc($name) ?></th><?php foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'pts'] as $k): ?><td class="text-center"><?= (int)$s[$k] ?></td><?php endforeach; ?>
            <td><?= $esc(($last['competition_stage'] ?? '') ?: '—') ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div></section>
    <?php foreach ($byComp as $name => $fx): if (count($byComp) < 2 && false) continue; ?>
    <section class="card shadow-sm"><div class="card-body"><h3 class="h6"><?= $esc($name) ?> results</h3>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table"><?= $tableHead(['Date', 'H/A', 'Opponent', 'Competition', 'Score', '']) ?><tbody>
        <?php foreach (array_reverse($fx) as $f) echo $fixtureRow($f, true); ?></tbody></table></div></div></section>
    <?php endforeach; ?>

<?php
/* ====================================================================== */
elseif ($tab === 'seasons'):
    $all = $loadFixtures($pdo);
    $bySeason = [];
    foreach ($all as $f) if ($f['status'] === 'played') $bySeason[(int)$f['season_id']][] = $f;
    $histories = [];
    foreach (leagueTableHistoryList($pdo) as $h) $histories[(int)$h['season_id']] = $h;
    $viewId = (int)($_GET['table_season'] ?? 0);
    $viewTable = $viewId && isset($histories[$viewId]) ? leagueTableHistoryGet($pdo, $viewId) : null;
    ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5">Season by season</h2>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table">
            <?= $tableHead(['Season', ['P'], ['W'], ['D'], ['L'], ['GF'], ['GA'], ['Win %'], ['League finish'], 'Table']) ?><tbody>
            <?php foreach ($seasonContext['seasons'] as $sn): $sid = (int)$sn['id']; $s = $record($bySeason[$sid] ?? []);
                $finish = '—';
                if (isset($histories[$sid])) { $t = leagueTableHistoryGet($pdo, $sid); foreach ($t['rows'] ?? [] as $r) if ($isUs((string)$r['club'])) $finish = (int)$r['pos'] . ' of ' . count($t['rows']); } ?>
            <tr><th scope="row"><?= $esc($sn['name']) ?></th><?php foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against'] as $k): ?><td class="text-center"><?= (int)$s[$k] ?></td><?php endforeach; ?>
            <td class="text-center"><?= $s['scored'] ? round(100 * $s['wins'] / $s['scored']) . '%' : '—' ?></td><td class="text-center"><?= $esc($finish) ?></td>
            <td><?php if (isset($histories[$sid])): ?><a href="<?= $esc($url(['tab' => 'seasons', 'table_season' => $sid])) ?>#season-table">View</a><?php endif; ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </div></section>
    <?php if ($viewTable): ?>
    <section class="card shadow-sm" id="season-table"><div class="card-body">
        <h2 class="h5"><?= $esc($viewTable['competition_title'] ?: 'Final table') ?> <small class="text-muted">· <?= $esc($seasonLabel($viewId)) ?></small></h2>
        <?php $standingsTable($viewTable['rows'], $viewTable['promotion_spots'] !== null ? (int)$viewTable['promotion_spots'] : null, $viewTable['relegation_spots'] !== null ? (int)$viewTable['relegation_spots'] : null); ?>
    </div></section>
    <?php endif; ?>

<?php
/* ====================================================================== */
elseif ($tab === 'career'):
    $all = $loadFixtures($pdo);
    $bySeason = [];
    foreach ($all as $f) if ($f['status'] === 'played') $bySeason[(int)$f['season_id']][] = $f;
    $names = [];
    foreach ($bySeason as $fixtures_) foreach ($fixtures_ as $f) {
        foreach (['starting11_starters_json', 'starting11_substitutes_json'] as $field) {
            foreach (json_decode((string)($f[$field] ?? '[]'), true) ?: [] as $n) if (is_string($n) && trim($n) !== '') $names[hub_player_stats_normalize_name($n)] = $n;
        }
    }
    asort($names, SORT_NATURAL | SORT_FLAG_CASE);
    $player = is_string($_GET['player'] ?? null) ? $_GET['player'] : '';
    $extra = '<div class="col-md-5"><label class="form-label" for="x-player">Player</label><select class="form-select form-select-sm" id="x-player" name="player"><option value="">Choose a player…</option>' . implode('', array_map(fn($n) => '<option' . ($n === $player ? ' selected' : '') . '>' . $esc($n) . '</option>', $names)) . '</select></div>';
    $extraFilter(['extra' => $extra]);
    if ($player !== '' && in_array($player, $names, true)):
        $rows = [];
        $tot = ['appearances' => 0, 'starts' => 0, 'goals' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'minutes_played' => 0];
        foreach ($seasonContext['seasons'] as $sn) {
            $sid = (int)$sn['id'];
            if (empty($bySeason[$sid])) continue;
            $st = hub_player_match_stats($pdo, $sid, $player, $dataFile, $bySeason[$sid], $eventsByFixture);
            if (!$st['appearances'] && !$st['goals']) continue;
            $rows[] = [$sn['name'], $st];
            foreach ($tot as $k => $_) $tot[$k] += (int)$st[$k];
        } ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5"><?= $esc($player) ?></h2>
        <?php if (!$rows): ?><p class="text-muted mb-0">No recorded appearances.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table">
            <?= $tableHead(['Season', ['Apps'], ['Starts'], ['Minutes'], ['Goals'], ['Yellow'], ['Red']]) ?><tbody>
            <?php foreach ($rows as [$sname, $st]): ?><tr><th scope="row"><?= $esc($sname) ?></th><?php foreach (['appearances', 'starts', 'minutes_played', 'goals', 'yellow_cards', 'red_cards'] as $k): ?><td class="text-center"><?= (int)$st[$k] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <tr class="table-light fw-bold"><th scope="row">Career</th><?php foreach (['appearances', 'starts', 'minutes_played', 'goals', 'yellow_cards', 'red_cards'] as $k): ?><td class="text-center"><?= $tot[$k] ?></td><?php endforeach; ?></tr>
            </tbody></table></div><?php endif; ?>
    </div></section>
    <?php else: ?><div class="card card-body text-center py-5 text-muted">Choose a player to see their season-by-season record.</div><?php endif; ?>

<?php
/* ====================================================================== */
elseif ($tab === 'records'):
    $all = array_values(array_filter($loadFixtures($pdo), static fn($f) => hub_stats_result($f) !== null));
    $enriched = array_map(static fn($f) => $f + ['_r' => hub_stats_result($f)], $all);
    $best = static function (callable $key, bool $max = true) use ($enriched) {
        $pick = null; $pv = null;
        foreach ($enriched as $f) { $v = $key($f); if ($pv === null || ($max ? $v > $pv : $v < $pv)) { $pv = $v; $pick = $f; } }
        return $pick;
    };
    $desc = static fn(?array $f): string => $f ? $f['_r']['for'] . '–' . $f['_r']['against'] . ' ' . ((int)$f['is_home'] ? 'v ' : 'at ') . $f['opponent'] . ' (' . date('j M Y', strtotime($f['match_date'])) . ', ' . ($f['competition'] ?: 'friendly') . ')' : '—';
    $streak = static function (callable $test) use ($enriched): array {
        $cur = $max = 0; $end = null;
        foreach ($enriched as $f) { if ($test($f['_r'])) { $cur++; if ($cur > $max) { $max = $cur; $end = $f; } } else $cur = 0; }
        return [$max, $end ? 'to ' . date('j M Y', strtotime($end['match_date'])) : ''];
    };
    $records = [
        'Biggest win' => $desc($best(static fn($f) => $f['_r']['for'] - $f['_r']['against'])),
        'Heaviest defeat' => $desc($best(static fn($f) => $f['_r']['for'] - $f['_r']['against'], false)),
        'Most goals scored in a match' => $desc($best(static fn($f) => $f['_r']['for'])),
        'Highest-scoring match' => $desc($best(static fn($f) => $f['_r']['for'] + $f['_r']['against'])),
    ];
    foreach (['Longest winning run' => fn($r) => $r['result'] === 'W', 'Longest unbeaten run' => fn($r) => $r['result'] !== 'L', 'Longest losing run' => fn($r) => $r['result'] === 'L', 'Longest clean-sheet run' => fn($r) => $r['against'] === 0, 'Longest scoring run' => fn($r) => $r['for'] > 0] as $label => $test) {
        [$n, $end] = $streak($test);
        $records[$label] = $n ? $n . ' matches ' . $end : '—';
    }
    // All-time scorers from recorded match events.
    $played = [];
    foreach ($all as $f) $played[(int)$f['id']] = $f;
    $scorers = $seasonScorers = [];
    foreach ($played as $fid => $f) foreach ($eventsByFixture[$fid] ?? [] as $e) {
        if (!hub_player_stats_is_club_player_event($e) || !empty($e['own_goal']) || !in_array($e['type'] ?? '', ['goal', 'penalty_scored'], true)) continue;
        $n = trim((string)($e['player'] ?? ''));
        if ($n === '') continue;
        $scorers[$n] = ($scorers[$n] ?? 0) + 1;
        $seasonScorers[$n . '|' . $f['season_id']] = ($seasonScorers[$n . '|' . $f['season_id']] ?? 0) + 1;
    }
    arsort($scorers);
    arsort($seasonScorers);
    ?>
    <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Club records</h2>
        <p class="text-muted small">From <?= count($all) ?> scored matches across all seasons.</p>
        <dl class="row mb-0"><?php foreach ($records as $label => $text): ?><dt class="col-sm-4"><?= $esc($label) ?></dt><dd class="col-sm-8"><?= $esc($text) ?></dd><?php endforeach; ?></dl>
    </div></section>
    <div class="row g-3">
        <div class="col-lg-6"><section class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">All-time top scorers</h2>
            <?php if (!$scorers): ?><p class="text-muted mb-0">No goal events recorded.</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 hub-data-table"><?= $tableHead(['Player', ['Goals']]) ?><tbody>
            <?php foreach (array_slice($scorers, 0, 15, true) as $n => $g): ?><tr><th scope="row"><?= $esc($n) ?></th><td class="text-center"><?= $g ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div></section></div>
        <div class="col-lg-6"><section class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Most goals in a season</h2>
            <?php if (!$seasonScorers): ?><p class="text-muted mb-0">No goal events recorded.</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 hub-data-table"><?= $tableHead(['Player', 'Season', ['Goals']]) ?><tbody>
            <?php foreach (array_slice($seasonScorers, 0, 15, true) as $key => $g): [$n, $sid] = explode('|', $key); ?><tr><th scope="row"><?= $esc($n) ?></th><td><?= $esc($seasonLabel((int)$sid)) ?></td><td class="text-center"><?= $g ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div></section></div>
    </div>

<?php
/* ====================================================================== */
elseif ($tab === 'teamstats'):
    $statDefs = [
        'biggestWin' => ['Biggest Win', 'desc'], 'mostGoals' => ['Most Goals in a Game', 'desc'],
        'unbeatenRun' => ['Longest Unbeaten Run', 'desc'], 'curUnbeaten' => ['Current Unbeaten Run', 'desc'],
        'winRun' => ['Longest Win Run', 'desc'], 'curWinRun' => ['Current Win Run', 'desc'],
        'lossRun' => ['Longest Losing Run', 'desc'], 'curLossRun' => ['Current Losing Run', 'desc'],
        'winlessRun' => ['Longest Run Without a Win', 'desc'], 'curWinlessRun' => ['Current Run Without a Win', 'desc'],
        'cleanSheets' => ['Total Clean Sheets', 'desc'], 'csRun' => ['Longest Clean Sheet Run', 'desc'],
        'curCSRun' => ['Current Clean Sheet Run', 'desc'], 'csPct' => ['Clean Sheet %', 'desc'],
        'ppg' => ['Points Per Game', 'desc'], 'pctPoints' => ['Points %', 'desc'],
        'hADiff' => ['Home > Away Points % Diff', 'desc'], 'aHDiff' => ['Away > Home Points % Diff', 'desc'],
        'drawPct' => ['Draw %', 'desc'], 'gf' => ['Total Goals Scored', 'desc'], 'avgGF' => ['Avg Goals Scored', 'desc'],
        'ga' => ['Total Goals Conceded', 'desc'], 'avgGA' => ['Avg Goals Conceded', 'desc'],
        'fewestGF' => ['Fewest Goals Scored', 'asc'], 'avgFewestGF' => ['Avg Goals Scored (Fewest)', 'asc'],
        'mostGA' => ['Most Goals Conceded', 'desc'], 'avgMostGA' => ['Avg Goals Conceded (Most)', 'desc'],
    ];
    $stat = is_string($_GET['stat'] ?? null) && isset($statDefs[$_GET['stat']]) ? $_GET['stat'] : 'biggestWin';
    [$statLabel, $statDir] = $statDefs[$stat];

    $allFx = array_values(array_filter($loadFixtures($pdo, $seasonId), static fn($f) => hub_stats_result($f) !== null));
    $allComps = array_values(array_unique(array_filter(array_column($allFx, 'competition'))));
    sort($allComps);
    if (!in_array($competition, $allComps, true)) $competition = '';
    if ($competition !== '') $allFx = array_values(array_filter($allFx, $compFilter));
    $groups = [];
    foreach ($allFx as $f) $groups[(string)($f['competition'] ?: 'Other')][] = $f;
    $fxDesc = static fn(array $f): string => hub_stats_result($f)['for'] . '–' . hub_stats_result($f)['against'] . ((int)$f['is_home'] ? ' v ' : ' at ') . $f['opponent'] . ' (' . date('j M Y', strtotime($f['match_date'])) . ')';
    $runOf = static function (array $fx, callable $test): array {
        $cur = $max = 0; $maxEnd = $maxStart = $start = null;
        foreach ($fx as $f) {
            if ($test(hub_stats_result($f))) { if (!$cur) $start = $f; $cur++; if ($cur > $max) { $max = $cur; $maxEnd = $f; $maxStart = $start; } } else $cur = 0;
        }
        return [$max, $maxEnd, $cur, $maxStart];
    };
    $tests = [
        'unbeaten' => static fn($r) => $r['result'] !== 'L', 'win' => static fn($r) => $r['result'] === 'W',
        'loss' => static fn($r) => $r['result'] === 'L', 'winless' => static fn($r) => $r['result'] !== 'W',
        'cs' => static fn($r) => $r['against'] === 0,
    ];
    $pctPts = static function (array $fx): ?float {
        $p = count($fx);
        if (!$p) return null;
        $pts = 0;
        foreach ($fx as $f) $pts += ['W' => 3, 'D' => 1, 'L' => 0][hub_stats_result($f)['result']];
        return 100 * $pts / (3 * $p);
    };
    /** @return array{0:float,1:string,2:string}|null sort value, shown value, detail */
    $compute = static function (array $fx) use ($stat, $fxDesc, $runOf, $tests, $pctPts, $record): ?array {
        $n = count($fx);
        if (!$n) return null;
        $s = $record($fx);
        $pct = static fn(float $v): string => number_format($v, 1) . '%';
        $runDetail = static fn(int $len, ?array $end, ?array $start): string => $len && $end && $start ? date('j M Y', strtotime($start['match_date'])) . ' – ' . date('j M Y', strtotime($end['match_date'])) : '';
        switch ($stat) {
            case 'biggestWin':
                $best = null; $bv = 0;
                foreach ($fx as $f) { $r = hub_stats_result($f); if ($r['for'] - $r['against'] > $bv) { $bv = $r['for'] - $r['against']; $best = $f; } }
                return $best ? [(float)$bv, '+' . $bv, $fxDesc($best)] : [0.0, '—', 'No wins'];
            case 'mostGoals':
                $best = $fx[0]; $bv = -1;
                foreach ($fx as $f) { $r = hub_stats_result($f); if ($r['for'] + $r['against'] > $bv) { $bv = $r['for'] + $r['against']; $best = $f; } }
                return [(float)$bv, (string)$bv, $fxDesc($best)];
            case 'unbeatenRun': case 'winRun': case 'lossRun': case 'winlessRun': case 'csRun':
                $key = ['unbeatenRun' => 'unbeaten', 'winRun' => 'win', 'lossRun' => 'loss', 'winlessRun' => 'winless', 'csRun' => 'cs'][$stat];
                [$max, $end, , $first] = $runOf($fx, $tests[$key]);
                return [(float)$max, $max . ($max === 1 ? ' game' : ' games'), $runDetail($max, $end, $first)];
            case 'curUnbeaten': case 'curWinRun': case 'curLossRun': case 'curWinlessRun': case 'curCSRun':
                $key = ['curUnbeaten' => 'unbeaten', 'curWinRun' => 'win', 'curLossRun' => 'loss', 'curWinlessRun' => 'winless', 'curCSRun' => 'cs'][$stat];
                [, , $cur] = $runOf($fx, $tests[$key]);
                return [(float)$cur, (string)$cur, 'as at ' . date('j M Y', strtotime(end($fx)['match_date']))];
            case 'cleanSheets': return [(float)$s['clean_sheets'], (string)$s['clean_sheets'], 'from ' . $n . ' games'];
            case 'csPct': $v = 100 * $s['clean_sheets'] / $n; return [$v, $pct($v), $s['clean_sheets'] . ' in ' . $n];
            case 'ppg': $v = $s['pts'] / $n; return [$v, number_format($v, 2), $s['pts'] . ' pts from ' . $n];
            case 'pctPoints': $v = $pctPts($fx); return [$v, $pct($v), $s['pts'] . ' of ' . 3 * $n . ' available'];
            case 'hADiff': case 'aHDiff':
                $h = array_values(array_filter($fx, static fn($f) => (int)$f['is_home'] === 1));
                $a = array_values(array_filter($fx, static fn($f) => (int)$f['is_home'] === 0));
                if (!$h || !$a) return null;
                $hp = $pctPts($h); $ap = $pctPts($a);
                $v = $stat === 'hADiff' ? $hp - $ap : $ap - $hp;
                return [$v, ($v > 0 ? '+' : '') . number_format($v, 1) . '%', 'Home ' . $pct($hp) . ' · Away ' . $pct($ap)];
            case 'drawPct': $v = 100 * $s['draws'] / $n; return [$v, $pct($v), $s['draws'] . ' in ' . $n];
            case 'gf': case 'fewestGF': return [(float)$s['goals_for'], (string)$s['goals_for'], 'from ' . $n . ' games'];
            case 'ga': case 'mostGA': return [(float)$s['goals_against'], (string)$s['goals_against'], 'from ' . $n . ' games'];
            case 'avgGF': case 'avgFewestGF': $v = $s['goals_for'] / $n; return [$v, number_format($v, 2), $s['goals_for'] . ' in ' . $n];
            case 'avgGA': case 'avgMostGA': $v = $s['goals_against'] / $n; return [$v, number_format($v, 2), $s['goals_against'] . ' in ' . $n];
        }
        return null;
    };
    $rowsOut = [];
    foreach ($groups as $cname => $fx) { $c = $compute($fx); if ($c) $rowsOut[] = [$cname, $c, count($fx)]; }
    usort($rowsOut, static fn($a, $b) => $statDir === 'asc' ? $a[1][0] <=> $b[1][0] : $b[1][0] <=> $a[1][0]);
    $overall = $compute($allFx);

    // League-wide ranking, for the stats a league table can answer (selected season).
    $tableRank = [
        'gf' => ['f', 'desc', 0], 'avgGF' => ['f', 'desc', 1], 'fewestGF' => ['f', 'asc', 0], 'avgFewestGF' => ['f', 'asc', 1],
        'ga' => ['a', 'asc', 0], 'avgGA' => ['a', 'asc', 1], 'mostGA' => ['a', 'desc', 0], 'avgMostGA' => ['a', 'desc', 1],
        'ppg' => ['pts', 'desc', 1], 'pctPoints' => ['pts', 'desc', 2], 'drawPct' => ['d', 'desc', 2],
    ];
    $leagueRows = null;
    if (isset($tableRank[$stat])) {
        $saved = leagueTableHistoryGet($pdo, $seasonId);
        if ($saved && $saved['rows']) $leagueRows = $saved['rows'];
        else {
            $isCur = $pdo->prepare('SELECT competition_id, is_current FROM seasons WHERE id = ?');
            $isCur->execute([$seasonId]);
            $sr = $isCur->fetch(PDO::FETCH_ASSOC);
            if ($sr && (int)$sr['is_current'] === 1) $leagueRows = leagueTableHistoryLiveSnapshot($pdo, ['id' => $seasonId, 'competition_id' => $sr['competition_id']])['rows'] ?: null;
        }
    }
    $extraFilter(['season' => true, 'competition' => true, 'competition_list' => $allComps, 'extra' => '<input type="hidden" name="stat" value="' . $esc($stat) . '">']);
    ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h6 text-uppercase text-muted">Choose a statistic</h2>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($statDefs as $key => [$label]): ?>
            <a class="btn btn-sm <?= $key === $stat ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= $esc($url(['tab' => 'teamstats', 'stat' => $key])) ?>"><?= $esc($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div></section>

    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5"><?= $esc($statLabel) ?> <small class="text-muted">· <?= $esc($seasonLabel($seasonId)) ?><?= $competition !== '' ? ' · ' . $esc($competition) : '' ?></small></h2>
        <?php if ($overall): ?>
            <p class="mb-3"><span class="fs-3 fw-bold"><?= $esc($overall[1]) ?></span> <span class="text-muted"><?= $competition !== '' ? '' : 'all competitions' ?><?= $overall[2] !== '' ? ' · ' . $esc($overall[2]) : '' ?></span></p>
        <?php endif; ?>
        <?php if (!$rowsOut): ?><p class="text-muted mb-0">No scored matches for this season.</p><?php elseif (count($rowsOut) > 1): ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table">
            <?= $tableHead([['#'], 'Competition', [$statLabel], ['Games'], 'Detail']) ?><tbody>
            <?php foreach ($rowsOut as $i => [$sname, $c, $games]): ?>
            <tr><td class="text-center"><?= $i + 1 ?></td><th scope="row"><?= $esc($sname) ?></th><td class="text-center fw-bold"><?= $esc($c[1]) ?></td><td class="text-center"><?= $games ?></td><td><?= $esc($c[2]) ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <p class="text-muted small mt-2 mb-0">By competition, ranked <?= $statDir === 'asc' ? 'lowest' : 'highest' ?> first. "Current" runs show the run standing at the latest match in this season.</p>
        <?php endif; ?>
    </div></section>

    <?php if (isset($tableRank[$stat])): [$col, $dir, $mode] = $tableRank[$stat]; ?>
    <section class="card shadow-sm"><div class="card-body">
        <h2 class="h5">League ranking <small class="text-muted">· <?= $esc($seasonLabel($seasonId)) ?></small></h2>
        <?php if (!$leagueRows): ?><p class="text-muted mb-0">No league table saved for this season, so other clubs can't be ranked.</p><?php else:
            $ranked = [];
            foreach ($leagueRows as $r) {
                $p = max(1, (int)$r['p']);
                $v = match (true) {
                    $col === 'pts' && $mode === 1 => $r['pts'] / $p,
                    $col === 'pts' => 100 * $r['pts'] / (3 * $p),
                    $col === 'd' => 100 * $r['d'] / $p,
                    $mode === 1 => $r[$col] / $p,
                    default => (float)$r[$col],
                };
                $ranked[] = [$r['club'], $v, (int)$r['p']];
            }
            usort($ranked, static fn($a, $b) => $statDir === 'asc' ? $a[1] <=> $b[1] : $b[1] <=> $a[1]);
            $fmt = static fn(float $v): string => $mode === 2 ? number_format($v, 1) . '%' : ($mode === 1 ? number_format($v, 2) : (string)(int)$v); ?>
        <p class="text-muted small">Every club in the saved table for that season (league games only).</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table">
            <?= $tableHead([['#'], 'Club', [$statLabel], ['P']]) ?><tbody>
            <?php foreach ($ranked as $i => [$club, $v, $p]): ?>
            <tr class="<?= $isUs((string)$club) ? 'table-warning fw-semibold' : '' ?>"><td class="text-center"><?= $i + 1 ?></td><th scope="row"><?= $esc($club) ?></th><td class="text-center"><?= $esc($fmt($v)) ?></td><td class="text-center"><?= $p ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div></section>
    <?php endif; ?>
<?php endif; ?>
