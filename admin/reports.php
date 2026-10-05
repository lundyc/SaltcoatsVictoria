<?php
declare(strict_types=1);

// reports.php - Dynamic, exportable reporting workspace for the whole club (not just player sponsorships).
$pageHero = [
    'eyebrow' => 'Reporting',
    'title' => 'Reports',
    'subtitle' => 'Build the report you need, filter it your way, then export it.',
    'actions' => [],
];
$pageStyles = ['reports.css'];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/player_match_stats.php';
require_once __DIR__ . '/lib/stats.php';

ensureSponsorshipCatalogSchema($pdo);
syncLegacySponsorshipAgreements($pdo);
ensureSeasonPassSchema($pdo);

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$seasonName = (string)($season['name'] ?? 'Selected season');
$today = date('Y-m-d');

/* =========================================================================
   Small formatting + labelling helpers
   ========================================================================= */

function reportDate(?string $date, bool $includeTime = false): string
{
    if (!$date) {
        return '-';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    return date($includeTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}

function reportScopeLabel(string $scope): string
{
    return match ($scope) {
        'club' => 'Club',
        'digital' => 'Digital',
        'team' => 'Team',
        'match' => 'Match',
        'player' => 'Player',
        default => $scope === '' ? '-' : ucfirst($scope),
    };
}

/** Icon + colour-tag metadata for the five sponsorship scopes. */
function reportScopeMeta(string $scope): array
{
    return match ($scope) {
        'club' => ['label' => 'Club', 'icon' => 'fa-shield-halved', 'key' => 'club'],
        'digital' => ['label' => 'Digital', 'icon' => 'fa-globe', 'key' => 'digital'],
        'team' => ['label' => 'Team', 'icon' => 'fa-people-group', 'key' => 'team'],
        'match' => ['label' => 'Match', 'icon' => 'fa-futbol', 'key' => 'match'],
        'player' => ['label' => 'Player', 'icon' => 'fa-user', 'key' => 'player'],
        default => ['label' => reportScopeLabel($scope), 'icon' => 'fa-tag', 'key' => 'neutral'],
    };
}

function reportPaymentState(float $due, float $paid, bool $complimentary = false): array
{
    if ($complimentary) {
        return ['label' => 'Complimentary', 'tone' => 'info'];
    }
    if ($due <= 0.0001) {
        return ['label' => 'No charge', 'tone' => 'neutral'];
    }
    if ($paid >= $due - 0.0001) {
        return ['label' => 'Paid', 'tone' => 'success'];
    }
    if ($paid > 0.0001) {
        return ['label' => 'Part paid', 'tone' => 'warning'];
    }
    return ['label' => 'Unpaid', 'tone' => 'danger'];
}

/** Formats a KPI's change vs one specific comparison season, e.g. "+£250.00 (+64.1%)". */
function reportKpiDeltaMeta(float $delta, float $pct, bool $isMoney): string
{
    if (abs($delta) < 0.005) {
        return 'No change';
    }
    $sign = $delta > 0 ? '+' : '-';
    $value = $isMoney ? gbp(abs($delta)) : number_format(abs($delta));
    return $sign . $value . ' (' . $sign . number_format(abs($pct), 1) . '%)';
}

/** Maps a known "state-ish" column + its display value to a colour tone. Returns null for plain text columns. */
function reportCellTone(string $column, string $value): ?string
{
    return match ($column) {
        'Payment' => match ($value) {
            'Paid' => 'success',
            'Part paid' => 'warning',
            'Complimentary' => 'info',
            'No charge' => 'neutral',
            'Unpaid' => 'danger',
            default => 'neutral',
        },
        'Status', 'Sponsor Status', 'Order Status', 'Payment Status', 'Ticket Status', 'Credential' => match ($value) {
            'Active' => 'success',
            'Paid' => 'success',
            'active' => 'success',
            'paid' => 'success',
            'Active credential' => 'success',
            'Scheduled' => 'info',
            'Pending' => 'warning',
            'pending' => 'warning',
            'pending_payment' => 'warning',
            'Expired' => 'neutral',
            'Cancelled' => 'danger',
            'cancelled' => 'danger',
            'refunded' => 'danger',
            'Inactive credential' => 'neutral',
            'Inactive' => 'neutral',
            default => 'neutral',
        },
        'Player Status' => match (strtolower($value)) {
            'current' => 'success',
            'trialist', 'loan' => 'info',
            'injured' => 'warning',
            'left', 'retired' => 'neutral',
            default => 'neutral',
        },
        'Urgency' => match ($value) {
            'Overdue' => 'danger',
            'Due soon' => 'warning',
            'Upcoming' => 'info',
            default => 'neutral',
        },
        'Coverage' => match (true) {
            str_starts_with($value, 'Fully') => 'success',
            str_starts_with($value, 'Partially') => 'warning',
            str_starts_with($value, 'Not') || str_starts_with($value, 'Needs') => 'danger',
            default => 'neutral',
        },
        'Result' => match ($value) {
            'Win' => 'success',
            'Draw' => 'warning',
            'Loss' => 'danger',
            default => 'neutral',
        },
        'Event' => match ($value) {
            'Goal', 'Goal (OG)' => 'success',
            'Yellow Card' => 'warning',
            'Red Card' => 'danger',
            default => 'neutral',
        },
        'Fill Status' => match ($value) {
            'Filled', 'In use' => 'success',
            'Needs sponsor' => 'danger',
            'Unused' => 'neutral',
            default => 'neutral',
        },
        'Fixture Status' => match ($value) {
            'Played' => 'success',
            'Postponed', 'Delayed' => 'warning',
            'Cancelled' => 'danger',
            default => 'info',
        },
        default => null,
    };
}

const REPORT_MONEY_COLUMNS = ['Value', 'Paid', 'Outstanding', 'Amount', 'Lifetime Value', 'Gifted Value', 'List Value', 'Avg Payment', 'Gross Income', 'Income Change', 'Average Ticket Value'];
const REPORT_COUNT_COLUMNS = ['Sponsorships', 'Agreements', 'Transactions', 'Active Agreements', 'Lifetime Agreements', 'Days Remaining', 'Fixtures', 'Tickets', 'Tickets Change', 'Active Tickets', 'Paid Orders', 'Complimentary', 'Cancelled/Refunded', 'Unique Holders', 'New Holders', 'Renewed Holders', 'Lapsed From Previous', 'Apps', 'Starts', 'Sub Apps', 'Minutes', 'Goals', 'Yellow Cards', 'Red Cards', 'Clean Sheets', 'Played', 'Won', 'Drawn', 'Lost', 'GF', 'GA', 'GD', 'Points'];
const REPORT_PCT_COLUMNS = ['% Paid', 'Share %', 'Ticket Growth %', 'Income Growth %', 'Renewal Rate %'];
const REPORT_SCOPE_KEYS = ['club', 'digital', 'team', 'match', 'player'];

/**
 * Hand-curated row layout for the Sponsor showcase's package sub-sections, keyed by
 * category name — a flat "one row, evenly divided" grid reads badly once a category has
 * many small package variants (Kit & Apparel has 11), so related packages are grouped
 * onto the same row instead (front/back-sleeve pairs, tracksuits with warm-up tops, the
 * two winter jackets together). Any package in the category not listed in any row here
 * still appears — grouped into one final row of its own — so a newly added package is
 * never silently dropped just because this list wasn't updated for it. Categories with
 * no entry here keep the default: every package in that category on one shared row.
 */
const SHOWCASE_PACKAGE_ROWS = [
    'Kit & Apparel' => [
        ['home_kit_sponsor', 'home_kit_sponsor_back_sleeve'],
        ['away_kit_sponsor', 'away_kit_sponsor_back_sleeve'],
        ['tracksuit_sponsor_players', 'tracksuit_sponsor_coaches', 'warmup_top_sponsor'],
        ['matchday_polo_shirt_sponsor', 'kit_bag_sponsor'],
        ['substitute_jacket_sponsor', 'waterproof_jacket_sponsor'],
    ],
];

/** Categories forced onto their own page(s) in the Sponsor showcase PDF export (a break
 *  before and after), rather than sharing a page with whatever comes immediately before
 *  or after them — currently just Ground Advertising, since its long sponsor lists made
 *  it spill awkwardly across the same page as neighbouring categories. */
const SHOWCASE_PDF_PAGE_BREAK_CATEGORIES = ['Ground Advertising'];

function reportExportValue(string $column, $value): string
{
    if ($column === 'Scope' && is_string($value) && in_array($value, REPORT_SCOPE_KEYS, true)) {
        return reportScopeLabel($value);
    }
    if (in_array($column, REPORT_MONEY_COLUMNS, true)) {
        return number_format((float)$value, 2, '.', '');
    }
    if (in_array($column, REPORT_PCT_COLUMNS, true)) {
        return number_format((float)$value, 1, '.', '');
    }
    if (in_array($column, REPORT_COUNT_COLUMNS, true)) {
        return (string)(int)$value;
    }
    return (string)$value;
}

function reportRenderCell(string $column, $value): string
{
    if ($column === 'Scope' && is_string($value) && in_array($value, REPORT_SCOPE_KEYS, true)) {
        $meta = reportScopeMeta($value);
        return '<span class="rpt-tag rpt-tag--' . h($meta['key']) . '"><i class="fa-solid ' . h($meta['icon']) . '" aria-hidden="true"></i>' . h($meta['label']) . '</span>';
    }
    if (in_array($column, REPORT_MONEY_COLUMNS, true)) {
        return '<span class="rpt-num">' . h(gbp((float)$value)) . '</span>';
    }
    if (in_array($column, REPORT_PCT_COLUMNS, true)) {
        return '<span class="rpt-num">' . number_format((float)$value, 0) . '%</span>';
    }
    if (in_array($column, REPORT_COUNT_COLUMNS, true)) {
        return '<span class="rpt-num">' . number_format((float)$value) . '</span>';
    }
    $string = (string)$value;
    if ($string === '') {
        return '<span class="rpt-muted">-</span>';
    }
    $tone = reportCellTone($column, $string);
    if ($tone !== null) {
        return '<span class="rpt-pill rpt-pill--' . h($tone) . '">' . h($string) . '</span>';
    }
    return h($string);
}

/* =========================================================================
   Portfolio roll-up (scope/category/sponsor/player totals from a set of agreements)
   ========================================================================= */

function buildPortfolioMetrics(array $agreements): array
{
    $totals = ['agreements' => 0, 'value' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'complimentary' => 0, 'sponsors' => []];
    $categoryTotals = [];
    $sponsorTotals = [];
    $playerTotals = [];

    foreach ($agreements as $agreement) {
        $due = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['agreed_amount'];
        $paid = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['total_paid'];
        $outstanding = max(0, $due - $paid);
        $scope = (string)$agreement['package_scope'];
        $category = (string)$agreement['package_category'];
        $categoryKey = $scope . '|' . $category;
        $sponsorId = (int)$agreement['sponsor_id'];

        $totals['agreements']++;
        $totals['value'] += $due;
        $totals['paid'] += $paid;
        $totals['outstanding'] += $outstanding;
        $totals['complimentary'] += !empty($agreement['is_complimentary']) ? 1 : 0;
        $totals['sponsors'][$sponsorId] = true;

        $categoryTotals[$categoryKey] ??= ['scope' => $scope, 'category' => $category, 'agreements' => 0, 'value' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
        $categoryTotals[$categoryKey]['agreements']++;
        $categoryTotals[$categoryKey]['value'] += $due;
        $categoryTotals[$categoryKey]['paid'] += $paid;
        $categoryTotals[$categoryKey]['outstanding'] += $outstanding;

        $sponsorTotals[$sponsorId] ??= ['id' => $sponsorId, 'name' => (string)$agreement['sponsor_name'], 'agreements' => 0, 'scopes' => [], 'value' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
        $sponsorTotals[$sponsorId]['agreements']++;
        $sponsorTotals[$sponsorId]['scopes'][$scope] = true;
        $sponsorTotals[$sponsorId]['value'] += $due;
        $sponsorTotals[$sponsorId]['paid'] += $paid;
        $sponsorTotals[$sponsorId]['outstanding'] += $outstanding;

        if ($scope === 'player' && !empty($agreement['player_id'])) {
            $playerId = (int)$agreement['player_id'];
            $playerTotals[$playerId] ??= ['id' => $playerId, 'name' => (string)($agreement['player_name'] ?? 'Player'), 'agreements' => 0, 'value' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
            $playerTotals[$playerId]['agreements']++;
            $playerTotals[$playerId]['value'] += $due;
            $playerTotals[$playerId]['paid'] += $paid;
            $playerTotals[$playerId]['outstanding'] += $outstanding;
        }
    }

    $totals['sponsors'] = count($totals['sponsors']);
    usort($categoryTotals, static fn(array $a, array $b): int => [$a['scope'], $a['category']] <=> [$b['scope'], $b['category']]);
    usort($sponsorTotals, static fn(array $a, array $b): int => $b['value'] <=> $a['value'] ?: strcasecmp($a['name'], $b['name']));
    usort($playerTotals, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return ['totals' => $totals, 'categoryTotals' => array_values($categoryTotals), 'sponsorTotals' => array_values($sponsorTotals), 'playerTotals' => array_values($playerTotals)];
}

/* =========================================================================
   Export (CSV / PDF) — shared by every report type
   ========================================================================= */

/**
 * PDF-safe markup for the Sponsor showcase's grouped card layout, mirroring the live
 * page's category → row → package → sponsor structure. Dompdf only supports CSS2.1 (no
 * flexbox/grid/custom properties), so each row is a real HTML table with one equal-width
 * <td> per package, rather than the CSS grid the live page uses for the same layout.
 */
function reportShowcasePdfHtml(array $groups): string
{
    $html = '';
    foreach ($groups as $group) {
        $scopeMeta = reportScopeMeta($group['scope']);
        $count = (int)$group['total_sponsors'];
        $categoryClass = 'showcase-category' . (in_array($group['name'], SHOWCASE_PDF_PAGE_BREAK_CATEGORIES, true) ? ' showcase-category--isolated' : '');
        $html .= '<div class="' . h($categoryClass) . '">';
        $html .= '<h2 class="showcase-category__title showcase-category__title--' . h($scopeMeta['key']) . '">'
            . h($group['name']) . ' <span class="showcase-category__count">' . $count . ' sponsor' . ($count === 1 ? '' : 's') . '</span></h2>';
        foreach ($group['rows'] as $row) {
            $width = number_format(100 / max(1, count($row)), 2, '.', '');
            $html .= '<table class="showcase-row"><tr>';
            foreach ($row as $package) {
                $html .= '<td style="width:' . $width . '%;">';
                $html .= '<div class="showcase-package__title">' . h($package['name']) . '</div>';
                $html .= '<ul class="showcase-package__list">';
                if (!$package['sponsors']) {
                    $html .= '<li class="showcase-package__empty">No sponsor yet</li>';
                } else {
                    foreach ($package['sponsors'] as $sponsor) {
                        $html .= '<li>' . h($sponsor['name']) . '</li>';
                    }
                }
                $html .= '</ul></td>';
            }
            $html .= '</tr></table>';
        }
        $html .= '</div>';
    }
    return $html;
}

function reportExport(array $rows, string $format, string $filename, string $title, string $subtitle, string $pdfOrientation = 'landscape', ?array $showcaseGroups = null): never
{
    if (ob_get_length() !== false) {
        ob_clean();
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        if ($rows) {
            fputcsv($output, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $row) {
                $line = [];
                foreach ($row as $column => $value) {
                    $line[] = reportExportValue((string)$column, $value);
                }
                fputcsv($output, $line, ',', '"', '');
            }
        }
        fclose($output);
        exit;
    }

    if ($format !== 'pdf') {
        http_response_code(400);
        exit('Invalid export format.');
    }

    // Dompdf has remote/file resource loading disabled by default, so a
    // <link> to an external stylesheet is silently ignored. Embed the CSS
    // directly instead — it's small and this keeps the PDF fully self-contained.
    $reportStylesheetCss = (string)@file_get_contents(__DIR__ . '/assets/css/reports-pdf.css');
    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $reportStylesheetCss . '</style></head><body>';
    $html .= '<div class="pdf-heading"><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p><p class="pdf-meta">Generated ' . date('d/m/Y H:i') . '</p></div>';

    if ($showcaseGroups !== null) {
        $html .= $showcaseGroups ? reportShowcasePdfHtml($showcaseGroups) : '<p>No sponsorship types selected.</p>';
        $html .= '</body></html>';
    } else {
        $html .= '<table><thead><tr>';

        if ($rows) {
            foreach (array_keys($rows[0]) as $column) {
                $html .= '<th>' . h((string)$column) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $column => $cell) {
                    $displayValue = reportExportValue((string)$column, $cell);
                    $tone = reportCellTone((string)$column, $displayValue);
                    if ($column === 'Scope' && is_string($cell) && in_array($cell, REPORT_SCOPE_KEYS, true)) {
                        $html .= '<td><span class="tag tag-' . h($cell) . '">' . h($displayValue) . '</span></td>';
                    } elseif ($tone !== null) {
                        $html .= '<td><span class="pill pill-' . h($tone) . '">' . h($displayValue) . '</span></td>';
                    } elseif (in_array((string)$column, REPORT_MONEY_COLUMNS, true)) {
                        $html .= '<td class="num">&pound;' . h($displayValue) . '</td>';
                    } else {
                        $html .= '<td>' . h($displayValue) . '</td>';
                    }
                }
                $html .= '</tr>';
            }
        } else {
            $html .= '<th>No data</th></tr></thead><tbody><tr><td>No records found.</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
    }

    if (!class_exists(Dompdf\Dompdf::class)) {
        $autoloaders = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/project_1/vendor/autoload.php'];
        foreach ($autoloaders as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;
                break;
            }
        }
    }
    if (!class_exists(Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF export is unavailable.');
    }

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', $pdfOrientation === 'portrait' ? 'portrait' : 'landscape');
    $dompdf->render();
    $dompdf->stream($filename . '.pdf');
    exit;
}

/* =========================================================================
   Core data load: sponsorship agreements + payments for the selected season
   ========================================================================= */

$rawAgreements = getSponsorshipAgreements($pdo, ['season_id' => $seasonId]);

// Legacy match records marked as deleted are not part of the current portfolio.
$deletedMatchIds = [];
$deletedMatchStmt = $pdo->prepare("SELECT id FROM match_sponsorships WHERE season_id = :season_id AND ended_at IS NOT NULL AND ended_reason = 'deleted'");
$deletedMatchStmt->execute([':season_id' => $seasonId]);
foreach ($deletedMatchStmt->fetchAll(PDO::FETCH_COLUMN) as $deletedMatchId) {
    $deletedMatchIds[(int)$deletedMatchId] = true;
}
$notDeleted = static function (array $agreement) use ($deletedMatchIds): bool {
    return !((string)($agreement['legacy_source'] ?? '') === 'match' && isset($deletedMatchIds[(int)($agreement['legacy_id'] ?? 0)]));
};

$teamNames = [];
foreach ($pdo->query('SELECT id, name FROM teams ORDER BY name') as $team) {
    $teamNames[(int)$team['id']] = (string)$team['name'];
}

$fixtureDetails = [];
$fixtureStmt = $pdo->prepare("
    SELECT f.id, f.match_date, COALESCE(o.clubname, f.opponent) AS opponent
    FROM match_fixtures f
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE f.season_id = :season_id
");
$fixtureStmt->execute([':season_id' => $seasonId]);
foreach ($fixtureStmt as $fixture) {
    $fixtureDetails[(int)$fixture['id']] = $fixture;
}

foreach ($rawAgreements as &$agreement) {
    $scope = (string)$agreement['package_scope'];
    if (!empty($agreement['fixture_id'])) {
        $fixture = $fixtureDetails[(int)$agreement['fixture_id']] ?? [];
        $agreement['report_target'] = trim((string)($fixture['opponent'] ?? 'Fixture') . (!empty($fixture['match_date']) ? ' · ' . reportDate((string)$fixture['match_date']) : ''));
    } elseif (!empty($agreement['player_id'])) {
        $agreement['report_target'] = (string)($agreement['player_name'] ?? 'Player');
    } elseif (!empty($agreement['team_id'])) {
        $agreement['report_target'] = $teamNames[(int)$agreement['team_id']] ?? 'Team';
    } elseif ($scope === 'digital') {
        $agreement['report_target'] = 'Digital channels';
    } else {
        $agreement['report_target'] = 'Club-wide';
    }
}
unset($agreement);

// $allAgreements: the full season ledger, including cancelled (used by ledger-style reports).
// $liveAgreements: excludes cancelled agreements (used by every financial roll-up).
$allAgreements = array_values(array_filter($rawAgreements, $notDeleted));
$liveAgreements = array_values(array_filter($allAgreements, static fn(array $a): bool => (string)$a['effective_status'] !== 'cancelled'));

$packageOptions = [];
foreach ($allAgreements as $agreement) {
    $name = trim((string)$agreement['package_name']);
    if ($name !== '') {
        $packageOptions[$name] = $name;
    }
}
ksort($packageOptions);
$packageOptions = array_values($packageOptions);

$sponsorOptions = [];
$playerOptions = [];
foreach ($allAgreements as $agreement) {
    $sponsorName = trim((string)$agreement['sponsor_name']);
    if ($sponsorName !== '') {
        $sponsorOptions[$sponsorName] = $sponsorName;
    }
    $playerName = trim((string)($agreement['player_name'] ?? ''));
    if ($playerName !== '') {
        $playerOptions[$playerName] = $playerName;
    }
}
ksort($sponsorOptions);
ksort($playerOptions);
$sponsorOptions = array_values($sponsorOptions);
$playerOptions = array_values($playerOptions);

// Payments across all three sponsorship storage generations, unioned into one ledger.
$paymentSql = "
    SELECT ap.paid_at, ap.amount,
           CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS sponsor_name,
           CONVERT(p.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS package_name,
           CONVERT(p.scope USING utf8mb4) COLLATE utf8mb4_unicode_ci AS scope,
           CONVERT(COALESCE(pl.name, t.name, COALESCE(o.clubname, f.opponent), 'Club-wide') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS target,
           CONVERT(ap.method USING utf8mb4) COLLATE utf8mb4_unicode_ci AS method,
           CONVERT(ap.note USING utf8mb4) COLLATE utf8mb4_unicode_ci AS note
    FROM sponsorship_agreement_payments ap
    JOIN sponsorship_agreements a ON a.id = ap.agreement_id
    JOIN sponsors s ON s.id = a.sponsor_id
    JOIN packages p ON p.id = a.package_id
    LEFT JOIN players pl ON pl.id = a.player_id
    LEFT JOIN teams t ON t.id = a.team_id
    LEFT JOIN match_fixtures f ON f.id = a.fixture_id
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE a.season_id = :season_id

    UNION ALL

    SELECT pay.paid_at, pay.amount,
           CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(p.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT('player' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(pl.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(pay.method USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(pay.note USING utf8mb4) COLLATE utf8mb4_unicode_ci
    FROM sponsorship_payments pay
    JOIN sponsorships sp ON sp.id = pay.sponsorship_id
    JOIN sponsors s ON s.id = sp.sponsor_id
    LEFT JOIN packages p ON p.id = sp.package_id
    JOIN players pl ON pl.id = sp.player_id
    WHERE sp.season_id = :season_id_player

    UNION ALL

    SELECT pay.paid_at, pay.amount,
           CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(p.name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT('match' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(COALESCE(o.clubname, f.opponent) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(pay.method USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(pay.note USING utf8mb4) COLLATE utf8mb4_unicode_ci
    FROM match_sponsorship_payments pay
    JOIN match_sponsorships ms ON ms.id = pay.match_sponsorship_id
    JOIN sponsors s ON s.id = ms.sponsor_id
    LEFT JOIN packages p ON p.code = ms.sponsorship_role
    JOIN match_fixtures f ON f.id = ms.fixture_id
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE ms.season_id = :season_id_match
    ORDER BY paid_at DESC
";
$paymentStmt = $pdo->prepare($paymentSql);
$paymentStmt->execute([':season_id' => $seasonId, ':season_id_player' => $seasonId, ':season_id_match' => $seasonId]);
$allPayments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentMethodOptions = [];
foreach ($allPayments as $payment) {
    $method = trim((string)$payment['method']);
    if ($method !== '') {
        $paymentMethodOptions[$method] = $method;
    }
}
ksort($paymentMethodOptions);
$paymentMethodOptions = array_values($paymentMethodOptions);

/* =========================================================================
   Matchday + club datasets (new report families beyond the sponsorship ledger)
   ========================================================================= */

function reportFixtureResult(array $fixture): ?string
{
    if (!in_array(strtolower((string)($fixture['status'] ?? '')), ['played', 'finished', 'complete', 'completed'], true)) {
        return null;
    }
    if ($fixture['full_time_home_score'] === null || $fixture['full_time_away_score'] === null) {
        return null;
    }
    $home = (int)$fixture['full_time_home_score'];
    $away = (int)$fixture['full_time_away_score'];
    $isHome = (int)($fixture['is_home'] ?? 1) === 1;
    $ours = $isHome ? $home : $away;
    $theirs = $isHome ? $away : $home;
    return $ours > $theirs ? 'Win' : ($ours < $theirs ? 'Loss' : 'Draw');
}

function reportFixtureStatusLabel(string $status): string
{
    return match (strtolower($status)) {
        'played', 'finished', 'complete', 'completed' => 'Played',
        'postponed' => 'Postponed',
        'delayed' => 'Delayed',
        'cancelled' => 'Cancelled',
        default => 'Scheduled',
    };
}

$fixturesStmt = $pdo->prepare("
    SELECT f.id, f.match_date, f.competition, f.venue, f.is_home, f.status,
           f.full_time_home_score, f.full_time_away_score,
           COALESCE(o.clubname, f.opponent) AS opponent
    FROM match_fixtures f
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE f.season_id = :season_id
    ORDER BY f.match_date ASC, f.id ASC
");
$fixturesStmt->execute([':season_id' => $seasonId]);
$seasonFixtures = $fixturesStmt->fetchAll(PDO::FETCH_ASSOC);

$matchAgreementsByFixture = [];
foreach ($liveAgreements as $agreement) {
    if ((string)$agreement['package_scope'] === 'match' && !empty($agreement['fixture_id'])) {
        $matchAgreementsByFixture[(int)$agreement['fixture_id']][(string)$agreement['package_code']] = $agreement;
    }
}

$playersStmt = $pdo->query('SELECT id, name, status, active, team_id, left_at FROM players ORDER BY name ASC');
$allPlayers = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

$playerAgreementsByPlayer = [];
foreach ($liveAgreements as $agreement) {
    if ((string)$agreement['package_scope'] === 'player' && !empty($agreement['player_id'])) {
        $playerAgreementsByPlayer[(int)$agreement['player_id']][(string)$agreement['package_code']] = $agreement;
    }
}

$sponsorsStmt = $pdo->query('SELECT id, name, is_active, is_main_sponsor, facebook_page_url, facebook_page_name, instagram_url, twitter_url, website_url, contact_email FROM sponsors ORDER BY name ASC');
$allSponsors = $sponsorsStmt->fetchAll(PDO::FETCH_ASSOC);
$sponsorContactById = array_column($allSponsors, null, 'id');

/** Best available "Facebook / contact" label for a sponsor — mirrors the printed sponsor board's contact column. */
function reportSponsorContactLabel(?array $sponsor): string
{
    if (!$sponsor) {
        return '-';
    }
    $facebookName = trim((string)($sponsor['facebook_page_name'] ?? ''));
    if ($facebookName !== '') {
        return $facebookName;
    }
    $facebookUrl = trim((string)($sponsor['facebook_page_url'] ?? ''));
    if ($facebookUrl !== '') {
        return trim((string)parse_url($facebookUrl, PHP_URL_PATH), '/') ?: $facebookUrl;
    }
    $website = trim((string)($sponsor['website_url'] ?? ''));
    if ($website !== '') {
        return $website;
    }
    $email = trim((string)($sponsor['contact_email'] ?? ''));
    return $email !== '' ? $email : '-';
}

$deletedMatchIdsAll = [];
foreach ($pdo->query("SELECT id FROM match_sponsorships WHERE ended_at IS NOT NULL AND ended_reason = 'deleted'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $deletedMatchIdsAll[(int)$id] = true;
}
$lifetimeAgreements = array_values(array_filter(
    getSponsorshipAgreements($pdo, []),
    static function (array $a) use ($deletedMatchIdsAll): bool {
        if ((string)$a['status'] === 'cancelled') {
            return false;
        }
        return !((string)($a['legacy_source'] ?? '') === 'match' && isset($deletedMatchIdsAll[(int)($a['legacy_id'] ?? 0)]));
    }
));
$lifetimeBySponsor = [];
foreach ($lifetimeAgreements as $agreement) {
    $sponsorId = (int)$agreement['sponsor_id'];
    $due = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['agreed_amount'];
    $paid = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['total_paid'];
    $lifetimeBySponsor[$sponsorId] ??= ['agreements' => 0, 'value' => 0.0, 'paid' => 0.0];
    $lifetimeBySponsor[$sponsorId]['agreements']++;
    $lifetimeBySponsor[$sponsorId]['value'] += $due;
    $lifetimeBySponsor[$sponsorId]['paid'] += $paid;
}
$seasonActiveBySponsor = [];
foreach ($liveAgreements as $agreement) {
    if (in_array((string)$agreement['effective_status'], ['active', 'scheduled'], true)) {
        $sponsorId = (int)$agreement['sponsor_id'];
        $seasonActiveBySponsor[$sponsorId] = ($seasonActiveBySponsor[$sponsorId] ?? 0) + 1;
    }
}

$packageAmountByCode = [];
foreach (getSponsorshipPackages($pdo) as $package) {
    $packageAmountByCode[(string)$package['code']] = (float)$package['amount'];
}

/* =========================================================================
   Season ticket datasets
   ========================================================================= */

function reportSeasonTicketRows(PDO $pdo, ?int $seasonId = null): array
{
    $where = ["oi.product_type = 'season_ticket'"];
    $params = [];
    if ($seasonId !== null && $seasonId > 0) {
        $where[] = 'sp.season_id = :season_id';
        $params[':season_id'] = $seasonId;
    }

    $stmt = $pdo->prepare("SELECT o.id AS order_id, o.status AS order_status, o.created_at, o.completed_at,
            o.total_amount, o.customer_name, o.customer_email,
            pay.payment_method, pay.status AS payment_status, pay.paid_at,
            e.person_id, e.status AS ticket_status, c.manual_code, c.is_active AS credential_active,
            p.display_name AS person_name, p.email AS person_email,
            t.name AS ticket_type, sp.season_id, se.name AS season_name, se.start_date AS season_start_date
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN payments pay ON pay.order_id = o.id
        JOIN entitlements e ON e.order_item_id = oi.id
        JOIN season_passes sp ON sp.entitlement_id = e.id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        JOIN people p ON p.id = e.person_id
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        JOIN seasons se ON se.id = sp.season_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY se.start_date DESC, o.created_at DESC, o.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function filterSeasonTicketReportRows(array $rows, array $filters): array
{
    $query = mb_strtolower((string)($filters['search'] ?? ''), 'UTF-8');
    return array_values(array_filter($rows, static function (array $row) use ($filters, $query): bool {
        if ($query !== '') {
            $haystack = mb_strtolower(implode(' | ', [
                (string)$row['person_name'], (string)$row['person_email'], (string)$row['ticket_type'],
                (string)$row['manual_code'], (string)$row['season_name'], (string)$row['order_id'],
            ]), 'UTF-8');
            if (!str_contains($haystack, $query)) {
                return false;
            }
        }
        if (($filters['status'] ?? '') !== '' && (string)$row['order_status'] !== $filters['status']) {
            return false;
        }
        if (($filters['package'] ?? '') !== '' && (string)$row['ticket_type'] !== $filters['package']) {
            return false;
        }
        if (($filters['method'] ?? '') !== '' && (string)$row['payment_method'] !== $filters['method']) {
            return false;
        }
        if (($filters['from'] ?? '') !== '' && substr((string)$row['created_at'], 0, 10) < $filters['from']) {
            return false;
        }
        if (($filters['to'] ?? '') !== '' && substr((string)$row['created_at'], 0, 10) > $filters['to']) {
            return false;
        }
        return true;
    }));
}

function reportRows_season_tickets(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'Season' => (string)$row['season_name'],
            'Person' => (string)$row['person_name'],
            'Email' => (string)($row['person_email'] ?: $row['customer_email']),
            'Type' => (string)$row['ticket_type'],
            'Order #' => (int)$row['order_id'],
            'Order Status' => (string)$row['order_status'],
            'Payment Status' => (string)$row['payment_status'],
            'Method' => (string)$row['payment_method'],
            'Ticket Status' => (string)$row['ticket_status'],
            'Ordered' => reportDate((string)$row['created_at'], true),
            'Paid at' => reportDate((string)($row['paid_at'] ?? ''), true),
            'Amount' => (float)$row['total_amount'],
        ];
    }, $rows);
}

/** Some season ticket types were renamed between seasons but represent the same product.
 *  Used only for the season-on-season "ticket types comparison" panel, so a rename doesn't
 *  show up as one type being discontinued and an unrelated new type appearing. */
function reportTicketTypeCanonicalName(string $name): string
{
    static $aliases = [
        'adult - 65' => 'Adult',
        'vics veteran (65+)' => 'Concession',
        'wee vics pass (under 16s)' => 'Wee Vics',
    ];
    $key = mb_strtolower(trim($name), 'UTF-8');
    return $aliases[$key] ?? $name;
}

/** Groups raw season-ticket order rows by season, keyed by season_id, sorted oldest-to-newest.
 *  Shared by the full-history ledger and the user-driven main/compare-season dashboard so both
 *  read from one accumulation of the facts (tickets, income, holder set, types) per season. */
function reportSeasonTicketGroupBySeason(array $rows): array
{
    $bySeason = [];
    foreach ($rows as $row) {
        $seasonId = (int)$row['season_id'];
        $bySeason[$seasonId] ??= [
            'id' => $seasonId,
            'Season' => (string)$row['season_name'],
            'sort_date' => (string)($row['season_start_date'] ?? ''),
            'Tickets' => 0,
            'Active Tickets' => 0,
            'Paid Orders' => 0,
            'Complimentary' => 0,
            'Cancelled/Refunded' => 0,
            'Gross Income' => 0.0,
            'types' => [],
            'people' => [],
        ];
        $bySeason[$seasonId]['Tickets']++;
        $bySeason[$seasonId]['types'][reportTicketTypeCanonicalName((string)$row['ticket_type'])] = true;
        $bySeason[$seasonId]['people'][(int)$row['person_id']] = (string)$row['person_name'];
        if ((string)$row['ticket_status'] === 'active') {
            $bySeason[$seasonId]['Active Tickets']++;
        }
        if ((string)$row['payment_status'] === 'paid') {
            $bySeason[$seasonId]['Paid Orders']++;
            $bySeason[$seasonId]['Gross Income'] += (float)$row['total_amount'];
        }
        if ((float)$row['total_amount'] <= 0.0001) {
            $bySeason[$seasonId]['Complimentary']++;
        }
        if (in_array((string)$row['order_status'], ['cancelled', 'refunded'], true) || in_array((string)$row['ticket_status'], ['cancelled', 'refunded'], true)) {
            $bySeason[$seasonId]['Cancelled/Refunded']++;
        }
    }

    uasort($bySeason, static function (array $a, array $b): int {
        return strcmp((string)$a['sort_date'], (string)$b['sort_date']);
    });

    return $bySeason;
}

/** A season's own value for a "fact" metric (tickets, income, etc.) — no comparison involved.
 *  Used to show each compared season's own numbers alongside the main season's, rather than
 *  collapsing every selected season into one blended total. */
function reportSeasonTicketOwnValue(array $group, string $key): float
{
    return match ($key) {
        'Average Ticket Value' => (int)$group['Tickets'] > 0 ? (float)$group['Gross Income'] / (int)$group['Tickets'] : 0.0,
        'Unique Holders' => (float)count($group['people']),
        default => (float)($group[$key] ?? 0),
    };
}

/** One season's own facts, framed against a single other season (or null for no comparison).
 *  Used to build one dashboard column/row per season the user has chosen to compare against. */
function reportSeasonTicketPairwise(array $season, ?array $compare): array
{
    $tickets = (int)$season['Tickets'];
    $holders = array_keys($season['people']);
    $compareHolders = $compare ? array_keys($compare['people']) : [];
    $renewed = $compare ? array_intersect($holders, $compareHolders) : [];
    $new = $compare ? array_diff($holders, $compareHolders) : $holders;
    $lapsed = $compare ? array_diff($compareHolders, $holders) : [];
    $compareTickets = $compare ? (int)$compare['Tickets'] : 0;
    $compareIncome = $compare ? (float)$compare['Gross Income'] : 0.0;
    $ticketChange = $tickets - $compareTickets;
    $incomeChange = (float)$season['Gross Income'] - $compareIncome;

    return [
        'Season' => $season['Season'],
        'Compare Season' => $compare['Season'] ?? null,
        'Tickets' => $tickets,
        'Tickets Change' => $compare ? $ticketChange : 0,
        'Ticket Growth %' => $compare && $compareTickets > 0 ? ($ticketChange / $compareTickets) * 100 : 0.0,
        'Active Tickets' => (int)$season['Active Tickets'],
        'Paid Orders' => (int)$season['Paid Orders'],
        'Gross Income' => (float)$season['Gross Income'],
        'Income Change' => $compare ? $incomeChange : 0.0,
        'Income Growth %' => $compare && $compareIncome > 0.0001 ? ($incomeChange / $compareIncome) * 100 : 0.0,
        'Average Ticket Value' => $tickets > 0 ? (float)$season['Gross Income'] / $tickets : 0.0,
        'Complimentary' => (int)$season['Complimentary'],
        'Cancelled/Refunded' => (int)$season['Cancelled/Refunded'],
        'Unique Holders' => count($holders),
        'New Holders' => count($new),
        'Renewed Holders' => count($renewed),
        'Lapsed From Previous' => count($lapsed),
        'Renewal Rate %' => $compare && count($compareHolders) > 0 ? (count($renewed) / count($compareHolders)) * 100 : 0.0,
        'Ticket Types' => implode(', ', array_keys($season['types'])),
    ];
}

function reportRows_season_ticket_comparison(array $rows): array
{
    $bySeason = reportSeasonTicketGroupBySeason($rows);
    $output = [];
    $previous = null;
    foreach ($bySeason as $season) {
        $tickets = (int)$season['Tickets'];
        $holders = array_keys($season['people']);
        $previousHolders = $previous ? array_keys($previous['people']) : [];
        $renewed = $previous ? array_intersect($holders, $previousHolders) : [];
        $new = $previous ? array_diff($holders, $previousHolders) : $holders;
        $lapsed = $previous ? array_diff($previousHolders, $holders) : [];
        $previousTickets = $previous ? (int)$previous['Tickets'] : 0;
        $previousIncome = $previous ? (float)$previous['Gross Income'] : 0.0;
        $ticketChange = $tickets - $previousTickets;
        $incomeChange = (float)$season['Gross Income'] - $previousIncome;
        $output[] = [
            'Season' => $season['Season'],
            'Tickets' => $tickets,
            'Tickets Change' => $previous ? $ticketChange : 0,
            'Ticket Growth %' => $previous && $previousTickets > 0 ? ($ticketChange / $previousTickets) * 100 : 0.0,
            'Active Tickets' => (int)$season['Active Tickets'],
            'Paid Orders' => (int)$season['Paid Orders'],
            'Gross Income' => (float)$season['Gross Income'],
            'Income Change' => $previous ? $incomeChange : 0.0,
            'Income Growth %' => $previous && $previousIncome > 0.0001 ? ($incomeChange / $previousIncome) * 100 : 0.0,
            'Average Ticket Value' => $tickets > 0 ? (float)$season['Gross Income'] / $tickets : 0.0,
            'Complimentary' => (int)$season['Complimentary'],
            'Cancelled/Refunded' => (int)$season['Cancelled/Refunded'],
            'Unique Holders' => count($holders),
            'New Holders' => count($new),
            'Renewed Holders' => count($renewed),
            'Lapsed From Previous' => count($lapsed),
            'Renewal Rate %' => $previous && count($previousHolders) > 0 ? (count($renewed) / count($previousHolders)) * 100 : 0.0,
            'Ticket Types' => implode(', ', array_keys($season['types'])),
        ];
        $previous = $season;
    }
    return array_reverse($output);
}

$seasonTicketRows = reportSeasonTicketRows($pdo, $seasonId);
$allSeasonTicketRows = reportSeasonTicketRows($pdo, null);
$seasonTicketTypeOptions = [];
$seasonTicketMethodOptions = [];
foreach ($allSeasonTicketRows as $ticketRow) {
    $typeName = trim((string)$ticketRow['ticket_type']);
    if ($typeName !== '') {
        $seasonTicketTypeOptions[$typeName] = $typeName;
    }
    $methodName = trim((string)$ticketRow['payment_method']);
    if ($methodName !== '') {
        $seasonTicketMethodOptions[$methodName] = $methodName;
    }
}
ksort($seasonTicketTypeOptions);
ksort($seasonTicketMethodOptions);

/* =========================================================================
   Report catalogue
   ========================================================================= */

$REPORTS = [
    'portfolio' => ['group' => 'Sponsorship', 'label' => 'Portfolio overview', 'icon' => 'fa-chart-pie', 'desc' => 'A high-level snapshot of the whole sponsorship portfolio.', 'filters' => ['status', 'scope', 'package', 'sponsor', 'player', 'team', 'date']],
    'agreements' => ['group' => 'Sponsorship', 'label' => 'Agreement ledger', 'icon' => 'fa-file-signature', 'desc' => 'Every sponsorship agreement this season, with payment and status context.', 'filters' => ['status', 'scope', 'package', 'sponsor', 'player', 'team', 'date']],
    'payments' => ['group' => 'Sponsorship', 'label' => 'Payment ledger', 'icon' => 'fa-sterling-sign', 'desc' => 'Every payment received this season, across every sponsorship type.', 'filters' => ['scope', 'package', 'sponsor', 'player', 'method', 'date']],
    'outstanding' => ['group' => 'Sponsorship', 'label' => 'Outstanding balances', 'icon' => 'fa-triangle-exclamation', 'desc' => 'Agreements that still have money owing, worst first.', 'filters' => ['status', 'scope', 'package', 'sponsor', 'player', 'team', 'date']],
    'renewals' => ['group' => 'Sponsorship', 'label' => 'Renewals watchlist', 'icon' => 'fa-hourglass-half', 'desc' => 'Agreements ending soon (or just lapsed) that need a renewal conversation.', 'filters' => ['scope', 'package', 'sponsor', 'player', 'team', 'window']],
    'complimentary' => ['group' => 'Sponsorship', 'label' => 'Complimentary sponsorships', 'icon' => 'fa-gift', 'desc' => 'Gifted sponsorships and the goodwill value they represent.', 'filters' => ['scope', 'package', 'sponsor', 'player', 'team', 'date']],
    'sponsors' => ['group' => 'Breakdowns', 'label' => 'Sponsor breakdown', 'icon' => 'fa-building', 'desc' => 'Who is carrying the portfolio value this season.', 'filters' => ['scope', 'package', 'sponsor', 'team', 'date']],
    'players' => ['group' => 'Breakdowns', 'label' => 'Player coverage (financial)', 'icon' => 'fa-user-tag', 'desc' => 'Sponsorship value linked to each individually-sponsored player.', 'filters' => ['player', 'team', 'date']],
    'scope' => ['group' => 'Breakdowns', 'label' => 'Scope summary', 'icon' => 'fa-layer-group', 'desc' => 'The portfolio broken down by club, digital, team, match and player.', 'filters' => ['scope', 'package', 'team', 'date']],
    'status' => ['group' => 'Breakdowns', 'label' => 'Status summary', 'icon' => 'fa-toggle-on', 'desc' => 'Spot which agreements are active, scheduled, expired or cancelled.', 'filters' => ['status', 'scope', 'package', 'sponsor', 'player', 'team', 'date']],
    'payment_methods' => ['group' => 'Breakdowns', 'label' => 'Payment method summary', 'icon' => 'fa-credit-card', 'desc' => 'Which payment methods are being used most.', 'filters' => ['method', 'scope', 'date']],
    'package_performance' => ['group' => 'Breakdowns', 'label' => 'Package fill & value', 'icon' => 'fa-boxes-stacked', 'desc' => 'Every sponsorship package type: how many agreements it has this season, and the money behind them.', 'filters' => []],
    'season_tickets' => ['group' => 'Ticketing', 'label' => 'Season ticket ledger', 'icon' => 'fa-id-card', 'desc' => 'Every season ticket order for the selected season.', 'filters' => ['status', 'package', 'method', 'date']],
    'season_ticket_comparison' => ['group' => 'Ticketing', 'label' => 'Season ticket comparison', 'icon' => 'fa-chart-column', 'desc' => 'Season-on-season growth, gross income, new holders, renewals, retained holders and lapsed holders.', 'filters' => ['main_season', 'compare_seasons', 'package', 'method', 'date']],
    'match_coverage' => ['group' => 'Matchday', 'label' => 'Match sponsorship coverage', 'icon' => 'fa-futbol', 'desc' => 'Every fixture and whether its matchday, ball and MOTM sponsors are in place.', 'filters' => ['date']],
    'fixtures' => ['group' => 'Matchday', 'label' => 'Fixtures & results', 'icon' => 'fa-calendar-days', 'desc' => 'The season fixture list with results.', 'filters' => ['date']],
    'appearances' => ['group' => 'Matchday', 'label' => 'Player appearances & discipline', 'icon' => 'fa-person-running', 'desc' => "Every player's season involvement: appearances, minutes, goals and cards.", 'filters' => ['player_status', 'team']],
    'match_events' => ['group' => 'Matchday', 'label' => 'Goals & bookings log', 'icon' => 'fa-futbol', 'desc' => 'Every goal, yellow and red card this season, one row per event.', 'filters' => ['player', 'event', 'date']],
    'results_summary' => ['group' => 'Matchday', 'label' => 'Results summary', 'icon' => 'fa-trophy', 'desc' => 'Played, won, drawn and lost, with goals for/against, by competition.', 'filters' => ['date']],
    'roster' => ['group' => 'Club', 'label' => 'Squad roster & coverage', 'icon' => 'fa-users', 'desc' => 'Every player and their kit sponsorship coverage.', 'filters' => ['player_status', 'team']],
    'sponsor_directory' => ['group' => 'Club', 'label' => 'Sponsor directory', 'icon' => 'fa-address-book', 'desc' => 'Every sponsor on file with lifetime value and contact presence.', 'filters' => ['sponsor_status']],
    'sponsor_showcase' => ['group' => 'Club', 'label' => 'Sponsor showcase', 'icon' => 'fa-award', 'desc' => 'Every sponsor grouped by sponsorship type, like a printed sponsor board — pick which types to show.', 'filters' => ['package_types']],
];

/**
 * Which capability each report type needs — reports.php mixes finance and
 * football data in one file, so it's gated per report type rather than as a
 * single page-level capability. Someone with only the Match Day capability
 * can open the page but only reach the four pure football-stats reports; a
 * Treasurer (finance only) reaches everything else. Unlisted/unknown types
 * deny by default. Admin bypasses as always via hub_auth_has_any_capability().
 */
$reportTypeCapability = [
    'fixtures' => ['matchday'],
    'appearances' => ['matchday'],
    'match_events' => ['matchday'],
    'results_summary' => ['matchday'],
    'roster' => ['finance_view', 'matchday'],
    'season_tickets' => ['finance_view', 'tickets_ops'],
    'season_ticket_comparison' => ['finance_view', 'tickets_ops'],
];
$defaultReportCapability = ['finance_view'];

$reportType = (string)($_GET['report_type'] ?? 'portfolio');
if (!isset($REPORTS[$reportType])) {
    $reportType = 'portfolio';
}
if (!hub_auth_has_any_capability($reportTypeCapability[$reportType] ?? $defaultReportCapability)) {
    // Requested/default report isn't reachable for this user — fall back to
    // the first one in catalogue order that is, rather than hard-denying
    // the whole shared reports shell.
    $fallbackType = null;
    foreach ($REPORTS as $candidateKey => $candidateMeta) {
        if (hub_auth_has_any_capability($reportTypeCapability[$candidateKey] ?? $defaultReportCapability)) {
            $fallbackType = $candidateKey;
            break;
        }
    }
    if ($fallbackType === null) {
        http_response_code(403);
        echo '<div><div class="alert alert-danger">You do not have permission to view any reports.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
    $reportType = $fallbackType;
}
$activeReport = $REPORTS[$reportType];

/* =========================================================================
   Filters (read once, applied per-report below)
   ========================================================================= */

$searchTerm = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$scopeFilter = trim((string)($_GET['scope'] ?? ''));
$packageFilter = trim((string)($_GET['package'] ?? ''));
$sponsorFilter = trim((string)($_GET['sponsor'] ?? ''));
$playerFilter = trim((string)($_GET['player'] ?? ''));
$teamFilter = trim((string)($_GET['team'] ?? ''));
$methodFilter = trim((string)($_GET['method'] ?? ''));
$eventFilter = trim((string)($_GET['event'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$playerStatusFilter = trim((string)($_GET['player_status'] ?? ''));
$sponsorStatusFilter = trim((string)($_GET['sponsor_status'] ?? ''));
$renewalWindow = max(7, min(180, (int)($_GET['window'] ?? 60)));
$pdfOrientation = (string)($_GET['pdf_orientation'] ?? 'landscape');
if (!in_array($pdfOrientation, ['landscape', 'portrait'], true)) {
    $pdfOrientation = 'landscape';
}

// All active packages, for the "Package fill & value" and "Sponsor showcase" reports.
// Showcase defaults to club/digital-scope packages (the "one name per package" ones a
// printed sponsor board would show) — match/player-scope packages have many holders per
// package (one per fixture or per player) so they're excluded by default, not hidden.
$allActivePackages = getSponsorshipPackages($pdo, true);
$packageNameById = array_column($allActivePackages, 'name', 'id');
$showcaseDefaultPackageIds = array_column(array_filter($allActivePackages, static fn(array $p): bool => in_array((string)$p['scope'], ['club', 'digital'], true)), 'id');
if (isset($_GET['package_ids'])) {
    $selectedPackageIds = array_values(array_intersect(array_map('intval', (array)$_GET['package_ids']), array_column($allActivePackages, 'id')));
} else {
    $selectedPackageIds = $showcaseDefaultPackageIds;
}

function filterAgreementRows(array $rows, array $filters, array $teamNames): array
{
    $query = mb_strtolower((string)($filters['search'] ?? ''), 'UTF-8');
    return array_values(array_filter($rows, function (array $row) use ($filters, $query, $teamNames): bool {
        $due = !empty($row['is_complimentary']) ? 0.0 : (float)$row['agreed_amount'];
        $paid = !empty($row['is_complimentary']) ? 0.0 : (float)$row['total_paid'];
        $paymentState = reportPaymentState($due, $paid, !empty($row['is_complimentary']));
        $scope = (string)$row['package_scope'];

        if ($query !== '') {
            $haystack = mb_strtolower(implode(' | ', [
                (string)$row['sponsor_name'], (string)$row['package_name'], (string)($row['report_target'] ?? ''),
                reportScopeLabel($scope), (string)$row['package_category'], $paymentState['label'], (string)($row['player_name'] ?? ''),
            ]), 'UTF-8');
            if (!str_contains($haystack, $query)) {
                return false;
            }
        }
        if (($filters['status'] ?? '') !== '' && (string)$row['effective_status'] !== $filters['status']) {
            return false;
        }
        if (($filters['scope'] ?? '') !== '' && $scope !== $filters['scope']) {
            return false;
        }
        if (($filters['package'] ?? '') !== '' && (string)$row['package_name'] !== $filters['package']) {
            return false;
        }
        if (($filters['sponsor'] ?? '') !== '' && (string)$row['sponsor_name'] !== $filters['sponsor']) {
            return false;
        }
        if (($filters['player'] ?? '') !== '' && (string)($row['player_name'] ?? '') !== $filters['player']) {
            return false;
        }
        if (($filters['team'] ?? '') !== '') {
            $teamName = !empty($row['team_id']) ? ($teamNames[(int)$row['team_id']] ?? '') : '';
            if ($teamName !== $filters['team']) {
                return false;
            }
        }
        if (($filters['from'] ?? '') !== '' && (empty($row['start_date']) || (string)$row['start_date'] < $filters['from'])) {
            return false;
        }
        if (($filters['to'] ?? '') !== '' && (empty($row['start_date']) || (string)$row['start_date'] > $filters['to'])) {
            return false;
        }
        return true;
    }));
}

$agreementFilters = [
    'search' => $searchTerm, 'status' => $statusFilter, 'scope' => $scopeFilter, 'package' => $packageFilter,
    'sponsor' => $sponsorFilter, 'player' => $playerFilter, 'team' => $teamFilter, 'from' => $dateFrom, 'to' => $dateTo,
];
$filteredAllAgreements = filterAgreementRows($allAgreements, $agreementFilters, $teamNames);
$filteredLiveAgreements = filterAgreementRows($liveAgreements, $agreementFilters, $teamNames);

$seasonTicketFilters = [
    'search' => $searchTerm, 'status' => $statusFilter, 'package' => $packageFilter, 'method' => $methodFilter,
    'from' => $dateFrom, 'to' => $dateTo,
];
$filteredSeasonTicketRows = filterSeasonTicketReportRows($seasonTicketRows, $seasonTicketFilters);
$filteredAllSeasonTicketRows = filterSeasonTicketReportRows($allSeasonTicketRows, $seasonTicketFilters);

/* =========================================================================
   Season ticket comparison: which season is "main" and which season(s) it is
   being compared against — driven by the filter bar, newest season first.
   ========================================================================= */

$seasonCompareOptions = [];
foreach ($filteredAllSeasonTicketRows as $ticketRow) {
    $sid = (int)$ticketRow['season_id'];
    $seasonCompareOptions[$sid] ??= ['id' => $sid, 'name' => (string)$ticketRow['season_name'], 'start' => (string)($ticketRow['season_start_date'] ?? '')];
}
uasort($seasonCompareOptions, static fn(array $a, array $b): int => strcmp($b['start'], $a['start']));
$seasonCompareOptions = array_values($seasonCompareOptions);
$seasonCompareValidIds = array_column($seasonCompareOptions, 'id');

if (isset($_GET['main_season_id'])) {
    $mainSeasonId = (int)$_GET['main_season_id'];
    if (!in_array($mainSeasonId, $seasonCompareValidIds, true)) {
        $mainSeasonId = $seasonCompareValidIds[0] ?? 0;
    }
    $compareSeasonIdsRaw = $_GET['compare_season_id'] ?? [];
    $compareSeasonIds = is_array($compareSeasonIdsRaw)
        ? array_values(array_intersect(array_unique(array_map('intval', $compareSeasonIdsRaw)), $seasonCompareValidIds))
        : [];
} else {
    $mainSeasonId = $seasonCompareValidIds[0] ?? 0;
    $mainIndex = array_search($mainSeasonId, $seasonCompareValidIds, true);
    $compareSeasonIds = ($mainIndex !== false && isset($seasonCompareValidIds[$mainIndex + 1])) ? [$seasonCompareValidIds[$mainIndex + 1]] : [];
}
$compareSeasonIds = array_values(array_diff($compareSeasonIds, [$mainSeasonId]));
$compareSeasonIds = array_values(array_intersect($seasonCompareValidIds, $compareSeasonIds));

$seasonCompareGrouped = reportSeasonTicketGroupBySeason($filteredAllSeasonTicketRows);
$seasonCompareMain = $seasonCompareGrouped[$mainSeasonId] ?? null;
$seasonCompareAgainst = [];
foreach ($compareSeasonIds as $compareId) {
    if (isset($seasonCompareGrouped[$compareId])) {
        $seasonCompareAgainst[] = $seasonCompareGrouped[$compareId];
    }
}
$seasonComparePairwise = $seasonCompareMain !== null
    ? array_map(static fn(array $compareGroup): array => reportSeasonTicketPairwise($seasonCompareMain, $compareGroup), $seasonCompareAgainst)
    : [];

$filteredPayments = array_values(array_filter($allPayments, function (array $payment) use ($searchTerm, $methodFilter, $scopeFilter, $sponsorFilter, $playerFilter, $packageFilter, $dateFrom, $dateTo): bool {
    $query = mb_strtolower($searchTerm, 'UTF-8');
    if ($query !== '') {
        $haystack = mb_strtolower(implode(' | ', [
            (string)$payment['sponsor_name'], (string)$payment['package_name'], (string)$payment['target'],
            (string)$payment['method'], (string)$payment['note'], reportScopeLabel((string)$payment['scope']),
        ]), 'UTF-8');
        if (!str_contains($haystack, $query)) {
            return false;
        }
    }
    if ($methodFilter !== '' && mb_strtolower((string)$payment['method'], 'UTF-8') !== mb_strtolower($methodFilter, 'UTF-8')) {
        return false;
    }
    if ($scopeFilter !== '' && (string)$payment['scope'] !== $scopeFilter) {
        return false;
    }
    if ($sponsorFilter !== '' && (string)$payment['sponsor_name'] !== $sponsorFilter) {
        return false;
    }
    if ($playerFilter !== '' && (string)$payment['target'] !== $playerFilter) {
        return false;
    }
    if ($packageFilter !== '' && (string)$payment['package_name'] !== $packageFilter) {
        return false;
    }
    if ($dateFrom !== '' && substr((string)$payment['paid_at'], 0, 10) < $dateFrom) {
        return false;
    }
    if ($dateTo !== '' && substr((string)$payment['paid_at'], 0, 10) > $dateTo) {
        return false;
    }
    return true;
}));

$filteredFixtures = array_values(array_filter($seasonFixtures, function (array $fixture) use ($searchTerm, $dateFrom, $dateTo): bool {
    if ($searchTerm !== '') {
        $haystack = mb_strtolower(implode(' | ', [(string)$fixture['opponent'], (string)$fixture['competition'], (string)$fixture['venue']]), 'UTF-8');
        if (!str_contains($haystack, mb_strtolower($searchTerm, 'UTF-8'))) {
            return false;
        }
    }
    if ($dateFrom !== '' && (string)$fixture['match_date'] < $dateFrom) {
        return false;
    }
    if ($dateTo !== '' && (string)$fixture['match_date'] > $dateTo) {
        return false;
    }
    return true;
}));

$filteredPlayers = array_values(array_filter($allPlayers, function (array $player) use ($searchTerm, $playerStatusFilter, $teamFilter, $teamNames): bool {
    if ($searchTerm !== '' && !str_contains(mb_strtolower((string)$player['name'], 'UTF-8'), mb_strtolower($searchTerm, 'UTF-8'))) {
        return false;
    }
    if ($playerStatusFilter !== '' && (string)$player['status'] !== $playerStatusFilter) {
        return false;
    }
    if ($teamFilter !== '') {
        $teamName = !empty($player['team_id']) ? ($teamNames[(int)$player['team_id']] ?? '') : '';
        if ($teamName !== $teamFilter) {
            return false;
        }
    }
    return true;
}));

$filteredSponsors = array_values(array_filter($allSponsors, function (array $sponsor) use ($searchTerm, $sponsorStatusFilter): bool {
    if ($searchTerm !== '' && !str_contains(mb_strtolower((string)$sponsor['name'], 'UTF-8'), mb_strtolower($searchTerm, 'UTF-8'))) {
        return false;
    }
    if ($sponsorStatusFilter === 'active' && (int)$sponsor['is_active'] !== 1) {
        return false;
    }
    if ($sponsorStatusFilter === 'inactive' && (int)$sponsor['is_active'] !== 0) {
        return false;
    }
    return true;
}));

/* =========================================================================
   Report builders — each returns ['rows' => [...], 'kpis' => [...], 'insights' => html|null]
   ========================================================================= */

function reportRows_portfolio(array $agreements): array
{
    $metrics = buildPortfolioMetrics($agreements);
    $rows = [];
    foreach ($metrics['categoryTotals'] as $category) {
        $rows[] = ['Scope' => $category['scope'], 'Category' => $category['category'], 'Sponsorships' => $category['agreements'], 'Value' => $category['value'], 'Paid' => $category['paid'], 'Outstanding' => $category['outstanding']];
    }
    return $rows;
}

function reportRows_agreements(array $agreements): array
{
    $rows = [];
    foreach ($agreements as $agreement) {
        $due = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['agreed_amount'];
        $paid = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['total_paid'];
        $state = reportPaymentState($due, $paid, !empty($agreement['is_complimentary']));
        $rows[] = [
            'Sponsor' => (string)$agreement['sponsor_name'], 'Package' => (string)$agreement['package_name'], 'Scope' => (string)$agreement['package_scope'],
            'Applies To' => (string)$agreement['report_target'], 'Start' => reportDate((string)($agreement['start_date'] ?? '')), 'End' => reportDate((string)($agreement['end_date'] ?? '')),
            'Value' => $due, 'Paid' => $paid, 'Outstanding' => max(0, $due - $paid), 'Payment' => $state['label'], 'Status' => ucfirst((string)$agreement['effective_status']),
        ];
    }
    return $rows;
}

function reportRows_payments(array $payments): array
{
    $rows = [];
    foreach ($payments as $payment) {
        $rows[] = [
            'Date' => reportDate((string)$payment['paid_at']), 'Sponsor' => (string)$payment['sponsor_name'],
            'Package' => (string)($payment['package_name'] ?: reportScopeLabel((string)$payment['scope']) . ' sponsorship'),
            'Scope' => (string)$payment['scope'], 'Applies To' => (string)$payment['target'],
            'Method' => (string)($payment['method'] ?: 'Unspecified'), 'Amount' => (float)$payment['amount'], 'Note' => (string)($payment['note'] ?? ''),
        ];
    }
    return $rows;
}

function reportRows_outstanding(array $agreements): array
{
    $rows = [];
    foreach ($agreements as $agreement) {
        if (!empty($agreement['is_complimentary'])) {
            continue;
        }
        $due = (float)$agreement['agreed_amount'];
        $paid = (float)$agreement['total_paid'];
        $outstanding = max(0, $due - $paid);
        if ($outstanding <= 0.0001) {
            continue;
        }
        $pctPaid = $due > 0 ? ($paid / $due) * 100 : 0.0;
        $urgency = $paid <= 0.0001 ? 'Overdue' : ($pctPaid < 50 ? 'Due soon' : 'Upcoming');
        $rows[] = [
            'Sponsor' => (string)$agreement['sponsor_name'], 'Package' => (string)$agreement['package_name'], 'Scope' => (string)$agreement['package_scope'],
            'Applies To' => (string)$agreement['report_target'], 'Value' => $due, 'Paid' => $paid, 'Outstanding' => $outstanding,
            '% Paid' => round($pctPaid, 1), 'Urgency' => $urgency, 'Status' => ucfirst((string)$agreement['effective_status']),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['Outstanding'] <=> $a['Outstanding']);
    return $rows;
}

function reportRows_renewals(array $agreements, string $today, int $windowDays): array
{
    $rows = [];
    $windowStart = date('Y-m-d', strtotime($today . " -{$windowDays} days"));
    $windowEnd = date('Y-m-d', strtotime($today . " +{$windowDays} days"));
    foreach ($agreements as $agreement) {
        if (!in_array((string)$agreement['effective_status'], ['active', 'expired'], true)) {
            continue;
        }
        $endDate = (string)($agreement['end_date'] ?? '');
        if ($endDate === '' || $endDate < $windowStart || $endDate > $windowEnd) {
            continue;
        }
        $daysRemaining = (int)round((strtotime($endDate) - strtotime($today)) / 86400);
        $urgency = $daysRemaining < 0 ? 'Overdue' : ($daysRemaining <= 14 ? 'Due soon' : 'Upcoming');
        $rows[] = [
            'Sponsor' => (string)$agreement['sponsor_name'], 'Package' => (string)$agreement['package_name'], 'Scope' => (string)$agreement['package_scope'],
            'Applies To' => (string)$agreement['report_target'], 'End' => reportDate($endDate), 'Days Remaining' => $daysRemaining, 'Urgency' => $urgency, 'Value' => (float)$agreement['agreed_amount'],
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $a['Days Remaining'] <=> $b['Days Remaining']);
    return $rows;
}

function reportRows_complimentary(array $agreements, array $packageAmountByCode): array
{
    $rows = [];
    foreach ($agreements as $agreement) {
        if (empty($agreement['is_complimentary'])) {
            continue;
        }
        $listValue = $packageAmountByCode[(string)$agreement['package_code']] ?? (float)$agreement['agreed_amount'];
        $rows[] = [
            'Sponsor' => (string)$agreement['sponsor_name'], 'Package' => (string)$agreement['package_name'], 'Scope' => (string)$agreement['package_scope'],
            'Applies To' => (string)$agreement['report_target'], 'Start' => reportDate((string)($agreement['start_date'] ?? '')), 'End' => reportDate((string)($agreement['end_date'] ?? '')),
            'List Value' => $listValue, 'Status' => ucfirst((string)$agreement['effective_status']),
        ];
    }
    return $rows;
}

function reportRows_sponsors(array $agreements): array
{
    $metrics = buildPortfolioMetrics($agreements);
    $rows = [];
    foreach ($metrics['sponsorTotals'] as $sponsor) {
        $pct = $sponsor['value'] > 0 ? ($sponsor['paid'] / $sponsor['value']) * 100 : 0.0;
        $rows[] = [
            'Sponsor' => $sponsor['name'], 'Sponsorships' => $sponsor['agreements'], 'Types' => implode(', ', array_map('reportScopeLabel', array_keys($sponsor['scopes']))),
            'Value' => $sponsor['value'], 'Paid' => $sponsor['paid'], 'Outstanding' => $sponsor['outstanding'], '% Paid' => round($pct, 1),
        ];
    }
    return $rows;
}

function reportRows_players(array $agreements): array
{
    $metrics = buildPortfolioMetrics($agreements);
    $rows = [];
    foreach ($metrics['playerTotals'] as $player) {
        $rows[] = ['Player' => $player['name'], 'Sponsorships' => $player['agreements'], 'Value' => $player['value'], 'Paid' => $player['paid'], 'Outstanding' => $player['outstanding']];
    }
    return $rows;
}

function reportRows_scope(array $agreements): array
{
    $metrics = buildPortfolioMetrics($agreements);
    $rows = [];
    foreach ($metrics['categoryTotals'] as $category) {
        $rows[] = ['Scope' => $category['scope'], 'Category' => $category['category'], 'Sponsorships' => $category['agreements'], 'Value' => $category['value'], 'Paid' => $category['paid'], 'Outstanding' => $category['outstanding']];
    }
    return $rows;
}

function reportRows_status(array $agreements): array
{
    $buckets = [];
    foreach ($agreements as $agreement) {
        $status = ucfirst((string)$agreement['effective_status']);
        $due = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['agreed_amount'];
        $paid = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['total_paid'];
        $buckets[$status] ??= ['Status' => $status, 'Sponsorships' => 0, 'Value' => 0.0, 'Paid' => 0.0, 'Outstanding' => 0.0];
        $buckets[$status]['Sponsorships']++;
        $buckets[$status]['Value'] += $due;
        $buckets[$status]['Paid'] += $paid;
        $buckets[$status]['Outstanding'] += max(0, $due - $paid);
    }
    return array_values($buckets);
}

function reportRows_payment_methods(array $payments): array
{
    $buckets = [];
    $total = array_sum(array_map(static fn(array $p): float => (float)$p['amount'], $payments));
    foreach ($payments as $payment) {
        $method = (string)($payment['method'] ?: 'Unspecified');
        $buckets[$method] ??= ['Method' => $method, 'Transactions' => 0, 'Value' => 0.0];
        $buckets[$method]['Transactions']++;
        $buckets[$method]['Value'] += (float)$payment['amount'];
    }
    $rows = array_values($buckets);
    foreach ($rows as &$row) {
        $row['Share %'] = $total > 0 ? round(($row['Value'] / $total) * 100, 1) : 0.0;
    }
    unset($row);
    usort($rows, static fn(array $a, array $b): int => $b['Value'] <=> $a['Value']);
    return $rows;
}

function reportRows_match_coverage(array $fixtures, array $matchAgreementsByFixture): array
{
    $codeLabels = ['match_day' => 'Matchday', 'match_ball' => 'Match Ball', 'motm' => 'MOTM'];
    $rows = [];
    foreach ($fixtures as $fixture) {
        $fixtureId = (int)$fixture['id'];
        $assigned = $matchAgreementsByFixture[$fixtureId] ?? [];
        $row = [
            'Date' => reportDate((string)$fixture['match_date']), 'Opponent' => (string)$fixture['opponent'],
            'Venue' => (int)($fixture['is_home'] ?? 1) === 1 ? 'Home' : 'Away',
        ];
        $covered = 0;
        $value = 0.0;
        $paid = 0.0;
        foreach ($codeLabels as $code => $label) {
            $entry = $assigned[$code] ?? null;
            if ($entry) {
                $due = !empty($entry['is_complimentary']) ? 0.0 : (float)$entry['agreed_amount'];
                $paidAmount = !empty($entry['is_complimentary']) ? 0.0 : (float)$entry['total_paid'];
                $state = reportPaymentState($due, $paidAmount, !empty($entry['is_complimentary']));
                $row[$label] = (string)$entry['sponsor_name'] . ' (' . $state['label'] . ')';
                $covered++;
                $value += $due;
                $paid += $paidAmount;
            } else {
                $row[$label] = '';
            }
        }
        $row['Coverage'] = $covered === 3 ? 'Fully sponsored' : ($covered > 0 ? "Partially sponsored ({$covered}/3)" : 'Needs sponsor');
        $row['Value'] = $value;
        $row['Paid'] = $paid;
        $rows[] = $row;
    }
    return $rows;
}

function reportRows_fixtures(array $fixtures): array
{
    $rows = [];
    foreach ($fixtures as $fixture) {
        $result = reportFixtureResult($fixture);
        $home = $fixture['full_time_home_score'];
        $away = $fixture['full_time_away_score'];
        $score = ($home !== null && $away !== null) ? "{$home} - {$away}" : '-';
        $rows[] = [
            'Date' => reportDate((string)$fixture['match_date']), 'Opponent' => (string)$fixture['opponent'], 'Competition' => (string)($fixture['competition'] ?: '-'),
            'Venue' => (int)($fixture['is_home'] ?? 1) === 1 ? 'Home' : 'Away', 'Score' => $score,
            'Result' => $result ?? '-', 'Fixture Status' => reportFixtureStatusLabel((string)($fixture['status'] ?? 'scheduled')),
        ];
    }
    return $rows;
}

/**
 * Players who left before this season (e.g. released at the end of last season) are dropped
 * entirely; players who left during this season are kept but sorted to the bottom, below
 * everyone still involved — mirrors the same left_at/season-window rule players.php already
 * uses to decide who counts as "this season's squad".
 */
function reportRows_appearances(array $players, PDO $pdo, int $seasonId, array $teamNames, string $matchesDataFile, ?string $seasonStart, ?string $seasonEnd): array
{
    $current = [];
    $left = [];
    foreach ($players as $player) {
        $status = (string)$player['status'];
        if ($status === 'left') {
            $leftAt = (string)($player['left_at'] ?? '');
            $leftThisSeason = $seasonStart && $seasonEnd && $leftAt !== '' && $leftAt >= $seasonStart && $leftAt <= $seasonEnd;
            if (!$leftThisSeason) {
                continue;
            }
        }

        $stats = hub_player_match_stats($pdo, $seasonId, (string)$player['name'], $matchesDataFile);
        $row = [
            'Player' => (string)$player['name'],
            'Team' => !empty($player['team_id']) ? ($teamNames[(int)$player['team_id']] ?? '-') : '-',
            'Player Status' => ucfirst($status),
            'Apps' => $stats['appearances'], 'Starts' => $stats['starts'], 'Sub Apps' => $stats['substitute_appearances'],
            'Minutes' => $stats['minutes_played'], 'Goals' => $stats['goals'],
            'Yellow Cards' => $stats['yellow_cards'], 'Red Cards' => $stats['red_cards'],
            'Clean Sheets' => $stats['is_goalkeeper'] ? $stats['clean_sheets'] : 0,
        ];

        if ($status === 'left') {
            $left[] = $row;
        } else {
            $current[] = $row;
        }
    }
    usort($current, static fn(array $a, array $b): int => $b['Apps'] <=> $a['Apps']);
    usort($left, static fn(array $a, array $b): int => $b['Apps'] <=> $a['Apps']);
    return array_merge($current, $left);
}

/** One row per goal/yellow/red card for our own players, in fixture (then in-match) order. */
function reportRows_match_events(array $fixtures, array $eventsByFixture, string $playerFilter, string $eventFilter): array
{
    $rows = [];
    foreach ($fixtures as $fixture) {
        $events = $eventsByFixture[(int)$fixture['id']] ?? [];
        usort($events, static fn(array $left, array $right): int =>
            (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0)
        );
        foreach ($events as $event) {
            if (!is_array($event) || (string)($event['team'] ?? '') !== 'svfc') {
                continue;
            }
            $type = (string)($event['type'] ?? '');
            $isGoal = $type === 'goal' || $type === 'penalty_scored' || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');
            $isYellow = $type === 'yellow_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'yellow');
            $isRed = $type === 'red_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'red');
            if (!$isGoal && !$isYellow && !$isRed) {
                continue;
            }
            $player = (string)($event['player'] ?? '');
            if ($playerFilter !== '' && $player !== $playerFilter) {
                continue;
            }
            $label = $isGoal ? (!empty($event['own_goal']) ? 'Goal (OG)' : 'Goal') : ($isYellow ? 'Yellow Card' : 'Red Card');
            if ($eventFilter !== '' && $label !== $eventFilter) {
                continue;
            }
            $minute = trim((string)($event['minute'] ?? ''));
            $rows[] = [
                'Date' => reportDate((string)$fixture['match_date']), 'Opponent' => (string)$fixture['opponent'],
                'Minute' => $minute !== '' ? $minute . "'" : '-',
                'Player' => $player !== '' ? $player : 'Unknown Player',
                'Event' => $label,
            ];
        }
    }
    return $rows;
}

/** Played/won/drawn/lost and goals for/against per competition, plus a Total row. */
function reportRows_results_summary(array $fixtures): array
{
    $byCompetition = [];
    foreach ($fixtures as $fixture) {
        $result = reportFixtureResult($fixture);
        if ($result === null) {
            continue;
        }
        $competition = (string)($fixture['competition'] ?: 'Uncategorised');
        $isHome = (int)($fixture['is_home'] ?? 1) === 1;
        $home = (int)$fixture['full_time_home_score'];
        $away = (int)$fixture['full_time_away_score'];
        $for = $isHome ? $home : $away;
        $against = $isHome ? $away : $home;

        $byCompetition[$competition] ??= ['Competition' => $competition, 'Played' => 0, 'Won' => 0, 'Drawn' => 0, 'Lost' => 0, 'GF' => 0, 'GA' => 0];
        $byCompetition[$competition]['Played']++;
        $byCompetition[$competition]['Won'] += $result === 'Win' ? 1 : 0;
        $byCompetition[$competition]['Drawn'] += $result === 'Draw' ? 1 : 0;
        $byCompetition[$competition]['Lost'] += $result === 'Loss' ? 1 : 0;
        $byCompetition[$competition]['GF'] += $for;
        $byCompetition[$competition]['GA'] += $against;
    }

    ksort($byCompetition);
    $rows = array_values($byCompetition);

    if ($rows !== []) {
        $total = ['Competition' => 'Total', 'Played' => 0, 'Won' => 0, 'Drawn' => 0, 'Lost' => 0, 'GF' => 0, 'GA' => 0];
        foreach ($rows as $row) {
            foreach (['Played', 'Won', 'Drawn', 'Lost', 'GF', 'GA'] as $key) {
                $total[$key] += $row[$key];
            }
        }
        $rows[] = $total;
    }

    foreach ($rows as &$row) {
        $row['GD'] = $row['GF'] - $row['GA'];
        $row['Points'] = 3 * $row['Won'] + $row['Drawn'];
    }
    unset($row);

    return $rows;
}

function reportRows_roster(array $players, array $playerAgreementsByPlayer, array $teamNames): array
{
    $rows = [];
    foreach ($players as $player) {
        $playerId = (int)$player['id'];
        $slots = $playerAgreementsByPlayer[$playerId] ?? [];
        $home = $slots['player_home'] ?? null;
        $away = $slots['player_away'] ?? null;
        $third = $slots['player_third'] ?? null;
        $covered = ($home ? 1 : 0) + ($away ? 1 : 0) + ($third ? 1 : 0);
        $value = 0.0;
        foreach ([$home, $away, $third] as $entry) {
            if ($entry) {
                $value += !empty($entry['is_complimentary']) ? 0.0 : (float)$entry['agreed_amount'];
            }
        }
        $coverage = $covered >= 2 ? 'Fully sponsored' : ($covered === 1 ? 'Partially sponsored (1/2)' : 'Not sponsored');
        $rows[] = [
            'Player' => (string)$player['name'], 'Player Status' => ucfirst((string)$player['status']),
            'Team' => !empty($player['team_id']) ? ($teamNames[(int)$player['team_id']] ?? '-') : '-',
            'Home Sponsor' => $home ? (string)$home['sponsor_name'] : '', 'Away Sponsor' => $away ? (string)$away['sponsor_name'] : '', 'Third Sponsor' => $third ? (string)$third['sponsor_name'] : '',
            'Coverage' => $coverage, 'Value' => $value,
        ];
    }
    return $rows;
}

function reportRows_sponsor_directory(array $sponsors, array $lifetimeBySponsor, array $seasonActiveBySponsor): array
{
    $rows = [];
    foreach ($sponsors as $sponsor) {
        $sponsorId = (int)$sponsor['id'];
        $lifetime = $lifetimeBySponsor[$sponsorId] ?? ['agreements' => 0, 'value' => 0.0, 'paid' => 0.0];
        $socials = [];
        if (!empty($sponsor['facebook_page_url'])) {
            $socials[] = 'Facebook';
        }
        if (!empty($sponsor['instagram_url'])) {
            $socials[] = 'Instagram';
        }
        if (!empty($sponsor['twitter_url'])) {
            $socials[] = 'Twitter/X';
        }
        $rows[] = [
            'Sponsor' => (string)$sponsor['name'], 'Sponsor Status' => (int)$sponsor['is_active'] === 1 ? 'Active' : 'Inactive',
            'Main Sponsor' => (int)($sponsor['is_main_sponsor'] ?? 0) === 1 ? 'Yes' : 'No',
            'Active Agreements' => $seasonActiveBySponsor[$sponsorId] ?? 0, 'Lifetime Agreements' => $lifetime['agreements'],
            'Lifetime Value' => $lifetime['value'], 'Social Presence' => $socials ? implode(', ', $socials) : 'None on file',
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['Lifetime Value'] <=> $a['Lifetime Value']);
    return $rows;
}

/** One row per package type: how many live agreements it has this season, and the money behind them. */
function reportRows_package_performance(array $packages, array $allAgreements): array
{
    $byPackage = [];
    foreach ($allAgreements as $agreement) {
        if ((string)$agreement['effective_status'] === 'cancelled') {
            continue;
        }
        $packageId = (int)$agreement['package_id'];
        $due = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['agreed_amount'];
        $paid = !empty($agreement['is_complimentary']) ? 0.0 : (float)$agreement['total_paid'];
        $byPackage[$packageId] ??= ['count' => 0, 'value' => 0.0, 'paid' => 0.0];
        $byPackage[$packageId]['count']++;
        $byPackage[$packageId]['value'] += $due;
        $byPackage[$packageId]['paid'] += $paid;
    }

    $manySlotScopes = ['match', 'player'];
    $rows = [];
    foreach ($packages as $package) {
        $packageId = (int)$package['id'];
        $stats = $byPackage[$packageId] ?? ['count' => 0, 'value' => 0.0, 'paid' => 0.0];
        $status = in_array((string)$package['scope'], $manySlotScopes, true)
            ? ($stats['count'] > 0 ? 'In use' : 'Unused')
            : ($stats['count'] > 0 ? 'Filled' : 'Needs sponsor');
        $rows[] = [
            'Package' => (string)$package['name'], 'Category' => (string)$package['category'],
            'Scope' => (string)$package['scope'], 'Fill Status' => $status,
            'Agreements' => $stats['count'], 'Value' => $stats['value'], 'Paid' => $stats['paid'],
            'Outstanding' => max(0, $stats['value'] - $stats['paid']),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['Agreements'] <=> $a['Agreements']);
    return $rows;
}

/** Flat (package, sponsor) pairs for the Sponsor showcase report — the exportable/sortable form of the grouped display below. */
function reportRows_sponsor_showcase(array $packages, array $allAgreements, array $selectedPackageIds, array $sponsorContactById): array
{
    $agreementsByPackage = [];
    foreach ($allAgreements as $agreement) {
        if (!in_array((string)$agreement['effective_status'], ['active', 'scheduled'], true)) {
            continue;
        }
        $agreementsByPackage[(int)$agreement['package_id']][] = $agreement;
    }

    $rows = [];
    foreach ($packages as $package) {
        $packageId = (int)$package['id'];
        if (!in_array($packageId, $selectedPackageIds, true)) {
            continue;
        }
        foreach ($agreementsByPackage[$packageId] ?? [] as $agreement) {
            $rows[] = [
                'Package' => (string)$package['name'], 'Category' => (string)$package['category'],
                'Sponsor' => (string)$agreement['sponsor_name'],
                'Facebook / Contact' => reportSponsorContactLabel($sponsorContactById[(int)$agreement['sponsor_id']] ?? null),
            ];
        }
    }
    return $rows;
}

/**
 * Groups the same package+sponsor pairs into one section per package (including packages
 * with zero sponsors yet, so an empty sponsorship slot is visible at a glance) — the data
 * behind the Sponsor showcase report's card layout, kept separate from reportRows_sponsor_showcase()
 * above since that one is deliberately flat for CSV/PDF export.
 */
function reportSponsorShowcaseGroups(array $packages, array $allAgreements, array $selectedPackageIds): array
{
    $agreementsByPackage = [];
    foreach ($allAgreements as $agreement) {
        if (!in_array((string)$agreement['effective_status'], ['active', 'scheduled'], true)) {
            continue;
        }
        $agreementsByPackage[(int)$agreement['package_id']][] = $agreement;
    }

    // Bucket selected packages by category first (in sort_order, since $packages already
    // arrives that way) — several package sizes/variants (e.g. Large/Medium/Small board,
    // or every Kit & Apparel package) share one category and appear together as one card.
    $packagesByCategory = [];
    foreach ($packages as $package) {
        if (!in_array((int)$package['id'], $selectedPackageIds, true)) {
            continue;
        }
        $packagesByCategory[(string)$package['category']][] = $package;
    }

    $groups = [];
    foreach ($packagesByCategory as $category => $categoryPackages) {
        $entryByCode = [];
        $totalSponsors = 0;
        foreach ($categoryPackages as $package) {
            $sponsors = [];
            foreach ($agreementsByPackage[(int)$package['id']] ?? [] as $agreement) {
                $sponsors[] = ['name' => (string)$agreement['sponsor_name']];
            }
            usort($sponsors, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            $totalSponsors += count($sponsors);
            $entryByCode[(string)$package['code']] = ['name' => (string)$package['name'], 'sponsors' => $sponsors];
        }

        // Lay out this category's packages according to SHOWCASE_PACKAGE_ROWS if it
        // defines one; anything left over (including every package, for a category with
        // no entry there) becomes one final row on its own.
        $rows = [];
        $placed = [];
        foreach (SHOWCASE_PACKAGE_ROWS[$category] ?? [] as $rowCodes) {
            $row = [];
            foreach ($rowCodes as $code) {
                if (isset($entryByCode[$code])) {
                    $row[] = $entryByCode[$code];
                    $placed[$code] = true;
                }
            }
            if ($row) {
                $rows[] = $row;
            }
        }
        $leftover = [];
        foreach ($entryByCode as $code => $entry) {
            if (!isset($placed[$code])) {
                $leftover[] = $entry;
            }
        }
        if ($leftover) {
            $rows[] = $leftover;
        }

        $groups[] = [
            'name' => $category, 'scope' => (string)$categoryPackages[0]['scope'],
            'rows' => $rows, 'total_sponsors' => $totalSponsors,
        ];
    }
    return $groups;
}

$showcaseGroups = null;
switch ($reportType) {
    case 'agreements':
        $reportRows = reportRows_agreements($filteredAllAgreements);
        break;
    case 'payments':
        $reportRows = reportRows_payments($filteredPayments);
        break;
    case 'outstanding':
        $reportRows = reportRows_outstanding($filteredLiveAgreements);
        break;
    case 'renewals':
        $reportRows = reportRows_renewals($filteredAllAgreements, $today, $renewalWindow);
        break;
    case 'complimentary':
        $reportRows = reportRows_complimentary($filteredLiveAgreements, $packageAmountByCode);
        break;
    case 'sponsors':
        $reportRows = reportRows_sponsors($filteredLiveAgreements);
        break;
    case 'players':
        $reportRows = reportRows_players($filteredLiveAgreements);
        break;
    case 'scope':
        $reportRows = reportRows_scope($filteredLiveAgreements);
        break;
    case 'status':
        $reportRows = reportRows_status($filteredAllAgreements);
        break;
    case 'payment_methods':
        $reportRows = reportRows_payment_methods($filteredPayments);
        break;
    case 'package_performance':
        $reportRows = reportRows_package_performance($allActivePackages, $allAgreements);
        break;
    case 'season_tickets':
        $reportRows = reportRows_season_tickets($filteredSeasonTicketRows);
        break;
    case 'season_ticket_comparison':
        $reportRows = reportRows_season_ticket_comparison($filteredAllSeasonTicketRows);
        break;
    case 'match_coverage':
        $reportRows = reportRows_match_coverage($filteredFixtures, $matchAgreementsByFixture);
        break;
    case 'fixtures':
        $reportRows = reportRows_fixtures($filteredFixtures);
        break;
    case 'appearances':
        $reportRows = reportRows_appearances($filteredPlayers, $pdo, $seasonId, $teamNames, __DIR__ . '/data/matches.json', $season['start_date'] ?? null, $season['end_date'] ?? null);
        break;
    case 'match_events':
        $reportRows = reportRows_match_events($filteredFixtures, hub_matchday_events_by_fixture($pdo), $playerFilter, $eventFilter);
        break;
    case 'results_summary':
        $reportRows = reportRows_results_summary($filteredFixtures);
        break;
    case 'roster':
        $reportRows = reportRows_roster($filteredPlayers, $playerAgreementsByPlayer, $teamNames);
        break;
    case 'sponsor_directory':
        $reportRows = reportRows_sponsor_directory($filteredSponsors, $lifetimeBySponsor, $seasonActiveBySponsor);
        break;
    case 'sponsor_showcase':
        $reportRows = reportRows_sponsor_showcase($allActivePackages, $allAgreements, $selectedPackageIds, $sponsorContactById);
        $showcaseGroups = reportSponsorShowcaseGroups($allActivePackages, $allAgreements, $selectedPackageIds);
        break;
    case 'portfolio':
    default:
        $reportRows = reportRows_portfolio($filteredLiveAgreements);
        break;
}

/* =========================================================================
   Headline KPI cards — change with the active report so they stay meaningful
   ========================================================================= */

$currentPortfolio = array_values(array_filter($filteredLiveAgreements, static fn(array $a): bool => in_array((string)$a['effective_status'], ['active', 'scheduled'], true)));
$portfolioMetrics = buildPortfolioMetrics($currentPortfolio);
$totals = $portfolioMetrics['totals'];
$collectionRate = $totals['value'] > 0 ? min(100, ($totals['paid'] / $totals['value']) * 100) : 0.0;

$kpis = [];
switch (true) {
    case in_array($reportType, ['payments', 'payment_methods'], true):
        $paymentTotal = array_sum(array_map(static fn(array $p): float => (float)$p['amount'], $filteredPayments));
        $txCount = count($filteredPayments);
        $kpis = [
            ['label' => 'Transactions', 'value' => number_format($txCount), 'icon' => 'fa-receipt', 'tone' => 'primary'],
            ['label' => 'Collected', 'value' => gbp($paymentTotal), 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
            ['label' => 'Average payment', 'value' => gbp($txCount > 0 ? $paymentTotal / $txCount : 0), 'icon' => 'fa-scale-balanced', 'tone' => 'info'],
            ['label' => 'Distinct sponsors', 'value' => number_format(count(array_unique(array_column($filteredPayments, 'sponsor_name')))), 'icon' => 'fa-building', 'tone' => 'neutral'],
        ];
        break;
    case $reportType === 'outstanding':
        $outstandingTotal = array_sum(array_column($reportRows, 'Outstanding'));
        $kpis = [
            ['label' => 'Agreements owing', 'value' => number_format(count($reportRows)), 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger'],
            ['label' => 'Total outstanding', 'value' => gbp($outstandingTotal), 'icon' => 'fa-sterling-sign', 'tone' => 'danger'],
            ['label' => 'Fully unpaid', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Urgency'] === 'Overdue'))), 'icon' => 'fa-circle-xmark', 'tone' => 'warning'],
            ['label' => 'Portfolio collected', 'value' => number_format($collectionRate, 0) . '%', 'icon' => 'fa-circle-check', 'tone' => 'success'],
        ];
        break;
    case $reportType === 'renewals':
        $kpis = [
            ['label' => 'On the watchlist', 'value' => number_format(count($reportRows)), 'icon' => 'fa-hourglass-half', 'tone' => 'primary'],
            ['label' => 'Overdue', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Urgency'] === 'Overdue'))), 'icon' => 'fa-circle-xmark', 'tone' => 'danger'],
            ['label' => 'Due soon', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Urgency'] === 'Due soon'))), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning'],
            ['label' => 'Value at stake', 'value' => gbp(array_sum(array_column($reportRows, 'Value'))), 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
        ];
        break;
    case $reportType === 'complimentary':
        $kpis = [
            ['label' => 'Complimentary agreements', 'value' => number_format(count($reportRows)), 'icon' => 'fa-gift', 'tone' => 'info'],
            ['label' => 'Goodwill value', 'value' => gbp(array_sum(array_column($reportRows, 'List Value'))), 'icon' => 'fa-sterling-sign', 'tone' => 'primary'],
        ];
        break;
    case $reportType === 'season_tickets':
        $ticketRevenue = array_sum(array_column($reportRows, 'Amount'));
        $activeTickets = count(array_filter($reportRows, static fn(array $r): bool => (string)$r['Ticket Status'] === 'active'));
        $paidTickets = count(array_filter($reportRows, static fn(array $r): bool => (string)$r['Payment Status'] === 'paid'));
        $kpis = [
            ['label' => 'Season tickets', 'value' => number_format(count($reportRows)), 'icon' => 'fa-id-card', 'tone' => 'primary'],
            ['label' => 'Active tickets', 'value' => number_format($activeTickets), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Paid orders', 'value' => number_format($paidTickets), 'icon' => 'fa-receipt', 'tone' => 'info'],
            ['label' => 'Ticket income', 'value' => gbp($ticketRevenue), 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
        ];
        break;
    case $reportType === 'season_ticket_comparison':
        $mainTickets = $seasonCompareMain ? (int)$seasonCompareMain['Tickets'] : 0;
        $mainIncome = $seasonCompareMain ? (float)$seasonCompareMain['Gross Income'] : 0.0;
        $mainSeasonLabel = $seasonCompareMain ? (string)$seasonCompareMain['Season'] : '-';
        $primaryCompare = $seasonComparePairwise[0] ?? null;
        $kpis = [
            ['label' => 'Main season', 'value' => $mainSeasonLabel, 'meta' => $seasonCompareAgainst ? ('vs ' . count($seasonCompareAgainst) . ' other season' . (count($seasonCompareAgainst) === 1 ? '' : 's')) : 'No comparison selected', 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
            ['label' => 'Tickets sold', 'value' => number_format($mainTickets), 'meta' => $primaryCompare ? (reportKpiDeltaMeta((float)$primaryCompare['Tickets Change'], (float)$primaryCompare['Ticket Growth %'], false) . ' vs ' . (string)$primaryCompare['Compare Season']) : 'This season only', 'icon' => 'fa-id-card', 'tone' => 'info'],
            ['label' => 'Gross income', 'value' => gbp($mainIncome), 'meta' => $primaryCompare ? (reportKpiDeltaMeta((float)$primaryCompare['Income Change'], (float)$primaryCompare['Income Growth %'], true) . ' vs ' . (string)$primaryCompare['Compare Season']) : 'This season only', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
            ['label' => 'Renewal rate', 'value' => $primaryCompare ? (number_format((float)$primaryCompare['Renewal Rate %'], 1) . '%') : '-', 'meta' => $primaryCompare ? (number_format((int)$primaryCompare['Renewed Holders']) . ' renewed from ' . (string)$primaryCompare['Compare Season']) : 'Choose a season to compare against', 'icon' => 'fa-rotate', 'tone' => 'primary'],
            ['label' => 'Comparing against', 'value' => number_format(count($seasonCompareAgainst)), 'meta' => $seasonCompareAgainst ? implode(', ', array_column($seasonCompareAgainst, 'Season')) : 'Choose seasons above', 'icon' => 'fa-layer-group', 'tone' => 'neutral'],
        ];
        break;
    case $reportType === 'match_coverage':
        $needsSponsor = count(array_filter($reportRows, static fn(array $r) => $r['Coverage'] === 'Needs sponsor'));
        $kpis = [
            ['label' => 'Fixtures', 'value' => number_format(count($reportRows)), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
            ['label' => 'Fully sponsored', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Coverage'] === 'Fully sponsored'))), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Need a sponsor', 'value' => number_format($needsSponsor), 'icon' => 'fa-triangle-exclamation', 'tone' => $needsSponsor > 0 ? 'danger' : 'success'],
            ['label' => 'Match sponsorship value', 'value' => gbp(array_sum(array_column($reportRows, 'Value'))), 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
        ];
        break;
    case $reportType === 'fixtures':
        $kpis = [
            ['label' => 'Fixtures', 'value' => number_format(count($reportRows)), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
            ['label' => 'Wins', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Result'] === 'Win'))), 'icon' => 'fa-trophy', 'tone' => 'success'],
            ['label' => 'Draws', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Result'] === 'Draw'))), 'icon' => 'fa-handshake', 'tone' => 'warning'],
            ['label' => 'Losses', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Result'] === 'Loss'))), 'icon' => 'fa-circle-xmark', 'tone' => 'danger'],
        ];
        break;
    case $reportType === 'roster':
        $notSponsored = count(array_filter($reportRows, static fn(array $r) => $r['Coverage'] === 'Not sponsored'));
        $kpis = [
            ['label' => 'Players', 'value' => number_format(count($reportRows)), 'icon' => 'fa-users', 'tone' => 'primary'],
            ['label' => 'Fully sponsored', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Coverage'] === 'Fully sponsored'))), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Partially sponsored', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => str_starts_with((string)$r['Coverage'], 'Partially')))), 'icon' => 'fa-circle-half-stroke', 'tone' => 'warning'],
            ['label' => 'Not sponsored', 'value' => number_format($notSponsored), 'icon' => 'fa-triangle-exclamation', 'tone' => $notSponsored > 0 ? 'danger' : 'success'],
        ];
        break;
    case $reportType === 'sponsor_directory':
        $kpis = [
            ['label' => 'Sponsors on file', 'value' => number_format(count($reportRows)), 'icon' => 'fa-address-book', 'tone' => 'primary'],
            ['label' => 'Active', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Sponsor Status'] === 'Active'))), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Lifetime value', 'value' => gbp(array_sum(array_column($reportRows, 'Lifetime Value'))), 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
            ['label' => 'No socials on file', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Social Presence'] === 'None on file'))), 'icon' => 'fa-circle-info', 'tone' => 'neutral'],
        ];
        break;
    case $reportType === 'appearances':
        $usedPlayers = count(array_filter($reportRows, static fn(array $r) => (int)$r['Apps'] > 0));
        $totalYellow = (int)array_sum(array_column($reportRows, 'Yellow Cards'));
        $totalRed = (int)array_sum(array_column($reportRows, 'Red Cards'));
        $kpis = [
            ['label' => 'Players used', 'value' => number_format($usedPlayers), 'meta' => 'Of ' . number_format(count($reportRows)) . ' listed', 'icon' => 'fa-person-running', 'tone' => 'primary'],
            ['label' => 'Total appearances', 'value' => number_format(array_sum(array_column($reportRows, 'Apps'))), 'icon' => 'fa-layer-group', 'tone' => 'info'],
            ['label' => 'Goals scored', 'value' => number_format(array_sum(array_column($reportRows, 'Goals'))), 'icon' => 'fa-futbol', 'tone' => 'success'],
            ['label' => 'Cards', 'value' => number_format($totalYellow + $totalRed), 'meta' => number_format($totalYellow) . ' yellow, ' . number_format($totalRed) . ' red', 'icon' => 'fa-square', 'tone' => ($totalYellow + $totalRed) > 0 ? 'warning' : 'success'],
        ];
        break;
    case $reportType === 'match_events':
        $kpis = [
            ['label' => 'Logged events', 'value' => number_format(count($reportRows)), 'icon' => 'fa-list', 'tone' => 'primary'],
            ['label' => 'Goals', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => str_starts_with((string)$r['Event'], 'Goal')))), 'icon' => 'fa-futbol', 'tone' => 'success'],
            ['label' => 'Yellow cards', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Event'] === 'Yellow Card'))), 'icon' => 'fa-square', 'tone' => 'warning'],
            ['label' => 'Red cards', 'value' => number_format(count(array_filter($reportRows, static fn(array $r) => $r['Event'] === 'Red Card'))), 'icon' => 'fa-square', 'tone' => 'danger'],
        ];
        break;
    case $reportType === 'results_summary':
        $summaryTotal = null;
        foreach ($reportRows as $row) {
            if ((string)($row['Competition'] ?? '') === 'Total') {
                $summaryTotal = $row;
                break;
            }
        }
        $kpis = $summaryTotal ? [
            ['label' => 'Played', 'value' => number_format($summaryTotal['Played']), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
            ['label' => 'Won', 'value' => number_format($summaryTotal['Won']), 'meta' => number_format($summaryTotal['Drawn']) . ' drawn, ' . number_format($summaryTotal['Lost']) . ' lost', 'icon' => 'fa-trophy', 'tone' => 'success'],
            ['label' => 'Goal difference', 'value' => ($summaryTotal['GD'] > 0 ? '+' : '') . number_format($summaryTotal['GD']), 'meta' => number_format($summaryTotal['GF']) . ' for, ' . number_format($summaryTotal['GA']) . ' against', 'icon' => 'fa-scale-balanced', 'tone' => $summaryTotal['GD'] >= 0 ? 'success' : 'danger'],
            ['label' => 'Points', 'value' => number_format($summaryTotal['Points']), 'icon' => 'fa-star', 'tone' => 'info'],
        ] : [
            ['label' => 'Played', 'value' => '0', 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
        ];
        break;
    case $reportType === 'package_performance':
        $filledCount = count(array_filter($reportRows, static fn(array $r) => in_array($r['Fill Status'], ['Filled', 'In use'], true)));
        $needsSponsorCount = count(array_filter($reportRows, static fn(array $r) => $r['Fill Status'] === 'Needs sponsor'));
        $kpis = [
            ['label' => 'Package types', 'value' => number_format(count($reportRows)), 'icon' => 'fa-boxes-stacked', 'tone' => 'primary'],
            ['label' => 'Filled / in use', 'value' => number_format($filledCount), 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Needs a sponsor', 'value' => number_format($needsSponsorCount), 'icon' => 'fa-triangle-exclamation', 'tone' => $needsSponsorCount > 0 ? 'danger' : 'success'],
            ['label' => 'Total agreed value', 'value' => gbp(array_sum(array_column($reportRows, 'Value'))), 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
        ];
        break;
    case $reportType === 'sponsor_showcase':
        $kpis = [
            ['label' => 'Sponsorship types shown', 'value' => number_format(count($selectedPackageIds)), 'meta' => 'Of ' . number_format(count($allActivePackages)) . ' available', 'icon' => 'fa-award', 'tone' => 'primary'],
            ['label' => 'Sponsors shown', 'value' => number_format(count(array_unique(array_column($reportRows, 'Sponsor')))), 'meta' => number_format(count($reportRows)) . ' placement' . (count($reportRows) === 1 ? '' : 's'), 'icon' => 'fa-building', 'tone' => 'info'],
        ];
        break;
    default:
        $kpis = [
            ['label' => 'Active agreements', 'value' => number_format($totals['agreements']), 'meta' => 'Across ' . number_format($totals['sponsors']) . ' sponsors', 'icon' => 'fa-layer-group', 'tone' => 'primary'],
            ['label' => 'Portfolio value', 'value' => gbp($totals['value']), 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
            ['label' => 'Collected', 'value' => gbp($totals['paid']), 'meta' => number_format($collectionRate, 0) . '% of value', 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'Outstanding', 'value' => gbp($totals['outstanding']), 'meta' => 'Still to collect', 'icon' => 'fa-clock', 'tone' => $totals['outstanding'] > 0 ? 'danger' : 'success'],
        ];
        break;
}

/* =========================================================================
   Export
   ========================================================================= */

if (isset($_GET['export'])) {
    $format = (string)$_GET['export'];
    $safeSeason = preg_replace('/[^a-z0-9]+/i', '-', strtolower($seasonName)) ?: 'season';
    $safeReport = preg_replace('/[^a-z0-9]+/i', '-', strtolower($reportType)) ?: 'report';
    reportExport($reportRows, $format, $safeReport . '-' . $safeSeason, $seasonName . ' - ' . $activeReport['label'], $activeReport['desc'], $pdfOrientation, $showcaseGroups);
}

/* =========================================================================
   View helpers
   ========================================================================= */

$exportQuery = static function (string $format) use ($reportType, $searchTerm, $statusFilter, $scopeFilter, $packageFilter, $sponsorFilter, $playerFilter, $teamFilter, $methodFilter, $eventFilter, $dateFrom, $dateTo, $playerStatusFilter, $sponsorStatusFilter, $renewalWindow, $pdfOrientation, $selectedPackageIds): string {
    $params = [
        'export' => $format, 'report_type' => $reportType, 'search' => $searchTerm, 'status' => $statusFilter, 'scope' => $scopeFilter,
        'package' => $packageFilter, 'sponsor' => $sponsorFilter, 'player' => $playerFilter, 'team' => $teamFilter, 'method' => $methodFilter, 'event' => $eventFilter,
        'from' => $dateFrom, 'to' => $dateTo, 'player_status' => $playerStatusFilter, 'sponsor_status' => $sponsorStatusFilter, 'window' => $renewalWindow,
    ];
    if ($reportType === 'sponsor_showcase') {
        $params['package_ids'] = $selectedPackageIds;
    }
    if ($format === 'pdf') {
        $params['pdf_orientation'] = $pdfOrientation;
    }
    return '?' . http_build_query(array_filter($params, static fn($v) => $v !== ''));
};

$groupOrder = ['Sponsorship', 'Breakdowns', 'Ticketing', 'Matchday', 'Club'];
$groupedReports = [];
foreach ($REPORTS as $key => $definition) {
    // Don't even show a report type as a picker option if the user can't
    // open it — not just a 403 if they force the URL.
    if (!hub_auth_has_any_capability($reportTypeCapability[$key] ?? $defaultReportCapability)) {
        continue;
    }
    $groupedReports[$definition['group']][$key] = $definition;
}
$isSeasonTicketReport = in_array($reportType, ['season_tickets', 'season_ticket_comparison'], true);
$visiblePackageOptions = $isSeasonTicketReport ? array_values($seasonTicketTypeOptions) : $packageOptions;
$visiblePaymentMethodOptions = $isSeasonTicketReport ? array_values($seasonTicketMethodOptions) : $paymentMethodOptions;

$compareSeasonNamesById = array_column($seasonCompareOptions, 'name', 'id');
$compareSeasonsSummary = match (count($compareSeasonIds)) {
    0 => 'None selected',
    1, 2 => implode(', ', array_map(static fn(int $id) => $compareSeasonNamesById[$id] ?? '', $compareSeasonIds)),
    default => count($compareSeasonIds) . ' seasons selected',
};

$packageTypesSummary = match (true) {
    count($selectedPackageIds) === count($allActivePackages) => 'All types',
    count($selectedPackageIds) === 0 => 'None selected',
    count($selectedPackageIds) <= 2 => implode(', ', array_map(static fn(int $id) => $packageNameById[$id] ?? '', $selectedPackageIds)),
    default => count($selectedPackageIds) . ' types selected',
};
?>

<div class="rpt-page">
    <div class="rpt-layout">
        <nav class="rpt-picker" aria-label="Report picker">
            <?php foreach ($groupOrder as $groupName): ?>
                <?php if (empty($groupedReports[$groupName])) continue; ?>
                <div class="rpt-picker__group">
                    <div class="rpt-picker__group-label"><?= h($groupName) ?></div>
                    <?php foreach ($groupedReports[$groupName] as $key => $definition): ?>
                        <a class="rpt-picker__item <?= $key === $reportType ? 'is-active' : '' ?>" href="?<?= h(http_build_query(['report_type' => $key])) ?>">
                            <span class="rpt-picker__icon"><i class="fa-solid <?= h($definition['icon']) ?>" aria-hidden="true"></i></span>
                            <span class="rpt-picker__text">
                                <strong><?= h($definition['label']) ?></strong>
                                <small><?= h($definition['desc']) ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="rpt-main">
            <form method="get" class="rpt-filter-card" id="rptFilterForm">
                <input type="hidden" name="report_type" value="<?= h($reportType) ?>">
                <input type="hidden" name="pdf_orientation" value="<?= h($pdfOrientation) ?>">
                <div class="rpt-filter-grid">
                    <div class="rpt-field" data-filter="main_season">
                        <label class="form-label" for="rptMainSeason">Main season</label>
                        <select id="rptMainSeason" name="main_season_id" class="form-select form-select-sm">
                            <?php foreach ($seasonCompareOptions as $option): ?>
                                <option value="<?= (int)$option['id'] ?>" <?= $mainSeasonId === (int)$option['id'] ? 'selected' : '' ?>><?= h($option['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="compare_seasons">
                        <label class="form-label" id="rptCompareSeasonsLabel">Compare against</label>
                        <div class="dropdown rpt-checkbox-dropdown">
                            <button type="button" class="form-select form-select-sm rpt-checkbox-dropdown__toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-labelledby="rptCompareSeasonsLabel">
                                <span data-checkbox-dropdown-summary><?= h($compareSeasonsSummary) ?></span>
                            </button>
                            <div class="dropdown-menu rpt-checkbox-dropdown__menu" aria-label="Seasons to compare against">
                                <?php if (count($seasonCompareOptions) <= 1): ?>
                                    <span class="rpt-checkbox-dropdown__empty">No other seasons on record yet.</span>
                                <?php endif; ?>
                                <?php foreach ($seasonCompareOptions as $option): ?>
                                    <?php if ((int)$option['id'] === $mainSeasonId) continue; ?>
                                    <label class="rpt-checkbox-dropdown__item">
                                        <input type="checkbox" name="compare_season_id[]" value="<?= (int)$option['id'] ?>" <?= in_array((int)$option['id'], $compareSeasonIds, true) ? 'checked' : '' ?>>
                                        <span><?= h($option['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="rpt-field rpt-field--search" data-filter="search">
                        <label class="form-label" for="rptSearch">Search</label>
                        <div class="rpt-search-input">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="rptSearch" type="search" name="search" value="<?= h($searchTerm) ?>" placeholder="Search this report&hellip;" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="rpt-field" data-filter="status">
                        <label class="form-label" for="rptStatus"><?= $isSeasonTicketReport ? 'Order status' : 'Agreement status' ?></label>
                        <select id="rptStatus" name="status" class="form-select form-select-sm">
                            <option value="">Any status</option>
                            <?php if ($isSeasonTicketReport): ?>
                                <?php foreach (['draft', 'pending_payment', 'paid', 'cancelled', 'partially_refunded', 'refunded'] as $value): ?>
                                    <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="scope">
                        <label class="form-label" for="rptScope">Scope</label>
                        <select id="rptScope" name="scope" class="form-select form-select-sm">
                            <option value="">All scopes</option>
                            <?php foreach (REPORT_SCOPE_KEYS as $scopeKey): ?>
                                <option value="<?= h($scopeKey) ?>" <?= $scopeFilter === $scopeKey ? 'selected' : '' ?>><?= h(reportScopeLabel($scopeKey)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="package">
                        <label class="form-label" for="rptPackage"><?= $isSeasonTicketReport ? 'Ticket type' : 'Package' ?></label>
                        <select id="rptPackage" name="package" class="form-select form-select-sm">
                            <option value=""><?= $isSeasonTicketReport ? 'All ticket types' : 'All packages' ?></option>
                            <?php foreach ($visiblePackageOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $packageFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="sponsor">
                        <label class="form-label" for="rptSponsor">Sponsor</label>
                        <select id="rptSponsor" name="sponsor" class="form-select form-select-sm">
                            <option value="">All sponsors</option>
                            <?php foreach ($sponsorOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $sponsorFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="player">
                        <label class="form-label" for="rptPlayer">Player</label>
                        <select id="rptPlayer" name="player" class="form-select form-select-sm">
                            <option value="">All players</option>
                            <?php foreach ($playerOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $playerFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="event">
                        <label class="form-label" for="rptEvent">Event</label>
                        <select id="rptEvent" name="event" class="form-select form-select-sm">
                            <option value="">Any event</option>
                            <?php foreach (['Goal' => 'Goal', 'Goal (OG)' => 'Own goal', 'Yellow Card' => 'Yellow card', 'Red Card' => 'Red card'] as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $eventFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="team">
                        <label class="form-label" for="rptTeam">Team</label>
                        <select id="rptTeam" name="team" class="form-select form-select-sm">
                            <option value="">All teams</option>
                            <?php foreach ($teamNames as $teamName): ?>
                                <option value="<?= h($teamName) ?>" <?= $teamFilter === $teamName ? 'selected' : '' ?>><?= h($teamName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="method">
                        <label class="form-label" for="rptMethod">Payment method</label>
                        <select id="rptMethod" name="method" class="form-select form-select-sm">
                            <option value="">Any method</option>
                            <?php foreach ($visiblePaymentMethodOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $methodFilter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="player_status">
                        <label class="form-label" for="rptPlayerStatus">Player status</label>
                        <select id="rptPlayerStatus" name="player_status" class="form-select form-select-sm">
                            <option value="">Any status</option>
                            <?php foreach (['current' => 'Current', 'trialist' => 'Trialist', 'loan' => 'Loan', 'injured' => 'Injured', 'left' => 'Left', 'retired' => 'Retired'] as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $playerStatusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="sponsor_status">
                        <label class="form-label" for="rptSponsorStatus">Sponsor status</label>
                        <select id="rptSponsorStatus" name="sponsor_status" class="form-select form-select-sm">
                            <option value="">Any status</option>
                            <option value="active" <?= $sponsorStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $sponsorStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="rpt-field" data-filter="date">
                        <label class="form-label" for="rptFrom">From</label>
                        <input id="rptFrom" type="date" name="from" value="<?= h($dateFrom) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="rpt-field" data-filter="date">
                        <label class="form-label" for="rptTo">To</label>
                        <input id="rptTo" type="date" name="to" value="<?= h($dateTo) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="rpt-field" data-filter="package_types">
                        <label class="form-label" id="rptPackageTypesLabel">Sponsorship types</label>
                        <div class="dropdown rpt-checkbox-dropdown">
                            <button type="button" class="form-select form-select-sm rpt-checkbox-dropdown__toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-labelledby="rptPackageTypesLabel">
                                <span data-package-types-summary><?= h($packageTypesSummary) ?></span>
                            </button>
                            <div class="dropdown-menu rpt-checkbox-dropdown__menu" aria-label="Sponsorship types to show">
                                <?php foreach ($allActivePackages as $package): ?>
                                    <label class="rpt-checkbox-dropdown__item">
                                        <input type="checkbox" name="package_ids[]" value="<?= (int)$package['id'] ?>" <?= in_array((int)$package['id'], $selectedPackageIds, true) ? 'checked' : '' ?>>
                                        <span><?= h((string)$package['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($reportType === 'renewals'): ?>
                        <div class="rpt-field" data-filter="window">
                            <label class="form-label" for="rptWindow">Renewal window</label>
                            <select id="rptWindow" name="window" class="form-select form-select-sm">
                                <?php foreach ([30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '180 days'] as $value => $label): ?>
                                    <option value="<?= (int)$value ?>" <?= $renewalWindow === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="rpt-filter-actions">
                    <button type="submit" class="btn rpt-btn btn-brand"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply filters</button>
                    <a href="?report_type=<?= h($reportType) ?>" class="btn rpt-btn rpt-btn--ghost">Reset</a>
                    <span class="rpt-filter-actions__count"><?= number_format(count($reportRows)) ?> result<?= count($reportRows) === 1 ? '' : 's' ?></span>
                </div>
            </form>

            <?php hub_render_metric_grid($kpis, $activeReport['label'] . ' summary'); ?>

            <?php if ($reportType === 'portfolio'): ?>
                <div class="rpt-insight-grid">
                    <div class="rpt-insight-card">
                        <h3><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Scope mix</h3>
                        <ul class="rpt-bar-list">
                            <?php $maxValue = max(array_column($portfolioMetrics['categoryTotals'], 'value') ?: [0]); ?>
                            <?php foreach ($portfolioMetrics['categoryTotals'] as $category): ?>
                                <?php $meta = reportScopeMeta((string)$category['scope']); $width = $maxValue > 0 ? max(4, ($category['value'] / $maxValue) * 100) : 0; ?>
                                <li>
                                    <div class="rpt-bar-list__label">
                                        <span class="rpt-tag rpt-tag--<?= h($meta['key']) ?>"><i class="fa-solid <?= h($meta['icon']) ?>" aria-hidden="true"></i><?= h($meta['label']) ?></span>
                                        <span><?= h($category['category']) ?></span>
                                        <strong><?= h(gbp($category['value'])) ?></strong>
                                    </div>
                                    <div class="rpt-bar-list__track"><div class="rpt-bar-list__fill rpt-bar-list__fill--<?= h($meta['key']) ?>" style="width: <?= number_format($width, 1) ?>%"></div></div>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$portfolioMetrics['categoryTotals']): ?>
                                <li class="rpt-empty-inline">No matching sponsorship data.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="rpt-insight-card">
                        <h3><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Payment health</h3>
                        <div class="rpt-health-bar" role="img" aria-label="<?= number_format($collectionRate, 0) ?>% collected">
                            <?php if ($totals['value'] > 0): ?>
                                <span class="rpt-health-bar__segment rpt-health-bar__segment--success" style="width: <?= number_format(($totals['paid'] / $totals['value']) * 100, 1) ?>%"></span>
                                <span class="rpt-health-bar__segment rpt-health-bar__segment--danger" style="width: <?= number_format(($totals['outstanding'] / $totals['value']) * 100, 1) ?>%"></span>
                            <?php endif; ?>
                        </div>
                        <ul class="rpt-legend-list">
                            <li><span class="rpt-pill rpt-pill--success">Paid</span><strong><?= gbp($totals['paid']) ?></strong></li>
                            <li><span class="rpt-pill rpt-pill--danger">Outstanding</span><strong><?= gbp($totals['outstanding']) ?></strong></li>
                            <li><span class="rpt-pill rpt-pill--info">Complimentary</span><strong><?= number_format($totals['complimentary']) ?> agreement<?= $totals['complimentary'] === 1 ? '' : 's' ?></strong></li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($reportType === 'season_ticket_comparison' && $seasonCompareMain !== null): ?>
                <?php
                $compareMetrics = [
                    ['key' => 'Tickets', 'label' => 'Tickets', 'icon' => 'fa-ticket', 'type' => 'count', 'diff' => true],
                    ['key' => 'Active Tickets', 'label' => 'Active tickets', 'icon' => 'fa-id-card', 'type' => 'count', 'diff' => true],
                    ['key' => 'Paid Orders', 'label' => 'Paid orders', 'icon' => 'fa-receipt', 'type' => 'count', 'diff' => true],
                    ['key' => 'Gross Income', 'label' => 'Gross income', 'icon' => 'fa-sterling-sign', 'type' => 'money', 'diff' => true],
                    ['key' => 'Average Ticket Value', 'label' => 'Average ticket value', 'icon' => 'fa-gem', 'type' => 'money', 'diff' => true],
                    ['key' => 'Complimentary', 'label' => 'Complimentary', 'icon' => 'fa-gift', 'type' => 'count', 'diff' => true],
                    ['key' => 'Cancelled/Refunded', 'label' => 'Cancelled / Refunded', 'icon' => 'fa-rotate-left', 'type' => 'count', 'diff' => true],
                    ['key' => 'Unique Holders', 'label' => 'Unique holders', 'icon' => 'fa-user-group', 'type' => 'count', 'diff' => true],
                    ['key' => 'New Holders', 'label' => 'New holders', 'icon' => 'fa-star', 'type' => 'count', 'diff' => false],
                    ['key' => 'Renewed Holders', 'label' => 'Renewed holders', 'icon' => 'fa-rotate', 'type' => 'count', 'diff' => false],
                    ['key' => 'Lapsed From Previous', 'label' => 'Lapsed from previous', 'icon' => 'fa-clock', 'type' => 'count', 'diff' => false],
                    ['key' => 'Renewal Rate %', 'label' => 'Renewal rate %', 'icon' => 'fa-percent', 'type' => 'pct', 'diff' => false],
                ];
                $formatCompareMetric = static function (string $type, float $value): string {
                    return match ($type) {
                        'money' => gbp($value),
                        'pct' => number_format($value, 1) . '%',
                        default => number_format($value),
                    };
                };
                $chartTrendSeasons = array_merge([$seasonCompareMain], $seasonCompareAgainst);
                usort($chartTrendSeasons, static fn(array $a, array $b): int => strcmp((string)$a['sort_date'], (string)$b['sort_date']));
                $chartRenewalSeasons = array_reverse($seasonComparePairwise);
                ?>
                <div class="rpt-chart-grid">
                    <div class="rpt-insight-card rpt-chart-card">
                        <h3><i class="fa-solid fa-ticket" aria-hidden="true"></i> Tickets sold</h3>
                        <div class="rpt-chart-wrap"><canvas id="rptTicketsChart" role="img" aria-label="Bar chart of season tickets sold, by season"></canvas></div>
                    </div>
                    <div class="rpt-insight-card rpt-chart-card">
                        <h3><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i> Gross income</h3>
                        <div class="rpt-chart-wrap"><canvas id="rptIncomeChart" role="img" aria-label="Bar chart of gross season ticket income, by season"></canvas></div>
                    </div>
                    <div class="rpt-insight-card rpt-chart-card">
                        <h3><i class="fa-solid fa-rotate" aria-hidden="true"></i> Renewal rate %</h3>
                        <div class="rpt-chart-wrap"><canvas id="rptRenewalChart" role="img" aria-label="Bar chart of renewal rate percentage, main season vs each compared season"></canvas></div>
                    </div>
                </div>

                <div class="rpt-compare-grid">
                    <div class="rpt-table-card">
                        <div class="rpt-table-card__header">
                            <div>
                                <div class="rpt-table-card__eyebrow">Main season vs comparisons</div>
                                <h2>Detailed season comparison</h2>
                                <p><?= h((string)$seasonCompareMain['Season']) ?><?= $seasonCompareAgainst ? (' compared with ' . h(implode(', ', array_column($seasonCompareAgainst, 'Season')))) : ' — choose a season above to compare against' ?>, metric by metric.</p>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table hub-data-table rpt-table rpt-compare-table mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Metric</th>
                                        <th scope="col"><?= h((string)$seasonCompareMain['Season']) ?></th>
                                        <?php foreach ($seasonCompareAgainst as $compareGroup): ?>
                                            <th scope="col"><?= h((string)$compareGroup['Season']) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($compareMetrics as $metric): ?>
                                        <tr>
                                            <td class="rpt-compare-table__metric">
                                                <span class="rpt-compare-table__metric-label"><i class="fa-solid <?= h($metric['icon']) ?>" aria-hidden="true"></i><?= h($metric['label']) ?></span>
                                                <span class="rpt-compare-table__spacer">&nbsp;</span>
                                            </td>
                                            <td>
                                                <div class="rpt-compare-table__cell">
                                                    <?php if ($metric['diff']): ?>
                                                        <span class="rpt-num"><?= h($formatCompareMetric($metric['type'], reportSeasonTicketOwnValue($seasonCompareMain, $metric['key']))) ?></span>
                                                    <?php else: ?>
                                                        <span class="rpt-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                    <span class="rpt-compare-table__spacer">&nbsp;</span>
                                                </div>
                                            </td>
                                            <?php foreach ($seasonCompareAgainst as $i => $compareGroup): ?>
                                                <td>
                                                    <div class="rpt-compare-table__cell">
                                                        <?php if ($metric['diff']):
                                                            $mainValue = reportSeasonTicketOwnValue($seasonCompareMain, $metric['key']);
                                                            $compareValue = reportSeasonTicketOwnValue($compareGroup, $metric['key']);
                                                            $delta = $mainValue - $compareValue;
                                                            $pctChange = abs($compareValue) > 0.0001 ? ($delta / $compareValue) * 100 : null;
                                                            ?>
                                                            <span class="rpt-num"><?= h($formatCompareMetric($metric['type'], $compareValue)) ?></span>
                                                            <?php if (abs($delta) < 0.005): ?>
                                                                <span class="rpt-muted">No change</span>
                                                            <?php else: ?>
                                                                <span class="rpt-delta rpt-delta--<?= $delta > 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-caret-<?= $delta > 0 ? 'up' : 'down' ?>" aria-hidden="true"></i><?= h($formatCompareMetric($metric['type'], abs($delta))) ?><?= $pctChange !== null ? ' (' . number_format(abs($pctChange), 1) . '%)' : '' ?></span>
                                                            <?php endif; ?>
                                                        <?php else:
                                                            $pairwise = $seasonComparePairwise[$i] ?? [];
                                                            ?>
                                                            <span class="rpt-num"><?= h($formatCompareMetric($metric['type'], (float)($pairwise[$metric['key']] ?? 0))) ?></span>
                                                            <span class="rpt-compare-table__spacer">&nbsp;</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rpt-compare-side">
                        <div class="rpt-insight-card">
                            <h3><i class="fa-solid fa-rotate" aria-hidden="true"></i> Renewal breakdown</h3>
                            <?php if ($seasonComparePairwise): ?>
                                <?php $primaryPairwise = $seasonComparePairwise[0]; ?>
                                <p class="rpt-empty-inline mb-2"><?= h((string)$seasonCompareMain['Season']) ?> vs <?= h((string)$primaryPairwise['Compare Season']) ?></p>
                                <div class="rpt-donut-wrap">
                                    <canvas id="rptRenewalDonut" role="img" aria-label="Donut chart of renewal rate for <?= h((string)$seasonCompareMain['Season']) ?> vs <?= h((string)$primaryPairwise['Compare Season']) ?>"></canvas>
                                    <div class="rpt-donut-center">
                                        <strong><?= number_format((float)$primaryPairwise['Renewal Rate %'], 1) ?>%</strong>
                                        <span>Renewal rate</span>
                                    </div>
                                </div>
                                <?php $primaryHolderTotal = (int)$primaryPairwise['Renewed Holders'] + (int)$primaryPairwise['Lapsed From Previous']; ?>
                                <ul class="rpt-legend-list">
                                    <li><span class="rpt-pill rpt-pill--primary">Renewed holders</span><strong><?= number_format((int)$primaryPairwise['Renewed Holders']) ?> (<?= number_format($primaryHolderTotal > 0 ? ((int)$primaryPairwise['Renewed Holders'] / $primaryHolderTotal) * 100 : 0, 1) ?>%)</strong></li>
                                    <li><span class="rpt-pill rpt-pill--neutral">Lapsed from previous</span><strong><?= number_format((int)$primaryPairwise['Lapsed From Previous']) ?> (<?= number_format($primaryHolderTotal > 0 ? ((int)$primaryPairwise['Lapsed From Previous'] / $primaryHolderTotal) * 100 : 0, 1) ?>%)</strong></li>
                                </ul>
                                <div class="rpt-compare-stat"><span>Total holders in <?= h((string)$primaryPairwise['Compare Season']) ?></span><strong><?= number_format($primaryHolderTotal) ?></strong></div>
                                <?php if (count($seasonComparePairwise) > 1): ?>
                                    <ul class="rpt-bar-list rpt-bar-list--compact">
                                        <?php foreach ($seasonComparePairwise as $i => $pairwise): ?>
                                            <?php if ($i === 0) continue; ?>
                                            <li>
                                                <div class="rpt-bar-list__label">
                                                    <span><?= h((string)$pairwise['Compare Season']) ?></span>
                                                    <strong><?= number_format((float)$pairwise['Renewal Rate %'], 1) ?>%</strong>
                                                </div>
                                                <div class="rpt-bar-list__track"><div class="rpt-bar-list__fill" style="width: <?= number_format(min(100, max(2, (float)$pairwise['Renewal Rate %'])), 1) ?>%"></div></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="rpt-empty-inline">Choose at least one season to compare against, to see a renewal breakdown.</p>
                            <?php endif; ?>
                        </div>

                        <div class="rpt-insight-card">
                            <h3><i class="fa-solid fa-id-card" aria-hidden="true"></i> Ticket types comparison</h3>
                            <?php if ($seasonCompareAgainst): ?>
                                <?php
                                $allTypeNames = array_keys($seasonCompareMain['types']);
                                foreach ($seasonCompareAgainst as $compareGroup) {
                                    $allTypeNames = array_merge($allTypeNames, array_keys($compareGroup['types']));
                                }
                                $allTypeNames = array_unique($allTypeNames);
                                sort($allTypeNames, SORT_NATURAL | SORT_FLAG_CASE);
                                ?>
                                <div class="table-responsive">
                                    <table class="table rpt-types-compare align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Ticket type</th>
                                                <th scope="col"><?= h((string)$seasonCompareMain['Season']) ?></th>
                                                <?php foreach ($seasonCompareAgainst as $compareGroup): ?>
                                                    <th scope="col"><?= h((string)$compareGroup['Season']) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($allTypeNames as $typeName): ?>
                                                <tr>
                                                    <td><i class="fa-solid fa-user" aria-hidden="true"></i> <?= h($typeName) ?></td>
                                                    <td><?= isset($seasonCompareMain['types'][$typeName]) ? '<span class="rpt-pill rpt-pill--success">Included</span>' : '<span class="rpt-muted">&mdash;</span>' ?></td>
                                                    <?php foreach ($seasonCompareAgainst as $compareGroup): ?>
                                                        <td><?= isset($compareGroup['types'][$typeName]) ? '<span class="rpt-pill rpt-pill--success">Included</span>' : '<span class="rpt-muted">&mdash;</span>' ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="rpt-empty-inline">Choose at least one season to compare against, to compare ticket types.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <p class="rpt-compare-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Use "Main season" and "Compare against" above to choose exactly which seasons this dashboard compares. The full history table below lists every season on record and can be exported.</p>
            <?php endif; ?>

            <div class="rpt-table-card">
                <div class="rpt-table-card__header">
                    <div>
                        <div class="rpt-table-card__eyebrow"><?= h($seasonName) ?></div>
                        <h2><?= $reportType === 'season_ticket_comparison' ? 'Full season-by-season history' : h($activeReport['label']) ?></h2>
                        <p><?= $reportType === 'season_ticket_comparison' ? 'Every season on record for season tickets, one row per season.' : h($activeReport['desc']) ?></p>
                        <?php if (in_array($reportType, ['payments', 'payment_methods'], true)): ?>
                            <p class="small mb-0"><i class="fa-brands fa-stripe-s" aria-hidden="true"></i> Card payments taken via Stripe appear here with method "Stripe" (and "Stripe refund" for refunds). For transaction status and to issue a refund, use the <a href="/admin/stripe_dashboard.php">Stripe Dashboard</a>.</p>
                        <?php endif; ?>
                    </div>
                    <div class="rpt-table-card__actions">
                        <a href="<?= h($exportQuery('csv')) ?>" class="btn btn-sm rpt-btn rpt-btn--ghost"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV</a>
                        <label class="rpt-export-orientation rpt-export-orientation--compact">
                            <span>PDF layout</span>
                            <select class="form-select form-select-sm" data-pdf-orientation>
                                <option value="landscape" <?= $pdfOrientation === 'landscape' ? 'selected' : '' ?>>Landscape</option>
                                <option value="portrait" <?= $pdfOrientation === 'portrait' ? 'selected' : '' ?>>Portrait</option>
                            </select>
                        </label>
                        <a href="<?= h($exportQuery('pdf')) ?>" class="btn btn-sm rpt-btn rpt-btn--ghost"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF</a>
                    </div>
                </div>

                <?php
                // The season-ticket comparison dashboard above already covers every change/growth/holder-movement
                // figure (framed against whichever seasons the user actually chose to compare). Showing those
                // again here — computed against a fixed chronological predecessor instead — would just repeat
                // the same numbers with a different, more confusing baseline. This ledger keeps only each
                // season's own standalone facts.
                $historyColumns = $reportType === 'season_ticket_comparison'
                    ? array_values(array_intersect(array_keys($reportRows[0] ?? []), ['Season', 'Tickets', 'Active Tickets', 'Paid Orders', 'Gross Income', 'Average Ticket Value', 'Complimentary', 'Cancelled/Refunded', 'Unique Holders', 'Ticket Types']))
                    : array_keys($reportRows[0] ?? []);
                ?>
                <?php if ($reportType === 'sponsor_showcase'): ?>
                    <?php if (!$showcaseGroups): ?>
                        <p class="rpt-empty-inline">Choose at least one sponsorship type above to show it here.</p>
                    <?php endif; ?>
                    <div class="rpt-showcase-list">
                        <?php foreach ($showcaseGroups as $group): ?>
                            <?php $scopeMeta = reportScopeMeta($group['scope']); ?>
                            <div class="rpt-showcase-card">
                                <div class="rpt-showcase-card__header rpt-showcase-card__header--<?= h($scopeMeta['key']) ?>">
                                    <span><i class="fa-solid <?= h($scopeMeta['icon']) ?>" aria-hidden="true"></i> <?= h($group['name']) ?></span>
                                    <span class="rpt-showcase-card__count"><?= (int)$group['total_sponsors'] ?></span>
                                </div>
                                <div class="rpt-showcase-card__body">
                                    <?php foreach ($group['rows'] as $row): ?>
                                        <div class="rpt-showcase-row" style="grid-template-columns: repeat(<?= (int)count($row) ?: 1 ?>, 1fr);">
                                            <?php foreach ($row as $package): ?>
                                                <div class="rpt-showcase-package">
                                                    <h3 class="rpt-showcase-package__title"><?= h($package['name']) ?></h3>
                                                    <ul class="rpt-showcase-package__list">
                                                        <?php if (!$package['sponsors']): ?>
                                                            <li class="rpt-showcase-package__empty">No sponsor yet</li>
                                                        <?php endif; ?>
                                                        <?php foreach ($package['sponsors'] as $sponsor): ?>
                                                            <li>
                                                                <span class="rpt-showcase-package__name"><?= h($sponsor['name']) ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table hub-data-table hub-data-table--responsive rpt-table align-middle mb-0" id="rptTable">
                            <thead>
                                <tr>
                                    <?php foreach ($historyColumns as $column): ?>
                                        <th scope="col" data-sort="<?= h((string)$column) ?>"><?= h((string)$column) ?><i class="fa-solid fa-sort rpt-sort-icon" aria-hidden="true"></i></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($reportRows): ?>
                                    <?php foreach ($reportRows as $row): ?>
                                        <tr>
                                            <?php foreach ($historyColumns as $column): ?>
                                                <?php $value = $row[$column] ?? ''; ?>
                                                <td data-value="<?= h((string)$value) ?>"><?= reportRenderCell((string)$column, $value) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="99"><div class="rpt-empty"><i class="fa-regular fa-folder-open" aria-hidden="true"></i><span>No rows match the current filters.</span></div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var REPORT_FILTERS = <?= json_encode(array_map(static fn(array $definition) => $definition['filters'], $REPORTS), JSON_THROW_ON_ERROR) ?>;
    var form = document.getElementById('rptFilterForm');
    if (!form) return;

    var pdfOrientationSelects = Array.prototype.slice.call(document.querySelectorAll('[data-pdf-orientation]'));

    function updatePdfOrientation(value) {
        pdfOrientationSelects.forEach(function (select) {
            select.value = value;
        });

        if (form.pdf_orientation) {
            form.pdf_orientation.value = value;
        }

        document.querySelectorAll('a[href*="export=pdf"]').forEach(function (link) {
            var url = new URL(link.getAttribute('href'), window.location.href);
            url.searchParams.set('pdf_orientation', value);
            link.setAttribute('href', url.pathname === window.location.pathname ? url.search : url.href);
        });
    }

    pdfOrientationSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            updatePdfOrientation(select.value === 'portrait' ? 'portrait' : 'landscape');
        });
    });

    function applyVisibility() {
        var allowed = REPORT_FILTERS[form.report_type.value] || [];
        form.querySelectorAll('[data-filter]').forEach(function (field) {
            var key = field.getAttribute('data-filter');
            field.style.display = (key === 'search' || allowed.indexOf(key) !== -1) ? '' : 'none';
        });
    }
    applyVisibility();

    var mainSeasonSelect = document.getElementById('rptMainSeason');
    var compareCheckboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="compare_season_id[]"]'));
    var compareSummary = document.querySelector('[data-checkbox-dropdown-summary]');

    function updateCompareSummary() {
        if (!compareSummary) return;
        var checked = compareCheckboxes.filter(function (box) {
            var item = box.closest('.rpt-checkbox-dropdown__item');
            return box.checked && item && item.style.display !== 'none';
        });
        if (checked.length === 0) {
            compareSummary.textContent = 'None selected';
        } else if (checked.length <= 2) {
            compareSummary.textContent = checked.map(function (box) {
                var label = box.closest('.rpt-checkbox-dropdown__item').querySelector('span');
                return label ? label.textContent : box.value;
            }).join(', ');
        } else {
            compareSummary.textContent = checked.length + ' seasons selected';
        }
    }

    function syncMainSeasonExclusion() {
        if (!mainSeasonSelect) return;
        compareCheckboxes.forEach(function (box) {
            var item = box.closest('.rpt-checkbox-dropdown__item');
            var isMain = box.value === mainSeasonSelect.value;
            if (item) item.style.display = isMain ? 'none' : '';
            if (isMain) box.checked = false;
        });
        updateCompareSummary();
    }

    if (mainSeasonSelect) mainSeasonSelect.addEventListener('change', syncMainSeasonExclusion);
    compareCheckboxes.forEach(function (box) { box.addEventListener('change', updateCompareSummary); });

    var packageCheckboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="package_ids[]"]'));
    var packageTypesSummary = document.querySelector('[data-package-types-summary]');
    function updatePackageTypesSummary() {
        if (!packageTypesSummary) return;
        var checked = packageCheckboxes.filter(function (box) { return box.checked; });
        if (checked.length === packageCheckboxes.length) {
            packageTypesSummary.textContent = 'All types';
        } else if (checked.length === 0) {
            packageTypesSummary.textContent = 'None selected';
        } else if (checked.length <= 2) {
            packageTypesSummary.textContent = checked.map(function (box) {
                var label = box.closest('.rpt-checkbox-dropdown__item').querySelector('span');
                return label ? label.textContent : box.value;
            }).join(', ');
        } else {
            packageTypesSummary.textContent = checked.length + ' types selected';
        }
    }
    packageCheckboxes.forEach(function (box) { box.addEventListener('change', updatePackageTypesSummary); });

    var picker = document.querySelector('.rpt-picker');
    if (picker) {
        picker.addEventListener('click', function (event) {
            var link = event.target.closest('.rpt-picker__item');
            if (!link) return;
            event.preventDefault();
            var params = new URLSearchParams(link.getAttribute('href').replace(/^\?/, ''));
            form.report_type.value = params.get('report_type') || 'portfolio';
            applyVisibility();
            form.submit();
        });
    }

    var table = document.getElementById('rptTable');
    if (table) {
        var tbody = table.querySelector('tbody');
        table.querySelectorAll('thead th[data-sort]').forEach(function (th, index) {
            th.addEventListener('click', function () {
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                if (!rows.length || !rows[0].children[index]) return;
                var ascending = th.getAttribute('data-order') !== 'asc';
                table.querySelectorAll('thead th').forEach(function (other) { other.removeAttribute('data-order'); });
                th.setAttribute('data-order', ascending ? 'asc' : 'desc');
                rows.sort(function (a, b) {
                    var av = a.children[index].getAttribute('data-value') || a.children[index].textContent.trim();
                    var bv = b.children[index].getAttribute('data-value') || b.children[index].textContent.trim();
                    var an = parseFloat(av.replace(/[^0-9.\-]/g, ''));
                    var bn = parseFloat(bv.replace(/[^0-9.\-]/g, ''));
                    var result;
                    if (!isNaN(an) && !isNaN(bn) && (/[0-9]/.test(av))) {
                        result = an - bn;
                    } else {
                        result = av.localeCompare(bv);
                    }
                    return ascending ? result : -result;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }
})();
</script>

<?php if ($reportType === 'season_ticket_comparison' && $seasonCompareMain !== null): ?>
<?php
$primaryPairwiseForChart = $seasonComparePairwise[0] ?? null;
$chartRenewedCount = $primaryPairwiseForChart ? (int)$primaryPairwiseForChart['Renewed Holders'] : 0;
$chartLapsedCount = $primaryPairwiseForChart ? (int)$primaryPairwiseForChart['Lapsed From Previous'] : 0;
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
(function () {
    var brandFont = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = brandFont;

    var wine = '#6a2036';
    var gold = '#b99b61';

    var trendSeasons = <?= json_encode(array_map(static fn(array $row) => [
        'label' => (string)$row['Season'],
        'tickets' => reportSeasonTicketOwnValue($row, 'Tickets'),
        'income' => reportSeasonTicketOwnValue($row, 'Gross Income'),
        'isMain' => (int)$row['id'] === $mainSeasonId,
    ], $chartTrendSeasons), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var renewalSeasons = <?= json_encode(array_map(static fn(array $row) => [
        'label' => (string)$row['Compare Season'],
        'renewalRate' => (float)$row['Renewal Rate %'],
    ], $chartRenewalSeasons), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var ticketsCanvas = document.getElementById('rptTicketsChart');
    if (ticketsCanvas && trendSeasons.length) {
        new Chart(ticketsCanvas, {
            type: 'bar',
            data: {
                labels: trendSeasons.map(function (s) { return s.label; }),
                datasets: [{
                    data: trendSeasons.map(function (s) { return s.tickets; }),
                    backgroundColor: trendSeasons.map(function (s) { return s.isMain ? wine : gold; }),
                    borderRadius: 6,
                    maxBarThickness: 56,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.parsed.y.toLocaleString('en-GB') + ' tickets'; } } },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    var incomeCanvas = document.getElementById('rptIncomeChart');
    if (incomeCanvas && trendSeasons.length) {
        new Chart(incomeCanvas, {
            type: 'bar',
            data: {
                labels: trendSeasons.map(function (s) { return s.label; }),
                datasets: [{
                    data: trendSeasons.map(function (s) { return s.income; }),
                    backgroundColor: trendSeasons.map(function (s) { return s.isMain ? wine : gold; }),
                    borderRadius: 6,
                    maxBarThickness: 56,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return '£' + Number(ctx.parsed.y).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } } },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function (value) { return '£' + Number(value).toLocaleString('en-GB'); } } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    var renewalCanvas = document.getElementById('rptRenewalChart');
    if (renewalCanvas && renewalSeasons.length) {
        new Chart(renewalCanvas, {
            type: 'bar',
            data: {
                labels: renewalSeasons.map(function (s) { return s.label; }),
                datasets: [{
                    data: renewalSeasons.map(function (s) { return s.renewalRate; }),
                    backgroundColor: gold,
                    borderRadius: 6,
                    maxBarThickness: 56,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.parsed.y.toFixed(1) + '% renewed from ' + ctx.label; } } },
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: function (value) { return value + '%'; } } },
                    x: { grid: { display: false } },
                },
            },
        });
    } else if (renewalCanvas) {
        renewalCanvas.closest('.rpt-chart-card').innerHTML += '<p class="rpt-empty-inline">Choose a season to compare against.</p>';
    }

    var donutCanvas = document.getElementById('rptRenewalDonut');
    if (donutCanvas) {
        new Chart(donutCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Renewed', 'Lapsed'],
                datasets: [{
                    data: [<?= $chartRenewedCount ?>, <?= $chartLapsedCount ?>],
                    backgroundColor: [wine, gold],
                    borderWidth: 2,
                    borderColor: '#fffdf9',
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: { legend: { display: false } },
            },
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
