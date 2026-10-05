<?php

declare(strict_types=1);

require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/match_sponsorship.php';
require_once __DIR__ . '/stripe.php';

const MEMBER_SPONSORSHIP_PLAYER_CODES = ['player_home', 'player_away', 'player_third'];
const MEMBER_SPONSORSHIP_MATCH_CODES = ['match_day', 'match_ball'];
const MEMBER_SPONSORSHIP_BOARD_CODES = ['pitchside_board_small', 'pitchside_board_medium', 'pitchside_board_large'];

function ensureMemberSponsorshipPackages(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ensureSponsorshipCatalogSchema($pdo);
    $packages = [
        ['Small Pitchside Board', 'pitchside_board_small', 300.00, 'Small 4x4 pitchside advertising board at Campbell Park.', 330],
        ['Medium Pitchside Board', 'pitchside_board_medium', 400.00, 'Medium 7x3.5 pitchside advertising board at Campbell Park.', 340],
        ['Large Pitchside Board', 'pitchside_board_large', 500.00, 'Large 8x4 pitchside advertising board at Campbell Park.', 350],
    ];
    $stmt = $pdo->prepare("INSERT INTO packages
        (name, code, scope, category, amount, duration_type, max_slots, graphic_enabled, graphic_placement, default_logo_variant, is_active, sort_order, description)
        VALUES (:name, :code, 'club', 'Ground Advertising', :amount, 'season', NULL, 0, :placement, 'colour', 1, :sort_order, :description)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            scope = VALUES(scope),
            category = VALUES(category),
            amount = VALUES(amount),
            duration_type = VALUES(duration_type),
            is_active = 1,
            sort_order = VALUES(sort_order),
            description = VALUES(description)");
    foreach ($packages as $package) {
        $stmt->execute([
            ':name' => $package[0],
            ':code' => $package[1],
            ':amount' => $package[2],
            ':placement' => $package[1],
            ':sort_order' => $package[4],
            ':description' => $package[3],
        ]);
    }
    $done = true;
}

/**
 * Player packages open for self-serve sponsorship, each with its currently
 * open (not already sponsored) players for the given season.
 *
 * @return list<array{package: array<string, mixed>, open_players: list<array<string, mixed>>}>
 */
function member_sponsorship_player_options(PDO $pdo, int $seasonId): array
{
    ensureMemberSponsorshipPackages($pdo);
    $players = $pdo->query('SELECT id, name FROM players WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach (MEMBER_SPONSORSHIP_PLAYER_CODES as $code) {
        $package = getSponsorshipPackageByCode($pdo, $code);
        if (!$package || (int) $package['is_active'] !== 1) {
            continue;
        }

        $taken = [];
        $stmt = $pdo->prepare("SELECT player_id FROM sponsorship_agreements WHERE package_id = :package AND season_id = :season AND status IN ('active','scheduled')");
        $stmt->execute([':package' => $package['id'], ':season' => $seasonId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $playerId) {
            $taken[(int) $playerId] = true;
        }

        $openPlayers = array_values(array_filter($players, static fn(array $p): bool => !isset($taken[(int) $p['id']])));
        $out[] = ['package' => $package, 'open_players' => $openPlayers];
    }

    return $out;
}

/**
 * Match packages open for self-serve sponsorship, each with its currently
 * open upcoming fixtures for the given season.
 *
 * @return list<array{package: array<string, mixed>, open_fixtures: list<array<string, mixed>>}>
 */
function member_sponsorship_match_options(PDO $pdo, int $seasonId): array
{
    ensureMemberSponsorshipPackages($pdo);
    $fixtures = $pdo->prepare("SELECT id, opponent, match_date FROM match_fixtures WHERE season_id = :season AND match_date >= CURDATE() ORDER BY match_date");
    $fixtures->execute([':season' => $seasonId]);
    $fixtures = $fixtures->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach (MEMBER_SPONSORSHIP_MATCH_CODES as $code) {
        $package = getSponsorshipPackageByCode($pdo, $code);
        if (!$package || (int) $package['is_active'] !== 1) {
            continue;
        }

        $taken = [];
        $stmt = $pdo->prepare("SELECT fixture_id FROM sponsorship_agreements WHERE package_id = :package AND status IN ('active','scheduled')");
        $stmt->execute([':package' => $package['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fixtureId) {
            $taken[(int) $fixtureId] = true;
        }

        $openFixtures = array_values(array_filter($fixtures, static fn(array $f): bool => !isset($taken[(int) $f['id']])));
        $out[] = ['package' => $package, 'open_fixtures' => $openFixtures];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function member_sponsorship_board_options(PDO $pdo): array
{
    ensureMemberSponsorshipPackages($pdo);
    $out = [];
    foreach (MEMBER_SPONSORSHIP_BOARD_CODES as $code) {
        $package = getSponsorshipPackageByCode($pdo, $code);
        if ($package && (int) $package['is_active'] === 1) {
            $out[] = $package;
        }
    }
    return $out;
}

/**
 * Find-or-create the sponsors row for a season ticket holder wanting to
 * sponsor something for the first time, and link it back onto their holder
 * record — this is how "a season ticket holder can also be a sponsor"
 * actually gets established in practice.
 */
function member_sponsorship_ensure_sponsor(PDO $pdo, array $holder): int
{
    if (!empty($holder['sponsor_id'])) {
        return (int) $holder['sponsor_id'];
    }

    $stmt = $pdo->prepare('INSERT INTO sponsors (name, contact_email, contact_phone, is_active) VALUES (:name, :email, :phone, 1)');
    $stmt->execute([
        ':name' => (string) $holder['name'],
        ':email' => (string) ($holder['email'] ?? ''),
        ':phone' => (string) ($holder['phone'] ?? ''),
    ]);
    $sponsorId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE season_ticket_holders SET sponsor_id = :sponsor_id WHERE id = :id')
        ->execute([':sponsor_id' => $sponsorId, ':id' => $holder['id']]);

    return $sponsorId;
}

/**
 * Throws if the slot is already claimed. Deliberately a plain read, not a
 * lock — see member_sponsorship_purchase() for why the real, authoritative
 * check happens again at the point the slot is actually claimed.
 */
function member_sponsorship_assert_slot_open(PDO $pdo, array $package, ?int $playerId, ?int $fixtureId, int $seasonId): void
{
    if ($playerId) {
        $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE package_id = :package AND player_id = :player AND season_id = :season AND status IN ('active','scheduled')");
        $slotCheck->execute([':package' => $package['id'], ':player' => $playerId, ':season' => $seasonId]);
        if ((int) $slotCheck->fetchColumn() > 0) {
            throw new RuntimeException('Sorry, that player has just been sponsored by someone else. Please pick another.');
        }
    } elseif ($fixtureId) {
        $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE package_id = :package AND fixture_id = :fixture AND status IN ('active','scheduled')");
        $slotCheck->execute([':package' => $package['id'], ':fixture' => $fixtureId]);
        if ((int) $slotCheck->fetchColumn() > 0) {
            throw new RuntimeException('Sorry, that fixture has just been sponsored by someone else. Please pick another.');
        }
    }
}

/**
 * Actually claims the slot: creates the agreement and syncs it to the
 * legacy tables. Caller is responsible for the transaction and for having
 * just re-checked availability inside it.
 */
function member_sponsorship_create_agreement(PDO $pdo, int $sponsorId, array $package, ?int $playerId, ?int $fixtureId, int $seasonId, string $source): int
{
    $stmt = $pdo->prepare('INSERT INTO sponsorship_agreements
        (sponsor_id, package_id, season_id, fixture_id, player_id, agreed_amount, status, notes)
        VALUES (:sponsor, :package, :season, :fixture, :player, :amount, :status, :notes)');
    $stmt->execute([
        ':sponsor' => $sponsorId,
        ':package' => $package['id'],
        ':season' => $seasonId,
        ':fixture' => $fixtureId,
        ':player' => $playerId,
        ':amount' => (float) $package['amount'],
        ':status' => 'active',
        ':notes' => $source, // e.g. 'self_serve:cash' or 'self_serve:stripe' — admin-visible provenance, not used for any automated cleanup.
    ]);
    $agreementId = (int) $pdo->lastInsertId();
    applySponsorshipAgreementToLegacy($pdo, $agreementId);

    return $agreementId;
}

/**
 * Start a member's self-serve sponsorship. For cash/bank transfer (or a
 * complimentary/free package) the slot is claimed immediately, same as an
 * admin creating an agreement by hand — an admin is expected to follow up
 * and either confirm the payment or cancel it.
 *
 * For card payment, nothing is created here at all — no agreement, no
 * "reservation". A member cancelling or abandoning Stripe Checkout must
 * never show up as sponsoring anything, and must never block the slot for
 * anyone else. The agreement is only created once Stripe actually confirms
 * payment, in member_sponsorship_stripe_handle_checkout_completed() below.
 *
 * @param string $paymentMethod 'online' (Stripe), 'cash', or 'bank_transfer'
 * @return array{url: string}
 */
function member_sponsorship_purchase(PDO $pdo, array $holder, string $packageCode, ?int $playerId, ?int $fixtureId, int $seasonId, string $successUrl, string $cancelUrl, string $paymentMethod = 'online'): array
{
    if (!in_array($paymentMethod, ['online', 'cash', 'bank_transfer'], true)) {
        $paymentMethod = 'online';
    }
    ensureMemberSponsorshipPackages($pdo);
    $package = getSponsorshipPackageByCode($pdo, $packageCode);
    if (!$package || (int) $package['is_active'] !== 1) {
        throw new RuntimeException('That sponsorship option is not available.');
    }

    $isPlayerPackage = in_array($packageCode, MEMBER_SPONSORSHIP_PLAYER_CODES, true);
    $isMatchPackage = in_array($packageCode, MEMBER_SPONSORSHIP_MATCH_CODES, true);
    $isBoardPackage = in_array($packageCode, MEMBER_SPONSORSHIP_BOARD_CODES, true);
    if (!$isPlayerPackage && !$isMatchPackage && !$isBoardPackage) {
        throw new RuntimeException('That sponsorship option is not available for self sign-up.');
    }
    if ($isPlayerPackage && !$playerId) {
        throw new RuntimeException('Choose a player to sponsor.');
    }
    if ($isMatchPackage && !$fixtureId) {
        throw new RuntimeException('Choose a fixture to sponsor.');
    }
    if ($isBoardPackage) {
        $playerId = null;
        $fixtureId = null;
    }

    // Soft check up front so we don't send someone to a payment form for a
    // slot that's obviously already gone. Not a reservation — see above.
    member_sponsorship_assert_slot_open($pdo, $package, $playerId, $fixtureId, $seasonId);

    $sponsorId = member_sponsorship_ensure_sponsor($pdo, $holder);

    if ($paymentMethod === 'online' && (float) $package['amount'] > 0) {
        $currency = stripe_default_currency();
        $session = stripe_request('POST', '/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => stripe_amount_to_minor_units((float) $package['amount'], $currency),
                    'product_data' => [
                        'name' => (string) $package['name'],
                        'description' => 'Sponsorship — Saltcoats Victoria FC',
                    ],
                ],
            ]],
            'metadata' => [
                'kind' => 'member_sponsorship',
                'sponsor_id' => (string) $sponsorId,
                'package_id' => (string) $package['id'],
                'season_id' => (string) $seasonId,
                'player_id' => (string) ($playerId ?? ''),
                'fixture_id' => (string) ($fixtureId ?? ''),
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'kind' => 'member_sponsorship',
                    'sponsor_id' => (string) $sponsorId,
                ],
            ],
        ]);

        return ['url' => (string) $session['url']];
    }

    $pdo->beginTransaction();
    try {
        member_sponsorship_assert_slot_open($pdo, $package, $playerId, $fixtureId, $seasonId);
        member_sponsorship_create_agreement($pdo, $sponsorId, $package, $playerId, $fixtureId, $seasonId, 'self_serve:' . $paymentMethod);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['url' => $successUrl];
}

/**
 * Only now — payment actually confirmed by Stripe — does the sponsorship
 * get created. Re-checks the slot one last time: if someone else's payment
 * for the same slot landed first (a genuine race between two people paying
 * within moments of each other), the agreement is still created rather than
 * silently dropping a payment the club has actually received — it just
 * won't be the only agreement for that slot, which is visible to an admin
 * in the Agreements list to resolve (refund one, or treat as a joint
 * sponsorship) rather than something the system should guess at.
 *
 * @param array<string, mixed> $session decoded Stripe Checkout Session
 */
function member_sponsorship_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    if ($paymentIntentId !== '') {
        $existing = $pdo->prepare('SELECT id FROM stripe_transactions WHERE stripe_payment_intent_id = :id');
        $existing->execute([':id' => $paymentIntentId]);
        if ($existing->fetchColumn()) {
            return; // Already processed (webhook retry).
        }
    }

    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $sponsorId = (int) ($metadata['sponsor_id'] ?? 0);
    $packageId = (int) ($metadata['package_id'] ?? 0);
    $seasonId = (int) ($metadata['season_id'] ?? 0);
    $playerId = !empty($metadata['player_id']) ? (int) $metadata['player_id'] : null;
    $fixtureId = !empty($metadata['fixture_id']) ? (int) $metadata['fixture_id'] : null;

    if (!$sponsorId || !$packageId) {
        error_log('[member_sponsorship] Paid session ' . $sessionId . ' is missing sponsor/package metadata.');
        return;
    }

    ensureSponsorshipCatalogSchema($pdo);
    $package = getSponsorshipPackage($pdo, $packageId);
    if (!$package) {
        error_log('[member_sponsorship] Paid session ' . $sessionId . ' references missing package ' . $packageId);
        return;
    }

    $amount = stripe_minor_units_to_amount((int) ($session['amount_total'] ?? 0), (string) ($session['currency'] ?? 'gbp'));

    $pdo->beginTransaction();
    try {
        $agreementId = member_sponsorship_create_agreement($pdo, $sponsorId, $package, $playerId, $fixtureId, $seasonId, 'self_serve:stripe (session ' . $sessionId . ')');

        $pdo->prepare('INSERT INTO stripe_transactions
            (agreement_id, payment_link_id, stripe_payment_intent_id, stripe_checkout_session_id, amount, currency, status, raw_payload)
            VALUES (:agreement_id, NULL, :payment_intent_id, :session_id, :amount, :currency, :status, :raw_payload)')
            ->execute([
                ':agreement_id' => $agreementId,
                ':payment_intent_id' => $paymentIntentId ?: ('unknown_' . $sessionId),
                ':session_id' => $sessionId,
                ':amount' => $amount,
                ':currency' => (string) ($session['currency'] ?? 'gbp'),
                ':status' => 'succeeded',
                ':raw_payload' => json_encode($session),
            ]);

        $agreement = getSponsorshipAgreement($pdo, $agreementId);
        if ($agreement) {
            stripe_record_agreement_payment($pdo, $agreement, $amount, 'Stripe payment (member self-serve, session ' . $sessionId . ')');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[member_sponsorship] Failed to finalize paid session ' . $sessionId . ': ' . $e->getMessage());
    }
}

/**
 * A member's sponsorships bucketed by where they stand today, for the
 * account overview's "thank you" section.
 *
 * @return array{current: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, previous: list<array<string, mixed>>}
 */
function member_sponsorship_overview(PDO $pdo, array $holder): array
{
    $overview = ['current' => [], 'upcoming' => [], 'previous' => []];
    $sponsorId = (int) ($holder['sponsor_id'] ?? 0);
    if ($sponsorId <= 0) {
        return $overview;
    }

    ensureSponsorshipCatalogSchema($pdo);
    $agreements = getSponsorshipAgreements($pdo, ['sponsor_id' => $sponsorId]);
    foreach ($agreements as $agreement) {
        $status = (string) $agreement['effective_status'];
        if ($status === 'scheduled') {
            $overview['upcoming'][] = $agreement;
        } elseif ($status === 'active') {
            $overview['current'][] = $agreement;
        } else {
            $overview['previous'][] = $agreement;
        }
    }

    return $overview;
}
