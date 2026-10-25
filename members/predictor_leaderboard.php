<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/score_predictor.php';

if (!$currentHolder) {
    header('Location: /members/index.php');
    exit;
}

sp_ensure_schema($pdo);

$seasonId = pub_current_season_id();
$holderId = (int) $currentHolder['id'];

// Months that have settled scores this season, newest first. The selected month must be
// one of these, so a typed-in value can't reach the query.
$monthStmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(match_date, '%Y-%m') AS month
    FROM sp_scores WHERE season_id = :season ORDER BY month DESC");
$monthStmt->execute([':season' => $seasonId]);
$months = array_map('strval', $monthStmt->fetchAll(PDO::FETCH_COLUMN));

$selectedMonth = (string) ($_GET['month'] ?? '');
if (!in_array($selectedMonth, $months, true)) {
    $selectedMonth = $months[0] ?? '';
}

$monthRows = $selectedMonth !== '' ? sp_leaderboard($pdo, $seasonId, $selectedMonth) : [];
$seasonRows = sp_leaderboard($pdo, $seasonId);

$seasonName = '';
foreach (pub_seasons() as $season) {
    if ((int) $season['id'] === $seasonId) {
        $seasonName = (string) $season['name'];
    }
}

/** @param list<array{holder_id:int, name:string, points:int, predictions:int}> $rows */
$renderRows = static function (array $rows) use ($holderId): void {
    if (!$rows) {
        echo '<tr><td colspan="4" class="text-muted">No scores yet.</td></tr>';
        return;
    }
    foreach ($rows as $i => $row) {
        $isMe = (int) $row['holder_id'] === $holderId;
        echo '<tr' . ($isMe ? ' class="table-warning"' : '') . '>'
            . '<td>' . ($i + 1) . '</td>'
            . '<td class="fw-semibold">' . h(sp_display_name((string) $row['name'])) . ($isMe ? ' <span class="small text-muted">(you)</span>' : '') . '</td>'
            . '<td>' . (int) $row['predictions'] . '</td>'
            . '<td class="text-end fw-bold">' . (int) $row['points'] . '</td>'
            . '</tr>';
    }
};
?>
<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">My Vics</div>
            <h1>Score Predictor leaderboard</h1>
            <p>Monthly and season standings. The monthly table resets each month.</p>
            <div class="member-hero__actions">
                <a class="btn btn-outline-light btn-sm" href="/members/predictor.php">Make predictions</a>
            </div>
        </div>
    </section>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">Monthly</h2>
                <?php if ($months): ?>
                    <form method="get" class="d-flex align-items-center gap-2">
                        <label class="small text-muted" for="month-select">Month</label>
                        <select class="form-select form-select-sm" id="month-select" name="month" onchange="this.form.submit()">
                            <?php foreach ($months as $month): ?>
                                <option value="<?= h($month) ?>" <?= $month === $selectedMonth ? 'selected' : '' ?>><?= h(date('F Y', strtotime($month . '-01'))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Player</th><th>Predictions</th><th class="text-end">Points</th></tr></thead>
                    <tbody>
                        <?php if ($months): $renderRows($monthRows); else: echo '<tr><td colspan="4" class="text-muted">No scores yet this season.</td></tr>'; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Season<?= $seasonName !== '' ? ' ' . h($seasonName) : '' ?></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Player</th><th>Predictions</th><th class="text-end">Points</th></tr></thead>
                    <tbody>
                        <?php $renderRows($seasonRows); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
