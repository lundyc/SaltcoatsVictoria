<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';

pos_controls_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$notice = '';
$error = '';
$activeTradingDay = pos_trading_day_active($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            pos_cash_movement_create(
                $pdo,
                (int) ($_POST['location_id'] ?? 0),
                $actor,
                (string) ($_POST['movement_type'] ?? ''),
                (float) ($_POST['amount'] ?? 0),
                (string) ($_POST['reason'] ?? '')
            );
            $notice = 'Cash movement recorded.';
            $activeTradingDay = pos_trading_day_active($pdo);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$openTills = $activeTradingDay ? array_values(array_filter(pos_till_sessions_for_day($pdo, (int) $activeTradingDay['id']), static fn(array $session): bool => (string) $session['status'] === 'open')) : [];
$movementLabels = [
    'skim' => 'Skim',
    'safe_drop' => 'Safe drop',
    'paid_in' => 'Paid-in',
    'paid_out' => 'Paid-out',
];

$movements = [];
if ($activeTradingDay) {
    $stmt = $pdo->prepare("
        SELECT cm.*, l.name AS location_name, o.name AS operator_name, a.email AS account_email
        FROM pos_cash_movements cm
        JOIN pos_locations l ON l.id = cm.location_id
        LEFT JOIN pos_operators o ON o.id = cm.created_by_operator_id
        LEFT JOIN accounts a ON a.id = cm.created_by_account_id
        WHERE cm.trading_day_id = :day
        ORDER BY cm.created_at DESC, cm.id DESC
        LIMIT 100
    ");
    $stmt->execute([':day' => (int) $activeTradingDay['id']]);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'Cash Movements',
    'subtitle' => 'Record skims, safe drops, paid-ins, and paid-outs against the active trading day.',
];
require_once __DIR__ . '/../admin/header.php';
?>
<div class="pos-cash-movements-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Cash Movements</span></nav>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="hub-section-commandbar" aria-labelledby="cashMovementTitle">
        <div>
            <h2 id="cashMovementTitle">Cash control</h2>
            <p><?= $activeTradingDay ? 'Trading day ' . h(date('d/m/Y', strtotime((string) $activeTradingDay['business_date']))) : 'Open the POS day before recording cash movements.' ?></p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/pos/tills.php"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Tills</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/z_report.php"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i>X/Z Reports</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/audit.php"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Audit</a>
        </div>
    </section>

    <section class="card hub-panel p-3 mb-3">
        <form method="post" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-3">
                <label class="form-label fw-bold" for="location_id">Open till</label>
                <select class="form-select" id="location_id" name="location_id" required <?= !$openTills ? 'disabled' : '' ?>>
                    <?php foreach ($openTills as $till): ?><option value="<?= (int) $till['location_id'] ?>"><?= h((string) $till['location_name']) ?> #<?= (int) $till['id'] ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold" for="movement_type">Movement</label>
                <select class="form-select" id="movement_type" name="movement_type" required <?= !$openTills ? 'disabled' : '' ?>>
                    <?php foreach ($movementLabels as $type => $label): ?><option value="<?= h($type) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label fw-bold" for="amount">Amount</label><input class="form-control" id="amount" name="amount" type="number" min="0.01" step="0.01" required <?= !$openTills ? 'disabled' : '' ?>></div>
            <div class="col-md-3"><label class="form-label fw-bold" for="reason">Reason</label><input class="form-control" id="reason" name="reason" maxlength="255" required <?= !$openTills ? 'disabled' : '' ?>></div>
            <div class="col-md-2"><button class="btn btn-dark w-100" type="submit" <?= !$openTills ? 'disabled' : '' ?>>Record</button></div>
        </form>
        <?php if (!$openTills): ?><div class="hub-empty-state mt-3">No tills are open for cash movements.</div><?php endif; ?>
    </section>

    <section class="card hub-panel table-responsive">
        <table class="table align-middle hub-data-table mb-0">
            <thead><tr><th>Time</th><th>Till</th><th>Movement</th><th>Reason</th><th>Recorded by</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><?= h(date('d/m/Y H:i', strtotime((string) $movement['created_at']))) ?></td>
                    <td><?= h((string) $movement['location_name']) ?></td>
                    <td class="fw-semibold"><?= h($movementLabels[(string) $movement['movement_type']] ?? ucfirst(str_replace('_', ' ', (string) $movement['movement_type']))) ?></td>
                    <td><?= h((string) $movement['reason']) ?></td>
                    <td><?= h((string) ($movement['operator_name'] ?: $movement['account_email'] ?: $movement['created_by_name'] ?: '-')) ?></td>
                    <td class="text-end fw-bold"><?= gbp((float) $movement['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$movements): ?><tr><td colspan="6" class="hub-empty-state">No cash movements recorded for this trading day.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/../admin/footer.php'; ?>
