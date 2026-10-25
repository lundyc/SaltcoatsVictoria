<?php

declare(strict_types=1);

/**
 * Score Predictor admin: point values, the cup bonus, settling played fixtures, and a
 * per-fixture view of every prediction with its points breakdown.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/score_predictor.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    echo '<div class="container-fluid"><div class="alert alert-danger">Access denied.</div></div>';
    exit;
}

sp_ensure_schema($pdo);

$seasonId = (int) ($_GET['season_id'] ?? 0) ?: getSelectedSeasonId($pdo);
$canEditPoints = hub_auth_has_capability('admin_settings');

$redirect = static function (array $query = []) use ($seasonId): never {
    header('Location: /admin/score_predictor.php?' . http_build_query(['season_id' => $seasonId] + $query), true, 303);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $_SESSION['sp_flash'] = ['type' => 'danger', 'msg' => 'Your session has expired. Reload the page and try again.'];
        $redirect();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'settle') {
        try {
            $count = sp_settle_fixture($pdo, (int) ($_POST['fixture_id'] ?? 0));
            $_SESSION['sp_flash'] = ['type' => 'success', 'msg' => "Scored {$count} prediction" . ($count === 1 ? '' : 's') . '.'];
        } catch (RuntimeException $e) {
            $_SESSION['sp_flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
        $redirect(['fixture' => (int) ($_POST['fixture_id'] ?? 0)]);
    }

    if ($action === 'save_points') {
        if (!$canEditPoints) {
            http_response_code(403);
            exit('Access denied.');
        }
        $error = '';
        $values = [];
        foreach (array_keys(SP_DEFAULT_POINTS) as $key) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            if (!ctype_digit($raw) || (int) $raw > 100) {
                $error = 'Each point value must be a whole number from 0 to 100.';
                break;
            }
            $values[$key] = (int) $raw;
        }
        if ($error !== '') {
            $_SESSION['sp_flash'] = ['type' => 'danger', 'msg' => $error];
        } else {
            $stmt = $pdo->prepare('INSERT INTO sp_settings (setting_key, setting_value) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($values as $key => $value) {
                $stmt->execute([':k' => $key, ':v' => $value]);
            }
            $_SESSION['sp_flash'] = ['type' => 'success', 'msg' => 'Point values saved. Fixtures already settled keep their old scores until you re-settle them.'];
        }
        $redirect();
    }

    $redirect();
}

$flash = $_SESSION['sp_flash'] ?? null;
unset($_SESSION['sp_flash']);

$points = sp_points($pdo);

$fixtureStmt = $pdo->prepare("SELECT f.*,
        (SELECT COUNT(*) FROM sp_predictions p WHERE p.fixture_id = f.id) AS predictions,
        (SELECT COUNT(*) FROM sp_scores s WHERE s.fixture_id = f.id) AS scored
    FROM match_fixtures f
    WHERE f.season_id = :season
    ORDER BY f.match_date DESC, f.kickoff_time DESC, f.id DESC");
$fixtureStmt->execute([':season' => $seasonId]);
$fixtures = $fixtureStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedId = (int) ($_GET['fixture'] ?? 0);
$selected = null;
foreach ($fixtures as $fixture) {
    if ((int) $fixture['id'] === $selectedId) {
        $selected = $fixture;
        break;
    }
}

$detail = [];
if ($selected) {
    $detailStmt = $pdo->prepare('SELECT p.*, h.name AS holder_name, s.total_points, s.submit_points, s.result_points,
            s.city_score_points, s.opposition_score_points, s.scoreline_points, s.goalscorer_points, s.cup_bonus_points
        FROM sp_predictions p
        LEFT JOIN season_ticket_holders h ON h.id = p.holder_id
        LEFT JOIN sp_scores s ON s.prediction_id = p.id
        WHERE p.fixture_id = :fixture
        ORDER BY s.total_points DESC, p.submitted_at ASC');
    $detailStmt->execute([':fixture' => (int) $selected['id']]);
    $detail = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageHero = [
    'eyebrow' => 'Match day',
    'title' => 'Score predictor',
    'subtitle' => 'Point values, settling results and checking predictions',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
?>

<div class="container-fluid">
    <?php if ($flash): ?>
        <div class="alert alert-<?= h((string) $flash['type']) ?>"><?= h((string) $flash['msg']) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h2 class="h5">Point values</h2>
                    <?php if (!$canEditPoints): ?>
                        <p class="small text-muted">You can see the values here. Only admins can change them.</p>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_points">
                        <?php
                        $labels = [
                            'submit' => 'For submitting',
                            'result' => 'Correct result',
                            'city_score' => 'Correct our score',
                            'opposition_score' => 'Correct opponent score',
                            'scoreline' => 'Correct exact scoreline',
                            'goalscorer' => 'Correct goalscorer',
                            'cup_bonus' => 'Cup game bonus',
                        ];
                        foreach ($labels as $key => $label): ?>
                            <div class="row g-2 align-items-center mb-2">
                                <label class="col-7 col-form-label small" for="pts-<?= h($key) ?>"><?= h($label) ?></label>
                                <div class="col-5">
                                    <input type="number" min="0" max="100" step="1" class="form-control form-control-sm" id="pts-<?= h($key) ?>" name="<?= h($key) ?>" value="<?= (int) $points[$key] ?>" <?= $canEditPoints ? '' : 'disabled' ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($canEditPoints): ?>
                            <button class="btn btn-primary btn-sm mt-2" type="submit">Save point values</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Fixtures</h2>
                        <form method="get" class="d-flex gap-2 align-items-center">
                            <label class="small text-muted" for="sp-season">Season</label>
                            <select class="form-select form-select-sm" id="sp-season" name="season_id" onchange="this.form.submit()">
                                <?php foreach (getSeasons($pdo) as $season): ?>
                                    <option value="<?= (int) $season['id'] ?>" <?= (int) $season['id'] === $seasonId ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Date</th><th>Opponent</th><th>Result</th><th>Predictions</th><th>Scored</th><th></th></tr></thead>
                            <tbody>
                                <?php if (!$fixtures): ?>
                                    <tr><td colspan="6" class="text-muted">No fixtures in this season.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($fixtures as $fixture):
                                    $isPlayed = (string) $fixture['status'] === 'played';
                                    $isCup = sp_fixture_is_cup($pdo, $fixture);
                                ?>
                                    <tr class="<?= (int) $fixture['id'] === $selectedId ? 'table-warning' : '' ?>">
                                        <td><?= h(date('j M Y', strtotime((string) $fixture['match_date']))) ?></td>
                                        <td><?= h((string) $fixture['opponent']) ?><?= $isCup ? ' <span class="badge bg-warning text-dark">Cup</span>' : '' ?>
                                            <div class="small text-muted"><?= h((string) $fixture['status']) ?></div></td>
                                        <td><?php if ($isPlayed && $fixture['full_time_home_score'] !== null): ?>
                                            <?= (int) $fixture['full_time_home_score'] ?>&ndash;<?= (int) $fixture['full_time_away_score'] ?>
                                        <?php else: ?><span class="text-muted">&ndash;</span><?php endif; ?></td>
                                        <td><?= (int) $fixture['predictions'] ?></td>
                                        <td><?= (int) $fixture['scored'] ?></td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <a class="btn btn-outline-secondary btn-sm" href="?season_id=<?= $seasonId ?>&fixture=<?= (int) $fixture['id'] ?>">View</a>
                                                <?php if ($isPlayed && (int) $fixture['predictions'] > 0): ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="settle">
                                                        <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                                                        <button class="btn btn-primary btn-sm" type="submit"><?= (int) $fixture['scored'] > 0 ? 'Re-settle' : 'Settle' ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($selected): ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h2 class="h5 mb-1">Predictions: <?= h((string) $selected['opponent']) ?> (<?= h(date('j M Y', strtotime((string) $selected['match_date']))) ?>)</h2>
                        <p class="small text-muted mb-3">
                            <?php if ((string) $selected['status'] === 'played' && $selected['full_time_home_score'] !== null): ?>
                                Final score: <?= (int) $selected['full_time_home_score'] ?>&ndash;<?= (int) $selected['full_time_away_score'] ?> (home&ndash;away)
                            <?php else: ?>
                                Not played yet. Settle it once the result is recorded.
                            <?php endif; ?>
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Member</th><th>Prediction</th><th>Goalscorer</th><th class="text-end">Result</th><th class="text-end">Score</th><th class="text-end">Points</th></tr></thead>
                                <tbody>
                                    <?php if (!$detail): ?>
                                        <tr><td colspan="6" class="text-muted">No predictions for this fixture.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($detail as $row): ?>
                                        <tr>
                                            <td><?= h((string) ($row['holder_name'] ?? 'Unknown')) ?></td>
                                            <td><?= (int) $row['city_score'] ?>&ndash;<?= (int) $row['opposition_score'] ?></td>
                                            <td><?= h((string) $row['goalscorer']) ?></td>
                                            <td class="text-end">
                                                <?= $row['submit_points'] !== null ? (int) $row['result_points'] : '<span class="text-muted">&ndash;</span>' ?>
                                            </td>
                                            <td class="text-end">
                                                <?= $row['submit_points'] !== null ? (int) ($row['city_score_points'] + $row['opposition_score_points'] + $row['scoreline_points']) : '<span class="text-muted">&ndash;</span>' ?>
                                            </td>
                                            <td class="text-end fw-bold">
                                                <?= $row['total_points'] !== null ? (int) $row['total_points'] : '<span class="text-muted small">not scored</span>' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-2 mb-0">Result column is the result points; Score column is the points for our score, the opponent score and the exact scoreline together.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
