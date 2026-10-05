<?php
declare(strict_types=1);

/**
 * audit_logs.user_id originally pointed at the legacy `users` table. Since
 * the migration to people/accounts, callers pass the session's account id
 * instead -- so the FK silently rejected almost every insert (the account id
 * rarely matches a row in the now-unused `users` table), and the try/catch
 * around every write swallowed that failure. This repoints the FK at
 * `accounts`, the identity table sessions actually carry now, and makes the
 * column nullable for actions with no signed-in actor.
 */
function ensureAuditLogSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $fk = $pdo->query("
        SELECT kcu.CONSTRAINT_NAME, kcu.REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE kcu
        WHERE kcu.TABLE_SCHEMA = DATABASE()
          AND kcu.TABLE_NAME = 'audit_logs'
          AND kcu.COLUMN_NAME = 'user_id'
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($fk && (string) $fk['REFERENCED_TABLE_NAME'] === 'accounts') {
        return;
    }

    if ($fk) {
        $pdo->exec('ALTER TABLE audit_logs DROP FOREIGN KEY ' . $fk['CONSTRAINT_NAME']);
    }
    $pdo->exec('ALTER TABLE audit_logs MODIFY COLUMN user_id INT UNSIGNED NULL');
    // Pre-migration rows carry an ID from the old users/holder space, which
    // won't exist in accounts -- null those out rather than losing the
    // action/details/timestamp history to a failed FK add.
    $pdo->exec('UPDATE audit_logs a LEFT JOIN accounts acc ON acc.id = a.user_id SET a.user_id = NULL WHERE a.user_id IS NOT NULL AND acc.id IS NULL');
    $pdo->exec('ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_logs_account FOREIGN KEY (user_id) REFERENCES accounts(id) ON DELETE SET NULL');
}

/**
 * Canonical audit logger for the whole Hub -- resolves the acting account
 * from the session key account_auth.php's HUB_AUTH_ACCOUNT_ID_KEY writes on
 * login ('hub_account_id'), so this stays in sync without requiring
 * account_auth.php here (most callers already load it via header.php, and
 * this file is loaded from many places that don't need the rest of auth).
 * Failure is logged, not thrown -- an audit write must never block the
 * action it's describing.
 */
function auditLog(PDO $pdo, string $action, string $details = ''): void
{
    ensureAuditLogSchema($pdo);
    try {
        $accountId = (int) ($_SESSION['hub_account_id'] ?? 0) ?: null;
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (:user_id, :action, :details)');
        $stmt->execute([':user_id' => $accountId, ':action' => $action, ':details' => $details]);
    } catch (Throwable $e) {
        error_log('auditLog failed for action "' . $action . '": ' . $e->getMessage());
    }
}
