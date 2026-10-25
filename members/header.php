<?php
declare(strict_types=1);
ob_start();

// Load the public site (config, db, helpers, SEO, partials) so members pages are
// rendered inside the same header/footer as the rest of the site.
require_once __DIR__ . '/../bootstrap.php';
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
$memberIsPublicPage = in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true);

// Read-only club info anyone can see without an account. These render with a
// slim public top bar (log in / create account) instead of the member portal
// chrome; the interactive parts on them (MOTM vote, venue review) stay gated
// to signed-in members.
$memberPublicContentPages = []; // the whole members area is now behind login
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
    $account = getAccountByLegacyHolderId($GLOBALS['pdo'], (int) ($holder['id'] ?? 0));
    $role = $account ? accountPrimaryRole($GLOBALS['pdo'], (int) $account['id']) : 'public';
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
    // Score Predictor is for season ticket holders only.
    'predictor.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
    'predictor_leaderboard.php' => MEMBER_TIER_SEASON_TICKET_HOLDER,
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
$memberCurrentRoute = $currentScript;

// Members area access: admins, club staff and season ticket holders only. Any other
// signed-in account sees a "buy a season ticket" page instead of the content.
if ($currentHolder && !$memberIsPublicPage && $memberTier < MEMBER_TIER_SEASON_TICKET_HOLDER) {
    require_once __DIR__ . '/_shell.php';
    member_shell_locked();
}
// Everything the page prints from here on is captured; footer.php wraps it in the
// public site chrome (see members/_shell.php).
ob_start();
