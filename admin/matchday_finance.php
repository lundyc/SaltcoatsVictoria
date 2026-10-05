<?php
declare(strict_types=1);

// matchday_finance.php — Matchday balance sheets for home games: a season
// list of home fixtures with their recorded income / outgoings / net, and a
// season roll-up. Lives under Match Day; also linked from Finance.

$pageHero = [
    'eyebrow' => 'Match Day',
    'title' => 'Matchday Income',
    'subtitle' => 'Record the balance sheet for each home game at Campbell Park — floats, income, outgoings and sign-off.',
    'actions' => [],
];
$pageStyles = ['matchday-finance.css'];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/matchday_finance.php';

// Delegable to the treasurer (finance) or the match secretary (matchday);
// admins always pass. header.php already denies volunteers/staff who hold
// neither capability, but keep an explicit guard here too.
if (!hub_auth_is_admin() && !hub_auth_has_any_capability(['finance_view', 'matchday'])) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view this page.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$seasonName = (string) ($season['name'] ?? 'Selected season');

$rows = matchday_finance_list_for_season($pdo, $seasonId);
$summary = matchday_finance_season_summary($rows);

$statusMeta = static function (array $row): array {
    if (empty($row['finance_id'])) {
        return ['label' => 'Not recorded', 'tone' => 'secondary'];
    }
    if ((string) $row['finance_status'] === 'final') {
        return ['label' => 'Final', 'tone' => 'success'];
    }
    return ['label' => 'Draft', 'tone' => 'warning'];
};

$fmtKickoff = static function (?string $time): string {
    $time = trim((string) $time);
    if ($time === '' || $time === '00:00:00') {
        return '';
    }
    $ts = strtotime($time);
    return $ts ? date('H:i', $ts) : '';
};
?>

<div class="matchday-finance-page">
    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>Matchday balance sheet saved.</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php hub_render_metric_grid([
        [
            'label' => 'Home games recorded',
            'value' => $summary['recorded'] . ' of ' . $summary['home_games'],
            'meta' => $summary['final'] > 0 ? $summary['final'] . ' finalised' : 'Season ' . $seasonName,
            'icon' => 'fa-clipboard-check',
            'tone' => 'primary',
        ],
        [
            'label' => 'Matchday income',
            'value' => gbp($summary['income']),
            'meta' => 'Recorded home games',
            'icon' => 'fa-sterling-sign',
            'tone' => 'success',
        ],
        [
            'label' => 'Matchday outgoings',
            'value' => gbp($summary['expenses']),
            'meta' => 'Recorded home games',
            'icon' => 'fa-receipt',
            'tone' => 'warning',
        ],
        [
            'label' => 'Net matchday result',
            'value' => gbp($summary['net']),
            'meta' => $summary['net'] >= 0 ? 'Surplus' : 'Shortfall',
            'icon' => $summary['net'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
            'tone' => $summary['net'] >= 0 ? 'success' : 'danger',
        ],
    ], 'Season matchday finance summary'); ?>

    <section class="hub-section" aria-labelledby="matchdayFinanceListTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Home fixtures &middot; <?= h($seasonName) ?></div>
                <h2 id="matchdayFinanceListTitle">Balance sheets</h2>
                <p>One sheet per home game. Cash floats and reconciliation are on each sheet; the paper form stays valid as a backup.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($summary['recorded'] > 0): ?>
                    <a href="matchday_finance_export.php?season_id=<?= (int) $seasonId ?>" class="btn btn-outline-secondary venues-directory__add">
                        <i class="fa-solid fa-file-csv" aria-hidden="true"></i>
                        <span>Export CSV</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-house-circle-xmark" aria-hidden="true"></i>
                <h3>No home fixtures in <?= h($seasonName) ?></h3>
                <p>Add home fixtures under Match day, then record a balance sheet for each.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0 matchday-finance-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Opponent</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Attendance</th>
                            <th scope="col" class="text-end">Income</th>
                            <th scope="col" class="text-end">Outgoings</th>
                            <th scope="col" class="text-end">Net</th>
                            <th scope="col" class="text-end">Sheet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $recorded = !empty($row['finance_id']);
                            $meta = $statusMeta($row);
                            $totals = matchday_finance_totals($row);
                            $kickoff = $fmtKickoff($row['kickoff_time'] ?? null);
                            $editHref = 'matchday_finance_edit.php?fixture_id=' . (int) $row['fixture_id'];
                            ?>
                            <tr>
                                <td>
                                    <?= h(date('D d M Y', strtotime((string) $row['match_date']))) ?>
                                    <?php if ($kickoff !== ''): ?>
                                        <span class="matchday-finance-table__sub"><?= h($kickoff) ?> KO</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h((string) $row['opponent']) ?>
                                    <?php if (trim((string) ($row['competition'] ?? '')) !== ''): ?>
                                        <span class="matchday-finance-table__sub"><?= h((string) $row['competition']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-<?= h($meta['tone']) ?>"><?= h($meta['label']) ?></span></td>
                                <td class="text-end"><?= $recorded && $row['attendance'] !== null ? number_format((int) $row['attendance']) : '<span class="venues-muted">&mdash;</span>' ?></td>
                                <td class="text-end"><?= $recorded ? h(gbp($totals['income'])) : '<span class="venues-muted">&mdash;</span>' ?></td>
                                <td class="text-end"><?= $recorded ? h(gbp($totals['expenses'])) : '<span class="venues-muted">&mdash;</span>' ?></td>
                                <td class="text-end">
                                    <?php if ($recorded): ?>
                                        <span class="matchday-finance-net matchday-finance-net--<?= $totals['net'] >= 0 ? 'pos' : 'neg' ?>"><?= h(gbp($totals['net'])) ?></span>
                                    <?php else: ?>
                                        <span class="venues-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= h($editHref) ?>" class="btn btn-sm <?= $recorded ? 'btn-outline-primary' : 'btn-brand' ?>">
                                        <i class="fa-solid <?= $recorded ? 'fa-pen' : 'fa-plus' ?>" aria-hidden="true"></i>
                                        <span><?= $recorded ? 'Edit' : 'Record' ?></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
