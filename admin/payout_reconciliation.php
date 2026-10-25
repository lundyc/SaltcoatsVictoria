<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Finance',
    'title' => 'Payout Reconciliation',
    'subtitle' => 'Match every Stripe payout to the payments and refunds inside it, and spot anything that does not add up.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';

if (!hub_auth_has_capability('finance_view')) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You do not have permission to view this page.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

require_once __DIR__ . '/lib/payout_reconciliation.php';
ensureStripeSchema($pdo);

$days = in_array((int) ($_GET['days'] ?? 0), [30, 60, 90, 180, 365], true) ? (int) $_GET['days'] : 90;
$refresh = !empty($_GET['refresh']);
$error = '';
$report = null;
if (stripe_is_configured()) {
    try {
        $report = payout_build_report($pdo, time() - $days * 86400, $refresh);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$p = static fn (int $cents): string => ($cents < 0 ? '-' : '') . gbp(abs($cents) / 100);
$statusTone = ['paid' => 'success', 'in_transit' => 'info', 'pending' => 'warning', 'canceled' => 'secondary', 'failed' => 'danger'];
$attention = $report ? $report['totals']['unrecognised'] + $report['totals']['mismatched'] + count($report['missing']) + ($report['totals']['unexplained'] > 0 ? 1 : 0) : 0;
?>

<?php hub_render_breadcrumbs([['label' => 'Finance'], ['label' => 'Payout reconciliation']]); ?>

<?php if (!stripe_is_configured()): ?><div class="alert alert-warning">Stripe is not configured, so payouts cannot be read.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Could not read payouts from Stripe.</strong> <?= h($error) ?></div><?php endif; ?>

<div class="hub-section-commandbar">
    <div><h2>Stripe payouts, last <?= (int) $days ?> days</h2><p>Read-only: this page only asks Stripe questions and never moves money. Settled payouts are remembered, so it stays fast.</p></div>
    <div class="hub-local-actions">
        <form method="get" class="d-flex gap-2 align-items-center">
            <select class="form-select form-select-sm" name="days" aria-label="Period" onchange="this.form.submit()">
                <?php foreach ([30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '6 months', 365 => '12 months'] as $d => $label): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
            </select>
            <a class="btn btn-sm btn-outline-secondary text-nowrap" href="payout_reconciliation.php?days=<?= $days ?>&amp;refresh=1">Refresh from Stripe</a>
            <?php if ($report): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="payout_reconciliation_export.php?days=<?= $days ?>"><i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>Download CSV</a><?php endif; ?>
        </form>
    </div>
</div>

<?php if ($report): $t = $report['totals']; ?>
<?php hub_render_metric_grid([
    ['label' => 'Paid into the bank', 'value' => $p($t['received']), 'meta' => count($report['payouts']) . ' payout' . (count($report['payouts']) === 1 ? '' : 's'), 'icon' => 'fa-building-columns', 'tone' => 'success'],
    ['label' => 'Card payments taken', 'value' => $p($t['gross']), 'meta' => 'before Stripe\'s fees', 'icon' => 'fa-credit-card', 'tone' => 'primary'],
    ['label' => 'Stripe fees', 'value' => $p($t['fees']), 'meta' => 'taken out of the payments', 'icon' => 'fa-percent', 'tone' => 'warning'],
    ['label' => 'Refunds and disputes', 'value' => $p($t['refunds']), 'meta' => 'taken back out', 'icon' => 'fa-rotate-left', 'tone' => 'danger'],
    ['label' => 'Needs attention', 'value' => (string) $attention, 'meta' => $attention === 0 ? 'everything adds up' : 'see below', 'icon' => 'fa-triangle-exclamation', 'tone' => $attention === 0 ? 'success' : 'danger'],
], 'Payout summary'); ?>

<?php if ($attention > 0 || $report['awaiting']): ?>
<div class="hub-panel mb-3" style="padding:1.15rem 1.25rem<?= $attention > 0 ? ';border-left:4px solid #b42335' : '' ?>">
    <h3 class="h5 mb-2"><?= $attention > 0 ? 'Needs attention' : 'Waiting for a payout' ?></h3>
    <?php if ($t['unrecognised'] > 0): ?><p class="mb-2"><strong><?= (int) $t['unrecognised'] ?> payment<?= $t['unrecognised'] === 1 ? '' : 's' ?> (<?= h($p($t['unrecognised_amount'])) ?>)</strong> in these payouts carry nothing the Hub recognises. They may have been taken directly in Stripe, or by a payment link that was not recorded. Look for "Not recognised" below.</p><?php endif; ?>
    <?php if ($t['mismatched'] > 0): ?><p class="mb-2"><strong><?= (int) $t['mismatched'] ?> payment<?= $t['mismatched'] === 1 ? '' : 's' ?></strong> where the amount Stripe took is not the amount on the Hub order.</p><?php endif; ?>
    <?php if ($t['unexplained'] > 0): ?><p class="mb-2"><strong>A payout does not add up</strong> to the lines inside it (difference <?= h($p($t['unexplained'])) ?>). It is marked below.</p><?php endif; ?>
    <?php if ($report['missing']): ?>
        <p class="mb-1"><strong><?= count($report['missing']) ?> payment<?= count($report['missing']) === 1 ? '' : 's' ?></strong> the Hub recorded as paid more than <?= (int) PAYOUT_EXPECTED_WITHIN_DAYS ?> days ago do not appear in any payout:</p>
        <ul class="mb-2"><?php foreach ($report['missing'] as $m): ?><li>Order #<?= (int) $m['order_id'] ?> · <?= h($m['name']) ?> · <?= h(gbp($m['amount'])) ?> · paid <?= h(date('j M', strtotime($m['paid_at']))) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($report['awaiting']): ?>
        <p class="mb-1 text-muted"><?= count($report['awaiting']) ?> recent payment<?= count($report['awaiting']) === 1 ? ' is' : 's are' ?> not in a payout yet. That is normal: Stripe pays out a few days after a payment.</p>
        <ul class="mb-0 text-muted small"><?php foreach (array_slice($report['awaiting'], 0, 8) as $m): ?><li>Order #<?= (int) $m['order_id'] ?> · <?= h(gbp($m['amount'])) ?> · paid <?= h(date('j M H:i', strtotime($m['paid_at']))) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($report['by_kind']): ?>
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card hub-list-card hub-table-card h-100">
            <div class="card-header"><h2 class="h5 mb-0">Where the money came from</h2></div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table align-middle hub-data-table mb-0"><thead><tr><th>Type</th><th class="text-end">Payments</th><th class="text-end">Taken</th><th class="text-end">Stripe fees</th><th class="text-end">Received</th></tr></thead><tbody>
                    <?php foreach ($report['by_kind'] as $k): ?>
                        <tr><td><?= h((string) $k['label']) ?></td><td class="text-end"><?= (int) $k['count'] ?></td><td class="text-end"><?= h($p((int) $k['gross'])) ?></td><td class="text-end"><?= h($p((int) $k['fees'])) ?></td><td class="text-end"><strong><?= h($p((int) $k['net'])) ?></strong></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div></div>
        </div>
    </div>
    <?php $ef = $report['event_fees']; if ($ef['count'] > 0): $surplus = round($ef['net'] - $ef['tickets'], 2); ?>
    <div class="col-lg-5">
        <div class="hub-panel h-100" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-2">Event booking fee check</h3>
            <p class="text-muted small">Does the booking fee cover what Stripe takes, so the club receives the full ticket price?</p>
            <div class="d-flex justify-content-between py-1"><span>Ticket prices (<?= (int) $ef['count'] ?> payments)</span><strong><?= h(gbp($ef['tickets'])) ?></strong></div>
            <div class="d-flex justify-content-between py-1"><span>Booking fees charged</span><strong><?= h(gbp($ef['booking'])) ?></strong></div>
            <div class="d-flex justify-content-between py-1"><span>Stripe fees taken</span><strong>- <?= h(gbp($ef['stripe'])) ?></strong></div>
            <div class="d-flex justify-content-between py-2 mt-1 border-top"><span>Club received, against ticket prices</span><strong class="<?= $surplus < -0.005 ? 'text-danger' : 'text-success' ?>"><?= $surplus >= 0 ? '+' : '-' ?><?= h(gbp(abs($surplus))) ?></strong></div>
            <p class="small text-muted mb-0"><?= $surplus < -0.005 ? 'The booking fee is not quite covering Stripe\'s fee. Check the rates on Events > Settings.' : 'The booking fee is covering Stripe\'s fee.' ?></p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2 class="h5 mb-2">Payouts</h2>
<?php if (!$report['payouts']): ?>
    <div class="hub-panel" style="padding:1.15rem 1.25rem"><p class="mb-0 text-muted">No payouts in this period.</p></div>
<?php endif; ?>
<?php foreach ($report['payouts'] as $po): ?>
    <details class="hub-panel mb-2" style="padding:.9rem 1.15rem"<?= $po['reconciled'] === false ? ' open' : '' ?>>
        <summary class="d-flex flex-wrap justify-content-between align-items-center gap-2" style="cursor:pointer">
            <span><strong><?= h(date('D j M Y', $po['arrival'])) ?></strong> <span class="text-muted small ms-2"><?= h(substr($po['id'], 0, 12)) ?>…</span>
                <span class="badge bg-<?= h($statusTone[$po['status']] ?? 'secondary') ?> ms-2"><?= h(ucfirst(str_replace('_', ' ', $po['status']))) ?></span>
                <?php if (!empty($po['explained'])): ?><span class="badge bg-success ms-1">Adds up (explained)</span>
                <?php elseif ($po['reconciled'] === true): ?><span class="badge bg-success ms-1">Adds up</span>
                <?php elseif ($po['reconciled'] === false): ?><span class="badge bg-danger ms-1">Out by <?= h($p(abs($po['difference']))) ?></span>
                <?php else: ?><span class="badge bg-secondary ms-1">Not itemised</span><?php endif; ?></span>
            <span class="fs-5"><strong><?= h($p($po['amount'])) ?></strong></span>
        </summary>
        <?php if ($po['note'] !== ''): ?>
            <p class="<?= $po['lines'] ? 'alert alert-info py-2 mt-2 mb-2 small' : 'text-muted mt-2 mb-0' ?>"><?= h($po['note']) ?></p>
        <?php endif; ?>
        <?php if ($po['lines']): ?>
            <div class="small text-muted mt-2 mb-2">Card payments <?= h($p($po['gross'])) ?> · refunds and disputes <?= h($p($po['refunds'])) ?> · Stripe fees -<?= h($p($po['fees'])) ?> = <strong><?= h($p($po['net'])) ?></strong> received</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Date</th><th>What</th><th>Hub record</th><th>Customer</th><th class="text-end">Taken</th><th class="text-end">Fee</th><th class="text-end">Received</th></tr></thead>
                    <tbody>
                        <?php foreach ($po['lines'] as $l): $bad = $l['hub']['amount_ok'] === false; ?>
                            <tr class="<?= $bad ? 'table-danger' : '' ?>">
                                <td class="text-nowrap"><?= h(date('j M H:i', $l['created'])) ?></td>
                                <td><?= h($l['is_refund'] ? 'Refund' : (string) $l['hub']['label']) ?><?= $l['is_refund'] && $l['kind'] !== 'unknown' ? ' <span class="text-muted">(' . h((string) $l['hub']['label']) . ')</span>' : '' ?></td>
                                <td><?php if ($l['hub']['url'] !== ''): ?><a href="<?= h($l['hub']['url']) ?>"><?= h($l['hub']['ref'] ?: 'Open') ?></a><?php else: ?><?= h($l['hub']['ref'] ?: '') ?><?php endif; ?>
                                    <?php if ($l['hub']['note'] !== ''): ?><div class="small text-danger"><?= h($l['hub']['note']) ?></div><?php endif; ?></td>
                                <td class="small"><?= h(trim($l['name'])) ?><?= $l['email'] !== '' ? '<div class="text-muted">' . h($l['email']) . '</div>' : '' ?></td>
                                <td class="text-end"><?= h($p($l['gross'])) ?></td>
                                <td class="text-end"><?= $l['fee'] > 0 ? h($p($l['fee'])) : '-' ?></td>
                                <td class="text-end"><strong><?= h($p($l['net'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </details>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
