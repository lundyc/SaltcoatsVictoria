<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Season Tickets',
    'title' => 'Season Ticket Eligibility',
    'subtitle' => 'Configure which fixture types each season ticket admits, with fixture-level overrides.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/season_pass_rules.php';
require_once __DIR__ . '/lib/audit.php';

hub_auth_require_capability('tickets_ops');
ensureSeasonPassRuleSchema($pdo);

$seasonContext = getSeasonContext($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save_rule') {
                $typeId = (int) ($_POST['season_ticket_type_id'] ?? 0);
                $competitionType = trim((string) ($_POST['competition_type'] ?? ''));
                $included = isset($_POST['included']) ? 1 : 0;
                $homeRequired = isset($_POST['is_home_required']) ? 1 : 0;
                $pdo->prepare('INSERT INTO season_pass_rules
                    (season_ticket_type_id, competition_id, competition_type, is_home_required, included)
                    VALUES (:type, NULL, :competition_type, :home, :included)
                    ON DUPLICATE KEY UPDATE included = VALUES(included), is_home_required = VALUES(is_home_required)')
                    ->execute([
                        ':type' => $typeId,
                        ':competition_type' => $competitionType !== '' ? $competitionType : null,
                        ':home' => $homeRequired,
                        ':included' => $included,
                    ]);
                auditLog($pdo, 'season_pass_rule_saved', "Saved eligibility rule for season ticket type #{$typeId}" . ($competitionType !== '' ? " (competition: {$competitionType})" : ' (any competition)'));
                $notice = 'Eligibility rule saved.';
            }
            if ($action === 'save_override') {
                $pdo->prepare('INSERT INTO season_pass_fixture_overrides
                    (season_ticket_type_id, fixture_id, included, reason)
                    VALUES (:type, :fixture, :included, :reason)
                    ON DUPLICATE KEY UPDATE included = VALUES(included), reason = VALUES(reason)')
                    ->execute([
                        ':type' => (int) ($_POST['season_ticket_type_id'] ?? 0),
                        ':fixture' => (int) ($_POST['fixture_id'] ?? 0),
                        ':included' => isset($_POST['included']) ? 1 : 0,
                        ':reason' => trim((string) ($_POST['reason'] ?? '')) ?: null,
                    ]);
                auditLog($pdo, 'season_pass_fixture_override_saved', 'Saved fixture override for season ticket type #' . (int) ($_POST['season_ticket_type_id'] ?? 0) . ', fixture #' . (int) ($_POST['fixture_id'] ?? 0));
                $notice = 'Fixture override saved.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$types = getSeasonTicketTypes($pdo, $seasonId);
$fixturesStmt = $pdo->prepare('SELECT f.id, f.opponent, f.match_date, f.kickoff_time, mc.competition_type
    FROM match_fixtures f
    LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
    LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
    WHERE f.season_id = :season AND f.is_home = 1
    ORDER BY f.match_date DESC, f.kickoff_time DESC, f.id DESC
    LIMIT 100');
$fixturesStmt->execute([':season' => $seasonId]);
$fixtures = $fixturesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5">Default Rules</h2>
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_rule">
                    <div class="col-md-6">
                        <label class="form-label">Season ticket type</label>
                        <select class="form-select" name="season_ticket_type_id" required>
                            <?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"><?= h((string) $type['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Competition type</label>
                        <select class="form-select" name="competition_type">
                            <option value="">Any competition</option>
                            <option value="league">League</option>
                            <option value="friendly">Friendly</option>
                            <option value="cup">Cup</option>
                        </select>
                    </div>
                    <div class="col-md-6 form-check ms-2">
                        <input class="form-check-input" type="checkbox" name="is_home_required" id="homeRequired" checked>
                        <label class="form-check-label" for="homeRequired">Home matches only</label>
                    </div>
                    <div class="col-md-6 form-check ms-2">
                        <input class="form-check-input" type="checkbox" name="included" id="included" checked>
                        <label class="form-check-label" for="included">Included</label>
                    </div>
                    <div class="col-12"><button class="btn btn-brand" type="submit">Save Rule</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5">Fixture Override</h2>
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_override">
                    <div class="col-md-6">
                        <label class="form-label">Season ticket type</label>
                        <select class="form-select" name="season_ticket_type_id" required>
                            <?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"><?= h((string) $type['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fixture</label>
                        <select class="form-select" name="fixture_id" required>
                            <?php foreach ($fixtures as $fixture): ?><option value="<?= (int) $fixture['id'] ?>">vs <?= h((string) $fixture['opponent']) ?> - <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 form-check ms-2">
                        <input class="form-check-input" type="checkbox" name="included" id="overrideIncluded" checked>
                        <label class="form-check-label" for="overrideIncluded">Included</label>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reason</label>
                        <input class="form-control" name="reason" maxlength="255">
                    </div>
                    <div class="col-12"><button class="btn btn-brand" type="submit">Save Override</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php foreach ($types as $type): ?>
    <?php $rules = seasonPassRulesForType($pdo, (int) $type['id']); ?>
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
            <h2 class="h5"><?= h((string) $type['name']) ?></h2>
            <?php if (!$rules): ?><p class="text-muted mb-0">No explicit rules yet. Legacy default applies: same-season home fixtures are included unless an override says otherwise.</p><?php endif; ?>
            <?php foreach ($rules as $rule): ?>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span><?= h((string) ($rule['competition_name'] ?: $rule['competition_type'] ?: 'Any competition')) ?><?= (int) $rule['is_home_required'] === 1 ? ' · Home only' : '' ?></span>
                    <strong><?= (int) $rule['included'] === 1 ? 'Included' : 'Excluded' ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
