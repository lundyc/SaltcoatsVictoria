<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/season_passes.php';
require_once __DIR__ . '/season_ticket_attendance.php';
require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/admissions.php';

const POS_OPERATOR_SESSION_KEY = 'hub_pos_operator_id';

function pos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_locations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        code VARCHAR(40) NOT NULL,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_locations_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $locationColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pos_locations') as $row) {
        $locationColumns[(string) $row['Field']] = true;
    }
    if (!isset($locationColumns['current_fixture_id'])) {
        $pdo->exec('ALTER TABLE pos_locations ADD COLUMN current_fixture_id INT UNSIGNED NULL AFTER description, ADD KEY idx_pos_locations_fixture (current_fixture_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_operators (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        username VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'operator',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_operators_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_operator_locations (
        operator_id INT UNSIGNED NOT NULL,
        location_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (operator_id, location_id),
        KEY idx_pos_operator_locations_location (location_id),
        CONSTRAINT fk_pos_operator_locations_operator FOREIGN KEY (operator_id) REFERENCES pos_operators(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_operator_locations_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        code VARCHAR(40) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_categories_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        sku VARCHAR(80) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        button_colour VARCHAR(20) NOT NULL DEFAULT '#4b0818',
        earns_points TINYINT(1) NOT NULL DEFAULT 1,
        discountable TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_products_category (category_id, is_active),
        UNIQUE KEY uq_pos_products_sku (sku),
        CONSTRAINT fk_pos_products_category FOREIGN KEY (category_id) REFERENCES pos_categories(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_location_products (
        location_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        price_override DECIMAL(10,2) NULL,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (location_id, product_id),
        KEY idx_pos_location_products_product (product_id),
        CONSTRAINT fk_pos_location_products_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_location_products_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sales (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_ref VARCHAR(30) NOT NULL,
        location_id INT UNSIGNED NOT NULL,
        operator_id INT UNSIGNED NULL,
        hub_user_id BIGINT UNSIGNED NULL,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        holder_name VARCHAR(190) NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        points_redeemed INT NOT NULL DEFAULT 0,
        points_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(30) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'complete',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_sales_ref (sale_ref),
        KEY idx_pos_sales_location_created (location_id, created_at),
        KEY idx_pos_sales_person (person_id),
        KEY idx_pos_sales_holder (holder_id),
        CONSTRAINT fk_pos_sales_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_sales_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_sales_operator FOREIGN KEY (operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sale_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sale_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_name VARCHAR(160) NOT NULL,
        category_name VARCHAR(120) NOT NULL,
        qty INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_pos_sale_items_sale (sale_id),
        CONSTRAINT fk_pos_sale_items_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_sale_items_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_discount_rules (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(160) NOT NULL,
        location_id INT UNSIGNED NULL,
        category_id INT UNSIGNED NULL,
        product_id INT UNSIGNED NULL,
        discount_type VARCHAR(20) NOT NULL DEFAULT 'percent',
        discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_discount_rules_active (is_active, starts_at, ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS member_points_ledger (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        sale_id BIGINT UNSIGNED NULL,
        points_delta INT NOT NULL,
        reason VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_member_points_person (person_id, created_at),
        KEY idx_member_points_holder (holder_id, created_at),
        CONSTRAINT fk_member_points_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_member_points_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE,
        CONSTRAINT fk_member_points_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensureAdmissionsSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_admission_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        admission_id INT UNSIGNED NOT NULL,
        fixture_id INT UNSIGNED NOT NULL,
        pos_sale_id BIGINT UNSIGNED NOT NULL,
        pos_sale_item_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        operator_id INT UNSIGNED NULL,
        hub_account_id INT UNSIGNED NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        admission_type VARCHAR(120) NOT NULL,
        unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_admission_link_item (pos_sale_item_id),
        KEY idx_pos_admission_links_fixture (fixture_id),
        KEY idx_pos_admission_links_sale (pos_sale_id),
        CONSTRAINT fk_pos_admission_links_admission FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_admission_links_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_admission_links_sale FOREIGN KEY (pos_sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_admission_links_item FOREIGN KEY (pos_sale_item_id) REFERENCES pos_sale_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_admission_links_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_admission_links_operator FOREIGN KEY (operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_admission_links_account FOREIGN KEY (hub_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    pos_seed_defaults($pdo);
    $done = true;
}

function pos_seed_defaults(PDO $pdo): void
{
    $locations = [
        ['Bar', 'bar', 'Drinks, snacks and selected merch', 10],
        ['Kitchen', 'kitchen', 'Food, drinks and snacks', 20],
        ['Gate', 'gate', 'Match admission and gate sales', 30],
        ['Merch', 'merch', 'Club merchandise', 40],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO pos_locations (name, code, description, sort_order) VALUES (:name, :code, :description, :sort_order)');
    foreach ($locations as [$name, $code, $description, $sort]) {
        $stmt->execute([':name' => $name, ':code' => $code, ':description' => $description, ':sort_order' => $sort]);
    }

    $categories = [
        ['Drinks', 'drinks', 10],
        ['Snacks', 'snacks', 20],
        ['Food', 'food', 30],
        ['Merch', 'merch', 40],
        ['Admission', 'admission', 50],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO pos_categories (name, code, sort_order) VALUES (:name, :code, :sort_order)');
    foreach ($categories as [$name, $code, $sort]) {
        $stmt->execute([':name' => $name, ':code' => $code, ':sort_order' => $sort]);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM pos_products')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $categoryIds = [];
    foreach ($pdo->query('SELECT id, code FROM pos_categories') as $row) {
        $categoryIds[(string) $row['code']] = (int) $row['id'];
    }
    $locationIds = [];
    foreach ($pdo->query('SELECT id, code FROM pos_locations') as $row) {
        $locationIds[(string) $row['code']] = (int) $row['id'];
    }

    $products = [
        ['Tea/Coffee', 'drinks', 1.50, '#7a243b', ['bar', 'kitchen']],
        ['Soft Drink', 'drinks', 2.00, '#7a243b', ['bar', 'kitchen']],
        ['Crisps', 'snacks', 1.00, '#9b6b22', ['bar', 'kitchen']],
        ['Chocolate', 'snacks', 1.20, '#9b6b22', ['bar', 'kitchen']],
        ['Pie', 'food', 3.00, '#4b0818', ['kitchen']],
        ['Burger', 'food', 4.00, '#4b0818', ['kitchen']],
        ['Adult Admission', 'admission', 8.00, '#1f6b4a', ['gate']],
        ['Concession Admission', 'admission', 4.00, '#1f6b4a', ['gate']],
        ['Scarf', 'merch', 12.00, '#ba9a2d', ['bar', 'kitchen', 'gate', 'merch']],
        ['Pin Badge', 'merch', 3.00, '#ba9a2d', ['bar', 'kitchen', 'gate', 'merch']],
    ];
    $productStmt = $pdo->prepare('INSERT INTO pos_products (category_id, name, price, button_colour, sort_order) VALUES (:category_id, :name, :price, :colour, :sort_order)');
    $linkStmt = $pdo->prepare('INSERT IGNORE INTO pos_location_products (location_id, product_id) VALUES (:location_id, :product_id)');
    foreach ($products as $index => [$name, $categoryCode, $price, $colour, $locationCodes]) {
        $productStmt->execute([
            ':category_id' => $categoryIds[$categoryCode],
            ':name' => $name,
            ':price' => $price,
            ':colour' => $colour,
            ':sort_order' => ($index + 1) * 10,
        ]);
        $productId = (int) $pdo->lastInsertId();
        foreach ($locationCodes as $locationCode) {
            $linkStmt->execute([':location_id' => $locationIds[$locationCode], ':product_id' => $productId]);
        }
    }

    $ruleCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_discount_rules')->fetchColumn();
    if ($ruleCount === 0) {
        $categoryId = $categoryIds['merch'] ?? null;
        if ($categoryId) {
            $pdo->prepare("INSERT INTO pos_discount_rules (name, category_id, discount_type, discount_value) VALUES ('Season ticket merch discount', :category_id, 'percent', 10)")
                ->execute([':category_id' => $categoryId]);
        }
    }
}

function pos_current_operator(PDO $pdo): ?array
{
    pos_ensure_schema($pdo);
    hub_auth_start_session();
    $operatorId = (int) ($_SESSION[POS_OPERATOR_SESSION_KEY] ?? 0);
    if ($operatorId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM pos_operators WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $operatorId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pos_current_actor(PDO $pdo): ?array
{
    $hubUser = hub_auth_current_user();
    if ($hubUser !== null) {
        return ['type' => 'hub_user', 'id' => (int) $hubUser['id'], 'account_id' => (int) ($hubUser['account_id'] ?? 0), 'name' => (string) ($hubUser['display_name'] ?: $hubUser['username']), 'role' => (string) $hubUser['role']];
    }
    $operator = pos_current_operator($pdo);
    if ($operator !== null) {
        return ['type' => 'operator', 'id' => (int) $operator['id'], 'name' => (string) $operator['name'], 'role' => (string) $operator['role']];
    }
    return null;
}

function pos_require_actor(PDO $pdo): array
{
    $actor = pos_current_actor($pdo);
    if ($actor !== null) {
        if ((string) $actor['type'] === 'hub_user' && !hub_auth_has_capability('tickets_ops')) {
            http_response_code(403);
            exit('You do not have permission to use POS.');
        }
        return $actor;
    }
    header('Location: /pos/login.php');
    exit;
}

function pos_actor_is_manager(PDO $pdo): bool
{
    $hubUser = hub_auth_current_user();
    if ($hubUser !== null && hub_auth_has_capability('tickets_ops')) {
        return true;
    }
    $operator = pos_current_operator($pdo);
    return $operator !== null && in_array((string) $operator['role'], ['manager', 'admin'], true);
}

function pos_attempt_operator_login(PDO $pdo, string $username, string $password): array
{
    pos_ensure_schema($pdo);
    hub_auth_start_session();
    $stmt = $pdo->prepare('SELECT * FROM pos_operators WHERE username = :username AND is_active = 1 LIMIT 1');
    $stmt->execute([':username' => trim($username)]);
    $operator = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$operator || !password_verify($password, (string) $operator['password_hash'])) {
        return ['ok' => false, 'error' => 'Incorrect operator username or password.'];
    }
    session_regenerate_id(true);
    $_SESSION[POS_OPERATOR_SESSION_KEY] = (int) $operator['id'];
    $pdo->prepare('UPDATE pos_operators SET last_login_at = NOW() WHERE id = :id')->execute([':id' => (int) $operator['id']]);
    return ['ok' => true];
}

function pos_locations_for_actor(PDO $pdo, array $actor): array
{
    pos_ensure_schema($pdo);
    if ((string) $actor['type'] === 'hub_user' || in_array((string) $actor['role'], ['manager', 'admin'], true)) {
        return $pdo->query('SELECT * FROM pos_locations WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT l.* FROM pos_locations l JOIN pos_operator_locations ol ON ol.location_id = l.id WHERE ol.operator_id = :operator AND l.is_active = 1 ORDER BY l.sort_order, l.name');
    $stmt->execute([':operator' => (int) $actor['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pos_location(PDO $pdo, int $locationId): ?array
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_locations WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $locationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pos_products_for_location(PDO $pdo, int $locationId): array
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.code AS category_code, COALESCE(lp.price_override, p.price) AS sell_price
        FROM pos_products p
        JOIN pos_categories c ON c.id = p.category_id
        JOIN pos_location_products lp ON lp.product_id = p.id
        WHERE lp.location_id = :location AND lp.is_available = 1 AND p.is_active = 1 AND c.is_active = 1
        ORDER BY c.sort_order, p.sort_order, p.name');
    $stmt->execute([':location' => $locationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pos_member_lookup(PDO $pdo, string $input): ?array
{
    pos_ensure_schema($pdo);
    ensureSeasonTicketSchema($pdo);
    $token = season_ticket_extract_token($input);
    $manualCode = season_ticket_normalize_manual_code($input);
    $order = null;
    if ($token !== '') {
        $pass = getSeasonPassByCredential($pdo, $token);
        $order = $pass ? seasonPassLegacyOrderShape($pass) : null;
    }
    if (!$order && $manualCode !== '') {
        $pass = getSeasonPassByCredential($pdo, $manualCode);
        $order = $pass ? seasonPassLegacyOrderShape($pass) : null;
    }
    if (!$order) {
        $email = trim($input);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('SELECT * FROM people WHERE email_normalized = :email AND is_active = 1 LIMIT 1');
            $stmt->execute([':email' => people_normalize_email($email)]);
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($person) {
                return pos_member_payload($pdo, [
                    'person_id' => (int) $person['id'],
                    'holder_id' => legacyHolderIdFromPersonId($pdo, (int) $person['id']) ?? 0,
                    'name' => $person['display_name'] ?? '',
                    'email' => $person['email'] ?? '',
                ], false);
            }
        }
        return null;
    }
    return pos_member_payload($pdo, $order, (int) $order['paid'] === 1 && (string) $order['status'] === 'complete');
}

function pos_member_payload(PDO $pdo, array $source, bool $validSeasonTicket): array
{
    $personId = (int) ($source['person_id'] ?? $source['owner_person_id'] ?? 0);
    $holderId = (int) ($source['holder_id'] ?? $source['id'] ?? 0);
    if ($personId <= 0 && $holderId > 0) {
        $personId = personIdFromLegacyHolderId($pdo, $holderId) ?? 0;
    }
    if ($holderId <= 0 && $personId > 0) {
        $holderId = legacyHolderIdFromPersonId($pdo, $personId) ?? 0;
    }
    return [
        'person_id' => $personId,
        'holder_id' => $holderId,
        'name' => (string) ($source['holder_name'] ?? $source['name'] ?? ''),
        'email' => (string) ($source['email'] ?? ''),
        'season_ticket_valid' => $validSeasonTicket,
        'ticket_type' => (string) ($source['type_name'] ?? ''),
        'points_balance' => pos_points_balance($pdo, $personId, $holderId),
    ];
}

function pos_points_balance(PDO $pdo, int $personId, ?int $legacyHolderId = null): int
{
    pos_ensure_schema($pdo);
    if ($personId <= 0 && ($legacyHolderId ?? 0) <= 0) {
        return 0;
    }
    $sql = 'SELECT COALESCE(SUM(points_delta), 0) FROM member_points_ledger WHERE person_id = :person';
    $params = [':person' => $personId > 0 ? $personId : -1];
    if ($legacyHolderId !== null && $legacyHolderId > 0) {
        $sql .= ' OR holder_id = :holder';
        $params[':holder'] = $legacyHolderId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function pos_best_discount_for_item(PDO $pdo, int $locationId, array $product, float $lineSubtotal, bool $hasValidSeasonTicket): float
{
    if (!$hasValidSeasonTicket || (int) ($product['discountable'] ?? 0) !== 1 || $lineSubtotal <= 0) {
        return 0.0;
    }
    $stmt = $pdo->prepare("SELECT * FROM pos_discount_rules
        WHERE is_active = 1
          AND (starts_at IS NULL OR starts_at <= NOW())
          AND (ends_at IS NULL OR ends_at >= NOW())
          AND (location_id IS NULL OR location_id = :location)
          AND (category_id IS NULL OR category_id = :category)
          AND (product_id IS NULL OR product_id = :product)
        ORDER BY product_id IS NOT NULL DESC, category_id IS NOT NULL DESC, location_id IS NOT NULL DESC, discount_value DESC");
    $stmt->execute([
        ':location' => $locationId,
        ':category' => (int) $product['category_id'],
        ':product' => (int) $product['id'],
    ]);
    $best = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $discount = (string) $rule['discount_type'] === 'fixed'
            ? min($lineSubtotal, (float) $rule['discount_value'])
            : $lineSubtotal * ((float) $rule['discount_value'] / 100);
        $best = max($best, $discount);
    }
    return round($best, 2);
}

function pos_resolve_admission_fixture(PDO $pdo, int $locationId): ?array
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT current_fixture_id FROM pos_locations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $locationId]);
    $fixtureId = (int) $stmt->fetchColumn();
    if ($fixtureId > 0) {
        $fixtureStmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE id = :id AND is_home = 1 AND status NOT IN ("cancelled","postponed") LIMIT 1');
        $fixtureStmt->execute([':id' => $fixtureId]);
        $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
        if ($fixture) {
            return $fixture;
        }
    }
    $todayStmt = $pdo->query('SELECT * FROM match_fixtures WHERE is_home = 1 AND match_date = CURDATE() AND status NOT IN ("cancelled","postponed") ORDER BY kickoff_time, id');
    $matches = $todayStmt ? $todayStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return count($matches) === 1 ? $matches[0] : null;
}

function pos_record_admission_items(PDO $pdo, int $saleId, int $locationId, array $actor): array
{
    pos_ensure_schema($pdo);
    $itemsStmt = $pdo->prepare("SELECT i.*, p.name AS product_name, c.code AS category_code
        FROM pos_sale_items i
        JOIN pos_products p ON p.id = i.product_id
        JOIN pos_categories c ON c.id = p.category_id
        WHERE i.sale_id = :sale AND c.code = 'admission'");
    $itemsStmt->execute([':sale' => $saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return ['fixture_id' => null, 'quantity' => 0, 'links' => 0];
    }
    $fixture = pos_resolve_admission_fixture($pdo, $locationId);
    if (!$fixture) {
        throw new RuntimeException('Gate admission sale needs a selected fixture. Choose a fixture for the Gate POS location or ensure exactly one home fixture is today.');
    }

    $fixtureId = (int) $fixture['id'];
    $links = 0;
    $quantity = 0;
    foreach ($items as $item) {
        $existing = $pdo->prepare('SELECT 1 FROM pos_admission_links WHERE pos_sale_item_id = :item LIMIT 1');
        $existing->execute([':item' => (int) $item['id']]);
        if ($existing->fetchColumn()) {
            continue;
        }
        $qty = max(1, (int) $item['qty']);
        $hubAccountId = (string) $actor['type'] === 'hub_user' ? (int) ($actor['account_id'] ?? $actor['id'] ?? 0) : 0;
        $admission = recordDirectAdmission($pdo, $fixtureId, $qty, 'pos', $hubAccountId > 0 ? $hubAccountId : null, [
            'category' => (string) $item['product_name'],
            'external_source' => 'pos',
            'external_reference' => 'sale:' . $saleId . ';item:' . (int) $item['id'],
            'unit_amount' => (float) $item['unit_price'],
            'pos_sale_id' => $saleId,
            'pos_sale_item_id' => (int) $item['id'],
            'operator_id' => (string) $actor['type'] === 'operator' ? (int) $actor['id'] : null,
        ]);
        $pdo->prepare('INSERT INTO pos_admission_links
            (admission_id, fixture_id, pos_sale_id, pos_sale_item_id, product_id, operator_id, hub_account_id, quantity, admission_type, unit_amount)
            VALUES (:admission, :fixture, :sale, :item, :product, :operator, :account, :quantity, :admission_type, :unit_amount)')
            ->execute([
                ':admission' => (int) $admission['admission_id'],
                ':fixture' => $fixtureId,
                ':sale' => $saleId,
                ':item' => (int) $item['id'],
                ':product' => (int) $item['product_id'],
                ':operator' => (string) $actor['type'] === 'operator' ? (int) $actor['id'] : null,
                ':account' => $hubAccountId > 0 ? $hubAccountId : null,
                ':quantity' => $qty,
                ':admission_type' => (string) $item['product_name'],
                ':unit_amount' => (float) $item['unit_price'],
            ]);
        $links++;
        $quantity += $qty;
    }
    return ['fixture_id' => $fixtureId, 'quantity' => $quantity, 'links' => $links];
}

function pos_create_sale(PDO $pdo, int $locationId, array $actor, array $items, string $paymentMethod, ?array $member, int $pointsRedeemed, string $status = 'complete'): array
{
    pos_ensure_schema($pdo);
    require_once __DIR__ . '/pos_trading_days.php';
    require_once __DIR__ . '/pos_controls.php';
    pos_controls_ensure_schema($pdo);
    $tradingDay = pos_trading_day_require_open($pdo);
    $tillSession = pos_till_session_require_open($pdo, (int) $tradingDay['id'], $locationId);
    $products = pos_products_for_location($pdo, $locationId);
    $productMap = [];
    foreach ($products as $product) {
        $productMap[(int) $product['id']] = $product;
    }

    $normalised = [];
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = max(1, min(99, (int) ($item['qty'] ?? 0)));
        if (!isset($productMap[$productId])) {
            continue;
        }
        $product = $productMap[$productId];
        if (array_key_exists('stock_on_hand', $product) && $product['stock_on_hand'] !== null && (float) $product['stock_on_hand'] < $qty) {
            throw new RuntimeException((string) $product['name'] . ' has only ' . number_format((float) $product['stock_on_hand'], 2) . ' in stock.');
        }
        $unit = round((float) $product['sell_price'], 2);
        $lineSubtotal = round($unit * $qty, 2);
        $discount = pos_best_discount_for_item($pdo, $locationId, $product, $lineSubtotal, (bool) ($member['season_ticket_valid'] ?? false));
        $normalised[] = ['product' => $product, 'qty' => $qty, 'unit' => $unit, 'subtotal' => $lineSubtotal, 'discount' => $discount, 'total' => round($lineSubtotal - $discount, 2)];
    }
    if ($normalised === []) {
        throw new RuntimeException('No valid products were selected.');
    }
    if (!in_array($paymentMethod, ['cash', 'card', 'card_stripe_app'], true)) {
        throw new RuntimeException('Select cash or card to finalise the sale.');
    }
    if (!in_array($status, ['complete', 'pending_card', 'pending_cash'], true)) {
        throw new RuntimeException('Invalid POS sale status.');
    }

    $subtotal = round(array_sum(array_column($normalised, 'subtotal')), 2);
    $discountTotal = round(array_sum(array_column($normalised, 'discount')), 2);
    $totalBeforePoints = round(array_sum(array_column($normalised, 'total')), 2);
    $earnableTotal = 0.0;
    foreach ($normalised as $line) {
        if ((int) ($line['product']['earns_points'] ?? 0) === 1) {
            $earnableTotal += (float) $line['total'];
        }
    }
    $personId = (int) ($member['person_id'] ?? 0);
    $holderId = (int) ($member['holder_id'] ?? 0);
    $pointsBalance = $personId > 0 ? pos_points_balance($pdo, $personId, $holderId) : 0;
    $pointsRedeemed = max(0, min($pointsRedeemed, $pointsBalance));
    $pointsDiscount = round(min($totalBeforePoints, $pointsRedeemed / 100), 2);
    $total = round($totalBeforePoints - $pointsDiscount, 2);
    $pointsUsed = (int) round($pointsDiscount * 100);
    $pointsEarned = $personId > 0 ? (int) floor(max(0, min($earnableTotal, $total))) : 0;

    $saleRef = 'POS-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO pos_sales
            (sale_ref, trading_day_id, till_session_id, location_id, operator_id, hub_user_id, person_id, holder_id, holder_name, subtotal, discount_total, points_redeemed, points_discount, total, payment_method, status, completed_at)
            VALUES (:sale_ref, :trading_day_id, :till_session_id, :location, :operator, :hub_user, :person, :holder_id, :holder_name, :subtotal, :discount_total, :points_redeemed, :points_discount, :total, :payment_method, :status, :completed_at)');
        $stmt->execute([
            ':sale_ref' => $saleRef,
            ':trading_day_id' => (int) $tradingDay['id'],
            ':till_session_id' => (int) $tillSession['id'],
            ':location' => $locationId,
            ':operator' => (string) $actor['type'] === 'operator' ? (int) $actor['id'] : null,
            ':hub_user' => (string) $actor['type'] === 'hub_user' ? (int) $actor['id'] : null,
            ':person' => $personId > 0 ? $personId : null,
            ':holder_id' => $holderId > 0 ? $holderId : null,
            ':holder_name' => $personId > 0 ? (string) ($member['name'] ?? '') : null,
            ':subtotal' => $subtotal,
            ':discount_total' => $discountTotal,
            ':points_redeemed' => $pointsUsed,
            ':points_discount' => $pointsDiscount,
            ':total' => $total,
            ':payment_method' => $paymentMethod,
            ':status' => $status,
            ':completed_at' => $status === 'complete' ? date('Y-m-d H:i:s') : null,
        ]);
        $saleId = (int) $pdo->lastInsertId();
        if ($status === 'complete') {
            pos_assign_receipt_number($pdo, $saleId);
        }
        $itemStmt = $pdo->prepare('INSERT INTO pos_sale_items
            (sale_id, product_id, product_name, category_name, qty, unit_price, discount_amount, line_total)
            VALUES (:sale, :product, :product_name, :category_name, :qty, :unit, :discount, :line_total)');
        foreach ($normalised as $line) {
            $product = $line['product'];
            $itemStmt->execute([
                ':sale' => $saleId,
                ':product' => (int) $product['id'],
                ':product_name' => (string) $product['name'],
                ':category_name' => (string) $product['category_name'],
                ':qty' => (int) $line['qty'],
                ':unit' => (float) $line['unit'],
                ':discount' => (float) $line['discount'],
                ':line_total' => (float) $line['total'],
            ]);
        }
        if ($status === 'complete') {
            pos_apply_sale_points($pdo, $saleId, $pointsEarned);
            pos_record_admission_items($pdo, $saleId, $locationId, $actor);
            pos_record_stock_for_sale($pdo, $saleId);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'sale_id' => $saleId,
        'sale_ref' => $saleRef,
        'subtotal' => $subtotal,
        'discount_total' => $discountTotal,
        'points_redeemed' => $pointsUsed,
        'points_discount' => $pointsDiscount,
        'points_earned' => $pointsEarned,
        'total' => $total,
        'points_balance' => $personId > 0 ? pos_points_balance($pdo, $personId, $holderId) : 0,
        'status' => $status,
        'trading_day_id' => (int) $tradingDay['id'],
        'till_session_id' => (int) $tillSession['id'],
    ];
}

function pos_apply_sale_points(PDO $pdo, int $saleId, ?int $pointsEarned = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        throw new RuntimeException('POS sale was not found.');
    }
    $personId = (int) ($sale['person_id'] ?? 0);
    $holderId = (int) ($sale['holder_id'] ?? 0);
    if ($personId <= 0 && $holderId > 0) {
        $personId = personIdFromLegacyHolderId($pdo, $holderId) ?? 0;
    }
    if ($personId <= 0) {
        return ['points_earned' => 0, 'points_balance' => 0];
    }
    $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM member_points_ledger WHERE sale_id = :sale');
    $existingStmt->execute([':sale' => $saleId]);
    if ((int) $existingStmt->fetchColumn() > 0) {
        return ['points_earned' => 0, 'points_balance' => pos_points_balance($pdo, $personId, $holderId)];
    }

    $pointsUsed = (int) ($sale['points_redeemed'] ?? 0);
    if ($pointsEarned === null) {
        $earnableStmt = $pdo->prepare('SELECT COALESCE(SUM(i.line_total), 0)
            FROM pos_sale_items i
            JOIN pos_products p ON p.id = i.product_id
            WHERE i.sale_id = :sale AND p.earns_points = 1');
        $earnableStmt->execute([':sale' => $saleId]);
        $earnableTotal = (float) $earnableStmt->fetchColumn();
        $pointsEarned = (int) floor(max(0, min($earnableTotal, (float) $sale['total'])));
    }

    if ($pointsUsed > 0) {
        $pdo->prepare('INSERT INTO member_points_ledger (person_id, sale_id, points_delta, reason) VALUES (:person, :sale, :points, :reason)')
            ->execute([':person' => $personId, ':sale' => $saleId, ':points' => -$pointsUsed, ':reason' => 'Redeemed in POS sale']);
    }
    if ($pointsEarned > 0) {
        $pdo->prepare('INSERT INTO member_points_ledger (person_id, sale_id, points_delta, reason) VALUES (:person, :sale, :points, :reason)')
            ->execute([':person' => $personId, ':sale' => $saleId, ':points' => $pointsEarned, ':reason' => 'Earned from POS sale']);
    }

    return ['points_earned' => $pointsEarned, 'points_balance' => pos_points_balance($pdo, $personId, $holderId)];
}

function pos_complete_pending_card_sale(PDO $pdo, int $saleId, array $actor, string $paymentReference = ''): array
{
    pos_ensure_schema($pdo);
    require_once __DIR__ . '/pos_controls.php';
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        throw new RuntimeException('POS sale was not found.');
    }
    if ((string) $sale['status'] !== 'pending_card') {
        throw new RuntimeException('This card sale is not waiting for confirmation.');
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        throw new RuntimeException('This pending card sale belongs to another operator.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE pos_sales SET status = 'complete', payment_method = 'card_stripe_app', payment_reference = :payment_reference, completed_at = NOW() WHERE id = :id AND status = 'pending_card'")
            ->execute([':id' => $saleId, ':payment_reference' => trim($paymentReference) !== '' ? trim($paymentReference) : null]);
        pos_assign_receipt_number($pdo, $saleId);
        $points = pos_apply_sale_points($pdo, $saleId);
        pos_record_admission_items($pdo, $saleId, (int) $sale['location_id'], $actor);
        pos_record_stock_for_sale($pdo, $saleId);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'sale_id' => $saleId,
        'sale_ref' => (string) $sale['sale_ref'],
        'total' => (float) $sale['total'],
        'points_earned' => (int) $points['points_earned'],
        'points_balance' => (int) $points['points_balance'],
        'status' => 'complete',
    ];
}

function pos_cancel_pending_card_sale(PDO $pdo, int $saleId, array $actor): void
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return;
    }
    if ((string) $sale['status'] !== 'pending_card') {
        throw new RuntimeException('Only pending card sales can be cancelled.');
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        throw new RuntimeException('This pending card sale belongs to another operator.');
    }
    $pdo->prepare("UPDATE pos_sales SET status = 'cancelled_card' WHERE id = :id AND status = 'pending_card'")
        ->execute([':id' => $saleId]);
}

function pos_complete_pending_cash_sale(PDO $pdo, int $saleId, array $actor): array
{
    pos_ensure_schema($pdo);
    require_once __DIR__ . '/pos_controls.php';
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        throw new RuntimeException('POS sale was not found.');
    }
    if ((string) $sale['status'] !== 'pending_cash') {
        throw new RuntimeException('This cash sale is not waiting for confirmation.');
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        throw new RuntimeException('This pending cash sale belongs to another operator.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE pos_sales SET status = 'complete', payment_method = 'cash', completed_at = NOW() WHERE id = :id AND status = 'pending_cash'")
            ->execute([':id' => $saleId]);
        pos_assign_receipt_number($pdo, $saleId);
        $points = pos_apply_sale_points($pdo, $saleId);
        pos_record_admission_items($pdo, $saleId, (int) $sale['location_id'], $actor);
        pos_record_stock_for_sale($pdo, $saleId);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'sale_id' => $saleId,
        'sale_ref' => (string) $sale['sale_ref'],
        'total' => (float) $sale['total'],
        'points_earned' => (int) $points['points_earned'],
        'points_balance' => (int) $points['points_balance'],
        'status' => 'complete',
    ];
}

function pos_cancel_pending_cash_sale(PDO $pdo, int $saleId, array $actor): void
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return;
    }
    if ((string) $sale['status'] !== 'pending_cash') {
        throw new RuntimeException('Only pending cash sales can be cancelled.');
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        throw new RuntimeException('This pending cash sale belongs to another operator.');
    }
    $pdo->prepare("UPDATE pos_sales SET status = 'cancelled_cash' WHERE id = :id AND status = 'pending_cash'")
        ->execute([':id' => $saleId]);
}

function pos_pending_card_sale(PDO $pdo, int $saleId, array $actor): ?array
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id AND status = "pending_card" LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return null;
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        return null;
    }

    return [
        'sale_id' => (int) $sale['id'],
        'sale_ref' => (string) $sale['sale_ref'],
        'total' => (float) $sale['total'],
        'status' => (string) $sale['status'],
    ];
}

function pos_pending_cash_sale(PDO $pdo, int $saleId, array $actor): ?array
{
    pos_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id AND status = "pending_cash" LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return null;
    }
    if ((string) $actor['type'] === 'operator' && (int) ($sale['operator_id'] ?? 0) !== (int) $actor['id']) {
        return null;
    }

    return [
        'sale_id' => (int) $sale['id'],
        'sale_ref' => (string) $sale['sale_ref'],
        'total' => (float) $sale['total'],
        'status' => (string) $sale['status'],
    ];
}

function pos_sales_summary(PDO $pdo, ?string $date = null): array
{
    pos_ensure_schema($pdo);
    if ($date === null) {
        require_once __DIR__ . '/pos_trading_days.php';
        $activeTradingDay = pos_trading_day_active($pdo);
        if ($activeTradingDay) {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, COALESCE(SUM(total),0) AS total, COALESCE(SUM(payment_method = "cash"),0) AS cash_count, COALESCE(SUM(payment_method IN ("card","card_stripe_app")),0) AS card_count FROM pos_sales WHERE trading_day_id = :trading_day_id AND status = "complete"');
            $stmt->execute([':trading_day_id' => (int) $activeTradingDay['id']]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'cash_count' => 0, 'card_count' => 0];
        }
    }

    $date = $date ?: date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) AS sales_count, COALESCE(SUM(total),0) AS total, COALESCE(SUM(payment_method = "cash"),0) AS cash_count, COALESCE(SUM(payment_method IN ("card","card_stripe_app")),0) AS card_count FROM pos_sales WHERE DATE(created_at) = :date AND status = "complete"');
    $stmt->execute([':date' => $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'cash_count' => 0, 'card_count' => 0];
}
