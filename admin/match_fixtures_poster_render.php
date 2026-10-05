<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$seasonId = (int)($_GET['season_id'] ?? getSelectedSeasonId($pdo));
$backgroundImage = trim((string)($_GET['background_image'] ?? (matchPosterSeasonBackgroundImage($seasonId) ?? '../assets/images/background.png')));

// 'fixtures' (default) draws the H/A letter; 'results' swaps in a colour-coded
// W/L/D plus the score line for any fixture that has a full-time score, and
// leaves upcoming fixtures showing H/A.
$view = (($_GET['view'] ?? '') === 'results') ? 'results' : 'fixtures';

function posterIntParam(string $key, int $default): int
{
          return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function fixturePosterAssetPath(string $value): ?string
{
          $value = trim($value);
          if ($value === '') {
                    return null;
          }

          if (preg_match('/^(https?:)?\/\//i', $value)) {
                    return null;
          }

          $candidate = $value;
          // Preserve older saved poster settings after consolidating shared assets.
          if (str_starts_with($candidate, 'assets/images/')) {
                    $candidate = '../' . $candidate;
          }
          if ($candidate[0] !== '/' && !preg_match('/^[A-Z]:\\\\/i', $candidate)) {
                    $candidate = __DIR__ . '/' . ltrim($candidate, '/');
          }

          return is_file($candidate) ? $candidate : null;
}

function posterTextWidth(string $font, float $size, string $text): float
{
          $box = imagettfbbox($size, 0, $font, $text);
          return (float)abs($box[2] - $box[0]);
}

function posterFitText(string $font, string $text, float $maxWidth, float $startSize, float $minSize): float
{
          $size = $startSize;
          while ($size > $minSize && posterTextWidth($font, $size, $text) > $maxWidth) {
                    $size -= 1;
          }
          return max($size, $minSize);
}

function posterDrawShadowedText($im, string $font, float $size, int $x, int $y, int $fg, int $shadow, string $text, int $shadowOffset = 2): void
{
          imagettftext($im, $size, 0, $x + $shadowOffset, $y + $shadowOffset, $shadow, $font, $text);
          imagettftext($im, $size, 0, $x, $y, $fg, $font, $text);
}

function posterDrawCenteredText($im, string $font, float $size, int $centerX, int $baselineY, int $fg, int $shadow, string $text): void
{
          $width = posterTextWidth($font, $size, $text);
          $x = (int)round($centerX - ($width / 2));
          posterDrawShadowedText($im, $font, $size, $x, $baselineY, $fg, $shadow, $text);
}

function posterDrawContainedImage($im, string $path, int $x, int $y, int $maxWidth, int $maxHeight): void
{
          if (!is_file($path)) {
                    return;
          }

          $info = getimagesize($path);
          if (!$info) {
                    return;
          }

          $src = match ($info[2]) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                    IMAGETYPE_PNG => imagecreatefrompng($path),
                    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
                    IMAGETYPE_GIF => imagecreatefromgif($path),
                    default => null,
          };
          if (!$src) {
                    return;
          }

          imagesavealpha($src, true);
          $sw = imagesx($src);
          $sh = imagesy($src);
          $ratio = min($maxWidth / max(1, $sw), $maxHeight / max(1, $sh));
          $dw = max(1, (int)round($sw * $ratio));
          $dh = max(1, (int)round($sh * $ratio));
          imagecopyresampled($im, $src, $x, $y, 0, 0, $dw, $dh, $sw, $sh);
          imagedestroy($src);
}

function posterContainedImageSize(string $path, int $maxWidth, int $maxHeight): array
{
          if (!is_file($path)) {
                    return [0, 0];
          }

          $info = getimagesize($path);
          if (!$info) {
                    return [0, 0];
          }

          $sw = (int)$info[0];
          $sh = (int)$info[1];
          $ratio = min($maxWidth / max(1, $sw), $maxHeight / max(1, $sh));
          return [
                    max(1, (int)round($sw * $ratio)),
                    max(1, (int)round($sh * $ratio)),
          ];
}

$width = 1500;
$height = 2000;
$im = imagecreatetruecolor($width, $height);
imagesavealpha($im, true);
imagealphablending($im, true);

$bgPath = fixturePosterAssetPath($backgroundImage);
if ($bgPath) {
          $info = getimagesize($bgPath);
          $src = null;
          if ($info) {
                    $src = match ($info[2]) {
                              IMAGETYPE_JPEG => imagecreatefromjpeg($bgPath),
                              IMAGETYPE_PNG => imagecreatefrompng($bgPath),
                              IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($bgPath) : null,
                              IMAGETYPE_GIF => imagecreatefromgif($bgPath),
                              default => null,
                    };
          }
          if ($src) {
                    $sw = imagesx($src);
                    $sh = imagesy($src);
                    imagecopyresampled($im, $src, 0, 0, 0, 0, $width, $height, $sw, $sh);
                    imagedestroy($src);
          }
}

if (!isset($src) || !$src) {
          $base = imagecolorallocate($im, 54, 109, 214);
          imagefilledrectangle($im, 0, 0, $width, $height, $base);
}

$white = imagecolorallocate($im, 255, 255, 255);
$softWhite = imagecolorallocate($im, 245, 246, 250);
$line = imagecolorallocate($im, 245, 245, 245);
$shadow = imagecolorallocatealpha($im, 0, 0, 0, 75);
$resultWin = imagecolorallocate($im, 46, 204, 113);   // green  W
$resultLoss = imagecolorallocate($im, 231, 76, 60);   // red    L
$resultDraw = $white;                                  // white  D

$fontTitle = __DIR__ . '/assets/fonts/Cinzel-Black.ttf';
$fontHeading = __DIR__ . '/assets/fonts/Roboto-Bold.ttf';
$fontBody = __DIR__ . '/assets/fonts/Roboto-Regular.ttf';
$fontMono = __DIR__ . '/assets/fonts/Roboto_Condensed-Bold.ttf';

if (!is_file($fontTitle) || !is_file($fontHeading) || !is_file($fontBody) || !is_file($fontMono)) {
          http_response_code(500);
          exit('Required font files are missing.');
}

$fixtures = getMatchFixtures($pdo, $seasonId);

// Optional "show only these competitions" filter (?competitions[]=...).
// Absent or empty = show everything. Fixtures with no competition set are
// always kept, since there's no box for them.
$competitionsFilter = $_GET['competitions'] ?? null;
if (is_array($competitionsFilter)) {
          $competitionsFilter = array_values(array_filter(
                    array_map(static fn ($c): string => mb_strtolower(trim((string)$c)), $competitionsFilter),
                    static fn (string $c): bool => $c !== ''
          ));
} else {
          $competitionsFilter = [];
}

if ($competitionsFilter !== []) {
          $fixtures = array_values(array_filter($fixtures, static function (array $fixture) use ($competitionsFilter): bool {
                    $competition = mb_strtolower(trim((string)($fixture['competition'] ?? '')));
                    return $competition === '' || in_array($competition, $competitionsFilter, true);
          }));
}

$months = [];
foreach ($fixtures as $fixture) {
          $ts = strtotime((string)$fixture['match_date']);
          if (!$ts) {
                    continue;
          }
          $key = date('Y-m', $ts);
          if (!isset($months[$key])) {
                    $months[$key] = [
                              'label' => strtoupper(date('F', $ts)),
                              'fixtures' => [],
                    ];
          }
          $months[$key]['fixtures'][] = $fixture;
}

$monthBlocks = array_values($months);
$columnBlocks = array_chunk($monthBlocks, max(1, (int)ceil(count($monthBlocks) / 3)));
while (count($columnBlocks) < 3) {
          $columnBlocks[] = [];
}

$columnXs = [120, 560, 1000];
$columnWidth = 360;
$monthStartY = 360;
$monthSpacing = 36;
$rowSpacing = 44;

foreach ($columnBlocks as $columnIndex => $monthsInColumn) {
          $x = $columnXs[$columnIndex] ?? $columnXs[0];
          $y = $monthStartY;

          foreach ($monthsInColumn as $month) {
                    posterDrawShadowedText($im, $fontHeading, 34, $x, $y, $white, $shadow, $month['label']);
                    imageline($im, $x, $y + 18, $x + 170, $y + 18, $white);
                    $y += 54;

                    foreach ($month['fixtures'] as $fixture) {
                              $ts = strtotime((string)$fixture['match_date']);
                              $day = $ts ? date('d', $ts) : '--';
                              $opponent = strtoupper(trim((string)$fixture['opponent']));
                              $isHome = (int)$fixture['is_home'] === 1;

                              $showResult = $view === 'results'
                                        && $fixture['full_time_home_score'] !== null
                                        && $fixture['full_time_away_score'] !== null;

                              $rightSlotX = $x + $columnWidth - 20;
                              $opponentMax = 230;

                              posterDrawShadowedText($im, $fontMono, 22, $x, $y, $softWhite, $shadow, $day);

                              if ($showResult) {
                                        $homeScore = (int)$fixture['full_time_home_score'];
                                        $awayScore = (int)$fixture['full_time_away_score'];
                                        $ours = $isHome ? $homeScore : $awayScore;
                                        $theirs = $isHome ? $awayScore : $homeScore;
                                        $letter = $ours > $theirs ? 'W' : ($ours < $theirs ? 'L' : 'D');
                                        $letterColour = $letter === 'W' ? $resultWin : ($letter === 'L' ? $resultLoss : $resultDraw);
                                        $score = $homeScore . '-' . $awayScore;

                                        $scoreWidth = posterTextWidth($fontMono, 20, $score);
                                        $opponentMax = max(150, (int)round(($rightSlotX - 12 - $scoreWidth) - ($x + 74) - 6));
                                        posterDrawShadowedText($im, $fontMono, 20, (int)round($rightSlotX - 12 - $scoreWidth), $y, $softWhite, $shadow, $score);
                                        posterDrawShadowedText($im, $fontHeading, 22, $rightSlotX, $y, $letterColour, $shadow, $letter);
                              } else {
                                        posterDrawShadowedText($im, $fontHeading, 20, $rightSlotX, $y, $softWhite, $shadow, $isHome ? 'H' : 'A');
                              }

                              $opponentSize = posterFitText($fontBody, $opponent, $opponentMax, 22, 16);
                              $rowWeight = $isHome ? $fontHeading : $fontBody;
                              posterDrawShadowedText($im, $rowWeight, $opponentSize, $x + 74, $y, $white, $shadow, $opponent);
                              $y += $rowSpacing;
                    }

                    $y += $monthSpacing;
          }
}

$footerText = 'All fixtures are subject to change';
$footerWidth = posterTextWidth($fontBody, 20, $footerText);
posterDrawShadowedText($im, $fontBody, 20, (int)round(($width - $footerWidth) / 2), 1910, $white, $shadow, $footerText);

$savedLogoLayout = getMatchPosterLogoLayout($seasonId);
$kitLogoMarginRight = 44;
$kitLogoBottom = 48;
$kitLogoGap = 18;
$barOnePath = __DIR__ . '/assets/images/BarOne.png';
$delGrecosPath = __DIR__ . '/assets/images/del grecos.png';

$barOneX = posterIntParam('bar_one_x', (int)$savedLogoLayout['bar_one_x']);
$barOneY = posterIntParam('bar_one_y', (int)$savedLogoLayout['bar_one_y']);
$barOneW = posterIntParam('bar_one_w', (int)$savedLogoLayout['bar_one_w']);
$barOneH = posterIntParam('bar_one_h', (int)$savedLogoLayout['bar_one_h']);
$delGrecosX = posterIntParam('del_grecos_x', (int)$savedLogoLayout['del_grecos_x']);
$delGrecosY = posterIntParam('del_grecos_y', (int)$savedLogoLayout['del_grecos_y']);
$delGrecosW = posterIntParam('del_grecos_w', (int)$savedLogoLayout['del_grecos_w']);
$delGrecosH = posterIntParam('del_grecos_h', (int)$savedLogoLayout['del_grecos_h']);

posterDrawContainedImage($im, $barOnePath, $barOneX, $barOneY, $barOneW, $barOneH);
posterDrawContainedImage($im, $delGrecosPath, $delGrecosX, $delGrecosY, $delGrecosW, $delGrecosH);

header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
