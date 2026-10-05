<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/season.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/audit.php';

pos_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('You do not have permission to manage POS fixture context.');
}

$seasonContext = getSeasonContext($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $gateFixtureId = (int) ($_POST['fixture_id'] ?? 0);
        $pdo->prepare('UPDATE pos_locations SET current_fixture_id = :fixture WHERE code = "gate"')
            ->execute([':fixture' => $gateFixtureId ?: null]);
        auditLog($pdo, 'pos_gate_fixture_updated', $gateFixtureId > 0 ? "Set POS gate fixture to fixture #{$gateFixtureId}" : 'Cleared POS gate fixture');
        $notice = 'Gate fixture updated.';
    }
}

$fixturesStmt = $pdo->prepare("SELECT id, opponent, match_date, kickoff_time
    FROM match_fixtures
    WHERE season_id = :season AND is_home = 1 AND status NOT IN ('cancelled','postponed')
    ORDER BY match_date DESC, kickoff_time DESC, id DESC
    LIMIT 100");
$fixturesStmt->execute([':season' => $seasonId]);
$fixtures = $fixturesStmt->fetchAll(PDO::FETCH_ASSOC);
$currentFixtureId = (int) $pdo->query('SELECT current_fixture_id FROM pos_locations WHERE code = "gate" LIMIT 1')->fetchColumn();
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gate Fixture - POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
</head>
<body class="hub-shell">
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h1 class="h3 mb-1">Gate POS Fixture</h1><p class="text-muted mb-0">Admission products sold at Gate are admitted to this fixture.</p></div>
        <a class="btn btn-outline-secondary" href="/pos/">Back to POS</a>
    </div>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <div class="col-md-8">
                    <label class="form-label fw-semibold" for="fixture_id">Current fixture</label>
                    <select class="form-select" id="fixture_id" name="fixture_id">
                        <option value="">No selected fixture</option>
                        <?php foreach ($fixtures as $fixture): ?>
                            <option value="<?= (int) $fixture['id'] ?>" <?= (int) $fixture['id'] === $currentFixtureId ? 'selected' : '' ?>>vs <?= h((string) $fixture['opponent']) ?> - <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?><?= !empty($fixture['kickoff_time']) ? ' ' . h(date('H:i', strtotime((string) $fixture['kickoff_time']))) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-brand w-100" type="submit">Save Fixture</button></div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
