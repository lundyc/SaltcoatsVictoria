<?php
declare(strict_types=1);

/** Payout reconciliation rules that need no database or Stripe: reading a Stripe transaction and deciding what it was. */

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../lib/payout_reconciliation.php';

$stripeCharge = static fn (array $meta, array $over = []): array => $over + [
    'id' => 'txn_1', 'type' => 'payment', 'reporting_category' => 'charge', 'created' => 1790000000, 'amount' => 1036, 'fee' => 35, 'net' => 1001, 'description' => 'x',
    'source' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'metadata' => $meta, 'billing_details' => ['name' => 'A Buyer', 'email' => 'a@b.test']],
];

$harness->test('a stripe payment is flattened to what the report needs', function () use ($harness, $stripeCharge): void {
    $t = payout_normalise_txn($stripeCharge(['kind' => 'event_ticket', 'order_id' => '55']));
    $harness->assertSame(1036, $t['gross']);
    $harness->assertSame(35, $t['fee']);
    $harness->assertSame(1001, $t['net']);
    $harness->assertSame('A Buyer', $t['name']);
    $harness->assertSame('a@b.test', $t['email']);
    $harness->assertSame('pi_1', $t['pi']);
    $harness->assertSame(false, $t['is_refund']);
});

$harness->test('a refund is recognised and takes its details from the original charge when expanded', function () use ($harness): void {
    $t = payout_normalise_txn(['id' => 'txn_r', 'type' => 'payment_refund', 'reporting_category' => 'refund', 'created' => 1, 'amount' => -1000, 'fee' => 0, 'net' => -1000,
        'source' => ['id' => 're_1', 'object' => 'refund', 'charge' => ['id' => 'ch_9', 'payment_intent' => 'pi_9', 'metadata' => ['kind' => 'shop', 'order_id' => '3'], 'billing_details' => ['name' => 'R Person', 'email' => 'r@p.test']], 'metadata' => []]]);
    $harness->assertSame(true, $t['is_refund']);
    $harness->assertSame('ch_9', $t['charge_id']);
    $harness->assertSame('shop', $t['meta']['kind']);
    $harness->assertSame('R Person', $t['name']);
});

$harness->test('payments are sorted into the right category with their order numbers', function () use ($harness, $stripeCharge): void {
    $cat = static fn (array $meta, array $over = []) => payout_categorise(payout_normalise_txn($stripeCharge($meta, $over)));
    $harness->assertSame(['event_ticket', [55]], $cat(['kind' => 'event_ticket', 'order_id' => '55']));
    $harness->assertSame(['match_ticket', [12]], $cat(['kind' => 'match_ticket', 'order_id' => '12', 'shared_order_id' => '12']));
    $harness->assertSame(['season_ticket', [39, 40]], $cat(['kind' => 'season_ticket', 'order_ids' => '39,40', 'commerce_order_ids' => '39,40']));
    $harness->assertSame(['shop', [3]], $cat(['kind' => 'shop', 'order_id' => '3', 'order_ref' => 'SVFC-0001']));
    $harness->assertSame(['sponsorship_link', [2230]], $cat(['agreement_id' => '2230']));
    $harness->assertSame(['hidden_team', []], $cat(['kind' => 'hidden_team', 'team_ids' => '1,2']));
    $harness->assertSame(['unknown', []], $cat([]), 'nothing the Hub recognises');
    $harness->assertSame(['unknown', []], $cat(['kind' => 'something_new']));
    $harness->assertSame(['stripe_fee', []], $cat([], ['type' => 'stripe_fee', 'reporting_category' => 'fee']));
    $harness->assertSame(['adjustment', []], $cat([], ['type' => 'adjustment', 'reporting_category' => 'dispute']));
});

$harness->test('the payout csv neutralises spreadsheet formulas', function () use ($harness): void {
    $csv = payout_report_csv(['payouts' => [['id' => 'po_1', 'arrival' => 1790000000, 'amount' => 1000, 'note' => '', 'lines' => [[
        'created' => 1790000000, 'type' => 'payment', 'is_refund' => false, 'name' => '=HYPERLINK("x")', 'email' => '', 'gross' => 1000, 'fee' => 25, 'net' => 975,
        'hub' => ['label' => 'Club shop', 'ref' => 'Shop order 1', 'amount_ok' => true, 'note' => ''],
    ]]]]]);
    $harness->assertTrue(str_contains($csv, '"\'=HYPERLINK(""x"")"'), 'formula escaped');
    $harness->assertTrue(!str_contains($csv, ',"=HYPERLINK'));
});

$harness->test('a payout that is out by exactly a manual payout is explained by it', function () use ($harness): void {
    $harness->assertSame([4], payout_subset_sum([2 => 500, 4 => 1950], 1950));
    $harness->assertSame([2, 4], payout_subset_sum([2 => 500, 4 => 1950, 7 => 99999], 2450));
    $harness->assertSame(null, payout_subset_sum([2 => 500, 4 => 1950], 123));
    $harness->assertSame(null, payout_subset_sum([], 100));
});
