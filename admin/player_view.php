<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/player_match_stats.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);

$seasonId = getSelectedSeasonId($pdo);
$id = (int)($_GET['id'] ?? 0);

// AJAX: delete a recorded sponsorship payment for this player. Must run before
// any HTML output (header.php) so the response is clean JSON.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax_delete_payment'])) {
    require_once __DIR__ . '/auth.php';
    header('Content-Type: application/json');

    if (!hub_auth_is_authenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Please log in again.']);
        exit;
    }
    if (!hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to delete payments.']);
        exit;
    }
    if (!csrf_check()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload the page and try again.']);
        exit;
    }

    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    if ($id <= 0 || $paymentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid payment reference.']);
        exit;
    }

    // Only a payment that belongs to one of THIS player's sponsorships may be deleted here.
    $lookup = $pdo->prepare("
        SELECT p.id, p.amount, p.sponsorship_id
        FROM sponsorship_payments p
        JOIN sponsorships s ON s.id = p.sponsorship_id
        WHERE p.id = :pid AND s.player_id = :player_id
        LIMIT 1
    ");
    $lookup->execute([':pid' => $paymentId, ':player_id' => $id]);
    $payment = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payment not found for this player.']);
        exit;
    }

    $sponsorshipId = (int) $payment['sponsorship_id'];

    $pdo->prepare('DELETE FROM sponsorship_payments WHERE id = :pid')->execute([':pid' => $paymentId]);
    recomputePaidFlag($pdo, $sponsorshipId);
    syncPlayerSponsorshipAgreement($pdo, $sponsorshipId);
    auditLog(
        $pdo,
        'player_sponsorship_payment_removed',
        'Deleted payment #' . $paymentId . ' (£' . number_format((float) $payment['amount'], 2)
            . ') from sponsorship #' . $sponsorshipId . ' (player #' . $id . ')'
    );

    echo json_encode(['success' => true]);
    exit;
}

if ($id <= 0) {
    $pageHero = [
        'eyebrow' => 'Player management',
        'title' => 'Player details',
        'subtitle' => 'No player was selected.',
        'actions' => [],
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Invalid player ID.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

players_ensure_date_of_birth_column($pdo);

$stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id');
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    $pageHero = [
        'eyebrow' => 'Player management',
        'title' => 'Player details',
        'subtitle' => 'The requested player could not be found.',
        'actions' => [],
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Player not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$season = getSeasonById($pdo, $seasonId);

$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));
$sponsorCatalog = getActiveSponsorsForSeason($pdo, $seasonId);
$activeSlotStmt = $pdo->prepare("SELECT slot FROM sponsorships WHERE player_id = :pid AND season_id = :season_id AND ended_at IS NULL AND slot IN ('home','away')");
$activeSlotStmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$playerActiveSlots = $activeSlotStmt->fetchAll(PDO::FETCH_COLUMN);
$freeSponsorshipSlots = array_diff($playerEditSlots, $playerActiveSlots);

$matchStats = hub_player_match_stats(
    $pdo,
    $seasonId,
    (string)($player['name'] ?? ''),
    __DIR__ . '/data/matches.json'
);

$pageHero = [
    'eyebrow' => 'Squad profile',
    'title' => (string) ($player['name'] ?? 'Player'),
    'subtitle' => (string)($season['name'] ?? 'Current season') . ' performance, sponsorships, and player record.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$playerViewJsVersion = (string) filemtime(__DIR__ . '/assets/js/player_view.js');
echo '<script>window.PLAYER_VIEW_CSRF = ' . json_encode((string) $_SESSION['csrf_token'], JSON_UNESCAPED_SLASHES) . ';</script>';
echo '<script src="/admin/assets/js/player_view.js?v=' . h($playerViewJsVersion) . '" defer></script>';

$stmt = $pdo->prepare("
  SELECT
    s.player_id,
    sp.id AS sponsor_id,
    sp.name AS sponsor_name,
    COALESCE(SUM(p.total_paid), 0) AS total_paid,
    COALESCE(SUM(s.amount), 0) AS required_amount,
    MAX(s.complimentary) AS complimentary
  FROM sponsorships s
  JOIN sponsors sp ON sp.id = s.sponsor_id
  LEFT JOIN (
    SELECT sponsorship_id, COALESCE(SUM(amount), 0) AS total_paid
    FROM sponsorship_payments
    GROUP BY sponsorship_id
  ) p ON p.sponsorship_id = s.id
  WHERE s.player_id = :pid
    AND s.season_id = :season_id
    AND s.ended_at IS NULL
  GROUP BY s.player_id, sp.id, sp.name
  ORDER BY sp.name
");
$stmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$sponsorships = $stmt->fetchAll(PDO::FETCH_ASSOC);

// A sponsor can carry more than one sponsorship "slot" (home/away/third kit) for the
// same player+season, aggregated together above. A Stripe payment link is generated per
// underlying agreement though, so fetch each slot individually here (unaggregated) with
// its own outstanding balance and the sponsorship_agreements row it synced to.
$stmt = $pdo->prepare("
  SELECT
    s.id AS sponsorship_id,
    s.sponsor_id,
    s.slot,
    s.amount,
    COALESCE(p.total_paid, 0) AS total_paid,
    a.id AS agreement_id
  FROM sponsorships s
  LEFT JOIN (
    SELECT sponsorship_id, COALESCE(SUM(amount), 0) AS total_paid
    FROM sponsorship_payments
    GROUP BY sponsorship_id
  ) p ON p.sponsorship_id = s.id
  LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'player' AND a.legacy_id = s.id
  WHERE s.player_id = :pid
    AND s.season_id = :season_id
    AND s.ended_at IS NULL
  ORDER BY s.slot
");
$stmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$stripeSlotsBySponsor = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slotRow) {
    $stripeSlotsBySponsor[(int)$slotRow['sponsor_id']][] = $slotRow;
}

$paymentsBySponsor = [];
foreach ($sponsorships as $s) {
    $stmt = $pdo->prepare("
      SELECT p.id, p.amount, p.paid_at, p.method, p.note
      FROM sponsorship_payments p
      WHERE p.sponsorship_id IN (
        SELECT id FROM sponsorships WHERE sponsor_id = :sid AND player_id = :pid AND season_id = :season_id AND ended_at IS NULL
      )
      ORDER BY p.paid_at DESC
    ");
    $stmt->execute([':sid' => $s['sponsor_id'], ':pid' => $id, ':season_id' => $seasonId]);
    $paymentsBySponsor[$s['sponsor_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalRequired = array_sum(array_map(static fn(array $row): float => (float) ($row['required_amount'] ?? 0), $sponsorships));
$totalPaid = array_sum(array_map(static fn(array $row): float => (float) ($row['total_paid'] ?? 0), $sponsorships));
$outstanding = $totalRequired - $totalPaid;

$stmt = $pdo->prepare('SELECT * FROM player_notes WHERE player_id = :pid ORDER BY created_at DESC');
$stmt->execute([':pid' => $id]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actionShotsStmt = $pdo->prepare('SELECT id, filename FROM player_action_shots WHERE player_id = :pid ORDER BY sort_order ASC, id ASC');
$actionShotsStmt->execute([':pid' => $id]);
$actionShots = array_values(array_filter(
    array_map(static fn(array $shot): array => [
        'url' => '/uploads/players/action_shots/' . rawurlencode(basename((string) $shot['filename'])),
        'label' => 'Action shot',
    ], $actionShotsStmt->fetchAll(PDO::FETCH_ASSOC)),
    static fn(array $shot): bool => is_file(__DIR__ . rawurldecode(parse_url($shot['url'], PHP_URL_PATH)))
));

// Photos where this player has been tagged in a match gallery — shown alongside
// the dedicated action-shots gallery so tagging work shows up on the profile too.
$taggedShotsStmt = $pdo->prepare("
    SELECT mp.id AS photo_id, mp.match_fixture_id, mp.filename
    FROM match_photo_tags mpt
    JOIN tagged_people tp ON tp.id = mpt.tagged_person_id
    JOIN match_photos mp ON mp.id = mpt.match_photo_id
    WHERE tp.player_id = :pid
    ORDER BY mp.uploaded_at DESC, mp.id DESC
");
$taggedShotsStmt->execute([':pid' => $id]);
$taggedShots = [];
foreach ($taggedShotsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $fixtureId = (int) $row['match_fixture_id'];
    $filename = basename((string) $row['filename']);
    if ($fixtureId <= 0 || $filename === '') {
        continue;
    }
    $relative = 'matches/gallery/' . $fixtureId . '/' . rawurlencode($filename);
    if (is_file(__DIR__ . '/uploads/' . $relative)) {
        $taggedShots[] = ['url' => '/uploads/' . $relative, 'label' => 'Tagged match photo'];
    }
}
$actionAndTaggedShots = array_merge($actionShots, $taggedShots);

$playerName = trim((string)($player['name'] ?? 'Player'));
$nameParts = preg_split('/\s+/', $playerName) ?: [];
$playerInitials = '';
foreach (array_slice($nameParts, 0, 2) as $namePart) {
    $playerInitials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
}
$playerInitials = $playerInitials !== '' ? $playerInitials : 'P';
$avatarFilename = basename((string)($player['avatar'] ?? ''));
$avatarAvailable = $avatarFilename !== ''
    && is_file(__DIR__ . '/uploads/players/' . $avatarFilename);
$status = (string)($player['status'] ?? '');
$statusLabel = match ($status) {
    'current' => 'Active',
    'trialist' => 'Trialist',
    'loan' => 'On loan',
    'injured' => 'Injured',
    'left' => 'Left club',
    'retired' => 'Retired',
    default => ucfirst($status !== '' ? $status : 'Unknown'),
};
$statusClass = match ($status) {
    'current' => 'is-active',
    'trialist' => 'is-warning',
    'injured' => 'is-warning',
    'left', 'retired' => 'is-inactive',
    default => 'is-neutral',
};
$paymentProgress = $totalRequired > 0
    ? min(100, max(0, ($totalPaid / $totalRequired) * 100))
    : 0;
?>

<link rel="stylesheet" href="/admin/assets/css/player_view.css">

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string)($player['name'] ?? 'Player')) ?></span></nav>
<div class="player-profile-page">
  <section class="card player-profile-performance hub-panel">
    <div class="card-body">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
          <div class="player-profile-section-kicker">Match graphics data</div>
          <h2 class="player-profile-section-title">Season performance</h2>
          <div class="text-muted small"><?= htmlspecialchars((string)($season['name'] ?? 'Selected season'), ENT_QUOTES, 'UTF-8') ?> · Calculated from Match Graphics</div>
        </div>
      </div>
      <?php
      $performanceMetrics = [
        ['label' => 'Appearances', 'value' => (int)$matchStats['appearances'], 'meta' => (int)$matchStats['starts'] . ' starts · ' . (int)$matchStats['substitute_appearances'] . ' as sub', 'icon' => 'fa-shirt', 'tone' => 'primary'],
        ['label' => 'Goals', 'value' => (int)$matchStats['goals'], 'meta' => 'This season', 'icon' => 'fa-futbol', 'tone' => 'success'],
        ['label' => 'Yellow cards', 'value' => (int)$matchStats['yellow_cards'], 'meta' => 'This season', 'icon' => 'fa-square', 'tone' => 'warning'],
        ['label' => 'Red cards', 'value' => (int)$matchStats['red_cards'], 'meta' => 'This season', 'icon' => 'fa-square', 'tone' => 'danger'],
        ['label' => 'Minutes played', 'value' => number_format((int)$matchStats['minutes_played']), 'meta' => 'Recorded match time', 'icon' => 'fa-stopwatch', 'tone' => 'info'],
      ];
      if ($matchStats['is_goalkeeper']) {
        $performanceMetrics[] = ['label' => 'Clean sheets', 'value' => (int)$matchStats['clean_sheets'], 'meta' => 'Starting goalkeeper', 'icon' => 'fa-shield-halved', 'tone' => 'success'];
      }
      hub_render_metric_grid($performanceMetrics, 'Season performance summary');
      ?>
    </div>
  </section>

  <section class="card player-profile-finance hub-panel">
    <div class="card-body">
      <div class="player-profile-section-kicker">Sponsorship</div>
      <h2 class="player-profile-section-title">Financial overview</h2>
      <div class="player-profile-money-grid">
        <div class="player-profile-money">
          <span>Required</span>
          <strong>£<?= number_format($totalRequired, 2) ?></strong>
        </div>
        <div class="player-profile-money">
          <span>Paid</span>
          <strong>£<?= number_format($totalPaid, 2) ?></strong>
        </div>
        <div class="player-profile-money is-outstanding<?= $outstanding <= 0 ? ' is-settled' : '' ?>">
          <span>Outstanding</span>
          <strong>£<?= number_format(max(0, $outstanding), 2) ?></strong>
        </div>
      </div>
      <progress class="player-profile-progress" value="<?= number_format($paymentProgress, 2, '.', '') ?>" max="100" aria-label="<?= (int)round($paymentProgress) ?>% paid"></progress>
      <div class="d-flex justify-content-between mt-2 small text-muted">
        <span><?= count($sponsorships) ?> sponsor<?= count($sponsorships) === 1 ? '' : 's' ?></span>
        <span><?= (int)round($paymentProgress) ?>% paid</span>
      </div>
    </div>
  </section>

  <section class="card player-profile-details hub-panel">
    <div class="card-body">
      <div class="player-profile-identity">
        <div class="player-profile-avatar">
          <?php if ($avatarAvailable): ?>
            <img src="/uploads/players/<?= rawurlencode($avatarFilename) ?>" alt="<?= htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8') ?>">
          <?php else: ?>
            <span><?= htmlspecialchars($playerInitials, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
        <div>
          <div class="player-profile-section-kicker">Player record</div>
          <h2 class="player-profile-name"><?= htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8') ?></h2>
          <span class="player-profile-status hub-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <a href="/admin/player_edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm ms-md-auto"><i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit player</a>
      </div>
      <div class="player-profile-meta">
        <div><span>Season</span><strong><?= htmlspecialchars((string)($season['name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Date of birth</span><strong><?= h(players_format_date_of_birth((string) ($player['date_of_birth'] ?? ''))) ?></strong></div>
        <?php $playerAge = players_calculate_age((string) ($player['date_of_birth'] ?? '')); ?>
        <div><span>Current age</span><strong><?= $playerAge !== null ? (int) $playerAge : '—' ?></strong></div>
        <div><span>Joined</span><strong><?= !empty($player['joined_at']) ? date('d M Y', strtotime((string)$player['joined_at'])) : 'Not recorded' ?></strong></div>
        <div><span>Left</span><strong><?= !empty($player['left_at']) ? date('d M Y', strtotime((string)$player['left_at'])) : '—' ?></strong></div>
      </div>
    </div>
  </section>

  <section class="card player-profile-actionshots hub-panel">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
          <div class="player-profile-section-kicker">Graphics library</div>
          <h2 class="player-profile-section-title">Action shots</h2>
        </div>
        <a href="/admin/player_edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-cloud-arrow-up me-1" aria-hidden="true"></i>Manage photos</a>
      </div>
      <?php if ($actionAndTaggedShots): ?>
        <p class="text-muted small mb-3">Includes photos tagged of this player in match galleries. Only dedicated action shots (not tagged photos) can be used in Player of the Match, Goal and Player Sponsor graphics.</p>
        <div class="player-profile-actionshots-grid" data-lightbox>
          <?php foreach ($actionAndTaggedShots as $shot): ?>
            <a href="<?= h((string) $shot['url']) ?>" class="player-profile-actionshot" data-full="<?= h((string) $shot['url']) ?>" data-caption="<?= h((string) $shot['label']) ?>">
              <img src="<?= h((string) $shot['url']) ?>" alt="<?= htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8') ?> — <?= h((string) $shot['label']) ?>" loading="lazy">
              <?php if ($shot['label'] === 'Tagged match photo'): ?>
                <span class="player-profile-actionshot-badge">Tagged</span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="lightbox" id="actionShotsLightbox" hidden role="dialog" aria-modal="true" aria-label="Photo viewer">
          <button class="lightbox__close" type="button" aria-label="Close">&times;</button>
          <button class="lightbox__nav lightbox__nav--prev" type="button" aria-label="Previous image">&#8249;</button>
          <div class="lightbox__stage">
            <img class="lightbox__img" src="" alt="">
            <p class="lightbox__meta"><span class="lightbox__count"></span><span class="lightbox__caption" hidden></span></p>
          </div>
          <button class="lightbox__nav lightbox__nav--next" type="button" aria-label="Next image">&#8250;</button>
          <div class="lightbox__thumbs"></div>
        </div>
      <?php else: ?>
        <p class="text-muted mb-0">No action shots or tagged match photos yet. Add some from the edit page to use in Player of the Match, Goal and Player Sponsor graphics.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="card player-profile-notes hub-form-card">
    <div class="card-body">
      <div class="player-profile-section-kicker">Internal</div>
      <h2 class="player-profile-section-title mb-3">Player notes</h2>
      <ul class="list-group mb-3" id="notesList">
        <?php if ($notes): ?>
          <?php foreach ($notes as $n): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between gap-3">
                <span><?= nl2br(htmlspecialchars((string) ($n['note'] ?? ''))) ?></span>
                <small class="text-muted text-nowrap"><?= date('d/m/Y H:i', strtotime((string) $n['created_at'])) ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li class="list-group-item text-muted">No notes yet.</li>
        <?php endif; ?>
      </ul>
      <form id="addNoteForm" class="hub-form-section">
        <textarea class="form-control mb-2" name="note" rows="3" placeholder="Write a private player note…" required></textarea>
        <button type="submit" class="btn btn-sm btn-brand w-100">Add note</button>
      </form>
    </div>
  </section>

  <section class="card player-profile-sponsors hub-section">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
        <div>
          <div class="player-profile-section-kicker">Commercial</div>
          <h2 class="player-profile-section-title">Sponsors &amp; payments</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="text-muted small"><?= htmlspecialchars((string)($season['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          <button type="button" class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#addSponsorshipModal" <?= $freeSponsorshipSlots ? '' : 'disabled title="No free home/away slot for this player this season"' ?>>
            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add sponsorship
          </button>
        </div>
      </div>
      <?php if ($sponsorships): ?>
        <div>
          <?php foreach ($sponsorships as $s): ?>
            <div class="player-profile-sponsor">
              <div class="player-profile-sponsor__header d-flex justify-content-between align-items-center mb-2 gap-2">
                <div class="player-profile-sponsor__name">
                  <strong><?= htmlspecialchars((string) $s['sponsor_name']) ?></strong>
                </div>
                <div>
                  <?php if ((bool) $s['complimentary']): ?>
                    <span class="badge hub-status bg-info text-dark">Complimentary</span>
                  <?php elseif ((float) $s['total_paid'] >= (float) $s['required_amount']): ?>
                    <span class="badge hub-status bg-success">Fully paid</span>
                  <?php elseif ((float) $s['total_paid'] > 0): ?>
                    <span class="badge hub-status bg-warning text-dark">Part paid</span>
                  <?php else: ?>
                    <span class="badge hub-status bg-danger">Unpaid</span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!empty($paymentsBySponsor[$s['sponsor_id']])): ?>
                <div class="table-responsive">
                <table class="table table-sm hub-data-table hub-data-table--responsive align-middle mb-2">
                  <thead class="table-light">
                    <tr>
                      <th>Date</th>
                      <th class="text-end">Amount</th>
                      <th>Method</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($paymentsBySponsor[$s['sponsor_id']] as $pay): ?>
                      <tr id="payment-row-<?= (int) $pay['id'] ?>">
                        <td data-label="Date"><?= date('d/m/Y', strtotime((string) $pay['paid_at'])) ?></td>
                        <td data-label="Amount" class="text-end">£<?= number_format((float) $pay['amount'], 2) ?></td>
                        <td data-label="Method"><?= htmlspecialchars((string) ($pay['method'] ?? '-')) ?></td>
                        <td data-label="Actions" class="player-profile-payment-actions text-end">
                          <a href="/admin/payment_edit.php?id=<?= (int) $pay['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                          <button class="btn btn-sm btn-outline-danger delete-payment" data-id="<?= (int) $pay['id'] ?>">Delete</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <th colspan="2">Total Paid:</th>
                      <th colspan="2" class="text-end">£<?= number_format((float) $s['total_paid'], 2) ?> / £<?= number_format((float) $s['required_amount'], 2) ?></th>
                    </tr>
                  </tfoot>
                </table>
                </div>
              <?php else: ?>
                <p class="text-muted mb-2">No payments recorded.</p>
              <?php endif; ?>

              <?php if ((float) $s['total_paid'] < (float) $s['required_amount']): ?>
                <div class="player-profile-add-payment text-end">
                  <button class="btn btn-sm btn-brand"
                    data-bs-toggle="modal"
                    data-bs-target="#addPaymentModal"
                    data-sponsor="<?= (int) $s['sponsor_id'] ?>"
                    data-remaining="<?= (float) $s['required_amount'] - (float) $s['total_paid'] ?>">
                    + Add Payment
                  </button>
                </div>
              <?php endif; ?>

              <?php
              $outstandingSlots = array_filter($stripeSlotsBySponsor[(int) $s['sponsor_id']] ?? [], static function (array $slot): bool {
                  return !empty($slot['agreement_id']) && (float) $slot['amount'] - (float) $slot['total_paid'] > 0.0001;
              });
              ?>
              <?php if ($outstandingSlots): ?>
                <div class="player-profile-stripe-links text-end mt-2">
                  <?php foreach ($outstandingSlots as $slot): ?>
                    <a href="/admin/sponsorship_agreement.php?id=<?= (int) $slot['agreement_id'] ?>#stripePaymentCard" class="btn btn-sm btn-outline-primary" title="Send Stripe payment link">
                      <i class="fa-brands fa-stripe-s" aria-hidden="true"></i>
                      <?= count($stripeSlotsBySponsor[(int) $s['sponsor_id']] ?? []) > 1 ? htmlspecialchars(ucfirst((string) $slot['slot']) . ' kit', ENT_QUOTES, 'UTF-8') . ' link' : 'Send Stripe link' ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-muted">No sponsorships recorded.</p>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="modal fade" id="addSponsorshipModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content hub-form-card" id="addSponsorshipForm">
      <div class="modal-header">
        <h5 class="modal-title">Add Sponsorship</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="player_id" value="<?= (int) $id ?>">
        <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
        <div class="mb-3">
          <label class="form-label">Sponsor</label>
          <select name="sponsor_id" class="form-select" required>
            <option value="">Select sponsor</option>
            <?php foreach ($sponsorCatalog as $sp): ?>
              <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars((string) $sp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Kit slot</label>
          <select name="slot" class="form-select" required>
            <?php if ($freeSponsorshipSlots): ?>
              <?php foreach ($freeSponsorshipSlots as $slot): ?>
                <option value="<?= h($slot) ?>"><?= h(ucfirst($slot)) ?></option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="">Player already has a sponsorship</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="mb-3 form-check form-switch">
          <input type="checkbox" name="complimentary" value="1" class="form-check-input" id="modalSponsorshipComplimentary" role="switch">
          <label class="form-check-label" for="modalSponsorshipComplimentary">Complimentary (free) sponsorship</label>
        </div>
        <div class="mb-3" id="modalSponsorshipAmountField">
          <label class="form-label">Amount</label>
          <input type="number" step="0.01" min="0" name="amount" class="form-control" placeholder="£0.00">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional notes">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success" <?= $freeSponsorshipSlots ? '' : 'disabled' ?>>Save Sponsorship</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="addPaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content hub-form-card" id="addPaymentForm">
      <div class="modal-header">
        <h5 class="modal-title">Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="sponsor_id" id="modalSponsorId">
        <input type="hidden" id="modalRemaining" value="0">
        <div class="mb-3 d-flex justify-content-between align-items-center">
          <label class="form-label mb-0">Amount</label>
          <button type="button" class="btn btn-sm btn-outline-primary" id="fillRemaining">Pay Remaining</button>
        </div>
        <input type="number" step="0.01" name="amount" id="modalAmount" class="form-control mb-3" required>
        <div class="mb-3">
          <label class="form-label">Method</label>
          <select name="method" class="form-select">
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="bank">Bank Transfer</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Note</label>
          <textarea name="note" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Payment</button>
      </div>
    </form>
  </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="toastNoteSuccess" class="toast align-items-center text-bg-success border-0 mb-2" role="alert">
    <div class="d-flex">
      <div class="toast-body">Note added successfully</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastNoteError" class="toast align-items-center text-bg-danger border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body">Error adding note</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
