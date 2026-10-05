<?php
$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';
$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => $action === 'new' ? 'Add Season Ticket Type' : 'Edit Season Ticket Type',
    'subtitle' => 'Set the label, price and season this ticket type applies to.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/audit.php';
ensureSeasonTicketSchema($pdo);

$errors = [];
$type = $id > 0 ? getSeasonTicketType($pdo, $id) : null;
if ($id > 0 && !$type) {
    echo '<div class="alert alert-danger">Season ticket type not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$currentSeason = getCurrentSeason($pdo);
$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);

$data = array_merge([
    'season_id' => $currentSeason['id'] ?? '',
    'name' => '',
    'code' => '',
    'price' => '0.00',
    'is_active' => 1,
    'sort_order' => 0,
], $type ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    }
    foreach (['season_id', 'name', 'code', 'price', 'sort_order'] as $field) {
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ((int) $data['season_id'] <= 0) {
        $errors[] = 'Choose a season.';
    }
    if ($data['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if (!is_numeric($data['price']) || (float) $data['price'] < 0) {
        $errors[] = 'Price must be zero or more.';
    }

    if (!$errors) {
        try {
            saveSeasonTicketType($pdo, $id ?: null, $data);
            auditLog($pdo, $id ? 'season_ticket_type_updated' : 'season_ticket_type_created', ($id ? "Updated season ticket type '{$data['name']}' (#{$id})" : "Created season ticket type '{$data['name']}'"));
            header('Location: season_ticket_types.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = str_contains(strtolower($e->getMessage()), 'duplicate')
                ? 'A ticket type with that code already exists for this season.'
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

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="season_ticket_types.php">Season ticket types</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add type' : 'Edit type' ?></span></nav>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="typeSeason" class="form-label">Season</label>
                <select class="form-select" id="typeSeason" name="season_id" required>
                    <option value="">Select season</option>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?= (int) $season['id'] ?>" <?= (int) $data['season_id'] === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="typeName" class="form-label">Name</label>
                <input type="text" class="form-control" id="typeName" name="name" value="<?= h((string) $data['name']) ?>" placeholder="e.g. Adult" required>
            </div>
            <div class="col-md-6">
                <label for="typeCode" class="form-label">Code</label>
                <input type="text" class="form-control" id="typeCode" name="code" value="<?= h((string) $data['code']) ?>" placeholder="Leave blank to generate from the name">
                <div class="form-text">Used internally and in the Stripe checkout line item.</div>
            </div>
            <div class="col-md-6">
                <label for="typePrice" class="form-label">Price</label>
                <div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" id="typePrice" name="price" value="<?= h((string) $data['price']) ?>" required></div>
            </div>
            <div class="col-md-6">
                <label for="typeSortOrder" class="form-label">Sort order</label>
                <input type="number" min="0" step="1" class="form-control" id="typeSortOrder" name="sort_order" value="<?= (int) $data['sort_order'] ?>">
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="typeActive" name="is_active" value="1" <?= !empty($data['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="typeActive">On sale (visible on the public sign-up page and available for new orders)</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between gap-2">
        <a href="season_ticket_types.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand"><?= $action === 'new' ? 'Create type' : 'Save changes' ?></button>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
