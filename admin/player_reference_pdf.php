<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/players_lib.php';

if (!hub_auth_is_authenticated()) {
  header('Location: login.php');
  exit;
}

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
  $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}
players_ensure_date_of_birth_column($pdo);

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$seasonLabel = '';
if ($season && !empty($season['start_date']) && !empty($season['end_date'])) {
  $startYear = date('Y', strtotime((string) $season['start_date']));
  $endYear = date('y', strtotime((string) $season['end_date']));
  $seasonLabel = $startYear . ' / ' . $endYear;
}
$pageTitle = trim($seasonLabel . ' Squad List');

$players = $pdo->query("
  SELECT p.id, p.name, p.position, p.avatar, p.date_of_birth
  FROM players p
  WHERE p.active = 1 AND p.status IN ('current', 'trialist', 'injured', 'loan')
  ORDER BY
    CASE WHEN p.position IS NULL OR TRIM(p.position) = '' THEN 999 ELSE CAST(SUBSTRING_INDEX(p.position, ' ', 1) AS UNSIGNED) END ASC,
    p.position ASC,
    p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sponsorsByPlayer = [];
if ($players) {
  $ids = implode(',', array_map('intval', array_column($players, 'id')));
  $sponsorStmt = $pdo->prepare("
    SELECT s.player_id, s.slot, sp.name AS sponsor_name
    FROM sponsorships s
    JOIN sponsors sp ON sp.id = s.sponsor_id
    WHERE s.player_id IN ($ids)
      AND s.season_id = :season_id
      AND s.ended_at IS NULL
    ORDER BY FIELD(s.slot, 'home', 'away', 'third'), sp.name
  ");
  $sponsorStmt->execute([':season_id' => $seasonId]);
  foreach ($sponsorStmt as $row) {
    $sponsorsByPlayer[(int) $row['player_id']][(string) $row['slot']] = trim((string) $row['sponsor_name']);
  }
}

function playerReferencePdfImageDataUri(?string $avatar): string
{
  $avatar = trim((string) $avatar);
  if ($avatar === '') {
    return '';
  }

  $path = __DIR__ . '/uploads/players/' . basename($avatar);
  if (!is_file($path) || !is_readable($path)) {
    return '';
  }

  $mime = mime_content_type($path) ?: 'image/jpeg';
  $data = file_get_contents($path);
  if ($data === false) {
    return '';
  }

  return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function playerReferencePdfDobText(?string $dateOfBirth): string
{
  $dateOfBirth = trim((string) $dateOfBirth);
  if ($dateOfBirth === '') {
    return '';
  }

  $timestamp = strtotime($dateOfBirth);
  if ($timestamp === false || (int) date('Y', $timestamp) === 2026) {
    return '';
  }

  return date('d M Y', $timestamp);
}

function playerReferencePdfSponsorText(array $slots): string
{
  $labels = ['home' => 'Home', 'away' => 'Away'];
  if (!empty($slots['third'])) {
    $labels['third'] = 'Third';
  }

  $lines = [];
  foreach ($labels as $slot => $label) {
    $sponsorName = $slots[$slot] ?? '';
    $lines[] = '<b>' . h($label) . ': </b>' . ($sponsorName !== '' ? h($sponsorName) : 'Available');
  }

  return implode('<br>', $lines);
}

if (!class_exists(Dompdf\Dompdf::class)) {
  $autoloaders = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/project_1/vendor/autoload.php'];
  foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
      require_once $autoloader;
      break;
    }
  }
}

if (!class_exists(Dompdf\Dompdf::class)) {
  http_response_code(500);
  exit('PDF export is unavailable.');
}

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
  @page { margin: 30px 40px 70px 40px; }
  body { font-family: DejaVu Sans, Arial, sans-serif;
  color:#21141a; font-size:14px; margin: 0; padding: 0; }
  h1 { margin:0 0 4px; color:#4b0818; font-size:20px; }
  .pdf-footer { position:fixed; bottom:-50px; left:0; right:0; color:#6f6470; font-size:9px; }
  table { width:100%; border-collapse:collapse; table-layout:fixed; }
  th { background:#4b0818; color:#fff; text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.03em; }
  th, td { border:1px solid #eadfdf; padding:6px; vertical-align:middle; }
  td { height: 95px; }
  tbody tr:nth-child(even) td { background:#faf7f8; }
  .photo { width:11%; text-align:center; }
  .photo-box { display:inline-block; width:100px; height:100px; background-color:#eadfdf; background-repeat:no-repeat; background-position:center center; background-size:cover; }
  .name { width:20%; font-weight:bold; }
  .position { width:10%; text-align: center; }
  .dob { width:20%; text-align: center; }
  .sponsors { width:auto; line-height:1.35; }
</style></head><body>';
$html .= '<div class="pdf-footer">Generated ' . h(date('d/m/Y H:i')) . '</div>';
$html .= '<h1>' . h($pageTitle) . '</h1>';
$html .= '<table><thead><tr><th class="photo">Photo</th><th class="name">Name</th><th class="position">Position</th><th class="dob">DOB</th><th class="sponsors">Sponsors</th></tr></thead><tbody>';

if ($players) {
  foreach ($players as $player) {
    $imageData = playerReferencePdfImageDataUri((string) ($player['avatar'] ?? ''));
    $position = players_position_label((string) ($player['position'] ?? ''));
    $dob = playerReferencePdfDobText((string) ($player['date_of_birth'] ?? ''));
    $sponsorText = playerReferencePdfSponsorText($sponsorsByPlayer[(int) $player['id']] ?? []);

    $html .= '<tr>';
    $html .= '<td class="photo">' . ($imageData !== '' ? '<div class="photo-box" style="background-image:url(\'' . h($imageData) . '\');"></div>' : '') . '</td>';
    $html .= '<td class="name">' . h((string) $player['name']) . '</td>';
    $html .= '<td class="position">' . h($position !== '' ? $position : 'Not set') . '</td>';
    $html .= '<td class="dob">' . h($dob) . '</td>';
    $html .= '<td class="sponsors">' . $sponsorText . '</td>';
    $html .= '</tr>';
  }
} else {
  $html .= '<tr><td colspan="5">No squad players found.</td></tr>';
}

$html .= '</tbody></table></body></html>';

if (ob_get_length() !== false) {
  ob_clean();
}

$dompdf = new Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('squad-players.pdf');
exit;
