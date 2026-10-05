<?php
declare(strict_types=1);

// Unified accounts module — replaces auth.php (staff) and member_auth.php
// (members), both of which are being retired now that staff and members
// are the same underlying accounts, distinguished only by
// season_ticket_holders.role (public / staff / admin). See
// /root/.claude/plans/velvet-exploring-cook.md for the full design.
//
// Every function name and return shape from the two old files is preserved
// exactly, so the ~19 admin-gated pages, ~59 authenticated-only pages,
// social_auth.php's existing bridge, lib/pos.php's role check, and every
// member-portal page can be cut over without touching their own code.
//
// The two sessions stay deliberately separate — staff ride PHP's default
// session (path '/'), members use an isolated 'member_session' cookie
// (path '/') — only the backing table and password logic are unified,
// not the session mechanism. This keeps social_auth.php and lib/pos.php
// (which piggyback on the default session) unaffected by this merge.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/positions.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/access_roles.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/season.php';

const ACCOUNT_ROLE_PUBLIC = 'public';
const ACCOUNT_ROLE_VOLUNTEER = 'volunteer';
const ACCOUNT_ROLE_STAFF = 'staff';
const ACCOUNT_ROLE_ADMIN = 'admin';
const ACCOUNT_ROLES = [ACCOUNT_ROLE_PUBLIC, ACCOUNT_ROLE_VOLUNTEER, ACCOUNT_ROLE_STAFF, ACCOUNT_ROLE_ADMIN];
const ACCOUNT_STAFF_ROLES = [ACCOUNT_ROLE_VOLUNTEER, ACCOUNT_ROLE_STAFF, ACCOUNT_ROLE_ADMIN];

/**
 * The only code path allowed to change an account's role — deliberately not
 * reachable from saveSeasonTicketHolder(), so legacy ticketing compatibility
 * writes can never change a member's account role.
 */
function account_auth_set_role(PDO $pdo, int $accountId, string $role): void
{
    if (!in_array($role, ACCOUNT_ROLES, true)) {
        throw new InvalidArgumentException("Invalid role: {$role}");
    }
    $account = getAccountByLegacyHolderId($pdo, $accountId) ?: getAccount($pdo, $accountId);
    if ($account) {
        setAccountRole($pdo, (int) $account['id'], $role);
    }
    $pdo->prepare('UPDATE season_ticket_holders SET role = :role WHERE id = :id')
        ->execute([':role' => $role, ':id' => $accountId]);
}

/**
 * The only code path allowed to change an account's committee position —
 * mirrors account_auth_set_role() above. $positionId is nullable (an
 * account can be staff/volunteer with no position assigned yet).
 */
function account_auth_set_position(PDO $pdo, int $accountId, ?int $positionId): void
{
    $person = getPersonByLegacyHolderId($pdo, $accountId);
    if ($person) {
        $pdo->prepare('DELETE FROM person_club_roles WHERE person_id = :person_id')->execute([':person_id' => (int) $person['id']]);
        if ($positionId !== null) {
            $pdo->prepare('INSERT IGNORE INTO person_club_roles (person_id, club_role_id) VALUES (:person_id, :position_id)')
                ->execute([':person_id' => (int) $person['id'], ':position_id' => $positionId]);
        }
    }
    $pdo->prepare('UPDATE season_ticket_holders SET position_id = :position_id WHERE id = :id')
        ->execute([':position_id' => $positionId, ':id' => $accountId]);
}

function account_auth_role(array $account): string
{
    $role = (string) ($account['role'] ?? ACCOUNT_ROLE_PUBLIC);
    return in_array($role, ACCOUNT_ROLES, true) ? $role : ACCOUNT_ROLE_PUBLIC;
}

// ---------------------------------------------------------------------
// Staff-facing (hub_auth_*) — default PHP session, unchanged from auth.php
// ---------------------------------------------------------------------

const HUB_AUTH_USER_ID_KEY = 'hub_user_id';
const HUB_AUTH_ACCOUNT_ID_KEY = 'hub_account_id';
const HUB_AUTH_PERSON_ID_KEY = 'hub_person_id';
const HUB_AUTH_CSRF_KEY = 'hub_csrf_token';
const HUB_AUTH_COOKIE_LIFETIME = 2592000;
// A hash of the password hash in force when this session was issued. Bound
// into the session at login and re-checked on every request in
// hub_auth_current_user(), so a session survives only as long as the
// password it was created under — a reset/change revokes every other
// session for that account without needing a server-side session store.
// Mirrors the credential binding already used by admin/lib/mobile_api/Auth.php.
const HUB_AUTH_CREDENTIAL_KEY = 'hub_credential_hash';

function hub_auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => HUB_AUTH_COOKIE_LIFETIME,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function hub_auth_csrf_token(): string
{
    hub_auth_start_session();

    $token = is_string($_SESSION[HUB_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[HUB_AUTH_CSRF_KEY] : '';
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION[HUB_AUTH_CSRF_KEY] = $token;
    }

    return $token;
}

function hub_auth_verify_csrf_token(?string $token): bool
{
    hub_auth_start_session();
    $stored = is_string($_SESSION[HUB_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[HUB_AUTH_CSRF_KEY] : '';
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

/**
 * Historical first-run gate for hub_auth_bootstrap_admin() — deliberately
 * left pointed at the frozen `users` table rather than the merged accounts
 * table. It only ever needs to answer "has anyone ever been provisioned",
 * and `users` already permanently satisfies that (non-empty, frozen, never
 * dropped), so bootstrap stays correctly and permanently disabled without
 * needing to touch this. Not read anywhere else.
 */
function hub_auth_user_count(): int
{
    global $pdo;
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function hub_auth_current_user(): ?array
{
    global $pdo;
    hub_auth_start_session();

    $newAccountId = (int) ($_SESSION[HUB_AUTH_ACCOUNT_ID_KEY] ?? 0);
    $legacyHolderId = (int) ($_SESSION[HUB_AUTH_USER_ID_KEY] ?? 0);
    $account = $newAccountId > 0 ? getAccount($pdo, $newAccountId) : null;
    if (!$account && $legacyHolderId > 0) {
        $account = getAccountByLegacyHolderId($pdo, $legacyHolderId);
    }

    if ($account) {
        $sessionCredential = (string) ($_SESSION[HUB_AUTH_CREDENTIAL_KEY] ?? '');
        $currentCredential = hash('sha256', (string) ($account['password_hash'] ?? ''));
        if ($sessionCredential === '' || !hash_equals($currentCredential, $sessionCredential)) {
            // Password changed (or reset) since this session was issued, or
            // this session predates credential binding — treat it as
            // revoked rather than silently trusting a stale identity.
            unset($_SESSION[HUB_AUTH_ACCOUNT_ID_KEY], $_SESSION[HUB_AUTH_USER_ID_KEY], $_SESSION[HUB_AUTH_PERSON_ID_KEY], $_SESSION[HUB_AUTH_CREDENTIAL_KEY]);
            return null;
        }
        $role = accountPrimaryRole($pdo, (int) $account['id']);
        if (!in_array($role, ACCOUNT_STAFF_ROLES, true) || (int) ($account['is_active'] ?? 0) !== 1 || (int) ($account['person_is_active'] ?? 0) !== 1) {
            return null;
        }
        $holderId = (int) ($account['old_holder_id'] ?? 0);
        $holder = $holderId > 0 ? getSeasonTicketHolder($pdo, $holderId) : null;
        return [
            'id' => $holderId,
            'account_id' => (int) $account['id'],
            'person_id' => (int) $account['person_id'],
            'legacy_holder_id' => $holderId,
            'username' => (string) ($holder['username'] ?? $account['email'] ?? ''),
            'email' => (string) ($account['email'] ?? ''),
            'display_name' => (string) ($account['display_name'] ?? ''),
            'role' => $role,
            'position_id' => (int) ($holder['position_id'] ?? 0),
            'is_active' => (int) ($account['is_active'] ?? 0),
            'last_login_at' => (string) ($account['last_login_at'] ?? ''),
        ];
    }

    if ($legacyHolderId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, username, email, name AS display_name, role, position_id, is_active, last_login_at, password_hash
        FROM season_ticket_holders
        WHERE id = :id AND role IN ('volunteer','staff','admin')
        LIMIT 1
    ");
    $stmt->execute([':id' => $legacyHolderId]);
    $user = $stmt->fetch();

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        return null;
    }

    $sessionCredential = (string) ($_SESSION[HUB_AUTH_CREDENTIAL_KEY] ?? '');
    $currentCredential = hash('sha256', (string) ($user['password_hash'] ?? ''));
    if ($sessionCredential === '' || !hash_equals($currentCredential, $sessionCredential)) {
        unset($_SESSION[HUB_AUTH_ACCOUNT_ID_KEY], $_SESSION[HUB_AUTH_USER_ID_KEY], $_SESSION[HUB_AUTH_PERSON_ID_KEY], $_SESSION[HUB_AUTH_CREDENTIAL_KEY]);
        return null;
    }
    unset($user['password_hash']);

    return $user;
}

function hub_auth_is_authenticated(): bool
{
    return hub_auth_current_user() !== null;
}

function hub_auth_is_admin(): bool
{
    // Admin is the Administrator access template (lib/access.php), not an
    // account flag.
    return access_is_admin();
}

/**
 * True only for the configured developer (HUB_DEVELOPER_EMAIL), not every
 * admin — gates raw-internals tools (DB inspector, error log, audit log,
 * sponsorships history) that are narrower than general club-admin access.
 * Empty/unmatched DEVELOPER_EMAIL denies everyone, same fail-closed shape as
 * developer_roles.php's own (separately maintained) identical lookup.
 */
function hub_auth_is_developer(): bool
{
    global $pdo;
    if (!defined('DEVELOPER_EMAIL') || DEVELOPER_EMAIL === '') {
        return false;
    }
    static $developerHolderId = null;
    if ($developerHolderId === null) {
        $stmt = $pdo->prepare("SELECT id FROM season_ticket_holders WHERE email_normalized = :email AND role IN ('staff','admin') LIMIT 1");
        $stmt->execute([':email' => seasonTicketNormalizeEmail(DEVELOPER_EMAIL)]);
        $developerHolderId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($developerHolderId <= 0) {
        return false;
    }
    $user = hub_auth_current_user();
    return $user !== null && (int) ($user['id'] ?? 0) === $developerHolderId;
}

/**
 * May the current user assign sponsors to players / move the sponsorship
 * deadline? Player sponsorships feed printed graphics, so this is a deliberate
 * grant (the sponsorship_assign capability), not something every area holder
 * gets. Administrators pass like every other capability.
 */
function hub_auth_is_sponsorship_editor(): bool
{
    // Now the sponsorship_assign capability (lib/access.php), grantable to a
    // template or a person; it was a single configured email address before.
    return access_can('sponsorship_assign');
}

/**
 * The current staff/volunteer user's committee position row, or null if
 * they're admin (positions don't apply — admin already sees everything) or
 * have no position assigned.
 */
function hub_auth_current_position(): ?array
{
    global $pdo;
    $user = hub_auth_current_user();
    $positionId = (int) ($user['position_id'] ?? 0);
    if ($positionId <= 0) {
        return null;
    }
    return getHubPosition($pdo, $positionId);
}

/**
 * Every committee position currently assigned to the logged-in user (not
 * just the single legacy "primary" one that hub_auth_current_position()
 * reads) — capability checks are the union across all of these, so someone
 * holding two positions gets both positions' capabilities, not whichever one
 * happened to be synced to the legacy season_ticket_holders.position_id
 * column. Resolves person_id straight off the accounts-based session, or via
 * the legacy holder id for sessions still authenticated through the old
 * season_ticket_holders fallback path in hub_auth_current_user().
 *
 * @return list<array<string, mixed>>
 */
function hub_auth_current_positions(): array
{
    global $pdo;
    $user = hub_auth_current_user();
    if ($user === null) {
        return [];
    }
    $personId = (int) ($user['person_id'] ?? 0);
    if ($personId <= 0) {
        $personId = (int) (personIdFromLegacyHolderId($pdo, (int) ($user['id'] ?? 0)) ?? 0);
    }
    if ($personId <= 0) {
        return [];
    }
    return getCurrentPersonPositions($pdo, $personId);
}

/**
 * The current staff/volunteer user's assigned access roles (the
 * WordPress-style Treasurer/Football Ops/... roles, distinct from the
 * legacy committee positions in hub_auth_current_positions()) — empty for
 * admin (bypass_all already covers it) or anyone with no role assigned.
 *
 * @return list<array<string, mixed>>
 */
function hub_auth_current_access_roles(): array
{
    global $pdo;
    $user = hub_auth_current_user();
    if ($user === null) {
        return [];
    }
    $personId = (int) ($user['person_id'] ?? 0);
    if ($personId <= 0) {
        $personId = (int) (personIdFromLegacyHolderId($pdo, (int) ($user['id'] ?? 0)) ?? 0);
    }
    if ($personId <= 0) {
        return [];
    }
    return getPersonAccessRoles($pdo, $personId);
}

/**
 * The check every capability-gated page calls. The rules (admin bypass, access
 * templates, individual overrides) live in lib/access.php; see access_can().
 */
function hub_auth_has_capability(string $capability): bool
{
    // Thin wrapper: the rules live in lib/access.php (access_can()). Kept so
    // the ~180 existing call sites keep working while they migrate.
    return access_can($capability);
}

/**
 * True if the current user holds any of the given capabilities — for pages
 * that legitimately span more than one domain (e.g. reports.php mixes
 * finance and matchday report types).
 *
 * @param list<string> $capabilities
 */
function hub_auth_has_any_capability(array $capabilities): bool
{
    foreach ($capabilities as $capability) {
        if (hub_auth_has_capability($capability)) {
            return true;
        }
    }
    return false;
}

// hub_auth_has_permission()/hub_auth_require_permission() (the old tickets.*/pos.* layer) were
// removed: nothing calls them; gate scanning is now the tickets_scan capability.
/**
 * Thin 403-and-exit wrapper around hub_auth_has_capability(), for gated
 * pages to use as a one-line guard — same shape as the existing
 * `if ($currentRole !== 'admin') { http_response_code(403); ...; exit; }`
 * blocks scattered across admin-only pages.
 */
function hub_auth_require_capability(string $capability): void
{
    if (hub_auth_has_capability($capability)) {
        return;
    }
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view this page.</div></div>';
    exit;
}

const HUB_AUTH_THROTTLE_WINDOW_SECONDS = 900;
const HUB_AUTH_THROTTLE_MAX_ATTEMPTS = 5;

/**
 * Atomic rate limit shared with admin/lib/mobile_api/Auth.php's rateLimit(),
 * backed by the same mobile_api_rate_limits table. Replaces the old
 * per-endpoint file-based counters here, whose read-modify-write wasn't
 * atomic across concurrent requests and only tracked one combined
 * IP+identity bucket.
 *
 * @return bool true if the request is within the limit, false if the bucket is exhausted
 */
function hub_auth_rate_limit(string $bucket, int $limit, int $window): bool
{
    global $pdo;

    $windowStart = intdiv(time(), $window);
    $hash = hash('sha256', $bucket . ':' . $windowStart);
    $expires = gmdate('Y-m-d H:i:s', ($windowStart + 1) * $window);

    $pdo->prepare('INSERT INTO mobile_api_rate_limits (bucket_hash, hits, expires_at) VALUES (:hash, 1, :expires)
        ON DUPLICATE KEY UPDATE hits = hits + 1')
        ->execute([':hash' => $hash, ':expires' => $expires]);

    $stmt = $pdo->prepare('SELECT hits FROM mobile_api_rate_limits WHERE bucket_hash = :hash');
    $stmt->execute([':hash' => $hash]);

    return (int) $stmt->fetchColumn() <= $limit;
}

function hub_auth_throttle_file(string $identifier): string
{
    $key = sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower(trim($identifier)));
    return __DIR__ . '/cache/login_throttle_' . $key . '.json';
}

/**
 * @return array{allowed: bool, retry_after: int}
 */
function hub_auth_throttle_check(string $identifier): array
{
    $file = hub_auth_throttle_file($identifier);
    $now = time();

    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['count'], $data['first']) || $now - (int) $data['first'] > HUB_AUTH_THROTTLE_WINDOW_SECONDS) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    if ((int) $data['count'] < HUB_AUTH_THROTTLE_MAX_ATTEMPTS) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    return ['allowed' => false, 'retry_after' => max(1, HUB_AUTH_THROTTLE_WINDOW_SECONDS - ($now - (int) $data['first']))];
}

function hub_auth_throttle_record_failure(string $identifier): void
{
    $file = hub_auth_throttle_file($identifier);
    $now = time();

    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['count'], $data['first']) || $now - (int) $data['first'] > HUB_AUTH_THROTTLE_WINDOW_SECONDS) {
        $data = ['count' => 0, 'first' => $now];
    }

    $data['count'] = (int) $data['count'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function hub_auth_throttle_clear(string $identifier): void
{
    $file = hub_auth_throttle_file($identifier);
    if (is_file($file)) {
        @unlink($file);
    }
}

function hub_auth_attempt_login(string $email, string $password): array
{
    global $pdo;
    hub_auth_start_session();

    $identifier = seasonTicketNormalizeEmail($email) ?? '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!hub_auth_rate_limit('web-login-ip:' . $ip, 30, HUB_AUTH_THROTTLE_WINDOW_SECONDS)
        || !hub_auth_rate_limit('web-login-account:' . $identifier, HUB_AUTH_THROTTLE_MAX_ATTEMPTS * 2, HUB_AUTH_THROTTLE_WINDOW_SECONDS)) {
        return ['ok' => false, 'error' => 'Too many login attempts. Please try again in a few minutes.'];
    }

    $account = authenticateAccount($pdo, $identifier, $password);
    if (!$account || !in_array((string) $account['role'], ACCOUNT_STAFF_ROLES, true)) {
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    session_regenerate_id(true);
    $_SESSION[HUB_AUTH_ACCOUNT_ID_KEY] = (string) $account['id'];
    $_SESSION[HUB_AUTH_PERSON_ID_KEY] = (string) $account['person_id'];
    $_SESSION[HUB_AUTH_USER_ID_KEY] = (string) ((int) ($account['old_holder_id'] ?? 0));
    $_SESSION[HUB_AUTH_CREDENTIAL_KEY] = hash('sha256', (string) ($account['password_hash'] ?? ''));
    $_SESSION['user_id'] = (int) ($account['old_holder_id'] ?? 0);
    $_SESSION['username'] = (string) ($account['display_name'] ?? $account['email'] ?? '');
    $_SESSION['role'] = (string) $account['role'];

    return ['ok' => true, 'username' => (string) ($account['display_name'] ?? $account['email'] ?? '')];
}

/**
 * First-run admin bootstrap — dead in practice (hub_auth_user_count() can
 * never return 0 on this install again) but left wired against the frozen
 * `users` table unchanged, since it can no longer fire either way and
 * touching it isn't necessary for the merge.
 */
function hub_auth_bootstrap_admin(string $username, string $email, string $password, string $installSecret): array
{
    hub_auth_start_session();
    return ['ok' => false, 'error' => 'Bootstrap is disabled. Hub identities are managed through people and accounts.'];
}

function hub_auth_logout(): void
{
    hub_auth_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();
}

// ---------------------------------------------------------------------
// Member-facing (member_auth_*) — isolated member_session cookie,
// unchanged mechanics from member_auth.php
// ---------------------------------------------------------------------

const MEMBER_AUTH_SESSION_NAME = 'member_session';
const MEMBER_AUTH_HOLDER_ID_KEY = 'member_holder_id';
const MEMBER_AUTH_ACCOUNT_ID_KEY = 'member_account_id';
const MEMBER_AUTH_PERSON_ID_KEY = 'member_person_id';
const MEMBER_AUTH_CSRF_KEY = 'member_csrf_token';
// Same credential-binding pattern as HUB_AUTH_CREDENTIAL_KEY: a hash of the
// password hash in force when this member session was issued, checked on
// every member_auth_current_account_id() call so a password change/reset
// revokes every other member session for that account.
const MEMBER_AUTH_CREDENTIAL_KEY = 'member_credential_hash';
const MEMBER_AUTH_COOKIE_LIFETIME = 2592000;
const MEMBER_RESET_TOKEN_TTL = 3600;
const MEMBER_AUTH_THROTTLE_WINDOW_SECONDS = 900;
const MEMBER_AUTH_THROTTLE_MAX_ATTEMPTS = 5;

function member_auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === MEMBER_AUTH_SESSION_NAME) {
            return;
        }

        // config.php (required by db.php, required above) unconditionally opens
        // PHP's default-named session as a side effect of connecting to the
        // database — the same session staff logins use. Close it unsaved and
        // reopen under our own session name so a member session can never be
        // the same session as (or collide with) a staff session.
        session_write_close();
    }

    session_name(MEMBER_AUTH_SESSION_NAME);

    // session_write_close() above does not clear PHP's internally tracked
    // session ID, and session_start() does not reliably re-read $_COOKIE for
    // the new name once an ID is already tracked from the just-closed staff
    // session — it can silently reuse the staff session's ID here, making
    // this "member" session literally the same storage as the staff one
    // (the isolation this whole file exists for). Deciding the ID explicitly
    // from the actual member cookie sidesteps that entirely. Validated
    // against PHP's own session ID charset/length so a malformed or forged
    // cookie can't be used to plant a chosen session ID (session fixation).
    $cookieValue = (string) ($_COOKIE[MEMBER_AUTH_SESSION_NAME] ?? '');
    if ($cookieValue !== '' && preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $cookieValue) === 1) {
        session_id($cookieValue);
    } else {
        session_id('');
    }

    session_set_cookie_params([
        'lifetime' => MEMBER_AUTH_COOKIE_LIFETIME,
        // Scoped to / (not just /members) so an already-logged-in
        // member is recognized on the season-tickets checkout pages too —
        // still a completely separate cookie *name* from the staff session,
        // so staff pages never see or use it.
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // The cookie above promises the browser 30 days, but PHP's own
    // server-side session-file garbage collection runs on a separate ini
    // setting (session.gc_maxlifetime) that defaults to as little as ~24
    // minutes on shared hosting — the cookie can still be valid while the
    // session data behind it has already been swept, silently emptying
    // $_SESSION (including the stored CSRF token) and making every form on
    // an idle-but-still-open tab fail with "Your session expired" the
    // moment the member comes back to it. Align the two so a session
    // genuinely lasts as long as its cookie says it does.
    ini_set('session.gc_maxlifetime', (string) MEMBER_AUTH_COOKIE_LIFETIME);

    session_start();
}

function member_auth_csrf_token(): string
{
    member_auth_start_session();
    $token = is_string($_SESSION[MEMBER_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[MEMBER_AUTH_CSRF_KEY] : '';
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION[MEMBER_AUTH_CSRF_KEY] = $token;
    }
    return $token;
}

function member_auth_verify_csrf_token(?string $token): bool
{
    member_auth_start_session();
    $stored = is_string($_SESSION[MEMBER_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[MEMBER_AUTH_CSRF_KEY] : '';
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

function member_auth_current_holder(): ?array
{
    global $pdo;
    $person = member_auth_current_person();
    if (!$person) {
        return null;
    }

    $holderId = member_auth_current_legacy_holder_id();
    if ($holderId <= 0) {
        return null;
    }

    $holder = getSeasonTicketHolder($pdo, $holderId);
    if (!$holder || (int) ($holder['is_active'] ?? 1) !== 1) {
        return null;
    }

    $holder['name'] = (string) ($person['display_name'] ?? $holder['name'] ?? '');
    $holder['email'] = (string) ($person['email'] ?? $holder['email'] ?? '');
    $holder['phone'] = (string) ($person['phone'] ?? $holder['phone'] ?? '');
    $holder['date_of_birth'] = (string) ($person['date_of_birth'] ?? $holder['date_of_birth'] ?? '');
    $holder['address_line1'] = (string) ($person['address_line1'] ?? $holder['address_line1'] ?? '');
    $holder['address_line2'] = (string) ($person['address_line2'] ?? $holder['address_line2'] ?? '');
    $holder['town'] = (string) ($person['town'] ?? $holder['town'] ?? '');
    $holder['postcode'] = (string) ($person['postcode'] ?? $holder['postcode'] ?? '');
    $holder['country'] = (string) ($person['country'] ?? $holder['country'] ?? '');
    $holder['marketing_opt_in'] = (int) ($person['marketing_opt_in'] ?? $holder['marketing_opt_in'] ?? 0);
    $holder['profile_image_path'] = (string) ($person['profile_image_path'] ?? $holder['profile_image_path'] ?? '');
    $holder['person_id'] = (int) $person['id'];
    return $holder;
}

function member_auth_current_account_id(): ?int
{
    member_auth_start_session();
    $accountId = (int) ($_SESSION[MEMBER_AUTH_ACCOUNT_ID_KEY] ?? 0);

    if ($accountId <= 0) {
        $holderId = (int) ($_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] ?? 0);
        if ($holderId <= 0) {
            return null;
        }
        $account = getAccountByLegacyHolderId($GLOBALS['pdo'], $holderId);
        if (!$account) {
            return null;
        }
        $accountId = (int) $account['id'];
    } else {
        $account = getAccount($GLOBALS['pdo'], $accountId);
        if (!$account) {
            return null;
        }
    }

    $sessionCredential = (string) ($_SESSION[MEMBER_AUTH_CREDENTIAL_KEY] ?? '');
    $currentCredential = hash('sha256', (string) ($account['password_hash'] ?? ''));
    if ($sessionCredential === '' || !hash_equals($currentCredential, $sessionCredential)) {
        // Password changed (or reset) since this session was issued, or this
        // session predates credential binding — treat it as revoked rather
        // than silently trusting a stale identity.
        unset($_SESSION[MEMBER_AUTH_ACCOUNT_ID_KEY], $_SESSION[MEMBER_AUTH_PERSON_ID_KEY], $_SESSION[MEMBER_AUTH_HOLDER_ID_KEY], $_SESSION[MEMBER_AUTH_CREDENTIAL_KEY]);
        return null;
    }

    $_SESSION[MEMBER_AUTH_ACCOUNT_ID_KEY] = (string) $accountId;
    $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] = (string) $account['person_id'];

    return $accountId;
}

function member_auth_current_person_id(): ?int
{
    member_auth_start_session();

    // Must go through member_auth_current_account_id() first rather than
    // trusting a cached $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] directly — that
    // function is where the session's credential binding is checked, and it
    // clears the cached person id along with everything else once a session
    // is revoked. Reading the cache here first would silently bypass that.
    $accountId = member_auth_current_account_id();
    if ($accountId !== null) {
        $personId = (int) ($_SESSION[MEMBER_AUTH_PERSON_ID_KEY] ?? 0);
        if ($personId > 0) {
            return $personId;
        }
        $personId = personIdFromAccountId($GLOBALS['pdo'], $accountId) ?? 0;
        if ($personId > 0) {
            $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] = (string) $personId;
            return $personId;
        }
        return null;
    }

    // No accounts-table row bound to this session (a bare legacy holder
    // record that never had a password) — nothing for credential binding to
    // apply to either way, so this path is unaffected by it.
    $holderId = (int) ($_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] ?? 0);
    if ($holderId > 0) {
        $personId = personIdFromLegacyHolderId($GLOBALS['pdo'], $holderId) ?? 0;
        if ($personId > 0) {
            $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] = (string) $personId;
            return $personId;
        }
    }

    return null;
}

function member_auth_current_account(): ?array
{
    $accountId = member_auth_current_account_id();
    return $accountId !== null ? getAccount($GLOBALS['pdo'], $accountId) : null;
}

function member_auth_current_person(): ?array
{
    $personId = member_auth_current_person_id();
    if ($personId === null) {
        return null;
    }
    $person = getPerson($GLOBALS['pdo'], $personId);
    if (!$person || (int) ($person['is_active'] ?? 1) !== 1) {
        return null;
    }
    return $person;
}

function member_auth_current_legacy_holder_id(): ?int
{
    global $pdo;
    member_auth_start_session();

    // Validate the session first (this clears MEMBER_AUTH_HOLDER_ID_KEY
    // along with everything else if the session's credential binding no
    // longer matches — see member_auth_current_account_id()) before
    // trusting any cached holder id below.
    $personId = member_auth_current_person_id();

    $holderId = (int) ($_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] ?? 0);
    if ($holderId > 0) {
        return $holderId;
    }

    if ($personId === null) {
        return null;
    }

    $holderId = ensureLegacyHolderForPerson($pdo, $personId);
    $_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] = (string) $holderId;
    return $holderId;
}

function member_auth_person_view_model(array $person, ?array $account = null, ?int $holderId = null): array
{
    $role = $account ? accountPrimaryRole($GLOBALS['pdo'], (int) $account['id']) : 'public';
    return [
        'id' => $holderId ?? 0,
        'legacy_holder_id' => $holderId ?? 0,
        'person_id' => (int) $person['id'],
        'account_id' => $account ? (int) $account['id'] : 0,
        'name' => (string) ($person['display_name'] ?? ''),
        'display_name' => (string) ($person['display_name'] ?? ''),
        'email' => (string) ($person['email'] ?? ''),
        'login_email' => (string) ($account['email'] ?? ''),
        'phone' => (string) ($person['phone'] ?? ''),
        'date_of_birth' => (string) ($person['date_of_birth'] ?? ''),
        'address_line1' => (string) ($person['address_line1'] ?? ''),
        'address_line2' => (string) ($person['address_line2'] ?? ''),
        'town' => (string) ($person['town'] ?? ''),
        'postcode' => (string) ($person['postcode'] ?? ''),
        'country' => (string) ($person['country'] ?? ''),
        'marketing_opt_in' => (int) ($person['marketing_opt_in'] ?? 0),
        'profile_image_path' => (string) ($person['profile_image_path'] ?? ''),
        'role' => $role,
        'is_active' => (int) ($person['is_active'] ?? 1),
    ];
}

function member_auth_current_profile(): ?array
{
    $person = member_auth_current_person();
    if (!$person) {
        return null;
    }
    return member_auth_person_view_model(
        $person,
        member_auth_current_account(),
        member_auth_current_legacy_holder_id()
    );
}

function member_auth_is_authenticated(): bool
{
    return member_auth_current_person() !== null && member_auth_current_account() !== null;
}

function member_auth_throttle_file(string $identifier): string
{
    $key = sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower(trim($identifier)));
    return __DIR__ . '/cache/member_login_throttle_' . $key . '.json';
}

/**
 * @return array{allowed: bool, retry_after: int}
 */
function member_auth_throttle_check(string $identifier): array
{
    $file = member_auth_throttle_file($identifier);
    $now = time();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['count'], $data['first']) || $now - (int) $data['first'] > MEMBER_AUTH_THROTTLE_WINDOW_SECONDS) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    if ((int) $data['count'] < MEMBER_AUTH_THROTTLE_MAX_ATTEMPTS) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    return ['allowed' => false, 'retry_after' => max(1, MEMBER_AUTH_THROTTLE_WINDOW_SECONDS - ($now - (int) $data['first']))];
}

function member_auth_throttle_record_failure(string $identifier): void
{
    $file = member_auth_throttle_file($identifier);
    $now = time();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['count'], $data['first']) || $now - (int) $data['first'] > MEMBER_AUTH_THROTTLE_WINDOW_SECONDS) {
        $data = ['count' => 0, 'first' => $now];
    }
    $data['count'] = (int) $data['count'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function member_auth_throttle_clear(string $identifier): void
{
    $file = member_auth_throttle_file($identifier);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Single-table login: staff and members are the same accounts now, so
 * there is no more "try season_ticket_holders, then fall back to users"
 * branch — any role can log in here with the one password on their account.
 *
 * @return array{ok: bool, error?: string}
 */
function member_auth_attempt_login(string $email, string $password): array
{
    global $pdo;
    member_auth_start_session();

    $identifier = seasonTicketNormalizeEmail($email) ?? '';
    $throttle = member_auth_throttle_check($identifier);
    if (!$throttle['allowed']) {
        return ['ok' => false, 'error' => 'Too many login attempts. Please try again in a few minutes.'];
    }

    $account = authenticateAccount($pdo, $identifier, $password);
    if (!$account) {
        member_auth_throttle_record_failure($identifier);
        return ['ok' => false, 'error' => 'Incorrect email or password.'];
    }

    member_auth_throttle_clear($identifier);
    member_auth_login_account((int) $account['id'], (int) $account['person_id'], (int) ($account['old_holder_id'] ?? 0));

    return ['ok' => true];
}

function member_auth_login_account(int $accountId, int $personId, int $holderId): void
{
    member_auth_start_session();
    session_regenerate_id(true);
    $_SESSION[MEMBER_AUTH_ACCOUNT_ID_KEY] = (string) $accountId;
    $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] = (string) $personId;
    if ($holderId > 0) {
        $_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] = (string) $holderId;
    }
    $account = getAccount($GLOBALS['pdo'], $accountId);
    $_SESSION[MEMBER_AUTH_CREDENTIAL_KEY] = hash('sha256', (string) ($account['password_hash'] ?? ''));
}

/**
 * Establish a logged-in member session for a specific holder, bypassing
 * password verification — for flows that have already established identity
 * some other way (checkout signup, staff auto-login, a future "activate
 * account" email link). Callers are responsible for that verification.
 */
function member_auth_login_session(int $holderId): void
{
    member_auth_start_session();
    session_regenerate_id(true);
    $_SESSION[MEMBER_AUTH_HOLDER_ID_KEY] = (string) $holderId;
    $account = getAccountByLegacyHolderId($GLOBALS['pdo'], $holderId);
    if ($account) {
        $_SESSION[MEMBER_AUTH_ACCOUNT_ID_KEY] = (string) $account['id'];
        $_SESSION[MEMBER_AUTH_PERSON_ID_KEY] = (string) $account['person_id'];
        $_SESSION[MEMBER_AUTH_CREDENTIAL_KEY] = hash('sha256', (string) ($account['password_hash'] ?? ''));
    }
}

function member_auth_logout(): void
{
    member_auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }
    session_destroy();
}

/**
 * Auto-log an already-authenticated staff session into the /members
 * portal as itself — so an admin already signed into the Hub never has to
 * separately log into the member area too. Collapses to a direct session
 * swap now that staff and members are the same account row: $staffUser
 * (a hub_auth_current_user() row) already *is* the season_ticket_holders
 * id, no separate lookup/auto-create needed.
 *
 * Must be called with the staff identity (from hub_auth_current_user())
 * captured *before* member_auth_start_session() switches $_SESSION over to
 * the member cookie, since the two sessions are deliberately isolated.
 *
 * @param array<string, mixed> $staffUser a hub_auth_current_user() row
 */
function member_auth_login_as_staff(PDO $pdo, array $staffUser): bool
{
    $accountId = (int) ($staffUser['account_id'] ?? 0);
    $personId = (int) ($staffUser['person_id'] ?? 0);
    $holderId = (int) ($staffUser['legacy_holder_id'] ?? $staffUser['id'] ?? 0);
    if ($accountId > 0) {
        member_auth_login_account($accountId, $personId, $holderId);
        return true;
    }

    if ($holderId > 0) {
        member_auth_login_session($holderId);
        return true;
    }

    return false;
}

/**
 * Public self-registration, shared by members/register.php and
 * tickets_shop.php's "signup" checkout mode. Creates a new account, or —
 * if a passwordless contact record already exists for this email (an
 * admin-created or CLI-imported holder that never got a login) —
 * activates that same row instead of creating a duplicate. An email that
 * already has a password on file is rejected rather than silently
 * overwritten. Never sets role (new rows keep the 'public' column
 * default); only account_auth_set_role() is allowed to change that.
 *
 * @param array<string, mixed> $data
 * @return array{ok: bool, error?: string, holder_id?: int}
 */
function member_account_register(PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $marketingOptIn = !empty($data['marketing_opt_in']);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Enter your name.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if ($password !== '' && strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $result = registerPublicAccount($pdo, [
        'name' => $name,
        'date_of_birth' => $dateOfBirth,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'password' => $password,
        'marketing_opt_in' => $marketingOptIn ? 1 : 0,
    ]);
    if (!$result['ok']) {
        return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Unable to create your account.')];
    }

    member_auth_login_account((int) $result['account_id'], (int) $result['person_id'], (int) $result['holder_id']);

    return ['ok' => true, 'holder_id' => (int) $result['holder_id']];
}

/**
 * @return array{ok: bool, message: string}
 */
function member_auth_issue_password_reset(PDO $pdo, string $email): array
{
    return issueAccountPasswordReset($pdo, $email, '/members/reset_password.php');
}

/**
 * @return array{ok: bool, message: string}
 */
function member_auth_reset_password_by_token(PDO $pdo, string $token, string $newPassword): array
{
    if ($token === '' || strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Please choose a password of at least 8 characters.'];
    }

    return resetAccountPasswordByToken($pdo, $token, $newPassword);
}
