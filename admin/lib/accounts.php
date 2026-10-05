<?php
declare(strict_types=1);

require_once __DIR__ . '/people.php';
require_once __DIR__ . '/season.php';
require_once __DIR__ . '/mailer.php';

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

function accountPrimaryRole(PDO $pdo, int $accountId): string
{
    $roles = accountRoleCodes($pdo, $accountId);
    foreach (['admin', 'staff', 'volunteer', 'public'] as $role) {
        if (in_array($role, $roles, true)) {
            return $role;
        }
    }
    return 'public';
}

function setAccountRole(PDO $pdo, int $accountId, string $roleCode): void
{
    if (!in_array($roleCode, ['public', 'volunteer', 'staff', 'admin'], true)) {
        throw new InvalidArgumentException('Invalid role.');
    }
    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
    $roleStmt->execute([':code' => $roleCode]);
    $roleId = (int) $roleStmt->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException('Role catalogue is missing ' . $roleCode . '.');
    }
    $pdo->prepare('DELETE FROM account_roles WHERE account_id = :account_id')->execute([':account_id' => $accountId]);
    $pdo->prepare('INSERT INTO account_roles (account_id, role_id) VALUES (:account_id, :role_id)')
        ->execute([':account_id' => $accountId, ':role_id' => $roleId]);
    identityAuditLog($pdo, 'account_role_changed', 'Set account #' . $accountId . ' role to ' . $roleCode);
}

/**
 * The role an account SHOULD have, computed rather than manually picked:
 * 'admin' only if explicitly flagged (the "Site Administrator" checkbox —
 * full, unconditional access), 'staff' if the person holds at least one
 * currently active Club Position (drives their capabilities via
 * hub_auth_has_capability()), 'public' if neither. Replaces the old
 * standalone Role picker in club_person.php — see
 * /root/.claude/plans/cheerful-soaring-mochi.md for why Role and Position
 * stay two mechanisms under the hood but only one thing the admin sets.
 */
function deriveAccountRoleForPerson(PDO $pdo, int $personId, bool $isAdmin): string
{
    if ($isAdmin) {
        return 'admin';
    }
    $positions = getCurrentPersonPositions($pdo, $personId);
    return $positions !== [] ? 'staff' : 'public';
}

/**
 * Recomputes and re-applies the derived role for this person's account (if
 * they have one) after a position add/edit/delete. Preserves an existing
 * 'admin' role untouched — the admin flag is only ever changed explicitly,
 * via the Account tab's own checkbox, never as a side effect of positions.
 */
function syncAccountRoleForPerson(PDO $pdo, int $personId): void
{
    $account = getAccountByPersonId($pdo, $personId);
    if ($account === null) {
        return;
    }
    $isAdmin = accountPrimaryRole($pdo, (int) $account['id']) === 'admin';
    setAccountRole($pdo, (int) $account['id'], deriveAccountRoleForPerson($pdo, $personId, $isAdmin));
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
    if (strlen($plainPassword) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
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

function createAccountForPerson(PDO $pdo, int $personId, string $email, string $roleCode = 'public', bool $isActive = true): int
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

    $pdo->prepare('INSERT INTO accounts (person_id, email, email_normalized, password_hash, is_active)
        VALUES (:person_id, :email, :email_normalized, NULL, :is_active)')
        ->execute([
            ':person_id' => $personId,
            ':email' => trim($email),
            ':email_normalized' => $normalized,
            ':is_active' => $isActive ? 1 : 0,
        ]);
    $accountId = (int) $pdo->lastInsertId();
    setAccountRole($pdo, $accountId, $roleCode);

    $holderId = ensureLegacyHolderForPerson($pdo, $personId);
    $pdo->prepare('UPDATE identity_migration_map SET account_id = :account_id WHERE old_holder_id = :holder_id')
        ->execute([':account_id' => $accountId, ':holder_id' => $holderId]);
    $pdo->prepare('UPDATE season_ticket_holders SET role = :role WHERE id = :id')
        ->execute([':role' => $roleCode, ':id' => $holderId]);
    identityAuditLog($pdo, 'account_created', 'Created account #' . $accountId . ' for person #' . $personId);
    return $accountId;
}

function updateAccountLogin(PDO $pdo, int $accountId, string $email, bool $isActive, string $roleCode): void
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
        setAccountRole($pdo, $accountId, $roleCode);

        $holderId = (int) ($account['old_holder_id'] ?? 0);
        if ($holderId > 0) {
            $pdo->prepare('UPDATE season_ticket_holders SET role = :role, email = :email, email_normalized = :email_normalized WHERE id = :id')
                ->execute([
                    ':role' => $roleCode,
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
    $pdo->prepare('UPDATE accounts SET is_active = :active WHERE id = :id')
        ->execute([':active' => $isActive ? 1 : 0, ':id' => $accountId]);
    identityAuditLog($pdo, $isActive ? 'account_activated' : 'account_disabled', 'Account #' . $accountId);
}

function syncAccountFromLegacyHolder(PDO $pdo, int $holderId, ?string $plainPassword = null): ?int
{
    $holder = getSeasonTicketHolder($pdo, $holderId);
    if (!$holder || empty($holder['email_normalized'])) {
        return null;
    }

    $person = getPersonByLegacyHolderId($pdo, $holderId);
    if (!$person) {
        $pdo->prepare('INSERT INTO people
            (display_name, date_of_birth, email, email_normalized, phone, address_line1, address_line2, town, postcode, country, marketing_opt_in, unsubscribe_token, unsubscribed_at, profile_image_path, is_active)
            VALUES (:display_name, :date_of_birth, :email, :email_normalized, :phone, :address_line1, :address_line2, :town, :postcode, :country, :marketing_opt_in, :unsubscribe_token, :unsubscribed_at, :profile_image_path, :is_active)')
            ->execute([
                ':display_name' => (string) $holder['name'],
                ':date_of_birth' => $holder['date_of_birth'] ?: null,
                ':email' => $holder['email'] ?: null,
                ':email_normalized' => $holder['email_normalized'] ?: null,
                ':phone' => $holder['phone'] ?: null,
                ':address_line1' => ($holder['address_line1'] ?: ($holder['address'] ?: null)),
                ':address_line2' => $holder['address_line2'] ?: null,
                ':town' => $holder['town'] ?: null,
                ':postcode' => $holder['postcode'] ?: null,
                ':country' => $holder['country'] ?: null,
                ':marketing_opt_in' => (int) ($holder['marketing_opt_in'] ?? 1),
                ':unsubscribe_token' => $holder['unsubscribe_token'] ?: null,
                ':unsubscribed_at' => $holder['unsubscribed_at'] ?: null,
                ':profile_image_path' => $holder['profile_image_path'] ?: null,
                ':is_active' => (int) ($holder['is_active'] ?? 1),
            ]);
        $personId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO identity_migration_map (old_holder_id, person_id) VALUES (:holder_id, :person_id)')
            ->execute([':holder_id' => $holderId, ':person_id' => $personId]);
    } else {
        $personId = (int) $person['id'];
        $pdo->prepare('UPDATE people SET display_name = :name, email = :email, email_normalized = :email_normalized, phone = :phone, is_active = :active WHERE id = :id')
            ->execute([
                ':name' => (string) $holder['name'],
                ':email' => $holder['email'] ?: null,
                ':email_normalized' => $holder['email_normalized'] ?: null,
                ':phone' => $holder['phone'] ?: null,
                ':active' => (int) ($holder['is_active'] ?? 1),
                ':id' => $personId,
            ]);
    }

    $account = getAccountByLegacyHolderId($pdo, $holderId);
    $passwordHash = $plainPassword !== null && $plainPassword !== ''
        ? password_hash($plainPassword, PASSWORD_DEFAULT)
        : (($holder['password_hash'] ?? '') ?: null);

    if ($account) {
        $params = [
            ':id' => (int) $account['id'],
            ':email' => $holder['email'] ?: null,
            ':email_normalized' => $holder['email_normalized'],
            ':is_active' => (int) ($holder['is_active'] ?? 1),
        ];
        $passwordSql = '';
        if ($passwordHash !== null) {
            $passwordSql = ', password_hash = :password_hash';
            $params[':password_hash'] = $passwordHash;
        }
        $pdo->prepare('UPDATE accounts SET email = :email, email_normalized = :email_normalized, is_active = :is_active' . $passwordSql . ' WHERE id = :id')
            ->execute($params);
        $accountId = (int) $account['id'];
    } else {
        $pdo->prepare('INSERT INTO accounts (person_id, email, email_normalized, password_hash, is_active)
            VALUES (:person_id, :email, :email_normalized, :password_hash, :is_active)')
            ->execute([
                ':person_id' => $personId,
                ':email' => $holder['email'] ?: null,
                ':email_normalized' => $holder['email_normalized'],
                ':password_hash' => $passwordHash,
                ':is_active' => (int) ($holder['is_active'] ?? 1),
            ]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE identity_migration_map SET account_id = :account_id WHERE old_holder_id = :holder_id')
            ->execute([':account_id' => $accountId, ':holder_id' => $holderId]);
    }

    setAccountRole($pdo, $accountId, (string) ($holder['role'] ?? 'public'));
    if ($plainPassword !== null && $plainPassword !== '') {
        $pdo->prepare('UPDATE season_ticket_holders SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $passwordHash, ':id' => $holderId]);
    }

    return $accountId;
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
        setAccountRole($pdo, $accountId, 'public');
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

function createAccountPasswordSetupLink(PDO $pdo, int $accountId, string $resetPath): string
{
    $account = getAccount($pdo, $accountId);
    if (!$account || (int) ($account['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('An active account is required before creating a setup link.');
    }
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + MEMBER_RESET_TOKEN_TTL);
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
    if ($token === '' || strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Please choose a password of at least 8 characters.'];
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT a.*, m.old_holder_id FROM accounts a LEFT JOIN identity_migration_map m ON m.account_id = a.id WHERE a.reset_token_hash = :hash LIMIT 1');
    $stmt->execute([':hash' => $tokenHash]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account || empty($account['reset_token_expires_at']) || strtotime((string) $account['reset_token_expires_at']) < time()) {
        return ['ok' => false, 'message' => 'That link is invalid or has expired. Please request a new one.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE accounts SET password_hash = :hash, reset_token_hash = NULL, reset_token_expires_at = NULL, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
        ->execute([':hash' => $hash, ':id' => $account['id']]);
    if (!empty($account['old_holder_id'])) {
        $pdo->prepare('UPDATE season_ticket_holders SET password_hash = :hash, reset_token_hash = NULL, reset_token_expires_at = NULL, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
            ->execute([':hash' => $hash, ':id' => $account['old_holder_id']]);
    }
    return ['ok' => true, 'message' => 'Your password has been set. You can now log in.'];
}
