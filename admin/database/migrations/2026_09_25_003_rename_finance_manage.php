<?php
declare(strict_types=1);

// finance_refunds was too narrow: the split is really "look" vs "change money"
// (record/edit/delete payments, mark paid, Stripe links, refunds). Rename it to
// finance_manage before any code depends on the old slug. Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("UPDATE capabilities
        SET slug = 'finance_manage', label = 'Finance — record and change payments, refunds and Stripe actions'
        WHERE slug = 'finance_refunds'
          AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM capabilities WHERE slug = 'finance_manage') x)");
    $pdo->exec("UPDATE capabilities SET label = 'Finance — view reports, income and payments (read-only)' WHERE slug = 'finance_view'");
    $pdo->exec("UPDATE capabilities SET label = 'Ticketing — refunds and complimentary tickets' WHERE slug = 'tickets_refund_comp'");
};
