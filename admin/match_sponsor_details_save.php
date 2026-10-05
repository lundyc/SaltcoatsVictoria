<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

header('Content-Type: application/json');

function match_sponsor_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function match_sponsor_logo_url(?string $filename): string
{
    $filename = trim((string) $filename);
    return $filename !== '' ? '/uploads/sponsors/' . rawurlencode(basename($filename)) : '';
}

function match_sponsor_uploaded_logo(string $field, string $prefix): ?array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Invalid image upload.');
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be 5MB or smaller.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, (string) $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime ?? ''])) {
        throw new RuntimeException('Unsupported image format. Please use PNG, JPG, GIF or WebP.');
    }

    $uploadDir = __DIR__ . '/uploads/sponsors';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to prepare upload directory.');
    }

    $filename = $prefix . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    return [
        'tmp_name' => (string) $file['tmp_name'],
        'destination' => $uploadDir . DIRECTORY_SEPARATOR . $filename,
        'filename' => $filename,
    ];
}

try {
    if (!hub_auth_is_authenticated()) {
        match_sponsor_json_error('Authentication required.', 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        match_sponsor_json_error('Invalid request method.', 405);
    }
    if (!csrf_check()) {
        match_sponsor_json_error('Your session expired. Please reload and try again.');
    }

    ensureSponsorshipCatalogSchema($pdo);

    $sponsorId = (int) ($_POST['sponsor_id'] ?? 0);
    if ($sponsorId <= 0) {
        match_sponsor_json_error('Missing sponsor.');
    }

    $stmt = $pdo->prepare('SELECT * FROM sponsors WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sponsorId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        match_sponsor_json_error('Sponsor not found.', 404);
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $websiteUrl = trim((string) ($_POST['website_url'] ?? ''));
    $facebookPageUrl = trim((string) ($_POST['facebook_page_url'] ?? ''));
    $instagramUrl = trim((string) ($_POST['instagram_url'] ?? ''));
    $twitterUrl = trim((string) ($_POST['twitter_url'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $isBusiness = isset($_POST['is_business']) ? 1 : 0;
    $isMainSponsor = isset($_POST['is_main_sponsor']) ? 1 : 0;
    $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));

    if ($name === '') {
        match_sponsor_json_error('Sponsor name is required.');
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        match_sponsor_json_error('Enter a valid contact email address.');
    }
    foreach ([
        'Website URL' => $websiteUrl,
        'Facebook URL' => $facebookPageUrl,
        'Instagram URL' => $instagramUrl,
        'X / Twitter URL' => $twitterUrl,
    ] as $label => $url) {
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            match_sponsor_json_error($label . ' must be a complete http:// or https:// URL.');
        }
    }

    $logoUpload = match_sponsor_uploaded_logo('logo', 'sponsor_');
    $whiteLogoUpload = match_sponsor_uploaded_logo('white_logo', 'sponsor_white_');
    $logoPath = $logoUpload['filename'] ?? ($current['logo_path'] ?? null);
    $whiteLogoPath = $whiteLogoUpload['filename'] ?? ($current['white_logo_path'] ?? null);

    if (isset($_POST['remove_logo']) && $logoUpload === null) {
        $logoPath = null;
    }
    if (isset($_POST['remove_white_logo']) && $whiteLogoUpload === null) {
        $whiteLogoPath = null;
    }

    $pdo->beginTransaction();
    $update = $pdo->prepare('
        UPDATE sponsors
        SET name = :name,
            is_active = :is_active,
            logo_path = :logo_path,
            white_logo_path = :white_logo_path,
            is_business = :is_business,
            is_main_sponsor = :is_main_sponsor,
            sort_order = :sort_order,
            address = :address,
            website_url = :website_url,
            facebook_page_url = :facebook_page_url,
            instagram_url = :instagram_url,
            twitter_url = :twitter_url,
            contact_phone = :contact_phone,
            contact_email = :contact_email
        WHERE id = :id
    ');
    $update->execute([
        ':name' => $name,
        ':is_active' => $isActive,
        ':logo_path' => $logoPath,
        ':white_logo_path' => $whiteLogoPath,
        ':is_business' => $isBusiness,
        ':is_main_sponsor' => $isMainSponsor,
        ':sort_order' => $sortOrder,
        ':address' => $address !== '' ? $address : null,
        ':website_url' => $websiteUrl !== '' ? $websiteUrl : null,
        ':facebook_page_url' => $facebookPageUrl !== '' ? $facebookPageUrl : null,
        ':instagram_url' => $instagramUrl !== '' ? $instagramUrl : null,
        ':twitter_url' => $twitterUrl !== '' ? $twitterUrl : null,
        ':contact_phone' => $contactPhone !== '' ? $contactPhone : null,
        ':contact_email' => $contactEmail !== '' ? $contactEmail : null,
        ':id' => $sponsorId,
    ]);

    if ($logoUpload !== null && !move_uploaded_file($logoUpload['tmp_name'], $logoUpload['destination'])) {
        throw new RuntimeException('Failed to store uploaded image.');
    }
    if ($whiteLogoUpload !== null && !move_uploaded_file($whiteLogoUpload['tmp_name'], $whiteLogoUpload['destination'])) {
        throw new RuntimeException('Failed to store uploaded white image.');
    }

    $pdo->commit();
    auditLog($pdo, 'sponsor_details_updated', "Updated details for sponsor '{$name}' (#{$sponsorId})");

    echo json_encode([
        'ok' => true,
        'sponsor' => [
            'id' => $sponsorId,
            'name' => $name,
            'logo_path' => $logoPath,
            'white_logo_path' => $whiteLogoPath,
            'logo_url' => match_sponsor_logo_url($logoPath),
            'white_logo_url' => match_sponsor_logo_url($whiteLogoPath),
            'website_url' => $websiteUrl,
            'facebook_page_url' => $facebookPageUrl,
            'instagram_url' => $instagramUrl,
            'twitter_url' => $twitterUrl,
            'contact_phone' => $contactPhone,
            'contact_email' => $contactEmail,
            'address' => $address,
            'is_business' => $isBusiness,
            'is_main_sponsor' => $isMainSponsor,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    match_sponsor_json_error($e->getMessage());
}
