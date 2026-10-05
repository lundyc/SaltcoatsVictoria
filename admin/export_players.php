<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// export_players.php — CSV / PDF export of the squad list (name, status, sponsors)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';

if (!hub_auth_is_authenticated()) {
  header('Location: login.php');
  exit;
}

$format = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv', 'pdf'], true)) {
  $format = 'csv';
}

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$seasonStart = $season['start_date'] ?? null;
$seasonEnd = $season['end_date'] ?? null;

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$whereParts = [];
$params = [];

if ($filter === 'current') {
  $whereParts[] = "(p.status='current' AND p.active=1)";
} elseif ($filter === 'trialist') {
  $whereParts[] = "(p.status='trialist' AND p.active=1)";
} elseif ($filter === 'left') {
  $whereParts[] = "(p.status='left' OR p.active=0)";
} elseif ($filter === 'injured') {
  $whereParts[] = "p.status='injured'";
} elseif ($filter === 'retired') {
  $whereParts[] = "p.status='retired'";
} elseif ($filter === 'loan') {
  $whereParts[] = "p.status='loan'";
}

if ($search !== '') {
  $whereParts[] = "p.name LIKE :q";
  $params[':q'] = "%$search%";
}

if ($seasonStart && $seasonEnd) {
  $whereParts[] = "(p.status <> 'left' OR (p.left_at IS NOT NULL AND p.left_at BETWEEN :season_start AND :season_end))";
  $params[':season_start'] = $seasonStart;
  $params[':season_end'] = $seasonEnd;
}

$where = $whereParts ? "WHERE " . implode(" AND ", $whereParts) : "";

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
  $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$sql = "
  SELECT
    p.id,
    p.name,
    p.status,
    p.active,
    CASE
      WHEN p.status = 'current' AND p.active = 1 THEN 1
      WHEN p.status = 'trialist' AND p.active = 1 THEN 2
      WHEN p.status = 'injured' THEN 3
      WHEN p.status = 'loan' THEN 4
      WHEN p.status = 'left' OR p.active = 0 THEN 5
      WHEN p.status = 'retired' THEN 6
      ELSE 7
    END AS status_order,
    CASE
      WHEN p.position IS NULL OR TRIM(p.position) = '' THEN 999
      ELSE CAST(SUBSTRING_INDEX(p.position, ' ', 1) AS UNSIGNED)
    END AS position_order
  FROM players p
  $where
  ORDER BY status_order ASC, position_order ASC, p.position ASC, p.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sponsorsByPlayer = [];
if ($players) {
  $ids = implode(',', array_map('intval', array_column($players, 'id')));
  $sponsorStmt = $pdo->prepare("
    SELECT
      s.player_id,
      s.slot,
      sp.name AS sponsor_name,
      s.amount,
      COALESCE(pay.total_paid, 0) AS total_paid
    FROM sponsorships s
    JOIN sponsors sp ON sp.id = s.sponsor_id
    LEFT JOIN (
      SELECT sponsorship_id, COALESCE(SUM(amount), 0) AS total_paid
      FROM sponsorship_payments
      GROUP BY sponsorship_id
    ) pay ON pay.sponsorship_id = s.id
    WHERE s.player_id IN ($ids)
      AND s.season_id = :season_id
      AND s.ended_at IS NULL
    ORDER BY FIELD(s.slot, 'home', 'away', 'third'), sp.name
  ");
  $sponsorStmt->execute([':season_id' => $seasonId]);
  foreach ($sponsorStmt as $row) {
    $outstanding = (float) $row['amount'] - (float) $row['total_paid'];
    $sponsorsByPlayer[(int) $row['player_id']][(string) $row['slot']] = [
      'name' => trim((string) $row['sponsor_name']),
      'paid' => $outstanding <= 0.0001,
    ];
  }
}

/**
 * @param array<string, array{name: string, paid: bool}> $slots
 */
function exportPlayersSponsorName(array $slots, string $slot): string
{
  return $slots[$slot]['name'] ?? '';
}

function exportPlayersStatusLabel(string $status, bool $active): string
{
  switch ($status) {
    case 'current':
      return 'Active';
    case 'trialist':
      return 'Trialist';
    case 'left':
      return 'Left';
    case 'retired':
      return 'Retired';
    case 'loan':
      return 'Loan';
    case 'injured':
      return 'Injured';
    default:
      return $active ? 'Active' : 'Inactive';
  }
}

$hasThirdSlot = false;
foreach ($sponsorsByPlayer as $slots) {
  if (!empty($slots['third']['name'])) {
    $hasThirdSlot = true;
    break;
  }
}

if ($format === 'csv') {
  $filename = 'players_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  $out = fopen('php://output', 'w');
  $headerRow = ['Name', 'Status', 'Home Sponsor', 'Away Sponsor'];
  if ($hasThirdSlot) {
    $headerRow[] = 'Third Sponsor';
  }
  fputcsv($out, $headerRow);

  foreach ($players as $player) {
    $slots = $sponsorsByPlayer[(int) $player['id']] ?? [];
    $row = [
      $player['name'],
      exportPlayersStatusLabel((string) $player['status'], (bool) $player['active']),
      exportPlayersSponsorName($slots, 'home'),
      exportPlayersSponsorName($slots, 'away'),
    ];
    if ($hasThirdSlot) {
      $row[] = exportPlayersSponsorName($slots, 'third');
    }
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

// PDF export
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

$seasonLabel = '';
if ($season && !empty($season['start_date']) && !empty($season['end_date'])) {
  $startYear = date('Y', strtotime((string) $season['start_date']));
  $endYear = date('y', strtotime((string) $season['end_date']));
  $seasonLabel = $startYear . ' / ' . $endYear;
}
$pageTitle = trim($seasonLabel . ' Player List');

/**
 * @param array<string, array{name: string, paid: bool}> $slots
 */
function exportPlayersSponsorCell(array $slots, string $slot): string
{
  $entry = $slots[$slot] ?? null;
  $name = trim((string) ($entry['name'] ?? ''));

  if ($name === '') {
    return '<td class="sponsor-available">Available</td>';
  }

  $class = $entry['paid'] ? 'sponsor-paid' : 'sponsor-unpaid';
  return '<td class="' . $class . '">' . h($name) . '</td>';
}

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
  @page { margin: 30px 40px 70px 40px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#21141a; font-size:12px; margin:0; padding:0; }
  h1 { margin:0 0 10px; color:#4b0818; font-size:20px; }
  .pdf-footer { position:fixed; bottom:-50px; left:0; right:0; color:#6f6470; font-size:9px; }
  .pdf-legend { margin:0 0 12px; font-size:10px; }
  .pdf-legend span { display:inline-block; margin-right:14px; }
  .pdf-legend i { display:inline-block; width:10px; height:10px; margin-right:4px; border:1px solid #c9c2c4; vertical-align:middle; }
  table { width:100%; border-collapse:collapse; }
  th { background:#4b0818; color:#fff; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.03em; padding:6px; }
  td { border:1px solid #eadfdf; padding:6px; vertical-align:middle; }
  tbody tr:nth-child(even) td { background:#faf7f8; }
  .name { font-weight:bold; }
  .sponsor-available { color:#8a7f84; font-style:italic; }
  .sponsor-paid { background:#d7f5df !important; color:#1f6f3a; font-weight:bold; }
  .sponsor-unpaid { background:#fbdada !important; color:#a3271f; font-weight:bold; }
  .sponsor-merged { text-align:center; }
</style></head><body>';
$html .= '<div class="pdf-footer">Generated ' . h(date('d/m/Y H:i')) . '</div>';
$html .= '<h1>' . h($pageTitle) . '</h1>';
$html .= '<div class="pdf-legend">
  <span><i style="background:#d7f5df;"></i>Paid</span>
  <span><i style="background:#fbdada;"></i>Unpaid</span>
  <span><i style="background:#fff;"></i>Available</span>
</div>';
$html .= '<table><thead><tr><th>Name</th><th>Status</th><th>Home Sponsor</th><th>Away Sponsor</th>';
if ($hasThirdSlot) {
  $html .= '<th>Third Sponsor</th>';
}
$html .= '</tr></thead><tbody>';

if ($players) {
  foreach ($players as $player) {
    $slots = $sponsorsByPlayer[(int) $player['id']] ?? [];
    $home = $slots['home'] ?? null;
    $away = $slots['away'] ?? null;
    $homeName = trim((string) ($home['name'] ?? ''));
    $awayName = trim((string) ($away['name'] ?? ''));

    $html .= '<tr>';
    $html .= '<td class="name">' . h((string) $player['name']) . '</td>';
    $html .= '<td>' . h(exportPlayersStatusLabel((string) $player['status'], (bool) $player['active'])) . '</td>';

    if ($homeName !== '' && $homeName === $awayName) {
      // Same sponsor holds both slots — merge into one centered cell.
      $mergedPaid = (bool) $home['paid'] && (bool) $away['paid'];
      $class = ($mergedPaid ? 'sponsor-paid' : 'sponsor-unpaid') . ' sponsor-merged';
      $html .= '<td class="' . $class . '" colspan="2">' . h($homeName) . '</td>';
    } else {
      $html .= exportPlayersSponsorCell($slots, 'home');
      $html .= exportPlayersSponsorCell($slots, 'away');
    }

    if ($hasThirdSlot) {
      $html .= exportPlayersSponsorCell($slots, 'third');
    }
    $html .= '</tr>';
  }
} else {
  $colspan = $hasThirdSlot ? 5 : 4;
  $html .= '<tr><td colspan="' . $colspan . '">No players found.</td></tr>';
}

$html .= '</tbody></table></body></html>';

if (ob_get_length() !== false) {
  ob_clean();
}

$dompdf = new Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('players_' . date('Ymd_His') . '.pdf');
exit;
