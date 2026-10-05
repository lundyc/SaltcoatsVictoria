<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (!hub_auth_is_authenticated()) { header('Location: /admin/login.php'); exit; }
if (!hub_auth_has_capability('matchday')) { http_response_code(403); exit('Access denied.'); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/pdf_importer.php';
require_once __DIR__ . '/lib/matchday_record.php';
pdf_import_schema($pdo);
matchday_record_ensure_schema($pdo);
$userId=(int)(hub_auth_current_user()['account_id']??hub_auth_current_user()['id']??0);
$id=(int)($_GET['id']??$_POST['id']??0);
$message=''; $error='';

/** Plain-English state for a report not yet in the wizard. @return array{0:string,1:string,2:string} label, tone, hint */
function pdf_import_stage(array $row): array {
    switch ((string)$row['status']) {
        case 'queued':       return ['Waiting to be read','secondary','This PDF is in the queue. Press "Read the queued PDFs" at the top of the page.'];
        case 'failed':       return ['Couldn’t read the PDF','danger','The PDF could not be read automatically. Fix the details by hand below, or re-read it.'];
        case 'skipped':      return ['Skipped','secondary','You set this report aside. Press "Re-read the PDF" to bring it back.'];
        case 'pending':      return ['Importing…','info','The match record is saved. The match pages are being brought up to date.'];
        case 'undo_pending': return ['Undoing…','info','The match pages are being put back to how they were.'];
        case 'imported':     return ['Imported','success','This report is part of the match record on the website. To change what was imported, undo it and import again.'];
        case 'undone':       return ['Import undone','secondary','Nothing from this report is on the website. Your player links were kept. Use “Read again & re-import” to run it back through the wizard.'];
        default:             return [ucfirst((string)$row['status']),'secondary',''];
    }
}

if (isset($_GET['pdf'])) {
    try {
        $row=pdf_import_get($pdo,(int)$_GET['pdf']); $path=pdf_import_document_path($row);
        if (!hash_equals($row['sha256'],hash_file('sha256',$path))) throw new RuntimeException('This source file changed. Scan the new version first.');
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="match-report.pdf"');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
    } catch (Throwable $e) { http_response_code(404); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $action=(string)($_POST['action']??'');
    $ajax=isset($_POST['ajax']);
    try {
        if (!csrf_check()) throw new RuntimeException('Your session expired. Reload the page and try again.');
        if ($action==='scan') { $r=pdf_import_scan($pdo); $message=$r['added'].' new PDF(s) added to the queue. '.$r['duplicates'].' were already here. '.$r['waiting'].' skipped (not a finished PDF, too big, or unreadable).'; }
        elseif ($action==='process') { session_write_close(); $worked=pdf_import_process_one($pdo); $projected=pdf_import_project_one($pdo); $message=($worked||$projected)?'Read one report.':'Nothing left to read.'; }
        elseif ($action==='upload') {
            $file=$_FILES['pdf']??null;
            if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('PDF upload failed.');
            if ($file['size']>PDF_IMPORT_MAX_BYTES || strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='pdf') throw new RuntimeException('Upload a PDF no larger than 20 MB.');
            $fh=fopen($file['tmp_name'],'rb'); $magic=fread($fh,5); fclose($fh);
            if ($magic!=='%PDF-') throw new RuntimeException('That file is not a PDF.');
            if (!is_dir(pdf_import_root()) && !mkdir(pdf_import_root(),0770,true)) throw new RuntimeException('Could not create PDF_imports.');
            $clean=preg_replace('/[^A-Za-z0-9 ._-]/','_',basename($file['name']));
            $clean=substr((string)$clean,0,190) ?: 'match-report.pdf';
            if (!preg_match('/\.pdf$/i',$clean)) $clean.='.pdf';
            $stem=preg_replace('/\.pdf$/i','',$clean); $name=$clean; $n=2;
            while (is_file(pdf_import_root().'/'.$name) && !hash_equals(hash_file('sha256',pdf_import_root().'/'.$name),hash_file('sha256',$file['tmp_name']))) { $name=$stem.' ('.($n++).').pdf'; }
            if (!is_file(pdf_import_root().'/'.$name) && !move_uploaded_file($file['tmp_name'],pdf_import_root().'/'.$name)) throw new RuntimeException('Could not save the PDF in PDF_imports.');
            touch(pdf_import_root().'/'.$name,time()-11);   // a finished HTTP upload is stable straight away
            pdf_import_scan($pdo); $message='PDF uploaded and added to the queue.';
        }
        elseif ($action==='correct') { pdf_import_correct($pdo,$id,$_POST,$userId); $message='Saved. Step back through the wizard to check it, then import.'; }
        elseif ($action==='create_player') {
          $playerName=trim((string)($_POST['player_name']??''));
          if ($playerName==='') throw new RuntimeException('Player name is required.');
          $st=$pdo->prepare('SELECT id FROM players WHERE name=? LIMIT 1'); $st->execute([$playerName]);
          if ($st->fetchColumn()) throw new RuntimeException('A player with that name already exists. Refresh the dropdown and select them.');
          $joinedAt=trim((string)($_POST['joined_at']??''));
          if ($joinedAt!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$joinedAt)) throw new RuntimeException('Joined date must use YYYY-MM-DD format.');
          $pdo->prepare("INSERT INTO players (name,status,joined_at,active) VALUES (?, 'trialist', ?, 1)")->execute([$playerName,$joinedAt?:null]);
            $_SESSION['pdf_import_notice']=$playerName.' was added as a trialist. Select them from the player dropdown.';
            header('Location: /admin/pdf_importer.php?id='.$id.'&step=3'); exit;
        }
        elseif ($action==='wizard_step') {
            $done=pdf_import_wizard_save($pdo,$id,(string)($_POST['step_key']??''),$_POST,$userId);
            $target=(($_POST['nav']??'next')==='back') ? $done-1 : $done+1;
            header('Location: /admin/pdf_importer.php?id='.$id.'&step='.max(1,min(6,$target))); exit;
        }
        elseif ($action==='import') {
            $srcName=pdf_import_display_name(pdf_import_get($pdo,$id)['source_name']);
            pdf_import_review($pdo,$id,$_POST,$userId);
            $fixtureId=pdf_import_apply($pdo,$id,$userId);
            for ($i=0;$i<6 && pdf_import_project_one($pdo);$i++);
            $message='Imported “'.$srcName.'” into fixture #'.$fixtureId.'. The match pages are updating.';
            $id=0;   // back to the list, not this report's "imported" page
        }
        elseif ($action==='apply') { $fixtureId=pdf_import_apply($pdo,$id,$userId); for ($i=0;$i<6 && pdf_import_project_one($pdo);$i++); $message='Imported into fixture #'.$fixtureId.'. The match pages will catch up within a minute.'; }
        elseif ($action==='undo') { pdf_import_undo($pdo,$id,$userId); for ($i=0;$i<6 && pdf_import_project_one($pdo);$i++); $message='Import undone. Your player links were kept.'; $id=0; }
        elseif ($action==='redo') {
            // Undo (if still imported), re-read the PDF, then open the wizard.
            $r=pdf_import_get($pdo,$id);
            if ($r['status']==='imported') { pdf_import_undo($pdo,$id,$userId); for ($i=0;$i<6 && pdf_import_project_one($pdo);$i++); }
            $path=pdf_import_document_path(pdf_import_get($pdo,$id));
            $report=null; $lastErr='';
            for ($try=0;$try<3 && !$report;$try++) {
                try { $report=pdf_import_extract($path); } catch (Throwable $e) { $lastErr=$e->getMessage(); usleep(500000); }
            }
            if ($report) {
                $report['validation']=pdf_import_validate($report);
                $pdo->prepare("UPDATE historical_pdf_imports SET report_json=?,parser_version=?,review_json=NULL,before_json=NULL,after_json=NULL,fixture_id=NULL,created_fixture=0,status='review',error=NULL WHERE id=?")
                    ->execute([pdf_import_json($report),PDF_IMPORT_VERSION,$id]);
                $_SESSION['pdf_import_notice']='Read again from the PDF. Work through the wizard and import it.';
                header('Location: /admin/pdf_importer.php?id='.$id.'&step=1'); exit;
            }
            // extractor was busy — hand it to the queue / cron, don't leave stale data in the wizard
            $pdo->prepare("UPDATE historical_pdf_imports SET status='queued',report_json=NULL,review_json=NULL,before_json=NULL,after_json=NULL,fixture_id=NULL,created_fixture=0,error=NULL WHERE id=?")->execute([$id]);
            $_SESSION['pdf_import_notice']='Queued for a fresh read — it will be ready within a minute. Refresh, or press "Read the queued PDFs".';
            header('Location: /admin/pdf_importer.php?id='.$id); exit;
        }
        elseif ($action==='retry') {
            $row=pdf_import_get($pdo,$id);
            if (in_array($row['status'],['pending','undo_pending'],true)) $pdo->prepare('UPDATE historical_pdf_imports SET error=NULL WHERE id=?')->execute([$id]);
            elseif (in_array($row['status'],['failed','skipped','review','ready','undone'],true)) $pdo->prepare("UPDATE historical_pdf_imports SET status='queued',review_json=NULL,error=NULL WHERE id=?")->execute([$id]);
            else throw new RuntimeException('This report cannot be re-read in its current state.');
            $message='Report put back in the queue to be read again.';
        }
        elseif ($action==='skip') { $pdo->prepare("UPDATE historical_pdf_imports SET status='skipped' WHERE id=? AND status IN ('queued','review','ready','failed')")->execute([$id]); $message='Report skipped.'; }
        elseif ($action==='remove') { if (empty($_POST['confirm_remove'])) throw new RuntimeException('Tick the box to confirm removing this report.'); pdf_import_remove($pdo,$id); $message='Report removed from the list.'; $id=0; }
        elseif ($action==='requeue_all') { $n=$pdo->exec("UPDATE historical_pdf_imports SET status='queued',review_json=NULL,error=NULL WHERE status IN ('review','ready','failed','skipped','undone')"); $message=$n.' report(s) put back in the queue. Press "Read the queued PDFs" to run them through the reader again.'; $id=0; }
        else throw new RuntimeException('Unknown action.');
        if ($ajax) { header('Content-Type: application/json'); echo pdf_import_json(['ok'=>true,'message'=>$message]); exit; }
        $_SESSION['pdf_import_notice']=$message;
        header('Location: /admin/pdf_importer.php'.($id?'?id='.$id:'')); exit;
    } catch (Throwable $e) {
        $error=$e->getMessage();
        if ($ajax) { http_response_code(422); header('Content-Type: application/json'); echo pdf_import_json(['ok'=>false,'message'=>$error]); exit; }
        $_SESSION['pdf_import_error']=$error;
        $back='/admin/pdf_importer.php'.($id?'?id='.$id:'');
        if (($_POST['step_num']??'')!=='') $back.='&step='.(int)$_POST['step_num'];
        header('Location: '.$back); exit;
    }
}
$message=$message ?: ($_SESSION['pdf_import_notice']??''); unset($_SESSION['pdf_import_notice']);
$error=$error ?: ($_SESSION['pdf_import_error']??''); unset($_SESSION['pdf_import_error']);
$pageHero=['eyebrow'=>'Match reports · One-off import tool','title'=>'Import match-report PDFs','subtitle'=>'Turn old COMET match-report PDFs into full match records on the website.','actions'=>[]];
require_once __DIR__.'/header.php';
$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$icn=static function(string $n): string {
    $p=[
        'doc'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'site'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'check'=>'<path d="M20 6 9 17l-5-5"/>',
        'right'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
        'left'=>'<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'ext'=>'<path d="M15 3h6v6M10 14 21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'target'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
        'search'=>'<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    ][$n]??'';
    return '<svg class="icn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$p.'</svg>';
};
$counts=$pdo->query('SELECT status,COUNT(*) FROM historical_pdf_imports GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$statusNames=['queued'=>'Waiting to be read','review'=>'In the wizard','ready'=>'Ready to import','pending'=>'Importing','undo_pending'=>'Undoing','imported'=>'Imported','undone'=>'Undone','failed'=>'Couldn’t read','skipped'=>'Skipped'];
?>
<style>
.pdfw{
  --w-ink:#1f1a1d; --w-muted:#726a72; --w-line:rgba(75,8,24,.11);
  --w-surface:#fff; --w-surface-2:#faf6ef;
  --w-brand:#6a2036; --w-brand-2:#4b0818; --w-brand-soft:rgba(106,32,54,.07);
  --w-accent:#b99b61;
  --w-ok:#2f7d55; --w-ok-soft:#e9f4ee;
  --w-warn:#8a5d10; --w-warn-soft:#fbf0d7;
  --w-danger:#8f1f2f;
  --w-radius:15px; --w-radius-sm:11px;
  --w-shadow:0 1px 2px rgba(31,26,29,.04), 0 10px 28px rgba(31,26,29,.055);
  color:var(--w-ink);
}
.pdfw .btn{border-radius:9px}
.pdfw .icn{width:1em;height:1em;vertical-align:-.13em;flex:none}
.pdfw code{background:var(--w-brand-soft);color:var(--w-brand-2);padding:.08em .38em;border-radius:5px;font-size:.88em}

/* toolbar (scan / upload / …) */
.pdfw-bar{background:var(--w-surface);border:1px solid var(--w-line);border-radius:var(--w-radius);box-shadow:var(--w-shadow);padding:1.1rem 1.25rem;margin-bottom:1.75rem}
.pdfw-bar__row{display:flex;gap:.55rem;flex-wrap:wrap;align-items:center}
.pdfw-bar__note{color:var(--w-muted);font-size:.82rem;margin:.85rem 0 0}

/* generic card */
.wiz-card{background:var(--w-surface);border:1px solid var(--w-line);border-radius:var(--w-radius);box-shadow:var(--w-shadow);overflow:hidden;margin-bottom:1.1rem}
.wiz-card__hd{padding:.7rem 1.1rem;border-bottom:1px solid var(--w-line);font-weight:700;font-size:.82rem;display:flex;align-items:center;gap:.5rem;background:var(--w-surface-2)}
.wiz-card__bd{padding:1.1rem}
.wiz-eyebrow{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--w-muted)}

/* header + stepper */
.wiz-head{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:baseline;margin-bottom:1.4rem}
.wiz-head h2{font-size:1.15rem;font-weight:700;margin:0;word-break:break-word}
.wiz-head .step-of{font-size:.78rem;font-weight:700;color:var(--w-muted);white-space:nowrap}
.wiz-steps{position:relative;display:flex;justify-content:space-between;list-style:none;margin:0 0 2rem;padding:0}
.wiz-steps li{position:relative;flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;min-width:0}
.wiz-steps li:not(:first-child)::before{content:"";position:absolute;top:17px;right:50%;width:100%;height:2px;background:var(--w-line);z-index:0}
.wiz-steps li.s-done:not(:first-child)::before,.wiz-steps li.s-now:not(:first-child)::before{background:var(--w-brand)}
.wiz-steps a,.wiz-steps span{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;gap:.45rem;text-decoration:none;color:var(--w-muted);font-size:.72rem;font-weight:700;line-height:1.25;max-width:8rem}
.wiz-steps a:hover{color:var(--w-brand)}
.wiz-steps .dot{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--w-surface);border:2px solid var(--w-line);color:var(--w-muted);font-size:.85rem;font-weight:700}
.wiz-steps .s-done .dot{background:var(--w-brand);border-color:var(--w-brand);color:#fff}
.wiz-steps .s-now .dot{border-color:var(--w-brand);color:var(--w-brand);box-shadow:0 0 0 4px var(--w-brand-soft)}
.wiz-steps .s-done a,.wiz-steps .s-now span,.wiz-steps .s-now a{color:var(--w-ink)}
@media(max-width:620px){.wiz-steps .lbl{display:none}}

.wiz-title{font-size:1.3rem;font-weight:700;margin:0 0 .3rem}
.wiz-lead{color:var(--w-muted);margin-bottom:1.5rem;max-width:60ch}

/* the "PDF says" summary */
.wiz-pdfcard{background:var(--w-surface-2);border:1px solid var(--w-line);border-radius:var(--w-radius);padding:1.1rem 1.25rem;margin-bottom:1.25rem}
.wiz-pdfcard .score{font-size:1.15rem;font-weight:700}
.wiz-pdfcard .meta{color:var(--w-muted);font-size:.86rem;margin:.35rem 0 0}

/* best-match callout */
.wiz-best{display:flex;gap:.9rem;align-items:flex-start;border:1px solid var(--w-line);border-left:4px solid var(--w-muted);border-radius:var(--w-radius-sm);padding:.95rem 1.1rem;margin-bottom:1.4rem;background:var(--w-surface)}
.wiz-best--yes{border-left-color:var(--w-ok);background:var(--w-ok-soft)}
.wiz-best--maybe{border-left-color:var(--w-accent);background:var(--w-warn-soft)}
.wiz-best__ic{flex:none;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#fff;border:1px solid var(--w-line);color:var(--w-ok)}
.wiz-best--maybe .wiz-best__ic{color:var(--w-warn)}
.wiz-best--none .wiz-best__ic{color:var(--w-muted)}
.wiz-best b{display:block;font-size:.82rem}
.wiz-best .fx{margin:.15rem 0 .6rem;font-size:.92rem}

/* filters toolbar */
.wiz-filters{display:flex;flex-wrap:wrap;gap:.7rem;align-items:flex-end;margin-bottom:.8rem}
.wiz-filters .fld{display:flex;flex-direction:column;gap:.2rem}
.wiz-filters label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--w-muted)}
.wiz-filters .grow{flex:1;min-width:12rem}

/* tables (fixture list + XI + compare) */
.wiz-tw{border:1px solid var(--w-line);border-radius:var(--w-radius-sm);overflow:hidden;background:var(--w-surface)}
.wiz-tw--scroll{max-height:25rem;overflow:auto}
table.wiz-t{width:100%;border-collapse:separate;border-spacing:0;font-size:.875rem}
table.wiz-t thead th{position:sticky;top:0;z-index:3;background:var(--w-surface-2);text-align:left;font-weight:700;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--w-muted);padding:.62rem .85rem;border-bottom:1px solid var(--w-line);white-space:nowrap}
table.wiz-t td{padding:.6rem .85rem;border-bottom:1px solid var(--w-line);vertical-align:middle}
table.wiz-t tbody tr:last-child td{border-bottom:0}
table.wiz-t td.num{color:var(--w-muted);font-variant-numeric:tabular-nums;white-space:nowrap}
table.wiz-t td.nowrap{white-space:nowrap}
table.wiz-t td.comp{color:var(--w-muted);max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wiz-fxrow{cursor:pointer}
.wiz-fxrow:hover td{background:var(--w-brand-soft)}
.wiz-fxrow.is-hidden{display:none}
.wiz-fxrow.is-best td{background:var(--w-ok-soft)}
.wiz-fxrow:has(input:checked) td{background:var(--w-brand-soft);box-shadow:inset 3px 0 0 var(--w-brand)}
.wiz-fxrow td.rcell{width:2.4rem;text-align:center}
.wiz-fxrow input[type=radio]{width:1.05rem;height:1.05rem;accent-color:var(--w-brand)}
tr.wiz-fxgrp td{position:sticky;top:32px;z-index:2;background:var(--w-surface-2);font-weight:700;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--w-ink);padding:.42rem .85rem;border-bottom:1px solid var(--w-line)}
tr.wiz-fxgrp.is-hidden{display:none}
.fx-pager{display:flex;align-items:center;justify-content:center;gap:.6rem;margin:.6rem 0 0}
.fx-pager__info{font-size:.8rem;color:var(--w-muted)}

/* "create new fixture" row + choice cards */
.wiz-newfx,.wiz-choice label{display:grid;grid-template-columns:1.3rem 1fr;gap:.7rem;align-items:start;border:1.5px solid var(--w-line);border-radius:var(--w-radius-sm);padding:.85rem 1rem;cursor:pointer;background:var(--w-surface);transition:border-color .12s,background .12s}
.wiz-newfx{margin-bottom:1rem}
.wiz-newfx:hover,.wiz-choice label:hover{border-color:var(--w-accent)}
.wiz-newfx:has(input:checked),.wiz-choice label:has(input:checked){border-color:var(--w-brand);background:var(--w-brand-soft);box-shadow:0 0 0 3px var(--w-brand-soft)}
.wiz-choice label:has(input:disabled){opacity:.6;cursor:not-allowed}
.wiz-newfx input,.wiz-choice input{margin-top:.15rem;accent-color:var(--w-brand)}
.wiz-choice{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;margin:1.35rem 0}
.wiz-choice .t{font-weight:700;display:block;margin-bottom:.15rem}
.wiz-choice .d{font-size:.82rem;color:var(--w-muted)}

/* two-column PDF vs website */
.wiz-two{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem}
.wiz-pane{border:1px solid var(--w-line);border-radius:var(--w-radius-sm);overflow:hidden;background:var(--w-surface)}
.wiz-pane__hd{padding:.6rem .9rem;background:var(--w-surface-2);border-bottom:1px solid var(--w-line);font-weight:700;font-size:.78rem;display:flex;align-items:center;gap:.45rem}
.wiz-pane__bd{padding:.5rem .9rem}
table.wiz-t.wiz-xi td:first-child{color:var(--w-muted);font-variant-numeric:tabular-nums;width:2.5rem}
tr.wiz-diff td{background:var(--w-warn-soft)}

/* event rows */
.wiz-evt{display:flex;gap:.7rem;align-items:baseline;padding:.5rem 0;border-bottom:1px solid var(--w-line);font-size:.875rem}
.wiz-evt:last-child{border-bottom:0}
.wiz-evt .min{flex:0 0 2.7rem;font-weight:700;font-variant-numeric:tabular-nums;color:var(--w-brand)}
.wiz-evt .who .mut{color:var(--w-muted)}
.wiz-empty{color:var(--w-muted);font-size:.85rem;padding:.6rem 0}

/* per-step reading notes (soft, non-blocking) */
.wiz-notes{border:1px solid var(--w-line);border-left:4px solid var(--w-warn);background:var(--w-warn-soft);border-radius:var(--w-radius-sm);padding:.85rem 1.05rem;margin:0 0 1.4rem;font-size:.87rem;color:var(--w-ink)}
.wiz-notes__hd{font-weight:700;margin:0 0 .4rem}
.wiz-notes ul{margin:0 0 .5rem;padding-left:1.15rem}
.wiz-notes li{margin:.2rem 0}
.wiz-notes__ft{margin:0;color:var(--w-muted);font-size:.82rem}

/* player linking */
.wiz-player{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--w-line)}
.wiz-player:last-child{border-bottom:0}
.wiz-player .nm small{color:var(--w-muted)}

/* review (step 6) */
.wiz-review{border:1px solid var(--w-line);border-radius:var(--w-radius-sm);overflow:hidden;background:var(--w-surface)}
.wiz-review>div{display:grid;grid-template-columns:10rem 1fr;gap:1rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--w-line)}
.wiz-review>div:last-child{border-bottom:0}
.wiz-review b{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--w-muted);font-weight:700}
.wiz-review--backup{background:var(--w-surface-2)}
@media(max-width:620px){.wiz-review>div{grid-template-columns:1fr;gap:.15rem}}

/* nav */
.wiz-actions{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:1.9rem;padding-top:1.3rem;border-top:1px solid var(--w-line)}
.wiz-actions .btn{display:inline-flex;align-items:center;gap:.4rem}

/* tags */
.tag{display:inline-flex;align-items:center;gap:.25rem;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:.16rem .45rem;border-radius:6px;background:var(--w-brand-soft);color:var(--w-brand);white-space:nowrap;vertical-align:middle}
.tag--mut{background:#f0edf0;color:var(--w-muted)}
.tag--ok{background:var(--w-ok-soft);color:var(--w-ok)}
.tag--warn{background:var(--w-warn-soft);color:var(--w-warn)}

/* footer bits */
.wiz-foot{margin-top:1.75rem;padding-top:1.25rem;border-top:1px solid var(--w-line)}
.wiz-foot details>summary{font-size:.85rem;color:var(--w-muted);cursor:pointer}
.pdf-preview{width:100%;height:520px;border:1px solid var(--w-line);border-radius:var(--w-radius-sm);margin-top:.6rem}
.pdf-import-actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.pdf-import-table td{vertical-align:middle}
.pdf-import-source{white-space:pre-wrap;max-height:340px;overflow:auto;font-size:.8rem}
@media(max-width:820px){.wiz-choice,.wiz-two,.wiz-player{grid-template-columns:1fr}}
</style>
<div class="pdfw">
<?php if($message):?><div class="alert alert-success" role="status"><?=$esc($message)?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=$esc($error)?></div><?php endif;?>
<div id="pdfProgress" class="alert alert-info d-none" role="status" aria-live="polite"></div>
<div class="pdfw-bar">
<div class="pdfw-bar__row">
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="scan"><button class="btn btn-brand btn-sm">Check the folder for new PDFs</button></form>
<button type="button" class="btn btn-outline-primary btn-sm" id="processQueue">Read the queued PDFs (<?= (int)($counts['queued']??0) ?>)</button>
<label class="btn btn-outline-secondary btn-sm mb-0">Upload PDFs<input id="pdfUploads" type="file" accept="application/pdf,.pdf" multiple hidden></label>
<form method="post" class="d-inline" data-confirm="Re-read every report that has not been imported yet? Nothing you have not already imported is lost." data-confirm-action="Re-read all" data-confirm-class="btn-primary"><?=csrf_field()?><input type="hidden" name="action" value="requeue_all"><button class="btn btn-outline-secondary btn-sm">Re-read all reports</button></form>
<a href="/admin/pdf_importer.php" class="btn btn-outline-secondary btn-sm">See all reports</a>
</div>
<p class="pdfw-bar__note">Drop COMET match-report PDFs into the <code>PDF_imports</code> folder, or upload them here (20&nbsp;MB per file). They are read automatically in the background. <strong>Nothing on the website changes until the last step of the wizard.</strong></p>
</div>
<?php if($id):
try {
$row=pdf_import_get($pdo,$id);
$report=$row['report_json']?json_decode($row['report_json'],true):null;
$name=$esc(pdf_import_display_name($row['source_name']));

/* ============ report not read yet, or failed ============ */
if(!$report):
[$sl,$stone,$shint]=pdf_import_stage($row); ?>
<div class="wiz-head"><h2><?=$name?></h2><span class="tag tag--mut"><?=$esc($sl)?></span></div>
<?php if($shint):?><p class="wiz-lead"><?=$esc($shint)?></p><?php endif;?>
<?php if($row['error']):?><div class="alert alert-warning">The reader said: <?=$esc($row['error'])?></div><?php endif;?>
<div class="pdf-import-actions mt-3">
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="retry"><button class="btn btn-outline-secondary btn-sm">Re-read the PDF</button></form>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="remove"><label class="me-2 small"><input type="checkbox" name="confirm_remove" value="1" required> Also delete the stored PDF</label><button class="btn btn-outline-danger btn-sm">Remove from the list</button></form>
</div>
<?php
/* ============ already imported / importing / undone ============ */
elseif(in_array($row['status'],['pending','undo_pending','imported','undone'],true)):
[$sl,$stone,$shint]=pdf_import_stage($row);
$backup=$row['fixture_id']?pdf_import_backup_latest((int)$row['fixture_id']):''; ?>
<div class="wiz-head"><h2><?=$name?></h2><span class="tag <?=$row['status']==='imported'?'tag--ok':'tag--mut'?>"><?=$esc($sl)?></span></div>
<p class="wiz-lead"><?=$esc($shint)?></p>
<?php if($row['error']):?><div class="alert alert-warning">The reader said: <?=$esc($row['error'])?></div><?php endif;?>
<div class="wiz-card"><div class="wiz-card__bd">
<?php if($row['status']==='imported'):?>
<p class="wiz-title" style="color:var(--w-ok)"><?=$icn('check')?> Imported</p>
<p class="mb-0">This report is now part of <a href="/admin/match.php?id=<?=(int)$row['fixture_id']?>">fixture #<?=(int)$row['fixture_id']?></a>.</p>
<?php elseif($row['status']==='undone'):?>
<p class="wiz-title">Import undone</p><p class="mb-2">Nothing from this report is on the website. Your player links were kept.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="redo"><button class="btn btn-brand"><?=$icn('right')?> Read again &amp; re-import</button></form>
<?php else:?>
<p class="wiz-title">Importing…</p><p class="mb-0">The match record is saved; the match pages are catching up.</p>
<?php endif;?>
<?php if($backup):?><p class="mt-3 mb-0" style="font-size:.83rem;color:var(--w-muted)"><?=$icn('shield')?> Backup of the fixture’s previous data: <code><?=$esc(str_replace(dirname(__DIR__,3).'/','',$backup))?></code>. “Undo this import” puts it all back.</p><?php endif;?>
</div></div>
<div class="pdf-import-actions mt-3">
<?php if($row['fixture_id']):?><a href="/admin/match.php?id=<?=(int)$row['fixture_id']?>" class="btn btn-outline-primary btn-sm">Open fixture #<?=(int)$row['fixture_id']?></a><?php endif;?>
<?php if($row['status']==='imported'):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="redo"><button class="btn btn-brand btn-sm"><?=$icn('right')?> Re-import from scratch</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="undo"><button class="btn btn-outline-danger btn-sm">Undo this import</button></form><?php endif;?>
<?php if(in_array($row['status'],['pending','undo_pending'],true)&&$row['error']):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="retry"><button class="btn btn-outline-secondary btn-sm">Finish updating the match pages</button></form><?php endif;?>
</div>
<?php
else:
/* ============ THE WIZARD ============ */
$events=$report['events']; usort($events,static fn($a,$b)=>pdf_import_minute_sort($a['minute'])<=>pdf_import_minute_sort($b['minute']));
/* Only hard validation errors keep the wizard shut. Softer reader notes — an
   unreadable line, a card/goal section with no timed entry — are shown inside
   the wizard on the step they belong to, so the import isn't blocked over them. */
$pdfProblems=pdf_import_validate($report);
$readNotes=array_values(array_unique(array_merge($report['notices']??[],$report['warnings']??[])));
$notesFor=static function(array $patterns) use($readNotes){
  return array_values(array_filter($readNotes,static function($n) use($patterns){
    foreach($patterns as $p) if(preg_match($p,$n)) return true; return false;
  }));
};
$notesPanel=static function(array $notes,string $intro) use($esc){
  if(!$notes) return;
  echo '<div class="wiz-notes"><p class="wiz-notes__hd">'.$esc($intro).'</p><ul>';
  foreach($notes as $n) echo '<li>'.$esc($n).'</li>';
  echo '</ul><p class="wiz-notes__ft">These don&rsquo;t stop the import. To add anything the reader missed, use <strong>&ldquo;The PDF was read wrong?&rdquo;</strong> at the foot of the page, then step through again.</p></div>';
};
$oppName=$report['opponent'];
$homeName=$report['is_home']?'Saltcoats Victoria':$oppName;
$awayName=$report['is_home']?$oppName:'Saltcoats Victoria';

if($pdfProblems): /* must be fixed before the wizard */ ?>
<div class="wiz-head"><h2><?=$name?></h2><span class="tag tag--warn">Needs fixing first</span></div>
<div class="alert alert-warning"><strong>The PDF was read with <?=count($pdfProblems)?> problem<?=count($pdfProblems)===1?'':'s'?>:</strong>
<ul class="mb-2 mt-1"><?php foreach($pdfProblems as $p):?><li><?=$esc($p)?></li><?php endforeach;?></ul>
<p class="mb-0">Open <strong>“The PDF was read wrong?”</strong> below and press <strong>“Save these fixes”</strong> — that re-checks the read and usually clears this. Then the wizard opens.</p></div>
<?php $esc_kept=$esc; require __DIR__.'/partials/pdf_import_corrections.php'; $esc=$esc_kept; ?>
<div class="pdf-import-actions mt-3">
<a class="btn btn-outline-secondary btn-sm" href="?pdf=<?=$id?>" target="_blank" rel="noopener"><?=$icn('ext')?> Open the original PDF</a>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="retry"><button class="btn btn-outline-secondary btn-sm">Re-read the PDF from scratch</button></form>
</div>
<?php
else:
$w=pdf_import_wizard_read($row);
$stepNames=[1=>'Match &amp; fixture',2=>'Match details',3=>'Starting XI',4=>'Substitutes',5=>'Events',6=>'Review &amp; import'];
$maxAllowed=min(6,(int)$w['step_done']+1);
$step=(int)($_GET['step']??$maxAllowed); $step=max(1,min($maxAllowed,$step));

$fixtureId=(int)$w['fixture_id'];
$snapshot=pdf_import_snapshot($pdo,$fixtureId);
$fx=$snapshot['fixture']??null;
$mdLineups=$snapshot['matchday_lineups']??[];
$mdSubs=$snapshot['matchday_subs']??[];
$mdEvents=$snapshot['matchday_events']??[];
$wv=pdf_import_website_view($snapshot);   // what /match/{id} actually shows (matchday_* or legacy stores)
$wvNote=static fn(string $key)=>($wv['from'][$key]??'')==='legacy'?' <span class="tag tag--mut">older store</span>':'';
$sec=$w['sections'];

$navBar=function(int $step,int $id,string $stepKey,string $nextLabel='Next') use($esc,$icn){ ?>
<div class="wiz-actions">
<?php if($step>1):?><a class="btn btn-outline-secondary" href="?id=<?=$id?>&step=<?=$step-1?>"><?=$icn('left')?> Back</a><?php else:?><span></span><?php endif;?>
<button type="submit" name="nav" value="next" class="btn btn-brand"><?=$esc($nextLabel)?> <?=$icn('right')?></button>
</div>
<?php };
?>
<div class="wiz-head"><h2><?=$name?></h2><span class="step-of">Step <?=$step?> of 6</span></div>
<ol class="wiz-steps" aria-label="Progress">
<?php foreach($stepNames as $n=>$lbl): $cls=$n<$step?'s-done':($n===$step?'s-now':'s-next'); $go=$n<=$maxAllowed&&$n!==$step;
$inner='<span class="dot">'.($n<$step?$icn('check'):$n).'</span><span class="lbl">'.$lbl.'</span>'; ?>
<li class="<?=$cls?>"><?php if($go):?><a href="?id=<?=$id?>&step=<?=$n?>"><?=$inner?></a><?php else:?><span aria-current="<?=$n===$step?'step':'false'?>"><?=$inner?></span><?php endif;?></li>
<?php endforeach;?>
</ol>

<?php if($step===1):
$allFixtures=pdf_import_all_fixtures($pdo);
$match=pdf_import_best_match($report,$allFixtures);
$bestId=(int)($match['fixture']['id']??0);
$sel=isset($_GET['fixture'])?(int)$_GET['fixture']:($fixtureId ?: $bestId);
$seasons=$pdo->query('SELECT * FROM seasons ORDER BY start_date DESC,id DESC')->fetchAll();
$selSeason=(int)($w['season_id']?:0);
if(!$selSeason) foreach($seasons as $s) if($s['start_date']<=$report['match_date']&&$s['end_date']>=$report['match_date']){ $selSeason=(int)$s['id']; break; }
// group all fixtures by season for the list
$bySeason=[];
foreach($allFixtures as $f){ $bySeason[$f['season_name']][]=$f; }
$fxResult=static function(array $f){
  if(($f['full_time_home_score']??null)===null) return '<span class="tag tag--mut">'.($f['status']==='played'?'no score':'upcoming').'</span>';
  return '<span class="tag">'.(int)$f['full_time_home_score'].'&ndash;'.(int)$f['full_time_away_score'].'</span>';
};
$fxOneLine=static fn(array $f)=> '#'.(int)$f['id'].' · '.$esc(pdf_import_uk_date($f['match_date'])).' · '.$esc($f['opponent']).' · '.($f['is_home']?'Home':'Away').' · '.$esc($f['competition']).($f['competition_stage']?' '.$esc($f['competition_stage']):'');
?>
<h1 class="wiz-title">Which match is this?</h1>
<p class="wiz-lead">Match the report to a fixture already in the database, or create a new one.</p>

<div class="wiz-pdfcard">
<p class="wiz-eyebrow mb-1"><?=$icn('doc')?> The PDF says</p>
<p class="score mb-1"><?=$esc($homeName)?> <?=$esc(implode('–',$report['score']))?> <?=$esc($awayName)?></p>
<p class="meta mb-0"><?=$esc(pdf_import_uk_date($report['match_date']))?> &nbsp;·&nbsp; kick-off <?=$esc($report['kickoff']?:'—')?> &nbsp;·&nbsp; <?=$esc($report['competition'])?><?=$report['stage']?' ('.$esc($report['stage']).')':''?><br><?=$esc($report['metadata']['venue']?:'venue not recorded')?> &nbsp;·&nbsp; Saltcoats <?=$report['is_home']?'at home':'away'?></p>
</div>

<form method="post" id="fxForm">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="wizard_step"><input type="hidden" name="step_key" value="fixture"><input type="hidden" name="step_num" value="1">

<?php if($match['fixture']): $bf=$match['fixture']; ?>
<div class="wiz-best wiz-best--<?=$match['confident']?'yes':'maybe'?>">
<span class="wiz-best__ic"><?=$icn($match['confident']?'check':'target')?></span>
<div class="flex-grow-1">
<b><?=$match['confident']?'Best match':'Likely match'?> — <?=$esc($match['why'])?> (<?=$match['confidence']?>% match)</b>
<div class="fx"><?=$fxOneLine($bf)?> <?=$fxResult($bf)?></div>
<button type="submit" name="nav" value="next" class="btn btn-brand btn-sm" data-use-best="1"<?=$match['confident']?' data-auto-advance="1"':''?>>Use fixture #<?=(int)$bf['id']?> <?=$icn('right')?></button>
</div>
</div>
<?php else: ?>
<div class="wiz-best wiz-best--none">
<span class="wiz-best__ic"><?=$icn('target')?></span>
<div><b>No clear match</b><div class="fx">Nothing in the database lines up with this report. Pick one below, or create a new fixture.</div></div>
</div>
<?php endif; ?>

<label class="wiz-newfx">
<input type="radio" name="fixture_id" value="0"<?=$sel===0?' checked':''?>>
<span><strong>Create a new fixture from this report</strong><br><span style="color:var(--w-muted);font-size:.85rem"><?=$esc($oppName)?> · <?=$esc(pdf_import_uk_date($report['match_date']))?> · <?=$report['is_home']?'Home':'Away'?></span></span>
</label>

<div class="wiz-filters">
<div class="fld"><label for="fxSeason">Season</label>
<select class="form-select form-select-sm" id="fxSeason"><option value="">All seasons</option><?php foreach($seasons as $s):?><option value="<?=$esc($s['name'])?>"><?=$esc($s['name'])?><?=$s['is_locked']?' (locked)':''?><?=isset($bySeason[$s['name']])?' · '.count($bySeason[$s['name']]):''?></option><?php endforeach;?></select></div>
<div class="fld grow"><label for="fxFilter">Filter</label>
<input type="search" class="form-control form-control-sm" id="fxFilter" placeholder="opponent, date or #id…"></div>
<div class="fld"><label for="manualFixture">Jump to ID</label>
<div class="input-group input-group-sm"><input type="number" min="1" id="manualFixture" class="form-control" style="max-width:5rem"><button type="button" class="btn btn-outline-secondary" id="findFixture"><?=$icn('search')?></button></div></div>
</div>

<div class="wiz-tw wiz-tw--scroll" id="fxList">
<table class="wiz-t"><thead><tr><th></th><th>#</th><th>Date</th><th>Opponent</th><th>V</th><th>Competition</th><th>Result</th></tr></thead><tbody>
<?php foreach($bySeason as $sn=>$fxs):?>
<tr class="wiz-fxgrp" data-season="<?=$esc($sn)?>"><td colspan="7"><?=$esc($sn)?> · <?=count($fxs)?> fixture<?=count($fxs)===1?'':'s'?></td></tr>
<?php foreach($fxs as $f): $isBest=$bestId===(int)$f['id']; ?>
<tr class="wiz-fxrow<?=$isBest?' is-best':''?>" data-season="<?=$esc($sn)?>" data-text="<?=$esc(strtolower('#'.$f['id'].' '.$f['match_date'].' '.pdf_import_uk_date($f['match_date']).' '.$f['opponent'].' '.($f['is_home']?'home':'away').' '.$f['competition']))?>">
<td class="rcell"><input type="radio" name="fixture_id" value="<?=(int)$f['id']?>"<?=$sel===(int)$f['id']?' checked':''?><?=$isBest?' data-best="1"':''?>></td>
<td class="num">#<?=(int)$f['id']?></td>
<td class="nowrap"><?=$esc(pdf_import_uk_date($f['match_date']))?></td>
<td><strong><?=$esc($f['opponent'])?></strong><?=$isBest?' <span class="tag tag--ok">best match</span>':''?></td>
<td><span class="tag tag--mut"><?=$f['is_home']?'H':'A'?></span></td>
<td class="comp" title="<?=$esc($f['competition'].($f['competition_stage']?' · '.$f['competition_stage']:''))?>"><?=$esc($f['competition'])?><?=$f['competition_stage']?' · '.$esc($f['competition_stage']):''?></td>
<td class="nowrap"><?=$fxResult($f)?></td>
</tr>
<?php endforeach; endforeach;?>
</tbody></table>
</div>
<div class="fx-pager" id="fxPager" hidden></div>
<p class="mt-1 mb-3" style="font-size:.8rem;color:var(--w-muted)"><?=count($allFixtures)?> fixture<?=count($allFixtures)===1?'':'s'?> in the database.</p>

<div class="wiz-card"><div class="wiz-card__hd">Only if you’re creating a new fixture</div><div class="wiz-card__bd">
<label class="wiz-eyebrow d-block mb-1" for="seasonChoice">Season for the new fixture</label>
<select class="form-select form-select-sm mb-2" name="season_id" id="seasonChoice" style="max-width:20rem"><?php foreach($seasons as $s):?><option value="<?=(int)$s['id']?>"<?=$selSeason===(int)$s['id']?' selected':''?>><?=$esc($s['name'])?><?=$s['is_locked']?' (locked)':''?></option><?php endforeach;?></select>
<label class="d-block small mb-1"><input type="checkbox" name="new_season" value="1"<?=$w['new_season']?' checked':''?>> Create that season from the match date (July–June) if it isn’t listed.</label>
<label class="d-block small mb-1"><input type="checkbox" name="match_override" value="1"<?=$w['match_override']?' checked':''?>> The date or opponent spelling differs a little from the PDF — I’ve checked, it’s the right match.</label>
<p class="d-block small mb-0" style="color:var(--w-muted)">Locked seasons are automatically allowed for this admin import.</p>
</div></div>
<?php $navBar(1,$id,'fixture','Next: match details'); ?>
</form>

<?php elseif($step===2):
$rows=[
 ['Date', pdf_import_uk_date($report['match_date']), $fx?pdf_import_uk_date($fx['match_date']):'—'],
 ['Kick-off', $report['kickoff']?:'—', $fx?($fx['kickoff_time']?substr((string)$fx['kickoff_time'],0,5):'—'):'—'],
 ['Competition', $report['competition']?pdf_import_canonical_competition($pdo,$report['competition']):'—', $fx?($fx['competition']?:'—'):'—'],
 ['Round / stage', $report['stage']?:'—', $fx?($fx['competition_stage']?:'—'):'—'],
 ['Venue', $report['metadata']['venue']?pdf_import_canonical_venue($pdo,$report['metadata']['venue']):'—', $fx?($fx['venue']?:'—'):'—'],
 ['Home or away', $report['is_home']?'Home':'Away', $fx?($fx['is_home']?'Home':'Away'):'—'],
 ['Final score', implode('–',$report['score']), $fx&&($fx['full_time_home_score']??null)!==null?($fx['full_time_home_score'].'–'.$fx['full_time_away_score']):'—'],
];
?>
<h1 class="wiz-title">Match details</h1>
<p class="wiz-lead">How the fixture details on the PDF compare with the website. Changed rows are highlighted.</p>
<form method="post">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="wizard_step"><input type="hidden" name="step_key" value="details"><input type="hidden" name="step_num" value="2">
<div class="wiz-tw">
<table class="wiz-t"><thead><tr><th style="width:9rem">Field</th><th><?=$icn('doc')?> From the PDF</th><th><?=$icn('site')?> On the website<?=$fx?' · #'.$fixtureId:' · new fixture'?></th></tr></thead><tbody>
<?php foreach($rows as [$lbl,$a,$b]): $d=trim((string)$a)!==trim((string)$b) && $b!=='—'; ?>
<tr<?=$d?' class="wiz-diff"':''?>><td class="num"><?=$esc($lbl)?><?=$d?' <span class="tag tag--warn">changed</span>':''?></td><td><?=$esc($a)?></td><td><?=$esc($b)?></td></tr>
<?php endforeach;?>
</tbody></table>
</div>
<div class="wiz-choice">
<label><input type="radio" name="source" value="pdf"<?=$sec['details']!=='web'?' checked':''?>><span><span class="t">Use the PDF details</span><span class="d">Overwrite the fixture’s date, kick-off, competition, round, venue and score with the report.</span></span></label>
<label><input type="radio" name="source" value="web"<?=$sec['details']==='web'?' checked':''?>><span><span class="t">Keep the website details</span><span class="d">Leave the fixture exactly as it is now.</span></span></label>
</div>
<?php $navBar(2,$id,'details','Next: starting XI'); ?>
</form>

<?php elseif($step===3):
$playerOptions=pdf_import_player_options($pdo,$report);
$auto=$todo=[];
foreach($playerOptions['options'] as $reg=>$opt){ $c=(string)($w['players'][$reg]??$opt['selected']); if($c!==''){$auto[$reg]=[$opt,$c];}else{$todo[$reg]=[$opt,$c];} }
$playerRow=function(string $reg,array $opt,string $choice) use($esc,$playerOptions){ ?>
<div class="wiz-player"><div class="nm"><button type="button" class="btn btn-link btn-sm p-0 text-start fw-semibold" data-bs-toggle="modal" data-bs-target="#pdfAddPlayerModal" data-player-name="<?=$esc($opt['source']['name'])?>"><?=$esc($opt['source']['name'])?></button><small class="d-block">#<?=$esc((string)$opt['source']['number'])?> · <?=$opt['source']['starting']?'started':'bench'?><?=$opt['method']?' · '.$esc($opt['method']):''?></small><button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="modal" data-bs-target="#pdfAddPlayerModal" data-player-name="<?=$esc($opt['source']['name'])?>">Add this player</button></div>
<select class="form-select form-select-sm pdf-player__sel" name="players[<?=$esc($reg)?>]" aria-label="Player for <?=$esc($opt['source']['name'])?>"><option value="">Choose a player…</option><option value="new"<?=$choice==='new'?' selected':''?>>Create a historical player</option><?php foreach($playerOptions['players'] as $p):?><option value="<?=(int)$p['id']?>"<?=$choice===(string)$p['id']?' selected':''?>><?=$esc($p['name'])?><?=$p['active']?'':' (inactive)'?></option><?php endforeach;?></select></div>
<?php };
$mkTags=static function($marker) use($esc){
 $out='';
 foreach (pdf_import_marker_set((string)$marker) as $m) {
   $lbl=in_array($m,['C','CP'],true)?'captain':(in_array($m,['G','GK'],true)?'GK':($m==='T'?'trialist':$m));
   $out.=' <span class="tag tag--mut">'.$esc($lbl).'</span>';
 }
 return $out;
};
$xiTable=function(array $pdfPlayers,array $webStarters) use($esc,$mkTags){
 $pdfStart=array_values(array_filter($pdfPlayers,static fn($p)=>!empty($p['starting'])));
 $webStart=array_values($webStarters);
 $n=max(count($pdfStart),count($webStart),11); ?>
 <table class="wiz-t wiz-xi"><thead><tr><th>#</th><th>PDF</th><th>Website</th></tr></thead><tbody>
 <?php for($i=0;$i<$n;$i++): $a=$pdfStart[$i]??null; $b=$webStart[$i]??null; ?>
 <tr><td><?=$a?$esc((string)$a['number']):($b?$esc((string)$b['number']):'')?></td><td><?=$a?$esc($a['name']).$mkTags($a['marker']??''):'<span style="color:var(--w-muted)">—</span>'?></td><td><?=$b?$esc($b['name']).($b['captain']?' <span class="tag tag--mut">captain</span>':'').(!empty($b['trialist'])?' <span class="tag tag--mut">trialist</span>':''):'<span style="color:var(--w-muted)">—</span>'?></td></tr>
 <?php endfor;?></tbody></table>
<?php };
$onlyStart=static fn(array $rows)=>array_filter($rows,static fn($p)=>$p['starting']);
?>
<h1 class="wiz-title">Starting XI</h1>
<p class="wiz-lead">Both teams’ starting elevens, from the PDF and from the website.</p>
<?php if(($wv['from']['svfc_xi']??'')==='legacy'):?>
<div class="alert alert-info small">This fixture’s line-up is only in the website’s <strong>older store</strong> (the one <code>/match/<?=$fixtureId?></code> reads) — not the new match-record tables yet. <strong>Use the PDF line-ups</strong> fills the new tables; <strong>Keep the website line-ups</strong> leaves the old data alone.</div>
<?php endif;?>
<?php $notesPanel($notesFor(['/player row/i','/line-?up/i','/\bstarter/i','/shirt number/i']),'Reading notes for the line-ups'); ?>
<form method="post" id="xiForm">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="wizard_step"><input type="hidden" name="step_key" value="starting_xi"><input type="hidden" name="step_num" value="3">
<div class="wiz-two">
<div class="wiz-pane"><div class="wiz-pane__hd">Saltcoats Victoria<?=$wvNote('svfc_xi')?></div><div class="wiz-pane__bd"><?php $xiTable($report['lineups']['svfc'],$onlyStart($wv['lineups']['svfc'])); ?></div></div>
<div class="wiz-pane"><div class="wiz-pane__hd"><?=$esc($oppName)?><?=$wvNote('opponent_xi')?></div><div class="wiz-pane__bd"><?php $xiTable($report['lineups']['opponent'],$onlyStart($wv['lineups']['opponent'])); ?></div></div>
</div>
<div class="wiz-choice">
<label><input type="radio" name="source" value="pdf" id="xiPdf"<?=$sec['starting_xi']!=='web'?' checked':''?>><span><span class="t">Use the PDF line-ups</span><span class="d">Replace both teams’ starting XI, substitutes and events with the report.</span></span></label>
<label><input type="radio" name="source" value="web"<?=$sec['starting_xi']==='web'?' checked':''?>><span><span class="t">Keep the website line-ups</span><span class="d">Leave the current line-up, substitutes and events untouched.</span></span></label>
</div>
<div id="linkBlock"<?=$sec['starting_xi']==='web'?' hidden':''?>>
<div class="wiz-card"><div class="wiz-card__hd">Link the Saltcoats players <span id="playerCount" class="tag tag--mut">0 of <?=count($playerOptions['options'])?> linked</span></div><div class="wiz-card__bd">
<p class="small" style="color:var(--w-muted)">Each Saltcoats name on the report needs a player record on the website. “Create a historical player” makes an inactive record. Links are remembered by COMET ID.</p>
<?php if($todo):?><p class="wiz-eyebrow mb-2" style="color:var(--w-danger)">These need a choice (<?=count($todo)?>)</p><?php foreach($todo as $r=>$pr):$playerRow((string)$r,$pr[0],$pr[1]);endforeach;?><?php endif;?>
<?php if($auto):?><details<?=$todo?' open':''?> class="mt-2"><summary class="small"><?=count($auto)?> matched automatically — click to check</summary><div class="mt-2"><?php foreach($auto as $r=>$pr):$playerRow((string)$r,$pr[0],$pr[1]);endforeach;?></div></details><?php endif;?>
</div></div>
</div>
<?php $navBar(3,$id,'starting_xi','Next: substitutes'); ?>
</form>
<div class="modal fade" id="pdfAddPlayerModal" tabindex="-1" aria-labelledby="pdfAddPlayerModalLabel" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header"><h2 class="modal-title h5" id="pdfAddPlayerModalLabel">Add trialist</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<form method="post">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="create_player"><input type="hidden" name="step_num" value="3">
<div class="modal-body"><p class="small text-muted">Create this player without leaving the importer. You can change their status to Left later from the player record.</p>
<label class="form-label" for="pdfAddPlayerName">Player name</label><input class="form-control" id="pdfAddPlayerName" name="player_name" required>
<label class="form-label mt-3" for="pdfAddPlayerJoined">Trial started</label><input class="form-control" type="date" id="pdfAddPlayerJoined" name="joined_at">
</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand">Add trialist</button></div>
</form></div></div></div>
<script>
document.querySelectorAll('[data-bs-target="#pdfAddPlayerModal"]').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('pdfAddPlayerName').value = button.dataset.playerName || '';
    document.getElementById('pdfAddPlayerName').focus();
  });
});
</script>

<?php elseif($step===4 || $step===5):
$isSubs=$step===4;
$stepKey=$isSubs?'subs':'events';
$pdfItems=array_values(array_filter($events,static fn($e)=> $isSubs ? $e['type']==='substitution' : $e['type']!=='substitution'));
$webItems=$isSubs?$wv['subs']:$wv['events'];
?>
<h1 class="wiz-title"><?=$isSubs?'Substitutes':'Goals &amp; cards'?></h1>
<p class="wiz-lead"><?=$isSubs?'Every substitution on the PDF, next to what the website has now.':'Every goal and card on the PDF, next to what the website has now.'?></p>
<?php $notesPanel(
  $isSubs ? $notesFor(['/substitut/i','/bench/i'])
          : $notesFor(['/goal/i','/card/i','/yellow/i','/\bred\b/i','/\bevent/i','/timed entry/i','/timed player event/i']),
  $isSubs ? 'Reading notes for the substitutes' : 'Reading notes for goals & cards'
); ?>
<form method="post">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="wizard_step"><input type="hidden" name="step_key" value="<?=$stepKey?>"><input type="hidden" name="step_num" value="<?=$step?>">
<div class="wiz-two">
<div class="wiz-pane"><div class="wiz-pane__hd"><?=$icn('doc')?> From the PDF <span class="tag tag--mut"><?=count($pdfItems)?></span></div><div class="wiz-pane__bd">
<?php if(!$pdfItems):?><p class="wiz-empty">None.</p><?php endif;?>
<?php foreach($pdfItems as $e):?><div class="wiz-evt"><span class="min"><?=$esc($e['minute'])?>′</span><span class="who"><span class="mut"><?=$e['side']==='svfc'?'Saltcoats':$esc($oppName)?></span> · <?php if($isSubs):?><?=$esc($e['name'])?> → <?=$esc($e['on_name']??'')?><?php else:?><?=$esc(str_replace('_',' ',$e['type']))?> — <?=$esc($e['name'])?><?php if(($e['participant_type']??'player')==='staff' && ($e['participant_role']??'')!==''):?> <span class="tag tag--mut"><?=$esc($e['participant_role'])?></span><?php endif;?><?php if($e['note']):?> <span class="mut">(<?=$esc($e['note'])?>)</span><?php endif;?><?php endif;?></span></div><?php endforeach;?>
</div></div>
<div class="wiz-pane"><div class="wiz-pane__hd"><?=$icn('site')?> On the website <span class="tag tag--mut"><?=count($webItems)?></span><?=$wvNote($stepKey)?></div><div class="wiz-pane__bd">
<?php if(!$webItems):?><p class="wiz-empty">None.</p><?php endif;?>
<?php foreach($webItems as $e):?><div class="wiz-evt"><span class="min"><?=$esc((string)$e['minute'])?>′</span><span class="who"><span class="mut"><?=$e['side']==='svfc'?'Saltcoats':$esc($oppName)?></span> · <?php if($isSubs):?><?=$esc($e['off'])?> → <?=$esc($e['on'])?><?php else:?><?=$esc(str_replace('_',' ',$e['type']))?> — <?=$esc($e['player'])?><?php if(($e['participant_type']??'player')==='staff' && ($e['participant_role']??'')!==''):?> <span class="tag tag--mut"><?=$esc($e['participant_role'])?></span><?php endif;?><?php endif;?></span></div><?php endforeach;?>
</div></div>
</div>
<div class="wiz-choice">
<label><input type="radio" name="source" value="pdf"<?=$sec[$stepKey]!=='web'?' checked':''?> disabled><span><span class="t">Use the PDF <?=$isSubs?'substitutes':'events'?></span></span></label>
<label><input type="radio" name="source" value="web"<?=$sec[$stepKey]==='web'?' checked':''?> disabled><span><span class="t">Keep the website <?=$isSubs?'substitutes':'events'?></span></span></label>
</div>
<p class="small" style="color:var(--w-muted)">Follows your <strong>Starting XI</strong> choice (step 3) — currently <strong><?=$sec['starting_xi']==='web'?'keep the website line-ups':'use the PDF line-ups'?></strong> — because <?=$isSubs?'substitutes':'goals and cards'?> name these players. Go back to step 3 to change it.</p>
<?php $navBar($step,$id,$stepKey,$isSubs?'Next: goals & cards':'Next: review'); ?>
</form>

<?php elseif($step===6):
$doRecord=$sec['starting_xi']!=='web';
$doDetails=$sec['details']!=='web';
$svfcXi=$report['lineups']['svfc']; $oppXi=$report['lineups']['opponent'];
$pdfSubs=array_values(array_filter($events,static fn($e)=>$e['type']==='substitution'));
$pdfGC=array_values(array_filter($events,static fn($e)=>$e['type']!=='substitution'));
$playerOptions=pdf_import_player_options($pdo,$report);
$linked=$new=0; $newNames=[];
foreach($playerOptions['options'] as $reg=>$opt){ $c=(string)($w['players'][$reg]??''); if($c==='new'){$new++;$newNames[]=$opt['source']['name'];} elseif($c!==''){$linked++;} }
$plan=[];
$plan[]=['Fixture', $fx? ('Existing fixture #'.$fixtureId.' — '.$esc($fx['opponent']).', '.pdf_import_uk_date($fx['match_date']).', '.($fx['is_home']?'Home':'Away')) : ('A new fixture will be created — '.$esc($oppName).', '.pdf_import_uk_date($report['match_date']).', '.($report['is_home']?'Home':'Away'))];
$plan[]=['Season', $fx? $esc((string)($pdo->query('SELECT name FROM seasons WHERE id='.(int)$fx['season_id'])->fetchColumn())) : ($w['new_season']?'Created from the match date if needed':$esc((string)($pdo->query('SELECT name FROM seasons WHERE id='.(int)$w['season_id'])->fetchColumn())))];
$plan[]=['Match details', $doDetails
  ? 'Taken from the PDF — date '.pdf_import_uk_date($report['match_date']).', kick-off '.($report['kickoff']?:'—').', '.$esc($report['competition']).', '.($report['stage']?$esc($report['stage']):'no round').', '.($report['metadata']['venue']?$esc($report['metadata']['venue']):'no venue').', score '.$esc(implode('–',$report['score']))
  : 'Left as they are on the website'];
$curXi=count(array_filter($wv['lineups']['svfc'],static fn($p)=>$p['starting']))+count(array_filter($wv['lineups']['opponent'],static fn($p)=>$p['starting']));
$curSubs=count($wv['subs']); $curEv=count($wv['events']);
$fromNote=static fn(string $k)=>($wv['from'][$k]??'')==='legacy'?' from the website’s older store':'';
$plan[]=['Starting XI', $doRecord
  ? 'Replaced with the PDF — '.count(array_filter($svfcXi,static fn($p)=>!empty($p['starting']))).' Saltcoats + '.count(array_filter($oppXi,static fn($p)=>!empty($p['starting']))).' '.$esc($oppName).' starters'.($curXi?' (replacing '.$curXi.$fromNote('svfc_xi').')':'')
  : 'Left as they are ('.$curXi.' on the website'.$fromNote('svfc_xi').')'];
$plan[]=['Substitutes', $doRecord ? 'Replaced with the PDF — '.count($pdfSubs).($curSubs?' (replacing '.$curSubs.$fromNote('subs').')':'') : 'Left as they are ('.$curSubs.' on the website)'];
$plan[]=['Goals &amp; cards', $doRecord ? 'Replaced with the PDF — '.count($pdfGC).($curEv?' (replacing '.$curEv.$fromNote('events').')':'') : 'Left as they are ('.$curEv.' on the website)'];
if($doRecord) $plan[]=['Players', $linked.' Saltcoats name(s) linked to existing records'.($new?'; '.$new.' new historical player(s) will be created: '.$esc(implode(', ',$newNames)):'').'. Opponent players are stored as names only.'];
$plan[]=['Backup', 'Before anything changes, a full snapshot of '.($fx?'fixture #'.$fixtureId.'’s':'the fixture’s').' current data is written to <code>var/pdf_importer/backups/</code>. You can undo this import afterwards.'];
?>
<h1 class="wiz-title">Review &amp; import</h1>
<p class="wiz-lead">Exactly what will be written to the database. Nothing has changed yet.</p>
<?php $notesPanel($readNotes,'The reader flagged these — check them against the PDF before you import'); ?>
<div class="wiz-review mb-3">
<?php foreach($plan as $i=>[$k,$v]):?><div<?=$k==='Backup'?' class="wiz-review--backup"':''?>><b><?=$k==='Backup'?$icn('shield').' Backup':$k?></b><span><?=$v?></span></div><?php endforeach;?>
</div>
<form method="post" id="importForm">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="import"><input type="hidden" name="step_num" value="6">
<div class="wiz-actions">
<a class="btn btn-outline-secondary" href="?id=<?=$id?>&step=5"><?=$icn('left')?> Back</a>
<button class="btn btn-brand btn-lg" id="importBtn">Import this report</button>
</div>
<p class="small mt-2 mb-0" style="color:var(--w-muted)">Pressing Import writes the changes and takes you back to the list.</p>
</form>
<?php endif; /* step switch */ ?>

<div class="wiz-foot">
<a class="btn btn-outline-secondary btn-sm" href="?pdf=<?=$id?>" target="_blank" rel="noopener"><?=$icn('ext')?> Open the original PDF</a>
<details class="mt-2"><summary>The PDF was read wrong? Fix the details</summary>
<div class="mt-2"><?php $report['warnings']=$report['warnings']??[]; $esc_kept=$esc; require __DIR__.'/partials/pdf_import_corrections.php'; $esc=$esc_kept; ?></div>
</details>
</div>
<?php endif; /* pdfProblems */ ?>
<?php endif; /* wizard vs imported vs not-read */ ?>
<?php }catch(Throwable $e){?><div class="alert alert-danger"><?=$esc($e->getMessage())?></div><?php } ?>
<?php else:
$filter=(string)($_GET['status']??'');$page=max(1,(int)($_GET['page']??1));
$where=$filter!==''?' WHERE status=?':'';
$st=$pdo->prepare('SELECT COUNT(*) FROM historical_pdf_imports'.$where);$st->execute($filter!==''?[$filter]:[]);$total=(int)$st->fetchColumn();
$st=$pdo->prepare('SELECT id,source_name,status,fixture_id,error,report_json,imported_at FROM historical_pdf_imports'.$where.' ORDER BY id DESC LIMIT 100 OFFSET '.(($page-1)*100));$st->execute($filter!==''?[$filter]:[]);
$listRows=$st->fetchAll();
// Reports still needing work rise to the top, in 001,002,… order; imported / set-aside sink.
$doneStates=['imported','pending','undo_pending','undone','skipped'];
$listNum=static function(string $sn){ return preg_match('/^\s*0*(\d+)/',pdf_import_display_name($sn),$m)?(int)$m[1]:PHP_INT_MAX; };
usort($listRows,function($a,$b) use($doneStates,$listNum){
    $ad=in_array($a['status'],$doneStates,true); $bd=in_array($b['status'],$doneStates,true);
    if($ad!==$bd) return $ad<=>$bd;
    $an=$listNum($a['source_name']); $bn=$listNum($b['source_name']);
    if($an!==$bn) return $an<=>$bn;
    return strcasecmp(pdf_import_display_name($a['source_name']),pdf_import_display_name($b['source_name']));
});
?>
<div class="pdf-import-actions mb-3"><a href="?" class="btn btn-sm <?=$filter===''?'btn-brand':'btn-outline-secondary'?>">All</a><?php foreach($counts as $status=>$count):?><a href="?status=<?=$esc($status)?>" class="btn btn-sm <?=$filter===$status?'btn-brand':'btn-outline-secondary'?>"><?=$esc($statusNames[$status]??ucfirst((string)$status))?> (<?=(int)$count?>)</a><?php endforeach;?></div>
<div class="pdf-import-actions mb-2"><button id="importSelected" class="btn btn-brand btn-sm" type="button">Import the ticked reports</button><label class="small"><input type="checkbox" id="selectReady"> Tick every ready report on this page</label></div>
<div class="wiz-tw"><table class="wiz-t"><thead><tr><th></th><th>PDF file</th><th>Match on the report</th><th>Status</th><th></th></tr></thead><tbody>
<?php $any=false;foreach($listRows as $r):$any=true;$rep=$r['report_json']?json_decode($r['report_json'],true):null;
$sName=$statusNames[$r['status']]??ucfirst((string)$r['status']); $sCls=$r['status']==='imported'?'tag--ok':($r['status']==='failed'?'tag--warn':'tag--mut'); ?>
<tr><td class="rcell"><?php if($r['status']==='ready'):?><input type="checkbox" class="readyReport" value="<?=(int)$r['id']?>" aria-label="Import <?=$esc(pdf_import_display_name($r['source_name']))?>"><?php endif;?></td><td><?=$esc(pdf_import_display_name($r['source_name']))?><?php if($r['error']):?><small class="d-block" style="color:var(--w-danger)"><?=$esc($r['error'])?></small><?php endif;?></td><td class="nowrap" style="color:var(--w-muted)"><?=$rep?$esc(pdf_import_uk_date($rep['match_date']).' · '.$rep['opponent'].' · '.($rep['is_home']?'Home':'Away')):'Not read yet'?></td><td><span class="tag <?=$sCls?>"><?=$esc($sName)?></span></td><td class="nowrap"><a class="btn btn-sm btn-outline-primary" href="?id=<?=(int)$r['id']?>"><?=$r['status']==='imported'?'View':'Open'?></a></td></tr>
<?php endforeach;if(!$any):?><tr><td colspan="5" style="color:var(--w-muted);padding:1.5rem .85rem">No match reports yet. Add PDFs to the <code>PDF_imports</code> folder or upload them, then press “Read the queued PDFs”.</td></tr><?php endif;?></tbody></table></div>
<div class="mt-2"><?php if($page>1):?><a class="btn btn-sm btn-outline-secondary" href="?page=<?=$page-1?>&amp;status=<?=$esc($filter)?>">Previous</a><?php endif;?><?php if($page*100<$total):?><a class="btn btn-sm btn-outline-secondary" href="?page=<?=$page+1?>&amp;status=<?=$esc($filter)?>">Next</a><?php endif;?></div>
<?php endif;?>
</div>
<form id="pdfToken" hidden><?=csrf_field()?></form>
<script src="/admin/assets/js/pdf-importer.js?v=9" defer></script>
<?php require_once __DIR__.'/footer.php'; ?>
