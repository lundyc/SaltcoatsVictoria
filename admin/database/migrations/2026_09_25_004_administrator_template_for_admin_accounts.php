<?php
declare(strict_types=1);

// Access-control refactor, phase 6. "Admin" stops being a special flag on the
// account and becomes an ordinary access template (Administrator, bypass_all)
// assigned to the person like any other. Give it to every person whose account
// currently has the admin role so they keep exactly the access they have now.
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec(
        "INSERT IGNORE INTO person_access_templates (person_id, template_id)
         SELECT DISTINCT a.person_id, t.id
         FROM accounts a
         JOIN account_roles ar ON ar.account_id = a.id
         JOIN roles r ON r.id = ar.role_id AND r.code = 'admin'
         JOIN access_templates t ON t.slug = 'admin' AND t.bypass_all = 1"
    );
};
