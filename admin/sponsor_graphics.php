<?php
// ==========================================
// sponsor_graphics.php — All-players sponsor graphic (poster)
// ==========================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : getSelectedSeasonId($pdo);
$seasons = getSeasons($pdo);
$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;

$pageHero = [
  'class' => 'player-graphics-hero',
  'eyebrow' => 'Creative studio',
  'title' => 'Sponsor Graphic',
  'subtitle' => 'Generate a poster listing every player with their sponsor.',
  'actions' => [],
];
require_once __DIR__ . '/header.php';

$viewMode = $_GET['view'] ?? 'list';
if (!in_array($viewMode, ['list', 'grid'], true)) {
  $viewMode = 'list';
}

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

$totalPlayers = count($players);
$totalSlots = $totalPlayers * 2;
$sponsoredSlots = 0;
foreach ($players as $player) {
  $id = (int)$player['id'];
  foreach (['home', 'away'] as $slot) {
    if (!empty($assignmentMap[$id][$slot])) {
      $sponsoredSlots++;
    }
  }
}
$availableSlots = max(0, $totalSlots - $sponsoredSlots);

$seasonName = $season['name'] ?? 'Current Season';
$renderUrl = 'sponsor_graphic_render.php?season_id=' . urlencode((string)$seasonId) . '&view=' . urlencode($viewMode);
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Sponsor graphic</span></nav>

<div class="container-fluid px-0 py-3">
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card dashboard-card h-100">
        <div class="card-body">
          <h6>Total Players</h6>
          <h3><?= (int)$totalPlayers ?></h3>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card dashboard-card h-100">
        <div class="card-body">
          <h6>Sponsored Slots</h6>
          <h3><?= (int)$sponsoredSlots ?></h3>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card dashboard-card h-100">
        <div class="card-body">
          <h6>Available Slots</h6>
          <h3><?= (int)$availableSlots ?></h3>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card dashboard-card h-100">
        <div class="card-body">
          <h6>Season</h6>
          <h3 class="fs-5 mb-0"><?= h($seasonName) ?></h3>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="view" id="graphicViewMode" value="<?= h($viewMode) ?>">
        <div class="col-12 col-lg-5">
          <label for="seasonSelect" class="form-label mb-1">Season</label>
          <select id="seasonSelect" name="season_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($seasons as $seasonOption): ?>
              <option value="<?= (int)$seasonOption['id'] ?>" <?= (int)$seasonOption['id'] === (int)$seasonId ? 'selected' : '' ?>>
                <?= h($seasonOption['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-7">
          <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-lg-end gap-2">
            <div class="btn-group" role="group" aria-label="Graphic view">
              <button
                type="button"
                class="btn btn-sm <?= $viewMode === 'list' ? 'btn-brand' : 'btn-outline-secondary' ?>"
                onclick="document.getElementById('graphicViewMode').value='list'; this.form.submit();"
                title="List view">
                <i class="fa-solid fa-list me-1"></i>List
              </button>
              <button
                type="button"
                class="btn btn-sm <?= $viewMode === 'grid' ? 'btn-brand' : 'btn-outline-secondary' ?>"
                onclick="document.getElementById('graphicViewMode').value='grid'; this.form.submit();"
                title="Grid view">
                <i class="fa-solid fa-table-cells-large me-1"></i>Grid
              </button>
            </div>
            <a href="<?= h($renderUrl . '&download=1') ?>" class="btn btn-brand">
              <i class="fa-solid fa-download me-1"></i>Download PNG
            </a>
            <a href="<?= h('sponsor_graphic_render.php?season_id=' . urlencode((string)$seasonId) . '&view=list&transparent=1&download=1') ?>"
               class="btn btn-outline-secondary"
               title="Plain table, cream text, transparent background — for pasting into Canva">
              <i class="fa-solid fa-layer-group me-1"></i>Download transparent table (Canva)
            </a>
            <a href="<?= h('sponsor_graphic_render.php?season_id=' . urlencode((string)$seasonId) . '&view=grid&transparent=1&download=1') ?>"
               class="btn btn-outline-secondary"
               title="Card grid, cream text, transparent background — for pasting into Canva">
              <i class="fa-solid fa-table-cells-large me-1"></i>Download transparent grid (Canva)
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm border-0">
    <div class="card-body p-2 p-lg-3">
      <img
        src="<?= h($renderUrl . '&t=' . time()) ?>"
        alt="Sponsor graphic preview"
        class="img-fluid rounded-3 border"
        style="width:100%; height:auto;">
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
