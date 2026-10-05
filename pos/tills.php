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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $isAuditCheck = $action === 'audit_till_close_count';
    if (!csrf_check()) {
        if ($isAuditCheck) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload and try again.']);
            exit;
        }
        $error = 'Your session expired. Please reload and try again.';
    } else {
        try {
            if ($action === 'open_till') {
                $sessionId = pos_till_session_open($pdo, (int) ($_POST['trading_day_id'] ?? 0), (int) ($_POST['location_id'] ?? 0), $actor, (float) ($_POST['opening_float'] ?? 0));
                pos_audit_event($pdo, 'pos_till_opened', 'Opened POS till session #' . $sessionId, [
                    'trading_day_id' => (int) ($_POST['trading_day_id'] ?? 0),
                    'till_session_id' => $sessionId,
                    'location_id' => (int) ($_POST['location_id'] ?? 0),
                    'operator_id' => pos_controls_actor_operator_id($actor),
                    'hub_account_id' => pos_controls_actor_account_id($actor),
                    'amount' => (float) ($_POST['opening_float'] ?? 0),
                ]);
                $notice = 'Till opened.';
            } elseif ($action === 'close_till') {
                $sessionId = (int) ($_POST['till_session_id'] ?? 0);
                pos_till_session_close($pdo, $sessionId, $actor, (float) ($_POST['counted_cash'] ?? 0), (string) ($_POST['closing_notes'] ?? ''));
                $closedStmt = $pdo->prepare('SELECT trading_day_id, location_id, cash_variance FROM pos_till_sessions WHERE id = :id');
                $closedStmt->execute([':id' => $sessionId]);
                $closed = $closedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                pos_audit_event($pdo, 'pos_till_closed', 'Closed POS till session #' . $sessionId, [
                    'trading_day_id' => (int) ($closed['trading_day_id'] ?? 0),
                    'till_session_id' => $sessionId,
                    'location_id' => (int) ($closed['location_id'] ?? 0),
                    'operator_id' => pos_controls_actor_operator_id($actor),
                    'hub_account_id' => pos_controls_actor_account_id($actor),
                    'amount' => (float) ($closed['cash_variance'] ?? 0),
                ]);
                $notice = 'Till closed.';
            } elseif ($action === 'audit_till_close_count') {
                $sessionId = (int) ($_POST['till_session_id'] ?? 0);
                $sessionStmt = $pdo->prepare("
                    SELECT ts.id, l.name AS location_name
                    FROM pos_till_sessions ts
                    JOIN pos_locations l ON l.id = ts.location_id
                    WHERE ts.id = :id AND ts.status = 'open'
                    LIMIT 1
                ");
                $sessionStmt->execute([':id' => $sessionId]);
                $auditSession = $sessionStmt->fetch(PDO::FETCH_ASSOC);
                if (!$auditSession) {
                    throw new RuntimeException('This till session is no longer open.');
                }
                $countedCash = max(0, round((float) ($_POST['counted_cash'] ?? 0), 2));
                $expectedCash = pos_till_session_expected_cash($pdo, $sessionId);
                $variance = round($countedCash - $expectedCash, 2);
                $notes = trim((string) ($_POST['closing_notes'] ?? ''));
                pos_audit_event(
                    $pdo,
                    'pos_till_close_count_checked',
                    'Checked ' . (string) $auditSession['location_name'] . ' till session #' . $sessionId . ' counted ' . gbp($countedCash) . ' variance ' . gbp($variance) . ($notes !== '' ? ' notes: ' . $notes : ''),
                    [
                        'till_session_id' => $sessionId,
                        'amount' => $variance,
                    ]
                );
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'variance' => $variance, 'balanced' => abs($variance) < 0.005]);
                exit;
            }
        } catch (Throwable $e) {
            if ($isAuditCheck) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        }
    }
}

$activeTradingDay = pos_trading_day_active($pdo);
$locations = $pdo->query('SELECT * FROM pos_locations WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$tillSessions = $activeTradingDay ? pos_till_sessions_for_day($pdo, (int) $activeTradingDay['id']) : [];
$activeTillSessionsByLocation = [];
foreach ($tillSessions as $session) {
    if ((string) $session['status'] === 'open') {
        $activeTillSessionsByLocation[(int) $session['location_id']] = $session;
    }
}
$latestTillSessionsByLocation = [];
foreach ($tillSessions as $session) {
    $locationId = (int) $session['location_id'];
    if (!isset($latestTillSessionsByLocation[$locationId])) {
        $latestTillSessionsByLocation[$locationId] = $session;
    }
}
foreach ($locations as &$location) {
    $location['till_session'] = $activeTillSessionsByLocation[(int) $location['id']] ?? null;
    $location['latest_till_session'] = $latestTillSessionsByLocation[(int) $location['id']] ?? null;
}
unset($location);

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'Till Sessions',
    'subtitle' => 'Open, close, and reconcile each till for the active POS day.',
];

require_once __DIR__ . '/../admin/header.php';
?>

<div class="pos-tills-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/pos_overview.php">POS Overview</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Till Sessions</span></nav>
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <section class="hub-section-commandbar" aria-labelledby="posTillsActionsTitle">
        <div>
            <h2 id="posTillsActionsTitle">Till control</h2>
            <p><?= $activeTradingDay ? 'Business date ' . h(date('d/m/Y', strtotime((string) $activeTradingDay['business_date']))) : 'Open the POS day before opening tills.' ?></p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/admin/pos_overview.php"><i class="fa-solid fa-chart-line" aria-hidden="true"></i>Overview</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/reports.php"><i class="fa-solid fa-table-list" aria-hidden="true"></i>Reports</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/z_report.php<?= $activeTradingDay ? '?trading_day_id=' . (int) $activeTradingDay['id'] : '' ?>"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i>X/Z</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/cash_movements.php"><i class="fa-solid fa-vault" aria-hidden="true"></i>Cash</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/audit.php<?= $activeTradingDay ? '?trading_day_id=' . (int) $activeTradingDay['id'] : '' ?>"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Audit</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/locations.php"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Locations</a>
        </div>
    </section>

    <?php if (!$activeTradingDay): ?>
        <section class="card hub-panel">
            <div class="card-body">
                <div class="hub-empty-state">No POS day is open. Open the POS day from POS Overview before opening tills.</div>
            </div>
        </section>
    <?php else: ?>
        <section class="card hub-panel table-responsive" aria-label="Current till sessions">
            <table class="table align-middle hub-data-table mb-0">
                <thead><tr><th>Till</th><th>Status</th><th>Opened</th><th>Closed</th><th class="text-end">Sales</th><th class="text-end">Taken</th><th class="text-end">Cash Sales</th><th class="text-end">Counted</th><th class="text-end">Variance</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($locations as $location): ?>
                    <?php
                    $activeSession = is_array($location['till_session'] ?? null) ? $location['till_session'] : null;
                    $session = $activeSession ?: (is_array($location['latest_till_session'] ?? null) ? $location['latest_till_session'] : null);
                    $modalId = $activeSession ? 'closeTillModal' . (int) $activeSession['id'] : 'openTillModal' . (int) $location['id'];
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= h((string) $location['name']) ?></td>
                        <td><span class="badge hub-status <?= $activeSession ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $activeSession ? 'Open' : 'Closed' ?></span></td>
                        <td><?= $session ? h(date('d/m/Y H:i', strtotime((string) $session['opened_at']))) : '-' ?></td>
                        <td><?= $session && !empty($session['closed_at']) ? h(date('d/m/Y H:i', strtotime((string) $session['closed_at']))) : '-' ?></td>
                        <td class="text-end"><?= $session ? (int) $session['sales_count'] : '-' ?></td>
                        <td class="text-end fw-semibold"><?= $session ? gbp((float) $session['total']) : '-' ?></td>
                        <td class="text-end"><?= $session ? gbp((float) $session['cash_sales']) : '-' ?></td>
                        <td class="text-end"><?= $session && $session['counted_cash'] !== null ? gbp((float) $session['counted_cash']) : '-' ?></td>
                        <td class="text-end fw-bold"><?= $session && $session['cash_variance'] !== null ? gbp((float) $session['cash_variance']) : '-' ?></td>
                        <td class="text-end">
                            <?php if ($activeSession): ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>">Close till</button>
                            <?php else: ?>
                                <?php if ($session): ?><a class="btn btn-sm btn-outline-secondary" href="/pos/z_report.php?trading_day_id=<?= (int) $activeTradingDay['id'] ?>&till_session_id=<?= (int) $session['id'] ?>">Report</a><?php endif; ?>
                                <button class="btn btn-sm btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#<?= h($modalId) ?>">Open till</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <?php foreach ($locations as $location): ?>
            <?php
            $session = is_array($location['till_session'] ?? null) ? $location['till_session'] : null;
            if (!$session):
                $modalId = 'openTillModal' . (int) $location['id'];
            ?>
                <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-labelledby="<?= h($modalId) ?>Title" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="post" class="modal-content">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="open_till">
                            <input type="hidden" name="trading_day_id" value="<?= (int) $activeTradingDay['id'] ?>">
                            <input type="hidden" name="location_id" value="<?= (int) $location['id'] ?>">
                            <div class="modal-header">
                                <div>
                                    <div class="small text-uppercase fw-bold text-muted">Open till</div>
                                    <h2 class="modal-title h5 mb-0" id="<?= h($modalId) ?>Title"><?= h((string) $location['name']) ?></h2>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <label class="form-label" for="opening_float_<?= (int) $location['id'] ?>">Opening float</label>
                                <input class="form-control" id="opening_float_<?= (int) $location['id'] ?>" name="opening_float" type="number" min="0" step="0.01" inputmode="decimal" required>
                                <div class="form-text">Enter the cash float placed into this till before trading starts.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-brand" type="submit">Open till</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php continue; ?>
            <?php endif; ?>
            <?php
            $modalId = 'closeTillModal' . (int) $session['id'];
            ?>
            <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-labelledby="<?= h($modalId) ?>Title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="post" class="modal-content pos-close-till-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="close_till">
                        <input type="hidden" name="till_session_id" value="<?= (int) $session['id'] ?>">
                        <div class="modal-header">
                            <div>
                                <div class="small text-uppercase fw-bold text-muted">Close till</div>
                                <h2 class="modal-title h5 mb-0" id="<?= h($modalId) ?>Title"><?= h((string) $location['name']) ?></h2>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="pos-close-till-summary d-none">
                                <span><small>Opening float</small><strong><?= gbp((float) $session['opening_float']) ?></strong></span>
                                <span><small>Cash sales</small><strong><?= gbp((float) $session['cash_sales']) ?></strong></span>
                                <span><small>Sales</small><strong><?= (int) $session['sales_count'] ?></strong></span>
                            </div>
                            <div data-close-entry-screen>
                                <div class="mb-3">
                                    <label class="form-label" for="counted_cash_<?= (int) $session['id'] ?>">Cash counted in drawer</label>
                                    <input class="form-control" id="counted_cash_<?= (int) $session['id'] ?>" name="counted_cash" type="number" min="0" step="0.01" required>
                                    <div class="form-text">Enter the actual cash physically counted in this till, including the opening float. After you press Close till, the system checks whether the drawer balances.</div>
                                </div>
                                <div>
                                    <label class="form-label" for="closing_notes_<?= (int) $session['id'] ?>">Closing notes</label>
                                    <textarea class="form-control" id="closing_notes_<?= (int) $session['id'] ?>" name="closing_notes" rows="3" maxlength="255" placeholder="Optional notes, for example: cash bag number, reason for a shortage, or who counted the till."></textarea>
                                    <div class="form-text">Use notes to explain anything useful for cash-up or audit. Leave blank if there is nothing to record.</div>
                                </div>
                            </div>
                            <div class="pos-close-till-check d-none" data-variance-result role="status" aria-live="polite"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-outline-secondary d-none" type="button" data-recheck-cash>Recheck cash</button>
                            <button class="btn btn-danger" type="button" data-check-variance>Close till</button>
                            <button class="btn btn-danger d-none" type="submit" data-accept-variance>Accept and close</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.pos-close-till-form').forEach((form) => {
    const countedInput = form.querySelector('[name="counted_cash"]');
    const entryScreen = form.querySelector('[data-close-entry-screen]');
    const checkButton = form.querySelector('[data-check-variance]');
    const acceptButton = form.querySelector('[data-accept-variance]');
    const recheckButton = form.querySelector('[data-recheck-cash]');
    const result = form.querySelector('[data-variance-result]');
    const money = (value) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(value);
    const resetCheck = () => {
        result.classList.add('d-none');
        result.classList.remove('pos-close-till-check--balanced', 'pos-close-till-check--variance');
        result.textContent = '';
        entryScreen?.classList.remove('d-none');
        acceptButton.classList.add('d-none');
        recheckButton.classList.add('d-none');
        checkButton.classList.remove('d-none');
    };

    countedInput?.addEventListener('input', resetCheck);
    recheckButton?.addEventListener('click', () => {
        resetCheck();
        countedInput?.focus();
        countedInput?.select();
    });
    checkButton?.addEventListener('click', async () => {
        if (!countedInput.reportValidity()) {
            return;
        }
        checkButton.disabled = true;
        const payload = new FormData(form);
        payload.set('action', 'audit_till_close_count');
        let variance = 0;
        let balanced = false;
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Unable to save the count check.');
            }
            variance = Number(data.variance || 0);
            balanced = Boolean(data.balanced);
        } catch (error) {
            result.classList.remove('d-none', 'pos-close-till-check--balanced');
            result.classList.add('pos-close-till-check--variance');
            result.innerHTML = '<strong>Count check was not saved.</strong><span>' + String(error.message || error) + '</span>';
            return;
        } finally {
            checkButton.disabled = false;
        }
        result.classList.remove('d-none', 'pos-close-till-check--balanced', 'pos-close-till-check--variance');
        result.classList.add(balanced ? 'pos-close-till-check--balanced' : 'pos-close-till-check--variance');
        result.innerHTML = balanced
            ? '<strong>Till balances.</strong><span>No variance found. You can accept and close this till.</span>'
            : '<strong>Variance found: ' + money(variance) + '</strong><span>Accept this variance if it is correct, or recheck the drawer and enter the cash count again.</span>';
        entryScreen?.classList.add('d-none');
        checkButton.classList.add('d-none');
        acceptButton.classList.remove('d-none');
        recheckButton.classList.remove('d-none');
    });
});
</script>

<?php require __DIR__ . '/../admin/footer.php'; ?>
