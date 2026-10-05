<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/season.php';
require_once __DIR__ . '/../admin/lib/season_ticket_attendance.php';
require_once __DIR__ . '/../admin/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('tickets_scan');

$currentUser = hub_auth_current_user();
$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($_GET['season_id'] ?? ($currentSeason['id'] ?? 0));
$fixtureId = (int) ($_GET['fixture_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

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

$totalMatches = 0;
$homeMatches = [];
if (!$fixture && $seasonId > 0) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM match_fixtures
        WHERE season_id = :season
          AND is_home = 1
          AND match_date >= CURDATE()
          AND status NOT IN ('played','completed','cancelled','postponed')");
    $countStmt->execute([':season' => $seasonId]);
    $totalMatches = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT f.*, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.season_id = :season
          AND f.is_home = 1
          AND f.match_date >= CURDATE()
          AND f.status NOT IN (\'played\',\'completed\',\'cancelled\',\'postponed\')
        ORDER BY f.match_date ASC, f.kickoff_time ASC, f.id ASC
        LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute([':season' => $seasonId]);
    $homeMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$recent = $fixture ? season_ticket_recent_scan_logs($pdo, (int) $fixture['id'], 25) : [];
$attendanceCount = $fixture ? season_ticket_attendance_count($pdo, (int) $fixture['id']) : 0;
$scanSummary = $fixture ? season_ticket_scan_log_summary($pdo, (int) $fixture['id']) : ['total' => 0, 'valid' => 0, 'invalid' => 0];

function scan_match_label(array $fixture): string
{
    return 'vs ' . (string) ($fixture['opponent'] ?? 'Opponent');
}

function scan_match_date(array $fixture): string
{
    $date = strtotime((string) ($fixture['match_date'] ?? ''));
    $time = trim((string) ($fixture['kickoff_time'] ?? ''));
    return ($date ? date('D j M Y', $date) : 'Date TBC') . ($time !== '' ? ' · ' . date('H:i', strtotime($time)) : '');
}

function scan_match_type_label(array $fixture): string
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
        return '';
    }
    if (stripos($competition, 'cup') !== false) {
        return 'Cup Match';
    }
    if (stripos($competition, 'league') !== false || stripos($competition, 'wosfl') !== false || stripos($competition, 'west of scotland') !== false) {
        return 'League';
    }
    return $competition;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4b0818">
    <title>Scan Season Tickets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <style>
        :root { --scan-maroon:#4b0818; --scan-gold:#e0b42a; --scan-bg:#f7f4ef; --scan-ink:#24151b; --scan-muted:#746872; --scan-line:#eadfdf; --scan-panel:#fff; }
        html, body { min-height:100%; margin:0; background:var(--scan-bg); color:var(--scan-ink); font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body { padding-bottom:calc(78px + env(safe-area-inset-bottom)); }
        .scan-app { min-height:100vh; background:var(--scan-bg); }
        .scan-picker { min-height:100vh; padding:18px 16px calc(96px + env(safe-area-inset-bottom)); background:linear-gradient(180deg,#4b0818 0%,#6b1026 34%,#f7f4ef 34%,#f7f4ef 100%); }
        .scan-topbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; }
        .scan-brand { display:flex; align-items:center; gap:.7rem; color:#fff; font-weight:850; }
        .scan-brand img { width:42px; height:42px; object-fit:contain; }
        .scan-user { color:rgba(255,255,255,.78); font-size:.85rem; }
        .scan-title { color:#fff; font-size:clamp(2rem,9vw,4rem); font-weight:900; letter-spacing:0; margin:1.5rem 0 .45rem; }
        .scan-subtitle { color:rgba(255,255,255,.82); margin:0 0 1.4rem; }
        .scan-match-list { display:grid; gap:.75rem; }
        .scan-match-card { display:flex; justify-content:space-between; align-items:center; gap:1rem; width:100%; text-align:left; color:var(--scan-ink); text-decoration:none; background:var(--scan-panel); border:1px solid var(--scan-line); border-radius:16px; padding:1rem; box-shadow:0 12px 30px rgba(75,8,24,.08); }
        .scan-match-card.is-next { color:#fff; background:linear-gradient(135deg,var(--scan-maroon),#74172f); border-color:rgba(224,180,42,.72); box-shadow:0 18px 40px rgba(75,8,24,.24); }
        .scan-match-card:active, .scan-match-card:hover { color:var(--scan-ink); border-color:rgba(75,8,24,.34); background:#fff; }
        .scan-match-card.is-next:active, .scan-match-card.is-next:hover { color:#fff; background:linear-gradient(135deg,#5d0c21,#84213a); border-color:var(--scan-gold); }
        .scan-match-card strong { display:block; font-size:1rem; }
        .scan-match-card span { display:block; color:var(--scan-muted); font-size:.88rem; margin-top:.18rem; }
        .scan-match-card.is-next span { color:rgba(255,255,255,.78); }
        .scan-pager { display:flex; justify-content:space-between; gap:.75rem; margin-top:1rem; padding-bottom:1rem; }
        .scan-pager a, .scan-pager span { min-width:120px; text-align:center; border-radius:999px; padding:.72rem 1rem; border:1px solid rgba(75,8,24,.22); color:#fff; font-weight:850; text-decoration:none; background:var(--scan-maroon); box-shadow:0 10px 24px rgba(75,8,24,.18); }
        .scan-pager span { opacity:.58; background:#f1ecec; border-color:var(--scan-line); color:var(--scan-muted); box-shadow:none; }
        .scan-screen { position:fixed; inset:0; overflow:hidden; background:var(--scan-maroon); }
        .scan-video { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .scan-camera-message { position:absolute; inset:0; display:grid; place-items:center; text-align:center; padding:2rem; color:#fff; background:radial-gradient(circle at center,rgba(75,8,24,.28),rgba(75,8,24,.82)); }
        .scan-frame { position:absolute; inset:17% 11% auto; height:min(54vw,260px); border:3px solid var(--scan-gold); border-radius:24px; box-shadow:0 0 0 999px rgba(75,8,24,.16); pointer-events:none; }
        .scan-header { position:absolute; top:env(safe-area-inset-top); left:0; right:0; z-index:5; display:flex; justify-content:space-between; align-items:center; padding:14px 16px; background:linear-gradient(to bottom,rgba(75,8,24,.78),rgba(75,8,24,0)); }
        .scan-header a, .scan-header button { color:#fff; border:1px solid rgba(224,180,42,.34); background:rgba(75,8,24,.72); border-radius:999px; width:44px; height:44px; display:grid; place-items:center; text-decoration:none; }
        .scan-fixture-label { position:absolute; left:16px; right:16px; bottom:calc(92px + env(safe-area-inset-bottom)); z-index:3; border-radius:16px; padding:.9rem 1rem; color:#fff; background:rgba(75,8,24,.86); backdrop-filter:blur(16px); border:1px solid rgba(224,180,42,.34); }
        .scan-fixture-label strong { display:block; }
        .scan-fixture-label span { color:rgba(255,255,255,.7); font-size:.86rem; }
        .scan-overlay { position:fixed; inset:0; z-index:20; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:#fff; padding:2rem; transform:translateY(105%); transition:transform .18s ease; }
        .scan-overlay.is-visible { transform:translateY(0); }
        .scan-overlay.is-success { background:#05ad58; }
        .scan-overlay.is-danger { background:#ef3544; }
        .scan-overlay__icon { width:104px; height:104px; border:3px solid rgba(255,255,255,.78); border-radius:999px; display:grid; place-items:center; font-size:4.1rem; font-weight:900; margin-bottom:1.3rem; }
        .scan-overlay__title { font-size:clamp(2.35rem,12vw,5rem); font-weight:900; line-height:1; }
        .scan-overlay__meta { margin-top:.8rem; font-size:1.25rem; color:rgba(255,255,255,.86); }
        .scan-sheet { position:fixed; left:0; right:0; bottom:calc(78px + env(safe-area-inset-bottom)); z-index:12; max-height:70vh; overflow:auto; background:#fff; color:var(--scan-ink); border-radius:24px 24px 0 0; padding:1rem; box-shadow:0 -24px 50px rgba(75,8,24,.2); transform:translateY(110%); transition:transform .18s ease; }
        .scan-sheet.is-open { transform:translateY(0); }
        .scan-sheet h2 { color:var(--scan-maroon); font-weight:900; font-size:1.25rem; margin:0 0 .75rem; }
        .scan-recent-row { display:flex; justify-content:space-between; gap:1rem; border-bottom:1px solid #eadfdf; padding:.75rem 0; }
        .scan-recent-row.is-success { background:#eefaf3; border:1px solid #bce8cc; border-radius:12px; padding:.75rem; margin-bottom:.5rem; }
        .scan-recent-row.is-danger { background:#fff0f1; border:1px solid #f4b8c0; border-radius:12px; padding:.75rem; margin-bottom:.5rem; }
        .scan-recent-row strong { display:block; }
        .scan-recent-row span { color:#6f6470; font-size:.86rem; }
        .scan-recent-status { display:flex; align-items:center; gap:.45rem; justify-content:flex-end; font-weight:850; color:var(--scan-maroon); }
        .scan-bottom-nav { position:fixed; left:0; right:0; bottom:0; z-index:30; display:grid; grid-template-columns:repeat(4,1fr); gap:.25rem; padding:8px 10px calc(8px + env(safe-area-inset-bottom)); background:rgba(75,8,24,.96); backdrop-filter:blur(18px); border-top:3px solid var(--scan-gold); }
        .scan-bottom-nav a, .scan-bottom-nav button { border:0; background:transparent; color:rgba(255,255,255,.72); display:flex; flex-direction:column; align-items:center; gap:.25rem; text-decoration:none; font-size:.72rem; font-weight:700; }
        .scan-bottom-nav i { font-size:1.18rem; }
        .scan-bottom-nav .active { color:#fff; }
        .scan-hidden { display:none !important; }
        .scan-submit { background:var(--scan-maroon); border-color:var(--scan-maroon); color:#fff; }
        .scan-submit:hover, .scan-submit:focus { background:#641126; border-color:#641126; color:#fff; }
    </style>
</head>
<body>
<div class="scan-app" data-fixture-id="<?= $fixture ? (int) $fixture['id'] : 0 ?>" data-csrf="<?= h($csrfToken) ?>">
    <?php if (!$fixture): ?>
        <main class="scan-picker">
            <div class="scan-topbar">
                <div class="scan-brand"><img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt=""> <span>Vics Scan</span></div>
                <div class="scan-user"><?= h((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? 'Hub user')) ?></div>
            </div>
            <h1 class="scan-title">Select Home Match</h1>
            <p class="scan-subtitle">Choose a home fixture to open the season ticket scanner.</p>
            <div class="scan-match-list">
                <?php foreach ($homeMatches as $index => $match): ?>
                    <a class="scan-match-card <?= $index === 0 && $page === 1 ? 'is-next' : '' ?>" href="/scan/?fixture_id=<?= (int) $match['id'] ?>&amp;season_id=<?= (int) $seasonId ?>">
                        <?php $matchTypeLabel = scan_match_type_label($match); ?>
                        <span><strong><?= h(scan_match_label($match)) ?></strong><span><?= h(scan_match_date($match)) ?><?= $matchTypeLabel !== '' ? ' · ' . h($matchTypeLabel) : '' ?></span></span>
                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
                <?php if (!$homeMatches): ?>
                    <div class="scan-match-card"><span><strong>No upcoming home matches found</strong><span>There are no future home fixtures available for scanning.</span></span></div>
                <?php endif; ?>
            </div>
            <div class="scan-pager">
                <?php if ($page > 1): ?><a href="/scan/?season_id=<?= (int) $seasonId ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php else: ?><span>Previous</span><?php endif; ?>
                <?php if ($offset + $perPage < $totalMatches): ?><a href="/scan/?season_id=<?= (int) $seasonId ?>&amp;page=<?= $page + 1 ?>">Next</a><?php else: ?><span>Next</span><?php endif; ?>
            </div>
        </main>
    <?php else: ?>
        <main class="scan-screen">
            <video class="scan-video" data-scan-video playsinline muted></video>
            <div class="scan-camera-message" data-scan-camera-message>
                <div>
                    <div class="spinner-border mb-3" aria-hidden="true"></div>
                    <div>Starting camera...</div>
                    <div class="small mt-2">Use manual entry if camera scanning is unavailable.</div>
                </div>
            </div>
            <div class="scan-frame" aria-hidden="true"></div>
            <div class="scan-header">
                <a href="/scan/?season_id=<?= (int) $seasonId ?>" aria-label="Back to matches"><i class="fa-solid fa-chevron-left"></i></a>
                <button type="button" data-scan-restart aria-label="Restart camera"><i class="fa-solid fa-camera"></i></button>
            </div>
            <div class="scan-fixture-label">
                <strong><?= h(scan_match_label($fixture)) ?></strong>
                <span><?= h(scan_match_date($fixture)) ?> · <span data-scan-count><?= (int) $attendanceCount ?></span> checked in</span>
            </div>
            <div class="scan-overlay" data-scan-overlay aria-live="assertive">
                <div class="scan-overlay__icon" data-scan-overlay-icon>✓</div>
                <div class="scan-overlay__title" data-scan-overlay-title>Welcome In</div>
                <div class="scan-overlay__meta" data-scan-overlay-meta></div>
            </div>
            <section class="scan-sheet" data-scan-manual>
                <h2>Manual Entry</h2>
                <form data-scan-form>
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                    <label class="form-label" for="scanValue">Ticket code (e.g. K3F-9XQP)</label>
                    <input class="form-control form-control-lg text-uppercase" id="scanValue" name="scan_value" autocomplete="off" autocapitalize="characters" placeholder="XXX-XXXX">
                    <button class="btn scan-submit w-100 mt-3" type="submit">Check In</button>
                </form>
            </section>
            <section class="scan-sheet" data-scan-recent>
                <h2>Recent Check-ins</h2>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Total scans</strong><strong class="fs-4" data-scan-total><?= (int) $scanSummary['total'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between gap-3 small mt-2">
                        <span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Valid <span data-scan-valid><?= (int) $scanSummary['valid'] ?></span></span>
                        <span class="text-danger fw-semibold"><i class="fa-solid fa-circle-exclamation me-1" aria-hidden="true"></i>Invalid <span data-scan-invalid><?= (int) $scanSummary['invalid'] ?></span></span>
                    </div>
                </div>
                <div data-scan-recent-list>
                    <?php foreach ($recent as $row): ?>
                        <?php $accepted = (int) ($row['accepted'] ?? 0) === 1; ?>
                        <div class="scan-recent-row <?= $accepted ? 'is-success' : 'is-danger' ?>">
                            <div><strong><?= h((string) ($row['holder_name'] ?: 'Unknown ticket')) ?></strong><span><?= h('Season Ticket' . (!empty($row['ticket_label']) ? ' · ' . (string) $row['ticket_label'] : '')) ?></span></div>
                            <div class="scan-recent-status"><i class="fa-solid <?= $accepted ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?>" aria-hidden="true"></i><span><?= h(date('H:i', strtotime((string) $row['scanned_at']))) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><p class="text-muted mb-0" data-scan-recent-empty>No check-ins yet.</p><?php endif; ?>
                </div>
            </section>
        </main>
    <?php endif; ?>

    <nav class="scan-bottom-nav" aria-label="Scan navigation">
        <a class="<?= !$fixture ? 'active' : '' ?>" href="/scan/?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-list"></i><span>Matches</span></a>
        <button class="<?= $fixture ? 'active' : '' ?>" type="button" data-scan-camera-nav <?= $fixture ? '' : 'disabled' ?>><i class="fa-solid fa-qrcode"></i><span>Scan</span></button>
        <button type="button" data-scan-manual-open <?= $fixture ? '' : 'disabled' ?>><i class="fa-solid fa-keyboard"></i><span>Manual</span></button>
        <button type="button" data-scan-recent-open <?= $fixture ? '' : 'disabled' ?>><i class="fa-solid fa-clock-rotate-left"></i><span>Recent</span></button>
    </nav>
</div>

<?php if ($fixture): ?>
<script>
(() => {
    const app = document.querySelector('.scan-app');
    const video = document.querySelector('[data-scan-video]');
    const cameraMessage = document.querySelector('[data-scan-camera-message]');
    const overlay = document.querySelector('[data-scan-overlay]');
    const overlayIcon = document.querySelector('[data-scan-overlay-icon]');
    const overlayTitle = document.querySelector('[data-scan-overlay-title]');
    const overlayMeta = document.querySelector('[data-scan-overlay-meta]');
    const manualSheet = document.querySelector('[data-scan-manual]');
    const recentSheet = document.querySelector('[data-scan-recent]');
    const form = document.querySelector('[data-scan-form]');
    const countEls = document.querySelectorAll('[data-scan-count]');
    const totalScanEl = document.querySelector('[data-scan-total]');
    const validScanEl = document.querySelector('[data-scan-valid]');
    const invalidScanEl = document.querySelector('[data-scan-invalid]');
    const recentList = document.querySelector('[data-scan-recent-list]');
    let stream = null;
    let detector = null;
    let scanning = false;
    let lastScan = '';
    let lastScanAt = 0;
    let audioContext = null;

    const encodeBody = (data) => Array.from(data.entries()).map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
    const closeSheets = () => { manualSheet?.classList.remove('is-open'); recentSheet?.classList.remove('is-open'); };

    const beep = (success) => {
        try {
            audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
            if (audioContext.state === 'suspended') audioContext.resume();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = success ? 900 : 210;
            gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.22, audioContext.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + (success ? 0.18 : 0.34));
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + (success ? 0.2 : 0.36));
        } catch (error) {}
    };

    const showOverlay = (success, title, meta) => {
        overlay.classList.remove('is-success', 'is-danger', 'is-visible');
        overlay.classList.add(success ? 'is-success' : 'is-danger');
        overlayIcon.textContent = success ? '✓' : '!';
        overlayTitle.textContent = title;
        overlayMeta.textContent = meta || '';
        window.requestAnimationFrame(() => overlay.classList.add('is-visible'));
        beep(success);
        window.setTimeout(() => overlay.classList.remove('is-visible'), 1800);
    };

    const addRecent = (scan, holder) => {
        if (!recentList) return;
        recentList.querySelector('[data-scan-recent-empty]')?.remove();
        const row = document.createElement('div');
        const accepted = scan ? !!scan.accepted : false;
        row.className = `scan-recent-row ${accepted ? 'is-success' : 'is-danger'}`;
        const scannedAt = scan && scan.scanned_at ? scan.scanned_at : '';
        const time = scannedAt ? new Date(scannedAt.replace(' ', 'T')) : new Date();
        row.innerHTML = '<div><strong></strong><span></span></div><div class="scan-recent-status"><i aria-hidden="true"></i><span></span></div>';
        const name = (scan && scan.holder_name) || (holder && holder.name) || 'Unknown ticket';
        const kind = (scan && scan.ticket_kind) || (holder && holder.ticket_kind) || 'Season Ticket';
        const label = (scan && scan.ticket_label) || (holder && holder.ticket) || '';
        row.querySelector('strong').textContent = name;
        row.querySelector('div span').textContent = `${kind}${label ? ' · ' + label : ''}`;
        row.querySelector('i').className = `fa-solid ${accepted ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger'}`;
        row.querySelector('.scan-recent-status span').textContent = time.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        recentList.prepend(row);
    };

    const incrementSummary = (accepted) => {
        if (totalScanEl) totalScanEl.textContent = String((parseInt(totalScanEl.textContent || '0', 10) || 0) + 1);
        const target = accepted ? validScanEl : invalidScanEl;
        if (target) target.textContent = String((parseInt(target.textContent || '0', 10) || 0) + 1);
    };

    const formatManualCode = (value) => {
        value = String(value || '').toUpperCase();
        if (value.startsWith('HTTP') || value.includes('TOKEN=')) return value;
        const clean = value.replace(/[^A-Z0-9]/g, '').slice(0, 7);
        return clean.length > 3 ? `${clean.slice(0, 3)}-${clean.slice(3)}` : clean;
    };

    const submitScan = async (value) => {
        value = String(value || '').trim();
        if (!value) {
            showOverlay(false, 'No Ticket', 'Enter or scan a ticket first.');
            return;
        }
        const now = Date.now();
        if (value === lastScan && now - lastScanAt < 3500) return;
        lastScan = value;
        lastScanAt = now;
        const body = new FormData(form);
        body.set('scan_value', value);
        try {
            const response = await fetch('/match_ticket_scan.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: encodeBody(body),
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                showOverlay(false, payload.status === 'already_scanned' ? 'Already Scanned' : 'Denied', payload.message || 'Ticket is not valid.');
                addRecent(payload.scan, payload.holder);
                incrementSummary(false);
                return;
            }
            const fresh = payload.status !== 'already_scanned';
            showOverlay(fresh, fresh ? 'Welcome In' : 'Already Scanned', payload.holder && payload.holder.name ? payload.holder.name : payload.message);
            if (typeof payload.count !== 'undefined') countEls.forEach((el) => { el.textContent = String(payload.count); });
            addRecent(payload.scan, payload.holder);
            incrementSummary(true);
            if (form.elements.scan_value) form.elements.scan_value.value = '';
            closeSheets();
        } catch (error) {
            showOverlay(false, 'Scan Failed', 'Try again.');
        }
    };

    const scanLoop = async () => {
        if (!scanning || !detector || !video) return;
        try {
            const codes = await detector.detect(video);
            if (codes && codes.length && codes[0].rawValue) submitScan(codes[0].rawValue);
        } catch (error) {}
        if (scanning) window.setTimeout(scanLoop, 450);
    };

    const stopCamera = () => {
        scanning = false;
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null;
        if (video) video.srcObject = null;
    };

    const startCamera = async () => {
        closeSheets();
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            cameraMessage.innerHTML = '<div>Camera QR scanning is not supported here.<div class="small mt-2">Use Manual at the bottom.</div></div>';
            return;
        }
        stopCamera();
        try {
            detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            video.srcObject = stream;
            await video.play();
            scanning = true;
            cameraMessage.classList.add('scan-hidden');
            scanLoop();
        } catch (error) {
            cameraMessage.classList.remove('scan-hidden');
            cameraMessage.innerHTML = '<div>Camera could not be started.<div class="small mt-2">Check permissions or use Manual.</div></div>';
        }
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitScan(form.elements.scan_value ? form.elements.scan_value.value : '');
    });
    form?.elements.scan_value?.addEventListener('input', (event) => {
        const input = event.target;
        const formatted = formatManualCode(input.value);
        if (input.value !== formatted) input.value = formatted;
    });
    document.querySelector('[data-scan-manual-open]')?.addEventListener('click', () => { recentSheet?.classList.remove('is-open'); manualSheet?.classList.toggle('is-open'); form?.elements.scan_value?.focus(); });
    document.querySelector('[data-scan-recent-open]')?.addEventListener('click', () => { manualSheet?.classList.remove('is-open'); recentSheet?.classList.toggle('is-open'); });
    document.querySelector('[data-scan-camera-nav]')?.addEventListener('click', () => { closeSheets(); startCamera(); });
    document.querySelector('[data-scan-restart]')?.addEventListener('click', startCamera);
    window.addEventListener('beforeunload', stopCamera);
    startCamera();
})();
</script>
<?php endif; ?>
</body>
</html>
