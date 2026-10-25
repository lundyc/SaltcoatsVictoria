<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

$hubFileEnv = app_parse_env_file(__DIR__ . '/.env');

if (!function_exists('hub_config_value')) {
    function hub_config_value(array $fileEnv, string $key, string $default): string
    {
        $runtime = getenv($key);
        if ($runtime !== false && trim((string) $runtime) !== '') {
            return (string) $runtime;
        }

        if (isset($fileEnv[$key]) && trim((string) $fileEnv[$key]) !== '') {
            return (string) $fileEnv[$key];
        }

        return $default;
    }
}

if (!defined('APP_NAME')) {
    define('APP_NAME', hub_config_value($hubFileEnv, 'APP_NAME', 'Hub'));
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
if (!defined('APP_ORIGIN')) {
    // Trusted scheme+host for links sent in emails (password resets, setup
    // links). Deliberately not derived from $_SERVER['HTTP_HOST'], which is
    // attacker-controlled on a server that doesn't reject unknown Host
    // headers at the vhost boundary.
    define('APP_ORIGIN', rtrim(hub_config_value($hubFileEnv, 'HUB_APP_ORIGIN', 'https://saltcoatsvictoria.co.uk'), '/'));
}
if (!defined('ERROR_LOG_PATH')) {
    define('ERROR_LOG_PATH', '/var/www/vhosts/lundy.me.uk/logs/error_log');
}
if (!defined('DB_HOST')) {
    define('DB_HOST', hub_config_value($hubFileEnv, 'HUB_DB_HOST', 'localhost:3306'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', hub_config_value($hubFileEnv, 'HUB_DB_NAME', 'Hub'));
}
if (!defined('DB_FALLBACK_NAME')) {
    define('DB_FALLBACK_NAME', hub_config_value($hubFileEnv, 'HUB_DB_FALLBACK_NAME', 'PlayerSponsors'));
}
if (!defined('DB_USER')) {
    define('DB_USER', hub_config_value($hubFileEnv, 'HUB_DB_USER', 'PlayerSponsors'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', hub_config_value($hubFileEnv, 'HUB_DB_PASS', ''));
}
if (!defined('INSTALL_SECRET')) {
    define('INSTALL_SECRET', hub_config_value($hubFileEnv, 'HUB_INSTALL_SECRET', ''));
}
if (!defined('DEVELOPER_EMAIL')) {
    // Identifies the one account allowed to use developer_roles.php's user
    // switcher. Previously hardcoded as users.id === 1; that broke once
    // staff accounts moved into season_ticket_holders (a different id
    // space), so this is now an explicit, reassignable identity instead of
    // an assumption about row order. Empty = feature disabled.
    define('DEVELOPER_EMAIL', hub_config_value($hubFileEnv, 'HUB_DEVELOPER_EMAIL', ''));
}
if (!defined('SPONSORSHIP_EDITOR_EMAIL')) {
    // The one account allowed to change player sponsorships (assign/remove a
    // home/away/third sponsor, transfer a sponsorship to another player).
    // Deliberately not a capability — hub_auth_has_capability() always
    // returns true for admin accounts, and this needs to hold even against
    // another admin. Empty = nobody is specially allowed (falls through to
    // "no one but this email may edit").
    define('SPONSORSHIP_EDITOR_EMAIL', hub_config_value($hubFileEnv, 'HUB_SPONSORSHIP_EDITOR_EMAIL', ''));
}
if (!defined('APP_DEBUG')) {
    // Fail safe: an environment with no HUB_APP_DEBUG set does not leak
    // stack traces. Set HUB_APP_DEBUG=1 in .env to turn display_errors on.
    define('APP_DEBUG', filter_var(hub_config_value($hubFileEnv, 'HUB_APP_DEBUG', '0'), FILTER_VALIDATE_BOOL));
}

// PHP has no date.timezone set in this vhost's php.ini and does not infer
// it from the OS, so it silently defaults to UTC. MySQL's NOW() follows the
// server's SYSTEM timezone (Europe/London), so without this, any code that
// mixes a MySQL-generated timestamp with a PHP-generated one (time(),
// date(), strtotime()) is off by an hour during BST.
date_default_timezone_set('Europe/London');

error_reporting(E_ALL);
ini_set('log_errors', '1');
if (APP_DEBUG) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Reject client-supplied session IDs that the server never generated,
    // closing the session-fixation gap left by PHP-FPM's default
    // session.use_strict_mode=0. Must be set before session_start().
    ini_set('session.use_strict_mode', '1');
    // "Remember me": keep logins for 180 days. Session files live in a private
    // folder because the system's cleanup job deletes anything in the default
    // folder after ~24 minutes, which was logging everyone out. The cookie
    // lifetime is renewed on every visit, so active users stay signed in.
    $sessionDir = dirname(__DIR__, 2) . '/php-sessions';
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
    }
    ini_set('session.gc_maxlifetime', '15552000');
    session_set_cookie_params([
        'lifetime' => 15552000,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Player/sponsor modules historically used user_id while the Hub uses
// hub_user_id. Keep both keys synchronized during the flattened transition.
if (isset($_SESSION['hub_user_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = (int) $_SESSION['hub_user_id'];
}
if (isset($_SESSION['user_id']) && !isset($_SESSION['hub_user_id'])) {
    $_SESSION['hub_user_id'] = (string) $_SESSION['user_id'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// X-Content-Type-Options, X-Frame-Options, Referrer-Policy, HSTS and CSP
// (both enforced and report-only) are already applied to every response at
// the Nginx/Plesk layer in front of PHP — confirmed live on both admin and
// public pages. Setting them again here would only produce duplicate,
// possibly conflicting header lines, so this file intentionally does not
// repeat them.
