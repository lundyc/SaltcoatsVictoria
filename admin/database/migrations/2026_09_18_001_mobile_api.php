<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // Additive only. No website/account/ticket tables are changed.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_api_sessions (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        access_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        device_name VARCHAR(100) NOT NULL,
        access_expires_at DATETIME NOT NULL,
        refresh_expires_at DATETIME NOT NULL,
        absolute_expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY uq_mobile_access (access_hash),
        KEY idx_mobile_account (account_id, revoked_at),
        KEY idx_mobile_expiry (refresh_expires_at),
        CONSTRAINT fk_mobile_session_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_api_refresh_tokens (
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        consumed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY idx_mobile_refresh_session (session_id),
        CONSTRAINT fk_mobile_refresh_session FOREIGN KEY (session_id) REFERENCES mobile_api_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_api_rate_limits (
        bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        hits INT UNSIGNED NOT NULL DEFAULT 1,
        expires_at DATETIME NOT NULL,
        KEY idx_mobile_rate_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_api_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NULL,
        event VARCHAR(60) NOT NULL,
        request_id CHAR(32) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_mobile_audit_created (created_at),
        KEY idx_mobile_audit_account (account_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
