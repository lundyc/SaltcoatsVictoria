<?php

declare(strict_types=1);

/**
 * Matchday balance sheet — one financial return per home fixture. The table
 * definition lives in lib/matchday_finance.php (matchday_finance_ensure_schema),
 * which the pages also call on first use, so this migration is just the
 * formal record of when it landed.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/matchday_finance.php';

    matchday_finance_ensure_schema($pdo);
};
