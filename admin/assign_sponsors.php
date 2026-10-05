<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$showThirdSlot = in_array('third', $allowedSlots, true);
$errors = [];
$success = false;
$isAjaxRequest = isset($_POST['ajax']) && $_POST['ajax'] === '1';

$sponsorshipDeadlinePassed = !empty($season['sponsorship_deadline']) && strtotime((string) $season['sponsorship_deadline']) <= time();
$canEditSponsorships = hub_auth_is_sponsorship_editor() && !$sponsorshipDeadlinePassed;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $action = $_POST['action'] ?? '';

    try {
      if ($action !== 'add_sponsorship_batch') {
        throw new RuntimeException('Invalid action.');
      }

      $postSeasonId = (int)($_POST['season_id'] ?? 0);
      if ($postSeasonId > 0) {
        $seasonId = $postSeasonId;
        $season = getSeasonById($pdo, $seasonId);
      }

      if (!$season) {
        throw new RuntimeException('Invalid season selected.');
      }
      assertSponsorshipEditable($pdo, $seasonId);

      $playerId = (int)($_POST['player_id'] ?? 0);
      $slotInputs = [
        'home' => (int)($_POST['home_sponsor_id'] ?? 0),
        'away' => (int)($_POST['away_sponsor_id'] ?? 0),
      ];
      if ($showThirdSlot) {
        $slotInputs['third'] = (int)($_POST['third_sponsor_id'] ?? 0);
      }

      if ($playerId <= 0) {
        throw new RuntimeException('Invalid player selected.');
      }

      $playerStmt = $pdo->prepare("SELECT id, name, active, status FROM players WHERE id = :id LIMIT 1");
      $playerStmt->execute([':id' => $playerId]);
      $player = $playerStmt->fetch(PDO::FETCH_ASSOC);
      if (!$player || (int)$player['active'] !== 1) {
        throw new RuntimeException('Player must be active.');
      }

      $activeSponsors = getActiveSponsorsForSeason($pdo, $seasonId);
      $activeSponsorIds = [];
      foreach ($activeSponsors as $activeSponsor) {
        $activeSponsorIds[(int)$activeSponsor['id']] = true;
      }

      $pdo->beginTransaction();

      $affectedSponsorIds = [];
      foreach ($slotInputs as $slot => $sponsorId) {
        if ($sponsorId <= 0) {
          continue;
        }
        if (!isset($activeSponsorIds[$sponsorId])) {
          throw new RuntimeException('Selected sponsor is not active in this season.');
        }

        $existingStmt = $pdo->prepare("
          SELECT id, sponsor_id
          FROM sponsorships
          WHERE season_id = :season_id
            AND player_id = :player_id
            AND slot = :slot
            AND ended_at IS NULL
          LIMIT 1
        ");
        $existingStmt->execute([
          ':season_id' => $seasonId,
          ':player_id' => $playerId,
          ':slot' => $slot,
        ]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
          $existingSponsorId = (int)$existing['sponsor_id'];
          if ($existingSponsorId !== $sponsorId) {
            $update = $pdo->prepare("
              UPDATE sponsorships
              SET sponsor_id = :sponsor_id,
                  assigned_at = NOW(),
                  amount = 0.00
              WHERE id = :id
            ");
            $update->execute([
              ':sponsor_id' => $sponsorId,
              ':id' => (int)$existing['id'],
            ]);
            $affectedSponsorIds[$existingSponsorId] = true;
            $affectedSponsorIds[$sponsorId] = true;
          }
          continue;
        }

        $insert = $pdo->prepare("
          INSERT INTO sponsorships
            (season_id, sponsor_id, player_id, slot, amount, paid, assigned_at, started_at)
          VALUES
            (:season_id, :sponsor_id, :player_id, :slot, 0.00, 0, NOW(), NOW())
        ");
        $insert->execute([
          ':season_id' => $seasonId,
          ':sponsor_id' => $sponsorId,
          ':player_id' => $playerId,
          ':slot' => $slot,
        ]);

        $affectedSponsorIds[$sponsorId] = true;
      }

      foreach (array_keys($affectedSponsorIds) as $sponsorId) {
        recalculateSponsorAmounts($pdo, $playerId, (int)$sponsorId, $seasonId);
      }

      if ($affectedSponsorIds) {
        $syncStmt = $pdo->prepare("SELECT id FROM sponsorships WHERE player_id = :player_id AND season_id = :season_id AND ended_at IS NULL");
        $syncStmt->execute([':player_id' => $playerId, ':season_id' => $seasonId]);
        foreach ($syncStmt->fetchAll(PDO::FETCH_COLUMN) as $syncId) {
          syncPlayerSponsorshipAgreement($pdo, (int) $syncId);
        }
      }

      $pdo->commit();

      if ($affectedSponsorIds) {
        $sponsorNameById = array_column($activeSponsors, 'name', 'id');
        $slotSummary = [];
        foreach ($slotInputs as $slotName => $sponsorIdForSlot) {
          if ($sponsorIdForSlot > 0) {
            $slotSummary[] = strtoupper($slotName) . ': ' . ($sponsorNameById[$sponsorIdForSlot] ?? ('#' . $sponsorIdForSlot));
          }
        }
        auditLog($pdo, 'player_sponsorship_assigned', "Assigned sponsors for player '{$player['name']}': " . implode(', ', $slotSummary));
      }

      if ($isAjaxRequest) {
        $summaryStmt = $pdo->prepare("
          SELECT s.slot, sp.name AS sponsor_name
          FROM sponsorships s
          JOIN sponsors sp ON sp.id = s.sponsor_id
          WHERE s.season_id = :season_id
            AND s.player_id = :player_id
            AND s.ended_at IS NULL
          ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), sp.name ASC
        ");
        $summaryStmt->execute([
          ':season_id' => $seasonId,
          ':player_id' => $playerId,
        ]);

        $summary = [];
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $summary[$row['slot']] = $row['sponsor_name'];
        }

        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'success' => true,
          'message' => 'Sponsorships saved.',
          'player_id' => $playerId,
          'assignments' => $summary,
        ]);
        exit;
      }

      header('Location: assign_sponsors.php?season_id=' . $seasonId . '&saved=1');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $errors[] = $e->getMessage();
    }
  }
}

if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
  ob_clean();
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(422);
  echo json_encode([
    'success' => false,
    'message' => $errors[0] ?? 'Unable to save sponsorships.',
    'errors' => $errors,
  ]);
  exit;
}

$seasonSponsors = getActiveSponsorsForSeason($pdo, $seasonId);
$sponsorOptions = $seasonSponsors;

$playersStmt = $pdo->prepare("
  SELECT id, name
  FROM players
  WHERE active = 1 AND status = 'current'
  ORDER BY name ASC
");
$playersStmt->execute();
$players = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentsStmt = $pdo->prepare("
  SELECT
    s.id,
    s.player_id,
    s.sponsor_id,
    s.slot,
    s.amount,
    p.name AS player_name,
    sp.name AS sponsor_name
  FROM sponsorships s
  JOIN players p ON p.id = s.player_id
  JOIN sponsors sp ON sp.id = s.sponsor_id
  WHERE s.season_id = :season_id
    AND s.ended_at IS NULL
  ORDER BY p.name ASC, FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), sp.name ASC
");
$assignmentsStmt->execute([':season_id' => $seasonId]);
$assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentsByPlayer = [];
foreach ($assignments as $row) {
  $assignmentsByPlayer[$row['player_id']][$row['slot']] = $row;
}

$totalCurrentPlayers = count($players);
$playersWithSponsors = count(array_filter($players, fn($p) => !empty($assignmentsByPlayer[$p['id']])));
$totalSlots = count($assignments);
$availableSponsors = count($sponsorOptions);
?>

<div class="assign-sponsors-page">
  <div class="page-hero mb-4">
    <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
      <div>
        <div class="page-hero-eyebrow">Season management</div>
        <h1 class="page-hero-title">Assign Sponsors</h1>
        <p class="page-hero-subtitle">Use one row per player to assign home, away, and third sponsors quickly.</p>
      </div>
      <div class="page-hero-actions assign-sponsors-actions">
        <a href="players.php" class="btn btn-outline-light btn-sm flex-fill text-center">Players</a>
        <a href="sponsors.php#season-sponsors" class="btn btn-outline-light btn-sm flex-fill text-center">Season Sponsors</a>
        <a href="overview.php" class="btn btn-outline-light btn-sm flex-fill text-center">Overview</a>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Sponsorships saved.</div>
  <?php endif; ?>
  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endforeach; ?>

  <?php if ($sponsorshipDeadlinePassed): ?>
    <div class="alert alert-warning">This season's sponsorship deadline has passed — player sponsorships are locked for everyone and can't be changed.</div>
  <?php elseif (!$canEditSponsorships): ?>
    <div class="alert alert-info">Player sponsorships can only be changed by the club owner. You can view assignments here, but the fields below are read-only.</div>
  <?php endif; ?>

  <?php hub_render_metric_grid([
    ['label'=>'Current players','value'=>(int)$totalCurrentPlayers,'meta'=>'Available for assignment','icon'=>'fa-users','tone'=>'primary'],
    ['label'=>'With sponsors','value'=>(int)$playersWithSponsors,'meta'=>'At least one placement','icon'=>'fa-handshake','tone'=>'success'],
    ['label'=>'Total slots','value'=>(int)$totalSlots,'meta'=>'Configured placements','icon'=>'fa-layer-group','tone'=>'info'],
    ['label'=>'Active sponsors','value'=>(int)$availableSponsors,'meta'=>'Available to assign','icon'=>'fa-building','tone'=>'warning'],
  ], 'Assignment summary'); ?>

  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
      <label for="playerFilter" class="form-label small text-muted mb-1">Filter players</label>
      <input id="playerFilter" type="search" class="form-control form-control-sm" placeholder="Type a player name...">
    </div>
  </div>

  <div id="playerFilterEmpty" class="alert alert-info d-none">
    No players match that filter.
  </div>

  <?php if (!$players): ?>
    <div class="alert alert-warning">No active current players are available for this season.</div>
  <?php else: ?>
    <div class="d-none d-lg-block">
      <div class="card shadow-sm border-0">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table hub-data-table--rounded table-hover align-middle mb-0 hub-data-table">
              <thead class="bg-brand-primary text-white">
                <tr>
                  <th style="min-width: 180px;">Player</th>
                  <th style="min-width: 220px;">Home Sponsor Slot</th>
                  <th style="min-width: 220px;">Away Sponsor Slot</th>
                  <?php if ($showThirdSlot): ?>
                    <th style="min-width: 220px;">Third</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($players as $player): ?>
                  <?php
                    $formId = 'player-sponsor-form-' . (int)$player['id'];
                    $currentHome = $assignmentsByPlayer[$player['id']]['home']['sponsor_id'] ?? 0;
                    $currentAway = $assignmentsByPlayer[$player['id']]['away']['sponsor_id'] ?? 0;
                    $currentThird = $assignmentsByPlayer[$player['id']]['third']['sponsor_id'] ?? 0;
                  ?>
                  <tr data-player-item data-player-name="<?= h(strtolower($player['name'])) ?>">
                    <td>
                      <form id="<?= h($formId) ?>" method="post"></form>
                      <?= str_replace('name="csrf_token"', 'form="' . h($formId) . '" name="csrf_token"', csrf_field()) ?>
                      <input form="<?= h($formId) ?>" type="hidden" name="action" value="add_sponsorship_batch">
                      <input form="<?= h($formId) ?>" type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                      <input form="<?= h($formId) ?>" type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">
                      <div class="fw-semibold"><?= h($player['name']) ?></div>
                      <div class="small text-muted mt-1" data-assignment-summary>
                        <?php if (!empty($assignmentsByPlayer[$player['id']])): ?>
                          <?php foreach ($assignmentsByPlayer[$player['id']] as $slot => $assignment): ?>
                            <span class="badge bg-primary me-1"><?= strtoupper(h($slot)) ?>: <?= h($assignment['sponsor_name']) ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          No sponsors assigned yet.
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <select form="<?= h($formId) ?>" name="home_sponsor_id" class="form-select" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                        <option value="">Select home sponsor</option>
                        <?php foreach ($sponsorOptions as $sponsor): ?>
                          <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentHome === (int)$sponsor['id'] ? 'selected' : '' ?>>
                            <?= h($sponsor['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td>
                      <select form="<?= h($formId) ?>" name="away_sponsor_id" class="form-select" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                        <option value="">Select away sponsor</option>
                        <?php foreach ($sponsorOptions as $sponsor): ?>
                          <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentAway === (int)$sponsor['id'] ? 'selected' : '' ?>>
                            <?= h($sponsor['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <?php if ($showThirdSlot): ?>
                      <td>
                        <select form="<?= h($formId) ?>" name="third_sponsor_id" class="form-select" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                          <option value="">Select third sponsor</option>
                          <?php foreach ($sponsorOptions as $sponsor): ?>
                            <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentThird === (int)$sponsor['id'] ? 'selected' : '' ?>>
                              <?= h($sponsor['name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="d-lg-none">
      <div class="d-flex flex-column gap-3">
        <?php foreach ($players as $player): ?>
          <?php
            $formId = 'player-sponsor-mobile-form-' . (int)$player['id'];
            $currentHome = $assignmentsByPlayer[$player['id']]['home']['sponsor_id'] ?? 0;
            $currentAway = $assignmentsByPlayer[$player['id']]['away']['sponsor_id'] ?? 0;
            $currentThird = $assignmentsByPlayer[$player['id']]['third']['sponsor_id'] ?? 0;
          ?>
          <div class="card shadow-sm border-0" data-player-item data-player-name="<?= h(strtolower($player['name'])) ?>">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <div>
                  <div class="fw-semibold"><?= h($player['name']) ?></div>
                  <div class="small text-muted mt-1" data-assignment-summary>
                    <?php if (!empty($assignmentsByPlayer[$player['id']])): ?>
                      <?php foreach ($assignmentsByPlayer[$player['id']] as $slot => $assignment): ?>
                        <span class="badge bg-primary me-1 mb-1"><?= strtoupper(h($slot)) ?>: <?= h($assignment['sponsor_name']) ?></span>
                      <?php endforeach; ?>
                    <?php else: ?>
                      No sponsors assigned yet.
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <form id="<?= h($formId) ?>" method="post" data-auto-save-sponsorship>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_sponsorship_batch">
                <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                <input type="hidden" name="player_id" value="<?= (int)$player['id'] ?>">

                <div class="mb-2">
                  <label class="form-label mb-1 small">Home Sponsor Slot</label>
                  <select name="home_sponsor_id" class="form-select form-select-sm" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                    <option value="">Select home sponsor</option>
                    <?php foreach ($sponsorOptions as $sponsor): ?>
                      <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentHome === (int)$sponsor['id'] ? 'selected' : '' ?>>
                        <?= h($sponsor['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-2">
                  <label class="form-label mb-1 small">Away Sponsor Slot</label>
                  <select name="away_sponsor_id" class="form-select form-select-sm" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                    <option value="">Select away sponsor</option>
                    <?php foreach ($sponsorOptions as $sponsor): ?>
                      <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentAway === (int)$sponsor['id'] ? 'selected' : '' ?>>
                        <?= h($sponsor['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <?php if ($showThirdSlot): ?>
                  <div class="mb-2">
                    <label class="form-label mb-1 small">Third</label>
                    <select name="third_sponsor_id" class="form-select form-select-sm" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                      <option value="">Select third sponsor</option>
                      <?php foreach ($sponsorOptions as $sponsor): ?>
                        <option value="<?= (int)$sponsor['id'] ?>" <?= (int)$currentThird === (int)$sponsor['id'] ? 'selected' : '' ?>>
                          <?= h($sponsor['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>

                <div class="small text-muted mt-2">Changes save automatically.</div>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const filter = document.getElementById('playerFilter');
  const empty = document.getElementById('playerFilterEmpty');
  const items = Array.from(document.querySelectorAll('[data-player-item]'));
  const toastContainer = document.getElementById('assignSponsorsToastContainer');

  const escapeHtml = (value) => {
    const span = document.createElement('span');
    span.textContent = value ?? '';
    return span.innerHTML;
  };

  const showToast = (message, variant = 'success') => {
    if (!toastContainer) return;

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-bg-${variant} border-0`;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML = `
      <div class="d-flex">
        <div class="toast-body"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    `;
    toastEl.querySelector('.toast-body').textContent = message;
    toastContainer.appendChild(toastEl);

    const toast = new bootstrap.Toast(toastEl, { delay: 2200 });
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    toast.show();
  };

  const renderSummary = (summary) => {
    const slots = ['home', 'away', 'third'];
    const parts = [];

    slots.forEach((slot) => {
      if (summary && summary[slot]) {
        parts.push(`<span class="badge bg-primary me-1 mb-1">${slot.toUpperCase()}: ${escapeHtml(summary[slot])}</span>`);
      }
    });

    return parts.length ? parts.join('') : 'No sponsors assigned yet.';
  };

  const updatePlayerSummary = (form, summary) => {
    const item = form.closest('[data-player-item]');
    if (!item) return;

    item.querySelectorAll('[data-assignment-summary]').forEach((el) => {
      el.innerHTML = renderSummary(summary);
    });
  };

  const setSavingState = (form, isSaving) => {
    Array.from(form.elements || []).forEach((control) => {
      if (!control.matches('select')) return;
      control.disabled = isSaving;
    });
    form.dataset.saving = isSaving ? '1' : '0';
  };

  const saveForm = async (form) => {
    if (!form || form.dataset.saving === '1') return;

    const payload = new FormData(form);
    payload.append('ajax', '1');

    setSavingState(form, true);

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: payload,
      });

      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) ? data.message : 'Unable to save sponsorships.');
      }

      updatePlayerSummary(form, data.assignments || {});
      showToast(data.message || 'Sponsorships saved.');
    } catch (error) {
      showToast(error.message || 'Unable to save sponsorships.', 'danger');
    } finally {
      setSavingState(form, false);
    }
  };

  if (!filter || !items.length) return;

  const applyFilter = () => {
    const term = filter.value.trim().toLowerCase();
    let visible = 0;

    items.forEach((item) => {
      const name = (item.dataset.playerName || '').toLowerCase();
      const match = !term || name.includes(term);
      item.classList.toggle('d-none', !match);
      if (match) visible++;
    });

    if (empty) {
      empty.classList.toggle('d-none', visible !== 0);
    }
  };

  filter.addEventListener('input', applyFilter);
  applyFilter();

  document.querySelectorAll('form[data-auto-save-sponsorship], form[id^="player-sponsor-form-"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      saveForm(form);
    });
    const associatedSelects = [
      ...form.querySelectorAll('select'),
      ...document.querySelectorAll(`select[form="${form.id}"]`)
    ];
    associatedSelects.forEach((select) => {
      select.addEventListener('change', () => saveForm(form));
    });
  });
});
</script>

<div id="assignSponsorsToastContainer" class="toast-container position-fixed top-0 end-0 p-3"></div>

<?php require __DIR__ . '/footer.php'; ?>
