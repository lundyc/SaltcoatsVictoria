<?php

declare(strict_types=1);

// matchday_finance_export.php — CSV of the season's recorded matchday
// balance sheets (one row per recorded home game). Standalone so it doesn't
// fight the page shell's output buffer.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/matchday_finance.php';

if (!hub_auth_is_authenticated()) {
    header('Location: login.php');
    exit;
}
if (!hub_auth_is_admin() && !hub_auth_has_any_capability(['finance_view', 'matchday'])) {
    http_response_code(403);
    exit('Access denied.');
}

$seasonId = (int) ($_GET['season_id'] ?? 0);
if ($seasonId <= 0) {
    $seasonId = getSelectedSeasonId($pdo);
}
$season = getSeasonById($pdo, $seasonId);
$seasonName = (string) ($season['name'] ?? ('season-' . $seasonId));

$rows = matchday_finance_list_for_season($pdo, $seasonId);
$incomeFields = matchday_finance_income_fields();
$expenseFields = matchday_finance_expense_fields();
$cashAreas = matchday_finance_cash_areas();

$header = ['Date', 'Opponent', 'Competition', 'Recorded', 'Status', 'Attendance'];
foreach ($cashAreas as $area) {
    $header[] = $area['label'] . ' float';
    $header[] = $area['label'] . ' counted';
    $header[] = $area['label'] . ' takings';
}
$header[] = 'Total takings';
foreach ($incomeFields as $label) {
    $header[] = $label;
}
$header[] = 'Total income';
foreach ($expenseFields as $label) {
    $header[] = $label;
}
$header[] = 'Total outgoings';
$header[] = 'Net';
$header[] = 'Completed by';
$header[] = 'Checked by';

$filename = 'matchday-finance-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($seasonName)) . '-' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $header, ',', '"', '');

$money = static fn (float $n): string => number_format($n, 2, '.', '');

foreach ($rows as $row) {
    $recorded = !empty($row['finance_id']);
    $totals = matchday_finance_totals($row);

    $line = [
        date('Y-m-d', strtotime((string) $row['match_date'])),
        (string) $row['opponent'],
        (string) ($row['competition'] ?? ''),
        $recorded ? 'Yes' : 'No',
        $recorded ? ucfirst((string) $row['finance_status']) : '',
        $recorded && $row['attendance'] !== null ? (string) (int) $row['attendance'] : '',
    ];
    foreach ($cashAreas as $key => $area) {
        $line[] = $money((float) ($row[$area['float']] ?? 0));
        $line[] = $money((float) ($row[$area['close']] ?? 0));
        $line[] = $money($totals['takings_by_area'][$key]['takings']);
    }
    $line[] = $money($totals['takings_total']);
    foreach (array_keys($incomeFields) as $column) {
        $line[] = $money((float) ($row[$column] ?? 0));
    }
    $line[] = $money($totals['income']);
    foreach (array_keys($expenseFields) as $column) {
        $line[] = $money((float) ($row[$column] ?? 0));
    }
    $line[] = $money($totals['expenses']);
    $line[] = $money($totals['net']);
    $line[] = (string) ($row['completed_by'] ?? '');
    $line[] = (string) ($row['checked_by'] ?? '');

    fputcsv($out, $line, ',', '"', '');
}

fclose($out);
exit;
