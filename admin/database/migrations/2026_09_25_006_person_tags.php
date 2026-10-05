<?php
declare(strict_types=1);

// Manual labels for the few relationships to the club that cannot be derived
// from other data (life member, sponsor contact, player …). Everything else — 
// committee, football staff, volunteer, season-ticket holder — is derived from
// existing tables by lib/affiliations.php, never stored twice. Tags are labels
// only and grant nothing. Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS person_tags (
        person_id INT UNSIGNED NOT NULL,
        tag VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (person_id, tag),
        CONSTRAINT fk_person_tags_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
