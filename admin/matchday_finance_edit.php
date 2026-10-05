<?php
declare(strict_types=1);

// matchday_finance_edit.php — the matchday balance sheet for one home
// fixture. You enter each cash point's starting float and the cash counted
// at close (takings = the difference), plus any non-till income and any
// outgoings; the totals are worked out for you.

$pageHero = [
    'eyebrow' => 'Match Day',
    'title' => 'Matchday balance sheet',
    'subtitle' => 'Floats in, cash counted, any outgoings — the totals work themselves out.',
    'actions' => [],
];
$pageStyles = ['matchday-finance.css'];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/matchday_finance.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_admin() && !hub_auth_has_any_capability(['finance_manage', 'matchday'])) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view this page.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$fixtureId = (int) ($_GET['fixture_id'] ?? 0);

$fixtureStmt = $pdo->prepare(
    "SELECT f.id, f.season_id, f.match_date, f.kickoff_time, f.competition, f.venue, f.is_home, f.status,
            COALESCE(o.clubname, f.opponent) AS opponent
     FROM match_fixtures f
     LEFT JOIN match_opponents o ON o.id = f.opponent_id
     WHERE f.id = :id"
);
$fixtureStmt->execute([':id' => $fixtureId]);
$fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);

if (!$fixture) {
    echo '<div class="matchday-finance-page"><nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="matchday_finance.php">Matchday Income</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Not found</span></nav>'
        . '<div class="alert alert-danger">That fixture could not be found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$existing = matchday_finance_get($pdo, $fixtureId);
$form = $existing ?? matchday_finance_blank();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            matchday_finance_save($pdo, $fixtureId, (int) $fixture['season_id'], $_POST, $userId);
            auditLog(
                $pdo,
                $existing ? 'matchday_finance_updated' : 'matchday_finance_created',
                'Matchday balance sheet for fixture #' . $fixtureId . ' (' . (string) $fixture['opponent'] . ', ' . (string) $fixture['match_date'] . ')'
            );
            header('Location: matchday_finance_edit.php?fixture_id=' . $fixtureId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Keep whatever was typed in front of the user on a failed save.
    $form = array_merge($form, $_POST);
}

$incomeFields = matchday_finance_income_fields();
$expenseFields = matchday_finance_expense_fields();
$cashAreas = matchday_finance_cash_areas();
$totals = matchday_finance_totals($form);

$matchDateLabel = date('D d M Y', strtotime((string) $fixture['match_date']));
$kickoff = trim((string) $fixture['kickoff_time']);
$kickoffLabel = ($kickoff !== '' && $kickoff !== '00:00:00' && strtotime($kickoff)) ? date('H:i', strtotime($kickoff)) : '';
$isHome = (int) $fixture['is_home'] === 1;

$moneyValue = static function (array $form, string $column): string {
    $value = (float) ($form[$column] ?? 0);
    return $value != 0.0 ? number_format($value, 2, '.', '') : '';
};

/** One "£ ____" number input bound to the live-totals script. */
$moneyInput = static function (string $column, string $group, string $display, array $data = []): string {
    $attrs = '';
    foreach ($data as $key => $val) {
        $attrs .= ' data-mf-' . $key . '="' . h((string) $val) . '"';
    }
    return '<div class="input-group input-group-sm mf-money">'
        . '<span class="input-group-text">&pound;</span>'
        . '<input type="number" inputmode="decimal" step="0.01" min="0" class="form-control text-end"'
        . ' name="' . h($column) . '" id="mf_' . h($column) . '" value="' . h($display) . '"'
        . ' data-mf-money data-mf-group="' . h($group) . '"' . $attrs . '>'
        . '</div>';
};
?>

<div class="matchday-finance-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb">
        <a href="matchday_finance.php">Matchday Income</a>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        <span aria-current="page"><?= h((string) $fixture['opponent']) ?> &middot; <?= h($matchDateLabel) ?></span>
    </nav>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>Matchday balance sheet saved.</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This balance sheet could not be saved.</div>
                <ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="mf-fixture">
        <div class="mf-fixture__main">
            <span class="mf-fixture__eyebrow"><?= $isHome ? 'Home fixture' : 'Fixture' ?></span>
            <h2 class="mf-fixture__title">Saltcoats Victoria <span>v</span> <?= h((string) $fixture['opponent']) ?></h2>
            <p class="mf-fixture__meta">
                <span><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> <?= h($matchDateLabel) ?></span>
                <?php if ($kickoffLabel !== ''): ?><span><i class="fa-solid fa-clock" aria-hidden="true"></i> <?= h($kickoffLabel) ?> KO</span><?php endif; ?>
                <?php if (trim((string) ($fixture['competition'] ?? '')) !== ''): ?><span><i class="fa-solid fa-trophy" aria-hidden="true"></i> <?= h((string) $fixture['competition']) ?></span><?php endif; ?>
                <?php if (trim((string) ($fixture['venue'] ?? '')) !== ''): ?><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= h((string) $fixture['venue']) ?></span><?php endif; ?>
            </p>
        </div>
        <?php if ($existing): ?>
            <div class="mf-fixture__status">
                <span class="badge text-bg-<?= (string) $form['status'] === 'final' ? 'success' : 'warning' ?>"><?= (string) $form['status'] === 'final' ? 'Final' : 'Draft' ?></span>
                <?php if (!empty($existing['updated_at'])): ?>
                    <span class="mf-fixture__saved">Saved <?= h(date('d M Y H:i', strtotime((string) $existing['updated_at']))) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isHome): ?>
        <div class="alert alert-info" role="alert">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            This isn't marked as a home fixture. You can still record a balance sheet if the club hosted or ran the gate for it.
        </div>
    <?php endif; ?>

    <form method="post" action="matchday_finance_edit.php?fixture_id=<?= (int) $fixtureId ?>" class="mf-form" id="matchdayFinanceForm">
        <?= csrf_field() ?>

        <section class="hub-section mf-card" aria-labelledby="mfTakingsTitle">
            <div class="mf-card__head">
                <h3 id="mfTakingsTitle">Takings on the day</h3>
                <span class="mf-card__total">Total takings <strong id="mfTakingsTotal"><?= h(gbp($totals['takings_total'])) ?></strong></span>
                <span class="mf-card__hint">For each point, enter the float you started with and the cash counted at the end. <strong>Takings = counted &minus; float.</strong></span>
            </div>
            <div class="table-responsive">
                <table class="table mf-recon align-middle mb-0 hub-data-table">
                    <thead>
                        <tr>
                            <th scope="col">Cash point</th>
                            <th scope="col" class="text-end">Starting float</th>
                            <th scope="col" class="text-end">Counted at close</th>
                            <th scope="col" class="text-end">Takings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashAreas as $key => $area): ?>
                            <?php $areaTotals = $totals['takings_by_area'][$key]; ?>
                            <tr>
                                <th scope="row"><?= h($area['label']) ?></th>
                                <td class="text-end"><?= $moneyInput($area['float'], 'cash', $moneyValue($form, $area['float']), ['cash-float' => $key]) ?></td>
                                <td class="text-end"><?= $moneyInput($area['close'], 'cash', $moneyValue($form, $area['close']), ['cash-close' => $key]) ?></td>
                                <td class="text-end"><span class="mf-recon__derived" data-mf-takings="<?= h($key) ?>"><?= h(gbp($areaTotals['takings'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">Total</th>
                            <td class="text-end"><span class="mf-recon__derived" id="mfFloatTotal"><?= h(gbp($totals['float_total'])) ?></span></td>
                            <td class="text-end"><span class="mf-recon__derived" id="mfCloseTotal"><?= h(gbp($totals['close_total'])) ?></span></td>
                            <td class="text-end"><span class="mf-recon__derived" id="mfTakingsTotalFoot"><?= h(gbp($totals['takings_total'])) ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="mf-grid">
            <section class="hub-section mf-card" aria-labelledby="mfIncomeTitle">
                <div class="mf-card__head">
                    <h3 id="mfIncomeTitle">Other income</h3>
                    <span class="mf-card__total">Subtotal <strong id="mfOtherIncomeSubtotal"><?= h(gbp($totals['other_income'])) ?></strong></span>
                    <span class="mf-card__hint">Money not counted as cash in the tills above. <strong>Card sales</strong> is the one combined total from the card reader; then sponsorship, raffle, donations.</span>
                </div>
                <div class="mf-lines">
                    <?php foreach ($incomeFields as $column => $label): ?>
                        <div class="mf-line">
                            <label class="mf-line__label" for="mf_<?= h($column) ?>"><?= h($label) ?></label>
                            <?= $moneyInput($column, 'income', $moneyValue($form, $column)) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="hub-section mf-card" aria-labelledby="mfExpenseTitle">
                <div class="mf-card__head">
                    <h3 id="mfExpenseTitle">Outgoings</h3>
                    <span class="mf-card__total">Total outgoings <strong id="mfExpenseSubtotal"><?= h(gbp($totals['expenses'])) ?></strong></span>
                    <span class="mf-card__hint">Anything paid out on the day &mdash; leave blank if not applicable.</span>
                </div>
                <div class="mf-lines">
                    <?php foreach ($expenseFields as $column => $label): ?>
                        <div class="mf-line">
                            <label class="mf-line__label" for="mf_<?= h($column) ?>"><?= h($label) ?></label>
                            <?= $moneyInput($column, 'expense', $moneyValue($form, $column)) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="hub-section mf-card" aria-labelledby="mfBalanceTitle">
            <h3 id="mfBalanceTitle" class="mf-card__head-plain">Matchday balance</h3>
            <div class="mf-balance">
                <div class="mf-balance__item">
                    <span class="mf-balance__label">Total income</span>
                    <span class="mf-balance__value" id="mfBalanceIncome"><?= h(gbp($totals['income'])) ?></span>
                    <span class="mf-balance__hint" id="mfBalanceIncomeBreakdown">Takings <?= h(gbp($totals['takings_total'])) ?> &middot; other <?= h(gbp($totals['other_income'])) ?></span>
                </div>
                <div class="mf-balance__item">
                    <span class="mf-balance__label">Total outgoings</span>
                    <span class="mf-balance__value" id="mfBalanceExpense"><?= h(gbp($totals['expenses'])) ?></span>
                </div>
                <div class="mf-balance__item mf-balance__item--net">
                    <span class="mf-balance__label">Matchday profit / (loss)</span>
                    <span class="mf-balance__value matchday-finance-net matchday-finance-net--<?= $totals['net'] >= 0 ? 'pos' : 'neg' ?>" id="mfBalanceNet"><?= h(gbp($totals['net'])) ?></span>
                </div>
            </div>
        </section>

        <section class="hub-section mf-card" aria-labelledby="mfSignoffTitle">
            <h3 id="mfSignoffTitle" class="mf-card__head-plain">Attendance &amp; sign-off</h3>
            <div class="row g-3">
                <div class="col-sm-4 col-lg-3">
                    <label for="mf_attendance" class="form-label">Attendance</label>
                    <input type="number" min="0" step="1" inputmode="numeric" class="form-control" id="mf_attendance" name="attendance"
                        value="<?= h(($form['attendance'] ?? '') === '' || $form['attendance'] === null ? '' : (string) (int) $form['attendance']) ?>">
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label for="mf_completed_by" class="form-label">Completed by</label>
                    <input type="text" maxlength="120" class="form-control" id="mf_completed_by" name="completed_by" value="<?= h((string) ($form['completed_by'] ?? '')) ?>">
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label for="mf_checked_by" class="form-label">Checked by</label>
                    <input type="text" maxlength="120" class="form-control" id="mf_checked_by" name="checked_by" value="<?= h((string) ($form['checked_by'] ?? '')) ?>">
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label for="mf_status" class="form-label">Status</label>
                    <select class="form-select" id="mf_status" name="status">
                        <option value="draft" <?= (string) ($form['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="final" <?= (string) ($form['status'] ?? 'draft') === 'final' ? 'selected' : '' ?>>Final</option>
                    </select>
                </div>
                <div class="col-12">
                    <label for="mf_notes" class="form-label">Notes</label>
                    <textarea class="form-control" id="mf_notes" name="notes" rows="3" placeholder="Anything worth recording for the treasurer — a count that looked off, a cash payment still to bank, etc."><?= h((string) ($form['notes'] ?? '')) ?></textarea>
                </div>
            </div>
        </section>

        <div class="mf-actions">
            <a href="matchday_finance.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-brand">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <?= $existing ? 'Save changes' : 'Save balance sheet' ?>
            </button>
        </div>
    </form>
</div>

<script>
    (function () {
        var form = document.getElementById('matchdayFinanceForm');
        if (!form) { return; }

        var AREAS = <?= json_encode(array_keys($cashAreas), JSON_UNESCAPED_SLASHES) ?>;

        function parseMoney(value) {
            var n = parseFloat(String(value == null ? '' : value).replace(/[£,\s]/g, ''));
            return isFinite(n) ? n : 0;
        }
        function money(n) {
            var sign = n < 0 ? '-' : '';
            return sign + '£' + Math.abs(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function sumGroup(group) {
            var total = 0;
            form.querySelectorAll('[data-mf-money][data-mf-group="' + group + '"]').forEach(function (el) {
                total += parseMoney(el.value);
            });
            return total;
        }
        function setText(id, text) {
            var el = document.getElementById(id);
            if (el) { el.textContent = text; }
        }
        function setNet(el, value) {
            if (!el) { return; }
            el.textContent = money(value);
            el.classList.toggle('matchday-finance-net--pos', value >= 0);
            el.classList.toggle('matchday-finance-net--neg', value < 0);
        }

        function recalc() {
            var otherIncome = sumGroup('income');
            var expense = sumGroup('expense');

            var floatTotal = 0, closeTotal = 0, takingsTotal = 0;
            AREAS.forEach(function (area) {
                var floatEl = form.querySelector('[data-mf-cash-float="' + area + '"]');
                var closeEl = form.querySelector('[data-mf-cash-close="' + area + '"]');
                var floatVal = parseMoney(floatEl && floatEl.value);
                var closeVal = parseMoney(closeEl && closeEl.value);
                var takings = closeVal - floatVal;

                var takingsEl = form.querySelector('[data-mf-takings="' + area + '"]');
                if (takingsEl) { takingsEl.textContent = money(takings); }

                floatTotal += floatVal;
                closeTotal += closeVal;
                takingsTotal += takings;
            });

            var income = takingsTotal + otherIncome;

            setText('mfFloatTotal', money(floatTotal));
            setText('mfCloseTotal', money(closeTotal));
            setText('mfTakingsTotal', money(takingsTotal));
            setText('mfTakingsTotalFoot', money(takingsTotal));
            setText('mfOtherIncomeSubtotal', money(otherIncome));
            setText('mfExpenseSubtotal', money(expense));
            setText('mfBalanceIncome', money(income));
            setText('mfBalanceExpense', money(expense));
            setText('mfBalanceIncomeBreakdown', 'Takings ' + money(takingsTotal) + ' · other ' + money(otherIncome));
            setNet(document.getElementById('mfBalanceNet'), income - expense);
        }

        form.addEventListener('input', function (ev) {
            if (ev.target && ev.target.matches('[data-mf-money]')) { recalc(); }
        });
        recalc();
    })();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
