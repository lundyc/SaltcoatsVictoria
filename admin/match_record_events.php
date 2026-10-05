<?php

declare(strict_types=1);

/**
 * Match events (both teams) — the Stage 3 replacement for the "Events" tab.
 * A chronological editor over matchday_events (goals, cards, ...): add / edit
 * / delete, grouped by period, with a derived score header and an explicit
 * "apply score to fixture" action. The old match_graphics.php is kept and
 * linked as "Social graphics".
 */

$pageStyles = ['match-fixture-tabs.css'];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';

$seasonId = (int) ($_GET['season_id'] ?? 0);
if ($seasonId <= 0) {
    $seasonId = getSelectedSeasonId($pdo);
}
$headerSeason = getSeasonById($pdo, $seasonId);
$fixtureId = (int) ($_GET['fixture_id'] ?? 0);

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Match events',
    'subtitle' => 'Season: ' . ($headerSeason['name'] ?? 'Unknown'),
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/lib/matchday_record.php';
require_once __DIR__ . '/lib/functions.php';

if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    echo '<div class="container-fluid"><div class="alert alert-danger">Access denied.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$fixture = $fixtureId > 0 ? matchday_record_fixture($pdo, $fixtureId) : null;
if ($fixture === null) {
    echo '<div class="container-fluid"><div class="alert alert-warning">Fixture not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}
$seasonId = (int) $fixture['season_id'];
$season = getSeasonById($pdo, $seasonId);
$seasonLocked = is_array($season) && (int) ($season['is_locked'] ?? 0) === 1;

$isHome = (int) ($fixture['is_home'] ?? 1) === 1;
$opponentName = trim((string) ($fixture['opponent'] ?? 'Opponent')) ?: 'Opponent';
$clubName = 'Saltcoats Victoria';

matchday_record_ensure_schema($pdo);
$periods = matchday_periods_ensure($pdo, $fixtureId);
$record = matchday_record_load($pdo, $fixtureId);
$events = $record['events'];
$summary = $record['summary'];
$types = matchday_record_event_types($pdo);

/* lineup names per side, for the player pickers */
$lineupNames = ['svfc' => [], 'opponent' => []];
foreach (['svfc', 'opponent'] as $s) {
    foreach ($record['lineups'][$s] as $r) {
        $nm = trim((string) $r['player_name']);
        if ($nm !== '' && !in_array($nm, $lineupNames[$s], true)) {
            $lineupNames[$s][] = $nm;
        }
    }
}

/* the event being edited, if any */
$editId = (int) ($_GET['edit'] ?? 0);
$editEvent = null;
if ($editId > 0) {
    foreach ($events as $e) {
        if ((int) $e['id'] === $editId) {
            $editEvent = $e;
            break;
        }
    }
}

/* which period does a minute fall in? */
function mre_period_for_minute(array $periods, ?int $minute): ?array
{
    if ($minute === null) {
        return null;
    }
    $fallback = null;
    foreach ($periods as $p) {
        $start = (int) $p['start_minute'];
        $end = $p['end_minute'] === null ? PHP_INT_MAX : (int) $p['end_minute'];
        if ($minute >= $start && $minute <= $end) {
            return $p;
        }
        if ($minute >= $start) {
            $fallback = $p;
        }
    }
    return $fallback ?? ($periods[array_key_last($periods)] ?? null);
}

$flash = '';
$flashType = 'success';
if (isset($_GET['saved'])) {
    $flash = match ((string) $_GET['saved']) {
        'event' => 'Event saved.',
        'deleted' => 'Event deleted.',
        'score' => 'Fixture score updated from the events.',
        default => 'Saved.',
    };
}
if (isset($_GET['error'])) {
    $flash = (string) $_GET['error'];
    $flashType = 'danger';
}

$backUrl = 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId;
$scoreLeftName = $isHome ? $clubName : $opponentName;
$scoreRightName = $isHome ? $opponentName : $clubName;
?>
<nav class="hub-breadcrumb" aria-label="Breadcrumb">
    <a href="/admin/matches.php">Fixtures</a>
    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    <a href="/admin/<?= h($backUrl) ?>"><?= h($opponentName) ?></a>
    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    <span aria-current="page">Match events</span>
</nav>
<?php renderFixtureTabs($fixtureId, $seasonId, 'graphics'); ?>

<div class="hub-section-commandbar">
    <div>
        <h2>Match events</h2>
        <p>Goals, cards and other events for both teams. Substitutions are managed on the Line-ups tab.</p>
    </div>
    <div class="hub-local-actions">
        <a href="matchday_stats.php?season_id=<?= $seasonId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-chart-simple me-1" aria-hidden="true"></i>Season stats
        </a>
        <a href="match_graphics.php?fixture_id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-image me-1" aria-hidden="true"></i>Social graphics
        </a>
    </div>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= h($flashType) ?>"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($seasonLocked): ?>
    <div class="alert alert-warning">This season is locked — events can be viewed but not changed.</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="text-center">
                <div class="small text-secondary text-truncate" style="max-width:9rem"><?= h($scoreLeftName) ?></div>
                <div class="display-6 fw-bold"><?= (int) $summary['home_score'] ?></div>
            </div>
            <div class="fs-3 text-secondary">–</div>
            <div class="text-center">
                <div class="small text-secondary text-truncate" style="max-width:9rem"><?= h($scoreRightName) ?></div>
                <div class="display-6 fw-bold"><?= (int) $summary['away_score'] ?></div>
            </div>
        </div>
        <div class="small text-secondary">
            Derived from <?= count(array_filter($events, fn($e) => ($types[$e['type']]['projects_to'] ?? '') === 'goal')) ?> goal event(s).
            Fixture score on file:
            <strong><?= $fixture['full_time_home_score'] === null ? '—' : ((int) $fixture['full_time_home_score'] . '–' . (int) $fixture['full_time_away_score']) ?></strong>
        </div>
        <?php if (!$seasonLocked): ?>
        <form method="post" action="match_record_event_save.php" data-confirm="Set the fixture full-time score to <?= (int) $summary['home_score'] ?>–<?= (int) $summary['away_score'] ?>?" data-confirm-action="Set score" data-confirm-class="btn-primary">
            <?= csrf_field() ?>
            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
            <input type="hidden" name="form_action" value="apply_score">
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Apply <?= (int) $summary['home_score'] ?>–<?= (int) $summary['away_score'] ?> to fixture
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <?php if (!$seasonLocked): ?>
        <div class="card">
            <div class="card-header"><?= $editEvent ? 'Edit event' : 'Add event' ?></div>
            <div class="card-body">
                <form method="post" action="match_record_event_save.php" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                    <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                    <input type="hidden" name="form_action" value="save">
                    <?php if ($editEvent): ?><input type="hidden" name="event_id" value="<?= (int) $editEvent['id'] ?>"><?php endif; ?>

                    <div class="col-7">
                        <label class="form-label small mb-0">Type</label>
                        <select name="type" class="form-select form-select-sm" required>
                            <?php foreach ($types as $key => $meta): ?>
                                <?php if ($key === 'substitution') { continue; } ?>
                                <option value="<?= h($key) ?>"<?= $editEvent && $editEvent['type'] === $key ? ' selected' : '' ?>><?= h($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-0">Team</label>
                        <select name="side" class="form-select form-select-sm">
                            <?php
                            $curSide = $editEvent['side'] ?? 'svfc';
                            foreach (['svfc' => $clubName, 'opponent' => $opponentName, 'none' => '— (neutral)'] as $sv => $sl):
                            ?>
                                <option value="<?= h($sv) ?>"<?= $curSide === $sv ? ' selected' : '' ?>><?= h($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-4">
                        <label class="form-label small mb-0">Minute</label>
                        <input type="number" name="minute" class="form-control form-control-sm" min="0" max="130" value="<?= $editEvent && $editEvent['minute'] !== null ? (int) $editEvent['minute'] : '' ?>">
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-0">+</label>
                        <input type="number" name="minute_extra" class="form-control form-control-sm" min="0" max="20" value="<?= $editEvent ? (int) $editEvent['minute_extra'] : 0 ?>">
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-0">
                            <input type="checkbox" name="own_goal" value="1"<?= $editEvent && (int) $editEvent['own_goal'] === 1 ? ' checked' : '' ?>> Own goal
                        </label>
                    </div>

                    <div class="col-12">
                        <label class="form-label small mb-0">Player</label>
                        <input type="text" name="player_name" class="form-control form-control-sm" list="mreSvfc" autocomplete="off" value="<?= h($editEvent['player_name'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Assisted by / related player <span class="text-secondary">(optional)</span></label>
                        <input type="text" name="secondary_player_name" class="form-control form-control-sm" list="mreSvfc" autocomplete="off" value="<?= h($editEvent['secondary_player_name'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Note <span class="text-secondary">(optional)</span></label>
                        <input type="text" name="note" class="form-control form-control-sm" maxlength="300" value="<?= h($editEvent['note'] ?? '') ?>">
                    </div>

                    <div class="col-12 d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i><?= $editEvent ? 'Update event' : 'Add event' ?></button>
                        <?php if ($editEvent): ?>
                            <a href="match_record_events.php?fixture_id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
                <datalist id="mreSvfc">
                    <?php foreach (array_merge($lineupNames['svfc'], $lineupNames['opponent']) as $nm): ?>
                        <option value="<?= h($nm) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="small text-secondary mt-2 mb-0">Tip: fill in the Line-ups tab first so the player list auto-completes.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Timeline</span>
                <span class="small text-secondary"><?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?></span>
            </div>
            <div class="card-body">
                <?php if ($events === []): ?>
                    <p class="text-secondary mb-0">No events yet.</p>
                <?php else: ?>
                    <?php
                    $grouped = [];
                    foreach ($events as $e) {
                        $p = mre_period_for_minute($periods, $e['minute'] === null ? null : (int) $e['minute']);
                        $key = $p['period_key'] ?? '_none';
                        $grouped[$key]['label'] = $p['label'] ?? 'Unassigned';
                        $grouped[$key]['events'][] = $e;
                    }
                    ?>
                    <?php foreach ($grouped as $g): ?>
                        <div class="fw-bold small text-uppercase text-secondary mt-3 mb-1"><?= h($g['label']) ?></div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($g['events'] as $e): ?>
                                <?php
                                $meta = $types[$e['type']] ?? ['label' => $e['type'], 'category' => 'other', 'projects_to' => ''];
                                $sideBadge = $e['side'] === 'svfc' ? 'primary' : ($e['side'] === 'opponent' ? 'secondary' : 'light');
                                $sideLabel = $e['side'] === 'svfc' ? $clubName : ($e['side'] === 'opponent' ? $opponentName : '—');
                                $min = matchday_record_minute_label((int) $e['minute'], (int) $e['minute_extra'], $e['minute'] === null);
                                ?>
                                <li class="list-group-item px-0 d-flex align-items-start justify-content-between gap-2">
                                    <div>
                                        <span class="badge bg-light text-dark border me-1"><?= $min === '' ? '—' : h($min) . "'" ?></span>
                                        <span class="badge bg-<?= $sideBadge ?> <?= $sideBadge === 'light' ? 'text-dark border' : '' ?> me-1"><?= h($sideLabel) ?></span>
                                        <strong><?= h($meta['label']) ?></strong>
                                        <?php if ((int) $e['own_goal'] === 1): ?><span class="badge bg-warning text-dark ms-1">OG</span><?php endif; ?>
                                        <?php if (trim((string) $e['player_name']) !== ''): ?>
                                            — <?= h((string) $e['player_name']) ?>
                                            <?php if (($e['participant_type'] ?? 'player') === 'staff' && trim((string) ($e['participant_role'] ?? '')) !== ''): ?>
                                                <span class="badge bg-secondary ms-1"><?= h((string) $e['participant_role']) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (trim((string) $e['secondary_player_name']) !== ''): ?>
                                            <span class="text-secondary">(<?= h((string) $e['secondary_player_name']) ?>)</span>
                                        <?php endif; ?>
                                        <?php if (trim((string) $e['note']) !== ''): ?>
                                            <div class="small text-secondary"><?= h((string) $e['note']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$seasonLocked): ?>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <?php if ($e['type'] === 'substitution'): ?>
                                            <a href="match_lineups.php?fixture_id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>" class="btn btn-link btn-sm p-0 text-secondary" title="Manage on Line-ups">
                                                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="match_record_events.php?fixture_id=<?= $fixtureId ?>&amp;season_id=<?= $seasonId ?>&amp;edit=<?= (int) $e['id'] ?>" class="btn btn-link btn-sm p-0" title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="post" action="match_record_event_delete.php" data-confirm="Delete this event?" data-confirm-action="Delete">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                                            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                                            <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                                            <button type="submit" class="btn btn-link btn-sm p-0 text-danger" title="Delete"><i class="fa-solid fa-xmark"></i></button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
