<?php
/**
 * venue_lookup.php — quick address + map-pin search for the Add/Edit Venue form.
 * GET ?q=Campbell Park  ->  JSON list of matching places (UK only).
 * Uses OpenStreetMap's Nominatim search, which needs no API key.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 3 || mb_strlen($q) > 120) {
    echo json_encode(['results' => []]);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
    'format' => 'jsonv2',
    'addressdetails' => 1,
    'limit' => 6,
    'countrycodes' => 'gb',
    'q' => $q,
]);
$context = stream_context_create(['http' => [
    'timeout' => 6,
    'header' => "User-Agent: SaltcoatsVictoriaHub/1.0 (club admin venue lookup)\r\nAccept: application/json\r\n",
]]);
$raw = @file_get_contents($url, false, $context);
$rows = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($rows)) {
    http_response_code(502);
    echo json_encode(['error' => 'The map search is unavailable right now. Enter the details by hand.']);
    exit;
}

$results = [];
foreach ($rows as $row) {
    if (!isset($row['lat'], $row['lon'])) {
        continue;
    }
    $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
    $road = trim((string) ($addr['road'] ?? ''));
    $number = trim((string) ($addr['house_number'] ?? ''));
    $line1 = trim($number . ' ' . $road);
    if ($line1 === '') {
        $line1 = trim((string) ($addr['pedestrian'] ?? $addr['path'] ?? ''));
    }
    $town = '';
    foreach (['town', 'city', 'village', 'suburb', 'hamlet'] as $k) {
        if (!empty($addr[$k])) {
            $town = (string) $addr[$k];
            break;
        }
    }
    $results[] = [
        'name' => (string) ($row['name'] ?? ''),
        'label' => (string) ($row['display_name'] ?? ''),
        'address_line1' => $line1,
        'town' => $town,
        'postcode' => strtoupper((string) ($addr['postcode'] ?? '')),
        'latitude' => round((float) $row['lat'], 6),
        'longitude' => round((float) $row['lon'], 6),
    ];
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
