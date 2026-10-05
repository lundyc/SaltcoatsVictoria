<?php

declare(strict_types=1);

// Shared secret for the *_graphic.php pages the server-side headless browser
// (render.js) screenshots for downloads and social posting. Those requests
// have no session cookie, so this token — checked before the normal
// hub_auth_has_capability() guard — is what lets them through instead of
// exposing the pages unauthenticated. Not a user-facing credential; never
// logged or displayed. Rotate by changing RENDER_ACCESS_TOKEN in .env if
// it's ever exposed.
require_once __DIR__ . '/../env.php';

$renderAccessTokenEnv = app_parse_env_file(__DIR__ . '/../.env');
$renderAccessToken = getenv('RENDER_ACCESS_TOKEN');
if ($renderAccessToken === false) {
    $renderAccessToken = $renderAccessTokenEnv['RENDER_ACCESS_TOKEN'] ?? '';
}

define('RENDER_ACCESS_TOKEN', (string) $renderAccessToken);
