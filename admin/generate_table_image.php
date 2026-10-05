<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/auth.php';
if (!$isCli && !hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/render_lib.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

$output = __DIR__ . '/export/latest_wosfl.png';
$url = APP_ORIGIN . '/admin/league_table_graphic.php?render=1&table_style=compact'
    . '&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);

$result = render_capture_image($url, $output, '.table-card--render', 1080, 1350, '.table-card--render');

if (!$result['ok']) {
    echo $result['error'] . PHP_EOL;
    foreach ($result['output'] as $line) {
        echo $line . PHP_EOL;
    }
    exit(1);
}

echo 'Done: ' . $output . PHP_EOL;
