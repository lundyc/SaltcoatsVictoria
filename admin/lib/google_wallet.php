<?php
declare(strict_types=1);

/**
 * Google Wallet "Save to Wallet" links for event tickets.
 *
 * No Google API call is needed: the pass is described inside a JWT signed (RS256) with the service account's
 * private key, and the link https://pay.google.com/gp/v/save/{jwt} adds it to the phone. The private key stays in a
 * file outside the web root; only its PATH is stored in settings. A pass is a snapshot: later name changes or a
 * cancelled event are not pushed to wallets, and the QR is checked against the live ticket when scanned anyway.
 */

const WALLET_DESIGN_VERSION = 2;

function wallet_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** @return array{issuer_id:string,service_email:string,key_path:string} */
function wallet_config(PDO $pdo): array
{
    require_once __DIR__ . '/site_settings.php';
    return [
        'issuer_id' => trim(site_setting($pdo, 'wallet_issuer_id', '')),
        'service_email' => trim(site_setting($pdo, 'wallet_service_email', '')),
        'key_path' => trim(site_setting($pdo, 'wallet_key_path', '')),
    ];
}

function wallet_is_configured(PDO $pdo): bool
{
    $c = wallet_config($pdo);
    return $c['issuer_id'] !== '' && $c['service_email'] !== '' && $c['key_path'] !== '' && is_readable($c['key_path']);
}

/**
 * Sign a Save-to-Wallet JWT for one ticket-style pass.
 *
 * $spec keys: class_key, object_key, event_name, venue, start (Y-m-d H:i:s), end (optional), holder, ticket_type,
 * qr_value, manual_code. Ids carry WALLET_DESIGN_VERSION because Google keeps a class/object as first saved.
 *
 * @param array<string,string> $cfg @param array<string,string> $spec @throws RuntimeException
 */
function wallet_build_pass_jwt(array $cfg, array $spec, string $logoUrl, ?int $now = null): string
{
    $pem = @file_get_contents($cfg['key_path']);
    $key = $pem ? openssl_pkey_get_private($pem) : false;
    if (!$key) {
        throw new RuntimeException('The Wallet signing key could not be read.');
    }
    $issuer = $cfg['issuer_id'];
    $classId = $issuer . '.' . $spec['class_key'] . '_v' . WALLET_DESIGN_VERSION;
    $objectId = $issuer . '.' . $spec['object_key'] . '_v' . WALLET_DESIGN_VERSION;
    $text = static fn (string $s): array => ['defaultValue' => ['language' => 'en-GB', 'value' => $s]];
    $when = ['start' => date('c', strtotime((string) $spec['start']))];
    if (!empty($spec['end'])) {
        $when['end'] = date('c', strtotime((string) $spec['end']));
    }
    $class = [
        'id' => $classId,
        'issuerName' => 'Saltcoats Victoria FC',
        'reviewStatus' => 'UNDER_REVIEW',
        'eventName' => $text((string) $spec['event_name']),
        'venue' => ['name' => $text((string) $spec['venue']), 'address' => $text((string) $spec['venue'])],
        'dateTime' => $when,
        'hexBackgroundColor' => '#4b0818',
        'logo' => ['sourceUri' => ['uri' => $logoUrl], 'contentDescription' => $text('Saltcoats Victoria FC')],
    ];
    $object = [
        'id' => $objectId,
        'classId' => $classId,
        'state' => 'ACTIVE',
        'ticketHolderName' => (string) $spec['holder'],
        'ticketType' => $text((string) $spec['ticket_type']),
        'barcode' => ['type' => 'QR_CODE', 'value' => (string) $spec['qr_value'], 'alternateText' => (string) $spec['manual_code']],
    ];
    $payload = [
        'iss' => $cfg['service_email'],
        'aud' => 'google',
        'typ' => 'savetowallet',
        'iat' => $now ?? time(),
        'origins' => [],
        'payload' => ['eventTicketClasses' => [$class], 'eventTicketObjects' => [$object]],
    ];
    $head = wallet_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $body = wallet_b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!openssl_sign($head . '.' . $body, $sig, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Wallet pass.');
    }
    return $head . '.' . $body . '.' . wallet_b64url($sig);
}

/** Event ticket (see wallet_build_pass_jwt). @param array<string,mixed> $event @param array<string,mixed> $ticket */
function wallet_build_jwt(array $cfg, array $event, array $ticket, string $ticketUrl, string $logoUrl, ?int $now = null): string
{
    return wallet_build_pass_jwt($cfg, [
        'class_key' => 'event_' . (int) $event['id'], 'object_key' => 'ticket_' . (int) $ticket['id'],
        'event_name' => (string) $event['title'], 'venue' => (string) ($event['venue_name'] ?? ''), 'start' => (string) $event['starts_at'],
        'holder' => (string) ($ticket['attendee_name'] ?? ''), 'ticket_type' => (string) $ticket['ticket_label'],
        'qr_value' => $ticketUrl, 'manual_code' => (string) $ticket['manual_code'],
    ], $logoUrl, $now);
}

/** Match ticket. @param array<string,mixed> $order fixture fields (opponent, match_date, kickoff_time) @param array<string,mixed> $ticket */
function wallet_build_match_jwt(array $cfg, array $order, array $ticket, string $ticketUrl, string $clubShort, string $ground, string $logoUrl): string
{
    $time = !empty($order['kickoff_time']) ? (string) $order['kickoff_time'] : '15:00:00';
    return wallet_build_pass_jwt($cfg, [
        'class_key' => 'match_' . (int) ($ticket['fixture_id'] ?? $order['fixture_id'] ?? 0), 'object_key' => 'matchticket_' . (int) $ticket['id'],
        'event_name' => $clubShort . ' v ' . (string) $order['opponent'], 'venue' => $ground, 'start' => $order['match_date'] . ' ' . $time,
        'holder' => '', 'ticket_type' => (string) $ticket['ticket_label'],
        'qr_value' => $ticketUrl, 'manual_code' => (string) $ticket['manual_code'],
    ], $logoUrl);
}

/** Season ticket. @param array<string,mixed> $pass a row from getSeasonPassesForPerson() */
function wallet_build_season_jwt(array $cfg, array $pass, string $credentialValue, string $ground, string $logoUrl): string
{
    $start = !empty($pass['valid_from']) ? (string) $pass['valid_from'] : date('Y-m-d');
    return wallet_build_pass_jwt($cfg, [
        'class_key' => 'season_' . (int) ($pass['season_id'] ?? 0) . '_' . (int) ($pass['season_ticket_type_id'] ?? 0),
        'object_key' => 'seasonpass_' . (int) $pass['id'],
        'event_name' => (string) $pass['season_name'] . ' Season Ticket', 'venue' => $ground, 'start' => $start,
        'end' => !empty($pass['valid_until']) ? (string) $pass['valid_until'] : '',
        'holder' => (string) $pass['holder_name'], 'ticket_type' => (string) $pass['type_name'],
        'qr_value' => $credentialValue, 'manual_code' => (string) ($pass['manual_code'] ?? ''),
    ], $logoUrl);
}

/** The "Add to Google Wallet" button (styles included once per page). */
function wallet_button_html(string $href): string
{
    static $styled = false;
    $css = '';
    if (!$styled) {
        $styled = true;
        $css = '<style>.gwallet-btn{display:inline-flex;align-items:center;gap:.8rem;padding:.9rem 1.6rem .9rem 1.2rem;background:#000;color:#fff;border-radius:999px;font-size:1.1rem;font-weight:600;line-height:1;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25);min-height:56px}.gwallet-btn:hover,.gwallet-btn:focus-visible{background:#1f1f1f;color:#fff;text-decoration:none}.gwallet-btn__icon{flex:0 0 auto}</style>';
    }
    $icon = '<svg class="gwallet-btn__icon" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true"><rect x="2" y="5" width="28" height="22" rx="5" fill="#4285F4"/><path d="M2 10a5 5 0 0 1 5-5h18a5 5 0 0 1 5 5v1H2z" fill="#34A853"/><path d="M2 11h28v3H2z" fill="#FBBC04"/><path d="M2 14h28v8a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5z" fill="#EA4335"/><rect x="19" y="16" width="11" height="7" rx="3.5" fill="#fff"/><circle cx="23" cy="19.5" r="1.4" fill="#4285F4"/></svg>';
    return $css . '<p style="margin:1rem 0 0"><a class="gwallet-btn" rel="noopener" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $icon . '<span>Add to Google Wallet</span></a></p>';
}

function wallet_save_url(string $jwt): string
{
    return 'https://pay.google.com/gp/v/save/' . $jwt;
}
