<?php
declare(strict_types=1);

/**
 * Event ticket emails. Replies go to the event's organiser address, else the club-wide events address
 * (site setting events_reply_to), else nowhere: the mail is sent from the noreply address and the
 * "contact us" line is left out until an address has been chosen.
 */

require_once __DIR__ . '/events_orders.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/site_settings.php';

function events_contact_email(PDO $pdo, array $event): string
{
    $own = trim((string) ($event['organiser_email'] ?? ''));
    if ($own !== '' && filter_var($own, FILTER_VALIDATE_EMAIL)) {
        return $own;
    }
    $club = trim(site_setting($pdo, 'events_reply_to', ''));
    return filter_var($club, FILTER_VALIDATE_EMAIL) ? $club : '';
}

function events_public_order_url(string $accessToken): string
{
    $base = function_exists('stripe_public_base_url') ? stripe_public_base_url() : 'https://saltcoatsvictoria.co.uk';
    return rtrim($base, '/') . '/events/order/' . rawurlencode($accessToken);
}

/** Send the order confirmation once. Returns true if sent (or already sent). */
function events_send_confirmation_email(PDO $pdo, int $orderId, bool $force = false): bool
{
    $stmt = $pdo->prepare("SELECT o.*, (SELECT token FROM order_access_tokens a WHERE a.order_id = o.id AND a.purpose = 'public_ticket_order' AND a.revoked_at IS NULL ORDER BY a.id DESC LIMIT 1) AS access_token
        FROM orders o WHERE o.id = :id AND o.source = 'event_ticket'");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || !in_array($order['status'], ['paid', 'complete'], true)) {
        return false;
    }
    if (!$force && !empty($order['confirmation_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['customer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $tickets = events_order_tickets($pdo, $orderId);
    if (!$tickets) {
        return false;
    }
    $event = events_get($pdo, (int) $tickets[0]['event_id']);
    if (!$event) {
        return false;
    }
    $url = events_public_order_url((string) $order['access_token']);
    $contact = events_contact_email($pdo, $event);
    $when = date('l j F Y', strtotime((string) $event['starts_at'])) . ' at ' . date('H:i', strtotime((string) $event['starts_at']));
    $counts = [];
    foreach ($tickets as $t) {
        $counts[$t['ticket_label']] = ($counts[$t['ticket_label']] ?? 0) + 1;
    }
    $lines = [];
    foreach ($counts as $label => $n) {
        $lines[] = $n . ' x ' . $label;
    }
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $subject = 'Your tickets for ' . $event['title'];

    $text = "Thank you, " . $order['customer_name'] . ".\n\nYour tickets for " . $event['title'] . " are confirmed.\n\n"
        . $when . "\n" . $event['venue_name'] . "\n\n" . implode("\n", $lines) . "\nTotal paid: " . number_format((float) $order['total_amount'], 2) . " GBP\n\n"
        . "Your QR tickets (show them on your phone at the door):\n" . $url . "\n"
        . ((int) $event['min_age'] > 0 ? "\nThis is " . ((int) $event['min_age'] === 18 ? 'an ' : 'a ') . EVENT_MIN_AGES[(int) $event['min_age']] . " event.\n" : '')
        . ($contact !== '' ? "\nA problem or a question? Email " . $contact . ".\n" : '');
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#24151b">'
        . '<h2 style="color:#4b0818;margin-bottom:4px">Your tickets are confirmed</h2>'
        . '<p>Thank you, ' . $h((string) $order['customer_name']) . '.</p>'
        . '<p><strong>' . $h((string) $event['title']) . '</strong><br>' . $h($when) . '<br>' . $h((string) $event['venue_name']) . '</p>'
        . '<p>' . $h(implode(', ', $lines)) . '<br>Total paid: &pound;' . number_format((float) $order['total_amount'], 2) . '</p>'
        . '<p><a href="' . $h($url) . '" style="display:inline-block;background:#4b0818;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">View my tickets</a></p>'
        . '<p style="color:#746872;font-size:13px">Show the QR code on your phone at the door.'
        . ((int) $event['min_age'] > 0 ? ' This is ' . ((int) $event['min_age'] === 18 ? 'an ' : 'a ') . $h(EVENT_MIN_AGES[(int) $event['min_age']]) . ' event.' : '') . '</p>'
        . ($contact !== '' ? '<p style="color:#746872;font-size:13px">A problem or a question? Email <a href="mailto:' . $h($contact) . '">' . $h($contact) . '</a>.</p>' : '')
        . '</div>';

    $sent = hub_send_mail($email, $subject, $html, true, $contact !== '' ? $contact : null);
    if ($sent) {
        $pdo->prepare('UPDATE orders SET confirmation_email_sent_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
    }
    return $sent;
}
