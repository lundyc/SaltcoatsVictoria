<?php
$id = (int)($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';
$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => $action === 'new' ? 'Add Sponsorship Type' : 'Edit Sponsorship Type',
    'subtitle' => 'Set the label, default amount, and display order used in the fixture sponsor modal.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$errors = [];
$type = $id > 0 ? getMatchSponsorshipTypeById($pdo, $id) : null;
if ($id > 0 && !$type) {
    echo '<div class="alert alert-danger">Sponsorship type not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$name = (string)($type['name'] ?? '');
$code = (string)($type['code'] ?? '');
$defaultAmount = (string)($type['default_amount'] ?? '0.00');
$sortOrder = (string)($type['sort_order'] ?? getNextMatchSponsorshipTypeSortOrder($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $defaultAmount = trim((string)($_POST['default_amount'] ?? '0'));
    $sortOrder = trim((string)($_POST['sort_order'] ?? ''));

    if ($name === '') {
        $errors[] = 'Sponsorship type name is required.';
    }
    if ($action === 'new' && matchSponsorshipTypeCode($code !== '' ? $code : $name) === '') {
        $errors[] = 'A valid code is required.';
    }
    if (!is_numeric($defaultAmount) || (float)$defaultAmount < 0) {
        $errors[] = 'Default amount must be zero or more.';
    }

    if (!$errors) {
        try {
            saveMatchSponsorshipType(
                $pdo,
                $id > 0 ? $id : null,
                $name,
                $code,
                (float)$defaultAmount,
                $sortOrder === '' ? null : max(0, (int)$sortOrder)
            );
            auditLog($pdo, $action === 'new' ? 'sponsorship_type_created' : 'sponsorship_type_updated', ($action === 'new' ? 'Created' : 'Updated') . " sponsorship type '{$name}'");
            header('Location: sponsorship_types.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = str_contains(strtolower($e->getMessage()), 'duplicate')
                ? 'A sponsorship type with that name or code already exists.'
                : $e->getMessage();
        }
    }
}
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
        <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsorship_packages.php">Packages</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add sponsorship type' : 'Edit sponsorship type' ?></span></nav>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="typeName" class="form-label">Name</label>
                <input type="text" class="form-control" id="typeName" name="name" value="<?= h($name) ?>" required>
            </div>
            <div class="col-md-6">
                <label for="typeCode" class="form-label">Code</label>
                <input type="text" class="form-control" id="typeCode" name="code" value="<?= h($code) ?>" <?= $action === 'edit' ? 'readonly' : '' ?> placeholder="e.g. motm" required>
                <div class="form-text"><?= $action === 'edit' ? 'The code is fixed after creation so existing assignments remain linked.' : 'Lowercase letters, numbers, and underscores; spaces are converted automatically.' ?></div>
            </div>
            <div class="col-md-6">
                <label for="typeDefaultAmount" class="form-label">Default Amount</label>
                <div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" id="typeDefaultAmount" name="default_amount" value="<?= h($defaultAmount) ?>" required></div>
                <div class="form-text">Matchday and Match Ball continue to use the selected season's pricing.</div>
            </div>
            <div class="col-md-6">
                <label for="typeSortOrder" class="form-label">Sort Order</label>
                <input type="number" min="0" step="1" class="form-control" id="typeSortOrder" name="sort_order" value="<?= h($sortOrder) ?>">
                <div class="form-text">Lower numbers appear first in the modal.</div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between gap-2">
        <a href="sponsorship_types.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand"><?= $action === 'new' ? 'Create Type' : 'Save Changes' ?></button>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
