<?php
$id=(int)($_GET['id']??0);
$pageHero=['eyebrow'=>'Sponsorship management','title'=>$id?'Manage Agreement':'Add Agreement','subtitle'=>'Connect a sponsor to a package, period and club asset.','actions'=>[]];
require_once __DIR__.'/header.php';
require_once __DIR__.'/lib/match_sponsorship.php';
require_once __DIR__.'/lib/stripe.php';
require_once __DIR__.'/lib/invoices.php';
ensureSponsorshipCatalogSchema($pdo);
ensureStripeSchema($pdo);
invoices_ensure_schema($pdo);
$agreement=$id?getSponsorshipAgreement($pdo,$id):null;
if($id&&!$agreement){echo '<div class="alert alert-danger">Agreement not found.</div>';require __DIR__.'/footer.php';exit;}

// A "bundle context" locks the sponsor field to that bundle's sponsor, so an item
// added or edited here can't accidentally end up filed against a different sponsor
// than the bundle it belongs to. An existing agreement's own bundle_id always wins;
// for a brand-new agreement it comes from ?bundle_id= (the link the bundle page's
// "Add new item" button uses).
$bundleId=$agreement?(int)($agreement['bundle_id']??0):(int)($_GET['bundle_id']??0);
$bundle=$bundleId>0?getSponsorshipBundle($pdo,$bundleId):null;
if($bundleId>0&&!$bundle)$bundleId=0;

$currentSeason=$pdo->query('SELECT * FROM seasons WHERE is_current=1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC)?:[];
// package_id/season_id are read from the querystring too (not just an existing
// agreement) so the New Agreement selector below can be reloaded via GET without
// losing the sponsor's/bundle's choice, and so a direct link (e.g. from the bundle
// page) can preselect a package.
$data=array_merge(['sponsor_id'=>$bundle?(int)$bundle['sponsor_id']:(int)($_GET['sponsor_id']??0),'package_id'=>(string)($_GET['package_id']??''),'season_id'=>(string)($_GET['season_id']??(($bundle['season_id']??null)?:($currentSeason['id']??''))),'team_id'=>'','fixture_id'=>'','player_id'=>'','start_date'=>$currentSeason['start_date']??date('Y-m-d'),'end_date'=>$currentSeason['end_date']??'','agreed_amount'=>'0.00','is_complimentary'=>0,'status'=>'active','display_order'=>0,'logo_variant'=>'package_default','notes'=>''],$agreement?:[]);
$sponsors=$pdo->query('SELECT id,name FROM sponsors WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$packages=getSponsorshipPackages($pdo,true);$seasons=$pdo->query('SELECT id,name,start_date,end_date FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);$teams=$pdo->query('SELECT id,name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$players=$pdo->query('SELECT id,name,active,status FROM players WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);$fixtures=$pdo->query("SELECT id,season_id,match_date,opponent FROM match_fixtures ORDER BY match_date DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
$selectedPlayerId=(int)($data['player_id']??0);
if($selectedPlayerId>0&&!in_array($selectedPlayerId,array_map(static fn(array $p):int=>(int)$p['id'],$players),true)){
 $selectedPlayerStmt=$pdo->prepare('SELECT id,name,active,status FROM players WHERE id=:id LIMIT 1');
 $selectedPlayerStmt->execute([':id'=>$selectedPlayerId]);
 $selectedPlayer=$selectedPlayerStmt->fetch(PDO::FETCH_ASSOC);
 if($selectedPlayer)$players[]=$selectedPlayer;
}
$errors=[];
$batchResults=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check())$errors[]='Invalid security token.';
 $formAction=(string)($_POST['form_action']??'save');
 if($formAction==='delete'){
  if(!$id)$errors[]='Agreement not found.';
  if(!$errors){try{$deletedLabel=(string)($agreement['sponsor_name']??'').' — '.(string)($agreement['package_name']??'');deleteSponsorshipAgreement($pdo,$id);auditLog($pdo,'sponsorship_agreement_deleted',"Deleted agreement #{$id} ({$deletedLabel})");header('Location: sponsorship_agreements.php?deleted=1');exit;}catch(Throwable $e){$errors[]=$e->getMessage();}}
 } elseif($formAction==='detach_bundle'){
  if(!$id)$errors[]='Agreement not found.';
  if(!$errors){detachSponsorshipAgreementFromBundle($pdo,$id);auditLog($pdo,'sponsorship_agreement_detached_from_bundle',"Detached agreement #{$id} from bundle #".(int)($agreement['bundle_id']??0));header('Location: sponsorship_agreement.php?id='.$id.'&detached=1');exit;}
 } elseif($formAction==='delete_payment'){
  // Remove one recorded payment. Agreements synced from a legacy player/match
  // sponsorship keep their payment history in that legacy table (see
  // getAgreementPayments()), so delete from whichever table this agreement reads.
  $paymentId=(int)($_POST['payment_id']??0);
  if(!$id||!$agreement)$errors[]='Agreement not found.';
  if(!$errors&&$paymentId<=0)$errors[]='Invalid payment reference.';
  if(!$errors){
   try{
    $legacySource=(string)($agreement['legacy_source']??'');
    $legacyId=(int)($agreement['legacy_id']??0);
    if($legacySource==='match'&&$legacyId>0){
     $del=$pdo->prepare('DELETE FROM match_sponsorship_payments WHERE id=:pid AND match_sponsorship_id=:legacy');
     $del->execute([':pid'=>$paymentId,':legacy'=>$legacyId]);
     $removed=$del->rowCount();
     if($removed>0)recomputeMatchPaidFlag($pdo,$legacyId);
    } elseif($legacySource==='player'&&$legacyId>0){
     $del=$pdo->prepare('DELETE FROM sponsorship_payments WHERE id=:pid AND sponsorship_id=:legacy');
     $del->execute([':pid'=>$paymentId,':legacy'=>$legacyId]);
     $removed=$del->rowCount();
     if($removed>0){recomputePaidFlag($pdo,$legacyId);syncPlayerSponsorshipAgreement($pdo,$legacyId);}
    } else {
     $del=$pdo->prepare('DELETE FROM sponsorship_agreement_payments WHERE id=:pid AND agreement_id=:agreement');
     $del->execute([':pid'=>$paymentId,':agreement'=>$id]);
     $removed=$del->rowCount();
    }
    if(!empty($removed)){
     auditLog($pdo,'sponsorship_agreement_payment_removed',"Removed payment #{$paymentId} from agreement #{$id} (".(string)($agreement['sponsor_name']??'').')');
     header('Location: sponsorship_agreement.php?id='.$id.'&payment_deleted=1');
     exit;
    }
    $errors[]='That payment could not be found on this agreement.';
   }catch(Throwable $e){$errors[]=$e->getMessage();}
  }
 } elseif($formAction==='generate_invoice'){
  if(!$id||!$agreement){$errors[]='Agreement not found.';}
  if(!$errors){
   $currentUserLabel=(string)($currentUser['display_name']??$currentUser['username']??$currentUser['email']??'');
   $result=invoices_generate_for_sponsorship_agreement($pdo,$id,$currentUserLabel);
   if($result['errors']){$errors=$result['errors'];}
   else{auditLog($pdo,'invoice_generated',"Generated invoice for sponsorship agreement #{$id}");header('Location: sponsorship_agreement.php?id='.$id.'&invoice_generated=1');exit;}
  }
 } elseif($formAction==='save_batch'){
  // New match-scope agreement(s) created from the fixture checklist below — one
  // saveSponsorshipAgreement() call per selected fixture, same validation/legacy-sync
  // as a single save, just looped. Replaces what used to be a separate Bulk Add page.
  // Pricing can be a flat per-fixture rate, or one deal total split evenly across the
  // ticked fixtures (last fixture absorbs the rounding remainder) — the latter is what
  // a real season-long deal (e.g. "£450 for the rest of the season") actually needs;
  // it used to require a one-off script to record correctly.
  foreach(['sponsor_id','package_id','season_id','agreed_amount','status','notes','pricing_mode'] as $f)$data[$f]=trim((string)($_POST[$f]??''));
  if($bundle)$data['sponsor_id']=(string)$bundle['sponsor_id'];
  $data['is_complimentary']=isset($_POST['is_complimentary'])&&$_POST['is_complimentary']==='1'?1:0;
  $fixtureIds=array_map('intval',$_POST['fixture_ids']??[]);
  $fixtureIds=array_values(array_unique(array_filter($fixtureIds,fn($v)=>$v>0)));
  $markPaid=isset($_POST['mark_paid'])&&$_POST['mark_paid']==='1'&&!$data['is_complimentary'];
  $paidAt=(string)($_POST['paid_at']??date('Y-m-d'));
  $paidMethod=trim((string)($_POST['paid_method']??''))?:null;
  if(!$errors&&!$fixtureIds)$errors[]='Select at least one fixture.';
  if(!$errors&&!$data['is_complimentary']&&(!is_numeric($data['agreed_amount'])||(float)$data['agreed_amount']<0))$errors[]='Enter a valid amount, or mark this complimentary.';
  if(!$errors&&$markPaid&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$paidAt))$errors[]='Enter a valid paid date.';
  if(!$errors){
   $count=count($fixtureIds);
   $perFixtureAmounts=[];
   if($data['is_complimentary']){
    foreach($fixtureIds as $fid)$perFixtureAmounts[$fid]='0.00';
   } elseif($data['pricing_mode']==='total_split'&&$count>0){
    $total=(float)$data['agreed_amount'];
    $base=floor(($total/$count)*100)/100;
    $remainder=round($total-($base*$count),2);
    foreach($fixtureIds as $i=>$fid)$perFixtureAmounts[$fid]=number_format($i===$count-1?round($base+$remainder,2):$base,2,'.','');
   } else {
    foreach($fixtureIds as $fid)$perFixtureAmounts[$fid]=$data['agreed_amount'];
   }

   $created=0;$skipped=[];$paidTotal=0.0;
   foreach($fixtureIds as $fid){
    $input=['sponsor_id'=>$data['sponsor_id'],'package_id'=>$data['package_id'],'season_id'=>$data['season_id'],
     'team_id'=>'','fixture_id'=>(string)$fid,'player_id'=>'','start_date'=>'','end_date'=>'',
     'agreed_amount'=>$perFixtureAmounts[$fid],
     'is_complimentary'=>$data['is_complimentary']?'1':'0','status'=>$data['status'],'display_order'=>'0',
     'logo_variant'=>'package_default','notes'=>$data['notes']];
    if($bundleId>0)$input['bundle_id']=(string)$bundleId;
    $result=saveSponsorshipAgreement($pdo,0,$input);
    if($result['errors']){$skipped[$fid]=$result['errors'][0];continue;}
    $created++;
    if($markPaid){
     $newAgreement=getSponsorshipAgreement($pdo,$result['id']);
     $legacyId=(int)($newAgreement['legacy_id']??0);
     if((string)($newAgreement['legacy_source']??'')==='match'&&$legacyId>0){
      $matchSponsorship=getMatchSponsorshipById($pdo,$legacyId);
      $pdo->prepare('INSERT INTO match_sponsorship_payments(match_sponsorship_id,season_id,amount,paid_at,method,note) VALUES(:id,:season_id,:amount,:paid_at,:method,:note)')
       ->execute([':id'=>$legacyId,':season_id'=>(int)($matchSponsorship['season_id']??$data['season_id']),':amount'=>$perFixtureAmounts[$fid],':paid_at'=>$paidAt,':method'=>$paidMethod,':note'=>'Recorded automatically when this agreement was created.']);
      recomputeMatchPaidFlag($pdo,$legacyId);
      $paidTotal+=(float)$perFixtureAmounts[$fid];
     }
    }
   }
   if($created>0){
    $batchSponsorLabel=(array_column($sponsors,'name','id'))[(int)$data['sponsor_id']]??('#'.$data['sponsor_id']);
    auditLog($pdo,'sponsorship_agreements_batch_created',"Created {$created} agreement(s) for {$batchSponsorLabel} across ".count($fixtureIds)." fixture(s)".($paidTotal>0?(', marked paid (£'.number_format($paidTotal,2).' total)'):''));
   }
   if($created>0&&!$skipped){
    header('Location: '.($bundleId>0?('sponsorship_bundle.php?id='.$bundleId.'&saved=1'):'sponsorship_agreements.php?saved=1'));
    exit;
   }
   $batchResults=['created'=>$created,'skipped'=>$skipped,'paid_total'=>$paidTotal];
  }
 } else {
 foreach(['sponsor_id','package_id','season_id','team_id','fixture_id','player_id','start_date','end_date','agreed_amount','status','display_order','logo_variant','notes'] as $f)$data[$f]=trim((string)($_POST[$f]??''));
 if($bundle)$data['sponsor_id']=(string)$bundle['sponsor_id']; // sponsor field is locked/hidden in bundle context — never trust a client-submitted value for it
 $data['is_complimentary']=isset($_POST['is_complimentary'])&&$_POST['is_complimentary']==='1'?1:0;
 $postInput=$data;
 if($bundleId>0)$postInput['bundle_id']=(string)$bundleId;
 $wasNewAgreement=$id===0;
 $result=saveSponsorshipAgreement($pdo,$id,$postInput);
 if($result['errors']){
  $errors=$result['errors'];
 } else {
  $savedSponsorLabel=(array_column($sponsors,'name','id'))[(int)$data['sponsor_id']]??('#'.$data['sponsor_id']);
  auditLog($pdo,$wasNewAgreement?'sponsorship_agreement_created':'sponsorship_agreement_updated',($wasNewAgreement?'Created':'Updated')." agreement #{$result['id']} for {$savedSponsorLabel} (£".number_format((float)$data['agreed_amount'],2).')');
  header('Location: '.($bundleId>0?('sponsorship_bundle.php?id='.$bundleId.'&saved=1'):'sponsorship_agreements.php?saved=1'));
  exit;
 }
 }
}
$agreementPayments=[];
$stripeOutstanding=0.0;
$stripeLinks=[];
$agreementInvoices=$id>0?invoices_list_for_source($pdo,'sponsorship_agreement',$id):[];
if($id>0&&$agreement){
 $agreementPayments=getAgreementPayments($pdo,$agreement);
 $stripeOutstanding=stripe_agreement_outstanding_amount($agreement);
 $linksStmt=$pdo->prepare('SELECT * FROM stripe_payment_links WHERE agreement_id=:id ORDER BY created_at DESC LIMIT 10');
 $linksStmt->execute([':id'=>$id]);
 $stripeLinks=$linksStmt->fetchAll(PDO::FETCH_ASSOC);
 // A link generated from a bundle is really N rows (one per member agreement) sharing
 // one Stripe session — flag that here so "Cancel" can warn it affects every sibling,
 // not just this one agreement's own row.
 $siblingCountStmt=$pdo->prepare('SELECT COUNT(*) FROM stripe_payment_links WHERE stripe_checkout_session_id=:session_id');
 foreach($stripeLinks as &$stripeLink){
  $siblingCountStmt->execute([':session_id'=>$stripeLink['stripe_checkout_session_id']]);
  $stripeLink['is_bundle_link']=(int)$siblingCountStmt->fetchColumn()>1;
 }
 unset($stripeLink);
}

// For a brand-new match-scope agreement, "Fixture" becomes a multi-select checklist
// (one agreement created per ticked fixture) instead of a single dropdown — this is
// what used to be the separate Bulk Add page. Editing an existing agreement always
// stays single-fixture; you can't bulk-edit several agreements from one row.
$packageIdForNew=$id===0?(int)($data['package_id']?:0):0;
$selectedPackageForNew=$packageIdForNew?getSponsorshipPackage($pdo,$packageIdForNew):null;
$scopeForNew=$selectedPackageForNew['scope']??'';
$homeOnly=!(isset($_GET['home_only'])&&$_GET['home_only']==='0');
$multiFixtures=[];
$multiExistingByFixture=[];
if($id===0&&$scopeForNew==='match'){
 $seasonForNew=(int)($data['season_id']?:($currentSeason['id']??0));
 $fixtureSql="SELECT id,match_date,kickoff_time,opponent,competition FROM match_fixtures WHERE season_id=:season".($homeOnly?" AND is_home=1":"")." ORDER BY match_date,id";
 $stmt=$pdo->prepare($fixtureSql);$stmt->execute([':season'=>$seasonForNew]);
 $multiFixtures=$stmt->fetchAll(PDO::FETCH_ASSOC);
 if($multiFixtures){
  $idsInScope=array_map(fn($f)=>(int)$f['id'],$multiFixtures);
  foreach(getSponsorshipAgreements($pdo,['package_id'=>$packageIdForNew]) as $row){
   $fid=(int)($row['fixture_id']??0);
   if($fid&&in_array($fid,$idsInScope,true)&&in_array($row['effective_status'],['active','scheduled'],true)){
    $multiExistingByFixture[$fid]=(string)$row['sponsor_name'];
   }
  }
 }
}
?>
<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsorship_agreements.php">Agreements</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $id ? 'Manage agreement' : 'Add agreement' ?></span></nav>
<?php if($bundle): ?><div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2"><div><i class="fa-solid fa-boxes-stacked me-1" aria-hidden="true"></i>Part of bundle #<?= (int)$bundle['id'] ?><?= $bundle['name']?': '.h((string)$bundle['name']):'' ?> — <?= h((string)$bundle['sponsor_name']) ?></div><a class="btn btn-sm btn-outline-primary" href="sponsorship_bundle.php?id=<?= (int)$bundle['id'] ?>">View bundle</a></div><?php endif; ?>
<?php if(isset($_GET['payment_saved'])): ?><div class="alert alert-success">Agreement payment recorded.</div><?php endif; ?>
<?php if(isset($_GET['payment_deleted'])): ?><div class="alert alert-success">Payment removed. The outstanding balance has been updated.</div><?php endif; ?>
<?php if(isset($_GET['detached'])): ?><div class="alert alert-success">Agreement removed from its bundle. The agreement and its payment history are unchanged.</div><?php endif; ?>
<?php if(isset($_GET['invoice_generated'])): ?><div class="alert alert-success">Invoice generated.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if($batchResults): ?>
<div class="alert <?= $batchResults['created']>0?'alert-success':'alert-warning' ?>">
 <div class="fw-semibold"><?= (int)$batchResults['created'] ?> agreement<?= $batchResults['created']===1?'':'s' ?> created<?= !empty($batchResults['paid_total'])?' and marked paid ('.gbp($batchResults['paid_total']).' total)':'' ?>.</div>
 <?php if($batchResults['skipped']): ?>
 <div class="mt-2 small"><?= count($batchResults['skipped']) ?> fixture(s) skipped:<ul class="mb-0">
  <?php foreach($batchResults['skipped'] as $fid=>$reason): ?><?php $f=null; foreach($multiFixtures as $candidate){if((int)$candidate['id']===(int)$fid){$f=$candidate;break;}} ?>
  <li><?= $f?h(date('d/m/Y',strtotime((string)$f['match_date'])).' · '.$f['opponent']):('Fixture #'.$fid) ?> — <?= h($reason) ?></li>
  <?php endforeach; ?>
 </ul></div>
 <?php endif; ?>
</div>
<?php endif; ?>

<?php if($id>0): ?>
<form method="post" class="card shadow-sm border-0 hub-form-card" id="agreementForm"><div class="card-body"><?= csrf_field() ?><div class="row g-3">
 <?php if($bundle): ?>
 <div class="col-md-6"><label class="form-label">Sponsor</label><input type="text" class="form-control" value="<?= h((string)$bundle['sponsor_name']) ?>" disabled><div class="form-text">Locked to this bundle's sponsor — <a href="sponsorship_bundle.php?id=<?= (int)$bundle['id'] ?>">remove this agreement from the bundle</a> first to sponsor it to someone else.</div><input type="hidden" name="sponsor_id" value="<?= (int)$bundle['sponsor_id'] ?>"></div>
 <?php else: ?>
 <div class="col-md-6"><label class="form-label">Sponsor</label><select class="form-select" name="sponsor_id" required><option value="">Select sponsor</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['sponsor_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <?php endif; ?>
 <div class="col-md-6"><label class="form-label">Package</label><select class="form-select" name="package_id" id="agreementPackage" required><option value="">Select package</option><?php foreach($packages as $p): ?><option value="<?= (int)$p['id'] ?>" data-scope="<?= h((string)$p['scope']) ?>" data-amount="<?= h((string)$p['amount']) ?>" <?= (int)$data['package_id']===(int)$p['id']?'selected':'' ?>><?= h((string)$p['category'].' · '.(string)$p['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6"><label class="form-label">Season</label><select class="form-select" name="season_id"><option value="">No specific season</option><?php foreach($seasons as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['season_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6 agreement-target" data-target-scope="team"><label class="form-label">Team</label><select class="form-select" name="team_id"><option value="">Select team</option><?php foreach($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$data['team_id']===(int)$t['id']?'selected':'' ?>><?= h((string)$t['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6 agreement-target" data-target-scope="match"><label class="form-label">Fixture</label><select class="form-select" name="fixture_id"><option value="">Select fixture</option><?php foreach($fixtures as $f): ?><option value="<?= (int)$f['id'] ?>" <?= (int)$data['fixture_id']===(int)$f['id']?'selected':'' ?>><?= h(date('d/m/Y',strtotime((string)$f['match_date'])).' · '.$f['opponent']) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-6 agreement-target" data-target-scope="player"><label class="form-label">Player</label><select class="form-select" name="player_id"><option value="">Select player</option><?php foreach($players as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$data['player_id']===(int)$p['id']?'selected':'' ?>><?= h((string)$p['name'].((int)($p['active']??1)===1?'':' (former)')) ?></option><?php endforeach; ?></select></div>
 <div class="col-md-3"><label class="form-label">Start date</label><input type="date" class="form-control" name="start_date" value="<?= h((string)$data['start_date']) ?>"></div><div class="col-md-3"><label class="form-label">End date</label><input type="date" class="form-control" name="end_date" value="<?= h((string)$data['end_date']) ?>"></div>
 <div class="col-md-3"><label class="form-label">Agreed amount</label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" name="agreed_amount" id="agreementAmount" value="<?= h((string)$data['agreed_amount']) ?>"></div></div>
 <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active'=>'Active','scheduled'=>'Scheduled','expired'=>'Expired','cancelled'=>'Cancelled'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
 <div class="col-12 agreement-complimentary"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="agreementComplimentary" name="is_complimentary" value="1" <?= !empty($data['is_complimentary'])?'checked':'' ?>><label class="form-check-label fw-semibold" for="agreementComplimentary">Complimentary / Free promotion</label></div><div class="form-text">Match sponsor visibility is retained, but the agreement has no value and cannot receive payments.</div></div>
 <div class="col-md-6"><label class="form-label">Graphic display order</label><input type="number" min="0" class="form-control" name="display_order" value="<?= (int)$data['display_order'] ?>"><div class="form-text">Lower numbers appear first.</div></div>
 <div class="col-md-6"><label class="form-label">Logo version</label><select class="form-select" name="logo_variant"><?php foreach(['package_default'=>'Package default','white'=>'White','colour'=>'Coloured'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['logo_variant']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
 <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3"><?= h((string)$data['notes']) ?></textarea></div>
</div></div><div class="card-footer d-flex flex-wrap justify-content-between gap-3 hub-actions"><div class="d-flex flex-wrap gap-2 hub-actions"><a class="btn btn-outline-secondary" href="<?= $bundle?('sponsorship_bundle.php?id='.(int)$bundle['id']):'sponsorship_agreements.php' ?>">Cancel</a><?php if($id>0&&$bundle): ?><button type="submit" class="btn btn-outline-secondary" name="form_action" value="detach_bundle" formnovalidate data-confirm="Remove this agreement from bundle #<?= (int)$bundle['id'] ?>? The agreement and its payment history stay untouched — it just goes back to being a standalone agreement." data-confirm-title="Remove from bundle?" data-confirm-action="Remove from bundle">Remove from bundle</button><?php endif; ?><?php if($id>0): ?><button type="submit" class="btn btn-outline-danger" name="form_action" value="delete" formnovalidate data-confirm="This agreement and every payment recorded against it will be permanently deleted." data-confirm-title="Delete this agreement?" data-confirm-action="Delete agreement">Delete agreement</button><?php endif; ?></div><button class="btn btn-brand" type="submit" name="form_action" value="save">Save agreement</button></div></form>
<script>(()=>{const p=document.getElementById('agreementPackage'),amount=document.getElementById('agreementAmount'),complimentary=document.getElementById('agreementComplimentary'),complimentaryWrap=document.querySelector('.agreement-complimentary'),targets=[...document.querySelectorAll('.agreement-target')];const syncComplimentary=()=>{const o=p.options[p.selectedIndex],match=(o?.dataset.scope||'')==='match';complimentaryWrap?.classList.toggle('d-none',!match);if(!match&&complimentary)complimentary.checked=false;if(!complimentary||!amount)return;if(complimentary.checked){if(Number(amount.value)>0)amount.dataset.chargeableValue=amount.value;amount.value='0.00';amount.readOnly=true;}else{amount.readOnly=false;if(Number(amount.value)===0&&amount.dataset.chargeableValue)amount.value=amount.dataset.chargeableValue;}};const sync=(setAmount=false)=>{const o=p.options[p.selectedIndex],scope=o?.dataset.scope||'';targets.forEach(w=>{const show=w.dataset.targetScope===scope;w.classList.toggle('d-none',!show);w.querySelectorAll('select').forEach(s=>s.disabled=!show)});if(setAmount&&o?.dataset.amount)amount.value=o.dataset.amount;syncComplimentary();};p.addEventListener('change',()=>sync(true));complimentary?.addEventListener('change',syncComplimentary);sync(false)})();</script>
<?php else: ?>

<form method="get" class="card shadow-sm border-0 hub-form-card mb-3" id="newAgreementSelectForm">
 <div class="card-body"><div class="row g-3">
  <?php if($bundleId>0): ?><input type="hidden" name="bundle_id" value="<?= $bundleId ?>"><?php endif; ?>
  <?php if($bundle): ?>
  <div class="col-md-4"><label class="form-label">Sponsor</label><input type="text" class="form-control" value="<?= h((string)$bundle['sponsor_name']) ?>" disabled><div class="form-text">Locked to this bundle's sponsor.</div></div>
  <?php else: ?>
  <div class="col-md-4"><label class="form-label">Sponsor</label><select class="form-select" name="sponsor_id" onchange="document.getElementById('newAgreementSelectForm').submit()"><option value="">Select sponsor</option><?php foreach($sponsors as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['sponsor_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-4"><label class="form-label">Package</label><select class="form-select" name="package_id" onchange="document.getElementById('newAgreementSelectForm').submit()"><option value="">Select package</option><?php foreach($packages as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $packageIdForNew===(int)$p['id']?'selected':'' ?>><?= h((string)$p['category'].' · '.(string)$p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Season</label><select class="form-select" name="season_id" onchange="document.getElementById('newAgreementSelectForm').submit()"><option value="">No specific season</option><?php foreach($seasons as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$data['season_id']===(int)$s['id']?'selected':'' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?></select></div>
  <?php if($scopeForNew==='match'): ?>
  <input type="hidden" name="home_only" value="0">
  <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="homeOnlyToggle" name="home_only" value="1" <?= $homeOnly?'checked':'' ?> onchange="document.getElementById('newAgreementSelectForm').submit()"><label class="form-check-label" for="homeOnlyToggle">Home fixtures only</label></div></div>
  <?php endif; ?>
 </div></div>
</form>

<?php if(!$selectedPackageForNew): ?>
<div class="alert alert-info">Choose a package above to continue. Match-day packages (Matchday, Match Ball, MOTM) will show a fixture checklist so you can cover several games in one go.</div>
<?php else: ?>
<form method="post" class="card shadow-sm border-0 hub-form-card <?= $scopeForNew==='match'?'hub-table-card':'' ?>">
 <div class="card-body">
  <?= csrf_field() ?>
  <input type="hidden" name="form_action" value="<?= $scopeForNew==='match'?'save_batch':'save' ?>">
  <input type="hidden" name="sponsor_id" value="<?= (int)$data['sponsor_id'] ?>">
  <input type="hidden" name="package_id" value="<?= (int)$packageIdForNew ?>">
  <input type="hidden" name="season_id" value="<?= h((string)$data['season_id']) ?>">
  <?php if($bundleId>0): ?><input type="hidden" name="bundle_id" value="<?= $bundleId ?>"><?php endif; ?>

  <?php if((int)$data['sponsor_id']<=0): ?><div class="alert alert-warning mb-3">Select a sponsor above before saving.</div><?php endif; ?>

  <?php if($scopeForNew==='match'): ?>
   <?php if(!$multiFixtures): ?>
   <div class="alert alert-warning">No<?= $homeOnly?' home':'' ?> fixtures found for that season yet.</div>
   <?php else: ?>
   <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Fixtures — tick every game this deal covers</div>
    <div class="hub-actions"><button type="button" class="btn btn-sm btn-outline-secondary" id="agreementSelectAll">Select all</button><button type="button" class="btn btn-sm btn-outline-secondary" id="agreementSelectNone">Select none</button></div>
   </div>
   <div class="table-responsive mb-3"><table class="table table-sm hub-data-table align-middle mb-0">
    <thead><tr><th style="width:40px"></th><th>Date</th><th>Opponent</th><th>Competition</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($multiFixtures as $f): $fid=(int)$f['id']; $taken=$multiExistingByFixture[$fid]??null; ?>
     <tr>
      <td><?php if($taken): ?><input type="checkbox" class="form-check-input" disabled><?php else: ?><input type="checkbox" class="form-check-input agreement-fixture-checkbox" name="fixture_ids[]" value="<?= $fid ?>"><?php endif; ?></td>
      <td class="text-nowrap"><?= h(date('D d/m/Y',strtotime((string)$f['match_date']))) ?><?php if($f['kickoff_time']): ?> <span class="text-muted small"><?= h(substr((string)$f['kickoff_time'],0,5)) ?></span><?php endif; ?></td>
      <td><?= h((string)$f['opponent']) ?></td>
      <td class="text-muted small"><?= h((string)$f['competition']) ?></td>
      <td><?php if($taken): ?><span class="badge text-bg-secondary">Already sponsored — <?= h($taken) ?></span><?php else: ?><span class="badge text-bg-light">Available</span><?php endif; ?></td>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table></div>
   <?php endif; ?>
  <?php elseif($scopeForNew==='team'): ?>
   <div class="mb-3"><label class="form-label">Team</label><select class="form-select" name="team_id" required><option value="">Select team</option><?php foreach($teams as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h((string)$t['name']) ?></option><?php endforeach; ?></select></div>
  <?php elseif($scopeForNew==='player'): ?>
   <div class="mb-3"><label class="form-label">Player</label><select class="form-select" name="player_id" required><option value="">Select player</option><?php foreach($players as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h((string)$p['name'].((int)($p['active']??1)===1?'':' (former)')) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>

  <div class="row g-3">
   <?php if($scopeForNew!=='match'): ?>
   <div class="col-md-3"><label class="form-label">Start date</label><input type="date" class="form-control" name="start_date" value="<?= h((string)$data['start_date']) ?>"></div>
   <div class="col-md-3"><label class="form-label">End date</label><input type="date" class="form-control" name="end_date" value="<?= h((string)$data['end_date']) ?>"></div>
   <?php endif; ?>
   <?php if($scopeForNew==='match'): ?>
   <div class="col-12">
    <label class="form-label d-block">Pricing</label>
    <div class="btn-group" role="group">
     <input type="radio" class="btn-check" name="pricing_mode" id="pricingPerFixture" value="per_fixture" checked>
     <label class="btn btn-outline-secondary btn-sm" for="pricingPerFixture">£ per fixture</label>
     <input type="radio" class="btn-check" name="pricing_mode" id="pricingTotalSplit" value="total_split">
     <label class="btn btn-outline-secondary btn-sm" for="pricingTotalSplit">One total, split across ticked fixtures</label>
    </div>
    <div class="form-text">"One total" is for a deal like "£450 for the rest of the season" — it divides evenly across whichever fixtures are ticked (to the penny; any odd penny lands on the last fixture) instead of pricing each game separately.</div>
   </div>
   <?php endif; ?>
   <div class="col-md-3"><label class="form-label" id="agreementAmountLabel"><?= $scopeForNew==='match'?'Agreed amount per fixture':'Agreed amount' ?></label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" name="agreed_amount" id="agreementAmountNew" value="<?= h((string)($data['agreed_amount']!=='0.00'?$data['agreed_amount']:$selectedPackageForNew['amount'])) ?>"></div></div>
   <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['active'=>'Active','scheduled'=>'Scheduled'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
   <?php if($scopeForNew!=='match'): ?>
   <div class="col-md-6"><label class="form-label">Graphic display order</label><input type="number" min="0" class="form-control" name="display_order" value="<?= (int)$data['display_order'] ?>"><div class="form-text">Lower numbers appear first.</div></div>
   <div class="col-md-6"><label class="form-label">Logo version</label><select class="form-select" name="logo_variant"><?php foreach(['package_default'=>'Package default','white'=>'White','colour'=>'Coloured'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['logo_variant']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
   <?php endif; ?>
   <?php if($scopeForNew==='match'): ?>
   <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="agreementComplimentaryNew" name="is_complimentary" value="1"><label class="form-check-label fw-semibold" for="agreementComplimentaryNew">Complimentary / Free promotion for every ticked fixture</label></div></div>
   <div class="col-12">
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="agreementMarkPaidNew" name="mark_paid" value="1"><label class="form-check-label fw-semibold" for="agreementMarkPaidNew">Mark every created agreement as paid in full now</label></div>
    <div class="row g-2 mt-1 d-none" id="markPaidDetails">
     <div class="col-md-3"><label class="form-label small">Paid date</label><input type="date" class="form-control form-control-sm" name="paid_at" value="<?= date('Y-m-d') ?>"></div>
     <div class="col-md-3"><label class="form-label small">Method</label><select class="form-select form-select-sm" name="paid_method"><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option><option value="Cheque">Cheque</option><option value="Card">Card</option><option value="Stripe">Stripe</option><option value="Other">Other</option></select></div>
    </div>
   </div>
   <?php endif; ?>
   <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3"><?= h((string)$data['notes']) ?></textarea></div>
  </div>
 </div>
 <div class="card-footer d-flex flex-wrap justify-content-between gap-3 hub-actions">
  <a class="btn btn-outline-secondary" href="<?= $bundleId>0?('sponsorship_bundle.php?id='.$bundleId):'sponsorship_agreements.php' ?>">Cancel</a>
  <button class="btn btn-brand" type="submit" <?= (int)$data['sponsor_id']<=0?'disabled':'' ?>><?= $scopeForNew==='match'?'Create agreement(s) for ticked fixtures':'Save agreement' ?></button>
 </div>
</form>
<script>(()=>{
 const complimentary=document.getElementById('agreementComplimentaryNew'),amount=document.getElementById('agreementAmountNew');
 complimentary?.addEventListener('change',()=>{
  if(complimentary.checked){if(Number(amount.value)>0)amount.dataset.chargeableValue=amount.value;amount.value='0.00';amount.readOnly=true;}
  else{amount.readOnly=false;if(Number(amount.value)===0&&amount.dataset.chargeableValue)amount.value=amount.dataset.chargeableValue;}
 });
 document.getElementById('agreementSelectAll')?.addEventListener('click',()=>document.querySelectorAll('.agreement-fixture-checkbox').forEach(c=>c.checked=true));
 document.getElementById('agreementSelectNone')?.addEventListener('click',()=>document.querySelectorAll('.agreement-fixture-checkbox').forEach(c=>c.checked=false));
 const amountLabel=document.getElementById('agreementAmountLabel');
 document.querySelectorAll('input[name="pricing_mode"]').forEach(r=>r.addEventListener('change',()=>{
  if(!amountLabel)return;
  amountLabel.textContent=document.getElementById('pricingTotalSplit')?.checked?'Total deal amount':'Agreed amount per fixture';
 }));
 const markPaid=document.getElementById('agreementMarkPaidNew'),markPaidDetails=document.getElementById('markPaidDetails');
 markPaid?.addEventListener('change',()=>markPaidDetails?.classList.toggle('d-none',!markPaid.checked));
})();</script>
<?php endif; ?>
<?php endif; ?>

<?php if($id>0): ?>
<section class="card shadow-sm border-0 mt-4 hub-section hub-table-card"><div class="card-header bg-transparent"><h2 class="h5 mb-0">Invoice</h2></div><div class="card-body">
 <?= invoices_render_admin_section($agreementInvoices,'<form method="post">'.csrf_field().'<input type="hidden" name="form_action" value="generate_invoice"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
</div></section>
<section class="card shadow-sm border-0 mt-4 hub-section hub-table-card"><div class="card-header bg-transparent"><h2 class="h5 mb-0">Agreement payments</h2></div><div class="card-body">
 <?php if(!empty($data['is_complimentary'])): ?><div class="alert alert-info mb-0">This is a complimentary agreement. No payment is due or can be recorded.</div><?php else: ?>
 <div class="hub-stripe-card border rounded p-3 mb-4" id="stripePaymentCard" data-agreement-id="<?= $id ?>" data-outstanding="<?= h(number_format($stripeOutstanding,2,'.','')) ?>" data-create-url="stripe_payment_link_create.php" data-send-url="stripe_payment_link_send.php" data-csrf="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
   <div><i class="fa-brands fa-stripe-s text-brand me-1" aria-hidden="true"></i><strong>Stripe payment link</strong><div class="small text-muted">Outstanding balance: <span id="stripeOutstandingLabel"><?= gbp($stripeOutstanding) ?></span></div></div>
   <button type="button" class="btn btn-brand btn-sm" id="stripeGenerateBtn" <?= $stripeOutstanding<=0?'disabled':'' ?>>Generate payment link</button>
  </div>
  <div id="stripeStatus" class="small mb-2"></div>
  <div id="stripeLinkResult" class="d-none">
   <div class="input-group input-group-sm mb-2">
    <input type="text" class="form-control" id="stripeLinkUrl" readonly>
    <button class="btn btn-outline-secondary" type="button" id="stripeCopyBtn">Copy link</button>
    <button class="btn btn-outline-secondary" type="button" id="stripeCopyMessageBtn">Copy message</button>
   </div>
   <div class="input-group input-group-sm">
    <input type="email" class="form-control" id="stripeSendEmail" placeholder="sponsor@example.com" value="<?= h((string)($agreement['sponsor_contact_email'] ?? '')) ?>">
    <button class="btn btn-outline-secondary" type="button" id="stripeSendBtn">Email to sponsor</button>
   </div>
   <div class="form-text">"Copy message" includes the sponsorship details (<?= h(implode(', ', array_column(stripe_agreement_context_lines($agreement), 'label'))) ?>) and the link — handy for WhatsApp or text message. The email includes the same details with a "Pay now" button.</div>
  </div>
  <?php if($stripeLinks): ?>
  <div class="table-responsive mt-3"><table class="table table-sm hub-data-table align-middle mb-0"><thead><tr><th>Created</th><th>Amount</th><th>Status</th><th>Sent to</th><th>Expires</th><th></th></tr></thead><tbody>
   <?php foreach($stripeLinks as $l): ?><?php
    $linkStatus=(string)$l['status'];
    if($linkStatus!=='complete')$linkStatus=stripe_payment_link_public_expires_at($l)>time()?'open':'expired';
   ?><tr>
    <td><?= h(date('d/m/Y H:i',strtotime((string)$l['created_at']))) ?></td>
    <td><?= gbp((float)$l['amount']) ?></td>
    <td><span class="badge bg-<?= $linkStatus==='complete'?'success':($linkStatus==='expired'?'secondary':'warning text-dark') ?>"><?= h(ucfirst($linkStatus)) ?></span></td>
    <td><?= h((string)($l['sent_to_email'] ?: '—')) ?></td>
    <td><?= h(date('d/m/Y H:i',stripe_payment_link_public_expires_at($l))) ?></td>
    <td class="text-end"><?php if($linkStatus==='open'): ?><div class="hub-actions hub-actions--end"><button type="button" class="btn btn-sm btn-outline-secondary stripe-copy-existing-link-btn" data-url="<?= h(stripe_payment_link_public_url($l)) ?>">Copy URL</button><button type="button" class="btn btn-sm btn-outline-danger stripe-cancel-link-btn" data-link-id="<?= (int)$l['id'] ?>" data-bundle-link="<?= !empty($l['is_bundle_link'])?'1':'0' ?>">Cancel</button></div><?php endif; ?></td>
   </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
 </div>
 <form class="row g-2 align-items-end mb-4" method="post" action="sponsorship_agreement_payment.php"><?= csrf_field() ?><input type="hidden" name="agreement_id" value="<?= $id ?>">
  <div class="col-md-3"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">£</span><input class="form-control" type="number" min="0.01" step="0.01" name="amount" required></div></div>
  <div class="col-md-3"><label class="form-label">Paid date</label><input class="form-control" type="date" name="paid_at" value="<?= date('Y-m-d') ?>" required></div>
  <div class="col-md-2"><label class="form-label">Method</label><select class="form-select" name="method"><option value="">Select…</option><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option><option value="Cheque">Cheque</option><option value="Card">Card</option><option value="Stripe">Stripe</option><option value="Other">Other</option></select></div>
  <div class="col-md-3"><label class="form-label">Note</label><input class="form-control" name="note"></div>
  <div class="col-md-1 d-grid"><button class="btn btn-success">Add</button></div>
 </form>
 <div class="table-responsive"><table class="table table-sm hub-data-table align-middle mb-0"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Note</th><th class="text-end">Action</th></tr></thead><tbody><?php foreach($agreementPayments as $payment): ?><tr><td><?= h(date('d/m/Y H:i',strtotime((string)$payment['recorded_at']))) ?></td><td><?= gbp((float)$payment['amount']) ?></td><td><?= h((string)$payment['method']) ?></td><td><?= h((string)$payment['note']) ?></td><td class="text-end"><?php if(!empty($payment['id'])): ?><form method="post" action="sponsorship_agreement.php?id=<?= $id ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="delete_payment"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger" formnovalidate data-confirm="Remove this <?= h(gbp((float)$payment['amount'])) ?> payment from the agreement? The outstanding balance will go back up." data-confirm-title="Remove payment?" data-confirm-action="Remove payment">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$agreementPayments): ?><tr><td colspan="5" class="text-center text-muted hub-record-empty">No payments recorded yet.</td></tr><?php endif; ?></tbody></table></div>
 <?php endif; ?>
</div></section>
<?php endif; ?>
<?php if($id>0&&empty($data['is_complimentary'])): ?>
<script>(()=>{
  const card=document.getElementById('stripePaymentCard');
  if(!card)return;
  const statusEl=document.getElementById('stripeStatus');
  const setStatus=(msg,tone)=>{statusEl.textContent=msg||'';statusEl.className='small mb-2'+(tone?(' text-'+tone):'');};
  const csrf=card.dataset.csrf;
  const generateBtn=document.getElementById('stripeGenerateBtn');
  const resultBox=document.getElementById('stripeLinkResult');
  const urlInput=document.getElementById('stripeLinkUrl');
  const copyBtn=document.getElementById('stripeCopyBtn');
  const copyMessageBtn=document.getElementById('stripeCopyMessageBtn');
  const emailInput=document.getElementById('stripeSendEmail');
  const sendBtn=document.getElementById('stripeSendBtn');
  let currentLinkId=null;
  let currentMessage='';

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
        body:'agreement_id='+encodeURIComponent(card.dataset.agreementId)+'&csrf_token='+encodeURIComponent(csrf)
      });
      const json=await response.json().catch(()=>null);
      if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not create a payment link.');}
      currentLinkId=json.link.id;
      currentMessage=json.link.message||'';
      urlInput.value=json.link.url;
      resultBox.classList.remove('d-none');
      if(json.link.sponsor_contact_email&&!emailInput.value){emailInput.value=json.link.sponsor_contact_email;}
      setStatus('Payment link ready for '+json.link.amount.toFixed(2)+'.','success');
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

  copyMessageBtn?.addEventListener('click',async()=>{
    if(!currentMessage){setStatus('Generate a payment link first.','danger');return;}
    try{
      await navigator.clipboard.writeText(currentMessage);
      setStatus('Message copied to clipboard.','success');
    }catch(error){
      setStatus('Could not copy the message — your browser may have blocked clipboard access.','danger');
    }
  });

  sendBtn?.addEventListener('click',async()=>{
    if(!currentLinkId){setStatus('Generate a payment link first.','danger');return;}
    const email=emailInput.value.trim();
    if(!email){setStatus('Enter an email address to send to.','danger');return;}
    sendBtn.disabled=true;
    const original=sendBtn.textContent;
    sendBtn.textContent='Sending…';
    try{
      const response=await fetch(card.dataset.sendUrl,{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:'link_id='+encodeURIComponent(currentLinkId)+'&email='+encodeURIComponent(email)+'&csrf_token='+encodeURIComponent(csrf)
      });
      const json=await response.json().catch(()=>null);
      if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not send the email.');}
      setStatus('Payment link emailed to '+email+'.','success');
    }catch(error){
      setStatus(error.message||'Could not send the email.','danger');
    }finally{
      sendBtn.disabled=false;
      sendBtn.textContent=original;
    }
  });

  document.querySelectorAll('.stripe-copy-existing-link-btn').forEach((btn)=>{
    btn.addEventListener('click',async()=>{
      const url=btn.dataset.url||'';
      if(!url){setStatus('No URL found for this payment link.','danger');return;}
      try{
        await navigator.clipboard.writeText(url);
      }catch(error){
        const fallback=document.createElement('textarea');
        fallback.value=url;
        fallback.setAttribute('readonly','');
        fallback.style.position='fixed';
        fallback.style.left='-9999px';
        document.body.appendChild(fallback);
        fallback.select();
        document.execCommand('copy');
        fallback.remove();
      }
      setStatus('Link copied to clipboard.','success');
    });
  });

  document.querySelectorAll('.stripe-cancel-link-btn').forEach((btn)=>{
    btn.addEventListener('click',async()=>{
      const confirmMsg=btn.dataset.bundleLink==='1'
        ?'This link was generated for a bundle covering several agreements. Cancelling it cancels payment for ALL of them, not just this one — continue?'
        :'Cancel this payment link? It will no longer be payable and you can generate a new one.';
      if(!(await window.hubConfirm(confirmMsg,{actionLabel:'Cancel link'})))return;
      btn.disabled=true;
      const original=btn.textContent;
      btn.textContent='Cancelling…';
      try{
        const response=await fetch('stripe_payment_link_expire.php',{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body:'link_id='+encodeURIComponent(btn.dataset.linkId)+'&csrf_token='+encodeURIComponent(csrf)
        });
        const json=await response.json().catch(()=>null);
        if(!response.ok||!json||!json.ok){throw new Error((json&&json.error)?json.error:'Could not cancel the link.');}
        window.location.reload();
      }catch(error){
        setStatus(error.message||'Could not cancel the link.','danger');
        btn.disabled=false;
        btn.textContent=original;
      }
    });
  });
})();</script>
<?php endif; ?>
<?php require_once __DIR__.'/footer.php'; ?>
