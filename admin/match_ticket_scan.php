<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season_ticket_attendance.php';
require_once __DIR__ . '/lib/match_tickets.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'You must be logged in to scan tickets.']);
    exit;
}
if (!hub_auth_has_capability('tickets_ops')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You do not have permission to scan tickets.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!csrf_check()) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'The security token is invalid. Refresh and try again.']);
    exit;
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$scanInput = (string) ($_POST['scan_value'] ?? '');
$user = hub_auth_current_user();

$matchResult = match_ticket_check_and_mark_attendance($pdo, $fixtureId, $scanInput, isset($user['id']) ? (int) $user['id'] : null);
if (!empty($matchResult['found'])) {
    $ticket = $matchResult['ticket'] ?? null;
    $order = $matchResult['order'] ?? null;
    $scanLog = $matchResult['scan_log'] ?? null;
    $accepted = (string) ($matchResult['status'] ?? '') === 'checked_in';
    echo json_encode([
        'ok' => $accepted,
        'valid_ticket' => (bool) ($matchResult['valid'] ?? false),
        'status' => $matchResult['status'],
        'message' => $matchResult['message'],
        'holder' => [
            'name' => (string) (($ticket['buyer_name'] ?? null) ?: ($order['buyer_name'] ?? 'Match ticket')),
            'ticket_kind' => 'Match Ticket',
            'ticket' => (string) (($ticket['ticket_label'] ?? null) ?: 'Group Order'),
            'order_id' => (int) (($ticket['order_id'] ?? null) ?: ($order['id'] ?? 0)),
        ],
        'attendance' => null,
        'scan' => is_array($scanLog) ? [
            'accepted' => (int) ($scanLog['accepted'] ?? 0) === 1,
            'status' => (string) ($scanLog['status'] ?? ''),
            'message' => (string) ($scanLog['message'] ?? ''),
            'holder_name' => (string) ($scanLog['holder_name'] ?? ''),
            'ticket_kind' => 'Match Ticket',
            'ticket_label' => (string) ($scanLog['ticket_label'] ?? ''),
            'scanned_at' => (string) ($scanLog['scanned_at'] ?? ''),
        ] : null,
        'count' => admissionsCount($pdo, $fixtureId),
    ]);
    exit;
}

$result = season_ticket_check_and_mark_attendance($pdo, $fixtureId, $scanInput, isset($user['id']) ? (int) $user['id'] : null);
$order = $result['order'] ?? null;
$attendance = $result['attendance'] ?? null;
$scanLog = $result['scan_log'] ?? null;
$accepted = (string) ($result['status'] ?? '') === 'checked_in';

echo json_encode([
    'ok' => $accepted,
    'valid_ticket' => (bool) $result['valid'],
    'status' => $result['status'],
    'message' => $result['message'],
    'holder' => is_array($order) ? [
        'name' => (string) ($order['holder_name'] ?? ''),
        'ticket_kind' => 'Season Ticket',
        'ticket' => (string) ($order['type_name'] ?? ''),
        'season' => (string) ($order['season_name'] ?? ''),
        'order_id' => (int) ($order['id'] ?? 0),
    ] : null,
    'attendance' => is_array($attendance) ? [
        'scanned_at' => (string) ($attendance['scanned_at'] ?? ''),
    ] : null,
    'scan' => is_array($scanLog) ? [
        'accepted' => (int) ($scanLog['accepted'] ?? 0) === 1,
        'status' => (string) ($scanLog['status'] ?? ''),
        'message' => (string) ($scanLog['message'] ?? ''),
        'holder_name' => (string) ($scanLog['holder_name'] ?? ''),
        'ticket_kind' => 'Season Ticket',
        'ticket_label' => (string) ($scanLog['ticket_label'] ?? ''),
        'scanned_at' => (string) ($scanLog['scanned_at'] ?? ''),
    ] : null,
    'count' => $fixtureId > 0 ? admissionsCount($pdo, $fixtureId) : 0,
]);
