<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_controls.php';

pos_controls_ensure_schema($pdo);
$actor = pos_require_actor($pdo);

header('Content-Type: application/json');

function pos_api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pos_api_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false ? $raw : '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = (string) ($data['csrf_token'] ?? '');
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    pos_api_response(['ok' => false, 'error' => 'Your session expired. Please reload and try again.'], 400);
}

$action = (string) ($data['action'] ?? '');

try {
    if ($action === 'member_lookup') {
        $member = pos_member_lookup($pdo, (string) ($data['input'] ?? ''));
        if (!$member) {
            pos_api_response(['ok' => false, 'error' => 'No season ticket holder or member was found.'], 404);
        }
        pos_api_response(['ok' => true, 'member' => $member]);
    }

    if ($action === 'no_sale_drawer') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_map('intval', array_column(pos_locations_for_actor($pdo, $actor), 'id'));
        if (!in_array($locationId, $allowed, true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $activeTradingDay = pos_trading_day_require_open($pdo);
        $session = pos_till_session_require_open($pdo, (int) $activeTradingDay['id'], $locationId);
        $reason = trim((string) ($data['reason'] ?? 'No sale drawer open'));
        pos_audit_event($pdo, 'pos_no_sale_drawer', $reason, [
            'trading_day_id' => (int) $activeTradingDay['id'],
            'till_session_id' => (int) $session['id'],
            'location_id' => $locationId,
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
        ]);
        pos_api_response(['ok' => true]);
    }

    if ($action === 'audit_till_close_count' || $action === 'close_till') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_map('intval', array_column(pos_locations_for_actor($pdo, $actor), 'id'));
        if (!in_array($locationId, $allowed, true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $activeTradingDay = pos_trading_day_require_open($pdo);
        $session = pos_till_session_require_open($pdo, (int) $activeTradingDay['id'], $locationId);
        $location = pos_location($pdo, $locationId);
        $countedCash = max(0, round((float) ($data['counted_cash'] ?? 0), 2));
        $expectedCash = pos_till_session_expected_cash($pdo, (int) $session['id']);
        $variance = round($countedCash - $expectedCash, 2);
        $notes = trim((string) ($data['closing_notes'] ?? ''));

        if ($action === 'audit_till_close_count') {
            pos_audit_event(
                $pdo,
                'pos_till_close_count_checked',
                'Checked ' . (string) ($location['name'] ?? 'POS') . ' till session #' . (int) $session['id'] . ' counted ' . gbp($countedCash) . ' variance ' . gbp($variance) . ($notes !== '' ? ' notes: ' . $notes : ''),
                [
                    'trading_day_id' => (int) $activeTradingDay['id'],
                    'till_session_id' => (int) $session['id'],
                    'location_id' => $locationId,
                    'operator_id' => pos_controls_actor_operator_id($actor),
                    'hub_account_id' => pos_controls_actor_account_id($actor),
                    'amount' => $variance,
                ]
            );
            pos_api_response(['ok' => true, 'variance' => $variance, 'balanced' => abs($variance) < 0.005]);
        }

        if (abs($variance) >= 0.005 && !pos_actor_is_manager($pdo)) {
            pos_api_response(['ok' => false, 'error' => 'A manager must accept a cash variance before this till can be closed.'], 403);
        }
        pos_till_session_close($pdo, (int) $session['id'], $actor, $countedCash, $notes);
        pos_audit_event($pdo, 'pos_till_closed', 'Closed POS till session #' . (int) $session['id'] . ' from POS screen', [
            'trading_day_id' => (int) $activeTradingDay['id'],
            'till_session_id' => (int) $session['id'],
            'location_id' => $locationId,
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
            'amount' => $variance,
        ]);
        pos_api_response(['ok' => true, 'redirect' => hub_auth_is_authenticated() ? '/pos_overview.php' : '/pos/logout.php']);
    }

    if ($action === 'create_sale') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            (string) ($data['payment_method'] ?? ''),
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0)
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'create_card_pending') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'card_stripe_app',
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0),
            'pending_card'
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'create_cash_pending') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'cash',
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0),
            'pending_cash'
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'complete_card_pending') {
        $result = pos_complete_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor, (string) ($data['payment_reference'] ?? ''));
        pos_audit_event($pdo, 'pos_sale_completed', 'Completed pending card POS sale #' . (int) ($data['sale_id'] ?? 0), [
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
            'amount' => (float) ($result['total'] ?? 0),
        ]);
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'complete_cash_pending') {
        $result = pos_complete_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_audit_event($pdo, 'pos_sale_completed', 'Completed pending cash POS sale #' . (int) ($data['sale_id'] ?? 0), [
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
            'amount' => (float) ($result['total'] ?? 0),
        ]);
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'cancel_card_pending') {
        pos_cancel_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_audit_event($pdo, 'pos_sale_cancelled', 'Cancelled pending card POS sale #' . (int) ($data['sale_id'] ?? 0), [
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
        ]);
        pos_api_response(['ok' => true]);
    }

    if ($action === 'cancel_cash_pending') {
        pos_cancel_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_audit_event($pdo, 'pos_sale_cancelled', 'Cancelled pending cash POS sale #' . (int) ($data['sale_id'] ?? 0), [
            'operator_id' => pos_controls_actor_operator_id($actor),
            'hub_account_id' => pos_controls_actor_account_id($actor),
        ]);
        pos_api_response(['ok' => true]);
    }

    if ($action === 'pending_card_status') {
        $sale = pos_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $sale]);
    }

    if ($action === 'pending_cash_status') {
        $sale = pos_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $sale]);
    }

    pos_api_response(['ok' => false, 'error' => 'Unknown POS action.'], 400);
} catch (Throwable $exception) {
    pos_api_response(['ok' => false, 'error' => $exception->getMessage()], 400);
}
