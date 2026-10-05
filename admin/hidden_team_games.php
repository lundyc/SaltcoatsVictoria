<?php
$pageHero = ['eyebrow' => 'Fundraising', 'title' => 'Hidden Team', 'subtitle' => 'Manage Hidden Team fundraiser boards — teams, pricing, and the prize draw.', 'actions' => []];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/hidden_team.php';
require_once __DIR__ . '/lib/audit.php';
ensureHiddenTeamSchema($pdo);

$errors = [];
$poolErrors = [];
$openCreateModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'create_game') {
        $openCreateModal = true;
        if (!csrf_check()) {
            $errors[] = 'Invalid security token.';
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            $costPerTeam = (float) ($_POST['cost_per_team'] ?? 0);
            $prizePercentage = (float) ($_POST['prize_percentage'] ?? 0);
            $teamCount = (int) ($_POST['team_count'] ?? 0);
            try {
                $seasonId = (int) ($seasonContext['season_id'] ?? 0);
                $gameId = createHiddenTeamGame($pdo, $name, $costPerTeam, $prizePercentage, $seasonId ?: null, $teamCount);
                auditLog($pdo, 'hidden_team_game_created', "Created hidden team game '{$name}'");
                header('Location: hidden_team_game.php?id=' . $gameId . '&created=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($formAction === 'add_club') {
        if (!csrf_check()) {
            $poolErrors[] = 'Invalid security token.';
        } else {
            try {
                $clubName = (string) ($_POST['club_name'] ?? '');
                hiddenTeamAddClubToPool($pdo, $clubName);
                auditLog($pdo, 'hidden_team_club_added', "Added '" . trim($clubName) . "' to the hidden team club pool");
            } catch (Throwable $e) {
                $poolErrors[] = $e->getMessage();
            }
        }
    } elseif ($formAction === 'remove_club') {
        if (csrf_check()) {
            $removedClubId = (int) ($_POST['club_id'] ?? 0);
            $removedClubStmt = $pdo->prepare('SELECT name FROM hidden_team_club_pool WHERE id = :id');
            $removedClubStmt->execute([':id' => $removedClubId]);
            $removedClubName = (string) $removedClubStmt->fetchColumn();
            hiddenTeamRemoveClubFromPool($pdo, $removedClubId);
            auditLog($pdo, 'hidden_team_club_removed', "Removed '" . ($removedClubName !== '' ? $removedClubName : "#{$removedClubId}") . "' from the hidden team club pool");
        }
    }
}

$games = getHiddenTeamGames($pdo);
$openGame = null;
foreach ($games as $game) {
    if ((string) $game['status'] === 'open') {
        $openGame = $game;
        break;
    }
}
$totalRaisedAllTime = 0.0;
foreach ($games as $game) {
    $totalRaisedAllTime += (float) $game['taken_count'] * (float) $game['cost_per_team'];
}
$totalCollectedAllTime = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM hidden_team_payments WHERE status = "settled"')->fetchColumn();
$conflictCount = (int) $pdo->query('SELECT COUNT(*) FROM hidden_team_payments WHERE status = "conflict"')->fetchColumn();
$clubPool = getHiddenTeamClubPool($pdo);
$activePoolCount = count(array_filter($clubPool, static fn(array $c): bool => (int) $c['is_active'] === 1));
?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Game created.</div><?php endif; ?>
<?php if ($errors && !$openCreateModal): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($conflictCount > 0): ?>
<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i><strong><?= $conflictCount ?></strong> Stripe payment(s) landed for a box that was already claimed by someone else — a genuine payment collision. These are real payments that need a manual look (refund one side, or agree who keeps the box). Open the game each payment belongs to and check its team's payment history.</div>
<?php endif; ?>

<?php hub_render_metric_grid([
    ['label' => 'Games', 'value' => count($games), 'meta' => $openGame ? '1 currently open' : 'None open', 'icon' => 'fa-futbol', 'tone' => 'primary'],
    ['label' => 'Raised (claimed boxes)', 'value' => gbp($totalRaisedAllTime), 'meta' => 'Across all games', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
    ['label' => 'Collected via Stripe/cash', 'value' => gbp($totalCollectedAllTime), 'meta' => 'Settled payments', 'icon' => 'fa-credit-card', 'tone' => 'info'],
    ['label' => 'Payment conflicts', 'value' => $conflictCount, 'meta' => $conflictCount > 0 ? 'Needs manual review' : 'None', 'icon' => 'fa-triangle-exclamation', 'tone' => $conflictCount > 0 ? 'danger' : 'neutral'],
], 'Hidden Team summary'); ?>

<div class="hub-section-commandbar">
    <div><h2>Games</h2><p>The board auto-draws a winner once it sells out, and the next game starts automatically straight after — this button is only for the very first game, or if you ever need one running before the last one sells out.</p></div>
    <div class="hub-local-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#teamPoolModal"><i class="fa-solid fa-list me-1" aria-hidden="true"></i>Team pool (<?= $activePoolCount ?>)</button>
        <button type="button" class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#createGameModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Start new game</button>
    </div>
</div>

<div class="card hub-list-card hub-table-card mb-4"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle hub-data-table hub-data-table--responsive">
    <thead><tr><th>Game</th><th>Status</th><th>Teams</th><th>Raised</th><th>Prize</th><th>Winner</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($games as $g): ?>
        <tr>
            <td data-label="Game" class="fw-semibold"><?= h((string) $g['name']) ?></td>
            <td data-label="Status"><span class="badge hub-status <?= $g['status'] === 'open' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h(ucfirst((string) $g['status'])) ?></span></td>
            <td data-label="Teams"><?= (int) $g['taken_count'] ?> / <?= (int) $g['team_count'] ?> claimed <span class="text-muted small">(<?= (int) $g['paid_count'] ?> paid)</span></td>
            <td data-label="Raised"><?= gbp((float) $g['taken_count'] * (float) $g['cost_per_team']) ?><div class="small text-muted">of <?= gbp($g['total_raised']) ?> if sold out</div></td>
            <td data-label="Prize"><?= gbp($g['prize_amount']) ?> <span class="text-muted small">(<?= h((string) $g['prize_percentage']) ?>%)</span></td>
            <td data-label="Winner"><?php if ($g['winner_team_name']): ?><?= h((string) $g['winner_team_name']) ?> — <?= h((string) $g['winner_supporter_name']) ?><?php else: ?><span class="text-muted">Not drawn</span><?php endif; ?></td>
            <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-primary" href="hidden_team_game.php?id=<?= (int) $g['id'] ?>">Manage</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$games): ?><tr><td colspan="7" class="hub-record-empty hub-empty-state">No Hidden Team games yet — start one below.</td></tr><?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="createGameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="create_game">
            <div class="modal-header"><h5 class="modal-title">Start a new game</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <?php if ($openGame): ?><div class="alert alert-warning">There's already an open game ("<?= h((string) $openGame['name']) ?>") — members only ever see one open game at a time. Draw its winner or leave it running before starting another, otherwise you'll have two open boards and members will only see whichever one this page treats as "current".</div><?php endif; ?>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Game name</label><input type="text" class="form-control" name="name" placeholder="e.g. Hidden Team — Summer 2026" value="<?= h((string) ($_POST['name'] ?? '')) ?>" required></div>
                    <div class="col-6"><label class="form-label">Cost per team</label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" name="cost_per_team" value="<?= h((string) ($_POST['cost_per_team'] ?? '10.00')) ?>" required></div></div>
                    <div class="col-6"><label class="form-label">Prize awarded</label><div class="input-group"><input type="number" min="0" max="100" step="1" class="form-control" name="prize_percentage" value="<?= h((string) ($_POST['prize_percentage'] ?? '50')) ?>"><span class="input-group-text">%</span></div></div>
                    <div class="col-12">
                        <label class="form-label">Number of teams on the board</label>
                        <input type="number" min="1" max="<?= $activePoolCount ?>" class="form-control" name="team_count" value="<?= h((string) ($_POST['team_count'] ?? (string) $activePoolCount)) ?>" required>
                        <div class="form-text"><?= $activePoolCount ?> team(s) currently in the pool — teams are picked at random from it. <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#teamPoolModal" data-bs-dismiss="modal">Manage the pool</button>.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit" <?= $activePoolCount === 0 ? 'disabled' : '' ?>>Create game</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="teamPoolModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Team pool</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <p class="text-muted">Games draw a random selection of teams from this pool. Removing a team here doesn't affect any game already created.</p>
            <?php if ($poolErrors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($poolErrors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" class="row g-2 align-items-end mb-3"><?= csrf_field() ?><input type="hidden" name="form_action" value="add_club">
                <div class="col-8"><label class="form-label">Add a team</label><input type="text" class="form-control" name="club_name" placeholder="Team name" required></div>
                <div class="col-4 d-grid"><button class="btn btn-outline-primary" type="submit">Add</button></div>
            </form>
            <div class="hub-list-scroll" style="max-height:18rem;overflow-y:auto;">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($clubPool as $club): ?>
                        <tr>
                            <td><?= h((string) $club['name']) ?><?php if ((int) $club['is_active'] !== 1): ?> <span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td>
                            <td class="text-end">
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="remove_club"><input type="hidden" name="club_id" value="<?= (int) $club['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove &quot;<?= h((string) $club['name']) ?>&quot; from the pool? Existing games keep it, but future games won't be able to pick it." data-confirm-title="Remove from pool?" data-confirm-action="Remove">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$clubPool): ?><tr><td class="text-center text-muted py-3">No teams in the pool yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<?php if ($openCreateModal): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('createGameModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
