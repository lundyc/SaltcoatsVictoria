<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Migration runner is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/migrations.php';

$directory = __DIR__ . '/migrations';
$results = runDatabaseMigrations($pdo, $directory);
$exitCode = 0;

foreach ($results as $result) {
    echo '[' . $result['status'] . '] ' . $result['migration'] . ' - ' . $result['message'] . PHP_EOL;
    if ($result['status'] === 'failed') {
        $exitCode = 1;
        break;
    }
}

if ($results === []) {
    echo "No migrations found.\n";
}

exit($exitCode);
