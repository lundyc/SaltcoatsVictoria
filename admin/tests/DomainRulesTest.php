<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../lib/season_tickets.php';
require_once __DIR__ . '/../lib/season_passes.php';
require_once __DIR__ . '/../lib/match_tickets.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/matchday_staffing.php';
require_once __DIR__ . '/../lib/player_availability.php';
require_once __DIR__ . '/../lib/pos_reconciliation.php';
require_once __DIR__ . '/../lib/pos_locations.php';
require_once __DIR__ . '/../lib/pos_trading_days.php';
require_once __DIR__ . '/../lib/committee_actions.php';
require_once __DIR__ . '/../lib/facility_maintenance.php';
require_once __DIR__ . '/../lib/sponsor_followups.php';
require_once __DIR__ . '/../lib/club_reminders.php';

final class TestOnlyPdo extends PDO
{
    public function __construct()
    {
    }
}

$harness->test('season pass legacy status mapping protects cancelled orders', function () use ($harness): void {
    $harness->assertSame('cancelled', seasonPassOrderStatusFromLegacy(['status' => 'cancelled', 'paid' => 1]));
    $harness->assertSame('cancelled', seasonPassEntitlementStatusFromLegacy(['status' => 'cancelled', 'paid' => 1]));
});

$harness->test('season pass legacy status mapping activates only paid complete or posted orders', function () use ($harness): void {
    $harness->assertSame('paid', seasonPassOrderStatusFromLegacy(['status' => 'complete', 'paid' => 1]));
    $harness->assertSame('pending_payment', seasonPassOrderStatusFromLegacy(['status' => 'complete', 'paid' => 0]));
    $harness->assertSame('active', seasonPassEntitlementStatusFromLegacy(['status' => 'complete', 'paid' => 1]));
    $harness->assertSame('active', seasonPassEntitlementStatusFromLegacy(['status' => 'posted', 'paid' => 1]));
    $harness->assertSame('pending', seasonPassEntitlementStatusFromLegacy(['status' => 'pending_payment', 'paid' => 1]));
});

$harness->test('season pass payment provider mapping keeps manual and internal payments distinct', function () use ($harness): void {
    $harness->assertSame('stripe', seasonPassProviderForMethod('stripe'));
    $harness->assertSame('stripe', seasonPassProviderForMethod('jotform'));
    $harness->assertSame('internal', seasonPassProviderForMethod('free_code'));
    $harness->assertSame('manual', seasonPassProviderForMethod('cash'));
    $harness->assertSame('manual', seasonPassProviderForMethod('bank_transfer'));
    $harness->assertSame('manual', seasonPassProviderForMethod('unknown'));
});

$harness->test('season pass payment status prioritises cancellation over paid flag', function () use ($harness): void {
    $harness->assertSame('cancelled', seasonPassPaymentStatusFromOrderStatus('cancelled', true));
    $harness->assertSame('paid', seasonPassPaymentStatusFromOrderStatus('paid', true));
    $harness->assertSame('pending', seasonPassPaymentStatusFromOrderStatus('pending_payment', false));
});

$harness->test('match ticket legacy status mapping protects refunded and cancelled orders', function () use ($harness): void {
    $harness->assertSame('cancelled', matchTicketOrderStatusFromLegacy(['status' => 'cancelled', 'paid' => 1]));
    $harness->assertSame('refunded', matchTicketOrderStatusFromLegacy(['status' => 'refunded', 'paid' => 1]));
    $harness->assertSame('cancelled', matchTicketEntitlementStatusFromLegacy(['status' => 'cancelled', 'paid' => 1]));
    $harness->assertSame('refunded', matchTicketEntitlementStatusFromLegacy(['status' => 'refunded', 'paid' => 1]));
});

$harness->test('match ticket legacy status mapping activates only paid complete orders', function () use ($harness): void {
    $harness->assertSame('paid', matchTicketOrderStatusFromLegacy(['status' => 'complete', 'paid' => 1]));
    $harness->assertSame('pending_payment', matchTicketOrderStatusFromLegacy(['status' => 'complete', 'paid' => 0]));
    $harness->assertSame('active', matchTicketEntitlementStatusFromLegacy(['status' => 'complete', 'paid' => 1]));
    $harness->assertSame('pending', matchTicketEntitlementStatusFromLegacy(['status' => 'pending_payment', 'paid' => 1]));
});

$harness->test('admission scan input parsing accepts ticket urls and raw tokens only', function () use ($harness): void {
    $token = str_repeat('a', 40);
    $harness->assertSame($token, admissionsCredentialToken('https://example.test/verify_ticket.php?ticket=' . $token));
    $harness->assertSame($token, admissionsCredentialToken('https://example.test/verify_ticket.php?token=' . $token));
    $harness->assertSame($token, admissionsCredentialToken('ticket=' . $token));
    $harness->assertSame($token, admissionsCredentialToken($token));
    $harness->assertSame('', admissionsCredentialToken('not-a-ticket'));
});

$harness->test('admission manual code parsing normalizes operator input', function () use ($harness): void {
    $harness->assertSame('AB123CD', admissionsNormalizeManualCode(' ab-123 cd '));
    $harness->assertSame('WEVICS9', admissionsNormalizeManualCode('We Vics #9'));
});

$harness->test('permission catalogue contains critical operational permissions', function () use ($harness): void {
    $permissions = defaultPermissionCatalogue();
    foreach ([
        'tickets.view',
        'tickets.manage',
        'tickets.scan',
        'tickets.refund',
        'tickets.comp',
        'season_passes.manage',
        'pos.use',
        'pos.manage',
        'finance.view',
        'finance.refund',
        'reports.view',
        'social.manage',
    ] as $permission) {
        $harness->assertTrue(isset($permissions[$permission]), "Missing permission {$permission}.");
    }
});

$harness->test('marketing contact display prefers people identity over legacy holder', function () use ($harness): void {
    $harness->assertSame('Alex Person', marketingContactDisplayName([
        'person' => ['display_name' => ' Alex Person '],
        'holder' => ['name' => 'Legacy Holder'],
    ]));
    $harness->assertSame('Legacy Holder', marketingContactDisplayName([
        'person' => null,
        'holder' => ['name' => ' Legacy Holder '],
    ]));
});

$harness->test('marketing contact unsubscribe status uses people row when available', function () use ($harness): void {
    $harness->assertTrue(marketingContactIsUnsubscribed([
        'person' => ['marketing_opt_in' => 0, 'unsubscribed_at' => '2026-08-25 10:00:00'],
        'holder' => ['marketing_opt_in' => 1, 'unsubscribed_at' => null],
    ]));
    $harness->assertSame(false, marketingContactIsUnsubscribed([
        'person' => ['marketing_opt_in' => 0, 'unsubscribed_at' => null],
        'holder' => ['marketing_opt_in' => 0, 'unsubscribed_at' => '2026-08-25 10:00:00'],
    ]));
    $harness->assertTrue(marketingContactIsUnsubscribed([
        'person' => null,
        'holder' => ['marketing_opt_in' => 0, 'unsubscribed_at' => '2026-08-25 10:00:00'],
    ]));
});

$harness->test('matchday staffing has expected operational role catalogue', function () use ($harness): void {
    $roles = matchday_staffing_roles();
    foreach (['gate', 'turnstile', 'pos', 'bar', 'kitchen', 'steward', 'media', 'secretary'] as $role) {
        $harness->assertTrue(isset($roles[$role]), "Missing matchday role {$role}.");
    }
    $harness->assertSame('Gate / Admissions', matchday_staffing_role_label('gate'));
    $harness->assertSame('Custom Role', matchday_staffing_role_label('custom', 'Custom Role'));
});

$harness->test('matchday staffing summary counts active confirmed and checked-in roles', function () use ($harness): void {
    $summary = matchday_staffing_summary([
        ['status' => 'planned'],
        ['status' => 'confirmed'],
        ['status' => 'checked_in'],
        ['status' => 'checked_out'],
        ['status' => 'cancelled'],
    ]);
    $harness->assertSame(5, $summary['total']);
    $harness->assertSame(4, $summary['active']);
    $harness->assertSame(3, $summary['confirmed']);
    $harness->assertSame(1, $summary['checked_in']);
});

$harness->test('player availability catalogue identifies blocked selection states', function () use ($harness): void {
    $statuses = player_availability_statuses();
    foreach (['unknown', 'available', 'doubtful', 'unavailable', 'injured', 'suspended'] as $status) {
        $harness->assertTrue(isset($statuses[$status]), "Missing availability status {$status}.");
    }
    $harness->assertSame(false, player_availability_blocks_selection('available'));
    $harness->assertSame(false, player_availability_blocks_selection('doubtful'));
    $harness->assertTrue(player_availability_blocks_selection('unavailable'));
    $harness->assertTrue(player_availability_blocks_selection('injured'));
    $harness->assertTrue(player_availability_blocks_selection('suspended'));
});

$harness->test('player availability merge and summary expose fixture squad state', function () use ($harness): void {
    $players = player_availability_merge_players([
        ['id' => 1, 'name' => 'Available Player'],
        ['id' => 2, 'name' => 'Doubtful Player'],
        ['id' => 3, 'name' => 'Injured Player'],
        ['id' => 4, 'name' => 'Unknown Player'],
    ], [
        1 => ['status' => 'available'],
        2 => ['status' => 'doubtful', 'reason' => 'Work'],
        3 => ['status' => 'injured', 'notes' => 'Hamstring'],
    ]);

    $summary = player_availability_summary($players);
    $harness->assertSame(4, $summary['total']);
    $harness->assertSame(1, $summary['available']);
    $harness->assertSame(1, $summary['doubtful']);
    $harness->assertSame(1, $summary['blocked']);
    $harness->assertSame(1, $summary['unknown']);
    $harness->assertTrue((bool) $players[2]['availability_blocks_selection']);
});

$harness->test('pos reconciliation calculates cash-up variance states', function () use ($harness): void {
    $harness->assertSame(0.0, pos_reconciliation_variance(120.00, 120.00));
    $harness->assertSame('balanced', pos_reconciliation_variance_state(0.0));
    $harness->assertSame(2.5, pos_reconciliation_variance(120.00, 122.50));
    $harness->assertSame('over', pos_reconciliation_variance_state(2.5));
    $harness->assertSame(-5.0, pos_reconciliation_variance(120.00, 115.00));
    $harness->assertSame('short', pos_reconciliation_variance_state(-5.0));
});

$harness->test('pos reconciliation expected amount reads selected payment totals', function () use ($harness): void {
    $totals = [
        'cash' => ['amount' => 84.25],
        'card_stripe_app' => ['amount' => 42.10],
    ];
    $harness->assertSame(84.25, pos_reconciliation_expected_amount($totals, 'cash'));
    $harness->assertSame(0.0, pos_reconciliation_expected_amount($totals, 'voucher'));
    $harness->assertSame('Card - Stripe app', pos_reconciliation_payment_label('card_stripe_app'));
});

$harness->test('pos location codes normalize manager input for urls and uniqueness', function () use ($harness): void {
    $harness->assertSame('main_bar', pos_location_normalize_code(' Main Bar '));
    $harness->assertSame('kitchen_2', pos_location_normalize_code('Kitchen #2'));
    $harness->assertSame('location', pos_location_normalize_code(' !!! '));
});

$harness->test('pos trading day business date accepts valid dates only', function () use ($harness): void {
    $harness->assertSame('2026-08-25', pos_trading_day_business_date('2026-08-25'));
    $harness->assertSame(date('Y-m-d'), pos_trading_day_business_date('25/08/2026'));
});

$harness->test('committee meeting action parser extracts explicit actions only', function () use ($harness): void {
    $actions = committee_meeting_extract_actions(implode("\n", [
        'Treasurer reported the balance.',
        'ACTION: Submit licence renewal (Owner: Secretary) (Due: 2026-09-01)',
        '- TODO - Check pitch hire invoice Owner: Treasurer Due: 05/09/2026',
        'ACTION: Urgent safeguarding form update Owner: Welfare Due: 2026-08-28',
    ]));

    $harness->assertSame(3, count($actions));
    $harness->assertSame('Submit licence renewal', $actions[0]['title']);
    $harness->assertSame('Secretary', $actions[0]['owner']);
    $harness->assertSame('2026-09-01', $actions[0]['due_at']);
    $harness->assertSame('Check pitch hire invoice', $actions[1]['title']);
    $harness->assertSame('Treasurer', $actions[1]['owner']);
    $harness->assertSame('2026-09-05', $actions[1]['due_at']);
    $harness->assertSame('urgent', $actions[2]['priority']);
});

$harness->test('committee meeting action keys normalize duplicate task titles', function () use ($harness): void {
    $harness->assertSame(
        committee_meeting_action_key('Submit licence renewal'),
        committee_meeting_action_key('  Submit   licence renewal  ')
    );
});

$harness->test('facility maintenance summary counts open overdue urgent and done jobs', function () use ($harness): void {
    $summary = facility_maintenance_summary([
        ['status' => 'open', 'priority' => 'urgent', 'due_at' => '2026-08-20'],
        ['status' => 'planned', 'priority' => 'normal', 'due_at' => '2026-08-30'],
        ['status' => 'done', 'priority' => 'urgent', 'due_at' => '2026-08-18'],
        ['status' => 'cancelled', 'priority' => 'urgent', 'due_at' => '2026-08-18'],
    ], '2026-08-25');

    $harness->assertSame(4, $summary['total']);
    $harness->assertSame(2, $summary['open']);
    $harness->assertSame(1, $summary['overdue']);
    $harness->assertSame(1, $summary['urgent']);
    $harness->assertSame(1, $summary['done']);
});

$harness->test('facility maintenance tones and status openness are stable', function () use ($harness): void {
    $harness->assertSame('danger', facility_maintenance_priority_tone('urgent'));
    $harness->assertSame('secondary', facility_maintenance_priority_tone('low'));
    $harness->assertSame('success', facility_maintenance_status_tone('done'));
    $harness->assertSame('secondary', facility_maintenance_status_tone('cancelled'));
    $harness->assertTrue(facility_maintenance_is_open_status('in_progress'));
    $harness->assertSame(false, facility_maintenance_is_open_status('cancelled'));
    $harness->assertTrue(facility_maintenance_is_overdue(['status' => 'open', 'due_at' => '2026-08-24'], '2026-08-25'));
});

$harness->test('sponsor follow-up summary tracks open pipeline and closed value', function () use ($harness): void {
    $summary = sponsor_followup_summary([
        ['stage' => 'lead', 'priority' => 'urgent', 'next_contact_at' => '2026-08-20', 'expected_value' => 250],
        ['stage' => 'proposal_sent', 'priority' => 'normal', 'next_contact_at' => '2026-08-30', 'expected_value' => 500],
        ['stage' => 'won', 'priority' => 'urgent', 'next_contact_at' => '2026-08-18', 'expected_value' => 750],
        ['stage' => 'lost', 'priority' => 'urgent', 'next_contact_at' => '2026-08-18', 'expected_value' => 100],
    ], '2026-08-25');

    $harness->assertSame(4, $summary['total']);
    $harness->assertSame(2, $summary['open']);
    $harness->assertSame(1, $summary['overdue']);
    $harness->assertSame(1, $summary['urgent']);
    $harness->assertSame(750.0, $summary['pipeline_value']);
    $harness->assertSame(750.0, $summary['won_value']);
});

$harness->test('sponsor follow-up stage and priority presentation rules are stable', function () use ($harness): void {
    $harness->assertTrue(sponsor_followup_is_open_stage('negotiating'));
    $harness->assertSame(false, sponsor_followup_is_open_stage('won'));
    $harness->assertTrue(sponsor_followup_is_overdue(['stage' => 'contacted', 'next_contact_at' => '2026-08-24'], '2026-08-25'));
    $harness->assertSame(false, sponsor_followup_is_overdue(['stage' => 'lost', 'next_contact_at' => '2026-08-24'], '2026-08-25'));
    $harness->assertSame('success', sponsor_followup_stage_tone('won'));
    $harness->assertSame('danger', sponsor_followup_priority_tone('urgent'));
});

$harness->test('club reminders summarize and sort by severity and due date', function () use ($harness): void {
    $reminders = club_reminders_sort([
        ['title' => 'Later warning', 'severity' => 'warning', 'due_at' => '2026-08-30'],
        ['title' => 'Info item', 'severity' => 'info', 'due_at' => '2026-08-20'],
        ['title' => 'Urgent item', 'severity' => 'urgent', 'due_at' => '2026-08-25'],
    ]);
    $summary = club_reminder_summary($reminders);

    $harness->assertSame('Urgent item', $reminders[0]['title']);
    $harness->assertSame(3, $summary['total']);
    $harness->assertSame(1, $summary['urgent']);
    $harness->assertSame(1, $summary['warning']);
    $harness->assertSame(1, $summary['info']);
    $harness->assertSame('danger', club_reminder_tone('urgent'));
});

$harness->test('legacy season ticket mutation helpers remain retired', function () use ($harness): void {
    $pdo = new TestOnlyPdo();
    $harness->assertThrows(RuntimeException::class, static function () use ($pdo): void {
        saveSeasonTicketOrder($pdo, null, []);
    }, 'Legacy season_ticket_orders writes are retired');
    $harness->assertThrows(RuntimeException::class, static function () use ($pdo): void {
        deleteSeasonTicketOrder($pdo, 1);
    }, 'Legacy season_ticket_orders deletes are retired');
    $harness->assertThrows(RuntimeException::class, static function () use ($pdo): void {
        cancelSeasonTicketOrder($pdo, 1, 'test', null);
    }, 'Legacy season_ticket_orders cancellations are retired');
    $harness->assertThrows(RuntimeException::class, static function () use ($pdo): void {
        attachSeasonTicketOrderStripeSession($pdo, 1, 'cs_test');
    }, 'Legacy season_ticket_orders Stripe sessions are retired');
    $harness->assertThrows(RuntimeException::class, static function () use ($pdo): void {
        markSeasonTicketOrderPaid($pdo, 1);
    }, 'Legacy season_ticket_orders payment updates are retired');
});
