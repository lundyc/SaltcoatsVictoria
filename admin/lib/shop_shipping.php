<?php

declare(strict_types=1);

/**
 * Club Shop — shipping zones, methods and rate calculation.
 *
 * A zone is a region (a set of countries, optionally narrowed by UK postcode
 * prefixes, or "everywhere else"). Each zone has delivery methods with a price
 * rule (flat, free over a basket value, or by total weight). Zones are tried
 * in order; the first that matches the customer's address supplies the
 * methods they can choose from. Shipping-class surcharges are added on top.
 * Collection from the club is separate and always free.
 */

/** @return array<string,string> ISO 3166-1 alpha-2 => name */
function shop_countries(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = [
        'GB' => 'United Kingdom', 'IE' => 'Ireland', 'US' => 'United States', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola', 'AG' => 'Antigua and Barbuda',
        'AR' => 'Argentina', 'AM' => 'Armenia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain',
        'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria',
        'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CV' => 'Cape Verde', 'CF' => 'Central African Republic',
        'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)',
        'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark',
        'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland',
        'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece',
        'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HN' => 'Honduras',
        'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
        'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KI' => 'Kiribati', 'XK' => 'Kosovo', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon',
        'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macao',
        'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands',
        'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia',
        'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal',
        'NL' => 'Netherlands', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'KP' => 'North Korea', 'MK' => 'North Macedonia',
        'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar',
        'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
        'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'São Tomé and Príncipe', 'SA' => 'Saudi Arabia',
        'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea', 'SS' => 'South Sudan', 'ES' => 'Spain',
        'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan',
        'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TO' => 'Tonga',
        'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TV' => 'Tuvalu', 'UG' => 'Uganda',
        'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VA' => 'Vatican City',
        'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        'JE' => 'Jersey', 'GG' => 'Guernsey', 'IM' => 'Isle of Man', 'GI' => 'Gibraltar', 'FO' => 'Faroe Islands',
    ];
    return $c;
}

/** @return list<string> */
function shop_europe_codes(): array
{
    return ['AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'XK', 'LV', 'LI',
        'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT', 'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'UA', 'VA', 'GI', 'FO'];
}

function shop_shipping_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_shipping_zones (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        countries TEXT NOT NULL,
        postcodes TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hadMethods = $pdo->query("SHOW TABLES LIKE 'shop_shipping_methods'")->fetchColumn() !== false;
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_shipping_methods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        zone_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        method_type VARCHAR(12) NOT NULL DEFAULT 'flat',
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        free_over DECIMAL(10,2) NULL,
        weight_bands TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY idx_shop_ship_methods_zone (zone_id),
        CONSTRAINT fk_shop_ship_methods_zone FOREIGN KEY (zone_id) REFERENCES shop_shipping_zones (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'shipping_zone' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'shipping_method' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'delivery_country' => 'CHAR(2) NULL',
        'delivery_postcode' => "VARCHAR(20) NOT NULL DEFAULT ''",
    ] as $col => $def) {
        $has = $pdo->query("SHOW COLUMNS FROM shop_orders LIKE '" . $col . "'")->fetchColumn();
        if ($has === false) {
            $pdo->exec("ALTER TABLE shop_orders ADD COLUMN {$col} {$def}");
        }
    }

    if (!$hadMethods) {
        // First run: UK zone carries over the old single flat delivery fee.
        // Europe / Rest of world are created switched off — prices are for the
        // club to set (nothing is invented here).
        $fee = '3.50';
        try {
            $row = $pdo->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'delivery_fee'")->fetchColumn();
            if ($row !== false && $row !== null && $row !== '') {
                $fee = number_format((float) $row, 2, '.', '');
            }
        } catch (Throwable) {
        }
        $zone = $pdo->prepare('INSERT INTO shop_shipping_zones (name, countries, postcodes, sort_order, is_active) VALUES (:n, :c, NULL, :s, :a)');
        $method = $pdo->prepare("INSERT INTO shop_shipping_methods (zone_id, name, method_type, cost, sort_order, is_active) VALUES (:z, :n, 'flat', :c, 10, :a)");
        $seed = [
            ['United Kingdom', 'GB,JE,GG,IM', 10, 1, 'Standard delivery', $fee],
            ['Europe', implode(',', array_diff(shop_europe_codes(), ['GB'])), 20, 1, 'International delivery', '0.00', 0],
            ['Rest of the world', '*', 30, 1, 'International delivery', '0.00', 0],
        ];
        foreach ($seed as $z) {
            $zone->execute([':n' => $z[0], ':c' => $z[1], ':s' => $z[2], ':a' => $z[3]]);
            $zid = (int) $pdo->lastInsertId();
            $method->execute([':z' => $zid, ':n' => $z[4], ':c' => $z[5], ':a' => $z[6] ?? 1]);
        }
    }
}

/** @return list<string> normalised uppercase postcode prefixes, no spaces */
function shop_zone_postcode_prefixes(array $zone): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtoupper((string) ($zone['postcodes'] ?? ''))) ?: [] as $p) {
        $p = str_replace(['*', ' '], '', $p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> zones with a 'methods' list, in match order */
function shop_shipping_zones(PDO $pdo, bool $activeOnly = false): array
{
    shop_ensure_schema($pdo);
    $zones = $pdo->query('SELECT * FROM shop_shipping_zones' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    $byZone = [];
    foreach ($pdo->query('SELECT * FROM shop_shipping_methods' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $byZone[(int) $m['zone_id']][] = $m;
    }
    foreach ($zones as &$z) {
        $z['methods'] = $byZone[(int) $z['id']] ?? [];
    }
    unset($z);
    return $zones;
}

/** First active zone matching the destination, or null. */
function shop_shipping_match_zone(PDO $pdo, string $country, string $postcode): ?array
{
    $country = strtoupper(trim($country));
    $postcode = strtoupper(str_replace(' ', '', trim($postcode)));
    foreach (shop_shipping_zones($pdo, true) as $zone) {
        $codes = array_filter(array_map('trim', explode(',', strtoupper((string) $zone['countries']))));
        $everywhere = in_array('*', $codes, true);
        if (!$everywhere && !in_array($country, $codes, true)) {
            continue;
        }
        $prefixes = shop_zone_postcode_prefixes($zone);
        if ($country === 'GB' && $prefixes !== []) {
            $ok = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($postcode, $p)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
        }
        return $zone;
    }
    return null;
}

/** Sum of surcharges for the distinct shipping classes among these products. */
function shop_class_surcharge(PDO $pdo, array $productIds): float
{
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    if ($productIds === []) {
        return 0.0;
    }
    $in = implode(',', $productIds);
    return max(0.0, round((float) $pdo->query("SELECT COALESCE(SUM(c.delivery_surcharge), 0) FROM shop_shipping_classes c
        WHERE c.id IN (SELECT DISTINCT shipping_class_id FROM shop_products WHERE id IN ({$in}) AND shipping_class_id IS NOT NULL)")->fetchColumn(), 2));
}

/** @return list<array{0:float,1:float}> [upto_kg, cost] sorted ascending */
function shop_parse_weight_bands(?string $raw): array
{
    $bands = [];
    foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
        if (preg_match('/^\s*([\d.]+)\s*(?:kg)?\s*[:=,]\s*(?:£)?\s*([\d.]+)\s*$/iu', $line, $m)) {
            $bands[] = [(float) $m[1], (float) $m[2]];
        }
    }
    usort($bands, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return $bands;
}

/**
 * Price one method for a basket, class surcharge included. Null if it can't
 * be used (e.g. heavier than the top weight band).
 *
 * @param list<array{product_id:int,quantity:int}> $items
 */
function shop_shipping_method_cost(PDO $pdo, array $method, array $items, float $orderTotal): ?float
{
    $cost = (float) $method['cost'];
    switch ((string) $method['method_type']) {
        case 'free_over':
            if ($method['free_over'] !== null && $orderTotal >= (float) $method['free_over']) {
                $cost = 0.0;
            }
            break;
        case 'weight':
            $weight = 0.0;
            $stmt = $pdo->prepare('SELECT weight FROM shop_products WHERE id = :id');
            foreach ($items as $it) {
                $stmt->execute([':id' => (int) $it['product_id']]);
                $weight += (float) $stmt->fetchColumn() * max(1, (int) $it['quantity']);
            }
            $cost = null;
            foreach (shop_parse_weight_bands((string) $method['weight_bands']) as [$upto, $bandCost]) {
                if ($weight <= $upto) {
                    $cost = $bandCost;
                    break;
                }
            }
            if ($cost === null) {
                return null;
            }
            break;
    }
    $cost += shop_class_surcharge($pdo, array_map(static fn(array $i): int => (int) $i['product_id'], $items));
    // Shop-wide "free delivery over £X" (Shop settings → Delivery) beats every zone price.
    $freeOver = trim(shop_setting($pdo, 'free_delivery_over', ''));
    if ($freeOver !== '' && (float) $freeOver > 0 && $orderTotal >= (float) $freeOver) {
        return 0.0;
    }
    return round(max(0.0, $cost), 2);
}

/**
 * Delivery choices for a destination.
 *
 * @param list<array{product_id:int,quantity:int}> $items
 * @return array{zone: ?array<string,mixed>, methods: list<array{id:int,name:string,cost:float}>}
 */
function shop_shipping_quote(PDO $pdo, string $country, string $postcode, array $items, float $orderTotal): array
{
    $zone = shop_shipping_match_zone($pdo, $country, $postcode);
    $out = [];
    if ($zone) {
        foreach ($zone['methods'] as $m) {
            if ((int) $m['is_active'] !== 1) {
                continue;
            }
            $cost = shop_shipping_method_cost($pdo, $m, $items, $orderTotal);
            if ($cost !== null) {
                $out[] = ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'cost' => $cost];
            }
        }
    }
    return ['zone' => $zone, 'methods' => $out];
}

/** @return array<string,mixed>|null method row with its zone name */
function shop_shipping_method(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT m.*, z.name AS zone_name FROM shop_shipping_methods m JOIN shop_shipping_zones z ON z.id = m.zone_id WHERE m.id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Active zones that have at least one active method — i.e. places a customer could actually get a delivery price. */
function shop_shipping_usable_zones(PDO $pdo): array
{
    return array_values(array_filter(
        shop_shipping_zones($pdo, true),
        static fn(array $z): bool => $z['methods'] !== []
    ));
}
