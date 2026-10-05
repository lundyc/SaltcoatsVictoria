<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Complimentary Admission',
    'subtitle' => 'Record sponsor guests, officials, media, committee guests, or other complimentary matchday entry.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/admissions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/audit.php';

hub_auth_require_capability('tickets_refund_comp');
ensureAdmissionsSchema($pdo);

$seasonContext = getSeasonContext($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$notice = '';
$error = '';
$currentUser = hub_auth_current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            $compFixtureId = (int) ($_POST['fixture_id'] ?? 0);
            $compQuantity = (int) ($_POST['quantity'] ?? 1);
            $compCategory = trim((string) ($_POST['category'] ?? 'other')) ?: 'other';
            recordDirectAdmission($pdo, $compFixtureId, $compQuantity, 'complimentary', isset($currentUser['account_id']) ? (int) $currentUser['account_id'] : null, [
                'category' => $compCategory,
                'reason' => trim((string) ($_POST['reason'] ?? '')),
                'external_source' => 'hub',
                'external_reference' => 'complimentary:' . date('YmdHis'),
                'unit_amount' => 0.00,
            ]);
            auditLog($pdo, 'complimentary_admission_issued', "Issued {$compQuantity}x complimentary admission for fixture #{$compFixtureId} ({$compCategory})");
            $notice = 'Complimentary admission recorded.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$stmt = $pdo->prepare("SELECT id, opponent, match_date, kickoff_time
    FROM match_fixtures
    WHERE season_id = :season AND is_home = 1 AND status NOT IN ('cancelled','postponed')
    ORDER BY match_date DESC, kickoff_time DESC, id DESC
    LIMIT 80");
$stmt->execute([':season' => $seasonId]);
$fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="fixture_id">Fixture</label>
                <select class="form-select" id="fixture_id" name="fixture_id" required>
                    <?php foreach ($fixtures as $fixture): ?>
                        <option value="<?= (int) $fixture['id'] ?>">vs <?= h((string) $fixture['opponent']) ?> - <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?><?= !empty($fixture['kickoff_time']) ? ' ' . h(date('H:i', strtotime((string) $fixture['kickoff_time']))) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <?php foreach (['sponsor_guest' => 'Sponsor guest', 'player_family' => 'Player family', 'officials' => 'Officials', 'committee_guest' => 'Committee guest', 'press_media' => 'Press/media', 'other' => 'Other'] as $value => $label): ?>
                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold" for="quantity">Quantity</label>
                <input class="form-control" id="quantity" name="quantity" type="number" min="1" max="99" value="1" required>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold" for="reason">Reason / notes</label>
                <input class="form-control" id="reason" name="reason" maxlength="255">
            </div>
            <div class="col-12">
                <button class="btn btn-brand" type="submit"><i class="fa-solid fa-ticket" aria-hidden="true"></i> Record Complimentary Entry</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
