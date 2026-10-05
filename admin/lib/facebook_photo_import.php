<?php
declare(strict_types=1);

require_once __DIR__ . '/facebook_publisher.php';
require_once __DIR__ . '/face_match.php';
require_once __DIR__ . '/tagged_people.php';

function facebook_photo_import_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS facebook_photo_imports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            facebook_photo_id VARCHAR(190) NOT NULL,
            facebook_post_id VARCHAR(190) NOT NULL DEFAULT '',
            match_photo_id INT UNSIGNED NOT NULL,
            source_url TEXT NOT NULL,
            post_message TEXT NULL,
            imported_by BIGINT UNSIGNED NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_facebook_photo_imports_photo (facebook_photo_id),
            KEY idx_facebook_photo_imports_match_photo (match_photo_id),
            CONSTRAINT fk_facebook_photo_imports_match_photo FOREIGN KEY (match_photo_id) REFERENCES match_photos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS facebook_photo_rejections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            facebook_photo_id VARCHAR(190) NOT NULL,
            rejected_by BIGINT UNSIGNED NULL,
            rejected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_facebook_photo_rejections_photo (facebook_photo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS facebook_photo_reference_imports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            facebook_photo_id VARCHAR(190) NOT NULL,
            tagged_people_photo_id INT UNSIGNED NOT NULL,
            source_url TEXT NOT NULL,
            imported_by BIGINT UNSIGNED NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_facebook_photo_reference_imports_photo (facebook_photo_id),
            KEY idx_facebook_photo_reference_imports_photo (tagged_people_photo_id),
            CONSTRAINT fk_facebook_photo_reference_imports_photo FOREIGN KEY (tagged_people_photo_id) REFERENCES tagged_people_photos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function facebook_photo_import_normalise(string $value): string
{
    $value = strtolower($value);
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/\b(fc|afc|jfc|cfc|yc|u20s|under\s*20s)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function facebook_photo_import_fixture_label(array $fixture): string
{
    $date = trim((string) ($fixture['match_date'] ?? ''));
    $opponent = trim((string) ($fixture['opponent'] ?? 'Opponent'));
    $homeAway = ((int) ($fixture['is_home'] ?? 1)) === 1 ? 'H' : 'A';
    return ($date !== '' ? date('d M Y', strtotime($date)) . ' - ' : '') . $opponent . ' (' . $homeAway . ')';
}

/**
 * @return list<array<string, mixed>>
 */
function facebook_photo_import_fixtures(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, opponent, match_date, is_home, competition, status
        FROM match_fixtures
        ORDER BY match_date DESC, id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param list<array<string, mixed>> $fixtures
 * @return array{fixture_id:int, confidence:int, reason:string, candidates:list<array{fixture_id:int, confidence:int, reason:string}>}
 */
function facebook_photo_import_suggest_fixture(string $postText, string $createdTime, array $fixtures): array
{
    $text = facebook_photo_import_normalise($postText);
    $postDate = $createdTime !== '' ? strtotime($createdTime) : false;
    $scores = [];

    foreach ($fixtures as $fixture) {
        $fixtureId = (int) ($fixture['id'] ?? 0);
        $opponent = trim((string) ($fixture['opponent'] ?? ''));
        $fixtureDate = strtotime((string) ($fixture['match_date'] ?? ''));
        if ($fixtureId <= 0 || $opponent === '' || $fixtureDate === false) {
            continue;
        }

        $score = 0;
        $reasons = [];
        $opponentNorm = facebook_photo_import_normalise($opponent);
        $opponentWords = array_values(array_filter(explode(' ', $opponentNorm), static fn(string $word): bool => strlen($word) >= 4));

        if ($opponentNorm !== '' && $text !== '' && str_contains($text, $opponentNorm)) {
            $score += 75;
            $reasons[] = 'opponent named in post';
        } elseif ($text !== '' && $opponentWords !== []) {
            $matchedWords = 0;
            foreach ($opponentWords as $word) {
                if (str_contains($text, $word)) {
                    $matchedWords++;
                }
            }
            if ($matchedWords > 0) {
                $score += min(55, 22 * $matchedWords);
                $reasons[] = 'partial opponent match';
            }
        }

        if ($postDate !== false) {
            $days = abs((int) floor(($postDate - $fixtureDate) / 86400));
            if ($days === 0) {
                $score += 28;
                $reasons[] = 'same date';
            } elseif ($days <= 2) {
                $score += 22 - ($days * 4);
                $reasons[] = $days . ' day' . ($days === 1 ? '' : 's') . ' from fixture';
            } elseif ($days <= 7) {
                $score += max(4, 12 - $days);
                $reasons[] = 'near fixture date';
            }
        }

        if ($score > 0) {
            $scores[] = [
                'fixture_id' => $fixtureId,
                'confidence' => min(99, $score),
                'reason' => implode(', ', $reasons),
            ];
        }
    }

    usort($scores, static fn(array $a, array $b): int => ($b['confidence'] <=> $a['confidence']));
    $best = $scores[0] ?? ['fixture_id' => 0, 'confidence' => 0, 'reason' => 'No confident match'];
    return [
        'fixture_id' => (int) $best['fixture_id'],
        'confidence' => (int) $best['confidence'],
        'reason' => (string) $best['reason'],
        'candidates' => array_slice($scores, 0, 5),
    ];
}

function facebook_photo_import_pick_image(array $media): string
{
    $image = is_array($media['image'] ?? null) ? $media['image'] : [];
    return trim((string) ($image['src'] ?? ''));
}

function facebook_photo_import_best_image(array $images): string
{
    $best = '';
    $bestArea = 0;
    foreach ($images as $image) {
        if (!is_array($image)) {
            continue;
        }
        $source = trim((string) ($image['source'] ?? ''));
        $area = max(1, (int) ($image['width'] ?? 0)) * max(1, (int) ($image['height'] ?? 0));
        if ($source !== '' && $area > $bestArea) {
            $best = $source;
            $bestArea = $area;
        }
    }
    return $best;
}

/**
 * @return list<array<string, mixed>>
 */
function facebook_photo_import_extract_attachments(array $attachments): array
{
    $photos = [];
    foreach ((array) ($attachments['data'] ?? []) as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        foreach ((array) (($attachment['subattachments']['data'] ?? null) ?: [$attachment]) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower((string) ($item['type'] ?? ''));
            $url = facebook_photo_import_pick_image(is_array($item['media'] ?? null) ? $item['media'] : []);
            if ($url === '' || (!str_contains($type, 'photo') && !str_contains($type, 'album'))) {
                continue;
            }
            $target = is_array($item['target'] ?? null) ? $item['target'] : [];
            $id = trim((string) ($target['id'] ?? ''));
            if ($id === '') {
                $id = 'url_' . hash('sha256', $url);
            }
            $photos[] = [
                'facebook_photo_id' => $id,
                'image_url' => $url,
                'target_url' => trim((string) ($target['url'] ?? ($item['url'] ?? ''))),
                'type' => $type,
            ];
        }
    }
    return $photos;
}

/**
 * @return array<string, true>
 */
function facebook_photo_import_hidden_ids(PDO $pdo): array
{
    facebook_photo_import_ensure_schema($pdo);
    $hidden = [];
    foreach ($pdo->query('SELECT facebook_photo_id FROM facebook_photo_imports') as $row) {
        $id = trim((string) ($row['facebook_photo_id'] ?? ''));
        if ($id !== '') {
            $hidden[$id] = true;
        }
    }
    foreach ($pdo->query('SELECT facebook_photo_id FROM facebook_photo_reference_imports') as $row) {
        $id = trim((string) ($row['facebook_photo_id'] ?? ''));
        if ($id !== '') {
            $hidden[$id] = true;
        }
    }
    foreach ($pdo->query('SELECT facebook_photo_id FROM facebook_photo_rejections') as $row) {
        $id = trim((string) ($row['facebook_photo_id'] ?? ''));
        if ($id !== '') {
            $hidden[$id] = true;
        }
    }
    return $hidden;
}

/**
 * @return list<array{id:int, name:string, category:string}>
 */
function facebook_photo_import_people(PDO $pdo): array
{
    return array_map(static fn(array $person): array => [
        'id' => (int) $person['id'],
        'name' => (string) $person['name'],
        'category' => (string) $person['category'],
    ], tagged_people_all($pdo));
}

/**
 * @param list<string> $photoIds
 */
function facebook_photo_import_reject(PDO $pdo, array $photoIds, ?int $userId): int
{
    facebook_photo_import_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO facebook_photo_rejections (facebook_photo_id, rejected_by)
        VALUES (:facebook_photo_id, :rejected_by)
    ");
    $count = 0;
    foreach ($photoIds as $photoId) {
        $photoId = trim((string) $photoId);
        if ($photoId === '' || strlen($photoId) > 190) {
            continue;
        }
        $stmt->execute([
            ':facebook_photo_id' => $photoId,
            ':rejected_by' => $userId,
        ]);
        $count += $stmt->rowCount() > 0 ? 1 : 0;
    }
    return $count;
}

/**
 * @return list<array{id:string, name:string, count:int, created_time:string}>
 */
function facebook_photo_import_albums(): array
{
    $token = facebook_resolve_page_token(__DIR__ . '/../logs/facebook_photo_import.log');
    if (!$token['ok']) {
        return [];
    }

    $response = facebook_api_request('GET', $token['graph_base'] . '/' . $token['page_id'] . '/albums', [
        'fields' => 'id,name,count,created_time',
        'limit' => 100,
        'access_token' => $token['token'],
        'appsecret_proof' => $token['proof'],
    ], [
        'timeout' => 60,
        'log_file' => __DIR__ . '/../logs/facebook_photo_import.log',
    ]);
    if (!$response['ok']) {
        return [];
    }

    $albums = [];
    foreach ((array) ($response['body']['data'] ?? []) as $album) {
        if (!is_array($album)) {
            continue;
        }
        $id = trim((string) ($album['id'] ?? ''));
        $name = trim((string) ($album['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $albums[] = [
            'id' => $id,
            'name' => $name,
            'count' => (int) ($album['count'] ?? 0),
            'created_time' => trim((string) ($album['created_time'] ?? '')),
        ];
    }
    usort($albums, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));
    return $albums;
}

/**
 * @param array<string,mixed> $photo
 * @param list<array<string,mixed>> $fixtures
 * @return array<string,mixed>|null
 */
function facebook_photo_import_photo_payload(array $photo, array $fixtures): ?array
{
    $photoId = trim((string) ($photo['id'] ?? ''));
    $imageUrl = facebook_photo_import_best_image((array) ($photo['images'] ?? []));
    if ($photoId === '' || $imageUrl === '') {
        return null;
    }
    $message = trim((string) ($photo['name'] ?? ''));
    $created = trim((string) ($photo['created_time'] ?? ''));
    return [
        'facebook_photo_id' => $photoId,
        'image_url' => $imageUrl,
        'target_url' => trim((string) ($photo['link'] ?? '')),
        'type' => 'photo',
        'facebook_post_id' => '',
        'post_message' => $message,
        'created_time' => $created,
        'permalink_url' => trim((string) ($photo['link'] ?? '')),
        'album_name' => is_array($photo['album'] ?? null) ? trim((string) ($photo['album']['name'] ?? '')) : '',
        'suggestion' => facebook_photo_import_suggest_fixture($message, $created, $fixtures),
    ];
}

/**
 * @return array{ok:bool, error:string, photos:list<array<string,mixed>>, next_after:string}
 */
function facebook_photo_import_fetch(PDO $pdo, int $limit = 25, string $after = '', string $albumId = ''): array
{
    $token = facebook_resolve_page_token(__DIR__ . '/../logs/facebook_photo_import.log');
    if (!$token['ok']) {
        return ['ok' => false, 'error' => (string) $token['error'], 'photos' => [], 'next_after' => ''];
    }

    $limit = max(5, min(500, $limit));
    $photos = [];
    $seen = [];
    $fixtures = facebook_photo_import_fixtures($pdo);
    $hidden = facebook_photo_import_hidden_ids($pdo);

    if ($albumId !== '') {
        if (!preg_match('/^\d+$/', $albumId)) {
            return ['ok' => false, 'error' => 'Choose a valid Facebook album.', 'photos' => [], 'next_after' => ''];
        }
        $cursor = $after;
        $nextAfter = '';
        while (count($photos) < $limit) {
            $albumParams = [
                'fields' => 'id,created_time,name,images,link,album{name}',
                'limit' => min(100, $limit - count($photos)),
                'access_token' => $token['token'],
                'appsecret_proof' => $token['proof'],
            ];
            if ($cursor !== '') {
                $albumParams['after'] = $cursor;
            }
            $albumResponse = facebook_api_request('GET', $token['graph_base'] . '/' . $albumId . '/photos', $albumParams, [
                'timeout' => 60,
                'log_file' => __DIR__ . '/../logs/facebook_photo_import.log',
            ]);
            if (!$albumResponse['ok']) {
                return ['ok' => false, 'error' => (string) ($albumResponse['error']['message'] ?? 'Facebook did not return album photos.'), 'photos' => [], 'next_after' => ''];
            }
            foreach ((array) ($albumResponse['body']['data'] ?? []) as $photo) {
                if (!is_array($photo)) {
                    continue;
                }
                $payload = facebook_photo_import_photo_payload($photo, $fixtures);
                if ($payload !== null && !isset($hidden[(string) $payload['facebook_photo_id']])) {
                    $photos[] = $payload;
                    if (count($photos) >= $limit) {
                        break;
                    }
                }
            }
            $nextAfter = is_array($albumResponse['body']['paging']['cursors'] ?? null)
                ? trim((string) ($albumResponse['body']['paging']['cursors']['after'] ?? ''))
                : '';
            if ($nextAfter === '' || $nextAfter === $cursor) {
                break;
            }
            $cursor = $nextAfter;
        }
        usort($photos, static fn(array $a, array $b): int => strcmp((string) ($b['created_time'] ?? ''), (string) ($a['created_time'] ?? '')));
        return ['ok' => true, 'error' => '', 'photos' => $photos, 'next_after' => $nextAfter];
    }

    $cursor = $after;
    $nextAfter = '';
    $photoResponse = ['ok' => true, 'error' => null];
    while (count($photos) < $limit) {
        $photoParams = [
            'type' => 'uploaded',
            'fields' => 'id,created_time,name,images,link,album{name}',
            'limit' => min(100, $limit - count($photos)),
            'access_token' => $token['token'],
            'appsecret_proof' => $token['proof'],
        ];
        if ($cursor !== '') {
            $photoParams['after'] = $cursor;
        }

        $photoResponse = facebook_api_request('GET', $token['graph_base'] . '/' . $token['page_id'] . '/photos', $photoParams, [
            'timeout' => 60,
            'log_file' => __DIR__ . '/../logs/facebook_photo_import.log',
        ]);
        if (!$photoResponse['ok']) {
            break;
        }
        foreach ((array) ($photoResponse['body']['data'] ?? []) as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $payload = facebook_photo_import_photo_payload($photo, $fixtures);
            if ($payload === null) {
                continue;
            }
            $seen[$payload['facebook_photo_id']] = true;
            if (!isset($hidden[(string) $payload['facebook_photo_id']])) {
                $photos[] = $payload;
                if (count($photos) >= $limit) {
                    break;
                }
            }
        }
        $nextAfter = is_array($photoResponse['body']['paging']['cursors'] ?? null)
            ? trim((string) ($photoResponse['body']['paging']['cursors']['after'] ?? ''))
            : '';
        if ($nextAfter === '' || $nextAfter === $cursor) {
            break;
        }
        $cursor = $nextAfter;
    }

    if ($after !== '') {
        usort($photos, static fn(array $a, array $b): int => strcmp((string) ($b['created_time'] ?? ''), (string) ($a['created_time'] ?? '')));
        return $photoResponse['ok']
            ? ['ok' => true, 'error' => '', 'photos' => $photos, 'next_after' => $nextAfter]
            : ['ok' => false, 'error' => (string) ($photoResponse['error']['message'] ?? 'Facebook did not return older page photos.'), 'photos' => [], 'next_after' => ''];
    }

    $fields = 'id,message,created_time,permalink_url,attachments{media,type,target,url,subattachments{media,type,target,url}}';
    $params = [
        'fields' => $fields,
        'limit' => $limit,
        'access_token' => $token['token'],
        'appsecret_proof' => $token['proof'],
    ];
    if ($after !== '') {
        $params['after'] = $after;
    }

    $response = facebook_api_request('GET', $token['graph_base'] . '/' . $token['page_id'] . '/posts', $params, [
        'timeout' => 60,
        'log_file' => __DIR__ . '/../logs/facebook_photo_import.log',
    ]);
    if (!$response['ok']) {
        if ($photos !== []) {
            usort($photos, static fn(array $a, array $b): int => strcmp((string) ($b['created_time'] ?? ''), (string) ($a['created_time'] ?? '')));
            return ['ok' => true, 'error' => '', 'photos' => $photos, 'next_after' => $nextAfter];
        }
        $message = $response['error']['message'] ?? ($photoResponse['error']['message'] ?? 'Facebook did not return page photos. The token may need page read permissions.');
        return ['ok' => false, 'error' => (string) $message, 'photos' => [], 'next_after' => ''];
    }

    foreach ((array) ($response['body']['data'] ?? []) as $post) {
        if (!is_array($post)) {
            continue;
        }
        $postId = trim((string) ($post['id'] ?? ''));
        $message = trim((string) ($post['message'] ?? ''));
        $created = trim((string) ($post['created_time'] ?? ''));
        $suggestion = facebook_photo_import_suggest_fixture($message, $created, $fixtures);

        foreach (facebook_photo_import_extract_attachments(is_array($post['attachments'] ?? null) ? $post['attachments'] : []) as $photo) {
            $photoId = (string) $photo['facebook_photo_id'];
            if (isset($seen[$photoId])) {
                continue;
            }
            $seen[$photoId] = true;
            if (isset($hidden[$photoId])) {
                continue;
            }
            $photos[] = $photo + [
                'facebook_post_id' => $postId,
                'post_message' => $message,
                'created_time' => $created,
                'permalink_url' => trim((string) ($post['permalink_url'] ?? '')),
                'suggestion' => $suggestion,
            ];
        }
    }

    usort($photos, static fn(array $a, array $b): int => strcmp((string) ($b['created_time'] ?? ''), (string) ($a['created_time'] ?? '')));
    return ['ok' => true, 'error' => '', 'photos' => array_slice($photos, 0, $limit), 'next_after' => $nextAfter];
}

function facebook_photo_import_allowed_source(string $url): bool
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    return (($parts['scheme'] ?? '') === 'https')
        && ($host === 'facebook.com' || str_ends_with($host, '.facebook.com') || str_ends_with($host, 'fbcdn.net') || str_ends_with($host, 'fbsbx.com'));
}

/**
 * @return array{ok:bool, bytes:string, error:string}
 */
function facebook_photo_import_download(string $url): array
{
    if (!facebook_photo_import_allowed_source($url)) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Unsupported image source.'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Could not prepare image download.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Saltcoats Victoria Hub Facebook Importer',
    ]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($bytes) || $bytes === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'bytes' => '', 'error' => $error !== '' ? $error : 'Facebook image download failed.'];
    }
    if (strlen($bytes) > 25_000_000) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Image is larger than 25 MB.'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'error' => ''];
}

/**
 * @param array{x:int,y:int,w:int,h:int} $box
 */
function facebook_photo_import_crop_face(string $bytes, array $box, int $targetSize = 420): string
{
    $source = @imagecreatefromstring($bytes);
    if (!$source) {
        return '';
    }

    $imageWidth = imagesx($source);
    $imageHeight = imagesy($source);
    $faceX = max(0, (int) $box['x']);
    $faceY = max(0, (int) $box['y']);
    $faceW = max(1, (int) $box['w']);
    $faceH = max(1, (int) $box['h']);
    $padX = (int) round($faceW * 0.45);
    $padY = (int) round($faceH * 0.65);
    $cropX = max(0, $faceX - $padX);
    $cropY = max(0, $faceY - $padY);
    $cropRight = min($imageWidth, $faceX + $faceW + $padX);
    $cropBottom = min($imageHeight, $faceY + $faceH + $padY);
    $cropW = max(1, $cropRight - $cropX);
    $cropH = max(1, $cropBottom - $cropY);

    $crop = imagecrop($source, ['x' => $cropX, 'y' => $cropY, 'width' => $cropW, 'height' => $cropH]);
    imagedestroy($source);
    if (!$crop) {
        return '';
    }

    $scale = min($targetSize / imagesx($crop), $targetSize / imagesy($crop));
    $width = max(1, (int) round(imagesx($crop) * $scale));
    $height = max(1, (int) round(imagesy($crop) * $scale));
    $resized = imagescale($crop, $width, $height);
    imagedestroy($crop);
    if (!$resized) {
        return '';
    }

    ob_start();
    imagejpeg($resized, null, 88);
    $cropped = (string) ob_get_clean();
    imagedestroy($resized);
    return $cropped;
}

/**
 * @return array{ok:bool, faces:list<array<string,mixed>>, error:string}
 */
function facebook_photo_import_detect_faces(PDO $pdo, string $imageUrl): array
{
    $download = facebook_photo_import_download($imageUrl);
    if (!$download['ok']) {
        return ['ok' => false, 'faces' => [], 'error' => $download['error']];
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'fb_faces_');
    if ($tmp === false || @file_put_contents($tmp, $download['bytes']) === false) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        return ['ok' => false, 'faces' => [], 'error' => 'Could not prepare image for face detection.'];
    }

    $references = tagged_people_build_references($pdo, __DIR__ . '/../uploads/players', __DIR__ . '/../uploads/tagged_people', __DIR__ . '/../uploads/matches/gallery');
    $peopleById = [];
    foreach (facebook_photo_import_people($pdo) as $person) {
        $peopleById[(int) $person['id']] = $person;
    }
    $facesByPath = face_match_detect_faces([0 => $tmp], $references);
    @unlink($tmp);
    $faces = $facesByPath[0] ?? [];
    foreach ($faces as $index => $face) {
        $thumbnail = facebook_photo_import_crop_face($download['bytes'], $face['box'], 180);
        $faces[$index]['thumbnail'] = $thumbnail !== '' ? 'data:image/jpeg;base64,' . base64_encode($thumbnail) : '';
        $match = is_array($face['match'] ?? null) ? $face['match'] : [];
        $personId = (int) ($match['person_id'] ?? 0);
        if ($personId > 0 && isset($peopleById[$personId])) {
            $score = (float) ($match['score'] ?? 0);
            if ($score >= FACE_MATCH_LIKELY_THRESHOLD) {
                $faces[$index]['person_id'] = $personId;
                $faces[$index]['match_name'] = (string) $peopleById[$personId]['name'];
                $faces[$index]['match_score'] = $score;
            } else {
                $faces[$index]['possible_name'] = (string) $peopleById[$personId]['name'];
                $faces[$index]['possible_score'] = $score;
            }
        }
    }

    return ['ok' => true, 'faces' => $faces, 'error' => ''];
}

/**
 * @param list<array<string,mixed>> $items
 * @return array{imported:int, skipped:int, tagged:int, errors:list<string>}
 */
function facebook_photo_import_save(PDO $pdo, array $items, ?int $userId): array
{
    facebook_photo_import_ensure_schema($pdo);
    @set_time_limit(240);

    $items = array_slice($items, 0, 80);
    $insertPhoto = $pdo->prepare('INSERT INTO match_photos (match_fixture_id, filename, kit) VALUES (:fixture_id, :filename, :kit)');
    $insertImport = $pdo->prepare("
        INSERT INTO facebook_photo_imports (facebook_photo_id, facebook_post_id, match_photo_id, source_url, post_message, imported_by)
        VALUES (:facebook_photo_id, :facebook_post_id, :match_photo_id, :source_url, :post_message, :imported_by)
    ");
    $existing = $pdo->prepare('SELECT match_photo_id FROM facebook_photo_imports WHERE facebook_photo_id = :id LIMIT 1');

    $storedPhotos = [];
    $errors = [];
    $skipped = 0;

    foreach ($items as $index => $item) {
        $fixtureId = (int) ($item['fixture_id'] ?? 0);
        $kit = (string) ($item['kit'] ?? '');
        $sourceUrl = trim((string) ($item['image_url'] ?? ''));
        $facebookPhotoId = trim((string) ($item['facebook_photo_id'] ?? ''));
        $facebookPostId = trim((string) ($item['facebook_post_id'] ?? ''));
        $postMessage = trim((string) ($item['post_message'] ?? ''));

        if ($fixtureId <= 0 || !in_array($kit, ['home', 'away', 'third'], true) || $sourceUrl === '' || $facebookPhotoId === '') {
            $errors[] = 'Item ' . ($index + 1) . ': missing match, kit or image data.';
            continue;
        }
        $fixture = getMatchFixtureById($pdo, $fixtureId);
        if (!$fixture) {
            $errors[] = 'Item ' . ($index + 1) . ': fixture not found.';
            continue;
        }

        $existing->execute([':id' => $facebookPhotoId]);
        if ($existing->fetchColumn()) {
            $skipped++;
            continue;
        }

        $download = facebook_photo_import_download($sourceUrl);
        if (!$download['ok']) {
            $errors[] = 'Item ' . ($index + 1) . ': ' . $download['error'];
            continue;
        }

        $info = @getimagesizefromstring($download['bytes']);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime])) {
            $errors[] = 'Item ' . ($index + 1) . ': downloaded file is not a supported image.';
            continue;
        }

        $directory = __DIR__ . '/../uploads/matches/gallery/' . $fixtureId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $errors[] = 'Item ' . ($index + 1) . ': could not create match gallery folder.';
            continue;
        }

        $filename = 'facebook_' . substr(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $facebookPhotoId) ?? hash('sha256', $facebookPhotoId), 0, 80) . '_' . date('YmdHis') . '.' . $extensions[$mime];
        $path = $directory . '/' . $filename;
        if (@file_put_contents($path, $download['bytes'], LOCK_EX) === false) {
            $errors[] = 'Item ' . ($index + 1) . ': could not save image.';
            continue;
        }

        try {
            $pdo->beginTransaction();
            $insertPhoto->execute([':fixture_id' => $fixtureId, ':filename' => $filename, ':kit' => $kit]);
            $matchPhotoId = (int) $pdo->lastInsertId();
            $insertImport->execute([
                ':facebook_photo_id' => $facebookPhotoId,
                ':facebook_post_id' => $facebookPostId,
                ':match_photo_id' => $matchPhotoId,
                ':source_url' => $sourceUrl,
                ':post_message' => $postMessage,
                ':imported_by' => $userId,
            ]);
            $pdo->commit();
            $storedPhotos[$matchPhotoId] = $path;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($path);
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                $skipped++;
            } else {
                $errors[] = 'Item ' . ($index + 1) . ': database save failed.';
            }
        }
    }

    $taggedCount = 0;
    if ($storedPhotos) {
        $references = tagged_people_build_references($pdo, __DIR__ . '/../uploads/players', __DIR__ . '/../uploads/tagged_people', __DIR__ . '/../uploads/matches/gallery');
        $tagsByPhotoId = face_match_get_tags($references, $storedPhotos);
        if ($tagsByPhotoId) {
            $tagStmt = $pdo->prepare("
                INSERT INTO match_photo_tags (match_photo_id, tagged_person_id, confidence, source)
                VALUES (:photo_id, :person_id, :confidence, 'auto')
                ON DUPLICATE KEY UPDATE confidence = GREATEST(confidence, VALUES(confidence))
            ");
            foreach ($tagsByPhotoId as $photoId => $tags) {
                foreach ($tags as $tag) {
                    if ($tag['score'] < FACE_MATCH_LIKELY_THRESHOLD) {
                        continue;
                    }
                    $tagStmt->execute([
                        ':photo_id' => (int) $photoId,
                        ':person_id' => (int) $tag['person_id'],
                        ':confidence' => (float) $tag['score'],
                    ]);
                    $taggedCount++;
                }
            }
        }
    }

    return ['imported' => count($storedPhotos), 'skipped' => $skipped, 'tagged' => $taggedCount, 'errors' => $errors];
}

/**
 * @param list<array<string,mixed>> $items
 * @return array{imported:int, skipped:int, tagged:int, errors:list<string>}
 */
function facebook_photo_import_save_references(PDO $pdo, array $items, ?int $userId): array
{
    facebook_photo_import_ensure_schema($pdo);
    @set_time_limit(180);

    $items = array_slice($items, 0, 80);
    $insertPhoto = $pdo->prepare('INSERT INTO tagged_people_photos (tagged_person_id, filename) VALUES (:person_id, :filename)');
    $insertImport = $pdo->prepare("
        INSERT INTO facebook_photo_reference_imports (facebook_photo_id, tagged_people_photo_id, source_url, imported_by)
        VALUES (:facebook_photo_id, :tagged_people_photo_id, :source_url, :imported_by)
    ");
    $existing = $pdo->prepare('
        SELECT facebook_photo_id FROM facebook_photo_imports WHERE facebook_photo_id = :id
        UNION ALL
        SELECT facebook_photo_id FROM facebook_photo_reference_imports WHERE facebook_photo_id = :id
        LIMIT 1
    ');
    $personStmt = $pdo->prepare('SELECT id FROM tagged_people WHERE id = :id LIMIT 1');

    $errors = [];
    $skipped = 0;
    $imported = 0;

    foreach ($items as $index => $item) {
        $referenceFaces = is_array($item['reference_faces'] ?? null) ? array_values($item['reference_faces']) : [];
        $personId = (int) ($item['person_id'] ?? 0);
        $sourceUrl = trim((string) ($item['image_url'] ?? ''));
        $facebookPhotoId = trim((string) ($item['facebook_photo_id'] ?? ''));

        if (($personId <= 0 && $referenceFaces === []) || $sourceUrl === '' || $facebookPhotoId === '') {
            $errors[] = 'Item ' . ($index + 1) . ': choose a person for this reference photo.';
            continue;
        }
        $existing->execute([':id' => $facebookPhotoId]);
        if ($existing->fetchColumn()) {
            $skipped++;
            continue;
        }

        $download = facebook_photo_import_download($sourceUrl);
        if (!$download['ok']) {
            $errors[] = 'Item ' . ($index + 1) . ': ' . $download['error'];
            continue;
        }

        $info = @getimagesizefromstring($download['bytes']);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime])) {
            $errors[] = 'Item ' . ($index + 1) . ': downloaded file is not a supported image.';
            continue;
        }

        $directory = __DIR__ . '/../uploads/tagged_people';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $errors[] = 'Item ' . ($index + 1) . ': could not create people reference folder.';
            continue;
        }

        $saved = [];
        $facesToSave = [];
        if ($referenceFaces !== []) {
            foreach ($referenceFaces as $faceIndex => $face) {
                if (!is_array($face)) {
                    continue;
                }
                $facePersonId = (int) ($face['person_id'] ?? 0);
                $box = is_array($face['box'] ?? null) ? $face['box'] : [];
                if ($facePersonId <= 0 || $box === []) {
                    continue;
                }
                $personStmt->execute([':id' => $facePersonId]);
                if (!$personStmt->fetchColumn()) {
                    $errors[] = 'Item ' . ($index + 1) . ', face ' . ($faceIndex + 1) . ': person not found.';
                    continue;
                }
                $croppedBytes = facebook_photo_import_crop_face($download['bytes'], [
                    'x' => (int) ($box['x'] ?? 0),
                    'y' => (int) ($box['y'] ?? 0),
                    'w' => (int) ($box['w'] ?? 1),
                    'h' => (int) ($box['h'] ?? 1),
                ]);
                if ($croppedBytes === '') {
                    $errors[] = 'Item ' . ($index + 1) . ', face ' . ($faceIndex + 1) . ': could not crop face.';
                    continue;
                }
                $facesToSave[] = ['person_id' => $facePersonId, 'bytes' => $croppedBytes, 'extension' => 'jpg'];
            }
            if ($facesToSave === []) {
                $errors[] = 'Item ' . ($index + 1) . ': assign at least one detected face to a person.';
                continue;
            }
        } else {
            $personStmt->execute([':id' => $personId]);
            if (!$personStmt->fetchColumn()) {
                $errors[] = 'Item ' . ($index + 1) . ': person not found.';
                continue;
            }
            $facesToSave[] = ['person_id' => $personId, 'bytes' => $download['bytes'], 'extension' => $extensions[$mime]];
        }

        try {
            $pdo->beginTransaction();
            $firstTaggedPeoplePhotoId = 0;
            $importedThisItem = 0;
            foreach ($facesToSave as $faceIndex => $faceToSave) {
                $facePersonId = (int) $faceToSave['person_id'];
                $filename = 'person_' . $facePersonId . '_facebook_' . substr(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $facebookPhotoId) ?? hash('sha256', $facebookPhotoId), 0, 62) . '_' . date('YmdHis') . '_' . ($faceIndex + 1) . '.' . $faceToSave['extension'];
                $path = $directory . '/' . $filename;
                if (@file_put_contents($path, (string) $faceToSave['bytes'], LOCK_EX) === false) {
                    throw new RuntimeException('Could not save reference image.');
                }
                $saved[] = $path;
                $insertPhoto->execute([':person_id' => $facePersonId, ':filename' => $filename]);
                $taggedPeoplePhotoId = (int) $pdo->lastInsertId();
                if ($firstTaggedPeoplePhotoId === 0) {
                    $firstTaggedPeoplePhotoId = $taggedPeoplePhotoId;
                }
                $importedThisItem++;
            }
            if ($firstTaggedPeoplePhotoId > 0) {
                $insertImport->execute([
                    ':facebook_photo_id' => $facebookPhotoId,
                    ':tagged_people_photo_id' => $firstTaggedPeoplePhotoId,
                    ':source_url' => $sourceUrl,
                    ':imported_by' => $userId,
                ]);
            }
            $pdo->commit();
            $imported += $importedThisItem;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($saved as $path) {
                @unlink($path);
            }
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                $skipped++;
            } else {
                $errors[] = 'Item ' . ($index + 1) . ': ' . ($e instanceof RuntimeException ? $e->getMessage() : 'database save failed.');
            }
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'tagged' => 0, 'errors' => $errors];
}
