<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('finance_view')) {
    http_response_code(403);
    exit('Access denied.');
}
// export_unpaid.php — CSV export of unpaid sponsorships (respects ?slot= & ?q=)
require_once __DIR__ . '/db.php';

$seasonId = getSelectedSeasonId($pdo);

// inputs
$slot = $_GET['slot'] ?? 'all';
$q    = trim($_GET['q'] ?? '');

$params = [];
$where  = ["sp.paid = 0", "sp.season_id = :season_id", "sp.ended_at IS NULL"];
$params[':season_id'] = $seasonId;

if (in_array($slot, ['home', 'away', 'third'], true)) {
          $where[] = "sp.slot = :slot";
          $params[':slot'] = $slot;
}
if ($q !== '') {
          $where[] = "(p.name LIKE :q OR s.name LIKE :q OR COALESCE(sp.notes,'') LIKE :q)";
          $params[':q'] = "%$q%";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
  SELECT 
    p.name AS player,
    s.name AS sponsor,
    UPPER(sp.slot) AS slot,
    sp.amount,
    CASE WHEN sp.paid=1 THEN 'Yes' ELSE 'No' END AS paid,
    COALESCE(sp.notes,'') AS notes,
    DATE(sp.assigned_at) AS assigned_date
  FROM sponsorships sp
  JOIN players  p ON p.id = sp.player_id
  JOIN sponsors s ON s.id = sp.sponsor_id
  $whereSql
  ORDER BY p.name ASC, FIELD(sp.slot,'home','away','third')
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// headers for download
$filename = 'unpaid_sponsorships_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Player', 'Sponsor', 'Kit', 'Amount', 'Paid', 'Notes', 'Assigned']);

foreach ($rows as $r) {
          fputcsv($out, [
                    $r['player'],
                    $r['sponsor'],
                    $r['slot'],
                    number_format((float)$r['amount'], 2, '.', ''), // plain 12.34
                    $r['paid'],
                    $r['notes'],
                    $r['assigned_date'] ?: ''
          ]);
}
fclose($out);
exit;
