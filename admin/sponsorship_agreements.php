<?php
$pageHero=[
 'eyebrow'=>'Sponsorship management','title'=>'Sponsorship Agreements','subtitle'=>'The single view of who sponsors what, for which period, and how much is due.',
 'actions'=>[],
];
require_once __DIR__.'/header.php';
require_once __DIR__.'/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);
$filters=['status'=>trim((string)($_GET['status']??'')),'sponsor_id'=>(int)($_GET['sponsor_id']??0),'package_id'=>(int)($_GET['package_id']??0),'season_id'=>(int)($_GET['season_id']??0)];
$agreements=getSponsorshipAgreements($pdo,$filters);
$sponsors=$pdo->query('SELECT id,name FROM sponsors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$packages=getSponsorshipPackages($pdo);
$seasons=$pdo->query('SELECT id,name FROM seasons ORDER BY start_date DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$totals=['value'=>0.0,'paid'=>0.0,'active'=>0,'complimentary'=>0]; foreach($agreements as $row){if(!empty($row['is_complimentary'])){$totals['complimentary']++;}else{$totals['value']+=(float)$row['agreed_amount'];$totals['paid']+=(float)$row['total_paid'];}if($row['effective_status']==='active')$totals['active']++;}
$formatUkDate=function(?string $value,string $fallback): string {
 if(!$value)return $fallback;
 $timestamp=strtotime($value);
 if($timestamp===false)return $value;
 return date(strlen($value)>10?'d/m/Y H:i':'d/m/Y',$timestamp);
};
?>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Agreement saved.</div><?php endif; ?>
<?php if(isset($_GET['deleted'])): ?><div class="alert alert-success">Agreement deleted.</div><?php endif; ?>
<?php if(isset($_GET['bulk_done'])): $bulkDone=(int)$_GET['bulk_done'];$bulkFailed=(int)($_GET['bulk_failed']??0);$bulkAction=(string)($_GET['bulk_action']??''); ?>
<div class="alert <?= $bulkDone>0?'alert-success':'alert-warning' ?>"><?= (int)$bulkDone ?> agreement<?= $bulkDone===1?'':'s' ?> <?= $bulkAction==='delete'?'deleted':'cancelled' ?>.<?php if($bulkFailed>0): ?> <?= $bulkFailed ?> couldn't be processed (see agreement notes if a payment conflict blocked it).<?php endif; ?></div>
<?php endif; ?>
<?php hub_render_metric_grid([
 ['label' => 'Results', 'value' => count($agreements), 'meta' => 'In current filters', 'icon' => 'fa-list', 'tone' => 'primary'],
 ['label' => 'Active', 'value' => $totals['active'], 'meta' => 'Current agreements', 'icon' => 'fa-circle-check', 'tone' => 'success'],
 ['label' => 'Complimentary', 'value' => $totals['complimentary'], 'meta' => 'No payment required', 'icon' => 'fa-gift', 'tone' => 'info'],
 ['label' => 'Agreement value', 'value' => gbp($totals['value']), 'meta' => 'Total contracted value', 'icon' => 'fa-sterling-sign', 'tone' => 'primary'],
 ['label' => 'Outstanding', 'value' => gbp(max(0,$totals['value']-$totals['paid'])), 'meta' => 'Still to collect', 'icon' => 'fa-clock', 'tone' => 'danger'],
], 'Agreement summary'); ?>
<div class="hub-section-commandbar">
 <div><h2>Agreement directory</h2><p>Filter contracts by sponsor, package, season or status.</p></div>
 <div class="hub-local-actions"><a class="btn btn-outline-secondary btn-sm" href="sponsorship_packages.php">Packages</a><a class="btn btn-brand btn-sm" href="sponsorship_agreement.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add agreement</a></div>
</div>
<form class="card hub-list-card hub-form-card mb-4" method="get" aria-label="Filter sponsorship agreements"><div class="card-body"><div class="row g-2">
 <div class="col-md-3"><label class="form-label" for="agreementStatus">Status</label><select class="form-select" id="agreementStatus" name="status"><option value="">All statuses</option><?php foreach(['active'=>'Active','scheduled'=>'Scheduled','expired'=>'Expired','cancelled'=>'Cancelled'] as $v=>$l): ?><option value="<?= $v ?>" <?= $filters['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
 <div class="col-md-3"><label class="form-label" for="agreementSponsor">Sponsor</label><select class="form-select" id="agreementSponsor" name="sponsor_id"><option value="">All sponsors</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $filters['sponsor_id']==(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-3"><label class="form-label" for="agreementPackage">Package</label><select class="form-select" id="agreementPackage" name="package_id"><option value="">All packages</option><?php foreach($packages as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $filters['package_id']==(int)$p['id']?'selected':'' ?>><?= h((string)$p['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-2"><label class="form-label" for="agreementSeason">Season</label><select class="form-select" id="agreementSeason" name="season_id"><option value="">All seasons</option><?php foreach($seasons as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $filters['season_id']==(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-1 d-grid"><button class="btn btn-brand">Filter</button></div>
</div></div></form>
<form method="post" action="sponsorship_agreement_bulk.php" id="bulkAgreementsForm">
<?= csrf_field() ?>
<div class="card hub-list-card mb-2 hub-bulk-actions-bar">
 <div class="card-body py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
  <div class="small text-muted"><span id="bulkSelectedCount">0</span> selected — tick agreements below to cancel or delete several at once (e.g. clearing out an old sponsor's rows before adding a replacement).</div>
  <div class="hub-actions">
   <button type="submit" class="btn btn-sm btn-outline-secondary" name="bulk_action" value="cancel" id="bulkCancelBtn" disabled data-confirm="Cancel the selected agreements? They stay in the system as history (status = Cancelled) but stop counting as active — reversible from each agreement's page." data-confirm-title="Cancel selected agreements?" data-confirm-action="Cancel selected">Cancel selected</button>
   <button type="submit" class="btn btn-sm btn-outline-danger" name="bulk_action" value="delete" id="bulkDeleteBtn" disabled data-confirm="Permanently delete the selected agreements and any payments recorded against them? This cannot be undone." data-confirm-title="Delete selected agreements?" data-confirm-action="Delete selected">Delete selected</button>
  </div>
 </div>
</div>
<div class="card hub-list-card hub-table-card"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle hub-data-table hub-data-table--responsive"><caption class="visually-hidden">Filtered sponsorship agreements</caption>
 <thead><tr><th style="width:36px"><input type="checkbox" class="form-check-input" id="bulkSelectAllHeader" aria-label="Select all"></th><th>Sponsor</th><th>Package</th><th>Applies to</th><th>Dates</th><th>Value</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach($agreements as $a): ?><?php $target=$a['season_name']?:'Club-wide'; if($a['fixture_id'])$target='Fixture #'.$a['fixture_id'].' · '.($a['fixture_opponent']??''); elseif($a['player_id'])$target=$a['player_name']??'Player'; ?>
 <tr><td><input type="checkbox" class="form-check-input agreement-bulk-checkbox" name="agreement_ids[]" value="<?= (int)$a['id'] ?>" aria-label="Select agreement #<?= (int)$a['id'] ?>"></td><td data-label="Sponsor" class="fw-semibold"><a href="sponsor.php?id=<?= (int)$a['sponsor_id'] ?>"><?= h((string)$a['sponsor_name']) ?></a></td><td data-label="Package"><div><?= h((string)$a['package_name']) ?></div><span class="badge text-bg-light"><?= h((string)$a['package_category']) ?></span></td><td data-label="Applies to"><?= h((string)$target) ?></td><td data-label="Dates" class="text-nowrap"><?= h($formatUkDate($a['start_date']??null,'Open')) ?> → <?= h($formatUkDate($a['end_date']??null,'Ongoing')) ?></td><td data-label="Value"><?php if(!empty($a['is_complimentary'])): ?><span class="badge hub-status text-bg-info">Complimentary</span><?php else: ?><?= gbp((float)$a['agreed_amount']) ?><div class="small text-muted"><?= gbp((float)$a['total_paid']) ?> paid</div><?php endif; ?></td><td data-label="Status"><span class="badge hub-status <?= $a['effective_status']==='active'?'text-bg-success':($a['effective_status']==='scheduled'?'text-bg-info':'text-bg-secondary') ?>"><?= h(ucfirst((string)$a['effective_status'])) ?></span></td><td data-label="Actions" class="text-end"><div class="hub-actions hub-actions--end"><a class="btn btn-sm btn-outline-primary" href="sponsorship_agreement.php?id=<?= (int)$a['id'] ?>">Manage</a></div></td></tr>
 <?php endforeach; ?><?php if(!$agreements): ?><tr><td colspan="8" class="hub-record-empty hub-empty-state">No agreements match these filters. Try broadening the filters or add a new agreement.</td></tr><?php endif; ?>
 </tbody></table></div></div></div>
</form>
<script>(()=>{
 const boxes=()=>[...document.querySelectorAll('.agreement-bulk-checkbox')];
 const countEl=document.getElementById('bulkSelectedCount');
 const cancelBtn=document.getElementById('bulkCancelBtn');
 const deleteBtn=document.getElementById('bulkDeleteBtn');
 const headerBox=document.getElementById('bulkSelectAllHeader');
 const sync=()=>{
  const n=boxes().filter(b=>b.checked).length;
  countEl.textContent=n;
  cancelBtn.disabled=n===0;
  deleteBtn.disabled=n===0;
 };
 headerBox?.addEventListener('change',()=>{boxes().forEach(b=>b.checked=headerBox.checked);sync();});
 boxes().forEach(b=>b.addEventListener('change',sync));
 sync();
})();</script>
<?php require_once __DIR__.'/footer.php'; ?>
