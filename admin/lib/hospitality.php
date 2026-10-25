<?php
declare(strict_types=1);

/**
 * "Big Js" hospitality suite bookings: settings, availability, pricing, Stripe checkout,
 * confirmation emails. Prices/capacity/notification emails are editable in Admin → Hospitality.
 *
 * Capacity counts paid bookings plus unpaid ones started in the last 30 minutes (so two people
 * can't both check out for the last places).
 */

require_once __DIR__ . '/mailer.php';

const HOSPITALITY_HOLD_MINUTES = 30;

/** Placeholder prices until the club sets the real ones in Admin → Hospitality. */
const HOSPITALITY_DEFAULTS = [
    'enabled' => '1',
    'capacity' => '40',
    'group_min' => '7',
    'individual_max' => '6',
    'price_individual_league' => '35.00',
    'price_individual_cup' => '40.00',
    'price_group_league' => '30.00',
    'price_group_cup' => '35.00',
    'arrival_minutes_before' => '90',
    // Corporate package: shown on the public page and sold by enquiry only (not a payable booking type)
    'price_corporate_league' => '280.00',
    'price_corporate_cup' => '320.00',
    'corporate_guests' => '8',
];

function hospitality_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS hospitality_settings (
        setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hospitality_notify_emails (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hospitality_notify_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hospitality_bookings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fixture_id INT UNSIGNED NOT NULL,
        booking_type VARCHAR(12) NOT NULL DEFAULT 'individual',
        guests INT UNSIGNED NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        is_cup TINYINT(1) NOT NULL DEFAULT 0,
        contact_name VARCHAR(190) NOT NULL,
        contact_email VARCHAR(190) NOT NULL,
        contact_phone VARCHAR(60) NOT NULL,
        company VARCHAR(190) NULL,
        dietary_requirements TEXT NULL,
        notes TEXT NULL,
        agreed_no_refunds TINYINT(1) NOT NULL DEFAULT 0,
        dietary_declared TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending_payment',
        access_token VARCHAR(64) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        paid_at DATETIME NULL,
        confirmation_sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hospitality_token (access_token),
        KEY idx_hospitality_fixture (fixture_id, status),
        KEY idx_hospitality_session (stripe_checkout_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO hospitality_notify_emails (email) VALUES ('colin@lundy.me.uk')");
    $done = true;
}

function hospitality_settings(PDO $pdo): array
{
    hospitality_ensure_schema($pdo);
    $settings = HOSPITALITY_DEFAULTS;
    foreach ($pdo->query('SELECT setting_key, setting_value FROM hospitality_settings') as $row) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    return $settings;
}

function hospitality_save_settings(PDO $pdo, array $values): void
{
    hospitality_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO hospitality_settings (setting_key, setting_value) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach (HOSPITALITY_DEFAULTS as $key => $default) {
        if (array_key_exists($key, $values)) {
            $stmt->execute([':k' => $key, ':v' => (string) $values[$key]]);
        }
    }
}

/** @return list<array{id:int,email:string}> */
function hospitality_notify_emails(PDO $pdo): array
{
    hospitality_ensure_schema($pdo);
    return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'email' => (string) $r['email']],
        $pdo->query('SELECT id, email FROM hospitality_notify_emails ORDER BY email')->fetchAll(PDO::FETCH_ASSOC));
}

function hospitality_is_cup(array $fixture): bool
{
    return stripos((string) ($fixture['competition'] ?? ''), 'cup') !== false;
}

function hospitality_price(array $settings, array $fixture, string $type): float
{
    $key = 'price_' . ($type === 'group' ? 'group' : 'individual') . '_' . (hospitality_is_cup($fixture) ? 'cup' : 'league');
    return round((float) $settings[$key], 2);
}

/** Home league and cup games still to be played (friendlies are not bookable). */
function hospitality_bookable_fixtures(PDO $pdo, int $seasonId): array
{
    hospitality_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT f.id, f.opponent, f.match_date, f.kickoff_time, f.competition, f.is_home, o.logo_path AS opponent_logo
        FROM match_fixtures f LEFT JOIN match_opponents o ON o.id = f.opponent_id
        WHERE f.season_id = :season AND f.is_home = 1 AND f.match_date >= CURDATE()
          AND (f.competition IS NULL OR f.competition NOT LIKE '%friendly%')
          AND (f.status IS NULL OR f.status NOT IN ('played','cancelled','postponed'))
        ORDER BY f.match_date, f.kickoff_time");
    $stmt->execute([':season' => $seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hospitality_fixture(PDO $pdo, int $fixtureId): ?array
{
    $stmt = $pdo->prepare("SELECT f.id, f.season_id, f.opponent, f.match_date, f.kickoff_time, f.competition, f.is_home, o.logo_path AS opponent_logo
        FROM match_fixtures f LEFT JOIN match_opponents o ON o.id = f.opponent_id WHERE f.id = :id");
    $stmt->execute([':id' => $fixtureId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hospitality_places_taken(PDO $pdo, int $fixtureId): int
{
    hospitality_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(guests), 0) FROM hospitality_bookings
        WHERE fixture_id = :f AND (status = 'paid' OR (status = 'pending_payment' AND created_at > (NOW() - INTERVAL " . HOSPITALITY_HOLD_MINUTES . " MINUTE)))");
    $stmt->execute([':f' => $fixtureId]);
    return (int) $stmt->fetchColumn();
}

function hospitality_places_left(PDO $pdo, array $settings, int $fixtureId): int
{
    return max(0, (int) $settings['capacity'] - hospitality_places_taken($pdo, $fixtureId));
}

/**
 * @param array{type:string,guests:int,name:string,email:string,phone:string,company:string,dietary:string,notes:string} $in
 * @return array{id:int, token:string}
 */
function hospitality_create_booking(PDO $pdo, array $fixture, array $in): array
{
    $settings = hospitality_settings($pdo);
    if ($settings['enabled'] !== '1') {
        throw new RuntimeException('Hospitality bookings are not open right now. Please contact the club.');
    }
    $type = $in['type'] === 'group' ? 'group' : 'individual';
    $guests = (int) $in['guests'];
    $groupMin = max(2, (int) $settings['group_min']);
    $indMax = max(1, (int) $settings['individual_max']);
    if ($type === 'individual' && ($guests < 1 || $guests > $indMax)) {
        throw new RuntimeException('Individual bookings are for 1 to ' . $indMax . ' places. For larger parties choose a group booking.');
    }
    if ($type === 'group' && $guests < $groupMin) {
        throw new RuntimeException('Group bookings start at ' . $groupMin . ' places.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT GET_LOCK("hospitality_booking", 5)')->fetchColumn();
        $left = hospitality_places_left($pdo, $settings, (int) $fixture['id']);
        if ($guests > $left) {
            throw new RuntimeException($left > 0 ? 'Sorry, only ' . $left . ' place' . ($left === 1 ? ' is' : 's are') . ' left for this game.' : 'Sorry, this game is fully booked.');
        }
        $unit = hospitality_price($settings, $fixture, $type);
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO hospitality_bookings
            (fixture_id, booking_type, guests, unit_price, total_amount, is_cup, contact_name, contact_email, contact_phone, company,
             dietary_requirements, notes, agreed_no_refunds, dietary_declared, status, access_token)
            VALUES (:f, :type, :g, :unit, :total, :cup, :name, :email, :phone, :company, :diet, :notes, 1, 1, \'pending_payment\', :token)')
            ->execute([
                ':f' => (int) $fixture['id'], ':type' => $type, ':g' => $guests, ':unit' => $unit, ':total' => round($unit * $guests, 2),
                ':cup' => hospitality_is_cup($fixture) ? 1 : 0, ':name' => $in['name'], ':email' => $in['email'], ':phone' => $in['phone'],
                ':company' => $in['company'] !== '' ? $in['company'] : null, ':diet' => $in['dietary'] !== '' ? $in['dietary'] : null,
                ':notes' => $in['notes'] !== '' ? $in['notes'] : null, ':token' => $token,
            ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        $pdo->query('SELECT RELEASE_LOCK("hospitality_booking")')->fetchColumn();
        return ['id' => $id, 'token' => $token];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try { $pdo->query('SELECT RELEASE_LOCK("hospitality_booking")')->fetchColumn(); } catch (Throwable) {}
        throw $e;
    }
}

function hospitality_booking_by_token(PDO $pdo, string $token): ?array
{
    hospitality_ensure_schema($pdo);
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT b.*, f.opponent, f.match_date, f.kickoff_time, f.competition
        FROM hospitality_bookings b JOIN match_fixtures f ON f.id = b.fixture_id WHERE b.access_token = :t");
    $stmt->execute([':t' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hospitality_booking(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT b.*, f.opponent, f.match_date, f.kickoff_time, f.competition
        FROM hospitality_bookings b JOIN match_fixtures f ON f.id = b.fixture_id WHERE b.id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hospitality_arrival_time(array $settings, ?string $kickoff): string
{
    if ($kickoff === null || $kickoff === '') {
        return '';
    }
    $ts = strtotime($kickoff);
    return $ts ? date('H:i', $ts - ((int) $settings['arrival_minutes_before']) * 60) : '';
}

function hospitality_stripe_checkout(PDO $pdo, array $booking, array $fixture, string $successUrl, string $cancelUrl): array
{
    $currency = stripe_default_currency();
    $name = 'Big Js hospitality - Saltcoats Victoria v ' . $fixture['opponent'] . ' (' . date('D j M Y', strtotime((string) $fixture['match_date'])) . ')';
    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string) $booking['contact_email'],
        'line_items' => [[
            'quantity' => (int) $booking['guests'],
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units((float) $booking['unit_price'], $currency),
                'product_data' => ['name' => mb_substr($name, 0, 120), 'description' => 'Per person, ' . ($booking['booking_type'] === 'group' ? 'group' : 'individual') . ' booking'],
            ],
        ]],
        'metadata' => ['kind' => 'hospitality', 'booking_id' => (string) $booking['id']],
        'payment_intent_data' => ['metadata' => ['kind' => 'hospitality', 'booking_id' => (string) $booking['id']]],
    ]);
    $pdo->prepare('UPDATE hospitality_bookings SET stripe_checkout_session_id = :s WHERE id = :id')
        ->execute([':s' => (string) $session['id'], ':id' => (int) $booking['id']]);
    return ['url' => (string) $session['url']];
}

function hospitality_handle_checkout_completed(PDO $pdo, array $session): void
{
    if ((string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $id = (int) ($session['metadata']['booking_id'] ?? 0);
    $booking = $id > 0 ? hospitality_booking($pdo, $id) : null;
    if (!$booking || $booking['status'] === 'paid') {
        return; // unknown, or webhook retry
    }
    $pdo->prepare("UPDATE hospitality_bookings SET status = 'paid', paid_at = NOW() WHERE id = :id")->execute([':id' => $id]);
    hospitality_send_emails($pdo, hospitality_booking($pdo, $id));
}

function hospitality_handle_checkout_expired(PDO $pdo, array $session): void
{
    $id = (int) ($session['metadata']['booking_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE hospitality_bookings SET status = 'expired' WHERE id = :id AND status = 'pending_payment'")->execute([':id' => $id]);
    }
}

function hospitality_send_emails(PDO $pdo, ?array $b): void
{
    if (!$b) {
        return;
    }
    $settings = hospitality_settings($pdo);
    $when = date('l j F Y', strtotime((string) $b['match_date']));
    $ko = !empty($b['kickoff_time']) ? date('H:i', strtotime((string) $b['kickoff_time'])) : 'TBC';
    $arrive = hospitality_arrival_time($settings, (string) ($b['kickoff_time'] ?? ''));
    $lines = [
        'Hi ' . $b['contact_name'] . ',',
        '',
        'Thank you - your Big Js hospitality booking is confirmed and paid.',
        '',
        'Game:     Saltcoats Victoria v ' . $b['opponent'] . ($b['competition'] ? ' (' . $b['competition'] . ')' : ''),
        'Date:     ' . $when,
        'Kick-off: ' . $ko,
        'Arrival:  ' . ($arrive !== '' ? $arrive . ' (1 hour 30 minutes before kick-off)' : '1 hour 30 minutes before kick-off'),
        'Places:   ' . (int) $b['guests'],
        'Total:    ' . gbp((float) $b['total_amount']),
        'Venue:    Big Js hospitality suite, ' . club('ground_name', 'Campbell Park'),
        '',
        'Reminder: bookings are non-refundable. If anything changes for your party, please contact the club.',
        '',
        'Saltcoats Victoria FC',
    ];
    if (empty($b['confirmation_sent_at'])) {
        if (hub_send_mail((string) $b['contact_email'], 'Your Big Js hospitality booking is confirmed', implode("\n", $lines), false)) {
            $pdo->prepare('UPDATE hospitality_bookings SET confirmation_sent_at = NOW() WHERE id = :id')->execute([':id' => (int) $b['id']]);
        }
    }
    $staff = [
        'New Big Js hospitality booking (paid).',
        '',
        'Game:     v ' . $b['opponent'] . ' - ' . $when . ', KO ' . $ko,
        'Type:     ' . ucfirst((string) $b['booking_type']) . ($b['is_cup'] ? ' (cup game price)' : ''),
        'Places:   ' . (int) $b['guests'],
        'Total:    ' . gbp((float) $b['total_amount']),
        'Name:     ' . $b['contact_name'] . ($b['company'] ? ' (' . $b['company'] . ')' : ''),
        'Email:    ' . $b['contact_email'],
        'Phone:    ' . $b['contact_phone'],
        'Dietary:  ' . ($b['dietary_requirements'] ?: 'None declared'),
        'Notes:    ' . ($b['notes'] ?: '-'),
        '',
        'Manage: ' . (defined('APP_ORIGIN') ? APP_ORIGIN : '') . '/admin/hospitality.php',
    ];
    foreach (hospitality_notify_emails($pdo) as $row) {
        hub_send_mail($row['email'], 'New hospitality booking: v ' . $b['opponent'] . ' (' . (int) $b['guests'] . ' places)', implode("\n", $staff), false);
    }
}
