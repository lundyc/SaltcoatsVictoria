<?php
declare(strict_types=1);

// Match report PDFs carry officials' names but not COMET person or match ids, so
// both ids become optional and an official's appointment is also unique per
// (date, home team, away team, official, role). Existing rows are unaffected. Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec('ALTER TABLE comet_officials MODIFY comet_person_id BIGINT UNSIGNED NULL');
    $pdo->exec('ALTER TABLE comet_match_officials MODIFY comet_match_id BIGINT UNSIGNED NULL');
    $has = $pdo->query("SHOW INDEX FROM comet_match_officials WHERE Key_name = 'uq_match_day_official_role'")->fetch();
    if (!$has) {
        $pdo->exec('ALTER TABLE comet_match_officials ADD UNIQUE KEY uq_match_day_official_role (match_date, home_team, away_team, official_id, role)');
    }
};
