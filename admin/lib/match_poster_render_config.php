<?php

declare(strict_types=1);

// Shared secret between match_poster_render.php (staff-authenticated) and
// match_poster_fragment.php (the bare page the server-side headless browser
// screenshots — it has no session to check, so this token is what stops it
// being a random open page instead). Not a user-facing credential; never
// logged or displayed. Rotate by changing MATCH_POSTER_RENDER_SECRET in .env if
// it's ever exposed.
require_once __DIR__ . '/../env.php';

$matchPosterRenderEnv = app_parse_env_file(__DIR__ . '/../.env');
$matchPosterRenderSecret = getenv('MATCH_POSTER_RENDER_SECRET');
if ($matchPosterRenderSecret === false) {
    $matchPosterRenderSecret = $matchPosterRenderEnv['MATCH_POSTER_RENDER_SECRET'] ?? '';
}

define('MATCH_POSTER_RENDER_SECRET', (string) $matchPosterRenderSecret);
