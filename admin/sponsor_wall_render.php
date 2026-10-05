<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/lib/sponsor_wall.php';

$layout = sponsors_current_wall_layout();
$settings = sponsors_current_wall_settings();
$download = isset($_GET['download']) && $_GET['download'] !== '' && $_GET['download'] !== '0';

sponsors_render_wall($layout, $download, $settings);
