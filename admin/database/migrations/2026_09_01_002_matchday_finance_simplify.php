<?php

declare(strict_types=1);

/**
 * Simplify the matchday balance sheet: gate / bar / catering / merchandise
 * income is now derived from each area's (counted at close − starting
 * float), so the four standalone income_* columns for those areas are dead.
 * Drop them if the earlier CREATE TABLE added them.
 */
return static function (PDO $pdo): void {
    $drop = ['income_gate', 'income_bar', 'income_catering', 'income_merchandise'];

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM matchday_finance') as $row) {
        $existing[(string) $row['Field']] = true;
    }

    foreach ($drop as $column) {
        if (isset($existing[$column])) {
            $pdo->exec("ALTER TABLE matchday_finance DROP COLUMN {$column}");
        }
    }
};
