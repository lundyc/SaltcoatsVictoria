<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Connect to the preferred hub database, then fall back to the legacy player sponsors database
 * if the new database is not yet available or the MySQL user lacks access.
 *
 * @return array{pdo: PDO, database: string, fallbackUsed: bool}
 */
function hub_connect_pdo(): array
{
    $databases = array_values(array_unique([
        DB_NAME,
        DB_FALLBACK_NAME,
    ]));

    $lastError = null;

    foreach ($databases as $index => $database) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            return [
                'pdo' => $pdo,
                'database' => $database,
                'fallbackUsed' => $index > 0,
            ];
        } catch (PDOException $e) {
            $lastError = $e;
        }
    }

    http_response_code(500);
    echo "<div style='padding:1rem;font-family:system-ui'>";
    echo "<h2>Database Connection Failed</h2>";
    echo "<pre>" . htmlspecialchars($lastError?->getMessage() ?? 'Unknown database error', ENT_QUOTES, 'UTF-8') . "</pre>";
    echo "</div>";
    exit;
}

$hubDb = hub_connect_pdo();
$pdo = $hubDb['pdo'];
$hubDatabaseName = $hubDb['database'];
$hubFallbackUsed = $hubDb['fallbackUsed'];

function hub_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL,
            display_name VARCHAR(190) NOT NULL DEFAULT '',
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL,
            UNIQUE KEY users_username_unique (username),
            UNIQUE KEY users_email_unique (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    if (!isset($columns['display_name'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(190) NOT NULL DEFAULT ''");
    }

    if (!isset($columns['reset_token_hash'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_hash VARCHAR(255) NOT NULL DEFAULT ''");
    }

    if (!isset($columns['reset_token_expires_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL");
    }

    if (!isset($columns['last_login_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL");
    }

    $playerColumns = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM players') as $row) {
            $playerColumns[(string) $row['Field']] = true;
        }

        if (!isset($playerColumns['date_of_birth'])) {
            $afterColumn = isset($playerColumns['position']) ? ' AFTER position' : ' AFTER name';
            $pdo->exec("ALTER TABLE players ADD COLUMN date_of_birth DATE NULL" . $afterColumn);
        }
    } catch (Throwable) {
        // Older installs may not have the players table yet; player pages also
        // guard their own schema before using this column.
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS social_posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id BIGINT UNSIGNED NULL,
            event_id VARCHAR(100) NOT NULL DEFAULT '',
            post_type VARCHAR(60) NOT NULL,
            platform VARCHAR(30) NOT NULL,
            caption TEXT NOT NULL,
            image_url VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            scheduled_for DATETIME NULL,
            published_at DATETIME NULL,
            external_post_id VARCHAR(190) NOT NULL DEFAULT '',
            error_message TEXT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            dedupe_key VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY social_posts_dedupe_unique (dedupe_key),
            KEY social_posts_status_schedule (status, scheduled_for),
            KEY social_posts_fixture (fixture_id, post_type),
            KEY social_posts_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * hub_ensure_schema() issues several CREATE TABLE/SHOW COLUMNS/ALTER TABLE
 * checks against MySQL. db.php is loaded on almost every request, so running
 * it unconditionally added avoidable DB round-trips to every page load.
 * Re-run it at most once per TTL window so schema drift after a deploy is
 * still picked up quickly without paying the cost on every request.
 */
$hubSchemaFlagFile = __DIR__ . '/cache/schema_ensured.flag';
$hubSchemaTtlSeconds = 300;
if (!is_file($hubSchemaFlagFile) || (time() - (int) @filemtime($hubSchemaFlagFile)) > $hubSchemaTtlSeconds) {
    hub_ensure_schema($pdo);
    @file_put_contents($hubSchemaFlagFile, (string) time(), LOCK_EX);
}
unset($hubSchemaFlagFile, $hubSchemaTtlSeconds);
