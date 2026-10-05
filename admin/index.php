<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Club overview',
    'title' => 'Club Hub',
    'subtitle' => 'Your starting point for club, sponsorship, fixture, and publishing work.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/secretary_tasks.php';
require_once __DIR__ . '/lib/facility_maintenance.php';
require_once __DIR__ . '/lib/sponsor_followups.php';
require_once __DIR__ . '/lib/pos.php';
require_once __DIR__ . '/lib/pos_reconciliation.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/shop.php';

function hub_index_load_json_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function hub_index_format_date(?string $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y', $timestamp);
}

// Just the opponent name — Home/Away is already shown separately as its own
// badge everywhere this is used, so prefixing it here too ("Home v Team")
// was saying the same thing twice.
function hub_index_fixture_title(array $fixture): string
{
    return trim((string) ($fixture['opponent'] ?? ''));
}

function hub_index_ordinal_position(mixed $value): string
{
    $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($position === false) {
        return '—';
    }

    $lastTwoDigits = $position % 100;
    $suffix = match (true) {
        $lastTwoDigits >= 11 && $lastTwoDigits <= 13 => 'th',
        $position % 10 === 1 => 'st',
        $position % 10 === 2 => 'nd',
        $position % 10 === 3 => 'rd',
        default => 'th',
    };

    return $position . $suffix;
}

function hub_index_result_outcome(array $fixture): ?array
{
    if (
        ($fixture['full_time_home_score'] ?? null) === null
        || ($fixture['full_time_away_score'] ?? null) === null
    ) {
        return null;
    }

    $homeScore = (int)$fixture['full_time_home_score'];
    $awayScore = (int)$fixture['full_time_away_score'];
    $saltcoatsScore = !empty($fixture['is_home']) ? $homeScore : $awayScore;
    $opponentScore = !empty($fixture['is_home']) ? $awayScore : $homeScore;

    if ($saltcoatsScore > $opponentScore) {
        return ['label' => 'Win', 'class' => 'win'];
    }
    if ($saltcoatsScore < $opponentScore) {
        return ['label' => 'Loss', 'class' => 'loss'];
    }

    return ['label' => 'Draw', 'class' => 'draw'];
}

// Competition names in the data are often long ("West of Scotland Football
// League Third Division", "Strathclyde Demolition West Of Scotland League
// Cup"...) — too long to sit comfortably in a compact list row. Icon
// stands in for the name; the full name is still available as a tooltip.
function hub_index_competition_icon(string $competition): array
{
    $lower = strtolower($competition);
    if (str_contains($lower, 'cup')) {
        return ['icon' => 'fa-trophy', 'title' => $competition !== '' ? $competition : 'Cup competition'];
    }
    if (str_contains($lower, 'league') || str_contains($lower, 'division') || str_contains($lower, 'conference')) {
        return ['icon' => 'fa-star', 'title' => $competition !== '' ? $competition : 'League competition'];
    }
    return ['icon' => 'fa-futbol', 'title' => $competition !== '' ? $competition : 'Fixture'];
}

/**
 * Dashboard widgets are shown per-viewer based on the same capability
 * groups that gate the pages they link to (see HUB_PAGE_CAPABILITIES in
 * admin/lib/positions.php) — a volunteer with only the Match Day
 * capability shouldn't see sponsorship money or secretary deadlines on
 * their landing page, since they can't open those pages anyway.
 *
 * @param list<string> $capabilities
 */
function hub_index_can(array $capabilities): bool
{
    return hub_auth_has_any_capability($capabilities);
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function hub_index_filter_by_capability(array $items): array
{
    return array_values(array_filter(
        $items,
        static fn(array $item): bool => hub_index_can((array) ($item['cap'] ?? []))
    ));
}

$playerTotals = [
    'active' => 0,
    'former' => 0,
    'total' => 0,
];

$upcomingFixtures = [];
$recentResults = [];
$nextBirthdays = [];
$fixtureTotals = ['upcoming' => 0, 'played' => 0];
$attentionTotals = [
    'players_without_sponsors' => 0,
    'unpaid_sponsorships' => 0,
    'fixtures_missing_details' => 0,
    'open_orders' => 0,
    'secretary_overdue' => 0,
    'facilities_overdue' => 0,
    'sponsor_followups_overdue' => 0,
    'cashup_variances' => 0,
];
$operationsTotals = [
    'secretary_due' => 0,
    'facilities_open' => 0,
    'sponsor_followups_open' => 0,
    'sponsor_pipeline_value' => 0.0,
    'cashup_variance_total' => 0.0,
];
$openOrderSeasonId = 0;
$leagueSnapshot = null;
$seasonId = (int) ($seasonContext['season_id'] ?? 0);
$selectedSeason = $seasonContext['season'] ?? null;

try {
    if (isset($pdo)) {
        try {
            // "Played" is the Hub's completed/finished fixture status. Leave
            // postponed and cancelled fixtures unchanged for an admin to review.
            $pdo->exec("
                UPDATE match_fixtures
                SET status = 'played'
                WHERE status = 'scheduled'
                  AND match_date < CURDATE()
            ");
        } catch (Throwable $e) {
            // A status rollover failure should not prevent the dashboard loading.
        }

        $playerTotals['active'] = (int) $pdo->query("SELECT COUNT(*) FROM players WHERE status IN ('current', 'trialist') AND active = 1")->fetchColumn();
        $nextBirthdays = players_all_birthdays($pdo, 3);

        $fixtureCountStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN match_date >= CURDATE() AND status IN ('scheduled', 'postponed') THEN 1 ELSE 0 END) AS upcoming_count,
                SUM(CASE WHEN status = 'played' THEN 1 ELSE 0 END) AS played_count
            FROM match_fixtures
            WHERE season_id = :season_id
        ");
        $fixtureCountStmt->execute([':season_id' => $seasonId]);
        $fixtureCountRow = $fixtureCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $fixtureTotals['upcoming'] = (int) ($fixtureCountRow['upcoming_count'] ?? 0);
        $fixtureTotals['played'] = (int) ($fixtureCountRow['played_count'] ?? 0);

        $upcomingStmt = $pdo->prepare("
            SELECT id, match_date, kickoff_time, opponent, is_home, competition, venue, status,
                   half_time_home_score, half_time_away_score,
                   full_time_home_score, full_time_away_score
            FROM match_fixtures
            WHERE season_id = :season_id
              AND match_date >= CURDATE()
              AND status IN ('scheduled', 'postponed')
            ORDER BY match_date ASC, kickoff_time ASC, id ASC
            LIMIT 3
        ");
        $upcomingStmt->execute([':season_id' => $seasonId]);
        $upcomingFixtures = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

        $resultsStmt = $pdo->prepare("
            SELECT id, match_date, kickoff_time, opponent, is_home, competition, venue, status,
                   half_time_home_score, half_time_away_score,
                   full_time_home_score, full_time_away_score
            FROM match_fixtures
            WHERE season_id = :season_id
              AND status = 'played'
            ORDER BY match_date DESC, kickoff_time DESC, id DESC
            LIMIT 3
        ");
        $resultsStmt->execute([':season_id' => $seasonId]);
        $recentResults = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

        $playersWithoutSponsorsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM players p
            WHERE p.status IN ('current', 'trialist') AND p.active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM sponsorships s
                  WHERE s.player_id = p.id AND s.season_id = :season_id AND s.ended_at IS NULL
              )
        ");
        $playersWithoutSponsorsStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['players_without_sponsors'] = (int) $playersWithoutSponsorsStmt->fetchColumn();

        $unpaidStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM sponsorships s
            WHERE s.season_id = :season_id AND s.ended_at IS NULL AND s.amount > 0
              AND COALESCE((SELECT SUM(p.amount) FROM sponsorship_payments p WHERE p.sponsorship_id = s.id), 0) < s.amount
        ");
        $unpaidStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['unpaid_sponsorships'] = (int) $unpaidStmt->fetchColumn();

        $missingFixtureStmt = $pdo->prepare("
            SELECT COUNT(*) FROM match_fixtures
            WHERE season_id = :season_id AND match_date >= CURDATE() AND status = 'scheduled'
              AND (opponent IS NULL OR TRIM(opponent) = '' OR kickoff_time IS NULL OR venue IS NULL OR TRIM(venue) = '')
        ");
        $missingFixtureStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['fixtures_missing_details'] = (int) $missingFixtureStmt->fetchColumn();

        // Orders (season tickets, match tickets, and any future product type sold
        // through the same checkout) that were started but never completed or paid.
        $attentionTotals['open_orders'] = (int) $pdo->query("
            SELECT COUNT(*) FROM orders WHERE status IN ('draft', 'pending_payment')
        ")->fetchColumn();

        // The season ticket ledger report is scoped to whichever season is currently
        // selected in the Hub's season switcher, so a link into it only shows these
        // open orders if it also jumps to the season they actually belong to.
        $openOrderSeasonStmt = $pdo->query("
            SELECT sp.season_id
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN entitlements e ON e.order_item_id = oi.id
            JOIN season_passes sp ON sp.entitlement_id = e.id
            WHERE o.status IN ('draft', 'pending_payment')
            ORDER BY o.created_at DESC
            LIMIT 1
        ");
        $openOrderSeasonId = (int) ($openOrderSeasonStmt->fetchColumn() ?: 0);

        $upcomingSecretaryTasks = secretary_task_upcoming($pdo, 14);
        $operationsTotals['secretary_due'] = count($upcomingSecretaryTasks);
        $attentionTotals['secretary_overdue'] = count(array_filter($upcomingSecretaryTasks, static fn(array $task): bool => !empty($task['due_at']) && (string) $task['due_at'] < date('Y-m-d')));

        $facilityJobs = facility_maintenance_list($pdo, 'open');
        $facilitySummary = facility_maintenance_summary($facilityJobs);
        $operationsTotals['facilities_open'] = (int) $facilitySummary['open'];
        $attentionTotals['facilities_overdue'] = (int) $facilitySummary['overdue'];

        $sponsorFollowups = sponsor_followup_list($pdo, 'open');
        $sponsorFollowupSummary = sponsor_followup_summary($sponsorFollowups);
        $operationsTotals['sponsor_followups_open'] = (int) $sponsorFollowupSummary['open'];
        $operationsTotals['sponsor_pipeline_value'] = (float) $sponsorFollowupSummary['pipeline_value'];
        $attentionTotals['sponsor_followups_overdue'] = (int) $sponsorFollowupSummary['overdue'];

        pos_ensure_schema($pdo);
        $cashups = pos_reconciliation_recent($pdo, date('Y-m-d'), 50);
        foreach ($cashups as $cashup) {
            $variance = (float) ($cashup['variance_amount'] ?? 0);
            if (abs($variance) >= 0.005) {
                $attentionTotals['cashup_variances']++;
                $operationsTotals['cashup_variance_total'] += abs($variance);
            }
        }
    }
} catch (Throwable $e) {
    // Keep dashboard resilient if one of the legacy tables is unavailable.
}

$seasonTicketTotals = ['holders' => 0, 'collected' => 0.0, 'outstanding' => 0.0];
$sponsorshipTotals = ['agreed' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
$stripeOverviewTotals = ['collected' => 0.0, 'refunded' => 0.0, 'payments' => 0];
$recentStripeOrders = [];

try {
    if (isset($pdo)) {
        ensureSeasonPassSchema($pdo);
        $holdersStmt = $pdo->prepare("SELECT COUNT(DISTINCT e.person_id)
            FROM season_passes sp
            JOIN entitlements e ON e.id = sp.entitlement_id
            WHERE sp.season_id = :season_id AND e.status <> 'cancelled'");
        $holdersStmt->execute([':season_id' => $seasonId]);
        $seasonTicketTotals['holders'] = (int) $holdersStmt->fetchColumn();

        $ticketMoneyStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN pay.status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN pay.status <> 'paid' AND o.status <> 'cancelled' THEN o.total_amount ELSE 0 END), 0) AS outstanding
            FROM season_passes sp
            JOIN entitlements e ON e.id = sp.entitlement_id
            JOIN order_items oi ON oi.id = e.order_item_id
            JOIN orders o ON o.id = oi.order_id
            JOIN payments pay ON pay.order_id = o.id
            WHERE sp.season_id = :season_id
        ");
        $ticketMoneyStmt->execute([':season_id' => $seasonId]);
        $ticketMoneyRow = $ticketMoneyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $seasonTicketTotals['collected'] = (float) ($ticketMoneyRow['collected'] ?? 0);
        $seasonTicketTotals['outstanding'] = (float) ($ticketMoneyRow['outstanding'] ?? 0);

        ensureSponsorshipCatalogSchema($pdo);
        foreach (getSponsorshipAgreements($pdo, ['season_id' => $seasonId]) as $agreement) {
            if (!empty($agreement['is_complimentary'])) {
                continue;
            }
            $sponsorshipTotals['agreed'] += (float) $agreement['agreed_amount'];
            $sponsorshipTotals['paid'] += (float) $agreement['total_paid'];
        }
        $sponsorshipTotals['outstanding'] = max(0.0, $sponsorshipTotals['agreed'] - $sponsorshipTotals['paid']);

        if (hub_auth_has_capability('finance_view')) {
            $stripeOverviewTotals = stripe_all_payments_summary($pdo);
            $recentStripeOrders = stripe_get_all_transactions($pdo, ['limit' => 4]);
        }
    }
} catch (Throwable $e) {
    // Keep dashboard resilient if one of these tables/features is unavailable.
}

$shopTotals = ['open_orders' => 0, 'ready_for_collection' => 0, 'collected_today' => 0.0];
$recentShopOrders = [];

try {
    if (isset($pdo) && hub_auth_has_capability('shop')) {
        shop_ensure_schema($pdo);
        $shopTotals['open_orders'] = (int) $pdo->query("
            SELECT COUNT(*) FROM shop_orders WHERE status = 'pending_payment'
        ")->fetchColumn();
        $shopTotals['ready_for_collection'] = (int) $pdo->query("
            SELECT COUNT(*) FROM shop_orders WHERE status = 'paid'
        ")->fetchColumn();
        $shopTotals['collected_today'] = (float) $pdo->query("
            SELECT COALESCE(SUM(total), 0) FROM shop_orders
            WHERE status IN ('paid', 'collected') AND DATE(paid_at) = CURDATE()
        ")->fetchColumn();
        $recentShopOrders = $pdo->query("
            SELECT id, order_ref, customer_name, status, total, created_at
            FROM shop_orders
            WHERE status IN ('pending_payment', 'paid')
            ORDER BY created_at DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Keep dashboard resilient if the shop tables aren't available.
}

$leagueRows = hub_index_load_json_array(__DIR__ . '/cache/wosfl_table.json');
foreach ($leagueRows as $row) {
    if (!is_array($row)) {
        continue;
    }

    if (strcasecmp(trim((string) ($row['club'] ?? '')), 'Saltcoats Victoria') === 0) {
        $leagueSnapshot = $row;
        break;
    }
}
$leaguePosition = hub_index_ordinal_position($leagueSnapshot['pos'] ?? null);

// Each attention item is capability-gated the same way its target page is,
// so the "needs attention" score only counts what this viewer can act on.
// Zero-count items are dropped too: this panel exists to flag exceptions,
// not to restate a full checklist of things that are currently fine.
$attentionItems = array_values(array_filter(
    hub_index_filter_by_capability([
    [
        'cap' => ['sponsorship', 'finance_view'],
        'count' => $attentionTotals['players_without_sponsors'],
        'href' => 'players.php?sponsor_status=none',
        'icon' => 'fa-user-tag',
        'label' => 'players without sponsors',
        'meta' => 'Review available player packages',
    ],
    [
        'cap' => ['sponsorship', 'finance_view'],
        'count' => $attentionTotals['unpaid_sponsorships'],
        'href' => 'reports.php?season_id=' . $seasonId,
        'icon' => 'fa-sterling-sign',
        'label' => 'unpaid sponsorships',
        'meta' => 'Check outstanding balances',
    ],
    [
        'cap' => ['matchday'],
        'count' => $attentionTotals['fixtures_missing_details'],
        'href' => 'matches.php?season_id=' . $seasonId,
        'icon' => 'fa-calendar-xmark',
        'label' => 'fixtures need details',
        'meta' => 'Add venue, opponent, or kick-off time',
    ],
    [
        'cap' => ['tickets_ops', 'finance_view'],
        'count' => $attentionTotals['open_orders'],
        'href' => 'reports.php?report_type=season_tickets&status=pending_payment' . ($openOrderSeasonId > 0 ? '&season_id=' . $openOrderSeasonId : ''),
        'icon' => 'fa-cart-shopping',
        'label' => 'open orders',
        'meta' => 'Season tickets, match tickets and other orders awaiting payment',
    ],
    [
        'cap' => ['secretary_ops'],
        'count' => $attentionTotals['secretary_overdue'],
        'href' => 'secretary_tasks.php',
        'icon' => 'fa-list-check',
        'label' => 'overdue secretary tasks',
        'meta' => (int) $operationsTotals['secretary_due'] . ' due within 14 days',
    ],
    [
        'cap' => ['club_setup'],
        'count' => $attentionTotals['facilities_overdue'],
        'href' => 'facilities.php',
        'icon' => 'fa-screwdriver-wrench',
        'label' => 'overdue facilities jobs',
        'meta' => (int) $operationsTotals['facilities_open'] . ' open ground or safety jobs',
    ],
    [
        'cap' => ['sponsorship'],
        'count' => $attentionTotals['sponsor_followups_overdue'],
        'href' => 'sponsor_followups.php',
        'icon' => 'fa-phone-volume',
        'label' => 'overdue sponsor follow-ups',
        'meta' => gbp($operationsTotals['sponsor_pipeline_value']) . ' open pipeline value',
    ],
    [
        'cap' => ['tickets_ops', 'finance_view'],
        'count' => $attentionTotals['cashup_variances'],
        'href' => 'pos/reports.php?date=' . date('Y-m-d'),
        'icon' => 'fa-cash-register',
        'label' => 'cash-up variances today',
        'meta' => gbp($operationsTotals['cashup_variance_total']) . ' total variance to review',
    ],
    [
        'cap' => ['shop'],
        'count' => (int) $shopTotals['open_orders'] + (int) $shopTotals['ready_for_collection'],
        'href' => 'shop_orders.php',
        'icon' => 'fa-bag-shopping',
        'label' => 'shop orders needing action',
        'meta' => (int) $shopTotals['open_orders'] . ' awaiting payment, ' . (int) $shopTotals['ready_for_collection'] . ' ready for collection',
    ],
    ]),
    static fn(array $item): bool => (int) $item['count'] > 0
));
$attentionCount = array_sum(array_map(static fn(array $item): int => (int) $item['count'], $attentionItems));
$canSeeMatchdayFocus = hub_index_can(['matchday']);

// Revenue-by-source chart — only include a source once it has actually collected
// something, so the legend doesn't fill up with permanently-zero slices.
$revenueSources = [];
if (hub_index_can(['sponsorship', 'finance_view', 'tickets_ops'])) {
    $revenueSources = [
        ['label' => 'Sponsorship', 'value' => round($sponsorshipTotals['paid'], 2), 'color' => '#6a2036'],
        ['label' => 'Season tickets', 'value' => round($seasonTicketTotals['collected'], 2), 'color' => '#b99b61'],
    ];
    $revenueSources = array_values(array_filter($revenueSources, static fn(array $s): bool => $s['value'] > 0));
}

?>

<div class="hub-index-page">

    <div class="hub-context-bar" role="status">
        <div><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>Showing <strong><?= htmlspecialchars((string) ($selectedSeason['name'] ?? 'the selected season'), ENT_QUOTES, 'UTF-8') ?></strong></span><?php if (!empty($selectedSeason['is_locked'])): ?><span class="badge text-bg-secondary">Locked</span><?php endif; ?></div>
        <span>Change season from the navigation menu.</span>
    </div>

    <?php $quickActions = hub_index_filter_by_capability([
        ['cap' => ['matchday'], 'href' => '/admin/match.php?action=new', 'class' => 'btn btn-brand btn-sm', 'icon' => 'fa-plus', 'label' => 'Add fixture'],
        ['cap' => ['matchday'], 'href' => '/admin/player_add.php', 'class' => 'btn btn-outline-secondary btn-sm', 'icon' => 'fa-user-plus', 'label' => 'Add player'],
        ['cap' => ['sponsorship'], 'href' => '/admin/sponsor.php?action=new', 'class' => 'btn btn-outline-secondary btn-sm', 'icon' => 'fa-handshake', 'label' => 'Add sponsor'],
        ['cap' => ['sponsorship'], 'href' => '/admin/sponsorship_agreement.php?action=new', 'class' => 'btn btn-outline-secondary btn-sm', 'icon' => 'fa-file-signature', 'label' => 'Add sponsorship agreement'],
        ['cap' => ['website'], 'href' => '/admin/news_edit.php', 'class' => 'btn btn-outline-secondary btn-sm', 'icon' => 'fa-newspaper', 'label' => 'Write news article'],
        ['cap' => ['shop'], 'href' => '/admin/shop_product.php', 'class' => 'btn btn-outline-secondary btn-sm', 'icon' => 'fa-bag-shopping', 'label' => 'Add shop product'],
    ]); ?>
    <?php if ($quickActions !== []): ?>
    <section class="hub-section-commandbar" aria-labelledby="quickActionsTitle">
        <div><h2 id="quickActionsTitle">Common actions</h2><p>Create the records used most often across match-day and sponsorship workflows.</p></div>
        <div class="hub-local-actions">
            <?php foreach ($quickActions as $action): ?>
                <a href="<?= h((string) $action['href']) ?>" class="<?= h((string) $action['class']) ?>"><i class="fa-solid <?= h((string) $action['icon']) ?> me-1" aria-hidden="true"></i><?= h((string) $action['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php $canSeeBirthdays = hub_index_can(['admin_settings']); ?>
    <?php if ($canSeeBirthdays): ?>
    <section class="hub-index-section" aria-labelledby="birthdaysTitle">
        <div class="hub-index-section__header">
            <div>
                <p class="page-kicker mb-1">People</p>
                <h2 id="birthdaysTitle" class="h4 mb-0">Club birthdays</h2>
            </div>
        </div>

        <div class="card shadow-sm border-0 hub-panel dashboard-birthdays">
            <div class="card-body p-4">
                <?php if ($nextBirthdays === []): ?>
                    <div class="alert alert-light border mb-0 hub-empty-state">No dates of birth have been recorded yet.</div>
                <?php else: ?>
                    <div class="dashboard-birthdays__list">
                        <?php foreach ($nextBirthdays as $birthday): ?>
                            <a class="dashboard-birthdays__item" href="<?= h((string) ($birthday['href'] ?? '/players.php')) ?>">
                                <span class="dashboard-birthdays__days<?= (int) $birthday['days_until'] === 0 ? ' dashboard-birthdays__days--today' : '' ?>">
                                    <strong><?= (int) $birthday['days_until'] === 0 ? '🎉' : (int) $birthday['days_until'] ?></strong>
                                    <small><?= (int) $birthday['days_until'] === 0 ? 'Today' : ((int) $birthday['days_until'] === 1 ? 'day' : 'days') ?></small>
                                </span>
                                <span class="dashboard-birthdays__info">
                                    <span class="dashboard-birthdays__name"><?= h($birthday['name']) ?> - <?= h((string) ($birthday['role_label'] ?? 'supporter')) ?></span>
                                    <small>Turns <?= (int) $birthday['age_turning'] ?> &middot; <?= h(date('d M', strtotime($birthday['next_birthday']))) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-end mt-2"><a href="/admin/club_birthdays.php" class="small">View all</a></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    // All season money in one place: the season-ticket/sponsorship totals
    // used to live in "Where things stand this season" — moved here so
    // every money figure sits next to the online activity feed and the
    // revenue chart, instead of being split across two sections.
    $moneyMetrics = hub_index_filter_by_capability([
        ['cap' => ['finance_view'], 'label' => 'Online payments', 'value' => gbp((float) $stripeOverviewTotals['collected']), 'meta' => (int) $stripeOverviewTotals['payments'] . ' payment' . ((int) $stripeOverviewTotals['payments'] === 1 ? '' : 's') . ((float) $stripeOverviewTotals['refunded'] > 0 ? ' · ' . gbp((float) $stripeOverviewTotals['refunded']) . ' refunded' : ''), 'icon' => 'fa-credit-card', 'tone' => 'success', 'href' => 'stripe_dashboard.php'],
        ['cap' => ['tickets_ops', 'finance_view'], 'label' => 'Season tickets', 'value' => (int) $seasonTicketTotals['holders'], 'meta' => gbp($seasonTicketTotals['collected']) . ' collected' . ($seasonTicketTotals['outstanding'] > 0 ? ', ' . gbp($seasonTicketTotals['outstanding']) . ' outstanding' : ''), 'icon' => 'fa-id-card', 'tone' => 'primary', 'href' => 'season_ticket_orders.php'],
        ['cap' => ['sponsorship', 'finance_view'], 'label' => 'Sponsorship collected', 'value' => gbp($sponsorshipTotals['paid']), 'meta' => gbp($sponsorshipTotals['outstanding']) . ' outstanding of ' . gbp($sponsorshipTotals['agreed']) . ' agreed', 'icon' => 'fa-sterling-sign', 'tone' => $sponsorshipTotals['outstanding'] > 0 ? 'warning' : 'success', 'href' => 'sponsorship_agreements.php'],
    ]);
    $showMoneySection = $moneyMetrics !== [] || $revenueSources !== [];
    ?>
    <?php if ($showMoneySection): ?>
        <section class="hub-index-section" aria-labelledby="recentOrdersTitle">
            <div class="hub-index-section__header">
                <div>
                    <p class="page-kicker mb-1">Money</p>
                    <h2 id="recentOrdersTitle" class="h4 mb-0">Income this season</h2>
                </div>
            </div>

            <div class="hub-index-money">
                <?php if (hub_auth_has_capability('finance_view')): ?>
                <div class="card shadow-sm border-0 hub-panel hub-index-money__stripe">
                    <div class="card-body p-0">
                        <?php if ($recentStripeOrders === []): ?>
                            <div class="hub-empty-state p-4">No online payments have been recorded yet.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recentStripeOrders as $order): ?>
                                    <a class="list-group-item list-group-item-action px-3 py-2" href="<?= h((string) ($order['manage_url'] ?? 'stripe_dashboard.php')) ?>">
                                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                            <div>
                                                <div class="fw-semibold"><?= h((string) $order['customer_name']) ?></div>
                                                <div class="text-muted small"><?= h((string) $order['source']) ?> &middot; <?= h((string) $order['description']) ?></div>
                                            </div>
                                            <div class="text-md-end">
                                                <div class="fw-bold"><?= gbp((float) $order['amount']) ?></div>
                                                <div class="text-muted small"><?= h(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($revenueSources !== []): ?>
                <div class="card shadow-sm border-0 hub-panel hub-index-money__charts">
                    <div class="card-body p-4">
                        <div class="dashboard-season-charts dashboard-season-charts--row">
                            <div class="dashboard-season-charts__block">
                                <p class="dashboard-season-charts__label">Revenue collected this season</p>
                                <div class="dashboard-chart-wrap dashboard-chart-wrap--rail"><canvas id="revenueChart" role="img" aria-label="Donut chart of revenue collected by source this season"></canvas></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($moneyMetrics !== []): ?>
                <div class="hub-index-money__metrics">
                    <?php hub_render_metric_grid($moneyMetrics, 'Income this season'); ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canSeeMatchdayFocus || $attentionItems !== []): ?>
    <section class="dashboard-command-centre" aria-labelledby="dashboardCommandTitle">
        <?php if ($canSeeMatchdayFocus): ?>
        <div class="dashboard-command-centre__main">
            <p class="page-kicker mb-1">Today&apos;s focus</p>
            <h2 id="dashboardCommandTitle">Upcoming fixtures</h2>
            <?php if ($upcomingFixtures === []): ?>
                <p class="dashboard-command-centre__summary">No upcoming fixtures are recorded for <?= htmlspecialchars((string) ($selectedSeason['name'] ?? 'the selected season'), ENT_QUOTES, 'UTF-8') ?>.</p>
                <div class="dashboard-command-centre__actions">
                    <a href="/admin/match.php?action=new&season_id=<?= (int) $seasonId ?>" class="btn btn-brand"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add fixture</a>
                    <a href="/admin/matches.php?season_id=<?= (int) $seasonId ?>" class="btn btn-neutral">View fixtures</a>
                </div>
            <?php else: ?>
                <?php foreach ($upcomingFixtures as $fixture): ?>
                <a class="dashboard-next-fixture" href="/admin/match.php?id=<?= (int) $fixture['id'] ?>&season_id=<?= (int) $seasonId ?>" aria-label="Open vs <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="dashboard-next-fixture__date">
                        <strong><?= htmlspecialchars(hub_index_format_date((string) ($fixture['match_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (!empty($fixture['kickoff_time'])): ?><small><?= htmlspecialchars(substr((string) $fixture['kickoff_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> kick-off</small><?php endif; ?>
                    </span>
                    <span class="dashboard-next-fixture__body">
                        <strong>vs <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small>
                            <span class="dashboard-next-fixture__competition" title="<?= htmlspecialchars((string) ($fixture['competition'] ?? 'Fixture'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($fixture['competition'] ?? 'Fixture'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($fixture['venue'])): ?>&middot; <?= htmlspecialchars((string) $fixture['venue'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                        </small>
                    </span>
                    <span class="badge <?= !empty($fixture['is_home']) ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= !empty($fixture['is_home']) ? 'Home' : 'Away' ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($attentionItems !== []): ?>
        <aside class="dashboard-command-centre__side" aria-label="Attention summary">
            <div class="dashboard-attention-score">
                <span>Needs attention</span>
                <strong><?= (int) $attentionCount ?></strong>
            </div>
            <div class="dashboard-attention__list dashboard-attention__list--compact">
                <?php foreach ($attentionItems as $item): ?>
                <a href="<?= h((string) $item['href']) ?>" class="dashboard-attention__item">
                    <span class="dashboard-attention__icon"><i class="fa-solid <?= h((string) $item['icon']) ?>" aria-hidden="true"></i></span>
                    <span><strong><?= (int) $item['count'] ?> <?= h((string) $item['label']) ?></strong><small><?= h((string) $item['meta']) ?></small></span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    $canSeeLeagueTable = hub_index_can(['matchday', 'publishing']);
    $showMatchdaySection = $canSeeMatchdayFocus || $canSeeLeagueTable;
    ?>
    <?php if ($showMatchdaySection): ?>
    <section class="hub-index-section" aria-labelledby="matchDayTitle">
        <div class="hub-index-section__header">
            <div>
                <p class="page-kicker mb-1">Overview</p>
                <h2 id="matchDayTitle" class="h4 mb-0">Season overview</h2>
            </div>
        </div>

        <div class="hub-index-matchday">
            <?php if ($canSeeLeagueTable): ?>
            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__league">
                <div class="card-body p-4">
                    <h3 class="h5 mb-3">League position: <?= htmlspecialchars($leaguePosition, ENT_QUOTES, 'UTF-8') ?></h3>

                    <?php if ($leagueSnapshot === null): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No league table cache is available yet.</div>
                    <?php else: ?>
                        <div class="hub-index__league-card mb-3">
                            <div class="hub-index__league-pos"><?= htmlspecialchars((string) ($leagueSnapshot['pos'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($leagueSnapshot['club'] ?? 'Saltcoats Victoria'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars((string) ($leagueSnapshot['p'] ?? '0'), ENT_QUOTES, 'UTF-8') ?> played
                                    &middot; <?= htmlspecialchars((string) ($leagueSnapshot['pts'] ?? '0'), ENT_QUOTES, 'UTF-8') ?> points
                                    &middot; GD <?= htmlspecialchars((string) ($leagueSnapshot['gd'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <?php
                        $leaguePlayed = (int) ($leagueSnapshot['p'] ?? 0);
                        $leagueWon = (int) ($leagueSnapshot['w'] ?? 0);
                        $winPercentage = $leaguePlayed > 0 ? round(($leagueWon / $leaguePlayed) * 100) : 0;
                        ?>
                        <div class="hub-index__fact-list mb-0">
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Won</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['w'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Drawn</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['d'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Lost</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['l'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Win %</span>
                                <span class="hub-index__fact-value"><?= (int) $winPercentage ?>%</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($canSeeMatchdayFocus): ?>
            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__fixtures">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                        <h3 class="h5 mb-0">Recent results</h3>
                        <span class="badge text-bg-light">Latest <?= (int) count($recentResults) ?> of <?= (int) $fixtureTotals['played'] ?></span>
                    </div>
                    <?php if ($recentResults === []): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No results have been recorded yet.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentResults as $fixture): ?>
                                <?php
                                $resultOutcome = hub_index_result_outcome($fixture);
                                $compMeta = hub_index_competition_icon((string) ($fixture['competition'] ?? ''));
                                ?>
                                <a
                                    class="list-group-item list-group-item-action px-0 py-2"
                                    href="/admin/match.php?id=<?= (int) $fixture['id'] ?>"
                                    aria-label="Open vs <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="hub-index-result__icon" title="<?= htmlspecialchars($compMeta['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="fa-solid <?= htmlspecialchars($compMeta['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                <span class="visually-hidden"><?= htmlspecialchars($compMeta['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </span>
                                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 flex-grow-1 min-w-0">
                                                <div>
                                                    <div class="fw-semibold">vs <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars(hub_index_format_date((string) ($fixture['match_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($fixture['kickoff_time'])): ?>
                                                            &middot; <?= htmlspecialchars(substr((string) $fixture['kickoff_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-md-end">
                                                    <?php if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null): ?>
                                                        <div class="fw-bold fs-5"><?= (int) $fixture['full_time_home_score'] ?>–<?= (int) $fixture['full_time_away_score'] ?></div>
                                                        <?php if ($resultOutcome !== null): ?>
                                                            <div class="hub-index__result hub-index__result--<?= htmlspecialchars($resultOutcome['class'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <span class="hub-index__result-light" aria-hidden="true"></span>
                                                                <?= htmlspecialchars($resultOutcome['label'], ENT_QUOTES, 'UTF-8') ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-success mb-1">Played</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>




        </div>
    </section>
    <?php endif; ?>

</div>

<link rel="stylesheet" href="/admin/assets/css/index.css">

<?php if ($revenueSources !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
(() => {
    const brandFont = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = brandFont;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const revenueCanvas = document.getElementById('revenueChart');
    if (revenueCanvas) {
        const data = <?= json_encode($revenueSources, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        new Chart(revenueCanvas, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.label),
                datasets: [{
                    data: data.map(d => d.value),
                    backgroundColor: data.map(d => d.color),
                    borderWidth: 2,
                    borderColor: '#fffdf9',
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.label + ': £' + Number(ctx.parsed).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                        },
                    },
                },
            },
        });
    }

})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
