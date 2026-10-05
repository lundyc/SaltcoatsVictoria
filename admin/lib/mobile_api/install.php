<?php
declare(strict_types=1);

// Explicit, additive installation of this migration only. Never run unrelated pending migrations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';
$pdo = MyClubHub\Api\connect(MyClubHub\Api\configuration());
$migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_18_001_mobile_api.php';
$migration($pdo);
require_once dirname(__DIR__) . '/migrations.php';
ensureSchemaMigrationsTable($pdo);
$pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)')->execute(['2026_09_18_001_mobile_api.php']);
echo "Mobile API tables installed; existing business tables unchanged.\n";
