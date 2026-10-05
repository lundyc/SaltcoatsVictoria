<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$playerId = isset($_GET['player_id']) ? (int) $_GET['player_id'] : 0;
if ($playerId <= 0) {
    http_response_code(400);
    exit('Missing player_id');
}

$seasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : getSelectedSeasonId($pdo);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$showThirdSlot = in_array('third', $allowedSlots, true);

$sql = "
SELECT
    p.id,
    p.name,
    p.avatar,
    s1.name AS home_sponsor,
    s1.logo_path AS home_logo,
    s2.name AS away_sponsor,
    s2.logo_path AS away_logo,
    s3.name AS third_sponsor,
    s3.logo_path AS third_logo
FROM players p
LEFT JOIN sponsorships sp1 ON sp1.player_id = p.id AND sp1.slot = 'home'
    AND sp1.season_id = :season_id AND sp1.ended_at IS NULL
LEFT JOIN sponsors s1 ON s1.id = sp1.sponsor_id
LEFT JOIN sponsorships sp2 ON sp2.player_id = p.id AND sp2.slot = 'away'
    AND sp2.season_id = :season_id AND sp2.ended_at IS NULL
LEFT JOIN sponsors s2 ON s2.id = sp2.sponsor_id
LEFT JOIN sponsorships sp3 ON sp3.player_id = p.id AND sp3.slot = 'third'
    AND sp3.season_id = :season_id AND sp3.ended_at IS NULL
LEFT JOIN sponsors s3 ON s3.id = sp3.sponsor_id
WHERE p.id = :id
LIMIT 1;
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $playerId, ':season_id' => $seasonId]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    http_response_code(404);
    exit('Player not found');
}

$playerName = trim((string) ($player['name'] ?? 'Player'));

$homeSponsor  = trim((string) ($player['home_sponsor'] ?? ''));
$awaySponsor  = trim((string) ($player['away_sponsor'] ?? ''));
$thirdSponsor = trim((string) ($player['third_sponsor'] ?? ''));
if (!$showThirdSlot) {
    $thirdSponsor = '';
}

$homeLogo  = !empty($player['home_logo']) ? __DIR__ . '/uploads/sponsors/' . $player['home_logo'] : null;
$awayLogo  = !empty($player['away_logo']) ? __DIR__ . '/uploads/sponsors/' . $player['away_logo'] : null;
$thirdLogo = !empty($player['third_logo']) ? __DIR__ . '/uploads/sponsors/' . $player['third_logo'] : null;
if (!$showThirdSlot) {
    $thirdLogo = null;
}

$avatarFile = !empty($player['avatar']) ? basename((string) $player['avatar']) : '';
$photoPath  = $avatarFile !== '' ? __DIR__ . '/uploads/players/' . $avatarFile : null;
if ($photoPath && !is_file($photoPath)) {
    $photoPath = null;
}

$isSameSponsor = $homeSponsor !== ''
    && $awaySponsor !== ''
    && mb_strtolower($homeSponsor) === mb_strtolower($awaySponsor);

function getPlayerSlotPrice(PDO $pdo, string $code): float
{
    $package = getSponsorshipPackageByCode($pdo, $code);
    return $package ? (float) $package['amount'] : 0.0;
}

$width = 768;
$height = 768;
$im = imagecreatetruecolor($width, $height);
imagesavealpha($im, true);
imagealphablending($im, true);

$maroon      = imagecolorallocate($im, 88, 16, 30);
$gold        = imagecolorallocate($im, 197, 155, 58);
$panelBg     = imagecolorallocate($im, 250, 244, 233);
$panelLine   = imagecolorallocate($im, 224, 205, 172);
$white       = imagecolorallocate($im, 255, 255, 255);
$textDark    = $maroon;
$textMuted   = imagecolorallocate($im, 150, 116, 90);

$fontDir = __DIR__ . '/assets/fonts';
function safeFont(string $dir, string $file, array $fallbacks = []): ?string
{
    $candidates = array_merge([$dir . '/' . $file], $fallbacks);
    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            return $candidate;
        }
    }
    return null;
}

$fontBlack  = safeFont($fontDir, 'Roboto_Condensed-Black.ttf', [$fontDir . '/Roboto_Condensed-ExtraBold.ttf']);
$fontBold   = safeFont($fontDir, 'Roboto_Condensed-Bold.ttf', [
    $fontDir . '/Montserrat-ExtraBold.ttf',
    $fontDir . '/Roboto-Bold.ttf',
]);
$fontMedium = safeFont($fontDir, 'Roboto_Condensed-Regular.ttf', [
    $fontDir . '/Montserrat-SemiBold.ttf',
    $fontDir . '/Roboto-Regular.ttf',
]);

if (!$fontBold || !$fontMedium) {
    http_response_code(500);
    exit('Required font files are missing');
}
if (!$fontBlack) {
    $fontBlack = $fontBold;
}

function textBoxWidth(string $font, float $size, string $text): float
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    return (float) ($bbox[2] - $bbox[0]);
}

function fitTextSize(string $font, string $text, float $maxWidth, float $startSize, float $minSize): float
{
    $size = $startSize;
    while ($size > $minSize && textBoxWidth($font, $size, $text) > $maxWidth) {
        $size -= 1;
    }
    return max($size, $minSize);
}

function splitNameLines(string $font, string $name, float $maxWidth, float $startSize): array
{
    $name = preg_replace('/\s+/', ' ', trim(mb_strtoupper($name)));
    if ($name === '') {
        return [['text' => 'PLAYER', 'size' => $startSize]];
    }

    $words = preg_split('/\s+/', $name) ?: [$name];
    if (count($words) === 1) {
        return [[
            'text' => $name,
            'size' => fitTextSize($font, $name, $maxWidth, $startSize, 26),
        ]];
    }

    if (count($words) === 2) {
        return [
            ['text' => $words[0], 'size' => fitTextSize($font, $words[0], $maxWidth, $startSize, 26)],
            ['text' => $words[1], 'size' => fitTextSize($font, $words[1], $maxWidth, $startSize, 26)],
        ];
    }

    $bestSplit = null;
    $fewestOverflow = PHP_FLOAT_MAX;

    for ($i = 1, $count = count($words); $i < $count; $i++) {
        $first = implode(' ', array_slice($words, 0, $i));
        $second = implode(' ', array_slice($words, $i));
        $firstWidth = textBoxWidth($font, $startSize, $first);
        $secondWidth = textBoxWidth($font, $startSize, $second);
        $overflow = max($firstWidth, $secondWidth);
        if ($overflow < $fewestOverflow) {
            $fewestOverflow = $overflow;
            $bestSplit = [$first, $second];
        }
    }

    if (!$bestSplit) {
        $bestSplit = [$name];
    }

    $lines = [];
    foreach ($bestSplit as $line) {
        $lines[] = ['text' => $line, 'size' => fitTextSize($font, $line, $maxWidth, $startSize, 26)];
    }

    return $lines;
}

function drawTextRight($im, float $size, int $xRight, int $baselineY, int $color, string $font, string $text): void
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    $textWidth = $bbox[2] - $bbox[0];
    $x = (int) round($xRight - $textWidth);
    imagettftext($im, $size, 0, $x, $baselineY, $color, $font, $text);
}

function loadImageResource(string $path)
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    return @imagecreatefromstring($raw) ?: null;
}

function drawCoverImage($im, string $path, int $dx, int $dy, int $dw, int $dh): bool
{
    $src = loadImageResource($path);
    if (!$src) {
        return false;
    }
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return false;
    }

    $destAspect = $dw / $dh;
    $srcAspect = $srcW / $srcH;
    if ($srcAspect > $destAspect) {
        $cropH = $srcH;
        $cropW = (int) round($srcH * $destAspect);
        $srcX = (int) round(($srcW - $cropW) / 2);
        $srcY = 0;
    } else {
        $cropW = $srcW;
        $cropH = (int) round($srcW / $destAspect);
        $srcX = 0;
        $srcY = (int) round(($srcH - $cropH) / 2);
    }

    imagecopyresampled($im, $src, $dx, $dy, $srcX, $srcY, $dw, $dh, $cropW, $cropH);
    imagedestroy($src);
    return true;
}

function drawLogoInBox($im, string $path, int $x, int $y, int $w, int $h): bool
{
    $src = loadImageResource($path);
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return false;
    }

    $scale = min($w / $srcW, $h / $srcH);
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));
    $dstX = $x + (int) round(($w - $newW) / 2);
    $dstY = $y + (int) round(($h - $newH) / 2);

    imagesavealpha($im, true);
    imagecopyresampled($im, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($src);
    return true;
}

function averageLogoBrightness($src): float
{
    $w = imagesx($src);
    $h = imagesy($src);
    $stepX = max(1, (int) floor($w / 40));
    $stepY = max(1, (int) floor($h / 40));
    $sum = 0.0;
    $count = 0;
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            $rgba = imagecolorat($src, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            if ($alpha > 40) {
                continue;
            }
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $sum += (0.299 * $r + 0.587 * $g + 0.114 * $b);
            $count++;
        }
    }
    return $count > 0 ? ($sum / $count) : 128.0;
}

// Draws a sponsor logo on a chip whose colour is chosen for contrast against the
// logo's own ink (many sponsor logos here are white-on-transparent, made for a
// dark background — a plain light card would make them invisible).
function drawLogoChip($im, string $path, int $x, int $y, int $w, int $h, int $lightChip, int $darkChip, int $chipBorder): bool
{
    $src = loadImageResource($path);
    if (!$src) {
        return false;
    }
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) {
        imagedestroy($src);
        return false;
    }

    $brightness = averageLogoBrightness($src);
    $chipColor = $brightness > 150 ? $darkChip : $lightChip;
    imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $chipColor);
    imagerectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $chipBorder);

    $pad = 12;
    $innerW = $w - ($pad * 2);
    $innerH = $h - ($pad * 2);
    $scale = min($innerW / $srcW, $innerH / $srcH);
    $newW = max(1, (int) round($srcW * $scale));
    $newH = max(1, (int) round($srcH * $scale));
    $dstX = $x + (int) round(($w - $newW) / 2);
    $dstY = $y + (int) round(($h - $newH) / 2);

    imagesavealpha($im, true);
    imagecopyresampled($im, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($src);
    return true;
}

function initialsOf(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

// ----------------------------------------------------------------------------------
// Layout geometry: photo left, sponsor panel right
// ----------------------------------------------------------------------------------
$splitX = 338;

imagefilledrectangle($im, 0, 0, $width, $height, $panelBg);

$photoDrawn = $photoPath ? drawCoverImage($im, $photoPath, 0, 0, $splitX, $height) : false;
if (!$photoDrawn) {
    imagefilledrectangle($im, 0, 0, $splitX, $height, $maroon);
    $initials = initialsOf($playerName);
    $initialsSize = fitTextSize($fontBlack, $initials, $splitX - 60, 150, 60);
    $bbox = imagettfbbox($initialsSize, 0, $fontBlack, $initials);
    $textW = $bbox[2] - $bbox[0];
    imagettftext($im, $initialsSize, 0, (int) round(($splitX - $textW) / 2), (int) round($height / 2) + 20, $gold, $fontBlack, $initials);
}

// Gold seam between photo and panel
imagefilledrectangle($im, $splitX, 0, $splitX + 3, $height, $gold);

$panelPad = 40;
$contentX1 = $splitX + 3 + $panelPad;
$contentX2 = $width - $panelPad;
$contentW = $contentX2 - $contentX1;

// Header: club badge + eyebrow text
$badgeSize = 56;
$badgePath = __DIR__ . '/assets/images/badge.png';
$badgeDrawn = drawLogoInBox($im, $badgePath, $contentX2 - $badgeSize, 34, $badgeSize, $badgeSize);
$eyebrowRight = $badgeDrawn ? ($contentX2 - $badgeSize - 16) : $contentX2;
drawTextRight($im, 15, $eyebrowRight, 68, $textMuted, $fontMedium, 'SALTCOATS VICTORIA FC');

imagefilledrectangle($im, $contentX1, 104, $contentX2, 106, $gold);

// Player name
$nameLines = splitNameLines($fontBlack, $playerName, (float) $contentW, 62);
$nameStartY = count($nameLines) === 1 ? 205 : 172;
foreach ($nameLines as $index => $line) {
    drawTextRight($im, $line['size'], $contentX2, $nameStartY + ($index * 74), $textDark, $fontBlack, $line['text']);
}

imagefilledrectangle($im, $contentX1, 268, $contentX2, 269, $panelLine);

// ----------------------------------------------------------------------------------
// Sponsor rows (one per applicable kit slot)
// ----------------------------------------------------------------------------------
$rows = [];
if ($isSameSponsor) {
    $rows[] = [
        'label' => 'HOME & AWAY KIT SPONSOR',
        'name'  => $homeSponsor,
        'logo'  => ($homeLogo && is_file($homeLogo)) ? $homeLogo : (($awayLogo && is_file($awayLogo)) ? $awayLogo : null),
        'price' => 0.0,
    ];
} else {
    $rows[] = [
        'label' => 'HOME KIT SPONSOR',
        'name'  => $homeSponsor,
        'logo'  => ($homeLogo && is_file($homeLogo)) ? $homeLogo : null,
        'price' => getPlayerSlotPrice($pdo, 'player_home'),
    ];
    $rows[] = [
        'label' => 'AWAY KIT SPONSOR',
        'name'  => $awaySponsor,
        'logo'  => ($awayLogo && is_file($awayLogo)) ? $awayLogo : null,
        'price' => getPlayerSlotPrice($pdo, 'player_away'),
    ];
}
if ($showThirdSlot) {
    $rows[] = [
        'label' => 'THIRD KIT SPONSOR',
        'name'  => $thirdSponsor,
        'logo'  => ($thirdLogo && is_file($thirdLogo)) ? $thirdLogo : null,
        'price' => getPlayerSlotPrice($pdo, 'player_third'),
    ];
}

$rowsTop = 292;
$rowsBottom = 730;
$rowCount = max(1, count($rows));
$rowH = (int) floor(($rowsBottom - $rowsTop) / $rowCount);

foreach ($rows as $i => $row) {
    $rowTop = $rowsTop + ($i * $rowH);

    drawTextRight($im, 14, $contentX2, $rowTop + 20, $textMuted, $fontMedium, $row['label']);

    $sponsored = trim((string) $row['name']) !== '';
    if ($sponsored) {
        $logoBoxH = min(88, $rowH - 46);
        $hasLogo = $row['logo'] && drawLogoChip($im, $row['logo'], $contentX1, $rowTop + 28, $contentW, $logoBoxH, $white, $maroon, $panelLine);
        if (!$hasLogo) {
            $nameSize = fitTextSize($fontBold, mb_strtoupper($row['name']), (float) $contentW, 24, 15);
            imagettftext($im, $nameSize, 0, $contentX1, $rowTop + 55, $textDark, $fontBold, mb_strtoupper($row['name']));
        }
    } else {
        imagettftext($im, 16, 0, $contentX1, $rowTop + 48, $textMuted, $fontMedium, 'AVAILABLE FOR SPONSORSHIP');
        $priceText = $row['price'] > 0 ? gbp($row['price']) : 'ENQUIRE';
        imagettftext($im, 26, 0, $contentX1, $rowTop + $rowH - 14, $gold, $fontBold, $priceText);
    }

    if ($i < $rowCount - 1) {
        imageline($im, $contentX1, $rowTop + $rowH - 1, $contentX2, $rowTop + $rowH - 1, $panelLine);
    }
}

header('Content-Type: image/png');
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $playerName);
header('Content-Disposition: inline; filename="' . $safeName . '.png"');
imagesavealpha($im, true);
imagepng($im);
imagedestroy($im);
exit;
