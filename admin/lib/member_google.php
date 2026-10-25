<?php
declare(strict_types=1);

/**
 * "Continue with Google" for the members area, plus the one-time welcome links an admin
 * sends to existing accounts so they can set a password or link Google.
 *
 * Google is optional: the buttons only appear once HUB_GOOGLE_CLIENT_ID and
 * HUB_GOOGLE_CLIENT_SECRET are set in admin/.env. Authorised redirect URI to register in
 * Google Cloud: {APP_ORIGIN}/members/google_callback.php
 *
 * Security notes: authorisation-code flow with PKCE + state + nonce; the ID token comes
 * straight from Google's token endpoint over TLS, and its iss/aud/exp/nonce are checked.
 * An account is only ever matched by Google's *verified* email, or via a valid
 * single-use welcome link (which proves the admin intended that exact account).
 */

require_once __DIR__ . '/accounts.php';

const MEMBER_WELCOME_LINK_TTL = 604800; // 7 days

function member_google_client_id(): string
{
    global $hubFileEnv;
    return hub_config_value(is_array($hubFileEnv ?? null) ? $hubFileEnv : [], 'HUB_GOOGLE_CLIENT_ID', '');
}

function member_google_client_secret(): string
{
    global $hubFileEnv;
    return hub_config_value(is_array($hubFileEnv ?? null) ? $hubFileEnv : [], 'HUB_GOOGLE_CLIENT_SECRET', '');
}

function member_google_configured(): bool
{
    return member_google_client_id() !== '' && member_google_client_secret() !== '';
}

function member_google_redirect_uri(): string
{
    return APP_ORIGIN . '/members/google_callback.php';
}

function ensureMemberGoogleSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_identities (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        provider VARCHAR(20) NOT NULL,
        subject VARCHAR(190) NOT NULL,
        email VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL,
        UNIQUE KEY uq_identity_subject (provider, subject),
        UNIQUE KEY uq_identity_account (account_id, provider)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_welcome_links (
        account_id INT UNSIGNED NOT NULL PRIMARY KEY,
        last_sent_at DATETIME NULL,
        send_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Account behind a still-valid reset/welcome token, or null. */
function member_welcome_account_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT a.*, p.display_name, m.old_holder_id
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN identity_migration_map m ON m.account_id = a.id
        WHERE a.reset_token_hash = :hash LIMIT 1');
    $stmt->execute([':hash' => hash('sha256', $token)]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account || empty($account['reset_token_expires_at']) || strtotime((string) $account['reset_token_expires_at']) < time()
        || (int) ($account['is_active'] ?? 0) !== 1) {
        return null;
    }
    return $account;
}

/** Create a fresh single-use welcome link (replaces any earlier one) and note it. */
function member_create_welcome_link(PDO $pdo, int $accountId): string
{
    ensureMemberGoogleSchema($pdo);
    $url = createAccountPasswordSetupLink($pdo, $accountId, '/members/welcome.php', MEMBER_WELCOME_LINK_TTL);
    $pdo->prepare('INSERT INTO account_welcome_links (account_id, last_created_at) VALUES (:id, NOW())
        ON DUPLICATE KEY UPDATE last_created_at = NOW()')->execute([':id' => $accountId]);
    return $url;
}

function member_mark_welcome_sent(PDO $pdo, int $accountId): void
{
    $pdo->prepare('UPDATE account_welcome_links SET last_sent_at = NOW(), send_count = send_count + 1 WHERE account_id = :id')
        ->execute([':id' => $accountId]);
}

/** Burn the token so the link can't be used again. */
function member_welcome_consume(PDO $pdo, array $account): void
{
    $pdo->prepare('UPDATE accounts SET reset_token_hash = NULL, reset_token_expires_at = NULL, email_verified_at = COALESCE(email_verified_at, NOW()), auth_version = auth_version + 1 WHERE id = :id')
        ->execute([':id' => $account['id']]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = :id')
            ->execute([':id' => (int) $account['old_holder_id']]);
    }
}

function member_google_identity_for_account(PDO $pdo, int $accountId): ?array
{
    ensureMemberGoogleSchema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM account_identities WHERE account_id = :id AND provider = 'google' LIMIT 1");
    $stmt->execute([':id' => $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function member_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Start the flow: remember state/nonce/PKCE (and the welcome account, if any) in the
 * member session and return Google's authorisation URL.
 */
function member_google_begin(?array $welcomeAccount): string
{
    member_auth_start_session();
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $verifier = member_b64url(random_bytes(48));
    $_SESSION['google_oauth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'welcome_account_id' => $welcomeAccount ? (int) $welcomeAccount['id'] : 0,
        'welcome_token_hash' => $welcomeAccount ? (string) ($welcomeAccount['reset_token_hash'] ?? '') : '',
        'created' => time(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => member_google_client_id(),
        'redirect_uri' => member_google_redirect_uri(),
        'response_type' => 'code',
        // "profile" is deliberately not requested: Google echoes it back as ".../auth/userinfo.profile" in the
        // callback URL, which the server's WAF (Comodo rule 210580) blocks as an OS-file access attempt.
        'scope' => 'openid email',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => member_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

/**
 * Finish the flow. @return array{ok:bool, error?:string, account?:array}
 */
function member_google_finish(PDO $pdo, array $query): array
{
    ensureMemberGoogleSchema($pdo);
    member_auth_start_session();
    $flow = is_array($_SESSION['google_oauth'] ?? null) ? $_SESSION['google_oauth'] : null;
    unset($_SESSION['google_oauth']);

    if (!hub_auth_rate_limit('google-cb:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'x'), 30, 900)) {
        return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.'];
    }
    if (isset($query['error'])) {
        return ['ok' => false, 'error' => 'Google sign-in was cancelled.'];
    }
    if (!$flow || (time() - (int) $flow['created']) > 600
        || !hash_equals((string) $flow['state'], (string) ($query['state'] ?? '')) || (string) ($query['code'] ?? '') === '') {
        return ['ok' => false, 'error' => 'That sign-in attempt expired. Please try again.'];
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => (string) $query['code'],
            'client_id' => member_google_client_id(),
            'client_secret' => member_google_client_secret(),
            'redirect_uri' => member_google_redirect_uri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => (string) $flow['verifier'],
        ]),
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    if ($status !== 200 || !is_array($data) || empty($data['id_token'])) {
        error_log('[google-login] token exchange failed: HTTP ' . $status);
        return ['ok' => false, 'error' => 'Google sign-in failed. Please try again.'];
    }

    // Ask Google's verification endpoint to validate the JWS signature and
    // return trusted claims. Decoding the JWT payload locally is not enough.
    $verify = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode((string) $data['id_token']));
    curl_setopt_array($verify, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $verifyResponse = curl_exec($verify);
    $verifyStatus = (int) curl_getinfo($verify, CURLINFO_RESPONSE_CODE);
    curl_close($verify);
    $claims = $verifyStatus === 200 && is_string($verifyResponse)
        ? json_decode($verifyResponse, true)
        : null;
    if (!is_array($claims)
        || !in_array((string) ($claims['iss'] ?? ''), ['https://accounts.google.com', 'accounts.google.com'], true)
        || (string) ($claims['aud'] ?? '') !== member_google_client_id()
        || (int) ($claims['exp'] ?? 0) < time()
        || !hash_equals((string) $flow['nonce'], (string) ($claims['nonce'] ?? ''))
        || (string) ($claims['sub'] ?? '') === '') {
        return ['ok' => false, 'error' => 'Google sign-in could not be verified. Please try again.'];
    }
    $sub = (string) $claims['sub'];
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $emailVerified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';

    // 1) Already linked -> that account.
    $stmt = $pdo->prepare("SELECT account_id FROM account_identities WHERE provider = 'google' AND subject = :sub LIMIT 1");
    $stmt->execute([':sub' => $sub]);
    $linkedAccountId = (int) $stmt->fetchColumn();
    if ($linkedAccountId > 0) {
        $account = getAccount($pdo, $linkedAccountId);
        if (!$account || (int) ($account['is_active'] ?? 0) !== 1 || (int) ($account['person_is_active'] ?? 1) !== 1) {
            return ['ok' => false, 'error' => 'That account is not active. Please contact the club.'];
        }
        $pdo->prepare("UPDATE account_identities SET last_login_at = NOW(), email = :e WHERE provider = 'google' AND subject = :sub")
            ->execute([':e' => $email ?: null, ':sub' => $sub]);
        return ['ok' => true, 'account' => $account];
    }

    // 2) Valid welcome link in this flow -> link to exactly that account.
    $welcomeId = (int) ($flow['welcome_account_id'] ?? 0);
    if ($welcomeId > 0) {
        $expectedTokenHash = (string) ($flow['welcome_token_hash'] ?? '');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT reset_token_expires_at
                FROM accounts
                WHERE id = :id AND reset_token_hash = :hash AND is_active = 1
                LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $welcomeId, ':hash' => $expectedTokenHash]);
            $expires = (string) $stmt->fetchColumn();
            $account = getAccount($pdo, $welcomeId);
            if ($expectedTokenHash === '' || !$account || $expires === '' || strtotime($expires) < time()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'That welcome link has already been used, replaced or has expired. Ask the club for a new one.'];
            }
            $existing = member_google_identity_for_account($pdo, $welcomeId);
            if ($existing && (string) $existing['subject'] !== $sub) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'A different Google account is already linked to this member account.'];
            }
            if (!$existing) {
                $pdo->prepare("INSERT INTO account_identities (account_id, provider, subject, email, last_login_at) VALUES (:a, 'google', :s, :e, NOW())")
                    ->execute([':a' => $welcomeId, ':s' => $sub, ':e' => $email ?: null]);
            }
            $account['old_holder_id'] = $account['old_holder_id'] ?? null;
            member_welcome_consume($pdo, $account);
            identityAuditLog($pdo, 'google_linked', 'Linked Google to account #' . $welcomeId . ' via welcome link');
            $pdo->commit();
            return ['ok' => true, 'account' => $account];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Google linking could not be completed. Please request a new link.'];
        }
    }

    // 3) Otherwise match on Google's verified email.
    if ($emailVerified && $email !== '') {
        $account = findAccountByEmail($pdo, $email);
        if ($account && (int) ($account['is_active'] ?? 0) === 1 && (int) ($account['person_is_active'] ?? 1) === 1) {
            $existing = member_google_identity_for_account($pdo, (int) $account['id']);
            if ($existing) {
                return ['ok' => false, 'error' => 'A different Google account is already linked to that member account.'];
            }
            $pdo->prepare("INSERT INTO account_identities (account_id, provider, subject, email, last_login_at) VALUES (:a, 'google', :s, :e, NOW())")
                ->execute([':a' => (int) $account['id'], ':s' => $sub, ':e' => $email]);
            $pdo->prepare('UPDATE accounts SET auth_version = auth_version + 1, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
                ->execute([':id' => (int) $account['id']]);
            identityAuditLog($pdo, 'google_linked', 'Linked Google to account #' . $account['id'] . ' by verified email');
            return ['ok' => true, 'account' => $account];
        }
    }

    return ['ok' => false, 'error' => 'We couldn\'t find a season ticket account for that Google account. Use the email you bought your ticket with, or ask the club for a welcome link.'];
}
