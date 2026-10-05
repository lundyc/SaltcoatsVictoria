<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Fixture Ticket Setup',
    'subtitle' => 'Choose which tickets are sold online for each home fixture.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_tickets.php';
require_once __DIR__ . '/lib/audit.php';
ensureMatchTicketSchema($pdo);

$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$fixtureId = (int) ($_GET['fixture_id'] ?? 0);
$errors = [];
$success = '';
$fixtures = $seasonId > 0 ? getTicketedHomeFixtures($pdo, $seasonId, false) : [];
if ($fixtureId <= 0 && $fixtures) {
    $fixtureId = (int) $fixtures[0]['id'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } else {
        $fixtureId = (int) ($_POST['fixture_id'] ?? $fixtureId);
        try {
            foreach (getMatchTicketPackages($pdo) as $package) {
                $packageId = (int) $package['id'];
                saveFixtureTicketPackage($pdo, $fixtureId, $packageId, [
                    'price' => $_POST['price'][$packageId] ?? $package['default_price'],
                    'allocation' => $_POST['allocation'][$packageId] ?? 0,
                    'is_active' => isset($_POST['active'][$packageId]),
                ]);
            }
            $fixtureLabel = 'fixture #' . $fixtureId;
            foreach ($fixtures as $fxRow) {
                if ((int) $fxRow['id'] === $fixtureId) {
                    $fixtureLabel = 'vs ' . (string) $fxRow['opponent'];
                    break;
                }
            }
            auditLog($pdo, 'fixture_ticket_setup_saved', 'Saved fixture ticket setup for ' . $fixtureLabel);
            $success = 'Fixture ticket setup saved.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$fixture = null;
foreach ($fixtures as $row) {
    if ((int) $row['id'] === $fixtureId) {
        $fixture = $row;
        break;
    }
}
$packages = getMatchTicketPackages($pdo);
$fixturePackages = $fixtureId > 0 ? getFixtureTicketPackages($pdo, $fixtureId, false) : [];
$fixturePackagesByPackage = [];
foreach ($fixturePackages as $row) {
    $fixturePackagesByPackage[(int) $row['package_id']] = $row;
}
?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
            <div class="col-md-8">
                <label class="form-label" for="fixtureSelect">Home fixture</label>
                <select class="form-select" id="fixtureSelect" name="fixture_id" onchange="this.form.submit()">
                    <?php foreach ($fixtures as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= (int) $row['id'] === $fixtureId ? 'selected' : '' ?>>
                            vs <?= h((string) $row['opponent']) ?> - <?= h(date('D j M Y', strtotime((string) $row['match_date']))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><button class="btn btn-outline-secondary w-100" type="submit">Load fixture</button></div>
        </form>
    </div>
</div>

<?php if (!$fixture): ?>
    <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-5">No home fixtures found for this season.</div></div>
<?php else: ?>
    <form method="post" class="card shadow-sm border-0">
        <?= csrf_field() ?>
        <input type="hidden" name="fixture_id" value="<?= (int) $fixtureId ?>">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">vs <?= h((string) $fixture['opponent']) ?></h2>
            <span class="badge text-bg-light"><?= h(date('D j M Y', strtotime((string) $fixture['match_date']))) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 hub-data-table">
                    <thead><tr><th>Package</th><th style="width:160px;">Price</th><th style="width:170px;">Online allocation</th><th style="width:120px;">Sold</th><th style="width:100px;">Active</th></tr></thead>
                    <tbody>
                        <?php foreach ($packages as $package): ?>
                            <?php $row = $fixturePackagesByPackage[(int) $package['id']] ?? null; ?>
                            <tr>
                                <td>
                                    <strong><?= h((string) $package['name']) ?></strong>
                                    <div class="small text-muted"><?= h((string) ($package['description'] ?? '')) ?></div>
                                </td>
                                <td><input class="form-control" type="number" step="0.01" min="0" name="price[<?= (int) $package['id'] ?>]" value="<?= h((string) ($row['price'] ?? $package['default_price'])) ?>"></td>
                                <td><input class="form-control" type="number" min="0" name="allocation[<?= (int) $package['id'] ?>]" value="<?= (int) ($row['allocation'] ?? 0) ?>"></td>
                                <td><?= (int) ($row['sold_qty'] ?? 0) ?></td>
                                <td><input class="form-check-input" type="checkbox" name="active[<?= (int) $package['id'] ?>]" value="1" <?= (int) ($row['is_active'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white text-end"><button class="btn btn-brand" type="submit">Save Ticket Setup</button></div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
