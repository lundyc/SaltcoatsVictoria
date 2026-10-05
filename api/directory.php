<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/players_lib.php';
require_once __DIR__ . '/../admin/sponsors_lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * This endpoint is unauthenticated and publicly reachable, so it must never
 * carry personal contact details — only fields intended for public display.
 */
function api_directory_public_player(array $player): array
{
    unset($player['dob']);

    return $player;
}

function api_directory_public_sponsor(array $sponsor): array
{
    unset($sponsor['contact_name'], $sponsor['contact_email'], $sponsor['contact_phone'], $sponsor['notes']);

    return $sponsor;
}

echo json_encode([
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'players' => array_map('api_directory_public_player', players_load_all()),
    'sponsors' => array_map('api_directory_public_sponsor', sponsors_load_all()),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
