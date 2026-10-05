<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/mailer.php';

const HUB_RESET_TOKEN_TTL = 3600;

function hub_users_column_exists(string $column): bool
{
    global $pdo;
    static $columns = null;

    if ($columns === null) {
        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM users') as $row) {
            $columns[(string) $row['Field']] = true;
        }
    }

    return isset($columns[$column]);
}

function hub_users_generate_reset_token(): array
{
    $token = bin2hex(random_bytes(24));
    return [
        'token' => $token,
        'token_hash' => hash('sha256', $token),
        'expires_at' => gmdate('c', time() + HUB_RESET_TOKEN_TTL),
    ];
}

function hub_users_load_all(): array
{
    global $pdo;
    $sql = 'SELECT id, username, email, COALESCE(display_name, username) AS display_name, role, password_hash, is_active';
    if (hub_users_column_exists('reset_token_hash')) {
        $sql .= ', reset_token_hash';
    }
    if (hub_users_column_exists('reset_token_expires_at')) {
        $sql .= ', reset_token_expires_at';
    }
    if (hub_users_column_exists('created_at')) {
        $sql .= ', created_at';
    }
    if (hub_users_column_exists('updated_at')) {
        $sql .= ', updated_at';
    }
    if (hub_users_column_exists('last_login_at')) {
        $sql .= ', last_login_at';
    }
    if (hub_users_column_exists('last_login')) {
        $sql .= ', last_login';
    }
    $sql .= ' FROM users ORDER BY username ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function hub_users_find_index_by_login(array $users, string $login): ?int
{
    $needle = mb_strtolower(trim($login));
    if ($needle === '') {
        return null;
    }
    foreach ($users as $index => $user) {
        $username = mb_strtolower(trim((string) ($user['username'] ?? '')));
        $email = mb_strtolower(trim((string) ($user['email'] ?? '')));
        if ($needle === $username || ($email !== '' && $needle === $email)) {
            return $index;
        }
    }
    return null;
}

function hub_users_find_index_by_reset_token(array $users, string $token): ?int
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

function hub_users_save_all(array $users): bool
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query('SELECT id FROM users');
        $ids = [];
        foreach ($stmt as $row) {
            $ids[] = (string) $row['id'];
        }

        // Only update fields we know are safe on the existing schema.
        $updatableColumns = ['username', 'email', 'password_hash', 'role', 'is_active'];
        foreach (['display_name', 'reset_token_hash', 'reset_token_expires_at', 'created_at', 'updated_at', 'last_login_at', 'last_login'] as $candidate) {
            if (hub_users_column_exists($candidate)) {
                $updatableColumns[] = $candidate;
            }
        }

        foreach ($users as $user) {
            $fields = [];
            $params = [':id' => (string) ($user['id'] ?? '')];
            foreach ($updatableColumns as $column) {
                if (array_key_exists($column, $user)) {
                    $fields[] = $column . ' = :' . $column;
                    $params[':' . $column] = $user[$column];
                }
            }
            if ($fields === []) {
                continue;
            }
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function hub_users_generate_reset_url(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/reset_password.php?token=' . rawurlencode($token);
}

function hub_users_send_password_reset_email(array $user, string $resetUrl): bool
{
    $to = trim((string) ($user['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Reset your Hub password';
    $message = implode("\n", [
        'You requested a password reset for Hub.',
        '',
        'Reset link:',
        $resetUrl,
        '',
        'This link expires in ' . (int) (HUB_RESET_TOKEN_TTL / 60) . ' minutes.',
    ]);

    return hub_send_mail($to, $subject, $message, false);
}

function hub_users_issue_password_reset(string $login): array
{
    $users = hub_users_load_all();
    $index = hub_users_find_index_by_login($users, $login);
    if ($index === null) {
        return [
            'ok' => true,
            'message' => 'If the account exists, a reset link has been generated.',
        ];
    }

    $token = hub_users_generate_reset_token();
    $users[$index]['reset_token_hash'] = $token['token_hash'];
    $users[$index]['reset_token_expires_at'] = $token['expires_at'];

    if (!hub_users_save_all($users)) {
        return [
            'ok' => false,
            'message' => 'Unable to save the password reset request.',
        ];
    }

    $resetUrl = hub_users_generate_reset_url($token['token']);
    $mailOk = hub_users_send_password_reset_email($users[$index], $resetUrl);
    if (!$mailOk) {
        return [
            'ok' => true,
            'message' => 'Reset link generated. Email delivery is not configured, so use this link: ' . $resetUrl,
            'user' => $users[$index],
        ];
    }

    return [
        'ok' => true,
        'message' => 'Password reset email sent.',
        'user' => $users[$index],
    ];
}

function hub_users_reset_password_by_token(string $token, string $newPassword): array
{
    if ($token === '' || $newPassword === '') {
        return [
            'ok' => false,
            'message' => 'Reset token and new password are required.',
        ];
    }

    $users = hub_users_load_all();
    $index = hub_users_find_index_by_reset_token($users, $token);
    if ($index === null) {
        return [
            'ok' => false,
            'message' => 'That reset link is invalid or has expired.',
        ];
    }

    $users[$index]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $users[$index]['reset_token_hash'] = '';
    $users[$index]['reset_token_expires_at'] = '';

    if (!hub_users_save_all($users)) {
        return [
            'ok' => false,
            'message' => 'Unable to update the password.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Password updated successfully.',
        'user' => $users[$index],
    ];
}
