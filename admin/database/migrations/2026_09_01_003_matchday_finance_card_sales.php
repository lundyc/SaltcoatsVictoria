<?php

declare(strict_types=1);

/**
 * Add a single "card sales (all areas)" figure to the matchday balance
 * sheet — the card reader's report is one combined total, not split by till.
 */
return static function (PDO $pdo): void {
    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM matchday_finance') as $row) {
        $existing[(string) $row['Field']] = true;
    }

    if (!isset($existing['income_card_sales'])) {
        $pdo->exec(
            "ALTER TABLE matchday_finance
             ADD COLUMN income_card_sales DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cash_close_merch"
        );
    }
};
