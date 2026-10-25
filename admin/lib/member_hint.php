<?php
declare(strict_types=1);

const MEMBER_HINT_LIFETIME = 2592000;

/**
 * Display-only "who is logged in" hint for the public site chrome. The real member
 * session lives in an isolated session (see member_auth_start_session) that public
 * pages deliberately never open, so on login we also drop a small signed cookie with
 * the member's name. It grants NO access — every /members page still checks the real
 * session — it only lets the public header show "Hi, Name · Log out" instead of
 * "Log in".
 */
const MEMBER_HINT_COOKIE = 'sv_mbr';

function member_hint_key(): string
{
    return hash('sha256', 'sv_mbr|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('INSTALL_SECRET') ? INSTALL_SECRET : ''), true);
}

function member_hint_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function member_hint_set(int $personId): void
{
    if (headers_sent() || $personId <= 0) {
        return;
    }
    try {
        $stmt = $GLOBALS['pdo']->prepare('SELECT display_name FROM people WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $personId]);
        $name = trim((string) $stmt->fetchColumn());
    } catch (Throwable) {
        return;
    }
    if ($name === '') {
        return;
    }
    $payload = rtrim(strtr(base64_encode(json_encode(['n' => $name, 'e' => time() + MEMBER_HINT_LIFETIME], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $payload, member_hint_key());
    setcookie(MEMBER_HINT_COOKIE, $payload . '.' . $sig, member_hint_cookie_options(time() + MEMBER_HINT_LIFETIME));
}

function member_hint_clear(): void
{
    if (!headers_sent()) {
        setcookie(MEMBER_HINT_COOKIE, '', member_hint_cookie_options(time() - 3600));
    }
    unset($_COOKIE[MEMBER_HINT_COOKIE]);
}

/** @return array{name:string}|null verified hint, or null */
function member_hint_read(): ?array
{
    $raw = (string) ($_COOKIE[MEMBER_HINT_COOKIE] ?? '');
    if ($raw === '' || substr_count($raw, '.') !== 1) {
        return null;
    }
    [$payload, $sig] = explode('.', $raw);
    if (!hash_equals(hash_hmac('sha256', $payload, member_hint_key()), $sig)) {
        return null;
    }
    $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    if (!is_array($data) || (int) ($data['e'] ?? 0) < time() || trim((string) ($data['n'] ?? '')) === '') {
        return null;
    }
    return ['name' => (string) $data['n']];
}
