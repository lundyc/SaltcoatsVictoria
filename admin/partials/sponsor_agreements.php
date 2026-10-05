<?php
// Included by sponsor.php after the sponsor and catalogue have been loaded.
$canManageAgreements = hub_auth_has_capability('sponsorship');
$canManagePayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
$workspacePackages = getSponsorshipPackages($pdo, false);
$workspaceSeasons = $pdo->query('SELECT id,name,start_date,end_date FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$workspacePlayers = $pdo->query('SELECT id,name,active FROM players ORDER BY active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$workspaceTeams = $pdo->query('SELECT id,name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$workspaceFixtures = $pdo->query('SELECT id,season_id,match_date,opponent FROM match_fixtures ORDER BY match_date DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$workspaceJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$workspaceRows = [];
$workspaceTotal = $workspacePaid = $workspaceOutstanding = 0;
foreach ($clubAgreements as $agreement) {
    $agreement['balance'] = max(0, round((float)$agreement['agreed_amount'] - (float)$agreement['total_paid'], 2));
    $agreement['target_label'] = $agreement['player_name'] ?: ($agreement['fixture_opponent'] ? 'vs ' . $agreement['fixture_opponent'] . ' · ' . sponsorProfileDate($agreement['fixture_date'], '') : ($agreement['team_name'] ?: 'Club-wide'));
    $agreement['payment_state'] = !empty($agreement['is_complimentary']) ? 'Complimentary' : ($agreement['balance'] <= 0 ? ((float)$agreement['agreed_amount'] > 0 ? 'Paid' : 'No payment due') : ((float)$agreement['total_paid'] > 0 ? 'Part paid' : 'Unpaid'));
    $agreement['payments'] = getAgreementPayments($pdo, $agreement);
    $workspaceRows[(int)$agreement['id']] = $agreement;
    $workspaceTotal += (float)$agreement['agreed_amount'];
    $workspacePaid += (float)$agreement['total_paid'];
    $workspaceOutstanding += $agreement['balance'];
}
?>
<link rel="stylesheet" href="/admin/assets/css/sponsor-workspace.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/sponsor-workspace.css') ?>">
<?php if (!empty($workspaceMessage)): ?><div class="alert alert-success" role="status"><?= h($workspaceMessage) ?></div><?php endif; ?>
<?php if (!empty($workspaceError)): ?><div class="alert alert-danger" role="alert"><?= h($workspaceError) ?></div><?php endif; ?>
<div class="sponsor-workspace" id="sponsorWorkspace">
    <div id="workspaceLiveError" class="alert alert-danger" role="alert" hidden></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div><h2 class="h4 mb-1">Agreements &amp; payments</h2><p class="text-muted mb-0">Manage sponsorships and record payments in one place.</p></div>
        <?php if ($canManageAgreements): ?><button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#workspaceAgreementModal" data-agreement-id="0" title="Add agreement" aria-label="Add agreement"><i class="fa-solid fa-plus" aria-hidden="true"></i></button><?php endif; ?>
    </div>
    <div class="sponsor-workspace__totals mb-3" aria-label="Totals for shown agreements">
        <div><span>Agreement value</span><strong id="workspaceTotal"><?= gbp($workspaceTotal) ?></strong></div>
        <div><span>Paid</span><strong id="workspacePaid"><?= gbp($workspacePaid) ?></strong></div>
        <div><span>Outstanding</span><strong id="workspaceOutstanding"><?= gbp($workspaceOutstanding) ?></strong></div>
    </div>
    <div class="sponsor-workspace__filters mb-3">
        <div><label for="workspaceSearch" class="form-label">Find an agreement</label><input type="search" id="workspaceSearch" class="form-control" placeholder="Package, player or fixture"></div>
        <div><label for="workspaceSeason" class="form-label">Season</label><select id="workspaceSeason" class="form-select"><option value="">All seasons</option><option value="none">No season</option><?php foreach ($workspaceSeasons as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="workspaceStatus" class="form-label">Payment status</label><select id="workspaceStatus" class="form-select"><option value="">All payments</option><option value="outstanding">Outstanding</option><option value="paid">Paid / no payment due</option></select></div>
    </div>
    <p class="small text-muted" id="workspaceCount" role="status"><?= count($workspaceRows) ?> agreements · totals include all shown agreements</p>
    <div class="sponsor-workspace__list">
    <?php foreach ($workspaceRows as $agreementId => $agreement): ?>
        <article class="sponsor-workspace__agreement" data-workspace-row="<?= $agreementId ?>">
            <div class="sponsor-workspace__row">
                <div class="sponsor-workspace__description">
                    <h3 class="h6 mb-1"><?= h($agreement['package_name']) ?></h3>
                    <div><?= h($agreement['target_label']) ?></div>
                    <div class="small text-muted mt-1"><?= h($agreement['season_name'] ?: 'No season') ?> · <?= h(sponsorProfileDate($agreement['start_date'], 'Open')) ?> – <?= h(sponsorProfileDate($agreement['end_date'], 'Ongoing')) ?></div>
                    <div class="mt-2 d-flex flex-wrap gap-2"><?= sponsorProfileStatusBadge($agreement['effective_status']) ?><span class="badge <?= $agreement['balance'] > 0 ? 'text-bg-warning' : 'text-bg-success' ?>"><?= h($agreement['payment_state']) ?></span></div>
                </div>
                <dl class="sponsor-workspace__amounts mb-0"><div><dt>Value</dt><dd><?= gbp($agreement['agreed_amount']) ?></dd></div><div><dt>Paid</dt><dd><?= gbp($agreement['total_paid']) ?></dd></div><div><dt>Outstanding</dt><dd><?= gbp($agreement['balance']) ?></dd></div></dl>
                <div class="sponsor-workspace__actions btn-group" role="group" aria-label="Actions for <?= h($agreement['package_name'] . ' – ' . $agreement['target_label']) ?>">
                    <?php if ($canManagePayments && empty($agreement['is_complimentary'])): ?><button type="button" class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#workspacePaymentModal" data-agreement-id="<?= $agreementId ?>" data-payment-action="add_payment" title="Record payment / mark paid" aria-label="Record payment or mark agreement paid"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i></button><?php endif; ?>
                    <?php if ($canManageAgreements): ?><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#workspaceAgreementModal" data-agreement-id="<?= $agreementId ?>" title="Edit agreement" aria-label="Edit agreement"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><button type="button" class="btn btn-outline-danger btn-sm workspace-delete-agreement" data-agreement-id="<?= $agreementId ?>" title="Delete agreement" aria-label="Delete agreement" data-workspace-confirm="Permanently delete this agreement and its entire payment history?"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button><?php endif; ?>
                </div>
            </div>
            <details class="sponsor-workspace__details"><summary>Payment history &amp; details <span class="text-muted">(<?= count($agreement['payments']) ?>)</span></summary>
                <?php if ($agreement['notes']): ?><p class="mt-3 mb-2"><?= nl2br(h($agreement['notes'])) ?></p><?php endif; ?>
                <?php if ($agreement['payments']): ?>
                <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Payments for <?= h($agreement['package_name'] . ' – ' . $agreement['target_label']) ?></caption><thead><tr><th>Date</th><th>Amount</th><th>Method / note</th><th class="text-end">Actions</th></tr></thead><tbody>
                <?php foreach ($agreement['payments'] as $payment): ?>
                    <tr><td class="text-nowrap"><?= h(sponsorProfileDate($payment['paid_at'], 'Not recorded')) ?></td><td class="text-nowrap"><?= gbp($payment['amount']) ?></td><td><?= h($payment['method'] ?: 'Not recorded') ?><?php if ($payment['note']): ?><div class="small text-muted"><?= h($payment['note']) ?></div><?php endif; ?></td><td class="text-end">
                        <?php if ($canManagePayments): ?><div class="d-flex justify-content-end gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#workspacePaymentModal" data-agreement-id="<?= $agreementId ?>" data-payment-id="<?= (int)$payment['id'] ?>" data-payment-action="edit_payment" title="Edit payment" aria-label="Edit payment"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><form method="post" action="sponsor.php?id=<?= $id ?>" class="workspace-confirm-form"><?= csrf_field() ?><input type="hidden" name="workspace_action" value="delete_payment"><input type="hidden" name="agreement_id" value="<?= $agreementId ?>"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Remove payment" aria-label="Remove payment" data-workspace-confirm="Remove this <?= h(gbp($payment['amount'])) ?> payment? The outstanding balance will increase."><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></form></div><?php endif; ?>
                    </td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php else: ?><p class="text-muted my-3">No payments recorded.</p><?php endif; ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <a class="small" href="sponsorship_agreement.php?id=<?= $agreementId ?>">Invoices, card payment links &amp; more</a>

                </div>
            </details>
        </article>
    <?php endforeach; ?>
    </div>
    <div id="workspaceEmpty" class="text-center text-muted border rounded p-4" <?= $workspaceRows ? 'hidden' : '' ?>>No agreements to show. Add an agreement or change the filters.</div>
</div>

<?php if ($canManageAgreements): ?>
<div class="modal fade" id="workspaceAgreementModal" tabindex="-1" aria-labelledby="workspaceAgreementTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
<form method="post" action="sponsor.php?id=<?= $id ?>" id="workspaceAgreementForm">
    <?= csrf_field() ?><input type="hidden" name="workspace_action" value="save_agreement"><input type="hidden" name="agreement_id" value="0">
    <div class="modal-header"><h2 class="modal-title fs-5" id="workspaceAgreementTitle">Add agreement</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="workspace-form-error alert alert-danger" hidden></div><div class="row g-3">
        <div class="col-md-7"><label class="form-label" for="workspacePackage">Package</label><select name="package_id" id="workspacePackage" class="form-select" required><option value="">Choose a package</option><?php foreach ($workspacePackages as $p): ?><option value="<?= (int)$p['id'] ?>" data-scope="<?= h($p['scope']) ?>" data-amount="<?= h((string)$p['amount']) ?>" data-active="<?= (int)$p['is_active'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label" for="workspaceAgreementSeason">Season</label><select name="season_id" id="workspaceAgreementSeason" class="form-select"><option value="">No season</option><?php foreach ($workspaceSeasons as $s): ?><option value="<?= (int)$s['id'] ?>" data-start="<?= h((string)$s['start_date']) ?>" data-end="<?= h((string)$s['end_date']) ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12" data-scope-field="player" hidden><label class="form-label" for="workspacePlayer">Player</label><select name="player_id" id="workspacePlayer" class="form-select"><option value="">Choose a player</option><?php foreach ($workspacePlayers as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?><?= $p['active'] ? '' : ' (former player)' ?></option><?php endforeach; ?></select></div>
        <div class="col-12" data-scope-field="match" hidden><label class="form-label" for="workspaceFixture">Fixture</label><select name="fixture_id" id="workspaceFixture" class="form-select"><option value="">Choose a fixture</option><?php foreach ($workspaceFixtures as $f): ?><option value="<?= (int)$f['id'] ?>" data-season="<?= (int)$f['season_id'] ?>" data-date="<?= h($f['match_date']) ?>"><?= h(sponsorProfileDate($f['match_date'], '') . ' · vs ' . $f['opponent']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12" data-scope-field="team" hidden><label class="form-label" for="workspaceTeam">Team</label><select name="team_id" id="workspaceTeam" class="form-select"><option value="">Choose a team</option><?php foreach ($workspaceTeams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="workspaceValue">Agreed amount (£)</label><input type="number" name="agreed_amount" id="workspaceValue" class="form-control" min="0" max="99999999.99" step="0.01" required></div>
        <div class="col-md-6"><label class="form-label" for="workspaceAgreementStatus">Agreement status</label><select name="status" id="workspaceAgreementStatus" class="form-select"><option value="active">Active</option><option value="scheduled">Scheduled</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
        <div class="col-12" id="workspaceComplimentaryWrap" hidden><div class="form-check"><input type="checkbox" name="is_complimentary" value="1" id="workspaceComplimentary" class="form-check-input"><label for="workspaceComplimentary" class="form-check-label">Complimentary — no payment due</label></div></div>
        <div class="col-md-6"><label class="form-label" for="workspaceStart">Start date</label><input type="date" name="start_date" id="workspaceStart" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="workspaceEnd">End date</label><input type="date" name="end_date" id="workspaceEnd" class="form-control"></div>
        <div class="col-12"><label class="form-label" for="workspaceAgreementNotes">Notes <span class="text-muted">(optional)</span></label><textarea name="notes" id="workspaceAgreementNotes" class="form-control" rows="2"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand">Save agreement</button></div>
</form></div></div></div>
<?php endif; ?>
<?php if ($canManagePayments): ?>
<div class="modal fade" id="workspacePaymentModal" tabindex="-1" aria-labelledby="workspacePaymentTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form method="post" action="sponsor.php?id=<?= $id ?>" id="workspacePaymentForm">
    <?= csrf_field() ?><input type="hidden" name="workspace_action"><input type="hidden" name="agreement_id"><input type="hidden" name="payment_id">
    <div class="modal-header"><h2 class="modal-title fs-5" id="workspacePaymentTitle">Record payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="workspace-form-error alert alert-danger" hidden></div><p id="workspacePaymentLabel" class="fw-semibold"></p><p id="workspacePaymentHelp" class="small text-muted"></p><div class="row g-3">
        <div class="col-sm-6"><label for="workspacePaymentAmount" class="form-label">Amount (£)</label><input type="number" name="amount" id="workspacePaymentAmount" class="form-control" step="0.01" min="0.01" max="99999999.99" required></div>
        <div class="col-sm-6"><label for="workspacePaymentDate" class="form-label">Date paid</label><input type="date" name="paid_at" id="workspacePaymentDate" class="form-control" required></div>
        <div class="col-12"><label for="workspacePaymentMethod" class="form-label">Payment method</label><input name="method" id="workspacePaymentMethod" class="form-control" maxlength="50" list="workspacePaymentMethods" placeholder="Choose or enter a method"><datalist id="workspacePaymentMethods"><option value="Bank transfer"><option value="Cash"><option value="Card"><option value="Cheque"><option value="Other"></datalist></div>
        <div class="col-12"><label for="workspacePaymentNote" class="form-label">Note <span class="text-muted">(optional)</span></label><textarea name="note" id="workspacePaymentNote" class="form-control" rows="2" maxlength="255"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand" id="workspacePaymentSubmit">Save payment</button></div>
</form></div></div></div>
<?php endif; ?>
<script type="application/json" id="workspaceData"><?= json_encode(['agreements' => $workspaceRows, 'csrf' => (string)($_SESSION['csrf_token'] ?? ''), 'seasonId' => $seasonId, 'today' => date('Y-m-d'), 'error' => $workspaceError ?? '', 'submitted' => $workspaceError ? $_POST : null], $workspaceJsonFlags) ?></script>
<script src="/admin/assets/js/sponsor-workspace.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/sponsor-workspace.js') ?>" defer></script>
