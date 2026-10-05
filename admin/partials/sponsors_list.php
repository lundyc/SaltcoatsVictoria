<?php
function sponsorListActions(array $s): void { ?>
  <div class="btn-group" role="group" aria-label="Actions for <?= h($s['name']) ?>">
    <a href="/admin/sponsor.php?action=edit&amp;id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit sponsor" aria-label="Edit <?= h($s['name']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
    <?php if (hub_auth_has_capability('sponsorship')): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-sponsor-archive="<?= (int)$s['id'] ?>" data-active="<?= (int)$s['is_active'] ?>" data-name="<?= h($s['name']) ?>" title="<?= $s['is_active'] ? 'Archive sponsor' : 'Restore sponsor' ?>" aria-label="<?= h(($s['is_active'] ? 'Archive ' : 'Restore ') . $s['name']) ?>"><i class="fa-solid <?= $s['is_active'] ? 'fa-box-archive' : 'fa-rotate-left' ?>" aria-hidden="true"></i></button><?php endif; ?>
  </div>
<?php }
function sponsorListFacebook(array $s, bool $locked, bool $mobile = false): void { ?>
  <label class="form-check form-switch sponsor-facebook-toggle mb-0" title="Advertised on Facebook">
    <input class="form-check-input" type="checkbox" role="switch" data-facebook-spotlight-toggle data-sponsor-id="<?= (int)$s['id'] ?>" aria-label="Advertised on Facebook for <?= h($s['name']) ?>" <?= $s['facebook_spotlight_posted'] ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
    <?php if ($mobile): ?><span class="small text-muted">Facebook</span><?php endif; ?>
  </label>
<?php }
?>
<div id="sponsorsListNotice" class="alert" role="status" hidden></div>
<div id="sponsorsListEmpty" class="alert alert-info" <?= $visibleSponsors ? 'hidden' : '' ?>>No sponsors match the current filters.</div>
<div class="d-xl-none d-flex flex-column gap-2 mb-3">
<?php foreach ($visibleSponsors as $s): ?>
  <?php $state = sponsorPaymentState($s); $outstanding = max(0, (float)$s['total_amount'] - (float)$s['total_paid']); ?>
  <article class="sponsors-mobile-card hub-record-card sponsor-list-record" data-list-sponsor="<?= (int)$s['id'] ?>" data-sponsor-href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>" tabindex="0" aria-label="View <?= h($s['name']) ?>">
    <div class="d-flex justify-content-between align-items-start gap-3">
      <div><a class="sponsor-ledger-name" href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a><div class="small text-muted mt-1"><?= (int)$s['agreement_count'] ?> agreement<?= (int)$s['agreement_count'] === 1 ? '' : 's' ?> <span data-sponsor-archived <?= $s['is_active'] ? 'hidden' : '' ?>>· Archived</span></div></div>
      <?php sponsorListActions($s); ?>
    </div>
    <div class="d-flex justify-content-between align-items-center gap-2 mt-3">
      <span class="badge hub-status <?= h($state['badge']) ?>"><?= h($state['label']) ?></span>
      <div class="text-end"><span class="small text-muted me-1">Outstanding</span><strong><?= gbp($outstanding) ?></strong></div>
    </div>
    <div class="mt-3"><?php sponsorListFacebook($s, $seasonLocked, true); ?></div>
  </article>
<?php endforeach; ?>
</div>
<div class="card sponsors-section-card border-0 shadow-sm d-none d-xl-block hub-table-card" id="sponsorsTableCard" <?= !$visibleSponsors ? 'hidden' : '' ?>>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-modern table-hover hub-data-table align-middle mb-0 sponsor-list-table" id="sponsorsLedgerTable">
      <caption class="visually-hidden">Sponsors, agreement counts, outstanding balances and payment status. Select a row to view the sponsor.</caption>
      <thead><tr>
        <th data-sort-key="sponsor" data-sort-type="text"><button type="button" class="sponsor-sort-button">Sponsor <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
        <th class="text-center" data-sort-key="portfolio" data-sort-type="number"><button type="button" class="sponsor-sort-button sponsor-sort-button--center">Agreements <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
        <th class="text-end" data-sort-key="outstanding" data-sort-type="number"><button type="button" class="sponsor-sort-button sponsor-sort-button--end">Outstanding <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
        <th data-sort-key="payment" data-sort-type="number"><button type="button" class="sponsor-sort-button">Status <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
        <th class="text-center" data-sort-key="facebook" data-sort-type="number"><button type="button" class="sponsor-sort-button sponsor-sort-button--center">Facebook <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
        <th class="text-end" data-export-ignore="1"><span class="visually-hidden">Actions</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($visibleSponsors as $s): ?>
        <?php $state = sponsorPaymentState($s); $outstanding = max(0, (float)$s['total_amount'] - (float)$s['total_paid']); $paymentSortOrder = ['unpaid' => 0, 'partial' => 1, 'paid' => 2, 'complimentary' => 3, 'nocharge' => 4, 'noagreements' => 5]; ?>
        <tr class="sponsor-list-record" data-list-sponsor="<?= (int)$s['id'] ?>" data-sponsor-href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>" tabindex="0" aria-label="View <?= h($s['name']) ?>" data-sort-sponsor="<?= h(strtolower($s['name'])) ?>" data-sort-portfolio="<?= (int)$s['agreement_count'] ?>" data-sort-outstanding="<?= h((string)$outstanding) ?>" data-sort-payment="<?= (int)($paymentSortOrder[$state['key']] ?? 99) ?>" data-sort-facebook="<?= (int)$s['facebook_spotlight_posted'] ?>">
          <td><a class="sponsor-ledger-name" href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a><span class="small text-muted ms-2" data-sponsor-archived <?= $s['is_active'] ? 'hidden' : '' ?>>Archived</span></td>
          <td class="text-center"><?= (int)$s['agreement_count'] ?></td>
          <td class="text-end fw-semibold text-nowrap"><?= gbp($outstanding) ?></td>
          <td><span class="badge hub-status <?= h($state['badge']) ?>"><?= h($state['label']) ?></span></td>
          <td class="text-center sponsor-facebook-cell"><?php sponsorListFacebook($s, $seasonLocked); ?></td>
          <td class="text-end text-nowrap" data-export-ignore="1"><?php sponsorListActions($s); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>
<style>
  .sponsor-list-record { cursor: pointer; }
  .sponsor-list-record:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: -2px; }
  .sponsor-list-table td { padding: .8rem 1rem; }
  .sponsor-list-table td:first-child { width: 40%; }
  .sponsor-list-table .badge { white-space: nowrap; }
  .sponsor-list-record .btn { min-width: 2.2rem; }
  .sponsor-list-record .sponsor-ledger-name { font-size: .9rem; }
  .sponsor-list-table td:last-child { width: 6rem; }
  #sponsorsTableCard[hidden] { display: none !important; }
</style>
