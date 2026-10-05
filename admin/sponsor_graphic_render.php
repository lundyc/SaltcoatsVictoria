<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// sponsor_graphic_render.php — GD renderer for the all-players sponsor poster
// ==========================================
ini_set('memory_limit', '512M');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';

function clamp_value(float $value, float $min, float $max): float
{
  return max($min, min($max, $value));
}

function safe_font(string $dir, string $file, array $fallbacks = []): ?string
{
  $candidates = array_merge([$dir . '/' . $file], $fallbacks);
  foreach ($candidates as $candidate) {
    if ($candidate && is_file($candidate) && is_readable($candidate)) {
      return $candidate;
    }
  }
  return null;
}

function load_image_resource(string $path)
{
  if (!is_file($path)) {
    return null;
  }
  $raw = @file_get_contents($path);
  if ($raw === false) {
    return null;
  }
  return @imagecreatefromstring($raw) ?: null;
}

function text_width(string $font, float $size, string $text): float
{
  $bbox = imagettfbbox($size, 0, $font, $text);
  return (float)($bbox[2] - $bbox[0]);
}

function fit_text_size(string $font, string $text, float $maxWidth, float $startSize, float $minSize): float
{
  $size = $startSize;
  while ($size > $minSize && text_width($font, $size, $text) > $maxWidth) {
    $size -= 1;
  }
  return max($size, $minSize);
}

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : getSelectedSeasonId($pdo);
$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : getCurrentSeason($pdo);
if (!$season) {
  http_response_code(404);
  exit('Season not found');
}
$transparent = isset($_GET['transparent']) && (int)$_GET['transparent'] === 1;

$viewMode = $_GET['view'] ?? 'list';
if (!in_array($viewMode, ['list', 'grid'], true)) {
  $viewMode = 'list';
}

$download = isset($_GET['download']) && (int)$_GET['download'] === 1;

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
  $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$playersStmt = $pdo->prepare("
  SELECT
    id,
    name,
    status,
    active,
    CASE
      WHEN position IS NULL OR TRIM(position) = '' THEN 999
      ELSE CAST(SUBSTRING_INDEX(position, ' ', 1) AS UNSIGNED)
    END AS position_order
  FROM players
  WHERE active = 1
    AND status IN ('current', 'trialist', 'injured', 'loan')
  ORDER BY position_order ASC, position ASC, name ASC
");
$playersStmt->execute();
$players = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

$sponsorshipStmt = $pdo->prepare("
  SELECT
    s.player_id,
    s.slot,
    sp.name AS sponsor_name
  FROM sponsorships s
  JOIN sponsors sp ON sp.id = s.sponsor_id
  WHERE s.season_id = :season_id
    AND s.ended_at IS NULL
  ORDER BY s.player_id ASC, FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), sp.name ASC
");
$sponsorshipStmt->execute([':season_id' => $seasonId]);
$sponsorships = $sponsorshipStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentMap = [];
foreach ($sponsorships as $row) {
  $assignmentMap[(int)$row['player_id']][strtolower((string)$row['slot'])] = (string)$row['sponsor_name'];
}

if ($transparent) {
  // Fixed canvas — final height is computed later once we know the row count.
  $width = 1054;
  $im = null;
} else {
  $bgPath = __DIR__ . '/assets/images/player_sponsor_background.png';
  $im = load_image_resource($bgPath);
  if (!$im) {
    $width = 1054;
    $height = 1492;
    $im = imagecreatetruecolor($width, $height);
    $bg = imagecolorallocate($im, 34, 36, 37);
    imagefill($im, 0, 0, $bg);
  } else {
    $width = imagesx($im);
    $height = imagesy($im);
  }
  imagealphablending($im, true);
  imagesavealpha($im, true);
}

// Layout constants below are tuned for a 1054px-wide canvas; scale them to
// whatever size the background image (or the transparent export) actually is.
$scale = $width / 1054.0;

$fontDir = __DIR__ . '/assets/fonts';
$fontBody = safe_font($fontDir, 'Roboto_Condensed-Bold.ttf', [
  $fontDir . '/Roboto-Bold.ttf',
  $fontDir . '/Montserrat-SemiBold.ttf',
]);
$fontSmall = safe_font($fontDir, 'Roboto_Condensed-Regular.ttf', [
  $fontDir . '/Roboto-Regular.ttf',
  $fontDir . '/Montserrat-Regular.ttf',
]);

if (!$fontBody || !$fontSmall) {
  http_response_code(500);
  exit('Required font files are missing');
}

function draw_line($im, int $x, int $y, int $w, int $color): void
{
  imageline($im, $x, $y, $x + $w, $y, $color);
}

function draw_centered_text($im, string $font, float $size, int $x, int $baselineY, int $color, string $text, int $maxWidth, float $scale = 1.0): void
{
  $size = fit_text_size($font, $text, $maxWidth, $size, max(8.0, 10 * $scale));
  $bbox = imagettfbbox($size, 0, $font, $text);
  $textWidth = (int)($bbox[2] - $bbox[0]);
  $drawX = $x + (int)floor(($maxWidth - $textWidth) / 2);
  imagettftext($im, $size, 0, $drawX, $baselineY, $color, $font, $text);
}

function draw_sponsor_item(
  $im,
  int $x,
  int $baselineY,
  int $maxWidth,
  string $name,
  string $fontSmall,
  int $cream,
  int $muted,
  int $textMaxWidth,
  float $textSize,
  float $scale = 1.0
): void {
  $label = $name !== '' ? $name : 'AVAILABLE';
  $lineColor = $label === 'AVAILABLE' ? $muted : $cream;
  draw_centered_text($im, $fontSmall, $textSize, $x, $baselineY, $lineColor, $label, $textMaxWidth, $scale);
}

function sponsor_names_for_player(array $assignmentMap, int $playerId): array
{
  $home = $assignmentMap[$playerId]['home'] ?? '';
  $away = $assignmentMap[$playerId]['away'] ?? '';
  $homeName = $home !== '' ? $home : 'AVAILABLE';
  $awayName = $away !== '' ? $away : 'AVAILABLE';

  if ($homeName === $awayName && $homeName !== 'AVAILABLE') {
    return [$homeName];
  }

  return [$homeName, $awayName];
}

function draw_table_row(
  $im,
  int $x,
  int $y,
  int $number,
  int $numberWidth,
  int $nameWidth,
  int $sponsorWidth,
  string $playerName,
  array $sponsorNames,
  string $fontBody,
  string $fontSmall,
  int $numberColor,
  int $cream,
  int $muted,
  int $tableWidth,
  int $lineColor,
  float $scale = 1.0
): void {
  $numberText = $number . '.';
  $playerName = mb_strtoupper(trim($playerName));
  $numberSize = 22.0 * $scale;
  $playerSize = fit_text_size($fontBody, $playerName, $nameWidth - (12 * $scale), 24 * $scale, 14 * $scale);

  imagettftext($im, $numberSize, 0, $x, $y, $numberColor, $fontBody, $numberText);
  imagettftext($im, $playerSize, 0, $x + $numberWidth, $y, $cream, $fontBody, $playerName);

  $sponsorOneX = $x + $numberWidth + $nameWidth;
  $sponsorTwoX = $sponsorOneX + $sponsorWidth;

  if (count($sponsorNames) === 1) {
    draw_sponsor_item($im, $sponsorOneX, $y, $sponsorWidth * 2, $sponsorNames[0], $fontSmall, $cream, $muted, (int)($sponsorWidth * 2 - (12 * $scale)), 19 * $scale, $scale);
  } else {
    draw_sponsor_item($im, $sponsorOneX, $y, $sponsorWidth, $sponsorNames[0], $fontSmall, $cream, $muted, (int)($sponsorWidth - (12 * $scale)), 18 * $scale, $scale);
    draw_sponsor_item($im, $sponsorTwoX, $y, $sponsorWidth, $sponsorNames[1], $fontSmall, $cream, $muted, (int)($sponsorWidth - (12 * $scale)), 18 * $scale, $scale);
  }

  $underlineY = $y + (int)round(10 * $scale);
  draw_line($im, $x + $numberWidth + (int)round(2 * $scale), $underlineY, $tableWidth - $numberWidth - (int)round(4 * $scale), $lineColor);
}

function draw_grid_card(
  $im,
  int $x,
  int $y,
  int $w,
  int $h,
  string $playerName,
  array $sponsorNames,
  string $fontBody,
  string $fontSmall,
  int $gold,
  int $cream,
  int $muted,
  float $scale = 1.0
): void {
  $pad = (int)round(10 * $scale);
  $fill = imagecolorallocatealpha($im, 12, 18, 26, 52);
  $border = imagecolorallocatealpha($im, 255, 255, 255, 84);
  imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $fill);
  imagerectangle($im, $x, $y, $x + $w, $y + $h, $border);

  draw_centered_text($im, $fontBody, 21 * $scale, $x + $pad, $y + (int)round(42 * $scale), $gold, mb_strtoupper(trim($playerName)), $w - (2 * $pad), $scale);

  $sponsorNames = array_slice($sponsorNames, 0, 2);
  if (count($sponsorNames) === 1) {
    draw_sponsor_item($im, $x + $pad, $y + (int)round(132 * $scale), $w - (2 * $pad), $sponsorNames[0], $fontSmall, $cream, $muted, min($w - (2 * $pad), (int)round(340 * $scale)), 20 * $scale, $scale);
    return;
  }

  $homeName = $sponsorNames[0] ?? 'AVAILABLE';
  $awayName = $sponsorNames[1] ?? 'AVAILABLE';
  draw_sponsor_item($im, $x + $pad, $y + (int)round(96 * $scale), $w - (2 * $pad), $homeName, $fontSmall, $cream, $muted, min($w - (2 * $pad), (int)round(300 * $scale)), 16 * $scale, $scale);
  draw_sponsor_item($im, $x + $pad, $y + (int)round(146 * $scale), $w - (2 * $pad), $awayName, $fontSmall, $cream, $muted, min($w - (2 * $pad), (int)round(300 * $scale)), 16 * $scale, $scale);
}

$playerCount = count($players);
$contentStartY = $transparent ? 40 : (int)round(420 * $scale);
$bottomPadding = $transparent ? 40 : (int)round(118 * $scale);

if ($viewMode === 'grid') {
  $gridX = (int)round(64 * $scale);
  $gridGapX = (int)round(18 * $scale);
  $gridGapY = (int)round(18 * $scale);
  $gridCols = 4;
  $cardWidth = (int)floor(($width - ($gridX * 2) - ($gridGapX * ($gridCols - 1))) / $gridCols);
  $cardHeight = (int)round(190 * $scale);
  $rowCount = (int)ceil(max(1, $playerCount) / $gridCols);
  $requiredHeight = $contentStartY + max(0, $rowCount * $cardHeight) + max(0, ($rowCount - 1) * $gridGapY) + $bottomPadding;
} else {
  $numberWidth = (int)round(56 * $scale);
  $nameWidth = (int)round(330 * $scale);
  $rowHeight = (int)round(42 * $scale);
  $requiredHeight = $contentStartY + max(0, $playerCount - 1) * $rowHeight + $bottomPadding;
}

if ($transparent) {
  // Build a fresh true-color canvas with a fully transparent background.
  $im = imagecreatetruecolor($width, $requiredHeight);
  imagealphablending($im, false);
  $transparentColor = imagecolorallocatealpha($im, 0, 0, 0, 127);
  imagefill($im, 0, 0, $transparentColor);
  imagesavealpha($im, true);
  imagealphablending($im, true);
  $height = $requiredHeight;
} elseif ($requiredHeight > $height) {
  $expanded = imagecreatetruecolor($width, $requiredHeight);
  imagealphablending($expanded, true);
  imagesavealpha($expanded, true);
  imagecopy($expanded, $im, 0, 0, 0, 0, $width, $height);
  $extraHeight = $requiredHeight - $height;
  $sourceHeight = min(180, $height);
  $sourceY = max(0, $height - $sourceHeight);
  imagecopyresampled(
    $expanded,
    $im,
    0,
    $height,
    0,
    $sourceY,
    $width,
    $extraHeight,
    $width,
    $sourceHeight
  );
  imagedestroy($im);
  $im = $expanded;
  $height = $requiredHeight;
}

if ($transparent) {
  $gold = imagecolorallocate($im, 255, 243, 227);
  $numberGold = imagecolorallocate($im, 255, 243, 227);
  $cream = imagecolorallocate($im, 255, 243, 227);
  $blue = imagecolorallocate($im, 72, 130, 201);
  $muted = imagecolorallocate($im, 226, 183, 46);
  $lineColor = imagecolorallocatealpha($im, 0, 0, 0, 100);
} else {
  $gold = imagecolorallocate($im, 241, 193, 55);
  $numberGold = imagecolorallocate($im, 231, 176, 28);
  $cream = imagecolorallocate($im, 245, 240, 230);
  $blue = imagecolorallocate($im, 72, 130, 201);
  $muted = imagecolorallocatealpha($im, 255, 255, 255, 78);
  $lineColor = imagecolorallocatealpha($im, 255, 255, 255, 82);
}

if ($viewMode === 'grid') {
  for ($i = 0; $i < $playerCount; $i++) {
    $player = $players[$i];
    $col = $i % $gridCols;
    $row = (int)floor($i / $gridCols);
    $x = $gridX + ($col * ($cardWidth + $gridGapX));
    $y = $contentStartY + ($row * ($cardHeight + $gridGapY));
    $sponsorNames = sponsor_names_for_player($assignmentMap, (int)$player['id']);

    draw_grid_card(
      $im,
      $x,
      $y,
      $cardWidth,
      $cardHeight,
      (string)$player['name'],
      $sponsorNames,
      $fontBody,
      $fontSmall,
      $numberGold,
      $cream,
      $muted,
      $scale
    );
  }
} else {
  $tableX = (int)round(64 * $scale);
  $tableWidth = $width - (2 * $tableX);
  $tableBottom = $height - $bottomPadding;
  $sponsorWidth = (int)floor(($tableWidth - $numberWidth - $nameWidth) / 2);

  for ($i = 0; $i < $playerCount; $i++) {
    $player = $players[$i];
    $y = $contentStartY + ($i * $rowHeight);
    if ($y > $tableBottom) {
      break;
    }

    $sponsorNames = sponsor_names_for_player($assignmentMap, (int)$player['id']);

    draw_table_row(
      $im,
      $tableX,
      $y,
      $i + 1,
      $numberWidth,
      $nameWidth,
      $sponsorWidth,
      (string)$player['name'],
      $sponsorNames,
      $fontBody,
      $fontSmall,
      $numberGold,
      $cream,
      $muted,
      $tableWidth,
      $lineColor,
      $scale
    );
  }
}

if ($playerCount === 0) {
  $noPlayers = $transparent ? $cream : imagecolorallocate($im, 245, 240, 230);
  imagettftext($im, 28 * $scale, 0, (int)round(90 * $scale), $contentStartY + (int)round(40 * $scale), $noPlayers, $fontBody, 'NO PLAYERS FOUND');
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$slug = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($season['name'] ?? 'sponsor_graphic'));
$filename = $slug . ($transparent ? '_table_transparent' : '') . '.png';
if ($download) {
  header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
  header('Content-Disposition: inline; filename="' . $filename . '"');
}
imagepng($im);
imagedestroy($im);
exit;
