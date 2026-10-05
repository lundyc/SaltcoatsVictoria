<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
$testFile=tempnam(sys_get_temp_dir(),'pdf-import-test-');
define('PDF_IMPORT_LEGACY_FILE',$testFile);
require __DIR__.'/../db.php';
require __DIR__.'/../lib/pdf_importer.php';
function check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function expect_failure(callable $fn,string $message): void { try{$fn();}catch(Throwable $e){echo "PASS $message (".$e->getMessage().")\n";return;}throw new RuntimeException('Expected failure: '.$message); }
try {
    $sourceRows=$pdo->query('SELECT * FROM historical_pdf_imports ORDER BY id')->fetchAll();
    // Temporary tables shadow production names for this connection only.
    foreach (array_merge(['match_fixtures','players','seasons','match_opponents','historical_pdf_imports','historical_pdf_identities','historical_pdf_audit'],PDF_IMPORT_RECORD_TABLES) as $table) {
        $rows=in_array($table,['players','seasons','match_opponents','match_fixtures'],true)?$pdo->query("SELECT * FROM $table")->fetchAll():[];
        $ddl=$pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $ddl=preg_replace('/,?\n\s*CONSTRAINT[^\n]+/', '', $ddl);
        $pdo->exec(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl));
        foreach($rows as $row)pdf_import_insert($pdo,$table,$row);
    }
    $pdo->exec('ALTER TABLE match_fixtures AUTO_INCREMENT=2000000');
    file_put_contents($testFile,'[]');
    $ids=[];
    foreach($sourceRows as $row) {
        $row['status']='review';$row['review_json']=null;$row['before_json']=null;$row['after_json']=null;$row['fixture_id']=null;
        pdf_import_insert($pdo,'historical_pdf_imports',$row);
        $r=pdf_import_extract(pdf_import_document_path($row));
        check(pdf_import_validate($r)===[] && $r['warnings']===[],$row['source_name'].' validates');
        $pdo->prepare('UPDATE historical_pdf_imports SET report_json=? WHERE id=?')->execute([pdf_import_json($r),$row['id']]);
        $ids[$row['source_name']]=(int)$row['id'];
    }
    $id=$ids['Leith - Away.pdf'];$row=pdf_import_get($pdo,$id);$report=json_decode($row['report_json'],true);
    check(count($report['lineups']['svfc'])===18 && count($report['lineups']['opponent'])===18,'Both benches retain all players');
    $candidates=pdf_import_candidates($pdo,$report);$exact=array_values(array_filter($candidates,fn($r)=>$r['exact']));
    check(count($exact)===1 && (int)$exact[0]['id']===78,'Fixture matching ignores FC punctuation without duplicating match');
    $snapshot=pdf_import_snapshot($pdo,78);
    $choices=pdf_import_player_options($pdo,$report);$mapping=[];
    foreach($choices['options'] as $reg=>$choice)$mapping[$reg]=$choice['selected']?:'new';
    $input=['fixture_id'=>78,'season_id'=>2,'mode'=>'replace','players'=>$mapping,'reviewed'=>1,'score_override'=>1,'preview_fingerprint'=>pdf_import_fingerprint($snapshot)];
    $bad=$input;$bad['preview_fingerprint']='stale';expect_failure(fn()=>pdf_import_review($pdo,$id,$bad,1),'Stale preview cannot be approved');
    pdf_import_review($pdo,$id,$input,1);
    $pdo->prepare('UPDATE match_fixtures SET notes=? WHERE id=78')->execute(['concurrent change']);
    expect_failure(fn()=>pdf_import_apply($pdo,$id,1),'Concurrent fixture edit blocks apply');
    $pdo->prepare('UPDATE match_fixtures SET notes=? WHERE id=78')->execute([$snapshot['fixture']['notes']]);
    pdf_import_apply($pdo,$id,1);
    check($pdo->query('SELECT COUNT(*) FROM matchday_lineups WHERE fixture_id=78')->fetchColumn()==36,'All 36 lineup participants saved');
    $own=$pdo->query("SELECT * FROM matchday_events WHERE fixture_id=78 AND type='own_goal'")->fetch();
    check($own['side']==='opponent' && $own['player_name']==='Jamie Stirling','Own goal credits opposition and preserves scorer');
    check($pdo->query('SELECT COUNT(*) FROM matchday_subs WHERE fixture_id=78')->fetchColumn()==9,'Nine substitutions linked to lineup identities');
    $appearances=(int)$pdo->query("SELECT COUNT(*) FROM matchday_lineups l WHERE l.fixture_id=78 AND l.side='svfc' AND (l.is_starting=1 OR EXISTS(SELECT 1 FROM matchday_subs s WHERE s.player_on_lineup_id=l.id))")->fetchColumn();
    check($appearances===15,'Unused bench players do not count as appearances');
    expect_failure(fn()=>pdf_import_apply($pdo,$id,1),'Repeated import cannot duplicate events');
    check(pdf_import_project_one($pdo),'Legacy projection processed');
    check(pdf_import_get($pdo,$id)['status']==='imported','Import completes after display synchronization');
    $pdo->exec("UPDATE matchday_events SET note='later edit' WHERE fixture_id=78 LIMIT 1");
    expect_failure(fn()=>pdf_import_undo($pdo,$id,1),'Undo preserves later match edits');
    $after=json_decode(pdf_import_get($pdo,$id)['after_json'],true);
    foreach($after['matchday_events'] as $event)$pdo->prepare('UPDATE matchday_events SET note=? WHERE id=?')->execute([$event['note'],$event['id']]);
    pdf_import_undo($pdo,$id,1);pdf_import_project_one($pdo);
    check(pdf_import_get($pdo,$id)['status']==='undone','Undo completed');
    check(pdf_import_fingerprint(pdf_import_snapshot($pdo,78))===pdf_import_fingerprint($snapshot),'Undo exactly restores fixture, lineup, events and legacy entry');
    // New fixture and missing historical season, without calendar/ticket side effects.
    $id=$ids['West Park - Away.pdf'];$row=pdf_import_get($pdo,$id);$r=json_decode($row['report_json'],true);$r['match_date']='2010-08-15';
    $pdo->prepare('UPDATE historical_pdf_imports SET report_json=? WHERE id=?')->execute([pdf_import_json($r),$id]);
    $mapping=[];foreach(pdf_import_player_options($pdo,$r)['options'] as $reg=>$choice)$mapping[$reg]=$choice['selected']?:'new';
    $input=['fixture_id'=>0,'new_season'=>1,'mode'=>'missing','players'=>$mapping,'reviewed'=>1,'preview_fingerprint'=>pdf_import_fingerprint(pdf_import_snapshot($pdo,0))];
    pdf_import_review($pdo,$id,$input,1);$fixtureId=pdf_import_apply($pdo,$id,1);pdf_import_project_one($pdo);
    $new=pdf_import_snapshot($pdo,$fixtureId);
    check($fixtureId>=2000000 && $new['fixture']['match_date']==='2010-08-15','Missing historical fixture created');
    check($pdo->query("SELECT COUNT(*) FROM seasons WHERE name='2010 / 2011' AND is_current=0 AND is_locked=1")->fetchColumn()==1,'Missing historical season created without changing current season');
    check($pdo->query("SELECT COUNT(*) FROM matchday_events WHERE fixture_id=$fixtureId AND minute=90 AND minute_extra IN (1,4)")->fetchColumn()==2,'Stoppage-time goal and card preserve extra minutes');
    $id=$ids['Newmains - Home.pdf'];$r=json_decode(pdf_import_get($pdo,$id)['report_json'],true);
    $red=array_values(array_filter($r['events'],fn($e)=>$e['type']==='red_card'));
    check(count($red)===1 && str_contains($red[0]['note'],'foul and abusive language'),'Full red-card explanation retained');
    check(count(array_filter($r['lineups']['svfc'],fn($p)=>$p['marker']==='T'))===1,'Trialist marker preserved');
    // Metadata-only mode must preserve authoritative existing football data.
    $pdo->exec('UPDATE match_fixtures SET full_time_home_score=9,full_time_away_score=8 WHERE id=50');
    $prior=pdf_import_snapshot($pdo,50);
    pdf_import_review($pdo,$id,['fixture_id'=>50,'mode'=>'metadata','reviewed'=>1,'preview_fingerprint'=>pdf_import_fingerprint($prior)],1);
    pdf_import_apply($pdo,$id,1);pdf_import_project_one($pdo);
    $preserved=pdf_import_snapshot($pdo,50);
    check((int)$preserved['fixture']['full_time_home_score']===9 && (int)$preserved['fixture']['full_time_away_score']===8,'Existing official result preserved unless replacement selected');
    check($preserved['fixture']['starting11_starters_json']===$prior['fixture']['starting11_starters_json'],'Metadata-only import preserves existing lineup');
    pdf_import_undo($pdo,$id,1);pdf_import_project_one($pdo);
    check(pdf_import_fingerprint(pdf_import_snapshot($pdo,50))===pdf_import_fingerprint($prior),'Metadata-only undo restores original record');
    // Manual corrections are versioned and clear previous approval.
    $pdo->prepare("UPDATE historical_pdf_imports SET status='review' WHERE id=?")->execute([$id]);
    $source=pdf_import_get($pdo,$id);$r=json_decode($source['report_json'],true);
    $correction=$r;
    $correction['report_fingerprint']=hash('sha256',$source['report_json']);
    $correction['is_home']=$r['is_home']?'1':'0';$correction['home_score']=$r['score'][0];$correction['away_score']=$r['score'][1];
    $correction['venue']=$r['metadata']['venue'];$correction['corrections_checked']=1;$correction['stage']='Round 6 (checked)';
    pdf_import_correct($pdo,$id,$correction,1);
    $corrected=pdf_import_get($pdo,$id);
    check($corrected['status']==='review' && $corrected['review_json']===null,'Corrections invalidate previous approval');
    check(json_decode($corrected['report_json'],true)['raw_text']===$r['raw_text'],'Corrections preserve original extracted source text');
    expect_failure(fn()=>pdf_import_correct($pdo,$id,$correction,1),'Stale correction form cannot overwrite newer edits');
    echo "All tests passed. Only temporary database tables and a temporary archive file were modified.\n";
} finally { @unlink($testFile); @unlink($testFile.'.lock'); }
