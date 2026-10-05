<?php
// Included by the authenticated importer; no standalone actions.
if (!isset($report,$row,$id,$esc)) return;
?>
<details class="card mt-3"><summary class="card-header">The PDF was read wrong? Fix the details here</summary><div class="card-body">
<p>Only needed when the reader misread the PDF. Type what the PDF actually says. The original PDF and its text are never changed, and every fix is kept in the import history. Saving here means you check and import again.</p>
<form method="post">
<?=csrf_field()?><input type="hidden" name="action" value="correct"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="report_fingerprint" value="<?=$esc(hash('sha256',$row['report_json']))?>">
<div class="row g-3">
<?php foreach(['match_date'=>'Match date','kickoff'=>'Kickoff','opponent'=>'Opponent','competition'=>'Competition','stage'=>'Round'] as $key=>$label):?><div class="col-md-4"><label class="form-label" for="correct_<?=$key?>"><?=$label?></label><input class="form-control" id="correct_<?=$key?>" name="<?=$key?>" value="<?=$esc($report[$key])?>" type="<?=$key==='match_date'?'date':'text'?>"></div><?php endforeach;?>
<div class="col-md-4"><label class="form-label" for="correct_home">Saltcoats venue side</label><select class="form-select" id="correct_home" name="is_home"><option value="1" <?=$report['is_home']?'selected':''?>>Home</option><option value="0" <?=!$report['is_home']?'selected':''?>>Away</option></select></div>
<div class="col-md-4"><label class="form-label" for="correct_venue">Venue</label><input class="form-control" id="correct_venue" name="venue" value="<?=$esc($report['metadata']['venue'])?>"></div>
<?php foreach(['home_score'=>0,'away_score'=>1] as $key=>$index):?><div class="col-md-2"><label class="form-label" for="correct_<?=$key?>"><?=$index?'Away':'Home'?> score</label><input type="number" min="0" max="99" class="form-control" id="correct_<?=$key?>" name="<?=$key?>" value="<?=$esc($report['score'][$index])?>" required></div><?php endforeach;?></div>
<?php foreach(['svfc'=>'Saltcoats Victoria','opponent'=>$report['opponent']] as $side=>$label):?>
<h4 class="h6 mt-4"><?=$esc($label)?> line-up — use the blank rows to add a player the reader missed</h4>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Shirt</th><th>COMET ID</th><th>Marker</th><th>Starts</th></tr></thead><tbody>
<?php $players=array_merge($report['lineups'][$side],array_fill(0,3,['name'=>'','number'=>'','registration_id'=>'','marker'=>'','starting'=>false]));foreach($players as $i=>$p):?>
<tr><td><input aria-label="<?=$esc($label)?> player <?=$i+1?> name" class="form-control form-control-sm" name="lineups[<?=$side?>][<?=$i?>][name]" value="<?=$esc($p['name'])?>"></td><td><input aria-label="Shirt number" type="number" min="1" max="99" class="form-control form-control-sm" style="min-width:65px" name="lineups[<?=$side?>][<?=$i?>][number]" value="<?=$esc($p['number'])?>"></td><td><input aria-label="COMET registration ID" class="form-control form-control-sm" name="lineups[<?=$side?>][<?=$i?>][registration_id]" value="<?=$esc($p['registration_id'])?>"></td><td><select aria-label="Player marker" class="form-select form-select-sm" name="lineups[<?=$side?>][<?=$i?>][marker]"><?php foreach([''=>'None','G'=>'Goalkeeper','C'=>'Captain','T'=>'Trialist'] as $value=>$text):?><option value="<?=$value?>" <?=$p['marker']===$value?'selected':''?>><?=$text?></option><?php endforeach;?></select></td><td><input aria-label="Starting player" type="checkbox" name="lineups[<?=$side?>][<?=$i?>][starting]" value="1" <?=$p['starting']?'checked':''?>></td></tr>
<?php endforeach;?></tbody></table></div><?php endforeach;?>
<h4 class="h6 mt-4">Events — player names follow the selected team and shirt number</h4>
<?php $events=array_merge($report['events'],array_fill(0,3,['minute'=>'','type'=>'goal','side'=>'svfc','number'=>'','on_number'=>'','note'=>'']));foreach($events as $i=>$e):?>
<div class="row g-2 border-bottom pb-2 mb-2">
<div class="col-md-1"><label class="form-label small">Minute<input class="form-control form-control-sm" name="events[<?=$i?>][minute]" value="<?=$esc($e['minute'])?>" placeholder="90+2"></label></div>
<div class="col-md-2"><label class="form-label small">Event<select class="form-select form-select-sm" name="events[<?=$i?>][type]"><?php foreach(['goal','own_goal','penalty_scored','penalty_missed','yellow_card','red_card','second_yellow','substitution'] as $type):?><option value="<?=$type?>" <?=$e['type']===$type?'selected':''?>><?=$esc(str_replace('_',' ',$type))?></option><?php endforeach;?></select></label></div>
<div class="col-md-2"><label class="form-label small">Team<select class="form-select form-select-sm" name="events[<?=$i?>][side]"><option value="svfc" <?=$e['side']==='svfc'?'selected':''?>>Saltcoats</option><option value="opponent" <?=$e['side']==='opponent'?'selected':''?>>Opponent</option></select></label></div>
<div class="col-md-1"><label class="form-label small">Shirt / off<input type="number" min="1" max="99" class="form-control form-control-sm" name="events[<?=$i?>][number]" value="<?=$esc($e['number'])?>"></label></div>
<div class="col-md-1"><label class="form-label small">Shirt on<input type="number" min="1" max="99" class="form-control form-control-sm" name="events[<?=$i?>][on_number]" value="<?=$esc($e['on_number']??'')?>"></label></div>
<div class="col-md-4"><label class="form-label small w-100">Detail<textarea class="form-control form-control-sm" name="events[<?=$i?>][note]" rows="1"><?=$esc($e['note'])?></textarea></label></div>
<div class="col-md-1"><label class="small"><input type="checkbox" name="events[<?=$i?>][remove]" value="1"> Remove</label></div></div>
<?php endforeach;?>
<label class="d-block my-3"><input type="checkbox" name="corrections_checked" value="1" required> I checked these against the original PDF, including any warnings above.</label><button class="btn btn-outline-primary">Save these fixes</button>
</form></div></details>
