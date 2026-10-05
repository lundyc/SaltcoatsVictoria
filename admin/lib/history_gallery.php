<?php

declare(strict_types=1);

/**
 * Write helpers for the photo-album system (history_galleries + history_gallery_photos).
 *
 * Files for a Hub-created album live under:
 *   uploads/history/gallery/album-<id>/<stem>.<ext>          (full, max 1600px wide)
 *   uploads/history/gallery/album-<id>/thumb/<stem>.<ext>    (thumbnail, max 620px wide)
 *
 * That prefix is categorised "Club archive" by lib/media_library.php, so every
 * uploaded photo also appears in the Media Library.
 */

require_once __DIR__ . '/history.php';

const HISTORY_GALLERY_ROOT = 'history/gallery'; // relative to uploads/
const HISTORY_GALLERY_MAX_W = 1600;
const HISTORY_GALLERY_THUMB_W = 620;

/** Absolute path to the uploads/ directory. */
function history_gallery_uploads_dir(): string
{
    return __DIR__ . '/../uploads';
}

/** A URL/filesystem-safe stem from an original filename. */
function history_gallery_safe_stem(string $name): string
{
    $stem = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
    $stem = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $stem), '-'));
    return substr($stem !== '' ? $stem : 'photo', 0, 80);
}

/** Slugify a title into a unique history_galleries.slug. */
function history_gallery_slug(PDO $pdo, string $title, ?int $excludeId = null): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $base = substr($base, 0, 100);
    if ($base === '') {
        $base = 'album';
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM history_galleries WHERE slug = :s AND id <> :id');
    $slug = $base;
    $n = 2;
    while (true) {
        $stmt->execute([':s' => $slug, ':id' => $excludeId ?? 0]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Downscale an image with GD and write it to $destAbs (creating parent dirs).
 * Falls back to a plain copy when GD can't help or the image is already small.
 * Returns true on success.
 */
function history_gallery_write_image(string $srcAbs, string $destAbs, int $maxW, int $quality = 84): bool
{
    $dir = dirname($destAbs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $info = @getimagesize($srcAbs);
    $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
    $gdOk = $info && function_exists('imagecreatetruecolor')
        && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);

    if ($gdOk && $info[0] > $maxW) {
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcAbs),
            IMAGETYPE_PNG  => @imagecreatefrompng($srcAbs),
            IMAGETYPE_GIF  => @imagecreatefromgif($srcAbs),
            IMAGETYPE_WEBP => @imagecreatefromwebp($srcAbs),
            default        => null,
        };
        if ($src) {
            $w = (int) $info[0];
            $h = (int) $info[1];
            $nw = $maxW;
            $nh = max(1, (int) round($h * ($maxW / $w)));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ext = strtolower(pathinfo($destAbs, PATHINFO_EXTENSION));
            $ok = match ($ext) {
                'png'  => imagepng($dst, $destAbs, 6),
                'webp' => function_exists('imagewebp') && imagewebp($dst, $destAbs, 90),
                'gif'  => imagegif($dst, $destAbs),
                default => imagejpeg($dst, $destAbs, $quality),
            };
            imagedestroy($src);
            imagedestroy($dst);
            if ($ok) {
                return true;
            }
        }
    }

    return @copy($srcAbs, $destAbs);
}

/**
 * Store one uploaded image into an album: full + thumbnail, and insert a
 * history_gallery_photos row. Returns the new photo id, or null on failure.
 *
 * @param array{tmp_name?:string,name?:string,error?:int,size?:int} $file  one $_FILES entry
 */
function history_gallery_store_upload(PDO $pdo, int $albumId, array $file): ?int
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extByMime[$mime])) {
        return null;
    }
    $ext = $extByMime[$mime];

    $rel = HISTORY_GALLERY_ROOT . '/album-' . $albumId;
    $absDir = history_gallery_uploads_dir() . '/' . $rel;
    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return null;
    }

    // Unique stem within the album directory.
    $stem = history_gallery_safe_stem((string) ($file['name'] ?? 'photo'));
    $candidate = $stem;
    $n = 2;
    while (file_exists($absDir . '/' . $candidate . '.' . $ext)) {
        $candidate = $stem . '-' . $n++;
    }
    $stem = $candidate;

    $fileRel  = $rel . '/' . $stem . '.' . $ext;
    $thumbRel = $rel . '/thumb/' . $stem . '.' . $ext;
    $fileAbs  = history_gallery_uploads_dir() . '/' . $fileRel;
    $thumbAbs = history_gallery_uploads_dir() . '/' . $thumbRel;

    if (!history_gallery_write_image($tmp, $fileAbs, HISTORY_GALLERY_MAX_W)) {
        return null;
    }
    if (!history_gallery_write_image($fileAbs, $thumbAbs, HISTORY_GALLERY_THUMB_W, 78)) {
        $thumbRel = $fileRel; // fall back to the full image as its own thumb
    }

    $sortOrder = (int) $pdo->query(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM history_gallery_photos WHERE gallery_id = ' . $albumId
    )->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO history_gallery_photos (gallery_id, file_path, thumb_path, sort_order)
         VALUES (:g, :f, :t, :s)'
    );
    $stmt->execute([':g' => $albumId, ':f' => $fileRel, ':t' => $thumbRel, ':s' => $sortOrder]);
    return (int) $pdo->lastInsertId();
}

/** Recompute photo_count, and pick a cover from the first photo if none is set / the current cover is gone. */
function history_gallery_recount(PDO $pdo, int $albumId): void
{
    $photos = $pdo->prepare(
        'SELECT thumb_path FROM history_gallery_photos WHERE gallery_id = :g ORDER BY sort_order ASC, id ASC'
    );
    $photos->execute([':g' => $albumId]);
    $rows = $photos->fetchAll(PDO::FETCH_COLUMN);

    $album = $pdo->prepare('SELECT cover_path FROM history_galleries WHERE id = :g');
    $album->execute([':g' => $albumId]);
    $cover = (string) ($album->fetchColumn() ?: '');

    if ($cover === '' || !in_array($cover, $rows, true)) {
        $cover = $rows[0] ?? '';
    }

    $upd = $pdo->prepare('UPDATE history_galleries SET photo_count = :c, cover_path = :cover WHERE id = :g');
    $upd->execute([':c' => count($rows), ':cover' => $cover, ':g' => $albumId]);
}

/** Delete a single photo (row + files). Returns its gallery_id, or null. */
function history_gallery_delete_photo(PDO $pdo, int $photoId): ?int
{
    $stmt = $pdo->prepare('SELECT gallery_id, file_path, thumb_path FROM history_gallery_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach ([$row['file_path'], $row['thumb_path']] as $rel) {
        $rel = trim((string) $rel);
        if ($rel !== '') {
            @unlink(history_gallery_uploads_dir() . '/' . $rel);
        }
    }
    $pdo->prepare('DELETE FROM history_gallery_photos WHERE id = :id')->execute([':id' => $photoId]);
    return (int) $row['gallery_id'];
}

/** Delete an album, all its photos and (for Hub-created albums) its directory. */
function history_gallery_delete_album(PDO $pdo, int $albumId): void
{
    $stmt = $pdo->prepare('SELECT file_path, thumb_path FROM history_gallery_photos WHERE gallery_id = :g');
    $stmt->execute([':g' => $albumId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ([$row['file_path'], $row['thumb_path']] as $rel) {
            $rel = trim((string) $rel);
            if ($rel !== '') {
                @unlink(history_gallery_uploads_dir() . '/' . $rel);
            }
        }
    }
    $pdo->prepare('DELETE FROM history_gallery_photos WHERE gallery_id = :g')->execute([':g' => $albumId]);
    $pdo->prepare('DELETE FROM history_galleries WHERE id = :g')->execute([':g' => $albumId]);

    // Best effort: remove the album's own upload directory if it is now empty.
    $dir = history_gallery_uploads_dir() . '/' . HISTORY_GALLERY_ROOT . '/album-' . $albumId;
    if (is_dir($dir)) {
        @unlink($dir . '/thumb');
        @rmdir($dir . '/thumb');
        @rmdir($dir);
    }
}

/** Move a photo up or down within its album by swapping sort_order with its neighbour. */
function history_gallery_move_photo(PDO $pdo, int $photoId, string $direction): void
{
    $stmt = $pdo->prepare('SELECT gallery_id, sort_order FROM history_gallery_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $op = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighbour = $pdo->prepare(
        "SELECT id, sort_order FROM history_gallery_photos
         WHERE gallery_id = :g AND sort_order {$op} :o
         ORDER BY sort_order {$order}, id {$order} LIMIT 1"
    );
    $neighbour->execute([':g' => (int) $row['gallery_id'], ':o' => (int) $row['sort_order']]);
    $other = $neighbour->fetch(PDO::FETCH_ASSOC);
    if (!$other) {
        return;
    }
    $swap = $pdo->prepare('UPDATE history_gallery_photos SET sort_order = :s WHERE id = :id');
    $swap->execute([':s' => (int) $other['sort_order'], ':id' => $photoId]);
    $swap->execute([':s' => (int) $row['sort_order'], ':id' => (int) $other['id']]);
}
