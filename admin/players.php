<?php
// players.php — Manage Players
$pageHero = [
  'class' => 'players-page-hero',
  'eyebrow' => 'Club',
  'title' => 'Players',
  'subtitle' => 'Manage squad records, player statuses, and sponsorship links.',
  'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/discipline_register.php';
ensureSponsorshipCatalogSchema($pdo);
players_ensure_date_of_birth_column($pdo);

$disciplineStatusByPlayer = discipline_status_by_player($pdo);

function renderPlayerDisciplineBadge(array $disciplineStatusByPlayer, int $playerId): string
{
    $entry = $disciplineStatusByPlayer[$playerId] ?? null;
    if ($entry === null) {
        return '';
    }
    $tone = $entry['status'] === 'red' ? 'danger' : 'warning';
    $label = $entry['status'] === 'red' ? 'Suspended' : 'Discipline: verify';
    return '<span class="badge text-bg-' . $tone . ' players-discipline-badge" title="' . h((string) $entry['reason']) . '">'
        . '<i class="fa-solid fa-square-exclamation me-1" aria-hidden="true"></i>' . h($label) . '</span>';
}

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
  $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS player_photo_reviews (
  player_id INT UNSIGNED NOT NULL,
  status ENUM('accepted','rejected') NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_photo_reviews_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS player_website_photos (
  player_id INT UNSIGNED NOT NULL,
  uploaded_to_website TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_website_photos_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function renderDoneIcon(bool $done): string
{
  return $done
    ? '<i class="fa-solid fa-check text-success" title="Done"></i>'
    : '<i class="fa-solid fa-xmark text-danger" title="Not done"></i>';
}

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$seasonStart = $season['start_date'] ?? null;
$seasonEnd = $season['end_date'] ?? null;

$flashToast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);

/**
 * Renders the Home/Away(/Third) mini-grid for one player's sponsors cell.
 *
 * @param array<string, array<string, mixed>> $slotsForPlayer keyed by slot ('home'/'away'/'third')
 */
function renderPlayerSponsorSlots(array $slotsForPlayer): string
{
  $slotLabels = ['home' => 'Home', 'away' => 'Away', 'third' => 'Third'];
  if (empty($slotsForPlayer['third'])) {
    unset($slotLabels['third']);
  }

  $cells = '';
  foreach ($slotLabels as $slotKey => $slotLabel) {
    $cells .= renderPlayerSponsorSlotCell($slotLabel, $slotsForPlayer[$slotKey] ?? null);
  }

  return "<div class='player-slot-grid player-slot-grid--" . count($slotLabels) . "'>{$cells}</div>";
}

/**
 * @param array<string, mixed>|null $row a single sponsorships row (sponsor_name, amount,
 *   total_paid, agreement_id), or null when nothing is assigned to this slot
 */
function renderPlayerSponsorSlotCell(string $slotLabel, ?array $row): string
{
  if (!$row) {
    return "<div class='player-slot'>
              <div class='player-slot__label'>" . h($slotLabel) . "</div>
              <div class='player-slot__value player-slot__value--empty'>—</div>
            </div>";
  }

  $outstanding = (float)$row['amount'] - (float)$row['total_paid'];
  $sponsorName = h((string)$row['sponsor_name']);
  $agreementId = (int)($row['agreement_id'] ?? 0);

  if ($outstanding > 0.0001 && $agreementId > 0) {
    $value = "<a href='/admin/sponsorship_agreement.php?id={$agreementId}#stripePaymentCard'
                 class='player-slot__value player-slot__value--unpaid'
                 data-bs-toggle='tooltip'
                 title='Unpaid — click to send a Stripe payment link'>{$sponsorName}</a>";
  } elseif ($outstanding > 0.0001) {
    // Outstanding but no agreement synced yet (shouldn't normally happen) — nothing to link to.
    $value = "<span class='player-slot__value player-slot__value--unpaid' data-bs-toggle='tooltip' title='Unpaid'>{$sponsorName}</span>";
  } else {
    $value = "<span class='player-slot__value player-slot__value--paid'>{$sponsorName} <i class=\"fa-solid fa-check\" aria-hidden=\"true\"></i></span>";
  }

  return "<div class='player-slot'>
            <div class='player-slot__label'>" . h($slotLabel) . "</div>
            <div>{$value}</div>
          </div>";
}

// --- Filters ---
$filter = $_GET['filter'] ?? 'all';
$sponsorFilter = $_GET['sponsor'] ?? '';
$search = trim($_GET['q'] ?? '');
$whereParts = [];
$params = [];

// Filter by status
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

// Filter by search
if ($search !== '') {
  $whereParts[] = "p.name LIKE :q";
  $params[':q'] = "%$search%";
}

if ($seasonStart && $seasonEnd) {
  $whereParts[] = "(p.status <> 'left' OR (p.left_at IS NOT NULL AND p.left_at BETWEEN :season_start AND :season_end))";
  $params[':season_start'] = $seasonStart;
  $params[':season_end'] = $seasonEnd;
}

// Build WHERE clause
$where = $whereParts ? "WHERE " . implode(" AND ", $whereParts) : "";

// --- Fetch players ---
$sql = "
    SELECT
      p.id,
      p.name,
      p.status,
      p.active,
      p.avatar,
      (pr.status = 'accepted') AS profile_picture_done,
      COALESCE(wp.uploaded_to_website, 0) AS website_done,
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
    LEFT JOIN player_photo_reviews pr ON pr.player_id = p.id
    LEFT JOIN player_website_photos wp ON wp.player_id = p.id
    $where
    ORDER BY status_order ASC, position_order ASC, p.position ASC, p.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Sponsors for filter dropdown ---
$sponsorList = $pdo->query("SELECT id, name FROM sponsors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// --- Sponsorships, one row per active slot (home/away/third), keyed by player then slot.
// Each row carries the sponsorship_agreements row it synced to, so an unpaid slot can link
// straight to that agreement's Stripe payment card. ---
$slotsByPlayer = [];
$atRiskPlayers = [];
if ($players) {
  $ids = implode(',', array_map('intval', array_column($players, 'id')));
  $stmt = $pdo->prepare("
      SELECT
        s.player_id,
        s.sponsor_id,
        sp.name AS sponsor_name,
        s.slot,
        s.amount,
        COALESCE(pay.total_paid, 0) AS total_paid,
        a.id AS agreement_id
      FROM sponsorships s
      JOIN sponsors sp ON sp.id = s.sponsor_id
      LEFT JOIN (
        SELECT sponsorship_id, COALESCE(SUM(amount), 0) AS total_paid
        FROM sponsorship_payments
        GROUP BY sponsorship_id
      ) pay ON pay.sponsorship_id = s.id
      LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'player' AND a.legacy_id = s.id
      WHERE s.player_id IN ($ids)
        AND s.season_id = :season_id
        AND s.ended_at IS NULL
  ");
  $stmt->execute([':season_id' => $seasonId]);

  foreach ($stmt as $row) {
    $playerId = (int)$row['player_id'];
    $slotsByPlayer[$playerId][(string)$row['slot']] = $row;
    if ((float)$row['amount'] - (float)$row['total_paid'] > 0.0001) {
      $atRiskPlayers[$playerId] = true;
    }
  }
}

// --- Status icon helper ---
function renderStatusIcon($status, $active)
{
  switch ($status) {
    case 'current':
      return '<i class="fa-solid fa-circle-check text-success" title="Current"></i>';
    case 'trialist':
      return '<i class="fa-solid fa-user-clock text-info" title="Trialist"></i>';
    case 'left':
      return '<i class="fa-solid fa-circle-xmark text-danger" title="Left"></i>';
    case 'retired':
      return '<i class="fa-solid fa-person-cane text-muted" title="Retired"></i>';
    case 'loan':
      return '<i class="fa-solid fa-right-left text-warning" title="On Loan"></i>';
    case 'injured':
      return '<i class="fa-solid fa-kit-medical text-secondary" title="Injured"></i>';
    default:
      return $active
        ? '<i class="fa-solid fa-user-check text-success" title="Active"></i>'
        : '<i class="fa-solid fa-user-slash text-danger" title="Inactive"></i>';
  }
}

function renderPlayerStatusLabel(string $status, bool $active): string
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

function renderPlayerAvatarUrl(?string $avatar): string
{
  if (empty($avatar)) {
    return '';
  }

  return '/uploads/players/' . rawurlencode($avatar);
}

// --- Group players by status ---
$grouped = [];
foreach ($players as $pl) {
  $grouped[$pl['status']][] = $pl;
}

// --- Totals ---
$totalPlayers = count($players);
$currentPlayers = count(array_filter($players, fn($p) => in_array($p['status'], ['current', 'trialist'], true) && $p['active']));
$leftPlayers = count(array_filter($players, fn($p) => $p['status'] === 'left' || !$p['active']));
$withSponsors = count(array_filter($players, fn($p) => !empty($slotsByPlayer[$p['id']])));
$withoutSponsors = $totalPlayers - $withSponsors;
$nextBirthdays = players_next_birthdays($pdo, 3);
$nextBirthday = $nextBirthdays[0] ?? null;

// --- Presentation metadata per squad status ---
$statusMeta = [
  'current' => ['label' => 'Current squad', 'description' => 'Available first-team players', 'icon' => 'fa-circle-check'],
  'trialist' => ['label' => 'Trialists', 'description' => 'Available players who have not signed', 'icon' => 'fa-user-clock'],
  'injured' => ['label' => 'Injured', 'description' => 'Players currently unavailable', 'icon' => 'fa-kit-medical'],
  'loan' => ['label' => 'On loan', 'description' => 'Players temporarily away', 'icon' => 'fa-right-left'],
  'left' => ['label' => 'Former players', 'description' => 'Archived squad records', 'icon' => 'fa-user-minus'],
  'retired' => ['label' => 'Retired', 'description' => 'Retired player records', 'icon' => 'fa-person-cane'],
];
?>

  <div class="players-page">
    <?php if ($nextBirthday !== null): ?>
      <section class="hub-context-bar mb-4" role="status">
        <div>
          <i class="fa-solid fa-cake-candles" aria-hidden="true"></i>
          <span>Next birthday: <strong><?= h($nextBirthday['name']) ?></strong></span>
        </div>
        <span>
          <?= (int) $nextBirthday['days_until'] === 0
            ? 'Today'
            : (int) $nextBirthday['days_until'] . ' day' . ((int) $nextBirthday['days_until'] === 1 ? '' : 's') . ' away' ?>
          · turns <?= (int) $nextBirthday['age_turning'] ?> on <?= h(date('d M Y', strtotime($nextBirthday['next_birthday']))) ?>
        </span>
      </section>
    <?php endif; ?>

    <!-- Totals Summary -->
    <?php hub_render_metric_grid([
      ['label' => 'Total players', 'value' => $totalPlayers, 'meta' => 'Visible records', 'icon' => 'fa-users', 'tone' => 'primary'],
      ['label' => 'Current squad', 'value' => $currentPlayers, 'meta' => 'Active players', 'icon' => 'fa-circle-check', 'tone' => 'success'],
      ['label' => 'Former players', 'value' => $leftPlayers, 'meta' => 'Archived records', 'icon' => 'fa-user-minus', 'tone' => 'neutral'],
      ['label' => 'Sponsored', 'value' => $withSponsors, 'meta' => 'Linked players', 'icon' => 'fa-handshake', 'tone' => 'info'],
      ['label' => 'No sponsor', 'value' => $withoutSponsors, 'meta' => 'Needs attention', 'icon' => 'fa-user-tag', 'tone' => 'warning'],
    ], 'Squad summary'); ?>

    <!-- Filters -->
    <section class="players-toolbar mb-4">
      <div class="players-toolbar__body">
        <div class="players-filter-header d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-2 mb-3">
          <div class="players-toolbar__heading">
            <span class="players-toolbar__icon"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
            <div>
              <div class="players-filter-eyebrow">Squad directory</div>
              <h2>Find a player</h2>
              <p class="players-filter-subtitle mb-0">Search by name, squad status or sponsor.</p>
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="players-toolbar__meta d-none d-xl-flex">
              <span><strong><?= count($players) ?></strong> results</span>
              <?php if ($search !== '' || $filter !== 'all' || $sponsorFilter !== ''): ?><a href="players.php">Clear filters</a><?php endif; ?>
            </div>
            <div class="hub-local-actions">
              <a href="player_add.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add player</a>
              <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Export</button>
                <div class="dropdown-menu dropdown-menu-end">
                  <button class="dropdown-item" type="button" id="exportCsv"><i class="fa-solid fa-file-csv" aria-hidden="true"></i>CSV</button>
                  <button class="dropdown-item" type="button" id="exportPdf"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i>PDF</button>
                </div>
              </div>
              <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Tools</button>
                <div class="dropdown-menu dropdown-menu-end">
                  <a class="dropdown-item" href="/admin/player_graphics.php"><i class="fa-solid fa-image" aria-hidden="true"></i>Player sponsor graphic</a>
                  <a class="dropdown-item" href="/admin/sponsor_graphics.php"><i class="fa-solid fa-users-rectangle" aria-hidden="true"></i>All players sponsor graphic</a>
                  <a class="dropdown-item" href="/admin/player_reference.php"><i class="fa-solid fa-address-card" aria-hidden="true"></i>Quick reference</a>
                  <a class="dropdown-item" href="/admin/player_photos_bulk.php"><i class="fa-solid fa-images" aria-hidden="true"></i>Bulk upload photos</a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="d-xl-none mb-3">
          <button
            class="btn btn-brand w-100 players-filter-toggle"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#playersFilterPanel"
            aria-expanded="false"
            aria-controls="playersFilterPanel"
          >
            <i class="fa-solid fa-magnifying-glass me-2" aria-hidden="true"></i>
            Search &amp; Filters
          </button>
        </div>

        <div class="collapse players-filter-collapse" id="playersFilterPanel">
          <form method="get" class="players-filter-form">
            <div class="row g-3 align-items-end">
              <div class="col-12 col-lg-5">
                <label class="form-label players-filter-label" for="playersSearch">Search players</label>
                <div class="players-search-input">
                  <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                  <input id="playersSearch" class="form-control" type="search" name="q"
                    placeholder="Enter player name" value="<?= htmlspecialchars($search) ?>">
                </div>
              </div>
              <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label players-filter-label" for="playersStatus">Status</label>
                <select id="playersStatus" name="filter" class="form-select">
                  <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                  <option value="current" <?= $filter === 'current' ? 'selected' : '' ?>>Current</option>
                  <option value="trialist" <?= $filter === 'trialist' ? 'selected' : '' ?>>Trialist</option>
                  <option value="left" <?= $filter === 'left' ? 'selected' : '' ?>>Left</option>
                  <option value="injured" <?= $filter === 'injured' ? 'selected' : '' ?>>Injured</option>
                  <option value="loan" <?= $filter === 'loan' ? 'selected' : '' ?>>Loan</option>
                  <option value="retired" <?= $filter === 'retired' ? 'selected' : '' ?>>Retired</option>
                </select>
              </div>
              <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label players-filter-label" for="playersSponsor">Sponsor</label>
                <select id="playersSponsor" name="sponsor" class="form-select">
                  <option value="">All Sponsors</option>
                  <?php foreach ($sponsorList as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $sponsorFilter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-lg-1 d-grid">
                <button class="btn btn-brand"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><span class="visually-hidden">Apply filters</span></button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </section>

  <!-- Players Grouped by Status -->
  <?php foreach (['current', 'trialist', 'injured', 'loan', 'left', 'retired'] as $status): ?>
    <?php if (!empty($grouped[$status])): ?>
      <?php
        $statusCount = count($grouped[$status]);
        $sectionMeta = $statusMeta[$status];
      ?>
      <section class="players-section hub-section hub-table-card players-section--<?= h($status) ?> mb-4 d-none d-xl-block">
        <header class="players-section__header">
          <div class="players-section__identity">
            <span class="players-section__icon"><i class="fa-solid <?= h($sectionMeta['icon']) ?>" aria-hidden="true"></i></span>
            <div>
              <h2><?= h($sectionMeta['label']) ?></h2>
              <p><?= h($sectionMeta['description']) ?></p>
            </div>
          </div>
          <span class="players-section__count"><?= $statusCount ?> <?= $statusCount === 1 ? 'player' : 'players' ?></span>
        </header>
        <div class="players-section__table">
          <table class="table hub-data-table--rounded players-table hub-data-table hub-data-table--responsive align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Player</th>
                <th scope="col">Sponsors</th>
                <th scope="col" class="text-center">Profile Picture</th>
                <th scope="col" class="text-center">Website</th>
                <th scope="col" class="text-center players-table__actions-col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grouped[$status] as $pl): ?>
                <?php
                  $rowStateClass = ($pl['status'] === 'left' || !$pl['active'])
                    ? 'players-table-row--inactive'
                    : (isset($atRiskPlayers[$pl['id']]) ? 'players-table-row--warning' : '');
                ?>
                <tr class="players-table-row players-table-row--clickable <?= $rowStateClass ?>" data-href="player_view.php?id=<?= $pl['id'] ?>">
                  <td class="players-table__player-cell" data-label="Player">
                    <div class="players-player">
                      <div class="players-avatar players-avatar--table">
                        <?php if (!empty($pl['avatar'])): ?>
                          <img src="<?= h(renderPlayerAvatarUrl((string) $pl['avatar'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                          <span><?= strtoupper(substr($pl['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="players-player__meta">
                        <div class="players-player__name"><?= htmlspecialchars($pl['name']) ?> <?= renderPlayerDisciplineBadge($disciplineStatusByPlayer, (int) $pl['id']) ?></div>
                        <div class="players-player__subtext">
                          <?= !empty($pl['active']) ? 'Active squad record' : 'Archived player record' ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="players-table__sponsors-cell" data-label="Sponsors">
                    <?php if (!empty($slotsByPlayer[$pl['id']])): ?>
                      <?= renderPlayerSponsorSlots($slotsByPlayer[$pl['id']]) ?>
                    <?php else: ?>
                      <span class="players-empty-sponsor"><i class="fa-regular fa-circle" aria-hidden="true"></i>No sponsors assigned</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center" data-label="Profile Picture"><?= renderDoneIcon((bool) $pl['profile_picture_done']) ?></td>
                  <td class="text-center" data-label="Website"><?= renderDoneIcon((bool) $pl['website_done']) ?></td>
                  <td class="text-center players-table__actions-col" data-label="Actions">
                    <div class="btn-group players-row-actions hub-actions" role="group" aria-label="Player actions">
                      <a href="player_edit.php?id=<?= $pl['id'] ?>"
                        class="btn btn-sm btn-action btn-edit"
                        title="Edit player">
                        <i class="fa-regular fa-pen-to-square"></i>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php foreach (['current', 'trialist', 'injured', 'loan', 'left', 'retired'] as $status): ?>
    <?php if (!empty($grouped[$status])): ?>
      <?php $sectionMeta = $statusMeta[$status]; ?>
      <section class="players-mobile-section hub-section players-mobile-section--<?= h($status) ?> d-xl-none mb-4">
        <header class="players-mobile-section__header">
          <div class="players-section__identity">
            <span class="players-section__icon"><i class="fa-solid <?= h($sectionMeta['icon']) ?>" aria-hidden="true"></i></span>
            <div>
              <h2><?= h($sectionMeta['label']) ?></h2>
              <p><?= h($sectionMeta['description']) ?></p>
            </div>
          </div>
          <span class="players-section__count"><?= count($grouped[$status]) ?></span>
        </header>
        <div class="d-flex flex-column gap-2">
          <?php foreach ($grouped[$status] as $pl): ?>
            <?php
              $playerCardClass = ($pl['status'] === 'left' || !$pl['active'])
                ? 'player-mobile-card player-mobile-left'
                : (isset($atRiskPlayers[$pl['id']]) ? 'player-mobile-card player-mobile-warning' : 'player-mobile-card');
              $statusLabel = renderPlayerStatusLabel((string) ($pl['status'] ?? ''), (bool) ($pl['active'] ?? false));
            ?>
            <div class="<?= $playerCardClass ?> player-mobile-card--clickable" data-href="player_view.php?id=<?= $pl['id'] ?>">
              <div class="player-mobile-head">
                <div class="players-player">
                  <div class="players-avatar players-avatar--mobile">
                    <?php if (!empty($pl['avatar'])): ?>
                      <img src="<?= h(renderPlayerAvatarUrl((string) $pl['avatar'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <span><?= h(strtoupper(substr($pl['name'], 0, 1))) ?></span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="players-player__name"><?= htmlspecialchars($pl['name']) ?> <?= renderPlayerDisciplineBadge($disciplineStatusByPlayer, (int) $pl['id']) ?></div>
                    <div class="players-player__subtext"><?= h($statusLabel) ?></div>
                  </div>
                </div>
                <div class="btn-group player-mobile-actions hub-actions" role="group" aria-label="Player actions">
                  <a href="player_edit.php?id=<?= $pl['id'] ?>" class="btn btn-sm btn-action btn-edit" title="Edit">
                    <i class="fa-regular fa-pen-to-square"></i>
                  </a>
                </div>
              </div>

              <div class="player-mobile-sponsors mt-3">
                <?php if (!empty($slotsByPlayer[$pl['id']])): ?>
                  <?= renderPlayerSponsorSlots($slotsByPlayer[$pl['id']]) ?>
                <?php else: ?>
                  <div class="players-empty-sponsor"><i class="fa-regular fa-circle" aria-hidden="true"></i>No sponsors assigned</div>
                <?php endif; ?>
              </div>

              <div class="mt-3 d-flex gap-3 small text-muted">
                <span><?= renderDoneIcon((bool) $pl['profile_picture_done']) ?> Profile Picture</span>
                <span><?= renderDoneIcon((bool) $pl['website_done']) ?> Website</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if (!empty($flashToast['message'])): ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;">
  <div id="playerFlashToast"
       class="toast text-bg-<?= htmlspecialchars($flashToast['type'] ?? 'success') ?> border-0"
       role="alert"
       aria-live="assertive"
       aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"><?= h($flashToast['message']) ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var toastEl = document.getElementById('playerFlashToast');
    if (toastEl) {
      new bootstrap.Toast(toastEl, { delay: 2500 }).show();
    }
  });
</script>
<?php endif; ?>

<script>
  $(function() {
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Export buttons
    $('#exportCsv').on('click', function() {
      window.location = 'export_players.php?format=csv';
    });
    $('#exportPdf').on('click', function() {
      window.location = 'export_players.php?format=pdf';
    });

    // Clickable player rows/cards — ignore clicks on links, buttons, or other controls within.
    $('.players-table-row--clickable, .player-mobile-card--clickable').on('click', function(e) {
      if ($(e.target).closest('a, button, input, select, textarea').length) {
        return;
      }
      var href = $(this).data('href');
      if (href) {
        window.location = href;
      }
    });
  });
</script>

<?php require __DIR__ . '/footer.php'; ?>
