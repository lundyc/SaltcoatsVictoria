<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/season_ticket_attendance.php';
require_once __DIR__ . '/lib/match_tickets.php';
require_once __DIR__ . '/lib/ticketing_reporting.php';
require_once __DIR__ . '/lib/audit.php';
ensureMatchTicketSchema($pdo);

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('tickets_ops');

ensureSeasonTicketAttendanceSchema($pdo);

$currentUser = hub_auth_current_user();
$seasonContext = getSeasonContext($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$fixtureId = (int) ($_GET['fixture_id'] ?? 0);
$notice = '';
$error = '';

function scan_overview_match_label(array $fixture): string
{
    return 'vs ' . (string) ($fixture['opponent'] ?? 'Opponent');
}

function scan_overview_match_date(array $fixture): string
{
    $date = strtotime((string) ($fixture['match_date'] ?? ''));
    $time = trim((string) ($fixture['kickoff_time'] ?? ''));
    return ($date ? date('D j M Y', $date) : 'Date TBC') . ($time !== '' ? ' · ' . date('H:i', strtotime($time)) : '');
}

function scan_overview_match_type(array $fixture): string
{
    $type = strtolower(trim((string) ($fixture['competition_type'] ?? '')));
    if ($type === 'league') {
        return 'League';
    }
    if ($type === 'cup') {
        return 'Cup Match';
    }
    $competition = trim((string) ($fixture['competition'] ?? ''));
    if ($competition === '') {
        return 'Match';
    }
    if (stripos($competition, 'cup') !== false) {
        return 'Cup Match';
    }
    if (stripos($competition, 'league') !== false || stripos($competition, 'wosfl') !== false || stripos($competition, 'west of scotland') !== false) {
        return 'League';
    }
    return $competition;
}

$fixture = null;
if ($fixtureId > 0) {
    $stmt = $pdo->prepare('SELECT f.*, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.id = :id AND f.is_home = 1 LIMIT 1');
    $stmt->execute([':id' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$fixture && $seasonId > 0) {
    $stmt = $pdo->prepare("SELECT f.*, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.season_id = :season
          AND f.is_home = 1
          AND f.match_date >= CURDATE()
          AND f.status NOT IN ('played','completed','cancelled','postponed')
        ORDER BY f.match_date ASC, f.kickoff_time ASC, f.id ASC
        LIMIT 1");
    $stmt->execute([':season' => $seasonId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$fixture && $seasonId > 0) {
    $stmt = $pdo->prepare('SELECT f.*, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.season_id = :season AND f.is_home = 1
        ORDER BY f.match_date DESC, f.kickoff_time DESC, f.id DESC
        LIMIT 1');
    $stmt->execute([':season' => $seasonId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$canClearCheckIns = hub_auth_has_capability('tickets_ops');
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'clear_scans') {
    if (!$canClearCheckIns) {
        $error = 'You do not have permission to clear check-ins.';
    } elseif (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please reload and try again.';
    } elseif (!$fixture) {
        $error = 'No fixture is selected.';
    } else {
        $clearFixtureId = (int) $fixture['id'];
        $pdo->prepare('DELETE FROM admissions WHERE fixture_id = :fixture')->execute([':fixture' => $clearFixtureId]);
        $pdo->prepare('DELETE FROM scan_logs WHERE fixture_id = :fixture')->execute([':fixture' => $clearFixtureId]);
        auditLog($pdo, 'ticket_checkins_cleared', 'Cleared check-ins and scan logs for ' . scan_overview_match_label($fixture));
        $notice = 'Check-ins cleared for ' . scan_overview_match_label($fixture) . '.';
    }
}

$summary = $fixture ? season_ticket_scan_log_summary($pdo, (int) $fixture['id']) : ['total' => 0, 'valid' => 0, 'invalid' => 0];
$attendanceCount = $fixture ? season_ticket_attendance_count($pdo, (int) $fixture['id']) : 0;
$recentScans = $fixture ? season_ticket_recent_scan_logs($pdo, (int) $fixture['id'], 12) : [];
$enterScanUrl = $fixture ? '/scan/?fixture_id=' . (int) $fixture['id'] . '&season_id=' . (int) $seasonId : '/scan/?season_id=' . (int) $seasonId;

$pageHero = [
    'eyebrow' => 'Match Day',
    'title' => 'Scan Overview',
    'subtitle' => $fixture ? scan_overview_match_label($fixture) . ' · ' . scan_overview_match_date($fixture) : 'Select a home fixture in the scanner to start check-ins.',
    'actions' => $fixture ? [
        ['label' => 'Enter Scan', 'href' => $enterScanUrl, 'class' => 'btn btn-light btn-sm', 'icon' => 'fa-qrcode'],
    ] : [
        ['label' => 'Open Scanner', 'href' => $enterScanUrl, 'class' => 'btn btn-light btn-sm', 'icon' => 'fa-qrcode'],
    ],
];

require_once __DIR__ . '/header.php';
?>

<style>
    .scan-overview-layout { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:1rem; align-items:start; }
    .scan-overview-panel { background:#fff; border:1px solid rgba(75,8,24,.1); border-radius:1rem; box-shadow:0 12px 30px rgba(33,20,26,.06); overflow:hidden; }
    .scan-overview-panel__header { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1rem 1.1rem; border-bottom:1px solid rgba(75,8,24,.1); }
    .scan-overview-panel__header h2 { margin:0; color:#4b0818; font-size:1.05rem; font-weight:850; }
    .scan-overview-panel__body { padding:1.1rem; }
    .scan-overview-current { display:flex; flex-wrap:wrap; justify-content:space-between; gap:1rem; align-items:center; padding:1rem; border-radius:1rem; color:#fff; background:linear-gradient(135deg,#4b0818,#74172f); }
    .scan-overview-current strong { display:block; font-size:1.35rem; }
    .scan-overview-current span { color:rgba(255,255,255,.76); }
    .scan-overview-recent { display:grid; gap:.6rem; }
    .scan-overview-row { display:flex; justify-content:space-between; gap:1rem; padding:.8rem; border:1px solid rgba(75,8,24,.1); border-radius:.85rem; background:#fff; }
    .scan-overview-row.is-valid { background:#effaf3; border-color:#bbe7ca; }
    .scan-overview-row.is-invalid { background:#fff0f1; border-color:#f2b9c0; }
    .scan-overview-row strong, .scan-overview-row span { display:block; }
    .scan-overview-row span { color:#6f6470; font-size:.86rem; }
    @media (max-width:991.98px) { .scan-overview-layout { grid-template-columns:1fr; } }
</style>

<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if (!$fixture): ?>
    <div class="scan-overview-panel">
        <div class="scan-overview-panel__body text-center text-muted py-5">
            No home fixture is available for this season.
            <div class="mt-3"><a class="btn btn-brand" href="/scan/?season_id=<?= (int) $seasonId ?>">Open Scanner</a></div>
        </div>
    </div>
<?php else: ?>
    <?php
    hub_render_metric_grid([
        ['label' => 'Total scans', 'value' => $summary['total'], 'meta' => 'All scan attempts', 'icon' => 'fa-qrcode', 'tone' => 'primary'],
        ['label' => 'Valid', 'value' => $summary['valid'], 'meta' => 'Accepted entries', 'icon' => 'fa-circle-check', 'tone' => 'success'],
        ['label' => 'Invalid', 'value' => $summary['invalid'], 'meta' => 'Denied attempts', 'icon' => 'fa-circle-exclamation', 'tone' => 'danger'],
        ['label' => 'Checked in', 'value' => $attendanceCount, 'meta' => 'Unique successful tickets', 'icon' => 'fa-ticket', 'tone' => 'warning'],
    ], 'Scan summary');
    ?>

    <div class="scan-overview-layout">
        <section class="scan-overview-panel">
            <div class="scan-overview-panel__header">
                <h2>Current Game</h2>
                <span class="badge text-bg-light"><?= h(scan_overview_match_type($fixture)) ?></span>
            </div>
            <div class="scan-overview-panel__body">
                <div class="scan-overview-current mb-3">
                    <div>
                        <span>Home fixture</span>
                        <strong><?= h(scan_overview_match_label($fixture)) ?></strong>
                        <span><?= h(scan_overview_match_date($fixture)) ?></span>
                    </div>
                    <a class="btn btn-light" href="<?= h($enterScanUrl) ?>"><i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>Enter Scan</a>
                </div>

                <h2 class="h5 text-brand mb-3">Recent Scans</h2>
                <div class="scan-overview-recent">
                    <?php if (!$recentScans): ?>
                        <p class="text-muted mb-0">No scans have been recorded for this fixture yet.</p>
                    <?php else: ?>
                        <?php foreach ($recentScans as $scan): ?>
                            <?php $accepted = (int) ($scan['accepted'] ?? 0) === 1; ?>
                            <div class="scan-overview-row <?= $accepted ? 'is-valid' : 'is-invalid' ?>">
                                <div>
                                    <strong><?= h((string) ($scan['holder_name'] ?: 'Unknown ticket')) ?></strong>
                                    <span><?= h((string) ($scan['ticket_kind'] ?: 'Season Ticket')) ?><?= !empty($scan['ticket_label']) ? ' · ' . h((string) $scan['ticket_label']) : '' ?></span>
                                </div>
                                <div class="text-end">
                                    <strong class="<?= $accepted ? 'text-success' : 'text-danger' ?>"><?= $accepted ? 'Valid' : 'Invalid' ?></strong>
                                    <span><?= h(date('H:i', strtotime((string) $scan['scanned_at']))) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <aside class="scan-overview-panel">
            <div class="scan-overview-panel__header"><h2>Actions</h2></div>
            <div class="scan-overview-panel__body">
                <a class="btn btn-brand w-100 mb-3" href="<?= h($enterScanUrl) ?>"><i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>Enter Scan</a>
                <a class="btn btn-outline-secondary w-100 mb-3" href="/scan/?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-list me-1" aria-hidden="true"></i>Choose Match</a>
                <?php if ($canClearCheckIns): ?>
                    <form method="post" data-confirm="Clear all check-ins and scan logs for this fixture?" data-confirm-action="Clear">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="clear_scans">
                        <button class="btn btn-outline-danger w-100" type="submit"><i class="fa-solid fa-trash-can me-1" aria-hidden="true"></i>Clear Check Ins</button>
                    </form>
                <?php endif; ?>
            </div>
        </aside>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
