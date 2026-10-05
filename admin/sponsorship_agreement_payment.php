<?php
declare(strict_types=1);

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments'])) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/lib/functions.php';
require_once __DIR__.'/lib/sponsorship_catalog.php';
require_once __DIR__.'/lib/match_sponsorship.php';
if(!hub_auth_is_authenticated()){header('Location: login.php');exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!csrf_check()){http_response_code(400);exit('Invalid request.');}
ensureSponsorshipCatalogSchema($pdo);
$agreementId=(int)($_POST['agreement_id']??0);$amount=(float)($_POST['amount']??0);$paidAt=(string)($_POST['paid_at']??'');
$agreement=getSponsorshipAgreement($pdo,$agreementId);
if(!$agreement||$amount<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$paidAt)){http_response_code(400);exit('Invalid payment details.');}
if((int)($agreement['is_complimentary']??0)===1){http_response_code(400);exit('Payments cannot be recorded against a complimentary agreement.');}
$method=trim((string)($_POST['method']??''))?:null;
$note=trim((string)($_POST['note']??''))?:null;

// Agreements synced from a legacy player/match sponsorship roll their payment
// history through that legacy table (see getAgreementPayments() in
// lib/stripe.php) — write to the same table the page reads from, or a
// manually-recorded payment silently vanishes from the agreement.
$legacySource=(string)($agreement['legacy_source']??'');
$legacyId=(int)($agreement['legacy_id']??0);

if($legacySource==='match'&&$legacyId>0){
  $matchSponsorship=getMatchSponsorshipById($pdo,$legacyId);
  if(!$matchSponsorship){http_response_code(400);exit('Linked match sponsorship not found.');}
  $stmt=$pdo->prepare('INSERT INTO match_sponsorship_payments(match_sponsorship_id,season_id,amount,paid_at,method,note) VALUES(:id,:season_id,:amount,:paid_at,:method,:note)');
  $stmt->execute([':id'=>$legacyId,':season_id'=>(int)$matchSponsorship['season_id'],':amount'=>$amount,':paid_at'=>$paidAt,':method'=>$method,':note'=>$note]);
  recomputeMatchPaidFlag($pdo,$legacyId);
} elseif($legacySource==='player'&&$legacyId>0){
  $seasonStmt=$pdo->prepare('SELECT season_id FROM sponsorships WHERE id = :id');
  $seasonStmt->execute([':id'=>$legacyId]);
  $seasonId=$seasonStmt->fetchColumn();
  $stmt=$pdo->prepare('INSERT INTO sponsorship_payments(sponsorship_id,amount,paid_at,method,note,season_id) VALUES(:id,:amount,:paid_at,:method,:note,:season_id)');
  $stmt->execute([':id'=>$legacyId,':amount'=>$amount,':paid_at'=>$paidAt,':method'=>$method,':note'=>$note,':season_id'=>$seasonId!==false?$seasonId:null]);
  recomputePaidFlag($pdo,$legacyId);
} else {
  $stmt=$pdo->prepare('INSERT INTO sponsorship_agreement_payments(agreement_id,amount,paid_at,method,note) VALUES(:agreement,:amount,:paid_at,:method,:note)');
  $stmt->execute([':agreement'=>$agreementId,':amount'=>$amount,':paid_at'=>$paidAt,':method'=>$method,':note'=>$note]);
}

auditLog($pdo,'sponsorship_agreement_payment_added',"Added payment of £".number_format($amount,2)." for agreement #{$agreementId} (".(string)($agreement['sponsor_name']??'').')');

header('Location: sponsorship_agreement.php?id='.$agreementId.'&payment_saved=1');
exit;
