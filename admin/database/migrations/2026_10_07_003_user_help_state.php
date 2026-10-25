<?php
declare(strict_types=1);

// Remembers each account's Help progress: completed/skipped tours and whether
// the first-login prompt was postponed ('later') or switched off ('off').
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_help_state (
        account_id INT UNSIGNED NOT NULL,
        state_key VARCHAR(60) NOT NULL,
        status VARCHAR(20) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, state_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
