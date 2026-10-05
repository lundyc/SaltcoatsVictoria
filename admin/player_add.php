<?php
// player_add.php
$pageHero = [
          'eyebrow' => 'Player management',
          'title' => 'Add player',
          'subtitle' => 'Create a new player record and set their initial status.',
          'actions' => [],
];
require_once __DIR__ . '/header.php';
$playerEditCssVersion = (string) filemtime(__DIR__ . '/assets/css/player_edit.css');
$playerStatusCardsJsVersion = (string) filemtime(__DIR__ . '/assets/js/player_status_cards.js');
echo '<link rel="stylesheet" href="/admin/assets/css/player_edit.css?v=' . h($playerEditCssVersion) . '">';
echo '<script src="/admin/assets/js/player_status_cards.js?v=' . h($playerStatusCardsJsVersion) . '" defer></script>';
$prefillName = trim((string) ($_GET['name'] ?? ''));
$prefillStatus = in_array((string) ($_GET['status'] ?? ''), ['trialist', 'current', 'left', 'retired', 'loan', 'injured'], true)
          ? (string) $_GET['status']
          : 'current';

// Sponsors active in the selected season
$seasonId = getSelectedSeasonId($pdo);
$flashToast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Add player</span></nav>
<div>
          <?php if (!empty($flashToast['message'])): ?>
                    <div class="alert alert-<?= htmlspecialchars($flashToast['type'] ?? 'danger') ?>">
                              <?= h($flashToast['message']) ?>
                    </div>
          <?php endif; ?>

          <div class="alert alert-info">Save the player first — you'll then be able to upload a profile picture and action shots for graphics.</div>

          <!-- Player Details -->
          <div class="card shadow-sm mb-3 hub-form-card">
                    <div class="card-body">
                              <h2 class="h5 card-title">Player details</h2>
                              <form id="addPlayerForm" method="post" action="players_save.php">
                                        <?= csrf_field() ?>
                                        <div class="mb-3">
                                                  <label class="form-label" for="playerNameAdd">Name</label>
                                                  <input type="text" class="form-control" id="playerNameAdd" name="name" value="<?= htmlspecialchars($prefillName) ?>" autocomplete="name" required>
                                        </div>
                                        <div class="mb-3">
                                                  <label class="form-label" for="playerDobAdd">Date of birth</label>
                                                  <input type="date" class="form-control" id="playerDobAdd" name="date_of_birth" value="">
                                        </div>
                                        <div class="mb-3">
                                                  <label class="form-label">Status</label>
                                                  <div class="player-status-cards">
                                                            <?php
                                                            $statuses = [
                                                                      'trialist' => 'Trialist',
                                                                      'current' => 'Current',
                                                                      'left' => 'Left',
                                                                      'retired' => 'Retired',
                                                                      'loan' => 'On Loan',
                                                                      'injured' => 'Injured'
                                                            ];
                                                            foreach ($statuses as $val => $label): ?>
                                                                      <button type="button" class="player-status-card <?= $val === $prefillStatus ? 'active' : '' ?>" data-status="<?= $val ?>">
                                                                                <span class="player-status-card-label"><?= htmlspecialchars($label) ?></span>
                                                                                <span class="player-status-card-meta"><?php if ($val === 'trialist'): ?>Trial start<?php elseif ($val === 'current'): ?>Signed in<?php elseif ($val === 'left'): ?>Left date<?php elseif ($val === 'retired'): ?>Retired on<?php elseif ($val === 'loan'): ?>On loan<?php else: ?>Injured<?php endif; ?></span>
                                                                      </button>
                                                            <?php endforeach; ?>
                                                  </div>
                                                  <div class="player-status-summary mt-3">
                                                            <div class="player-status-summary-row"><span>Status</span><strong id="playerStatusSummaryState">Current</strong></div>
                                                            <div class="player-status-summary-row"><span>Joined</span><strong id="playerStatusSummaryJoined">Not recorded</strong></div>
                                                            <div class="player-status-summary-row"><span>Left</span><strong id="playerStatusSummaryLeft">—</strong></div>
                                                            <div class="player-status-summary-row"><span>Active squad</span><strong id="playerStatusSummaryActive">Yes</strong></div>
                                                  </div>
                                                  <input type="hidden" name="status" id="playerStatusAdd" value="<?= htmlspecialchars($prefillStatus) ?>">
                                                  <input type="hidden" name="joined_at" id="playerJoinedAdd" value="">
                                                  <input type="hidden" name="left_at" id="playerLeftAdd" value="">
                                                  <div id="playerActiveAddHidden">
                                                            <input type="hidden" name="active" id="playerActiveAdd" value="1">
                                                  </div>
                                        </div>
                                        <div class="d-grid d-md-flex hub-actions">
                                                  <button type="submit" class="btn btn-brand">Save player</button>
                                        </div>
                              </form>
                    </div>
          </div>
          <div class="modal fade" id="playerStatusModal" tabindex="-1" aria-labelledby="playerStatusModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                      <div class="modal-header">
                          <div>
                              <p class="text-uppercase small mb-1">Status settings</p>
                              <h2 class="h5 modal-title" id="playerStatusModalLabel"><span id="playerStatusModalSelected">Status</span></h2>
                          </div>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                          <p class="small text-muted mb-3" id="playerStatusModalHelp">Choose the status and update the matching dates.</p>
                          <input type="hidden" id="playerStatusModalStatus" value="">
                          <div class="mb-3" id="playerStatusModalJoinedGroup">
                              <label class="form-label">Joined at</label>
                              <input type="date" class="form-control" id="playerStatusModalJoinedAt">
                          </div>
                          <div class="mb-3" id="playerStatusModalLeftGroup">
                              <label class="form-label">Left at</label>
                              <input type="date" class="form-control" id="playerStatusModalLeftAt">
                          </div>
                          <div class="form-check form-switch mb-0" id="playerStatusModalActiveGroup">
                              <input class="form-check-input" type="checkbox" id="playerStatusModalActive">
                              <label class="form-check-label" for="playerStatusModalActive">Active squad member</label>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-brand" id="playerStatusModalSave">Save</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Sponsorships (none yet for new player) -->
          <div class="card shadow-sm mb-3 hub-panel">
                    <div class="card-body">
                              <h2 class="h5 card-title">Sponsorships</h2>
                              <div class="hub-empty-state"><p class="text-muted mb-0">No sponsorships assigned yet. You can add sponsorships after creating the player.</p></div>
                    </div>
          </div>
</div>

<!-- Toasts -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
          <div id="toastSuccess" class="toast text-bg-success border-0 mb-2">
                    <div class="d-flex">
                              <div class="toast-body">✅ Saved successfully</div>
                              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
          </div>
          <div id="toastError" class="toast text-bg-danger border-0">
                    <div class="d-flex">
                              <div class="toast-body">❌ Error saving</div>
                              <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
          </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
