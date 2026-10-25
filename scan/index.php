<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/season.php';
require_once __DIR__ . '/../admin/lib/season_ticket_attendance.php';
require_once __DIR__ . '/../admin/lib/match_tickets.php';
require_once __DIR__ . '/../admin/lib/events_scan.php';
ensureMatchTicketSchema($pdo);

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/scan/')));
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
    $stmt = $pdo->prepare('SELECT f.*, mc.competition_type, mo.logo_path AS opponent_logo
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        LEFT JOIN match_opponents mo ON mo.id = f.opponent_id
        WHERE f.id = :id AND f.is_home = 1 LIMIT 1');
    $stmt->execute([':id' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Only a match played today can be scanned, so nobody can open the scanner for a future fixture beforehand.
$todayDate = scan_business_date();
$notTodayNotice = false;
if ($fixture && (string) $fixture['match_date'] !== $todayDate) {
    $fixture = null;
    $notTodayNotice = true;
}

$featuredMatches = [];
if (!$fixture && $seasonId > 0) {
    $stmt = $pdo->prepare('SELECT f.*, mc.competition_type, mo.logo_path AS opponent_logo
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        LEFT JOIN match_opponents mo ON mo.id = f.opponent_id
        WHERE f.season_id = :season
          AND f.is_home = 1
          AND f.match_date = :today
          AND f.status NOT IN (\'cancelled\',\'postponed\')
        ORDER BY f.kickoff_time ASC, f.id ASC');
    $stmt->execute([':season' => $seasonId, ':today' => $todayDate]);
    $featuredMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// An event (speaker night, tribute night...) can be scanned in the same app: same-day only, like matches.
$scanEvent = null;
$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId > 0 && !$fixture) {
    $scanEvent = events_get($pdo, $eventId);
    if (!$scanEvent || substr((string) $scanEvent['starts_at'], 0, 10) !== $todayDate || !in_array($scanEvent['status'], ['on_sale', 'sold_out'], true)) {
        $scanEvent = null;
        $notTodayNotice = true;
    }
}
$scanning = (bool) ($fixture || $scanEvent);
$todayEvents = $scanning ? [] : events_scannable_today($pdo);
$eventCapacity = $scanEvent ? events_capacity($scanEvent) : 0;

// Post-login home: the first match/event today gets a details card; anything else today goes behind the ... menu.
$primary = null;
$others = [];
if (!$scanning) {
    $choices = [];
    foreach ($featuredMatches as $m) {
        $choices[] = ['kind' => 'match', 'id' => (int) $m['id'], 'row' => $m];
    }
    foreach ($todayEvents as $ev) {
        $choices[] = ['kind' => 'event', 'id' => (int) $ev['id'], 'row' => $ev];
    }
    $primary = array_shift($choices);
    $others = $choices;
    // People already admitted, straight from the server (includes anyone let in without a ticket on the saved list).
    $primaryIn = 0;
    if ($primary) {
        $primaryIn = $primary['kind'] === 'event' ? events_admitted_count($pdo, (int) $primary['id']) : season_ticket_attendance_count($pdo, (int) $primary['id']);
    }
}
$canOverview = hub_auth_has_capability('tickets_ops');

if ($scanEvent) {
    $recent = events_recent_scan_logs($pdo, (int) $scanEvent['id'], 25);
    $attendanceCount = events_admitted_count($pdo, (int) $scanEvent['id']);
    $scanSummary = events_scan_summary($pdo, (int) $scanEvent['id']);
} else {
    $recent = $fixture ? season_ticket_recent_scan_logs($pdo, (int) $fixture['id'], 25) : [];
    $attendanceCount = $fixture ? season_ticket_attendance_count($pdo, (int) $fixture['id']) : 0;
    $scanSummary = $fixture ? season_ticket_scan_log_summary($pdo, (int) $fixture['id']) : ['total' => 0, 'valid' => 0, 'invalid' => 0];
}

/** Day label and traffic-light class for a fixture date relative to today. */
function scan_day_info(array $fixture): array
{
    $days = (int) round((strtotime((string) ($fixture['match_date'] ?? '')) - strtotime(scan_business_date())) / 86400);
    if ($days === 0) {
        return ['TODAY', 'today'];
    }
    if ($days === 1) {
        return ['TOMORROW', 'other'];
    }
    if ($days === -1) {
        return ['YESTERDAY', 'other'];
    }
    return [$days > 0 ? 'in ' . $days . ' days' : abs($days) . ' days ago', 'other'];
}

/** Opponent badge URL (uploaded crest, else the WOSFL badge cache), or '' if none exists. */
function scan_opponent_badge(array $fixture): string
{
    $logo = basename(trim((string) ($fixture['opponent_logo'] ?? '')));
    $adminDir = dirname(__DIR__) . '/admin';
    $candidates = [];
    if ($logo !== '') {
        $candidates[] = ['uploads/opponents/' . $logo, '/admin/uploads/opponents/' . rawurlencode($logo)];
        $candidates[] = ['badges/' . $logo, '/admin/badges/' . rawurlencode($logo)];
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($fixture['opponent'] ?? ''))), '-');
    if ($slug !== '') {
        $candidates[] = ['badges/' . $slug . '.png', '/admin/badges/' . $slug . '.png'];
    }
    foreach ($candidates as [$rel, $url]) {
        if (is_file($adminDir . '/' . $rel)) {
            return $url;
        }
    }
    return '';
}

function scan_badge_html(array $fixture): string
{
    $url = scan_opponent_badge($fixture);
    return $url !== ''
        ? '<img class="scan-badge" src="' . h($url) . '" alt="" width="48" height="48" loading="lazy">'
        : '<span class="scan-badge scan-badge--none" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>';
}

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
    <title>Ticket Scanner</title>
    <link rel="manifest" href="/scan/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/scan/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Ticket Scanner">
    <link href="/admin/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        :root { --scan-maroon:#4b0818; --scan-gold:#e0b42a; --scan-bg:#f7f4ef; --scan-ink:#24151b; --scan-muted:#746872; --scan-line:#eadfdf; --scan-panel:#fff; }
        html, body { min-height:100%; margin:0; background:var(--scan-bg); color:var(--scan-ink); font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body { padding-bottom:calc(78px + env(safe-area-inset-bottom)); }
        body.no-nav { padding-bottom:env(safe-area-inset-bottom); }
        .scan-app { min-height:100vh; background:var(--scan-bg); }
        .scan-picker { min-height:100vh; padding:0 0 calc(24px + env(safe-area-inset-bottom)); background:var(--scan-bg); }
        .scan-hero { padding:18px 16px 1.6rem; background:linear-gradient(180deg,#4b0818 0%,#6b1026 100%); }
        .scan-hero .scan-title { margin-top:1rem; }
        .scan-hero .scan-subtitle { margin-bottom:0; }
        .scan-picker-body { padding:1.25rem 16px 0; }
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
        .scan-fixture-label { position:absolute; left:16px; right:16px; bottom:calc(var(--scan-nav-h, calc(78px + env(safe-area-inset-bottom))) + 14px); z-index:3; border-radius:16px; padding:.9rem 1rem; color:#fff; background:rgba(75,8,24,.86); backdrop-filter:blur(16px); border:1px solid rgba(224,180,42,.34); }
        .scan-fixture-label strong { display:block; font-size:1.25rem; }
        .scan-fixture-label { background:rgba(75,8,24,.88); }
        .scan-fixture-label.is-offline { background:rgba(196,32,47,.95); border-color:rgba(255,255,255,.6); }
        .scan-day-badge { display:inline-block !important; margin:0 0 .35rem !important; padding:.15rem .6rem; border-radius:999px; font-size:.78rem !important; font-weight:800; letter-spacing:.04em; color:#fff !important; }
        .scan-day-badge.is-today { background:#15803d; }
        .scan-day-badge.is-other { background:#b45309; }
        .scan-featured strong { font-size:1.35rem; }
        .scan-match-card { justify-content:flex-start; }
        .scan-match-card > i { margin-left:auto; }
        .scan-card-text { flex:1; min-width:0; }
        .scan-badge { width:48px; height:48px; flex:0 0 48px; object-fit:contain; border-radius:10px; background:#fff; padding:3px; }
        .scan-badge--none { display:inline-flex !important; align-items:center; justify-content:center; background:rgba(120,100,110,.14); color:var(--scan-muted) !important; font-size:1.3rem !important; margin:0 !important; }
        .scan-fixture-label .scan-badge { float:left; margin-right:.8rem; }
        .scan-fixture-label { overflow:hidden; }
        .scan-leave-toast { position:fixed; left:50%; bottom:calc(110px + env(safe-area-inset-bottom)); z-index:60; transform:translate(-50%,12px); opacity:0; pointer-events:none; background:rgba(36,21,27,.94); color:#fff; padding:.7rem 1.1rem; border-radius:999px; font-size:.92rem; font-weight:600; white-space:nowrap; transition:opacity .2s, transform .2s; }
        .scan-leave-toast.is-visible { opacity:1; transform:translate(-50%,0); }
        .scan-fixture-label span { color:rgba(255,255,255,.85); font-size:.86rem; }
        .scan-overlay { position:fixed; inset:0; z-index:20; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:#fff; padding:2rem; transform:translateY(105%); transition:transform .18s ease; }
        .scan-overlay.is-visible { transform:translateY(0); }
        .scan-overlay.is-success { background:#05ad58; }
        .scan-overlay.is-danger { background:#ef3544; }
        .scan-overlay__icon { width:104px; height:104px; border:3px solid rgba(255,255,255,.78); border-radius:999px; display:grid; place-items:center; font-size:4.1rem; font-weight:900; margin-bottom:1.3rem; }
        .scan-overlay__title { font-size:clamp(2.35rem,12vw,5rem); font-weight:900; line-height:1; }
        .scan-overlay__meta { margin-top:.8rem; font-size:1.25rem; color:rgba(255,255,255,.86); white-space:pre-line; }
        .scan-sheet { position:fixed; left:0; right:0; bottom:calc(var(--scan-nav-h, calc(78px + env(safe-area-inset-bottom))) - 1px); z-index:12; max-height:70vh; overflow:auto; background:#fff; color:var(--scan-ink); border-radius:24px 24px 0 0; padding:1rem; box-shadow:0 -24px 50px rgba(75,8,24,.2); transform:translateY(110%); transition:transform .18s ease; }
        .scan-sheet.is-open { transform:translateY(0); }
        .scan-sheet h2 { display:flex; align-items:center; justify-content:space-between; color:var(--scan-maroon); font-weight:900; font-size:1.25rem; margin:0 0 .75rem; }
        .scan-header .scan-gate-chip { width:auto; height:44px; min-width:44px; max-width:55vw; display:inline-flex; align-items:center; justify-content:center; gap:.45rem; padding:0 1rem; border:1px solid rgba(224,180,42,.34); background:rgba(75,8,24,.72); color:#fff; border-radius:999px; font-size:.9rem; font-weight:700; line-height:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .scan-header { gap:.5rem; }
        .scan-sheet-close { border:0; background:rgba(75,8,24,.08); color:var(--scan-maroon); width:44px; height:44px; border-radius:50%; font-size:1.7rem; line-height:1; }
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
        .scan-banner { position:fixed; left:0; right:0; top:env(safe-area-inset-top); z-index:40; display:none; align-items:center; justify-content:space-between; gap:.75rem; padding:.7rem 1rem; color:#fff; font-weight:700; font-size:.95rem; background:#b3261e; box-shadow:0 6px 18px rgba(0,0,0,.25); }
        .scan-banner.is-visible { display:flex; }
        .scan-banner.is-warning { background:#8a5a00; }
        .scan-banner a, .scan-banner button { flex:none; color:#fff; border:1px solid rgba(255,255,255,.7); background:transparent; border-radius:999px; padding:.3rem .85rem; font-weight:800; text-decoration:none; }
        .scan-submit { background:var(--scan-maroon); border-color:var(--scan-maroon); color:#fff; }
        .scan-submit:hover, .scan-submit:focus { background:#641126; border-color:#641126; color:#fff; }
        .scan-home { padding:0; }
        .scan-home__bar { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:calc(12px + env(safe-area-inset-top)) 16px 10px; background:#4b0818; color:#fff; }
        .scan-home__bar .scan-brand { gap:.6rem; }
        .scan-home__bar .scan-brand img { width:34px; height:34px; }
        .scan-home__user { color:rgba(255,255,255,.8); font-size:.85rem; margin-right:auto; }
        .scan-dots { position:relative; }
        .scan-dots__btn { width:44px; height:44px; border-radius:50%; border:1px solid rgba(224,180,42,.4); background:rgba(255,255,255,.08); color:#fff; font-size:1.2rem; display:grid; place-items:center; }
        .scan-dots__menu { position:absolute; right:0; top:50px; z-index:30; min-width:260px; max-width:86vw; background:#fff; color:var(--scan-ink); border-radius:14px; box-shadow:0 18px 40px rgba(0,0,0,.3); padding:.4rem; display:none; }
        .scan-dots__menu.is-open { display:block; }
        .scan-dots__menu h2 { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--scan-muted); margin:.5rem .7rem .2rem; font-weight:800; }
        .scan-dots__menu a { display:flex; align-items:center; gap:.7rem; min-height:48px; padding:.5rem .7rem; color:var(--scan-ink); text-decoration:none; border-radius:10px; font-weight:650; }
        .scan-dots__menu a:active, .scan-dots__menu a:hover { background:#f3ecec; }
        .scan-dots__menu small { display:block; color:var(--scan-muted); font-weight:500; }
        .scan-status { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; padding:12px 16px; background:#5a0f23; color:#fff; border-top:1px solid rgba(255,255,255,.14); }
        .scan-pill { display:inline-block; padding:.4rem .8rem; border-radius:8px; font-size:.95rem; font-weight:650; background:rgba(255,255,255,.16); color:#fff; }
        .scan-pill.is-today { background:#15803d; color:#fff; text-transform:uppercase; letter-spacing:.04em; font-size:.85rem; font-weight:800; }
        .scan-pill.is-ready { background:#a4b43c; color:#1f2a05; }
        .scan-pill.is-wait { background:#e0b42a; color:#3b2a00; }
        .scan-pill.is-bad { background:#ef3544; color:#fff; }
        .scan-pill.is-online { background:#e4e7ee; color:#4a4f5c; }
        .scan-info { width:36px; height:36px; border-radius:50%; border:0; background:transparent; color:#fff; font-size:1.35rem; }
        .scan-sync { margin-left:auto; text-align:right; font-size:.8rem; color:rgba(255,255,255,.8); line-height:1.25; }
        .scan-sync strong { display:block; color:#fff; font-size:.92rem; }
        .scan-infobox { display:none; padding:.7rem 16px; background:#fff7e0; color:#4a3a00; font-size:.9rem; }
        .scan-infobox.is-open { display:block; }
        .scan-event { background:linear-gradient(180deg,#6b1026 0%,#7a1a33 100%); color:#fff; padding:18px 16px 6px; }
        .scan-event__title { font-size:clamp(1.6rem,7vw,2.2rem); font-weight:850; line-height:1.15; margin:.2rem 0 .1rem; overflow-wrap:anywhere; }
        .scan-event__venue { font-size:1.25rem; color:rgba(255,255,255,.75); }
        .scan-event__venue b { color:#fff; font-weight:650; }
        .scan-event__date { font-size:1.15rem; color:rgba(255,255,255,.62); margin-top:.25rem; }
        .scan-event__badge { display:inline-block; margin-bottom:.4rem; padding:.15rem .6rem; border-radius:999px; font-size:.78rem; font-weight:800; letter-spacing:.04em; background:#15803d; color:#fff; }
        .scan-counts { margin:14px 0 0; padding:6px 0 10px; border-top:1px solid rgba(255,255,255,.2); }
        .scan-counts div { display:flex; justify-content:space-between; gap:1rem; padding:.42rem 0; font-size:1.1rem; color:rgba(255,255,255,.7); }
        .scan-counts dd, .scan-counts dt { margin:0; font-weight:400; }
        .scan-counts dd { color:#fff; font-variant-numeric:tabular-nums; }
        .scan-counts .is-total { color:#fff; }
        .scan-actions { padding:16px; display:grid; gap:.75rem; }
        .scan-go { display:flex; align-items:center; gap:1rem; min-height:72px; padding:.9rem 1.1rem; border-radius:16px; background:var(--scan-maroon); color:#fff; text-decoration:none; font-size:1.3rem; font-weight:800; box-shadow:0 12px 28px rgba(75,8,24,.25); }
        .scan-go i:first-child { font-size:2rem; }
        .scan-go i:last-child { margin-left:auto; color:var(--scan-gold); }
        .scan-go:active { background:#641126; color:#fff; }
        .scan-none { padding:2rem 16px; text-align:center; color:var(--scan-muted); }
        .scan-others { padding:4px 16px 24px; display:grid; gap:.6rem; }
        .scan-others h2 { font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:var(--scan-muted); font-weight:800; margin:.4rem 0 .1rem; }
        .scan-other { display:flex; align-items:center; gap:.9rem; min-height:64px; padding:.7rem .9rem; border-radius:14px; background:#fff; border:1px solid var(--scan-line); color:var(--scan-ink); text-decoration:none; }
        .scan-other__text { flex:1; min-width:0; }
        .scan-other__text strong { display:block; font-size:1.05rem; overflow-wrap:anywhere; }
        .scan-other__text span { display:block; color:var(--scan-muted); font-size:.86rem; }
        .scan-other > i:last-child { color:var(--scan-maroon); }
        .scan-signout { display:inline-flex; align-items:center; gap:.45rem; min-height:44px; padding:0 .9rem; border-radius:999px; border:1px solid rgba(224,180,42,.4); color:#fff; text-decoration:none; font-weight:700; font-size:.9rem; background:rgba(255,255,255,.08); }
        .scan-event { position:relative; }
        .scan-event__menu { position:absolute; top:12px; right:12px; z-index:5; }
        .scan-event .scan-event__title { padding-right:56px; }
        .scan-other-wrap { position:relative; display:flex; align-items:stretch; gap:0; background:#fff; border:1px solid var(--scan-line); border-radius:14px; }
        .scan-other-wrap .scan-other { border:0; background:transparent; border-radius:14px 0 0 14px; }
        .scan-other-wrap .scan-other { flex:1; min-width:0; }
        .scan-other-wrap .scan-dots__btn { height:100%; min-height:64px; border-radius:0; border:0; border-left:1px solid var(--scan-line); background:transparent; color:var(--scan-maroon); }
        .scan-other-wrap .scan-dots { display:flex; flex:none; width:48px; }
        .scan-other-wrap .scan-dots__btn { width:48px; }
        .scan-other-wrap .scan-dots__menu { top:calc(100% + 6px); }
        .scan-event__menu .scan-dots__menu { top:calc(100% + 6px); }
        .scan-dots__menu { min-width:200px; }
        /* Yellow scheme: gold status strip and event card, maroon kept for the top bar and the scan button. */
        .scan-status[hidden], .scan-infobox[hidden] { display:none !important; }
        /* Only shown when the phone is offline: a red strip, plain white text (no pill background). */
        .scan-status { background:#c4202f; color:#fff; border-top:0; }
        .scan-status .scan-pill, .scan-status .scan-pill.is-wait, .scan-status .scan-pill.is-bad, .scan-status .scan-pill.is-today { background:transparent; color:#fff; padding:0; border-radius:0; font-size:1.05rem; font-weight:800; letter-spacing:.02em; text-transform:none; }
        .scan-status .scan-info { color:#fff; }
        .scan-status .scan-sync { color:rgba(255,255,255,.85); }
        .scan-status .scan-sync strong { color:#fff; }
        .scan-infobox { background:#fff3c4; color:#3a2a00; border-bottom:1px solid #ecd27a; }
        .scan-event { background:linear-gradient(180deg,#6b1026 0%,#7a1a33 100%); color:#fff; padding:18px 16px 0; overflow:hidden; }
        .scan-event__title { color:#fff; }
        .scan-event__venue { color:rgba(255,255,255,.78); }
        .scan-event__venue b { color:#fff; }
        .scan-event__date { color:rgba(255,255,255,.62); }
        .scan-counts { border-top:1px solid rgba(255,255,255,.2); }
        .scan-counts div { color:rgba(255,255,255,.7); }
        .scan-counts dd, .scan-counts .is-total { color:#fff; }
        .scan-counts .is-total { font-weight:700; }
        .scan-event .scan-dots__btn { border-color:rgba(224,180,42,.5); background:rgba(255,255,255,.08); color:#fff; }
        .scan-go--attached { margin:14px -16px 0; border-radius:0; box-shadow:none; min-height:68px; background:#f3c53a; color:#3a0713; }
        .scan-go--attached i:first-child, .scan-go--attached i:last-child { color:#3a0713; }
        .scan-go--attached:active { background:#e9b522; color:#3a0713; }
        .scan-pill.is-today { background:#15803d; color:#fff; }
        /* "Also on today": a smaller version of the main card, maroon with a yellow scan button. */
        .scan-others { padding:18px 16px 24px; display:grid; gap:.8rem; }
        .scan-others h2 { margin:0 0 .1rem; font-size:.8rem; text-transform:uppercase; letter-spacing:.07em; color:var(--scan-muted); font-weight:800; }
        .scan-also { background:linear-gradient(180deg,#6b1026 0%,#7a1a33 100%); color:#fff; border-radius:18px; overflow:hidden; box-shadow:0 10px 24px rgba(75,8,24,.18); }
        .scan-also__top { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; padding:14px 14px 12px 16px; }
        .scan-also__text { min-width:0; }
        .scan-also__tag { display:inline-block; font-size:.7rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; padding:.1rem .55rem; border-radius:999px; background:rgba(243,197,58,.2); color:#f6d160; }
        .scan-also h3 { margin:.35rem 0 .15rem; font-size:1.2rem; font-weight:800; line-height:1.2; overflow-wrap:anywhere; color:#fff; }
        .scan-also p { margin:0; color:rgba(255,255,255,.72); font-size:.9rem; }
        .scan-also .scan-dots__btn { width:40px; height:40px; font-size:1rem; border-color:rgba(224,180,42,.5); background:rgba(255,255,255,.08); color:#fff; }
        .scan-also .scan-dots__menu { top:calc(100% + 6px); }
        .scan-also__go { display:flex; align-items:center; gap:.8rem; min-height:56px; padding:0 16px; background:#f3c53a; color:#3a0713; text-decoration:none; font-weight:800; font-size:1.1rem; }
        .scan-also__go i { color:#3a0713; }
        .scan-also__go i:last-child { margin-left:auto; }
        .scan-also__go:active { background:#e9b522; color:#3a0713; }
        /* "Also on today", plain list version. */
        .scan-list { background:#fff; border:1px solid var(--scan-line); border-radius:16px; overflow:visible; }
        .scan-row { display:flex; align-items:center; gap:.6rem; padding:.85rem .9rem .85rem 1rem; border-bottom:1px solid var(--scan-line); }
        .scan-row:last-child { border-bottom:0; }
        .scan-row__text { flex:1; min-width:0; }
        .scan-row__text strong { display:block; font-size:1.05rem; line-height:1.25; overflow-wrap:anywhere; color:var(--scan-ink); }
        .scan-row__text span { display:block; margin-top:.1rem; color:var(--scan-muted); font-size:.85rem; }
        .scan-row__go { flex:none; display:inline-flex; align-items:center; gap:.4rem; min-height:44px; padding:0 1rem; border-radius:999px; background:#f3c53a; color:#3a0713; font-weight:800; text-decoration:none; }
        .scan-row__go:active { background:#e9b522; color:#3a0713; }
        .scan-row .scan-dots { flex:none; }
        .scan-row .scan-dots__btn { width:44px; height:44px; font-size:1rem; border:1px solid var(--scan-line); background:#fff; color:var(--scan-maroon); }
        .scan-row .scan-dots__menu { top:calc(100% + 6px); }
        /* "Also on today": full-width blocks, ... top right, big scan button underneath. */
        .scan-others { padding:18px 0 24px; gap:0; }
        .scan-others h2 { padding:0 16px .6rem; }
        .scan-list { border-radius:0; border-left:0; border-right:0; }
        .scan-row { display:block; padding:16px; }
        .scan-row__top { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; }
        .scan-row__text strong { font-size:1.3rem; }
        .scan-row__text span { font-size:.95rem; }
        .scan-row__go { display:flex; width:100%; box-sizing:border-box; margin-top:14px; min-height:68px; padding:0 18px; gap:.9rem; border-radius:14px; font-size:1.3rem; }
        .scan-row__go i:first-child { font-size:1.9rem; }
        .scan-row__go i:last-child { margin-left:auto; }
        /* Recent check-ins: title and totals stay at the top, only the list scrolls. */
        .scan-sheet--fixedhead { display:flex; flex-direction:column; overflow:hidden; }
        .scan-sheet--fixedhead .scan-sheet__head { flex:none; }
        .scan-sheet--fixedhead .scan-sheet__scroll { flex:1 1 auto; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
    </style>
</head>
<body class="<?= $scanning ? 'has-nav' : 'no-nav' ?>">
<div class="scan-app" data-fixture-id="<?= $fixture ? (int) $fixture['id'] : 0 ?>" data-csrf="<?= h($csrfToken) ?>">
    <div class="scan-banner" data-scan-banner role="alert"><span data-scan-banner-text></span><span data-scan-banner-action></span></div>
    <?php
    // Per-card "..." menu: options for that one match or event.
    $cardMenu = static function (string $kind, int $id) use ($canOverview): string {
        if (!$canOverview) {
            return '';
        }
        return '<div class="scan-dots"><button type="button" class="scan-dots__btn" data-scan-menu-btn aria-label="Options for this ' . ($kind === 'event' ? 'event' : 'match') . '" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>'
            . '<div class="scan-dots__menu" data-scan-menu role="menu"><a role="menuitem" href="/scan/stats.php?kind=' . h($kind) . '&amp;id=' . $id . '"><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span>Event stats</span></a></div></div>';
    };
    ?>
    <?php if (!$scanning): ?>
        <main class="scan-home">
            <div class="scan-home__bar">
                <div class="scan-brand"><img src="/scan/logo-96.png" alt="" width="34" height="34"><span class="scan-home__user"><?= h((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? 'Hub user')) ?></span></div>
                <a class="scan-signout" href="/admin/logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sign out</a>
            </div>
            <?php if ($primary): ?>
                <?php
                $pr = $primary['row'];
                $isMatch = $primary['kind'] === 'match';
                if ($isMatch) {
                    [$dayLabel, $dayClass] = scan_day_info($pr);
                    $pTitle = scan_match_label($pr);
                    $pVenue = trim((string) ($pr['venue'] ?? '')) ?: 'Campbell Park';
                    $pWhen = scan_match_date($pr);
                    $pType = scan_match_type_label($pr);
                    $pUrl = '/scan/?fixture_id=' . (int) $primary['id'] . '&season_id=' . (int) $seasonId;
                } else {
                    $dayLabel = 'EVENT TODAY';
                    $pTitle = (string) $pr['title'];
                    $pVenue = (string) ($pr['venue_name'] ?? '');
                    $pWhen = date('D j M Y · H:i', strtotime((string) $pr['starts_at']));
                    $pType = (int) ($pr['min_age'] ?? 0) > 0 ? EVENT_MIN_AGES[(int) $pr['min_age']] : '';
                    $pUrl = '/scan/?event_id=' . (int) $primary['id'] . '&season_id=' . (int) $seasonId;
                }
                ?>
                <?php if ($notTodayNotice): ?><div class="alert alert-warning m-3 mb-0">That match or event is not today, so it cannot be scanned.</div><?php endif; ?>
                <div class="scan-status" data-scan-status hidden>
                    <span class="scan-pill is-today" data-scan-net>Online</span>
                    <button type="button" class="scan-info" data-scan-info-btn aria-label="What do these mean?" aria-expanded="false"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></button>
                    <div class="scan-sync">Last sync<strong data-scan-sync-age>–</strong></div>
                </div>
                <div class="scan-infobox" data-scan-infobox hidden>The pill shows this phone's signal. <strong>Online</strong> (green): scans are checked and recorded live. <strong>Offline · list saved</strong> (amber): no signal, but today's ticket list is on this phone, so tickets can still be checked and are sent when the signal returns. <strong>Offline · no list</strong> (red): no signal and no saved list, so tickets cannot be checked until there is signal.</div>
                <section class="scan-event" aria-label="Today">
                    <div class="scan-event__menu"><?= $cardMenu($primary['kind'], (int) $primary['id']) ?></div>
                    <h1 class="scan-event__title"><?= h($pTitle) ?></h1>
                    <?php if ($pVenue !== ''): ?><div class="scan-event__venue">at <b><?= h($pVenue) ?></b></div><?php endif; ?>
                    <div class="scan-event__date"><?= h($pWhen) ?><?= $pType !== '' ? ' · ' . h($pType) : '' ?></div>
                    <dl class="scan-counts" data-scan-counts data-target="<?= h($primary['kind'] . '-' . (int) $primary['id']) ?>" data-server-in="<?= (int) $primaryIn ?>">
                        <div><dt><?= $isMatch ? 'Match tickets' : 'Tickets' ?></dt><dd data-c="tickets">–</dd></div>
                        <?php if ($isMatch): ?><div><dt>Season tickets</dt><dd data-c="season">–</dd></div><?php endif; ?>
                        <div class="is-total"><dt>Total</dt><dd data-c="total">–</dd></div>
                        <div><dt>Scanned by this phone</dt><dd data-c="mine">0</dd></div>
                    </dl>
                                    <a class="scan-go scan-go--attached" href="<?= h($pUrl) ?>"><i class="fa-solid fa-qrcode" aria-hidden="true"></i><span>Scan tickets</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
                </section>
                <?php if ($others): ?>
                    <section class="scan-others" aria-label="Also on today">
                        <h2>Also on today</h2>
                        <div class="scan-list">
                        <?php foreach ($others as $o): $r = $o['row']; ?>
                            <?php
                            if ($o['kind'] === 'match') {
                                $oTitle = scan_match_label($r);
                                $oMeta = scan_match_date($r);
                                $oVenue = trim((string) ($r['venue'] ?? '')) ?: 'Campbell Park';
                                $oUrl = '/scan/?fixture_id=' . (int) $o['id'] . '&season_id=' . (int) $seasonId;
                            } else {
                                $oTitle = (string) $r['title'];
                                $oMeta = date('D j M · H:i', strtotime((string) $r['starts_at']));
                                $oVenue = (string) $r['venue_name'];
                                $oUrl = '/scan/?event_id=' . (int) $o['id'] . '&season_id=' . (int) $seasonId;
                            }
                            ?>
                            <div class="scan-row">
                                <div class="scan-row__top">
                                    <div class="scan-row__text">
                                        <strong><?= h($oTitle) ?></strong>
                                        <span><?= h($oMeta) ?></span>
                                        <?php if ($oVenue !== ''): ?><span>at <?= h($oVenue) ?></span><?php endif; ?>
                                    </div>
                                    <?= $cardMenu($o['kind'], (int) $o['id']) ?>
                                </div>
                                <a class="scan-row__go" href="<?= h($oUrl) ?>" aria-label="Scan tickets for <?= h($oTitle) ?>"><i class="fa-solid fa-qrcode" aria-hidden="true"></i><span>Scan tickets</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($notTodayNotice): ?><div class="alert alert-warning m-3">That match or event is not today, so it cannot be scanned.</div><?php endif; ?>
                <div class="scan-none"><strong>No match or event today</strong><p class="mb-0">The scanner opens on the day of a home match or an event.</p></div>
            <?php endif; ?>
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
            <?php if ($scanEvent): ?>
            <div class="scan-fixture-label">
                <span class="scan-badge scan-badge--none" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>
                <strong><?= h((string) $scanEvent['title']) ?></strong>
                <span><?= h(date('H:i', strtotime((string) $scanEvent['starts_at']))) ?> · <span data-scan-count><?= (int) $attendanceCount ?></span> of <?= (int) $eventCapacity ?> admitted<?= (int) $scanEvent['min_age'] > 0 ? ' · ' . h(EVENT_MIN_AGES[(int) $scanEvent['min_age']]) : '' ?></span>
            </div>
            <?php else: ?>
            <?php [$dayLabel, $dayClass] = scan_day_info($fixture); ?>
            <div class="scan-fixture-label">
                <?= scan_badge_html($fixture) ?>
                <strong><?= h(scan_match_label($fixture)) ?></strong>
                <span><?= h(scan_match_date($fixture)) ?> · <span data-scan-count><?= (int) $attendanceCount ?></span> checked in</span>
            </div>
            <?php endif; ?>
            <div class="scan-overlay" data-scan-overlay aria-live="assertive">
                <div class="scan-overlay__icon" data-scan-overlay-icon>✓</div>
                <div class="scan-overlay__title" data-scan-overlay-title>Welcome In</div>
                <div class="scan-overlay__meta" data-scan-overlay-meta></div>
            </div>
            <section class="scan-sheet" data-scan-manual>
                <h2>Manual Entry<button type="button" class="scan-sheet-close" data-scan-sheet-close aria-label="Close">&times;</button></h2>
                <form data-scan-form>
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <?php if ($scanEvent): ?><input type="hidden" name="event_id" value="<?= (int) $scanEvent['id'] ?>"><?php else: ?><input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>"><?php endif; ?>
                    <label class="form-label" for="scanValue">Ticket code (e.g. K3F-9XQP)</label>
                    <input class="form-control form-control-lg text-uppercase" id="scanValue" name="scan_value" autocomplete="off" autocapitalize="characters" placeholder="XXX-XXXX">
                    <button class="btn scan-submit w-100 mt-3" type="submit">Check In</button>
                </form>
            </section>
            <section class="scan-sheet scan-sheet--fixedhead" data-scan-recent>
                <div class="scan-sheet__head">
                <h2>Recent Check-ins<button type="button" class="scan-sheet-close" data-scan-sheet-close aria-label="Close">&times;</button></h2>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Total scans</strong><strong class="fs-4" data-scan-total><?= (int) $scanSummary['total'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between gap-3 small mt-2">
                        <span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Valid <span data-scan-valid><?= (int) $scanSummary['valid'] ?></span></span>
                        <span class="text-danger fw-semibold"><i class="fa-solid fa-circle-exclamation me-1" aria-hidden="true"></i>Invalid <span data-scan-invalid><?= (int) $scanSummary['invalid'] ?></span></span>
                    </div>
                </div>
                </div>
                <div class="scan-sheet__scroll" data-scan-recent-list>
                    <?php foreach ($recent as $row): ?>
                        <?php $accepted = (int) ($row['accepted'] ?? 0) === 1; ?>
                        <div class="scan-recent-row <?= $accepted ? 'is-success' : 'is-danger' ?>">
                            <div><strong><?= h((string) ($row['holder_name'] ?: 'Unknown ticket')) ?></strong><span><?= h(($scanEvent ? 'Event Ticket' : 'Season Ticket') . (!empty($row['ticket_label']) ? ' · ' . (string) $row['ticket_label'] : '')) ?></span></div>
                            <div class="scan-recent-status"><i class="fa-solid <?= $accepted ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?>" aria-hidden="true"></i><span><?= h(date('H:i', strtotime((string) $row['scanned_at']))) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><p class="text-muted mb-0" data-scan-recent-empty>No check-ins yet.</p><?php endif; ?>
                </div>
            </section>
        </main>
    <?php endif; ?>

    <?php if ($scanning): ?>
    <nav class="scan-bottom-nav" aria-label="Scan navigation">
        <a class="<?= !$fixture ? 'active' : '' ?>" href="/scan/?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-list"></i><span>Today</span></a>
        <button class="<?= $scanning ? 'active' : '' ?>" type="button" data-scan-camera-nav <?= $scanning ? '' : 'disabled' ?>><i class="fa-solid fa-qrcode"></i><span>Scan</span></button>
        <button type="button" data-scan-manual-open <?= $scanning ? '' : 'disabled' ?>><i class="fa-solid fa-keyboard"></i><span>Manual</span></button>
        <button type="button" data-scan-recent-open <?= $scanning ? '' : 'disabled' ?>><i class="fa-solid fa-clock-rotate-left"></i><span>Recent</span></button>
    </nav>
    <?php endif; ?>
</div>

<?php if ($scanning): ?>
<script src="/scan/jsQR.js?v=<?= (int) @filemtime(__DIR__ . '/jsQR.js') ?>"></script>
<script src="/scan/offline.js?v=<?= (int) @filemtime(__DIR__ . '/offline.js') ?>"></script>
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
    const gateSheet = document.querySelector('[data-scan-gate]');
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

    const fixtureId = form && form.elements.fixture_id ? form.elements.fixture_id.value : '';
    const csrf = app.dataset.csrf || '';
    const banner = document.querySelector('[data-scan-banner]');
    const bannerText = document.querySelector('[data-scan-banner-text]');
    const bannerAction = document.querySelector('[data-scan-banner-action]');
    let blocked = false;       // login/session ended: nothing will be accepted until the page is reloaded
    let offlineSince = 0;
    let reportsSent = 0;
    let flushing = false;
    const PENDING_KEY = 'ticket-scanner-pending-reports';

    const showBanner = (text, warning, actionHtml) => {
        if (!banner) return;
        bannerText.textContent = text;
        bannerAction.innerHTML = actionHtml || '';
        banner.classList.toggle('is-warning', !!warning);
        banner.classList.add('is-visible');
    };
    const hideBanner = () => banner && banner.classList.remove('is-visible');

    // Problems the phone sees (no signal, camera trouble, odd replies) are sent to the Hub log.
    // If there is no signal they wait in the phone's storage and are sent when it returns.
    const readPending = () => { try { return JSON.parse(localStorage.getItem(PENDING_KEY) || '[]'); } catch (e) { return []; } };
    const writePending = (list) => { try { localStorage.setItem(PENDING_KEY, JSON.stringify(list.slice(-30))); } catch (e) {} };
    const flushReports = async () => {
        if (flushing || blocked || !navigator.onLine) return;
        flushing = true;
        try {
            let list = readPending();
            while (list.length) {
                const item = list[0];
                const body = new URLSearchParams({ csrf_token: csrf, fixture_id: fixtureId, ...item });
                const res = await fetch('/admin/scan_problem_report.php', { method: 'POST', credentials: 'same-origin', keepalive: true, body });
                if (!res.ok) break;
                list = readPending().slice(1);
                writePending(list);
            }
        } catch (e) {} finally { flushing = false; }
    };
    const report = (kind, message, extra = {}) => {
        if (reportsSent++ >= 25) return; // a broken page must not flood the log
        const stamp = new Date().toLocaleTimeString();
        const list = readPending();
        list.push({ kind, message: String(message || '').slice(0, 250), detail: `${extra.detail || ''} [phone time ${stamp}; online=${navigator.onLine}]`.trim(), http_status: extra.http_status || '', duration_ms: extra.duration_ms || '' });
        writePending(list);
        flushReports();
    };
    window.addEventListener('error', (event) => report('client_error', event.message || 'Script error', { detail: `${event.filename || ''}:${event.lineno || ''}` }));
    window.addEventListener('unhandledrejection', (event) => report('client_error', 'Unhandled: ' + String((event.reason && event.reason.message) || event.reason || '')));
    window.addEventListener('offline', () => { offlineSince = Date.now(); if (!(window.ScanOffline && ScanOffline.ready())) showBanner('No signal. Scans are not being recorded.', true); });
    window.addEventListener('online', () => {
        if (offlineSince) report('network', `Phone had no signal for ${Math.round((Date.now() - offlineSince) / 1000)} s`);
        offlineSince = 0;
        if (!blocked) hideBanner();
        flushReports();
    });
    if (!navigator.onLine) { offlineSince = Date.now(); if (!(window.ScanOffline && ScanOffline.ready())) showBanner('No signal. Scans are not being recorded.', true); }
    flushReports();

    const encodeBody = (data) => Array.from(data.entries()).map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
    // Back button: first closes an open Manual/Recent sheet, then asks for a second press before leaving the scanner.
    let sheetEntry = false;
    let ignorePop = false;
    let leaveUntil = 0;
    const hideSheets = () => { manualSheet?.classList.remove('is-open'); recentSheet?.classList.remove('is-open'); gateSheet?.classList.remove('is-open'); };
    const closeSheets = () => {
        hideSheets();
        if (sheetEntry) { sheetEntry = false; ignorePop = true; history.back(); }
    };
    const leaveToast = document.createElement('div');
    leaveToast.className = 'scan-leave-toast';
    leaveToast.setAttribute('role', 'status');
    leaveToast.textContent = 'Press back again to leave the scanner';
    document.body.appendChild(leaveToast);
    let leaveToastTimer = 0;
    history.pushState({ scanGuard: 1 }, '');
    const toggleSheet = (sheet, other) => {
        const wasOpen = !!sheet?.classList.contains('is-open');
        [manualSheet, recentSheet, gateSheet].forEach((x) => { if (x && x !== sheet) x.classList.remove('is-open'); });
        if (wasOpen) { closeSheets(); return false; }
        sheet?.classList.add('is-open');
        if (!sheetEntry) { history.pushState({ scanSheet: 1 }, ''); sheetEntry = true; }
        return true;
    };
    window.addEventListener('popstate', () => {
        if (ignorePop) { ignorePop = false; return; }
        if (sheetEntry) { sheetEntry = false; hideSheets(); return; }
        if (Date.now() < leaveUntil) { leaveUntil = 0; history.back(); return; }
        leaveUntil = Date.now() + 3000;
        history.pushState({ scanGuard: 1 }, '');
        leaveToast.classList.add('is-visible');
        clearTimeout(leaveToastTimer);
        leaveToastTimer = setTimeout(() => leaveToast.classList.remove('is-visible'), 3000);
    });

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

    let overlayShownAt = 0;
    let overlayTimer = 0;
    const hideOverlay = () => { window.clearTimeout(overlayTimer); overlay.classList.remove('is-visible'); };
    overlay.addEventListener('click', () => { if (Date.now() - overlayShownAt > 400) hideOverlay(); });

    const showOverlay = (success, title, meta, extra) => {
        overlay.classList.remove('is-success', 'is-danger', 'is-visible');
        overlay.classList.add(success ? 'is-success' : 'is-danger');
        overlayIcon.textContent = success ? '✓' : '!';
        overlayTitle.textContent = title;
        overlayMeta.textContent = [meta, extra && extra.progress, extra && extra.offline ? 'Checked offline, will sync' : ''].filter(Boolean).join('\n');
        window.requestAnimationFrame(() => overlay.classList.add('is-visible'));
        beep(success);
        overlayShownAt = Date.now();
        window.clearTimeout(overlayTimer);
        // Success is a quick confirmation; a refusal stays longer so it cannot be missed. Tap to dismiss.
        overlayTimer = window.setTimeout(hideOverlay, success ? 2500 : 4000);
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

    // Shows the result of a scan, whether it came from the server or from the phone's saved list.
    const finishPayload = (payload, httpOk) => {
        if (!httpOk || !payload.ok) {
            showOverlay(false, payload.status === 'already_scanned' ? 'Already Scanned' : 'Denied', (payload.message || 'Ticket is not valid.') + (payload.offline ? ' (offline)' : ''));
            // A ticket that belongs to a different match or event is refused but is not part of this one's list.
            if (!['wrong_fixture', 'wrong_event', 'wrong_ticket_type', 'not_on_list'].includes(payload.status)) {
                addRecent(payload.scan, payload.holder);
                incrementSummary(false);
            }
            return;
        }
        const fresh = payload.status !== 'already_scanned';
        showOverlay(fresh, fresh ? 'Welcome In' : 'Already Scanned', payload.holder && payload.holder.name ? payload.holder.name : payload.message, fresh ? { progress: payload.progress, offline: payload.offline } : null);
        if (typeof payload.count !== 'undefined') countEls.forEach((el) => { el.textContent = String(payload.count); });
        if (fresh) { try { const k = 'ticket-scanner-mine-' + <?= json_encode(($scanEvent ? 'event-' : 'match-') . ($scanEvent ? (int) $scanEvent['id'] : (int) $fixture['id'])) ?>; localStorage.setItem(k, String((parseInt(localStorage.getItem(k) || '0', 10) || 0) + 1)); } catch (e) {} }
        addRecent(payload.scan, payload.holder);
        incrementSummary(true);
        if (form.elements.scan_value) form.elements.scan_value.value = '';
        closeSheets();
    };

    const submitScan = async (value) => {
        value = String(value || '').trim();
        if (!value) {
            showOverlay(false, 'No Ticket', 'Enter or scan a ticket first.');
            return;
        }
        if (blocked) {
            showOverlay(false, 'Scanner Stopped', 'Log in again or reload this page first.');
            return;
        }
        const now = Date.now();
        if (value === lastScan && now - lastScanAt < 8000) return; // same code still in view: ignore repeats
        lastScan = value;
        lastScanAt = now;
        const body = new FormData(form);
        body.set('scan_value', value);
        if (window.ScanOffline) { body.set('gate', ScanOffline.getGate()); body.set('device', ScanOffline.deviceId()); }
        // No signal and a saved list: check on the phone straight away instead of waiting for a request to fail.
        if (window.ScanOffline && ScanOffline.shouldUseOffline()) {
            const off = await ScanOffline.scanOffline(value);
            if (off) { finishPayload(off, true); return; }
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), (window.ScanOffline && ScanOffline.ready()) ? 4000 : 8000);
        const started = performance.now();
        let response;
        let raw = '';
        try {
            response = await fetch('/admin/match_ticket_scan.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: encodeBody(body),
                signal: controller.signal,
            });
            raw = await response.text();
        } catch (error) {
            window.clearTimeout(timer);
            const timedOut = !!(error && error.name === 'AbortError');
            const took = Math.round(performance.now() - started);
            if (window.ScanOffline && ScanOffline.ready()) {
                const off = await ScanOffline.fallback(value); // the live check failed: use the saved list
                if (off) { finishPayload(off, true); return; }
            }
            lastScan = ''; heldCode = ''; // the ticket was not checked, so scanning it again must work straight away
            showOverlay(false, timedOut ? 'No Reply' : 'No Signal', timedOut ? 'The server did not answer. Scan again.' : 'Check your signal, then scan again.');
            if (!timedOut) showBanner('No signal. Scans are not being recorded.', true);
            report(timedOut ? 'timeout' : 'network', timedOut ? 'No reply within 8 seconds' : 'Request failed: ' + (error && error.message), { duration_ms: took });
            return;
        }
        window.clearTimeout(timer);
        const took = Math.round(performance.now() - started);
        let payload = null;
        try { payload = JSON.parse(raw); } catch (error) {}
        if (!payload) {
            lastScan = ''; heldCode = '';
            showOverlay(false, 'Scan Error', `Unexpected reply (HTTP ${response.status}). Scan again.`);
            report('bad_response', `Unreadable reply, HTTP ${response.status}`, { http_status: response.status, duration_ms: took, detail: raw.slice(0, 300) });
            return;
        }
        const ref = payload.ref ? ` (ref ${payload.ref})` : '';
        if (response.status === 401 || payload.code === 'not_logged_in') {
            blocked = true;
            showOverlay(false, 'Logged Out', 'Log in again to keep scanning.');
            showBanner('You have been logged out.', false, `<a href="/admin/login.php?next=${encodeURIComponent(location.pathname + location.search)}">Log in</a>`);
            return;
        }
        if (response.status === 419 || payload.code === 'session_expired') {
            blocked = true;
            showOverlay(false, 'Scanner Expired', 'Reload this page, then scan again.');
            showBanner('This scanner page has expired.', false, '<button type="button" onclick="location.reload()">Reload</button>');
            return;
        }
        if (payload.code === 'wrong_day') {
            showOverlay(false, 'Wrong Day', payload.message || 'This is not scheduled for today.');
            return;
        }
        if (response.status === 403 || payload.code === 'no_permission') {
            showOverlay(false, 'No Permission', 'This login cannot scan tickets.');
            return;
        }
        if (response.status >= 500 || payload.code === 'server_error') {
            lastScan = ''; heldCode = '';
            showOverlay(false, 'Server Error', (payload.message || 'Try again or use Manual entry.') + ref);
            return;
        }
        if (offlineSince === 0 && !(window.ScanOffline && ScanOffline.status().queued > 0)) hideBanner();
        flushReports();
        if (window.ScanOffline) ScanOffline.markOnline();
        finishPayload(payload, response.ok);
    };

    // A code that has just been answered is ignored until the camera has stopped seeing it, so a
    // QR left in view does not flash the result screen again and again.
    let heldCode = '';
    let heldAbsent = 0;
    const scanLoop = async () => {
        if (!scanning || !detector || !video) return;
        try {
            const codes = ((await detector.detect(video)) || []).map((c) => c.rawValue).filter(Boolean);
            if (heldCode) {
                if (codes.includes(heldCode)) { heldAbsent = 0; } else if (++heldAbsent >= 3) { heldCode = ''; }
            }
            const overlayHolding = overlay.classList.contains('is-visible') && Date.now() - overlayShownAt < 2000;
            const next = codes.find((c) => c !== heldCode);
            if (next && !overlayHolding) {
                heldCode = next;
                heldAbsent = 0;
                submitScan(next);
            }
        } catch (error) {}
        if (scanning) window.setTimeout(scanLoop, 450);
    };

    const stopCamera = () => {
        scanning = false;
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null;
        if (video) video.srcObject = null;
    };

    // Safari/iPhone has no BarcodeDetector: read camera frames with jsQR instead. Same shape as BarcodeDetector.detect().
    const frameCanvas = document.createElement('canvas');
    const makeJsQrDetector = () => ({
        detect: async (source) => {
            const w = source.videoWidth, h = source.videoHeight;
            if (!w || !h) return [];
            const scale = Math.min(1, 640 / Math.max(w, h));
            frameCanvas.width = Math.round(w * scale);
            frameCanvas.height = Math.round(h * scale);
            const ctx = frameCanvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(source, 0, 0, frameCanvas.width, frameCanvas.height);
            const img = ctx.getImageData(0, 0, frameCanvas.width, frameCanvas.height);
            const hit = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
            return hit && hit.data ? [{ rawValue: hit.data }] : [];
        },
    });

    const startCamera = async () => {
        closeSheets();
        if (!navigator.mediaDevices?.getUserMedia || (!('BarcodeDetector' in window) && typeof window.jsQR !== 'function')) {
            cameraMessage.innerHTML = '<div>Camera QR scanning is not supported here.<div class="small mt-2">Use Manual at the bottom.</div></div>';
            report('camera_unsupported', 'Camera or QR decoding is not available in this browser');
            return;
        }
        stopCamera();
        try {
            detector = 'BarcodeDetector' in window ? new BarcodeDetector({ formats: ['qr_code'] }) : makeJsQrDetector();
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            video.srcObject = stream;
            await video.play();
            scanning = true;
            cameraMessage.classList.add('scan-hidden');
            scanLoop();
        } catch (error) {
            cameraMessage.classList.remove('scan-hidden');
            cameraMessage.innerHTML = '<div>Camera could not be started.<div class="small mt-2">Check permissions or use Manual.</div></div>';
            report('camera_error', 'Camera could not start: ' + ((error && error.name) || 'unknown') + (error && error.message ? ' - ' + error.message : ''));
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
    document.querySelector('[data-scan-manual-open]')?.addEventListener('click', () => { if (toggleSheet(manualSheet, recentSheet)) { form?.elements.scan_value?.focus(); } });
    document.querySelector('[data-scan-recent-open]')?.addEventListener('click', () => { toggleSheet(recentSheet, manualSheet); });
    document.querySelectorAll('[data-scan-sheet-close]').forEach((b) => b.addEventListener('click', closeSheets));
    document.querySelector('[data-scan-camera-nav]')?.addEventListener('click', () => { closeSheets(); startCamera(); });
    document.querySelector('[data-scan-restart]')?.addEventListener('click', startCamera);
    window.addEventListener('beforeunload', stopCamera);
    // Offline engine: keeps a saved ticket list, checks scans on the phone with no signal, and sends them later.
    let lastShownSyncMessage = '';
    const navBar = document.querySelector('.scan-bottom-nav');
    const setNavHeight = () => { if (navBar) document.documentElement.style.setProperty('--scan-nav-h', navBar.offsetHeight + 'px'); };
    setNavHeight();
    window.addEventListener('resize', setNavHeight);
    window.addEventListener('orientationchange', setNavHeight);
    const fixtureLabel = document.querySelector('.scan-fixture-label');
    const setLabelOffline = (off) => fixtureLabel?.classList.toggle('is-offline', !!off);
    window.addEventListener('offline', () => setLabelOffline(true));
    window.addEventListener('online', () => setLabelOffline(false));
    setLabelOffline(navigator.onLine === false);
    const renderOfflineStatus = (st) => {
        setLabelOffline(!st.online);
        if (blocked) return;
        const at = st.savedAt ? new Date(st.savedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        const plural = (n) => `${n} scan${n === 1 ? '' : 's'}`;
        if (!st.online) {
            showBanner(st.listReady ? `OFFLINE: checking tickets from the list saved at ${at}. ${plural(st.queued)} waiting to send.` : 'No signal and no saved ticket list. Scans cannot be checked.', true);
        } else if (st.queued > 0) {
            showBanner(`${st.syncing ? 'Sending' : 'Waiting to send'} ${plural(st.queued)} saved while offline.`, true, '<button type="button" data-scan-sync>Send now</button>');
        } else if (st.message && st.message !== lastShownSyncMessage) {
            lastShownSyncMessage = st.message;
            showBanner(st.message, false);
            window.setTimeout(() => { if (offlineSince === 0 && ScanOffline.status().queued === 0) hideBanner(); }, 9000);
        } else if (offlineSince === 0 && !st.message) {
            hideBanner();
        }
    };
    bannerAction?.addEventListener('click', (event) => { if (event.target.closest('[data-scan-sync]') && window.ScanOffline) ScanOffline.sync(); });
    const gateNameEl = document.querySelector('[data-scan-gate-name]');
    const gateInput = document.querySelector('[data-scan-gate-input]');
    const updateGateChip = () => { if (gateNameEl && window.ScanOffline) gateNameEl.textContent = ScanOffline.getGate() || 'Set gate'; };
    document.querySelector('[data-scan-gate-open]')?.addEventListener('click', () => { if (gateInput && window.ScanOffline) gateInput.value = ScanOffline.getGate(); toggleSheet(gateSheet, null); });
    document.querySelectorAll('[data-scan-gate-preset]').forEach((b) => b.addEventListener('click', () => { if (gateInput) gateInput.value = b.dataset.scanGatePreset; }));
    document.querySelector('[data-scan-gate-save]')?.addEventListener('click', () => { if (window.ScanOffline && gateInput) { ScanOffline.setGate(gateInput.value.trim()); updateGateChip(); closeSheets(); } });
    if (window.ScanOffline) {
        ScanOffline.init({ kind: <?= json_encode($scanEvent ? 'event' : 'match') ?>, targetId: <?= (int) ($scanEvent ? $scanEvent['id'] : $fixture['id']) ?>, csrf, onStatus: renderOfflineStatus });
        updateGateChip();
    }
    startCamera();
})();
</script>
<?php endif; ?>
<?php if (!$scanning): ?>
<script src="/scan/offline.js?v=<?= (int) @filemtime(__DIR__ . '/offline.js') ?>"></script>
<script>
(() => {
    const closeMenus = (except) => document.querySelectorAll('[data-scan-menu]').forEach((m) => { if (m !== except) { m.classList.remove('is-open'); m.parentElement.querySelector('[data-scan-menu-btn]')?.setAttribute('aria-expanded', 'false'); } });
    document.querySelectorAll('[data-scan-menu-btn]').forEach((btn) => btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const m = btn.parentElement.querySelector('[data-scan-menu]');
        closeMenus(m);
        const open = m.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }));
    document.addEventListener('click', () => closeMenus(null));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenus(null); });
    const infoBtn = document.querySelector('[data-scan-info-btn]');
    const infoBox = document.querySelector('[data-scan-infobox]');
    infoBtn?.addEventListener('click', () => { infoBox.hidden = false; const open = infoBox.classList.toggle('is-open'); infoBox.hidden = !open; infoBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    <?php if ($primary): ?>
    const net = document.querySelector('[data-scan-net]');
    const ageEl = document.querySelector('[data-scan-sync-age]');
    const counts = document.querySelector('[data-scan-counts]');
    const set = (key, text) => { const el = counts?.querySelector('[data-c="' + key + '"]'); if (el) el.textContent = text; };
    let last = null;
    const age = (ms) => { if (!ms) return '–'; const m = Math.floor((Date.now() - ms) / 60000); return m < 1 ? 'Just now' : (m < 60 ? m + ' min ago' : Math.floor(m / 60) + ' h ago'); };
    const render = (st) => {
        last = st;
        const strip = document.querySelector('[data-scan-status]');
        if (strip) { strip.hidden = !!st.online; if (st.online) { infoBox.hidden = true; infoBox.classList.remove('is-open'); infoBtn?.setAttribute('aria-expanded', 'false'); } }
        if (net) { net.className = 'scan-pill ' + (st.online ? 'is-today' : (st.listReady ? 'is-wait' : 'is-bad')); net.textContent = st.online ? 'Online' : (st.listReady ? 'Offline · list saved' : 'Offline · no list'); }
        if (ageEl) ageEl.textContent = age(st.savedAt);
        const c = window.ScanOffline && ScanOffline.counts ? ScanOffline.counts() : null;
        if (c) {
            set('tickets', c.tickets.in + ' / ' + c.tickets.total);
            set('season', c.season.in + ' / ' + c.season.total);
            const serverIn = parseInt(counts.dataset.serverIn || '0', 10) || 0;
            const listIn = c.tickets.in + c.season.in;
            const totalIn = Math.max(serverIn, listIn);
            set('total', totalIn + ' / ' + Math.max(c.tickets.total + c.season.total, totalIn));
        }
    };
    let mine = 0;
    try { mine = parseInt(localStorage.getItem('ticket-scanner-mine-' + counts.dataset.target) || '0', 10) || 0; } catch (e) {}
    set('mine', String(mine));
    if (window.ScanOffline) {
        ScanOffline.init({ kind: <?= json_encode($primary['kind']) ?>, targetId: <?= (int) $primary['id'] ?>, csrf: document.querySelector('.scan-app').dataset.csrf, onStatus: render });
        setInterval(() => { if (last) render(ScanOffline.status()); }, 30000);
    }
    <?php endif; ?>
})();
</script>
<?php endif; ?>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/scan/sw.js', { scope: '/scan/', updateViaCache: 'none' }).catch(() => {});
    });
}
</script>
</body>
</html>
