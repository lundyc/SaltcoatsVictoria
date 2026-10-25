<?php
declare(strict_types=1);

/**
 * Events module: venues and events (non-match ticketed occasions such as speaker or tribute nights).
 *
 * Events live in their own tables so they can never appear on the public Fixtures, Results, Table,
 * Stats, calendar or social features, which all read match_fixtures. Ticket types, holds, checkout and
 * scanning are added in later steps (see SCANNER_DEMO_AND_EVENTS_PLAN.md section 4).
 */

require_once __DIR__ . '/schema_guard.php';

const EVENT_STATUSES = [
    'draft' => 'Draft',
    'on_sale' => 'On sale',
    'sold_out' => 'Sold out',
    'postponed' => 'Postponed',
    'cancelled' => 'Cancelled',
    'finished' => 'Finished',
];

/** Who can buy a ticket type: everyone, members only, or members first (early window, then everyone). */
const EVENT_AUDIENCES = [
    'everyone' => 'Everyone',
    'members' => 'Members only',
    'members_first' => 'Members first, then everyone',
];

/** Default member discount (percent) when a ticket type does not set its own. */
const EVENT_DEFAULT_MEMBER_DISCOUNT_PCT = 10.0;
/** Hours of early access for 'members_first' ticket types. */
const EVENT_MEMBER_EARLY_ACCESS_HOURS = 48;

/** Where a ticket type's seats come from: the general seats, or the places held back for people with access needs. */
const EVENT_POOLS = ['general' => 'General seats', 'access' => 'Accessible places'];

/** Minimum ages offered on an event: 0 means no age limit. */
const EVENT_MIN_AGES = [0 => 'No age limit', 14 => '14+', 16 => '16+', 18 => '18+'];

function ensureEventsSchema(PDO $pdo): void
{
    if (schema_guard_done('events')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_venues (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        address VARCHAR(255) NULL,
        postcode VARCHAR(12) NULL,
        map_url VARCHAR(500) NULL,
        photo_path VARCHAR(255) NULL,
        capacity INT UNSIGNED NOT NULL,
        access_notes TEXT NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_venues_active (is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        venue_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        slug VARCHAR(120) NOT NULL,
        short_description VARCHAR(255) NULL,
        description TEXT NULL,
        image_path VARCHAR(255) NULL,
        starts_at DATETIME NOT NULL,
        doors_at DATETIME NULL,
        ends_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        min_age TINYINT UNSIGNED NOT NULL DEFAULT 0,
        age_note VARCHAR(120) NULL,
        visibility VARCHAR(10) NOT NULL DEFAULT 'public',
        organiser_email VARCHAR(190) NULL,
        capacity_override INT UNSIGNED NULL,
        created_by_account_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_events_slug (slug),
        KEY idx_events_starts (starts_at),
        KEY idx_events_status (status, visibility, starts_at),
        CONSTRAINT fk_events_venue FOREIGN KEY (venue_id) REFERENCES event_venues(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_ticket_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        price DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        allocation INT UNSIGNED NOT NULL,
        admits_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
        max_per_order SMALLINT UNSIGNED NULL,
        sale_starts_at DATETIME NULL,
        sale_ends_at DATETIME NULL,
        audience VARCHAR(16) NOT NULL DEFAULT 'everyone',
        member_discount_pct DECIMAL(5,2) NULL,
        visibility VARCHAR(10) NOT NULL DEFAULT 'public',
        sort_order SMALLINT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_ticket_types_event (event_id, is_active, sort_order),
        CONSTRAINT fk_event_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Accessible places: seats held back for people who need them, released to everyone shortly before the event.
    $eventCols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'access_places' => 'ALTER TABLE events ADD COLUMN access_places INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity_override',
        'access_release_hours' => 'ALTER TABLE events ADD COLUMN access_release_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER access_places',
        'access_released_at' => 'ALTER TABLE events ADD COLUMN access_released_at DATETIME NULL AFTER access_release_hours',
    ] as $col => $sql) {
        if (!in_array($col, $eventCols, true)) {
            $pdo->exec($sql);
        }
    }
    $typeCols = $pdo->query('SHOW COLUMNS FROM event_ticket_types')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('pool', $typeCols, true)) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN pool VARCHAR(10) NOT NULL DEFAULT 'general' AFTER visibility");
    }

    // First venue: the home ground. Only seeded into an empty table so edits are never overwritten.
    if ((int) $pdo->query('SELECT COUNT(*) FROM event_venues')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO event_venues (name, address, capacity, photo_path, notes) VALUES (:name, :address, :capacity, :photo, :notes)')
            ->execute([
                ':name' => "Big J's, Campbell Park",
                ':address' => 'Campbell Park, Saltcoats',
                ':capacity' => 30,
                ':photo' => '/uploads/site/big-jays.jpg',
                ':notes' => 'Home ground function space. Confirm the address, postcode and access notes.',
            ]);
    }
    schema_guard_mark('events');
}

/** @return list<array<string,mixed>> */
function events_venues(PDO $pdo, bool $activeOnly = false): array
{
    ensureEventsSchema($pdo);
    $sql = 'SELECT v.*, (SELECT COUNT(*) FROM events e WHERE e.venue_id = v.id) AS event_count FROM event_venues v'
        . ($activeOnly ? ' WHERE v.is_active = 1' : '') . ' ORDER BY v.is_active DESC, v.name ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function events_venue(PDO $pdo, int $id): ?array
{
    ensureEventsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM event_venues WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @param array<string,mixed> $data
 * @return int the venue id
 * @throws RuntimeException on invalid input
 */
function events_save_venue(PDO $pdo, ?int $id, array $data): int
{
    ensureEventsSchema($pdo);
    $name = trim((string) ($data['name'] ?? ''));
    $capacity = (int) ($data['capacity'] ?? 0);
    if ($name === '') {
        throw new RuntimeException('Venue name is required.');
    }
    if ($capacity < 1 || $capacity > 100000) {
        throw new RuntimeException('Capacity must be a whole number of at least 1.');
    }
    $mapUrl = trim((string) ($data['map_url'] ?? ''));
    if ($mapUrl !== '' && !preg_match('#^https?://#i', $mapUrl)) {
        throw new RuntimeException('Map link must start with http:// or https://.');
    }
    $params = [
        ':name' => mb_substr($name, 0, 150),
        ':address' => mb_substr(trim((string) ($data['address'] ?? '')), 0, 255) ?: null,
        ':postcode' => mb_substr(strtoupper(trim((string) ($data['postcode'] ?? ''))), 0, 12) ?: null,
        ':map_url' => $mapUrl !== '' ? mb_substr($mapUrl, 0, 500) : null,
        ':photo' => mb_substr(trim((string) ($data['photo_path'] ?? '')), 0, 255) ?: null,
        ':capacity' => $capacity,
        ':access_notes' => trim((string) ($data['access_notes'] ?? '')) ?: null,
        ':notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ':active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $pdo->prepare('UPDATE event_venues SET name = :name, address = :address, postcode = :postcode, map_url = :map_url, photo_path = :photo,
            capacity = :capacity, access_notes = :access_notes, notes = :notes, is_active = :active WHERE id = :id')->execute($params + [':id' => $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO event_venues (name, address, postcode, map_url, photo_path, capacity, access_notes, notes, is_active)
        VALUES (:name, :address, :postcode, :map_url, :photo, :capacity, :access_notes, :notes, :active)')->execute($params);
    return (int) $pdo->lastInsertId();
}

/** @return list<array<string,mixed>> */
function events_list(PDO $pdo, string $when = 'upcoming'): array
{
    ensureEventsSchema($pdo);
    $where = match ($when) {
        'past' => 'e.starts_at < NOW()',
        'all' => '1 = 1',
        default => 'e.starts_at >= (NOW() - INTERVAL 1 DAY)',
    };
    $order = $when === 'past' ? 'DESC' : 'ASC';
    return $pdo->query("SELECT e.*, v.name AS venue_name, v.capacity AS venue_capacity,
            (SELECT COUNT(*) FROM event_ticket_types t WHERE t.event_id = e.id AND t.is_active = 1) AS type_count,
            (SELECT COALESCE(SUM(t.allocation * t.admits_count), 0) FROM event_ticket_types t WHERE t.event_id = e.id AND t.is_active = 1) AS allocated_seats
        FROM events e JOIN event_venues v ON v.id = e.venue_id WHERE $where ORDER BY e.starts_at $order")->fetchAll(PDO::FETCH_ASSOC);
}

function events_get(PDO $pdo, int $id): ?array
{
    ensureEventsSchema($pdo);
    $stmt = $pdo->prepare('SELECT e.*, v.name AS venue_name, v.capacity AS venue_capacity FROM events e JOIN event_venues v ON v.id = e.venue_id WHERE e.id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Number of public, upcoming events people can see (used to decide whether to show Events links). */
function events_public_upcoming_count(PDO $pdo): int
{
    try {
        ensureEventsSchema($pdo);
        return (int) $pdo->query("SELECT COUNT(*) FROM events WHERE visibility = 'public' AND status IN ('on_sale', 'sold_out', 'postponed') AND starts_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function events_get_by_slug(PDO $pdo, string $slug): ?array
{
    ensureEventsSchema($pdo);
    $stmt = $pdo->prepare('SELECT e.*, v.name AS venue_name, v.address AS venue_address, v.postcode AS venue_postcode, v.map_url AS venue_map_url,
            v.access_notes AS venue_access_notes, v.photo_path AS venue_photo, v.capacity AS venue_capacity
        FROM events e JOIN event_venues v ON v.id = e.venue_id WHERE e.slug = :slug');
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The number of people the event can hold: its own limit if set, otherwise the venue's. */
function events_capacity(array $event): int
{
    $own = (int) ($event['capacity_override'] ?? 0);
    return $own > 0 ? $own : (int) ($event['venue_capacity'] ?? 0);
}

function events_slugify(string $title): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    return $slug !== '' ? mb_substr($slug, 0, 100) : 'event';
}

function events_unique_slug(PDO $pdo, string $title, ?int $ignoreId): string
{
    $base = events_slugify($title);
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $stmt = $pdo->prepare('SELECT id FROM events WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $existing = $stmt->fetchColumn();
        if (!$existing || (int) $existing === (int) $ignoreId) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

function events_parse_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/**
 * @param array<string,mixed> $data
 * @return int the event id
 * @throws RuntimeException on invalid input
 */
function events_save(PDO $pdo, ?int $id, array $data, ?int $accountId): int
{
    ensureEventsSchema($pdo);
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Event title is required.');
    }
    $venue = events_venue($pdo, (int) ($data['venue_id'] ?? 0));
    if (!$venue) {
        throw new RuntimeException('Choose a venue.');
    }
    $starts = events_parse_datetime((string) ($data['starts_at'] ?? ''));
    if ($starts === null) {
        throw new RuntimeException('Start date and time are required.');
    }
    $doors = events_parse_datetime((string) ($data['doors_at'] ?? ''));
    $ends = events_parse_datetime((string) ($data['ends_at'] ?? ''));
    if ($doors !== null && $doors > $starts) {
        throw new RuntimeException('Doors time cannot be after the start time.');
    }
    if ($ends !== null && $ends < $starts) {
        throw new RuntimeException('End time cannot be before the start time.');
    }
    $status = (string) ($data['status'] ?? 'draft');
    if (!isset(EVENT_STATUSES[$status])) {
        throw new RuntimeException('Unknown status.');
    }
    $minAge = (int) ($data['min_age'] ?? 0);
    if (!isset(EVENT_MIN_AGES[$minAge])) {
        throw new RuntimeException('Unknown age limit.');
    }
    $capacityOverride = (int) ($data['capacity_override'] ?? 0);
    if ($capacityOverride < 0) {
        throw new RuntimeException('Capacity cannot be negative.');
    }
    if ($capacityOverride > (int) $venue['capacity']) {
        throw new RuntimeException('This event\'s capacity (' . $capacityOverride . ') is more than the venue holds (' . (int) $venue['capacity'] . ').');
    }
    $email = trim((string) ($data['organiser_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Organiser email is not a valid address.');
    }
    $accessPlaces = max(0, (int) ($data['access_places'] ?? 0));
    $accessHours = (int) ($data['access_release_hours'] ?? 48);
    $eventCapacity = $capacityOverride > 0 ? $capacityOverride : (int) $venue['capacity'];
    if ($accessPlaces > $eventCapacity) {
        throw new RuntimeException('Accessible places (' . $accessPlaces . ') cannot be more than the event holds (' . $eventCapacity . ').');
    }
    if ($accessHours < 0 || $accessHours > 720) {
        throw new RuntimeException('Release unsold accessible places between 0 and 720 hours before the event.');
    }
    $visibility = ($data['visibility'] ?? 'public') === 'hidden' ? 'hidden' : 'public';

    $params = [
        ':venue' => (int) $venue['id'],
        ':title' => mb_substr($title, 0, 200),
        ':short' => mb_substr(trim((string) ($data['short_description'] ?? '')), 0, 255) ?: null,
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':image' => mb_substr(trim((string) ($data['image_path'] ?? '')), 0, 255) ?: null,
        ':starts' => $starts,
        ':doors' => $doors,
        ':ends' => $ends,
        ':status' => $status,
        ':min_age' => $minAge,
        ':age_note' => mb_substr(trim((string) ($data['age_note'] ?? '')), 0, 120) ?: null,
        ':visibility' => $visibility,
        ':email' => $email !== '' ? $email : null,
        ':capacity' => $capacityOverride > 0 ? $capacityOverride : null,
        ':access' => $accessPlaces,
        ':access_hours' => $accessHours,
    ];
    if ($id) {
        $pdo->prepare('UPDATE events SET venue_id = :venue, title = :title, short_description = :short, description = :description, image_path = :image,
            starts_at = :starts, doors_at = :doors, ends_at = :ends, status = :status, min_age = :min_age, age_note = :age_note,
            visibility = :visibility, organiser_email = :email, capacity_override = :capacity, access_places = :access, access_release_hours = :access_hours WHERE id = :id')->execute($params + [':id' => $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO events (venue_id, title, slug, short_description, description, image_path, starts_at, doors_at, ends_at, status,
        min_age, age_note, visibility, organiser_email, capacity_override, access_places, access_release_hours, created_by_account_id)
        VALUES (:venue, :title, :slug, :short, :description, :image, :starts, :doors, :ends, :status, :min_age, :age_note, :visibility, :email, :capacity, :access, :access_hours, :account)')
        ->execute($params + [':slug' => events_unique_slug($pdo, $title, null), ':account' => $accountId]);
    return (int) $pdo->lastInsertId();
}


/** @return list<array<string,mixed>> */
function events_ticket_types(PDO $pdo, int $eventId, bool $activeOnly = false): array
{
    ensureEventsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM event_ticket_types WHERE event_id = :event' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':event' => $eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function events_ticket_type(PDO $pdo, int $id): ?array
{
    ensureEventsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM event_ticket_types WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Seats one ticket type uses up if every ticket is sold: allocation x people admitted per ticket. */
function events_type_seats(array $type): int
{
    return (int) $type['allocation'] * max(1, (int) $type['admits_count']);
}

/**
 * Seats allocated across active ticket types, against the event's capacity.
 *
 * @return array{capacity:int,allocated:int,remaining:int,over:bool}
 */
function events_capacity_summary(PDO $pdo, array $event, ?int $excludeTypeId = null): array
{
    $allocated = 0;
    $accessAllocated = 0;
    foreach (events_ticket_types($pdo, (int) $event['id'], true) as $type) {
        if ($excludeTypeId !== null && (int) $type['id'] === $excludeTypeId) {
            continue;
        }
        $allocated += events_type_seats($type);
        if (($type['pool'] ?? 'general') === 'access') {
            $accessAllocated += events_type_seats($type);
        }
    }
    $capacity = events_capacity($event);
    $accessPlaces = (int) ($event['access_places'] ?? 0);
    $generalAllocated = $allocated - $accessAllocated;
    $generalCapacity = max(0, $capacity - $accessPlaces);
    return [
        'capacity' => $capacity, 'allocated' => $allocated, 'remaining' => $capacity - $allocated, 'over' => $allocated > $capacity,
        'access_places' => $accessPlaces, 'access_allocated' => $accessAllocated, 'general_capacity' => $generalCapacity, 'general_allocated' => $generalAllocated,
        'access_over' => $accessAllocated > $accessPlaces, 'general_over' => $generalAllocated > $generalCapacity,
    ];
}

/** Have the unsold accessible places been opened up to everyone? Manually, or automatically shortly before the event. */
function events_access_released(array $event, ?int $now = null): bool
{
    $now ??= time();
    if ((int) ($event['access_places'] ?? 0) <= 0) {
        return true;
    }
    if (!empty($event['access_released_at']) && strtotime((string) $event['access_released_at']) <= $now) {
        return true;
    }
    return $now >= strtotime((string) $event['starts_at']) - (int) ($event['access_release_hours'] ?? 48) * 3600;
}

/**
 * How many more seats can be sold from each pool right now. Until the accessible places are released, the unsold
 * ones are kept back from general sale, and accessible tickets may only use that pool.
 *
 * @return array{general:int,access:int,total:int}
 */
function events_pool_room(array $event, int $generalSold, int $accessSold, ?int $now = null): array
{
    $capacity = events_capacity($event);
    $accessPlaces = (int) ($event['access_places'] ?? 0);
    $totalLeft = max(0, $capacity - $generalSold - $accessSold);
    $released = events_access_released($event, $now);
    $reserved = $released ? 0 : max(0, $accessPlaces - $accessSold);
    $accessRoom = $released ? $totalLeft : min($totalLeft, max(0, $accessPlaces - $accessSold));
    return ['general' => max(0, $totalLeft - $reserved), 'access' => $accessRoom, 'total' => $totalLeft];
}

/**
 * Save a ticket type. The total seats allocated may not exceed the event capacity unless
 * $allowOverCapacity is true (an explicit, audited override).
 *
 * @param array<string,mixed> $data
 * @return int the ticket type id
 * @throws RuntimeException on invalid input or when the capacity rule is broken
 */
function events_save_ticket_type(PDO $pdo, int $eventId, ?int $id, array $data, bool $allowOverCapacity = false): int
{
    ensureEventsSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Ticket name is required.');
    }
    $priceRaw = trim((string) ($data['price'] ?? '0'));
    if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 10000) {
        throw new RuntimeException('Price must be a number from 0 (free) upwards.');
    }
    $allocation = (int) ($data['allocation'] ?? 0);
    if ($allocation < 1) {
        throw new RuntimeException('Set how many of this ticket can be sold (at least 1).');
    }
    $admits = (int) ($data['admits_count'] ?? 1);
    if ($admits < 1 || $admits > 50) {
        throw new RuntimeException('People admitted per ticket must be between 1 and 50.');
    }
    $maxPerOrder = trim((string) ($data['max_per_order'] ?? ''));
    if ($maxPerOrder !== '' && (!ctype_digit($maxPerOrder) || (int) $maxPerOrder < 1)) {
        throw new RuntimeException('Maximum per order must be blank (no limit) or a whole number of at least 1.');
    }
    $audience = (string) ($data['audience'] ?? 'everyone');
    if (!isset(EVENT_AUDIENCES[$audience])) {
        throw new RuntimeException('Unknown audience.');
    }
    $discountRaw = trim((string) ($data['member_discount_pct'] ?? ''));
    if ($discountRaw !== '' && (!is_numeric($discountRaw) || (float) $discountRaw < 0 || (float) $discountRaw > 100)) {
        throw new RuntimeException('Member discount must be a percentage from 0 to 100, or blank for the default.');
    }
    $saleStarts = events_parse_datetime((string) ($data['sale_starts_at'] ?? ''));
    $saleEnds = events_parse_datetime((string) ($data['sale_ends_at'] ?? ''));
    if ($saleStarts !== null && $saleEnds !== null && $saleEnds <= $saleStarts) {
        throw new RuntimeException('Sales must end after they start.');
    }
    if ($audience === 'members_first' && $saleStarts === null) {
        throw new RuntimeException('"Members first" needs a sale start time: members can buy ' . EVENT_MEMBER_EARLY_ACCESS_HOURS . ' hours before it, everyone from it.');
    }
    $pool = ($data['pool'] ?? 'general') === 'access' ? 'access' : 'general';
    if ($pool === 'access' && (int) ($event['access_places'] ?? 0) <= 0) {
        throw new RuntimeException('Set how many accessible places the event holds (on the event details) before adding accessible tickets.');
    }
    $active = !empty($data['is_active']) ? 1 : 0;

    if ($active) {
        $summary = events_capacity_summary($pdo, $event, $id);
        $seats = $allocation * $admits;
        if ($pool === 'access' && $summary['access_allocated'] + $seats > $summary['access_places'] && !$allowOverCapacity) {
            throw new RuntimeException('That would allocate ' . ($summary['access_allocated'] + $seats) . ' accessible places but the event holds ' . $summary['access_places']
                . '. Lower the number, raise the accessible places on the event, or tick the override.');
        }
        if ($pool === 'general' && $summary['general_allocated'] + $seats > $summary['general_capacity'] && !$allowOverCapacity) {
            throw new RuntimeException('That would allocate ' . ($summary['general_allocated'] + $seats) . ' general seats but only ' . $summary['general_capacity']
                . ' are available once ' . $summary['access_places'] . ' accessible places are held back. Lower the number, or tick the override.');
        }
        if ($summary['allocated'] + $seats > $summary['capacity'] && !$allowOverCapacity) {
            throw new RuntimeException('That would allocate ' . ($summary['allocated'] + $seats) . ' seats but this event holds ' . $summary['capacity']
                . ' (' . max(0, $summary['remaining']) . ' left). Lower the allocation, or tick the override to allow it.');
        }
    }

    $params = [
        ':name' => mb_substr($name, 0, 120),
        ':description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255) ?: null,
        ':price' => round((float) $priceRaw, 2),
        ':allocation' => $allocation,
        ':admits' => $admits,
        ':max' => $maxPerOrder !== '' ? (int) $maxPerOrder : null,
        ':starts' => $saleStarts,
        ':ends' => $saleEnds,
        ':audience' => $audience,
        ':discount' => $discountRaw !== '' ? round((float) $discountRaw, 2) : null,
        ':visibility' => ($data['visibility'] ?? 'public') === 'hidden' ? 'hidden' : 'public',
        ':pool' => $pool,
        ':sort' => (int) ($data['sort_order'] ?? 0),
        ':active' => $active,
    ];
    if ($id) {
        $pdo->prepare('UPDATE event_ticket_types SET name = :name, description = :description, price = :price, allocation = :allocation, admits_count = :admits,
            max_per_order = :max, sale_starts_at = :starts, sale_ends_at = :ends, audience = :audience, member_discount_pct = :discount,
            visibility = :visibility, pool = :pool, sort_order = :sort, is_active = :active WHERE id = :id AND event_id = :event')->execute($params + [':id' => $id, ':event' => $eventId]);
        return $id;
    }
    $pdo->prepare('INSERT INTO event_ticket_types (event_id, name, description, price, allocation, admits_count, max_per_order, sale_starts_at, sale_ends_at,
        audience, member_discount_pct, visibility, pool, sort_order, is_active)
        VALUES (:event, :name, :description, :price, :allocation, :admits, :max, :starts, :ends, :audience, :discount, :visibility, :pool, :sort, :active)')
        ->execute($params + [':event' => $eventId]);
    return (int) $pdo->lastInsertId();
}

/** The member price for a ticket type: its own discount if set, otherwise the club default. */
function events_member_price(array $type): float
{
    $pct = $type['member_discount_pct'] !== null && $type['member_discount_pct'] !== '' ? (float) $type['member_discount_pct'] : EVENT_DEFAULT_MEMBER_DISCOUNT_PCT;
    return round((float) $type['price'] * (1 - $pct / 100), 2);
}

/**
 * Booking fee for a whole order (not per ticket). Worked out so the club receives the full ticket
 * price after Stripe's fee: fee = (total x rate + fixed) / (1 - rate), rounded UP to the penny.
 * Free orders pay no fee. Rates are settings (events_fee_rate_pct, events_fee_fixed_pence); the
 * defaults are Stripe's standard UK card rate of 1.5% + 20p.
 */
function events_booking_fee(PDO $pdo, float $orderTotal): float
{
    if ($orderTotal <= 0) {
        return 0.0;
    }
    require_once __DIR__ . '/site_settings.php';
    return events_fee_for($orderTotal, (float) site_setting($pdo, 'events_fee_rate_pct', '1.5'), (float) site_setting($pdo, 'events_fee_fixed_pence', '20'));
}

/** The fee formula on its own (no database): total in pounds, rate in percent, fixed amount in pence. */
function events_fee_for(float $orderTotal, float $ratePct, float $fixedPence): float
{
    if ($orderTotal <= 0) {
        return 0.0;
    }
    $rate = max(0.0, $ratePct) / 100;
    $fixed = max(0.0, $fixedPence) / 100;
    $fee = ($orderTotal * $rate + $fixed) / (1 - $rate);
    return ceil(round($fee * 100, 6)) / 100;
}
