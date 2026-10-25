<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Board renewals',
    'subtitle' => 'Renew pitchside board sponsors for next season at the standard renewal price for their board size.',
    'actions' => [['label' => 'Edit board prices', 'href' => '/sponsorship_packages.php', 'class' => 'btn btn-outline-light btn-sm']],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/stripe.php';
ensureSponsorshipCatalogSchema($pdo);
ensureStripeSchema($pdo);

/*
 * HOW THE RENEWAL PRICE IS KEPT RIGHT
 *  - The renewal price lives in ONE place: the board package (Sponsorship → Packages → "Renewal price").
 *  - Nobody types a price here. "Renew" creates next season's agreement at the package's renewal price.
 *  - Payment links and emails are built from the agreement's outstanding balance, so they can only ever
 *    show that agreed price.
 *  - Before a payment link is sent, the agreement's price is compared with the package's standard renewal
 *    price. If they differ (someone edited it), sending is blocked until you tick a deliberate override.
 *  - Never shown on the public website.
 */

$boardPackages = $pdo->query("SELECT * FROM packages WHERE category = 'Ground Advertising' AND is_active = 1 ORDER BY amount, id")->fetchAll(PDO::FETCH_ASSOC);
$boardById = [];
foreach ($boardPackages as $p) {
    $boardById[(int) $p['id']] = $p;
}
$tier = static function (array $p): string {
    $n = strtolower((string) $p['name'] . ' ' . (string) $p['code']);
    return str_contains($n, 'small') ? 'Small' : (str_contains($n, 'medium') ? 'Medium' : 'Large');
};
$size = static function (array $p): string {
    return preg_match('/\(([^)]+)\)/', (string) ($p['description'] ?? ''), $m) ? $m[1] : '';
};

$seasons = $pdo->query('SELECT id, name, start_date, end_date FROM seasons ORDER BY start_date')->fetchAll(PDO::FETCH_ASSOC);
$current = getCurrentSeason($pdo);
$currentStart = (string) ($current['start_date'] ?? '1970-01-01');
$targets = array_values(array_filter($seasons, static fn(array $s): bool => (string) $s['start_date'] > $currentStart));
$targetId = (int) ($_GET['season'] ?? $_POST['target_season'] ?? ($targets[0]['id'] ?? 0));
$target = null;
foreach ($seasons as $s) {
    if ((int) $s['id'] === $targetId) {
        $target = $s;
    }
}
$source = null;
if ($target) {
    foreach ($seasons as $s) {
        if ((string) $s['start_date'] < (string) $target['start_date'] && ($source === null || (string) $s['start_date'] > (string) $source['start_date'])) {
            $source = $s;
        }
    }
}

$notice = '';
$errors = [];

function board_renewal_link_url(array $link): string
{
    return !empty($link['slug']) ? stripe_public_base_url() . '/p/' . $link['slug'] : (string) ($link['url'] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $target && $source) {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'renew') {
            $ids = $_POST['agreement_ids'] ?? [];
            $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
            $done = 0;
            foreach ($ids as $aid) {
                $old = getSponsorshipAgreement($pdo, $aid);
                if (!$old || (int) $old['season_id'] !== (int) $source['id'] || (string) $old['status'] !== 'active' || !isset($boardById[(int) $old['package_id']])) {
                    continue;
                }
                $pkg = $boardById[(int) $old['package_id']];
                $renewal = isset($pkg['renewal_amount']) ? (float) $pkg['renewal_amount'] : 0.0;
                if ($renewal <= 0) {
                    $errors[] = $old['sponsor_name'] . ': no renewal price is set for ' . $pkg['name'] . '. Set it in Packages first.';
                    continue;
                }
                $exists = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE sponsor_id = :s AND package_id = :p AND season_id = :t AND status <> 'cancelled'");
                $exists->execute([':s' => $old['sponsor_id'], ':p' => $old['package_id'], ':t' => $target['id']]);
                if ((int) $exists->fetchColumn() > 0) {
                    continue; // already renewed
                }
                $r = saveSponsorshipAgreement($pdo, 0, [
                    'sponsor_id' => (string) $old['sponsor_id'], 'package_id' => (string) $old['package_id'], 'season_id' => (string) $target['id'],
                    'start_date' => (string) $target['start_date'], 'end_date' => (string) $target['end_date'],
                    'agreed_amount' => number_format($renewal, 2, '.', ''), 'status' => 'active', 'display_order' => (string) $old['display_order'],
                    'logo_variant' => (string) $old['logo_variant'],
                    'notes' => 'Board renewal of agreement #' . (int) $old['id'] . ' (' . $source['name'] . ') at the standard ' . $tier($pkg) . ' board renewal price.',
                ]);
                if ($r['errors']) {
                    $errors[] = $old['sponsor_name'] . ': ' . implode(' ', $r['errors']);
                } else {
                    $done++;
                    auditLog($pdo, 'board_renewal_created', 'Renewed board agreement #' . (int) $old['id'] . ' for ' . $old['sponsor_name'] . ' into ' . $target['name'] . ' at ' . gbp($renewal));
                }
            }
            if ($done > 0) {
                $notice = $done . ' board' . ($done === 1 ? '' : 's') . ' renewed for ' . $target['name'] . '.';
            } elseif (!$errors) {
                $notice = 'Nothing to renew from that selection.';
            }
        } elseif ($action === 'send_link') {
            $agreement = getSponsorshipAgreement($pdo, (int) ($_POST['agreement_id'] ?? 0));
            $pkg = $agreement ? ($boardById[(int) $agreement['package_id']] ?? null) : null;
            if (!$agreement || !$pkg || (int) $agreement['season_id'] !== (int) $target['id']) {
                $errors[] = 'That renewal could not be found.';
            } else {
                $standard = (float) ($pkg['renewal_amount'] ?? 0);
                $agreed = (float) $agreement['agreed_amount'];
                if (abs($standard - $agreed) > 0.004 && empty($_POST['override_price'])) {
                    $errors[] = $agreement['sponsor_name'] . ': the agreed price (' . gbp($agreed) . ') is not the standard renewal price for a ' . $tier($pkg) . ' board (' . gbp($standard) . '). Tick "Send at the agreed price" if that is intended.';
                } else {
                    try {
                        $base = stripe_public_base_url() . '/admin/sponsorship_agreement.php?id=' . (int) $agreement['id'];
                        $user = hub_auth_current_user();
                        $link = stripe_create_checkout_session_for_agreement($pdo, $agreement, stripe_agreement_success_url(), $base . '&stripe=cancelled', (int) ($user['id'] ?? 0) ?: null);
                        $url = board_renewal_link_url($link);
                        $sponsor = $pdo->prepare('SELECT name, contact_email FROM sponsors WHERE id = :id');
                        $sponsor->execute([':id' => $agreement['sponsor_id']]);
                        $sp = $sponsor->fetch(PDO::FETCH_ASSOC) ?: [];
                        $emailed = false;
                        if (!empty($_POST['email_it']) && !empty($sp['contact_email'])) {
                            $emailed = stripe_send_payment_link_email((string) $sp['contact_email'], (string) $sp['name'], $agreement, $link);
                        }
                        auditLog($pdo, 'board_renewal_link_created', 'Renewal payment link of ' . gbp((float) $link['amount']) . ' for agreement #' . (int) $agreement['id'] . ' (' . $sp['name'] . ')' . ($emailed ? ', emailed' : ''));
                        $notice = 'Payment link for ' . $sp['name'] . ' (' . gbp((float) $link['amount']) . ')' . ($emailed ? ' emailed to ' . $sp['contact_email'] . '.' : ' created: ' . $url);
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }
    }
}

$rows = [];
if ($target && $source) {
    $targetAgreements = [];
    foreach (getSponsorshipAgreements($pdo, ['season_id' => (int) $target['id']]) as $a) {
        if (isset($boardById[(int) $a['package_id']]) && (string) $a['status'] !== 'cancelled') {
            $targetAgreements[(int) $a['sponsor_id'] . ':' . (int) $a['package_id']] = $a;
        }
    }
    foreach (getSponsorshipAgreements($pdo, ['season_id' => (int) $source['id']]) as $a) {
        if (!isset($boardById[(int) $a['package_id']]) || (string) $a['status'] !== 'active') {
            continue;
        }
        $rows[] = ['old' => $a, 'pkg' => $boardById[(int) $a['package_id']], 'new' => $targetAgreements[(int) $a['sponsor_id'] . ':' . (int) $a['package_id']] ?? null];
    }
    usort($rows, static fn(array $x, array $y): int => strcasecmp((string) $x['old']['sponsor_name'], (string) $y['old']['sponsor_name']));
}
?>
<?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

<div class="card shadow-sm border-0 mb-3"><div class="card-body">
  <div class="row g-3 align-items-center">
    <div class="col-lg-7">
      <h2 class="h6 mb-2">Standard renewal prices</h2>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($boardPackages as $p): if ((string) $p['code'] === 'large_board_sponsor') { continue; } ?>
          <span class="badge text-bg-light border fs-6 fw-normal"><strong><?= h($tier($p)) ?></strong> <?= h($size($p)) ?>: <?= isset($p['renewal_amount']) && (float) $p['renewal_amount'] > 0 ? '<strong>' . h(gbp((float) $p['renewal_amount'])) . '</strong>' : '<span class="text-danger">not set</span>' ?></span>
        <?php endforeach; ?>
      </div>
      <div class="small text-muted mt-2">Set in Sponsorship &rarr; Packages (&ldquo;Renewal price&rdquo;). Never shown on the public website.</div>
    </div>
    <div class="col-lg-5">
      <form method="get" class="d-flex gap-2 align-items-end">
        <div class="flex-grow-1"><label class="form-label small mb-1">Renew into</label>
          <select class="form-select" name="season" onchange="this.form.submit()">
            <?php foreach ($targets as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $targetId ? 'selected' : '' ?>><?= h((string) $s['name']) ?></option><?php endforeach; ?>
            <?php if (!$targets): ?><option>No future season created yet</option><?php endif; ?>
          </select></div>
      </form>
    </div>
  </div>
</div></div>

<?php if (!$target || !$source): ?>
  <div class="alert alert-warning">Create next season first (Admin &rarr; Seasons). Renewals are made from this season&rsquo;s board agreements into the new season.</div>
<?php else: ?>
<form method="post" id="renewForm"><?= csrf_field() ?><input type="hidden" name="target_season" value="<?= (int) $target['id'] ?>"></form>
<div>
  <div class="card shadow-sm border-0">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <strong>Boards from <?= h((string) $source['name']) ?> renewing into <?= h((string) $target['name']) ?></strong>
      <button class="btn btn-brand btn-sm" type="submit" form="renewForm" name="action" value="renew" onclick="return confirm('Renew every ticked board at its standard renewal price?');">Renew selected</button>
    </div>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th style="width:2rem"><input type="checkbox" id="selAll" aria-label="Select all"></th><th>Sponsor</th><th>Board</th><th>This season</th><th class="text-end">Renewal price</th><th><?= h((string) $target['name']) ?></th><th class="text-end">Payment link</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No active board agreements in <?= h((string) $source['name']) ?>.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $old = $r['old']; $pkg = $r['pkg']; $new = $r['new'];
          $renewal = (float) ($pkg['renewal_amount'] ?? 0);
          $oldAgreed = (float) $old['agreed_amount']; $oldPaid = (float) $old['total_paid']; ?>
        <tr>
          <td><?php if (!$new && $renewal > 0): ?><input type="checkbox" name="agreement_ids[]" form="renewForm" value="<?= (int) $old['id'] ?>" class="rowSel"><?php endif; ?></td>
          <td><?= h((string) $old['sponsor_name']) ?></td>
          <td><?= h($tier($pkg)) ?> <span class="small text-muted"><?= h($size($pkg)) ?></span></td>
          <td class="small"><?= h(gbp($oldAgreed)) ?> agreed, <?= h(gbp($oldPaid)) ?> paid
            <?php if ($oldAgreed <= 0): ?><br><span class="badge text-bg-warning">Agreed value £0 — check before renewing</span>
            <?php elseif ($oldPaid + 0.004 < $oldAgreed): ?><br><span class="badge text-bg-warning">Not fully paid this season</span><?php endif; ?></td>
          <td class="text-end fw-bold"><?= $renewal > 0 ? h(gbp($renewal)) : '<span class="text-danger">not set</span>' ?></td>
          <td class="small">
            <?php if (!$new): ?><span class="text-muted">Not renewed yet</span>
            <?php else: $nAgreed = (float) $new['agreed_amount']; $nPaid = (float) $new['total_paid']; ?>
              <span class="badge text-bg-success">Renewed</span> <?= h(gbp($nAgreed)) ?>, <?= h(gbp($nPaid)) ?> paid
              <?php if (abs($nAgreed - $renewal) > 0.004): ?><br><span class="badge text-bg-danger">Price differs from standard <?= h(gbp($renewal)) ?></span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($new && $nAgreed - $nPaid > 0.004): $differs = abs($nAgreed - $renewal) > 0.004; ?>
              <form method="post" class="d-inline-block text-start" style="min-width:210px"><?= csrf_field() ?><input type="hidden" name="target_season" value="<?= (int) $target['id'] ?>">
                <input type="hidden" name="action" value="send_link"><input type="hidden" name="agreement_id" value="<?= (int) $new['id'] ?>">
                <div class="form-check small"><input class="form-check-input" type="checkbox" name="email_it" value="1" id="em<?= (int) $new['id'] ?>" checked><label class="form-check-label" for="em<?= (int) $new['id'] ?>">Email it (if they have an email)</label></div>
                <?php if ($differs): ?><div class="form-check small text-danger"><input class="form-check-input" type="checkbox" name="override_price" value="1" id="ov<?= (int) $new['id'] ?>"><label class="form-check-label" for="ov<?= (int) $new['id'] ?>">Send at the agreed price (<?= h(gbp($nAgreed)) ?>)</label></div><?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary mt-1" type="submit">Create link for <?= h(gbp($nAgreed - $nPaid)) ?></button>
              </form>
            <?php elseif ($new): ?><span class="small text-muted">Paid</span><?php else: ?><span class="small text-muted">Renew first</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
</div>
<?php endif; ?>
<script>document.getElementById('selAll')?.addEventListener('change', function () { document.querySelectorAll('.rowSel').forEach(function (c) { c.checked = this.checked; }, this); });</script>
<?php require __DIR__ . '/footer.php'; ?>
