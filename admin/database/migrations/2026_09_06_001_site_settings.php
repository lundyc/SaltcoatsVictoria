<?php

declare(strict_types=1);

/**
 * site_settings key/value store for public-website configuration.
 * Table defined in lib/site_settings.php (site_settings_ensure_schema).
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/site_settings.php';
    site_settings_ensure_schema($pdo);
};
