<?php
$pageHero=[
 'eyebrow'=>'Sponsorship management','title'=>'Sponsorship Bundles','subtitle'=>'Group several agreements for one sponsor so they can be paid with a single Stripe link.',
 'actions'=>[],
];
require_once __DIR__.'/header.php';
require_once __DIR__.'/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);
$filters=['status'=>trim((string)($_GET['status']??'')),'sponsor_id'=>(int)($_GET['sponsor_id']??0)];
$bundles=getSponsorshipBundles($pdo,$filters);
$sponsors=$pdo->query('SELECT id,name FROM sponsors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$totals=['value'=>0.0,'paid'=>0.0,'items'=>0,'active'=>0];
foreach($bundles as $row){
 $totals['value']+=(float)$row['agreed_total'];
 $totals['paid']+=(float)$row['paid_total'];
 $totals['items']+=(int)$row['item_count'];
 if((string)$row['status']==='active')$totals['active']++;
}
?>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Bundle saved.</div><?php endif; ?>
<?php if(isset($_GET['deleted'])): ?><div class="alert alert-success">Bundle deleted. Its member agreements were kept, just no longer grouped.</div><?php endif; ?>
<?php hub_render_metric_grid([
 ['label' => 'Bundles', 'value' => count($bundles), 'meta' => 'In current filters', 'icon' => 'fa-boxes-stacked', 'tone' => 'primary'],
 ['label' => 'Active', 'value' => $totals['active'], 'meta' => 'Currently active', 'icon' => 'fa-circle-check', 'tone' => 'success'],
 ['label' => 'Items grouped', 'value' => $totals['items'], 'meta' => 'Agreements across all bundles', 'icon' => 'fa-list', 'tone' => 'info'],
 ['label' => 'Bundle value', 'value' => gbp($totals['value']), 'meta' => 'Total contracted value', 'icon' => 'fa-sterling-sign', 'tone' => 'primary'],
 ['label' => 'Outstanding', 'value' => gbp(max(0,$totals['value']-$totals['paid'])), 'meta' => 'Still to collect', 'icon' => 'fa-clock', 'tone' => 'danger'],
], 'Bundle summary'); ?>
<div class="hub-section-commandbar">
 <div><h2>Bundle directory</h2><p>A sponsor taking multiple things — a board, several players, several MOTMs — grouped so one link collects for all of them.</p></div>
 <div class="hub-local-actions"><a class="btn btn-outline-secondary btn-sm" href="sponsorship_agreements.php">Agreements</a><a class="btn btn-brand btn-sm" href="sponsorship_bundle.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add bundle</a></div>
</div>
<form class="card hub-list-card hub-form-card mb-4" method="get" aria-label="Filter sponsorship bundles"><div class="card-body"><div class="row g-2">
 <div class="col-md-4"><label class="form-label" for="bundleStatus">Status</label><select class="form-select" id="bundleStatus" name="status"><option value="">All statuses</option><?php foreach(['active'=>'Active','archived'=>'Archived'] as $v=>$l): ?><option value="<?= $v ?>" <?= $filters['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label" for="bundleSponsor">Sponsor</label><select class="form-select" id="bundleSponsor" name="sponsor_id"><option value="">All sponsors</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $filters['sponsor_id']==(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-2 d-grid"><button class="btn btn-brand">Filter</button></div>
</div></div></form>
<div class="card hub-list-card hub-table-card"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle hub-data-table hub-data-table--responsive"><caption class="visually-hidden">Filtered sponsorship bundles</caption>
 <thead><tr><th>Sponsor</th><th>Bundle</th><th>Items</th><th>Value</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach($bundles as $b): ?>
 <tr><td data-label="Sponsor" class="fw-semibold"><a href="sponsor.php?id=<?= (int)$b['sponsor_id'] ?>"><?= h((string)$b['sponsor_name']) ?></a></td><td data-label="Bundle"><div><?= h((string)($b['name']?:('Bundle #'.$b['id']))) ?></div><?php if($b['season_name']): ?><span class="badge text-bg-light"><?= h((string)$b['season_name']) ?></span><?php endif; ?></td><td data-label="Items"><?= (int)$b['item_count'] ?></td><td data-label="Value"><?= gbp((float)$b['agreed_total']) ?><div class="small text-muted"><?= gbp((float)$b['paid_total']) ?> paid</div></td><td data-label="Status"><span class="badge hub-status <?= $b['status']==='active'?'text-bg-success':'text-bg-secondary' ?>"><?= h(ucfirst((string)$b['status'])) ?></span></td><td data-label="Actions" class="text-end"><div class="hub-actions hub-actions--end"><a class="btn btn-sm btn-outline-primary" href="sponsorship_bundle.php?id=<?= (int)$b['id'] ?>">Manage</a></div></td></tr>
 <?php endforeach; ?><?php if(!$bundles): ?><tr><td colspan="6" class="hub-record-empty hub-empty-state">No bundles match these filters. Try broadening the filters or add a new bundle.</td></tr><?php endif; ?>
 </tbody></table></div></div></div>
<?php require_once __DIR__.'/footer.php'; ?>
