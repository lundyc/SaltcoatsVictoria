<?php
declare(strict_types=1);

/**
 * Filesystem-backed media catalogue for the Hub. Paths returned by this file are
 * always relative to the Hub root and are resolved against an explicit allowlist.
 */

function hub_media_roots(): array
{
    return [
        ['path' => 'uploads', 'label' => 'Uploads'],
        ['path' => 'badges', 'label' => 'Club badges'],
        ['path' => 'assets/images', 'label' => 'Design assets'],
    ];
}

function hub_media_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
}

function hub_media_web_url(string $relativePath): string
{
    $parts = array_map('rawurlencode', explode('/', str_replace('\\', '/', $relativePath)));
    return '/' . implode('/', $parts);
}

function hub_media_category(string $relativePath): string
{
    $path = strtolower(str_replace('\\', '/', $relativePath));
    if (str_starts_with($path, 'uploads/template_packs/')) {
        $parts = explode('/', $relativePath);
        return 'Template packs / Pack ' . ($parts[2] ?? 'Unknown');
    }
    if (str_starts_with($path, 'uploads/media/templates/')) return 'Media library / Templates';
    if (str_starts_with($path, 'uploads/media/')) return 'Media library';
    if (str_starts_with($path, 'badges/white/')) return 'Club badges / White';
    if (str_starts_with($path, 'badges/')) return 'Club badges';
    if (str_starts_with($path, 'uploads/sponsors/')) return 'Sponsors';
    if (str_starts_with($path, 'uploads/players/')) return 'Players';
    if (str_starts_with($path, 'uploads/matches/')) return 'Matches';
    if (str_starts_with($path, 'uploads/history/gallery/')) return 'Club archive';
    if (str_starts_with($path, 'uploads/history/')) return 'Club archive';
    if (str_starts_with($path, 'uploads/competitions/')) return 'Competitions';
    if (str_starts_with($path, 'uploads/match_fixtures/')) return 'Match fixtures';
    if (str_starts_with($path, 'assets/images/')) return 'Design assets';
    if (str_starts_with($path, 'uploads/event-backgrounds/')) return 'Social publishing / Event backgrounds';
    if (str_starts_with($path, 'uploads/event-settings/')) return 'Social publishing / Event settings';
    if (str_starts_with($path, 'uploads/league/')) return 'Social publishing / League';
    return 'Other uploads';
}

function hub_media_resolve(string $relativePath, bool $mustExist = true): ?string
{
    $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relativePath === '' || str_contains($relativePath, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) return null;
    foreach (hub_media_roots() as $root) {
        $prefix = $root['path'] . '/';
        if (!str_starts_with($relativePath, $prefix)) continue;
        $rootReal = realpath(__DIR__ . '/../' . $root['path']);
        if ($rootReal === false) continue;
        if ($mustExist) {
            $resolved = realpath(__DIR__ . '/../' . $relativePath);
            if ($resolved === false || !is_file($resolved)) return null;
            $resolved = str_replace('\\', '/', $resolved);
            $rootReal = str_replace('\\', '/', $rootReal);
            return str_starts_with($resolved, $rootReal . '/') ? $resolved : null;
        }
        $parent = realpath(dirname(__DIR__ . '/../' . $relativePath));
        if ($parent === false) return null;
        $parent = str_replace('\\', '/', $parent);
        $rootReal = str_replace('\\', '/', $rootReal);
        return ($parent === $rootReal || str_starts_with($parent, $rootReal . '/')) ? $parent . '/' . basename($relativePath) : null;
    }
    return null;
}

function hub_media_catalogue(): array
{
    $items = [];
    $extensions = hub_media_allowed_extensions();
    foreach (hub_media_roots() as $root) {
        $absoluteRoot = realpath(__DIR__ . '/../' . $root['path']);
        if ($absoluteRoot === false || !is_dir($absoluteRoot)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $extensions, true)) continue;
            $absolute = str_replace('\\', '/', realpath($file->getPathname()) ?: $file->getPathname());
            // Derive the relative path from the (already realpath'd) root, not
            // from realpath(__DIR__.'/..'): after the admin/ restructure the
            // media roots (uploads, badges) reach the web root through a
            // symlink, so the file's real path is not under the admin dir.
            $absoluteRootNorm = str_replace('\\', '/', $absoluteRoot);
            $relative = $root['path'] . '/' . ltrim(substr($absolute, strlen($absoluteRootNorm)), '/');
            $info = @getimagesize($absolute);
            if (!is_array($info)) continue;
            $items[] = [
                'path' => $relative,
                'url' => hub_media_web_url($relative),
                'name' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                'filename' => $file->getFilename(),
                'category' => hub_media_category($relative),
                'width' => (int) ($info[0] ?? 0),
                'height' => (int) ($info[1] ?? 0),
                'mime' => (string) ($info['mime'] ?? ''),
                'size' => $file->getSize(),
                'modified' => $file->getMTime(),
            ];
        }
    }
    usort($items, static fn(array $a, array $b): int => ($b['modified'] <=> $a['modified']) ?: strcasecmp($a['filename'], $b['filename']));
    return $items;
}

function hub_media_upload_targets(): array
{
    return [
        'library' => ['label' => 'Media library', 'path' => 'uploads/media'],
        'templates' => ['label' => 'Media library / Templates', 'path' => 'uploads/media/templates'],
        'players' => ['label' => 'Players', 'path' => 'uploads/players'],
        'sponsors' => ['label' => 'Sponsors', 'path' => 'uploads/sponsors'],
        'matches' => ['label' => 'Matches', 'path' => 'uploads/matches'],
        'competitions' => ['label' => 'Competitions', 'path' => 'uploads/competitions'],
        'badges' => ['label' => 'Club badges', 'path' => 'badges'],
    ];
}

function hub_media_safe_stem(string $name): string
{
    $stem = pathinfo(basename($name), PATHINFO_FILENAME);
    $stem = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $stem), '-_');
    return substr($stem !== '' ? $stem : 'image', 0, 100);
}

function hub_media_validate_upload(array $file, int $maxBytes = 25_000_000): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) return ['ok' => false, 'message' => 'The image upload did not complete.'];
    $temp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temp === '' || !is_uploaded_file($temp) || $size < 1 || $size > $maxBytes) return ['ok' => false, 'message' => 'Images must be no larger than 25 MB.'];
    $info = @getimagesize($temp);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($map[$mime])) return ['ok' => false, 'message' => 'Use a JPG, PNG, WEBP or GIF image.'];
    return ['ok' => true, 'temp' => $temp, 'extension' => $map[$mime], 'mime' => $mime];
}

function hub_media_unique_path(string $directory, string $stem, string $extension): string
{
    $candidate = $directory . '/' . $stem . '.' . $extension;
    $counter = 2;
    while (file_exists($candidate)) $candidate = $directory . '/' . $stem . '-' . $counter++ . '.' . $extension;
    return $candidate;
}

function hub_media_convert(string $source, string $destination): bool
{
    $extension = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    $bytes = @file_get_contents($source);
    $image = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if (!$image) return false;
    if (in_array($extension, ['png', 'webp', 'gif'], true)) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    $ok = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($image, $destination, 92),
        'png' => imagepng($image, $destination, 6),
        'webp' => function_exists('imagewebp') && imagewebp($image, $destination, 90),
        'gif' => imagegif($image, $destination),
        default => false,
    };
    imagedestroy($image);
    return $ok;
}
