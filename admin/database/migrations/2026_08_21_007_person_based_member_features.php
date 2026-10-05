<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $indexExists = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index');
        $stmt->execute([':table' => $table, ':index' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint');
        $stmt->execute([':table' => $table, ':constraint' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $tableExists = static function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    };

    $personTables = [
        'motm_votes' => ['column' => 'person_id', 'after' => 'id', 'holder_nullable' => true, 'index' => 'idx_motm_votes_person', 'fk' => 'fk_motm_votes_person', 'delete' => 'CASCADE'],
        'venue_reviews' => ['column' => 'person_id', 'after' => 'id', 'holder_nullable' => true, 'index' => 'idx_venue_reviews_person', 'fk' => 'fk_venue_reviews_person', 'delete' => 'CASCADE'],
        'feedback' => ['column' => 'person_id', 'after' => 'id', 'holder_nullable' => true, 'index' => 'idx_feedback_person', 'fk' => 'fk_feedback_person', 'delete' => 'CASCADE'],
        'hidden_team_teams' => ['column' => 'person_id', 'after' => 'team_name', 'holder_nullable' => true, 'index' => 'idx_hidden_team_teams_person', 'fk' => 'fk_hidden_team_teams_person', 'delete' => 'SET NULL'],
        'season_ticket_free_signup_codes' => ['column' => 'used_by_person_id', 'after' => 'used_count', 'holder_nullable' => false, 'index' => 'idx_season_ticket_free_codes_person', 'fk' => 'fk_season_ticket_free_codes_person', 'delete' => 'SET NULL'],
        'pos_sales' => ['column' => 'person_id', 'after' => 'hub_user_id', 'holder_nullable' => false, 'index' => 'idx_pos_sales_person', 'fk' => 'fk_pos_sales_person', 'delete' => 'SET NULL'],
        'member_points_ledger' => ['column' => 'person_id', 'after' => 'id', 'holder_nullable' => true, 'index' => 'idx_member_points_person', 'fk' => 'fk_member_points_person', 'delete' => 'CASCADE'],
    ];

    foreach ($personTables as $table => $config) {
        if (!$tableExists($table)) {
            continue;
        }

        $personColumn = (string) $config['column'];
        if (!$columnExists($table, $personColumn)) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $personColumn . '` INT UNSIGNED NULL AFTER `' . $config['after'] . '`');
        }

        if (!empty($config['holder_nullable']) && $columnExists($table, 'holder_id')) {
            $pdo->exec('ALTER TABLE `' . $table . '` MODIFY holder_id INT UNSIGNED NULL');
        }

        if (!$indexExists($table, $config['index'])) {
            $indexColumns = $table === 'motm_votes' || $table === 'venue_reviews'
                ? '(' . $personColumn . ', fixture_id)'
                : ($table === 'feedback' || $table === 'member_points_ledger' ? '(' . $personColumn . ', created_at)' : '(' . $personColumn . ')');
            $pdo->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $config['index'] . '` ' . $indexColumns);
        }
    }

    $pdo->exec('UPDATE motm_votes v JOIN identity_migration_map m ON m.old_holder_id = v.holder_id SET v.person_id = m.person_id WHERE v.person_id IS NULL');
    $pdo->exec('UPDATE venue_reviews r JOIN identity_migration_map m ON m.old_holder_id = r.holder_id SET r.person_id = m.person_id WHERE r.person_id IS NULL');
    $pdo->exec('UPDATE feedback f JOIN identity_migration_map m ON m.old_holder_id = f.holder_id SET f.person_id = m.person_id WHERE f.person_id IS NULL');
    $pdo->exec('UPDATE hidden_team_teams t JOIN identity_migration_map m ON m.old_holder_id = t.holder_id SET t.person_id = m.person_id WHERE t.person_id IS NULL');
    $pdo->exec('UPDATE season_ticket_free_signup_codes c JOIN identity_migration_map m ON m.old_holder_id = c.used_by_holder_id SET c.used_by_person_id = m.person_id WHERE c.used_by_person_id IS NULL');
    $pdo->exec('UPDATE pos_sales s JOIN identity_migration_map m ON m.old_holder_id = s.holder_id SET s.person_id = m.person_id WHERE s.person_id IS NULL');
    $pdo->exec('UPDATE member_points_ledger l JOIN identity_migration_map m ON m.old_holder_id = l.holder_id SET l.person_id = m.person_id WHERE l.person_id IS NULL');

    foreach ($personTables as $table => $config) {
        if (!$tableExists($table) || $constraintExists($table, $config['fk'])) {
            continue;
        }
        $pdo->exec('ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $config['fk'] . '` FOREIGN KEY (`' . $config['column'] . '`) REFERENCES people(id) ON DELETE ' . $config['delete']);
    }
};
