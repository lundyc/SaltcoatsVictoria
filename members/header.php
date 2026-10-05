<?php
declare(strict_types=1);
ob_start();

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/auth.php';

// Capture the staff identity (if any) from the default/staff session *before*
// member_auth.php switches $_SESSION over to the isolated member cookie —
// the two sessions are deliberately separate, so this is the only point
// where both can be read in the same request.
hub_auth_start_session();
$staffUserForAutoLogin = hub_auth_is_authenticated() ? hub_auth_current_user() : null;

require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/season.php';
require_once __DIR__ . '/../admin/lib/season_tickets.php';
ensureSeasonTicketSchema($pdo);

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$memberIsPublicPage = in_array($currentScript, ['login.php', 'register.php', 'forgot_password.php', 'reset_password.php'], true);

// Read-only club info anyone can see without an account. These render with a
// slim public top bar (log in / create account) instead of the member portal
// chrome; the interactive parts on them (MOTM vote, venue review) stay gated
// to signed-in members.
$memberPublicContentPages = ['matches.php', 'match.php', 'table.php'];
$memberIsPublicContentPage = in_array($currentScript, $memberPublicContentPages, true);

if (!member_auth_is_authenticated() && $staffUserForAutoLogin !== null) {
    member_auth_login_as_staff($pdo, $staffUserForAutoLogin);
}

if (!member_auth_is_authenticated() && !$memberIsPublicPage && !$memberIsPublicContentPage) {
    header('Location: login.php');
    exit;
}

$currentHolder = member_auth_current_holder();
$memberHasSeasonTicket = false;
if ($currentHolder) {
    foreach (getSeasonTicketOrders($pdo, ['holder_id' => (int) $currentHolder['id']]) as $memberOrder) {
        if ((int) ($memberOrder['paid'] ?? 0) === 1 && (string) ($memberOrder['status'] ?? '') === 'complete') {
            $memberHasSeasonTicket = true;
            break;
        }
    }
}

// Access ladder for the member portal: each tier sees everything the ones
// below it see. "Season ticket holder" stays a computed fact (the order
// check above), never stored on the account — only "staff"/"admin" are
// real account roles. Higher tiers (staff/admin) get season-ticket-holder
// content too regardless of whether they personally hold a ticket.
const MEMBER_TIER_PUBLIC = 0;
const MEMBER_TIER_SEASON_TICKET_HOLDER = 1;
const MEMBER_TIER_STAFF = 2;
const MEMBER_TIER_ADMIN = 3;

function member_current_tier(?array $holder, bool $hasSeasonTicket): int
{
    if (!$holder) {
        return MEMBER_TIER_PUBLIC;
    }
    $role = (string) ($holder['role'] ?? 'public');
    if ($role === 'admin') {
        return MEMBER_TIER_ADMIN;
    }
    if ($role === 'staff') {
        return MEMBER_TIER_STAFF;
    }
    return $hasSeasonTicket ? MEMBER_TIER_SEASON_TICKET_HOLDER : MEMBER_TIER_PUBLIC;
}

$memberTier = member_current_tier($currentHolder, $memberHasSeasonTicket);
$memberHasSeasonTicket = $memberTier >= MEMBER_TIER_SEASON_TICKET_HOLDER;

// Per-page minimum tier. matches.php/match.php/table.php/orders.php/
// ticket.php/profile.php/index.php aren't listed — matches, results and the
// league table are public (see $memberPublicContentPages above), and the
// rest stay open to any registered account since "see the games" is baseline
// access for anyone who's signed up, not just season ticket holders.
$memberTierRequirements = [
    'announcements.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
    'sponsor.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
    'player.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
    // Previously nav-hidden only, with no actual server-side check — a
    // logged-in account without a season ticket could reach it directly
    // by URL. Now enforced like the rest of the season-ticket-only pages.
    'hidden_team.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
];
if ($currentHolder && isset($memberTierRequirements[$currentScript]) && $memberTier < $memberTierRequirements[$currentScript]) {
    header('Location: /members/index.php');
    exit;
}

// Taken offline for members until there's a real way to pay a winner their
// prize — flip back to false (and restore the nav link below) once that's
// sorted. Blocks direct URL access too, not just the nav link.
const HIDDEN_TEAM_TEMPORARILY_DISABLED = true;
if (HIDDEN_TEAM_TEMPORARILY_DISABLED && $currentScript === 'hidden_team.php') {
    header('Location: /members/index.php');
    exit;
}

function member_active(string $page): string
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $page ? 'active' : '';
}

function member_active_group(array $pages): string
{
    return in_array(basename($_SERVER['PHP_SELF'] ?? ''), $pages, true) ? 'active' : '';
}

function member_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'SV';
}

function member_format_date(?string $value, string $fallback = 'TBC'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('D j M Y', $timestamp) : $value;
}

function member_format_time(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('H:i', $timestamp) : $value;
}

function member_holder_address(array $holder): string
{
    $parts = [
        trim((string) ($holder['address_line1'] ?? '')),
        trim((string) ($holder['address_line2'] ?? '')),
        trim((string) ($holder['town'] ?? '')),
        trim((string) ($holder['country'] ?? '')),
        trim((string) ($holder['postcode'] ?? '')),
    ];
    $structured = trim(implode(', ', array_filter($parts, static fn(string $part): bool => $part !== '')));
    return $structured !== '' ? $structured : trim((string) ($holder['address'] ?? ''));
}

// True when an anonymous visitor is on one of the public content pages:
// render the slim public top bar instead of the member portal nav.
$memberShowPublicChrome = $memberIsPublicContentPage && !$currentHolder;

$memberPageTitle = [
    'matches.php' => 'Fixtures & Results',
    'match.php' => 'Match Centre',
    'table.php' => 'League Table',
][$currentScript] ?? 'Members';
$memberPageDescription = [
    'matches.php' => 'Upcoming fixtures and latest results for Saltcoats Victoria FC.',
    'match.php' => 'Match details, line-ups, events and head-to-head record for Saltcoats Victoria FC.',
    'table.php' => 'The current league table for Saltcoats Victoria FC.',
][$currentScript] ?? '';
$memberCanonicalUrl = '';
if ($memberIsPublicContentPage) {
    $memberScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $memberHost = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'saltcoatsvictoria.co.uk'));
    $memberPath = '/members/' . $currentScript;
    if ($currentScript === 'match.php' && (int) ($_GET['id'] ?? 0) > 0) {
        $memberPath .= '?id=' . (int) $_GET['id'];
    }
    $memberCanonicalUrl = $memberScheme . '://' . $memberHost . $memberPath;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#4b0818">
    <?php if (!$memberIsPublicContentPage): ?>
    <meta name="robots" content="noindex">
    <?php elseif ($memberCanonicalUrl !== ''): ?>
    <link rel="canonical" href="<?= h($memberCanonicalUrl) ?>">
    <?php if ($memberPageDescription !== ''): ?>
    <meta name="description" content="<?= h($memberPageDescription) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Saltcoats Victoria FC">
    <meta property="og:title" content="<?= h($memberPageTitle) ?> &middot; Saltcoats Victoria FC">
    <meta property="og:description" content="<?= h($memberPageDescription) ?>">
    <meta property="og:url" content="<?= h($memberCanonicalUrl) ?>">
    <meta property="og:image" content="<?= h($memberScheme . '://' . $memberHost) ?>/Saltcoats%20Victoria%20FC%20-White_Transparent.png">
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <title><?= h($memberPageTitle) ?> &middot; Saltcoats Victoria FC</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css?v=<?= (int) (@filemtime(__DIR__ . '/../admin/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <script src="/admin/assets/js/app.js?v=<?= (int) (@filemtime(__DIR__ . '/../admin/assets/js/app.js') ?: time()) ?>" defer></script>
    <style>
        .member-page { --member-maroon:#4b0818; --member-gold:#e0b42a; --member-ink:#21141a; --member-muted:#6f6470; --member-line:#eadfdf; --member-panel:#fff; color:var(--member-ink); }
        .member-hero { position:relative; overflow:hidden; border-radius:18px; padding:clamp(1.25rem,3vw,2.25rem); margin-bottom:1.25rem; color:#fff; background:linear-gradient(135deg,#4b0818 0%,#7a1730 58%,#a6791d 100%); box-shadow:0 20px 45px rgba(75,8,24,.2); }
        .member-hero:after { content:""; position:absolute; inset:auto -12% -45% auto; width:42%; aspect-ratio:1; border-radius:50%; background:rgba(255,255,255,.12); }
        .member-hero__content { position:relative; z-index:1; max-width:760px; }
        .member-hero__eyebrow { font-size:.74rem; letter-spacing:.14em; text-transform:uppercase; opacity:.76; font-weight:800; margin-bottom:.45rem; }
        .member-hero h1 { margin:0; color:#fff; font-weight:850; letter-spacing:0; font-size:clamp(1.85rem,4vw,3.25rem); }
        .member-hero p { max-width:680px; margin:.65rem 0 0; color:rgba(255,255,255,.82); font-size:1rem; }
        .member-hero__actions { display:flex; flex-wrap:wrap; gap:.65rem; margin-top:1rem; }
        .member-grid { display:grid; gap:1rem; }
        .member-grid--2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .member-grid--3 { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .member-grid--aside { grid-template-columns:minmax(0,1fr) 340px; align-items:start; }
        .member-card { background:var(--member-panel); border:1px solid var(--member-line); border-radius:14px; box-shadow:0 10px 28px rgba(33,20,26,.06); overflow:hidden; }
        .member-card__body { padding:1.1rem; }
        .member-card__header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.1rem; border-bottom:1px solid var(--member-line); }
        .member-card__header h2, .member-card__body h2 { margin:0; color:var(--member-maroon); font-weight:800; font-size:1.05rem; }
        .member-stat { padding:1rem; border:1px solid var(--member-line); border-radius:14px; background:#fff; }
        .member-stat span { display:block; color:var(--member-muted); font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; }
        .member-stat strong { display:block; color:var(--member-maroon); font-size:1.6rem; line-height:1.1; margin-top:.2rem; }
        .member-list { display:grid; gap:.7rem; }
        .member-row { display:flex; justify-content:space-between; align-items:center; gap:1rem; border:1px solid var(--member-line); border-radius:12px; padding:.85rem; background:#fff; text-decoration:none; color:inherit; }
        .member-row:hover { border-color:rgba(75,8,24,.28); box-shadow:0 8px 22px rgba(75,8,24,.08); color:inherit; }
        .member-row__title { font-weight:800; color:var(--member-ink); }
        .member-row__meta { color:var(--member-muted); font-size:.9rem; margin-top:.15rem; }
        .member-avatar { width:74px; height:74px; border-radius:999px; display:grid; place-items:center; overflow:hidden; background:linear-gradient(135deg,#4b0818,#e0b42a); color:#fff; font-weight:900; font-size:1.35rem; }
        .member-avatar img { width:100%; height:100%; object-fit:cover; }
        .member-ticket-card { border-radius:18px; overflow:hidden; border:0; box-shadow:0 18px 50px rgba(75,8,24,.16); background:#fff; }
        .member-ticket-card__top { background:linear-gradient(135deg,#4b0818,#74172f); color:#fff; padding:1.2rem; }
        .member-badge { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.34rem .62rem; background:#f7f2e6; color:#5a3f00; font-weight:800; font-size:.78rem; }
        .member-tabs { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; }
        .member-tab { border:1px solid var(--member-line); background:#fff; color:var(--member-ink); border-radius:999px; padding:.5rem .85rem; font-weight:800; text-decoration:none; }
        .member-tab.is-active { background:var(--member-maroon); border-color:var(--member-maroon); color:#fff; }
        .member-shop-layout { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:1rem; align-items:start; }
        .member-cart { position:sticky; top:1rem; }
        .member-product { display:flex; justify-content:space-between; gap:1rem; align-items:center; border:1px solid var(--member-line); border-radius:14px; padding:1rem; background:#fff; }
        .member-product h3 { margin:0; color:var(--member-maroon); font-size:1rem; font-weight:850; }
        .member-product p { margin:.2rem 0 0; color:var(--member-muted); font-size:.9rem; }
        .member-row__actions { display:flex; align-items:center; justify-content:flex-end; gap:.55rem; flex-wrap:wrap; }
        .member-app-tabs { position:fixed; left:.75rem; right:.75rem; bottom:.75rem; z-index:1040; display:none; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.2rem; padding:.45rem; border:1px solid rgba(255,255,255,.16); border-radius:1.25rem; background:rgba(33,42,49,.96); box-shadow:0 14px 36px rgba(0,0,0,.24); backdrop-filter:blur(16px); }
        .member-app-tab { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.18rem; min-height:3.15rem; border-radius:.95rem; color:rgba(255,255,255,.7); text-decoration:none; font-size:.68rem; font-weight:800; }
        .member-app-tab i { font-size:1rem; }
        .member-app-tab.active { background:#4b0818; color:#e0b42a; }
        body.hub-shell #hubSideNavigation { align-items:stretch; justify-content:flex-start !important; }
        body.hub-shell #hubSideNavigation > .member-navbar-container { display:flex; flex-direction:column; justify-content:flex-start !important; align-items:stretch !important; align-content:flex-start !important; min-height:0 !important; height:auto !important; gap:.75rem; }
        body.hub-shell #hubSideNavigation .member-navbar-main { flex-direction:column; align-items:stretch; justify-content:flex-start; width:100%; flex:0 0 auto !important; min-height:0; margin-top:0; }
        body.hub-shell #hubSideNavigation .member-navbar-main .nav-sections { align-items:stretch; justify-content:flex-start; flex:0 0 auto; }
        @media (max-width: 1199.98px) {
            body.hub-shell #hubSideNavigation > .member-navbar-container { flex-direction:row; flex-wrap:wrap; align-items:center !important; gap:.65rem; }
            body.hub-shell #hubSideNavigation .navbar-brand { flex:1 1 auto; width:auto; margin:0; justify-content:flex-start; }
            body.hub-shell #hubSideNavigation .navbar-toggler { display:inline-flex; align-items:center; justify-content:center; }
            body.hub-shell #hubSideNavigation .member-navbar-main { flex-basis:100%; margin-top:.35rem; padding:.9rem; border-radius:1rem; background:rgba(33,42,49,.96); }
            body.hub-shell #hubSideNavigation .member-navbar-main.collapse:not(.show) { display:none !important; }
            body.hub-shell #hubSideNavigation .member-navbar-main.collapse.show,
            body.hub-shell #hubSideNavigation .member-navbar-main.collapsing { display:flex !important; }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-link {
                background:rgba(255,255,255,.045) !important;
                border-color:rgba(255,255,255,.08) !important;
                color:#f7efe4 !important;
            }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-link:hover,
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-link:focus {
                background:rgba(255,255,255,.075) !important;
                color:#f7efe4 !important;
            }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-link.active {
                background:rgba(255,255,255,.11) !important;
                color:#e0b42a !important;
            }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-section-label { color:rgba(255,255,255,.66); }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-account__identity { color:#fff; }
            body.hub-shell #hubSideNavigation .member-navbar-main .nav-account__identity small { color:rgba(255,255,255,.62); }
        }
        @media (max-width: 1199.98px) {
            body.hub-shell { padding-bottom:5.6rem; }
            .member-app-tabs { display:grid; }
        }
        @media (min-width: 1200px) {
            body.hub-shell #hubSideNavigation .member-navbar-main { display:flex !important; }
        }
        @media (max-width: 991.98px) { .member-grid--2,.member-grid--3,.member-grid--aside,.member-shop-layout { grid-template-columns:1fr; } .member-cart { position:static; } }
        @media (max-width: 575.98px) { .member-row,.member-product { align-items:flex-start; flex-direction:column; } .member-row > .text-end { text-align:left !important; } }

        /* Slim public chrome for anonymous visitors on the read-only pages */
        .member-public-topbar { display:flex; flex-wrap:wrap; align-items:center; gap:.75rem 1.25rem; padding:.85rem clamp(1rem,4vw,2rem); background:linear-gradient(135deg,#4b0818,#7a1730 70%); color:#fff; }
        .member-public-topbar__brand { display:inline-flex; align-items:center; gap:.6rem; color:#fff; text-decoration:none; font-weight:800; letter-spacing:.01em; }
        .member-public-topbar__brand img { height:34px; width:auto; }
        .member-public-topbar__nav { display:flex; flex-wrap:wrap; gap:.35rem; margin-left:.25rem; }
        .member-public-topbar__nav a { color:rgba(255,255,255,.82); text-decoration:none; font-weight:700; font-size:.92rem; padding:.35rem .7rem; border-radius:999px; }
        .member-public-topbar__nav a:hover, .member-public-topbar__nav a:focus-visible { background:rgba(255,255,255,.12); color:#fff; }
        .member-public-topbar__nav a.active { background:rgba(255,255,255,.16); color:#fff; }
        .member-public-topbar__cta { display:flex; gap:.5rem; margin-left:auto; }
        .member-public-topbar__cta .btn-outline-light { --bs-btn-color:#fff; --bs-btn-border-color:rgba(255,255,255,.6); --bs-btn-hover-bg:rgba(255,255,255,.14); --bs-btn-hover-border-color:#fff; --bs-btn-hover-color:#fff; }
        .member-public-topbar__cta .btn-join { background:#e0b42a; border:1px solid #e0b42a; color:#3a2600; font-weight:800; }
        .member-public-topbar__cta .btn-join:hover, .member-public-topbar__cta .btn-join:focus-visible { background:#efc75a; border-color:#efc75a; color:#3a2600; }
        .member-public-main { padding:clamp(1.25rem,4vw,2.5rem) clamp(1rem,4vw,2rem) 4rem; }
        .member-public-main__inner { max-width:1080px; margin:0 auto; }
        .member-public-footer { border-top:1px solid var(--member-line,#eadfdf); padding:1.5rem; text-align:center; color:var(--member-muted,#6f6470); font-size:.92rem; }
        .member-public-footer a { color:var(--member-maroon,#4b0818); font-weight:700; }
        @media (max-width: 575.98px) { .member-public-topbar__cta { margin-left:0; width:100%; } .member-public-topbar__cta .btn { flex:1; } }
    </style>
</head>
<body<?= $currentHolder ? ' class="hub-shell"' : ($memberShowPublicChrome ? ' class="member-page"' : '') ?>>
<?php if ($currentHolder): ?>
    <a class="hub-skip-link" href="#hubMemberMainContent">Skip to main content</a>
    <nav class="navbar border-bottom shadow-sm navbar-dark" id="hubSideNavigation">
        <div class="container-fluid member-navbar-container">
            <a class="navbar-brand fw-bold text-brand" href="/members/index.php">
                <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" class="navbar-brand__logo" loading="eager">
                <span class="navbar-brand__text">MY VICS</span>
            </a>

            <button class="navbar-toggler collapsed d-xl-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
                    aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="custom-toggler-icon"><span></span><span></span><span></span></span>
            </button>

            <div class="collapse member-navbar-main" id="navbarMain">
                <div class="nav-sections d-flex flex-column w-100">
                    <section class="nav-section">
                        <div class="nav-section-label">Overview</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= member_active('index.php') ?>" href="/members/index.php"><i class="fa-solid fa-house me-1" aria-hidden="true"></i>Account</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Club</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= member_active_group(['matches.php', 'match.php']) ?>" href="/members/matches.php"><i class="fa-solid fa-futbol me-1" aria-hidden="true"></i>Matches</a></li>
                            <li class="nav-item"><a class="nav-link <?= member_active('orders.php') ?>" href="/members/orders.php"><i class="fa-solid fa-ticket me-1" aria-hidden="true"></i>Match Tickets</a></li>
                            <li class="nav-item"><a class="nav-link <?= member_active('table.php') ?>" href="/members/table.php"><i class="fa-solid fa-table me-1" aria-hidden="true"></i>League Table</a></li>
                            <?php if ($memberHasSeasonTicket): ?>
                                <li class="nav-item"><a class="nav-link <?= member_active('announcements.php') ?>" href="/members/announcements.php"><i class="fa-solid fa-bullhorn me-1" aria-hidden="true"></i>Announcements</a></li>
                                <li class="nav-item"><a class="nav-link <?= member_active_group(['sponsor.php', 'player.php']) ?>" href="/members/sponsor.php"><i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Sponsor</a></li>
                            <?php if (!HIDDEN_TEAM_TEMPORARILY_DISABLED): ?>
                                <li class="nav-item"><a class="nav-link <?= member_active('hidden_team.php') ?>" href="/members/hidden_team.php"><i class="fa-solid fa-futbol me-1" aria-hidden="true"></i>Hidden Team</a></li>
                            <?php endif; ?>
                            <?php endif; ?>
                        </ul>
                    </section>

                    <section class="nav-section nav-account">
                        <div class="nav-account__identity"><i class="fa-solid fa-circle-user" aria-hidden="true"></i><span><strong><?= h((string) $currentHolder['name']) ?></strong><small>Season ticket holder</small></span></div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= member_active('ticket.php') ?>" href="/members/ticket.php"><i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>Digital Ticket</a></li>
                            <li class="nav-item"><a class="nav-link <?= member_active('profile.php') ?>" href="/members/profile.php"><i class="fa-solid fa-user-gear me-1" aria-hidden="true"></i>Profile</a></li>
                            <li class="nav-item"><a class="nav-link" href="/members/logout.php?all=1"><i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>Log out</a></li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </nav>

    <nav class="member-app-tabs" aria-label="Member quick navigation">
        <a class="member-app-tab <?= member_active('index.php') ?>" href="/members/index.php"><i class="fa-solid fa-house" aria-hidden="true"></i><span>Home</span></a>
        <a class="member-app-tab <?= member_active_group(['matches.php', 'match.php']) ?>" href="/members/matches.php"><i class="fa-solid fa-futbol" aria-hidden="true"></i><span>Matches</span></a>
        <a class="member-app-tab <?= member_active_group(['orders.php', 'ticket.php']) ?>" href="/members/orders.php"><i class="fa-solid fa-ticket" aria-hidden="true"></i><span>Tickets</span></a>
        <a class="member-app-tab <?= member_active('profile.php') ?>" href="/members/profile.php"><i class="fa-solid fa-user" aria-hidden="true"></i><span>Profile</span></a>
    </nav>

    <main class="hub-main" id="hubMemberMainContent" tabindex="-1">
        <div class="container-fluid" style="padding:1.5rem;">
<?php elseif ($memberShowPublicChrome): ?>
    <a class="hub-skip-link" href="#hubMemberMainContent">Skip to main content</a>
    <header class="member-public-topbar">
        <a class="member-public-topbar__brand" href="/members/matches.php">
            <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" loading="eager">
            <span>Saltcoats Victoria FC</span>
        </a>
        <nav class="member-public-topbar__nav" aria-label="Sections">
            <a class="<?= member_active_group(['matches.php', 'match.php']) ?>" href="/members/matches.php">Fixtures &amp; Results</a>
            <a class="<?= member_active('table.php') ?>" href="/members/table.php">League Table</a>
        </nav>
        <div class="member-public-topbar__cta">
            <a class="btn btn-sm btn-outline-light" href="/members/login.php">Log in</a>
            <a class="btn btn-sm btn-join" href="/members/register.php">Create account</a>
        </div>
    </header>
    <main class="member-public-main" id="hubMemberMainContent" tabindex="-1">
        <div class="member-public-main__inner">
<?php else: ?>
    <div style="padding:24px 16px 64px;">
<?php endif; ?>
