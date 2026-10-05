<?php

declare(strict_types=1);

/*
 * Rebuilds the players.json / sponsors.json cache the social tools read.
 * Pure library: NO access check here — every caller must already be
 * authorised for its own reasons. (sync_social_directory.php wraps this with
 * a content_social gate for the pages that have always required it.)
 */
require_once __DIR__ . '/directory.php';

const SOCIALS_PLAYERS_CACHE = __DIR__ . '/../data/players.json';
const SOCIALS_SPONSORS_CACHE = __DIR__ . '/../data/sponsors.json';

function write_json_file(string $path, array $rows): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @return array{ok: bool, players: int, sponsors: int}
 */
function player_sponsors_sync_social_directory(): array
{
    $players = player_sponsors_directory_players();
    $sponsors = player_sponsors_directory_sponsors();

    $playersOk = write_json_file(SOCIALS_PLAYERS_CACHE, $players);
    $sponsorsOk = write_json_file(SOCIALS_SPONSORS_CACHE, $sponsors);

    return [
        'ok' => $playersOk && $sponsorsOk,
        'players' => count($players),
        'sponsors' => count($sponsors),
    ];
}
