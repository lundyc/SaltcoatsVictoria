<?php
/** Referees tab for stats.php (included inside the tab switch; $pdo and $esc come from stats.php). */
require_once __DIR__ . '/lib/referee_stats.php';
$refData = hub_referee_dataset($pdo);
$refs = $refData['refs']; $base = $refData['baseline']; $lg = $refData['league'];
$refKey = is_string($_GET['ref'] ?? null) ? hub_ref_norm($_GET['ref']) : '';
$uk = static fn(string $d): string => date('d/m/Y', strtotime($d));
$fmt = static fn(?float $v, int $dp = 1): string => $v === null ? '—' : number_format($v, $dp);
$pct = static fn(?float $v): string => $v === null ? '—' : number_format($v * 100, 0) . '%';
$initials = static function (string $n): string { $p = preg_split('/\s+/', trim($n)); return strtoupper(mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : '')); };
// value bar with a marker at the league average (as on a "referee card")
$rateBar = static function (?float $v, float $avg, string $unit, int $n) use ($fmt, $esc): string {
    [$label, $mod] = hub_ref_rate_label($v, $avg);
    $max = max($avg * 2, ($v ?? 0) * 1.15, 0.1);
    $fill = $v === null ? 0 : min(100, $v / $max * 100); $mark = min(100, $avg / $max * 100);
    return '<div class="rf-rate"><div class="rf-rate__value">' . $esc($fmt($v)) . ' <span>/ ' . $esc($unit) . '</span></div>'
        . '<div class="rf-bar" role="img" aria-label="' . $esc($label) . ', league average ' . $esc($fmt($avg)) . '"><i class="rf-bar__fill rf-' . $mod . '" style="width:' . round($fill, 1) . '%"></i><i class="rf-bar__avg" style="left:' . round($mark, 1) . '%" title="League average ' . $esc($fmt($avg)) . '"></i></div>'
        . '<div class="rf-rate__label rf-t-' . $mod . '">' . $esc($label) . '</div><div class="rf-rate__sub">League average ' . $esc($fmt($avg)) . ' &middot; ' . (int)$n . ' match' . ($n === 1 ? '' : 'es') . '</div></div>';
};
$fairText = static function (array $r) use ($base, $pct): array {
    if ($r['n_ours'] === 0) { return ['none', 'Hasn&rsquo;t refereed a Saltcoats match yet.', '']; }
    if ($r['share'] === null) { return ['none', 'No cards were shown to either side in our games with this referee.', '']; }
    $dir = ['even' => ['in line', 'In line with how we&rsquo;re usually carded'], 'harder' => ['harder', 'Harder on us than usual'], 'easier' => ['easier', 'Easier on us than usual']][$r['fair_dir']];
    $note = $r['n_ours'] < 3 ? 'Only ' . $r['n_ours'] . ' game' . ($r['n_ours'] === 1 ? '' : 's') . ' &mdash; a guide, not a verdict.' : '';
    return [$dir[0], $dir[1], $note];
};
?>
<style>
.rf-box{border:1px solid var(--bs-border-color,#dee2e6);border-radius:.6rem;padding:1rem}
.rf-box h3{font-size:.85rem;font-weight:700;margin:0 0 .5rem}
.rf-avatar{width:3rem;height:3rem;border-radius:50%;background:#e9ecef;color:#495057;display:grid;place-items:center;font-weight:700}
.rf-rate__value{font-size:1.6rem;font-weight:700;line-height:1}.rf-rate__value span{font-size:.8rem;font-weight:400;color:#6c757d}
.rf-bar{position:relative;height:.5rem;background:#e3e6ea;border-radius:99px;margin:.7rem 0 .45rem}
.rf-bar__fill{position:absolute;left:0;top:0;bottom:0;border-radius:99px;display:block}
.rf-bar__avg{position:absolute;top:-.25rem;bottom:-.25rem;width:2px;background:#212529;display:block;border-radius:2px}
.rf-high{background:#f0a52e}.rf-low{background:#2f9e6b}.rf-mid{background:#8f95a1}.rf-muted{background:#bbb}
.rf-t-high{color:#a86500}.rf-t-low{color:#1d7a50}.rf-t-mid,.rf-t-muted{color:#6c757d}
.rf-rate__label{font-weight:700;font-size:.85rem}.rf-rate__sub{font-size:.75rem;color:#6c757d;margin-top:.15rem}
.rf-pill{display:inline-block;padding:.15rem .6rem;border-radius:99px;font-size:.8rem;font-weight:600}
.rf-pill--in{background:#e3f4ec;color:#146c43}.rf-pill--harder{background:#fdecd0;color:#8a5200}.rf-pill--easier{background:#e0ecfb;color:#0b4f9e}.rf-pill--none{background:#eceef1;color:#5b616c}
.rf-sub{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#6c757d;font-weight:700;margin:0 0 .4rem}
.rf-bigpct{font-size:2rem;font-weight:800;line-height:1}
</style>

<?php if ($refKey !== '' && isset($refs[$refKey])): $r = $refs[$refKey]; [$fk, $ftext, $fnote] = $fairText($r); $g = max(1, (int)$r['n_ours']); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="?tab=referees"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>All referees</a>
        <a class="btn btn-sm btn-primary" href="/admin/referee_profile_pdf.php?ref=<?= $esc(rawurlencode($r['key'])) ?>"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Export</a>
    </div>

    <div class="card card-body shadow-sm mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rf-avatar" aria-hidden="true"><?= $esc($initials($r['name'])) ?></div>
            <div><h2 class="h4 mb-0"><?= $esc($r['name']) ?></h2>
                <div class="text-muted small"><?= $r['club'] ? $esc($r['club']) . ' &middot; ' : '' ?><?= (int)$r['n_matches'] ?> match<?= $r['n_matches'] === 1 ? '' : 'es' ?> on record, <?= (int)$r['n_ours'] ?> with Saltcoats</div></div>
        </div>
        <p class="rf-sub">Cards and penalties per match &mdash; every match of his on record, compared with all referees</p>
        <div class="row g-3">
            <div class="col-md-4"><div class="rf-box"><h3><i class="fa-solid fa-square me-1" style="color:#f5c518" aria-hidden="true"></i>Yellow cards</h3><?= $rateBar($r['avg_yellow'], $lg['yellow'], 'match', $r['n_matches']) ?></div></div>
            <div class="col-md-4"><div class="rf-box"><h3><i class="fa-solid fa-square me-1" style="color:#e5484d" aria-hidden="true"></i>Red cards</h3><?= $rateBar($r['avg_red'], $lg['red'], 'match', $r['n_matches']) ?></div></div>
            <div class="col-md-4"><div class="rf-box"><h3><i class="fa-solid fa-bullseye me-1" aria-hidden="true"></i>Penalties</h3><?= $rateBar($r['avg_pens'], $lg['pens'], 'match', $r['n_matches']) ?></div></div>
        </div>
    </div>

    <?php if ($r['n_ours'] === 0): ?>
    <div class="alert alert-info d-flex align-items-start gap-3 mb-4">
        <i class="fa-solid fa-circle-info fs-4 mt-1" aria-hidden="true"></i>
        <div><div class="fw-semibold"><?= $esc($r['name']) ?> hasn&rsquo;t refereed a Saltcoats game yet.</div>
            <div class="small">So there&rsquo;s no record with us to show. Below is how he has refereed <strong><?= (int)$r['n_matches'] ?></strong> other match<?= $r['n_matches'] === 1 ? '' : 'es' ?> in the league, so you know what to expect.</div></div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm mb-4"><div class="card-body">
        <h3 class="h6 mb-3">Record with Saltcoats</h3>
        <?php if ($r['n_ours'] === 0): ?><p class="text-muted mb-0">This referee hasn&rsquo;t taken one of our games yet.</p><?php else: ?>
        <?php $recRows = [
            ['Games refereed', (int)$r['n_ours'], 'Goals for', (int)$r['gf']],
            ['Won', (int)$r['w'], 'Goals against', (int)$r['ga']],
            ['Drawn', (int)$r['d'], 'Penalties for / against', (int)$r['pen_us'] . ' / ' . (int)$r['pen_opp']],
            ['Lost', (int)$r['l'], 'Points per game with him', $fmt($r['ppg'], 2)],
            ['Last refereed us', $uk((string)$r['last_ours']), 'Our usual points per game', $fmt($base['ppg'], 2)],
        ]; ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <tbody>
            <?php foreach ($recRows as [$l1, $v1, $l2, $v2]): ?>
                <tr><th scope="row" class="fw-normal text-muted" style="width:30%"><?= $esc($l1) ?></th><td class="fw-semibold" style="width:20%"><?= $esc($v1) ?></td><th scope="row" class="fw-normal text-muted" style="width:30%"><?= $esc($l2) ?></th><td class="fw-semibold" style="width:20%"><?= $esc($v2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div></div>

    <div class="card shadow-sm mb-4"><div class="card-body">
        <h3 class="h5 mb-1">Cards in our games with <?= $esc($r['name']) ?></h3>
        <?php if ($r['n_ours'] === 0): ?><p class="text-muted mb-0">Nothing to show until he has refereed one of our games.</p><?php else: ?>
        <p class="text-muted small mb-4">Across the <?= (int)$r['n_ours'] ?> game<?= $r['n_ours'] === 1 ? '' : 's' ?> of ours he has refereed.</p>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <p class="rf-sub">1. Cards he has given us</p>
                <table class="table table-sm mb-0"><tbody>
                    <tr><td>Yellow cards</td><td class="text-end fw-semibold"><?= (int)$r['y_us'] ?></td></tr>
                    <tr><td>Players sent off (red)</td><td class="text-end fw-semibold"><?= (int)$r['r_us'] ?></td></tr>
                    <tr><td>Staff cards</td><td class="text-end fw-semibold"><?= (int)$r['staff_us'] ?></td></tr>
                    <tr class="table-light"><td class="fw-semibold">Total player cards</td><td class="text-end fw-bold"><?= (int)$r['cards_us'] ?></td></tr>
                </tbody></table>
            </div>
            <div class="col-md-6">
                <p class="rf-sub">2. Cards he has given the other team</p>
                <table class="table table-sm mb-0"><tbody>
                    <tr><td>Yellow cards</td><td class="text-end fw-semibold"><?= (int)$r['y_opp'] ?></td></tr>
                    <tr><td>Players sent off (red)</td><td class="text-end fw-semibold"><?= (int)$r['r_opp'] ?></td></tr>
                    <tr><td>Staff cards</td><td class="text-end fw-semibold"><?= (int)$r['staff_opp'] ?></td></tr>
                    <tr class="table-light"><td class="fw-semibold">Total player cards</td><td class="text-end fw-bold"><?= (int)$r['cards_opp'] ?></td></tr>
                </tbody></table>
            </div>
        </div>

        <p class="rf-sub">3. The averages (per game)</p>
        <div class="table-responsive mb-4"><table class="table table-sm align-middle mb-0">
            <thead><tr><th></th><th class="text-end">Per game with <?= $esc($r['name']) ?></th></tr></thead>
            <tbody>
                <tr><td>Yellow cards given to us</td><td class="text-end fw-semibold"><?= $esc($fmt($r['y_us'] / $g)) ?></td></tr>
                <tr><td>Yellow cards given to the other team</td><td class="text-end fw-semibold"><?= $esc($fmt($r['y_opp'] / $g)) ?></td></tr>
                <tr><td>Red cards given to us</td><td class="text-end fw-semibold"><?= $esc($fmt($r['r_us'] / $g, 2)) ?></td></tr>
                <tr><td>Red cards given to the other team</td><td class="text-end fw-semibold"><?= $esc($fmt($r['r_opp'] / $g, 2)) ?></td></tr>
            </tbody>
        </table></div>

        <?php if ($r['share'] !== null): ?>
        <div class="d-flex flex-wrap align-items-center gap-3 p-3 rounded" style="background:#f6f7f9">
            <div><div class="rf-bigpct"><?= (int)$r['fair'] ?>%</div><div class="small text-muted">card fairness</div></div>
            <div class="flex-grow-1">
                <span class="rf-pill rf-pill--<?= $fk === 'in line' ? 'in' : $esc($fk) ?>"><?= $ftext ?></span>
                <div class="small mt-1">He gives us <strong><?= $esc($pct($r['share'])) ?></strong> of the cards in his games (<?= (int)$r['cards_us'] ?> of <?= (int)($r['cards_us'] + $r['cards_opp']) ?>). Across all referees we get <?= $esc($pct($base['share'])) ?>.</div>
                <?php if ($fnote): ?><div class="small text-warning-emphasis"><?= $fnote ?></div><?php endif; ?>
            </div>
        </div>
        <?php else: ?><p class="text-muted mb-0"><?= $ftext ?></p><?php endif; ?>
        <?php endif; ?>
    </div></div>

    <?php if ($r['ours']): ?>
    <div class="card shadow-sm mb-4"><div class="card-body">
        <h3 class="h6 mb-3">Our games with <?= $esc($r['name']) ?></h3>
        <div class="table-responsive"><table class="table align-middle mb-0 hub-data-table">
            <thead><tr><th>Date</th><th>Match</th><th>Result</th><th class="text-center">Yellows (us / them)</th><th class="text-center">Reds (us / them)</th><th class="text-center">Penalties (us / them)</th></tr></thead>
            <tbody>
            <?php foreach ($r['ours'] as $o): ?>
                <tr>
                    <td><?= $esc($uk($o['date'])) ?></td>
                    <td><a href="/admin/match.php?id=<?= (int)$o['fixture_id'] ?>&amp;season_id=<?= (int)$o['season_id'] ?>"><?= $esc($o['home'] ? 'Saltcoats Victoria v ' . $o['opponent'] : $o['opponent'] . ' v Saltcoats Victoria') ?></a><div class="small text-muted"><?= $esc($o['competition']) ?></div></td>
                    <td><span class="badge text-bg-<?= $o['result'] === 'W' ? 'success' : ($o['result'] === 'L' ? 'danger' : 'secondary') ?>"><?= $esc($o['result']) ?></span> <?= (int)$o['for'] ?>&ndash;<?= (int)$o['against'] ?></td>
                    <td class="text-center"><?= (int)$o['y_us'] ?> / <?= (int)$o['y_opp'] ?></td>
                    <td class="text-center"><?= (int)$o['r_us'] ?> / <?= (int)$o['r_opp'] ?></td>
                    <td class="text-center"><?= (int)$o['pen_us'] ?> / <?= (int)$o['pen_opp'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
    <?php endif; ?>

    <?php endif; ?>

    <div class="card shadow-sm mb-4"><div class="card-body">
        <h3 class="h5 mb-1">What <?= $esc($r['name']) ?> is like</h3>
        <p class="text-muted small mb-3"><?= $r['n_ours'] > 0 ? 'Across all ' . (int)$r['n_matches'] . ' matches of his on record (ours and other clubs&rsquo;), compared with the league average.' : 'From his ' . (int)$r['n_matches'] . ' match' . ($r['n_matches'] === 1 ? '' : 'es') . ' on record, compared with the league average.' ?></p>
        <ul class="mb-3">
            <?php foreach (hub_ref_style_lines($r, $lg) as $line): ?><li><?= $esc($line) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($r['n_matches'] > 0): ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Per match</th><th class="text-end">With <?= $esc($r['name']) ?></th><th class="text-end">League average</th></tr></thead>
            <tbody>
                <tr><td>Yellow cards</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_yellow'])) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['yellow'])) ?></td></tr>
                <tr><td>Yellow cards to the home team</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_yh'])) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['yh'])) ?></td></tr>
                <tr><td>Yellow cards to the away team</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_ya'])) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['ya'])) ?></td></tr>
                <tr><td>Red cards</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_red'], 2)) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['red'], 2)) ?></td></tr>
                <tr><td>Penalties scored</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_pens'], 2)) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['pens'], 2)) ?></td></tr>
                <tr><td>Goals</td><td class="text-end fw-semibold"><?= $esc($fmt($r['avg_goals'])) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['goals'])) ?></td></tr>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($r['n_matches'] > 0 && array_sum($r['bands']) > 0): $bmax = max(max($r['bands']) / $r['n_matches'], max($lg['bands']), 0.01); ?>
        <h4 class="h6 mt-4 mb-2">When he gives cards</h4>
        <p class="text-muted small mb-2">Yellow and red cards by time of game (<?= (int)array_sum($r['bands']) ?> cards with a known minute, both teams), per match, compared with the league average.</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Minutes</th><th class="text-end">Cards</th><th class="text-end">Per match</th><th class="text-end">League per match</th><th style="width:35%"></th></tr></thead>
            <tbody>
            <?php foreach (HUB_REF_BANDS as $bi => $bl): $pm = $r['bands'][$bi] / $r['n_matches']; ?>
                <tr><td><?= $esc($bl) ?></td><td class="text-end"><?= (int)$r['bands'][$bi] ?></td><td class="text-end fw-semibold"><?= $esc($fmt($pm, 2)) ?></td><td class="text-end text-muted"><?= $esc($fmt($lg['bands'][$bi], 2)) ?></td>
                <td><div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= (int)round($pm / $bmax * 100) ?>%"></div></div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div></div>

    <?php if ($r['other']): ?>
    <div class="card shadow-sm mb-4"><div class="card-body">
        <h3 class="h6 mb-3"><?= $r['n_ours'] > 0 ? 'His other matches' : 'His matches' ?> <span class="text-muted fw-normal">(<?= count($r['other']) ?>)</span></h3>
        <div class="table-responsive"><table class="table align-middle mb-0 hub-data-table">
            <thead><tr><th>Date</th><th>Match</th><th class="text-center">Score</th><th class="text-center">Yellows (home / away)</th><th class="text-center">Reds (home / away)</th><th class="text-center">Penalties</th><th>Competition</th></tr></thead>
            <tbody>
            <?php foreach ($r['other'] as $m): ?>
                <tr>
                    <td><?= $esc($uk($m['date'])) ?></td>
                    <td><?= $esc($m['home'] . ' v ' . $m['away']) ?></td>
                    <td class="text-center"><?= (int)$m['hg'] ?>&ndash;<?= (int)$m['ag'] ?></td>
                    <td class="text-center"><?= (int)$m['yh'] ?> / <?= (int)$m['ya'] ?></td>
                    <td class="text-center"><?= (int)$m['rh'] ?> / <?= (int)$m['ra'] ?></td>
                    <td class="text-center"><?= (int)$m['pens'] ?></td>
                    <td class="small text-muted"><?= $esc($m['comp']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
    <?php endif; ?>

    <details class="small text-muted mb-4"><summary>How these numbers are worked out</summary>
        <ul class="mt-2">
            <li><strong>Cards and penalties per match</strong> (the bars at the top) use every match of his the Hub has card data for &mdash; our own games plus the opponent reports fetched from COMET. The black marker is the average across all referees.</li>
            <li><strong>Card fairness</strong> compares the share of cards (yellows and reds) we get in his games with the share we get from all referees. 100% means his split matches our usual one; the further apart, the lower the score. It can&rsquo;t say whether the cards were deserved, so treat it as a guide &mdash; especially with only a few games.</li>
            <li>Fouls aren&rsquo;t recorded anywhere, so red cards and penalties are shown instead. Penalties are those that were scored. A second-yellow dismissal counts as two yellows and a red in our games; in other clubs&rsquo; reports it appears only as a yellow, so the league red-card rate is slightly low.</li>
            <li>Staff cards are listed separately and aren&rsquo;t part of the totals or the fairness score.</li>
        </ul>
    </details>

<?php else: ?>
    <?php if ($refKey !== ''): ?><div class="alert alert-warning">That referee wasn&rsquo;t found.</div><?php endif; ?>
    <?php $withUs = count(array_filter($refs, fn($x) => $x['n_ours'] > 0)); ?>
    <div class="card card-body shadow-sm mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div><h2 class="h5 mb-1">Referees</h2>
                <p class="text-muted small mb-0"><?= count($refs) ?> referees on record from COMET (<?= $withUs ?> have taken Saltcoats games). This is internal scouting information.</p></div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="rfOnlyUs" checked><label class="form-check-label small" for="rfOnlyUs">Only referees who&rsquo;ve done our games</label></div>
                <input type="search" id="rfSearch" class="form-control form-control-sm" style="width:14rem" placeholder="Search referees" aria-label="Search referees">
            </div>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0 hub-data-table" id="rfTable">
            <thead><tr><th>Referee</th><th class="text-center">Matches</th><th class="text-center">With us</th><th>Last refereed us</th><th style="min-width:11rem">Yellows / match</th><th class="text-center">Cards to us</th><th>Card fairness</th></tr></thead>
            <tbody>
            <?php foreach ($refs as $x): [$fk, $ftext] = $fairText($x); [$lab, $mod] = hub_ref_rate_label($x['avg_yellow'], $lg['yellow']); ?>
                <tr data-name="<?= $esc(strtolower($x['name'] . ' ' . ($x['club'] ?? ''))) ?>" data-us="<?= (int)$x['n_ours'] ?>">
                    <td><a class="fw-semibold" href="?tab=referees&amp;ref=<?= $esc(rawurlencode($x['key'])) ?>"><?= $esc($x['name']) ?></a><?= $x['club'] ? '<div class="small text-muted">' . $esc($x['club']) . '</div>' : '' ?></td>
                    <td class="text-center"><?= (int)$x['n_matches'] ?></td>
                    <td class="text-center"><?= (int)$x['n_ours'] ?: '<span class="badge text-bg-info" title="Has not refereed Saltcoats yet">New to us</span>' ?></td>
                    <td><?= $x['last_ours'] ? $esc($uk((string)$x['last_ours'])) : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td><?php if ($x['avg_yellow'] !== null): ?><span class="fw-semibold"><?= $esc($fmt($x['avg_yellow'])) ?></span> <span class="small rf-t-<?= $esc($mod) ?>" style="filter:brightness(.75)"><?= $esc($lab) ?></span><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
                    <td class="text-center"><?= $x['share'] !== null ? $esc($pct($x['share'])) . ' <span class="small text-muted">(' . (int)$x['cards_us'] . '/' . (int)($x['cards_us'] + $x['cards_opp']) . ')</span>' : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td><?php if ($x['fair'] !== null): ?><span class="rf-pill rf-pill--<?= $fk === 'in line' ? 'in' : $esc($fk) ?>"><?= (int)$x['fair'] ?>%</span><?php if ($x['n_ours'] < 3): ?> <span class="small text-muted" title="Only <?= (int)$x['n_ours'] ?> game(s)">small sample</span><?php endif; ?><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="small text-muted mt-3 mb-0">League average: <?= $esc($fmt($lg['yellow'])) ?> yellows, <?= $esc($fmt($lg['red'], 2)) ?> reds and <?= $esc($fmt($lg['pens'], 2)) ?> penalties per match (<?= (int)$lg['matches'] ?> matches). Card fairness compares the share of cards we get in a referee&rsquo;s games with our share across all referees (<?= $esc($pct($base['share'])) ?>).</p>
    </div>
    <script>
    (function () {
        var rows = Array.prototype.slice.call(document.querySelectorAll('#rfTable tbody tr')), only = document.getElementById('rfOnlyUs'), q = document.getElementById('rfSearch');
        function apply() { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = (only.checked && r.getAttribute('data-us') === '0') || (t && r.getAttribute('data-name').indexOf(t) < 0); }); }
        only.addEventListener('change', apply); q.addEventListener('input', apply); apply();
    })();
    </script>
<?php endif; ?>
