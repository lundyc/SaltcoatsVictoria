<?php

declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/match_sponsorship.php';

const STRIPE_API_BASE = 'https://api.stripe.com/v1';
const STRIPE_DEFAULT_LINK_EXPIRY_HOURS = 24; // Stripe Checkout Sessions cannot expire more than 24h after creation.
const STRIPE_DEFAULT_PUBLIC_LINK_EXPIRY_DAYS = 7;
const STRIPE_WEBHOOK_TOLERANCE_SECONDS = 300;

/**
 * Merge parsed .env values with any non-empty runtime environment overrides,
 * mirroring match_google_calendar_env() in lib/google_calendar.php.
 *
 * @return array<string, string>
 */
function stripe_env(): array
{
    $fileEnv = app_parse_env_file(__DIR__ . '/../.env');
    $runtimeEnv = [
        'STRIPE_MODE' => getenv('STRIPE_MODE') !== false ? (string) getenv('STRIPE_MODE') : '',
        'STRIPE_SECRET_KEY' => getenv('STRIPE_SECRET_KEY') !== false ? (string) getenv('STRIPE_SECRET_KEY') : '',
        'STRIPE_WEBHOOK_SECRET' => getenv('STRIPE_WEBHOOK_SECRET') !== false ? (string) getenv('STRIPE_WEBHOOK_SECRET') : '',
        'STRIPE_DEFAULT_CURRENCY' => getenv('STRIPE_DEFAULT_CURRENCY') !== false ? (string) getenv('STRIPE_DEFAULT_CURRENCY') : '',
        'STRIPE_LINK_EXPIRY_HOURS' => getenv('STRIPE_LINK_EXPIRY_HOURS') !== false ? (string) getenv('STRIPE_LINK_EXPIRY_HOURS') : '',
        'STRIPE_PUBLIC_LINK_EXPIRY_DAYS' => getenv('STRIPE_PUBLIC_LINK_EXPIRY_DAYS') !== false ? (string) getenv('STRIPE_PUBLIC_LINK_EXPIRY_DAYS') : '',
        'ORDER_NOTIFICATION_ACCOUNT_IDS' => getenv('ORDER_NOTIFICATION_ACCOUNT_IDS') !== false ? (string) getenv('ORDER_NOTIFICATION_ACCOUNT_IDS') : '',
        'ORDER_NOTIFICATION_EMAILS' => getenv('ORDER_NOTIFICATION_EMAILS') !== false ? (string) getenv('ORDER_NOTIFICATION_EMAILS') : '',
        'PAYMENT_NOTIFICATION_EMAILS' => getenv('PAYMENT_NOTIFICATION_EMAILS') !== false ? (string) getenv('PAYMENT_NOTIFICATION_EMAILS') : '',
        'STRIPE_NOTIFICATION_EMAILS' => getenv('STRIPE_NOTIFICATION_EMAILS') !== false ? (string) getenv('STRIPE_NOTIFICATION_EMAILS') : '',
    ];

    return array_merge($fileEnv, array_filter($runtimeEnv, static fn(string $value): bool => $value !== ''));
}

function stripe_mode(): string
{
    $env = stripe_env();
    $mode = strtolower(trim((string) ($env['STRIPE_MODE'] ?? 'test')));
    return $mode === 'live' ? 'live' : 'test';
}

function stripe_secret_key(): string
{
    return trim((string) (stripe_env()['STRIPE_SECRET_KEY'] ?? ''));
}

function stripe_webhook_secret(): string
{
    return trim((string) (stripe_env()['STRIPE_WEBHOOK_SECRET'] ?? ''));
}

function stripe_default_currency(): string
{
    $currency = strtolower(trim((string) (stripe_env()['STRIPE_DEFAULT_CURRENCY'] ?? 'gbp')));
    return $currency !== '' ? $currency : 'gbp';
}

function stripe_link_expiry_hours(): int
{
    $hours = (int) (stripe_env()['STRIPE_LINK_EXPIRY_HOURS'] ?? STRIPE_DEFAULT_LINK_EXPIRY_HOURS);
    if ($hours < 1) {
        return STRIPE_DEFAULT_LINK_EXPIRY_HOURS;
    }
    return min($hours, STRIPE_DEFAULT_LINK_EXPIRY_HOURS);
}

function stripe_public_link_expiry_days(): int
{
    $days = (int) (stripe_env()['STRIPE_PUBLIC_LINK_EXPIRY_DAYS'] ?? STRIPE_DEFAULT_PUBLIC_LINK_EXPIRY_DAYS);
    if ($days < 1) {
        return STRIPE_DEFAULT_PUBLIC_LINK_EXPIRY_DAYS;
    }
    return min($days, 30);
}

function stripe_is_configured(): bool
{
    return stripe_secret_key() !== '';
}

/**
 * Idempotent schema guard, following the same CREATE TABLE IF NOT EXISTS
 * pattern as ensureSponsorshipCatalogSchema() / ensureMatchSchema().
 */
function ensureStripeSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_payment_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id INT UNSIGNED NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NOT NULL,
        stripe_payment_intent_id VARCHAR(190) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'gbp',
        url VARCHAR(500) NOT NULL,
        slug VARCHAR(24) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        sent_to_email VARCHAR(190) DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        created_by BIGINT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME DEFAULT NULL,
        public_expires_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_payment_links_session (stripe_checkout_session_id),
        KEY idx_stripe_payment_links_agreement (agreement_id, status),
        KEY idx_stripe_payment_links_slug (slug),
        CONSTRAINT fk_stripe_payment_links_agreement FOREIGN KEY (agreement_id) REFERENCES sponsorship_agreements (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_transactions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id INT UNSIGNED NOT NULL,
        payment_link_id INT UNSIGNED DEFAULT NULL,
        stripe_payment_intent_id VARCHAR(190) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'gbp',
        status VARCHAR(30) NOT NULL DEFAULT 'succeeded',
        refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        raw_payload MEDIUMTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_transactions_intent (stripe_payment_intent_id),
        KEY idx_stripe_transactions_agreement (agreement_id, status),
        KEY idx_stripe_transactions_link (payment_link_id),
        CONSTRAINT fk_stripe_transactions_agreement FOREIGN KEY (agreement_id) REFERENCES sponsorship_agreements (id) ON DELETE CASCADE,
        CONSTRAINT fk_stripe_transactions_link FOREIGN KEY (payment_link_id) REFERENCES stripe_payment_links (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sponsorship bundles let one Stripe Checkout Session cover several agreements at
    // once (one stripe_payment_links / stripe_transactions row per agreement, all
    // sharing one session/payment-intent — see stripe_create_checkout_session_for_bundle()
    // and stripe_handle_checkout_session_completed()), so the old 1:1 unique keys on
    // stripe_checkout_session_id / stripe_payment_intent_id have to go. payment_link_id
    // stays genuinely 1:1 with "one agreement's slice of one session", so that's where
    // the real uniqueness guarantee (and the webhook's per-item idempotency check) now
    // lives instead.
    $linkIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM stripe_payment_links') as $row) {
        $linkIndexes[(string) $row['Key_name']] = true;
    }
    if (isset($linkIndexes['uq_stripe_payment_links_session'])) {
        $pdo->exec('ALTER TABLE stripe_payment_links DROP INDEX uq_stripe_payment_links_session');
        unset($linkIndexes['uq_stripe_payment_links_session']);
    }
    if (!isset($linkIndexes['idx_stripe_payment_links_session'])) {
        $pdo->exec('ALTER TABLE stripe_payment_links ADD KEY idx_stripe_payment_links_session (stripe_checkout_session_id)');
    }

    // Short, on-brand redirect slug (myclubhub.co.uk/p/<slug>) that forwards to the
    // long Stripe Checkout URL — the URL actually handed to a sponsor. A bundle's
    // several rows share one slug, so this is a plain index, not unique.
    $linkColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM stripe_payment_links') as $row) {
        $linkColumns[(string) $row['Field']] = true;
    }
    if (!isset($linkColumns['slug'])) {
        $pdo->exec("ALTER TABLE stripe_payment_links ADD COLUMN slug VARCHAR(24) DEFAULT NULL AFTER url");
    }
    if (!isset($linkColumns['public_expires_at'])) {
        $pdo->exec("ALTER TABLE stripe_payment_links ADD COLUMN public_expires_at DATETIME DEFAULT NULL AFTER expires_at");
    }
    if (!isset($linkIndexes['idx_stripe_payment_links_slug'])) {
        $pdo->exec('ALTER TABLE stripe_payment_links ADD KEY idx_stripe_payment_links_slug (slug)');
    }

    $txnIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM stripe_transactions') as $row) {
        $txnIndexes[(string) $row['Key_name']] = true;
    }
    if (isset($txnIndexes['uq_stripe_transactions_intent'])) {
        $pdo->exec('ALTER TABLE stripe_transactions DROP INDEX uq_stripe_transactions_intent');
        unset($txnIndexes['uq_stripe_transactions_intent']);
    }
    if (!isset($txnIndexes['idx_stripe_transactions_intent'])) {
        $pdo->exec('ALTER TABLE stripe_transactions ADD KEY idx_stripe_transactions_intent (stripe_payment_intent_id)');
    }
    if (!isset($txnIndexes['uq_stripe_transactions_link'])) {
        $pdo->exec('ALTER TABLE stripe_transactions ADD UNIQUE KEY uq_stripe_transactions_link (payment_link_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stripe_event_id VARCHAR(190) NOT NULL,
        type VARCHAR(100) NOT NULL,
        processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_webhook_events_event (stripe_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_refunds (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id INT UNSIGNED NOT NULL,
        stripe_refund_id VARCHAR(190) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        initiated_by BIGINT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_refunds_refund (stripe_refund_id),
        KEY idx_stripe_refunds_transaction (transaction_id),
        CONSTRAINT fk_stripe_refunds_transaction FOREIGN KEY (transaction_id) REFERENCES stripe_transactions (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

class StripeApiException extends RuntimeException
{
}

/**
 * Raw cURL client for the Stripe REST API, mirroring the cURL usage in
 * lib/google_calendar.php and lib/facebook_publisher.php rather than pulling
 * in the Composer-based Stripe SDK.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function stripe_request(string $method, string $path, array $params = []): array
{
    $secretKey = stripe_secret_key();
    if ($secretKey === '') {
        throw new StripeApiException('Stripe is not configured. Add a secret key in Settings > Payments.');
    }

    $method = strtoupper($method);
    $url = STRIPE_API_BASE . '/' . ltrim($path, '/');
    $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    if ($method === 'GET' && $body !== '') {
        $url .= '?' . $body;
        $body = '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $errorNumber = curl_errno($ch);
    $errorMessage = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errorNumber !== 0 || $response === false) {
        throw new StripeApiException('Could not reach Stripe: ' . $errorMessage);
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new StripeApiException('Stripe returned an unreadable response.');
    }

    if ($statusCode >= 400) {
        $message = (string) ($decoded['error']['message'] ?? 'Stripe request failed.');
        throw new StripeApiException($message);
    }

    return $decoded;
}

function stripe_amount_to_minor_units(float $amount, string $currency): int
{
    // GBP/USD/EUR-style two-decimal currencies only; the club only trades in GBP today.
    return (int) round($amount * 100);
}

function stripe_minor_units_to_amount(int $minorUnits, string $currency): float
{
    return round($minorUnits / 100, 2);
}

/**
 * The outstanding balance for an agreement, matching the epsilon tolerance
 * used by reportPaymentState() in reports.php.
 */
function stripe_agreement_outstanding_amount(array $agreement): float
{
    $due = (float) ($agreement['agreed_amount'] ?? 0);
    $paid = (float) ($agreement['total_paid'] ?? 0);
    $outstanding = round($due - $paid, 2);
    return $outstanding > 0.0001 ? $outstanding : 0.0;
}

function stripe_agreement_display_name(array $agreement): string
{
    $sponsor = trim((string) ($agreement['sponsor_name'] ?? 'Sponsor'));
    $package = trim((string) ($agreement['package_name'] ?? 'Sponsorship'));
    return $sponsor . ' — ' . $package;
}

/**
 * Scheme + host for building public-facing links (the /p/<slug> redirect).
 */
function stripe_public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'saltcoatsvictoria.co.uk';
    }
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * A short [A-Za-z0-9] slug for stripe_payment_links.slug, checked for collisions.
 */
function stripe_generate_payment_link_slug(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $len = strlen($alphabet);
    $check = $pdo->prepare('SELECT 1 FROM stripe_payment_links WHERE slug = :slug LIMIT 1');

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $bytes = random_bytes(11);
        $slug = '';
        for ($i = 0; $i < 11; $i++) {
            $slug .= $alphabet[ord($bytes[$i]) % $len];
        }
        $check->execute([':slug' => $slug]);
        if (!$check->fetchColumn()) {
            return $slug;
        }
    }

    return substr(bin2hex(random_bytes(8)), 0, 16);
}

/**
 * The URL to actually hand a sponsor: the short /p/<slug> redirect when the row
 * has a slug, otherwise the raw Stripe URL (older rows created before slugs).
 *
 * @param array<string, mixed> $link a stripe_payment_links row
 */
function stripe_payment_link_public_url(array $link): string
{
    $slug = trim((string) ($link['slug'] ?? ''));
    if ($slug !== '') {
        return stripe_public_base_url() . '/p/' . rawurlencode($slug);
    }
    return (string) ($link['url'] ?? '');
}

function stripe_payment_link_public_expires_at(array $link): int
{
    $slug = trim((string) ($link['slug'] ?? ''));
    $stripeExpiresAt = trim((string) ($link['expires_at'] ?? ''));
    if ($slug === '') {
        $timestamp = $stripeExpiresAt !== '' ? strtotime($stripeExpiresAt) : false;
        return $timestamp !== false ? $timestamp : time();
    }

    $publicExpiresAt = trim((string) ($link['public_expires_at'] ?? ''));
    if ($publicExpiresAt !== '') {
        $timestamp = strtotime($publicExpiresAt);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    $createdAt = trim((string) ($link['created_at'] ?? ''));
    if ($createdAt !== '') {
        $timestamp = strtotime($createdAt . ' +' . stripe_public_link_expiry_days() . ' days');
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    $timestamp = $stripeExpiresAt !== '' ? strtotime($stripeExpiresAt) : false;
    return $timestamp !== false ? $timestamp : time();
}

function stripe_create_checkout_session_payload(array $agreement, float $amount, string $successUrl, string $cancelUrl): array
{
    $currency = stripe_default_currency();
    $expiresAt = time() + (stripe_link_expiry_hours() * 3600);

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string) $agreement['id'],
        'expires_at' => $expiresAt,
        'line_items' => [
            [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => stripe_amount_to_minor_units($amount, $currency),
                    'product_data' => [
                        'name' => stripe_agreement_display_name($agreement),
                        'description' => 'Sponsorship payment — Saltcoats Victoria FC',
                    ],
                ],
            ],
        ],
        'metadata' => [
            'agreement_id' => (string) $agreement['id'],
            'sponsor_id' => (string) ($agreement['sponsor_id'] ?? ''),
        ],
        'payment_intent_data' => [
            'metadata' => [
                'agreement_id' => (string) $agreement['id'],
            ],
        ],
    ];

    $contactEmail = trim((string) ($agreement['sponsor_contact_email'] ?? ''));
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $params['customer_email'] = $contactEmail;
    }

    return [$params, $currency, $expiresAt];
}

function stripe_refresh_payment_link_checkout_session(PDO $pdo, array $link): array
{
    ensureStripeSchema($pdo);

    if ((string) ($link['status'] ?? '') === 'complete') {
        return $link;
    }

    $links = [$link];
    $slug = trim((string) ($link['slug'] ?? ''));
    if ($slug !== '') {
        $stmt = $pdo->prepare("SELECT * FROM stripe_payment_links WHERE slug = :slug AND status <> 'complete' ORDER BY id");
        $stmt->execute([':slug' => $slug]);
        $slugLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($slugLinks) {
            $links = $slugLinks;
        }
    }

    $chargeable = [];
    foreach ($links as $candidate) {
        $agreement = getSponsorshipAgreement($pdo, (int) ($candidate['agreement_id'] ?? 0));
        if (!$agreement) {
            throw new RuntimeException('Agreement not found.');
        }
        $amount = stripe_agreement_outstanding_amount($agreement);
        if ($amount <= 0) {
            $pdo->prepare("UPDATE stripe_payment_links SET status = 'complete' WHERE id = :id")
                ->execute([':id' => (int) $candidate['id']]);
            continue;
        }
        $chargeable[] = ['link' => $candidate, 'agreement' => $agreement, 'amount' => $amount];
    }

    if (!$chargeable) {
        $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
        $stmt->execute([':id' => (int) $link['id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: $link;
    }

    $firstAgreement = $chargeable[0]['agreement'];
    $baseUrl = stripe_public_base_url() . '/sponsorship_agreement.php?id=' . (int) $firstAgreement['id'];
    $successUrl = $baseUrl . '&stripe=success&session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $baseUrl . '&stripe=cancelled';

    if (count($chargeable) === 1) {
        [$params, $currency, $expiresAt] = stripe_create_checkout_session_payload($firstAgreement, (float) $chargeable[0]['amount'], $successUrl, $cancelUrl);
    } else {
        $currency = stripe_default_currency();
        $expiresAt = time() + (stripe_link_expiry_hours() * 3600);
        $lineItems = [];
        $agreementIds = [];
        $contactEmail = '';
        foreach ($chargeable as $item) {
            $agreement = $item['agreement'];
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => stripe_amount_to_minor_units((float) $item['amount'], $currency),
                    'product_data' => [
                        'name' => stripe_agreement_display_name($agreement),
                        'description' => 'Sponsorship payment — Saltcoats Victoria FC',
                    ],
                ],
            ];
            $agreementIds[] = (int) $agreement['id'];
            if ($contactEmail === '') {
                $candidateEmail = trim((string) ($agreement['sponsor_contact_email'] ?? ''));
                if ($candidateEmail !== '' && filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                    $contactEmail = $candidateEmail;
                }
            }
        }
        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expiresAt,
            'line_items' => $lineItems,
            'metadata' => [
                'agreement_ids' => implode(',', $agreementIds),
                'sponsor_id' => (string) ($firstAgreement['sponsor_id'] ?? ''),
            ],
        ];
        if ($contactEmail !== '') {
            $params['customer_email'] = $contactEmail;
        }
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);

    $update = $pdo->prepare("UPDATE stripe_payment_links
        SET stripe_checkout_session_id = :session_id,
            stripe_payment_intent_id = :payment_intent_id,
            amount = :amount,
            currency = :currency,
            url = :url,
            status = 'open',
            expires_at = :expires_at
        WHERE id = :id");
    foreach ($chargeable as $item) {
        $update
            ->execute([
                ':session_id' => (string) $session['id'],
                ':payment_intent_id' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                ':amount' => (float) $item['amount'],
                ':currency' => $currency,
                ':url' => (string) $session['url'],
                ':expires_at' => date('Y-m-d H:i:s', $expiresAt),
                ':id' => (int) $item['link']['id'],
            ]);
    }

    $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
    $stmt->execute([':id' => (int) $link['id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: $link;
}

/**
 * Create a Stripe Checkout Session for an agreement's outstanding balance
 * and persist it in stripe_payment_links. The amount is always computed
 * server-side from the agreement record — never trust a client-submitted
 * amount for this.
 *
 * @param array<string, mixed> $agreement
 * @return array<string, mixed> the stripe_payment_links row
 */
function stripe_create_checkout_session_for_agreement(PDO $pdo, array $agreement, string $successUrl, string $cancelUrl, ?int $createdByUserId = null): array
{
    ensureStripeSchema($pdo);

    if ((int) ($agreement['is_complimentary'] ?? 0) === 1) {
        throw new RuntimeException('Complimentary agreements cannot be charged.');
    }

    $amount = stripe_agreement_outstanding_amount($agreement);
    if ($amount <= 0) {
        throw new RuntimeException('This agreement has no outstanding balance.');
    }

    [$params, $currency, $expiresAt] = stripe_create_checkout_session_payload($agreement, $amount, $successUrl, $cancelUrl);

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $publicExpiresAt = time() + (stripe_public_link_expiry_days() * 86400);

    $stmt = $pdo->prepare('INSERT INTO stripe_payment_links
        (agreement_id, stripe_checkout_session_id, stripe_payment_intent_id, amount, currency, url, status, created_by, created_at, expires_at, public_expires_at)
        VALUES (:agreement_id, :session_id, :payment_intent_id, :amount, :currency, :url, :status, :created_by, NOW(), :expires_at, :public_expires_at)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':session_id' => (string) $session['id'],
        ':payment_intent_id' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
        ':amount' => $amount,
        ':currency' => $currency,
        ':url' => (string) $session['url'],
        ':status' => 'open',
        ':created_by' => $createdByUserId,
        ':expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ':public_expires_at' => date('Y-m-d H:i:s', $publicExpiresAt),
    ]);

    $linkId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE stripe_payment_links SET slug = :slug WHERE id = :id')
        ->execute([':slug' => stripe_generate_payment_link_slug($pdo), ':id' => $linkId]);

    $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
    $stmt->execute([':id' => $linkId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Create ONE Stripe Checkout Session covering several agreements at once (a
 * "sponsorship bundle" — a board + several players + several MOTMs, etc., all for one
 * sponsor) and persist one stripe_payment_links row per chargeable agreement, all
 * sharing that session's id/url/status/expires_at but each keeping its own split
 * amount. Each member agreement's own detail page needs zero changes — it just reads
 * its own stripe_payment_links row exactly like it always has for a standalone
 * agreement. See stripe_handle_checkout_session_completed() for how one confirmed
 * payment gets split back across every member.
 *
 * Complimentary agreements and agreements with no outstanding balance are silently
 * skipped rather than erroring, so generating a bundle link after some members are
 * already paid just charges for what's left.
 *
 * @param list<array<string, mixed>> $agreements the bundle's member agreement rows,
 *   e.g. getSponsorshipAgreements(['bundle_id' => $bundleId])
 * @return array{session_id: string, session_url: string, total_amount: float, agreement_ids: list<int>}
 */
function stripe_create_checkout_session_for_bundle(PDO $pdo, array $agreements, string $successUrl, string $cancelUrl, ?int $createdByUserId = null): array
{
    ensureStripeSchema($pdo);

    $chargeable = [];
    foreach ($agreements as $agreement) {
        if ((int) ($agreement['is_complimentary'] ?? 0) === 1) {
            continue;
        }
        $amount = stripe_agreement_outstanding_amount($agreement);
        if ($amount <= 0) {
            continue;
        }
        $chargeable[] = ['agreement' => $agreement, 'amount' => $amount];
    }

    if (!$chargeable) {
        throw new RuntimeException('This bundle has no outstanding balance.');
    }

    $currency = stripe_default_currency();
    $expiresAt = time() + (stripe_link_expiry_hours() * 3600);

    $lineItems = [];
    $agreementIds = [];
    $totalAmount = 0.0;
    $contactEmail = '';
    foreach ($chargeable as $item) {
        $agreement = $item['agreement'];
        $amount = $item['amount'];
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units($amount, $currency),
                'product_data' => [
                    'name' => stripe_agreement_display_name($agreement),
                    'description' => 'Sponsorship payment — Saltcoats Victoria FC',
                ],
            ],
        ];
        $agreementIds[] = (int) $agreement['id'];
        $totalAmount += $amount;
        if ($contactEmail === '') {
            $candidate = trim((string) ($agreement['sponsor_contact_email'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $contactEmail = $candidate;
            }
        }
    }

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'expires_at' => $expiresAt,
        'line_items' => $lineItems,
        'metadata' => [
            // Traceability only (visible in the Stripe dashboard) — the webhook looks
            // rows up by session id, not by parsing this.
            'agreement_ids' => implode(',', $agreementIds),
            'sponsor_id' => (string) ($chargeable[0]['agreement']['sponsor_id'] ?? ''),
        ],
    ];
    if ($contactEmail !== '') {
        $params['customer_email'] = $contactEmail;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) $session['id'];
    $paymentIntentId = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
    $url = (string) $session['url'];
    $publicExpiresAt = time() + (stripe_public_link_expiry_days() * 86400);

    $insert = $pdo->prepare('INSERT INTO stripe_payment_links
        (agreement_id, stripe_checkout_session_id, stripe_payment_intent_id, amount, currency, url, status, created_by, created_at, expires_at, public_expires_at)
        VALUES (:agreement_id, :session_id, :payment_intent_id, :amount, :currency, :url, :status, :created_by, NOW(), :expires_at, :public_expires_at)');
    foreach ($chargeable as $item) {
        $insert->execute([
            ':agreement_id' => (int) $item['agreement']['id'],
            ':session_id' => $sessionId,
            ':payment_intent_id' => $paymentIntentId,
            ':amount' => $item['amount'],
            ':currency' => $currency,
            ':url' => $url,
            ':status' => 'open',
            ':created_by' => $createdByUserId,
            ':expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ':public_expires_at' => date('Y-m-d H:i:s', $publicExpiresAt),
        ]);
    }

    // One slug shared by every row of this session — the sponsor gets one link.
    $bundleSlug = stripe_generate_payment_link_slug($pdo);
    $pdo->prepare('UPDATE stripe_payment_links SET slug = :slug WHERE stripe_checkout_session_id = :session_id')
        ->execute([':slug' => $bundleSlug, ':session_id' => $sessionId]);

    return [
        'session_id' => $sessionId,
        'session_url' => $url,
        'short_url' => stripe_public_base_url() . '/p/' . $bundleSlug,
        'total_amount' => round($totalAmount, 2),
        'agreement_ids' => $agreementIds,
    ];
}

/**
 * Expires an open Checkout Session on Stripe's side (Stripe has no "delete" for these —
 * expiring is the correct way to invalidate a link so it can no longer be paid) and marks
 * the local row expired to match, so a fresh link can be generated in its place.
 *
 * @param array<string, mixed> $link a stripe_payment_links row
 */
function stripe_expire_payment_link(PDO $pdo, array $link): void
{
    if ((string) $link['status'] === 'complete') {
        return;
    }

    if ((string) $link['status'] === 'open') {
        try {
            stripe_request('POST', '/checkout/sessions/' . rawurlencode((string) $link['stripe_checkout_session_id']) . '/expire');
        } catch (StripeApiException $e) {
            // Already expired/completed on Stripe's side (e.g. the sponsor paid or it lapsed
            // moments ago) — fall through and reconcile our local status regardless.
        }
    }

    // Stripe expires the whole Checkout Session, not one line item — a bundle's combined
    // link is several local rows sharing that one session, so every sibling (not just the
    // row that was clicked) needs to flip to expired, or the others would be stuck showing
    // "open" locally for a link that no longer actually works.
    $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired', public_expires_at = NOW() WHERE stripe_checkout_session_id = :session_id AND status = 'open'")
        ->execute([':session_id' => (string) $link['stripe_checkout_session_id']]);
    $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired', public_expires_at = NOW() WHERE id = :id")
        ->execute([':id' => (int) $link['id']]);
    $slug = trim((string) ($link['slug'] ?? ''));
    if ($slug !== '') {
        $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired', public_expires_at = NOW() WHERE slug = :slug AND status <> 'complete'")
            ->execute([':slug' => $slug]);
    }
}

/**
 * Human-readable "what is this for" lines shared by the copyable message and the email —
 * e.g. which fixture, which player, which season — built from whatever the package scope
 * actually applies to.
 *
 * @param array<string, mixed> $agreement
 * @return list<array{label: string, value: string}>
 */
function stripe_agreement_context_lines(array $agreement): array
{
    $lines = [];
    $lines[] = ['label' => 'Sponsor', 'value' => (string) ($agreement['sponsor_name'] ?? '')];
    $lines[] = ['label' => 'Package', 'value' => (string) ($agreement['package_name'] ?? '')];

    $scope = (string) ($agreement['package_scope'] ?? '');
    if ($scope === 'match' && !empty($agreement['fixture_opponent'])) {
        $matchLabel = 'vs ' . (string) $agreement['fixture_opponent'];
        if (!empty($agreement['fixture_date'])) {
            $matchLabel .= ' (' . date('d/m/Y', strtotime((string) $agreement['fixture_date'])) . ')';
        }
        $lines[] = ['label' => 'Match', 'value' => $matchLabel];
    } elseif ($scope === 'player' && !empty($agreement['player_name'])) {
        $lines[] = ['label' => 'Player', 'value' => (string) $agreement['player_name']];
    } elseif ($scope === 'team' && !empty($agreement['team_name'])) {
        $lines[] = ['label' => 'Team', 'value' => (string) $agreement['team_name']];
    }

    if (!empty($agreement['season_name'])) {
        $lines[] = ['label' => 'Season', 'value' => (string) $agreement['season_name']];
    }

    return $lines;
}

/**
 * Plain-text payment message — used both as the "copy message" button's clipboard content
 * and as the plain-text part of the email, so the two always say exactly the same thing.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_build_payment_message(string $sponsorName, array $agreement, array $link): string
{
    $amount = gbp((float) $link['amount']);
    $expires = date('d/m/Y H:i', stripe_payment_link_public_expires_at($link));

    $out = [];
    $out[] = 'Saltcoats Victoria FC — Sponsorship Payment';
    $out[] = '';
    $out[] = 'Hi ' . ($sponsorName !== '' ? $sponsorName : 'there') . ',';
    $out[] = '';
    $out[] = 'Thanks for your support! Here are the details of your sponsorship:';
    $out[] = '';
    foreach (stripe_agreement_context_lines($agreement) as $line) {
        $out[] = $line['label'] . ': ' . $line['value'];
    }
    $out[] = 'Amount due: ' . $amount;
    $out[] = '';
    $out[] = 'Pay securely here: ' . stripe_payment_link_public_url($link);
    $out[] = '';
    $out[] = 'This link expires on ' . $expires . '.';
    $out[] = '';
    $out[] = 'Thanks again for your support,';
    $out[] = 'Saltcoats Victoria FC';

    return implode("\n", $out);
}

/**
 * HTML email body with a proper "Pay now" button instead of a bare link, plus the same
 * sponsorship context lines as the plain-text message. Inline-styled throughout, since email
 * clients don't reliably support external/embedded stylesheets.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_build_payment_email_html(string $sponsorName, array $agreement, array $link): string
{
    $amount = gbp((float) $link['amount']);
    $expires = date('d/m/Y H:i', stripe_payment_link_public_expires_at($link));
    $url = stripe_payment_link_public_url($link);
    $greetingName = $sponsorName !== '' ? h($sponsorName) : 'there';

    $rows = '';
    foreach (stripe_agreement_context_lines($agreement) as $line) {
        $rows .= '<tr>'
            . '<td style="padding:6px 16px 6px 0;color:#6a2036;font-weight:600;white-space:nowrap;font-size:14px;">' . h($line['label']) . '</td>'
            . '<td style="padding:6px 0;color:#1f1a1d;font-size:14px;">' . h($line['value']) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6ecde;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:520px;margin:0 auto;padding:24px 16px;">'
        . '<div style="background:#ffffff;border-radius:12px;padding:32px;">'
        . '<h1 style="margin:0 0 16px;font-size:20px;color:#4b0818;">Saltcoats Victoria FC</h1>'
        . '<p style="margin:0 0 16px;color:#1f1a1d;font-size:15px;">Hi ' . $greetingName . ',</p>'
        . '<p style="margin:0 0 20px;color:#1f1a1d;font-size:15px;">Thanks for your support! Here are the details of your sponsorship:</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">' . $rows . '</table>'
        . '<p style="margin:0 0 8px;color:#1f1a1d;font-weight:700;font-size:18px;">Amount due: ' . h($amount) . '</p>'
        . '<div style="text-align:center;margin:28px 0;">'
        . '<a href="' . h($url) . '" style="background:#6a2036;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 32px;border-radius:8px;display:inline-block;font-size:16px;">Pay ' . h($amount) . ' now</a>'
        . '</div>'
        . '<p style="margin:0;color:#6b6b6b;font-size:13px;">This link expires on ' . h($expires) . '. If the button doesn\'t work, copy and paste this link into your browser:<br>'
        . '<a href="' . h($url) . '" style="color:#6a2036;word-break:break-all;">' . h($url) . '</a></p>'
        . '</div>'
        . '<p style="text-align:center;color:#9a8f93;font-size:12px;margin-top:16px;">Saltcoats Victoria FC</p>'
        . '</div>'
        . '</body></html>';
}

/**
 * Email a payment link to a sponsor through the shared SMTP mailer.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_send_payment_link_email(string $toEmail, string $sponsorName, array $agreement, array $link): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $amount = gbp((float) $link['amount']);
    $subject = 'Sponsorship payment request — ' . $amount;
    $textBody = stripe_build_payment_message($sponsorName, $agreement, $link);
    $htmlBody = stripe_build_payment_email_html($sponsorName, $agreement, $link);

    return hub_send_mail($toEmail, $subject, $htmlBody, true);
}

/**
 * Dispatches a Stripe payment into whichever underlying payment table the
 * agreement actually rolls up through (see getSponsorshipAgreements() in
 * lib/sponsorship_catalog.php), so total_paid/status stay correct everywhere
 * without changing that rollup query.
 */
function stripe_record_agreement_payment(PDO $pdo, array $agreement, float $amount, string $note): void
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        try {
            addMatchPayment($pdo, $legacyId, $amount, 'Stripe', $note);
        } catch (Throwable $e) {
            error_log('[stripe] Payment succeeded in Stripe but could not be recorded against match_sponsorship ' . $legacyId . ': ' . $e->getMessage());
        }
        return;
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $seasonStmt = $pdo->prepare('SELECT season_id FROM sponsorships WHERE id = :id');
        $seasonStmt->execute([':id' => $legacyId]);
        $seasonId = $seasonStmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
            VALUES (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)');
        $stmt->execute([
            ':sponsorship_id' => $legacyId,
            ':amount' => $amount,
            ':method' => 'Stripe',
            ':note' => $note,
            ':season_id' => $seasonId !== false ? $seasonId : null,
        ]);
        recomputePaidFlag($pdo, $legacyId);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO sponsorship_agreement_payments (agreement_id, amount, paid_at, method, note)
        VALUES (:agreement_id, :amount, CURDATE(), :method, :note)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':amount' => $amount,
        ':method' => 'Stripe',
        ':note' => $note,
    ]);
}

/**
 * Mirror image of stripe_record_agreement_payment() for refunds: writes a
 * negative adjustment row into whichever table the original payment used,
 * preserving history instead of deleting the original payment.
 */
function stripe_reverse_agreement_payment(PDO $pdo, array $agreement, float $amount, string $note): void
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        $row = getMatchSponsorshipById($pdo, $legacyId);
        $seasonId = $row['season_id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO match_sponsorship_payments (match_sponsorship_id, season_id, amount, paid_at, method, note)
            VALUES (:match_sponsorship_id, :season_id, :amount, NOW(), :method, :note)');
        $stmt->execute([
            ':match_sponsorship_id' => $legacyId,
            ':season_id' => $seasonId,
            ':amount' => -$amount,
            ':method' => 'Stripe refund',
            ':note' => $note,
        ]);
        recomputeMatchPaidFlag($pdo, $legacyId);
        return;
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $seasonStmt = $pdo->prepare('SELECT season_id FROM sponsorships WHERE id = :id');
        $seasonStmt->execute([':id' => $legacyId]);
        $seasonId = $seasonStmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
            VALUES (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)');
        $stmt->execute([
            ':sponsorship_id' => $legacyId,
            ':amount' => -$amount,
            ':method' => 'Stripe refund',
            ':note' => $note,
            ':season_id' => $seasonId !== false ? $seasonId : null,
        ]);
        recomputePaidFlag($pdo, $legacyId);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO sponsorship_agreement_payments (agreement_id, amount, paid_at, method, note)
        VALUES (:agreement_id, :amount, CURDATE(), :method, :note)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':amount' => -$amount,
        ':method' => 'Stripe refund',
        ':note' => $note,
    ]);
}

/**
 * Verify Stripe's webhook signature by hand (documented HMAC-SHA256 scheme),
 * avoiding a dependency on the Stripe SDK's webhook helper.
 */
function stripe_verify_webhook_signature(string $payload, string $sigHeader, string $secret): bool
{
    if ($secret === '' || $sigHeader === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2) {
            continue;
        }
        [$key, $value] = $pair;
        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === []) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > STRIPE_WEBHOOK_TOLERANCE_SECONDS) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $event decoded Stripe event payload
 */
function stripe_handle_webhook_event(PDO $pdo, array $event): void
{
    ensureStripeSchema($pdo);

    $type = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    $kind = (string) ($object['metadata']['kind'] ?? '');

    if ($kind === 'season_ticket' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        // Season ticket self-serve checkouts are handled by lib/season_ticket_stripe.php
        // (separate order/holder model from sponsorship_agreements) rather than below.
        require_once __DIR__ . '/season_ticket_stripe.php';
        if ($type === 'checkout.session.completed') {
            season_ticket_stripe_handle_checkout_completed($pdo, $object);
        } else {
            season_ticket_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'member_sponsorship') {
        // Member self-serve sponsorships (lib/member_sponsorship.php) never create
        // an agreement until Stripe confirms payment, so there's nothing to do here
        // for checkout.session.expired — no agreement was ever created to clean up.
        require_once __DIR__ . '/member_sponsorship.php';
        if ($type === 'checkout.session.completed') {
            member_sponsorship_stripe_handle_checkout_completed($pdo, $object);
        }
        return;
    }

    if ($kind === 'match_ticket' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        require_once __DIR__ . '/match_tickets.php';
        if ($type === 'checkout.session.completed') {
            match_ticket_stripe_handle_checkout_completed($pdo, $object);
        } else {
            match_ticket_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'hidden_team' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        require_once __DIR__ . '/hidden_team_stripe.php';
        if ($type === 'checkout.session.completed') {
            hidden_team_stripe_handle_checkout_completed($pdo, $object);
        } else {
            hidden_team_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'player_sponsorship_shop' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        // Public multi-item player kit sponsorship shop (hub/playersponsors*.php) —
        // pre-creates a pending order+items row at checkout, unlike member_sponsorship
        // above, so both completed and expired need handling here.
        require_once __DIR__ . '/player_sponsorship_shop.php';
        if ($type === 'checkout.session.completed') {
            player_sponsorship_shop_stripe_handle_checkout_completed($pdo, $object);
        } else {
            player_sponsorship_shop_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'shop' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        // Club Shop (/shop/*, lib/shop.php) — pre-creates a pending shop_orders
        // row at checkout, so both completed and expired are handled here.
        require_once __DIR__ . '/shop.php';
        if ($type === 'checkout.session.completed') {
            shop_stripe_handle_checkout_completed($pdo, $object);
        } else {
            shop_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($type === 'checkout.session.completed') {
        stripe_handle_checkout_session_completed($pdo, $object);
    } elseif ($type === 'checkout.session.expired') {
        stripe_handle_checkout_session_expired($pdo, $object);
    } elseif ($type === 'charge.refunded') {
        stripe_handle_charge_refunded($pdo, $object);
    }
}

/**
 * @param array<string, mixed> $session
 */
function stripe_handle_checkout_session_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    // A bundle's combined link produces several rows sharing this one session id (one
    // per member agreement, each with its own split amount) — a plain single-agreement
    // link just has one. Either way, loop every row so every agreement gets credited.
    $linkStmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE stripe_checkout_session_id = :session_id');
    $linkStmt->execute([':session_id' => $sessionId]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$links) {
        error_log('[stripe] checkout.session.completed for unknown session ' . $sessionId);
        return;
    }

    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $pdo->prepare('UPDATE stripe_payment_links SET status = :status, stripe_payment_intent_id = :payment_intent_id WHERE stripe_checkout_session_id = :session_id')
        ->execute([':status' => 'complete', ':payment_intent_id' => $paymentIntentId ?: null, ':session_id' => $sessionId]);

    if ($paymentIntentId === '') {
        return;
    }

    foreach ($links as $link) {
        // Idempotency is scoped per payment_link_id (one row = one agreement's slice of
        // this session), not per payment_intent_id — a session-wide check would find the
        // first sibling's just-inserted row and wrongly skip recording every other one.
        $existing = $pdo->prepare('SELECT id FROM stripe_transactions WHERE payment_link_id = :payment_link_id');
        $existing->execute([':payment_link_id' => (int) $link['id']]);
        if ($existing->fetchColumn()) {
            continue; // Already recorded (webhook retry).
        }

        $agreement = getSponsorshipAgreement($pdo, (int) $link['agreement_id']);
        if (!$agreement) {
            error_log('[stripe] Paid session ' . $sessionId . ' references missing agreement ' . $link['agreement_id']);
            continue;
        }

        $amount = (float) $link['amount'];
        $currency = (string) $link['currency'];

        $pdo->prepare('INSERT INTO stripe_transactions
            (agreement_id, payment_link_id, stripe_payment_intent_id, stripe_checkout_session_id, amount, currency, status, raw_payload)
            VALUES (:agreement_id, :payment_link_id, :payment_intent_id, :session_id, :amount, :currency, :status, :raw_payload)')
            ->execute([
                ':agreement_id' => (int) $agreement['id'],
                ':payment_link_id' => (int) $link['id'],
                ':payment_intent_id' => $paymentIntentId,
                ':session_id' => $sessionId,
                ':amount' => $amount,
                ':currency' => $currency,
                ':status' => 'succeeded',
                ':raw_payload' => json_encode($session),
            ]);

        stripe_record_agreement_payment($pdo, $agreement, $amount, 'Stripe payment (session ' . $sessionId . ')');
        stripe_send_payment_notification(
            $pdo,
            'Sponsorship',
            (string) ($agreement['sponsor_name'] ?? ''),
            (string) ($agreement['sponsor_contact_email'] ?? ''),
            $amount,
            (string) ($agreement['package_name'] ?? 'Sponsorship') . ' agreement #' . (int) $agreement['id'],
            stripe_public_base_url() . '/sponsorship_agreement.php?id=' . (int) $agreement['id']
        );
    }
}

/**
 * @param array<string, mixed> $session
 */
function stripe_handle_checkout_session_expired(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }

    $linkStmt = $pdo->prepare("SELECT * FROM stripe_payment_links WHERE stripe_checkout_session_id = :session_id AND status = 'open'");
    $linkStmt->execute([':session_id' => $sessionId]);
    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        return;
    }

    $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired' WHERE id = :id")->execute([':id' => $link['id']]);
}

/**
 * @param array<string, mixed> $charge
 */
function stripe_handle_charge_refunded(PDO $pdo, array $charge): void
{
    $paymentIntentId = (string) ($charge['payment_intent'] ?? '');
    if ($paymentIntentId === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM stripe_transactions WHERE stripe_payment_intent_id = :id');
    $stmt->execute([':id' => $paymentIntentId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$transactions) {
        return;
    }
    if (count($transactions) > 1) {
        // A bundle's combined payment can back several transactions off one payment
        // intent — but Stripe's refund/charge event carries no per-line-item breakdown,
        // so there's no way to know which agreement(s) this refund actually belongs to.
        // Refuse to guess; refund each bundle member individually from its own agreement
        // page (stripe_refund.php) instead, which already reconciles correctly per-row.
        error_log('[stripe] charge.refunded for payment_intent ' . $paymentIntentId . ' backs ' . count($transactions) . ' transactions (bundle) — skipping automatic reconciliation, refund each agreement individually.');
        return;
    }
    $transaction = $transactions[0];

    $newRefundedTotal = stripe_minor_units_to_amount((int) ($charge['amount_refunded'] ?? 0), (string) $transaction['currency']);
    $delta = round($newRefundedTotal - (float) $transaction['refunded_amount'], 2);
    if ($delta <= 0.0001) {
        return; // Already reconciled (webhook retry, or refunded via our own stripe_refund.php flow already updated this row).
    }

    $refundId = 'webhook_' . (string) ($charge['id'] ?? uniqid('ch_', true));
    $refunds = $charge['refunds']['data'] ?? [];
    if (is_array($refunds) && $refunds !== []) {
        $latest = end($refunds);
        if (is_array($latest) && isset($latest['id'])) {
            $refundId = (string) $latest['id'];
        }
    }

    $agreement = getSponsorshipAgreement($pdo, (int) $transaction['agreement_id']);
    if (!$agreement) {
        return;
    }

    try {
        $pdo->prepare('INSERT INTO stripe_refunds (transaction_id, stripe_refund_id, amount, reason, initiated_by)
            VALUES (:transaction_id, :refund_id, :amount, :reason, NULL)')
            ->execute([
                ':transaction_id' => (int) $transaction['id'],
                ':refund_id' => $refundId,
                ':amount' => $delta,
                ':reason' => 'Refunded directly in Stripe',
            ]);
    } catch (Throwable $e) {
        // Unique key collision means our own stripe_refund.php already recorded this refund; nothing further to do.
        return;
    }

    $status = $newRefundedTotal >= (float) $transaction['amount'] - 0.0001 ? 'refunded' : 'partially_refunded';
    $pdo->prepare('UPDATE stripe_transactions SET refunded_amount = :refunded_amount, status = :status WHERE id = :id')
        ->execute([':refunded_amount' => $newRefundedTotal, ':status' => $status, ':id' => $transaction['id']]);

    stripe_reverse_agreement_payment($pdo, $agreement, $delta, 'Stripe refund (' . $refundId . ')');
}

/**
 * Initiate a refund from within the Hub (full or partial).
 *
 * @param array<string, mixed> $transaction a stripe_transactions row
 */
function stripe_create_refund(PDO $pdo, array $transaction, float $amount, ?string $reason, ?int $initiatedByUserId): array
{
    ensureStripeSchema($pdo);

    $remaining = round((float) $transaction['amount'] - (float) $transaction['refunded_amount'], 2);
    if ($amount <= 0 || $amount > $remaining + 0.0001) {
        throw new RuntimeException('Refund amount must be between £0.01 and the remaining paid amount (' . gbp($remaining) . ').');
    }

    $currency = (string) $transaction['currency'];
    $response = stripe_request('POST', '/refunds', [
        'payment_intent' => (string) $transaction['stripe_payment_intent_id'],
        'amount' => stripe_amount_to_minor_units($amount, $currency),
    ]);

    $refundId = (string) ($response['id'] ?? '');
    if ($refundId === '') {
        throw new RuntimeException('Stripe did not return a refund id.');
    }

    $pdo->prepare('INSERT INTO stripe_refunds (transaction_id, stripe_refund_id, amount, reason, initiated_by)
        VALUES (:transaction_id, :refund_id, :amount, :reason, :initiated_by)')
        ->execute([
            ':transaction_id' => (int) $transaction['id'],
            ':refund_id' => $refundId,
            ':amount' => $amount,
            ':reason' => $reason !== null && $reason !== '' ? $reason : null,
            ':initiated_by' => $initiatedByUserId,
        ]);

    $newRefundedTotal = round((float) $transaction['refunded_amount'] + $amount, 2);
    $status = $newRefundedTotal >= (float) $transaction['amount'] - 0.0001 ? 'refunded' : 'partially_refunded';
    $pdo->prepare('UPDATE stripe_transactions SET refunded_amount = :refunded_amount, status = :status WHERE id = :id')
        ->execute([':refunded_amount' => $newRefundedTotal, ':status' => $status, ':id' => $transaction['id']]);

    $agreement = getSponsorshipAgreement($pdo, (int) $transaction['agreement_id']);
    if ($agreement) {
        stripe_reverse_agreement_payment($pdo, $agreement, $amount, 'Stripe refund (' . $refundId . ')' . ($reason ? ' — ' . $reason : ''));
    }

    return $response;
}

function stripe_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<int>
 */
function stripe_notification_env_account_ids(array $env): array
{
    $ids = [];
    foreach (preg_split('/[,;\s]+/', (string) ($env['ORDER_NOTIFICATION_ACCOUNT_IDS'] ?? '')) ?: [] as $id) {
        $id = trim((string) $id);
        if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
            $ids[(int) $id] = (int) $id;
        }
    }

    return array_values($ids);
}

/**
 * @return list<string>
 */
function stripe_notification_env_emails(array $env, bool $includeLegacy = true): array
{
    $emails = [];
    $keys = $includeLegacy
        ? ['STRIPE_NOTIFICATION_EMAILS', 'PAYMENT_NOTIFICATION_EMAILS', 'ORDER_NOTIFICATION_EMAILS']
        : ['ORDER_NOTIFICATION_EMAILS'];
    foreach ($keys as $key) {
        foreach (preg_split('/[,;\s]+/', (string) ($env[$key] ?? '')) ?: [] as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }
    }

    return array_values($emails);
}

function stripe_notification_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_notification_recipients (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        recipient_type VARCHAR(20) NOT NULL,
        account_id INT UNSIGNED NULL,
        email VARCHAR(190) NULL,
        email_normalized VARCHAR(190) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_payment_notification_account (account_id),
        UNIQUE KEY uq_payment_notification_email (email_normalized),
        KEY idx_payment_notification_type (recipient_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function stripe_notification_seed_from_env(PDO $pdo, array $env, ?int $createdBy = null): void
{
    try {
        stripe_notification_ensure_schema($pdo);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_recipients')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $accountIds = stripe_notification_env_account_ids($env);
        $emails = stripe_notification_env_emails($env);
        if ($accountIds === [] && $emails === []) {
            return;
        }

        stripe_notification_save_lists($pdo, $accountIds, $emails, $createdBy);
    } catch (Throwable $e) {
    }
}

/**
 * @return list<int>
 */
function stripe_notification_account_ids(PDO $pdo): array
{
    try {
        stripe_notification_ensure_schema($pdo);
        $stmt = $pdo->query("SELECT account_id FROM payment_notification_recipients WHERE recipient_type = 'account' AND account_id IS NOT NULL ORDER BY id");
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<string>
 */
function stripe_notification_manual_emails(PDO $pdo): array
{
    try {
        stripe_notification_ensure_schema($pdo);
        $stmt = $pdo->query("SELECT email FROM payment_notification_recipients WHERE recipient_type = 'email' AND email IS NOT NULL AND TRIM(email) <> '' ORDER BY email");
        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[strtolower($email)] = $email;
            }
        }

        return array_values($emails);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int> $accountIds
 * @param list<string> $manualEmails
 */
function stripe_notification_save_lists(PDO $pdo, array $accountIds, array $manualEmails, ?int $createdBy = null): bool
{
    $cleanIds = [];
    foreach ($accountIds as $accountId) {
        $accountId = (int) $accountId;
        if ($accountId > 0) {
            $cleanIds[$accountId] = $accountId;
        }
    }

    $cleanEmails = [];
    foreach ($manualEmails as $email) {
        $email = trim((string) $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $cleanEmails[strtolower($email)] = $email;
        }
    }

    try {
        stripe_notification_ensure_schema($pdo);
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM payment_notification_recipients');

        $accountStmt = $pdo->prepare("INSERT INTO payment_notification_recipients (recipient_type, account_id, created_by) VALUES ('account', :account_id, :created_by)");
        foreach ($cleanIds as $accountId) {
            $accountStmt->execute([
                ':account_id' => $accountId,
                ':created_by' => $createdBy,
            ]);
        }

        $emailStmt = $pdo->prepare("INSERT INTO payment_notification_recipients (recipient_type, email, email_normalized, created_by) VALUES ('email', :email, :email_normalized, :created_by)");
        foreach ($cleanEmails as $normalized => $email) {
            $emailStmt->execute([
                ':email' => $email,
                ':email_normalized' => $normalized,
                ':created_by' => $createdBy,
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}

/**
 * @return list<string>
 */
function stripe_notification_recipients(PDO $pdo): array
{
    $emails = [];
    $env = stripe_env();
    stripe_notification_seed_from_env($pdo, $env);

    $dbAccountIds = stripe_notification_account_ids($pdo);
    $dbManualEmails = stripe_notification_manual_emails($pdo);
    $hasExplicitRecipients = $dbAccountIds !== []
        || $dbManualEmails !== []
        || trim((string) ($env['ORDER_NOTIFICATION_ACCOUNT_IDS'] ?? '')) !== ''
        || trim((string) ($env['ORDER_NOTIFICATION_EMAILS'] ?? '')) !== ''
        || trim((string) ($env['PAYMENT_NOTIFICATION_EMAILS'] ?? '')) !== ''
        || trim((string) ($env['STRIPE_NOTIFICATION_EMAILS'] ?? '')) !== '';

    $accountIds = $dbAccountIds !== [] ? $dbAccountIds : stripe_notification_env_account_ids($env);
    if ($accountIds !== [] && stripe_table_exists($pdo, 'accounts') && stripe_table_exists($pdo, 'people')) {
        try {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(p.email), ''), NULLIF(TRIM(a.email), '')) AS email
                FROM accounts a
                JOIN people p ON p.id = a.person_id
                WHERE a.id IN ({$placeholders})
                  AND a.is_active = 1
                  AND p.is_active = 1");
            $stmt->execute(array_values($accountIds));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = trim((string) $email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[strtolower($email)] = $email;
                }
            }
        } catch (Throwable $e) {
        }
    }

    foreach ($dbManualEmails !== [] ? $dbManualEmails : stripe_notification_env_emails($env) as $email) {
        $emails[strtolower($email)] = $email;
    }

    if (stripe_table_exists($pdo, 'shop_settings')) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'contact_email' LIMIT 1");
            $email = trim((string) ($stmt ? $stmt->fetchColumn() : ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $hasExplicitRecipients = true;
                $emails[strtolower($email)] = $email;
            }
        } catch (Throwable $e) {
        }
    }

    if (!$hasExplicitRecipients && stripe_table_exists($pdo, 'users')) {
        try {
            $stmt = $pdo->query("SELECT email FROM users WHERE is_active = 1 AND role IN ('admin','super_admin','treasurer') ORDER BY id LIMIT 10");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = trim((string) $email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[strtolower($email)] = $email;
                }
            }
        } catch (Throwable $e) {
        }
    }

    return array_values($emails);
}

function stripe_send_payment_notification(PDO $pdo, string $source, string $customerName, string $customerEmail, float $amount, string $reference, ?string $manageUrl = null): void
{
    $recipients = stripe_notification_recipients($pdo);
    if ($recipients === []) {
        return;
    }

    $subject = '[' . $source . '] New payment received - ' . gbp($amount);
    $lines = [
        'A new payment has been received.',
        '',
        'Source: ' . $source,
        'Reference: ' . $reference,
        'Customer: ' . ($customerName !== '' ? $customerName : 'Unknown'),
        'Email: ' . ($customerEmail !== '' ? $customerEmail : 'Unknown'),
        'Amount: ' . gbp($amount),
    ];
    if ($manageUrl !== null && $manageUrl !== '') {
        $lines[] = 'Manage: ' . $manageUrl;
    }
    $lines[] = '';
    $lines[] = 'My Club Hub';

    foreach ($recipients as $to) {
        hub_send_mail($to, $subject, implode("\n", $lines), false);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function stripe_get_all_transactions(PDO $pdo, array $filters = []): array
{
    ensureStripeSchema($pdo);

    $rows = [];
    $status = trim((string) ($filters['status'] ?? ''));
    $seasonId = (int) ($filters['season_id'] ?? 0);
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    $limit = isset($filters['limit']) ? max(1, min((int) $filters['limit'], 500)) : 0;

    $include = static function (array $row) use ($status, $seasonId, $from, $to): bool {
        if ($status !== '' && (string) $row['status'] !== $status) {
            return false;
        }
        if ($seasonId > 0 && (int) ($row['season_id'] ?? 0) !== $seasonId) {
            return false;
        }
        $createdAt = (string) ($row['created_at'] ?? '');
        if ($from !== '' && $createdAt < $from . ' 00:00:00') {
            return false;
        }
        if ($to !== '' && $createdAt > $to . ' 23:59:59') {
            return false;
        }
        return true;
    };

    foreach (stripe_get_transactions($pdo, []) as $row) {
        $item = $row + [
            'source' => 'Sponsorship',
            'customer_name' => (string) ($row['sponsor_name'] ?? ''),
            'customer_email' => '',
            'description' => (string) ($row['package_name'] ?? ''),
            'manage_url' => 'sponsorship_agreement.php?id=' . (int) $row['agreement_id'],
            'local_transaction_id' => (int) $row['id'],
        ];
        if ($include($item)) {
            $rows[] = $item;
        }
    }

    if (stripe_table_exists($pdo, 'payments') && stripe_table_exists($pdo, 'orders') && stripe_table_exists($pdo, 'order_items')) {
        $hasSeasonPassTables = stripe_table_exists($pdo, 'entitlements') && stripe_table_exists($pdo, 'season_passes');
        $hasMatchTicketTables = stripe_table_exists($pdo, 'fixture_ticket_packages') && stripe_table_exists($pdo, 'match_fixtures');
        $seasonSelect = $hasSeasonPassTables ? 'MAX(sp.season_id)' : '0';
        $fixtureSeasonSelect = $hasMatchTicketTables ? 'MAX(mf.season_id)' : '0';
        $seasonJoins = $hasSeasonPassTables
            ? 'LEFT JOIN entitlements e ON e.order_item_id = oi.id
                LEFT JOIN season_passes sp ON sp.entitlement_id = e.id'
            : '';
        $matchTicketJoins = $hasMatchTicketTables
            ? "LEFT JOIN fixture_ticket_packages ftp ON ftp.id = oi.product_reference_id AND oi.product_type = 'match_ticket'
                LEFT JOIN match_fixtures mf ON mf.id = ftp.fixture_id"
            : '';
        $refundJoin = stripe_table_exists($pdo, 'refunds')
            ? "LEFT JOIN (
                    SELECT payment_id, SUM(amount) AS refunded_amount
                    FROM refunds
                    WHERE status IN ('complete','completed','succeeded','paid')
                    GROUP BY payment_id
                ) ref ON ref.payment_id = pay.id"
            : "LEFT JOIN (SELECT NULL AS payment_id, 0 AS refunded_amount) ref ON ref.payment_id = pay.id";
        $sql = "SELECT pay.id, pay.provider_payment_id AS stripe_payment_intent_id,
                   pay.provider_checkout_session_id AS stripe_checkout_session_id,
                   pay.amount, pay.currency, pay.status,
                   COALESCE(ref.refunded_amount, 0) AS refunded_amount,
                   COALESCE(pay.paid_at, pay.created_at) AS created_at,
                   o.id AS order_id, o.customer_name, COALESCE(o.customer_email, '') AS customer_email,
                   GROUP_CONCAT(DISTINCT oi.product_type ORDER BY oi.product_type SEPARATOR ', ') AS product_types,
                   GROUP_CONCAT(DISTINCT oi.description_snapshot ORDER BY oi.id SEPARATOR ', ') AS description,
                   {$seasonSelect} AS season_id,
                   {$fixtureSeasonSelect} AS fixture_season_id
                FROM payments pay
                JOIN orders o ON o.id = pay.order_id
                JOIN order_items oi ON oi.order_id = o.id
                {$refundJoin}
                {$seasonJoins}
                {$matchTicketJoins}
                WHERE pay.provider = 'stripe' AND pay.status IN ('paid','refunded','partially_refunded')
                GROUP BY pay.id, pay.provider_payment_id, pay.provider_checkout_session_id, pay.amount, pay.currency, pay.status,
                         pay.paid_at, pay.created_at, o.id, o.customer_name, o.customer_email, ref.refunded_amount
                ORDER BY created_at DESC, pay.id DESC";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productTypes = (string) ($row['product_types'] ?? '');
            $source = str_contains($productTypes, 'match_ticket') ? 'Match tickets' : (str_contains($productTypes, 'season_ticket') ? 'Season tickets' : 'Orders');
            $item = [
                'id' => 'payment:' . (int) $row['id'],
                'source' => $source,
                'customer_name' => (string) $row['customer_name'],
                'customer_email' => (string) $row['customer_email'],
                'description' => (string) $row['description'],
                'amount' => (float) $row['amount'],
                'currency' => strtolower((string) $row['currency']),
                'status' => (string) $row['status'] === 'paid' ? 'succeeded' : (string) $row['status'],
                'refunded_amount' => (float) $row['refunded_amount'],
                'created_at' => (string) $row['created_at'],
                'season_id' => (int) ($row['season_id'] ?: $row['fixture_season_id'] ?: 0),
                'stripe_payment_intent_id' => (string) ($row['stripe_payment_intent_id'] ?? ''),
                'stripe_checkout_session_id' => (string) ($row['stripe_checkout_session_id'] ?? ''),
                'manage_url' => str_contains($productTypes, 'season_ticket') ? 'season_ticket_orders.php?id=' . (int) $row['order_id'] : 'ticket_orders.php?id=' . (int) $row['order_id'],
            ];
            if ($include($item)) {
                $rows[] = $item;
            }
        }
    }

    if (stripe_table_exists($pdo, 'shop_orders')) {
        $sql = "SELECT id, order_ref, customer_name, customer_email, total AS amount, currency, amount_refunded AS refunded_amount,
                   status, COALESCE(paid_at, created_at) AS created_at, stripe_payment_intent_id, stripe_checkout_session_id
                FROM shop_orders
                WHERE (stripe_payment_intent_id IS NOT NULL OR stripe_checkout_session_id IS NOT NULL)
                  AND status IN ('paid','collected','refunded')
                ORDER BY created_at DESC, id DESC";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = [
                'id' => 'shop:' . (int) $row['id'],
                'source' => 'Club shop',
                'customer_name' => (string) $row['customer_name'],
                'customer_email' => (string) $row['customer_email'],
                'description' => 'Shop order ' . (string) $row['order_ref'],
                'amount' => (float) $row['amount'],
                'currency' => strtolower((string) $row['currency']),
                'status' => (string) $row['status'] === 'refunded' ? 'refunded' : 'succeeded',
                'refunded_amount' => (float) $row['refunded_amount'],
                'created_at' => (string) $row['created_at'],
                'season_id' => 0,
                'stripe_payment_intent_id' => (string) ($row['stripe_payment_intent_id'] ?? ''),
                'stripe_checkout_session_id' => (string) ($row['stripe_checkout_session_id'] ?? ''),
                'manage_url' => 'shop_order.php?id=' . (int) $row['id'],
            ];
            if ($include($item)) {
                $rows[] = $item;
            }
        }
    }

    if (stripe_table_exists($pdo, 'hidden_team_payments')) {
        $sql = "SELECT p.id, p.amount, 'gbp' AS currency, p.status, p.paid_at AS created_at,
                   p.stripe_payment_intent_id, p.stripe_checkout_session_id,
                   t.supporter_name AS customer_name, t.team_name, g.name AS game_name, g.season_id
                FROM hidden_team_payments p
                JOIN hidden_team_teams t ON t.id = p.team_id
                JOIN hidden_team_games g ON g.id = t.game_id
                WHERE p.method = 'stripe'
                ORDER BY p.paid_at DESC, p.id DESC";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = [
                'id' => 'hidden_team:' . (int) $row['id'],
                'source' => 'Hidden team',
                'customer_name' => (string) $row['customer_name'],
                'customer_email' => '',
                'description' => (string) $row['game_name'] . ' - ' . (string) $row['team_name'],
                'amount' => (float) $row['amount'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'] === 'conflict' ? 'partially_refunded' : 'succeeded',
                'refunded_amount' => 0.0,
                'created_at' => (string) $row['created_at'],
                'season_id' => (int) ($row['season_id'] ?? 0),
                'stripe_payment_intent_id' => (string) ($row['stripe_payment_intent_id'] ?? ''),
                'stripe_checkout_session_id' => (string) ($row['stripe_checkout_session_id'] ?? ''),
                'manage_url' => 'hidden_team_games.php',
            ];
            if ($include($item)) {
                $rows[] = $item;
            }
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
}

/**
 * @return array{collected: float, refunded: float, payments: int}
 */
function stripe_all_payments_summary(PDO $pdo, array $filters = []): array
{
    $rows = stripe_get_all_transactions($pdo, $filters);
    $collected = 0.0;
    $refunded = 0.0;
    foreach ($rows as $row) {
        $amount = (float) ($row['amount'] ?? 0);
        $refund = (float) ($row['refunded_amount'] ?? 0);
        $collected += max(0.0, $amount - $refund);
        $refunded += max(0.0, $refund);
    }
    return ['collected' => round($collected, 2), 'refunded' => round($refunded, 2), 'payments' => count($rows)];
}

/**
 * @return list<array<string, mixed>>
 */
function stripe_get_transactions(PDO $pdo, array $filters = []): array
{
    ensureStripeSchema($pdo);

    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 't.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['season_id'])) {
        $where[] = 'a.season_id = :season_id';
        $params[':season_id'] = (int) $filters['season_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 't.created_at >= :from';
        $params[':from'] = (string) $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 't.created_at <= :to';
        $params[':to'] = (string) $filters['to'] . ' 23:59:59';
    }

    $sql = 'SELECT t.*, s.name AS sponsor_name, p.name AS package_name, a.season_id
        FROM stripe_transactions t
        JOIN sponsorship_agreements a ON a.id = t.agreement_id
        JOIN sponsors s ON s.id = a.sponsor_id
        JOIN packages p ON p.id = a.package_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.created_at DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stripe_get_payment_links(PDO $pdo, int $limit = 100): array
{
    ensureStripeSchema($pdo);

    $limit = max(1, min($limit, 250));
    $sql = 'SELECT l.*, s.name AS sponsor_name, p.name AS package_name, a.season_id,
            pl.name AS player_name, f.opponent AS fixture_opponent, f.match_date AS fixture_date
        FROM stripe_payment_links l
        JOIN sponsorship_agreements a ON a.id = l.agreement_id
        JOIN sponsors s ON s.id = a.sponsor_id
        JOIN packages p ON p.id = a.package_id
        LEFT JOIN players pl ON pl.id = a.player_id
        LEFT JOIN match_fixtures f ON f.id = a.fixture_id
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT ' . $limit;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array{collected: float, pending_links: int, expired_links: int, refunded: float}
 */
function stripe_dashboard_summary(PDO $pdo): array
{
    ensureStripeSchema($pdo);

    $collected = (float) ($pdo->query("SELECT COALESCE(SUM(amount - refunded_amount),0) FROM stripe_transactions WHERE status IN ('succeeded','partially_refunded')")->fetchColumn());
    $publicExpirySql = "CASE WHEN COALESCE(slug, '') <> '' THEN COALESCE(public_expires_at, DATE_ADD(created_at, INTERVAL " . stripe_public_link_expiry_days() . " DAY), expires_at) ELSE expires_at END";
    $pendingLinks = (int) ($pdo->query("SELECT COUNT(*) FROM stripe_payment_links WHERE status <> 'complete' AND {$publicExpirySql} > NOW()")->fetchColumn());
    $expiredLinks = (int) ($pdo->query("SELECT COUNT(*) FROM stripe_payment_links WHERE status <> 'complete' AND {$publicExpirySql} <= NOW()")->fetchColumn());
    $refunded = (float) ($pdo->query('SELECT COALESCE(SUM(refunded_amount),0) FROM stripe_transactions')->fetchColumn());

    return [
        'collected' => $collected,
        'pending_links' => $pendingLinks,
        'expired_links' => $expiredLinks,
        'refunded' => $refunded,
    ];
}

/**
 * Unified payment history for an agreement regardless of which underlying
 * table it rolls up through, so Stripe payments on legacy match/player
 * agreements show up on sponsorship_agreement.php too.
 *
 * recorded_at is when the row was actually written (full date+time) — distinct
 * from paid_at, which on sponsorship_agreement_payments is a DATE staff pick by
 * hand and carries no time. The match/player tables' paid_at is already a real
 * timestamp (Stripe writes it via NOW()), so recorded_at just mirrors it there.
 *
 * @param array<string, mixed> $agreement
 * @return list<array{id: int, paid_at: string, recorded_at: string, amount: float, method: ?string, note: ?string}>
 */
function getAgreementPayments(PDO $pdo, array $agreement): array
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        $stmt = $pdo->prepare('SELECT id, paid_at, paid_at AS recorded_at, amount, method, note FROM match_sponsorship_payments WHERE match_sponsorship_id = :id ORDER BY paid_at DESC, id DESC');
        $stmt->execute([':id' => $legacyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $stmt = $pdo->prepare('SELECT id, paid_at, paid_at AS recorded_at, amount, method, note FROM sponsorship_payments WHERE sponsorship_id = :id ORDER BY paid_at DESC, id DESC');
        $stmt->execute([':id' => $legacyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare('SELECT id, paid_at, created_at AS recorded_at, amount, method, note FROM sponsorship_agreement_payments WHERE agreement_id = :id ORDER BY paid_at DESC, id DESC');
    $stmt->execute([':id' => (int) $agreement['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
