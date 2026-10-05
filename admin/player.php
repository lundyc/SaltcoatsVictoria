<?php
// player.php — clean redesign with AJAX saving, free sponsorships, inline payments
require_once __DIR__ . '/header.php';

$seasonId = getSelectedSeasonId($pdo);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM players WHERE id=:id");
$stmt->execute([':id' => $id]);
$player = $stmt->fetch();
if (!$player) {
  echo "<div class='alert alert-danger'>Player not found.</div>";
  require __DIR__ . '/footer.php';
  exit;
}

// Get sponsors list active in the selected season
$sponsors = getActiveSponsorsForSeason($pdo, $seasonId);

// Fetch slot helper
function getSlot(PDO $pdo, int $playerId, string $slot, int $seasonId): ?array
{
  $q = $pdo->prepare("
    SELECT sp.*, s.name AS sponsor_name,
           COALESCE(SUM(pay.amount),0) AS paid_total
    FROM sponsorships sp
    LEFT JOIN sponsors s ON s.id = sp.sponsor_id
    LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
    WHERE sp.player_id=:pid AND sp.slot=:slot AND sp.season_id = :season_id
    GROUP BY sp.id
  ");
  $q->execute([':pid' => $playerId, ':slot' => $slot, ':season_id' => $seasonId]);
  $row = $q->fetch();
  if (!$row) return null;
  $row['due'] = max(0, (float)$row['amount'] - (float)$row['paid_total']);
  return $row;
}

$slotLabels = [
  'home' => 'Home',
  'away' => 'Away',
  'third' => 'Third',
];
?>
<div class="page-hero mb-4">
  <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
    <div>
      <div class="page-hero-eyebrow">Player detail</div>
      <h1 class="page-hero-title"><i class="fa-solid fa-user me-2"></i><?= h($player['name']) ?></h1>
      <p class="page-hero-subtitle">Manage sponsored slots, payments, and notes for the selected season.</p>
    </div>
  </div>
</div>
<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string)$player['name']) ?></span></nav>

<div class="row g-4">
  <?php $slotColumnClass = count($allowedSlots) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-4'; ?>
  <?php foreach ($allowedSlots as $slot): ?>
    <?php $row = getSlot($pdo, $id, $slot, $seasonId); ?>
    <div class="<?= $slotColumnClass ?>">
      <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold"><?= $slotLabels[$slot] ?? ucfirst($slot) ?> Sponsor</div>
        <div class="card-body">
          <form class="slot-form">
            <?= csrf_field() ?>
            <input type="hidden" name="player" value="<?= $id ?>">
            <input type="hidden" name="slot" value="<?= $slot ?>">

            <div class="mb-3">
              <label class="form-label">Sponsor</label>
              <select name="sponsor_id" class="form-select">
                <option value="0">(Available)</option>
                <?php foreach ($sponsors as $sp): ?>
                  <option value="<?= (int)$sp['id'] ?>" <?= ($row && (int)$row['sponsor_id'] === (int)$sp['id']) ? 'selected' : '' ?>>
                    <?= h($sp['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="is_free" value="1" id="free-<?= $slot ?>"
                <?= ($row && (float)$row['amount'] == 0.0) ? 'checked' : '' ?>>
              <label class="form-check-label" for="free-<?= $slot ?>">
                Free Sponsorship (no payment expected)
              </label>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-4">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control"
                  value="<?= $row ? h($row['amount']) : '' ?>" <?= ($row && (float)$row['amount'] == 0.0) ? 'readonly' : '' ?>>
              </div>
              <div class="col-4">
                <label class="form-label">Paid</label>
                <input type="number" step="0.01" readonly class="form-control paid-display"
                  value="<?= $row ? h($row['paid_total']) : '0.00' ?>">
              </div>
              <div class="col-4">
                <label class="form-label">Due</label>
                <input type="number" step="0.01" readonly class="form-control due-display"
                  value="<?= $row ? h($row['due']) : '0.00' ?>">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Update Paid Amount</label>
              <input type="number" step="0.01" name="add_payment" class="form-control" placeholder="Enter new payment e.g. 20.00">
              <div class="form-text">This will be added as a new payment entry.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" class="form-control"
                value="<?= $row ? h((string)$row['notes']) : '' ?>"
                placeholder="Pending, TBC, follow-up…">
            </div>

            <div class="text-end">
              <button type="submit" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="Save without reloading">
                <i class="fa-solid fa-floppy-disk me-1"></i> Save
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- AJAX handler -->
<script>
  $(function() {
    $('form.slot-form').on('submit', function(e) {
      e.preventDefault();
      const $form = $(this);
      $.post('player_save.php', $form.serialize(), function(resp) {
        if (resp.success) {
          // Update paid/due display
          $form.find('.paid-display').val(resp.paid_total.toFixed(2));
          $form.find('.due-display').val(resp.due.toFixed(2));
          $form.find('input[name=add_payment]').val(''); // clear input

          // Show tooltip confirmation
          const btn = $form.find('button[type=submit]');
          btn.attr('data-bs-title', 'Saved!').tooltip('show');
          setTimeout(() => btn.tooltip('hide'), 1500);
        } else {
          window.hubToast('Error: ' + resp.error, 'danger');
        }
      }, 'json').fail(() => window.hubToast('Request failed.', 'danger'));
    });
  });
</script>

<?php require __DIR__ . '/footer.php'; ?>
