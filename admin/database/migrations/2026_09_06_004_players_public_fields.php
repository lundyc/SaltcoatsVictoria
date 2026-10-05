<?php

declare(strict_types=1);

/**
 * Website profile fields on `players`: squad_number, nationality, bio,
 * website_slug. Additions defined in lib/squad_public.php.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/squad_public.php';
    squad_public_ensure_schema($pdo);
};
