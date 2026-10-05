<?php

declare(strict_types=1);

require_once __DIR__ . '/social_users_lib.php';

if (is_file(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}

const WOSFL_AUTH_SESSION_KEY = 'authenticated';
const WOSFL_AUTH_USER_ID_KEY = 'user_id';
const WOSFL_AUTH_USERNAME_KEY = 'username';
const WOSFL_AUTH_ROLE_KEY = 'role';
const WOSFL_AUTH_CSRF_KEY = 'csrf_token';
const WOSFL_AUTH_COOKIE_LIFETIME = 2592000;

function auth_cookie_domain(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $host = preg_replace('/^www\./', '', $host) ?? '';

    if ($host === '') {
        return '';
    }

    if (preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $host)) {
        return '';
    }

    if (str_contains($host, '.')) {
        return '.' . $host;
    }

    return '';
}

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => WOSFL_AUTH_COOKIE_LIFETIME,
        'path' => '/',
        'domain' => auth_cookie_domain(),
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
    }

    session_start();

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

/**
 * @return array<string, mixed>|null
 */
function auth_current_user(): ?array
{
    auth_start_session();

    if (function_exists('hub_auth_is_authenticated') && hub_auth_is_authenticated()) {
        $hubUser = hub_auth_current_user();
        if ($hubUser !== null) {
            return [
                'id' => (string) ($hubUser['id'] ?? ''),
                'username' => (string) ($hubUser['username'] ?? $hubUser['display_name'] ?? ''),
                'email' => (string) ($hubUser['email'] ?? ''),
                'name' => (string) ($hubUser['display_name'] ?? $hubUser['username'] ?? ''),
                'role' => (string) ($hubUser['role'] ?? 'user'),
                'is_active' => 1,
            ];
        }
    }

    $userId = is_string($_SESSION[WOSFL_AUTH_USER_ID_KEY] ?? null) ? (string) $_SESSION[WOSFL_AUTH_USER_ID_KEY] : '';
    if ($userId === '') {
        return null;
    }

    $user = users_find_by_id(users_load_all(), $userId);
    if ($user === null) {
        return null;
    }

    return $user;
}

function auth_is_authenticated(): bool
{
    return auth_current_user() !== null;
}

function auth_is_admin(): bool
{
    $user = auth_current_user();
    return $user !== null && (string) ($user['role'] ?? '') === 'admin';
}

function auth_get_username(): string
{
    $user = auth_current_user();
    if ($user === null) {
        return '';
    }

    $username = trim((string) ($user['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return trim((string) ($user['email'] ?? ''));
}

function auth_get_display_name(): string
{
    $user = auth_current_user();
    if ($user === null) {
        return '';
    }

    $name = trim((string) ($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return auth_get_username();
}

function auth_get_user_id(): string
{
    auth_start_session();
    return is_string($_SESSION[WOSFL_AUTH_USER_ID_KEY] ?? null) ? (string) $_SESSION[WOSFL_AUTH_USER_ID_KEY] : '';
}

function auth_csrf_token(): string
{
    auth_start_session();

    $token = is_string($_SESSION[WOSFL_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[WOSFL_AUTH_CSRF_KEY] : '';
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION[WOSFL_AUTH_CSRF_KEY] = $token;
    }

    return $token;
}

function auth_verify_csrf_token(?string $token): bool
{
    auth_start_session();

    $stored = is_string($_SESSION[WOSFL_AUTH_CSRF_KEY] ?? null) ? (string) $_SESSION[WOSFL_AUTH_CSRF_KEY] : '';
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

/**
 * @return array{ok: bool, error?: string}
 */
function auth_attempt_login(string $identifier, string $password): array
{
    auth_start_session();

    if (function_exists('hub_auth_throttle_check')) {
        $throttle = hub_auth_throttle_check($identifier);
        if (!$throttle['allowed']) {
            return ['ok' => false, 'error' => 'Too many login attempts. Please try again in a few minutes.'];
        }
    }

    $users = users_load_all();
    $user = users_find_by_login($users, $identifier);
    if ($user === null) {
        if (function_exists('hub_auth_throttle_record_failure')) {
            hub_auth_throttle_record_failure($identifier);
        }
        return [
            'ok' => false,
            'error' => 'Invalid username or password.',
        ];
    }

    $passwordHash = trim((string) ($user['password_hash'] ?? ''));
    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        if (function_exists('hub_auth_throttle_record_failure')) {
            hub_auth_throttle_record_failure($identifier);
        }
        return [
            'ok' => false,
            'error' => 'Invalid username or password.',
        ];
    }

    if (function_exists('hub_auth_throttle_clear')) {
        hub_auth_throttle_clear($identifier);
    }

    $index = users_find_index_by_id($users, (string) ($user['id'] ?? ''));
    if ($index !== null) {
        $users[$index]['last_login_at'] = users_now();
        $users[$index]['updated_at'] = users_now();
        users_save_all($users);
        $user = $users[$index];
    }

    session_regenerate_id(true);
    $_SESSION[WOSFL_AUTH_SESSION_KEY] = true;
    $_SESSION[WOSFL_AUTH_USER_ID_KEY] = (string) ($user['id'] ?? '');
    $_SESSION[WOSFL_AUTH_USERNAME_KEY] = trim((string) ($user['username'] ?? $user['email'] ?? ''));
    $_SESSION[WOSFL_AUTH_ROLE_KEY] = (string) ($user['role'] ?? 'user');

    return ['ok' => true];
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();

    if (function_exists('hub_auth_logout')) {
        hub_auth_logout();
    }
}

function auth_require_json(): void
{
    if (auth_is_authenticated()) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Authentication required.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
