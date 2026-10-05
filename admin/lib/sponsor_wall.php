<?php

declare(strict_types=1);

const SPONSORS_BASE_DIR = __DIR__ . '/..';
const SPONSORS_UPLOAD_DIR = __DIR__ . '/../uploads/sponsors';
const SPONSORS_WALL_COLUMNS = 6;
const SPONSORS_WALL_TILE_WIDTH = 300;
const SPONSORS_WALL_TILE_HEIGHT = 180;
const SPONSORS_WALL_GAP = 24;
const SPONSORS_WALL_MARGIN_X = 64;
const SPONSORS_WALL_MARGIN_Y = 64;
const SPONSORS_UPLOAD_CANVAS_WIDTH = 1200;
const SPONSORS_UPLOAD_CANVAS_HEIGHT = 720;
const SPONSORS_TILE_PREVIEW_WIDTH = 252;
const SPONSORS_TILE_PREVIEW_HEIGHT = 140;
const SPONSORS_WALL_LAYOUT_FILE = __DIR__ . '/../data/wall-layout.json';
const SPONSORS_WALL_SETTINGS_FILE = __DIR__ . '/../data/wall-settings.json';

function sponsors_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sponsors_ensure_upload_dir(): bool
{
    return is_dir(SPONSORS_UPLOAD_DIR) || (@mkdir(SPONSORS_UPLOAD_DIR, 0775, true) && is_dir(SPONSORS_UPLOAD_DIR));
}

function sponsors_hex_to_rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (strlen($hex) !== 6) {
        return [0, 0, 0];
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function sponsors_allocate_color($image, string $hex, int $alpha = 0): int
{
    [$red, $green, $blue] = sponsors_hex_to_rgb($hex);

    return imagecolorallocatealpha($image, $red, $green, $blue, max(0, min(127, $alpha)));
}

function sponsors_rgb_to_hex(array $rgb): string
{
    return sprintf('%02x%02x%02x', max(0, min(255, (int) ($rgb[0] ?? 0))), max(0, min(255, (int) ($rgb[1] ?? 0))), max(0, min(255, (int) ($rgb[2] ?? 0))));
}

function sponsors_adjust_rgb(array $rgb, float $factor): array
{
    return [
        (int) max(0, min(255, round(((int) ($rgb[0] ?? 0)) + (255 - ((int) ($rgb[0] ?? 0))) * max(0.0, $factor)))),
        (int) max(0, min(255, round(((int) ($rgb[1] ?? 0)) + (255 - ((int) ($rgb[1] ?? 0))) * max(0.0, $factor)))),
        (int) max(0, min(255, round(((int) ($rgb[2] ?? 0)) + (255 - ((int) ($rgb[2] ?? 0))) * max(0.0, $factor)))),
    ];
}

function sponsors_darken_rgb(array $rgb, float $factor): array
{
    $factor = max(0.0, min(1.0, $factor));

    return [
        (int) max(0, min(255, round(((int) ($rgb[0] ?? 0)) * (1 - $factor)))),
        (int) max(0, min(255, round(((int) ($rgb[1] ?? 0)) * (1 - $factor)))),
        (int) max(0, min(255, round(((int) ($rgb[2] ?? 0)) * (1 - $factor)))),
    ];
}

function sponsors_supports_image(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    return @getimagesize($path) !== false;
}

function sponsors_wall_image_url(string $name): string
{
    return 'uploads/sponsors/' . rawurlencode($name);
}

function sponsors_wall_source_path(string $filename): string
{
    return SPONSORS_UPLOAD_DIR . '/' . basename($filename);
}

function sponsors_wall_layout_file(): string
{
    return SPONSORS_WALL_LAYOUT_FILE;
}

function sponsors_wall_settings_file(): string
{
    return SPONSORS_WALL_SETTINGS_FILE;
}

function sponsors_generate_uid(): string
{
    return bin2hex(random_bytes(8));
}

function sponsors_normalize_wall_entry(array $entry): ?array
{
    $filename = trim((string) ($entry['filename'] ?? $entry['file'] ?? ''));
    $uid = trim((string) ($entry['uid'] ?? ''));

    if ($filename === '') {
        return null;
    }

    if ($uid === '') {
        $uid = sponsors_generate_uid();
    }

    return [
        'uid' => $uid,
        'filename' => basename($filename),
    ];
}

/**
 * @return list<array{uid: string, filename: string}>
 */
function sponsors_load_wall_layout(): array
{
    $path = sponsors_wall_layout_file();
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $layout = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $normalized = sponsors_normalize_wall_entry($entry);
        if ($normalized !== null) {
            $layout[] = $normalized;
        }
    }

    return $layout;
}

/**
 * @param list<array{uid: string, filename: string}> $layout
 */
function sponsors_save_wall_layout(array $layout): bool
{
    $path = sponsors_wall_layout_file();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $json = json_encode(array_values($layout), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @param list<array{name: string, path: string, url: string, size: int, mtime: int, mime: string}> $uploads
 * @param list<array{uid: string, filename: string}> $layout
 * @return list<array{uid: string, filename: string}>
 */
function sponsors_sync_wall_layout(array $uploads, array $layout, bool $initialiseMissing = false): array
{
    $uploadsByFilename = [];
    foreach ($uploads as $upload) {
        $filename = (string) ($upload['name'] ?? '');
        if ($filename !== '') {
            $uploadsByFilename[$filename] = true;
        }
    }

    $normalized = [];
    $existingFilenames = [];
    $changed = false;

    foreach ($layout as $entry) {
        $filename = basename((string) ($entry['filename'] ?? ''));
        $uid = trim((string) ($entry['uid'] ?? ''));
        if ($filename === '' || $uid === '') {
            $changed = true;
            continue;
        }

        if (!isset($uploadsByFilename[$filename])) {
            $changed = true;
            continue;
        }

        $normalized[] = ['uid' => $uid, 'filename' => $filename];
        $existingFilenames[$filename] = true;
    }

    if ($normalized === [] && $initialiseMissing) {
        foreach ($uploads as $upload) {
            $filename = (string) ($upload['name'] ?? '');
            if ($filename === '') {
                continue;
            }
            $normalized[] = [
                'uid' => sponsors_generate_uid(),
                'filename' => $filename,
            ];
        }

        return $normalized;
    }

    return array_values($normalized);
}

/**
 * @return list<array{uid: string, filename: string}>
 */
function sponsors_current_wall_layout(): array
{
    $uploads = sponsors_list_uploads();
    $layout = sponsors_load_wall_layout();
    $initialiseMissing = !is_file(sponsors_wall_layout_file());
    $synced = sponsors_sync_wall_layout($uploads, $layout, $initialiseMissing);

    if ($synced !== $layout) {
        sponsors_save_wall_layout($synced);
    }

    return $synced;
}

function sponsors_latest_layout_timestamp(): int
{
    $path = sponsors_wall_layout_file();
    return is_file($path) ? (int) (@filemtime($path) ?: 0) : 0;
}

function sponsors_latest_settings_timestamp(): int
{
    $path = sponsors_wall_settings_file();
    return is_file($path) ? (int) (@filemtime($path) ?: 0) : 0;
}

function sponsors_wall_cache_version(): int
{
    return max(1, sponsors_latest_upload_timestamp(), sponsors_latest_layout_timestamp(), sponsors_latest_settings_timestamp());
}

/**
 * @param list<array{uid: string, filename: string}> $layout
 * @param array{mode: string, width: int, height: int}|null $settings
 * @return array{
 *     canvas_width:int,
 *     canvas_height:int,
 *     tile_width:int,
 *     tile_height:int,
 *     gap:int,
 *     margin_x:int,
 *     margin_y:int,
 *     offset_x:int,
 *     offset_y:int,
 *     scale:float
 * }
 */
function sponsors_wall_render_metrics(array $layout, ?array $settings = null): array
{
    $settings = sponsors_normalize_wall_settings($settings ?? sponsors_current_wall_settings());
    $base = sponsors_wall_dimensions(count($layout));

    $metrics = [
        'canvas_width' => $base['width'],
        'canvas_height' => $base['height'],
        'tile_width' => SPONSORS_WALL_TILE_WIDTH,
        'tile_height' => SPONSORS_WALL_TILE_HEIGHT,
        'gap' => SPONSORS_WALL_GAP,
        'margin_x' => SPONSORS_WALL_MARGIN_X,
        'margin_y' => SPONSORS_WALL_MARGIN_Y,
        'offset_x' => 0,
        'offset_y' => 0,
        'scale' => 1.0,
    ];

    if ($settings['mode'] !== 'custom' || $settings['width'] <= 0 || $settings['height'] <= 0) {
        return $metrics;
    }

    $canvasWidth = $settings['width'];
    $canvasHeight = $settings['height'];
    $scale = min(
        $canvasWidth / max(1, $base['width']),
        $canvasHeight / max(1, $base['height'])
    );

    $tileWidth = max(1, (int) floor(SPONSORS_WALL_TILE_WIDTH * $scale));
    $tileHeight = max(1, (int) floor(SPONSORS_WALL_TILE_HEIGHT * $scale));
    $gap = max(1, (int) floor(SPONSORS_WALL_GAP * $scale));
    $marginX = max(1, (int) floor(SPONSORS_WALL_MARGIN_X * $scale));
    $marginY = max(1, (int) floor(SPONSORS_WALL_MARGIN_Y * $scale));

    $contentWidth = $marginX * 2
        + (SPONSORS_WALL_COLUMNS * $tileWidth)
        + ((SPONSORS_WALL_COLUMNS - 1) * $gap);
    $contentRows = max(1, (int) ceil(max(1, count($layout)) / SPONSORS_WALL_COLUMNS));
    $contentHeight = $marginY * 2
        + ($contentRows * $tileHeight)
        + (($contentRows - 1) * $gap);

    $offsetX = max(0, (int) floor(($canvasWidth - $contentWidth) / 2));
    $offsetY = max(0, (int) floor(($canvasHeight - $contentHeight) / 2));

    return [
        'canvas_width' => $canvasWidth,
        'canvas_height' => $canvasHeight,
        'tile_width' => $tileWidth,
        'tile_height' => $tileHeight,
        'gap' => $gap,
        'margin_x' => $marginX,
        'margin_y' => $marginY,
        'offset_x' => $offsetX,
        'offset_y' => $offsetY,
        'scale' => $scale,
    ];
}

/**
 * @return array{mode: string, width: int, height: int}
 */
function sponsors_default_wall_settings(): array
{
    return [
        'mode' => 'auto',
        'width' => 0,
        'height' => 0,
    ];
}

/**
 * @param array<string, mixed> $settings
 * @return array{mode: string, width: int, height: int}
 */
function sponsors_normalize_wall_settings(array $settings): array
{
    $mode = trim((string) ($settings['mode'] ?? 'auto'));
    $mode = in_array($mode, ['auto', 'custom'], true) ? $mode : 'auto';
    $width = (int) ($settings['width'] ?? 0);
    $height = (int) ($settings['height'] ?? 0);

    if ($mode !== 'custom') {
        return sponsors_default_wall_settings();
    }

    $width = max(200, min(20000, $width));
    $height = max(200, min(20000, $height));

    return [
        'mode' => 'custom',
        'width' => $width,
        'height' => $height,
    ];
}

/**
 * @return array{mode: string, width: int, height: int}
 */
function sponsors_load_wall_settings(): array
{
    $path = sponsors_wall_settings_file();
    if (!is_file($path)) {
        return sponsors_default_wall_settings();
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return sponsors_default_wall_settings();
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return sponsors_default_wall_settings();
    }

    return sponsors_normalize_wall_settings($data);
}

/**
 * @param array{mode: string, width: int, height: int} $settings
 */
function sponsors_save_wall_settings(array $settings): bool
{
    $path = sponsors_wall_settings_file();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @return array{mode: string, width: int, height: int}
 */
function sponsors_current_wall_settings(): array
{
    return sponsors_load_wall_settings();
}

/**
 * @return list<array{name: string, path: string, url: string, size: int, mtime: int, mime: string}>
 */
function sponsors_list_uploads(): array
{
    if (!is_dir(SPONSORS_UPLOAD_DIR)) {
        return [];
    }

    $items = [];
    $entries = scandir(SPONSORS_UPLOAD_DIR);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitkeep') {
            continue;
        }

        $path = SPONSORS_UPLOAD_DIR . '/' . $entry;
        if (!is_file($path) || !sponsors_supports_image($path)) {
            continue;
        }

        $imageInfo = @getimagesize($path);
        $items[] = [
            'name' => $entry,
            'path' => $path,
            'url' => sponsors_wall_image_url($entry),
            'size' => (int) (@filesize($path) ?: 0),
            'mtime' => (int) (@filemtime($path) ?: 0),
            'mime' => is_array($imageInfo) && isset($imageInfo['mime']) ? (string) $imageInfo['mime'] : 'application/octet-stream',
        ];
    }

    usort($items, static function (array $left, array $right): int {
        $mtime = $left['mtime'] <=> $right['mtime'];

        return $mtime !== 0 ? $mtime : strcmp($left['name'], $right['name']);
    });

    return $items;
}

/**
 * @param array<string, mixed> $file
 * @return array{ok: bool, filename: string, error: string}
 */
function sponsors_store_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'filename' => '', 'error' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'filename' => '', 'error' => 'One of the uploads failed to transfer.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'filename' => '', 'error' => 'The uploaded file could not be validated.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['ok' => false, 'filename' => '', 'error' => 'Only valid image files can be uploaded.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensionMap[$mime])) {
        return ['ok' => false, 'filename' => '', 'error' => 'Supported formats are JPG, PNG, WebP, and GIF.'];
    }

    if (!sponsors_ensure_upload_dir()) {
        return ['ok' => false, 'filename' => '', 'error' => 'The uploads folder could not be created.'];
    }

    $filename = basename((string) ($file['name'] ?? ''));
    if ($filename === '') {
        return ['ok' => false, 'filename' => '', 'error' => 'The uploaded file name is invalid.'];
    }

    $destination = SPONSORS_UPLOAD_DIR . '/' . $filename;
    if (is_file($destination) && !@unlink($destination)) {
        return ['ok' => false, 'filename' => '', 'error' => 'The existing file could not be replaced.'];
    }

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['ok' => false, 'filename' => '', 'error' => 'The uploaded image could not be saved.'];
    }

    if (!sponsors_normalize_uploaded_image($destination)) {
        @unlink($destination);
        return ['ok' => false, 'filename' => '', 'error' => 'The uploaded image could not be normalised.'];
    }

    return ['ok' => true, 'filename' => $filename, 'error' => ''];
}

/**
 * @param array<string, mixed> $files
 * @return array{saved: list<string>, errors: list<string>}
 */
function sponsors_store_uploaded_images(array $files): array
{
    $saved = [];
    $errors = [];

    if (!isset($files['name'])) {
        return ['saved' => [], 'errors' => ['No files were selected.']];
    }

    $normalized = sponsors_rearray_files($files);
    foreach ($normalized as $file) {
        $result = sponsors_store_upload($file);
        if ($result['error'] !== '') {
            $errors[] = $result['error'];
            continue;
        }

        if ($result['ok'] && $result['filename'] !== '') {
            $saved[] = $result['filename'];
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * @param array<string, mixed> $files
 * @return list<array<string, mixed>>
 */
function sponsors_rearray_files(array $files): array
{
    $result = [];
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

    for ($index = 0; $index < $count; $index++) {
        $result[] = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $result;
}

function sponsors_delete_upload(string $filename): bool
{
    $basename = basename($filename);
    if ($basename !== $filename || $basename === '') {
        return false;
    }

    $path = SPONSORS_UPLOAD_DIR . '/' . $basename;
    if (!is_file($path)) {
        return false;
    }

    return @unlink($path);
}

function sponsors_latest_upload_timestamp(): int
{
    $latest = 0;
    foreach (sponsors_list_uploads() as $item) {
        $latest = max($latest, (int) ($item['mtime'] ?? 0));
    }

    return $latest;
}

/**
 * @return array{width: int, height: int, rows: int}
 */
function sponsors_wall_dimensions(int $count): array
{
    $rows = max(1, (int) ceil(max(1, $count) / SPONSORS_WALL_COLUMNS));
    $width = SPONSORS_WALL_MARGIN_X * 2
        + (SPONSORS_WALL_COLUMNS * SPONSORS_WALL_TILE_WIDTH)
        + ((SPONSORS_WALL_COLUMNS - 1) * SPONSORS_WALL_GAP);
    $height = SPONSORS_WALL_MARGIN_Y * 2
        + ($rows * SPONSORS_WALL_TILE_HEIGHT)
        + (($rows - 1) * SPONSORS_WALL_GAP);

    return [
        'width' => $width,
        'height' => $height,
        'rows' => $rows,
    ];
}

function sponsors_draw_gradient_background($image, int $width, int $height): void
{
    $top = sponsors_hex_to_rgb('f6f8fb');
    $middle = sponsors_hex_to_rgb('e8edf3');
    $bottom = sponsors_hex_to_rgb('dfe5ec');

    for ($y = 0; $y < $height; $y++) {
        $t = $height > 1 ? $y / ($height - 1) : 0.0;
        if ($t < 0.5) {
            $local = $t / 0.5;
            $red = (int) round($top[0] + (($middle[0] - $top[0]) * $local));
            $green = (int) round($top[1] + (($middle[1] - $top[1]) * $local));
            $blue = (int) round($top[2] + (($middle[2] - $top[2]) * $local));
        } else {
            $local = ($t - 0.5) / 0.5;
            $red = (int) round($middle[0] + (($bottom[0] - $middle[0]) * $local));
            $green = (int) round($middle[1] + (($bottom[1] - $middle[1]) * $local));
            $blue = (int) round($middle[2] + (($bottom[2] - $middle[2]) * $local));
        }

        $color = imagecolorallocate($image, $red, $green, $blue);
        imageline($image, 0, $y, $width, $y, $color);
    }

    $glowColors = [
        ['color' => '8d2242', 'alpha' => 104, 'x' => (int) ($width * 0.45), 'y' => (int) ($height * 0.24), 'rx' => (int) ($width * 0.72), 'ry' => (int) ($height * 0.45)],
        ['color' => '4d0b16', 'alpha' => 100, 'x' => (int) ($width * 0.16), 'y' => (int) ($height * 0.8), 'rx' => (int) ($width * 0.54), 'ry' => (int) ($height * 0.36)],
        ['color' => 'a33a61', 'alpha' => 114, 'x' => (int) ($width * 0.92), 'y' => (int) ($height * 0.52), 'rx' => (int) ($width * 0.34), 'ry' => (int) ($height * 0.58)],
    ];

    foreach ($glowColors as $glow) {
        $color = imagecolorallocatealpha($image, ...array_merge(sponsors_hex_to_rgb($glow['color']), [$glow['alpha']]));
        imagefilledellipse($image, $glow['x'], $glow['y'], $glow['rx'], $glow['ry'], $color);
    }
}

function sponsors_fill_rounded_rectangle($image, int $x, int $y, int $width, int $height, int $radius, int $color): void
{
    imagefilledrectangle($image, $x + $radius, $y, $x + $width - $radius - 1, $y + $height - 1, $color);
    imagefilledrectangle($image, $x, $y + $radius, $x + $width - 1, $y + $height - $radius - 1, $color);

    imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x + $width - $radius - 1, $y + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x + $radius, $y + $height - $radius - 1, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x + $width - $radius - 1, $y + $height - $radius - 1, $radius * 2, $radius * 2, $color);
}

/**
 * @param list<array{uid: string, filename: string}> $layout
 */
function sponsors_render_wall(array $layout, bool $asDownload = false, ?array $settings = null): void
{
    $metrics = sponsors_wall_render_metrics($layout, $settings);
    $width = $metrics['canvas_width'];
    $height = $metrics['canvas_height'];

    $canvas = imagecreatetruecolor($width, $height);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    sponsors_draw_gradient_background($canvas, $width, $height);

    $styles = [
        ['base' => 'f7f3eb', 'highlight' => 'ffffff', 'shadow' => 'c9bfa9'],
        ['base' => 'd9e2ef', 'highlight' => 'ffffff', 'shadow' => 'a8b4c2'],
        ['base' => 'c69b47', 'highlight' => 'f2d68e', 'shadow' => '8a6422'],
        ['base' => '5d7285', 'highlight' => 'd6e0ea', 'shadow' => '344553'],
        ['base' => 'bfc7d8', 'highlight' => 'eef2fb', 'shadow' => '8390a8'],
        ['base' => '6a6458', 'highlight' => '8d8575', 'shadow' => '2d2922'],
    ];

    if ($layout === []) {
        $panelX = (int) round(($width - 960) / 2);
        $panelY = (int) round(($height - 280) / 2);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 86);
        $panel = imagecolorallocatealpha($canvas, 255, 255, 255, 2);
        $border = imagecolorallocatealpha($canvas, 50, 65, 82, 92);
        sponsors_fill_rounded_rectangle($canvas, $panelX + 14, $panelY + 16, 960, 280, 40, $shadow);
        sponsors_fill_rounded_rectangle($canvas, $panelX, $panelY, 960, 280, 40, $panel);
        imagerectangle($canvas, $panelX, $panelY, $panelX + 959, $panelY + 279, $border);

        $textColor = imagecolorallocate($canvas, 33, 37, 41);
        $softText = imagecolorallocatealpha($canvas, 33, 37, 41, 52);
        $title = 'SPONSOR WALL';
        $body = 'Upload sponsor images, arrange the repeat, then export the full backdrop.';
        $titleX = $panelX + 78;
        $titleY = $panelY + 72;
        imagestring($canvas, 5, $titleX, $titleY, $title, $textColor);
        imagestring($canvas, 3, $titleX, $titleY + 54, $body, $softText);
        imageline($canvas, $titleX, $titleY + 36, $titleX + 520, $titleY + 36, $border);
    } else {
        foreach ($layout as $index => $item) {
            $column = $index % SPONSORS_WALL_COLUMNS;
            $row = intdiv($index, SPONSORS_WALL_COLUMNS);
            $x = $metrics['offset_x'] + $metrics['margin_x'] + ($column * ($metrics['tile_width'] + $metrics['gap']));
            $y = $metrics['offset_y'] + $metrics['margin_y'] + ($row * ($metrics['tile_height'] + $metrics['gap']));
            $path = sponsors_wall_source_path((string) ($item['filename'] ?? ''));
            $tile = sponsors_render_tile($path, $index, $styles[$index % count($styles)]);

            if ($metrics['tile_width'] === SPONSORS_WALL_TILE_WIDTH && $metrics['tile_height'] === SPONSORS_WALL_TILE_HEIGHT) {
                imagecopy($canvas, $tile, $x, $y, 0, 0, SPONSORS_WALL_TILE_WIDTH, SPONSORS_WALL_TILE_HEIGHT);
            } else {
                imagecopyresampled(
                    $canvas,
                    $tile,
                    $x,
                    $y,
                    0,
                    0,
                    $metrics['tile_width'],
                    $metrics['tile_height'],
                    SPONSORS_WALL_TILE_WIDTH,
                    SPONSORS_WALL_TILE_HEIGHT
                );
            }
            imagedestroy($tile);
        }
    }

    if ($asDownload) {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="sponsor-wall-' . date('Ymd-His') . '.png"');
    } else {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    imagepng($canvas);
    imagedestroy($canvas);
}

function sponsors_apply_rounded_corners($image, int $radius): void
{
    $width = imagesx($image);
    $height = imagesy($image);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $outside = false;

            if ($x < $radius && $y < $radius) {
                $dx = $radius - $x - 1;
                $dy = $radius - $y - 1;
                $outside = (($dx * $dx) + ($dy * $dy)) > ($radius * $radius);
            } elseif ($x >= $width - $radius && $y < $radius) {
                $dx = $x - ($width - $radius);
                $dy = $radius - $y - 1;
                $outside = (($dx * $dx) + ($dy * $dy)) > ($radius * $radius);
            } elseif ($x < $radius && $y >= $height - $radius) {
                $dx = $radius - $x - 1;
                $dy = $y - ($height - $radius);
                $outside = (($dx * $dx) + ($dy * $dy)) > ($radius * $radius);
            } elseif ($x >= $width - $radius && $y >= $height - $radius) {
                $dx = $x - ($width - $radius);
                $dy = $y - ($height - $radius);
                $outside = (($dx * $dx) + ($dy * $dy)) > ($radius * $radius);
            }

            if ($outside) {
                imagesetpixel($image, $x, $y, $transparent);
            }
        }
    }
}

/**
 * @param array{base: string, highlight: string, shadow: string} $style
 */
function sponsors_render_tile(string $path, int $index, array $style): GdImage
{
    $tile = imagecreatetruecolor(SPONSORS_WALL_TILE_WIDTH, SPONSORS_WALL_TILE_HEIGHT);
    imagealphablending($tile, true);
    imagesavealpha($tile, true);

    $white = imagecolorallocate($tile, 255, 255, 255);
    imagefill($tile, 0, 0, $white);

    $previewPath = sponsors_build_tile_preview($path);
    $src = $previewPath !== false ? @imagecreatefrompng($previewPath) : false;
    if ($src instanceof GdImage) {
        imagealphablending($src, true);
        imagesavealpha($src, true);

        $sourceWidth = imagesx($src);
        $sourceHeight = imagesy($src);
        $maxWidth = SPONSORS_WALL_TILE_WIDTH - 52;
        $maxHeight = SPONSORS_WALL_TILE_HEIGHT - 42;
        $ratio = min($maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
        $ratio = max(0.15, $ratio);

        $drawWidth = max(1, (int) round($sourceWidth * $ratio));
        $drawHeight = max(1, (int) round($sourceHeight * $ratio));
        $drawX = (int) round((SPONSORS_WALL_TILE_WIDTH - $drawWidth) / 2);
        $drawY = (int) round((SPONSORS_WALL_TILE_HEIGHT - $drawHeight) / 2);

        imagecopyresampled($tile, $src, $drawX, $drawY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
        imagedestroy($src);
    } else {
        $placeholder = imagecolorallocatealpha($tile, 255, 255, 255, 14);
        imagestring($tile, 5, 88, 78, 'IMAGE', $placeholder);
    }

    sponsors_apply_rounded_corners($tile, 28);

    return $tile;
}

function sponsors_load_source_image(string $path): GdImage|false
{
    $imageInfo = @getimagesize($path);
    if ($imageInfo === false) {
        return false;
    }

    $mime = (string) ($imageInfo['mime'] ?? '');

    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif' => @imagecreatefromgif($path),
        default => false,
    };
}

function sponsors_normalize_uploaded_image(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $imageInfo = @getimagesize($path);
    if ($imageInfo === false) {
        return false;
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $source = sponsors_load_source_image($path);
    if (!$source instanceof GdImage) {
        return false;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        return false;
    }

    $target = imagecreatetruecolor(SPONSORS_UPLOAD_CANVAS_WIDTH, SPONSORS_UPLOAD_CANVAS_HEIGHT);
    if (!$target instanceof GdImage) {
        imagedestroy($source);
        return false;
    }

    imagealphablending($target, false);
    imagesavealpha($target, true);

    $background = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $background);

    imagealphablending($source, true);
    imagesavealpha($source, true);

    $ratio = min(
        SPONSORS_UPLOAD_CANVAS_WIDTH / max(1, $sourceWidth),
        SPONSORS_UPLOAD_CANVAS_HEIGHT / max(1, $sourceHeight)
    );

    $drawWidth = max(1, (int) round($sourceWidth * $ratio));
    $drawHeight = max(1, (int) round($sourceHeight * $ratio));
    $drawX = (int) round((SPONSORS_UPLOAD_CANVAS_WIDTH - $drawWidth) / 2);
    $drawY = (int) round((SPONSORS_UPLOAD_CANVAS_HEIGHT - $drawHeight) / 2);

    imagecopyresampled(
        $target,
        $source,
        $drawX,
        $drawY,
        0,
        0,
        $drawWidth,
        $drawHeight,
        $sourceWidth,
        $sourceHeight
    );

    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($target, $path, 88),
        'image/png' => imagepng($target, $path, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($target, $path, 82) : imagepng($target, $path, 6),
        'image/gif' => imagegif($target, $path),
        default => false,
    };

    imagedestroy($source);
    imagedestroy($target);

    return $saved;
}

function sponsors_tile_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sponsors-wall-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function sponsors_tile_preview_path(string $sourcePath): string
{
    $signature = sha1($sourcePath . '|' . (string) (@filemtime($sourcePath) ?: 0) . '|' . (string) (@filesize($sourcePath) ?: 0));

    return sponsors_tile_cache_dir() . DIRECTORY_SEPARATOR . $signature . '.png';
}

function sponsors_build_tile_preview(string $sourcePath): string|false
{
    if (!is_file($sourcePath)) {
        return false;
    }

    $previewPath = sponsors_tile_preview_path($sourcePath);
    if (is_file($previewPath) && filesize($previewPath) > 0) {
        return $previewPath;
    }

    $ffmpeg = '/usr/bin/ffmpeg';
    $vf = 'scale=' . SPONSORS_TILE_PREVIEW_WIDTH . ':' . SPONSORS_TILE_PREVIEW_HEIGHT . ':force_original_aspect_ratio=decrease:flags=lanczos,'
        . 'pad=' . SPONSORS_TILE_PREVIEW_WIDTH . ':' . SPONSORS_TILE_PREVIEW_HEIGHT . ':(ow-iw)/2:(oh-ih)/2:color=0x00000000';
    $command = escapeshellarg($ffmpeg)
        . ' -hide_banner -loglevel error -nostdin -y'
        . ' -i ' . escapeshellarg($sourcePath)
        . ' -vf ' . escapeshellarg($vf)
        . ' -frames:v 1 '
        . escapeshellarg($previewPath)
        . ' 2>&1';

    $output = [];
    $exitCode = 0;
    @exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($previewPath) || filesize($previewPath) === 0) {
        @unlink($previewPath);
        return false;
    }

    return $previewPath;
}

/**
 * @return array{0:int,1:int,2:int}
 */
function sponsors_average_color(string $path): array
{
    $image = @imagecreatefrompng($path);
    if (!$image instanceof GdImage) {
        return [128, 128, 128];
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $step = max(1, (int) round(max($width, $height) / 48));
    $redTotal = 0.0;
    $greenTotal = 0.0;
    $blueTotal = 0.0;
    $weightTotal = 0.0;

    for ($y = 0; $y < $height; $y += $step) {
        for ($x = 0; $x < $width; $x += $step) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha >= 120) {
                continue;
            }

            $weight = 1 - ($alpha / 127);
            $redTotal += (($rgba >> 16) & 0xFF) * $weight;
            $greenTotal += (($rgba >> 8) & 0xFF) * $weight;
            $blueTotal += ($rgba & 0xFF) * $weight;
            $weightTotal += $weight;
        }
    }

    imagedestroy($image);

    if ($weightTotal <= 0) {
        return [128, 128, 128];
    }

    return [
        (int) round($redTotal / $weightTotal),
        (int) round($greenTotal / $weightTotal),
        (int) round($blueTotal / $weightTotal),
    ];
}
