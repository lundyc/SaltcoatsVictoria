<?php
/**
 * On-demand image optimisation for the public site.
 *
 * Originals in /uploads are never touched. Large PNG/JPG images are served via
 * /img/{width}/uploads/... as resized WebP, cached under cache/img/. The HTML
 * filter also adds intrinsic width/height to <img> tags (stops layout shift).
 */
declare(strict_types=1);

/** Allowed output widths; a request is snapped up to the nearest. */
const IMGOPT_WIDTHS = [96, 160, 240, 400, 800, 1200, 1600];
/** Files at or under this size that are already narrow are left alone. */
const IMGOPT_MIN_BYTES = 60000;

function imgopt_resolve(string $rel): ?string
{
    $rel = '/' . ltrim($rel, '/');
    if (!preg_match('#^/(uploads|badges)/[^\0]+\.(png|jpe?g)$#i', $rel) || str_contains($rel, '..')) {
        return null;
    }
    $full = realpath(PUBLIC_ROOT . $rel);
    $dir = explode('/', ltrim($rel, '/'))[0];
    $base = realpath(PUBLIC_ROOT . '/' . $dir);
    if ($full === false || $base === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
        || str_contains($full, DIRECTORY_SEPARATOR . 'secretary_documents' . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return is_file($full) ? $full : null;
}

function imgopt_snap(int $w): int
{
    foreach (IMGOPT_WIDTHS as $s) {
        if ($w <= $s) {
            return $s;
        }
    }
    return end(IMGOPT_WIDTHS);
}

/** Public URL of the optimised variant, or null when the original is fine. */
function imgopt_url(string $rel, int $maxWidth = 1200, string $fmt = 'webp'): ?string
{
    $rel = (string) parse_url($rel, PHP_URL_PATH);
    $file = imgopt_resolve($rel);
    if ($file === null) {
        return null;
    }
    $size = @getimagesize($file);
    if (!$size || $size[0] < 1) {
        return null;
    }
    if ($size[0] <= 480 && (int) filesize($file) <= IMGOPT_MIN_BYTES * 3) {
        return null; // small badge/crest: fine as is
    }
    $w = imgopt_snap(min($size[0], $maxWidth));
    return ($fmt === 'jpg' ? '/imgj/' : '/img/') . $w . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/')))) . '?v=' . filemtime($file);
}

/** Generate (or fetch from cache) and stream the WebP. Exits. */
function imgopt_serve(int $w, string $rel, string $fmt = 'webp'): void
{
    $file = imgopt_resolve($rel);
    if ($file === null || !in_array($w, IMGOPT_WIDTHS, true) || !function_exists('imagewebp')) {
        http_response_code(404);
        exit;
    }
    $dir = PUBLIC_ROOT . '/cache/img/' . $fmt . $w;
    $cache = $dir . '/' . sha1($file . '|' . filemtime($file)) . '.' . $fmt;
    if (!is_file($cache)) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imgopt_fallback($file);
        }
        $info = @getimagesize($file);
        if (!$info || $info[0] * $info[1] > 60_000_000) {
            imgopt_fallback($file);
        }
        @ini_set('memory_limit', '512M');
        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            default => false,
        };
        if (!$src) {
            imgopt_fallback($file);
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rot !== 0 && ($r = imagerotate($src, $rot, 0))) {
                $src = $r;
            }
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw > $w) {
            $dst = imagescale($src, $w, (int) round($sh * $w / $sw), IMG_BICUBIC);
            $src = $dst ?: $src;
        }
        imagepalettetotruecolor($src);
        $tmp = $cache . '.' . getmypid() . '.tmp';
        if ($fmt === 'jpg') {
            $flat = imagecreatetruecolor(imagesx($src), imagesy($src));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
            imagejpeg($flat, $tmp, 82);
        } else {
            imagealphablending($src, false);
            imagesavealpha($src, true);
            imagewebp($src, $tmp, 80);
        }
        // never serve something bigger than the source
        if (!is_file($tmp) || filesize($tmp) >= filesize($file)) {
            @unlink($tmp);
            imgopt_fallback($file);
        }
        rename($tmp, $cache);
    }
    header('Content-Type: ' . ($fmt === 'jpg' ? 'image/jpeg' : 'image/webp'));
    header('Content-Length: ' . filesize($cache));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($cache);
    exit;
}

function imgopt_fallback(string $file): never
{
    $mime = str_ends_with(strtolower($file), '.png') ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

/** Output filter: optimise local <img> tags and add missing width/height. */
function imgopt_filter_html(string $html): string
{
    $out = preg_replace_callback('#<img\b[^>]*>#i', static function (array $m): string {
        $tag = $m[0];
        // Templates can say how wide an image is shown on a phone (data-sz="148"). That becomes a
        // `sizes` hint that only applies up to 720px wide; above that it stays "100vw", which is
        // what the browser assumes anyway, so tablets and desktops pick exactly the same file as before.
        if (preg_match('#\sdata-sz="(\d{2,4})"#', $tag, $sz)) {
            $tag = str_replace($sz[0], '', $tag);
            if (!preg_match('#\ssizes=#i', $tag)) {
                $tag = preg_replace('#\s*/?>$#', ' sizes="(max-width: 720px) ' . $sz[1] . 'px, 100vw">', $tag, 1) ?? $tag;
            }
        }
        if (!preg_match('#\ssrc="(/(?:uploads|badges)/[^"?]+\.(?:png|jpe?g))(\?[^"]*)?"#i', $tag, $s)) {
            return $tag;
        }
        $rel = rawurldecode(html_entity_decode($s[1], ENT_QUOTES));
        $file = imgopt_resolve($rel);
        if ($file === null) {
            return $tag;
        }
        $size = @getimagesize($file);
        if (!$size) {
            return $tag;
        }
        [$w, $h] = $size;
        // Small display size (a 36px badge, a 42px crest): the template says how big it is shown through
        // its width attribute, so serve a copy about 2x and 3x that wide instead of the full-size file.
        if (preg_match('#\swidth="(\d{1,3})"#i', $tag, $dm)) {
            $disp = (int) $dm[1];
            if ($disp >= 16 && $disp <= 200 && $w > $disp * 3 && (int) filesize($file) > 15000 && !preg_match('#\ssrcset=#i', $tag)) {
                $enc = implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/')))) . '?v=' . filemtime($file);
                $s2 = imgopt_snap($disp * 2);
                $s3 = imgopt_snap($disp * 3);
                $set = '/img/' . $s2 . '/' . $enc . ' ' . $s2 . 'w' . ($s3 !== $s2 ? ', /img/' . $s3 . '/' . $enc . ' ' . $s3 . 'w' : '');
                $tag = str_replace($s[0], ' src="' . htmlspecialchars('/img/' . $s3 . '/' . $enc, ENT_QUOTES) . '"', $tag);
                $tag = preg_replace('#\s*/?>$#', ' srcset="' . htmlspecialchars($set, ENT_QUOTES) . '" sizes="' . $disp . 'px">', $tag, 1) ?? $tag;
                if (!preg_match('#\sdecoding=#i', $tag)) {
                    $tag = preg_replace('#\s*/?>$#', ' decoding="async">', $tag, 1) ?? $tag;
                }
                return $tag;
            }
        }
        $variant = imgopt_url($rel);
        if ($variant !== null) {
            $vw = (int) explode('/', $variant)[2];
            if ($w > $vw) {
                $h = (int) round($h * $vw / $w);
                $w = $vw;
            }
            $tag = str_replace($s[0], ' src="' . htmlspecialchars($variant, ENT_QUOTES) . '"', $tag);
            // Smaller copies for small screens. With no `sizes` the browser assumes the image is
            // as wide as the screen, so a 390px phone asks for the 800w copy while a desktop
            // (wider than the largest copy) still picks exactly the same file as before.
            if (!preg_match('#\ssrcset=#i', $tag)) {
                $set = [];
                foreach ([400, 800] as $smaller) {
                    if ($smaller < $vw && $smaller < $size[0]) {
                        $set[] = htmlspecialchars(str_replace('/img/' . $vw . '/', '/img/' . $smaller . '/', $variant), ENT_QUOTES) . ' ' . $smaller . 'w';
                    }
                }
                if ($set !== []) {
                    $set[] = htmlspecialchars($variant, ENT_QUOTES) . ' ' . $vw . 'w';
                    $tag = preg_replace('#\s*/?>$#', ' srcset="' . implode(', ', $set) . '">', $tag, 1) ?? $tag;
                }
            }
        }
        // Narrow but heavy images (the club crest is a 135 KB PNG shown at about 60px): keep the
        // original as the desktop source, and give small screens a 400px WebP copy instead.
        if ($variant === null && $w > 400 && $w <= 480 && (int) filesize($file) > IMGOPT_MIN_BYTES * 2
            && !preg_match('#\ssrcset=#i', $tag) && preg_match('#\.(png|jpe?g)$#i', $rel)) {
            $small = '/img/400/' . implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/')))) . '?v=' . filemtime($file);
            $tag = preg_replace('#\s*/?>$#', ' srcset="' . htmlspecialchars($small, ENT_QUOTES) . ' 400w, ' . htmlspecialchars($s[1] . ($s[2] ?? ''), ENT_QUOTES) . ' ' . $w . 'w"'
                . ' sizes="(max-width: 720px) 64px, ' . $w . 'px">', $tag, 1) ?? $tag;
        }
        if (!preg_match('#\swidth=#i', $tag) && !preg_match('#\sheight=#i', $tag)) {
            $tag = preg_replace('#\s*/?>$#', ' width="' . $w . '" height="' . $h . '">', $tag, 1) ?? $tag;
        }
        if (!preg_match('#\sdecoding=#i', $tag)) {
            $tag = preg_replace('#\s*/?>$#', ' decoding="async">', $tag, 1) ?? $tag;
        }
        return $tag;
    }, $html);
    return $out ?? $html;
}
