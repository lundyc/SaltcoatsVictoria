<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/lib/social_directory_sync.php';

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    header('Content-Type: text/plain; charset=UTF-8');

    $result = player_sponsors_sync_social_directory();
    if (!$result['ok']) {
        http_response_code(500);
        echo "Export failed: could not write one or more cache files.\n";
        exit;
    }

    echo "Export complete\n";
    echo "Players exported: " . $result['players'] . "\n";
    echo "Sponsors exported: " . $result['sponsors'] . "\n";
}
