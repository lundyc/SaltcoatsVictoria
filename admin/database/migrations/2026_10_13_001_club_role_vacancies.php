<?php
declare(strict_types=1);

// Lets the public Vacancies page list any club role with no current holder.
// vacancy_hidden = 1 keeps a role off that page; vacancy_blurb is the "what's involved" text. Idempotent.

return static function (PDO $pdo): void {
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM club_roles') as $c) {
        $have[(string) $c['Field']] = true;
    }
    if (!isset($have['vacancy_hidden'])) {
        $pdo->exec('ALTER TABLE club_roles ADD COLUMN vacancy_hidden TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!isset($have['vacancy_blurb'])) {
        $pdo->exec('ALTER TABLE club_roles ADD COLUMN vacancy_blurb TEXT NULL');
    }
};
