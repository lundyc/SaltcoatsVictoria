<?php
declare(strict_types=1);

/**
 * person_positions today is a pure "currently assigned" set: composite PK
 * (person_id, position_id), no season, and the only writer
 * (setPersonPositions()) replaces it wholesale on every save -- unchecking a
 * box deletes the row, so there's no way to keep history from season to
 * season. This migration adds season_id + notes and a proper auto-increment
 * id, so the same position can recur across different seasons and old rows
 * are never overwritten by a new season's assignment.
 */
return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE person_positions
        ADD COLUMN season_id INT UNSIGNED NULL AFTER position_id,
        ADD COLUMN notes VARCHAR(255) NULL AFTER season_id");

    // Backfill: every existing row represents a position held today, so it
    // belongs to whichever season is currently marked active.
    $currentSeasonId = (int) $pdo->query(
        "SELECT id FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    if ($currentSeasonId > 0) {
        $pdo->exec("UPDATE person_positions SET season_id = {$currentSeasonId} WHERE season_id IS NULL");
    }

    $pdo->exec("ALTER TABLE person_positions MODIFY COLUMN season_id INT UNSIGNED NOT NULL");

    // Provides an index with person_id as the leftmost column before the old
    // composite PRIMARY KEY (which was the only such index) gets dropped
    // below -- required to keep fk_person_positions_person satisfiable.
    $pdo->exec("ALTER TABLE person_positions
        ADD UNIQUE KEY uq_person_positions_person_position_season (person_id, position_id, season_id)");

    $pdo->exec("ALTER TABLE person_positions DROP PRIMARY KEY");

    $pdo->exec("ALTER TABLE person_positions
        ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ADD PRIMARY KEY (id)");

    $pdo->exec("ALTER TABLE person_positions
        ADD CONSTRAINT fk_person_positions_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE RESTRICT");
};
