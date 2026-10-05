<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('tickets_ops')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$accessToken = match_ticket_extract_token((string) ($_GET['access'] ?? ($_GET['group'] ?? '')));
$order = $accessToken !== '' ? getMatchTicketOrderByAccessToken($pdo, $accessToken) : null;
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Ticket order not found.']);
    exit;
}

$tickets = getMatchTicketsForOrder($pdo, (int) $order['id']);
echo json_encode([
    'ok' => true,
    'order_id' => (int) $order['id'],
    'tickets' => array_map(static fn(array $ticket): array => [
        'token' => (string) $ticket['ticket_token'],
        'manual_code' => (string) $ticket['manual_code'],
        'checked_in' => !empty($ticket['checked_in_at']),
        'checked_in_at' => (string) ($ticket['checked_in_at'] ?? ''),
    ], $tickets),
]);
