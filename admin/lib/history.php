<?php

declare(strict_types=1);

/**
 * Historical archive tables for the public site: results going back to 1999,
 * the all-time appearances/goals leaderboard, and the photo-gallery albums —
 * all imported from the old saltcoatsvictoria.co.uk backup by
 * tools/import_svfc_history.php and never touched by the live Hub match system.
 *
 * Schema self-heals on first use (Hub convention); there is no formal
 * migration because none of this feeds Hub operations.
 */

function history_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS history_matches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(180) NOT NULL,
        match_date DATE NULL,
        season VARCHAR(20) NOT NULL DEFAULT '',
        competition VARCHAR(190) NOT NULL DEFAULT '',
        is_home TINYINT(1) NOT NULL DEFAULT 1,
        opponent VARCHAR(190) NOT NULL DEFAULT '',
        opponent_badge VARCHAR(255) NOT NULL DEFAULT '',
        home_score SMALLINT NULL,
        away_score SMALLINT NULL,
        score_line VARCHAR(30) NOT NULL DEFAULT '',
        result CHAR(1) NULL,
        venue VARCHAR(190) NOT NULL DEFAULT '',
        scorers_text TEXT NULL,
        report_html MEDIUMTEXT NULL,
        report_by VARCHAR(120) NOT NULL DEFAULT '',
        has_report TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_history_matches_slug (slug),
        KEY idx_history_matches_date (match_date),
        KEY idx_history_matches_season (season)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Additive: link an archived result to its live match_fixtures row once the
    // history merge (tools/merge_history_into_fixtures.php) has run, so the
    // public match page can pull the old written report / scorers through it.
    $matchColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM history_matches') as $column) {
        $matchColumns[(string) $column['Field']] = true;
    }
    if (!isset($matchColumns['fixture_id'])) {
        try {
            $pdo->exec("ALTER TABLE history_matches
                ADD COLUMN fixture_id INT UNSIGNED NULL AFTER id,
                ADD KEY idx_history_matches_fixture (fixture_id)");
        } catch (Throwable) {
            // another request beat us to it, or the grant is read-only — harmless
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS history_player_stats (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        legacy_id INT UNSIGNED NULL,
        name VARCHAR(160) NOT NULL,
        sort_name VARCHAR(160) NOT NULL DEFAULT '',
        appearances INT NOT NULL DEFAULT 0,
        starts INT NOT NULL DEFAULT 0,
        subs INT NOT NULL DEFAULT 0,
        goals INT NOT NULL DEFAULT 0,
        penalties INT NOT NULL DEFAULT 0,
        clean_sheets INT NOT NULL DEFAULT 0,
        yellows INT NOT NULL DEFAULT 0,
        reds INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_history_stats_legacy (legacy_id),
        KEY idx_history_stats_apps (appearances),
        KEY idx_history_stats_goals (goals)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS history_galleries (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        title VARCHAR(190) NOT NULL DEFAULT '',
        description VARCHAR(255) NOT NULL DEFAULT '',
        album_date DATE NULL,
        match_fixture_id INT UNSIGNED NULL,
        photo_count INT NOT NULL DEFAULT 0,
        cover_path VARCHAR(255) NOT NULL DEFAULT '',
        display_on_site TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_history_galleries_slug (slug),
        KEY idx_history_galleries_date (album_date),
        KEY idx_history_galleries_fixture (match_fixture_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Additive: columns that arrived after the first import. Existing rows keep
    // sensible defaults; albums are now created/managed in photo_albums.php.
    $galleryColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM history_galleries') as $column) {
        $galleryColumns[(string) $column['Field']] = true;
    }
    if (!isset($galleryColumns['display_on_site'])) {
        $pdo->exec("ALTER TABLE history_galleries ADD COLUMN display_on_site TINYINT(1) NOT NULL DEFAULT 1 AFTER cover_path");
    }
    if (!isset($galleryColumns['description'])) {
        $pdo->exec("ALTER TABLE history_galleries ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER title");
    }
    if (!isset($galleryColumns['match_fixture_id'])) {
        $pdo->exec("ALTER TABLE history_galleries ADD COLUMN match_fixture_id INT UNSIGNED NULL AFTER album_date, ADD KEY idx_history_galleries_fixture (match_fixture_id)");
    }
    if (!isset($galleryColumns['updated_at'])) {
        $pdo->exec("ALTER TABLE history_galleries ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS history_gallery_photos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gallery_id INT UNSIGNED NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        thumb_path VARCHAR(255) NOT NULL DEFAULT '',
        photo_date DATE NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_history_photo (gallery_id, file_path),
        KEY idx_history_photo_gallery (gallery_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}
