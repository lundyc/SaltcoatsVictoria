<?php
// lib/face_match.php — Suggests player matches for newly uploaded photos by comparing
// them against players' existing reference photos (profile picture + action shots),
// using a small Python/OpenCV helper (see hub/tools/face_match.py). Always degrades
// gracefully to "no suggestions" if the helper, its models, or the Python venv are
// unavailable — it never blocks the upload flow.

declare(strict_types=1);

// Confidence bands, tuned around the SFace model's documented same-person cosine-
// similarity threshold (~0.363). "Likely" matches are safe to pre-select/auto-apply;
// "possible" matches are worth showing as a hint but should not be applied automatically.
const FACE_MATCH_LIKELY_THRESHOLD = 0.45;
const FACE_MATCH_POSSIBLE_THRESHOLD = 0.30;

/**
 * Builds the reference photo set (player_id => photo path) used as candidates for
 * face matching: each player's profile picture plus every action shot they have.
 *
 * @return list<array{player_id:int, path:string}>
 */
function face_match_build_references(PDO $pdo, string $playersUploadDir): array
{
    $references = [];

    try {
        $avatarStmt = $pdo->query("SELECT id, avatar FROM players WHERE avatar IS NOT NULL AND avatar <> ''");
        foreach ($avatarStmt as $row) {
            $path = $playersUploadDir . '/' . basename((string) $row['avatar']);
            if (is_file($path)) {
                $references[] = ['player_id' => (int) $row['id'], 'path' => $path];
            }
        }

        $actionShotStmt = $pdo->query("SELECT player_id, filename FROM player_action_shots");
        foreach ($actionShotStmt as $row) {
            $path = $playersUploadDir . '/action_shots/' . basename((string) $row['filename']);
            if (is_file($path)) {
                $references[] = ['player_id' => (int) $row['player_id'], 'path' => $path];
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $references;
}

/**
 * Runs hub/tools/face_match.py with the given references/queries/mode and returns the
 * decoded JSON response, or null if the helper is unavailable or the call failed.
 *
 * @param list<array{player_id:int, path:string}> $references
 * @param list<string> $queryPaths
 */
function face_match_run(array $references, array $queryPaths, string $mode, int $timeoutSeconds): ?array
{
    if ((!$references && $mode !== 'detect') || !$queryPaths) {
        return null;
    }

    $pythonBin = __DIR__ . '/../tools/facematch_venv/bin/python';
    $script = __DIR__ . '/../tools/face_match.py';
    $timeoutBin = '/usr/bin/timeout';
    // Under the web server's PHP-FPM pool, open_basedir is scoped to the vhost
    // (+ /tmp). /usr/bin/timeout lives outside it outright, and the venv's
    // python is a symlink chain that resolves to /usr/bin/python3 — also
    // outside it — so is_file() (which follows symlinks) reports false for
    // both even though they're perfectly runnable. proc_open() executing a
    // path is NOT subject to open_basedir the way filesystem checks are, so
    // skip the pre-check for anything outside the vhost and let proc_open
    // itself fail (returning null below) if a path is genuinely missing.
    // Only $script — a real file inside the vhost — is safe to pre-check.
    if (!is_file($script)) {
        return null;
    }

    $requestFile = @tempnam(sys_get_temp_dir(), 'facematch_req_');
    if ($requestFile === false) {
        return null;
    }

    $written = @file_put_contents($requestFile, json_encode([
        'mode' => $mode,
        'references' => $references,
        'queries' => $queryPaths,
    ]));

    if ($written === false) {
        @unlink($requestFile);
        return null;
    }

    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $command = [$timeoutBin, (string) $timeoutSeconds, $pythonBin, $script, $requestFile];
    $process = @proc_open($command, $descriptorSpec, $pipes);

    $output = '';
    if (is_resource($process)) {
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    @unlink($requestFile);

    $decoded = json_decode($output, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param list<array{player_id:int, path:string}> $references
 * @param array<int, string> $queryPathsByKey absolute image paths, keyed however the caller likes
 * @return array<string, array{player_id:int, score:float}> suggestions keyed the same way as $queryPathsByKey
 */
function face_match_get_suggestions(array $references, array $queryPathsByKey): array
{
    if (!$queryPathsByKey) {
        return [];
    }

    $pathToKey = array_flip($queryPathsByKey);
    $decoded = face_match_run($references, array_values($queryPathsByKey), 'match', 25);

    if (!$decoded || !isset($decoded['matches']) || !is_array($decoded['matches'])) {
        return [];
    }

    $suggestions = [];
    foreach ($decoded['matches'] as $path => $match) {
        if (!isset($pathToKey[$path]) || !is_array($match) || !isset($match['player_id'], $match['score'])) {
            continue;
        }
        $suggestions[$pathToKey[$path]] = [
            'player_id' => (int) $match['player_id'],
            'score' => (float) $match['score'],
        ];
    }

    return $suggestions;
}

/**
 * Detects every face in each query photo (e.g. a match action shot with several
 * people in frame) and matches each one independently against the reference set.
 *
 * @param list<array{player_id:int, path:string}> $references identifier field is a
 *   generic reference id (e.g. a tagged_people.id) — "player_id" is just the wire
 *   format hub/tools/face_match.py expects.
 * @param array<int, string> $queryPathsByKey absolute image paths, keyed however the caller likes
 * @return array<string, list<array{person_id:int, score:float}>> candidate tags keyed the same way as $queryPathsByKey
 */
function face_match_get_tags(array $references, array $queryPathsByKey): array
{
    if (!$queryPathsByKey) {
        return [];
    }

    $pathToKey = array_flip($queryPathsByKey);
    $decoded = face_match_run($references, array_values($queryPathsByKey), 'tag', 60);

    if (!$decoded || !isset($decoded['tags']) || !is_array($decoded['tags'])) {
        return [];
    }

    $tagsByKey = [];
    foreach ($decoded['tags'] as $path => $tags) {
        if (!isset($pathToKey[$path]) || !is_array($tags)) {
            continue;
        }
        $key = $pathToKey[$path];
        $tagsByKey[$key] = [];
        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag['player_id'], $tag['score'])) {
                continue;
            }
            $tagsByKey[$key][] = [
                'person_id' => (int) $tag['player_id'],
                'score' => (float) $tag['score'],
            ];
        }
    }

    return $tagsByKey;
}

/**
 * Detects visible faces and returns crop boxes for each query photo.
 *
 * @param array<int, string> $queryPathsByKey absolute image paths, keyed however the caller likes
 * @param list<array{player_id:int, path:string}> $references
 * @return array<string, list<array{box:array{x:int,y:int,w:int,h:int}, confidence:float, match?:array{person_id:int, score:float}}>>
 */
function face_match_detect_faces(array $queryPathsByKey, array $references = []): array
{
    if (!$queryPathsByKey) {
        return [];
    }

    $pathToKey = array_flip($queryPathsByKey);
    $decoded = face_match_run($references, array_values($queryPathsByKey), 'detect', 45);

    if (!$decoded || !isset($decoded['faces']) || !is_array($decoded['faces'])) {
        return [];
    }

    $facesByKey = [];
    foreach ($decoded['faces'] as $path => $faces) {
        if (!isset($pathToKey[$path]) || !is_array($faces)) {
            continue;
        }
        $key = $pathToKey[$path];
        $facesByKey[$key] = [];
        foreach ($faces as $face) {
            if (!is_array($face) || !isset($face['box']) || !is_array($face['box'])) {
                continue;
            }
            $box = $face['box'];
            $facePayload = [
                'box' => [
                    'x' => max(0, (int) ($box['x'] ?? 0)),
                    'y' => max(0, (int) ($box['y'] ?? 0)),
                    'w' => max(1, (int) ($box['w'] ?? 1)),
                    'h' => max(1, (int) ($box['h'] ?? 1)),
                ],
                'confidence' => (float) ($face['confidence'] ?? 0),
            ];
            if (isset($face['match']) && is_array($face['match'])) {
                $facePayload['match'] = [
                    'person_id' => (int) ($face['match']['player_id'] ?? 0),
                    'score' => (float) ($face['match']['score'] ?? 0),
                ];
            }
            $facesByKey[$key][] = $facePayload;
        }
    }

    return $facesByKey;
}
