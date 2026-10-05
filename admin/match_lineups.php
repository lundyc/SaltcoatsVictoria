<?php

declare(strict_types=1);

/**
 * Line-ups (both teams) — the Stage 1 replacement for the Starting 11 tab.
 * Records Saltcoats' AND the opponent's starting XI, substitutes, captain,
 * shirt numbers and in-match substitutions on the normalised matchday_*
 * tables (lib/matchday_record.php). The legacy starting11_*_json / matches.json
 * stores are kept in sync by that lib's projection, so Match Graphics and the
 * public site are unaffected.
 *
 * Rendered standalone or iframed into match.php with ?embedded=1 (same
 * mechanism the old fixture_starting_11.php used).
 */

$embedded = (string) ($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1';
if ($embedded) {
    $pageHero = [];
}

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/matchday_record.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    echo '<div class="container-fluid"><div class="alert alert-danger">Access denied.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$fixture = $fixtureId > 0 ? matchday_record_fixture($pdo, $fixtureId) : null;
if ($fixture === null) {
    echo '<div class="container-fluid"><div class="alert alert-warning">Fixture not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$seasonId = (int) $fixture['season_id'];
$season = function_exists('getSeasonById') ? getSeasonById($pdo, $seasonId) : null;
$seasonLocked = is_array($season) && (int) ($season['is_locked'] ?? 0) === 1;

$isHome = (int) ($fixture['is_home'] ?? 1) === 1;
$opponentName = trim((string) ($fixture['opponent'] ?? 'Opponent')) ?: 'Opponent';
$opponentId = (int) ($fixture['opponent_id'] ?? 0);
$clubName = 'Saltcoats Victoria';

$sideMeta = [
    'svfc' => ['label' => $clubName, 'venue' => $isHome ? 'Home' : 'Away'],
    'opponent' => ['label' => $opponentName, 'venue' => $isHome ? 'Away' : 'Home'],
];

matchday_record_ensure_schema($pdo);

/* ---- squad + opponent name history for the pickers -------------------- */
$squad = $pdo->query(
    "SELECT id, name, position, squad_number, status
     FROM players
     WHERE active = 1
     ORDER BY name ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$squadById = [];
foreach ($squad as $row) {
    $squadById[(int) $row['id']] = $row;
}

$opponentNameHistory = [];
if ($opponentId > 0) {
    $histStmt = $pdo->prepare(
        "SELECT DISTINCT ml.player_name
         FROM matchday_lineups ml
         JOIN match_fixtures mf ON mf.id = ml.fixture_id
         WHERE ml.side = 'opponent' AND mf.opponent_id = :oid
           AND ml.player_name <> '' AND ml.fixture_id <> :fid
         ORDER BY ml.player_name ASC
         LIMIT 200"
    );
    $histStmt->execute([':oid' => $opponentId, ':fid' => $fixtureId]);
    $opponentNameHistory = $histStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* ---- flash ----------------------------------------------------------- */
$flash = '';
$flashType = 'success';
if (isset($_GET['saved'])) {
    $flash = match ((string) $_GET['saved']) {
        'svfc' => $clubName . ' line-up saved.',
        'opponent' => $opponentName . ' line-up saved.',
        'sub' => 'Substitution recorded.',
        'sub_deleted' => 'Substitution removed.',
        default => 'Saved.',
    };
}
if (isset($_GET['error'])) {
    $flash = (string) $_GET['error'];
    $flashType = 'danger';
}

/* ---- current record ------------------------------------------------- */
$record = matchday_record_load($pdo, $fixtureId);

/**
 * Build the 11 starter rows + N sub rows for a side from stored data,
 * padding to a minimum number of blank rows so the form is usable when empty.
 */
function ml_rows_for_side(array $record, string $side, int $minSubRows = 5): array
{
    $lineup = $record['lineups'][$side] ?? [];
    $starters = array_values(array_filter($lineup, static fn($r) => (int) $r['is_starting'] === 1));
    $subs = array_values(array_filter($lineup, static fn($r) => (int) $r['is_starting'] === 0));

    $starterRows = [];
    for ($i = 0; $i < 11; $i++) {
        $starterRows[$i] = $starters[$i] ?? null;
    }
    $targetSubRows = max($minSubRows, count($subs) + 2);
    for ($i = count($subs); $i < $targetSubRows; $i++) {
        $subs[$i] = null;
    }
    return ['starters' => $starterRows, 'subs' => array_values($subs)];
}

$formationTemplates = matchday_record_formation_templates();
$formations = array_merge([''], array_keys($formationTemplates));

require_once __DIR__ . '/lib/functions.php';
?>
<style>
    <?php if ($embedded): ?>
    body > nav, body > footer, .hub-desktop-nav-toggle { display: none !important; }
    body { min-height: 0; background: #fff; }
    body > main > .container-fluid { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
    .ml-page { padding: 1rem 1.15rem 2rem; }
    <?php endif; ?>
    .ml-pitch { position: relative; width: 100%; max-width: 340px; aspect-ratio: 3 / 4; margin: 0 auto .5rem;
        background: linear-gradient(#1f7a3d, #2e9150); border: 2px solid rgba(255,255,255,.5); border-radius: 8px; overflow: hidden; touch-action: none; }
    .ml-pitch::before { content: ""; position: absolute; left: 6%; right: 6%; top: 6%; bottom: 6%; border: 1px solid rgba(255,255,255,.35); border-radius: 4px; }
    .ml-pitch::after { content: ""; position: absolute; left: 6%; right: 6%; top: 50%; border-top: 1px solid rgba(255,255,255,.35); }
    .ml-chip { position: absolute; transform: translate(-50%, -50%); min-width: 2.1rem; padding: .1rem .3rem; border-radius: 5px;
        background: #fff; border: 1px solid rgba(0,0,0,.2); font-size: .68rem; line-height: 1.15; text-align: center; cursor: grab;
        box-shadow: 0 1px 3px rgba(0,0,0,.3); user-select: none; }
    .ml-chip.is-empty { opacity: .5; }
    .ml-chip b { display: block; font-size: .8rem; }
    .ml-chip.dragging { cursor: grabbing; z-index: 5; }
</style>

<div class="ml-page">
    <?php if (!$embedded): ?>
        <nav class="hub-breadcrumb" aria-label="Breadcrumb">
            <a href="matches.php">Fixtures</a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <a href="match.php?id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>"><?= h($opponentName) ?></a>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page">Line-ups</span>
        </nav>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <div class="fixture-panel-kicker text-uppercase small text-secondary fw-bold">Team sheet</div>
            <h2 class="h4 mb-0">Line-ups
                <span class="text-secondary fw-normal fs-6">· <?= h($isHome ? $clubName : $opponentName) ?> v <?= h($isHome ? $opponentName : $clubName) ?></span>
            </h2>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="match_graphics.php?fixture_id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>"<?= $embedded ? ' target="_top"' : '' ?>>
            <i class="fa-solid fa-image me-1" aria-hidden="true"></i>Social graphics
        </a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($seasonLocked): ?>
        <div class="alert alert-warning">This season is locked — line-ups can be viewed but not changed.</div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach (['svfc', 'opponent'] as $side): ?>
            <?php
            $rows = ml_rows_for_side($record, $side);
            $meta = $sideMeta[$side];
            $formation = $record['formations'][$side]['formation_key'] ?? '';
            $layout = json_decode((string) ($record['formations'][$side]['layout_json'] ?? ''), true);
            $layout = is_array($layout) ? $layout : null;
            $captainIndex = '';
            foreach ($rows['starters'] as $ix => $r) {
                if ($r !== null && (int) $r['is_captain'] === 1) {
                    $captainIndex = (string) $ix;
                }
            }
            $sideSubs = $record['subs'][$side] ?? [];
            $chipLabels = [];
            foreach ($rows['starters'] as $ix => $r) {
                $nm = $r !== null ? trim((string) $r['player_name']) : '';
                $parts = $nm === '' ? [] : preg_split('/\s+/', $nm);
                $chipLabels[$ix] = [
                    'num' => $r !== null && $r['shirt_number'] !== null ? (int) $r['shirt_number'] : ($ix + 1),
                    'name' => $nm === '' ? '' : (string) end($parts),
                ];
            }
            ?>
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <span class="badge bg-<?= $side === 'svfc' ? 'primary' : 'secondary' ?>"><?= h($meta['venue']) ?></span>
                            <strong class="ms-1"><?= h($meta['label']) ?></strong>
                        </div>
                        <span class="small text-secondary"><?= $side === 'svfc' ? 'Pick from the squad' : 'Type the names' ?></span>
                    </div>
                    <div class="card-body">
                        <form method="post" action="match_formation_save.php" class="ml-pitch-form mb-3" id="pitch-<?= h($side) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                            <input type="hidden" name="side" value="<?= h($side) ?>">
                            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
                            <input type="hidden" name="layout_json" value="">
                            <div class="d-flex align-items-end gap-2 mb-2" style="max-width:20rem">
                                <div class="flex-grow-1">
                                    <label class="form-label small fw-bold mb-1">Formation</label>
                                    <select name="formation_key" class="form-select form-select-sm" data-formation-select<?= $seasonLocked ? ' disabled' : '' ?>>
                                        <?php foreach ($formations as $f): ?>
                                            <option value="<?= h($f) ?>"<?= $f === $formation ? ' selected' : '' ?>><?= $f === '' ? '—' : h($f) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if (!$seasonLocked): ?>
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Save shape</button>
                                <?php endif; ?>
                            </div>
                            <div class="ml-pitch" data-pitch data-side="<?= h($side) ?>"
                                 data-layout='<?= h(json_encode($layout ?? new stdClass(), JSON_UNESCAPED_SLASHES)) ?>'
                                 data-chips='<?= h(json_encode($chipLabels, JSON_UNESCAPED_SLASHES)) ?>'
                                 data-locked="<?= $seasonLocked ? '1' : '0' ?>"></div>
                            <p class="small text-secondary mb-0">Drag the shirts to fine-tune, then Save shape. Changing the formation re-lays them out.</p>
                        </form>

                        <form method="post" action="match_lineup_save.php" class="ml-lineup-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                            <input type="hidden" name="side" value="<?= h($side) ?>">
                            <input type="hidden" name="formation_key" value="<?= h($formation) ?>">
                            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle ml-lineup-table mb-2">
                                    <thead>
                                        <tr class="small text-secondary">
                                            <th style="width:2rem">#</th>
                                            <th>Player</th>
                                            <th style="width:4.5rem">Shirt</th>
                                            <th style="width:5.5rem">Pos</th>
                                            <th style="width:2.5rem" title="Captain">C</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($i = 0; $i < 11; $i++): ?>
                                            <?php
                                            $r = $rows['starters'][$i];
                                            $rowPid = $r !== null ? (int) ($r['player_id'] ?? 0) : 0;
                                            $rowName = $r !== null ? (string) $r['player_name'] : '';
                                            $rowNum = $r !== null && $r['shirt_number'] !== null ? (int) $r['shirt_number'] : ($i + 1);
                                            $rowPos = $r !== null ? (string) ($r['position_label'] ?? '') : '';
                                            ?>
                                            <tr>
                                                <td class="text-secondary small"><?= $i + 1 ?></td>
                                                <td>
                                                    <?php if ($side === 'svfc'): ?>
                                                        <select name="starter_player_id[]" class="form-select form-select-sm"<?= $seasonLocked ? ' disabled' : '' ?>>
                                                            <option value="">— empty —</option>
                                                            <?php foreach ($squad as $p): ?>
                                                                <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $rowPid ? ' selected' : '' ?>>
                                                                    <?= h((string) $p['name']) ?><?= $p['status'] === 'trialist' ? ' (trialist)' : '' ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="text" name="starter_name[]" class="form-control form-control-sm" list="oppNameList" value="<?= h($rowName) ?>" autocomplete="off"<?= $seasonLocked ? ' disabled' : '' ?>>
                                                    <?php endif; ?>
                                                </td>
                                                <td><input type="number" name="starter_number[]" class="form-control form-control-sm" value="<?= $rowNum ?>" min="1" max="99"<?= $seasonLocked ? ' disabled' : '' ?>></td>
                                                <td><input type="text" name="starter_position[]" class="form-control form-control-sm" value="<?= h($rowPos) ?>" maxlength="12" placeholder="<?= $i === 0 ? 'GK' : '' ?>"<?= $seasonLocked ? ' disabled' : '' ?>></td>
                                                <td class="text-center"><input type="radio" name="captain_slot" value="<?= $i ?>"<?= (string) $i === $captainIndex ? ' checked' : '' ?><?= $seasonLocked ? ' disabled' : '' ?>></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-link btn-sm p-0 mb-2" data-clear-captain>Clear captain</button>

                            <div class="fw-bold small text-secondary text-uppercase mt-2 mb-1">Substitutes</div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle ml-lineup-table mb-2" data-sub-table>
                                    <thead>
                                        <tr class="small text-secondary">
                                            <th>Player</th>
                                            <th style="width:4.5rem">Shirt</th>
                                            <th style="width:5.5rem">Pos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows['subs'] as $j => $r): ?>
                                            <?php
                                            $rowPid = $r !== null ? (int) ($r['player_id'] ?? 0) : 0;
                                            $rowName = $r !== null ? (string) $r['player_name'] : '';
                                            $rowNum = $r !== null && $r['shirt_number'] !== null ? (string) (int) $r['shirt_number'] : '';
                                            $rowPos = $r !== null ? (string) ($r['position_label'] ?? '') : '';
                                            ?>
                                            <tr>
                                                <td>
                                                    <?php if ($side === 'svfc'): ?>
                                                        <select name="sub_player_id[]" class="form-select form-select-sm"<?= $seasonLocked ? ' disabled' : '' ?>>
                                                            <option value="">— empty —</option>
                                                            <?php foreach ($squad as $p): ?>
                                                                <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $rowPid ? ' selected' : '' ?>>
                                                                    <?= h((string) $p['name']) ?><?= $p['status'] === 'trialist' ? ' (trialist)' : '' ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="text" name="sub_name[]" class="form-control form-control-sm" list="oppNameList" value="<?= h($rowName) ?>" autocomplete="off"<?= $seasonLocked ? ' disabled' : '' ?>>
                                                    <?php endif; ?>
                                                </td>
                                                <td><input type="number" name="sub_number[]" class="form-control form-control-sm" value="<?= h($rowNum) ?>" min="1" max="99"<?= $seasonLocked ? ' disabled' : '' ?>></td>
                                                <td><input type="text" name="sub_position[]" class="form-control form-control-sm" value="<?= h($rowPos) ?>" maxlength="12"<?= $seasonLocked ? ' disabled' : '' ?>></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-add-sub-row<?= $seasonLocked ? ' disabled' : '' ?>><i class="fa-solid fa-plus me-1"></i>Add sub row</button>
                                <button type="submit" class="btn btn-primary btn-sm ms-auto"<?= $seasonLocked ? ' disabled' : '' ?>><i class="fa-solid fa-floppy-disk me-1"></i>Save <?= h($meta['label']) ?> line-up</button>
                            </div>
                        </form>

                        <hr>
                        <div class="fw-bold small text-secondary text-uppercase mb-2">Substitutions</div>
                        <?php if ($sideSubs === []): ?>
                            <p class="text-secondary small mb-2">No substitutions recorded.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush mb-2">
                                <?php foreach ($sideSubs as $s): ?>
                                    <li class="list-group-item d-flex align-items-center justify-content-between px-0 py-1 small">
                                        <span>
                                            <span class="badge bg-light text-dark border"><?= h(matchday_record_minute_label((int) $s['minute'], (int) $s['minute_extra'], $s['minute'] === null)) ?: '?' ?>'</span>
                                            <i class="fa-solid fa-arrow-right-arrow-left mx-1 text-secondary"></i>
                                            <span class="text-danger"><?= h((string) $s['player_off_name']) ?></span>
                                            <i class="fa-solid fa-arrow-right mx-1 text-secondary"></i>
                                            <span class="text-success"><?= h((string) $s['player_on_name']) ?></span>
                                            <?php if ((string) $s['reason'] !== ''): ?><span class="text-secondary">· <?= h((string) $s['reason']) ?></span><?php endif; ?>
                                        </span>
                                        <form method="post" action="match_sub_save.php" data-confirm="Remove this substitution?" data-confirm-action="Remove">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                                            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="sub_id" value="<?= (int) $s['id'] ?>">
                                            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0"<?= $seasonLocked ? ' disabled' : '' ?>><i class="fa-solid fa-xmark"></i></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php
                        $benchNames = [];
                        foreach (($record['lineups'][$side] ?? []) as $r) {
                            if ((string) $r['player_name'] !== '') {
                                $benchNames[] = (string) $r['player_name'];
                            }
                        }
                        $benchNames = array_values(array_unique($benchNames));
                        ?>
                        <?php if (!$seasonLocked): ?>
                        <form method="post" action="match_sub_save.php" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                            <input type="hidden" name="side" value="<?= h($side) ?>">
                            <input type="hidden" name="action" value="add">
                            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
                            <div class="col-12 col-sm">
                                <label class="form-label small mb-0">Off</label>
                                <input type="text" name="player_off_name" class="form-control form-control-sm" list="benchList_<?= h($side) ?>" required>
                            </div>
                            <div class="col-12 col-sm">
                                <label class="form-label small mb-0">On</label>
                                <input type="text" name="player_on_name" class="form-control form-control-sm" list="benchList_<?= h($side) ?>" required>
                            </div>
                            <div class="col-6 col-sm-2">
                                <label class="form-label small mb-0">Min</label>
                                <input type="number" name="minute" class="form-control form-control-sm" min="0" max="130">
                            </div>
                            <div class="col-6 col-sm-2">
                                <label class="form-label small mb-0">+</label>
                                <input type="number" name="minute_extra" class="form-control form-control-sm" min="0" max="20" value="0">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add sub</button>
                            </div>
                            <datalist id="benchList_<?= h($side) ?>">
                                <?php foreach ($benchNames as $bn): ?><option value="<?= h($bn) ?>"></option><?php endforeach; ?>
                                <?php if ($side === 'svfc'): foreach ($squad as $p): ?><option value="<?= h((string) $p['name']) ?>"></option><?php endforeach; endif; ?>
                            </datalist>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <datalist id="oppNameList">
        <?php foreach ($opponentNameHistory as $n): ?><option value="<?= h((string) $n) ?>"></option><?php endforeach; ?>
    </datalist>
</div>

<script>window.ML_FORMATIONS = <?= json_encode($formationTemplates, JSON_UNESCAPED_SLASHES) ?>;</script>
<script>
(function () {
    /* ---- formation pitch ---- */
    var GRID = (function () {
        var g = [{ x: 50, y: 92 }];
        for (var i = 0; i < 10; i++) { g.push({ x: 15 + (i % 5) * 17.5, y: i < 5 ? 62 : 30 }); }
        return g;
    })();

    document.querySelectorAll('[data-pitch]').forEach(function (pitch) {
        var form = pitch.closest('form');
        var select = form.querySelector('[data-formation-select]');
        var hidden = form.querySelector('input[name="layout_json"]');
        var locked = pitch.dataset.locked === '1';
        var chips = JSON.parse(pitch.dataset.chips || '[]');
        var stored = {};
        try { stored = JSON.parse(pitch.dataset.layout || '{}') || {}; } catch (e) { stored = {}; }

        function coordsFor(key) {
            return (window.ML_FORMATIONS && window.ML_FORMATIONS[key]) ? window.ML_FORMATIONS[key] : GRID;
        }
        var pos = [];
        function layout(fromStored) {
            var base = coordsFor(select ? select.value : '');
            pos = [];
            for (var i = 0; i < 11; i++) {
                if (fromStored && stored[i]) { pos[i] = { x: +stored[i].x, y: +stored[i].y }; }
                else { pos[i] = { x: base[i] ? base[i].x : 50, y: base[i] ? base[i].y : 50 }; }
            }
            render();
        }
        function render() {
            pitch.querySelectorAll('.ml-chip').forEach(function (c) { c.remove(); });
            pos.forEach(function (p, i) {
                var c = chips[i] || { num: i + 1, name: '' };
                var el = document.createElement('div');
                el.className = 'ml-chip' + (c.name ? '' : ' is-empty');
                el.style.left = p.x + '%';
                el.style.top = p.y + '%';
                el.innerHTML = '<b>' + (c.num || (i + 1)) + '</b>' + (c.name ? String(c.name).replace(/[<>&]/g, '') : '—');
                el.dataset.slot = i;
                if (!locked) { attachDrag(el); }
                pitch.appendChild(el);
            });
        }
        function attachDrag(el) {
            el.addEventListener('pointerdown', function (ev) {
                ev.preventDefault();
                el.setPointerCapture(ev.pointerId);
                el.classList.add('dragging');
                function move(e) {
                    var r = pitch.getBoundingClientRect();
                    var x = Math.max(2, Math.min(98, ((e.clientX - r.left) / r.width) * 100));
                    var y = Math.max(2, Math.min(98, ((e.clientY - r.top) / r.height) * 100));
                    el.style.left = x + '%'; el.style.top = y + '%';
                    pos[+el.dataset.slot] = { x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 };
                }
                function up() {
                    el.classList.remove('dragging');
                    el.removeEventListener('pointermove', move);
                    el.removeEventListener('pointerup', up);
                    syncHidden();
                }
                el.addEventListener('pointermove', move);
                el.addEventListener('pointerup', up);
            });
        }
        function syncHidden() {
            if (!hidden) { return; }
            var out = {};
            pos.forEach(function (p, i) { out[i] = p; });
            hidden.value = JSON.stringify(out);
        }

        layout(Object.keys(stored).length > 0);
        syncHidden();
        if (select && !locked) {
            select.addEventListener('change', function () { stored = {}; layout(false); syncHidden(); });
        }
    });

    document.querySelectorAll('[data-clear-captain]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('form').querySelectorAll('input[name="captain_slot"]').forEach(function (r) { r.checked = false; });
        });
    });
    document.querySelectorAll('[data-add-sub-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var table = btn.closest('form').querySelector('[data-sub-table] tbody');
            if (!table) { return; }
            var last = table.querySelector('tr:last-child');
            if (!last) { return; }
            var clone = last.cloneNode(true);
            clone.querySelectorAll('input, select').forEach(function (el) {
                if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
            });
            table.appendChild(clone);
        });
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
