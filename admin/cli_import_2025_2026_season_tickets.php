<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';

ensureSeasonPassSchema($pdo);

$seasonId = 1;

$rows = [
    ['no' => 1, 'name' => 'Robert Gibson', 'type' => 'Adult - Free', 'price' => 0.00, 'order_date' => '', 'paid' => '', 'payment' => '', 'delivery' => 'Collect at match on 26 April (vs Royal Albert)', 'phone' => '07350533968', 'email' => 'RobertGibson92@outlook.com', 'address' => 'Campbell Park', 'status' => 'Pending', 'notes' => ''],
    ['no' => 2, 'name' => 'Eva Gibson', 'type' => 'Kid - Free', 'price' => 0.00, 'order_date' => '', 'paid' => '', 'payment' => '', 'delivery' => 'Collect at match on 26 April (vs Royal Albert)', 'phone' => '', 'email' => '', 'address' => 'Campbell Park', 'status' => 'Pending', 'notes' => ''],
    ['no' => 3, 'name' => 'Colin Thomson', 'type' => 'Adult', 'price' => 60.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => '', 'delivery' => 'Collect at match on 10 May (vs Easterhouse)', 'phone' => '07967118816', 'email' => 'Colin_thomson@btopenworld.com', 'address' => 'Campbell Park', 'status' => 'Done', 'notes' => ''],
    ['no' => 4, 'name' => 'Jim Carswell', 'type' => 'Adult - 65', 'price' => 65.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => 'Bank Transfer (Fundraising)', 'delivery' => 'Collect at match on 26 April (vs Royal Albert)', 'phone' => '07859781972', 'email' => 'jimcarswell1@gmail.com', 'address' => 'Campbell Park', 'status' => 'Done', 'notes' => ''],
    ['no' => 5, 'name' => 'Barry Wilkie', 'type' => 'Adult', 'price' => 60.00, 'order_date' => '', 'paid' => '', 'payment' => '', 'delivery' => 'Other arrangement with the club', 'phone' => '07593083323', 'email' => 'barrywilkie2002@gmail.com', 'address' => '', 'status' => 'Pending', 'notes' => ''],
    ['no' => 6, 'name' => 'Mark Pettigrew', 'type' => 'Adult', 'price' => 60.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => 'Fanbase', 'delivery' => 'Digital', 'phone' => '', 'email' => '', 'address' => '', 'status' => 'Done', 'notes' => ''],
    ['no' => 7, 'name' => 'Con Martin', 'type' => 'Adult - 65', 'price' => 65.00, 'order_date' => '2025-06-08', 'paid' => 'Yes', 'payment' => 'Cash', 'delivery' => 'Given at Highland Games', 'phone' => '', 'email' => '', 'address' => '', 'status' => 'Done', 'notes' => ''],
    ['no' => 8, 'name' => 'Ronnie Cree', 'type' => 'Vics Veteran (65+)', 'price' => 40.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => 'Cash', 'delivery' => 'End of Season Dance', 'phone' => '', 'email' => '', 'address' => '', 'status' => 'Done', 'notes' => 'Original ticket type: Adult - 40'],
    ['no' => 9, 'name' => 'Berryman', 'type' => 'Adult', 'price' => 60.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => 'Cash', 'delivery' => '', 'phone' => '', 'email' => '', 'address' => '', 'status' => 'Done', 'notes' => ''],
    ['no' => 10, 'name' => 'James McGinn', 'type' => 'Adult - 65', 'price' => 65.00, 'order_date' => '', 'paid' => '', 'payment' => '', 'delivery' => '', 'phone' => '07881289059', 'email' => 'JASMCG1969@talktalk.net', 'address' => '', 'status' => 'Pending', 'notes' => ''],
    ['no' => 11, 'name' => 'Brian Eaglesham', 'type' => 'Adult - 65', 'price' => 65.00, 'order_date' => '', 'paid' => 'No', 'payment' => '', 'delivery' => '', 'phone' => '07815713176', 'email' => 'brian.eaglesham@yahoo.com', 'address' => '', 'status' => 'Pending', 'notes' => ''],
    ['no' => 12, 'name' => 'Sammy Eaglesham', 'type' => 'Vics Veteran (65+)', 'price' => 40.00, 'order_date' => '', 'paid' => 'No', 'payment' => '', 'delivery' => '', 'phone' => '07815713176', 'email' => 'brian.eaglesham@yahoo.com', 'address' => '', 'status' => 'Pending', 'notes' => ''],
    ['no' => 13, 'name' => 'Ava Eaglesham', 'type' => 'Wee Vics Pass (Under 16s)', 'price' => 0.00, 'order_date' => '', 'paid' => 'No', 'payment' => '', 'delivery' => '', 'phone' => '07815713176', 'email' => 'brian.eaglesham@yahoo.com', 'address' => '', 'status' => 'Pending', 'notes' => ''],
    ['no' => 14, 'name' => 'Joseph Ring', 'type' => 'Vics Veteran (65+)', 'price' => 40.00, 'order_date' => '', 'paid' => 'Yes', 'payment' => 'SumUp', 'delivery' => 'Post', 'phone' => '07954408771', 'email' => 'Carolgirls@ntlworld.com', 'address' => '', 'status' => 'Pending', 'notes' => 'Original order status: Pending'],
];

function import_payment_method(string $payment, float $price): string
{
    $value = strtolower(trim($payment));
    if ($price <= 0.0001) {
        return 'free_code';
    }
    if (str_contains($value, 'cash')) {
        return 'cash';
    }
    if (str_contains($value, 'bank')) {
        return 'bank_transfer';
    }
    return 'other';
}

function find_or_create_import_person(PDO $pdo, array $row): int
{
    $stmt = $pdo->prepare('SELECT id FROM people WHERE display_name = :name ORDER BY id LIMIT 1');
    $stmt->execute([':name' => trim((string) $row['name'])]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $email = trim((string) $row['email']);
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM people WHERE email_normalized = :email ORDER BY id LIMIT 1');
        $stmt->execute([':email' => people_normalize_email($email)]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $pdo->prepare('UPDATE people SET display_name = COALESCE(NULLIF(display_name, ""), :name), phone = COALESCE(phone, :phone), address_line1 = COALESCE(address_line1, :address) WHERE id = :id')
                ->execute([':name' => $row['name'], ':phone' => trim((string) $row['phone']) ?: null, ':address' => trim((string) $row['address']) ?: null, ':id' => (int) $id]);
            return (int) $id;
        }
    }

    return createPerson($pdo, [
        'display_name' => $row['name'],
        'email' => $email,
        'phone' => $row['phone'],
        'address_line1' => $row['address'],
        'marketing_opt_in' => 1,
    ]);
}

function find_or_create_ticket_type(PDO $pdo, int $seasonId, string $name, float $price, int $sortOrder): int
{
    $stmt = $pdo->prepare('SELECT id FROM season_ticket_types WHERE season_id = :season_id AND name = :name LIMIT 1');
    $stmt->execute([':season_id' => $seasonId, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    return saveSeasonTicketType($pdo, null, [
        'season_id' => $seasonId,
        'name' => $name,
        'code' => seasonTicketTypeCode($name),
        'price' => $price,
        'is_active' => 1,
        'sort_order' => $sortOrder,
    ]);
}

$typeIds = [];
$created = 0;
$skipped = 0;

foreach ($rows as $row) {
    $typeIds[$row['type']] ??= find_or_create_ticket_type($pdo, $seasonId, (string) $row['type'], (float) $row['price'], 100 + count($typeIds));
    $personId = find_or_create_import_person($pdo, $row);

    $existing = $pdo->prepare("SELECT o.id
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN entitlements e ON e.order_item_id = oi.id
        JOIN season_passes sp ON sp.entitlement_id = e.id
        WHERE e.person_id = :person_id
          AND sp.season_id = :season_id
          AND sp.season_ticket_type_id = :type_id
        LIMIT 1");
    $existing->execute([':person_id' => $personId, ':season_id' => $seasonId, ':type_id' => $typeIds[$row['type']]]);
    if ($existing->fetchColumn()) {
        echo 'skip existing: ' . $row['name'] . ' - ' . $row['type'] . PHP_EOL;
        $skipped++;
        continue;
    }

    $paid = strtolower(trim((string) $row['paid'])) === 'yes' || strtolower(trim((string) $row['status'])) === 'done';
    $paymentMethod = import_payment_method((string) $row['payment'], (float) $row['price']);
    $orderDate = trim((string) $row['order_date']) !== '' ? (string) $row['order_date'] . ' 12:00:00' : '2025-06-01 12:00:00';
    $notes = trim(implode(' | ', array_filter([
        '2025/26 import row #' . $row['no'],
        trim((string) $row['payment']) !== '' ? 'Original payment: ' . trim((string) $row['payment']) : '',
        trim((string) $row['delivery']) !== '' ? 'Delivery: ' . trim((string) $row['delivery']) : '',
        trim((string) $row['address']) !== '' ? 'Address: ' . trim((string) $row['address']) : '',
        trim((string) $row['status']) !== '' ? 'Original status: ' . trim((string) $row['status']) : '',
        trim((string) $row['notes']),
    ])));

    $bundle = createSeasonPassOrder(
        $pdo,
        $personId,
        $personId,
        $typeIds[$row['type']],
        'historical_import_2025_2026',
        $paymentMethod,
        (float) $row['price'],
        $paid ? $orderDate : null,
        null,
        $notes,
        $paid
    );

    $orderId = (int) $bundle['id'];
    $pdo->prepare('UPDATE orders SET created_at = :created_at, completed_at = :completed_at WHERE id = :id')
        ->execute([':created_at' => $orderDate, ':completed_at' => $paid ? $orderDate : null, ':id' => $orderId]);
    $pdo->prepare('UPDATE payments SET paid_at = :paid_at WHERE order_id = :id')
        ->execute([':paid_at' => $paid ? $orderDate : null, ':id' => $orderId]);

    echo 'imported: order #' . $orderId . ' ' . $row['name'] . ' - ' . $row['type'] . PHP_EOL;
    $created++;
}

echo 'created=' . $created . ' skipped=' . $skipped . PHP_EOL;
