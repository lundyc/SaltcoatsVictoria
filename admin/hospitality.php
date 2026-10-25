<?php
declare(strict_types=1);

// Big Js hospitality — who has booked, game by game. Prices, capacity and booking
// notification emails live on hospitality_settings.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/hospitality.php';
require_once __DIR__ . '/account_auth.php';

$exportFixture = (int) ($_GET['fixture'] ?? 0);

// Guest-list CSV for one game (for the caterer / the door), bypassing the HTML shell.
if (($_GET['export'] ?? '') === 'csv' && $exportFixture > 0) {
    if (!hub_auth_has_capability('tickets_ops')) {
        http_response_code(403);
        exit('Forbidden');
    }
    hospitality_ensure_schema($pdo);
    $fixture = hospitality_fixture($pdo, $exportFixture);
    $stmt = $pdo->prepare("SELECT * FROM hospitality_bookings WHERE fixture_id = :f AND status = 'paid' ORDER BY contact_name");
    $stmt->execute([':f' => $exportFixture]);
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($fixture['opponent'] ?? 'game'))) ?: 'game';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hospitality-v-' . $slug . '-' . ($fixture['match_date'] ?? date('Y-m-d')) . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Name', 'Company', 'Email', 'Phone', 'Places', 'Booking type', 'Total', 'Dietary requirements', 'Notes'], ',', '"', '');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        fputcsv($out, [
            $b['contact_name'], (string) ($b['company'] ?? ''), $b['contact_email'], $b['contact_phone'], (int) $b['guests'], $b['booking_type'],
            number_format((float) $b['total_amount'], 2, '.', ''), (string) ($b['dietary_requirements'] ?? ''), (string) ($b['notes'] ?? ''),
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Big Js hospitality',
    'subtitle' => 'Who has booked the hospitality suite, game by game.',
    'actions' => [
        ['label' => 'Prices & settings', 'href' => '/admin/hospitality_settings.php', 'class' => 'btn btn-light btn-sm'],
        ['label' => 'Public page', 'href' => '/hospitality', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';

if (!hub_auth_has_capability('tickets_ops')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage hospitality.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

hospitality_ensure_schema($pdo);
$since = (string) ($hubOrderPrevSeen['hospitality'] ?? '1970-01-01 00:00:00');
$settings = hospitality_settings($pdo);
$capacity = (int) $settings['capacity'];
$showPending = !empty($_GET['pending']);
$today = date('Y-m-d');

$allBookings = $pdo->query("SELECT b.*, f.opponent, f.match_date, f.kickoff_time, f.competition
    FROM hospitality_bookings b JOIN match_fixtures f ON f.id = b.fixture_id
    WHERE b.status IN ('paid','pending_payment')
    ORDER BY f.match_date, f.kickoff_time, b.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// One entry per game: every upcoming bookable home game (even with no bookings yet), plus any game that has bookings.
$games = [];
$publicIds = [];
foreach (hospitality_bookable_fixtures($pdo, (int) ($seasonContext['season_id'] ?? 0)) as $index => $fx) {
    $games[(int) $fx['id']] = ['fixture' => $fx, 'rows' => []];
    if ($index < 3) {
        $publicIds[(int) $fx['id']] = true; // the public page lists the next three games
    }
}
$pendingCount = 0;
foreach ($allBookings as $b) {
    $isPending = $b['status'] !== 'paid';
    if ($isPending) {
        $pendingCount++;
        if (!$showPending) {
            continue;
        }
    }
    $id = (int) $b['fixture_id'];
    $games[$id] ??= ['fixture' => ['id' => $id, 'opponent' => $b['opponent'], 'match_date' => $b['match_date'], 'kickoff_time' => $b['kickoff_time'], 'competition' => $b['competition']], 'rows' => []];
    $games[$id]['rows'][] = $b;
}
uasort($games, static fn(array $a, array $b): int => strcmp((string) $a['fixture']['match_date'] . (string) ($a['fixture']['kickoff_time'] ?? ''), (string) $b['fixture']['match_date'] . (string) ($b['fixture']['kickoff_time'] ?? '')));

$upcoming = [];
$past = [];
foreach ($games as $id => $game) {
    if ((string) $game['fixture']['match_date'] >= $today) {
        $upcoming[$id] = $game;
    } else {
        $past[$id] = $game;
    }
}
$past = array_reverse($past, true);

$paidPlaces = static function (array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        $n += $r['status'] === 'paid' ? (int) $r['guests'] : 0;
    }
    return $n;
};
$paidIncome = static function (array $rows): float {
    $n = 0.0;
    foreach ($rows as $r) {
        $n += $r['status'] === 'paid' ? (float) $r['total_amount'] : 0.0;
    }
    return $n;
};

$newCount = 0;
$totalIncome = 0.0;
$upcomingPlaces = 0;
$upcomingIncome = 0.0;
foreach ($allBookings as $b) {
    if ($b['status'] !== 'paid') {
        continue;
    }
    $totalIncome += (float) $b['total_amount'];
    if ((string) ($b['paid_at'] ?? '') > $since) {
        $newCount++;
    }
    if ((string) $b['match_date'] >= $today) {
        $upcomingPlaces += (int) $b['guests'];
        $upcomingIncome += (float) $b['total_amount'];
    }
}
$onlineOpen = ($settings['enabled'] ?? '1') === '1';

$renderGame = static function (int $fixtureId, array $game) use ($capacity, $since, $paidPlaces, $paidIncome, $publicIds, $showPending, $onlineOpen): void {
    $fx = $game['fixture'];
    $rows = $game['rows'];
    $isCup = hospitality_is_cup($fx);
    $taken = $paidPlaces($rows);
    $pct = $capacity > 0 ? min(100, (int) round($taken / $capacity * 100)) : 0;
    $tone = $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success');
    $kickoff = trim((string) ($fx['kickoff_time'] ?? ''));
    $hasPaid = $paidPlaces($rows) > 0 || array_filter($rows, static fn(array $r): bool => $r['status'] === 'paid') !== [];
    ?>
    <article class="hub-panel hosp-game" aria-label="v <?= h((string) $fx['opponent']) ?>">
        <header class="hosp-game__head">
            <div class="hosp-game__title">
                <h3>v <?= h((string) $fx['opponent']) ?></h3>
                <p>
                    <i class="fa-solid fa-calendar-day" aria-hidden="true"></i> <?= h(date('D j M Y', strtotime((string) $fx['match_date']))) ?><?= $kickoff !== '' ? ' · ' . h(substr($kickoff, 0, 5)) . ' KO' : '' ?>
                    <span class="badge <?= $isCup ? 'text-bg-warning' : 'text-bg-light border' ?> ms-1"><?= $isCup ? 'Cup' : 'League' ?></span>
                    <?php if (isset($publicIds[$fixtureId])): ?><span class="badge text-bg-light border ms-1" title="Listed on the public Hospitality page"><i class="fa-solid fa-globe me-1" aria-hidden="true"></i>On public page</span><?php endif; ?>
                </p>
            </div>
            <div class="hosp-game__meter" role="group" aria-label="Places booked">
                <div class="hosp-game__meter-text"><strong><?= $taken ?></strong> / <?= $capacity ?> places<?= $taken >= $capacity ? ' · <span class="text-danger">Full</span>' : '' ?></div>
                <div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $tone ?>" style="width: <?= $pct ?>%"></div></div>
            </div>
            <div class="hosp-game__actions">
                <?php if ($hasPaid): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="?export=csv&amp;fixture=<?= $fixtureId ?>"><i class="fa-solid fa-download me-1" aria-hidden="true"></i>Guest list</a>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($rows === []): ?>
            <p class="hosp-game__empty"><?= $onlineOpen ? 'No bookings yet.' : 'No bookings, and online booking is currently closed.' ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                    <thead><tr><th>Booked</th><th>Guest</th><th>Contact</th><th class="text-end">Places</th><th class="text-end">Total</th><th>Dietary &amp; notes</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $b):
                        $isNew = $b['status'] === 'paid' && (string) ($b['paid_at'] ?? '') > $since; ?>
                        <tr class="<?= $isNew ? 'table-warning' : '' ?>">
                            <td data-label="Booked" class="small text-nowrap"><?= h(date('j M, H:i', strtotime((string) ($b['paid_at'] ?: $b['created_at'])))) ?><?= $isNew ? ' <span class="badge text-bg-danger ms-1">New</span>' : '' ?></td>
                            <td data-label="Guest"><a class="fw-bold text-decoration-none" href="/admin/hospitality_booking.php?id=<?= (int) $b['id'] ?>"><?= h((string) $b['contact_name']) ?></a><?= $b['company'] ? '<div class="small text-muted">' . h((string) $b['company']) . '</div>' : '' ?></td>
                            <td data-label="Contact" class="small"><a href="mailto:<?= h((string) $b['contact_email']) ?>"><?= h((string) $b['contact_email']) ?></a><div><a class="text-reset" href="tel:<?= h(preg_replace('/[^0-9+]/', '', (string) $b['contact_phone'])) ?>"><?= h((string) $b['contact_phone']) ?></a></div></td>
                            <td data-label="Places" class="text-end"><?= (int) $b['guests'] ?><div class="small text-muted"><?= h(ucfirst((string) $b['booking_type'])) ?></div></td>
                            <td data-label="Total" class="text-end fw-bold"><?= h(gbp((float) $b['total_amount'])) ?></td>
                            <td data-label="Dietary &amp; notes" class="small">
                                <?php if ($b['dietary_requirements']): ?><span class="badge text-bg-warning me-1">Dietary</span><?= nl2br(h((string) $b['dietary_requirements'])) ?><?php else: ?><span class="text-muted">None declared</span><?php endif; ?>
                                <?php if ($b['notes']): ?><div class="mt-1"><span class="badge text-bg-light border me-1">Note</span><?= nl2br(h((string) $b['notes'])) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Status"><span class="badge <?= $b['status'] === 'paid' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $b['status'] === 'paid' ? 'Paid' : 'Awaiting payment' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="3" class="small text-muted">Paid total for this game</td><td class="text-end fw-bold"><?= $taken ?></td><td class="text-end fw-bold"><?= h(gbp($paidIncome($rows))) ?></td><td colspan="2"></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php
};
?>
<div class="hosp-admin">
    <?php hub_render_metric_grid([
        ['label' => 'New bookings', 'value' => $newCount, 'meta' => 'Since you last looked', 'icon' => 'fa-bell', 'tone' => $newCount > 0 ? 'danger' : 'neutral'],
        ['label' => 'Places booked', 'value' => $upcomingPlaces, 'meta' => 'Upcoming games', 'icon' => 'fa-champagne-glasses', 'tone' => 'primary'],
        ['label' => 'Upcoming income', 'value' => gbp($upcomingIncome), 'meta' => 'Paid, games still to play', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
        ['label' => 'Income to date', 'value' => gbp($totalIncome), 'meta' => 'All paid bookings', 'icon' => 'fa-chart-line', 'tone' => 'info'],
    ], 'Hospitality summary'); ?>

    <div class="hosp-status">
        <span class="hosp-status__item"><span class="hosp-dot <?= $onlineOpen ? 'hosp-dot--on' : 'hosp-dot--off' ?>" aria-hidden="true"></span>Online booking is <strong><?= $onlineOpen ? 'open' : 'closed' ?></strong></span>
        <span class="hosp-status__item"><?= $capacity ?> places per game</span>
        <span class="hosp-status__item"><?= h(gbp((float) $settings['price_individual_league'])) ?> league · <?= h(gbp((float) $settings['price_individual_cup'])) ?> cup per person</span>
        <a class="hosp-status__link" href="/admin/hospitality_settings.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Change prices &amp; settings</a>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h2 class="h5 mb-0">Upcoming games</h2>
        <?php if ($pendingCount > 0): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $showPending ? '?' : '?pending=1' ?>"><?= $showPending ? 'Hide' : 'Show' ?> awaiting payment (<?= $pendingCount ?>)</a>
        <?php endif; ?>
    </div>

    <?php if ($upcoming === []): ?>
        <div class="hub-panel hosp-game"><p class="hosp-game__empty">No upcoming home league or cup games to book. Fixtures appear here once they are on the calendar.</p></div>
    <?php endif; ?>
    <?php foreach ($upcoming as $fixtureId => $game): $renderGame((int) $fixtureId, $game); endforeach; ?>

    <?php if ($past !== []): ?>
        <details class="hosp-past mt-4">
            <summary><h2 class="h5 d-inline mb-0">Past games</h2> <span class="text-muted small">(<?= count($past) ?>)</span></summary>
            <div class="mt-3">
                <?php foreach ($past as $fixtureId => $game): $renderGame((int) $fixtureId, $game); endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
