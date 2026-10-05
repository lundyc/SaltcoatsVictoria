<?php
// Bulk cancel/delete for the Agreements list checkboxes — the self-service version
// of what removing Neptune Social Club's agreements required doing by hand: find
// every matching row and act on each one via the normal single-agreement functions
// (same validation/legacy-sync as sponsorship_agreement.php), just looped.
require_once __DIR__.'/header.php';
require_once __DIR__.'/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: sponsorship_agreements.php');exit;}
if(!csrf_check()){header('Location: sponsorship_agreements.php?bulk_done=0&bulk_failed=0');exit;}

$action=(string)($_POST['bulk_action']??'');
$ids=array_map('intval',$_POST['agreement_ids']??[]);
$ids=array_values(array_unique(array_filter($ids,fn($v)=>$v>0)));

$done=0;$failed=0;
if($ids&&in_array($action,['cancel','delete'],true)){
 foreach($ids as $agreementId){
  $agreement=getSponsorshipAgreement($pdo,$agreementId);
  if(!$agreement){$failed++;continue;}
  try{
   if($action==='delete'){
    deleteSponsorshipAgreement($pdo,$agreementId);
   } else {
    $input=[
     'sponsor_id'=>(string)$agreement['sponsor_id'],'package_id'=>(string)$agreement['package_id'],
     'season_id'=>(string)($agreement['season_id']??''),'team_id'=>(string)($agreement['team_id']??''),
     'fixture_id'=>(string)($agreement['fixture_id']??''),'player_id'=>(string)($agreement['player_id']??''),
     'start_date'=>(string)($agreement['start_date']??''),'end_date'=>(string)($agreement['end_date']??''),
     'agreed_amount'=>(string)$agreement['agreed_amount'],'is_complimentary'=>(string)(int)$agreement['is_complimentary'],
     'status'=>'cancelled','display_order'=>(string)$agreement['display_order'],
     'logo_variant'=>(string)$agreement['logo_variant'],'notes'=>(string)($agreement['notes']??''),
    ];
    $result=saveSponsorshipAgreement($pdo,$agreementId,$input);
    if($result['errors']){$failed++;continue;}
   }
   $done++;
  }catch(Throwable $e){
   $failed++;
  }
 }
}

if($done>0){
 auditLog($pdo,$action==='delete'?'sponsorship_agreement_bulk_deleted':'sponsorship_agreement_bulk_cancelled',($action==='delete'?'Deleted ':'Cancelled ').$done.' agreement(s) in bulk'.($failed>0?" ({$failed} failed)":''));
}

header('Location: sponsorship_agreements.php?bulk_done='.$done.'&bulk_failed='.$failed.'&bulk_action='.urlencode($action));
exit;
