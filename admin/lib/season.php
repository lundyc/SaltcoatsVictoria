<?php

function ensureSeasonSchema(PDO $pdo): void
{
          static $done = false;
          if ($done) {
                    return;
          }

          $done = true;

          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'seasons'")->fetchColumn();
          if (!$tableExists) {
                    $pdo->exec("
                              CREATE TABLE seasons (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        name VARCHAR(32) NOT NULL,
                                        start_date DATE DEFAULT NULL,
                                        end_date DATE DEFAULT NULL,
                                        is_current TINYINT(1) NOT NULL DEFAULT 0,
                                        is_locked TINYINT(1) NOT NULL DEFAULT 0,
                                        competition_id INT UNSIGNED DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_season_name (name)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $seedSeasons = [
                    ['2025 / 2026', '2025-07-01', '2026-05-31', 0, 1],
                    ['2026 / 2027', '2026-06-01', '2027-05-31', 1, 0],
          ];
          $stmt = $pdo->query("SELECT COUNT(*) FROM seasons");
          $seasonCount = (int)$stmt->fetchColumn();
          if ($seasonCount === 0) {
                    $ins = $pdo->prepare("
                              INSERT INTO seasons (name, start_date, end_date, is_current, is_locked)
                              VALUES (:name, :start_date, :end_date, :is_current, :is_locked)
                    ");
                    foreach ($seedSeasons as $season) {
                              $ins->execute([
                                        ':name' => $season[0],
                                        ':start_date' => $season[1],
                                        ':end_date' => $season[2],
                                        ':is_current' => $season[3],
                                        ':is_locked' => $season[4],
                              ]);
                    }
          }

          $columns = [];
          $stmt = $pdo->query("SHOW COLUMNS FROM sponsorships");
          foreach ($stmt as $row) {
                    $columns[$row['Field']] = true;
          }

          if (!isset($columns['season_id'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN season_id INT UNSIGNED NULL AFTER package_id");
          }
          if (!isset($columns['paid'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN paid TINYINT(1) NOT NULL DEFAULT 0 AFTER amount");
          }
          if (!isset($columns['started_at'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN started_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER assigned_at");
          }
          if (!isset($columns['ended_at'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN ended_at DATETIME NULL DEFAULT NULL AFTER started_at");
          }
          if (!isset($columns['ended_reason'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN ended_reason VARCHAR(50) DEFAULT NULL AFTER ended_at");
          }
          if (!isset($columns['transferred_to_player_id'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN transferred_to_player_id INT UNSIGNED DEFAULT NULL AFTER ended_reason");
          }
          if (!isset($columns['transferred_from_sponsorship_id'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN transferred_from_sponsorship_id INT UNSIGNED DEFAULT NULL AFTER transferred_to_player_id");
          }
          if (!isset($columns['complimentary'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD COLUMN complimentary TINYINT(1) NOT NULL DEFAULT 0 AFTER amount");
          }

          $seasonColumns = [];
          $stmt = $pdo->query("SHOW COLUMNS FROM seasons");
          foreach ($stmt as $row) {
                    $seasonColumns[$row['Field']] = true;
          }
          if (!isset($seasonColumns['player_home_amount'])) {
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN player_home_amount DECIMAL(10,2) DEFAULT NULL AFTER is_locked");
          }
          if (!isset($seasonColumns['player_away_amount'])) {
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN player_away_amount DECIMAL(10,2) DEFAULT NULL AFTER player_home_amount");
          }
          if (!isset($seasonColumns['player_third_amount'])) {
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN player_third_amount DECIMAL(10,2) DEFAULT NULL AFTER player_away_amount");
          }
          if (!isset($seasonColumns['competition_id'])) {
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN competition_id INT UNSIGNED DEFAULT NULL AFTER is_locked");
          }
          if (!isset($seasonColumns['season_ticket_terms'])) {
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN season_ticket_terms LONGTEXT NULL AFTER competition_id");
          }
          if (!isset($seasonColumns['sponsorship_deadline'])) {
                    // Past this date, player sponsorships are locked for everyone —
                    // see assertSponsorshipEditable() in lib/sponsorship_catalog.php.
                    $pdo->exec("ALTER TABLE seasons ADD COLUMN sponsorship_deadline DATE NULL DEFAULT NULL AFTER is_locked");
          }

          $paymentColumns = [];
          $stmt = $pdo->query("SHOW COLUMNS FROM sponsorship_payments");
          foreach ($stmt as $row) {
                    $paymentColumns[$row['Field']] = true;
          }
          if (!isset($paymentColumns['season_id'])) {
                    $pdo->exec("ALTER TABLE sponsorship_payments ADD COLUMN season_id INT UNSIGNED NULL AFTER note");
          }

          $historyExists = (bool)$pdo->query("SHOW TABLES LIKE 'sponsorships_history'")->fetchColumn();
          if ($historyExists) {
                    $historyColumns = [];
                    $stmt = $pdo->query("SHOW COLUMNS FROM sponsorships_history");
                    foreach ($stmt as $row) {
                              $historyColumns[$row['Field']] = true;
                    }
                    if (!isset($historyColumns['season_id'])) {
                              $pdo->exec("ALTER TABLE sponsorships_history ADD COLUMN season_id INT UNSIGNED NULL AFTER sponsor_id");
                    }
          }

          $indexNames = [];
          $stmt = $pdo->query("SHOW INDEX FROM sponsorships");
          foreach ($stmt as $row) {
                    $indexNames[$row['Key_name']] = true;
          }

          if (isset($indexNames['uq_player_slot'])) {
                    $pdo->exec("ALTER TABLE sponsorships DROP INDEX uq_player_slot");
          }
          if (!isset($indexNames['uq_season_player_slot'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD UNIQUE KEY uq_season_player_slot (season_id, player_id, slot)");
          }
          if (!isset($indexNames['idx_sponsorships_season'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD KEY idx_sponsorships_season (season_id)");
          }
          if (!isset($indexNames['idx_sponsorships_season_sponsor'])) {
                    $pdo->exec("ALTER TABLE sponsorships ADD KEY idx_sponsorships_season_sponsor (season_id, sponsor_id)");
          }

          $currentSeasonId = (int)$pdo->query("SELECT id FROM seasons WHERE name = '2026 / 2027' LIMIT 1")->fetchColumn();
          if ($currentSeasonId === 0) {
                    $currentSeasonId = (int)$pdo->query("SELECT id FROM seasons ORDER BY is_current DESC, id DESC LIMIT 1")->fetchColumn();
          }

          $historicSeasonId = (int)$pdo->query("SELECT id FROM seasons WHERE name = '2025 / 2026' LIMIT 1")->fetchColumn();
          if ($historicSeasonId === 0) {
                    $historicSeasonId = $currentSeasonId;
          }

          $pdo->prepare("UPDATE seasons SET is_current = 0")->execute();
          if ($historicSeasonId > 0) {
                    $pdo->prepare("UPDATE seasons SET is_locked = 1 WHERE id = :id")->execute([':id' => $historicSeasonId]);
          }
          if ($currentSeasonId > 0) {
                    $pdo->prepare("UPDATE seasons SET is_current = 1, is_locked = 0 WHERE id = :id")->execute([':id' => $currentSeasonId]);
          }

          $pdo->exec("
                    UPDATE seasons
                    SET player_home_amount = COALESCE(player_home_amount, 50.00),
                        player_away_amount = COALESCE(player_away_amount, CASE WHEN name = '2026 / 2027' THEN 50.00 ELSE 30.00 END),
                        player_third_amount = COALESCE(player_third_amount, CASE WHEN name = '2026 / 2027' THEN 0.00 ELSE 20.00 END)
          ");

          if ($historicSeasonId > 0) {
                    $pdo->prepare("UPDATE sponsorships SET season_id = :sid WHERE season_id IS NULL")->execute([':sid' => $historicSeasonId]);
                    if ($pdo->query("SHOW TABLES LIKE 'sponsorship_payments'")->fetchColumn()) {
                              $pdo->exec("
                                        UPDATE sponsorship_payments p
                                        JOIN sponsorships s ON p.sponsorship_id = s.id
                                        SET p.season_id = s.season_id
                                        WHERE p.season_id IS NULL
                              ");
                    }
          }

          ensureSponsorSeasonSchema($pdo);
}

function ensureSponsorSeasonSchema(PDO $pdo): void
{
          static $done = false;
          if ($done) {
                    return;
          }
          $done = true;

          $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sponsor_seasons'")->fetchColumn();
          if (!$tableExists) {
                    $pdo->exec("
                              CREATE TABLE sponsor_seasons (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        season_id INT UNSIGNED NOT NULL,
                                        sponsor_id INT UNSIGNED NOT NULL,
                                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                                        joined_at DATE DEFAULT NULL,
                                        left_at DATE DEFAULT NULL,
                                        notes VARCHAR(255) DEFAULT NULL,
                                        facebook_spotlight_posted TINYINT(1) NOT NULL DEFAULT 0,
                                        facebook_spotlight_posted_at DATETIME DEFAULT NULL,
                                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE KEY uq_sponsor_season (season_id, sponsor_id),
                                        KEY idx_sponsor_seasons_season (season_id),
                                        KEY idx_sponsor_seasons_sponsor (sponsor_id)
                              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
          }

          $columns = [];
          foreach ($pdo->query("SHOW COLUMNS FROM sponsor_seasons") as $row) {
                    $columns[(string)$row['Field']] = true;
          }
          if (!isset($columns['facebook_spotlight_posted'])) {
                    $pdo->exec("ALTER TABLE sponsor_seasons ADD COLUMN facebook_spotlight_posted TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
                    $columns['facebook_spotlight_posted'] = true;
          }
          if (!isset($columns['facebook_spotlight_posted_at'])) {
                    $afterColumn = isset($columns['facebook_spotlight_posted']) ? ' AFTER facebook_spotlight_posted' : ' AFTER notes';
                    $pdo->exec("ALTER TABLE sponsor_seasons ADD COLUMN facebook_spotlight_posted_at DATETIME DEFAULT NULL" . $afterColumn);
          }
}

function upsertSponsorSeason(
          PDO $pdo,
          int $seasonId,
          int $sponsorId,
          int $isActive = 1,
          ?string $joinedAt = null,
          ?string $leftAt = null,
          ?string $notes = null
): void
{
          ensureSponsorSeasonSchema($pdo);

          $stmt = $pdo->prepare("
                    INSERT INTO sponsor_seasons
                              (season_id, sponsor_id, is_active, joined_at, left_at, notes)
                    VALUES
                              (:season_id, :sponsor_id, :is_active, :joined_at, :left_at, :notes)
                    ON DUPLICATE KEY UPDATE
                              is_active = VALUES(is_active),
                              joined_at = COALESCE(sponsor_seasons.joined_at, VALUES(joined_at)),
                              left_at = VALUES(left_at),
                              notes = VALUES(notes)
          ");
          $stmt->execute([
                    ':season_id' => $seasonId,
                    ':sponsor_id' => $sponsorId,
                    ':is_active' => $isActive ? 1 : 0,
                    ':joined_at' => $joinedAt,
                    ':left_at' => $leftAt,
                    ':notes' => $notes,
          ]);
}

function getPreviousSeasonId(PDO $pdo, int $seasonId): int
{
          $stmt = $pdo->prepare("
                    SELECT prev.id
                    FROM seasons cur
                    JOIN seasons prev
                      ON (
                        prev.start_date < cur.start_date
                        OR (prev.start_date = cur.start_date AND prev.id < cur.id)
                      )
                    WHERE cur.id = :season_id
                    ORDER BY prev.start_date DESC, prev.id DESC
                    LIMIT 1
          ");
          $stmt->execute([':season_id' => $seasonId]);
          return (int)$stmt->fetchColumn();
}

function seedSeasonSponsors(PDO $pdo, int $seasonId, ?int $sourceSeasonId = null): void
{
          ensureSponsorSeasonSchema($pdo);

          $stmt = $pdo->prepare("SELECT COUNT(*) FROM sponsor_seasons WHERE season_id = :season_id");
          $stmt->execute([':season_id' => $seasonId]);
          if ((int)$stmt->fetchColumn() > 0) {
                    return;
          }

          if ($sourceSeasonId === null || $sourceSeasonId <= 0) {
                    $sourceSeasonId = getPreviousSeasonId($pdo, $seasonId);
          }

          if ($sourceSeasonId > 0) {
                    $stmt = $pdo->prepare("
                              SELECT sponsor_id, is_active, joined_at, left_at, notes
                              FROM sponsor_seasons
                              WHERE season_id = :source_season_id
                              ORDER BY sponsor_id ASC
                    ");
                    $stmt->execute([':source_season_id' => $sourceSeasonId]);
                    $sourceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if ($sourceRows) {
                              $ins = $pdo->prepare("
                                        INSERT INTO sponsor_seasons
                                                  (season_id, sponsor_id, is_active, joined_at, left_at, notes)
                                        VALUES
                                                  (:season_id, :sponsor_id, :is_active, :joined_at, :left_at, :notes)
                              ");
                              foreach ($sourceRows as $row) {
                                        $ins->execute([
                                                  ':season_id' => $seasonId,
                                                  ':sponsor_id' => (int)$row['sponsor_id'],
                                                  ':is_active' => (int)$row['is_active'],
                                                  ':joined_at' => $row['joined_at'] ?: null,
                                                  ':left_at' => $row['left_at'] ?: null,
                                                  ':notes' => $row['notes'] ?: null,
                                        ]);
                              }
                              return;
                    }
          }

          $stmt = $pdo->query("SELECT id FROM sponsors ORDER BY name ASC");
          $ins = $pdo->prepare("
                    INSERT INTO sponsor_seasons
                              (season_id, sponsor_id, is_active, joined_at, left_at, notes)
                    VALUES
                              (:season_id, :sponsor_id, 1, NULL, NULL, NULL)
          ");
          foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sponsorId) {
                    $ins->execute([
                              ':season_id' => $seasonId,
                              ':sponsor_id' => (int)$sponsorId,
                    ]);
          }
}

function getSeasonSponsors(PDO $pdo, int $seasonId, bool $activeOnly = false): array
{
          ensureSponsorSeasonSchema($pdo);
          seedSeasonSponsors($pdo, $seasonId);

          $sql = "
                    SELECT s.id, s.name, s.is_active AS sponsor_is_active,
                           ss.is_active AS season_is_active,
                           ss.joined_at, ss.left_at, ss.notes
                    FROM sponsor_seasons ss
                    JOIN sponsors s ON s.id = ss.sponsor_id
                    WHERE ss.season_id = :season_id
          ";
          if ($activeOnly) {
                    $sql .= " AND ss.is_active = 1 AND s.is_active = 1";
          }
          $sql .= " ORDER BY s.name ASC";

          $stmt = $pdo->prepare($sql);
          $stmt->execute([':season_id' => $seasonId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getActiveSponsorsForSeason(PDO $pdo, int $seasonId): array
{
          return getSeasonSponsors($pdo, $seasonId, true);
}

function getSeasons(PDO $pdo): array
{
          ensureSeasonSchema($pdo);
          return $pdo->query("
                    SELECT
                              s.id,
                              s.name,
                              s.start_date,
                              s.end_date,
                              s.is_current,
                              s.is_locked,
                              s.sponsorship_deadline,
                              s.competition_id,
                              s.season_ticket_terms,
                              s.player_home_amount,
                              s.player_away_amount,
                              s.player_third_amount,
                              mc.name AS competition_name,
                              mc.is_league AS competition_is_league
                    FROM seasons s
                    LEFT JOIN match_competitions mc ON mc.id = s.competition_id
                    ORDER BY s.start_date ASC, s.id ASC
          ")->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonById(PDO $pdo, int $seasonId): ?array
{
          ensureSeasonSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT
                              s.id,
                              s.name,
                              s.start_date,
                              s.end_date,
                              s.is_current,
                              s.is_locked,
                              s.sponsorship_deadline,
                              s.competition_id,
                              s.season_ticket_terms,
                              s.player_home_amount,
                              s.player_away_amount,
                              s.player_third_amount,
                              mc.name AS competition_name,
                              mc.is_league AS competition_is_league
                    FROM seasons s
                    LEFT JOIN match_competitions mc ON mc.id = s.competition_id
                    WHERE s.id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $seasonId]);
          $season = $stmt->fetch(PDO::FETCH_ASSOC);
          return $season ?: null;
}

function getCurrentSeason(PDO $pdo): ?array
{
          ensureSeasonSchema($pdo);
          $stmt = $pdo->query("
                    SELECT
                              s.id,
                              s.name,
                              s.start_date,
                              s.end_date,
                              s.is_current,
                              s.is_locked,
                              s.sponsorship_deadline,
                              s.competition_id,
                              s.season_ticket_terms,
                              s.player_home_amount,
                              s.player_away_amount,
                              s.player_third_amount,
                              mc.name AS competition_name,
                              mc.is_league AS competition_is_league
                    FROM seasons s
                    LEFT JOIN match_competitions mc ON mc.id = s.competition_id
                    WHERE s.is_current = 1
                    ORDER BY s.id DESC
                    LIMIT 1
          ");
          $season = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($season) {
                    return $season;
          }
          $stmt = $pdo->query("
                    SELECT
                              s.id,
                              s.name,
                              s.start_date,
                              s.end_date,
                              s.is_current,
                              s.is_locked,
                              s.sponsorship_deadline,
                              s.competition_id,
                              s.season_ticket_terms,
                              s.player_home_amount,
                              s.player_away_amount,
                              s.player_third_amount,
                              mc.name AS competition_name,
                              mc.is_league AS competition_is_league
                    FROM seasons s
                    LEFT JOIN match_competitions mc ON mc.id = s.competition_id
                    ORDER BY s.is_locked ASC, s.start_date DESC, s.id DESC
                    LIMIT 1
          ");
          $season = $stmt->fetch(PDO::FETCH_ASSOC);
          return $season ?: null;
}

function defaultSeasonTicketTermsTemplate(): string
{
          return implode("\n\n", [
                    'SEASON TICKET TERMS & CONDITIONS {season_name}',
                    'Season tickets are issued subject to the following terms and conditions.',
                    'A season ticket allows the named holder admission to Campbell Park for all Saltcoats Victoria home {league_name} fixtures during {season_name}. Campbell Park does not offer reserved seating and a season ticket does not reserve or guarantee any seat, standing position, or viewing location.',
                    'All season tickets are non-transferable and non-refundable. Use of a season ticket for one or more matches attended in person will be considered acceptance of these terms and conditions.',
                    'The club reserves the right to change advertised fixtures without notice or liability. Play cannot be guaranteed to take place on a particular date or at a particular time. Each season ticket is valid for the advertised match date or any date to which a valid fixture is rescheduled.',
                    'Season ticket cards and digital passes remain the property of Saltcoats Victoria FC. If a season ticket card or digital pass is lost, damaged, or cannot be presented at a match, please contact the club for assistance.',
                    'If your contact details change during the season, you must contact the club so your record can be updated.',
                    'Season ticket holders are subject to Campbell Park ground regulations and any instructions issued by club officials, stewards, Police Scotland, the Scottish FA, the West of Scotland Football League, or any other relevant authority.',
                    'The club has a strict behavioural policy. Racial, sectarian, threatening, foul, abusive, or discriminatory behaviour will not be tolerated. Serious misconduct may result in ejection from the ground and cancellation of the season ticket without refund or compensation.',
                    'Entry to the ground is conditional upon season ticket holders consenting to reasonable search procedures and to the confiscation of prohibited items under ground regulations and applicable law.',
                    'Terms and conditions may be updated if required by changes to league rules, safety requirements, public health guidance, or other circumstances outside the club’s control.',
          ]);
}

function seasonTicketTermsTemplateForSeason(?array $season): string
{
          $terms = trim((string) ($season['season_ticket_terms'] ?? ''));
          return $terms !== '' ? $terms : defaultSeasonTicketTermsTemplate();
}

function renderSeasonTicketTermsForSeason(?array $season): string
{
          $seasonName = trim((string) ($season['name'] ?? 'this season'));
          $leagueName = trim((string) ($season['competition_name'] ?? 'league'));
          if ($leagueName === '') {
                    $leagueName = 'league';
          }

          return strtr(seasonTicketTermsTemplateForSeason($season), [
                    '{season_name}' => $seasonName,
                    '{league_name}' => $leagueName,
          ]);
}

function getSelectedSeasonId(PDO $pdo): int
{
          ensureSeasonSchema($pdo);
          if (isset($_GET['season_id'])) {
                    $candidate = (int)$_GET['season_id'];
                    if ($candidate > 0 && getSeasonById($pdo, $candidate)) {
                              $_SESSION['season_id'] = $candidate;
                              return $candidate;
                    }
          }

          if (isset($_POST['season_id'])) {
                    $candidate = (int)$_POST['season_id'];
                    if ($candidate > 0 && getSeasonById($pdo, $candidate)) {
                              $_SESSION['season_id'] = $candidate;
                              return $candidate;
                    }
          }

          if (isset($_SESSION['season_id'])) {
                    $candidate = (int)$_SESSION['season_id'];
                    if ($candidate > 0 && getSeasonById($pdo, $candidate)) {
                              return $candidate;
                    }
          }

          $season = getCurrentSeason($pdo);
          if ($season) {
                    $_SESSION['season_id'] = (int)$season['id'];
                    return (int)$season['id'];
          }

          return 0;
}

function getSeasonContext(PDO $pdo): array
{
          $seasons = getSeasons($pdo);
          $seasonId = getSelectedSeasonId($pdo);
          if ($seasonId <= 0 && $seasons) {
                    $seasonId = (int)$seasons[0]['id'];
          }
          $season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;
          return [
                    'seasons' => $seasons,
                    'season_id' => $seasonId,
                    'season' => $season,
          ];
}

function getSeasonPlayerPricing(PDO $pdo, int $seasonId): array
{
          ensureSeasonSchema($pdo);
          $stmt = $pdo->prepare("
                    SELECT player_home_amount, player_away_amount, player_third_amount
                    FROM seasons
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $seasonId]);
          $row = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!$row) {
                    return [
                              'player_home_amount' => 50.00,
                              'player_away_amount' => 30.00,
                              'player_third_amount' => 20.00,
                    ];
          }

          return [
                    'player_home_amount' => $row['player_home_amount'] !== null ? (float)$row['player_home_amount'] : 50.00,
                    'player_away_amount' => $row['player_away_amount'] !== null ? (float)$row['player_away_amount'] : 30.00,
                    'player_third_amount' => $row['player_third_amount'] !== null ? (float)$row['player_third_amount'] : 20.00,
          ];
}

function saveSeasonPlayerPricing(PDO $pdo, int $seasonId, float $homeAmount, float $awayAmount, float $thirdAmount): void
{
          ensureSeasonSchema($pdo);
          $stmt = $pdo->prepare("
                    UPDATE seasons
                    SET player_home_amount = :home_amount,
                        player_away_amount = :away_amount,
                        player_third_amount = :third_amount
                    WHERE id = :id
                    LIMIT 1
          ");
          $stmt->execute([
                    ':home_amount' => $homeAmount,
                    ':away_amount' => $awayAmount,
                    ':third_amount' => $thirdAmount,
                    ':id' => $seasonId,
          ]);
}

function seasonScopeSql(string $alias = 's', ?int $seasonId = null): string
{
          if ($seasonId === null || $seasonId <= 0) {
                    return '';
          }
          return " AND {$alias}.season_id = :season_id";
}
