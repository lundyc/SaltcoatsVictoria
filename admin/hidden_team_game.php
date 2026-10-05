<?php
$id = (int) ($_GET['id'] ?? 0);
$pageHero = ['eyebrow' => 'Fundraising', 'title' => 'Hidden Team', 'subtitle' => 'Manage this game\'s pricing and draw.', 'actions' => []];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/hidden_team.php';
require_once __DIR__ . '/lib/audit.php';
ensureHiddenTeamSchema($pdo);

$game = getHiddenTeamGame($pdo, $id);
if (!$game) {
    echo '<div class="alert alert-danger">Game not found.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$drawResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? '');
        try {
            if ($formAction === 'save_settings') {
                saveHiddenTeamGameSettings($pdo, $id, (string) ($_POST['name'] ?? ''), (float) ($_POST['cost_per_team'] ?? 0), (float) ($_POST['prize_percentage'] ?? 0));
                auditLog($pdo, 'hidden_team_game_updated', "Updated settings for hidden team game '" . (string) ($_POST['name'] ?? '') . "'");
                header('Location: hidden_team_game.php?id=' . $id . '&saved=1');
                exit;
            } elseif ($formAction === 'release_team') {
                $releasedTeamId = (int) ($_POST['team_id'] ?? 0);
                $releasedTeam = getHiddenTeamTeam($pdo, $releasedTeamId);
                hiddenTeamReleaseTeam($pdo, $releasedTeamId);
                auditLog($pdo, 'hidden_team_team_released', 'Released team ' . ($releasedTeam ? "'" . (string) $releasedTeam['team_name'] . "'" : "#{$releasedTeamId}") . " in hidden team game '" . (string) $game['name'] . "'");
                header('Location: hidden_team_game.php?id=' . $id . '&released=1');
                exit;
            } elseif ($formAction === 'draw') {
                $drawResult = hiddenTeamDrawWinner($pdo, $id);
                $game = getHiddenTeamGame($pdo, $id);
                auditLog($pdo, 'hidden_team_winner_drawn', "Drew winner '" . (string) $drawResult['team_name'] . "' (" . (string) $drawResult['supporter_name'] . ") for hidden team game '" . (string) $game['name'] . "'");
            } else {
                $errors[] = 'Unknown action.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$teams = getHiddenTeamTeams($pdo, $id);
$paymentsByTeam = [];
foreach ($teams as $t) {
    if ((int) $t['paid'] === 1 || (int) $t['is_taken'] === 1) {
        $payments = getHiddenTeamPayments($pdo, (int) $t['id']);
        if ($payments) {
            $paymentsByTeam[(int) $t['id']] = $payments;
        }
    }
}
?>
<style>
    .htg-board { display:grid; grid-template-columns:repeat(auto-fill, minmax(15rem, 1fr)); gap:.9rem; }
    .htg-card { display:flex; flex-direction:column; gap:.5rem; padding:1rem; border-radius:.9rem; border:1px solid rgba(75,8,24,.12); background:#fff; }
    .htg-card--open { background:rgba(246,236,222,.4); }
    .htg-card--taken { background:rgba(224,180,42,.1); border-color:rgba(224,180,42,.4); }
    .htg-card--paid { background:rgba(57,191,180,.1); border-color:rgba(57,191,180,.5); }
    .htg-card--winner { outline:2px solid var(--brand-warning,#e0b42a); outline-offset:-2px; }
    .htg-card--conflict { border-color:rgba(220,53,69,.6); }
    .htg-card__name { font-weight:800; font-size:1.05rem; }
    .htg-card__meta { font-size:.82rem; color:rgba(31,26,29,.65); }
    .htg-card__footer { margin-top:auto; padding-top:.5rem; }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="hidden_team_games.php">Hidden Team</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string) $game['name']) ?></span></nav>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Settings saved.</div><?php endif; ?>
<?php if (isset($_GET['released'])): ?><div class="alert alert-success">Team released — it's open again.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($drawResult): ?>
<div class="alert alert-success"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i><strong>Winner drawn: <?= h($drawResult['team_name']) ?></strong> — <?= h($drawResult['supporter_name']) ?> wins <?= gbp($game['prize_amount']) ?>.</div>
<?php endif; ?>

<?php hub_render_metric_grid([
    ['label' => 'Status', 'value' => ucfirst((string) $game['status']), 'meta' => $game['winner_team_name'] ? ('Winner: ' . (string) $game['winner_team_name']) : 'No winner yet', 'icon' => 'fa-circle-info', 'tone' => $game['status'] === 'open' ? 'success' : 'neutral'],
    ['label' => 'Teams claimed', 'value' => (int) $game['taken_count'] . ' / ' . (int) $game['team_count'], 'meta' => (int) $game['paid_count'] . ' paid', 'icon' => 'fa-flag', 'tone' => 'primary'],
    ['label' => 'Raised so far', 'value' => gbp((float) $game['taken_count'] * (float) $game['cost_per_team']), 'meta' => gbp($game['total_raised']) . ' if sold out', 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
    ['label' => 'Prize pot', 'value' => gbp($game['prize_amount']), 'meta' => $game['prize_percentage'] . '% of the full pot', 'icon' => 'fa-trophy', 'tone' => 'warning'],
], 'Game summary'); ?>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <section class="card shadow-sm border-0 h-100 hub-form-card"><div class="card-body">
            <h2 class="h5 mb-3">Board settings</h2>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="save_settings">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Name</label><input type="text" class="form-control" name="name" value="<?= h((string) $game['name']) ?>" required></div>
                    <div class="col-6"><label class="form-label">Cost per team</label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" name="cost_per_team" value="<?= h((string) $game['cost_per_team']) ?>"></div></div>
                    <div class="col-6"><label class="form-label">Prize awarded</label><div class="input-group"><input type="number" min="0" max="100" step="1" class="form-control" name="prize_percentage" value="<?= h((string) $game['prize_percentage']) ?>"><span class="input-group-text">%</span></div></div>
                </div>
                <div class="mt-3"><button class="btn btn-brand btn-sm" type="submit">Save settings</button></div>
            </form>
        </div></section>
    </div>
    <div class="col-lg-6">
        <section class="card shadow-sm border-0 h-100 hub-form-card"><div class="card-body">
            <h2 class="h5 mb-3">Draw</h2>
            <?php if ((string) $game['status'] !== 'open'): ?>
                <p class="mb-0">This game is finished — the winner was <strong><?= h((string) $game['winner_team_name']) ?></strong> (<?= h((string) $game['winner_supporter_name']) ?>), drawn <?= h(date('d/m/Y H:i', strtotime((string) $game['winner_drawn_at']))) ?>.</p>
            <?php else: ?>
                <p class="text-muted">Draws randomly from every claimed team. <?= (int) $game['taken_count'] ?> / <?= (int) $game['team_count'] ?> team(s) claimed so far — this happens automatically the moment the board sells out, and a new game starts right after. Use this button to draw early instead of waiting for a sellout.</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="draw">
                    <button class="btn btn-danger" type="submit" <?= (int) $game['taken_count'] === 0 ? 'disabled' : '' ?> data-confirm="Draw a winner now for &quot;<?= h((string) $game['name']) ?>&quot;? This closes the game to further claims (a new game will start automatically) and cannot be undone." data-confirm-title="Draw winner?" data-confirm-action="Draw winner"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i>Draw winner now</button>
                </form>
            <?php endif; ?>
            <p class="mt-3 mb-0"><a href="/members/hidden_team.php" target="_blank" rel="noopener">View the member board <i class="fa-solid fa-up-right-from-square ms-1" aria-hidden="true"></i></a></p>
        </div></section>
    </div>
</div>

<section class="card shadow-sm border-0 hub-form-card"><div class="card-body">
    <h2 class="h5 mb-3">Teams</h2>
    <?php if (!$teams): ?>
        <div class="hub-record-empty hub-empty-state">No teams in this game.</div>
    <?php else: ?>
    <div class="htg-board">
        <?php foreach ($teams as $t): ?>
            <?php
            $taken = (int) $t['is_taken'] === 1;
            $paid = (int) $t['paid'] === 1;
            $isWinner = (int) $game['winner_team_id'] === (int) $t['id'];
            $payments = $paymentsByTeam[(int) $t['id']] ?? [];
            $hasConflict = false;
            foreach ($payments as $p) {
                if ((string) $p['status'] === 'conflict') {
                    $hasConflict = true;
                }
            }
            $cardClass = 'htg-card ' . ($hasConflict ? 'htg-card--conflict' : ($paid ? 'htg-card--paid' : ($taken ? 'htg-card--taken' : 'htg-card--open'))) . ($isWinner ? ' htg-card--winner' : '');
            ?>
            <div class="<?= h($cardClass) ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="htg-card__name"><?= h((string) $t['team_name']) ?></div>
                    <?php if ($isWinner): ?><span class="badge text-bg-warning">Winner</span><?php endif; ?>
                </div>
                <span class="badge hub-status align-self-start <?= $paid ? 'text-bg-success' : ($taken ? 'text-bg-warning' : 'text-bg-light') ?>"><?= $paid ? 'Paid' : ($taken ? 'Claimed, unpaid' : 'Open') ?></span>
                <?php if ($taken): ?>
                    <div class="htg-card__meta">
                        <strong><?= h((string) $t['supporter_name']) ?: '—' ?></strong>
                        <?php if ($t['holder_email']): ?><br><?= h((string) $t['holder_email']) ?><?php endif; ?>
                        <?php if ($t['claimed_at']): ?><br>Claimed <?= h(date('d/m/Y H:i', strtotime((string) $t['claimed_at']))) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($payments): ?>
                    <div class="htg-card__meta">
                        <?php foreach ($payments as $p): ?>
                            <div class="<?= (string) $p['status'] === 'conflict' ? 'text-danger fw-semibold' : '' ?>">
                                <?= gbp((float) $p['amount']) ?> · <?= h(ucfirst((string) $p['method'])) ?> · <?= h(date('d/m/Y', strtotime((string) $p['paid_at']))) ?>
                                <?php if ((string) $p['status'] === 'conflict'): ?><br>⚠ Conflict — box claimed by someone else when this landed<?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($taken && $game['status'] === 'open'): ?>
                <div class="htg-card__footer">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="release_team"><input type="hidden" name="team_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100" data-confirm="Release &quot;<?= h((string) $t['team_name']) ?>&quot; back to open? This clears the supporter and paid status — if they paid via Stripe, refund them separately first." data-confirm-title="Release this team?" data-confirm-action="Release">Release</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div></section>

<?php require_once __DIR__ . '/footer.php'; ?>
