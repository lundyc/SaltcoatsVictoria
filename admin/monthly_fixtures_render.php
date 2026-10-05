<?php
declare(strict_types=1);

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/monthly_fixtures.php';

$seasonId = (int)($_GET['season_id'] ?? getSelectedSeasonId($pdo));
$season = getSeasonById($pdo, $seasonId);
if (!$season) {
    http_response_code(404);
    exit('Season not found.');
}
$months = monthlyFixturesSeasonMonths($pdo, $seasonId);
$month = monthlyFixturesSelectMonth($months, (string)($_GET['month'] ?? ''));
$layout = monthlyFixturesLayout((string)($_GET['layout'] ?? 'block'));
$fixtures = monthlyFixturesForMonth($pdo, $seasonId, $month);
$monthTimestamp = strtotime($month . '-01');
$monthLabel = $monthTimestamp !== false ? strtoupper(date('F Y', $monthTimestamp)) : strtoupper($month);

function monthlyGraphicColour(GdImage $image, string $hex, int $alpha = 0): int
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        $hex = '000000';
    }
    return imagecolorallocatealpha(
        $image,
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
        max(0, min(127, $alpha))
    );
}

function monthlyGraphicLoadImage(string $path): ?GdImage
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $info = @getimagesize($path);
    return match ((int)($info[2] ?? 0)) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path) ?: null,
        IMAGETYPE_PNG => @imagecreatefrompng($path) ?: null,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
        IMAGETYPE_GIF => @imagecreatefromgif($path) ?: null,
        default => null,
    };
}

function monthlyGraphicDrawCover(GdImage $canvas, GdImage $source, int $width, int $height): void
{
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = max($width / max(1, $sourceWidth), $height / max(1, $sourceHeight));
    $drawWidth = (int)ceil($sourceWidth * $scale);
    $drawHeight = (int)ceil($sourceHeight * $scale);
    $drawX = (int)floor(($width - $drawWidth) / 2);
    $drawY = (int)floor(($height - $drawHeight) / 2);
    imagecopyresampled($canvas, $source, $drawX, $drawY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
}

function monthlyGraphicRoundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
{
    $radius = max(0, min($radius, (int)floor(min($x2 - $x1, $y2 - $y1) / 2)));
    if ($radius === 0) {
        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $colour);
        return;
    }
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $colour);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $colour);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $colour);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $colour);
}

function monthlyGraphicDrawCupIcon(GdImage $image, int $x, int $y, int $size, int $backgroundColour, int $iconColour): void
{
    monthlyGraphicRoundedRect($image, $x, $y, $x + $size, $y + $size, 4, $backgroundColour);
    $cupLeft = $x + (int)round($size * .32);
    $cupTop = $y + (int)round($size * .24);
    $cupRight = $x + (int)round($size * .68);
    $cupBottom = $y + (int)round($size * .52);
    imagefilledrectangle($image, $cupLeft, $cupTop, $cupRight, $cupBottom, $iconColour);
    imagefilledellipse($image, $cupLeft, $cupTop + (int)round($size * .15), (int)round($size * .24), (int)round($size * .22), $iconColour);
    imagefilledellipse($image, $cupRight, $cupTop + (int)round($size * .15), (int)round($size * .24), (int)round($size * .22), $iconColour);
    imagefilledrectangle($image, $x + (int)round($size * .46), $cupBottom, $x + (int)round($size * .54), $y + (int)round($size * .70), $iconColour);
    imagefilledrectangle($image, $x + (int)round($size * .35), $y + (int)round($size * .70), $x + (int)round($size * .65), $y + (int)round($size * .77), $iconColour);
}

function monthlyGraphicTextWidth(string $font, float $size, string $text): float
{
    $box = imagettfbbox($size, 0, $font, $text);
    return (float)abs($box[2] - $box[0]);
}

function monthlyGraphicFitText(string $font, string $text, float $maxWidth, float $startSize, float $minimumSize): float
{
    $size = $startSize;
    while ($size > $minimumSize && monthlyGraphicTextWidth($font, $size, $text) > $maxWidth) {
        $size -= 1;
    }
    return max($minimumSize, $size);
}

function monthlyGraphicText(GdImage $image, string $font, float $size, int $x, int $baseline, int $colour, string $text): void
{
    imagettftext($image, $size, 0, $x, $baseline, $colour, $font, $text);
}

function monthlyGraphicCenteredText(GdImage $image, string $font, float $size, int $centerX, int $baseline, int $colour, string $text): void
{
    monthlyGraphicText($image, $font, $size, (int)round($centerX - monthlyGraphicTextWidth($font, $size, $text) / 2), $baseline, $colour, $text);
}

function monthlyGraphicDrawContained(GdImage $canvas, string $path, int $x, int $y, int $maxWidth, int $maxHeight): bool
{
    $source = monthlyGraphicLoadImage($path);
    if (!$source) {
        return false;
    }
    imagesavealpha($source, true);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min($maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
    $drawWidth = max(1, (int)round($sourceWidth * $scale));
    $drawHeight = max(1, (int)round($sourceHeight * $scale));
    $drawX = $x + (int)floor(($maxWidth - $drawWidth) / 2);
    $drawY = $y + (int)floor(($maxHeight - $drawHeight) / 2);
    imagecopyresampled($canvas, $source, $drawX, $drawY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
    imagedestroy($source);
    return true;
}

function monthlyGraphicInitials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 3) as $word) {
        $initials .= strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $word) ?? '', 0, 1));
    }
    return $initials !== '' ? $initials : 'FC';
}

function monthlyGraphicDrawBadgeOrInitials(GdImage $image, array $fixture, int $x, int $y, int $width, int $height, string $font, int $fallbackColour, int $textColour): void
{
    $path = monthlyFixturesOpponentBadgePath($fixture);
    if ($path !== '' && monthlyGraphicDrawContained($image, $path, $x, $y, $width, $height)) {
        return;
    }
    $diameter = min($width, $height);
    imagefilledellipse($image, $x + (int)floor($width / 2), $y + (int)floor($height / 2), $diameter, $diameter, $fallbackColour);
    $initials = monthlyGraphicInitials((string)($fixture['opponent'] ?? 'FC'));
    $size = monthlyGraphicFitText($font, $initials, $diameter - 14, max(14, $diameter * 0.24), 10);
    monthlyGraphicCenteredText($image, $font, $size, $x + (int)floor($width / 2), $y + (int)floor($height / 2) + (int)round($size * 0.38), $textColour, $initials);
}

$width = 1080;
$height = 1350;
$image = imagecreatetruecolor($width, $height);
imagesavealpha($image, false);
imagealphablending($image, true);

$maroon = monthlyGraphicColour($image, '#6a2036');
$darkMaroon = monthlyGraphicColour($image, '#3f0715');
$gold = monthlyGraphicColour($image, '#e0b42a');
$cream = monthlyGraphicColour($image, '#f6ecde');
$white = monthlyGraphicColour($image, '#ffffff');
$ink = monthlyGraphicColour($image, '#1f1a1d');
$softWhite = monthlyGraphicColour($image, '#ffffff', 22);
$panel = monthlyGraphicColour($image, '#fffdf9', 15);
$panelDark = monthlyGraphicColour($image, '#160f12', 22);
$mutedWhite = monthlyGraphicColour($image, '#ffffff', 42);

$backgroundPath = monthlyFixturesBackgroundPath($seasonId);
$background = monthlyGraphicLoadImage($backgroundPath);
if ($background) {
    monthlyGraphicDrawCover($image, $background, $width, $height);
    imagedestroy($background);
    imagefilledrectangle($image, 0, 0, $width, $height, monthlyGraphicColour($image, '#160f12', 38));
} else {
    imagefilledrectangle($image, 0, 0, $width, $height, $darkMaroon);
    for ($y = 0; $y < $height; $y += 5) {
        $progress = $y / $height;
        $red = (int)round(63 + 25 * $progress);
        $green = (int)round(7 + 12 * $progress);
        $blue = (int)round(21 + 22 * $progress);
        imageline($image, 0, $y, $width, $y, imagecolorallocate($image, $red, $green, $blue));
    }
    imagefilledellipse($image, 930, 180, 620, 620, monthlyGraphicColour($image, '#e0b42a', 112));
    imagefilledellipse($image, 100, 1190, 760, 760, monthlyGraphicColour($image, '#ffffff', 121));
}

$fontBlack = __DIR__ . '/assets/fonts/Montserrat-Black.ttf';
$fontExtraBold = __DIR__ . '/assets/fonts/Montserrat-ExtraBold.ttf';
$fontBold = __DIR__ . '/assets/fonts/Montserrat-Bold.ttf';
$fontRegular = __DIR__ . '/assets/fonts/Montserrat-Regular.ttf';
foreach ([$fontBlack, $fontExtraBold, $fontBold, $fontRegular] as $font) {
    if (!is_file($font)) {
        http_response_code(500);
        exit('Required graphic font is missing.');
    }
}

$clubBadgePath = __DIR__ . '/assets/images/Saltcoats Victoria FC.png';
monthlyGraphicDrawContained($image, $clubBadgePath, 466, 36, 148, 148);
monthlyGraphicCenteredText($image, $fontBlack, 48, 540, 230, $cream, 'MONTHLY FIXTURES');
monthlyGraphicCenteredText($image, $fontBold, 24, 540, 276, $gold, $monthLabel);
imagefilledrectangle($image, 435, 300, 645, 305, $gold);

if ($layout === 'calendar') {
    $weekdays = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    $gridX = 68;
    $gridWidth = 944;
    $columnGap = 8;
    $cellWidth = (int)floor(($gridWidth - $columnGap * 6) / 7);
    foreach ($weekdays as $index => $weekday) {
        $cellX = $gridX + $index * ($cellWidth + $columnGap);
        monthlyGraphicCenteredText($image, $fontBold, 15, $cellX + (int)floor($cellWidth / 2), 346, $mutedWhite, $weekday);
    }

    $year = (int)substr($month, 0, 4);
    $monthNumber = (int)substr($month, 5, 2);
    $firstTimestamp = strtotime(sprintf('%04d-%02d-01', $year, $monthNumber));
    $daysInMonth = (int)date('t', $firstTimestamp ?: time());
    $firstWeekday = (int)date('N', $firstTimestamp ?: time());
    $rows = (int)ceil(($firstWeekday - 1 + $daysInMonth) / 7);
    $gridY = 372;
    $gridBottom = 1200;
    $rowGap = 9;
    $cellHeight = (int)floor(($gridBottom - $gridY - $rowGap * ($rows - 1)) / $rows);
    $fixturesByDay = [];
    foreach ($fixtures as $fixture) {
        $day = (int)substr((string)$fixture['match_date'], 8, 2);
        $fixturesByDay[$day][] = $fixture;
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $position = $firstWeekday - 1 + $day - 1;
        $column = $position % 7;
        $row = (int)floor($position / 7);
        $x = $gridX + $column * ($cellWidth + $columnGap);
        $y = $gridY + $row * ($cellHeight + $rowGap);
        $dayFixtures = $fixturesByDay[$day] ?? [];
        $fixture = $dayFixtures[0] ?? null;
        $isHome = $fixture && (int)$fixture['is_home'] === 1;
        $backgroundColour = $fixture ? ($isHome ? $gold : $maroon) : $panelDark;
        $textColour = $fixture && $isHome ? $ink : $cream;
        monthlyGraphicRoundedRect($image, $x, $y, $x + $cellWidth, $y + $cellHeight, 11, $backgroundColour);
        monthlyGraphicText($image, $fontExtraBold, 19, $x + 10, $y + 27, $textColour, (string)$day);

        if ($fixture) {
            monthlyGraphicText($image, $fontBlack, 15, $x + $cellWidth - 27, $y + 25, $textColour, $isHome ? 'H' : 'A');
            $badgeSize = min(66, max(42, $cellHeight - 69));
            $badgeX = $x + (int)floor(($cellWidth - $badgeSize) / 2);
            $badgeY = $y + 34;
            monthlyGraphicDrawBadgeOrInitials($image, $fixture, $badgeX, $badgeY, $badgeSize, $badgeSize, $fontExtraBold, $isHome ? $maroon : $gold, $isHome ? $cream : $ink);
            $opponent = strtoupper((string)$fixture['opponent']);
            $opponentSize = monthlyGraphicFitText($fontBold, $opponent, $cellWidth - 14, 10, 7);
            monthlyGraphicCenteredText($image, $fontBold, $opponentSize, $x + (int)floor($cellWidth / 2), $y + $cellHeight - 12, $textColour, $opponent);
            if (count($dayFixtures) > 1) {
                monthlyGraphicText($image, $fontBold, 9, $x + 8, $y + $cellHeight - 12, $textColour, '+' . (count($dayFixtures) - 1));
            }
            if (monthlyFixturesIsCupFixture($fixture)) {
                monthlyGraphicDrawCupIcon($image, $x + $cellWidth - 33, $y + $cellHeight - 33, 24, $isHome ? $maroon : $gold, $isHome ? $gold : $ink);
            }
        }
    }
} else {
    $visibleFixtures = array_slice($fixtures, 0, 9);
    $fixtureCount = count($visibleFixtures);

    monthlyGraphicRoundedRect($image, 68, 326, 238, 380, 4, $gold);
    monthlyGraphicCenteredText($image, $fontBlack, 18, 153, 362, $ink, 'HOME');
    monthlyGraphicRoundedRect($image, 252, 326, 422, 380, 4, $cream);
    monthlyGraphicCenteredText($image, $fontBlack, 18, 337, 362, $maroon, 'AWAY');
    monthlyGraphicText($image, $fontBlack, 20, 830, 362, $gold, 'FIXTURES');

    if ($fixtureCount === 0) {
        monthlyGraphicRoundedRect($image, 68, 420, 1012, 790, 20, $panel);
        monthlyGraphicCenteredText($image, $fontExtraBold, 30, 540, 575, $ink, 'NO FIXTURES SCHEDULED');
        monthlyGraphicCenteredText($image, $fontRegular, 18, 540, 620, $maroon, 'Add fixtures in Match Day and regenerate this graphic.');
    } else {
        $columns = 3;
        $rows = (int)ceil($fixtureCount / $columns);
        $gridX = 68;
        $gridY = 414;
        $gridWidth = 944;
        $columnGap = 18;
        $rowGap = 18;
        $cardWidth = (int)floor(($gridWidth - ($columnGap * ($columns - 1))) / $columns);
        $cardHeight = $rows <= 2 ? 300 : 236;

        foreach ($visibleFixtures as $index => $fixture) {
            $column = $index % $columns;
            $row = (int)floor($index / $columns);
            $x = $gridX + $column * ($cardWidth + $columnGap);
            $y = $gridY + $row * ($cardHeight + $rowGap);
            $isHome = (int)$fixture['is_home'] === 1;
            $cardColour = $isHome ? $gold : $cream;
            $cardTextColour = $isHome ? $ink : $maroon;
            $labelColour = $isHome ? $maroon : $gold;
            $labelTextColour = $isHome ? $cream : $ink;
            monthlyGraphicRoundedRect($image, $x, $y, $x + $cardWidth, $y + $cardHeight, 5, $cardColour);
            monthlyGraphicRoundedRect($image, $x + 12, $y + 12, $x + 54, $y + 54, 2, $labelColour);
            monthlyGraphicCenteredText($image, $fontBlack, 16, $x + 33, $y + 41, $labelTextColour, $isHome ? 'H' : 'A');

            $badgeSize = $rows <= 2 ? 132 : 92;
            $badgeTop = $y + ($rows <= 2 ? 45 : 42);
            monthlyGraphicDrawBadgeOrInitials(
                $image,
                $fixture,
                $x + (int)floor(($cardWidth - $badgeSize) / 2),
                $badgeTop,
                $badgeSize,
                $badgeSize,
                $fontExtraBold,
                $maroon,
                $cream
            );

            $opponent = strtoupper(trim((string)$fixture['opponent']));
            $opponentSize = monthlyGraphicFitText($fontBlack, $opponent, $cardWidth - 28, $rows <= 2 ? 16 : 13, 9);
            monthlyGraphicCenteredText($image, $fontBlack, $opponentSize, $x + (int)floor($cardWidth / 2), $y + $cardHeight - 54, $cardTextColour, $opponent);
            $timestamp = strtotime((string)$fixture['match_date']);
            $dateLabel = $timestamp ? strtoupper(date('M j', $timestamp)) : 'DATE TBC';
            monthlyGraphicCenteredText($image, $fontBold, $rows <= 2 ? 15 : 12, $x + (int)floor($cardWidth / 2), $y + $cardHeight - 24, $cardTextColour, $dateLabel);
            if (monthlyFixturesIsCupFixture($fixture)) {
                monthlyGraphicDrawCupIcon($image, $x + $cardWidth - 44, $y + $cardHeight - 44, 32, $labelColour, $labelTextColour);
            }
        }
        if (count($fixtures) > count($visibleFixtures)) {
            monthlyGraphicCenteredText($image, $fontBold, 13, 540, 1225, $cream, '+' . (count($fixtures) - count($visibleFixtures)) . ' MORE FIXTURES');
        }
    }
}

monthlyGraphicCenteredText($image, $fontBold, 14, 540, 1288, $mutedWhite, 'ALL FIXTURES ARE SUBJECT TO CHANGE');
monthlyGraphicCenteredText($image, $fontRegular, 12, 540, 1320, $gold, 'SALTCOATS VICTORIA FC · ' . strtoupper((string)$season['name']));

if (isset($_GET['download'])) {
    $filename = 'monthly-fixtures-' . strtolower($monthLabel) . '-' . $layout . '.png';
    $filename = preg_replace('/[^a-z0-9.-]+/', '-', $filename) ?: 'monthly-fixtures.png';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
imagepng($image, null, 6);
imagedestroy($image);
