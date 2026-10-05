<?php

declare(strict_types=1);

/**
 * club_pages: editable static content for the public site, seeded with the
 * history / ground / officials starter pages. Table defined in lib/club_pages.php.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/club_pages.php';
    club_pages_ensure_schema($pdo);
    club_pages_seed($pdo);
};
