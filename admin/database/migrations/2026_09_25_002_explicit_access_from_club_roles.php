<?php
declare(strict_types=1);

// Access-control refactor, phase 5a. Until now a person's current club role
// (Treasurer, Chairman …) silently granted Hub access through
// club_roles.access_template_id. To stop roles granting access (phase 6),
// turn every such grant that a person who can actually log in relies on into
// an explicit access-template assignment on the person, so their effective
// access is identical before and after.
//
// "Can log in" mirrors hub_auth_current_user(): an active account, an active
// person, and a staff-level account role (volunteer / staff / admin).
// People with no login get nothing assigned — the old grant was latent and
// unused, and access should only ever come from being set up deliberately.
//
// Idempotent (INSERT IGNORE on the person/template primary key).

return static function (PDO $pdo): void {
    $pdo->exec(
        "INSERT IGNORE INTO person_access_templates (person_id, template_id)
         SELECT DISTINCT pcr.person_id, cr.access_template_id
         FROM person_club_roles pcr
         JOIN club_roles cr ON cr.id = pcr.club_role_id AND cr.access_template_id IS NOT NULL
         LEFT JOIN seasons s ON s.id = pcr.season_id
         JOIN accounts a ON a.person_id = pcr.person_id AND a.is_active = 1
         JOIN people p ON p.id = pcr.person_id AND p.is_active = 1
         WHERE COALESCE(pcr.start_date, s.start_date, DATE(pcr.assigned_at)) <= CURDATE()
           AND (COALESCE(pcr.end_date, s.end_date) IS NULL OR COALESCE(pcr.end_date, s.end_date) >= CURDATE())
           AND EXISTS (
               SELECT 1 FROM account_roles ar
               JOIN roles r ON r.id = ar.role_id
               WHERE ar.account_id = a.id AND r.code IN ('volunteer', 'staff', 'admin')
           )"
    );
};
