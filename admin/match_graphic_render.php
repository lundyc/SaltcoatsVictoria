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
require_once __DIR__ . '/lib/match_sponsorship.php';

if (session_status() === PHP_SESSION_NONE) {
          session_start();
}

$seasonId = (int)($_GET['season_id'] ?? getSelectedSeasonId($pdo));
$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$editBase = isset($_GET['edit_base']) ? (int)$_GET['edit_base'] : 0;

$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          http_response_code(404);
          exit('Fixture not found');
}

$seasonId = (int)$fixture['season_id'];
$season = getSeasonById($pdo, $seasonId);
$layout = getMatchGraphicLayout($pdo, $fixtureId, $seasonId);
$rows = getDisplayableMatchSponsorshipRows($pdo, $fixtureId);
$dayRow = null;
$ballRow = null;
foreach ($rows as $row) {
          if ($row['sponsorship_role'] === 'match_day') {
                    $dayRow = $row;
          }
          if ($row['sponsorship_role'] === 'match_ball') {
                    $ballRow = $row;
          }
}

$width = 768;
$height = 768;
$im = imagecreatetruecolor($width, $height);
imagesavealpha($im, true);

$maroon = imagecolorallocate($im, 88, 16, 30);
$gold = imagecolorallocate($im, 220, 170, 54);
$cream = imagecolorallocate($im, 247, 238, 224);
$panelFill = imagecolorallocate($im, 255, 251, 243);
$panelAccent = imagecolorallocate($im, 240, 225, 192);
$textCol = imagecolorallocate($im, 88, 16, 30);
$mutedText = imagecolorallocate($im, 124, 76, 57);

imagefill($im, 0, 0, $maroon);
imagefilledrectangle($im, 8, 8, $width - 9, $height - 9, $cream);
imagerectangle($im, 16, 16, $width - 17, $height - 17, $panelAccent);

$fontDir = __DIR__ . '/assets/fonts';
$fontBold = file_exists($fontDir . '/Roboto_Condensed-Bold.ttf') ? $fontDir . '/Roboto_Condensed-Bold.ttf' : $fontDir . '/Roboto-Bold.ttf';
$fontMedium = file_exists($fontDir . '/Roboto_Condensed-Regular.ttf') ? $fontDir . '/Roboto_Condensed-Regular.ttf' : $fontDir . '/Roboto-Regular.ttf';
if (!$fontBold || !$fontMedium) {
          http_response_code(500);
          exit('Required font files are missing');
}

function mbbox(string $font, float $size, string $text): array
{
          return imagettfbbox($size, 0, $font, $text);
}

function textWidth(string $font, float $size, string $text): float
{
          $bbox = mbbox($font, $size, $text);
          return (float)($bbox[2] - $bbox[0]);
}

function fitText(string $font, string $text, float $maxWidth, float $startSize, float $minSize): float
{
          $size = $startSize;
          while ($size > $minSize && textWidth($font, $size, $text) > $maxWidth) {
                    $size -= 1;
          }
          return max($size, $minSize);
}

function drawCentered($im, float $size, int $cx, int $baselineY, int $color, string $font, string $text): void
{
          $bbox = imagettfbbox($size, 0, $font, $text);
          $w = $bbox[2] - $bbox[0];
          $x = (int)round($cx - ($w / 2));
          imagettftext($im, $size, 0, $x, $baselineY, $color, $font, $text);
}

function drawCard($im, int $x, int $y, int $w, int $h, int $fill, int $border): void
{
          imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $fill);
          imagerectangle($im, $x, $y, $x + $w, $y + $h, $border);
}

function drawLogoCard($im, ?array $row, int $x, int $y, int $w, int $h, string $fontBold, string $fontMedium, int $textCol, int $mutedText, int $panelFill, int $panelAccent): void
{
          drawCard($im, $x, $y, $w, $h, $panelFill, $panelAccent);
          if (!$row) {
                    drawCentered($im, 24, $x + (int)($w / 2), $y + (int)($h / 2), $mutedText, $fontBold, 'AVAILABLE');
                    return;
          }

          $logoPath = !empty($row['sponsor_logo']) ? __DIR__ . '/uploads/sponsors/' . $row['sponsor_logo'] : null;
          if ($logoPath && file_exists($logoPath)) {
                    $info = getimagesize($logoPath);
                    if ($info) {
                              $src = match ($info[2]) {
                                        IMAGETYPE_PNG => imagecreatefrompng($logoPath),
                                        IMAGETYPE_JPEG => imagecreatefromjpeg($logoPath),
                                        IMAGETYPE_GIF => imagecreatefromgif($logoPath),
                                        default => null,
                              };
                              if ($src) {
                                        $sw = imagesx($src);
                                        $sh = imagesy($src);
                                        $targetW = (int)min(110, $w - 24);
                                        $targetH = (int)min(90, $h - 24);
                                        $ratio = min($targetW / max(1, $sw), $targetH / max(1, $sh));
                                        $dw = (int)round($sw * $ratio);
                                        $dh = (int)round($sh * $ratio);
                                        $dx = $x + 16;
                                        $dy = $y + (int)(($h - $dh) / 2);
                                        imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
                                        imagedestroy($src);
                              }
                    }
          }

          $label = strtoupper((string)$row['sponsorship_role']);
          $name = strtoupper((string)$row['sponsor_name']);
          $isComplimentary = (int)($row['is_complimentary'] ?? 0) === 1;
          $amount = '£' . number_format((float)$row['amount'], 2);
          $nameSize = fitText($fontBold, $name, max(160, $w - 160), 24, 16);
          imagettftext($im, 18, 0, $x + 140, $y + 42, $mutedText, $fontBold, $label);
          imagettftext($im, $nameSize, 0, $x + 140, $y + 78, $textCol, $fontBold, $name);
          $financeLabel = $isComplimentary
                    ? 'COMPLIMENTARY SPONSOR'
                    : $amount . ' / PAID £' . number_format((float)$row['paid_total'], 2);
          imagettftext($im, 18, 0, $x + 140, $y + 108, $mutedText, $fontMedium, $financeLabel);
}

imagefilledrectangle($im, 22, 22, $width - 23, $height - 23, $maroon);
imagefilledrectangle($im, 30, 30, $width - 31, $height - 31, $cream);

$opponentText = strtoupper((string)$fixture['opponent']);
$opponentSize = fitText($fontBold, $opponentText, 660, 26, 18);
imagettftext($im, $opponentSize, 0, 50, 82, $textCol, $fontBold, $opponentText);
$dateLine = date('l j F Y', strtotime((string)$fixture['match_date'])) . (!empty($fixture['kickoff_time']) ? ' - ' . substr((string)$fixture['kickoff_time'], 0, 5) : '');
imagettftext($im, 18, 0, 50, 112, $mutedText, $fontMedium, $dateLine);
imagettftext($im, 18, 0, 50, 142, $mutedText, $fontMedium, ($fixture['is_home'] ? 'HOME FIXTURE' : 'AWAY FIXTURE') . '  |  ' . strtoupper((string)$fixture['status']));

drawCard($im, 46, 170, 676, 88, $panelFill, $panelAccent);
drawCentered($im, 32, 384, 222, $textCol, $fontBold, 'MATCH SPONSORSHIP');
if ($editBase) {
          drawCard($im, (int)$layout['match_day_x'], (int)$layout['match_day_y'], (int)$layout['match_day_width'], (int)$layout['match_day_height'], $panelFill, $panelAccent);
          drawCard($im, (int)$layout['match_ball_x'], (int)$layout['match_ball_y'], (int)$layout['match_ball_width'], (int)$layout['match_ball_height'], $panelFill, $panelAccent);
          drawCentered($im, 20, (int)$layout['match_day_x'] + (int)($layout['match_day_width'] / 2), (int)$layout['match_day_y'] + 42, $mutedText, $fontBold, 'MATCH DAY SPONSOR');
          drawCentered($im, 20, (int)$layout['match_ball_x'] + (int)($layout['match_ball_width'] / 2), (int)$layout['match_ball_y'] + 42, $mutedText, $fontBold, 'MATCH BALL SPONSOR');
} else {
          drawLogoCard($im, $dayRow, (int)$layout['match_day_x'], (int)$layout['match_day_y'], (int)$layout['match_day_width'], (int)$layout['match_day_height'], $fontBold, $fontMedium, $textCol, $mutedText, $panelFill, $panelAccent);
          drawLogoCard($im, $ballRow, (int)$layout['match_ball_x'], (int)$layout['match_ball_y'], (int)$layout['match_ball_width'], (int)$layout['match_ball_height'], $fontBold, $fontMedium, $textCol, $mutedText, $panelFill, $panelAccent);
}

imagettftext($im, 16, 0, 50, 720, $mutedText, $fontMedium, 'Season ' . ($season['name'] ?? ('ID ' . (string)$seasonId)));

$downloadSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$fixture['opponent'])) ?? 'match';
$downloadName = 'match-graphic-' . trim($downloadSlug, '-') . '-' . (string)$fixture['match_date'] . '.png';

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
imagepng($im);
imagedestroy($im);
