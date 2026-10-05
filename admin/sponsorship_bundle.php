<?php
$id=(int)($_GET['id']??0);
$pageHero=['eyebrow'=>'Sponsorship management','title'=>$id?'Manage Bundle':'Add Bundle','subtitle'=>'Group several agreements for one sponsor so they can be paid with a single Stripe link.','actions'=>[]];
require_once __DIR__.'/header.php';
require_once __DIR__.'/lib/stripe.php';
ensureSponsorshipCatalogSchema($pdo);
ensureStripeSchema($pdo);
$bundle=$id?getSponsorshipBundle($pdo,$id):null;
if($id&&!$bundle){echo '<div class="alert alert-danger">Bundle not found.</div>';require __DIR__.'/footer.php';exit;}

$data=array_merge(['sponsor_id'=>0,'season_id'=>'','name'=>'','notes'=>'','status'=>'active'],$bundle?:[]);
$sponsors=$pdo->query('SELECT id,name FROM sponsors WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$seasons=$pdo->query('SELECT id,name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$errors=[];
$notice='';

if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check())$errors[]='Invalid security token.';
 $formAction=(string)($_POST['form_action']??'save_header');
 if(!$errors&&$formAction==='delete_bundle'){
  if(!$id)$errors[]='Bundle not found.';
  if(!$errors){try{$deletedBundleLabel=(string)($bundle['sponsor_name']??'').($bundle['name']?' — '.(string)$bundle['name']:'');deleteSponsorshipBundle($pdo,$id);auditLog($pdo,'sponsorship_bundle_deleted',"Deleted bundle #{$id} ({$deletedBundleLabel})");header('Location: sponsorship_bundles.php?deleted=1');exit;}catch(Throwable $e){$errors[]=$e->getMessage();}}
 } elseif(!$errors&&$formAction==='attach_existing'){
  $agreementId=(int)($_POST['agreement_id']??0);
  if(!$id||$agreementId<=0){$errors[]='Choose an agreement to attach.';}
  else{try{attachSponsorshipAgreementToBundle($pdo,$agreementId,$id);auditLog($pdo,'sponsorship_bundle_item_attached',"Attached agreement #{$agreementId} to bundle #{$id}");header('Location: sponsorship_bundle.php?id='.$id.'&attached=1');exit;}catch(Throwable $e){$errors[]=$e->getMessage();}}
 } elseif(!$errors&&$formAction==='detach_item'){
  $agreementId=(int)($_POST['agreement_id']??0);
  $member=$agreementId>0?getSponsorshipAgreement($pdo,$agreementId):null;
  if(!$member||(int)($member['bundle_id']??0)!==$id){$errors[]='That agreement is not part of this bundle.';}
  else{detachSponsorshipAgreementFromBundle($pdo,$agreementId);auditLog($pdo,'sponsorship_bundle_item_detached',"Detached agreement #{$agreementId} from bundle #{$id}");header('Location: sponsorship_bundle.php?id='.$id.'&detached=1');exit;}
 } elseif(!$errors&&$formAction==='delete_item'){
  $agreementId=(int)($_POST['agreement_id']??0);
  $member=$agreementId>0?getSponsorshipAgreement($pdo,$agreementId):null;
  if(!$member||(int)($member['bundle_id']??0)!==$id){$errors[]='That agreement is not part of this bundle.';}
  else{try{deleteSponsorshipAgreement($pdo,$agreementId);auditLog($pdo,'sponsorship_bundle_item_deleted',"Deleted agreement #{$agreementId} from bundle #{$id}");header('Location: sponsorship_bundle.php?id='.$id.'&item_deleted=1');exit;}catch(Throwable $e){$errors[]=$e->getMessage();}}
 } elseif(!$errors){
  foreach(['sponsor_id','season_id','name','notes','status'] as $f)$data[$f]=trim((string)($_POST[$f]??''));
  $wasNewBundle=$id===0;
  $result=saveSponsorshipBundle($pdo,$id,$data);
  if($result['errors']){$errors=$result['errors'];}
  else{
   $bundleSponsorLabel=(array_column($sponsors,'name','id'))[(int)$data['sponsor_id']]??('#'.$data['sponsor_id']);
   auditLog($pdo,$wasNewBundle?'sponsorship_bundle_created':'sponsorship_bundle_updated',($wasNewBundle?'Created':'Updated')." bundle #{$result['id']} for {$bundleSponsorLabel}");
   header('Location: sponsorship_bundle.php?id='.$result['id'].'&saved=1');exit;}
 }
}

$members=[];
$attachable=[];
$stripeSessions=[];
$outstandingTotal=0.0;
if($id>0&&$bundle){
 $members=getSponsorshipAgreements($pdo,['bundle_id'=>$id]);
 foreach($members as $m)$outstandingTotal+=stripe_agreement_outstanding_amount($m);

 $sponsorAgreements=getSponsorshipAgreements($pdo,['sponsor_id'=>(int)$bundle['sponsor_id']]);
 foreach($sponsorAgreements as $a){
  if(empty($a['bundle_id']))$attachable[]=$a;
 }

 if($members){
  $memberIds=array_map(fn($m)=>(int)$m['id'],$members);
  $placeholders=implode(',',array_fill(0,count($memberIds),'?'));
  $linksStmt=$pdo->prepare("SELECT * FROM stripe_payment_links WHERE agreement_id IN ($placeholders) ORDER BY created_at DESC");
  $linksStmt->execute($memberIds);
  foreach($linksStmt->fetchAll(PDO::FETCH_ASSOC) as $link){
   $sid=(string)$link['stripe_checkout_session_id'];
   if(!isset($stripeSessions[$sid])){
    $stripeSessions[$sid]=['session_id'=>$sid,'url'=>stripe_payment_link_public_url($link),'status'=>$link['status'],'created_at'=>$link['created_at'],'expires_at'=>$link['expires_at'],'public_expires_at'=>stripe_payment_link_public_expires_at($link),'amount'=>0.0,'item_count'=>0,'first_link_id'=>(int)$link['id']];
   }
   $stripeSessions[$sid]['amount']+=(float)$link['amount'];
   $stripeSessions[$sid]['item_count']++;
  }
  $stripeSessions=array_values($stripeSessions);
  usort($stripeSessions,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
  $stripeSessions=array_slice($stripeSessions,0,10);
 }
}
?>
<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsorship_bundles.php">Bundles</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $id ? 'Manage bundle' : 'Add bundle' ?></span></nav>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Bundle saved.</div><?php endif; ?>
<?php if(isset($_GET['attached'])): ?><div class="alert alert-success">Agreement attached to this bundle.</div><?php endif; ?>
<?php if(isset($_GET['detached'])): ?><div class="alert alert-success">Agreement removed from this bundle. It's still a standalone agreement with its payment history intact.</div><?php endif; ?>
<?php if(isset($_GET['item_deleted'])): ?><div class="alert alert-success">Agreement deleted.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="card shadow-sm border-0 hub-form-card mb-4"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="form_action" value="save_header"><div class="row g-3">
 <div class="col-md-4"><label class="form-label">Sponsor</label><select class="form-select" name="sponsor_id" required <?= $members?'disabled':'' ?>><option value="">Select sponsor</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['sponsor_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select><?php if($members): ?><input type="hidden" name="sponsor_id" value="<?= (int)$data['sponsor_id'] ?>"><div class="form-text">Locked — this bundle already has items. Remove them all first to change sponsor.</div><?php endif; ?></div>
 <div class="col-md-4"><label class="form-label">Season</label><select class="form-select" name="season_id"><option value="">No specific season</option><?php foreach($seasons as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['season_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active'=>'Active','archived'=>'Archived'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label">Name</label><input type="text" class="form-control" name="name" placeholder="e.g. 2026/27 Full Package" value="<?= h((string)$data['name']) ?>"></div>
 <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"><?= h((string)$data['notes']) ?></textarea></div>
</div></div><div class="card-footer d-flex flex-wrap justify-content-between gap-3 hub-actions"><div class="d-flex flex-wrap gap-2 hub-actions"><a class="btn btn-outline-secondary" href="sponsorship_bundles.php">Cancel</a><?php if($id>0): ?><button type="submit" class="btn btn-outline-danger" name="form_action" value="delete_bundle" formnovalidate data-confirm="The bundle record itself will be deleted. Its <?= count($members) ?> member agreement(s) are kept — they just go back to being standalone agreements." data-confirm-title="Delete this bundle?" data-confirm-action="Delete bundle">Delete bundle</button><?php endif; ?></div><button class="btn btn-brand" type="submit">Save bundle</button></div></form>

<?php if($id>0&&$bundle): ?>
<section class="card shadow-sm border-0 mb-4 hub-section hub-table-card"><div class="card-header bg-transparent"><h2 class="h5 mb-0">Combined payment link</h2></div><div class="card-body">
 <div class="hub-stripe-card border rounded p-3 mb-4" id="bundleStripeCard" data-bundle-id="<?= $id ?>" data-outstanding="<?= h(number_format($outstandingTotal,2,'.','')) ?>" data-create-url="stripe_bundle_payment_link_create.php" data-expire-url="stripe_payment_link_expire.php" data-csrf="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
   <div><i class="fa-brands fa-stripe-s text-brand me-1" aria-hidden="true"></i><strong>Stripe payment link</strong><div class="small text-muted">Outstanding across <?= count($members) ?> item(s): <span id="bundleStripeOutstandingLabel"><?= gbp($outstandingTotal) ?></span></div></div>
   <button type="button" class="btn btn-brand btn-sm" id="bundleStripeGenerateBtn" <?= $outstandingTotal<=0?'disabled':'' ?>>Generate combined payment link</button>
  </div>
  <div id="bundleStripeStatus" class="small mb-2"></div>
  <div id="bundleStripeLinkResult" class="d-none">
   <div class="input-group input-group-sm mb-2">
    <input type="text" class="form-control" id="bundleStripeLinkUrl" readonly>
    <button class="btn btn-outline-secondary" type="button" id="bundleStripeCopyBtn">Copy link</button>
   </div>
  </div>
  <?php if($stripeSessions): ?>
  <div class="table-responsive mt-3"><table class="table table-sm hub-data-table align-middle mb-0"><thead><tr><th>Created</th><th>Items</th><th>Amount</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody>
   <?php foreach($stripeSessions as $s): ?><?php
    $linkStatus=(string)$s['status'];
    if($linkStatus!=='complete')$linkStatus=(int)$s['public_expires_at']>time()?'open':'expired';
   ?><tr>
    <td><?= h(date('d/m/Y H:i',strtotime((string)$s['created_at']))) ?></td>
    <td><?= (int)$s['item_count'] ?></td>
    <td><?= gbp((float)$s['amount']) ?></td>
    <td><span class="badge bg-<?= $linkStatus==='complete'?'success':($linkStatus==='expired'?'secondary':'warning text-dark') ?>"><?= h(ucfirst($linkStatus)) ?></span></td>
    <td><?= h(date('d/m/Y H:i',(int)$s['public_expires_at'])) ?></td>
    <td class="text-end"><?php if($linkStatus==='open'): ?><div class="hub-actions hub-actions--end"><button type="button" class="btn btn-sm btn-outline-secondary bundle-stripe-copy-existing-btn" data-url="<?= h((string)$s['url']) ?>">Copy link</button><button type="button" class="btn btn-sm btn-outline-danger bundle-stripe-cancel-btn" data-link-id="<?= (int)$s['first_link_id'] ?>">Cancel</button></div><?php endif; ?></td>
   </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
 </div>
</div></section>

<section class="card shadow-sm border-0 mb-4 hub-section hub-table-card"><div class="card-header bg-transparent"><h2 class="h5 mb-0">Bundle items</h2></div><div class="card-body">
 <div class="table-responsive mb-3"><table class="table table-sm hub-data-table align-middle mb-0"><thead><tr><th>Package</th><th>Applies to</th><th>Value</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($members as $m): ?><?php $target=$m['season_name']?:'Club-wide'; if($m['fixture_id'])$target='Fixture #'.$m['fixture_id'].' · '.($m['fixture_opponent']??''); elseif($m['player_id'])$target=$m['player_name']??'Player'; ?>
  <tr>
   <td data-label="Package"><div><?= h((string)$m['package_name']) ?></div><span class="badge text-bg-light"><?= h((string)$m['package_category']) ?></span></td>
   <td data-label="Applies to"><?= h((string)$target) ?></td>
   <td data-label="Value"><?php if(!empty($m['is_complimentary'])): ?><span class="badge hub-status text-bg-info">Complimentary</span><?php else: ?><?= gbp((float)$m['agreed_amount']) ?><div class="small text-muted"><?= gbp((float)$m['total_paid']) ?> paid</div><?php endif; ?></td>
   <td data-label="Status"><span class="badge hub-status <?= $m['effective_status']==='active'?'text-bg-success':($m['effective_status']==='scheduled'?'text-bg-info':'text-bg-secondary') ?>"><?= h(ucfirst((string)$m['effective_status'])) ?></span></td>
   <td data-label="Actions" class="text-end"><div class="hub-actions hub-actions--end">
    <a class="btn btn-sm btn-outline-primary" href="sponsorship_agreement.php?id=<?= (int)$m['id'] ?>">Manage</a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="detach_item"><input type="hidden" name="agreement_id" value="<?= (int)$m['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Remove this agreement from the bundle? It stays as a standalone agreement with its payment history intact." data-confirm-title="Remove from bundle?" data-confirm-action="Remove">Remove</button></form>
   </div></td>
  </tr>
  <?php endforeach; ?><?php if(!$members): ?><tr><td colspan="5" class="hub-record-empty hub-empty-state">No items yet — attach an existing agreement below, or add a new one.</td></tr><?php endif; ?>
 </tbody></table></div>
 <?php if($members): ?><p class="fw-semibold mb-0">Total: <?= gbp(array_sum(array_map(fn($m)=>(float)$m['agreed_amount'],$members))) ?> agreed, <?= gbp(array_sum(array_map(fn($m)=>(float)$m['total_paid'],$members))) ?> paid, <?= gbp($outstandingTotal) ?> outstanding.</p><?php endif; ?>
</div></section>

<section class="card shadow-sm border-0 hub-section hub-table-card"><div class="card-header bg-transparent"><h2 class="h5 mb-0">Add to this bundle</h2></div><div class="card-body">
 <div class="row g-3">
  <div class="col-md-6">
   <h3 class="h6">Attach an existing agreement</h3>
   <?php if($attachable): ?>
   <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="form_action" value="attach_existing">
    <div class="col-8"><select class="form-select" name="agreement_id" required><option value="">Select agreement…</option><?php foreach($attachable as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h((string)$a['package_name']) ?> — <?= gbp((float)$a['agreed_amount']) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 d-grid"><button class="btn btn-brand">Attach</button></div>
   </form>
   <?php else: ?>
   <p class="text-muted mb-0">This sponsor has no other standalone agreements to attach.</p>
   <?php endif; ?>
  </div>
  <div class="col-md-6">
   <h3 class="h6">Add a new item</h3>
   <p class="text-muted">Creates a new agreement (a player, a match sponsorship, a board, etc.) pre-filed to this bundle's sponsor.</p>
   <a class="btn btn-outline-primary" href="sponsorship_agreement.php?bundle_id=<?= $id ?>"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add new item</a>
   <div class="form-text mt-1">Pick a match-day package (Matchday, Match Ball, MOTM) on that screen to cover several fixtures in one go.</div>
  </div>
 </div>
</div></section>

<script>(()=>{
  const card=document.getElementById('bundleStripeCard');
  if(!card)return;
  const statusEl=document.getElementById('bundleStripeStatus');
  const setStatus=(msg,tone)=>{statusEl.textContent=msg||'';statusEl.className='small mb-2'+(tone?(' text-'+tone):'');};
  const csrf=card.dataset.csrf;
  const generateBtn=document.getElementById('bundleStripeGenerateBtn');
  const resultBox=document.getElementById('bundleStripeLinkResult');
  const urlInput=document.getElementById('bundleStripeLinkUrl');
  const copyBtn=document.getElementById('bundleStripeCopyBtn');

  generateBtn?.addEventListener('click',async()=>{
    generateBtn.disabled=true;
    const original=generateBtn.textContent;
    generateBtn.textContent='Generating…';
    setStatus('');
    try{
      const response=await fetch(card.dataset.createUrl,{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:'bundle_id='+encodeURIComponent(card.dataset.bundleId)+'&csrf_token='+encodeURIComponent(csrf)
      });
      const json=await response.json().catch(()=>null);
      if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not create a payment link.');}
      urlInput.value=json.link.url;
      resultBox.classList.remove('d-none');
      setStatus('Payment link ready for '+json.link.total_amount.toFixed(2)+'.','success');
    }catch(error){
      setStatus(error.message||'Could not create a payment link.','danger');
    }finally{
      generateBtn.disabled=false;
      generateBtn.textContent=original;
    }
  });

  copyBtn?.addEventListener('click',async()=>{
    try{
      await navigator.clipboard.writeText(urlInput.value);
    }catch(error){
      urlInput.select();
      document.execCommand('copy');
    }
    setStatus('Link copied to clipboard.','success');
  });

  document.querySelectorAll('.bundle-stripe-copy-existing-btn').forEach((btn)=>{
    btn.addEventListener('click',async()=>{
      try{
        await navigator.clipboard.writeText(btn.dataset.url);
      }catch(error){
        const temp=document.createElement('input');
        temp.value=btn.dataset.url;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        temp.remove();
      }
      const original=btn.textContent;
      btn.textContent='Copied!';
      setTimeout(()=>{btn.textContent=original;},1500);
    });
  });

  document.querySelectorAll('.bundle-stripe-cancel-btn').forEach((btn)=>{
    btn.addEventListener('click',async()=>{
      const confirmed=await window.hubConfirm('Cancel this combined payment link? It covers every item on it — none of them will be payable through this link once cancelled.',{actionLabel:'Cancel link'});
      if(!confirmed)return;
      btn.disabled=true;
      const original=btn.textContent;
      btn.textContent='Cancelling…';
      try{
        const response=await fetch(card.dataset.expireUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body:'link_id='+encodeURIComponent(btn.dataset.linkId)+'&csrf_token='+encodeURIComponent(csrf)
        });
        const json=await response.json().catch(()=>null);
        if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not cancel the link.');}
        window.location.reload();
      }catch(error){
        window.hubToast(error.message||'Could not cancel the link.','danger');
        btn.disabled=false;
        btn.textContent=original;
      }
    });
  });
})();</script>
<?php endif; ?>
<?php require_once __DIR__.'/footer.php'; ?>
