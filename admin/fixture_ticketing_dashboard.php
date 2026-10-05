<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Match Day',
    'title' => 'Fixture Ticketing Dashboard',
    'subtitle' => 'Unified ticket sales, gate admissions, complimentary entry, attendance and revenue.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/ticketing_reporting.php';

hub_auth_require_capability('tickets_ops');

$seasonContext = getSeasonContext($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$fixtureId = (int) ($_GET['fixture_id'] ?? 0);

$fixturesStmt = $pdo->prepare('SELECT id, opponent, match_date, kickoff_time
    FROM match_fixtures
    WHERE season_id = :season AND is_home = 1
    ORDER BY match_date DESC, kickoff_time DESC, id DESC
    LIMIT 100');
$fixturesStmt->execute([':season' => $seasonId]);
$fixtures = $fixturesStmt->fetchAll(PDO::FETCH_ASSOC);
if ($fixtureId <= 0 && $fixtures) {
    $fixtureId = (int) $fixtures[0]['id'];
}

$fixture = null;
foreach ($fixtures as $candidate) {
    if ((int) $candidate['id'] === $fixtureId) {
        $fixture = $candidate;
        break;
    }
}
$dashboard = $fixture ? getFixtureTicketingDashboard($pdo, $fixtureId) : null;
?>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-3 align-items-end">
        <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
        <div class="col-md-8">
            <label class="form-label fw-semibold" for="fixture_id">Fixture</label>
            <select class="form-select" id="fixture_id" name="fixture_id">
                <?php foreach ($fixtures as $row): ?>
                    <option value="<?= (int) $row['id'] ?>" <?= (int) $row['id'] === $fixtureId ? 'selected' : '' ?>>vs <?= h((string) $row['opponent']) ?> - <?= h(date('d/m/Y', strtotime((string) $row['match_date']))) ?><?= !empty($row['kickoff_time']) ? ' ' . h(date('H:i', strtotime((string) $row['kickoff_time']))) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4"><button class="btn btn-brand" type="submit">Open Dashboard</button></div>
    </div>
</form>

<?php if (!$dashboard): ?>
    <div class="alert alert-info">No fixture selected.</div>
<?php else: ?>
    <?php
    hub_render_metric_grid([
        ['label' => 'Total admitted', 'value' => (int) $dashboard['attendance']['total'], 'meta' => 'From admissions.quantity', 'icon' => 'fa-users', 'tone' => 'primary'],
        ['label' => 'Online tickets', 'value' => (int) $dashboard['attendance']['online_match_tickets'], 'meta' => 'Scanned match tickets', 'icon' => 'fa-ticket', 'tone' => 'success'],
        ['label' => 'Season tickets', 'value' => (int) $dashboard['attendance']['season_passes'], 'meta' => 'Scanned tickets', 'icon' => 'fa-id-card', 'tone' => 'warning'],
        ['label' => 'Gate POS', 'value' => (int) $dashboard['attendance']['pos_gate'], 'meta' => 'Paid gate admissions', 'icon' => 'fa-cash-register', 'tone' => 'info'],
        ['label' => 'Complimentary', 'value' => (int) $dashboard['attendance']['complimentary'], 'meta' => 'Staff-recorded comps', 'icon' => 'fa-handshake', 'tone' => 'neutral'],
        ['label' => 'Net ticket revenue', 'value' => gbp((float) $dashboard['finance']['net_ticket_revenue']), 'meta' => 'Online + POS - refunds', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
    ], 'Fixture ticketing summary');
    ?>

    <div class="row g-3">
        <?php foreach (['online_match_tickets' => 'Online Match Tickets', 'pos_gate' => 'Gate POS', 'complimentary' => 'Complimentary'] as $key => $title): ?>
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <h2 class="h5"><?= h($title) ?></h2>
                        <?php foreach ($dashboard[$key] as $row): ?>
                            <div class="d-flex justify-content-between border-bottom py-2"><span><?= h((string) $row['label']) ?></span><strong><?= (int) $row['qty'] ?></strong></div>
                        <?php endforeach; ?>
                        <?php if (!$dashboard[$key]): ?><p class="text-muted mb-0">No entries yet.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
            <h2 class="h5">Finance</h2>
            <div class="d-flex justify-content-between border-bottom py-2"><span>Online gross</span><strong><?= gbp((float) $dashboard['finance']['online_gross']) ?></strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span>Gate gross</span><strong><?= gbp((float) $dashboard['finance']['gate_gross']) ?></strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span>Refunds</span><strong><?= gbp((float) $dashboard['finance']['refunds']) ?></strong></div>
            <div class="d-flex justify-content-between pt-2"><span>Net ticket revenue</span><strong><?= gbp((float) $dashboard['finance']['net_ticket_revenue']) ?></strong></div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
