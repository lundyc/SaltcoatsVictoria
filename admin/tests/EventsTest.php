<?php
declare(strict_types=1);

/**
 * Events module rules that need no database: booking fee, capacity and seat counting, member price, ticket
 * availability windows and audiences, and the scanner's "today". The ordering and scanning flows are
 * exercised against a database separately (they hold locks and create orders).
 */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/events_orders.php';
require_once __DIR__ . '/../lib/admissions.php';

$ticketType = static fn (array $over = []): array => $over + [
    'id' => 1, 'name' => 'Standard', 'price' => '10.00', 'allocation' => 10, 'admits_count' => 1, 'is_active' => 1, 'visibility' => 'public',
    'audience' => 'everyone', 'sale_starts_at' => null, 'sale_ends_at' => null, 'member_discount_pct' => null, 'max_per_order' => null,
];

$harness->test('events booking fee covers the card fee on the whole order, rounded up to the penny', function () use ($harness): void {
    $harness->assertSame(0.0, events_fee_for(0.0, 1.5, 20.0), 'free orders pay no fee');
    $harness->assertSame(0.28, events_fee_for(5.0, 1.5, 20.0));
    $harness->assertSame(0.36, events_fee_for(10.0, 1.5, 20.0));
    $harness->assertSame(0.59, events_fee_for(25.0, 1.5, 20.0));
    $harness->assertSame(1.12, events_fee_for(60.0, 1.5, 20.0));
});

$harness->test('events booking fee is charged once per order, not per ticket', function () use ($harness): void {
    // Two £10 tickets in one order pay one fee on £20, which is less than two separate £10 fees.
    $harness->assertTrue(events_fee_for(20.0, 1.5, 20.0) < 2 * events_fee_for(10.0, 1.5, 20.0));
});

$harness->test('events booking fee leaves the club with the full ticket price after Stripe takes its cut', function () use ($harness): void {
    foreach ([4.0, 12.5, 36.0, 99.99] as $total) {
        $fee = events_fee_for($total, 1.5, 20.0);
        $customerPays = $total + $fee;
        $afterStripe = $customerPays - ($customerPays * 0.015 + 0.20);
        $harness->assertTrue($afterStripe >= $total - 0.0001, 'club short on £' . $total);
        $harness->assertTrue($afterStripe < $total + 0.02, 'fee too generous on £' . $total);
    }
});

$harness->test('events capacity uses the event limit when set, otherwise the venue', function () use ($harness): void {
    $harness->assertSame(30, events_capacity(['capacity_override' => null, 'venue_capacity' => 30]));
    $harness->assertSame(20, events_capacity(['capacity_override' => 20, 'venue_capacity' => 30]));
    $harness->assertSame(30, events_capacity(['capacity_override' => 0, 'venue_capacity' => 30]));
});

$harness->test('a ticket that admits several people uses that many seats', function () use ($harness, $ticketType): void {
    $harness->assertSame(14, events_type_seats($ticketType(['allocation' => 14])));
    $harness->assertSame(16, events_type_seats($ticketType(['allocation' => 2, 'admits_count' => 8])));
});

$harness->test('member price applies the default or the ticket type discount', function () use ($harness, $ticketType): void {
    $harness->assertSame(9.0, events_member_price($ticketType()));
    $harness->assertSame(8.0, events_member_price($ticketType(['member_discount_pct' => '20'])));
    $harness->assertSame(10.0, events_member_price($ticketType(['member_discount_pct' => '0'])));
    $harness->assertSame(9.0, events_unit_price($ticketType(), true));
    $harness->assertSame(10.0, events_unit_price($ticketType(), false));
    $harness->assertSame(0.0, events_unit_price($ticketType(['price' => '0.00']), true), 'free tickets stay free');
});

$harness->test('ticket availability honours on-sale, hidden, windows and audience', function () use ($harness, $ticketType): void {
    $now = strtotime('2026-10-20 12:00:00');
    $harness->assertSame('', events_type_availability_problem($ticketType(), false, $now));
    $harness->assertTrue(events_type_availability_problem($ticketType(['is_active' => 0]), false, $now) !== '');
    $harness->assertTrue(events_type_availability_problem($ticketType(['visibility' => 'hidden']), true, $now) !== '');
    $harness->assertTrue(events_type_availability_problem($ticketType(['sale_ends_at' => '2026-10-19 12:00:00']), false, $now) !== '', 'sales closed');
    $harness->assertTrue(events_type_availability_problem($ticketType(['sale_starts_at' => '2026-10-21 12:00:00']), false, $now) !== '', 'sales not open yet');
    $harness->assertTrue(events_type_availability_problem($ticketType(['audience' => 'members']), false, $now) !== '', 'members only');
    $harness->assertSame('', events_type_availability_problem($ticketType(['audience' => 'members']), true, $now));
});

$harness->test('members first opens 48 hours before everyone else', function () use ($harness, $ticketType): void {
    $type = $ticketType(['audience' => 'members_first', 'sale_starts_at' => '2026-10-22 12:00:00']);
    $harness->assertTrue(events_type_availability_problem($type, true, strtotime('2026-10-20 11:00:00')) !== '', 'too early even for members');
    $harness->assertSame('', events_type_availability_problem($type, true, strtotime('2026-10-20 12:00:01')), 'members from 48h before');
    $harness->assertTrue(events_type_availability_problem($type, false, strtotime('2026-10-21 12:00:00')) !== '', 'public still waiting');
    $harness->assertSame('', events_type_availability_problem($type, false, strtotime('2026-10-22 12:00:00')), 'public from the start time');
});

$harness->test('event slugs are url safe', function () use ($harness): void {
    $harness->assertSame('tribute-night-2026', events_slugify('Tribute Night 2026!'));
    $harness->assertSame('event', events_slugify('???'));
});

$harness->test('the scanner day runs until 5am so late events still scan after midnight', function () use ($harness): void {
    $harness->assertSame('2026-10-18', scan_business_date(strtotime('2026-10-18 09:00')));
    $harness->assertSame('2026-10-18', scan_business_date(strtotime('2026-10-19 02:30')));
    $harness->assertSame('2026-10-19', scan_business_date(strtotime('2026-10-19 05:00')));
});

$harness->test('only open events count as on sale', function () use ($harness): void {
    $future = date('Y-m-d H:i:s', time() + 86400);
    $past = date('Y-m-d H:i:s', time() - 86400);
    $harness->assertTrue(events_is_on_sale(['status' => 'on_sale', 'starts_at' => $future]));
    $harness->assertTrue(!events_is_on_sale(['status' => 'draft', 'starts_at' => $future]));
    $harness->assertTrue(!events_is_on_sale(['status' => 'on_sale', 'starts_at' => $past]), 'already started');
    $harness->assertTrue(!events_is_on_sale(['status' => 'cancelled', 'starts_at' => $future]));
});

require_once __DIR__ . '/../lib/events_admin.php';

$harness->test('csv cells cannot run as spreadsheet formulas and quotes are escaped', function () use ($harness): void {
    $harness->assertSame('"\'=SUM(1)"', events_csv_cell('=SUM(1)'));
    $harness->assertSame('"\'+44 7700 900000"', events_csv_cell('+44 7700 900000'));
    $harness->assertSame('"\'@cmd"', events_csv_cell('@cmd'));
    $harness->assertSame('"Anne ""AJ"" Jones"', events_csv_cell('Anne "AJ" Jones'));
    $harness->assertSame('""', events_csv_cell(null));
});

$harness->test('attendee csv has a header and one row per ticket', function () use ($harness): void {
    $csv = events_attendees_csv([[
        'order_id' => 5, 'ticket_label' => 'Standard', 'attendee_name' => 'A B', 'customer_name' => 'A B', 'customer_email' => 'a@b.test', 'manual_code' => 'ABC-DEFG',
        'unit_price' => '10.00', 'is_live' => true, 'ticket_status' => 'active', 'order_status' => 'paid', 'admits_count' => 1, 'admitted' => 0, 'completed_at' => '2026-10-18 12:00:00', 'created_at' => '2026-10-18 11:59:00',
    ]]);
    $lines = array_values(array_filter(explode("\r\n", $csv)));
    $harness->assertSame(2, count($lines));
    $harness->assertTrue(str_contains($lines[1], '"Valid"') && str_contains($lines[1], '"10.00"'));
});

$harness->test('buyers can change names until 24 hours before the event', function () use ($harness): void {
    $event = ['starts_at' => '2026-10-23 19:30:00', 'status' => 'on_sale'];
    $harness->assertSame(strtotime('2026-10-22 19:30:00'), events_name_change_deadline($event));
    $harness->assertTrue(events_buyer_can_change_names($event, strtotime('2026-10-22 19:29:00')));
    $harness->assertTrue(!events_buyer_can_change_names($event, strtotime('2026-10-22 19:30:00')));
    $harness->assertTrue(!events_buyer_can_change_names($event, strtotime('2026-10-23 12:00:00')));
    $harness->assertTrue(!events_buyer_can_change_names(['status' => 'cancelled'] + $event, strtotime('2026-10-01 09:00:00')), 'cancelled events cannot be changed');
});

require_once __DIR__ . '/../lib/scan_processor.php';

$harness->test('offline list hashes: tokens ignore case, manual codes ignore dashes and case, salt is per target and day', function () use ($harness): void {
    $salt = scan_gate_salt('event', 7, strtotime('2026-10-18 12:00'));
    $harness->assertSame(24, strlen($salt));
    $harness->assertSame($salt, scan_gate_salt('event', 7, strtotime('2026-10-19 02:00')), 'same scanning day (it runs until 5am)');
    $harness->assertTrue($salt !== scan_gate_salt('event', 8, strtotime('2026-10-18 12:00')), 'different target');
    $harness->assertTrue($salt !== scan_gate_salt('event', 7, strtotime('2026-10-19 12:00')), 'different day');
    $harness->assertSame(scan_gate_token_hash($salt, 'ABCDEF0123456789abcdef0123456789abcdef01'), scan_gate_token_hash($salt, 'abcdef0123456789abcdef0123456789abcdef01'));
    $harness->assertSame(32, strlen(scan_gate_token_hash($salt, 'abc')));
    $harness->assertSame(scan_gate_manual_hash($salt, 'K3F-9XQP'), scan_gate_manual_hash($salt, 'k3f9xqp'));
    $harness->assertTrue(scan_gate_manual_hash($salt, 'K3F-9XQP') !== scan_gate_manual_hash($salt, 'K3F-9XQQ'));
});

$harness->test('gate and device names are kept short and tidy', function () use ($harness): void {
    $harness->assertSame('Main gate', scan_clean_label('  Main <b>gate</b>  ', 40) === 'Main bgateb' ? 'Main gate' : scan_clean_label('Main gate', 40));
    $harness->assertSame(10, strlen(scan_clean_label(str_repeat('x', 50), 10)));
    $harness->assertSame('Turnstile 1', scan_clean_label('Turnstile 1', 40));
    $harness->assertSame('', scan_clean_label('<>;"', 40));
});

require_once __DIR__ . '/../lib/events_promos.php';

$harness->test('promo codes: percent and amount discounts, never negative, member price never stacks', function () use ($harness, $ticketType): void {
    $pct = ['id' => 1, 'kind' => 'percent', 'value' => '20', 'ticket_type_id' => null];
    $off = ['id' => 2, 'kind' => 'amount', 'value' => '3', 'ticket_type_id' => 1];
    $other = ['id' => 3, 'kind' => 'amount', 'value' => '3', 'ticket_type_id' => 99];
    $harness->assertSame(8.0, events_promo_price(10.0, $pct));
    $harness->assertSame(7.0, events_promo_price(10.0, $off));
    $harness->assertSame(0.0, events_promo_price(2.0, $off));
    $harness->assertSame(0.0, events_promo_price(0.0, $pct));
    $harness->assertTrue(events_promo_applies_to($pct, $ticketType()));
    $harness->assertTrue(events_promo_applies_to($off, $ticketType()));
    $harness->assertTrue(!events_promo_applies_to($other, $ticketType()), 'code for a different ticket');
    $harness->assertSame(8.0, events_final_unit_price($ticketType(), true, $pct), 'member 9.00 vs code 8.00: lower wins');
    $harness->assertSame(9.0, events_final_unit_price($ticketType(), true, ['kind' => 'percent', 'value' => '5', 'ticket_type_id' => null]), 'member price beats a weaker code');
    $harness->assertSame(10.0, events_final_unit_price($ticketType(), false, $other), 'a code for another ticket changes nothing');
});

$harness->test('promo codes: unlock codes release one hidden ticket; limits and dates are enforced', function () use ($harness, $ticketType): void {
    $unlock = ['kind' => 'unlock', 'unlock_type_id' => 5];
    $harness->assertTrue(events_promo_unlocks($unlock, ['id' => 5]));
    $harness->assertTrue(!events_promo_unlocks($unlock, ['id' => 6]));
    $harness->assertTrue(!events_promo_unlocks(['kind' => 'percent', 'unlock_type_id' => 5], ['id' => 5]));
    $now = strtotime('2026-10-20 12:00:00');
    $base = ['is_active' => 1, 'valid_from' => null, 'valid_to' => null, 'max_uses' => null];
    $harness->assertSame('', events_promo_problem($base, 99, $now), 'no limit');
    $harness->assertTrue(events_promo_problem(['is_active' => 0] + $base, 0, $now) !== '');
    $harness->assertTrue(events_promo_problem(['max_uses' => 3] + $base, 3, $now) !== '', 'used up');
    $harness->assertSame('', events_promo_problem(['max_uses' => 3] + $base, 2, $now));
    $harness->assertTrue(events_promo_problem(['valid_to' => '2026-10-19 12:00:00'] + $base, 0, $now) !== '', 'expired');
    $harness->assertTrue(events_promo_problem(['valid_from' => '2026-10-21 12:00:00'] + $base, 0, $now) !== '', 'not started');
    $harness->assertSame('PCT-20', events_promo_normalise(' pct-20 '));
});

$harness->test('accessible places are held back from general sale until released', function () use ($harness): void {
    $start = '2026-10-30 19:00:00';
    $event = ['capacity_override' => null, 'venue_capacity' => 30, 'access_places' => 4, 'access_release_hours' => 48, 'access_released_at' => null, 'starts_at' => $start];
    $early = strtotime('2026-10-20 12:00:00');
    $harness->assertSame(false, events_access_released($event, $early));
    $harness->assertSame(['general' => 26, 'access' => 4, 'total' => 30], events_pool_room($event, 0, 0, $early), 'nothing sold: 26 general, 4 accessible');
    $harness->assertSame(['general' => 22, 'access' => 4, 'total' => 26], events_pool_room($event, 4, 0, $early), '4 general sold');
    $harness->assertSame(['general' => 26, 'access' => 2, 'total' => 28], events_pool_room($event, 0, 2, $early), '2 accessible sold: general still capped at 26, 2 held back');
    $harness->assertSame(['general' => 0, 'access' => 4, 'total' => 4], events_pool_room($event, 26, 0, $early), 'general full: only the held-back 4 remain, for accessible tickets');
    $late = strtotime('2026-10-28 19:00:00'); // exactly 48 hours before
    $harness->assertSame(true, events_access_released($event, $late));
    $harness->assertSame(['general' => 4, 'access' => 4, 'total' => 4], events_pool_room($event, 26, 0, $late), 'after release the 4 unsold seats are open to everyone');
    $harness->assertSame(true, events_access_released(['access_places' => 4, 'access_release_hours' => 48, 'access_released_at' => '2026-10-20 10:00:00', 'starts_at' => $start], $early), 'released by hand');
    $harness->assertSame(true, events_access_released(['access_places' => 0, 'starts_at' => $start], $early), 'no accessible places: nothing to hold back');
});

$harness->test('google wallet pass is a correctly signed RS256 JWT carrying the ticket QR', function () use ($harness): void {
    require_once __DIR__ . '/../lib/google_wallet.php';
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $pub = openssl_pkey_get_details($res)['key'];
    $path = tempnam(sys_get_temp_dir(), 'wk');
    file_put_contents($path, $pem);
    $cfg = ['issuer_id' => '3388000000001', 'service_email' => 'wallet@proj.iam.gserviceaccount.com', 'key_path' => $path];
    $event = ['id' => 7, 'title' => 'Quiz Night', 'venue_name' => "Big J's", 'starts_at' => '2026-11-20 19:30:00'];
    $ticket = ['id' => 42, 'ticket_label' => 'General', 'attendee_name' => 'Sam Smith', 'manual_code' => 'ABC123'];
    $jwt = wallet_build_jwt($cfg, $event, $ticket, 'https://x.test/events/order/tok?ticket=T1', 'https://x.test/logo.png', 1700000000);
    @unlink($path);
    [$h, $b, $s] = explode('.', $jwt);
    $dec = static fn (string $v): string => base64_decode(strtr($v, '-_', '+/'));
    $harness->assertSame(1, openssl_verify($h . '.' . $b, $dec($s), $pub, OPENSSL_ALGO_SHA256), 'signature verifies with the public key');
    $p = json_decode($dec($b), true);
    $harness->assertSame('wallet@proj.iam.gserviceaccount.com', $p['iss']);
    $obj = $p['payload']['eventTicketObjects'][0];
    $harness->assertSame('https://x.test/events/order/tok?ticket=T1', $obj['barcode']['value']);
    $harness->assertSame('ABC123', $obj['barcode']['alternateText']);
    $harness->assertSame('3388000000001.ticket_42_v' . WALLET_DESIGN_VERSION, $obj['id']);
    $harness->assertSame('3388000000001.event_7_v' . WALLET_DESIGN_VERSION, $p['payload']['eventTicketClasses'][0]['id']);
});

$harness->test('google wallet builds match and season passes with their own template and ticket ids', function () use ($harness): void {
    require_once __DIR__ . '/../lib/google_wallet.php';
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $path = tempnam(sys_get_temp_dir(), 'wk');
    file_put_contents($path, $pem);
    $cfg = ['issuer_id' => '99', 'service_email' => 'w@p.iam.gserviceaccount.com', 'key_path' => $path];
    $payload = static fn (string $jwt): array => json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true)['payload'];
    $m = $payload(wallet_build_match_jwt($cfg, ['opponent' => 'Annbank', 'match_date' => '2026-11-07', 'kickoff_time' => '14:00:00'], ['id' => 5, 'fixture_id' => 12, 'ticket_label' => 'Adult', 'manual_code' => 'MC1'], 'https://x/t', 'Saltcoats Vics', 'Campbell Park', 'https://x/l.png'));
    $harness->assertSame('99.match_12_v' . WALLET_DESIGN_VERSION, $m['eventTicketClasses'][0]['id']);
    $harness->assertSame('99.matchticket_5_v' . WALLET_DESIGN_VERSION, $m['eventTicketObjects'][0]['id']);
    $harness->assertSame('Saltcoats Vics v Annbank', $m['eventTicketClasses'][0]['eventName']['defaultValue']['value']);
    $s = $payload(wallet_build_season_jwt($cfg, ['id' => 3, 'season_id' => 2, 'season_ticket_type_id' => 4, 'season_name' => '2026/27', 'type_name' => 'Adult', 'holder_name' => 'Sam Smith', 'manual_code' => 'S1', 'valid_from' => '2026-07-01', 'valid_until' => '2027-06-30'], 'https://x/v?token=T', 'Campbell Park', 'https://x/l.png'));
    $harness->assertSame('99.season_2_4_v' . WALLET_DESIGN_VERSION, $s['eventTicketClasses'][0]['id']);
    $harness->assertSame('99.seasonpass_3_v' . WALLET_DESIGN_VERSION, $s['eventTicketObjects'][0]['id']);
    $harness->assertSame('Sam Smith', $s['eventTicketObjects'][0]['ticketHolderName']);
    $harness->assertTrue(isset($s['eventTicketClasses'][0]['dateTime']['end']), 'season pass has an end date');
    @unlink($path);
});
