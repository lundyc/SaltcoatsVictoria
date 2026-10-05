<?php

require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/season.php';
$competitionStructureHelper = __DIR__ . '/competition_structure.php';
if (is_file($competitionStructureHelper)) {
          require_once $competitionStructureHelper;
}

function ensureMatchSchema(PDO $pdo): void
{
          static $done = false;
          if ($done) {
                    return;
          }
          $done = true;

          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_fixtures'")->fetchColumn();
          if (!$tableExists) {
                    $pdo->exec("
                              CREATE TABLE match_fixtures (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        season_id INT UNSIGNED NOT NULL,
                                        opponent_id INT UNSIGNED DEFAULT NULL,
                                        match_date DATE NOT NULL,
                                        kickoff_time TIME DEFAULT NULL,
                                        opponent VARCHAR(150) NOT NULL,
                                        competition VARCHAR(150) DEFAULT NULL,
                                        competition_season_id INT UNSIGNED DEFAULT NULL,
                                        competition_stage VARCHAR(100) DEFAULT NULL,
                                        is_home TINYINT(1) NOT NULL DEFAULT 1,
                                        venue VARCHAR(150) DEFAULT NULL,
                                        status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                                        half_time_home_score TINYINT UNSIGNED DEFAULT NULL,
                                        half_time_away_score TINYINT UNSIGNED DEFAULT NULL,
                                        full_time_home_score TINYINT UNSIGNED DEFAULT NULL,
                                        full_time_away_score TINYINT UNSIGNED DEFAULT NULL,
                                        notes VARCHAR(255) DEFAULT NULL,
                                        google_calendar_event_id VARCHAR(255) DEFAULT NULL,
                                        google_calendar_synced_at DATETIME DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        KEY idx_match_fixtures_season_date (season_id, match_date),
                                        KEY idx_match_fixtures_season_status (season_id, status),
                                        KEY idx_match_fixtures_opponent (opponent),
                                        KEY idx_match_fixtures_opponent_id (opponent_id),
                                        KEY idx_match_fixtures_competition_season (competition_season_id),
                                        CONSTRAINT fk_match_fixtures_season
                                                  FOREIGN KEY (season_id) REFERENCES seasons (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $fixtureOpponentColumnExists = (bool)$pdo->query("SHOW COLUMNS FROM match_fixtures LIKE 'opponent_id'")->fetchColumn();
          if ($tableExists && !$fixtureOpponentColumnExists) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN opponent_id INT UNSIGNED DEFAULT NULL AFTER season_id");
                    $pdo->exec("ALTER TABLE match_fixtures ADD KEY idx_match_fixtures_opponent_id (opponent_id)");
          }

          $opponentsExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_opponents'")->fetchColumn();
          if (!$opponentsExists) {
                    $pdo->exec("
                              CREATE TABLE match_opponents (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        clubname VARCHAR(150) NOT NULL,
                                        abbreviation VARCHAR(16) DEFAULT NULL,
                                        logo_path VARCHAR(255) DEFAULT NULL,
                                        white_logo_path VARCHAR(255) DEFAULT NULL,
                                        venue_id INT UNSIGNED DEFAULT NULL,
                                        ground_location VARCHAR(255) DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_match_opponents_clubname (clubname),
                                        KEY idx_match_opponents_venue_id (venue_id)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    $opponentsExists = true;
          }

          $competitionsExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_competitions'")->fetchColumn();
          if (!$competitionsExists) {
                    $pdo->exec("
                              CREATE TABLE match_competitions (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        name VARCHAR(150) NOT NULL,
                                        sort_order INT UNSIGNED DEFAULT NULL,
                                        is_league TINYINT(1) NOT NULL DEFAULT 0,
                                        league_url VARCHAR(255) DEFAULT NULL,
                                        league_banner_image VARCHAR(255) DEFAULT NULL,
                                        badge_image VARCHAR(255) DEFAULT NULL,
                                        white_badge_image VARCHAR(255) DEFAULT NULL,
                                        promotion_spots INT UNSIGNED DEFAULT NULL,
                                        relegation_spots INT UNSIGNED DEFAULT NULL,
                                        show_table_lines TINYINT(1) NOT NULL DEFAULT 1,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_match_competitions_name (name)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $venuesExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_venues'")->fetchColumn();
          if (!$venuesExists) {
                    $pdo->exec("
                              CREATE TABLE match_venues (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        club_name VARCHAR(150) DEFAULT NULL,
                                        name VARCHAR(150) NOT NULL,
                                        address_line1 VARCHAR(255) DEFAULT NULL,
                                        town VARCHAR(100) DEFAULT NULL,
                                        postcode VARCHAR(20) DEFAULT NULL,
                                        notes VARCHAR(255) DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        venue_identity CHAR(64) AS (
                                                  SHA2(CONCAT(
                                                            LOWER(TRIM(COALESCE(club_name, ''))), '|',
                                                            LOWER(TRIM(name)), '|',
                                                            LOWER(TRIM(COALESCE(address_line1, ''))), '|',
                                                            LOWER(TRIM(COALESCE(town, ''))), '|',
                                                            LOWER(TRIM(COALESCE(postcode, '')))
                                                  ), 256)
                                        ) PERSISTENT,
                                        PRIMARY KEY (id),
                                        KEY idx_match_venues_name (name),
                                        KEY idx_match_venues_town (town),
                                        UNIQUE KEY uq_match_venues_identity (venue_identity),
                                        UNIQUE KEY uq_match_venues_club_name (club_name)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }
          $venueNameUniqueExists = false;
          $venueIndexRows = $pdo->query("SHOW INDEX FROM match_venues")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($venueIndexRows as $indexRow) {
                    if ((string)($indexRow['Key_name'] ?? '') === 'uq_match_venues_name' && (int)($indexRow['Non_unique'] ?? 1) === 0) {
                              $venueNameUniqueExists = true;
                              break;
                    }
          }
          if ($venuesExists && $venueNameUniqueExists) {
                    $pdo->exec("ALTER TABLE match_venues DROP INDEX uq_match_venues_name");
                    $pdo->exec("ALTER TABLE match_venues ADD INDEX idx_match_venues_name (name)");
                    $pdo->exec("ALTER TABLE match_venues ADD INDEX idx_match_venues_town (town)");
          }
          $venueClubNameExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'club_name'")->fetchColumn();
          if ($venuesExists && !$venueClubNameExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD COLUMN club_name VARCHAR(150) DEFAULT NULL AFTER id");
          }
          $venueClubUniqueExists = false;
          $venueIndexRows = $pdo->query("SHOW INDEX FROM match_venues")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($venueIndexRows as $indexRow) {
                    if ((string)($indexRow['Key_name'] ?? '') === 'uq_match_venues_club_name' && (int)($indexRow['Non_unique'] ?? 1) === 0) {
                              $venueClubUniqueExists = true;
                              break;
                    }
          }
          if ($venuesExists && !$venueClubUniqueExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD UNIQUE KEY uq_match_venues_club_name (club_name)");
          }
          $venueIdentityExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'venue_identity'")->fetchColumn();
          if ($venuesExists && !$venueIdentityExists) {
                    $pdo->exec("
                              ALTER TABLE match_venues
                              ADD COLUMN venue_identity CHAR(64) AS (
                                        SHA2(CONCAT(
                                                  LOWER(TRIM(COALESCE(club_name, ''))), '|',
                                                  LOWER(TRIM(name)), '|',
                                                  LOWER(TRIM(COALESCE(address_line1, ''))), '|',
                                                  LOWER(TRIM(COALESCE(town, ''))), '|',
                                                  LOWER(TRIM(COALESCE(postcode, '')))
                                        ), 256)
                              ) PERSISTENT,
                              ADD UNIQUE KEY uq_match_venues_identity (venue_identity)
                    ");
          }
          $venueAddressExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'address_line1'")->fetchColumn();
          if ($venuesExists && !$venueAddressExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL AFTER name");
          }
          $venueTownExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'town'")->fetchColumn();
          if ($venuesExists && !$venueTownExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD COLUMN town VARCHAR(100) DEFAULT NULL AFTER address_line1");
          }
          $venuePostcodeExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'postcode'")->fetchColumn();
          if ($venuesExists && !$venuePostcodeExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD COLUMN postcode VARCHAR(20) DEFAULT NULL AFTER town");
          }
          $venueNotesExists = (bool)$pdo->query("SHOW COLUMNS FROM match_venues LIKE 'notes'")->fetchColumn();
          if ($venuesExists && !$venueNotesExists) {
                    $pdo->exec("ALTER TABLE match_venues ADD COLUMN notes VARCHAR(255) DEFAULT NULL AFTER postcode");
          }

          $opponentAbbreviationExists = (bool)$pdo->query("SHOW COLUMNS FROM match_opponents LIKE 'abbreviation'")->fetchColumn();
          if ($opponentsExists && !$opponentAbbreviationExists) {
                    $pdo->exec("ALTER TABLE match_opponents ADD COLUMN abbreviation VARCHAR(16) DEFAULT NULL AFTER clubname");
          }
          $opponentLogoPathExists = (bool)$pdo->query("SHOW COLUMNS FROM match_opponents LIKE 'logo_path'")->fetchColumn();
          if ($opponentsExists && !$opponentLogoPathExists) {
                    $pdo->exec("ALTER TABLE match_opponents ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL AFTER abbreviation");
          }
          $opponentWhiteLogoPathExists = (bool)$pdo->query("SHOW COLUMNS FROM match_opponents LIKE 'white_logo_path'")->fetchColumn();
          if ($opponentsExists && !$opponentWhiteLogoPathExists) {
                    $pdo->exec("ALTER TABLE match_opponents ADD COLUMN white_logo_path VARCHAR(255) DEFAULT NULL AFTER logo_path");
          }
          $opponentVenueIdExists = (bool)$pdo->query("SHOW COLUMNS FROM match_opponents LIKE 'venue_id'")->fetchColumn();
          if ($opponentsExists && !$opponentVenueIdExists) {
                    $pdo->exec("ALTER TABLE match_opponents ADD COLUMN venue_id INT UNSIGNED DEFAULT NULL AFTER white_logo_path");
                    $pdo->exec("ALTER TABLE match_opponents ADD KEY idx_match_opponents_venue_id (venue_id)");
          }
          $opponentGroundLocationExists = (bool)$pdo->query("SHOW COLUMNS FROM match_opponents LIKE 'ground_location'")->fetchColumn();
          if ($opponentsExists && !$opponentGroundLocationExists) {
                    $pdo->exec("ALTER TABLE match_opponents ADD COLUMN ground_location VARCHAR(255) DEFAULT NULL AFTER venue_id");
          }

          $competitionSortOrderExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'sort_order'")->fetchColumn();
          if ($competitionsExists && !$competitionSortOrderExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN sort_order INT UNSIGNED DEFAULT NULL AFTER name");
          }
          $competitionIsLeagueExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'is_league'")->fetchColumn();
          if ($competitionsExists && !$competitionIsLeagueExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN is_league TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order");
          }
          $competitionLeagueUrlExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'league_url'")->fetchColumn();
          if ($competitionsExists && !$competitionLeagueUrlExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN league_url VARCHAR(255) DEFAULT NULL AFTER is_league");
          }
          $competitionLeagueBannerExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'league_banner_image'")->fetchColumn();
          if ($competitionsExists && !$competitionLeagueBannerExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN league_banner_image VARCHAR(255) DEFAULT NULL AFTER league_url");
          }
          $competitionBadgeExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'badge_image'")->fetchColumn();
          if ($competitionsExists && !$competitionBadgeExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN badge_image VARCHAR(255) DEFAULT NULL AFTER league_banner_image");
          }
          $competitionWhiteBadgeExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'white_badge_image'")->fetchColumn();
          if ($competitionsExists && !$competitionWhiteBadgeExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN white_badge_image VARCHAR(255) DEFAULT NULL AFTER badge_image");
          }
          $competitionPromotionSpotsExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'promotion_spots'")->fetchColumn();
          if ($competitionsExists && !$competitionPromotionSpotsExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN promotion_spots INT UNSIGNED DEFAULT NULL AFTER league_banner_image");
          }
          $competitionRelegationSpotsExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'relegation_spots'")->fetchColumn();
          if ($competitionsExists && !$competitionRelegationSpotsExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN relegation_spots INT UNSIGNED DEFAULT NULL AFTER promotion_spots");
          }
          $competitionShowTableLinesExists = (bool)$pdo->query("SHOW COLUMNS FROM match_competitions LIKE 'show_table_lines'")->fetchColumn();
          if ($competitionsExists && !$competitionShowTableLinesExists) {
                    $pdo->exec("ALTER TABLE match_competitions ADD COLUMN show_table_lines TINYINT(1) NOT NULL DEFAULT 1 AFTER relegation_spots");
          }

          $pricingExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_season_pricing'")->fetchColumn();
          if (!$pricingExists) {
                    $pdo->exec("
                              CREATE TABLE match_season_pricing (
                                        season_id INT UNSIGNED NOT NULL,
                                        home_amount DECIMAL(10,2) NOT NULL DEFAULT 50.00,
                                        away_amount DECIMAL(10,2) NOT NULL DEFAULT 20.00,
                                        match_ball_amount DECIMAL(10,2) NOT NULL DEFAULT 25.00,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (season_id),
                                        CONSTRAINT fk_match_season_pricing_season
                                                  FOREIGN KEY (season_id) REFERENCES seasons (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $sponsorshipExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_sponsorships'")->fetchColumn();
          if (!$sponsorshipExists) {
                    $pdo->exec("
                              CREATE TABLE match_sponsorships (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        season_id INT UNSIGNED NOT NULL,
                                        fixture_id INT UNSIGNED NOT NULL,
                                        sponsor_id INT UNSIGNED NOT NULL,
                                        sponsorship_role VARCHAR(50) NOT NULL,
                                        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        paid TINYINT(1) NOT NULL DEFAULT 0,
                                        is_complimentary TINYINT(1) NOT NULL DEFAULT 0,
                                        notes VARCHAR(255) DEFAULT NULL,
                                        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        started_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                                        ended_at DATETIME NULL DEFAULT NULL,
                                        ended_reason VARCHAR(50) DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_match_fixture_role (season_id, fixture_id, sponsorship_role),
                                        KEY idx_match_sponsorships_season (season_id),
                                        KEY idx_match_sponsorships_fixture (fixture_id),
                                        KEY idx_match_sponsorships_sponsor (sponsor_id),
                                        KEY idx_match_sponsorships_season_sponsor (season_id, sponsor_id),
                                        CONSTRAINT fk_match_sponsorships_season
                                                  FOREIGN KEY (season_id) REFERENCES seasons (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE,
                                        CONSTRAINT fk_match_sponsorships_fixture
                                                  FOREIGN KEY (fixture_id) REFERENCES match_fixtures (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE,
                                        CONSTRAINT fk_match_sponsorships_sponsor
                                                  FOREIGN KEY (sponsor_id) REFERENCES sponsors (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $sponsorshipTypesExist = (bool)$pdo->query("SHOW TABLES LIKE 'match_sponsorship_types'")->fetchColumn();
          if (!$sponsorshipTypesExist) {
                    $pdo->exec("
                              CREATE TABLE match_sponsorship_types (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        name VARCHAR(100) NOT NULL,
                                        code VARCHAR(50) NOT NULL,
                                        default_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        sort_order INT UNSIGNED DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_match_sponsorship_types_name (name),
                                        UNIQUE KEY uq_match_sponsorship_types_code (code)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    $seedTypes = $pdo->prepare("
                              INSERT INTO match_sponsorship_types (name, code, default_amount, sort_order)
                              VALUES (:name, :code, :default_amount, :sort_order)
                    ");
                    foreach ([
                              ['Matchday', 'match_day', 0.00, 10],
                              ['Match Ball', 'match_ball', 0.00, 20],
                              ['MOTM', 'motm', 0.00, 30],
                    ] as $type) {
                              $seedTypes->execute([
                                        ':name' => $type[0],
                                        ':code' => $type[1],
                                        ':default_amount' => $type[2],
                                        ':sort_order' => $type[3],
                              ]);
                    }
          }

          if ($sponsorshipExists) {
                    $roleColumn = $pdo->query("SHOW COLUMNS FROM match_sponsorships LIKE 'sponsorship_role'")->fetch(PDO::FETCH_ASSOC);
                    if ($roleColumn && stripos((string)($roleColumn['Type'] ?? ''), 'varchar') !== 0) {
                              $pdo->exec("ALTER TABLE match_sponsorships MODIFY sponsorship_role VARCHAR(50) NOT NULL");
                    }
                    $complimentaryColumn = $pdo->query("SHOW COLUMNS FROM match_sponsorships LIKE 'is_complimentary'")->fetch(PDO::FETCH_ASSOC);
                    if (!$complimentaryColumn) {
                              $pdo->exec("ALTER TABLE match_sponsorships ADD COLUMN is_complimentary TINYINT(1) NOT NULL DEFAULT 0 AFTER paid");
                    }
          }

          $opponentFixtureFkExists = false;
          $fks = $pdo->query("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'match_fixtures'
                      AND COLUMN_NAME = 'opponent_id'
                      AND REFERENCED_TABLE_NAME = 'match_opponents'
          ")->fetchAll(PDO::FETCH_COLUMN);
          if ($fks) {
                    $opponentFixtureFkExists = true;
          }

          $fixtureGoogleEventIdExists = (bool)$pdo->query("SHOW COLUMNS FROM match_fixtures LIKE 'google_calendar_event_id'")->fetchColumn();
          if ($tableExists && !$fixtureGoogleEventIdExists) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN google_calendar_event_id VARCHAR(255) DEFAULT NULL AFTER notes");
          }
          $fixtureGoogleSyncedAtExists = (bool)$pdo->query("SHOW COLUMNS FROM match_fixtures LIKE 'google_calendar_synced_at'")->fetchColumn();
          if ($tableExists && !$fixtureGoogleSyncedAtExists) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN google_calendar_synced_at DATETIME DEFAULT NULL AFTER google_calendar_event_id");
          }
          if ($opponentsExists && !$opponentFixtureFkExists) {
                    try {
                              $pdo->exec("
                                        ALTER TABLE match_fixtures
                                                  ADD CONSTRAINT fk_match_fixtures_opponent
                                                  FOREIGN KEY (opponent_id) REFERENCES match_opponents (id)
                                                  ON DELETE SET NULL
                                                  ON UPDATE CASCADE
                              ");
                    } catch (Throwable $e) {
                              // Ignore if an equivalent foreign key already exists under a different name.
                    }
          }

          $historyExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_sponsorships_history'")->fetchColumn();
          if (!$historyExists) {
                    $pdo->exec("
                              CREATE TABLE match_sponsorships_history (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        season_id INT UNSIGNED DEFAULT NULL,
                                        match_sponsorship_id INT UNSIGNED NOT NULL,
                                        fixture_id INT UNSIGNED DEFAULT NULL,
                                        sponsor_id INT UNSIGNED DEFAULT NULL,
                                        sponsorship_role VARCHAR(50) DEFAULT NULL,
                                        amount DECIMAL(10,2) DEFAULT NULL,
                                        paid TINYINT(1) DEFAULT NULL,
                                        is_complimentary TINYINT(1) DEFAULT NULL,
                                        notes VARCHAR(255) DEFAULT NULL,
                                        action VARCHAR(20) DEFAULT NULL,
                                        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        change_type VARCHAR(20) DEFAULT NULL,
                                        reason VARCHAR(50) DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        KEY idx_match_sponsorships_history_season (season_id),
                                        KEY idx_match_sponsorships_history_fixture (fixture_id),
                                        KEY idx_match_sponsorships_history_sponsor (sponsor_id),
                                        KEY idx_match_sponsorships_history_sponsorship (match_sponsorship_id)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }
          if ($historyExists) {
                    $historyRoleColumn = $pdo->query("SHOW COLUMNS FROM match_sponsorships_history LIKE 'sponsorship_role'")->fetch(PDO::FETCH_ASSOC);
                    if ($historyRoleColumn && stripos((string)($historyRoleColumn['Type'] ?? ''), 'varchar') !== 0) {
                              $pdo->exec("ALTER TABLE match_sponsorships_history MODIFY sponsorship_role VARCHAR(50) DEFAULT NULL");
                    }
                    $historyComplimentaryColumn = $pdo->query("SHOW COLUMNS FROM match_sponsorships_history LIKE 'is_complimentary'")->fetch(PDO::FETCH_ASSOC);
                    if (!$historyComplimentaryColumn) {
                              $pdo->exec("ALTER TABLE match_sponsorships_history ADD COLUMN is_complimentary TINYINT(1) DEFAULT NULL AFTER paid");
                    }
          }

          $paymentExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_sponsorship_payments'")->fetchColumn();
          if (!$paymentExists) {
                    $pdo->exec("
                              CREATE TABLE match_sponsorship_payments (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        match_sponsorship_id INT UNSIGNED NOT NULL,
                                        season_id INT UNSIGNED NOT NULL,
                                        amount DECIMAL(10,2) NOT NULL,
                                        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        method VARCHAR(50) DEFAULT NULL,
                                        note VARCHAR(255) DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        KEY idx_match_sponsorship_payments_season (season_id),
                                        KEY idx_match_sponsorship_payments_sponsorship (match_sponsorship_id),
                                        CONSTRAINT fk_match_sponsorship_payments_sponsorship
                                                  FOREIGN KEY (match_sponsorship_id) REFERENCES match_sponsorships (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE,
                                        CONSTRAINT fk_match_sponsorship_payments_season
                                                  FOREIGN KEY (season_id) REFERENCES seasons (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $layoutExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_graphic_layouts'")->fetchColumn();
          if (!$layoutExists) {
                    $pdo->exec("
                              CREATE TABLE match_graphic_layouts (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        season_id INT UNSIGNED NOT NULL,
                                        fixture_id INT UNSIGNED NOT NULL,
                                        title_x INT NOT NULL DEFAULT 48,
                                        title_y INT NOT NULL DEFAULT 78,
                                        title_width INT NOT NULL DEFAULT 670,
                                        title_height INT NOT NULL DEFAULT 110,
                                        match_day_x INT NOT NULL DEFAULT 74,
                                        match_day_y INT NOT NULL DEFAULT 300,
                                        match_day_width INT NOT NULL DEFAULT 620,
                                        match_day_height INT NOT NULL DEFAULT 120,
                                        match_ball_x INT NOT NULL DEFAULT 74,
                                        match_ball_y INT NOT NULL DEFAULT 445,
                                        match_ball_width INT NOT NULL DEFAULT 620,
                                        match_ball_height INT NOT NULL DEFAULT 120,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_match_graphic_fixture (fixture_id),
                                        KEY idx_match_graphic_layouts_season (season_id),
                                        CONSTRAINT fk_match_graphic_layouts_season
                                                  FOREIGN KEY (season_id) REFERENCES seasons (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE,
                                        CONSTRAINT fk_match_graphic_layouts_fixture
                                                  FOREIGN KEY (fixture_id) REFERENCES match_fixtures (id)
                                                  ON DELETE CASCADE
                                                  ON UPDATE CASCADE
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $fixtureColumns = [];
          $stmt = $pdo->query("SHOW COLUMNS FROM match_fixtures");
          foreach ($stmt as $row) {
                    $fixtureColumns[$row['Field']] = true;
          }
          if (!isset($fixtureColumns['starting11_template_key'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_template_key VARCHAR(50) DEFAULT 'starting_xi_classic' AFTER notes");
          }
          if (!isset($fixtureColumns['starting11_background_image'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_background_image VARCHAR(255) DEFAULT NULL AFTER starting11_template_key");
          }
          if (!isset($fixtureColumns['next_match_background_image'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN next_match_background_image VARCHAR(255) DEFAULT NULL AFTER starting11_background_image");
          }
          if (!isset($fixtureColumns['starting11_starters_json'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_starters_json TEXT DEFAULT NULL AFTER next_match_background_image");
          }
          if (!isset($fixtureColumns['starting11_substitutes_json'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_substitutes_json TEXT DEFAULT NULL AFTER starting11_starters_json");
          }
          if (!isset($fixtureColumns['starting11_squad_numbers_json'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_squad_numbers_json TEXT DEFAULT NULL AFTER starting11_substitutes_json");
          }
          if (!isset($fixtureColumns['starting11_captain'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN starting11_captain VARCHAR(120) DEFAULT NULL AFTER starting11_squad_numbers_json");
          }
          if (!isset($fixtureColumns['half_time_home_score'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN half_time_home_score TINYINT UNSIGNED DEFAULT NULL AFTER status");
          }
          if (!isset($fixtureColumns['half_time_away_score'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN half_time_away_score TINYINT UNSIGNED DEFAULT NULL AFTER half_time_home_score");
          }
          if (!isset($fixtureColumns['full_time_home_score'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN full_time_home_score TINYINT UNSIGNED DEFAULT NULL AFTER half_time_away_score");
          }
          if (!isset($fixtureColumns['full_time_away_score'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN full_time_away_score TINYINT UNSIGNED DEFAULT NULL AFTER full_time_home_score");
          }
          // Cup ties only — a draw after full time that goes to a penalty
          // shootout. NULL for every league game and any cup tie that didn't
          // need one; set together, never independently.
          if (!isset($fixtureColumns['home_penalties'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN home_penalties TINYINT UNSIGNED DEFAULT NULL AFTER full_time_away_score");
          }
          if (!isset($fixtureColumns['away_penalties'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN away_penalties TINYINT UNSIGNED DEFAULT NULL AFTER home_penalties");
          }
          if (!isset($fixtureColumns['competition_season_id'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN competition_season_id INT UNSIGNED DEFAULT NULL AFTER competition");
          }
          if (!isset($fixtureColumns['competition_stage'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN competition_stage VARCHAR(100) DEFAULT NULL AFTER competition_season_id");
          }
          $fixtureIndexes = [];
          foreach ($pdo->query("SHOW INDEX FROM match_fixtures") as $fixtureIndex) {
                    $fixtureIndexes[(string)$fixtureIndex['Key_name']] = true;
          }
          if (!isset($fixtureIndexes['idx_match_fixtures_competition_season'])) {
                    $pdo->exec("ALTER TABLE match_fixtures ADD KEY idx_match_fixtures_competition_season (competition_season_id)");
          }
          if ((bool)$pdo->query("SHOW TABLES LIKE 'competition_seasons'")->fetchColumn()) {
                    $constraintStmt = $pdo->prepare("
                              SELECT COUNT(*)
                              FROM information_schema.TABLE_CONSTRAINTS
                              WHERE CONSTRAINT_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'match_fixtures'
                                AND CONSTRAINT_NAME = 'fk_match_fixtures_competition_season'
                    ");
                    $constraintStmt->execute();
                    if ((int)$constraintStmt->fetchColumn() === 0) {
                              $pdo->exec("
                                        ALTER TABLE match_fixtures
                                        ADD CONSTRAINT fk_match_fixtures_competition_season
                                        FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
                                        ON DELETE SET NULL
                                        ON UPDATE CASCADE
                              ");
                    }
          }

          seedMatchSeasonPricing($pdo);
          seedMatchOpponentsFromFixtures($pdo);
          seedMatchCompetitionsFromFixtures($pdo);
          seedMatchCompetitionSortOrder($pdo);
}

function matchOpponentAutoAbbreviation(string $clubname): string
{
          $clubname = trim(preg_replace('/\s+/', ' ', $clubname) ?? '');
          if ($clubname === '') {
                    return 'TBC';
          }

          $words = preg_split('/\s+/', strtoupper($clubname)) ?: [];
          $words = array_values(array_filter(array_map(
                    static function (string $word): string {
                              return preg_replace('/[^A-Z0-9]/', '', $word) ?? '';
                    },
                    $words
          ), static function (string $word): bool {
                    return $word !== '' && !in_array($word, ['FC', 'AFC', 'SC', 'THE'], true);
          }));

          if ($words === []) {
                    return strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($clubname)) ?? 'TBC', 0, 3));
          }

          if (count($words) >= 3) {
                    return substr(implode('', array_map(static fn(string $word): string => substr($word, 0, 1), $words)), 0, 3);
          }

          return substr($words[0], 0, 3);
}

function isDeletedOpponentLabel($value): bool
{
          return is_string($value) && strcasecmp(trim($value), 'Team Deleted') === 0;
}

function matchPosterSeasonBackgroundImage(int $seasonId): ?string
{
          if ($seasonId <= 0) {
                    return null;
          }

          $base = __DIR__ . '/../uploads/match_fixtures/match_poster_bg_season_' . $seasonId;
          foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                    $path = $base . '.' . $ext;
                    if (is_file($path)) {
                              return 'uploads/match_fixtures/match_poster_bg_season_' . $seasonId . '.' . $ext;
                    }
          }

          return null;
}

function matchImportNormalizeHeader(string $value): string
{
          $value = strtolower(trim($value));
          $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
          return trim($value, '_');
}

function matchImportParseBoolean($value): ?int
{
          if ($value === null) {
                    return null;
          }

          $value = strtolower(trim((string)$value));
          if ($value === '') {
                    return null;
          }

          if (in_array($value, ['1', 'y', 'yes', 'true', 'home', 'h'], true)) {
                    return 1;
          }
          if (in_array($value, ['0', 'n', 'no', 'false', 'away', 'a'], true)) {
                    return 0;
          }

          return null;
}

function matchImportParseStatus(string $value): string
{
          $value = strtolower(trim($value));
          return in_array($value, ['scheduled', 'played', 'postponed', 'cancelled'], true) ? $value : 'scheduled';
}

function matchImportResolveOpponent(PDO $pdo, string $clubname, ?string $abbreviation = null): array
{
          $clubname = trim(preg_replace('/\s+/', ' ', $clubname) ?? '');
          if ($clubname === '') {
                    throw new RuntimeException('Opponent name is required.');
          }

          $existing = getMatchOpponentByClubname($pdo, $clubname);
          if ($existing) {
                    return $existing;
          }

          $opponentId = saveMatchOpponentWithAbbreviation(
                    $pdo,
                    null,
                    $clubname,
                    trim((string)$abbreviation) !== '' ? (string)$abbreviation : matchOpponentAutoAbbreviation($clubname),
                    null,
                    null
          );

          $opponent = getMatchOpponentById($pdo, $opponentId);
          if (!$opponent) {
                    throw new RuntimeException('Failed to create opponent "' . $clubname . '".');
          }

          return $opponent;
}

function matchImportParseCsvFile(string $path): array
{
          $handle = fopen($path, 'rb');
          if (!$handle) {
                    throw new RuntimeException('Failed to open CSV file.');
          }

          $headers = null;
          $rows = [];
          while (($row = fgetcsv($handle)) !== false) {
                    if ($headers === null) {
                              $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($row[0] ?? ''));
                              $headers = array_map('matchImportNormalizeHeader', array_map('trim', $row));
                              continue;
                    }

                    if ($row === [null] || $row === []) {
                              continue;
                    }

                    $mapped = [];
                    foreach ($headers as $index => $header) {
                              if ($header === '') {
                                        continue;
                              }
                              $mapped[$header] = isset($row[$index]) ? trim((string)$row[$index]) : '';
                    }
                    $rows[] = $mapped;
          }
          fclose($handle);

          if (!$headers) {
                    throw new RuntimeException('CSV file is missing a header row.');
          }

          return $rows;
}

function matchImportXlsxColumnLetter(int $index): string
{
          $index += 1;
          $letters = '';
          while ($index > 0) {
                    $mod = ($index - 1) % 26;
                    $letters = chr(65 + $mod) . $letters;
                    $index = intdiv($index - 1, 26);
          }
          return $letters;
}

function matchImportXlsxColumnIndex(string $ref): int
{
          if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
                    return 0;
          }
          $letters = strtoupper($m[1]);
          $index = 0;
          for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
                    $index = ($index * 26) + (ord($letters[$i]) - 64);
          }
          return max(0, $index - 1);
}

function matchImportXlsxIsDateFormat(string $formatCode): bool
{
          $formatCode = strtolower($formatCode);
          if ($formatCode === '') {
                    return false;
          }

          if (preg_match('/(^|[^a-z])m\/d\/yy([^a-z]|$)/', $formatCode)) {
                    return true;
          }

          return (bool)preg_match('/(?<!\[)[dyhms](?!\])/i', $formatCode);
}

function matchImportXlsxSerialToDateTime(float $serial): string
{
          $unix = (int)round(($serial - 25569) * 86400);
          return gmdate('Y-m-d H:i:s', $unix);
}

function matchImportParseXlsxFile(string $path): array
{
          if (!class_exists('ZipArchive')) {
                    throw new RuntimeException('XLSX support is unavailable on this server.');
          }

          $zip = new ZipArchive();
          if ($zip->open($path) !== true) {
                    throw new RuntimeException('Failed to open XLSX file.');
          }

          $sharedStrings = [];
          $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
          if ($sharedXml !== false) {
                    $sharedDoc = new SimpleXMLElement($sharedXml);
                    foreach ($sharedDoc->si as $item) {
                              $text = '';
                              if (isset($item->t)) {
                                        $text = (string)$item->t;
                              } else {
                                        foreach ($item->r as $run) {
                                                  $text .= (string)($run->t ?? '');
                                        }
                              }
                              $sharedStrings[] = $text;
                    }
          }

          $dateStyles = [];
          $stylesXml = $zip->getFromName('xl/styles.xml');
          if ($stylesXml !== false) {
                    $stylesDoc = new SimpleXMLElement($stylesXml);
                    $customFormats = [];
                    if (isset($stylesDoc->numFmts)) {
                              foreach ($stylesDoc->numFmts->numFmt as $numFmt) {
                                        $customFormats[(int)$numFmt['numFmtId']] = (string)$numFmt['formatCode'];
                              }
                    }

                    if (isset($stylesDoc->cellXfs)) {
                              foreach ($stylesDoc->cellXfs->xf as $index => $xf) {
                                        $numFmtId = (int)($xf['numFmtId'] ?? 0);
                                        $formatCode = $customFormats[$numFmtId] ?? '';
                                        $dateStyles[(int)$index] = in_array($numFmtId, [14, 15, 16, 17, 22, 27, 30, 36, 50, 57, 58], true) || matchImportXlsxIsDateFormat($formatCode);
                              }
                    }
          }

          $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
          if ($sheetXml === false) {
                    $zip->close();
                    throw new RuntimeException('Unable to find the first worksheet in the XLSX file.');
          }

          $sheet = new SimpleXMLElement($sheetXml);
          $rows = [];
          $headerMap = null;

          foreach ($sheet->sheetData->row as $rowNode) {
                    $row = [];
                    foreach ($rowNode->c as $cell) {
                              $ref = (string)$cell['r'];
                              $colIndex = matchImportXlsxColumnIndex($ref);
                              $cellType = (string)($cell['t'] ?? '');
                              $styleIndex = (int)($cell['s'] ?? 0);
                              $value = '';

                              if ($cellType === 's') {
                                        $idx = (int)($cell->v ?? 0);
                                        $value = $sharedStrings[$idx] ?? '';
                              } elseif ($cellType === 'inlineStr') {
                                        $value = (string)($cell->is->t ?? '');
                              } elseif ($cellType === 'b') {
                                        $value = ((string)$cell->v === '1') ? '1' : '0';
                              } else {
                                        $raw = (string)($cell->v ?? '');
                                        if ($raw !== '' && !empty($dateStyles[$styleIndex]) && is_numeric($raw)) {
                                                  $value = matchImportXlsxSerialToDateTime((float)$raw);
                                        } else {
                                                  $value = $raw;
                                        }
                              }

                              $row[$colIndex] = trim((string)$value);
                    }
                    if ($row === []) {
                              continue;
                    }
                    ksort($row);
                    $ordered = [];
                    $maxIndex = (int)max(array_keys($row));
                    for ($i = 0; $i <= $maxIndex; $i++) {
                              $ordered[$i] = $row[$i] ?? '';
                    }

                    if ($headerMap === null) {
                              $headerMap = array_map('matchImportNormalizeHeader', $ordered);
                              continue;
                    }

                    $mapped = [];
                    foreach ($headerMap as $index => $header) {
                              if ($header === '') {
                                        continue;
                              }
                              $mapped[$header] = isset($ordered[$index]) ? trim((string)$ordered[$index]) : '';
                    }
                    $rows[] = $mapped;
          }

          $zip->close();

          if (!$headerMap) {
                    throw new RuntimeException('XLSX file is missing a header row.');
          }

          return $rows;
}

function matchImportReadSpreadsheetRows(string $path, string $originalName): array
{
          $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
          if ($ext === 'csv' || $ext === 'txt') {
                    return matchImportParseCsvFile($path);
          }

          if (in_array($ext, ['xlsx', 'xlsm'], true)) {
                    return matchImportParseXlsxFile($path);
          }

          throw new RuntimeException('Unsupported file type. Upload a CSV or XLSX file.');
}

function matchImportUpsertFixture(PDO $pdo, int $seasonId, array $row): array
{
          $fixtureId = (int)($row['fixture_id'] ?? $row['id'] ?? 0);
          $matchDate = trim((string)($row['match_date'] ?? $row['date'] ?? ''));
          if ($matchDate === '') {
                    throw new RuntimeException('Match date is required.');
          }

          $matchDateTs = strtotime($matchDate);
          if ($matchDateTs === false) {
                    throw new RuntimeException('Invalid match date "' . $matchDate . '".');
          }
          $matchDate = date('Y-m-d', $matchDateTs);
          $kickoffTime = trim((string)($row['kickoff_time'] ?? $row['kickoff'] ?? ''));
          if ($kickoffTime !== '') {
                    $kickoffTs = strtotime($kickoffTime);
                    if ($kickoffTs === false) {
                              throw new RuntimeException('Invalid kickoff time "' . $kickoffTime . '".');
                    }
                    $kickoffTime = date('H:i:s', $kickoffTs);
          }

          $opponentName = trim((string)($row['opponent'] ?? $row['opponent_name'] ?? $row['clubname'] ?? ''));
          if ($opponentName === '') {
                    throw new RuntimeException('Opponent is required.');
          }

          $abbreviation = trim((string)($row['opponent_abbreviation'] ?? $row['abbreviation'] ?? ''));
          $opponent = matchImportResolveOpponent($pdo, $opponentName, $abbreviation !== '' ? $abbreviation : null);
          $isHome = matchImportParseBoolean($row['is_home'] ?? $row['home_away'] ?? $row['venue_side'] ?? null);
          if ($isHome === null) {
                    $isHome = 1;
          }
          $status = matchImportParseStatus((string)($row['status'] ?? 'scheduled'));
          $competition = trim((string)($row['competition'] ?? ''));
          $competitionSeasonId = (int)($row['competition_season_id'] ?? $row['competition_edition_id'] ?? 0);
          $competitionSeason = null;
          if ($competitionSeasonId > 0) {
                    $competitionSeason = getMatchCompetitionSeasonById($pdo, $competitionSeasonId, $seasonId);
                    if (!$competitionSeason) {
                              throw new RuntimeException('Competition edition ' . $competitionSeasonId . ' is not assigned to this season.');
                    }
          } elseif ($competition !== '') {
                    $competitionSeason = findMatchCompetitionSeasonByTitle($pdo, $seasonId, $competition);
                    $competitionSeasonId = (int)($competitionSeason['id'] ?? 0);
          }
          if ($competitionSeason) {
                    $competition = trim((string)$competitionSeason['display_title']);
          }
          $competitionStage = trim((string)($row['competition_stage'] ?? $row['competition_round'] ?? $row['round'] ?? $row['stage'] ?? ''));
          $venue = trim((string)($row['venue'] ?? ''));
          $notes = trim((string)($row['notes'] ?? ''));

          $existingFixture = null;
          if ($fixtureId > 0) {
                    $existingFixture = getMatchFixtureById($pdo, $fixtureId);
                    if ($existingFixture && (int)$existingFixture['season_id'] !== $seasonId) {
                              throw new RuntimeException('Fixture ' . $fixtureId . ' belongs to a different season.');
                    }
          }

          if (!$existingFixture) {
                    $lookup = $pdo->prepare("
                              SELECT id
                              FROM match_fixtures
                              WHERE season_id = :season_id
                                AND match_date = :match_date
                                AND opponent_id = :opponent_id
                              ORDER BY id ASC
                              LIMIT 1
                    ");
                    $lookup->execute([
                              ':season_id' => $seasonId,
                              ':match_date' => $matchDate,
                              ':opponent_id' => (int)$opponent['id'],
                    ]);
                    $existingFixtureId = (int)$lookup->fetchColumn();
                    if ($existingFixtureId > 0) {
                              $fixtureId = $existingFixtureId;
                              $existingFixture = getMatchFixtureById($pdo, $fixtureId);
                    }
          }

          if ($fixtureId > 0 && $existingFixture) {
                    $stmt = $pdo->prepare("
                              UPDATE match_fixtures
                              SET match_date = :match_date,
                                  kickoff_time = :kickoff_time,
                                  opponent_id = :opponent_id,
                                  opponent = :opponent,
                                  competition = :competition,
                                  competition_season_id = :competition_season_id,
                                  competition_stage = :competition_stage,
                                  venue = :venue,
                                  is_home = :is_home,
                                  status = :status,
                                  notes = :notes
                              WHERE id = :id
                                AND season_id = :season_id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':match_date' => $matchDate,
                              ':kickoff_time' => $kickoffTime !== '' ? $kickoffTime : null,
                              ':opponent_id' => (int)$opponent['id'],
                              ':opponent' => (string)$opponent['clubname'],
                              ':competition' => $competition !== '' ? $competition : null,
                              ':competition_season_id' => $competitionSeasonId > 0 ? $competitionSeasonId : null,
                              ':competition_stage' => $competitionStage !== '' ? $competitionStage : null,
                              ':venue' => $venue !== '' ? $venue : null,
                              ':is_home' => $isHome,
                              ':status' => $status,
                              ':notes' => $notes !== '' ? $notes : null,
                              ':id' => $fixtureId,
                              ':season_id' => $seasonId,
                    ]);
          } else {
                    $stmt = $pdo->prepare("
                              INSERT INTO match_fixtures
                                        (season_id, opponent_id, match_date, kickoff_time, opponent, competition, competition_season_id, competition_stage, venue, is_home, status, notes)
                              VALUES
                                        (:season_id, :opponent_id, :match_date, :kickoff_time, :opponent, :competition, :competition_season_id, :competition_stage, :venue, :is_home, :status, :notes)
                    ");
                    $stmt->execute([
                              ':season_id' => $seasonId,
                              ':opponent_id' => (int)$opponent['id'],
                              ':match_date' => $matchDate,
                              ':kickoff_time' => $kickoffTime !== '' ? $kickoffTime : null,
                              ':opponent' => (string)$opponent['clubname'],
                              ':competition' => $competition !== '' ? $competition : null,
                              ':competition_season_id' => $competitionSeasonId > 0 ? $competitionSeasonId : null,
                              ':competition_stage' => $competitionStage !== '' ? $competitionStage : null,
                              ':venue' => $venue !== '' ? $venue : null,
                              ':is_home' => $isHome,
                              ':status' => $status,
                              ':notes' => $notes !== '' ? $notes : null,
                    ]);
                    $fixtureId = (int)$pdo->lastInsertId();
                    $existingFixture = getMatchFixtureById($pdo, $fixtureId);
          }

          if ($existingFixture && (int)$existingFixture['is_home'] !== $isHome) {
                    recalculateMatchFixtureSponsorshipAmounts($pdo, $fixtureId);
          }

          return [
                    'fixture_id' => $fixtureId,
                    'opponent_id' => (int)$opponent['id'],
                    'opponent_name' => (string)$opponent['clubname'],
                    'action' => $existingFixture ? 'updated' : 'created',
          ];
}

/**
 * @return array<string, array{label: string}>
 */
function matchStarting11TemplateOptions(): array
{
          return [
                    'starting_xi_classic' => [
                              'label' => 'Fixture background',
                    ],
                    'starting_xi_master' => [
                              'label' => 'Starting XI master template',
                    ],
          ];
}

/**
 * @return array{key: string, label: string}
 */
function matchStarting11TemplateConfig(?string $key): array
{
          $options = matchStarting11TemplateOptions();
          $key = trim((string)$key);
          if ($key === '' || !isset($options[$key])) {
                    $key = 'starting_xi_classic';
          }

          return [
                    'key' => $key,
                    'label' => $options[$key]['label'],
          ];
}

/**
 * @param mixed $submitted
 * @return list<string>
 */
function matchStarting11PrepareLineup($submitted): array
{
          if (!is_array($submitted)) {
                    return [];
          }

          $values = [];
          foreach ($submitted as $value) {
                    $name = trim((string)$value);
                    if ($name === '' || in_array($name, $values, true)) {
                              continue;
                    }
                    $values[] = $name;
          }

          return $values;
}

/**
 * @param mixed $submitted
 * @return list<string>
 */
function matchStarting11PrepareStarterSlots($submitted): array
{
          if (!is_array($submitted)) {
                    return array_fill(0, 11, '');
          }

          $values = [];
          for ($i = 0; $i < 11; $i++) {
                    $values[] = trim((string)($submitted[$i] ?? ''));
          }

          return $values;
}

function matchStarting11SubstituteNumber(int $index): int
{
          $number = 12 + max(0, $index);
          return $number >= 13 ? $number + 1 : $number;
}

/**
 * Return the public team-sheet label while retaining the player's real name in
 * fixture data for selection, captaincy, squad numbers and match events.
 */
function matchStarting11PublicPlayerName(PDO $pdo, string $name): string
{
          $name = trim($name);
          if ($name === '') {
                    return '';
          }

          /** @var array<int, array<string, bool>> $trialistsByConnection */
          static $trialistsByConnection = [];
          $connectionId = spl_object_id($pdo);
          if (!isset($trialistsByConnection[$connectionId])) {
                    $trialistsByConnection[$connectionId] = [];
                    try {
                              $rows = $pdo->query("SELECT name FROM players WHERE status = 'trialist'")->fetchAll(PDO::FETCH_COLUMN);
                              foreach ($rows as $trialistName) {
                                        $key = mb_strtolower(trim((string)$trialistName), 'UTF-8');
                                        if ($key !== '') {
                                                  $trialistsByConnection[$connectionId][$key] = true;
                                        }
                              }
                    } catch (Throwable $error) {
                              error_log('Trialist team-sheet lookup failed: ' . $error->getMessage());
                    }
          }

          $key = mb_strtolower($name, 'UTF-8');
          return isset($trialistsByConnection[$connectionId][$key]) ? 'Trialist' : $name;
}

/**
 * @param array<int, mixed> $starterSlots
 * @param array<int, mixed> $substitutes
 * @return array<string, int>
 */
function matchStarting11BuildSquadNumbers(array $starterSlots, array $substitutes): array
{
          $numbers = [];
          foreach (matchStarting11PrepareStarterSlots($starterSlots) as $index => $playerName) {
                    $playerName = trim((string)$playerName);
                    if ($playerName !== '') {
                              $numbers[$playerName] = $index + 1;
                    }
          }
          foreach (matchStarting11PrepareLineup($substitutes) as $index => $playerName) {
                    if (!isset($numbers[$playerName])) {
                              $numbers[$playerName] = matchStarting11SubstituteNumber($index);
                    }
          }
          return $numbers;
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matchStarting11NormalizeFixture(array $fixture): array
{
          $template = matchStarting11TemplateConfig(isset($fixture['starting11_template_key']) ? (string)$fixture['starting11_template_key'] : null);
          $starters = json_decode((string)($fixture['starting11_starters_json'] ?? '[]'), true);
          $substitutes = json_decode((string)($fixture['starting11_substitutes_json'] ?? '[]'), true);
          $starterSlots = matchStarting11PrepareStarterSlots($starters);
          $preparedSubstitutes = matchStarting11PrepareLineup($substitutes);

          return array_merge($fixture, [
                    'starting11_template_key' => $template['key'],
                    'starting11_template_label' => $template['label'],
                    'starting11_background_image' => trim((string)($fixture['starting11_background_image'] ?? '')),
                    'starting11_starters' => $starterSlots,
                    'starting11_substitutes' => $preparedSubstitutes,
                    'starting11_squad_numbers' => matchStarting11BuildSquadNumbers($starterSlots, $preparedSubstitutes),
                    'starting11_captain' => trim((string)($fixture['starting11_captain'] ?? '')),
          ]);
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function matchStarting11FixtureState(array $fixture): array
{
          $fixture = matchStarting11NormalizeFixture($fixture);
          $fixture['starting11_starters'] = matchStarting11PrepareStarterSlots($fixture['starting11_starters'] ?? []);
          $fixture['starting11_substitutes'] = array_pad($fixture['starting11_substitutes'] ?? [], 9, '');
          return $fixture;
}

function seedMatchSeasonPricing(PDO $pdo): void
{
          ensureMatchSchemaTables($pdo);
          $seasons = $pdo->query("SELECT id FROM seasons ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
          if (!$seasons) {
                    return;
          }

          $ins = $pdo->prepare("
                    INSERT INTO match_season_pricing (season_id, home_amount, away_amount, match_ball_amount)
                    VALUES (:season_id, 50.00, 20.00, 25.00)
          ");

          foreach ($seasons as $seasonId) {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM match_season_pricing WHERE season_id = :season_id");
                    $check->execute([':season_id' => (int)$seasonId]);
                    if ((int)$check->fetchColumn() === 0) {
                              $ins->execute([':season_id' => (int)$seasonId]);
                    }
          }
}

function ensureMatchSchemaTables(PDO $pdo): void
{
          // Compatibility shim for helper calls before full schema bootstrap.
          static $done = false;
          if ($done) {
                    return;
          }
          $done = true;

          $tables = ['match_fixtures', 'match_opponents', 'match_competitions', 'match_venues', 'match_season_pricing', 'match_sponsorships', 'match_sponsorships_history', 'match_sponsorship_payments', 'match_graphic_layouts'];
          foreach ($tables as $table) {
                    $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
                    if (!$exists) {
                              return;
                    }
          }
}

function getMatchSeasonPricing(PDO $pdo, int $seasonId): array
{
          ensureMatchSchema($pdo);

          $stmt = $pdo->prepare("
                    SELECT season_id, home_amount, away_amount, match_ball_amount
                    FROM match_season_pricing
                    WHERE season_id = :season_id
                    LIMIT 1
          ");
          $stmt->execute([':season_id' => $seasonId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$row) {
                    $insert = $pdo->prepare("
                              INSERT INTO match_season_pricing (season_id, home_amount, away_amount, match_ball_amount)
                              VALUES (:season_id, 50.00, 20.00, 25.00)
                    ");
                    $insert->execute([':season_id' => $seasonId]);
                    $row = [
                              'season_id' => $seasonId,
                              'home_amount' => 50.00,
                              'away_amount' => 20.00,
                              'match_ball_amount' => 25.00,
                    ];
          }

          return $row;
}

function saveMatchSeasonPricing(PDO $pdo, int $seasonId, float $homeAmount, float $awayAmount, float $matchBallAmount): void
{
          ensureMatchSchema($pdo);

          $stmt = $pdo->prepare("
                    INSERT INTO match_season_pricing (season_id, home_amount, away_amount, match_ball_amount)
                    VALUES (:season_id, :home_amount, :away_amount, :match_ball_amount)
                    ON DUPLICATE KEY UPDATE
                              home_amount = VALUES(home_amount),
                              away_amount = VALUES(away_amount),
                              match_ball_amount = VALUES(match_ball_amount),
                              updated_at = NOW()
          ");
          $stmt->execute([
                    ':season_id' => $seasonId,
                    ':home_amount' => $homeAmount,
                    ':away_amount' => $awayAmount,
                    ':match_ball_amount' => $matchBallAmount,
          ]);
}

function seedMatchOpponentsFromFixtures(PDO $pdo): void
{
          ensureMatchSchemaTables($pdo);
          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_opponents'")->fetchColumn();
          if (!$tableExists) {
                    return;
          }

          $stmt = $pdo->query("
                    SELECT DISTINCT TRIM(opponent) AS clubname
                    FROM match_fixtures
                    WHERE opponent IS NOT NULL
                      AND TRIM(opponent) <> ''
                      AND LOWER(TRIM(opponent)) <> 'team deleted'
                    ORDER BY TRIM(opponent) ASC
          ");
          $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
          if (!$names) {
                    return;
          }

          $ins = $pdo->prepare("
                    INSERT IGNORE INTO match_opponents (clubname, abbreviation)
                    VALUES (:clubname, :abbreviation)
          ");
          foreach ($names as $name) {
                    $ins->execute([
                              ':clubname' => $name,
                              ':abbreviation' => matchOpponentAutoAbbreviation((string)$name),
                    ]);
          }
}

function seedMatchCompetitionsFromFixtures(PDO $pdo): void
{
          ensureMatchSchemaTables($pdo);
          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_competitions'")->fetchColumn();
          if (!$tableExists) {
                    return;
          }

          $stmt = $pdo->query("
                    SELECT DISTINCT TRIM(competition) AS name
                    FROM match_fixtures
                    WHERE competition IS NOT NULL
                      AND TRIM(competition) <> ''
                    ORDER BY TRIM(competition) ASC
          ");
          $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
          if (!$names) {
                    return;
          }

          $hasAliases = (bool)$pdo->query("SHOW TABLES LIKE 'competition_aliases'")->fetchColumn();
          $hasEditions = (bool)$pdo->query("SHOW TABLES LIKE 'competition_seasons'")->fetchColumn();
          $knownNameSql = "
                    SELECT 1 FROM match_competitions WHERE LOWER(TRIM(name)) = LOWER(TRIM(:canonical_name))
          ";
          if ($hasAliases) {
                    $knownNameSql .= " UNION ALL SELECT 1 FROM competition_aliases WHERE review_status = 'confirmed' AND LOWER(TRIM(alias_name)) = LOWER(TRIM(:alias_name))";
          }
          if ($hasEditions) {
                    $knownNameSql .= " UNION ALL SELECT 1 FROM competition_seasons WHERE LOWER(TRIM(COALESCE(display_name, ''))) = LOWER(TRIM(:display_name)) OR LOWER(TRIM(COALESCE(sponsor_title, ''))) = LOWER(TRIM(:sponsor_title))";
          }
          $knownNameSql .= ' LIMIT 1';
          $knownName = $pdo->prepare($knownNameSql);
          $ins = $pdo->prepare("INSERT IGNORE INTO match_competitions (name) VALUES (:name)");
          foreach ($names as $name) {
                    $params = [':canonical_name' => (string)$name];
                    if ($hasAliases) {
                              $params[':alias_name'] = (string)$name;
                    }
                    if ($hasEditions) {
                              $params[':display_name'] = (string)$name;
                              $params[':sponsor_title'] = (string)$name;
                    }
                    $knownName->execute($params);
                    if ($knownName->fetchColumn() === false) {
                              $ins->execute([':name' => (string)$name]);
                    }
          }
}

function seedMatchCompetitionSortOrder(PDO $pdo): void
{
          ensureMatchSchemaTables($pdo);
          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_competitions'")->fetchColumn();
          if (!$tableExists) {
                    return;
          }

          $preferred = [
                    'WOSFL - GreenVersity Fourth Division',
                    'WOSFL - GreenVersity Third Division',
                    'Friendly',
                    'Scottish Junior Cup',
                    'West Of Scotland Cup',
                    '3 Pillars Financial Planning Scottish Communities Cup',
                    'Ardagh Glass League Cup',
                    'Ayrshire AFA - Budget Blinds Division 2',
                    'Ayrshire Weekly Press Cup',
                    'Emirates Scottish Junior Cup',
                    'Finest Carmats South Region Challenge Cup',
                    'McBookie.com West of Scotland Ayrshire District League',
                    'My bookie.com West of Scotland Ayrshire District League',
                    'New Coin Automatics West Of Scotland Cup',
                    'Scottish Communities Cup',
                    'South Region Challenge Cup',
                    'Stagecoach West Of Scotland League Ayrshire District',
                    'Strathclyde Demolition West Of Scotland League Cup',
          ];

          $stmt = $pdo->query("SELECT id, name, sort_order FROM match_competitions ORDER BY name ASC");
          $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
          if (!$rows) {
                    return;
          }

          $usedOrders = [];
          foreach ($rows as $row) {
                    if ($row['sort_order'] !== null && $row['sort_order'] !== '') {
                              $usedOrders[(int)$row['sort_order']] = true;
                    }
          }

          $nextFallback = 1000;
          $preferredIndex = 0;
          $update = $pdo->prepare("
                    UPDATE match_competitions
                    SET sort_order = :sort_order
                    WHERE id = :id
                    LIMIT 1
          ");

          foreach ($preferred as $name) {
                    foreach ($rows as $row) {
                              if (strcasecmp((string)$row['name'], $name) === 0 && ($row['sort_order'] === null || $row['sort_order'] === '')) {
                                        $sortOrder = $preferredIndex;
                                        $preferredIndex++;
                                        while (isset($usedOrders[(int)$sortOrder])) {
                                                  $sortOrder = $nextFallback++;
                                        }
                                        $update->execute([
                                                  ':sort_order' => (int)$sortOrder,
                                                  ':id' => (int)$row['id'],
                                        ]);
                                        $usedOrders[(int)$sortOrder] = true;
                                        break;
                              }
                    }
          }

          foreach ($rows as $row) {
                    $id = (int)$row['id'];
                    if ($row['sort_order'] !== null && $row['sort_order'] !== '') {
                              continue;
                    }
                    $sortOrder = $nextFallback++;
                    while (isset($usedOrders[$sortOrder])) {
                              $sortOrder++;
                    }
                    $update->execute([
                              ':sort_order' => $sortOrder,
                              ':id' => $id,
                    ]);
                    $usedOrders[$sortOrder] = true;
          }
}

function seedMatchVenuesFromFixtures(PDO $pdo): void
{
          ensureMatchSchemaTables($pdo);
          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'match_venues'")->fetchColumn();
          if (!$tableExists) {
                    return;
          }

          $stmt = $pdo->query("
                    SELECT DISTINCT TRIM(venue) AS name
                    FROM match_fixtures
                    WHERE venue IS NOT NULL
                      AND TRIM(venue) <> ''
                    ORDER BY TRIM(venue) ASC
          ");
          $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
          if (!$names) {
                    return;
          }

          $ins = $pdo->prepare("
                    INSERT INTO match_venues (name)
                    SELECT :name
                    WHERE NOT EXISTS (
                              SELECT 1
                              FROM match_venues
                              WHERE LOWER(TRIM(name)) = LOWER(TRIM(:existing_name))
                    )
          ");
          foreach ($names as $name) {
                    $ins->execute([
                              ':name' => (string) $name,
                              ':existing_name' => (string) $name,
                    ]);
          }
}

function getMatchFixtureById(PDO $pdo, int $fixtureId): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT f.*,
                           o.clubname AS opponent_name,
                           o.abbreviation AS opponent_abbreviation,
                           o.logo_path AS opponent_logo,
                           o.ground_location AS opponent_ground_location
                    FROM match_fixtures f
                    LEFT JOIN match_opponents o ON o.id = f.opponent_id
                    WHERE f.id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $fixtureId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row && !empty($row['opponent_name'])) {
                    $row['opponent'] = $row['opponent_name'];
          }
          return $row ? matchStarting11NormalizeFixture($row) : null;
}

/**
 * Returns only the competition editions assigned to a season. An empty result is
 * intentional when the normalized competition structure has not been installed yet.
 *
 * @return list<array<string, mixed>>
 */
function getMatchCompetitionSeasons(PDO $pdo, int $seasonId): array
{
          ensureMatchSchema($pdo);
          if (function_exists('ensureCompetitionStructureSchema')) {
                    ensureCompetitionStructureSchema($pdo);
          }
          if ($seasonId <= 0 || !(bool)$pdo->query("SHOW TABLES LIKE 'competition_seasons'")->fetchColumn()) {
                    return [];
          }

          $stmt = $pdo->prepare("
                    SELECT cs.*,
                           mc.name AS competition_name,
                           COALESCE(NULLIF(TRIM(cs.display_name), ''), NULLIF(TRIM(cs.sponsor_title), ''), mc.name) AS display_title
                    FROM competition_seasons cs
                    JOIN match_competitions mc ON mc.id = cs.competition_id
                    WHERE cs.season_id = :season_id
                      AND cs.is_active = 1
                    ORDER BY COALESCE(NULLIF(TRIM(cs.display_name), ''), NULLIF(TRIM(cs.sponsor_title), ''), mc.name) ASC,
                             cs.id ASC
          ");
          $stmt->execute([':season_id' => $seasonId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchCompetitionSeasonById(PDO $pdo, int $competitionSeasonId, ?int $seasonId = null): ?array
{
          if (function_exists('ensureCompetitionStructureSchema')) {
                    ensureCompetitionStructureSchema($pdo);
          }
          if ($competitionSeasonId <= 0 || !(bool)$pdo->query("SHOW TABLES LIKE 'competition_seasons'")->fetchColumn()) {
                    return null;
          }

          $sql = "
                    SELECT cs.*,
                           mc.name AS competition_name,
                           COALESCE(NULLIF(TRIM(cs.display_name), ''), NULLIF(TRIM(cs.sponsor_title), ''), mc.name) AS display_title
                    FROM competition_seasons cs
                    JOIN match_competitions mc ON mc.id = cs.competition_id
                    WHERE cs.id = :id
                      AND cs.is_active = 1
          ";
          $params = [':id' => $competitionSeasonId];
          if ($seasonId !== null) {
                    $sql .= ' AND cs.season_id = :season_id';
                    $params[':season_id'] = $seasonId;
          }
          $sql .= ' LIMIT 1';
          $stmt = $pdo->prepare($sql);
          $stmt->execute($params);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function findMatchCompetitionSeasonByTitle(PDO $pdo, int $seasonId, string $title): ?array
{
          $title = trim($title);
          if ($title === '') {
                    return null;
          }
          $editions = getMatchCompetitionSeasons($pdo, $seasonId);
          foreach ($editions as $edition) {
                    foreach (['display_title', 'display_name', 'sponsor_title', 'competition_name'] as $field) {
                              if (isset($edition[$field]) && strcasecmp(trim((string)$edition[$field]), $title) === 0) {
                                        return $edition;
                              }
                    }
          }
          if (function_exists('competitionStructureResolveAlias')) {
                    $alias = competitionStructureResolveAlias($pdo, $title, $seasonId);
                    $canonicalCompetitionId = (int)($alias['competition_id'] ?? 0);
                    if ($canonicalCompetitionId > 0) {
                              foreach ($editions as $edition) {
                                        if ((int)($edition['competition_id'] ?? 0) === $canonicalCompetitionId) {
                                                  return $edition;
                                        }
                              }
                    }
          }
          return null;
}

function getMatchOpponents(PDO $pdo): array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->query("
                    SELECT *
                    FROM match_opponents
                    ORDER BY clubname ASC
          ");
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchOpponentById(PDO $pdo, int $opponentId): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_opponents
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $opponentId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchOpponentByClubname(PDO $pdo, string $clubname): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_opponents
                    WHERE LOWER(clubname) = LOWER(:clubname)
                    LIMIT 1
          ");
          $stmt->execute([':clubname' => $clubname]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function getMatchCompetitions(PDO $pdo): array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->query("
                    SELECT *
                    FROM match_competitions
                    ORDER BY COALESCE(sort_order, 2147483647) ASC, name ASC
          ");
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchCompetitionById(PDO $pdo, int $competitionId): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_competitions
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $competitionId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchCompetitionByName(PDO $pdo, string $name): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_competitions
                    WHERE LOWER(name) = LOWER(:name)
                    LIMIT 1
          ");
          $stmt->execute([':name' => $name]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
                    return $row;
          }
          if (function_exists('competitionStructureResolveAlias')) {
                    $alias = competitionStructureResolveAlias($pdo, $name);
                    $competitionId = (int)($alias['competition_id'] ?? 0);
                    return $competitionId > 0 ? getMatchCompetitionById($pdo, $competitionId) : null;
          }
          return null;
}

function getNextMatchCompetitionSortOrder(PDO $pdo): int
{
          ensureMatchSchema($pdo);
          $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM match_competitions")->fetchColumn();
          return $next > 0 ? $next : 0;
}

function saveMatchCompetition(
          PDO $pdo,
          ?int $competitionId,
          string $name,
          ?int $sortOrder = null,
          ?string $leagueUrl = null,
          bool $isLeague = false,
          ?string $leagueBannerImage = null,
          ?int $promotionSpots = null,
          ?int $relegationSpots = null,
          bool $showTableLines = true,
          ?string $badgeImage = null,
          ?string $whiteBadgeImage = null
): int
{
          ensureMatchSchema($pdo);
          $name = trim($name);
          if ($name === '') {
                    throw new RuntimeException('Competition name is required.');
          }

          $leagueUrl = trim((string) $leagueUrl);
          $leagueUrl = $leagueUrl !== '' ? $leagueUrl : null;
          $leagueBannerImage = trim((string) $leagueBannerImage);
          $leagueBannerImage = $leagueBannerImage !== '' ? $leagueBannerImage : null;
          $badgeImage = trim((string) $badgeImage);
          $badgeImage = $badgeImage !== '' ? $badgeImage : null;
          $whiteBadgeImage = trim((string) $whiteBadgeImage);
          $whiteBadgeImage = $whiteBadgeImage !== '' ? $whiteBadgeImage : null;
          $promotionSpots = $promotionSpots !== null ? max(1, (int) $promotionSpots) : null;
          $relegationSpots = $relegationSpots !== null ? max(0, (int) $relegationSpots) : null;
          $showTableLines = $showTableLines ? 1 : 0;
          $isLeague = $isLeague ? 1 : 0;

          if ($sortOrder === null) {
                    if ($competitionId !== null && $competitionId > 0) {
                              $current = getMatchCompetitionById($pdo, $competitionId);
                              $sortOrder = isset($current['sort_order']) ? (int)$current['sort_order'] : null;
                    } else {
                              $sortOrder = getNextMatchCompetitionSortOrder($pdo);
                    }
          }

          if ($competitionId !== null && $competitionId > 0) {
                    $stmt = $pdo->prepare("
                              UPDATE match_competitions
                              SET name = :name,
                                  sort_order = :sort_order,
                                  is_league = :is_league,
                                  league_url = :league_url,
                                  league_banner_image = :league_banner_image,
                                  badge_image = :badge_image,
                                  white_badge_image = :white_badge_image,
                                  promotion_spots = :promotion_spots,
                                  relegation_spots = :relegation_spots,
                                  show_table_lines = :show_table_lines
                              WHERE id = :id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':name' => $name,
                              ':sort_order' => $sortOrder,
                              ':is_league' => $isLeague,
                              ':league_url' => $leagueUrl,
                              ':league_banner_image' => $leagueBannerImage,
                              ':badge_image' => $badgeImage,
                              ':white_badge_image' => $whiteBadgeImage,
                              ':promotion_spots' => $promotionSpots,
                              ':relegation_spots' => $relegationSpots,
                              ':show_table_lines' => $showTableLines,
                              ':id' => $competitionId,
                    ]);
                    return $competitionId;
          }

          $stmt = $pdo->prepare("
                    INSERT INTO match_competitions
                              (name, sort_order, is_league, league_url, league_banner_image, badge_image, white_badge_image, promotion_spots, relegation_spots, show_table_lines)
                    VALUES
                              (:name, :sort_order, :is_league, :league_url, :league_banner_image, :badge_image, :white_badge_image, :promotion_spots, :relegation_spots, :show_table_lines)
          ");
          $stmt->execute([
                    ':name' => $name,
                    ':sort_order' => $sortOrder,
                    ':is_league' => $isLeague,
                    ':league_url' => $leagueUrl,
                    ':league_banner_image' => $leagueBannerImage,
                    ':badge_image' => $badgeImage,
                    ':white_badge_image' => $whiteBadgeImage,
                    ':promotion_spots' => $promotionSpots,
                    ':relegation_spots' => $relegationSpots,
                    ':show_table_lines' => $showTableLines,
          ]);
          return (int) $pdo->lastInsertId();
}

function deleteMatchCompetition(PDO $pdo, int $competitionId): void
{
          ensureMatchSchema($pdo);
          if (function_exists('ensureCompetitionStructureSchema')) {
                    ensureCompetitionStructureSchema($pdo);
          }
          $usageStmt = $pdo->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM seasons WHERE competition_id = :season_competition_id)
                      + (SELECT COUNT(*) FROM competition_seasons WHERE competition_id = :edition_competition_id)
                      + (SELECT COUNT(*)
                           FROM match_fixtures f
                           INNER JOIN competition_seasons cs ON cs.id = f.competition_season_id
                          WHERE cs.competition_id = :fixture_competition_id)
                      + (SELECT COUNT(DISTINCT f.id)
                           FROM match_fixtures f
                           INNER JOIN match_competitions mc ON mc.id = :legacy_competition_id
                           LEFT JOIN competition_aliases ca
                             ON ca.competition_id = mc.id
                            AND ca.review_status = 'confirmed'
                            AND LOWER(TRIM(ca.alias_name)) = LOWER(TRIM(f.competition))
                          WHERE f.competition_season_id IS NULL
                            AND (LOWER(TRIM(f.competition)) = LOWER(TRIM(mc.name)) OR ca.id IS NOT NULL))
          ");
          $usageStmt->execute([
                    ':season_competition_id' => $competitionId,
                    ':edition_competition_id' => $competitionId,
                    ':fixture_competition_id' => $competitionId,
                    ':legacy_competition_id' => $competitionId,
          ]);
          if ((int)$usageStmt->fetchColumn() > 0) {
                    throw new RuntimeException('This competition is assigned to a season or fixture. Remove those relationships before deleting it.');
          }
          $stmt = $pdo->prepare("DELETE FROM match_competitions WHERE id = :id LIMIT 1");
          $stmt->execute([':id' => $competitionId]);
}

/**
 * @return list<array<string, mixed>>
 */
function getMatchSponsorshipTypes(PDO $pdo): array
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $stmt = $pdo->query("
                    SELECT id, name, code, amount AS default_amount, sort_order, is_active
                    FROM packages
                    WHERE scope = 'match' AND is_active = 1
                    ORDER BY sort_order ASC, name ASC
          ");
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchSponsorshipTypeById(PDO $pdo, int $typeId): ?array
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $stmt = $pdo->prepare("SELECT id, name, code, amount AS default_amount, sort_order, is_active FROM packages WHERE id = :id AND scope = 'match' LIMIT 1");
          $stmt->execute([':id' => $typeId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchSponsorshipTypeByCode(PDO $pdo, string $code): ?array
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $stmt = $pdo->prepare("SELECT id, name, code, amount AS default_amount, sort_order, is_active FROM packages WHERE code = :code AND scope = 'match' AND is_active = 1 LIMIT 1");
          $stmt->execute([':code' => strtolower(trim($code))]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function matchSponsorshipTypeCode(string $value): string
{
          $code = strtolower(trim($value));
          $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
          return trim($code, '_');
}

function getNextMatchSponsorshipTypeSortOrder(PDO $pdo): int
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          return max(0, (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM packages WHERE scope = 'match'")->fetchColumn());
}

function saveMatchSponsorshipType(
          PDO $pdo,
          ?int $typeId,
          string $name,
          string $code,
          float $defaultAmount,
          ?int $sortOrder
): int {
          ensureMatchSchema($pdo);
          $name = trim($name);
          if ($name === '') {
                    throw new RuntimeException('Sponsorship type name is required.');
          }
          $defaultAmount = max(0, $defaultAmount);

          if ($typeId !== null && $typeId > 0) {
                    $existing = getMatchSponsorshipTypeById($pdo, $typeId);
                    if (!$existing) {
                              throw new RuntimeException('Sponsorship type not found.');
                    }
                    $stmt = $pdo->prepare("
                              UPDATE packages
                              SET name = :name, amount = :default_amount, sort_order = :sort_order
                              WHERE id = :id LIMIT 1
                    ");
                    $stmt->execute([
                              ':name' => $name,
                              ':default_amount' => $defaultAmount,
                              ':sort_order' => $sortOrder,
                              ':id' => $typeId,
                    ]);
                    return $typeId;
          }

          $code = matchSponsorshipTypeCode($code !== '' ? $code : $name);
          if ($code === '' || strlen($code) > 50) {
                    throw new RuntimeException('Enter a valid code containing letters and numbers.');
          }
          $stmt = $pdo->prepare("
                    INSERT INTO packages (name, code, scope, category, amount, duration_type, max_slots, graphic_enabled, graphic_placement, default_logo_variant, is_active, sort_order)
                    VALUES (:name, :code, 'match', 'Match', :default_amount, 'fixture', 1, 1, :code, 'package_default', 1, :sort_order)
          ");
          $stmt->execute([
                    ':name' => $name,
                    ':code' => $code,
                    ':default_amount' => $defaultAmount,
                    ':sort_order' => $sortOrder,
          ]);
          return (int)$pdo->lastInsertId();
}

function deleteMatchSponsorshipType(PDO $pdo, int $typeId): void
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $stmt = $pdo->prepare("UPDATE packages SET is_active = 0 WHERE id = :id AND scope = 'match' LIMIT 1");
          $stmt->execute([':id' => $typeId]);
}

/**
 * @return list<array<string, mixed>>
 */
function getMatchVenues(PDO $pdo): array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->query("
                    SELECT *
                    FROM match_venues
                    ORDER BY COALESCE(club_name, name) ASC, name ASC
          ");
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchVenueById(PDO $pdo, int $venueId): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_venues
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $venueId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchVenueByName(PDO $pdo, string $name): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_venues
                    WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
                    ORDER BY
                              CASE WHEN club_name IS NOT NULL AND TRIM(club_name) <> '' THEN 0 ELSE 1 END,
                              CASE WHEN postcode IS NOT NULL AND TRIM(postcode) <> '' THEN 0 ELSE 1 END,
                              id ASC
                    LIMIT 1
          ");
          $stmt->execute([':name' => $name]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchVenueByClubName(PDO $pdo, string $clubName): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_venues
                    WHERE LOWER(TRIM(club_name)) = LOWER(TRIM(:club_name))
                    LIMIT 1
          ");
          $stmt->execute([':club_name' => $clubName]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function getMatchVenueByExactDetails(
          PDO $pdo,
          string $name,
          ?string $addressLine1,
          ?string $town,
          ?string $postcode
): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_venues
                    WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
                      AND COALESCE(LOWER(TRIM(address_line1)), '') = COALESCE(LOWER(TRIM(:address_line1)), '')
                      AND COALESCE(LOWER(TRIM(town)), '') = COALESCE(LOWER(TRIM(:town)), '')
                      AND COALESCE(LOWER(TRIM(postcode)), '') = COALESCE(LOWER(TRIM(:postcode)), '')
                    LIMIT 1
          ");
          $stmt->execute([
                    ':name' => $name,
                    ':address_line1' => $addressLine1,
                    ':town' => $town,
                    ':postcode' => $postcode,
          ]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          return $row ?: null;
}

function matchVenueDisplayLabel(array $venue): string
{
          $clubName = trim((string) ($venue['club_name'] ?? ''));
          $name = trim((string) ($venue['name'] ?? ''));
          $town = trim((string) ($venue['town'] ?? ''));
          $address = trim((string) ($venue['address_line1'] ?? ''));

          if ($clubName !== '') {
                    $label = $clubName;
                    if ($name !== '' && strcasecmp($name, $clubName) !== 0) {
                              $label .= ' - ' . $name;
                    }
                    if ($town !== '') {
                              $label .= ', ' . $town;
                    } elseif ($address !== '') {
                              $label .= ', ' . $address;
                    }

                    return $label;
          }

          if ($name === '') {
                    return '';
          }

          $parts = [$name];
          if ($town !== '') {
                    $parts[] = $town;
          } elseif ($address !== '') {
                    $parts[] = $address;
          }

          return implode(', ', $parts);
}

function saveMatchVenue(
          PDO $pdo,
          ?int $venueId,
          string $name,
          ?string $clubName = null,
          ?string $addressLine1 = null,
          ?string $town = null,
          ?string $postcode = null,
          ?string $notes = null
): int
{
          ensureMatchSchema($pdo);
          $name = trim($name);
          $clubName = trim((string) $clubName);
          if ($name === '') {
                    throw new RuntimeException('Venue name is required.');
          }

          $addressLine1 = trim((string) $addressLine1);
          $town = trim((string) $town);
          $postcode = strtoupper(trim((string) $postcode));
          $notes = trim((string) $notes);

          $existing = getMatchVenueByExactDetails(
                    $pdo,
                    $name,
                    $addressLine1 !== '' ? $addressLine1 : null,
                    $town !== '' ? $town : null,
                    $postcode !== '' ? $postcode : null
          );
          if ($clubName !== '') {
                    $clubVenue = getMatchVenueByClubName($pdo, $clubName);
                    if ($clubVenue) {
                              $existing = $clubVenue;
                    }
          } elseif ($addressLine1 === '' && $town === '' && $postcode === '') {
                    // A fixture seed or name-only quick-add should reuse a richer venue
                    // with the same name instead of adding an empty duplicate.
                    $existing = getMatchVenueByName($pdo, $name);
          }

          if ($existing && ($venueId === null || $venueId <= 0)) {
                    return (int)$existing['id'];
          }
          if ($existing && (int)$existing['id'] !== (int)$venueId) {
                    throw new RuntimeException('This venue already exists.');
          }

          if ($venueId !== null && $venueId > 0) {
                    $stmt = $pdo->prepare("
                              UPDATE match_venues
                              SET club_name = :club_name,
                                  name = :name,
                                  address_line1 = :address_line1,
                                  town = :town,
                                  postcode = :postcode,
                                  notes = :notes
                              WHERE id = :id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':club_name' => $clubName !== '' ? $clubName : null,
                              ':name' => $name,
                              ':address_line1' => $addressLine1 !== '' ? $addressLine1 : null,
                              ':town' => $town !== '' ? $town : null,
                              ':postcode' => $postcode !== '' ? $postcode : null,
                              ':notes' => $notes !== '' ? $notes : null,
                              ':id' => $venueId,
                    ]);
                    return $venueId;
          }

          $stmt = $pdo->prepare("
                    INSERT INTO match_venues (club_name, name, address_line1, town, postcode, notes)
                    VALUES (:club_name, :name, :address_line1, :town, :postcode, :notes)
          ");
          $stmt->execute([
                    ':club_name' => $clubName !== '' ? $clubName : null,
                    ':name' => $name,
                    ':address_line1' => $addressLine1 !== '' ? $addressLine1 : null,
                    ':town' => $town !== '' ? $town : null,
                    ':postcode' => $postcode !== '' ? $postcode : null,
                    ':notes' => $notes !== '' ? $notes : null,
          ]);
          return (int) $pdo->lastInsertId();
}

function deleteMatchVenue(PDO $pdo, int $venueId): void
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("DELETE FROM match_venues WHERE id = :id LIMIT 1");
          $stmt->execute([':id' => $venueId]);
}

function matchOpponentLogoAssetUrl(?string $logoPath, bool $preferWhiteDirectory = false): string
{
          $logoPath = trim((string) $logoPath);
          if ($logoPath === '') {
                    return '';
          }

          if (preg_match('#^(?:https?:)?//#i', $logoPath) === 1 || str_starts_with($logoPath, 'data:')) {
                    return $logoPath;
          }

          $badgeCandidates = $preferWhiteDirectory
                    ? [dirname(__DIR__) . '/badges/white/' . basename($logoPath), dirname(__DIR__) . '/badges/' . basename($logoPath)]
                    : [dirname(__DIR__) . '/badges/' . basename($logoPath), dirname(__DIR__) . '/badges/white/' . basename($logoPath)];
          $candidates = array_merge(
                    [dirname(__DIR__) . '/uploads/opponents/' . basename($logoPath)],
                    $badgeCandidates,
                    [dirname(__DIR__) . '/badges/' . basename($logoPath)]
          );

          foreach ($candidates as $absolutePath) {
                    if (!is_file($absolutePath)) {
                              continue;
                    }

                    return '/' . str_replace(dirname(__DIR__) . '/', '', $absolutePath);
          }

          return '/uploads/opponents/' . basename($logoPath);
}

function matchOpponentLogoFilePath(?string $logoPath): string
{
          $logoPath = trim((string) $logoPath);
          if ($logoPath === '') {
                    return '';
          }

          $absolutePath = dirname(__DIR__) . '/uploads/opponents/' . basename($logoPath);
          return is_file($absolutePath) ? $absolutePath : '';
}

function matchOpponentBadgeAssetUrl(PDO $pdo, string $clubname, bool $white = false): string
{
          $clubname = trim($clubname);
          if ($clubname === '') {
                    return '';
          }

          $opponent = getMatchOpponentByClubname($pdo, $clubname);
          if (!$opponent) {
                    return '';
          }

          $usingWhiteLogo = $white && trim((string)($opponent['white_logo_path'] ?? '')) !== '';
          $logoPath = $usingWhiteLogo ? trim((string)$opponent['white_logo_path']) : '';
          if ($logoPath === '') {
                    $logoPath = trim((string)($opponent['logo_path'] ?? ''));
          }

          return matchOpponentLogoAssetUrl($logoPath, $usingWhiteLogo);
}

function saveMatchOpponent(PDO $pdo, ?int $opponentId, string $clubname, ?string $logoPath, ?string $groundLocation): int
{
          return saveMatchOpponentWithAbbreviation(
                    $pdo,
                    $opponentId,
                    $clubname,
                    matchOpponentAutoAbbreviation($clubname),
                    $logoPath,
                    $groundLocation,
                    null
          );
}

function saveMatchOpponentWithAbbreviation(PDO $pdo, ?int $opponentId, string $clubname, string $abbreviation, ?string $logoPath, ?string $groundLocation, ?int $venueId = null, ?string $whiteLogoPath = null): int
{
          ensureMatchSchema($pdo);
          if ($opponentId !== null && $opponentId > 0 && $whiteLogoPath === null) {
                    $currentOpponent = getMatchOpponentById($pdo, $opponentId);
                    $whiteLogoPath = isset($currentOpponent['white_logo_path']) ? (string)$currentOpponent['white_logo_path'] : null;
          }
          $abbreviation = strtoupper(trim($abbreviation));
          if ($abbreviation === '') {
                    $abbreviation = matchOpponentAutoAbbreviation($clubname);
          }

          $venueName = null;
          if ($venueId !== null && $venueId > 0) {
                    $venue = getMatchVenueById($pdo, $venueId);
                    if ($venue) {
                              $venueName = trim((string) ($venue['name'] ?? ''));
                              if ($venueName === '') {
                                        $venueName = null;
                              }
                    } else {
                              $venueId = null;
                    }
          }

          $groundLocation = trim((string) $groundLocation);
          if ($venueName !== null) {
                    $groundLocation = $venueName;
          }
          if ($groundLocation === '') {
                    $groundLocation = null;
          }

          if ($opponentId !== null && $opponentId > 0) {
                    $stmt = $pdo->prepare("
                              UPDATE match_opponents
                              SET clubname = :clubname,
                                  abbreviation = :abbreviation,
                                  logo_path = :logo_path,
                                  white_logo_path = :white_logo_path,
                                  venue_id = :venue_id,
                                  ground_location = :ground_location
                              WHERE id = :id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':clubname' => $clubname,
                              ':abbreviation' => $abbreviation,
                              ':logo_path' => $logoPath,
                              ':white_logo_path' => $whiteLogoPath,
                              ':venue_id' => $venueId,
                              ':ground_location' => $groundLocation,
                              ':id' => $opponentId,
                    ]);
                    return $opponentId;
          }

          $stmt = $pdo->prepare("
                    INSERT INTO match_opponents (clubname, abbreviation, logo_path, white_logo_path, venue_id, ground_location)
                    VALUES (:clubname, :abbreviation, :logo_path, :white_logo_path, :venue_id, :ground_location)
          ");
          $stmt->execute([
                    ':clubname' => $clubname,
                    ':abbreviation' => $abbreviation,
                    ':logo_path' => $logoPath,
                    ':white_logo_path' => $whiteLogoPath,
                    ':venue_id' => $venueId,
                    ':ground_location' => $groundLocation,
          ]);
          return (int)$pdo->lastInsertId();
}

function deleteMatchOpponent(PDO $pdo, int $opponentId): void
{
          ensureMatchSchema($pdo);

          $stmt = $pdo->prepare("
                    SELECT clubname
                    FROM match_opponents
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $opponentId]);
          $clubname = (string)$stmt->fetchColumn();
          if ($clubname === '') {
                    return;
          }

          $startedTransaction = !$pdo->inTransaction();
          if ($startedTransaction) {
                    $pdo->beginTransaction();
          }

          try {
                    $fixtureUpdate = $pdo->prepare("
                              UPDATE match_fixtures
                              SET opponent_id = NULL,
                                  opponent = 'Team Deleted'
                              WHERE opponent_id = :id
                                 OR LOWER(TRIM(opponent)) = LOWER(TRIM(:clubname_text))
                    ");
                    $fixtureUpdate->execute([
                              ':id' => $opponentId,
                              ':clubname_text' => $clubname,
                    ]);

                    $stmt = $pdo->prepare("DELETE FROM match_opponents WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $opponentId]);

                    if ($startedTransaction) {
                              $pdo->commit();
                    }
          } catch (Throwable $e) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                              $pdo->rollBack();
                    }
                    throw $e;
          }
}

function getMatchFixtures(PDO $pdo, int $seasonId): array
{
          ensureMatchSchema($pdo);
          if (function_exists('ensureCompetitionStructureSchema')) {
                    ensureCompetitionStructureSchema($pdo);
          }
          // $seasonId <= 0 -> every season (the "All seasons" view in matches.php).
          $allSeasons = $seasonId <= 0;
          $stmt = $pdo->prepare("
                    SELECT f.*,
                           COALESCE(o.clubname, f.opponent) AS opponent_name,
                           o.logo_path AS opponent_logo,
                           o.ground_location AS opponent_ground_location,
                           se.name AS season_name,
                           se.is_locked AS season_is_locked,
                           COALESCE(mc.competition_type, legacy_mc.competition_type, '') AS competition_type,
                           COALESCE(day_counts.match_day_count, 0) AS match_day_count,
                           COALESCE(ball_counts.match_ball_count, 0) AS match_ball_count
                    FROM match_fixtures f
                    LEFT JOIN match_opponents o ON o.id = f.opponent_id
                    LEFT JOIN seasons se ON se.id = f.season_id
                    LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
                    LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
                    LEFT JOIN match_competitions legacy_mc
                              ON f.competition_season_id IS NULL
                             AND LOWER(TRIM(legacy_mc.name)) = LOWER(TRIM(f.competition))
                    LEFT JOIN (
                              SELECT fixture_id, COUNT(*) AS match_day_count
                              FROM match_sponsorships
                              WHERE sponsorship_role = 'match_day'
                                AND ended_at IS NULL
                              GROUP BY fixture_id
                    ) day_counts ON day_counts.fixture_id = f.id
                    LEFT JOIN (
                              SELECT fixture_id, COUNT(*) AS match_ball_count
                              FROM match_sponsorships
                              WHERE sponsorship_role = 'match_ball'
                                AND ended_at IS NULL
                              GROUP BY fixture_id
                    ) ball_counts ON ball_counts.fixture_id = f.id
                    " . ($allSeasons ? "" : "WHERE f.season_id = :season_id") . "
                    ORDER BY f.match_date ASC, f.kickoff_time ASC, f.id ASC
          ");
          $stmt->execute($allSeasons ? [] : [':season_id' => $seasonId]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as &$row) {
                    if (!empty($row['opponent_name'])) {
                              $row['opponent'] = $row['opponent_name'];
                    }
                    $row = matchStarting11NormalizeFixture($row);
          }
          unset($row);
          return $rows;
}

function getMatchSponsorshipRows(PDO $pdo, int $fixtureId): array
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT ms.*,
                           s.name AS sponsor_name,
                           s.logo_path AS sponsor_logo,
                           s.white_logo_path AS sponsor_white_logo,
                           s.website_url AS sponsor_website_url,
                           s.facebook_page_url AS sponsor_facebook_page_url,
                           s.instagram_url AS sponsor_instagram_url,
                           s.twitter_url AS sponsor_twitter_url,
                           s.contact_phone AS sponsor_contact_phone,
                           s.contact_email AS sponsor_contact_email,
                           s.address AS sponsor_address,
                           s.is_business AS sponsor_is_business,
                           s.is_main_sponsor AS sponsor_is_main_sponsor,
                           s.sort_order AS sponsor_sort_order,
                           s.is_active AS sponsor_is_active,
                           COALESCE(pay.total_paid, 0) AS paid_total,
                           a.id AS agreement_id
                    FROM match_sponsorships ms
                    JOIN sponsors s ON s.id = ms.sponsor_id
                    LEFT JOIN (
                              SELECT match_sponsorship_id, SUM(amount) AS total_paid
                              FROM match_sponsorship_payments
                              GROUP BY match_sponsorship_id
                    ) pay ON pay.match_sponsorship_id = ms.id
                    LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'match' AND a.legacy_id = ms.id
                    WHERE ms.fixture_id = :fixture_id
                      AND ms.ended_at IS NULL
                    ORDER BY FIELD(ms.sponsorship_role, 'match_day', 'match_ball'), s.name ASC, ms.id ASC
          ");
          $stmt->execute([':fixture_id' => $fixtureId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Paid, complimentary, and zero-value placements may be promoted publicly.
 * Part-paid and unpaid placements remain visible in management screens only.
 */
function matchSponsorshipCanBeDisplayed(array $row): bool
{
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    return true;
          }

          $amount = max(0.0, (float)($row['amount'] ?? 0));
          if ($amount === 0.0) {
                    return true;
          }

          return (float)($row['paid_total'] ?? 0) + 0.005 >= $amount;
}

function getDisplayableMatchSponsorshipRows(PDO $pdo, int $fixtureId): array
{
          return array_values(array_filter(
                    getMatchSponsorshipRows($pdo, $fixtureId),
                    static fn(array $row): bool => matchSponsorshipCanBeDisplayed($row)
          ));
}

/**
 * Build public-facing sponsor credits from eligible match sponsorships.
 * Only the first paid/complimentary sponsor for each fixture role is shown.
 */
function getMatchSponsorshipPublicCredit(PDO $pdo, int $fixtureId): string
{
          $credits = [];
          $seenRoles = [];
          foreach (getDisplayableMatchSponsorshipRows($pdo, $fixtureId) as $row) {
                    $role = (string)($row['sponsorship_role'] ?? '');
                    if (!in_array($role, ['match_day', 'match_ball'], true) || isset($seenRoles[$role])) {
                              continue;
                    }

                    $sponsorName = trim((string)($row['sponsor_name'] ?? ''));
                    if ($sponsorName === '') {
                              continue;
                    }

                    $seenRoles[$role] = true;
                    $credits[] = ($role === 'match_day' ? '🤝 Matchday Sponsor: ' : '⚽ Match Ball Sponsor: ') . $sponsorName;
          }

          return implode("\n", $credits);
}

function getMatchSponsorshipById(PDO $pdo, int $id): ?array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT ms.*,
                           s.name AS sponsor_name,
                           s.logo_path AS sponsor_logo,
                           f.match_date,
                           COALESCE(o.clubname, f.opponent) AS opponent,
                           f.is_home,
                           f.status AS fixture_status
                    FROM match_sponsorships ms
                    JOIN sponsors s ON s.id = ms.sponsor_id
                    JOIN match_fixtures f ON f.id = ms.fixture_id
                    LEFT JOIN match_opponents o ON o.id = f.opponent_id
                    WHERE ms.id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $id]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row && !empty($row['opponent_name'])) {
                    $row['opponent'] = $row['opponent_name'];
          }
          return $row ?: null;
}

function getMatchSponsorshipPayments(PDO $pdo, int $matchSponsorshipId): array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_sponsorship_payments
                    WHERE match_sponsorship_id = :id
                    ORDER BY paid_at DESC, id DESC
          ");
          $stmt->execute([':id' => $matchSponsorshipId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchSponsorshipPaidTotal(PDO $pdo, int $matchSponsorshipId): float
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                    FROM match_sponsorship_payments
                    WHERE match_sponsorship_id = :id
          ");
          $stmt->execute([':id' => $matchSponsorshipId]);
          return (float)$stmt->fetchColumn();
}

function recomputeMatchPaidFlag(PDO $pdo, int $matchSponsorshipId): void
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("SELECT amount, is_complimentary FROM match_sponsorships WHERE id = :id LIMIT 1");
          $stmt->execute([':id' => $matchSponsorshipId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$row) {
                    return;
          }
          $amount = (float)$row['amount'];
          if ((int)$row['is_complimentary'] === 1) {
                    $upd = $pdo->prepare("UPDATE match_sponsorships SET paid = 0 WHERE id = :id");
                    $upd->execute([':id' => $matchSponsorshipId]);
                    return;
          }
          $paidTotal = getMatchSponsorshipPaidTotal($pdo, $matchSponsorshipId);
          $flag = ($paidTotal + 0.0001) >= $amount ? 1 : 0;
          $upd = $pdo->prepare("UPDATE match_sponsorships SET paid = :paid WHERE id = :id");
          $upd->execute([':paid' => $flag, ':id' => $matchSponsorshipId]);
}

function getMatchSponsorshipOutstandingAmount(PDO $pdo, int $matchSponsorshipId): float
{
          ensureMatchSchema($pdo);
          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }
          return max(0.0, (float)$row['amount'] - getMatchSponsorshipPaidTotal($pdo, $matchSponsorshipId));
}

function markMatchSponsorshipPaid(PDO $pdo, int $matchSponsorshipId, ?string $method = 'Marked Paid', ?string $note = 'Marked as paid'): array
{
          ensureMatchSchema($pdo);
          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    throw new RuntimeException('Complimentary sponsorships cannot be marked as paid.');
          }

          $outstanding = getMatchSponsorshipOutstandingAmount($pdo, $matchSponsorshipId);
          if ($outstanding > 0) {
                    addMatchPayment($pdo, $matchSponsorshipId, $outstanding, $method, $note);
          } else {
                    recomputeMatchPaidFlag($pdo, $matchSponsorshipId);
          }

          return getMatchSponsorshipById($pdo, $matchSponsorshipId) ?: [];
}

function unpayMatchSponsorship(PDO $pdo, int $matchSponsorshipId): array
{
          ensureMatchSchema($pdo);
          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }

          $startedTransaction = false;
          if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTransaction = true;
          }
          try {
                    $del = $pdo->prepare("DELETE FROM match_sponsorship_payments WHERE match_sponsorship_id = :id");
                    $del->execute([':id' => $matchSponsorshipId]);

                    $upd = $pdo->prepare("UPDATE match_sponsorships SET paid = 0 WHERE id = :id");
                    $upd->execute([':id' => $matchSponsorshipId]);

                    if ($startedTransaction) {
                              $pdo->commit();
                    }
                    return getMatchSponsorshipById($pdo, $matchSponsorshipId) ?: [];
          } catch (Throwable $e) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                              $pdo->rollBack();
                    }
                    throw $e;
          }
}

function logMatchSponsorshipHistory(PDO $pdo, array $row, string $action = 'update', string $changeType = 'update', ?string $reason = null): void
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    INSERT INTO match_sponsorships_history
                              (season_id, match_sponsorship_id, fixture_id, sponsor_id, sponsorship_role, amount, paid, is_complimentary, notes, action, changed_at, change_type, reason)
                    VALUES
                              (:season_id, :match_sponsorship_id, :fixture_id, :sponsor_id, :sponsorship_role, :amount, :paid, :is_complimentary, :notes, :action, NOW(), :change_type, :reason)
          ");
          $stmt->execute([
                    ':season_id' => $row['season_id'] ?? null,
                    ':match_sponsorship_id' => $row['id'] ?? null,
                    ':fixture_id' => $row['fixture_id'] ?? null,
                    ':sponsor_id' => $row['sponsor_id'] ?? null,
                    ':sponsorship_role' => $row['sponsorship_role'] ?? null,
                    ':amount' => $row['amount'] ?? null,
                    ':paid' => $row['paid'] ?? null,
                    ':is_complimentary' => $row['is_complimentary'] ?? 0,
                    ':notes' => $row['notes'] ?? null,
                    ':action' => $action,
                    ':change_type' => $changeType,
                    ':reason' => $reason,
          ]);
}

function calculateMatchSponsorshipAmount(PDO $pdo, int $seasonId, string $role, bool $isHome): float
{
          $pricing = getMatchSeasonPricing($pdo, $seasonId);
          if ($role === 'match_ball') {
                    return (float)$pricing['match_ball_amount'];
          }
          if ($role === 'match_day') {
                    return $isHome ? (float)$pricing['home_amount'] : (float)$pricing['away_amount'];
          }
          $type = getMatchSponsorshipTypeByCode($pdo, $role);
          return (float)($type['default_amount'] ?? 0);
}

function recalculateMatchFixtureSponsorshipAmounts(PDO $pdo, int $fixtureId): void
{
          ensureMatchSchema($pdo);

          $fixture = getMatchFixtureById($pdo, $fixtureId);
          if (!$fixture) {
                    throw new RuntimeException('Fixture not found.');
          }

          $rows = getMatchSponsorshipRows($pdo, $fixtureId);
          if (!$rows) {
                    return;
          }

          foreach ($rows as $row) {
                    if ($row['sponsorship_role'] !== 'match_day' || (int)($row['is_complimentary'] ?? 0) === 1) {
                              continue;
                    }
                    $amount = calculateMatchSponsorshipAmount($pdo, (int)$fixture['season_id'], 'match_day', (int)$fixture['is_home'] === 1);
                    $upd = $pdo->prepare("UPDATE match_sponsorships SET amount = :amount WHERE id = :id LIMIT 1");
                    $upd->execute([
                              ':amount' => $amount,
                              ':id' => (int)$row['id'],
                    ]);
                    recomputeMatchPaidFlag($pdo, (int)$row['id']);
          }
}

function getMatchGraphicLayout(PDO $pdo, int $fixtureId, int $seasonId): array
{
          ensureMatchSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT *
                    FROM match_graphic_layouts
                    WHERE fixture_id = :fixture_id
                    LIMIT 1
          ");
          $stmt->execute([':fixture_id' => $fixtureId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($row) {
                    return $row;
          }

          $defaults = [
                    'season_id' => $seasonId,
                    'fixture_id' => $fixtureId,
                    'title_x' => 48,
                    'title_y' => 78,
                    'title_width' => 670,
                    'title_height' => 110,
                    'match_day_x' => 74,
                    'match_day_y' => 300,
                    'match_day_width' => 620,
                    'match_day_height' => 120,
                    'match_ball_x' => 74,
                    'match_ball_y' => 445,
                    'match_ball_width' => 620,
                    'match_ball_height' => 120,
          ];

          $ins = $pdo->prepare("
                    INSERT INTO match_graphic_layouts
                              (season_id, fixture_id, title_x, title_y, title_width, title_height, match_day_x, match_day_y, match_day_width, match_day_height, match_ball_x, match_ball_y, match_ball_width, match_ball_height)
                    VALUES
                              (:season_id, :fixture_id, :title_x, :title_y, :title_width, :title_height, :match_day_x, :match_day_y, :match_day_width, :match_day_height, :match_ball_x, :match_ball_y, :match_ball_width, :match_ball_height)
          ");
          $ins->execute($defaults);
          return $defaults;
}

function saveMatchGraphicLayout(PDO $pdo, int $fixtureId, int $seasonId, array $data): void
{
          ensureMatchSchema($pdo);

          $fields = [
                    'title_x', 'title_y', 'title_width', 'title_height',
                    'match_day_x', 'match_day_y', 'match_day_width', 'match_day_height',
                    'match_ball_x', 'match_ball_y', 'match_ball_width', 'match_ball_height',
          ];

          $payload = [];
          foreach ($fields as $field) {
                    if (isset($data[$field])) {
                              $payload[$field] = (int)$data[$field];
                    }
          }

          $check = $pdo->prepare("SELECT id FROM match_graphic_layouts WHERE fixture_id = :fixture_id LIMIT 1");
          $check->execute([':fixture_id' => $fixtureId]);
          $exists = (int)$check->fetchColumn() > 0;

          if ($exists) {
                    $sets = [];
                    foreach ($payload as $key => $value) {
                              $sets[] = "`$key` = :$key";
                    }
                    $sql = "UPDATE match_graphic_layouts SET " . implode(', ', $sets) . ", season_id = :season_id, updated_at = NOW() WHERE fixture_id = :fixture_id LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $payload['season_id'] = $seasonId;
                    $payload['fixture_id'] = $fixtureId;
                    $stmt->execute($payload);
                    return;
          }

          $payload['season_id'] = $seasonId;
          $payload['fixture_id'] = $fixtureId;
          $cols = array_keys($payload);
          $placeholders = array_map(fn($col) => ':' . $col, $cols);
          $sql = "INSERT INTO match_graphic_layouts (" . implode(',', $cols) . ", updated_at) VALUES (" . implode(',', $placeholders) . ", NOW())";
          $stmt = $pdo->prepare($sql);
          $stmt->execute($payload);
}

function matchPosterLayoutStoragePath(): string
{
          return __DIR__ . '/../uploads/match_fixtures/match_fixtures_poster_layouts.json';
}

function matchPosterDefaultLogoLayout(): array
{
          return [
                    'bar_one_x' => 1060,
                    'bar_one_y' => 1730,
                    'bar_one_w' => 240,
                    'bar_one_h' => 72,
                    'del_grecos_x' => 1010,
                    'del_grecos_y' => 1830,
                    'del_grecos_w' => 280,
                    'del_grecos_h' => 72,
          ];
}

function getMatchPosterLogoLayout(int $seasonId): array
{
          $defaults = matchPosterDefaultLogoLayout();
          if ($seasonId <= 0) {
                    return $defaults;
          }

          $path = matchPosterLayoutStoragePath();
          $raw = null;
          if (is_file($path)) {
                    $raw = json_decode((string)file_get_contents($path), true);
          } else {
                    $legacyPath = __DIR__ . '/../data/match_fixtures_poster_layouts.json';
                    if (is_file($legacyPath)) {
                              $raw = json_decode((string)file_get_contents($legacyPath), true);
                    }
          }

          if (!is_array($raw)) {
                    return $defaults;
          }

          $layout = $raw[(string)$seasonId] ?? null;
          if (!is_array($layout)) {
                    return $defaults;
          }

          foreach ($defaults as $key => $value) {
                    $defaults[$key] = isset($layout[$key]) ? (int)$layout[$key] : $value;
          }

          return $defaults;
}

function saveMatchPosterLogoLayout(int $seasonId, array $layout): void
{
          if ($seasonId <= 0) {
                    throw new RuntimeException('Invalid season.');
          }

          $path = matchPosterLayoutStoragePath();
          $dir = dirname($path);
          if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Failed to prepare poster layout storage.');
          }

          $current = [];
          if (is_file($path)) {
                    $decoded = json_decode((string)file_get_contents($path), true);
                    if (is_array($decoded)) {
                              $current = $decoded;
                    }
          }

          $defaults = matchPosterDefaultLogoLayout();
          $normalized = [];
          foreach ($defaults as $key => $defaultValue) {
                    $normalized[$key] = isset($layout[$key]) ? max(0, (int)$layout[$key]) : $defaultValue;
          }

          $current[(string)$seasonId] = $normalized;
          $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
          if ($json === false) {
                    throw new RuntimeException('Failed to encode poster layout.');
          }

          if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to save poster layout.');
          }
}

function assignMatchSponsorship(
          PDO $pdo,
          int $fixtureId,
          int $sponsorId,
          string $role,
          ?float $overrideAmount = null,
          ?string $notes = null,
          bool $isComplimentary = false
): array {
          ensureMatchSchema($pdo);
          // Catalogue schema checks can issue DDL, so complete them before opening
          // the assignment transaction.
          ensureSponsorshipCatalogSchema($pdo);

          $fixture = getMatchFixtureById($pdo, $fixtureId);
          if (!$fixture) {
                    throw new RuntimeException('Fixture not found.');
          }

          $season = getSeasonById($pdo, (int)$fixture['season_id']);
          if (!$season) {
                    throw new RuntimeException('Season not found.');
          }
          if ((int)$season['is_locked'] === 1) {
                    throw new RuntimeException('This season is locked.');
          }

          if (!getMatchSponsorshipTypeByCode($pdo, $role)) {
                    throw new RuntimeException('Invalid sponsorship role.');
          }

          $sponsorCheck = $pdo->prepare("
                    SELECT id
                    FROM sponsors
                    WHERE id = :id
                      AND is_active = 1
                    LIMIT 1
          ");
          $sponsorCheck->execute([':id' => $sponsorId]);
          if (!(int)$sponsorCheck->fetchColumn()) {
                    throw new RuntimeException('Selected sponsor is not active.');
          }

          $amount = $overrideAmount !== null
                    ? $overrideAmount
                    : calculateMatchSponsorshipAmount($pdo, (int)$fixture['season_id'], $role, (int)$fixture['is_home'] === 1);
          if ($isComplimentary) {
                    $amount = 0.0;
          }

          $startedTransaction = false;
          if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTransaction = true;
          }
          try {
                    $existingStmt = $pdo->prepare("
                              SELECT *
                              FROM match_sponsorships
                              WHERE season_id = :season_id
                                AND fixture_id = :fixture_id
                                AND sponsorship_role = :role
                              LIMIT 1
                              FOR UPDATE
                    ");
                    $existingStmt->execute([
                              ':season_id' => (int)$fixture['season_id'],
                              ':fixture_id' => $fixtureId,
                              ':role' => $role,
                    ]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                              $sponsorChanged = (int)$existing['sponsor_id'] !== $sponsorId;
                              $wasEnded = !empty($existing['ended_at']);
                              logMatchSponsorshipHistory(
                                        $pdo,
                                        $existing,
                                        'update',
                                        'update',
                                        $wasEnded ? 'reactivate' : 'reassign'
                              );
                              if ($isComplimentary && getMatchSponsorshipPaidTotal($pdo, (int)$existing['id']) > 0.0001) {
                                        throw new RuntimeException('A sponsorship with recorded payments cannot be changed to complimentary. Remove or refund the payments first.');
                              }
                              if ($sponsorChanged) {
                                        $deletePayments = $pdo->prepare("DELETE FROM match_sponsorship_payments WHERE match_sponsorship_id = :id");
                                        $deletePayments->execute([':id' => (int)$existing['id']]);
                              }
                              $upd = $pdo->prepare("
                              UPDATE match_sponsorships
                              SET sponsor_id = :sponsor_id,
                                    amount = :amount,
                                    is_complimentary = :is_complimentary,
                                    notes = COALESCE(:notes, notes),
                                    assigned_at = NOW(),
                                    started_at = NOW(),
                                    ended_at = NULL,
                                    ended_reason = NULL
                              WHERE id = :id
                      ");
                      $upd->execute([
                                ':sponsor_id' => $sponsorId,
                                ':amount' => $amount,
                                ':is_complimentary' => $isComplimentary ? 1 : 0,
                                ':notes' => $notes,
                                ':id' => (int)$existing['id'],
                              ]);
                              recomputeMatchPaidFlag($pdo, (int)$existing['id']);
                              syncMatchSponsorshipAgreement($pdo, (int)$existing['id']);
                              if ($startedTransaction) {
                                        $pdo->commit();
                              }
                              return getMatchSponsorshipById($pdo, (int)$existing['id']) ?: [];
                    }

                    $ins = $pdo->prepare("
                              INSERT INTO match_sponsorships
                                        (season_id, fixture_id, sponsor_id, sponsorship_role, amount, paid, is_complimentary, notes, assigned_at, started_at)
                              VALUES
                                        (:season_id, :fixture_id, :sponsor_id, :role, :amount, 0, :is_complimentary, :notes, NOW(), NOW())
                    ");
                    $ins->execute([
                              ':season_id' => (int)$fixture['season_id'],
                              ':fixture_id' => $fixtureId,
                              ':sponsor_id' => $sponsorId,
                              ':role' => $role,
                              ':amount' => $amount,
                              ':is_complimentary' => $isComplimentary ? 1 : 0,
                              ':notes' => $notes,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    recomputeMatchPaidFlag($pdo, $id);
                    syncMatchSponsorshipAgreement($pdo, $id);
                    if ($startedTransaction) {
                              $pdo->commit();
                    }
                    return getMatchSponsorshipById($pdo, $id) ?: [];
          } catch (Throwable $e) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                              $pdo->rollBack();
                    }
                    throw $e;
          }
}

function endMatchSponsorship(PDO $pdo, int $matchSponsorshipId, string $reason = 'deleted'): void
{
          ensureMatchSchema($pdo);
          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }
          $season = getSeasonById($pdo, (int)$row['season_id']);
          if ($season && (int)$season['is_locked'] === 1) {
                    throw new RuntimeException('This season is locked.');
          }

          logMatchSponsorshipHistory($pdo, $row, 'delete', 'delete', $reason);
          $stmt = $pdo->prepare("
                    UPDATE match_sponsorships
                    SET ended_at = NOW(),
                        ended_reason = :reason
                    WHERE id = :id
          ");
          $stmt->execute([
                    ':reason' => $reason,
                    ':id' => $matchSponsorshipId,
          ]);
          syncMatchSponsorshipAgreement($pdo, $matchSponsorshipId);
}

function moveMatchSponsorship(PDO $pdo, int $matchSponsorshipId, int $toFixtureId): array
{
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }

          $targetFixture = getMatchFixtureById($pdo, $toFixtureId);
          if (!$targetFixture) {
                    throw new RuntimeException('Target fixture not found.');
          }
          if ((int)$targetFixture['season_id'] !== (int)$row['season_id']) {
                    throw new RuntimeException('Target fixture must be in the same season.');
          }

          $season = getSeasonById($pdo, (int)$row['season_id']);
          if ($season && (int)$season['is_locked'] === 1) {
                    throw new RuntimeException('This season is locked.');
          }

          $conflict = $pdo->prepare("
                    SELECT id
                    FROM match_sponsorships
                    WHERE season_id = :season_id
                      AND fixture_id = :fixture_id
                      AND sponsorship_role = :role
                      AND ended_at IS NULL
                      AND id <> :id
                    LIMIT 1
          ");
          $conflict->execute([
                    ':season_id' => (int)$row['season_id'],
                    ':fixture_id' => $toFixtureId,
                    ':role' => $row['sponsorship_role'],
                    ':id' => $matchSponsorshipId,
          ]);
          if ((int)$conflict->fetchColumn() > 0) {
                    throw new RuntimeException('Target fixture already has this sponsorship role.');
          }

          $pdo->beginTransaction();
          try {
                    logMatchSponsorshipHistory($pdo, $row, 'transfer', 'update', 'moved');
                    $stmt = $pdo->prepare("
                              UPDATE match_sponsorships
                              SET fixture_id = :fixture_id,
                                  assigned_at = NOW()
                              WHERE id = :id
                    ");
                    $stmt->execute([
                              ':fixture_id' => $toFixtureId,
                              ':id' => $matchSponsorshipId,
                    ]);
                    syncMatchSponsorshipAgreement($pdo, $matchSponsorshipId);
                    $pdo->commit();
                    return getMatchSponsorshipById($pdo, $matchSponsorshipId) ?: [];
          } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                              $pdo->rollBack();
                    }
                    throw $e;
          }
}

function addMatchPayment(PDO $pdo, int $matchSponsorshipId, float $amount, ?string $method = null, ?string $note = null): array
{
          ensureMatchSchema($pdo);
          if ($amount <= 0) {
                    throw new RuntimeException('Payment amount must be greater than zero.');
          }

          $row = getMatchSponsorshipById($pdo, $matchSponsorshipId);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    throw new RuntimeException('Payments cannot be recorded against a complimentary sponsorship.');
          }

          $season = getSeasonById($pdo, (int)$row['season_id']);
          if ($season && (int)$season['is_locked'] === 1) {
                    throw new RuntimeException('This season is locked.');
          }

          $stmt = $pdo->prepare("
                    INSERT INTO match_sponsorship_payments
                              (match_sponsorship_id, season_id, amount, paid_at, method, note)
                    VALUES
                              (:match_sponsorship_id, :season_id, :amount, NOW(), :method, :note)
          ");
          $stmt->execute([
                    ':match_sponsorship_id' => $matchSponsorshipId,
                    ':season_id' => (int)$row['season_id'],
                    ':amount' => $amount,
                    ':method' => $method,
                    ':note' => $note,
          ]);

          recomputeMatchPaidFlag($pdo, $matchSponsorshipId);
          return getMatchSponsorshipById($pdo, $matchSponsorshipId) ?: [];
}
