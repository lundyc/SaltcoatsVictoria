<?php
declare(strict_types=1);

require_once __DIR__ . '/match_sponsorship.php';
require_once __DIR__ . '/template_packs.php';

/** @return array<string, array{value:string,label:string,count:int}> */
function monthlyFixturesSeasonMonths(PDO $pdo, int $seasonId): array
{
    $months = [];
    foreach (getMatchFixtures($pdo, $seasonId) as $fixture) {
        $timestamp = strtotime((string)($fixture['match_date'] ?? ''));
        if ($timestamp === false) {
            continue;
        }
        $value = date('Y-m', $timestamp);
        if (!isset($months[$value])) {
            $months[$value] = [
                'value' => $value,
                'label' => date('F Y', $timestamp),
                'count' => 0,
            ];
        }
        $months[$value]['count']++;
    }
    ksort($months);
    return $months;
}

/** @param array<string, array{value:string,label:string,count:int}> $months */
function monthlyFixturesSelectMonth(array $months, ?string $requestedMonth = null): string
{
    $requestedMonth = trim((string)$requestedMonth);
    if ($requestedMonth !== '' && isset($months[$requestedMonth])) {
        return $requestedMonth;
    }
    if (!$months) {
        return date('Y-m');
    }

    $currentMonth = date('Y-m');
    if (isset($months[$currentMonth])) {
        return $currentMonth;
    }
    foreach (array_keys($months) as $month) {
        if ($month >= $currentMonth) {
            return $month;
        }
    }
    return (string)array_key_first($months);
}

/** @return list<array<string, mixed>> */
function monthlyFixturesForMonth(PDO $pdo, int $seasonId, string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        return [];
    }
    return array_values(array_filter(
        getMatchFixtures($pdo, $seasonId),
        static fn(array $fixture): bool => str_starts_with((string)($fixture['match_date'] ?? ''), $month . '-')
    ));
}

function monthlyFixturesLayout(?string $layout): string
{
    return $layout === 'calendar' ? 'calendar' : 'block';
}

function monthlyFixturesUploadDirectory(): string
{
    return dirname(__DIR__) . '/uploads/monthly_fixtures';
}

function monthlyFixturesBackgroundPath(int $seasonId): string
{
    if ($seasonId < 1) {
        return '';
    }
    $directory = monthlyFixturesUploadDirectory();
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
        $candidate = $directory . '/monthly_fixtures_bg_season_' . $seasonId . '.' . $extension;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function monthlyFixturesBackgroundUrl(int $seasonId): string
{
    $path = monthlyFixturesBackgroundPath($seasonId);
    return $path === '' ? '' : '/uploads/monthly_fixtures/' . basename($path);
}

function monthlyFixturesRemoveBackground(int $seasonId): void
{
    if ($seasonId < 1) {
        return;
    }
    $directory = monthlyFixturesUploadDirectory();
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
        $candidate = $directory . '/monthly_fixtures_bg_season_' . $seasonId . '.' . $extension;
        if (is_file($candidate)) {
            @unlink($candidate);
        }
    }
}

/** @return array{path:string,error:string} */
function monthlyFixturesStoreBackground(array $file, int $seasonId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => 'Choose a background image first.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'The background upload failed.'];
    }
    if ((int)($file['size'] ?? 0) > 12 * 1024 * 1024) {
        return ['path' => '', 'error' => 'The background image must be 12 MB or smaller.'];
    }
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        return ['path' => '', 'error' => 'The uploaded background could not be verified.'];
    }
    $info = @getimagesize($temporaryPath);
    if ((int)($info[0] ?? 0) > 8000 || (int)($info[1] ?? 0) > 8000) {
        return ['path' => '', 'error' => 'The background image must be no larger than 8000 × 8000 pixels.'];
    }
    $extension = match ((int)($info[2] ?? 0)) {
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default => '',
    };
    if ($extension === '') {
        return ['path' => '', 'error' => 'Upload a JPG, PNG, or WebP image.'];
    }

    $directory = monthlyFixturesUploadDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['path' => '', 'error' => 'The background directory could not be prepared.'];
    }
    @chmod($directory, 0775);
    $destination = $directory . '/monthly_fixtures_bg_season_' . $seasonId . '.' . $extension;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        return ['path' => '', 'error' => 'The background image could not be stored.'];
    }
    @chmod($destination, 0664);
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $oldExtension) {
        $oldPath = $directory . '/monthly_fixtures_bg_season_' . $seasonId . '.' . $oldExtension;
        if ($oldPath !== $destination && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    return ['path' => $destination, 'error' => ''];
}

function monthlyFixturesOpponentBadgePath(array $fixture): string
{
    $logo = basename(trim((string)($fixture['opponent_logo'] ?? '')));
    if ($logo === '') {
        return '';
    }
    $root = dirname(__DIR__);
    foreach ([$root . '/uploads/opponents/' . $logo, $root . '/badges/' . $logo] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function monthlyFixturesOpponentBadgeUrl(array $fixture): string
{
    $path = monthlyFixturesOpponentBadgePath($fixture);
    if ($path === '') {
        return '';
    }
    $root = dirname(__DIR__);
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    return '/' . implode('/', array_map('rawurlencode', explode('/', $relative))) . '?v=' . (int)(@filemtime($path) ?: time());
}

/** @return array{white:string,colour:string} */
function monthlyFixturesOpponentBadgeUrls(PDO $pdo, array $fixture): array
{
    $clubname = trim((string)($fixture['opponent'] ?? ''));
    if ($clubname === '') {
        return ['white' => '', 'colour' => ''];
    }
    return [
        'white' => matchOpponentBadgeAssetUrl($pdo, $clubname, true),
        'colour' => matchOpponentBadgeAssetUrl($pdo, $clubname, false),
    ];
}

/**
 * Scale a hex colour toward black by a factor (0-1), e.g. 0.8 keeps 80% brightness.
 * Used instead of CSS color-mix(), which html2canvas cannot parse for PNG export.
 */
function monthlyFixturesScaleHex(string $hex, float $factor): string
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return '#000000';
    }
    $red = (int)round(hexdec(substr($hex, 0, 2)) * $factor);
    $green = (int)round(hexdec(substr($hex, 2, 2)) * $factor);
    $blue = (int)round(hexdec(substr($hex, 4, 2)) * $factor);
    return sprintf('#%02x%02x%02x', max(0, min(255, $red)), max(0, min(255, $green)), max(0, min(255, $blue)));
}

/** @return array{0:int,1:int,2:int} */
function monthlyFixturesHexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return [0, 0, 0];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function monthlyFixturesIsPlayed(array $fixture): bool
{
    return $fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null;
}

function monthlyFixturesScoreLabel(array $fixture): string
{
    if (!monthlyFixturesIsPlayed($fixture)) {
        return '';
    }
    return (int)$fixture['full_time_home_score'] . '-' . (int)$fixture['full_time_away_score'];
}

function monthlyFixturesIsCupFixture(array $fixture): bool
{
    if (strtolower(trim((string)($fixture['competition_type'] ?? ''))) === 'cup') {
        return true;
    }

    $competition = strtolower(trim((string)($fixture['competition'] ?? '')));
    return $competition !== '' && preg_match('/\bcup\b/', $competition) === 1;
}

/** @return 'W'|'L'|'D'|null */
function monthlyFixturesResult(array $fixture): ?string
{
    if (!monthlyFixturesIsPlayed($fixture)) {
        return null;
    }
    $isHome = (int)($fixture['is_home'] ?? 0) === 1;
    $ourScore = $isHome ? (int)$fixture['full_time_home_score'] : (int)$fixture['full_time_away_score'];
    $theirScore = $isHome ? (int)$fixture['full_time_away_score'] : (int)$fixture['full_time_home_score'];
    if ($ourScore > $theirScore) {
        return 'W';
    }
    if ($ourScore < $theirScore) {
        return 'L';
    }
    return 'D';
}

/**
 * Recolour a white/transparent badge to a solid brand colour, cached on disk so
 * exports (html2canvas) always see a plain raster <img> rather than a live filter.
 */
function monthlyFixturesTintedBadgeUrl(string $sourceUrl, string $hexColour): string
{
    $sourceUrl = trim($sourceUrl);
    $hexColour = strtolower(trim($hexColour));
    if ($sourceUrl === '' || preg_match('/^#[0-9a-f]{6}$/', $hexColour) !== 1) {
        return $sourceUrl;
    }
    if (preg_match('#^(?:https?:)?//#i', $sourceUrl) === 1) {
        return $sourceUrl;
    }

    $root = dirname(__DIR__);
    $realRoot = realpath($root);
    $path = parse_url($sourceUrl, PHP_URL_PATH) ?: '';
    $relative = ltrim(rawurldecode($path), '/');
    if ($relative === '' || $realRoot === false) {
        return $sourceUrl;
    }
    $sourcePath = $root . '/' . $relative;
    $realSource = realpath($sourcePath);
    if ($realSource === false || !str_starts_with($realSource, $realRoot . DIRECTORY_SEPARATOR) || !is_file($realSource)) {
        return $sourceUrl;
    }

    $cacheDir = $root . '/uploads/badge_tints';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return $sourceUrl;
    }
    $cacheKey = md5($realSource . '|' . $hexColour . '|' . (@filemtime($realSource) ?: 0));
    $cachePath = $cacheDir . '/' . $cacheKey . '.png';
    if (!is_file($cachePath)) {
        $info = @getimagesize($realSource);
        $sourceWidth = (int)($info[0] ?? 0);
        $sourceHeight = (int)($info[1] ?? 0);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return $sourceUrl;
        }

        // Source badges range from tiny icons to multi-thousand-pixel scans; decoding
        // those needs headroom well beyond the default limit, so raise it just for
        // this call and always put it back.
        $previousMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        try {
            $image = match ((int)($info[2] ?? 0)) {
                IMAGETYPE_PNG => @imagecreatefrompng($realSource),
                IMAGETYPE_GIF => @imagecreatefromgif($realSource),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($realSource) : false,
                default => false,
            };
            if (!$image) {
                return $sourceUrl;
            }

            // Badges never render larger than a few hundred pixels in this app, so
            // shrink oversized source scans before filtering/encoding to keep the
            // cached file small and the processing cost bounded.
            $maxDimension = 640;
            if ($sourceWidth > $maxDimension || $sourceHeight > $maxDimension) {
                $scale = min($maxDimension / $sourceWidth, $maxDimension / $sourceHeight);
                $targetWidth = max(1, (int)round($sourceWidth * $scale));
                $targetHeight = max(1, (int)round($sourceHeight * $scale));
                $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
                imagedestroy($image);
                $image = $resized;
            } else {
                imagepalettetotruecolor($image);
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);
            $red = (int)hexdec(substr($hexColour, 1, 2));
            $green = (int)hexdec(substr($hexColour, 3, 2));
            $blue = (int)hexdec(substr($hexColour, 5, 2));
            imagefilter($image, IMG_FILTER_COLORIZE, $red - 255, $green - 255, $blue - 255);
            imagepng($image, $cachePath, 6);
            imagedestroy($image);
        } finally {
            ini_set('memory_limit', $previousMemoryLimit !== false ? $previousMemoryLimit : '128M');
        }
    }
    if (!is_file($cachePath)) {
        return $sourceUrl;
    }
    return '/uploads/badge_tints/' . basename($cachePath) . '?v=' . (int)(@filemtime($cachePath) ?: time());
}

/** @return array<string, mixed> */
function monthlyFixturesTemplateContext(PDO $pdo, int $seasonId): array
{
    try {
        template_packs_ensure_schema($pdo);
        $assignment = template_packs_get_assignment($pdo, 'season', $seasonId)
            ?? template_packs_get_global_default($pdo);
        if (!$assignment) {
            throw new RuntimeException('No template pack is assigned.');
        }
        $pack = template_packs_get($pdo, (int)$assignment['pack_id']);
        if (!$pack) {
            throw new RuntimeException('The assigned template pack is unavailable.');
        }
        $versionId = (int)($assignment['version_id'] ?? 0);
        if ($versionId <= 0) {
            $versionId = (int)($pack['current_published_version_id'] ?? 0);
        }
        $version = $versionId > 0 ? template_packs_get_version($pdo, $versionId) : null;
        if (!$version) {
            throw new RuntimeException('The assigned template pack has no published version.');
        }
        // Match the live Starting XI / Next Match workflow for the editorial
        // pack: its latest draft is the creator preview while it is being tuned.
        if ((int)$pack['id'] === 5) {
            $draft = template_packs_get_draft($pdo, (int)$pack['id']);
            if ($draft) {
                $version = $draft;
            }
        }
        return [
            'pack' => $pack,
            'version' => $version,
            'brand_settings' => (array)($version['brand_settings'] ?? []),
            'action' => (array)($version['actions']['monthly_fixtures'] ?? []),
            'assignment' => $assignment,
        ];
    } catch (Throwable $error) {
        error_log('Monthly fixtures template resolution failed: ' . $error->getMessage());
        return ['pack' => null, 'version' => null, 'brand_settings' => [], 'action' => [], 'assignment' => null];
    }
}

/** @return array<string, array<string, int|string|bool>> */
function monthlyFixturesDefaultElements(): array
{
    return [
        'club_badge' => ['label' => 'Club badge', 'x' => 466, 'y' => 36, 'width' => 148, 'height' => 148, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'legend' => ['label' => 'Home and away legend', 'x' => 68, 'y' => 205, 'width' => 944, 'height' => 65, 'font_size' => 38, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
        'month_heading' => ['label' => 'Month fixtures heading', 'x' => 68, 'y' => 280, 'width' => 944, 'height' => 65, 'font_size' => 38, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
        'fixtures' => ['label' => 'Fixture blocks or calendar', 'x' => 68, 'y' => 362, 'width' => 944, 'height' => 830, 'font_size' => 18, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'footer_meta' => ['label' => 'Footer note', 'x' => 120, 'y' => 1260, 'width' => 840, 'height' => 64, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
    ];
}
