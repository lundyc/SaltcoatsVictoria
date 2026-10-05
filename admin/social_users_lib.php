<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lib/mailer.php';

const USERS_DATA_FILE = __DIR__ . '/data/users.json';
const USERS_RESET_TOKEN_TTL = 3600;
const USERS_DEFAULT_LOGIN = 'lundy';
const USERS_DEFAULT_EMAIL = 'colin@lundy.me.uk';

/**
 * @return array<string, string>
 */
function users_env_defaults(): array
{
    $env = app_parse_env_file(__DIR__ . '/.env');

    return [
        'username' => trim((string) ($env['AUTH_USERNAME'] ?? USERS_DEFAULT_LOGIN)),
        'email' => trim((string) ($env['AUTH_EMAIL'] ?? USERS_DEFAULT_EMAIL)),
        'password_hash' => trim((string) ($env['AUTH_PASSWORD_HASH'] ?? '')),
    ];
}

function users_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

function users_now(): string
{
    return gmdate('c');
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function users_normalize(array $user): array
{
    $role = strtolower(trim((string) ($user['role'] ?? 'admin')));
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }

    return [
        'id' => trim((string) ($user['id'] ?? '')),
        'name' => trim((string) ($user['name'] ?? '')),
        'username' => trim((string) ($user['username'] ?? '')),
        'email' => trim((string) ($user['email'] ?? '')),
        'role' => $role,
        'password_hash' => trim((string) ($user['password_hash'] ?? '')),
        'reset_token_hash' => trim((string) ($user['reset_token_hash'] ?? '')),
        'reset_token_expires_at' => trim((string) ($user['reset_token_expires_at'] ?? '')),
        'created_at' => trim((string) ($user['created_at'] ?? '')),
        'updated_at' => trim((string) ($user['updated_at'] ?? '')),
        'last_login_at' => trim((string) ($user['last_login_at'] ?? '')),
    ];
}

/**
 * @return array<string, mixed>
 */
function users_default_admin_record(): array
{
    $defaults = users_env_defaults();
    if ($defaults['password_hash'] === '') {
        throw new RuntimeException('AUTH_PASSWORD_HASH must be configured before bootstrapping the user store.');
    }

    return users_normalize([
        'id' => users_generate_id(),
        'name' => 'Colin',
        'username' => $defaults['username'] !== '' ? $defaults['username'] : USERS_DEFAULT_LOGIN,
        'email' => $defaults['email'] !== '' ? $defaults['email'] : USERS_DEFAULT_EMAIL,
        'role' => 'admin',
        'password_hash' => $defaults['password_hash'],
        'reset_token_hash' => '',
        'reset_token_expires_at' => '',
        'created_at' => users_now(),
        'updated_at' => users_now(),
        'last_login_at' => '',
    ]);
}

function users_ensure_storage(): void
{
    $dir = dirname(USERS_DATA_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_file(USERS_DATA_FILE) || trim((string) @file_get_contents(USERS_DATA_FILE)) === '') {
        if (users_env_defaults()['password_hash'] === '') {
            return;
        }

        users_save_all([users_default_admin_record()]);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function users_load_all(): array
{
    users_ensure_storage();

    $json = file_get_contents(USERS_DATA_FILE);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $users = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            $users[] = users_normalize($item);
        }
    }

    usort($users, static function (array $a, array $b): int {
        if ($a['role'] !== $b['role']) {
            return ($a['role'] === 'admin' ? 0 : 1) <=> ($b['role'] === 'admin' ? 0 : 1);
        }

        $nameComparison = strcasecmp((string) $a['name'], (string) $b['name']);
        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        return strcasecmp((string) $a['username'], (string) $b['username']);
    });

    return $users;
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_save_all(array $users): bool
{
    $dir = dirname(USERS_DATA_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(USERS_DATA_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_find_index_by_id(array $users, string $id): ?int
{
    foreach ($users as $index => $user) {
        if ((string) ($user['id'] ?? '') === $id) {
            return $index;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_find_by_id(array $users, string $id): ?array
{
    $index = users_find_index_by_id($users, $id);
    return $index !== null ? $users[$index] : null;
}

function users_normalize_login(string $value): string
{
    return mb_strtolower(trim($value));
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_find_index_by_login(array $users, string $login): ?int
{
    $needle = users_normalize_login($login);
    if ($needle === '') {
        return null;
    }

    foreach ($users as $index => $user) {
        $username = users_normalize_login((string) ($user['username'] ?? ''));
        $email = users_normalize_login((string) ($user['email'] ?? ''));

        if ($needle === $username || ($email !== '' && $needle === $email)) {
            return $index;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_find_by_login(array $users, string $login): ?array
{
    $index = users_find_index_by_login($users, $login);
    return $index !== null ? $users[$index] : null;
}

function users_generate_password_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * @return array{token: string, token_hash: string, expires_at: string}
 */
function users_generate_reset_token(): array
{
    $token = bin2hex(random_bytes(24));

    return [
        'token' => $token,
        'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('c', time() + USERS_RESET_TOKEN_TTL),
    ];
}

function users_mail_host(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = trim(strtolower($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $host = preg_replace('/[^a-z0-9.-]/', '', $host) ?? '';
    $host = preg_replace('/^www\./', '', $host) ?? '';

    return $host !== '' ? $host : 'lundy.me.uk';
}

function users_mail_from_address(): string
{
    return hub_mail_from_address();
}

function users_mail_from_name(): string
{
    return hub_mail_from_name();
}

function users_app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = users_mail_host();
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/social_users_lib.php');
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . ($basePath !== '' ? $basePath : '');
}

function users_build_password_reset_url(string $token): string
{
    return users_app_base_url() . '/reset_password.php?token=' . rawurlencode($token);
}

function users_send_password_reset_email(array $user, string $resetUrl): bool
{
    $to = trim((string) ($user['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Reset your password';
    $fromName = users_mail_from_name();
    $fromAddress = users_mail_from_address();
    $message = implode("\n", [
        'You requested a password reset for your account.',
        '',
        'Reset link:',
        $resetUrl,
        '',
        'This link expires in ' . (int) (USERS_RESET_TOKEN_TTL / 60) . ' minutes.',
        '',
        'If you did not request this reset, you can ignore this email.',
    ]);

    return hub_send_mail($to, $subject, $message, false, $fromAddress);
}

/**
 * @param list<array<string, mixed>> $users
 */
function users_find_index_by_reset_token(array $users, string $token): ?int
{
    $tokenHash = hash('sha256', $token);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    foreach ($users as $index => $user) {
        $storedHash = (string) ($user['reset_token_hash'] ?? '');
        $expiresAt = trim((string) ($user['reset_token_expires_at'] ?? ''));

        if ($storedHash === '' || !hash_equals($storedHash, $tokenHash) || $expiresAt === '') {
            continue;
        }

        $expiry = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $expiresAt, new DateTimeZone('UTC'));
        if ($expiry === false || $expiry < $now) {
            continue;
        }

        return $index;
    }

    return null;
}

/**
 * @return array{ok: bool, message: string, user?: array<string, mixed>}
 */
function users_issue_password_reset(string $login): array
{
    $users = users_load_all();
    $index = users_find_index_by_login($users, $login);
    if ($index === null) {
        return [
            'ok' => true,
            'message' => 'If the account exists, a secure password reset email has been sent.',
        ];
    }

    $token = users_generate_reset_token();
    $users[$index]['reset_token_hash'] = $token['token_hash'];
    $users[$index]['reset_token_expires_at'] = $token['expires_at'];
    $users[$index]['updated_at'] = users_now();
    if (!users_save_all($users)) {
        return [
            'ok' => false,
            'message' => 'Unable to save the password reset request.',
        ];
    }

    $resetUrl = users_build_password_reset_url($token['token']);
    if (!users_send_password_reset_email($users[$index], $resetUrl)) {
        $users[$index]['reset_token_hash'] = '';
        $users[$index]['reset_token_expires_at'] = '';
        $users[$index]['updated_at'] = users_now();
        users_save_all($users);

        return [
            'ok' => false,
            'message' => 'Unable to send the password reset email.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Password reset email sent.',
        'user' => $users[$index],
    ];
}

/**
 * @return array{ok: bool, message: string, user?: array<string, mixed>}
 */
function users_reset_password_by_token(string $token, string $newPassword): array
{
    if ($token === '') {
        return [
            'ok' => false,
            'message' => 'Reset token is missing.',
        ];
    }

    if ($newPassword === '') {
        return [
            'ok' => false,
            'message' => 'Please enter a new password.',
        ];
    }

    $users = users_load_all();
    $index = users_find_index_by_reset_token($users, $token);
    if ($index === null) {
        return [
            'ok' => false,
            'message' => 'That reset link is invalid or has expired.',
        ];
    }

    $users[$index]['password_hash'] = users_generate_password_hash($newPassword);
    $users[$index]['reset_token_hash'] = '';
    $users[$index]['reset_token_expires_at'] = '';
    $users[$index]['updated_at'] = users_now();
    users_save_all($users);

    return [
        'ok' => true,
        'message' => 'Password updated successfully.',
        'user' => $users[$index],
    ];
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, message: string, user?: array<string, mixed>, errors?: list<string>}
 */
function users_validate_record(array $user, ?string $password, ?string $passwordConfirm, ?string $existingId = null): array
{
    $errors = [];

    $name = trim((string) ($user['name'] ?? ''));
    $username = trim((string) ($user['username'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $role = strtolower(trim((string) ($user['role'] ?? 'admin')));

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    if ($username === '') {
        $errors[] = 'Username is required.';
    }

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }

    if (!in_array($role, ['admin', 'user'], true)) {
        $errors[] = 'Role must be admin or user.';
    }

    if ($password !== null || $passwordConfirm !== null) {
        if (($password ?? '') !== ($passwordConfirm ?? '')) {
            $errors[] = 'Passwords do not match.';
        }
    }

    $users = users_load_all();
    foreach ($users as $existing) {
        if ($existingId !== null && (string) ($existing['id'] ?? '') === $existingId) {
            continue;
        }

        if (strcasecmp((string) ($existing['username'] ?? ''), $username) === 0) {
            $errors[] = 'That username is already in use.';
        }

        if (strcasecmp((string) ($existing['email'] ?? ''), $email) === 0) {
            $errors[] = 'That email address is already in use.';
        }
    }

    if ($existingId === null && trim((string) ($password ?? '')) === '') {
        $errors[] = 'Password is required when creating a user.';
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the highlighted user fields.',
            'errors' => $errors,
        ];
    }

    return [
        'ok' => true,
        'message' => 'User record is valid.',
        'user' => [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'password' => $password,
        ],
    ];
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok: bool, message: string, user?: array<string, mixed>, errors?: list<string>}
 */
function users_save_record(array $fields): array
{
    $existingId = isset($fields['id']) && is_string($fields['id']) ? trim($fields['id']) : null;
    $password = isset($fields['password']) && is_string($fields['password']) ? $fields['password'] : null;
    $passwordConfirm = isset($fields['password_confirm']) && is_string($fields['password_confirm']) ? $fields['password_confirm'] : null;

    $validation = users_validate_record($fields, $password, $passwordConfirm, $existingId !== '' ? $existingId : null);
    if (!$validation['ok']) {
        return $validation;
    }

    $users = users_load_all();
    $now = users_now();
    $record = $validation['user'] ?? [];

    if ($existingId !== null && $existingId !== '') {
        $index = users_find_index_by_id($users, $existingId);
        if ($index === null) {
            return [
                'ok' => false,
                'message' => 'The selected user no longer exists.',
                'errors' => ['User not found.'],
            ];
        }

        $current = $users[$index];
        $users[$index] = array_merge($current, [
            'name' => $record['name'],
            'username' => $record['username'],
            'email' => $record['email'],
            'role' => $record['role'],
            'password_hash' => trim((string) ($password ?? '')) !== '' ? users_generate_password_hash((string) $password) : (string) ($current['password_hash'] ?? ''),
            'updated_at' => $now,
        ]);
        $users[$index] = users_normalize($users[$index]);

        if (trim((string) ($password ?? '')) !== '') {
            $users[$index]['reset_token_hash'] = '';
            $users[$index]['reset_token_expires_at'] = '';
        }

        users_save_all($users);

        return [
            'ok' => true,
            'message' => 'User updated successfully.',
            'user' => $users[$index],
        ];
    }

    $newUser = users_normalize([
        'id' => users_generate_id(),
        'name' => $record['name'],
        'username' => $record['username'],
        'email' => $record['email'],
        'role' => $record['role'],
        'password_hash' => users_generate_password_hash((string) $password),
        'reset_token_hash' => '',
        'reset_token_expires_at' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => '',
    ]);

    $users[] = $newUser;
    users_save_all($users);

    return [
        'ok' => true,
        'message' => 'User created successfully.',
        'user' => $newUser,
    ];
}

/**
 * @param list<array<string, mixed>> $users
 * @return array{ok: bool, message: string, users?: list<array<string, mixed>>, deleted_user?: array<string, mixed>}
 */
function users_delete_record(string $id): array
{
    $users = users_load_all();
    $index = users_find_index_by_id($users, $id);
    if ($index === null) {
        return [
            'ok' => false,
            'message' => 'User not found.',
        ];
    }

    $user = $users[$index];
    $adminCount = count(array_filter($users, static fn(array $candidate): bool => (string) ($candidate['role'] ?? '') === 'admin'));
    if ((string) ($user['role'] ?? '') === 'admin' && $adminCount <= 1) {
        return [
            'ok' => false,
            'message' => 'You cannot delete the last admin user.',
        ];
    }

    array_splice($users, $index, 1);
    users_save_all($users);

    return [
        'ok' => true,
        'message' => 'User deleted successfully.',
        'deleted_user' => $user,
        'users' => $users,
    ];
}
