<?php
$pageHero=[
 'eyebrow'=>'Sponsorship management','title'=>'Stripe Dashboard','subtitle'=>'Transaction history, payment link status, and refunds — without logging into Stripe.',
 'actions'=>[],
];
require_once __DIR__.'/header.php';

if (!hub_auth_has_capability('finance_view')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view this page.</div></div>';
    require __DIR__.'/footer.php';
    exit;
}

require_once __DIR__.'/lib/sponsorship_catalog.php';
require_once __DIR__.'/lib/stripe.php';
ensureSponsorshipCatalogSchema($pdo);
ensureStripeSchema($pdo);

$filters=[
 'status'=>trim((string)($_GET['status']??'')),
 'season_id'=>(int)($_GET['season_id']??0),
 'from'=>trim((string)($_GET['from']??'')),
 'to'=>trim((string)($_GET['to']??'')),
];
$transactions=stripe_get_all_transactions($pdo,$filters);
$allSummary=stripe_all_payments_summary($pdo,$filters);
$seasons=$pdo->query('SELECT id,name FROM seasons ORDER BY start_date DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$statusLabels=['succeeded'=>'Paid','refunded'=>'Refunded','partially_refunded'=>'Partially refunded'];
$statusTones=['succeeded'=>'text-bg-success','refunded'=>'text-bg-secondary','partially_refunded'=>'text-bg-warning'];
?>
<?php if(!stripe_is_configured()): ?><div class="alert alert-warning">Stripe is not configured yet. Add a secret key under <a href="settings.php?tab=payments">Settings &gt; Payments</a> to start generating payment links.</div><?php endif; ?>
<?php hub_render_metric_grid([
 ['label'=>'All Stripe collected','value'=>gbp($allSummary['collected']),'meta'=>$allSummary['payments'].' payment'.((int)$allSummary['payments']===1?'':'s').' in this view','icon'=>'fa-sterling-sign','tone'=>'success'],
 ['label'=>'All Stripe refunded','value'=>gbp($allSummary['refunded']),'meta'=>'Across tickets, shop and sponsorships','icon'=>'fa-rotate-left','tone'=>'danger'],
], 'Stripe summary'); ?>
<div class="hub-section-commandbar">
 <div><h2>Transaction history</h2><p>All Stripe payments recorded in Hub, including season tickets, match tickets, shop orders and sponsorships.</p></div>
</div>
<form class="card hub-list-card hub-form-card mb-4" method="get" aria-label="Filter Stripe transactions"><div class="card-body"><div class="row g-2">
 <div class="col-md-3"><label class="form-label" for="stripeStatusFilter">Status</label><select class="form-select" id="stripeStatusFilter" name="status"><option value="">All statuses</option><?php foreach($statusLabels as $v=>$l): ?><option value="<?= h($v) ?>" <?= $filters['status']===$v?'selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-3"><label class="form-label" for="stripeSeasonFilter">Season</label><select class="form-select" id="stripeSeasonFilter" name="season_id"><option value="">All seasons</option><?php foreach($seasons as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $filters['season_id']==(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-2"><label class="form-label" for="stripeFromFilter">From</label><input type="date" class="form-control" id="stripeFromFilter" name="from" value="<?= h($filters['from']) ?>"></div>
 <div class="col-md-2"><label class="form-label" for="stripeToFilter">To</label><input type="date" class="form-control" id="stripeToFilter" name="to" value="<?= h($filters['to']) ?>"></div>
 <div class="col-md-2 d-grid"><button class="btn btn-brand">Filter</button></div>
</div></div></form>
<div class="card hub-list-card hub-table-card"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle hub-data-table hub-data-table--responsive"><caption class="visually-hidden">Stripe transactions</caption>
 <thead><tr><th>Date</th><th>Source</th><th>Customer</th><th>Details</th><th>Amount</th><th>Status</th><th>Record</th><th></th></tr></thead><tbody>
 <?php foreach($transactions as $t): ?>
 <tr>
  <td data-label="Date"><?= h(date('d/m/Y H:i',strtotime((string)$t['created_at']))) ?></td>
  <td data-label="Source"><span class="badge text-bg-light"><?= h((string)$t['source']) ?></span></td>
  <td data-label="Customer" class="fw-semibold"><?= h((string)$t['customer_name']) ?><?php if(!empty($t['customer_email'])): ?><div class="small text-muted"><?= h((string)$t['customer_email']) ?></div><?php endif; ?></td>
  <td data-label="Details"><?= h((string)$t['description']) ?></td>
  <td data-label="Amount"><?= gbp((float)$t['amount']) ?><?php if((float)$t['refunded_amount']>0): ?><div class="small text-muted"><?= gbp((float)$t['refunded_amount']) ?> refunded</div><?php endif; ?></td>
  <td data-label="Status"><span class="badge hub-status <?= h($statusTones[$t['status']]??'text-bg-light') ?>"><?= h($statusLabels[$t['status']]??ucfirst((string)$t['status'])) ?></span></td>
  <td data-label="Record"><?php if(!empty($t['manage_url'])): ?><a href="<?= h((string)$t['manage_url']) ?>">Manage</a><?php else: ?>&mdash;<?php endif; ?></td>
  <td data-label="Actions" class="text-end">
   <?php if(hub_auth_has_capability('finance_manage')&&($t['source']??'')==='Sponsorship'&&!empty($t['local_transaction_id'])&&(float)$t['refunded_amount']<(float)$t['amount']-0.0001): ?>
   <button type="button" class="btn btn-sm btn-outline-danger stripe-refund-btn"
     data-transaction-id="<?= (int)$t['local_transaction_id'] ?>"
     data-remaining="<?= h(number_format((float)$t['amount']-(float)$t['refunded_amount'],2,'.','')) ?>"
     data-sponsor="<?= h((string)$t['customer_name']) ?>">Refund</button>
   <?php endif; ?>
  </td>
 </tr>
 <?php endforeach; ?>
 <?php if(!$transactions): ?><tr><td colspan="8" class="hub-record-empty hub-empty-state">No Stripe transactions match these filters.</td></tr><?php endif; ?>
 </tbody></table></div></div></div>

<?php if(hub_auth_has_capability('finance_manage')): ?>
<div class="modal fade" id="stripeRefundModal" tabindex="-1" aria-labelledby="stripeRefundModalTitle" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered">
  <div class="modal-content">
   <div class="modal-header"><h2 class="modal-title h5" id="stripeRefundModalTitle">Refund payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
   <div class="modal-body">
    <p class="mb-3" id="stripeRefundSponsorLabel"></p>
    <div class="mb-3"><label class="form-label" for="stripeRefundAmount">Amount</label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0.01" step="0.01" class="form-control" id="stripeRefundAmount"></div><div class="form-text">Leave as the full remaining amount, or reduce it for a partial refund.</div></div>
    <div class="mb-0"><label class="form-label" for="stripeRefundReason">Reason (optional)</label><input type="text" class="form-control" id="stripeRefundReason" maxlength="255"></div>
    <div id="stripeRefundStatus" class="small mt-2"></div>
   </div>
   <div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-danger" id="stripeRefundConfirmBtn">Issue refund</button>
   </div>
  </div>
 </div>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{
  const modalEl=document.getElementById('stripeRefundModal');
  if(!modalEl||typeof bootstrap==='undefined')return;
  const modal=new bootstrap.Modal(modalEl);
  const amountInput=document.getElementById('stripeRefundAmount');
  const reasonInput=document.getElementById('stripeRefundReason');
  const sponsorLabel=document.getElementById('stripeRefundSponsorLabel');
  const statusEl=document.getElementById('stripeRefundStatus');
  const confirmBtn=document.getElementById('stripeRefundConfirmBtn');
  const csrf='<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>';
  let currentTransactionId=null;

  document.querySelectorAll('.stripe-refund-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
      currentTransactionId=btn.dataset.transactionId;
      amountInput.value=btn.dataset.remaining;
      amountInput.max=btn.dataset.remaining;
      reasonInput.value='';
      statusEl.textContent='';
      sponsorLabel.textContent='Refunding a payment from '+btn.dataset.sponsor+'.';
      modal.show();
    });
  });

  confirmBtn.addEventListener('click',async()=>{
    if(!currentTransactionId)return;
    confirmBtn.disabled=true;
    const original=confirmBtn.textContent;
    confirmBtn.textContent='Refunding…';
    statusEl.className='small mt-2';
    statusEl.textContent='';
    try{
      const response=await fetch('stripe_refund.php',{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:'transaction_id='+encodeURIComponent(currentTransactionId)+'&amount='+encodeURIComponent(amountInput.value)+'&reason='+encodeURIComponent(reasonInput.value)+'&csrf_token='+encodeURIComponent(csrf)
      });
      const json=await response.json().catch(()=>null);
      if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not issue the refund.');}
      window.location.reload();
    }catch(error){
      statusEl.className='small mt-2 text-danger';
      statusEl.textContent=error.message||'Could not issue the refund.';
    }finally{
      confirmBtn.disabled=false;
      confirmBtn.textContent=original;
    }
  });
});</script>
<?php endif; ?>
<?php require_once __DIR__.'/footer.php'; ?>
