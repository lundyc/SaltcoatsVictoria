<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'login_disabled_manually'"
    );
    if ((int) $column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE accounts ADD COLUMN login_disabled_manually TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }
};
