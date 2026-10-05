<?php

declare(strict_types=1);

/**
 * site_settings — key/value store for public-website configuration: club
 * identity, brand colours, contact details, social links. Edited from the Hub
 * (settings_public.php) and read by the public site (public/bootstrap.php) plus
 * anything in the Hub that wants the canonical club identity.
 *
 * Schema lives here (site_settings_ensure_schema) so callers can self-heal; the
 * 2026_09_06 migration is just the formal record.
 */

function site_settings_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
            setting_key   VARCHAR(80) NOT NULL,
            setting_value TEXT NOT NULL,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

/**
 * Every known setting with its shipped default. Anything not in this list is
 * ignored on save, so this doubles as the allow-list.
 *
 * @return array<string,string>
 */
function site_settings_defaults(): array
{
    return [
        // Identity
        'club_name'       => 'Saltcoats Victoria FC',
        'club_short_name' => 'Saltcoats Vics',
        'club_nickname'   => 'The Vics',
        'club_tagline'    => 'West of Scotland Football League',
        'club_founded'    => '',
        // Full-colour crest — used on light backgrounds (fixture rows, cards).
        'crest_url'       => '/uploads/club/crest-colour.png',
        // Reversed / white crest — used on dark backgrounds (header, footer,
        // next-match card, match-centre hero). Falls back to crest_url if blank.
        'crest_url_reverse' => '/Saltcoats Victoria FC -White_Transparent.png',

        // Ground
        'ground_name'         => 'Campbell Park',
        'ground_address'      => 'Campbell Park, Saltcoats',
        'ground_maps_url'     => '',
        'ground_station'      => '',   // nearest railway station
        'ground_record_attendance' => '',

        // Contact
        'contact_email'   => '',
        'contact_phone'   => '',
        'contact_address' => '',

        // Invoicing — shown on the letterhead of every generated invoice
        // (admin/invoice_pdf.php). Left blank by default since the club has
        // no VAT registration or bank details recorded anywhere else in the
        // system; fill in only what applies.
        'company_number' => '',
        'bank_details'   => '',

        // Brand tokens (CSS colours) — Saltcoats Victoria 2026/27: maroon + gold
        'brand_primary'     => '#6d2231',  // wine maroon: buttons, links, chips
        'brand_primary_ink' => '#4c1521',  // deep maroon: hover / active
        'brand_secondary'   => '#3a0f19',  // maroon-black: header, footer, hero
        'brand_on_primary'  => '#ffffff',
        'brand_accent'      => '#e0b42a',  // gold: labels/highlights on dark
        'brand_accent_ink'  => '#8a6410',  // dark gold: readable on light

        // Social (empty => icon hidden)
        'social_facebook'  => '',
        'social_twitter'   => '',
        'social_instagram' => '',
        'social_youtube'   => '',

        // Advanced
        'league_table_url'  => '',   // overrides the competition's WOSFL URL
        'ga_measurement_id' => '',   // GA4 id, e.g. G-XXXXXXX
    ];
}

/**
 * All settings: stored values merged over the defaults.
 * @return array<string,string>
 */
function site_settings_all(PDO $pdo): array
{
    site_settings_ensure_schema($pdo);
    $stored = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings') as $row) {
        $stored[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    return array_merge(site_settings_defaults(), $stored);
}

function site_setting(PDO $pdo, string $key, ?string $default = null): string
{
    $all = site_settings_all($pdo);
    return $all[$key] ?? (string) ($default ?? '');
}

/**
 * Upsert. Keys not present in site_settings_defaults() are silently skipped.
 * @param array<string,string> $values
 */
function site_settings_save(PDO $pdo, array $values): void
{
    site_settings_ensure_schema($pdo);
    $allowed = site_settings_defaults();
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, $allowed)) {
            continue;
        }
        $stmt->execute([':k' => $key, ':v' => trim((string) $value)]);
    }
}
