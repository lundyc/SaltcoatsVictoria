<?php

declare(strict_types=1);

/**
 * Season deals: a sponsor taking a sponsorship across many fixtures for an
 * agreed total (all the match balls, every home match day, ...).
 * Views: list (default), ?action=new, ?id=<deal>.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_deals.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}

matchDealsEnsureSchema($pdo);
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);

$dealId = (int) ($_GET['id'] ?? $_POST['deal_id'] ?? 0);
$deal = $dealId > 0 ? matchDealGet($pdo, $dealId) : null;
$seasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? ($deal['season_id'] ?? 0));
if ($seasonId <= 0) {
    $seasonId = getSelectedSeasonId($pdo);
}
$season = getSeasonById($pdo, $seasonId);
$action = (string) ($_GET['action'] ?? '');
$errors = [];
$flash = '';
$skippedAfterCreate = [];

/* ------------------------------------------------------------------ */
/* POST handlers                                                       */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($do === 'create') {
        $scope = (string) ($_POST['scope'] ?? 'all');
        $fixtures = getMatchFixtures($pdo, $seasonId);
        $fixtureIds = [];
        foreach ($fixtures as $fixture) {
            $isHome = (int) $fixture['is_home'] === 1;
            $chosen = in_array((int) $fixture['id'], array_map('intval', (array) ($_POST['fixture_ids'] ?? [])), true);
            if ($scope === 'all' || ($scope === 'home' && $isHome) || ($scope === 'away' && !$isHome) || ($scope === 'custom' && $chosen)) {
                $fixtureIds[] = (int) $fixture['id'];
            }
        }
        $totalRaw = preg_replace('/[^0-9.]/', '', (string) ($_POST['total'] ?? '')) ?? '';
        try {
            $result = matchDealCreate($pdo, [
                'season_id' => $seasonId,
                'sponsor_id' => (int) ($_POST['sponsor_id'] ?? 0),
                'roles' => array_map('strval', (array) ($_POST['roles'] ?? [])),
                'fixture_ids' => $fixtureIds,
                'scope' => $scope,
                'billing' => (string) ($_POST['billing'] ?? 'single'),
                'total' => $totalRaw === '' ? 0.0 : (float) $totalRaw,
                'complimentary' => !empty($_POST['complimentary']),
                'show_on_graphics' => !empty($_POST['show_on_graphics']),
                'notes' => (string) ($_POST['notes'] ?? ''),
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ]);
            auditLog($pdo, 'match_deal_created', 'Created season deal #' . $result['deal_id'] . ' (' . $result['created'] . ' placements' . ($result['skipped'] ? ', ' . count($result['skipped']) . ' skipped' : '') . ')');
            $_SESSION['match_deal_skipped'] = $result['skipped'];
            header('Location: /admin/match_deals.php?id=' . $result['deal_id'] . '&created=' . $result['created']);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $action = 'new';
        }
    } elseif ($deal) {
        try {
            if ($do === 'mark_paid') {
                if (!$canRecordPayments) {
                    throw new RuntimeException('You do not have permission to record payments.');
                }
                $n = matchDealMarkPaid($pdo, $dealId);
                auditLog($pdo, 'match_deal_paid', 'Marked season deal #' . $dealId . ' as paid (' . $n . ' placements)');
                $flash = 'Marked as paid.';
            } elseif ($do === 'mark_unpaid') {
                if (!$canRecordPayments) {
                    throw new RuntimeException('You do not have permission to record payments.');
                }
                matchDealMarkUnpaid($pdo, $dealId);
                auditLog($pdo, 'match_deal_unpaid', 'Cleared payments on season deal #' . $dealId);
                $flash = 'Payments cleared.';
            } elseif ($do === 'visibility') {
                matchDealSetVisibility($pdo, $dealId, !empty($_POST['show_on_graphics']));
                auditLog($pdo, 'match_deal_visibility', 'Season deal #' . $dealId . (!empty($_POST['show_on_graphics']) ? ' now shown' : ' now hidden') . ' on graphics');
                $flash = !empty($_POST['show_on_graphics']) ? 'The sponsor now appears on match graphics.' : 'The sponsor is now hidden from match graphics.';
            } elseif ($do === 'end') {
                $n = matchDealEnd($pdo, $dealId);
                auditLog($pdo, 'match_deal_ended', 'Ended season deal #' . $dealId . ' (' . $n . ' placements)');
                header('Location: /admin/match_deals.php?season_id=' . (int) $deal['season_id'] . '&ended=1');
                exit;
            }
            $deal = matchDealGet($pdo, $dealId);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['created'])) {
    $flash = (int) $_GET['created'] . ' placements created.';
    $skippedAfterCreate = (array) ($_SESSION['match_deal_skipped'] ?? []);
    unset($_SESSION['match_deal_skipped']);
}
if (isset($_GET['ended'])) {
    $flash = 'The deal and its placements were ended.';
}

$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Season Deals',
    'subtitle' => 'One sponsor taking a sponsorship across many fixtures for an agreed total.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$typeNames = [];
foreach (getMatchSponsorshipTypes($pdo) as $type) {
    $typeNames[(string) $type['code']] = (string) $type['name'];
}
$roleLabel = static fn (string $csv): string => implode(', ', array_map(static fn (string $r): string => $typeNames[$r] ?? ucwords(str_replace('_', ' ', $r)), array_filter(explode(',', $csv))));
$billingLabel = ['single' => 'One payment', 'per_match' => 'Per match'];
$scopeLabel = ['all' => 'All fixtures', 'home' => 'Home fixtures', 'away' => 'Away fixtures', 'custom' => 'Chosen fixtures'];
?>

<style>
.season-switch { min-height: 1.75rem; display: flex; align-items: center; gap: .65rem; padding-left: 0; }
.season-switch .form-check-input { margin: 0; width: 2.6rem; height: 1.4rem; cursor: pointer; float: none; flex: none; }
.season-switch .form-check-input:checked { background-color: #4b0818; border-color: #4b0818; }
.season-switch .form-check-label { cursor: pointer; font-weight: 600; }
.deal-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.deal-stat { padding: 1rem 1.1rem; border: 1px solid rgba(75, 8, 24, .12); border-radius: .8rem; background: #fff; }
.deal-stat small { display: block; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; font-size: .7rem; margin-bottom: .2rem; }
.deal-stat strong { font-size: 1.25rem; }
.deal-fixtures { max-height: 22rem; overflow: auto; border: 1px solid rgba(75, 8, 24, .12); border-radius: .7rem; }
.deal-fixtures label { display: flex; gap: .65rem; align-items: center; padding: .45rem .8rem; border-bottom: 1px solid rgba(75, 8, 24, .06); margin: 0; cursor: pointer; }
.deal-fixtures label:hover { background: rgba(75, 8, 24, .04); }
.deal-choice { display: flex; gap: .65rem; align-items: flex-start; padding: .7rem .9rem; border: 1px solid rgba(75, 8, 24, .15); border-radius: .7rem; background: #fff; height: 100%; cursor: pointer; }
.deal-choice input { margin-top: .25rem; flex: none; }
.deal-choice strong { display: block; }
.deal-choice span { color: #6c757d; font-size: .85rem; }
.deal-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.deal-pill--paid { background: #e3f4e7; color: #1c6b2a; }
.deal-pill--part { background: #fbf0c6; color: #7a5a00; }
.deal-pill--due { background: #f9dde0; color: #8a1424; }
.deal-pill--ended { background: #eee; color: #666; }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/match_deals.php?season_id=<?= (int) $seasonId ?>">Season deals</a><?php if ($deal): ?> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= $esc($deal['sponsor_name']) ?></span><?php elseif ($action === 'new'): ?> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">New deal</span><?php endif; ?></nav>

<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= $esc($error) ?></div><?php endforeach; ?>
<?php if ($flash !== ''): ?><div class="alert alert-success"><?= $esc($flash) ?></div><?php endif; ?>
<?php if ($skippedAfterCreate): ?>
  <div class="alert alert-warning">
    <strong><?= count($skippedAfterCreate) ?> placement(s) were skipped</strong> because another sponsor already has them:
    <ul class="mb-0 small"><?php foreach ($skippedAfterCreate as $line): ?><li><?= $esc($line) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($deal): /* ================= DETAIL ================= */ ?>
  <?php
  $rows = matchDealRows($pdo, $dealId);
  $rowsTotal = array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows));
  $paidTotal = array_sum(array_map(static fn (array $r): float => min((float) $r['amount'], (float) $r['paid_total']), $rows));
  $settled = count(array_filter($rows, static fn (array $r): bool => (int) $r['is_complimentary'] === 1 || (float) $r['paid_total'] + 0.005 >= (float) $r['amount']));
  $isEnded = $deal['status'] === 'ended';
  $payState = $isEnded ? ['ended', 'Ended'] : ((int) $deal['is_complimentary'] === 1 ? ['paid', 'Complimentary'] : ($rows && $settled === count($rows) ? ['paid', 'Paid'] : ($paidTotal > 0 ? ['part', 'Part paid'] : ['due', 'Unpaid'])));
  ?>
  <div class="card dashboard-card hub-section mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
      <div>
        <h2 class="h4 mb-1"><?= $esc($deal['sponsor_name']) ?> <span class="deal-pill deal-pill--<?= $payState[0] ?> align-middle ms-1"><?= $esc($payState[1]) ?></span></h2>
        <div class="text-muted"><?= $esc($roleLabel((string) $deal['roles'])) ?> &middot; <?= $esc($scopeLabel[$deal['scope']] ?? $deal['scope']) ?> &middot; <?= $esc($deal['season_name']) ?></div>
      </div>
      <?php if (!$isEnded): ?>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($canRecordPayments && (int) $deal['is_complimentary'] !== 1): ?>
          <?php if ($settled < count($rows)): ?>
            <form method="post" data-confirm="Record <?= $esc(gbp(max(0, $rowsTotal - $paidTotal))) ?> as paid on every placement in this deal?" data-confirm-action="Mark paid" data-confirm-class="btn-primary">
              <?= csrf_field() ?><input type="hidden" name="do" value="mark_paid"><input type="hidden" name="deal_id" value="<?= $dealId ?>">
              <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Mark whole deal paid</button>
            </form>
          <?php endif; ?>
          <?php if ($paidTotal > 0): ?>
            <form method="post" data-confirm="Remove all recorded payments from this deal?" data-confirm-action="Clear payments">
              <?= csrf_field() ?><input type="hidden" name="do" value="mark_unpaid"><input type="hidden" name="deal_id" value="<?= $dealId ?>">
              <button class="btn btn-outline-secondary btn-sm" type="submit">Clear payments</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <form method="post" data-confirm="End this deal and remove the sponsor from all <?= count($rows) ?> placements?" data-confirm-action="End deal">
          <?= csrf_field() ?><input type="hidden" name="do" value="end"><input type="hidden" name="deal_id" value="<?= $dealId ?>">
          <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-regular fa-circle-xmark me-1" aria-hidden="true"></i>End deal</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="deal-grid">
      <div class="deal-stat"><small>Agreed total</small><strong><?= (int) $deal['is_complimentary'] === 1 ? 'Complimentary' : $esc(gbp((float) $deal['total_amount'])) ?></strong></div>
      <div class="deal-stat"><small>Billing</small><strong><?= $esc($billingLabel[$deal['billing']] ?? $deal['billing']) ?></strong></div>
      <div class="deal-stat"><small>Placements</small><strong><?= count($rows) ?></strong></div>
      <div class="deal-stat"><small>Paid</small><strong><?= $esc(gbp($paidTotal)) ?> <span class="text-muted fs-6">of <?= $esc(gbp($rowsTotal)) ?></span></strong></div>
    </div>

    <?php if (!$isEnded): ?>
    <form method="post" class="d-flex align-items-center gap-3 mb-0" id="dealVisibilityForm">
      <?= csrf_field() ?><input type="hidden" name="do" value="visibility"><input type="hidden" name="deal_id" value="<?= $dealId ?>">
      <div class="form-check form-switch season-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="dealShow" name="show_on_graphics" value="1" <?= (int) $deal['show_on_graphics'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="dealShow">Show this sponsor on match graphics</label>
      </div>
      <span class="text-muted small">Unpaid placements stay hidden from graphics until paid, as for any sponsorship.</span>
    </form>
    <?php endif; ?>
    <?php if (!empty($deal['notes'])): ?><p class="text-muted small mt-3 mb-0">Notes: <?= $esc($deal['notes']) ?></p><?php endif; ?>
  </div></div>

  <div class="card dashboard-card hub-section"><div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table mb-0">
        <thead><tr><th>Date</th><th>Fixture</th><th>Package</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php $settledRow = (int) $r['is_complimentary'] === 1 || (float) $r['paid_total'] + 0.005 >= (float) $r['amount']; ?>
            <tr>
              <td><?= $esc(date('j M Y', strtotime((string) $r['match_date']))) ?></td>
              <td><?= (int) $r['is_home'] === 1 ? 'v' : '@' ?> <?= $esc($r['opponent']) ?></td>
              <td><?= $esc($typeNames[$r['sponsorship_role']] ?? $r['sponsorship_role']) ?></td>
              <td class="text-end"><?= (int) $r['is_complimentary'] === 1 ? 'Comp' : $esc(gbp((float) $r['amount'])) ?></td>
              <td><span class="deal-pill deal-pill--<?= $settledRow ? 'paid' : ((float) $r['paid_total'] > 0 ? 'part' : 'due') ?>"><?= (int) $r['is_complimentary'] === 1 ? 'Comp' : ($settledRow ? 'Paid' : ((float) $r['paid_total'] > 0 ? 'Part' : 'Unpaid')) ?></span></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/match.php?id=<?= (int) $r['fixture_id'] ?>&season_id=<?= (int) $r['season_id'] ?>">Open fixture</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="6" class="hub-record-empty hub-empty-state">No active placements.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>

<?php elseif ($action === 'new'): /* ================= CREATE ================= */ ?>
  <?php
  $fixtures = $season ? getMatchFixtures($pdo, $seasonId) : [];
  $sponsors = $pdo->query('SELECT id, name FROM sponsors WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
  $types = getMatchSponsorshipTypes($pdo);
  $pricing = $season ? getMatchSeasonPricing($pdo, $seasonId) : ['home_amount' => 0, 'away_amount' => 0, 'match_ball_amount' => 0];
  $standard = [];
  foreach ($types as $type) {
      $code = (string) $type['code'];
      $standard[$code] = $code === 'match_day'
          ? ['home' => (float) $pricing['home_amount'], 'away' => (float) $pricing['away_amount']]
          : ($code === 'match_ball' ? ['home' => (float) $pricing['match_ball_amount'], 'away' => (float) $pricing['match_ball_amount']] : ['home' => (float) $type['default_amount'], 'away' => (float) $type['default_amount']]);
  }
  $postedRoles = array_map('strval', (array) ($_POST['roles'] ?? ['match_ball']));
  $postedScope = (string) ($_POST['scope'] ?? 'all');
  $postedFixtures = array_map('intval', (array) ($_POST['fixture_ids'] ?? []));
  $postedBilling = (string) ($_POST['billing'] ?? 'single');
  ?>
  <?php if (!$season || (int) $season['is_locked'] === 1): ?>
    <div class="alert alert-warning">This season is locked, so new deals can't be added to it.</div>
  <?php else: ?>
  <form method="post" action="/admin/match_deals.php?action=new&season_id=<?= $seasonId ?>" id="dealForm">
    <?= csrf_field() ?><input type="hidden" name="do" value="create"><input type="hidden" name="season_id" value="<?= $seasonId ?>">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card hub-section h-100"><div class="card-body">
          <h3 class="h6 mb-3">Who and what</h3>
          <div class="mb-3">
            <label class="form-label" for="dealSponsor">Sponsor</label>
            <select class="form-select" id="dealSponsor" name="sponsor_id" required>
              <option value="">Choose a sponsor&hellip;</option>
              <?php foreach ($sponsors as $sp): ?><option value="<?= (int) $sp['id'] ?>" <?= (int) ($_POST['sponsor_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>><?= $esc($sp['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <label class="form-label">Sponsorship packages</label>
          <div class="row g-2 mb-3">
            <?php foreach ($types as $type): ?>
              <div class="col-sm-6"><label class="deal-choice"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= $esc($type['code']) ?>" <?= in_array((string) $type['code'], $postedRoles, true) ? 'checked' : '' ?> data-deal-role><span><strong><?= $esc($type['name']) ?></strong><span>Standard <?= $esc(gbp((float) ($standard[$type['code']]['home'] ?? 0))) ?><?= ($standard[$type['code']]['home'] ?? 0) != ($standard[$type['code']]['away'] ?? 0) ? ' home / ' . $esc(gbp((float) $standard[$type['code']]['away'])) . ' away' : '' ?></span></span></label></div>
            <?php endforeach; ?>
          </div>
          <div class="mb-0">
            <label class="form-label" for="dealNotes">Notes <span class="text-muted fw-normal">(optional)</span></label>
            <input class="form-control" type="text" id="dealNotes" name="notes" maxlength="255" value="<?= $esc($_POST['notes'] ?? '') ?>" placeholder="e.g. agreed with the owner, invoice to the office">
          </div>
        </div></div>
      </div>

      <div class="col-lg-6">
        <div class="card hub-section h-100"><div class="card-body">
          <h3 class="h6 mb-3">Price and billing</h3>
          <div class="mb-3">
            <label class="form-label" for="dealTotal">Agreed total for the whole deal</label>
            <div class="input-group"><span class="input-group-text">&pound;</span><input type="text" inputmode="decimal" autocomplete="off" id="dealTotal" name="total" class="form-control text-end" value="<?= $esc($_POST['total'] ?? '0.00') ?>" data-money></div>
            <div class="form-text" id="dealPriceHint">&nbsp;</div>
          </div>
          <div class="form-check form-switch season-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="dealComp" name="complimentary" value="1" <?= !empty($_POST['complimentary']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="dealComp">Complimentary (no charge)</label>
          </div>
          <label class="form-label">Billing</label>
          <div class="row g-2 mb-3">
            <div class="col-sm-6"><label class="deal-choice"><input class="form-check-input" type="radio" name="billing" value="single" <?= $postedBilling !== 'per_match' ? 'checked' : '' ?>><span><strong>One payment</strong><span>Sponsor pays the total once; mark the whole deal paid.</span></span></label></div>
            <div class="col-sm-6"><label class="deal-choice"><input class="form-check-input" type="radio" name="billing" value="per_match" <?= $postedBilling === 'per_match' ? 'checked' : '' ?>><span><strong>Per match</strong><span>The total is split evenly; each match is paid separately.</span></span></label></div>
          </div>
          <div class="form-check form-switch season-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="dealShowGraphics" name="show_on_graphics" value="1" <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['show_on_graphics']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="dealShowGraphics">Show this sponsor on match graphics</label>
          </div>
        </div></div>
      </div>

      <div class="col-12">
        <div class="card hub-section"><div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h3 class="h6 mb-0">Fixtures &mdash; <?= $esc($season['name']) ?></h3>
            <span class="text-muted small" id="dealCount"></span>
          </div>
          <div class="row g-2 mb-3">
            <?php foreach (['all' => ['All fixtures', 'Every fixture this season'], 'home' => ['Home only', 'Home fixtures'], 'away' => ['Away only', 'Away fixtures'], 'custom' => ['Choose fixtures', 'Tick the ones you want']] as $key => [$title, $sub]): ?>
              <div class="col-sm-6 col-lg-3"><label class="deal-choice"><input class="form-check-input" type="radio" name="scope" value="<?= $key ?>" <?= $postedScope === $key ? 'checked' : '' ?>><span><strong><?= $title ?></strong><span><?= $sub ?></span></span></label></div>
            <?php endforeach; ?>
          </div>
          <div class="deal-fixtures" id="dealFixtures">
            <?php foreach ($fixtures as $fixture): ?>
              <label>
                <input class="form-check-input" type="checkbox" name="fixture_ids[]" value="<?= (int) $fixture['id'] ?>" data-home="<?= (int) $fixture['is_home'] === 1 ? 1 : 0 ?>" <?= in_array((int) $fixture['id'], $postedFixtures, true) ? 'checked' : '' ?>>
                <span><?= $esc(date('D j M Y', strtotime((string) $fixture['match_date']))) ?></span>
                <span class="text-muted"><?= (int) $fixture['is_home'] === 1 ? 'v' : '@' ?></span>
                <strong><?= $esc($fixture['opponent'] ?? '') ?></strong>
                <?php if (!empty($fixture['competition'])): ?><span class="text-muted small ms-auto"><?= $esc($fixture['competition']) ?></span><?php endif; ?>
              </label>
            <?php endforeach; ?>
            <?php if (!$fixtures): ?><div class="p-3 text-muted">There are no fixtures in this season yet.</div><?php endif; ?>
          </div>
          <p class="text-muted small mt-2 mb-0">Fixtures where another sponsor already has that package are skipped and listed afterwards; nobody is replaced.</p>
        </div></div>
      </div>
    </div>

    <div class="settings-savebar">
      <a href="/admin/match_deals.php?season_id=<?= $seasonId ?>" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">Create deal</button>
    </div>
  </form>

  <script>
  (function () {
    var STANDARD = <?= json_encode($standard, JSON_UNESCAPED_SLASHES) ?>;
    var form = document.getElementById('dealForm');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('#dealFixtures input[type=checkbox]'));
    var list = document.getElementById('dealFixtures');
    var hint = document.getElementById('dealPriceHint');
    var count = document.getElementById('dealCount');
    var total = document.getElementById('dealTotal');
    var comp = document.getElementById('dealComp');

    function scope() { return form.querySelector('input[name=scope]:checked').value; }
    function selected() {
      var s = scope();
      return boxes.filter(function (b) {
        var home = b.getAttribute('data-home') === '1';
        return s === 'all' || (s === 'home' && home) || (s === 'away' && !home) || (s === 'custom' && b.checked);
      });
    }
    function roles() { return Array.prototype.slice.call(form.querySelectorAll('[data-deal-role]:checked')).map(function (r) { return r.value; }); }
    function money(n) { return '£' + n.toFixed(2); }

    function refresh() {
      var s = scope();
      list.style.opacity = s === 'custom' ? '1' : '.55';
      boxes.forEach(function (b) {
        var home = b.getAttribute('data-home') === '1';
        if (s !== 'custom') { b.checked = s === 'all' || (s === 'home' && home) || (s === 'away' && !home); }
        b.disabled = s !== 'custom';
      });
      var sel = selected(), r = roles(), slots = sel.length * r.length, std = 0;
      sel.forEach(function (b) { r.forEach(function (role) { var p = STANDARD[role] || { home: 0, away: 0 }; std += b.getAttribute('data-home') === '1' ? p.home : p.away; }); });
      count.textContent = sel.length + ' fixture' + (sel.length === 1 ? '' : 's') + ' × ' + r.length + ' package' + (r.length === 1 ? '' : 's') + ' = ' + slots + ' placements';
      var t = parseFloat(total.value) || 0;
      hint.textContent = comp.checked ? 'No charge for this deal.' : (slots ? 'Standard prices would total ' + money(std) + '. This deal works out at ' + money(t / slots) + ' per placement.' : ' ');
      total.disabled = comp.checked;
    }

    form.addEventListener('change', refresh);
    total.addEventListener('input', refresh);
    total.addEventListener('blur', function () { var n = parseFloat(String(total.value).replace(/[^0-9.]/g, '')); total.value = (isNaN(n) ? 0 : n).toFixed(2); refresh(); });
    total.addEventListener('input', function () { total.value = total.value.replace(/[^0-9.]/g, ''); });
    form.addEventListener('submit', function () { boxes.forEach(function (b) { b.disabled = false; }); });
    refresh();
  })();
  </script>
  <?php endif; ?>

<?php else: /* ================= LIST ================= */ ?>
  <?php $deals = matchDealsList($pdo, $seasonId); $seasons = array_reverse(getSeasons($pdo)); ?>
  <div class="hub-section-commandbar">
    <div><h2>Deals for <?= $esc($season['name'] ?? 'this season') ?></h2><p>Sponsors taking a package across many fixtures for one agreed total.</p></div>
    <div class="hub-local-actions d-flex flex-wrap gap-2 align-items-center">
      <form method="get" class="m-0"><select class="form-select form-select-sm" name="season_id" onchange="this.form.submit()" aria-label="Season">
        <?php foreach ($seasons as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $seasonId ? 'selected' : '' ?>><?= $esc($s['name']) ?></option><?php endforeach; ?>
      </select></form>
      <a href="/admin/match_deals.php?action=new&season_id=<?= $seasonId ?>" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>New deal</a>
    </div>
  </div>
  <div class="card hub-list-card hub-table-card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table align-middle hub-data-table mb-0">
      <thead><tr><th>Sponsor</th><th>Package</th><th>Fixtures</th><th>Billing</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($deals as $d): ?>
          <?php
          $ended = $d['status'] === 'ended';
          $settled = (int) $d['settled_count'];
          $rowsN = (int) $d['row_count'];
          $state = $ended ? ['ended', 'Ended'] : ((int) $d['is_complimentary'] === 1 ? ['paid', 'Complimentary'] : ($rowsN > 0 && $settled === $rowsN ? ['paid', 'Paid'] : ($settled > 0 ? ['part', $settled . ' of ' . $rowsN . ' paid'] : ['due', 'Unpaid'])));
          ?>
          <tr>
            <td><a href="/admin/match_deals.php?id=<?= (int) $d['id'] ?>"><strong><?= $esc($d['sponsor_name']) ?></strong></a></td>
            <td><?= $esc($roleLabel((string) $d['roles'])) ?></td>
            <td><?= $rowsN ?> <span class="text-muted small">(<?= $esc($scopeLabel[$d['scope']] ?? $d['scope']) ?>)</span></td>
            <td><?= $esc($billingLabel[$d['billing']] ?? $d['billing']) ?></td>
            <td class="text-end"><?= (int) $d['is_complimentary'] === 1 ? 'Comp' : $esc(gbp((float) $d['total_amount'])) ?></td>
            <td><span class="deal-pill deal-pill--<?= $state[0] ?>"><?= $esc($state[1]) ?></span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/match_deals.php?id=<?= (int) $d['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$deals): ?><tr><td colspan="7" class="hub-record-empty hub-empty-state">No season deals yet. Use <strong>New deal</strong> when a sponsor takes a package across many fixtures.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
