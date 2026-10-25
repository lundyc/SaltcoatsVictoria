<?php
declare(strict_types=1);

require_once __DIR__ . '/people.php';
require_once __DIR__ . '/season.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/access_roles.php';

function account_normalize_email(?string $email): ?string
{
    return people_normalize_email($email);
}

function getAccount(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare("SELECT a.*, p.display_name, p.is_active AS person_is_active, m.old_holder_id
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN identity_migration_map m ON m.account_id = a.id
        WHERE a.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getAccountByLegacyHolderId(PDO $pdo, int $holderId): ?array
{
    $stmt = $pdo->prepare("SELECT a.*, p.display_name, p.is_active AS person_is_active, m.old_holder_id
        FROM identity_migration_map m
        JOIN accounts a ON a.id = m.account_id
        JOIN people p ON p.id = a.person_id
        WHERE m.old_holder_id = :holder_id
        LIMIT 1");
    $stmt->execute([':holder_id' => $holderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getAccountByPersonId(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare("SELECT a.*, p.display_name, p.is_active AS person_is_active, m.old_holder_id
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN identity_migration_map m ON m.account_id = a.id
        WHERE a.person_id = :person_id
        LIMIT 1");
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function accountIdFromPersonId(PDO $pdo, int $personId): ?int
{
    $account = getAccountByPersonId($pdo, $personId);
    return $account ? (int) $account['id'] : null;
}

function personIdFromAccountId(PDO $pdo, int $accountId): ?int
{
    $account = getAccount($pdo, $accountId);
    return $account ? (int) $account['person_id'] : null;
}

function findAccountByEmail(PDO $pdo, string $email): ?array
{
    $normalized = account_normalize_email($email);
    if ($normalized === null) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT a.*, p.display_name, p.is_active AS person_is_active, m.old_holder_id
        FROM accounts a
        JOIN people p ON p.id = a.person_id
        LEFT JOIN identity_migration_map m ON m.account_id = a.id
        WHERE a.email_normalized = :email
        LIMIT 1");
    $stmt->execute([':email' => $normalized]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @return list<string>
 */
function accountRoleCodes(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare('SELECT r.code
        FROM account_roles ar
        JOIN roles r ON r.id = ar.role_id
        WHERE ar.account_id = :account_id
        ORDER BY r.code');
    $stmt->execute([':account_id' => $accountId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The legacy role code (admin / staff / public) for an account, derived from the
 * person's access template. The access templates are the source of truth; this
 * keeps the old code values that the session and member tiers still expect.
 */
function accountPrimaryRole(PDO $pdo, int $accountId): string
{
    $stmt = $pdo->prepare('SELECT person_id FROM accounts WHERE id = :id');
    $stmt->execute([':id' => $accountId]);
    $personId = (int) $stmt->fetchColumn();
    $primary = $personId > 0 ? getPersonPrimaryAccessRole($pdo, $personId) : null;
    return $primary === null ? 'public' : compatibilityAccountRoleForAccessRole($primary);
}

/**
 * SQL list of a person's legacy role codes, for list pages in place of the old
 * account_roles join. Needs the `p` (people) alias in the enclosing query.
 */
function accountRoleCodesSql(): string
{
    return "COALESCE((SELECT GROUP_CONCAT(DISTINCT CASE WHEN t.bypass_all = 1 THEN 'admin' WHEN t.slug = 'member' THEN 'public' ELSE 'staff' END SEPARATOR ',')
        FROM person_access_templates pat JOIN access_templates t ON t.id = pat.template_id
        WHERE pat.person_id = p.id), IF(EXISTS(SELECT 1 FROM accounts x WHERE x.person_id = p.id AND x.is_active = 1), 'public', NULL))";
}

/** Legacy mirror only; access_templates is the authority. */
function compatibilityAccountRoleForAccessRole(array $accessRole): string
{
    if ((int) ($accessRole['bypass_all'] ?? 0) === 1) {
        return 'admin';
    }
    return (string) ($accessRole['slug'] ?? '') === 'member' ? 'public' : 'staff';
}

/** Prevent disabling/demoting the final active Administrator. Caller holds a transaction. */
function assertPersonMayLoseLoginOrAdministrator(PDO $pdo, int $personId): void
{
    $stmt = $pdo->query(
        'SELECT pat.person_id
         FROM person_access_templates pat
         JOIN access_templates t ON t.id = pat.template_id AND t.bypass_all = 1
         JOIN people p ON p.id = pat.person_id AND p.is_active = 1
         JOIN accounts a ON a.person_id = pat.person_id AND a.is_active = 1
         FOR UPDATE'
    );
    $activeAdministratorIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    if (in_array($personId, $activeAdministratorIds, true)
        && count(array_diff($activeAdministratorIds, [$personId])) === 0) {
        throw new RuntimeException('The final active Administrator cannot be disabled or removed. Assign another Administrator first.');
    }
}

function setPersonLoginEnabled(PDO $pdo, int $personId, bool $enabled): void
{
    $account = getAccountByPersonId($pdo, $personId);
    if (!$account) {
        throw new RuntimeException('This person does not have a login.');
    }
    if (!$enabled) {
        assertPersonMayLoseLoginOrAdministrator($pdo, $personId);
    }
    $pdo->prepare('UPDATE accounts
        SET is_active = :active, login_disabled_manually = :manual,
            auth_version = auth_version + 1,
            reset_token_hash = CASE WHEN :active = 0 THEN NULL ELSE reset_token_hash END,
            reset_token_expires_at = CASE WHEN :active = 0 THEN NULL ELSE reset_token_expires_at END
        WHERE id = :id')->execute([
            ':active' => $enabled ? 1 : 0,
            ':manual' => $enabled ? 0 : 1,
            ':id' => (int) $account['id'],
        ]);
    identityAuditLog($pdo, $enabled ? 'account_activated' : 'account_disabled', ($enabled ? 'Enabled' : 'Disabled') . ' login for person #' . $personId);
}

function updateAccountPasswordHash(PDO $pdo, int $accountId, string $passwordHash): void
{
    if ($passwordHash === '') {
        throw new InvalidArgumentException('Password hash is required.');
    }

    $account = getAccount($pdo, $accountId);
    if (!$account) {
        throw new RuntimeException('Account not found.');
    }

    $pdo->prepare('UPDATE accounts SET password_hash = :hash WHERE id = :id')
        ->execute([':hash' => $passwordHash, ':id' => $accountId]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $passwordHash, ':id' => (int) $account['old_holder_id']]);
    }
}

/**
 * Admin sets a password directly, no reset link/email involved. Clears any
 * pending reset token so a stale link can't be used alongside the new
 * password.
 */
function setAccountPassword(PDO $pdo, int $accountId, string $plainPassword): void
{
    if (strlen($plainPassword) < 12 || strlen($plainPassword) > 200) {
        throw new InvalidArgumentException('Password must be between 12 and 200 characters.');
    }
    $account = getAccount($pdo, $accountId);
    if (!$account) {
        throw new RuntimeException('Account not found.');
    }
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE accounts SET password_hash = :hash, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = :id')
        ->execute([':hash' => $hash, ':id' => $accountId]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET password_hash = :hash, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = :id')
            ->execute([':hash' => $hash, ':id' => (int) $account['old_holder_id']]);
    }
    identityAuditLog($pdo, 'account_password_set', 'Admin set a new password for account #' . $accountId);
}

function createAccountForPerson(PDO $pdo, int $personId, string $email, bool $isActive = true): int
{
    $person = getPerson($pdo, $personId);
    if (!$person) {
        throw new RuntimeException('Person not found.');
    }
    if (getAccountByPersonId($pdo, $personId)) {
        throw new RuntimeException('This person already has a Hub account.');
    }
    $normalized = account_normalize_email($email);
    if ($normalized === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid login email is required.');
    }
    if (findAccountByEmail($pdo, $email)) {
        throw new RuntimeException('That login email is already used by another account.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT INTO accounts (person_id, email, email_normalized, password_hash, is_active)
            VALUES (:person_id, :email, :email_normalized, NULL, :is_active)')
            ->execute([
                ':person_id' => $personId,
                ':email' => trim($email),
                ':email_normalized' => $normalized,
                ':is_active' => $isActive ? 1 : 0,
            ]);
        $accountId = (int) $pdo->lastInsertId();

        $holderId = ensureLegacyHolderForPerson($pdo, $personId);
        $pdo->prepare('UPDATE identity_migration_map SET account_id = :account_id WHERE old_holder_id = :holder_id')
            ->execute([':account_id' => $accountId, ':holder_id' => $holderId]);
        identityAuditLog($pdo, 'account_created', 'Created account #' . $accountId . ' for person #' . $personId);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $accountId;
    } catch (PDOException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $e->getCode() === '23000') {
            throw new RuntimeException('That email is already used for another login.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Reconcile the login identity with the person's email. An email means an
 * active Member login; no email means the retained account is disabled.
 * Returns the resulting account, or null when the person has no account.
 */
function reconcilePersonLogin(PDO $pdo, int $personId, ?string $email): ?array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $account = reconcilePersonLoginWithinTransaction($pdo, $personId, $email);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $account;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @internal Caller must already be inside the reconciliation transaction. */
function reconcilePersonLoginWithinTransaction(PDO $pdo, int $personId, ?string $email): ?array
{
    $person = getPerson($pdo, $personId);
    if (!$person) {
        throw new RuntimeException('Person not found.');
    }

    $email = trim((string) $email);
    $normalized = account_normalize_email($email);
    if ($email !== '' && ($normalized === null || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    $account = getAccountByPersonId($pdo, $personId);
    if ($email === '' || (int) ($person['is_active'] ?? 0) !== 1) {
        if ($account && (int) $account['is_active'] === 1) {
            assertPersonMayLoseLoginOrAdministrator($pdo, $personId);
            $pdo->prepare('UPDATE accounts
                SET is_active = 0, login_disabled_manually = 0, auth_version = auth_version + 1,
                    reset_token_hash = NULL, reset_token_expires_at = NULL
                WHERE id = :id')->execute([':id' => (int) $account['id']]);
            $pdo->prepare('DELETE FROM account_identities WHERE account_id = :id')
                ->execute([':id' => (int) $account['id']]);
            identityAuditLog($pdo, 'account_disabled', 'Disabled login for person #' . $personId . ' because their email was removed or person was inactive');
        }
        return $account ? getAccount($pdo, (int) $account['id']) : null;
    }

    $conflict = findAccountByEmail($pdo, $email);
    if ($conflict && (!$account || (int) $conflict['id'] !== (int) $account['id'])) {
        throw new RuntimeException('That email is already used for another login.');
    }

    if (!$account) {
        $accountId = createAccountForPerson($pdo, $personId, $email, true);
        $pdo->prepare(
            "INSERT INTO person_access_templates (person_id, template_id)
             SELECT :person, id FROM access_templates WHERE slug = 'member'
             ON DUPLICATE KEY UPDATE template_id = template_id"
        )->execute([':person' => $personId]);
        identityAuditLog($pdo, 'member_login_created', 'Created Member login for person #' . $personId);
        return getAccount($pdo, $accountId);
    }

    $changed = (string) $account['email_normalized'] !== $normalized;
    $reactivated = (int) $account['is_active'] !== 1 && (int) ($account['login_disabled_manually'] ?? 0) !== 1;
    if ($changed || $reactivated) {
        $pdo->prepare('UPDATE accounts
            SET email = :email, email_normalized = :normalized,
                is_active = CASE WHEN login_disabled_manually = 1 THEN 0 ELSE 1 END,
                email_verified_at = CASE WHEN :changed = 1 THEN NULL ELSE email_verified_at END,
                reset_token_hash = NULL, reset_token_expires_at = NULL,
                auth_version = auth_version + 1
            WHERE id = :id')->execute([
                ':email' => $email,
                ':normalized' => $normalized,
                ':changed' => $changed ? 1 : 0,
                ':id' => (int) $account['id'],
            ]);
        if ($changed) {
            $pdo->prepare('DELETE FROM account_identities WHERE account_id = :id')
                ->execute([':id' => (int) $account['id']]);
            identityAuditLog($pdo, 'account_login_email_changed', 'Changed login email and unlinked external identities for account #' . (int) $account['id']);
        } else {
            identityAuditLog($pdo, 'account_activated', 'Reactivated login for person #' . $personId);
        }
    }

    $pdo->prepare(
        "INSERT INTO person_access_templates (person_id, template_id)
         SELECT :person, id FROM access_templates WHERE slug = 'member'
         ON DUPLICATE KEY UPDATE template_id = template_id"
    )->execute([':person' => $personId]);
    return getAccount($pdo, (int) $account['id']);
}

function updateAccountLogin(PDO $pdo, int $accountId, string $email, bool $isActive): void
{
    $account = getAccount($pdo, $accountId);
    if (!$account) {
        throw new RuntimeException('Account not found.');
    }
    $normalized = account_normalize_email($email);
    if ($normalized === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid login email is required.');
    }
    $existing = findAccountByEmail($pdo, $email);
    if ($existing && (int) $existing['id'] !== $accountId) {
        throw new RuntimeException('That login email is already used by another account.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $oldEmail = (string) ($account['email'] ?? '');
        $pdo->prepare('UPDATE accounts SET email = :email, email_normalized = :email_normalized, is_active = :is_active WHERE id = :id')
            ->execute([
                ':email' => trim($email),
                ':email_normalized' => $normalized,
                ':is_active' => $isActive ? 1 : 0,
                ':id' => $accountId,
            ]);
        $pdo->prepare('UPDATE people SET email = ?, email_normalized = ? WHERE id = ?')
            ->execute([trim($email), $normalized, (int) $account['person_id']]);

        $holderId = (int) ($account['old_holder_id'] ?? 0);
        if ($holderId > 0) {
            $pdo->prepare('UPDATE season_ticket_holders SET email = :email, email_normalized = :email_normalized WHERE id = :id')
                ->execute([
                    ':email' => trim($email),
                    ':email_normalized' => $normalized,
                    ':id' => $holderId,
                ]);
        }
        if ($oldEmail !== trim($email)) {
            identityAuditLog($pdo, 'account_login_email_changed', 'Changed login email for account #' . $accountId);
        }
        identityAuditLog($pdo, $isActive ? 'account_activated' : 'account_disabled', 'Updated account #' . $accountId);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

}

function setAccountActive(PDO $pdo, int $accountId, bool $isActive): void
{
    $account = getAccount($pdo, $accountId);
    if (!$account) {
        throw new RuntimeException('Account not found.');
    }
    $pdo->prepare('UPDATE accounts SET is_active = :active, auth_version = auth_version + 1 WHERE id = :id')
        ->execute([':active' => $isActive ? 1 : 0, ':id' => $accountId]);
    identityAuditLog($pdo, $isActive ? 'account_activated' : 'account_disabled', 'Account #' . $accountId);
}

function authenticateAccount(PDO $pdo, string $email, string $password): ?array
{
    $account = findAccountByEmail($pdo, $email);
    $hash = (string) ($account['password_hash'] ?? '');
    if (!$account || (int) ($account['is_active'] ?? 0) !== 1 || (int) ($account['person_is_active'] ?? 0) !== 1 || $hash === '') {
        return null;
    }
    if (!password_verify($password, $hash)) {
        return null;
    }
    $pdo->prepare('UPDATE accounts SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $account['id']]);
    $holderId = (int) ($account['old_holder_id'] ?? 0);
    if ($holderId > 0) {
        $pdo->prepare('UPDATE season_ticket_holders SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $holderId]);
    }
    $account['role'] = accountPrimaryRole($pdo, (int) $account['id']);
    return $account;
}

/**
 * @return array{ok:bool,error?:string,account_id?:int,person_id?:int,holder_id?:int}
 */
function registerPublicAccount(PDO $pdo, array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    if ($name === '') {
        return ['ok' => false, 'error' => 'Enter your name.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if ($password !== '' && strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    if (findAccountByEmail($pdo, $email)) {
        return ['ok' => false, 'error' => 'An account already exists for this email. Please log in instead.'];
    }

    $pdo->beginTransaction();
    try {
        $existingHolder = findSeasonTicketHolderByEmail($pdo, $email);
        if ($existingHolder && !empty($existingHolder['password_hash'])) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'An account already exists for this email. Please log in instead.'];
        }

        $personId = 0;
        if ($existingHolder) {
            $mappedPersonId = personIdFromLegacyHolderId($pdo, (int) $existingHolder['id']);
            $personId = $mappedPersonId ?? 0;
        }

        if ($personId <= 0) {
            $personId = createPerson($pdo, [
                'display_name' => $name,
                'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
                'email' => $email,
                'phone' => (string) ($data['phone'] ?? ''),
                'address_line1' => (string) ($data['address'] ?? ''),
                'marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
                'is_active' => 1,
            ]);
        } else {
            updatePerson($pdo, $personId, [
                'display_name' => $name,
                'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
                'email' => $email,
                'phone' => (string) ($data['phone'] ?? ''),
                'address_line1' => (string) ($data['address'] ?? ''),
                'marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
                'is_active' => 1,
            ], false);
        }

        $holderId = ensureLegacyHolderForPerson($pdo, $personId, [
            'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
            'email' => $email,
            'phone' => (string) ($data['phone'] ?? ''),
            'address_line1' => (string) ($data['address'] ?? ''),
            'marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        ]);

        $pdo->prepare('INSERT INTO accounts
            (person_id, email, email_normalized, password_hash, is_active)
            VALUES (:person_id, :email, :email_normalized, :password_hash, 1)')
            ->execute([
                ':person_id' => $personId,
                ':email' => $email,
                ':email_normalized' => account_normalize_email($email),
                ':password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            ]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE identity_migration_map SET account_id = :account WHERE old_holder_id = :holder')
            ->execute([':account' => $accountId, ':holder' => $holderId]);

        $pdo->commit();
        return ['ok' => true, 'account_id' => $accountId, 'person_id' => $personId, 'holder_id' => $holderId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, message: string, reset_url?: string}
 */
function issueAccountPasswordReset(PDO $pdo, string $email, string $resetPath): array
{
    $genericMessage = 'If that email is on file, a link has been sent.';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!hub_auth_rate_limit('pwreset-ip:' . $ip, 20, 3600)) {
        return ['ok' => true, 'message' => $genericMessage];
    }

    $account = findAccountByEmail($pdo, $email);
    if (!$account || (int) ($account['is_active'] ?? 0) !== 1) {
        return ['ok' => true, 'message' => $genericMessage];
    }

    if (!hub_auth_rate_limit('pwreset-account:' . $account['id'], 5, 3600)) {
        return ['ok' => true, 'message' => $genericMessage];
    }

    $token = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE accounts SET reset_token_hash = :hash, reset_token_expires_at = :expires WHERE id = :id')
        ->execute([
            ':hash' => hash('sha256', $token),
            ':expires' => date('Y-m-d H:i:s', time() + MEMBER_RESET_TOKEN_TTL),
            ':id' => $account['id'],
        ]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET reset_token_hash = :hash, reset_token_expires_at = :expires WHERE id = :id')
            ->execute([
                ':hash' => hash('sha256', $token),
                ':expires' => date('Y-m-d H:i:s', time() + MEMBER_RESET_TOKEN_TTL),
                ':id' => $account['old_holder_id'],
            ]);
    }

    $resetUrl = APP_ORIGIN . $resetPath . '?token=' . rawurlencode($token);
    $message = implode("\n", [
        'Hi ' . (string) $account['display_name'] . ',',
        '',
        'Use this link to set your Hub account password:',
        $resetUrl,
        '',
        'This link expires in ' . (int) (MEMBER_RESET_TOKEN_TTL / 60) . ' minutes.',
        '',
        'If you did not request this, you can ignore this email.',
    ]);

    $sent = hub_send_mail((string) $account['email'], 'Set your Hub account password', $message, false);
    if (!$sent) {
        // Never surface the reset link/message text to an unauthenticated
        // caller: this function is reached from the public forgot-password
        // form, and doing so would let anyone with an active account's
        // email obtain its reset credential whenever mail delivery fails.
        // The link is still returned in `reset_url` for authenticated,
        // admin-initiated callers (club_person.php's "email setup link"
        // action) to hand to the member directly; only the generic
        // `message` is safe to echo back to an anonymous requester.
        identityAuditLog($pdo, 'password_reset_mail_failed', 'Reset email delivery failed for account #' . $account['id']);
        return ['ok' => true, 'message' => $genericMessage, 'reset_url' => $resetUrl];
    }
    return ['ok' => true, 'message' => $genericMessage];
}

function createAccountPasswordSetupLink(PDO $pdo, int $accountId, string $resetPath, ?int $ttlSeconds = null): string
{
    $account = getAccount($pdo, $accountId);
    if (!$account || (int) ($account['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('An active account is required before creating a setup link.');
    }
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + ($ttlSeconds ?? MEMBER_RESET_TOKEN_TTL));
    $hash = hash('sha256', $token);
    $pdo->prepare('UPDATE accounts SET reset_token_hash = :hash, reset_token_expires_at = :expires WHERE id = :id')
        ->execute([':hash' => $hash, ':expires' => $expires, ':id' => $accountId]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET reset_token_hash = :hash, reset_token_expires_at = :expires WHERE id = :id')
            ->execute([':hash' => $hash, ':expires' => $expires, ':id' => (int) $account['old_holder_id']]);
    }
    identityAuditLog($pdo, 'password_reset_initiated', 'Created setup link for account #' . $accountId);
    return APP_ORIGIN . $resetPath . '?token=' . rawurlencode($token);
}

/**
 * @return array{ok: bool, message: string}
 */
function resetAccountPasswordByToken(PDO $pdo, string $token, string $newPassword): array
{
    if ($token === '' || strlen($newPassword) < 12 || strlen($newPassword) > 200) {
        return ['ok' => false, 'message' => 'Please choose a password between 12 and 200 characters.'];
    }

    $tokenHash = hash('sha256', $token);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT a.*, m.old_holder_id
            FROM accounts a
            JOIN people p ON p.id = a.person_id
            LEFT JOIN identity_migration_map m ON m.account_id = a.id
            WHERE a.reset_token_hash = :hash AND a.is_active = 1 AND p.is_active = 1
            LIMIT 1 FOR UPDATE');
        $stmt->execute([':hash' => $tokenHash]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || empty($account['reset_token_expires_at']) || strtotime((string) $account['reset_token_expires_at']) < time()) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'That link is invalid or has expired. Please request a new one.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $consume = $pdo->prepare('UPDATE accounts
            SET password_hash = :password_hash, reset_token_hash = NULL, reset_token_expires_at = NULL,
                email_verified_at = COALESCE(email_verified_at, NOW()), auth_version = auth_version + 1
            WHERE id = :id AND reset_token_hash = :token_hash');
        $consume->execute([
            ':password_hash' => $hash,
            ':id' => (int) $account['id'],
            ':token_hash' => $tokenHash,
        ]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('That link has already been used.');
        }
        if (!empty($account['old_holder_id'])) {
            $pdo->prepare('UPDATE season_ticket_holders SET password_hash = :hash, reset_token_hash = NULL, reset_token_expires_at = NULL, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
                ->execute([':hash' => $hash, ':id' => $account['old_holder_id']]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'message' => 'Your password has been set. You can now log in.'];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $e->getMessage() === 'That link has already been used.'
            ? $e->getMessage()
            : 'That link could not be used. Please request a new one.'];
    }
}
