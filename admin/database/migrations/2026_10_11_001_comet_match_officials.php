<?php
declare(strict_types=1);

// INTERNAL: referees / match officials collected from COMET, kept to build up
// a picture of each official over time. Never exposed on the public site or
// in any report. comet_match_officials keys on the COMET match id so rows can
// be stored for opposition matches that have no match_fixtures row; fixture_id
// is filled in when the match is one of Saltcoats Victoria's own. Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comet_officials (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        comet_person_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        home_club VARCHAR(160) NULL,
        nationality VARCHAR(8) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_comet_person (comet_person_id),
        KEY idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comet_match_officials (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        comet_match_id BIGINT UNSIGNED NOT NULL,
        fixture_id INT UNSIGNED NULL,
        official_id INT UNSIGNED NOT NULL,
        role VARCHAR(60) NOT NULL,
        appointment_status VARCHAR(30) NULL,
        match_date DATE NULL,
        home_team VARCHAR(150) NULL,
        away_team VARCHAR(150) NULL,
        competition VARCHAR(190) NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'comet',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_match_official_role (comet_match_id, official_id, role),
        KEY idx_fixture (fixture_id),
        KEY idx_official (official_id),
        KEY idx_match_date (match_date),
        CONSTRAINT fk_cmo_official FOREIGN KEY (official_id) REFERENCES comet_officials (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
