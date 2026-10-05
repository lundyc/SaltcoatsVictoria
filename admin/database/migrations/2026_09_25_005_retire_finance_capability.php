<?php
declare(strict_types=1);

// The old all-in-one 'finance' capability was replaced by finance_view and
// finance_manage (migrations 001/003) and no code checks it any more. Remove it
// from the catalog so it stops appearing in the templates grid and override
// picker. Template links and overrides for it go with it (ON DELETE CASCADE).
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("DELETE FROM capabilities WHERE slug = 'finance'");
};
