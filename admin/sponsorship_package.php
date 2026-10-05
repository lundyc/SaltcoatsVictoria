<?php
$id = (int)($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => $id > 0 ? 'Edit sponsorship package' : 'Add sponsorship package',
    'subtitle' => 'Control scope, pricing, availability and graphic placement.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);
$package = $id > 0 ? getSponsorshipPackage($pdo, $id) : null;
if ($id > 0 && !$package) { echo '<div class="alert alert-danger">Package not found.</div>'; require __DIR__.'/footer.php'; exit; }
$data = array_merge([
  'name'=>'','code'=>'','scope'=>'club','category'=>'Club-wide','description'=>'','amount'=>'0.00','duration_type'=>'season',
  'max_slots'=>'','graphic_enabled'=>0,'graphic_placement'=>'','default_logo_variant'=>'white','is_active'=>1,'sort_order'=>100,
], $package ?: []);
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check()) $errors[]='Invalid security token.';
  foreach (['name','code','scope','category','description','amount','duration_type','max_slots','graphic_placement','default_logo_variant','sort_order'] as $field) $data[$field]=trim((string)($_POST[$field]??''));
  $data['graphic_enabled']=isset($_POST['graphic_enabled'])?1:0; $data['is_active']=isset($_POST['is_active'])?1:0;
  $data['code']=strtolower(trim(preg_replace('/[^a-z0-9]+/','_',$data['code']!==''?$data['code']:$data['name']),'_'));
  if ($data['name']===''||$data['code']==='') $errors[]='Name and code are required.';
  if (!in_array($data['scope'],['club','match','player','team','digital'],true)) $errors[]='Choose a valid scope.';
  if (!is_numeric($data['amount'])||(float)$data['amount']<0) $errors[]='Default amount must be zero or more.';
  if (!$errors) {
    try {
      $params=[':name'=>$data['name'],':code'=>$data['code'],':scope'=>$data['scope'],':category'=>$data['category'],':description'=>$data['description']?:null,
        ':amount'=>(float)$data['amount'],':duration'=>$data['duration_type'],':max_slots'=>$data['max_slots']===''?null:max(1,(int)$data['max_slots']),
        ':graphics'=>$data['graphic_enabled'],':placement'=>$data['graphic_placement']?:null,':variant'=>$data['default_logo_variant'],':active'=>$data['is_active'],':sort'=>max(0,(int)$data['sort_order'])];
      if ($id>0) { unset($params[':code']); $params[':id']=$id; $stmt=$pdo->prepare('UPDATE packages SET name=:name,scope=:scope,category=:category,description=:description,amount=:amount,duration_type=:duration,max_slots=:max_slots,graphic_enabled=:graphics,graphic_placement=:placement,default_logo_variant=:variant,is_active=:active,sort_order=:sort WHERE id=:id'); }
      else $stmt=$pdo->prepare('INSERT INTO packages(name,code,scope,category,description,amount,duration_type,max_slots,graphic_enabled,graphic_placement,default_logo_variant,is_active,sort_order) VALUES(:name,:code,:scope,:category,:description,:amount,:duration,:max_slots,:graphics,:placement,:variant,:active,:sort)');
      $stmt->execute($params);
      auditLog($pdo, $id > 0 ? 'sponsorship_package_updated' : 'sponsorship_package_created', ($id > 0 ? 'Updated' : 'Created') . " sponsorship package '{$data['name']}'");
      header('Location: sponsorship_packages.php?saved=1'); exit;
    } catch(Throwable $e) { $errors[]=str_contains(strtolower($e->getMessage()),'duplicate')?'That package code already exists.':$e->getMessage(); }
  }
}
?>
<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsorship_packages.php">Packages</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $id > 0 ? 'Edit package' : 'Add package' ?></span></nav>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="card shadow-sm border-0 hub-form-card"><div class="card-body"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Package name</label><input class="form-control" name="name" value="<?= h((string)$data['name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Code</label><input class="form-control" name="code" value="<?= h((string)$data['code']) ?>" <?= $id>0?'readonly':'' ?> required><div class="form-text">Fixed after creation because agreements use this code.</div></div>
    <div class="col-md-6"><label class="form-label">Scope</label><select class="form-select" name="scope"><?php foreach(['club'=>'Club-wide','match'=>'Match','player'=>'Player','team'=>'Team','digital'=>'Digital & Media'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['scope']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Category</label><input class="form-control" name="category" value="<?= h((string)$data['category']) ?>" required></div>
    <div class="col-12"><label class="form-label">Description and included benefits</label><textarea class="form-control" name="description" rows="3"><?= h((string)$data['description']) ?></textarea></div>
    <div class="col-md-4"><label class="form-label">Default amount</label><div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" name="amount" value="<?= h((string)$data['amount']) ?>"></div></div>
    <div class="col-md-4"><label class="form-label">Duration</label><select class="form-select" name="duration_type"><?php foreach(['season'=>'Season','fixture'=>'Single fixture','date_range'=>'Date range','ongoing'=>'Ongoing'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['duration_type']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Maximum slots</label><input type="number" min="1" class="form-control" name="max_slots" value="<?= h((string)$data['max_slots']) ?>" placeholder="Unlimited"></div>
    <div class="col-md-6"><label class="form-label">Graphic placement</label><input class="form-control" name="graphic_placement" value="<?= h((string)$data['graphic_placement']) ?>" placeholder="e.g. club_graphic_footer"></div>
    <div class="col-md-3"><label class="form-label">Default logo</label><select class="form-select" name="default_logo_variant"><?php foreach(['white'=>'White','colour'=>'Coloured','package_default'=>'Page default'] as $v=>$l): ?><option value="<?= $v ?>" <?= $data['default_logo_variant']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Sort order</label><input type="number" min="0" class="form-control" name="sort_order" value="<?= h((string)$data['sort_order']) ?>"></div>
    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="graphic_enabled" id="graphics" <?= $data['graphic_enabled']?'checked':'' ?>><label class="form-check-label" for="graphics">Included on graphics</label></div></div>
    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="active" <?= $data['is_active']?'checked':'' ?>><label class="form-check-label" for="active">Package is available</label></div></div>
  </div>
</div><div class="card-footer d-flex justify-content-between hub-actions"><a class="btn btn-outline-secondary" href="sponsorship_packages.php">Cancel</a><button class="btn btn-brand">Save package</button></div></form>
<?php require_once __DIR__.'/footer.php'; ?>
