<?php

declare(strict_types=1);

require_once __DIR__ . '/hidden_team.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/stripe.php';

/**
 * Start a member's basket checkout — one or more Hidden Team boxes paid for
 * in a single Stripe Checkout session. Same philosophy as
 * member_sponsorship_purchase(): nothing is written to any team row here —
 * no reservation, no "pending" state. If the member abandons Stripe
 * Checkout, every box in the basket must stay open for someone else. The
 * claims only happen in hidden_team_stripe_handle_checkout_completed() once
 * Stripe actually confirms payment.
 *
 * @param list<int> $teamIds
 * @return array{url: string}
 */
function hidden_team_checkout_start(PDO $pdo, array $holder, array $teamIds, string $successUrl, string $cancelUrl): array
{
    ensureHiddenTeamSchema($pdo);
    $personId = (int) ($holder['person_id'] ?? 0);
    if ($personId <= 0 && !empty($holder['id'])) {
        $personId = personIdFromLegacyHolderId($pdo, (int) $holder['id']) ?? 0;
    }
    if ($personId <= 0) {
        throw new RuntimeException('Your account could not be matched to a person record.');
    }
    $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
    if ($teamIds === []) {
        throw new RuntimeException('Your basket is empty.');
    }

    $teams = [];
    foreach ($teamIds as $teamId) {
        $team = getHiddenTeamTeam($pdo, $teamId);
        if (!$team) {
            throw new RuntimeException('One of the teams in your basket no longer exists.');
        }
        if ((string) $team['game_status'] !== 'open') {
            throw new RuntimeException('"' . $team['team_name'] . '" is no longer available — that game has closed.');
        }
        if ((int) $team['is_taken'] === 1) {
            throw new RuntimeException('Sorry, "' . $team['team_name'] . '" has just been claimed by someone else. Remove it from your basket and try again.');
        }
        $teams[] = $team;
    }

    $totalCost = array_sum(array_map(static fn(array $t): float => (float) $t['cost_per_team'], $teams));

    if ($totalCost <= 0) {
        // A free game (cost_per_team = 0) — claim everything immediately, no payment needed.
        $pdo->beginTransaction();
        try {
            foreach ($teams as $team) {
                hidden_team_claim_team($pdo, (int) $team['id'], $personId, (string) $holder['name']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['url' => $successUrl];
    }

    $currency = stripe_default_currency();
    $lineItems = [];
    foreach ($teams as $team) {
        $cost = (float) $team['cost_per_team'];
        if ($cost <= 0) {
            continue;
        }
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units($cost, $currency),
                'product_data' => [
                    'name' => 'Hidden Team — ' . (string) $team['team_name'],
                    'description' => (string) $team['game_name'] . ' fundraiser · Saltcoats Victoria FC',
                ],
            ],
        ];
    }

    // Append the Stripe-templated session id to the success redirect so the
    // landing page can look up exactly what this checkout paid for — the
    // page always shows whichever game is *currently* open, which after a
    // sellout-triggered auto-draw may already be a new, unrelated game by
    // the time Stripe redirects the browser back.
    $successUrlWithSession = $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $successUrlWithSession,
        'cancel_url' => $cancelUrl,
        'line_items' => $lineItems,
        'customer_email' => filter_var((string) ($holder['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
        'metadata' => [
            'kind' => 'hidden_team',
            'team_ids' => implode(',', $teamIds),
            'person_id' => (string) $personId,
            'holder_id' => '0',
        ],
        'payment_intent_data' => [
            'metadata' => [
                'kind' => 'hidden_team',
                'team_ids' => implode(',', $teamIds),
            ],
        ],
    ]);

    return ['url' => (string) $session['url']];
}

/**
 * Actually claims the box. Caller is responsible for the transaction and for
 * having just re-checked availability inside it.
 */
function hidden_team_claim_team(PDO $pdo, int $teamId, int $personId, string $supporterName, ?int $legacyHolderId = null): void
{
    $stmt = $pdo->prepare("UPDATE hidden_team_teams SET person_id = :person, supporter_name = :name, is_taken = 1, claimed_at = NOW() WHERE id = :id AND is_taken = 0");
    $stmt->execute([':person' => $personId, ':name' => $supporterName, ':id' => $teamId]);
}

/**
 * @param array<string, mixed> $session decoded Stripe Checkout Session
 */
function hidden_team_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    // Idempotency is checked per *session*, not per payment-intent, because
    // one basket checkout now produces several hidden_team_payments rows (one
    // per team) sharing the same payment intent — a payment-intent-keyed
    // check would only ever match the first row and let a webhook retry
    // silently skip claiming the rest of the basket.
    $existing = $pdo->prepare('SELECT id FROM hidden_team_payments WHERE stripe_checkout_session_id = :session_id LIMIT 1');
    $existing->execute([':session_id' => $sessionId]);
    if ($existing->fetchColumn()) {
        return; // Already processed (webhook retry).
    }

    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $teamIds = array_values(array_filter(array_map('intval', explode(',', (string) ($metadata['team_ids'] ?? '')))));
    $personId = (int) ($metadata['person_id'] ?? 0);
    $holderId = (int) ($metadata['holder_id'] ?? 0);
    if ($personId <= 0 && $holderId > 0) {
        $personId = personIdFromLegacyHolderId($pdo, $holderId) ?? 0;
    }
    if (!$teamIds || !$personId) {
        error_log('[hidden_team] Paid session ' . $sessionId . ' is missing team/person metadata.');
        return;
    }

    ensureHiddenTeamSchema($pdo);
    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $person = getPerson($pdo, $personId);
    $holderName = (string) ($person['display_name'] ?? 'Member');
    $totalPaid = 0.0;

    $pdo->beginTransaction();
    try {
        foreach ($teamIds as $teamId) {
            $team = getHiddenTeamTeam($pdo, $teamId);
            if (!$team) {
                error_log('[hidden_team] Paid session ' . $sessionId . ' references missing team ' . $teamId);
                continue;
            }

            $claimed = $pdo->prepare("UPDATE hidden_team_teams SET person_id = :person, supporter_name = :name, is_taken = 1, paid = 1, claimed_at = NOW() WHERE id = :id AND is_taken = 0");
            $claimed->execute([':person' => $personId, ':name' => $holderName, ':id' => $teamId]);
            $wonTheRace = $claimed->rowCount() === 1;
            $totalPaid += (float) $team['cost_per_team'];

            // Each team can only ever hold one claimant (unlike sponsorships,
            // which allow several historical agreement rows per slot) — if
            // someone else's payment landed first for this exact box, this
            // payment must never be silently dropped just because it lost the
            // race. It's recorded against the team anyway, flagged
            // 'conflict', so an admin sees it and can refund/reassign — the
            // alternative (discarding a real payment) is worse than
            // surfacing a rare, honest edge case. Each team in the basket is
            // judged independently, so one conflicting box doesn't affect
            // the others the member successfully claimed in the same purchase.
            $pdo->prepare('INSERT INTO hidden_team_payments (team_id, amount, method, status, stripe_checkout_session_id, stripe_payment_intent_id, note) VALUES (:team, :amount, "stripe", :status, :session_id, :payment_intent_id, :note)')
                ->execute([
                    ':team' => $teamId,
                    ':amount' => (float) $team['cost_per_team'],
                    ':status' => $wonTheRace ? 'settled' : 'conflict',
                    ':session_id' => $sessionId,
                    ':payment_intent_id' => $paymentIntentId ?: ('unknown_' . $sessionId),
                    ':note' => $wonTheRace
                        ? ('Stripe payment (member basket checkout, session ' . $sessionId . ')')
                        : ('CONFLICT — team was already claimed when this payment landed. Needs manual review/refund. Session ' . $sessionId),
                ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[hidden_team] Failed to finalize paid session ' . $sessionId . ': ' . $e->getMessage());

        return;
    }

    // Every team in a basket is bought from the same open game in practice
    // (members only ever see one open board), but check per-game rather
    // than assume, in case that ever changes.
    $gameIdsToCheck = [];
    foreach ($teamIds as $teamId) {
        $team = getHiddenTeamTeam($pdo, $teamId);
        if ($team) {
            $gameIdsToCheck[(int) $team['game_id']] = true;
        }
    }
    foreach (array_keys($gameIdsToCheck) as $gameId) {
        hiddenTeamMaybeAutoDrawIfSoldOut($pdo, $gameId);
    }

    stripe_send_payment_notification(
        $pdo,
        'Hidden team',
        $holderName,
        (string) ($person['email'] ?? ''),
        $totalPaid,
        'Hidden team basket - session ' . $sessionId,
        stripe_public_base_url() . '/hidden_team_games.php'
    );
}

/**
 * @param array<string, mixed> $session decoded Stripe Checkout Session
 */
function hidden_team_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    // No reservation was ever made for an abandoned checkout, so there's
    // nothing to release — kept only for symmetry with the other kinds and
    // as a place to log if that assumption is ever wrong.
    unset($pdo, $session);
}
