<?php
declare(strict_types=1);

namespace MyClubHub\Api;

final class Auth
{
    public const ACCESS_SECONDS = 900;
    public const REFRESH_SECONDS = 2592000;
    public const ABSOLUTE_SECONDS = 7776000;
    // Constant dummy hash prevents fast user-not-found password checks.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(private \PDO $pdo, private string $requestId) {}

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    public function audit(string $event, ?int $accountId = null): void
    {
        $this->execute('INSERT INTO mobile_api_audit (account_id,event,request_id,created_at) VALUES (?,?,?,UTC_TIMESTAMP())',
            [$accountId, $event, $this->requestId]);
    }

    public function rateLimit(string $bucket, int $limit, int $window): void
    {
        // The bucket identifies a time window; increment and check under a row lock.
        $hash = hash('sha256', $bucket . ':' . intdiv(time(), $window));
        $expires = gmdate('Y-m-d H:i:s', (intdiv(time(), $window) + 1) * $window);
        $this->pdo->beginTransaction();
        try {
            $this->execute('INSERT INTO mobile_api_rate_limits (bucket_hash,hits,expires_at) VALUES (?,1,?)
                ON DUPLICATE KEY UPDATE hits = hits + 1', [$hash, $expires]);
            $row = $this->one('SELECT hits FROM mobile_api_rate_limits WHERE bucket_hash = ? FOR UPDATE', [$hash]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        if ((int) $row['hits'] > $limit) {
            throw new ApiError(429, 'rate_limited', 'Too many requests. Please try again later.',
                ['Retry-After' => (string) max(1, $window - time() % $window)]);
        }
    }

    public function login(array $input, string $ip): array
    {
        Input::only($input, ['email', 'password', 'device_name']);
        $email = strtolower(Input::text($input, 'email', 190));
        $password = Input::text($input, 'password', 1024, true, false);
        $name = Input::text($input, 'device_name', 100, false) ?: 'Mobile app';
        $this->rateLimit('login-ip:' . $ip, 30, 900);
        $this->rateLimit('login-account:' . $email, 10, 900);
        $account = $this->one('SELECT a.id, a.person_id, a.password_hash, a.is_active, p.is_active AS person_active
            FROM accounts a JOIN people p ON p.id = a.person_id WHERE a.email_normalized = ? LIMIT 1', [$email]);
        $valid = password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH);
        if (!$valid || !$account || !(int) $account['is_active'] || !(int) $account['person_active']) {
            $this->audit('login_failed');
            throw new ApiError(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        $this->pdo->beginTransaction();
        try {
            // Recheck after locking, so a concurrent password reset cannot issue a session.
            $locked = $this->one('SELECT a.password_hash, a.is_active, p.is_active AS person_active
                FROM accounts a JOIN people p ON p.id = a.person_id WHERE a.id = ? FOR UPDATE', [$account['id']]);
            if (!$locked || $locked['password_hash'] !== $account['password_hash'] || !(int) $locked['is_active'] || !(int) $locked['person_active']) {
                throw new ApiError(401, 'invalid_credentials', 'Email or password is incorrect.');
            }
            $sessionId = bin2hex(random_bytes(16));
            $tokens = $this->tokens();
            $this->execute('INSERT INTO mobile_api_sessions
                (id,account_id,access_hash,credential_hash,device_name,access_expires_at,refresh_expires_at,absolute_expires_at,created_at,last_used_at)
                VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [
                    $sessionId, $account['id'], hash('sha256', $tokens['access_token']), hash('sha256', $account['password_hash']), $name,
                    gmdate('Y-m-d H:i:s', time() + self::ACCESS_SECONDS),
                    gmdate('Y-m-d H:i:s', time() + self::REFRESH_SECONDS),
                    gmdate('Y-m-d H:i:s', time() + self::ABSOLUTE_SECONDS),
                ]);
            $this->storeRefresh($sessionId, $tokens['refresh_token']);
            $this->execute('UPDATE accounts SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$account['id']]);
            $this->audit('login', (int) $account['id']);
            $this->pdo->commit();
            return $tokens + ['session_id' => $sessionId];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function tokens(int $refreshSeconds = self::REFRESH_SECONDS): array
    {
        return ['token_type' => 'Bearer', 'access_token' => 'mch_a_' . bin2hex(random_bytes(32)),
            'refresh_token' => 'mch_r_' . bin2hex(random_bytes(32)),
            'expires_in' => min(self::ACCESS_SECONDS, $refreshSeconds), 'refresh_expires_in' => $refreshSeconds];
    }

    private function storeRefresh(string $sessionId, string $token): void
    {
        $this->execute('INSERT INTO mobile_api_refresh_tokens (token_hash,session_id,created_at) VALUES (?,?,UTC_TIMESTAMP())',
            [hash('sha256', $token), $sessionId]);
    }

    private function validSession(array $row): bool
    {
        return $row['revoked_at'] === null && (int) $row['is_active'] === 1 && (int) $row['person_active'] === 1
            && hash_equals($row['credential_hash'], hash('sha256', (string) $row['password_hash']))
            && $row['refresh_expires_at'] > gmdate('Y-m-d H:i:s')
            && $row['absolute_expires_at'] > gmdate('Y-m-d H:i:s');
    }

    public function authenticate(string $token): array
    {
        $row = $this->one('SELECT s.*, a.person_id, a.password_hash, a.is_active, p.is_active AS person_active
            FROM mobile_api_sessions s JOIN accounts a ON a.id = s.account_id JOIN people p ON p.id = a.person_id
            WHERE s.access_hash = ? LIMIT 1', [hash('sha256', $token)]);
        if (!$row || !$this->validSession($row) || $row['access_expires_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new ApiError(401, 'unauthenticated', 'The access token is invalid or expired.', ['WWW-Authenticate' => 'Bearer']);
        }
        $this->execute('UPDATE mobile_api_sessions SET last_used_at = UTC_TIMESTAMP()
            WHERE id = ? AND last_used_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)', [$row['id']]);
        return ['session_id' => $row['id'], 'account_id' => (int) $row['account_id'], 'person_id' => (int) $row['person_id']];
    }

    public function refresh(array $input, string $ip): array
    {
        Input::only($input, ['refresh_token']);
        $this->rateLimit('refresh-ip:' . $ip, 60, 900);
        $token = Input::text($input, 'refresh_token', 70);
        if (!preg_match('/^mch_r_[a-f0-9]{64}$/D', $token)) {
            throw new ApiError(401, 'invalid_refresh_token', 'The refresh token is invalid or expired.');
        }
        $this->pdo->beginTransaction();
        try {
            $row = $this->one('SELECT s.*, r.consumed_at, a.person_id, a.password_hash, a.is_active, p.is_active AS person_active
                FROM mobile_api_refresh_tokens r JOIN mobile_api_sessions s ON s.id = r.session_id
                JOIN accounts a ON a.id = s.account_id JOIN people p ON p.id = a.person_id
                WHERE r.token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if (!$row || !$this->validSession($row) || $row['consumed_at'] !== null) {
                if ($row) {
                    $this->execute('UPDATE mobile_api_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
                    $this->audit($row['consumed_at'] !== null ? 'refresh_replay' : 'refresh_rejected', (int) $row['account_id']);
                }
                $this->pdo->commit(); // Persist revocation even though the request fails.
                throw new ApiError(401, 'invalid_refresh_token', 'The refresh token is invalid or expired. Sign in again.');
            }
            $remaining = min(self::REFRESH_SECONDS, strtotime($row['absolute_expires_at'] . ' UTC') - time());
            $tokens = $this->tokens($remaining);
            $this->execute('UPDATE mobile_api_refresh_tokens SET consumed_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
            $this->storeRefresh($row['id'], $tokens['refresh_token']);
            $this->execute('UPDATE mobile_api_sessions SET access_hash = ?, access_expires_at = ?, refresh_expires_at = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?',
                [hash('sha256', $tokens['access_token']), gmdate('Y-m-d H:i:s', time() + $tokens['expires_in']),
                    gmdate('Y-m-d H:i:s', time() + $remaining), $row['id']]);
            $this->audit('refresh', (int) $row['account_id']);
            $this->pdo->commit();
            return $tokens + ['session_id' => $row['id']];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function revoke(array $actor, ?string $sessionId, bool $all = false): void
    {
        $this->pdo->beginTransaction();
        try {
            if (!$all && !$this->one('SELECT id FROM mobile_api_sessions WHERE id = ? AND account_id = ? FOR UPDATE', [$sessionId, $actor['account_id']])) {
                throw new ApiError(404, 'not_found', 'Session not found.');
            }
            $this->execute('UPDATE mobile_api_sessions SET revoked_at = UTC_TIMESTAMP() WHERE account_id = ?' . ($all ? '' : ' AND id = ?'),
                $all ? [$actor['account_id']] : [$actor['account_id'], $sessionId]);
            $this->audit($all ? 'logout_all' : 'session_revoked', $actor['account_id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function maintenance(): void
    {
        // Bounded cleanup; called occasionally, no additional scheduled job needed.
        $this->execute('DELETE FROM mobile_api_rate_limits WHERE expires_at < UTC_TIMESTAMP() LIMIT 500');
        $this->execute('DELETE FROM mobile_api_sessions WHERE refresh_expires_at < UTC_TIMESTAMP()
            OR revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT 100');
        $this->execute('DELETE FROM mobile_api_audit WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY) LIMIT 500');
    }
}
