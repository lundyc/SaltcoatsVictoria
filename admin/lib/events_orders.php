<?php
declare(strict_types=1);

/**
 * Event ticket orders: availability, 30-minute holds, member pricing, the booking fee, order creation,
 * and payment handling. Uses the same shared order tables as match tickets (orders, order_items, payments,
 * entitlements, ticket_credentials, order_access_tokens) so tickets can later be scanned by the same gate
 * scanner. Event tickets are recorded in event_tickets.
 *
 * Overselling is prevented by locking the event's ticket type rows (SELECT ... FOR UPDATE) while an order
 * is created, and counting paid tickets plus unexpired holds against each allocation.
 */

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/events_promos.php';
require_once __DIR__ . '/match_tickets.php';

/** How long a customer has to pay. Stripe Checkout sessions cannot expire in under 30 minutes. */
const EVENT_HOLD_MINUTES = 31;

function ensureEventOrdersSchema(PDO $pdo): void
{
    ensureEventsSchema($pdo);
    ensureMatchTicketSchema($pdo); // creates the shared order tables
    if (schema_guard_done('event_orders')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_tickets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        entitlement_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,
        ticket_type_id INT UNSIGNED NOT NULL,
        ticket_label VARCHAR(120) NOT NULL,
        admits_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
        attendee_name VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_tickets_entitlement (entitlement_id),
        KEY idx_event_tickets_event (event_id, ticket_type_id),
        KEY idx_event_tickets_order (order_id),
        CONSTRAINT fk_event_tickets_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_tickets_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
        CONSTRAINT fk_event_tickets_type FOREIGN KEY (ticket_type_id) REFERENCES event_ticket_types(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_order_holds (
        order_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        state VARCHAR(10) NOT NULL DEFAULT 'held',
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (order_id),
        KEY idx_event_holds_event (event_id, state, expires_at),
        KEY idx_event_holds_ip (ip, state, expires_at),
        CONSTRAINT fk_event_holds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_holds_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Holds created before the abuse limit existed have no ip column yet.
    $holdColumns = $pdo->query('SHOW COLUMNS FROM event_order_holds')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ip', $holdColumns, true)) {
        $pdo->exec('ALTER TABLE event_order_holds ADD COLUMN ip VARCHAR(45) NULL AFTER state, ADD KEY idx_event_holds_ip (ip, state, expires_at)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_ticket_changes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_ticket_id INT UNSIGNED NOT NULL,
        old_name VARCHAR(150) NULL,
        new_name VARCHAR(150) NULL,
        changed_by VARCHAR(20) NOT NULL DEFAULT 'buyer',
        changed_by_account_id INT UNSIGNED NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_ticket_changes_ticket (event_ticket_id),
        CONSTRAINT fk_event_ticket_changes_ticket FOREIGN KEY (event_ticket_id) REFERENCES event_tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        subject VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        recipients INT UNSIGNED NOT NULL DEFAULT 0,
        failed INT UNSIGNED NOT NULL DEFAULT 0,
        sent_by_account_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_messages_event (event_id, created_at),
        CONSTRAINT fk_event_messages_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_access_needs (
        order_id INT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,
        needs TEXT NOT NULL,
        consent_at DATETIME NOT NULL,
        delete_after DATE NOT NULL,
        PRIMARY KEY (order_id),
        KEY idx_event_access_needs_delete (delete_after),
        CONSTRAINT fk_event_access_needs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_access_needs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_promo_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        code VARCHAR(30) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        value DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        ticket_type_id INT UNSIGNED NULL,
        unlock_type_id INT UNSIGNED NULL,
        max_uses INT UNSIGNED NULL,
        valid_from DATETIME NULL,
        valid_to DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(200) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_promo_code (event_id, code),
        CONSTRAINT fk_event_promo_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_order_promos (
        order_id INT UNSIGNED NOT NULL,
        promo_id INT UNSIGNED NOT NULL,
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (order_id),
        KEY idx_event_order_promos_promo (promo_id),
        CONSTRAINT fk_event_order_promos_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_order_promos_promo FOREIGN KEY (promo_id) REFERENCES event_promo_codes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('event_orders');
}

/** Most unpaid, still-held orders one person (same address or email) may have open at once. */
const EVENT_MAX_OPEN_HOLDS_PER_BUYER = 3;

/**
 * Tickets (not seats) already taken from one ticket type: paid orders plus pending orders whose hold has
 * not run out. Call inside the order transaction, after locking the type rows.
 */
function events_type_sold(PDO $pdo, int $typeId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM event_tickets t
        JOIN entitlements e ON e.id = t.entitlement_id
        JOIN orders o ON o.id = t.order_id
        LEFT JOIN event_order_holds h ON h.order_id = o.id
        WHERE t.ticket_type_id = :type
          AND ((e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded'))
               OR (e.status = 'pending' AND o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()))");
    $stmt->execute([':type' => $typeId]);
    return (int) $stmt->fetchColumn();
}

/** Seats taken across the whole event (tickets x people admitted per ticket). */
function events_seats_sold(PDO $pdo, int $eventId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(t.admits_count), 0)
        FROM event_tickets t
        JOIN entitlements e ON e.id = t.entitlement_id
        JOIN orders o ON o.id = t.order_id
        LEFT JOIN event_order_holds h ON h.order_id = o.id
        WHERE t.event_id = :event
          AND ((e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded'))
               OR (e.status = 'pending' AND o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()))");
    $stmt->execute([':event' => $eventId]);
    return (int) $stmt->fetchColumn();
}

/** Seats taken, split into general seats and accessible places. @return array{general:int,access:int} */
function events_seats_by_pool(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("SELECT ty.pool, COALESCE(SUM(t.admits_count), 0) AS seats
        FROM event_tickets t
        JOIN event_ticket_types ty ON ty.id = t.ticket_type_id
        JOIN entitlements e ON e.id = t.entitlement_id
        JOIN orders o ON o.id = t.order_id
        LEFT JOIN event_order_holds h ON h.order_id = o.id
        WHERE t.event_id = :event
          AND ((e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded'))
               OR (e.status = 'pending' AND o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()))
        GROUP BY ty.pool");
    $stmt->execute([':event' => $eventId]);
    $out = ['general' => 0, 'access' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['pool'] === 'access' ? 'access' : 'general'] = (int) $r['seats'];
    }
    return $out;
}

/** Access needs are sensitive: deleted 30 days after the event. Safe to call often. */
function events_purge_access_needs(PDO $pdo): int
{
    try {
        $n = $pdo->exec('DELETE FROM event_access_needs WHERE delete_after < CURDATE()');
        return (int) $n;
    } catch (Throwable) {
        return 0;
    }
}

/** What a buyer told us about access (staff only). */
function events_order_access_needs(PDO $pdo, int $orderId): ?string
{
    $st = $pdo->prepare('SELECT needs FROM event_access_needs WHERE order_id = :o');
    $st->execute([':o' => $orderId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/** Tidy up: pending orders whose hold has run out are marked expired so they stop showing as open. */
function events_release_expired_holds(PDO $pdo): int
{
    ensureEventOrdersSchema($pdo);
    $ids = $pdo->query("SELECT h.order_id FROM event_order_holds h JOIN orders o ON o.id = h.order_id
        WHERE h.state = 'held' AND h.expires_at < (NOW() - INTERVAL 2 MINUTE) AND o.status = 'pending_payment'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $orderId) {
        events_expire_order($pdo, (int) $orderId);
    }
    return count($ids);
}

/** Seats came back (a hold lapsed, an order was refunded): tell the waiting list. Never breaks the caller. */
function events_after_seats_freed(PDO $pdo, int $eventId): void
{
    if ($pdo->inTransaction()) {
        return; // creating tables would commit the caller's transaction; the next page visit or cron run catches up
    }
    try {
        require_once __DIR__ . '/events_followups.php';
        events_process_waitlist($pdo, $eventId);
    } catch (Throwable) {
    }
}

function events_order_event_id(PDO $pdo, int $orderId): int
{
    $st = $pdo->prepare('SELECT event_id FROM event_tickets WHERE order_id = :o LIMIT 1');
    $st->execute([':o' => $orderId]);
    return (int) $st->fetchColumn();
}

function events_expire_order(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE orders SET status = 'expired', cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = :id AND status = 'pending_payment'")->execute([':id' => $orderId]);
    $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :id AND status = 'pending'")->execute([':id' => $orderId]);
    $pdo->prepare("UPDATE event_order_holds SET state = 'released' WHERE order_id = :id AND state = 'held'")->execute([':id' => $orderId]);
    events_after_seats_freed($pdo, events_order_event_id($pdo, $orderId));
}

/** Is this person an active season ticket holder this season? (A "member" for early access and discounts.) */
function events_person_is_member(PDO $pdo, int $personId): bool
{
    if ($personId <= 0) {
        return false;
    }
    require_once __DIR__ . '/season.php';
    $season = getCurrentSeason($pdo);
    if (!$season) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM entitlements e JOIN season_passes sp ON sp.entitlement_id = e.id
            WHERE e.person_id = :person AND sp.season_id = :season AND e.status = 'active' AND sp.status = 'active' LIMIT 1");
        $stmt->execute([':person' => $personId, ':season' => (int) $season['id']]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Can this buyer buy this ticket type right now? Returns '' if yes, otherwise a short reason.
 */
function events_type_availability_problem(array $type, bool $isMember, ?int $now = null, bool $unlocked = false): string
{
    $now ??= time();
    if ((int) $type['is_active'] !== 1) {
        return 'This ticket is not on sale.';
    }
    if ($type['visibility'] === 'hidden' && !$unlocked) {
        return 'This ticket is not available.';
    }
    $starts = $type['sale_starts_at'] ? strtotime((string) $type['sale_starts_at']) : null;
    $ends = $type['sale_ends_at'] ? strtotime((string) $type['sale_ends_at']) : null;
    if ($ends !== null && $now > $ends) {
        return 'Sales for this ticket have closed.';
    }
    if ($type['audience'] === 'members' && !$isMember) {
        return 'Members only (active season ticket holders).';
    }
    if ($starts !== null) {
        $opens = $type['audience'] === 'members_first' && $isMember ? $starts - EVENT_MEMBER_EARLY_ACCESS_HOURS * 3600 : $starts;
        if ($now < $opens) {
            return $type['audience'] === 'members_first'
                ? 'On sale to everyone from ' . date('D j M, H:i', $starts) . '. Season ticket holders get early access.'
                : 'On sale from ' . date('D j M, H:i', $starts) . '.';
        }
    }
    return '';
}

/** Price one ticket of this type for this buyer (members get the type's member discount). */
function events_unit_price(array $type, bool $isMember): float
{
    $price = (float) $type['price'];
    return $isMember && $price > 0 ? events_member_price($type) : $price;
}

/**
 * Public view of an event's tickets for this buyer: each type with availability, price and any reason it
 * cannot be bought. Read-only (no locks); the order code re-checks everything under lock.
 *
 * @return list<array<string,mixed>>
 */
function events_public_ticket_options(PDO $pdo, array $event, bool $isMember, ?array $promo = null): array
{
    ensureEventOrdersSchema($pdo);
    $out = [];
    foreach (events_ticket_types($pdo, (int) $event['id'], true) as $type) {
        $unlocked = $promo !== null && events_promo_unlocks($promo, $type);
        if ($type['visibility'] === 'hidden' && !$unlocked) {
            continue;
        }
        $sold = events_type_sold($pdo, (int) $type['id']);
        $left = max(0, (int) $type['allocation'] - $sold);
        $problem = events_type_availability_problem($type, $isMember, null, $unlocked);
        $out[] = $type + [
            'left' => $left,
            'problem' => $problem,
            'unlocked' => $unlocked,
            'unit_price' => events_final_unit_price($type, $isMember, $promo),
            'member_price' => events_member_price($type),
            'buyable' => $problem === '' && $left > 0,
        ];
    }
    return $out;
}

/** True while the event itself is open to buyers. */
function events_is_on_sale(array $event): bool
{
    return $event['status'] === 'on_sale' && strtotime((string) $event['starts_at']) > time();
}

/**
 * Create an order and its tickets under a lock.
 *
 * @param array{name:string,email:string,phone?:string,person_id?:int|null} $buyer
 * @param array<int,int> $cart ticket type id => quantity
 * @param array{attendee_names?:array<int,list<string>>,terms_text?:string,is_member?:bool} $opts
 * @return array{order_id:int,total:float,fee:float,needs_payment:bool,access_token:string}
 * @throws RuntimeException with a customer-friendly message
 */
function events_create_order(PDO $pdo, array $buyer, int $eventId, array $cart, array $opts = []): array
{
    ensureEventOrdersSchema($pdo); // before the transaction: creating tables would commit it
    // Same reason: the first site_setting() call in a request runs CREATE TABLE IF NOT EXISTS, which
    // would silently commit the order transaction (and release its locks) if it happened inside it.
    require_once __DIR__ . '/site_settings.php';
    site_settings_ensure_schema($pdo);
    require_once __DIR__ . '/events_followups.php';
    ensureEventFollowupsSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event || !events_is_on_sale($event)) {
        throw new RuntimeException('Tickets for this event are not on sale.');
    }
    $cart = array_filter(array_map('intval', $cart), static fn (int $q): bool => $q > 0);
    if (!$cart) {
        throw new RuntimeException('Choose at least one ticket.');
    }
    $name = trim((string) ($buyer['name'] ?? ''));
    $email = trim((string) ($buyer['email'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Enter your name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }
    $accessNeeds = trim((string) ($opts['access_needs'] ?? ''));
    if ($accessNeeds !== '') {
        if (mb_strlen($accessNeeds) > 1000) {
            throw new RuntimeException('Please keep your access requirements under 1000 characters.');
        }
        if (empty($opts['access_consent'])) {
            throw new RuntimeException('Please tick the box to let us keep your access information so we can arrange your visit.');
        }
    }
    $isMember = !empty($opts['is_member']);
    $personId = !empty($buyer['person_id']) ? (int) $buyer['person_id'] : 0;
    $needNames = (int) $event['min_age'] > 0;
    $attendeeNames = (array) ($opts['attendee_names'] ?? []);

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // Lock every ticket type for the event so two checkouts cannot take the last ticket together.
        $lock = $pdo->prepare('SELECT * FROM event_ticket_types WHERE event_id = :event ORDER BY id FOR UPDATE');
        $lock->execute([':event' => $eventId]);
        $types = [];
        foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[(int) $row['id']] = $row;
        }

        // A promo or invite code is checked under the same lock, so a limited code cannot be over-used.
        $promo = null;
        $promoCode = events_promo_normalise((string) ($opts['promo_code'] ?? ''));
        if ($promoCode !== '') {
            $lockPromo = $pdo->prepare('SELECT * FROM event_promo_codes WHERE event_id = :e AND code = :c FOR UPDATE');
            $lockPromo->execute([':e' => $eventId, ':c' => $promoCode]);
            $promo = $lockPromo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$promo) {
                throw new RuntimeException('That code is not valid for this event.');
            }
            $promoProblem = events_promo_problem($promo, events_promo_uses($pdo, (int) $promo['id']));
            if ($promoProblem !== '') {
                throw new RuntimeException('Sorry, ' . $promoProblem);
            }
        }

        $lines = [];
        $total = 0.0;
        $discount = 0.0;
        $seatsWanted = 0;
        $accessWanted = 0;
        foreach ($cart as $typeId => $qty) {
            $type = $types[$typeId] ?? null;
            if (!$type) {
                throw new RuntimeException('One of the selected tickets is no longer available.');
            }
            $problem = events_type_availability_problem($type, $isMember, null, $promo !== null && events_promo_unlocks($promo, $type));
            if ($problem !== '') {
                throw new RuntimeException($type['name'] . ': ' . $problem);
            }
            $max = $type['max_per_order'] !== null ? (int) $type['max_per_order'] : 0;
            if ($max > 0 && $qty > $max) {
                throw new RuntimeException('You can buy up to ' . $max . ' x ' . $type['name'] . ' in one order.');
            }
            $left = (int) $type['allocation'] - events_type_sold($pdo, $typeId);
            if ($qty > $left) {
                throw new RuntimeException($left > 0 ? 'Only ' . $left . ' x ' . $type['name'] . ' left.' : $type['name'] . ' has sold out.');
            }
            $unit = events_final_unit_price($type, $isMember, $promo);
            $discount += max(0.0, events_unit_price($type, $isMember) - $unit) * $qty;
            $lines[] = ['type' => $type, 'qty' => $qty, 'unit' => $unit];
            $total += $unit * $qty;
            $seatsWanted += $qty * max(1, (int) $type['admits_count']);
            if (($type['pool'] ?? 'general') === 'access') {
                $accessWanted += $qty * max(1, (int) $type['admits_count']);
            }
        }
        // Accessible places are held back until they are released; general tickets cannot use them.
        $sold = events_seats_by_pool($pdo, $eventId);
        $room = events_pool_room($event, $sold['general'], $sold['access']);
        $generalWanted = $seatsWanted - $accessWanted;
        if ($accessWanted > $room['access']) {
            throw new RuntimeException('Sorry, there are not enough accessible places left.');
        }
        if ($generalWanted > $room['general'] || $seatsWanted > $room['total']) {
            throw new RuntimeException('Sorry, there is not enough room left for that many people.');
        }
        $total = round($total, 2);
        $fee = events_booking_fee($pdo, $total);
        $grand = round($total + $fee, 2);
        $needsPayment = $grand > 0;
        if ($needsPayment) {
            // Stop one buyer reserving the whole event with unpaid checkouts (free orders hold nothing).
            $ip = mb_substr((string) ($opts['ip'] ?? ''), 0, 45);
            $open = $pdo->prepare("SELECT COUNT(*) FROM event_order_holds h JOIN orders o ON o.id = h.order_id
                WHERE h.state = 'held' AND h.expires_at > NOW() AND o.status = 'pending_payment' AND (o.customer_email = :email OR (:ip <> '' AND h.ip = :ip2))");
            $open->execute([':email' => $email, ':ip' => $ip, ':ip2' => $ip]);
            if ((int) $open->fetchColumn() >= EVENT_MAX_OPEN_HOLDS_PER_BUYER) {
                throw new RuntimeException('You already have tickets reserved and waiting for payment. Please complete that payment, or try again in about half an hour.');
            }
        }

        $pdo->prepare('INSERT INTO orders (person_id, customer_name, customer_email, customer_phone, status, subtotal, discount_total, total_amount, currency, source, terms_accepted_at, terms_text_snapshot, completed_at)
            VALUES (:person, :name, :email, :phone, :status, :subtotal, :discount, :total, "GBP", "event_ticket", NOW(), :terms, :completed)')->execute([
            ':discount' => round($discount, 2),
            ':person' => $personId > 0 ? $personId : null,
            ':name' => mb_substr($name, 0, 190),
            ':email' => mb_substr($email, 0, 190),
            ':phone' => mb_substr(trim((string) ($buyer['phone'] ?? '')), 0, 50) ?: null,
            ':status' => $needsPayment ? 'pending_payment' : 'paid',
            ':subtotal' => $total,
            ':total' => $grand,
            ':terms' => mb_substr((string) ($opts['terms_text'] ?? ''), 0, 4000) ?: null,
            ':completed' => $needsPayment ? null : date('Y-m-d H:i:s'),
        ]);
        $orderId = (int) $pdo->lastInsertId();
        if (!empty($opts['remind']) && $needsPayment) {
            events_register_reminder($pdo, $orderId);
        }
        if ($accessNeeds !== '') {
            $pdo->prepare('INSERT INTO event_access_needs (order_id, event_id, needs, consent_at, delete_after) VALUES (:o, :e, :n, NOW(), :d)')
                ->execute([':o' => $orderId, ':e' => $eventId, ':n' => $accessNeeds, ':d' => date('Y-m-d', strtotime((string) $event['starts_at'] . ' +30 days'))]);
        }
        if ($promo) {
            $pdo->prepare('INSERT INTO event_order_promos (order_id, promo_id, discount_total) VALUES (:o, :p, :d)')->execute([':o' => $orderId, ':p' => (int) $promo['id'], ':d' => round($discount, 2)]);
        }
        $pdo->prepare('INSERT INTO payments (order_id, provider, payment_method, amount, currency, status, paid_at)
            VALUES (:order, :provider, :method, :amount, "GBP", :status, :paid)')->execute([
            ':order' => $orderId,
            ':provider' => $needsPayment ? 'stripe' : 'manual',
            ':method' => $needsPayment ? 'stripe' : 'free',
            ':amount' => $grand,
            ':status' => $needsPayment ? 'pending' : 'paid',
            ':paid' => $needsPayment ? null : date('Y-m-d H:i:s'),
        ]);
        $accessToken = ensureOrderAccessToken($pdo, $orderId);

        $entStatus = $needsPayment ? 'pending' : 'active';
        $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
            VALUES (:order, "event_ticket", :ref, :desc, :qty, :price, :line, :meta)');
        $insertEnt = $pdo->prepare('INSERT INTO entitlements (order_item_id, person_id, type, status) VALUES (:item, :person, "event_ticket", :status)');
        $insertTicket = $pdo->prepare('INSERT INTO event_tickets (entitlement_id, order_id, event_id, ticket_type_id, ticket_label, admits_count, attendee_name)
            VALUES (:ent, :order, :event, :type, :label, :admits, :attendee)');
        $insertCred = $pdo->prepare('INSERT INTO ticket_credentials (entitlement_id, token, manual_code, is_active, legacy_source) VALUES (:ent, :token, :code, :active, "event_tickets")');

        foreach ($lines as $line) {
            $type = $line['type'];
            $insertItem->execute([
                ':order' => $orderId, ':ref' => (int) $type['id'], ':desc' => mb_substr((string) $type['name'] . ' - ' . $event['title'], 0, 255),
                ':qty' => $line['qty'], ':price' => $line['unit'], ':line' => round($line['unit'] * $line['qty'], 2),
                ':meta' => json_encode(['event_id' => $eventId, 'ticket_type_id' => (int) $type['id'], 'member_price' => $isMember, 'list_price' => (float) $type['price']], JSON_THROW_ON_ERROR),
            ]);
            $itemId = (int) $pdo->lastInsertId();
            $names = array_values((array) ($attendeeNames[(int) $type['id']] ?? []));
            for ($i = 0; $i < $line['qty']; $i++) {
                $attendee = trim((string) ($names[$i] ?? ''));
                if ($needNames && $attendee === '') {
                    throw new RuntimeException('Enter a name for every ticket (this event has an age limit).');
                }
                $insertEnt->execute([':item' => $itemId, ':person' => $personId > 0 ? $personId : null, ':status' => $entStatus]);
                $entId = (int) $pdo->lastInsertId();
                $insertTicket->execute([
                    ':ent' => $entId, ':order' => $orderId, ':event' => $eventId, ':type' => (int) $type['id'],
                    ':label' => (string) $type['name'], ':admits' => max(1, (int) $type['admits_count']),
                    ':attendee' => $attendee !== '' ? mb_substr($attendee, 0, 150) : null,
                ]);
                $insertCred->execute([
                    ':ent' => $entId, ':token' => matchTicketNewToken($pdo, 'ticket_credentials', 'token', 20),
                    ':code' => match_ticket_generate_manual_code($pdo), ':active' => $needsPayment ? 0 : 1,
                ]);
            }
        }
        if ($fee > 0) {
            $pdo->prepare('INSERT INTO order_items (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
                VALUES (:order, "booking_fee", NULL, "Booking fee", 1, :fee, :fee2, NULL)')->execute([':order' => $orderId, ':fee' => $fee, ':fee2' => $fee]);
        }
        if ($needsPayment) {
            $pdo->prepare('INSERT INTO event_order_holds (order_id, event_id, expires_at, state, ip) VALUES (:order, :event, :expires, "held", :ip)')
                ->execute([':order' => $orderId, ':event' => $eventId, ':expires' => date('Y-m-d H:i:s', time() + EVENT_HOLD_MINUTES * 60), ':ip' => $ip !== '' ? $ip : null]);
        }
        if ($ownTransaction) {
            $pdo->commit();
            events_waitlist_mark_bought($pdo, $eventId, $email);
        }
        return ['order_id' => $orderId, 'total' => $grand, 'fee' => $fee, 'needs_payment' => $needsPayment, 'access_token' => $accessToken];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function events_order_by_token(PDO $pdo, string $token): ?array
{
    ensureEventOrdersSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, h.state AS hold_state, h.expires_at AS hold_expires_at,
            (SELECT t.event_id FROM event_tickets t WHERE t.order_id = o.id LIMIT 1) AS event_id
        FROM order_access_tokens a
        JOIN orders o ON o.id = a.order_id
        LEFT JOIN event_order_holds h ON h.order_id = o.id
        WHERE a.token = :token AND a.purpose = 'public_ticket_order' AND a.revoked_at IS NULL AND o.source = 'event_ticket' LIMIT 1");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function events_order_tickets(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("SELECT t.*, c.token AS ticket_token, c.manual_code, c.is_active AS credential_is_active, e.status AS entitlement_status,
            (SELECT COALESCE(SUM(a.quantity), 0) FROM event_admissions a WHERE a.entitlement_id = t.entitlement_id) AS admitted_qty
        FROM event_tickets t
        JOIN entitlements e ON e.id = t.entitlement_id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        WHERE t.order_id = :order ORDER BY t.id");
    $stmt->execute([':order' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function events_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order ORDER BY id');
    $stmt->execute([':order' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark an order paid and switch its tickets on. If the hold had already run out and the seats were
 * taken meanwhile, the order is flagged for a manual refund instead of being confirmed.
 *
 * @return string 'paid', 'already_paid' or 'needs_refund'
 */
function events_mark_order_paid(PDO $pdo, int $orderId, string $method = 'stripe'): string
{
    ensureEventOrdersSchema($pdo);
    $order = $pdo->prepare("SELECT o.*, h.state AS hold_state, h.expires_at, h.event_id AS hold_event FROM orders o LEFT JOIN event_order_holds h ON h.order_id = o.id WHERE o.id = :id AND o.source = 'event_ticket'");
    $order->execute([':id' => $orderId]);
    $row = $order->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'missing';
    }
    if (in_array($row['status'], ['paid', 'complete'], true)) {
        return 'already_paid';
    }
    $holdLive = $row['hold_state'] === 'held' && strtotime((string) $row['expires_at']) > time();
    if (!$holdLive) {
        // The hold lapsed before the payment arrived: only confirm if the seats are still free.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('SELECT id FROM event_ticket_types WHERE event_id = :event FOR UPDATE')->execute([':event' => (int) $row['hold_event']]);
            $need = $pdo->prepare("SELECT t.ticket_type_id, COUNT(*) AS n FROM event_tickets t WHERE t.order_id = :o GROUP BY t.ticket_type_id");
            $need->execute([':o' => $orderId]);
            $ok = true;
            foreach ($need->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $type = events_ticket_type($pdo, (int) $n['ticket_type_id']);
                if (!$type || events_type_sold($pdo, (int) $n['ticket_type_id']) + (int) $n['n'] > (int) $type['allocation']) {
                    $ok = false;
                }
            }
            if (!$ok) {
                $pdo->prepare("UPDATE orders SET status = 'needs_refund' WHERE id = :id")->execute([':id' => $orderId]);
                $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = COALESCE(paid_at, NOW()), payment_method = :m WHERE order_id = :id")->execute([':m' => $method, ':id' => $orderId]);
                if ($own) {
                    $pdo->commit();
                }
                return 'needs_refund';
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    $pdo->prepare("UPDATE orders SET status = 'paid', completed_at = COALESCE(completed_at, NOW()), cancelled_at = NULL WHERE id = :id")->execute([':id' => $orderId]);
    $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = COALESCE(paid_at, NOW()), payment_method = :m WHERE order_id = :id")->execute([':m' => $method, ':id' => $orderId]);
    $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'active', e.cancelled_at = NULL WHERE oi.order_id = :id AND e.type = 'event_ticket'")->execute([':id' => $orderId]);
    $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 1, c.revoked_at = NULL WHERE oi.order_id = :id AND e.type = 'event_ticket'")->execute([':id' => $orderId]);
    $pdo->prepare("UPDATE event_order_holds SET state = 'paid' WHERE order_id = :id")->execute([':id' => $orderId]);
    return 'paid';
}

/**
 * Create the Stripe Checkout session for a pending event order. The session expires with the hold, and
 * Stripe's expired webhook releases the seats.
 *
 * @return array{url:string,session_id:string}
 */
function events_stripe_create_checkout_session(PDO $pdo, int $orderId, string $successUrl, string $cancelUrl): array
{
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND source = 'event_ticket'");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $currency = stripe_default_currency();
    $lineItems = [];
    foreach (events_order_items($pdo, $orderId) as $item) {
        if ((float) $item['unit_price'] <= 0) {
            continue;
        }
        $lineItems[] = [
            'quantity' => (int) $item['quantity'],
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units((float) $item['unit_price'], $currency),
                'product_data' => ['name' => (string) $item['description_snapshot']],
            ],
        ];
    }
    if (!$lineItems) {
        throw new RuntimeException('Nothing to pay for.');
    }
    $meta = ['kind' => 'event_ticket', 'order_id' => (string) $orderId];
    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string) $order['customer_email'],
        'line_items' => $lineItems,
        'expires_at' => time() + EVENT_HOLD_MINUTES * 60,
        'metadata' => $meta,
        'payment_intent_data' => ['metadata' => $meta],
    ]);
    $pdo->prepare('UPDATE payments SET provider_checkout_session_id = :s WHERE order_id = :id')->execute([':s' => (string) $session['id'], ':id' => $orderId]);
    return ['url' => (string) $session['url'], 'session_id' => (string) $session['id']];
}

function events_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    if ((string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $orderId = (int) ($session['metadata']['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    $result = events_mark_order_paid($pdo, $orderId, 'stripe');
    if ($result === 'paid') {
        require_once __DIR__ . '/events_mail.php';
        events_send_confirmation_email($pdo, $orderId);
    }
    $stmt = $pdo->prepare('SELECT customer_name, customer_email, total_amount FROM orders WHERE id = :id');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($result !== 'already_paid' && $order) {
        stripe_send_payment_notification(
            $pdo,
            $result === 'needs_refund' ? 'Event tickets - NEEDS REFUND (seats were released before payment arrived)' : 'Event tickets',
            (string) $order['customer_name'],
            (string) $order['customer_email'],
            (float) $order['total_amount'],
            'Event ticket order #' . $orderId,
            stripe_public_base_url() . '/admin/events.php'
        );
    }
}

function events_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    $orderId = (int) ($session['metadata']['order_id'] ?? 0);
    if ($orderId > 0) {
        ensureEventOrdersSchema($pdo);
        events_expire_order($pdo, $orderId);
    }
}

/**
 * Issue complimentary tickets (guests, speakers, sponsors). Creates a free, paid-up order with active tickets.
 * Counts against the ticket type's allocation and the event capacity, ignores sale windows and audience, and
 * records who issued it and why.
 *
 * @param array{name:string,email?:string} $recipient
 * @return array{order_id:int,access_token:string}
 * @throws RuntimeException with a message for the admin
 */
function events_issue_complimentary(PDO $pdo, int $eventId, int $typeId, int $qty, array $recipient, string $reason, ?int $accountId, array $attendeeNames = []): array
{
    ensureEventOrdersSchema($pdo); // DDL first: it would commit the transaction below
    $event = events_get($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    if (in_array($event['status'], ['cancelled', 'finished'], true)) {
        throw new RuntimeException('This event is ' . strtolower(EVENT_STATUSES[$event['status']]) . ', so tickets cannot be issued.');
    }
    $name = trim((string) ($recipient['name'] ?? ''));
    $email = trim((string) ($recipient['email'] ?? ''));
    $reason = trim($reason);
    if ($name === '') {
        throw new RuntimeException('Enter who the tickets are for.');
    }
    if ($reason === '') {
        throw new RuntimeException('Enter a reason (for example "Speaker guests"). It is kept in the record.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('That email address is not valid.');
    }
    if ($qty < 1 || $qty > 50) {
        throw new RuntimeException('Quantity must be between 1 and 50.');
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->prepare('SELECT * FROM event_ticket_types WHERE event_id = :event ORDER BY id FOR UPDATE');
        $lock->execute([':event' => $eventId]);
        $type = null;
        foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['id'] === $typeId) {
                $type = $row;
            }
        }
        if (!$type || (int) $type['is_active'] !== 1) {
            throw new RuntimeException('Choose a ticket type that is on sale.');
        }
        $left = (int) $type['allocation'] - events_type_sold($pdo, $typeId);
        if ($qty > $left) {
            throw new RuntimeException($left > 0 ? 'Only ' . $left . ' x ' . $type['name'] . ' left.' : $type['name'] . ' has none left.');
        }
        $seats = $qty * max(1, (int) $type['admits_count']);
        $sold = events_seats_by_pool($pdo, $eventId);
        $room = events_pool_room($event, $sold['general'], $sold['access']);
        $isAccess = ($type['pool'] ?? 'general') === 'access';
        if ($seats > ($isAccess ? $room['access'] : $room['general']) || $seats > $room['total']) {
            throw new RuntimeException($isAccess ? 'There are not enough accessible places left.' : 'There is not enough room left in the event for that many people.');
        }

        $pdo->prepare('INSERT INTO orders (customer_name, customer_email, status, subtotal, discount_total, total_amount, currency, source, completed_at)
            VALUES (:name, :email, "paid", 0.00, 0.00, 0.00, "GBP", "event_ticket", NOW())')->execute([':name' => mb_substr($name, 0, 190), ':email' => $email !== '' ? mb_substr($email, 0, 190) : null]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payments (order_id, provider, payment_method, amount, currency, status, paid_at) VALUES (:o, "manual", "complimentary", 0.00, "GBP", "paid", NOW())')->execute([':o' => $orderId]);
        $accessToken = ensureOrderAccessToken($pdo, $orderId);
        $pdo->prepare('INSERT INTO order_items (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
            VALUES (:o, "event_ticket", :ref, :desc, :qty, 0.00, 0.00, :meta)')->execute([
            ':o' => $orderId, ':ref' => $typeId, ':desc' => mb_substr((string) $type['name'] . ' (complimentary) - ' . $event['title'], 0, 255), ':qty' => $qty,
            ':meta' => json_encode(['event_id' => $eventId, 'ticket_type_id' => $typeId, 'complimentary' => true, 'reason' => mb_substr($reason, 0, 255), 'issued_by_account_id' => $accountId], JSON_THROW_ON_ERROR),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $names = array_values($attendeeNames);
        for ($i = 0; $i < $qty; $i++) {
            $pdo->prepare('INSERT INTO entitlements (order_item_id, type, status) VALUES (:item, "event_ticket", "active")')->execute([':item' => $itemId]);
            $entId = (int) $pdo->lastInsertId();
            $attendee = trim((string) ($names[$i] ?? ''));
            $pdo->prepare('INSERT INTO event_tickets (entitlement_id, order_id, event_id, ticket_type_id, ticket_label, admits_count, attendee_name) VALUES (:e, :o, :ev, :t, :label, :admits, :att)')->execute([
                ':e' => $entId, ':o' => $orderId, ':ev' => $eventId, ':t' => $typeId, ':label' => (string) $type['name'],
                ':admits' => max(1, (int) $type['admits_count']), ':att' => $attendee !== '' ? mb_substr($attendee, 0, 150) : $name,
            ]);
            $pdo->prepare('INSERT INTO ticket_credentials (entitlement_id, token, manual_code, is_active, legacy_source) VALUES (:e, :token, :code, 1, "event_tickets")')->execute([
                ':e' => $entId, ':token' => matchTicketNewToken($pdo, 'ticket_credentials', 'token', 20), ':code' => match_ticket_generate_manual_code($pdo),
            ]);
        }
        if ($own) {
            $pdo->commit();
        }
        return ['order_id' => $orderId, 'access_token' => $accessToken];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * Event orders a signed-in member can open: orders placed with their email address (or issued to their person).
 *
 * @param array<string,mixed> $holder the member row (email, id)
 * @return list<array<string,mixed>> newest event first, with ticket_count, access_token and paid
 */
function events_orders_for_member(PDO $pdo, array $holder): array
{
    ensureEventOrdersSchema($pdo);
    $email = strtolower(trim((string) ($holder['email'] ?? '')));
    if ($email === '') {
        return [];
    }
    $stmt = $pdo->prepare("SELECT o.id, o.status, o.total_amount, o.created_at, ev.id AS event_id, ev.title, ev.slug, ev.starts_at, ev.status AS event_status, v.name AS venue_name,
            COUNT(t.id) AS ticket_count, MIN(a.token) AS access_token
        FROM orders o
        JOIN event_tickets t ON t.order_id = o.id
        JOIN events ev ON ev.id = t.event_id
        JOIN event_venues v ON v.id = ev.venue_id
        LEFT JOIN order_access_tokens a ON a.order_id = o.id AND a.purpose = 'public_ticket_order' AND a.revoked_at IS NULL
        WHERE o.source = 'event_ticket' AND LOWER(o.customer_email) = :email AND o.status IN ('paid', 'complete', 'partially_refunded', 'pending_payment')
        GROUP BY o.id, ev.id, v.name
        ORDER BY ev.starts_at DESC, o.id DESC");
    $stmt->execute([':email' => $email]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['paid'] = in_array($r['status'], ['paid', 'complete', 'partially_refunded'], true) ? 1 : 0;
    }
    return $rows;
}
