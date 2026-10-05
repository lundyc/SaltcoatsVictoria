<?php
$id = (int) ($_GET['id'] ?? 0);
$action = 'new';
$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => $action === 'new' ? 'Add Season Ticket Order' : 'Manage Season Ticket Order',
    'subtitle' => 'Record who bought a ticket, how they paid, and how it will be delivered.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/audit.php';
ensureSeasonTicketSchema($pdo);
ensureSeasonPassSchema($pdo);

$errors = [];
$order = null;
if ($id > 0) {
    $bundle = getSeasonPassOrderBundle($pdo, $id);
    if (!$bundle) {
        $legacyPass = getSeasonPassByLegacyOrderId($pdo, $id);
        if ($legacyPass && !empty($legacyPass['order_id'])) {
            header('Location: season_ticket_orders.php?id=' . (int) $legacyPass['order_id']);
            exit;
        }
    } else {
        header('Location: season_ticket_orders.php?id=' . (int) $bundle['id']);
        exit;
    }
    echo '<div class="alert alert-danger">Season ticket order not found in the shared order system.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$currentSeason = getCurrentSeason($pdo);
$initialPersonId = (int) ($_GET['person_id'] ?? 0);
if ($initialPersonId <= 0 && (int) ($_GET['holder_id'] ?? 0) > 0) {
    $initialPersonId = personIdFromLegacyHolderId($pdo, (int) $_GET['holder_id']) ?? 0;
}
$data = array_merge([
    'person_id' => $initialPersonId,
    'season_id' => $currentSeason['id'] ?? '',
    'ticket_type_id' => '',
    'price' => '',
    'order_date' => date('Y-m-d'),
    'paid' => 0,
    'paid_at' => date('Y-m-d'),
    'payment_method' => 'cash',
    'delivery_method' => '',
    'delivery_address' => '',
    'status' => 'pending_payment',
    'special_notes' => '',
], $order ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    }
    $formAction = (string) ($_POST['form_action'] ?? 'save');

    if ($formAction !== 'save') {
        $errors[] = 'Legacy season ticket order edits are retired. Use the shared order screen.';
    } else {
        foreach (['person_id', 'season_id', 'ticket_type_id', 'price', 'order_date', 'paid_at', 'payment_method', 'delivery_method', 'delivery_address', 'status', 'special_notes'] as $field) {
            $data[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $data['paid'] = isset($_POST['paid']) ? 1 : 0;

        if ((int) $data['person_id'] <= 0) {
            $errors[] = 'Choose a person.';
        }
        if ((int) $data['season_id'] <= 0) {
            $errors[] = 'Choose a season.';
        }
        if ((int) $data['ticket_type_id'] <= 0) {
            $errors[] = 'Choose a ticket type.';
        }
        if (!is_numeric($data['price']) || (float) $data['price'] < 0) {
            $errors[] = 'Price must be zero or more.';
        }
        if ($data['order_date'] === '') {
            $errors[] = 'Order date is required.';
        }

        if (!$errors) {
            try {
                $data['source'] = $order['source'] ?? 'admin';
                $personId = (int) $data['person_id'];
                $bundle = createSeasonPassOrder(
                    $pdo,
                    $personId,
                    $personId,
                    (int) $data['ticket_type_id'],
                    'admin',
                    (string) $data['payment_method'],
                    (float) $data['price'],
                    null,
                    null,
                    (string) $data['special_notes'],
                    !empty($data['paid'])
                );
                auditLog($pdo, 'season_ticket_order_created', "Created season ticket order #{$bundle['id']} for '{$bundle['holder_name']}' ({$bundle['type_name']})");
                header('Location: season_ticket_orders.php?id=' . (int) $bundle['id'] . '&saved=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$people = getPeopleDirectory($pdo, ['status' => 'active']);
$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$types = getSeasonTicketTypes($pdo, (int) $data['season_id'] ?: null);
$statusOptions = seasonTicketOrderStatusOptions();
$paymentOptions = seasonTicketPaymentMethodOptions();
$deliveryOptions = seasonTicketDeliveryMethodOptions();
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
        <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="season_ticket_orders.php">Season tickets</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Add ticket</span></nav>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="orderPerson" class="form-label">Person</label>
                <select class="form-select" id="orderPerson" name="person_id" required>
                    <option value="">Select person</option>
                    <?php foreach ($people as $person): ?>
                        <option value="<?= (int) $person['id'] ?>" <?= (int) $data['person_id'] === (int) $person['id'] ? 'selected' : '' ?>><?= h((string) $person['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><a href="club_person.php">Add a person</a> first if they're not listed.</div>
            </div>
            <div class="col-md-6">
                <label for="orderSeason" class="form-label">Season</label>
                <select class="form-select" id="orderSeason" name="season_id" required>
                    <option value="">Select season</option>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?= (int) $season['id'] ?>" <?= (int) $data['season_id'] === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="orderType" class="form-label">Ticket type</label>
                <select class="form-select" id="orderType" name="ticket_type_id" required>
                    <option value="">Select type</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= (int) $type['id'] ?>" data-price="<?= h((string) $type['price']) ?>" <?= (int) $data['ticket_type_id'] === (int) $type['id'] ? 'selected' : '' ?>><?= h((string) $type['name']) ?> (<?= gbp((float) $type['price']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="orderPrice" class="form-label">Price charged</label>
                <div class="input-group"><span class="input-group-text">£</span><input type="number" min="0" step="0.01" class="form-control" id="orderPrice" name="price" value="<?= h((string) $data['price']) ?>" required></div>
            </div>
            <div class="col-md-6">
                <label for="orderDate" class="form-label">Order date</label>
                <input type="date" class="form-control" id="orderDate" name="order_date" value="<?= h((string) $data['order_date']) ?>" required>
            </div>
            <div class="col-md-6">
                <label for="orderStatus" class="form-label">Status</label>
                <select class="form-select" id="orderStatus" name="status">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $data['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="orderPaymentMethod" class="form-label">Payment method</label>
                <select class="form-select" id="orderPaymentMethod" name="payment_method">
                    <?php foreach ($paymentOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $data['payment_method'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="orderPaidAt" class="form-label">Paid on</label>
                <input type="date" class="form-control" id="orderPaidAt" name="paid_at" value="<?= h((string) $data['paid_at']) ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="orderPaid" name="paid" value="1" <?= !empty($data['paid']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="orderPaid">Paid</label>
                </div>
            </div>
            <div class="col-md-6">
                <label for="orderDeliveryMethod" class="form-label">Delivery method</label>
                <select class="form-select" id="orderDeliveryMethod" name="delivery_method">
                    <?php foreach ($deliveryOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $data['delivery_method'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="orderDeliveryAddress" class="form-label">Delivery address</label>
                <input type="text" class="form-control" id="orderDeliveryAddress" name="delivery_address" value="<?= h((string) $data['delivery_address']) ?>" placeholder="Only needed if posting">
            </div>
            <div class="col-12">
                <label for="orderNotes" class="form-label">Special notes</label>
                <input type="text" class="form-control" id="orderNotes" name="special_notes" value="<?= h((string) $data['special_notes']) ?>" placeholder="e.g. Open Training Day">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between gap-2">
        <div class="d-flex gap-2">
            <a href="season_ticket_orders.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
        <button type="submit" class="btn btn-brand" name="form_action" value="save">Create order</button>
    </div>
</form>

<script>(() => {
  const typeSelect = document.getElementById('orderType');
  const priceInput = document.getElementById('orderPrice');
  typeSelect?.addEventListener('change', () => {
    const option = typeSelect.options[typeSelect.selectedIndex];
    const price = option?.dataset.price;
    if (price !== undefined && (priceInput.value === '' || priceInput.dataset.touched !== '1')) {
      priceInput.value = price;
    }
  });
  priceInput?.addEventListener('input', () => { priceInput.dataset.touched = '1'; });
})();</script>

<?php require_once __DIR__ . '/footer.php'; ?>
