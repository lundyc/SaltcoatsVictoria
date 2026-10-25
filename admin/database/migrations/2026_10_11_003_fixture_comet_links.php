<?php
declare(strict_types=1);

// Remembers which COMET match page belongs to each of our fixtures, so a match report
// can be fetched later without searching for it. Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fixture_comet_links (
        fixture_id INT UNSIGNED NOT NULL PRIMARY KEY,
        comet_match_id BIGINT UNSIGNED NOT NULL,
        url VARCHAR(255) NOT NULL,
        last_fetched_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_comet_match (comet_match_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
