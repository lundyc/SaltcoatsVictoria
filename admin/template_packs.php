<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/monthly_fixtures.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Template Packs</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/admin/assets/css/style.css" rel="stylesheet"></head><body class="hub-shell"><main class="hub-main"><div class="container-fluid"><div class="alert alert-danger mb-0">You do not have permission to manage template packs.</div></div></main></body></html>';
    exit;
}

$hubPackAjaxTransport = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($hubPackAjaxTransport && ob_get_level() === 0) {
    ob_start();
}

$backendFile = __DIR__ . '/lib/template_packs.php';
$backendLoaded = is_file($backendFile);
if ($backendLoaded) {
    require_once $backendFile;
}

/**
 * Call the first available backend function. The small compatibility layer keeps
 * this page useful while the template-pack library is deployed independently.
 *
 * @param list<string> $names
 * @param list<mixed> $arguments
 * @return mixed
 */
function hub_pack_ui_call(array $names, array $arguments = [])
{
    foreach ($names as $name) {
        if (function_exists($name)) {
            return $name(...$arguments);
        }
    }
    return null;
}

function hub_pack_ui_text(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hub_pack_ui_redirect(int $packId = 0, string $status = 'saved', string $anchor = ''): never
{
    $query = ['status' => $status];
    if ($packId > 0) {
        $query['pack_id'] = $packId;
    }
    $fragment = $anchor !== '' ? '#' . rawurlencode($anchor) : '';
    header('Location: /admin/template_packs.php?' . http_build_query($query) . $fragment);
    exit;
}

/** @param array<string, mixed> $payload */
function hub_pack_ui_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function hub_pack_ui_background_url(string $path): string
{
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

/** @return array{ok: bool, path?: string, message?: string} */
function hub_pack_ui_store_background(array $file, int $packId, string $actionKey): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? 'The artwork exceeds the server upload limit.'
            : 'Choose a background image before uploading.'];
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > 25 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Artwork must be a valid image no larger than 25 MB.'];
    }
    $imageInfo = @getimagesize($temporaryPath);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        return ['ok' => false, 'message' => 'Use a PNG, JPG or WEBP background image.'];
    }
    $directory = __DIR__ . '/uploads/template_packs/' . $packId;
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['ok' => false, 'message' => 'The artwork folder could not be created.'];
    }
    $safeAction = preg_replace('/[^a-z0-9_-]/', '', strtolower($actionKey)) ?: 'graphic';
    $filename = $safeAction . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        return ['ok' => false, 'message' => 'The artwork could not be stored.'];
    }
    return ['ok' => true, 'path' => 'uploads/template_packs/' . $packId . '/' . $filename];
}

/**
 * @return array{ok:bool,uploaded:bool,path?:string,family?:string,label?:string,message?:string}
 */
function hub_pack_ui_store_font(array $file, int $packId, string $role): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'uploaded' => false];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'uploaded' => false, 'message' => 'The font upload could not be completed.'];
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $originalName = basename((string) ($file['name'] ?? 'Custom font'));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['woff2', 'woff', 'ttf', 'otf'];
    if (
        $temporaryPath === ''
        || !is_uploaded_file($temporaryPath)
        || $size < 1
        || $size > 8 * 1024 * 1024
        || !in_array($extension, $allowedExtensions, true)
    ) {
        return ['ok' => false, 'uploaded' => false, 'message' => 'Use a WOFF2, WOFF, TTF or OTF font no larger than 8 MB.'];
    }

    $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) ?: '');
    $allowedMimes = [
        'font/woff2', 'font/woff', 'font/ttf', 'font/otf', 'font/sfnt',
        'application/font-woff', 'application/font-sfnt',
        'application/x-font-woff', 'application/x-font-ttf', 'application/x-font-opentype',
        'application/vnd.ms-opentype', 'application/octet-stream',
    ];
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'uploaded' => false, 'message' => 'The selected file is not recognised as a web font.'];
    }

    $role = in_array($role, ['heading', 'body', 'numbers', 'library'], true) ? $role : 'library';
    $directory = __DIR__ . '/uploads/template_packs/' . $packId . '/fonts';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['ok' => false, 'uploaded' => false, 'message' => 'The font storage folder could not be created.'];
    }
    $fontId = bin2hex(random_bytes(8));
    $filename = $role . '-' . $fontId . '.' . $extension;
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        return ['ok' => false, 'uploaded' => false, 'message' => 'The font could not be stored.'];
    }

    $label = trim((string) pathinfo($originalName, PATHINFO_FILENAME));
    $label = trim((string) preg_replace('/[^a-z0-9 ._-]+/i', ' ', $label));
    return [
        'ok' => true,
        'uploaded' => true,
        'path' => 'uploads/template_packs/' . $packId . '/fonts/' . $filename,
        'family' => 'Pack ' . $packId . ' Font ' . $fontId,
        'label' => $label !== '' ? mb_substr($label, 0, 80) : 'Custom ' . ucfirst($role) . ' Font',
    ];
}

function hub_pack_ui_font_format(string $path): string
{
    return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
        'otf' => 'opentype',
        default => '',
    };
}

function hub_pack_ui_preview_text(string $actionKey, string $elementKey): string
{
    $headlines = [
        'starting_xi' => 'STARTING XI',
        'next_match' => 'NEXT UP',
        'monthly_fixtures' => 'MONTHLY FIXTURES',
        'kick_off' => 'KICK OFF',
        'half_time' => 'HALF TIME',
        'full_time' => 'FULL TIME',
        'goal' => 'GOAL!',
        'substitution' => 'SUBSTITUTION',
        'yellow_card' => 'YELLOW CARD',
        'red_card' => 'RED CARD',
        'player_of_match' => 'PLAYER OF THE MATCH',
        'player_sponsorship' => 'PLAYER SPONSORSHIP',
        'postponed' => 'MATCH POSTPONED',
        'abandoned' => 'MATCH ABANDONED',
    ];
    return match ($elementKey) {
        'headline' => $headlines[$actionKey] ?? strtoupper(str_replace('_', ' ', $actionKey)),
        'club_badge' => 'SVC',
        'opponent_badge' => 'OPP',
        'month' => 'SEPTEMBER 2026',
        'sponsor' => 'YOUR SPONSOR',
        'lineup' => "1  A. PLAYER\n2  B. PLAYER\n3  C. PLAYER\n4  D. PLAYER\n5  E. PLAYER",
        'substitutes' => "SUBSTITUTES\nF. PLAYER\nG. PLAYER\nH. PLAYER",
        'score' => '2 – 1',
        'fixture' => 'SALTCOATS VICTORIA  v  OPPOSITION',
        'player_name' => $actionKey === 'player_sponsorship' ? 'SEASON 24/25' : 'PLAYER NAME',
        'player_image' => '●',
        'player_on' => 'ON  —  PLAYER NAME',
        'player_off' => 'OFF  —  PLAYER NAME',
        default => strtoupper(str_replace('_', ' ', $elementKey)),
    };
}

/** @param array<string, mixed> $element */
function hub_pack_ui_preview_element_style(array $element): string
{
    $rules = [];
    foreach (['x' => 'left', 'y' => 'top', 'width' => 'width', 'height' => 'height'] as $key => $property) {
        if (isset($element[$key]) && is_numeric($element[$key])) {
            $rules[] = $property . ':' . max(0, (int) $element[$key]) . 'px';
        }
    }
    if (isset($element['font_size']) && is_numeric($element['font_size']) && (int) $element['font_size'] > 0) {
        $rules[] = 'font-size:' . (int) $element['font_size'] . 'px';
    }
    if (in_array((string) ($element['text_align'] ?? ''), ['left', 'center', 'right'], true)) {
        $rules[] = 'text-align:' . $element['text_align'];
    }
    if (preg_match('/^#[0-9a-f]{6}$/i', (string) ($element['color'] ?? '')) === 1) {
        $rules[] = 'color:' . $element['color'];
    }
    $fontFamily = trim((string) ($element['font_family'] ?? ''));
    if ($fontFamily !== '' && preg_match('/^[a-z0-9 ._-]{1,100}$/i', $fontFamily) === 1) {
        $rules[] = 'font-family:"' . $fontFamily . '",Arial,sans-serif';
    }
    $fontWeight = (int) ($element['font_weight'] ?? 0);
    if ($fontWeight >= 100 && $fontWeight <= 900) {
        $rules[] = 'font-weight:' . $fontWeight;
    }
    if (isset($element['letter_spacing']) && is_numeric($element['letter_spacing'])) {
        $rules[] = 'letter-spacing:' . max(-20, min(100, (float) $element['letter_spacing'])) . 'px';
    }
    if (isset($element['line_height']) && is_numeric($element['line_height'])) {
        $rules[] = 'line-height:' . max(.5, min(3, (float) $element['line_height']));
    }
    if (in_array((string) ($element['text_transform'] ?? ''), ['none', 'uppercase', 'lowercase', 'capitalize'], true)) {
        $rules[] = 'text-transform:' . $element['text_transform'];
    }
    if (array_key_exists('visible', $element) && !$element['visible']) {
        $rules[] = 'display:none';
    }
    return implode(';', $rules);
}

function hub_pack_ui_is_text_element(string $elementKey, array $element): bool
{
    if ((int) ($element['font_size'] ?? 0) <= 0) {
        return false;
    }

    return !in_array($elementKey, [
        'club_badge', 'opponent_badge', 'badges', 'player_image',
        'sponsor', 'sponsor_logos', 'footer_meta', 'competition_meta',
    ], true);
}

/** @return array<string, array<string, mixed>> */
function hub_pack_ui_fallback_actions(): array
{
    return [
        'starting_xi' => ['key' => 'starting_xi', 'label' => 'Starting XI', 'icon' => 'fa-people-group', 'description' => 'Team selection, captain and substitutes.'],
        'next_match' => ['key' => 'next_match', 'label' => 'Next Match', 'icon' => 'fa-calendar-day', 'description' => 'Promote the next fixture.'],
        'monthly_fixtures' => ['key' => 'monthly_fixtures', 'label' => 'Monthly Fixtures', 'icon' => 'fa-calendar-days', 'description' => 'Monthly block and calendar fixture graphics.'],
        'kick_off' => ['key' => 'kick_off', 'label' => 'Kick Off', 'icon' => 'fa-stopwatch', 'description' => 'Live match opening graphic.'],
        'half_time' => ['key' => 'half_time', 'label' => 'Half Time', 'icon' => 'fa-clock', 'description' => 'Half-time score update.'],
        'full_time' => ['key' => 'full_time', 'label' => 'Full Time', 'icon' => 'fa-flag-checkered', 'description' => 'Final score and result.'],
        'goal' => ['key' => 'goal', 'label' => 'Goal', 'icon' => 'fa-futbol', 'description' => 'Goalscorer announcement.'],
        'substitution' => ['key' => 'substitution', 'label' => 'Substitution', 'icon' => 'fa-right-left', 'description' => 'Player on and off update.'],
        'yellow_card' => ['key' => 'yellow_card', 'label' => 'Yellow Card', 'icon' => 'fa-square', 'description' => 'Booking announcement.'],
        'red_card' => ['key' => 'red_card', 'label' => 'Red Card', 'icon' => 'fa-square', 'description' => 'Dismissal announcement.'],
        'player_of_match' => ['key' => 'player_of_match', 'label' => 'Player of the Match', 'icon' => 'fa-star', 'description' => 'Post-match player recognition.'],
        'player_sponsorship' => ['key' => 'player_sponsorship', 'label' => 'Player Sponsorship', 'icon' => 'fa-shirt', 'description' => 'Player sponsorship announcement.'],
        'postponed' => ['key' => 'postponed', 'label' => 'Match Postponed', 'icon' => 'fa-calendar-xmark', 'description' => 'Fixture postponement notice.'],
        'abandoned' => ['key' => 'abandoned', 'label' => 'Match Abandoned', 'icon' => 'fa-triangle-exclamation', 'description' => 'Abandoned fixture notice.'],
    ];
}

/** @return array<string, array<string, int|string|bool>> */
function hub_pack_ui_default_elements(string $actionKey): array
{
    if ($actionKey === 'monthly_fixtures') {
        return [
            'club_badge' => ['label' => 'Club badge', 'x' => 466, 'y' => 36, 'width' => 148, 'height' => 148, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'legend' => ['label' => 'Home and away legend', 'x' => 68, 'y' => 205, 'width' => 944, 'height' => 65, 'font_size' => 38, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
            'month_heading' => ['label' => 'Month fixtures heading', 'x' => 68, 'y' => 280, 'width' => 944, 'height' => 65, 'font_size' => 38, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
            'fixtures' => ['label' => 'Fixture blocks or calendar', 'x' => 68, 'y' => 362, 'width' => 944, 'height' => 830, 'font_size' => 18, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'footer_meta' => ['label' => 'Footer note', 'x' => 120, 'y' => 1260, 'width' => 840, 'height' => 64, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        ];
    }
    $elements = [
        'headline' => ['label' => 'Headline', 'x' => 90, 'y' => 90, 'width' => 900, 'height' => 90, 'font_size' => 58, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'club_badge' => ['label' => 'Club badge', 'x' => 120, 'y' => 270, 'width' => 230, 'height' => 230, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'opponent_badge' => ['label' => 'Opponent badge', 'x' => 730, 'y' => 270, 'width' => 230, 'height' => 230, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'sponsor' => ['label' => 'Sponsor', 'x' => 340, 'y' => 930, 'width' => 400, 'height' => 90, 'font_size' => 24, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
    ];
    if ($actionKey === 'starting_xi') {
        $elements['lineup'] = ['label' => 'Starting XI list', 'x' => 100, 'y' => 230, 'width' => 560, 'height' => 600, 'font_size' => 38, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['substitutes'] = ['label' => 'Substitutes', 'x' => 680, 'y' => 230, 'width' => 300, 'height' => 600, 'font_size' => 28, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        unset($elements['club_badge'], $elements['opponent_badge']);
    } elseif (in_array($actionKey, ['half_time', 'full_time', 'kick_off'], true)) {
        $elements['score'] = ['label' => 'Score', 'x' => 300, 'y' => 520, 'width' => 480, 'height' => 150, 'font_size' => 110, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => $actionKey !== 'kick_off'];
        $elements['fixture'] = ['label' => 'Fixture names', 'x' => 100, 'y' => 700, 'width' => 880, 'height' => 100, 'font_size' => 36, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
    } elseif (in_array($actionKey, ['goal', 'yellow_card', 'red_card', 'player_of_match'], true)) {
        $elements['player_name'] = ['label' => 'Player name', 'x' => 120, 'y' => 710, 'width' => 840, 'height' => 120, 'font_size' => 62, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_image'] = ['label' => 'Player image', 'x' => 290, 'y' => 190, 'width' => 500, 'height' => 500, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => $actionKey !== 'player_of_match'];
        if ($actionKey === 'player_of_match') {
            $elements['headline'] = ['label' => 'Player of the Match heading', 'x' => 90, 'y' => 170, 'width' => 900, 'height' => 480, 'font_size' => 276, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
            $elements['player_name'] = ['label' => 'Player name', 'x' => 100, 'y' => 675, 'width' => 880, 'height' => 245, 'font_size' => 122, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
            $elements['player_sponsors'] = ['label' => 'Player sponsors', 'x' => 120, 'y' => 955, 'width' => 760, 'height' => 250, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        }
        unset($elements['club_badge'], $elements['opponent_badge']);
    } elseif ($actionKey === 'player_sponsorship') {
        $elements['headline'] = ['label' => 'Sponsorship heading', 'x' => 76, 'y' => 80, 'width' => 880, 'height' => 84, 'font_size' => 56, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['player_image'] = ['label' => 'Player image', 'x' => 64, 'y' => 180, 'width' => 520, 'height' => 820, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_sponsors'] = ['label' => 'Player sponsors', 'x' => 64, 'y' => 1020, 'width' => 520, 'height' => 220, 'font_size' => 22, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['player_name'] = ['label' => 'Season text', 'x' => 608, 'y' => 260, 'width' => 380, 'height' => 120, 'font_size' => 42, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        unset($elements['club_badge'], $elements['opponent_badge']);
    } elseif ($actionKey === 'substitution') {
        $elements['player_on'] = ['label' => 'Player on', 'x' => 150, 'y' => 510, 'width' => 780, 'height' => 100, 'font_size' => 48, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_off'] = ['label' => 'Player off', 'x' => 150, 'y' => 640, 'width' => 780, 'height' => 100, 'font_size' => 42, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        unset($elements['club_badge'], $elements['opponent_badge']);
    } else {
        $elements['fixture'] = ['label' => 'Fixture details', 'x' => 100, 'y' => 570, 'width' => 880, 'height' => 180, 'font_size' => 42, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
    }
    return $elements;
}

/** @return array<string, array<string, int|string|bool>> */
function hub_pack_ui_editorial_elements(string $actionKey): array
{
    if ($actionKey === 'monthly_fixtures') {
        return hub_pack_ui_default_elements($actionKey);
    }
    if ($actionKey === 'next_match') {
        return [
            'badges' => ['label' => 'Club and competition badges', 'x' => 260, 'y' => 365, 'width' => 560, 'height' => 190, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'headline' => ['label' => 'Next Up heading', 'x' => 90, 'y' => 600, 'width' => 900, 'height' => 150, 'font_size' => 174, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'fixture' => [
                'label' => 'Venue and date',
                'x' => 150,
                'y' => 790,
                'width' => 780,
                'height' => 90,
                'font_size' => 31,
                'text_align' => 'center',
                'color' => '#ffffff',
                'visible' => true,
                'venue_font' => 'Poppins',
                'venue_weight' => '600',
                'date_font' => 'Poppins',
                'date_weight' => '500',
            ],
            'fixture_sponsors' => ['label' => 'Match sponsors', 'x' => 160, 'y' => 900, 'width' => 760, 'height' => 280, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'sponsor_logos' => ['label' => 'Main sponsor logos', 'x' => 110, 'y' => 1235, 'width' => 860, 'height' => 90, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        ];
    }
    if ($actionKey === 'player_sponsorship') {
        return [
            'headline' => ['label' => 'Sponsorship heading', 'x' => 76, 'y' => 80, 'width' => 880, 'height' => 84, 'font_size' => 56, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
            'player_image' => ['label' => 'Player image', 'x' => 64, 'y' => 180, 'width' => 520, 'height' => 820, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'player_sponsors' => ['label' => 'Player sponsors', 'x' => 64, 'y' => 1020, 'width' => 520, 'height' => 220, 'font_size' => 22, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
            'player_name' => ['label' => 'Season text', 'x' => 608, 'y' => 260, 'width' => 380, 'height' => 120, 'font_size' => 42, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true],
        ];
    }
    if ($actionKey === 'kick_off') {
        return [
            'fixture' => [
                'label' => 'Venue, date and kick-off time',
                'x' => 36,
                'y' => 90,
                'width' => 480,
                'height' => 110,
                'font_size' => 22,
                'text_align' => 'left',
                'color' => '#ffffff',
                'visible' => true,
                'venue_font' => 'Poppins',
                'venue_weight' => '600',
                'date_font' => 'Poppins',
                'date_weight' => '500',
            ],
            'badges' => ['label' => 'Club badges', 'x' => 415, 'y' => 330, 'width' => 250, 'height' => 105, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'headline' => ['label' => 'Kick Off heading', 'x' => 110, 'y' => 455, 'width' => 860, 'height' => 500, 'font_size' => 276, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'footer_meta' => ['label' => 'Competition badge', 'x' => 36, 'y' => 1200, 'width' => 115, 'height' => 90, 'font_size' => 20, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true],
            'fixture_sponsors' => ['label' => 'Match sponsors', 'x' => 160, 'y' => 900, 'width' => 760, 'height' => 280, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
            'sponsor_logos' => ['label' => 'Main sponsor logos', 'x' => 720, 'y' => 1220, 'width' => 320, 'height' => 68, 'font_size' => 14, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true],
        ];
    }

    $elements = [
        'headline' => ['label' => 'Headline', 'x' => 140, 'y' => 290, 'width' => 800, 'height' => 90, 'font_size' => 46, 'text_align' => 'center', 'color' => '#f5f1e9', 'visible' => true],
        'club_badge' => ['label' => 'Saltcoats badge', 'x' => 375, 'y' => 62, 'width' => 145, 'height' => 145, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'opponent_badge' => ['label' => 'Opponent badge', 'x' => 560, 'y' => 62, 'width' => 145, 'height' => 145, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
        'sponsor' => ['label' => 'Competition mark', 'x' => 900, 'y' => 1170, 'width' => 125, 'height' => 125, 'font_size' => 18, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true],
    ];

    if ($actionKey === 'starting_xi') {
        $elements['headline'] = ['label' => 'Fixture heading', 'x' => 190, 'y' => 290, 'width' => 700, 'height' => 50, 'font_size' => 22, 'text_align' => 'center', 'color' => '#f5f1e9', 'visible' => true];
        $elements['lineup'] = ['label' => 'Starting XI list', 'x' => 140, 'y' => 345, 'width' => 800, 'height' => 735, 'font_size' => 64, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
        $elements['substitutes'] = ['label' => 'Substitutes', 'x' => 245, 'y' => 1125, 'width' => 590, 'height' => 105, 'font_size' => 18, 'text_align' => 'center', 'color' => '#d8c68c', 'visible' => true];
        $elements['sponsor_logos'] = ['label' => 'Sponsor logos', 'x' => 80, 'y' => 1245, 'width' => 780, 'height' => 70, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['matchday_sponsors'] = ['label' => 'Matchday / matchball sponsors', 'x' => 710, 'y' => 62, 'width' => 330, 'height' => 145, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
    } elseif (in_array($actionKey, ['half_time', 'full_time'], true)) {
        unset($elements['headline']);
        $elements['headline_first'] = ['label' => $actionKey === 'half_time' ? 'Half heading' : 'Full heading', 'x' => 45, 'y' => 70, 'width' => 540, 'height' => 260, 'font_size' => 276, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['headline_second'] = ['label' => 'Time heading', 'x' => 485, 'y' => 285, 'width' => 550, 'height' => 260, 'font_size' => 276, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        $elements['club_badge'] = ['label' => 'Saltcoats badge', 'x' => 145, 'y' => 673, 'width' => 155, 'height' => 155, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['opponent_badge'] = ['label' => 'Opponent badge', 'x' => 780, 'y' => 673, 'width' => 155, 'height' => 155, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['score'] = ['label' => 'Score', 'x' => 315, 'y' => 635, 'width' => 450, 'height' => 230, 'font_size' => 214, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['fixture'] = ['label' => 'Goalscorers', 'x' => 220, 'y' => 850, 'width' => 640, 'height' => 45, 'font_size' => 24, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => false];
        $elements['sponsor'] = ['label' => 'Competition mark', 'x' => 735, 'y' => 105, 'width' => 105, 'height' => 105, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['fixture_sponsors'] = ['label' => 'Match sponsors', 'x' => 160, 'y' => 900, 'width' => 760, 'height' => 280, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['sponsor_logos'] = ['label' => 'Club sponsor logos', 'x' => 555, 'y' => 1200, 'width' => 470, 'height' => 90, 'font_size' => 16, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
    } elseif ($actionKey === 'kick_off') {
        $elements['score'] = ['label' => 'Score', 'x' => 340, 'y' => 480, 'width' => 400, 'height' => 150, 'font_size' => 100, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => false];
        $elements['fixture'] = ['label' => 'Fixture names', 'x' => 150, 'y' => 480, 'width' => 780, 'height' => 300, 'font_size' => 58, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
    } elseif ($actionKey === 'goal') {
        unset($elements['club_badge'], $elements['opponent_badge'], $elements['sponsor']);
        $elements['competition_meta'] = ['label' => 'Competition badge', 'x' => 35, 'y' => 35, 'width' => 110, 'height' => 90, 'font_size' => 0, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['matchday_meta'] = ['label' => 'Goal time', 'x' => 615, 'y' => 35, 'width' => 430, 'height' => 45, 'font_size' => 24, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        $elements['player_name'] = ['label' => 'Goalscorer name', 'x' => 140, 'y' => 330, 'width' => 800, 'height' => 90, 'font_size' => 62, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['headline'] = ['label' => 'Goal heading', 'x' => 90, 'y' => 455, 'width' => 900, 'height' => 190, 'font_size' => 174, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['badges'] = ['label' => 'Club badges', 'x' => 145, 'y' => 612, 'width' => 790, 'height' => 155, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['score'] = ['label' => 'Score', 'x' => 317, 'y' => 574, 'width' => 450, 'height' => 230, 'font_size' => 214, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_sponsors'] = ['label' => 'Goal sponsors', 'x' => 160, 'y' => 850, 'width' => 760, 'height' => 300, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_number'] = ['label' => 'Match date', 'x' => 35, 'y' => 1260, 'width' => 470, 'height' => 45, 'font_size' => 22, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['venue'] = ['label' => 'Venue', 'x' => 650, 'y' => 1260, 'width' => 395, 'height' => 45, 'font_size' => 22, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        unset($elements['player_image']);
    } elseif ($actionKey === 'player_of_match') {
        $elements['headline'] = ['label' => 'Player of the Match heading', 'x' => 90, 'y' => 170, 'width' => 900, 'height' => 480, 'font_size' => 276, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_image'] = ['label' => 'Player image', 'x' => 265, 'y' => 390, 'width' => 550, 'height' => 500, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => false];
        $elements['player_name'] = ['label' => 'Player name', 'x' => 100, 'y' => 675, 'width' => 880, 'height' => 245, 'font_size' => 122, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
        $elements['player_sponsors'] = ['label' => 'Player sponsors', 'x' => 120, 'y' => 955, 'width' => 760, 'height' => 250, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
    } elseif (in_array($actionKey, ['yellow_card', 'red_card'], true)) {
        // Yellow Card and Red Card share the same design: it mirrors the Kick
        // Off layout (badges, big headline, venue/date corner, sponsors) with
        // the carded player's name below the headline, and deliberately
        // carries no player photo.
        unset($elements['club_badge'], $elements['opponent_badge'], $elements['sponsor']);
        $elements['fixture'] = ['label' => 'Venue, date and kick-off time', 'x' => 34, 'y' => 94, 'width' => 480, 'height' => 110, 'font_size' => 22, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true, 'venue_font' => 'Poppins', 'venue_weight' => '600', 'date_font' => 'Poppins', 'date_weight' => '500'];
        $elements['badges'] = ['label' => 'Club badges', 'x' => 781, 'y' => 67, 'width' => 250, 'height' => 105, 'font_size' => 0, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        // "YELLOW" is a much wider word than "RED", so its headline needs a
        // smaller font size to avoid overflowing the canvas at the same width.
        $elements['headline'] = ['label' => $actionKey === 'red_card' ? 'Red Card heading' : 'Yellow Card heading', 'x' => 97, 'y' => 235, 'width' => 860, 'height' => 500, 'font_size' => $actionKey === 'red_card' ? 276 : 204, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_name'] = ['label' => 'Player name', 'x' => 97, 'y' => 745, 'width' => 860, 'height' => 110, 'font_size' => 64, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
        $elements['footer_meta'] = ['label' => 'Competition badge', 'x' => 36, 'y' => 1200, 'width' => 115, 'height' => 90, 'font_size' => 20, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['fixture_sponsors'] = ['label' => 'Match sponsors', 'x' => 160, 'y' => 900, 'width' => 760, 'height' => 280, 'font_size' => 21, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['sponsor_logos'] = ['label' => 'Main sponsor logos', 'x' => 720, 'y' => 1220, 'width' => 320, 'height' => 68, 'font_size' => 14, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
    } elseif ($actionKey === 'substitution') {
        unset($elements['club_badge'], $elements['opponent_badge'], $elements['sponsor']);
        $elements['headline'] = ['label' => 'Subs heading', 'x' => 90, 'y' => 560, 'width' => 900, 'height' => 230, 'font_size' => 200, 'text_align' => 'center', 'color' => '#ffffff', 'visible' => true];
        $elements['player_on'] = ['label' => 'Player on', 'x' => 80, 'y' => 140, 'width' => 750, 'height' => 120, 'font_size' => 40, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['player_off'] = ['label' => 'Player off', 'x' => 250, 'y' => 1090, 'width' => 750, 'height' => 120, 'font_size' => 40, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        $elements['badges'] = ['label' => 'Club badges', 'x' => 60, 'y' => 60, 'width' => 260, 'height' => 100, 'font_size' => 0, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['fixture_sponsors'] = ['label' => 'Matchday / matchball sponsors', 'x' => 640, 'y' => 60, 'width' => 380, 'height' => 140, 'font_size' => 15, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
        $elements['sponsor_logos'] = ['label' => 'Main sponsor logos', 'x' => 60, 'y' => 1190, 'width' => 460, 'height' => 100, 'font_size' => 14, 'text_align' => 'left', 'color' => '#ffffff', 'visible' => true];
        $elements['footer_meta'] = ['label' => 'League badge', 'x' => 940, 'y' => 1190, 'width' => 90, 'height' => 100, 'font_size' => 0, 'text_align' => 'right', 'color' => '#ffffff', 'visible' => true];
    } else {
        $elements['fixture'] = ['label' => 'Fixture details', 'x' => 150, 'y' => 480, 'width' => 780, 'height' => 330, 'font_size' => 58, 'text_align' => 'center', 'color' => '#f8f6f1', 'visible' => true];
    }

    return $elements;
}

/** @param array<string, mixed> $template */
function hub_pack_ui_action_configured(array $template): bool
{
    if (trim((string) ($template['background_path'] ?? '')) !== '') {
        return true;
    }
    $settings = is_array($template['layout_settings'] ?? null) ? $template['layout_settings'] : [];
    $elements = is_array($settings['elements'] ?? null) ? $settings['elements'] : [];
    return $elements !== []
        || array_key_exists('canvas_width', $settings)
        || array_key_exists('gradient_color', $settings);
}

$statusType = '';
$statusMessage = '';
$packId = max(0, (int) ($_GET['pack_id'] ?? $_POST['pack_id'] ?? 0));
$ajaxRequest = (string) ($_POST['ajax'] ?? '') === '1'
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$backendReady = $backendLoaded
    && function_exists('template_packs_list')
    && function_exists('template_packs_get')
    && function_exists('template_packs_get_draft');

if ($backendReady && function_exists('template_packs_ensure_schema')) {
    template_packs_ensure_schema($pdo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    if (!hub_auth_verify_csrf_token($token)) {
        $statusType = 'danger';
        $statusMessage = 'Your session expired. Reload the page and try again.';
    } elseif (!$backendLoaded) {
        $statusType = 'warning';
        $statusMessage = 'The template-pack service is not available yet. No changes were made.';
    } elseif (!$backendReady) {
        $statusType = 'warning';
        $statusMessage = 'The template-pack service is installed but is not ready to accept changes.';
    } else {
        $postAction = trim((string) ($_POST['action'] ?? ''));
        try {
            $userId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;
            if ($postAction === 'create_pack') {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Enter a name for the template pack.');
                }
                $result = template_packs_create($pdo, $name, trim((string) ($_POST['description'] ?? '')) ?: null, $userId);
                auditLog($pdo, 'template_pack_created', 'Created template pack "' . $name . '"');
                hub_pack_ui_redirect((int) ($result['id'] ?? 0), 'created');
            } elseif ($postAction === 'save_pack') {
                $packName = trim((string) ($_POST['name'] ?? ''));
                template_packs_update($pdo, $packId, [
                    'name' => $packName,
                    'description' => trim((string) ($_POST['description'] ?? '')),
                ]);
                auditLog($pdo, 'template_pack_updated', 'Updated template pack "' . $packName . '"');
                hub_pack_ui_redirect($packId);
            } elseif ($postAction === 'upload_font') {
                $fontRole = trim((string) ($_POST['font_role'] ?? ''));
                if (!in_array($fontRole, ['heading', 'body', 'numbers', 'library'], true)) {
                    throw new RuntimeException('Choose a valid font library.');
                }
                $fontUpload = hub_pack_ui_store_font(
                    is_array($_FILES['font_file'] ?? null) ? $_FILES['font_file'] : [],
                    $packId,
                    $fontRole
                );
                if (empty($fontUpload['ok']) || empty($fontUpload['uploaded'])) {
                    throw new RuntimeException((string) ($fontUpload['message'] ?? 'Choose a font to upload.'));
                }
                $draft = template_packs_get_draft($pdo, $packId);
                $brandSettings = is_array($draft['brand_settings'] ?? null) ? $draft['brand_settings'] : [];
                $family = (string) $fontUpload['family'];
                $fontLibrary = is_array($brandSettings['font_library'] ?? null) ? $brandSettings['font_library'] : [];
                $fontLibrary[] = [
                    'family' => $family,
                    'label' => (string) $fontUpload['label'],
                    'path' => (string) $fontUpload['path'],
                ];
                $brandSettings['font_library'] = array_values($fontLibrary);
                if (in_array($fontRole, ['heading', 'body', 'numbers'], true)) {
                    $brandSettings[$fontRole . '_font'] = $family;
                    $brandSettings[$fontRole . '_font_path'] = (string) $fontUpload['path'];
                    $brandSettings[$fontRole . '_font_label'] = (string) $fontUpload['label'];
                    $brandSettings[$fontRole . '_font_family'] = $family;
                    if (isset($brandSettings['fonts']) && is_array($brandSettings['fonts'])) {
                        $brandSettings['fonts'][match ($fontRole) {
                            'heading' => 'primary',
                            'body' => 'secondary',
                            default => 'numbers',
                        }] = $family;
                    }
                }
                template_packs_save_draft_brand($pdo, $packId, $brandSettings);
                auditLog($pdo, 'template_pack_font_uploaded', 'Uploaded ' . $fontRole . ' font "' . (string) $fontUpload['label'] . '" for template pack #' . $packId);
                if ($ajaxRequest) {
                    hub_pack_ui_json([
                        'ok' => true,
                        'message' => (string) $fontUpload['label'] . ' added to this pack.',
                        'role' => $fontRole,
                        'family' => $family,
                        'label' => (string) $fontUpload['label'],
                        'font_url' => hub_pack_ui_background_url((string) $fontUpload['path']),
                        'format' => hub_pack_ui_font_format((string) $fontUpload['path']),
                    ]);
                }
                hub_pack_ui_redirect($packId, 'saved', 'brand-system');
            } elseif ($postAction === 'save_brand') {
                $postedBrand = is_array($_POST['brand'] ?? null) ? $_POST['brand'] : [];
                $draft = template_packs_get_draft($pdo, $packId);
                $existingBrand = is_array($draft['brand_settings'] ?? null) ? $draft['brand_settings'] : [];
                $colour = static function (mixed $value, string $fallback): string {
                    $value = strtolower(trim((string) $value));
                    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
                };
                $headingFont = hub_pack_ui_store_font(
                    is_array($_FILES['heading_font_file'] ?? null) ? $_FILES['heading_font_file'] : [],
                    $packId,
                    'heading'
                );
                $bodyFont = hub_pack_ui_store_font(
                    is_array($_FILES['body_font_file'] ?? null) ? $_FILES['body_font_file'] : [],
                    $packId,
                    'body'
                );
                $numbersFont = hub_pack_ui_store_font(
                    is_array($_FILES['numbers_font_file'] ?? null) ? $_FILES['numbers_font_file'] : [],
                    $packId,
                    'numbers'
                );
                if (empty($headingFont['ok'])) {
                    throw new RuntimeException((string) ($headingFont['message'] ?? 'The heading font could not be uploaded.'));
                }
                if (empty($bodyFont['ok'])) {
                    throw new RuntimeException((string) ($bodyFont['message'] ?? 'The body font could not be uploaded.'));
                }
                if (empty($numbersFont['ok'])) {
                    throw new RuntimeException((string) ($numbersFont['message'] ?? 'The numbers font could not be uploaded.'));
                }
                $brandSettings = [
                    'primary_color' => $colour($postedBrand['primary_color'] ?? '', '#6f263d'),
                    'secondary_color' => $colour($postedBrand['secondary_color'] ?? '', '#f1c75b'),
                    'accent_color' => $colour($postedBrand['accent_color'] ?? '', '#ffffff'),
                    'text_color' => $colour($postedBrand['text_color'] ?? '', '#ffffff'),
                    'heading_font' => trim((string) ($postedBrand['heading_font'] ?? 'Inter')),
                    'body_font' => trim((string) ($postedBrand['body_font'] ?? 'Inter')),
                    'numbers_font' => trim((string) ($postedBrand['numbers_font'] ?? 'Inter')),
                    'heading_style' => trim((string) ($postedBrand['heading_style'] ?? 'title')),
                    'badge_style' => trim((string) ($postedBrand['badge_style'] ?? 'colour')),
                    'sponsor_style' => trim((string) ($postedBrand['sponsor_style'] ?? 'colour')),
                    'use_white_badges' => !empty($postedBrand['use_white_badges']),
                    'show_sponsor_names' => !empty($postedBrand['show_sponsor_names']),
                    'heading_font_path' => (string) ($existingBrand['heading_font_path'] ?? ''),
                    'heading_font_label' => (string) ($existingBrand['heading_font_label'] ?? ''),
                    'heading_font_family' => (string) ($existingBrand['heading_font_family'] ?? ''),
                    'body_font_path' => (string) ($existingBrand['body_font_path'] ?? ''),
                    'body_font_label' => (string) ($existingBrand['body_font_label'] ?? ''),
                    'body_font_family' => (string) ($existingBrand['body_font_family'] ?? ''),
                    'numbers_font_path' => (string) ($existingBrand['numbers_font_path'] ?? ''),
                    'numbers_font_label' => (string) ($existingBrand['numbers_font_label'] ?? ''),
                    'numbers_font_family' => (string) ($existingBrand['numbers_font_family'] ?? ''),
                    'font_library' => is_array($existingBrand['font_library'] ?? null) ? $existingBrand['font_library'] : [],
                ];
                if (!empty($headingFont['uploaded'])) {
                    $brandSettings['heading_font'] = (string) $headingFont['family'];
                    $brandSettings['heading_font_path'] = (string) $headingFont['path'];
                    $brandSettings['heading_font_label'] = (string) $headingFont['label'];
                    $brandSettings['heading_font_family'] = (string) $headingFont['family'];
                }
                if (!empty($bodyFont['uploaded'])) {
                    $brandSettings['body_font'] = (string) $bodyFont['family'];
                    $brandSettings['body_font_path'] = (string) $bodyFont['path'];
                    $brandSettings['body_font_label'] = (string) $bodyFont['label'];
                    $brandSettings['body_font_family'] = (string) $bodyFont['family'];
                }
                if (!empty($numbersFont['uploaded'])) {
                    $brandSettings['numbers_font'] = (string) $numbersFont['family'];
                    $brandSettings['numbers_font_path'] = (string) $numbersFont['path'];
                    $brandSettings['numbers_font_label'] = (string) $numbersFont['label'];
                    $brandSettings['numbers_font_family'] = (string) $numbersFont['family'];
                }
                template_packs_save_draft_brand($pdo, $packId, $brandSettings);
                auditLog($pdo, 'template_pack_brand_updated', 'Updated brand settings for template pack #' . $packId);
                hub_pack_ui_redirect($packId, 'saved', 'brand-system');
            } elseif ($postAction === 'save_action_background') {
                $actionKey = trim((string) ($_POST['action_key'] ?? ''));
                $upload = hub_pack_ui_store_background(is_array($_FILES['background'] ?? null) ? $_FILES['background'] : [], $packId, $actionKey);
                if (empty($upload['ok'])) {
                    throw new RuntimeException((string) ($upload['message'] ?? 'Artwork could not be uploaded.'));
                }
                $draft = template_packs_get_draft($pdo, $packId);
                $current = is_array($draft['actions'][$actionKey]['layout_settings'] ?? null) ? $draft['actions'][$actionKey]['layout_settings'] : [];
                template_packs_save_draft_action($pdo, $packId, $actionKey, $current, (string) $upload['path']);
                $pack = template_packs_get($pdo, $packId);
                if (is_array($pack) && trim((string) ($pack['cover_path'] ?? '')) === '') {
                    template_packs_update($pdo, $packId, ['cover_path' => (string) $upload['path']]);
                }
                auditLog($pdo, 'template_pack_background_saved', 'Saved background for "' . $actionKey . '" in template pack #' . $packId);
                if ($ajaxRequest) {
                    hub_pack_ui_json([
                        'ok' => true,
                        'message' => 'Background uploaded and saved.',
                        'image_url' => hub_pack_ui_background_url((string) $upload['path']),
                        'action_key' => $actionKey,
                    ]);
                }
                hub_pack_ui_redirect($packId, 'saved', 'action-' . $actionKey);
            } elseif ($postAction === 'save_action_layout') {
                $actionKey = trim((string) ($_POST['action_key'] ?? ''));
                $elements = json_decode((string) ($_POST['layout_json'] ?? '{}'), true);
                $elements = is_array($elements) ? $elements : [];
                $gradientColour = strtolower(trim((string) ($_POST['gradient_color'] ?? '#000000')));
                $layoutSettings = [
                    'canvas_width' => max(320, min(4096, (int) ($_POST['canvas_width'] ?? 1080))),
                    'canvas_height' => max(320, min(4096, (int) ($_POST['canvas_height'] ?? 1080))),
                    'layout' => trim((string) ($_POST['layout'] ?? 'square')),
                    'background_fit' => trim((string) ($_POST['background_fit'] ?? 'cover')),
                    'gradient_color' => preg_match('/^#[0-9a-f]{6}$/', $gradientColour) ? $gradientColour : '#000000',
                    'gradient_strength' => max(0, min(100, (int) ($_POST['gradient_strength'] ?? 0))),
                    'elements' => $elements,
                ];
                if ($actionKey === 'monthly_fixtures') {
                    $layoutSettings['badge_size'] = max(50, min(220, (int) ($_POST['badge_size'] ?? 165)));
                    $layoutSettings['card_gap'] = max(0, min(42, (int) ($_POST['card_gap'] ?? 18)));
                    $layoutSettings['badge_style'] = (string) ($_POST['badge_style'] ?? '') === 'colour' ? 'colour' : 'white';
                }
                $draft = template_packs_get_draft($pdo, $packId);
                $backgroundPath = isset($draft['actions'][$actionKey]['background_path']) ? (string) $draft['actions'][$actionKey]['background_path'] : null;
                template_packs_save_draft_action($pdo, $packId, $actionKey, $layoutSettings, $backgroundPath);
                auditLog($pdo, 'template_pack_layout_saved', 'Saved layout for "' . $actionKey . '" in template pack #' . $packId);
                hub_pack_ui_redirect($packId, 'saved', 'action-' . $actionKey);
            } elseif ($postAction === 'remove_action_background') {
                $actionKey = trim((string) ($_POST['action_key'] ?? ''));
                $draft = template_packs_get_draft($pdo, $packId);
                $current = is_array($draft['actions'][$actionKey]['layout_settings'] ?? null) ? $draft['actions'][$actionKey]['layout_settings'] : [];
                template_packs_save_draft_action($pdo, $packId, $actionKey, $current, '');
                auditLog($pdo, 'template_pack_background_removed', 'Removed background for "' . $actionKey . '" in template pack #' . $packId);
                hub_pack_ui_redirect($packId, 'asset_removed', 'action-' . $actionKey);
            } elseif ($postAction === 'publish_pack') {
                template_packs_publish($pdo, $packId, $userId);
                auditLog($pdo, 'template_pack_published', 'Published template pack #' . $packId);
                hub_pack_ui_redirect($packId, 'published');
            } elseif ($postAction === 'duplicate_pack') {
                $duplicateName = trim((string) ($_POST['name'] ?? 'Template pack copy'));
                $result = template_packs_duplicate($pdo, $packId, $duplicateName, $userId);
                auditLog($pdo, 'template_pack_duplicated', 'Duplicated template pack #' . $packId . ' as "' . $duplicateName . '"');
                hub_pack_ui_redirect((int) ($result['id'] ?? 0), 'duplicated');
            } elseif ($postAction === 'archive_pack' || $postAction === 'restore_pack') {
                template_packs_archive($pdo, $packId, $postAction === 'archive_pack');
                auditLog($pdo, $postAction === 'archive_pack' ? 'template_pack_archived' : 'template_pack_restored', ($postAction === 'archive_pack' ? 'Archived' : 'Restored') . ' template pack #' . $packId);
                hub_pack_ui_redirect(0, $postAction === 'archive_pack' ? 'archived' : 'restored');
            } else {
                throw new RuntimeException('Unknown template-pack action.');
            }
        } catch (Throwable $exception) {
            $statusType = 'danger';
            $statusMessage = $exception->getMessage() !== '' ? $exception->getMessage() : 'The template pack could not be updated.';
            if ($ajaxRequest) {
                hub_pack_ui_json(['ok' => false, 'message' => $statusMessage], 422);
            }
        }
    }
    if ($ajaxRequest && $statusMessage !== '') {
        hub_pack_ui_json(['ok' => false, 'message' => $statusMessage], 422);
    }
}

$rawActions = hub_pack_ui_call(
    ['template_packs_action_registry', 'hub_template_pack_action_definitions', 'template_pack_action_definitions']
) ?? hub_pack_ui_fallback_actions();
$actions = [];
foreach ((array) $rawActions as $key => $action) {
    if (!is_array($action)) {
        continue;
    }
    $actionKey = (string) ($action['key'] ?? (is_string($key) ? $key : ''));
    if ($actionKey === '') {
        continue;
    }
    $actions[$actionKey] = $action + ['key' => $actionKey, 'label' => ucwords(str_replace('_', ' ', $actionKey))];
}

$rawPacks = $backendReady ? template_packs_list($pdo, true) : [];
$packs = is_array($rawPacks) ? array_values($rawPacks) : [];
$selectedPack = null;
if ($packId > 0) {
    $selectedPack = $backendReady ? template_packs_get($pdo, $packId) : null;
    if (!is_array($selectedPack)) {
        foreach ($packs as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $packId) {
                $selectedPack = $candidate;
                break;
            }
        }
    }
    if (is_array($selectedPack) && $backendReady) {
        $selectedDraft = template_packs_get_draft($pdo, $packId);
        if (is_array($selectedDraft)) {
            $selectedPack['brand_settings'] = is_array($selectedDraft['brand_settings'] ?? null) ? $selectedDraft['brand_settings'] : [];
            $selectedPack['actions'] = is_array($selectedDraft['actions'] ?? null) ? $selectedDraft['actions'] : [];
        }
        if (function_exists('template_packs_list_versions')) {
            $selectedPack['versions'] = template_packs_list_versions($pdo, $packId, true);
        }
    }
}

$templatePreviewSponsors = $selectedPack
    ? getActiveMainSponsorAgreements($pdo, 0, date('Y-m-d'))
    : [];
$templatePreviewFixtureSponsors = $selectedPack ? getMatchSponsorshipRows($pdo, 46) : [];

$publishedCount = 0;
$draftCount = 0;
foreach ($packs as $pack) {
    if (!empty($pack['is_archived']) || (string) ($pack['status'] ?? '') === 'archived') {
        continue;
    }
    if (!empty($pack['current_version_number']) || !empty($pack['current_published_version_id'])) {
        $publishedCount++;
    } else {
        $draftCount++;
    }
}

$noticeMap = [
    'created' => 'Template pack created.',
    'duplicated' => 'Template pack duplicated.',
    'saved' => 'Your changes have been saved.',
    'published' => 'A new immutable version has been published.',
    'archived' => 'The template pack has been archived.',
    'restored' => 'The template pack has been restored.',
    'asset_removed' => 'The background artwork has been removed.',
];
if ($statusMessage === '' && isset($_GET['status'], $noticeMap[(string) $_GET['status']])) {
    $statusType = 'success';
    $statusMessage = $noticeMap[(string) $_GET['status']];
}

$pageHero = [
    'eyebrow' => 'Creative studio',
    'title' => $selectedPack ? (string) ($selectedPack['name'] ?? 'Template Pack') : 'Template Packs',
    'subtitle' => $selectedPack
        ? 'Build one consistent visual identity across every match-day graphic.'
        : 'Create and publish coordinated designs for every match-day moment.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;family=Montserrat:wght@300;400;500;600;700;800;900&amp;family=Oswald:wght@300;400;500;600;700&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;family=Roboto:wght@300;400;500;600;700;800;900&amp;family=Roboto+Condensed:wght@300;400;500;600;700;800;900&amp;display=swap">
<link rel="stylesheet" href="/admin/assets/css/template-packs.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/template-packs.css') ?: time()) ?>">

<div class="template-packs-page">
    <?php if ($statusMessage !== ''): ?>
        <div class="alert alert-<?= hub_pack_ui_text($statusType) ?> alert-dismissible fade show" role="alert">
            <?= hub_pack_ui_text($statusMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
        </div>
    <?php endif; ?>

    <?php if (!$backendReady): ?>
        <div class="pack-service-notice" role="status">
            <span class="pack-service-notice__icon"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span>
            <div>
                <strong>Template Pack service is being prepared</strong>
                <p>The design workspace is ready, but its storage service is not currently available. Editing controls are safely disabled until it is connected.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$selectedPack): ?>
        <?php hub_render_metric_grid([
            ['label' => 'Total packs', 'value' => count($packs), 'meta' => 'All template packs', 'icon' => 'fa-layer-group', 'tone' => 'primary'],
            ['label' => 'Published', 'value' => $publishedCount, 'meta' => 'Ready for use', 'icon' => 'fa-circle-check', 'tone' => 'success'],
            ['label' => 'In progress', 'value' => $draftCount, 'meta' => 'Draft or incomplete', 'icon' => 'fa-pen-ruler', 'tone' => 'warning'],
        ], 'Template pack summary'); ?>
        <aside class="pack-stat pack-stat--guide mb-4" aria-label="Template selection order">
            <div>
                <span class="pack-stat__eyebrow">Selection order</span>
                <strong>Fixture <i class="fa-solid fa-chevron-right"></i> Season <i class="fa-solid fa-chevron-right"></i> Global</strong>
            </div>
        </aside>

        <div class="pack-toolbar hub-toolbar">
            <div>
                <h2 id="pack-overview-title">Your design systems</h2>
                <p>Each pack keeps colours, typography and layouts consistent.</p>
            </div>
            <div class="pack-toolbar__controls">
                <label class="pack-search">
                    <span class="visually-hidden">Search template packs</span>
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" placeholder="Search packs…" data-pack-search>
                </label>
                <select class="form-select" aria-label="Filter packs" data-pack-filter>
                    <option value="active">Active packs</option>
                    <option value="all">All packs</option>
                    <option value="published">Published</option>
                    <option value="draft">Drafts</option>
                    <option value="archived">Archived</option>
                </select>
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#createPackModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Create pack</button>
            </div>
        </div>

        <div class="pack-grid" data-pack-grid>
            <?php foreach ($packs as $pack): ?>
                <?php
                $id = (int) ($pack['id'] ?? 0);
                $status = (string) ($pack['status'] ?? '') === 'archived'
                    ? 'archived'
                    : (!empty($pack['current_version_number']) ? 'published' : 'draft');
                $configured = (int) ($pack['configured_action_count'] ?? $pack['configured_actions'] ?? 0);
                $total = max(1, (int) ($pack['total_actions'] ?? count($actions)));
                $progress = min(100, (int) round(($configured / $total) * 100));
                $cover = hub_pack_ui_background_url((string) ($pack['cover_path'] ?? $pack['cover_url'] ?? ''));
                ?>
                <article class="pack-card hub-record-card" data-pack-card data-name="<?= hub_pack_ui_text(strtolower((string) ($pack['name'] ?? ''))) ?>" data-status="<?= hub_pack_ui_text($status) ?>">
                    <a class="pack-card__visual" href="/admin/template_packs.php?pack_id=<?= $id ?>" aria-label="Edit <?= hub_pack_ui_text($pack['name'] ?? 'template pack') ?>">
                        <?php if ($cover !== ''): ?>
                            <img src="<?= hub_pack_ui_text($cover) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="pack-card__monogram"><?= hub_pack_ui_text(strtoupper(substr((string) ($pack['name'] ?? 'TP'), 0, 2))) ?></span>
                            <span class="pack-card__motif" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="pack-badge is-<?= hub_pack_ui_text($status) ?>"><?= hub_pack_ui_text(ucfirst($status)) ?></span>
                        <?php if (!empty($pack['is_global_default'])): ?><span class="pack-default-badge"><i class="fa-solid fa-globe"></i> Global default</span><?php endif; ?>
                    </a>
                    <div class="pack-card__body">
                        <div class="pack-card__heading">
                            <div>
                                <h3><a href="/admin/template_packs.php?pack_id=<?= $id ?>"><?= hub_pack_ui_text($pack['name'] ?? 'Untitled pack') ?></a></h3>
                                <p><?= hub_pack_ui_text($pack['description'] ?? 'A coordinated match-day graphic collection.') ?></p>
                            </div>
                            <div class="dropdown">
                                <button class="pack-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= hub_pack_ui_text($pack['name'] ?? 'pack') ?>"><i class="fa-solid fa-ellipsis"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="/admin/template_packs.php?pack_id=<?= $id ?>"><i class="fa-solid fa-pen me-2"></i>Edit pack</a></li>
                                    <li><button class="dropdown-item" type="button" data-duplicate-pack="<?= $id ?>" data-pack-name="<?= hub_pack_ui_text($pack['name'] ?? '') ?>"><i class="fa-regular fa-copy me-2"></i>Duplicate</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item <?= $status === 'archived' ? '' : 'text-danger' ?>" type="button" data-archive-pack="<?= $id ?>" data-archive-mode="<?= $status === 'archived' ? 'restore' : 'archive' ?>"><i class="fa-solid fa-box-archive me-2"></i><?= $status === 'archived' ? 'Restore' : 'Archive' ?></button></li>
                                </ul>
                            </div>
                        </div>
                        <div class="pack-progress-row">
                            <span><?= $configured ?> of <?= $total ?> graphics ready</span><strong><?= $progress ?>%</strong>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Pack completeness" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                        </div>
                        <div class="pack-card__meta">
                            <span><i class="fa-solid fa-code-branch"></i> Version <?= hub_pack_ui_text($pack['current_version_number'] ?? 'Draft') ?></span>
                            <span>Updated <?= hub_pack_ui_text($pack['updated_label'] ?? $pack['updated_at'] ?? 'recently') ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <button class="pack-card pack-card--create" type="button" data-bs-toggle="modal" data-bs-target="#createPackModal" <?= $backendReady ? '' : 'disabled' ?>>
                <span class="pack-card--create__icon"><i class="fa-solid fa-plus"></i></span>
                <strong>Create a template pack</strong>
                <span>Start blank or duplicate an existing design.</span>
            </button>
        </div>
        <p class="pack-empty hub-empty-state" data-pack-empty hidden>No template packs match your search.</p>
    <?php else: ?>
        <?php
        $brand = is_array($selectedPack['brand'] ?? null) ? $selectedPack['brand'] : (is_array($selectedPack['brand_settings'] ?? null) ? $selectedPack['brand_settings'] : []);
        $nestedColours = is_array($brand['colours'] ?? null) ? $brand['colours'] : [];
        $nestedFonts = is_array($brand['fonts'] ?? null) ? $brand['fonts'] : [];
        $nestedBadges = is_array($brand['badges'] ?? null) ? $brand['badges'] : [];
        $nestedSponsors = is_array($brand['sponsors'] ?? null) ? $brand['sponsors'] : [];
        $brand += [
            'primary_color' => $nestedColours['primary'] ?? '#6f263d',
            'secondary_color' => $nestedColours['secondary'] ?? '#f1c75b',
            'accent_color' => $nestedColours['accent'] ?? '#ffffff',
            'text_color' => $nestedColours['text'] ?? '#ffffff',
            'heading_font' => $nestedFonts['primary'] ?? 'Inter',
            'body_font' => $nestedFonts['secondary'] ?? 'Inter',
            'numbers_font' => $nestedFonts['numbers'] ?? 'Inter',
            'badge_style' => $nestedBadges['variant'] ?? 'colour',
            'sponsor_style' => $nestedSponsors['variant'] ?? 'colour',
            'use_white_badges' => ($nestedBadges['variant'] ?? '') === 'white',
            'show_sponsor_names' => !empty($nestedSponsors['show_name']),
        ];
        $customHeadingFontUrl = hub_pack_ui_background_url((string) ($brand['heading_font_path'] ?? ''));
        $customBodyFontUrl = hub_pack_ui_background_url((string) ($brand['body_font_path'] ?? ''));
        $customNumbersFontUrl = hub_pack_ui_background_url((string) ($brand['numbers_font_path'] ?? ''));
        $customHeadingFontFamily = trim((string) preg_replace('/[^a-z0-9 ._-]/i', '', (string) ($brand['heading_font_family'] ?? $brand['heading_font'] ?? '')));
        $customBodyFontFamily = trim((string) preg_replace('/[^a-z0-9 ._-]/i', '', (string) ($brand['body_font_family'] ?? $brand['body_font'] ?? '')));
        $customNumbersFontFamily = trim((string) preg_replace('/[^a-z0-9 ._-]/i', '', (string) ($brand['numbers_font_family'] ?? $brand['numbers_font'] ?? '')));
        $builtInPackFonts = ['Inter', 'Montserrat', 'Oswald', 'Poppins', 'Roboto', 'Roboto Condensed', 'Arial'];
        $fontLibrary = [];
        foreach ((array) ($brand['font_library'] ?? []) as $fontEntry) {
            if (!is_array($fontEntry)) {
                continue;
            }
            $family = trim((string) ($fontEntry['family'] ?? ''));
            $path = trim((string) ($fontEntry['path'] ?? ''));
            if ($family === '' || $path === '' || preg_match('/^[a-z0-9 ._-]{1,100}$/i', $family) !== 1) {
                continue;
            }
            $fontLibrary[$family] = [
                'family' => $family,
                'label' => trim((string) ($fontEntry['label'] ?? $family)) ?: $family,
                'path' => $path,
            ];
        }
        foreach ([
            [$customHeadingFontFamily, (string) ($brand['heading_font_label'] ?? $customHeadingFontFamily), (string) ($brand['heading_font_path'] ?? '')],
            [$customBodyFontFamily, (string) ($brand['body_font_label'] ?? $customBodyFontFamily), (string) ($brand['body_font_path'] ?? '')],
            [$customNumbersFontFamily, (string) ($brand['numbers_font_label'] ?? $customNumbersFontFamily), (string) ($brand['numbers_font_path'] ?? '')],
        ] as [$legacyFamily, $legacyLabel, $legacyPath]) {
            if ($legacyFamily !== '' && $legacyPath !== '' && !isset($fontLibrary[$legacyFamily])) {
                $fontLibrary[$legacyFamily] = ['family' => $legacyFamily, 'label' => $legacyLabel ?: $legacyFamily, 'path' => $legacyPath];
            }
        }
        $packFontChoices = [];
        foreach ($builtInPackFonts as $fontFamily) {
            $packFontChoices[$fontFamily] = $fontFamily;
        }
        foreach ($fontLibrary as $fontEntry) {
            $packFontChoices[(string) $fontEntry['family']] = (string) $fontEntry['label'];
        }
        foreach ([(string) ($brand['heading_font'] ?? ''), (string) ($brand['body_font'] ?? ''), (string) ($brand['numbers_font'] ?? '')] as $selectedFont) {
            if ($selectedFont !== '' && !isset($packFontChoices[$selectedFont])) {
                $packFontChoices[$selectedFont] = $selectedFont;
            }
        }
        // Elements only offer the pack's three chosen brand fonts, so every graphic stays visually consistent.
        $brandFontChoices = [];
        foreach ([
            (string) ($brand['heading_font'] ?? 'Inter'),
            (string) ($brand['body_font'] ?? 'Inter'),
            (string) ($brand['numbers_font'] ?? 'Inter'),
        ] as $brandFontFamily) {
            if ($brandFontFamily !== '' && !isset($brandFontChoices[$brandFontFamily])) {
                $brandFontChoices[$brandFontFamily] = $packFontChoices[$brandFontFamily] ?? $brandFontFamily;
            }
        }
        $packActions = is_array($selectedPack['actions'] ?? null) ? $selectedPack['actions'] : [];
        $packStatus = (string) ($selectedPack['status'] ?? '') === 'archived'
            ? 'archived'
            : (!empty($selectedPack['current_version_number']) ? 'published' : 'draft');
        ?>
        <?php
        $browserFontFaces = [];
        if ($customHeadingFontUrl !== '' && $customHeadingFontFamily !== '') {
            $browserFontFaces[$customHeadingFontFamily] = ['family' => $customHeadingFontFamily, 'url' => $customHeadingFontUrl];
        }
        if ($customBodyFontUrl !== '' && $customBodyFontFamily !== '') {
            $browserFontFaces[$customBodyFontFamily] = ['family' => $customBodyFontFamily, 'url' => $customBodyFontUrl];
        }
        if ($customNumbersFontUrl !== '' && $customNumbersFontFamily !== '') {
            $browserFontFaces[$customNumbersFontFamily] = ['family' => $customNumbersFontFamily, 'url' => $customNumbersFontUrl];
        }
        foreach ($fontLibrary as $fontEntry) {
            $family = (string) ($fontEntry['family'] ?? '');
            $url = hub_pack_ui_background_url((string) ($fontEntry['path'] ?? ''));
            if ($family !== '' && $url !== '') {
                $browserFontFaces[$family] = ['family' => $family, 'url' => $url];
            }
        }
        ?>
        <?php if ($browserFontFaces !== []): ?>
            <script type="application/json" id="hubPackFontFaces"><?= json_encode(array_values($browserFontFaces), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
        <?php endif; ?>
        <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/template_packs.php">Template packs</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= hub_pack_ui_text($selectedPack['name'] ?? 'Template pack') ?></span></nav>
        <section class="editor-summary">
            <div class="editor-summary__identity">
                <span class="pack-badge is-<?= hub_pack_ui_text($packStatus) ?>"><?= hub_pack_ui_text(ucfirst($packStatus)) ?></span>
                <span><i class="fa-solid fa-code-branch"></i> Published version <?= hub_pack_ui_text($selectedPack['current_version_number'] ?? '—') ?></span>
                <span><i class="fa-regular fa-clock"></i> <?= hub_pack_ui_text($selectedPack['updated_label'] ?? $selectedPack['updated_at'] ?? 'Not yet saved') ?></span>
            </div>
            <div class="editor-summary__actions hub-actions">
                <button class="btn btn-outline-secondary" type="button" data-pack-preview><i class="fa-regular fa-eye me-2"></i>Preview</button>
                <button class="btn btn-outline-secondary" type="button" data-duplicate-pack="<?= $packId ?>" data-pack-name="<?= hub_pack_ui_text($selectedPack['name'] ?? '') ?>"><i class="fa-regular fa-copy me-2"></i>Duplicate</button>
                <button class="btn btn-brand" type="button" data-publish-pack="<?= $packId ?>" <?= $backendReady && $packStatus !== 'archived' ? '' : 'disabled' ?>><i class="fa-solid fa-rocket me-2"></i>Publish version</button>
            </div>
        </section>

        <div class="pack-editor-layout">
            <aside class="pack-editor-nav" aria-label="Template pack sections">
                <a href="#pack-details" class="active" data-editor-nav><i class="fa-solid fa-sliders"></i><span>Pack details</span></a>
                <a href="#brand-system" data-editor-nav><i class="fa-solid fa-palette"></i><span>Brand system</span></a>
                <div class="pack-editor-nav__label">Graphic actions</div>
                <?php foreach ($actions as $actionKey => $action): ?>
                    <?php $configured = hub_pack_ui_action_configured(is_array($packActions[$actionKey] ?? null) ? $packActions[$actionKey] : []); ?>
                    <a href="#action-<?= hub_pack_ui_text($actionKey) ?>" data-editor-nav>
                        <i class="fa-solid <?= hub_pack_ui_text($action['icon'] ?? 'fa-image') ?>"></i>
                        <span><?= hub_pack_ui_text($action['label']) ?></span>
                        <span class="editor-nav-state <?= $configured ? 'is-ready' : '' ?>" title="<?= $configured ? 'Configured' : 'Needs setup' ?>"></span>
                    </a>
                <?php endforeach; ?>
            </aside>

            <div class="pack-editor-content">
                <form class="editor-panel hub-form-card" id="pack-details" method="post" data-warn-unsaved>
                    <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_pack">
                    <input type="hidden" name="pack_id" value="<?= $packId ?>">
                    <div class="editor-panel__heading">
                        <div><span class="section-kicker">Foundation</span><h2>Pack details</h2><p>Name and describe this visual system for your team.</p></div>
                        <button class="btn btn-brand" type="submit" <?= $backendReady ? '' : 'disabled' ?>>Save details</button>
                    </div>
                    <div class="editor-fields">
                        <label class="form-field"><span>Pack name</span><input class="form-control" type="text" name="name" maxlength="100" required value="<?= hub_pack_ui_text($selectedPack['name'] ?? '') ?>"></label>
                        <label class="form-field form-field--wide"><span>Description <small>Optional</small></span><textarea class="form-control" name="description" rows="3" maxlength="300"><?= hub_pack_ui_text($selectedPack['description'] ?? '') ?></textarea></label>
                    </div>
                </form>

                <form class="editor-panel hub-form-card" id="brand-system" method="post" enctype="multipart/form-data" data-brand-form data-warn-unsaved>
                    <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_brand">
                    <input type="hidden" name="pack_id" value="<?= $packId ?>">
                    <div class="editor-panel__heading">
                        <div><span class="section-kicker">Shared defaults</span><h2>Brand system</h2><p>Actions inherit these choices unless you override them.</p></div>
                        <button class="btn btn-brand" type="submit" <?= $backendReady ? '' : 'disabled' ?>>Save brand</button>
                    </div>
                    <div class="brand-grid">
                        <div class="brand-group">
                            <h3>Colour palette</h3>
                            <?php foreach (['primary' => ['Primary', '#6f263d'], 'secondary' => ['Secondary', '#f1c75b'], 'accent' => ['Accent', '#ffffff'], 'text' => ['Default text', '#ffffff']] as $colourKey => [$label, $fallback]): ?>
                                <label class="colour-field">
                                    <input type="color" name="brand[<?= $colourKey ?>_color]" value="<?= hub_pack_ui_text($brand[$colourKey . '_color'] ?? $fallback) ?>" data-colour-input data-brand-colour="<?= $colourKey ?>" title="Choose <?= strtolower($label) ?> colour">
                                    <span><?= $label ?><small>Click the swatch to choose</small></span>
                                    <input class="form-control" type="text" value="<?= hub_pack_ui_text($brand[$colourKey . '_color'] ?? $fallback) ?>" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7" data-colour-text aria-label="<?= $label ?> hex colour">
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="brand-group">
                            <div class="font-library-heading">
                                <div><h3>Typography</h3><p>Add fonts once, then choose them for any text element below.</p></div>
                                <button type="button" class="btn btn-link btn-sm" data-font-add="library"><i class="fa-solid fa-plus me-1"></i>Add font</button>
                            </div>
                            <div class="font-library" data-font-library>
                                <?php foreach ($packFontChoices as $fontFamily => $fontLabel): ?>
                                    <span class="font-library__item" data-font-library-item="<?= hub_pack_ui_text($fontFamily) ?>" style="font-family:'<?= hub_pack_ui_text($fontFamily) ?>',sans-serif">
                                        <strong><?= hub_pack_ui_text($fontLabel) ?></strong><small>Aa Bb 123</small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <label class="form-field"><span>Default heading font</span><select class="form-select" name="brand[heading_font]" data-font-select="heading" data-pack-font-select><?php foreach ($packFontChoices as $fontFamily => $fontLabel): ?><option value="<?= hub_pack_ui_text($fontFamily) ?>" <?= ($brand['heading_font'] ?? 'Inter') === $fontFamily ? 'selected' : '' ?>><?= hub_pack_ui_text($fontLabel) ?></option><?php endforeach; ?></select></label>
                            <div class="font-live-sample" data-font-sample="heading" style="font-family:'<?= hub_pack_ui_text($brand['heading_font'] ?? 'Inter') ?>',sans-serif"><span>Heading preview</span><strong>Match Day Graphics</strong><small>ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789</small></div>
                            <label class="form-field"><span>Default body font</span><select class="form-select" name="brand[body_font]" data-font-select="body" data-pack-font-select><?php foreach ($packFontChoices as $fontFamily => $fontLabel): ?><option value="<?= hub_pack_ui_text($fontFamily) ?>" <?= ($brand['body_font'] ?? 'Inter') === $fontFamily ? 'selected' : '' ?>><?= hub_pack_ui_text($fontLabel) ?></option><?php endforeach; ?></select></label>
                            <div class="font-live-sample font-live-sample--body" data-font-sample="body" style="font-family:'<?= hub_pack_ui_text($brand['body_font'] ?? 'Inter') ?>',sans-serif"><span>Body preview</span><strong>Saltcoats Victoria v Opposition</strong><small>Saturday 3:00 PM · League fixture</small></div>
                            <label class="form-field"><span>Default numbers font</span><select class="form-select" name="brand[numbers_font]" data-font-select="numbers" data-pack-font-select><?php foreach ($packFontChoices as $fontFamily => $fontLabel): ?><option value="<?= hub_pack_ui_text($fontFamily) ?>" <?= ($brand['numbers_font'] ?? 'Inter') === $fontFamily ? 'selected' : '' ?>><?= hub_pack_ui_text($fontLabel) ?></option><?php endforeach; ?></select></label>
                            <div class="font-live-sample font-live-sample--numbers" data-font-sample="numbers" style="font-family:'<?= hub_pack_ui_text($brand['numbers_font'] ?? 'Inter') ?>',sans-serif"><span>Numbers preview</span><strong>2 – 1</strong><small>90:00 · Scores and match figures</small></div>
                            <p class="font-library-heading__hint">These three fonts are the only ones each graphic action can use, to keep every design consistent.</p>
                            <label class="form-field"><span>Heading style</span><select class="form-select" name="brand[heading_style]"><option value="uppercase" <?= ($brand['heading_style'] ?? '') === 'uppercase' ? 'selected' : '' ?>>Uppercase</option><option value="title" <?= ($brand['heading_style'] ?? 'title') === 'title' ? 'selected' : '' ?>>Title case</option><option value="natural" <?= ($brand['heading_style'] ?? '') === 'natural' ? 'selected' : '' ?>>As entered</option></select></label>
                        </div>
                        <div class="brand-group">
                            <h3>Badges & sponsors</h3>
                            <label class="form-field"><span>Badge treatment</span><select class="form-select" name="brand[badge_style]"><option value="colour" <?= ($brand['badge_style'] ?? 'colour') === 'colour' ? 'selected' : '' ?>>Full colour</option><option value="white" <?= ($brand['badge_style'] ?? '') === 'white' ? 'selected' : '' ?>>White</option><option value="framed" <?= ($brand['badge_style'] ?? '') === 'framed' ? 'selected' : '' ?>>Framed</option></select></label>
                            <label class="form-field"><span>Sponsor treatment</span><select class="form-select" name="brand[sponsor_style]"><option value="colour" <?= ($brand['sponsor_style'] ?? 'colour') === 'colour' ? 'selected' : '' ?>>Full colour</option><option value="white" <?= ($brand['sponsor_style'] ?? '') === 'white' ? 'selected' : '' ?>>White</option><option value="panel" <?= ($brand['sponsor_style'] ?? '') === 'panel' ? 'selected' : '' ?>>On a panel</option></select></label>
                            <label class="switch-field"><span><strong>Prefer white badges</strong><small>Use monochrome club badges where available.</small></span><input class="form-check-input" type="checkbox" role="switch" name="brand[use_white_badges]" value="1" <?= !empty($brand['use_white_badges']) ? 'checked' : '' ?>></label>
                            <label class="switch-field"><span><strong>Show sponsor names</strong><small>Add text when no suitable logo exists.</small></span><input class="form-check-input" type="checkbox" role="switch" name="brand[show_sponsor_names]" value="1" <?= !empty($brand['show_sponsor_names']) ? 'checked' : '' ?>></label>
                        </div>
                    </div>
                </form>

                <?php foreach ($actions as $actionKey => $action): ?>
                    <?php
                    $template = is_array($packActions[$actionKey] ?? null) ? $packActions[$actionKey] : [];
                    $layoutSettings = is_array($template['layout_settings'] ?? null) ? $template['layout_settings'] : [];
                    $layout = is_array($layoutSettings['elements'] ?? null) ? $layoutSettings['elements'] : [];
                    $isEditorialPack = $packId === 5;
                    $isReferenceStartingXi = $isEditorialPack && $actionKey === 'starting_xi';
                    $isReferenceNextMatch = $isEditorialPack && $actionKey === 'next_match';
                    $isReferenceKickOff = $isEditorialPack && $actionKey === 'kick_off';
                    $isReferenceRedCard = $isEditorialPack && $actionKey === 'red_card';
                    $isReferenceYellowCard = $isEditorialPack && $actionKey === 'yellow_card';
                    $isReferenceSubstitution = $isEditorialPack && $actionKey === 'substitution';
                    $isReferenceScorePoster = $isEditorialPack && in_array($actionKey, ['half_time', 'full_time'], true);
                    $isReferenceGoalPoster = $isEditorialPack && $actionKey === 'goal';
                    $isReferenceMotmPoster = $isEditorialPack && $actionKey === 'player_of_match';
                    $isReferencePlayerSponsorship = $isEditorialPack && $actionKey === 'player_sponsorship';
                    $isReferenceMonthlyFixtures = $isEditorialPack && $actionKey === 'monthly_fixtures';
                    $isReferenceNextUpDesign = $isReferenceNextMatch;
                    if ($isEditorialPack) {
                        // Merge newly supported elements into existing pack layouts
                        // without disturbing any positions already saved by the user.
                        $editorialDefaults = hub_pack_ui_editorial_elements($actionKey);
                        foreach ($editorialDefaults as $defaultElementKey => $defaultElement) {
                            if (isset($layout[$defaultElementKey]) && is_array($layout[$defaultElementKey])) {
                                $layout[$defaultElementKey] = array_replace($defaultElement, $layout[$defaultElementKey]);
                            } else {
                                $layout[$defaultElementKey] = $defaultElement;
                            }
                        }
                    } elseif ($layout === []) {
                        $layout = hub_pack_ui_default_elements($actionKey);
                    }
                    if ($actionKey === 'monthly_fixtures') {
                        // The heading/month text was dropped from this design; strip any
                        // stale positions a pack saved before that change.
                        unset($layout['headline'], $layout['month']);
                    }
                    $backgroundUrl = hub_pack_ui_background_url((string) ($template['background_path'] ?? ''));
                    $legacyCanvas = is_array($layoutSettings['canvas'] ?? null) ? $layoutSettings['canvas'] : [];
                    $canvasWidth = (int) ($layoutSettings['canvas_width'] ?? $legacyCanvas['width'] ?? $action['default_width'] ?? 1080);
                    $canvasHeight = (int) ($layoutSettings['canvas_height'] ?? ($isEditorialPack ? 1350 : ($legacyCanvas['height'] ?? $action['default_height'] ?? 1080)));
                    $actionConfigured = hub_pack_ui_action_configured($template);
                    $previewFitCandidate = (string) ($layoutSettings['background_fit'] ?? 'cover');
                    $previewFit = in_array($previewFitCandidate, ['cover', 'contain', 'stretch'], true)
                        ? $previewFitCandidate : 'cover';
                    $previewObjectFit = $previewFit === 'stretch' ? 'fill' : $previewFit;
                    $previewGradient = preg_match('/^#[0-9a-f]{6}$/i', (string) ($layoutSettings['gradient_color'] ?? ''))
                        ? (string) $layoutSettings['gradient_color'] : '#000000';
                    $previewGradientStrength = max(0, min(100, (int) ($layoutSettings['gradient_strength'] ?? 0)));
                    ?>
                    <section class="editor-panel action-editor" id="action-<?= hub_pack_ui_text($actionKey) ?>" data-action-editor="<?= hub_pack_ui_text($actionKey) ?>">
                        <div class="editor-panel__heading">
                            <div class="action-title">
                                <span class="action-title__icon"><i class="fa-solid <?= hub_pack_ui_text($action['icon'] ?? 'fa-image') ?>"></i></span>
                                <div><span class="section-kicker">Graphic action</span><h2><?= hub_pack_ui_text($action['label']) ?></h2><p><?= hub_pack_ui_text($action['description'] ?? 'Configure this graphic in the pack.') ?></p></div>
                            </div>
                            <span class="configuration-state <?= $actionConfigured ? 'is-ready' : '' ?>"><i class="fa-solid fa-circle"></i><?= $actionConfigured ? 'Configured' : 'Needs setup' ?></span>
                        </div>
                        <div class="pack-live-preview">
                            <div class="pack-live-preview__heading">
                                <div><span class="section-kicker">Live preview</span><h3><?= hub_pack_ui_text($action['label']) ?></h3><p>Click an element to select it. Drag to move, use its corner handles to resize, or press Ctrl/Cmd + Z to undo.</p></div>
                                <span data-preview-dimensions><?= $canvasWidth ?> × <?= $canvasHeight ?></span>
                            </div>
                            <div class="pack-live-preview__viewport" data-preview-viewport>
                                <div
                                    class="pack-live-preview__canvas<?= $isEditorialPack ? ' pack-live-preview__canvas--editorial-xi' : '' ?><?= $isReferenceNextUpDesign ? ' pack-live-preview__canvas--next-up' : '' ?><?= ($isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard) ? ' pack-live-preview__canvas--reference-kick-off' : '' ?><?= $isReferenceSubstitution ? ' pack-live-preview__canvas--substitution' : '' ?><?= $isReferenceScorePoster ? ' pack-live-preview__canvas--score-poster' : '' ?><?= $isReferenceGoalPoster ? ' pack-live-preview__canvas--goal-poster' : '' ?><?= $isReferenceMotmPoster ? ' pack-live-preview__canvas--motm-poster' : '' ?><?= $isReferencePlayerSponsorship ? ' pack-live-preview__canvas--player-sponsorship' : '' ?><?= $isReferenceMonthlyFixtures ? ' pack-live-preview__canvas--monthly-fixtures' : '' ?><?= $backgroundUrl !== '' ? ' has-background' : '' ?>"
                                    data-action-preview
                                    data-preview-action="<?= hub_pack_ui_text($actionKey) ?>"
                                    data-width="<?= $canvasWidth ?>"
                                    data-height="<?= $canvasHeight ?>"
                                    style="width:<?= $canvasWidth ?>px;height:<?= $canvasHeight ?>px;--preview-primary:<?= hub_pack_ui_text($brand['primary_color']) ?>;--preview-secondary:<?= hub_pack_ui_text($brand['secondary_color']) ?>;--preview-accent:<?= hub_pack_ui_text($brand['accent_color']) ?>;--preview-text:<?= hub_pack_ui_text($brand['text_color']) ?>;--preview-heading-font:'<?= hub_pack_ui_text($brand['heading_font']) ?>';--preview-body-font:'<?= hub_pack_ui_text($brand['body_font']) ?>';--preview-numbers-font:'<?= hub_pack_ui_text($brand['numbers_font']) ?>';<?= $isReferenceMonthlyFixtures ? '--preview-badge-size:' . max(50, min(220, (int) ($layoutSettings['badge_size'] ?? 165))) . 'px;--preview-card-gap:' . max(0, min(42, (int) ($layoutSettings['card_gap'] ?? 18))) . 'px;' : '' ?>"
                                >
                                    <div class="pack-live-preview__base"></div>
                                    <img class="pack-live-preview__background" data-preview-background src="<?= hub_pack_ui_text($backgroundUrl) ?>" style="<?= $backgroundUrl === '' ? 'display:none;' : '' ?>object-fit:<?= hub_pack_ui_text($previewObjectFit) ?>" alt="">
                                    <div class="pack-live-preview__gradient" data-preview-gradient style="<?= $isReferenceScorePoster
                                        ? 'background:linear-gradient(180deg,rgba(0,0,0,.88) 0%,rgba(0,0,0,.08) 38%,rgba(0,0,0,.12) 62%,rgba(0,0,0,.94) 100%);opacity:1'
                                        : 'background:' . hub_pack_ui_text($previewGradient) . ';opacity:' . number_format($previewGradientStrength / 100, 2, '.', '') ?>"></div>
                                    <div class="pack-live-preview__texture" aria-hidden="true"></div>
                                    <?php if ($isReferenceStartingXi): ?>
                                        <div class="editorial-xi__portrait" aria-hidden="true"></div>
                                    <?php endif; ?>
                                    <?php foreach ($layout as $elementKey => $element): ?>
                                        <?php if (!is_array($element)) continue; ?>
                                        <div
                                            class="pack-live-preview__element pack-live-preview__element--<?= hub_pack_ui_text($elementKey) ?>"
                                            data-preview-element="<?= hub_pack_ui_text($elementKey) ?>"
                                            tabindex="0"
                                            role="button"
                                            aria-label="Edit <?= hub_pack_ui_text($element['label'] ?? ucwords(str_replace('_', ' ', (string) $elementKey))) ?>"
                                            style="<?= hub_pack_ui_text(hub_pack_ui_preview_element_style($element)) ?>"
                                        >
                                            <?php if ($isReferenceMotmPoster && $elementKey === 'headline'): ?>
                                                <span>Man of</span><span>the Match</span>
                                            <?php elseif ($isReferenceMotmPoster && $elementKey === 'player_name'): ?>
                                                <span class="motm-player-name__first">Gary</span><span class="motm-player-name__surname">Brown</span>
                                            <?php elseif ($isReferenceMotmPoster && $elementKey === 'player_sponsors'): ?>
                                                <span class="goal-player-sponsor-preview is-available">
                                                    <small>HOME PLAYER SPONSOR</small>
                                                    <strong>Available for Sponsorship</strong>
                                                </span>
                                                <span class="goal-player-sponsor-preview is-available">
                                                    <small>AWAY PLAYER SPONSOR</small>
                                                    <strong>Available for Sponsorship</strong>
                                                </span>
                                            <?php elseif ($isReferenceMonthlyFixtures && $elementKey === 'club_badge'): ?>
                                                <img src="<?= ($layoutSettings['badge_style'] ?? 'white') === 'colour' ? '/admin/assets/images/Saltcoats%20Victoria%20FC.png' : '/badges/white/Saltcoats%20Victoria%20FC%20-White_Transparent.png' ?>" alt="">
                                            <?php elseif ($isEditorialPack && $elementKey === 'club_badge'): ?>
                                                <img src="/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png" alt="">
                                            <?php elseif ($isEditorialPack && $elementKey === 'opponent_badge'): ?>
                                                <img src="/badges/white/Kello Rovers.png" alt="">
                                            <?php elseif (($isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard) && $elementKey === 'badges'): ?>
                                                <img src="/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png" alt="">
                                                <img src="/badges/white/Kello Rovers.png" alt="">
                                            <?php elseif ($isReferenceKickOff && $elementKey === 'headline'): ?>
                                                <span>KICK</span><span>OFF</span>
                                            <?php elseif ($isReferenceRedCard && $elementKey === 'headline'): ?>
                                                <span>RED</span><span>CARD</span>
                                            <?php elseif ($isReferenceYellowCard && $elementKey === 'headline'): ?>
                                                <span>YELLOW</span><span>CARD</span>
                                            <?php elseif (($isReferenceRedCard || $isReferenceYellowCard) && $elementKey === 'player_name'): ?>
                                                Gary Brown
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'headline'): ?>
                                                <span>Subs</span>
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'player_on'): ?>
                                                <span class="preview-subs-tag">On</span>
                                                <span class="preview-subs-number">14</span>
                                                <span class="preview-subs-name">Ryan Ritchie</span>
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'player_off'): ?>
                                                <span class="preview-subs-name">Cameron McIntyre</span>
                                                <span class="preview-subs-number">8</span>
                                                <span class="preview-subs-tag">Off</span>
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'badges'): ?>
                                                <img src="/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png" alt="">
                                                <img src="/badges/white/Kello Rovers.png" alt="">
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'fixture_sponsors'): ?>
                                                <?php foreach ($templatePreviewFixtureSponsors as $fixtureSponsor): ?>
                                                    <?php if (!in_array((string) ($fixtureSponsor['sponsorship_role'] ?? ''), ['match_day', 'match_ball'], true)) continue; ?>
                                                    <small><?= (string) ($fixtureSponsor['sponsorship_role'] ?? '') === 'match_ball' ? 'MATCHBALL SPONSOR' : 'MATCHDAY SPONSOR' ?></small>
                                                    <?php if (trim((string) ($fixtureSponsor['sponsor_logo'] ?? '')) !== ''): ?>
                                                        <img src="/uploads/sponsors/<?= rawurlencode((string) $fixtureSponsor['sponsor_logo']) ?>" alt="<?= hub_pack_ui_text((string) ($fixtureSponsor['sponsor_name'] ?? 'Sponsor')) ?>">
                                                    <?php endif; ?>
                                                    <strong><?= hub_pack_ui_text((string) ($fixtureSponsor['sponsor_name'] ?? 'Sponsor')) ?></strong>
                                                <?php endforeach; ?>
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'sponsor_logos'): ?>
                                                <?php foreach ($templatePreviewSponsors as $previewSponsor): ?>
                                                    <?php
                                                    $previewSponsorLogo = trim((string) ($previewSponsor['white_logo_path'] ?? ''));
                                                    if ($previewSponsorLogo === '') $previewSponsorLogo = trim((string) ($previewSponsor['logo_path'] ?? ''));
                                                    ?>
                                                    <?php if ($previewSponsorLogo !== ''): ?><img src="/uploads/sponsors/<?= rawurlencode($previewSponsorLogo) ?>" alt="<?= hub_pack_ui_text((string) ($previewSponsor['name'] ?? 'Sponsor')) ?>"><?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php elseif ($isReferenceSubstitution && $elementKey === 'footer_meta'): ?>
                                                <img src="/uploads/competitions/competition-white-badge-20260721065339-0a69a31b.png" alt="Competition badge">
                                            <?php elseif (($isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard) && $elementKey === 'fixture'): ?>
                                                <strong data-next-up-venue>Campbell Park</strong>
                                                <span data-next-up-date>Wednesday 29th July - 7:30pm</span>
                                            <?php elseif (($isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard) && $elementKey === 'footer_meta'): ?>
                                                <img src="/uploads/competitions/competition-white-badge-20260721065339-0a69a31b.png" alt="Competition badge">
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'competition_meta'): ?>
                                                <img src="/uploads/competitions/competition-white-badge-20260721065339-0a69a31b.png" alt="Competition badge">
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'matchday_meta'): ?>
                                                5'
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'player_name'): ?>
                                                Gary Brown
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'headline'): ?>
                                                GOAL!
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'badges'): ?>
                                                <img src="/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png" alt="">
                                                <img src="/badges/white/Kello Rovers.png" alt="">
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'score'): ?>
                                                <span>1</span><i aria-hidden="true">-</i><span>0</span>
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'player_sponsors'): ?>
                                                <span class="goal-player-sponsor-preview is-available">
                                                    <small>HOME PLAYER SPONSOR</small>
                                                    <strong>Available for Sponsorship</strong>
                                                </span>
                                                <span class="goal-player-sponsor-preview is-available">
                                                    <small>AWAY PLAYER SPONSOR</small>
                                                    <strong>Available for Sponsorship</strong>
                                                </span>
                                            <?php elseif ($isReferencePlayerSponsorship && $elementKey === 'player_image'): ?>
                                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                                            <?php elseif ($isReferencePlayerSponsorship && $elementKey === 'player_sponsors'): ?>
                                                <div class="player-sponsorship-preview__info">
                                                    <span>HOME SPONSOR</span>
                                                    <strong>Available</strong>
                                                </div>
                                                <div class="player-sponsorship-preview__info">
                                                    <span>AWAY SPONSOR</span>
                                                    <strong>Available</strong>
                                                </div>
                                            <?php elseif ($isReferencePlayerSponsorship && $elementKey === 'player_name'): ?>
                                                <?= hub_pack_ui_text('SEASON 24/25') ?>
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'player_number'): ?>
                                                WEDNESDAY 29TH JULY 2026
                                            <?php elseif ($isReferenceGoalPoster && $elementKey === 'venue'): ?>
                                                CAMPBELL PARK
                                            <?php elseif ($isReferenceNextUpDesign && $elementKey === 'badges'): ?>
                                                <span class="next-up-preview__accent" aria-hidden="true"></span>
                                                <img src="/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png" alt="">
                                                <img src="/uploads/competitions/competition-white-badge-20260721065339-0a69a31b.png" alt="">
                                                <img src="/badges/white/Kello Rovers.png" alt="">
                                            <?php elseif ($isReferenceNextUpDesign && $elementKey === 'headline'): ?>
                                                Next Up
                                            <?php elseif ($isReferenceNextUpDesign && $elementKey === 'fixture'): ?>
                                                <strong data-next-up-venue>Campbell Park</strong>
                                                <span data-next-up-date>Wednesday 29th July, 7:30pm</span>
                                            <?php elseif ($isReferenceScorePoster && $elementKey === 'sponsor_logos'): ?>
                                                <?php foreach ($templatePreviewSponsors as $previewSponsor): ?>
                                                    <?php
                                                    $previewSponsorLogo = trim((string) ($previewSponsor['white_logo_path'] ?? ''));
                                                    if ($previewSponsorLogo === '') $previewSponsorLogo = trim((string) ($previewSponsor['logo_path'] ?? ''));
                                                    ?>
                                                    <?php if ($previewSponsorLogo !== ''): ?><img src="/uploads/sponsors/<?= rawurlencode($previewSponsorLogo) ?>" alt="<?= hub_pack_ui_text((string) ($previewSponsor['name'] ?? 'Sponsor')) ?>"><?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php elseif (($isReferenceNextUpDesign || $isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard || $isReferenceScorePoster) && $elementKey === 'fixture_sponsors'): ?>
                                                <?php foreach ($templatePreviewFixtureSponsors as $fixtureSponsor): ?>
                                                    <?php if (!in_array((string) ($fixtureSponsor['sponsorship_role'] ?? ''), ['match_day', 'match_ball'], true)) continue; ?>
                                                    <small><?= (string) ($fixtureSponsor['sponsorship_role'] ?? '') === 'match_ball' ? 'MATCHBALL SPONSOR' : 'MATCHDAY SPONSOR' ?></small>
                                                    <?php if (trim((string) ($fixtureSponsor['sponsor_logo'] ?? '')) !== ''): ?>
                                                        <img src="/uploads/sponsors/<?= rawurlencode((string) $fixtureSponsor['sponsor_logo']) ?>" alt="<?= hub_pack_ui_text((string) ($fixtureSponsor['sponsor_name'] ?? 'Sponsor')) ?>">
                                                    <?php endif; ?>
                                                    <strong><?= hub_pack_ui_text((string) ($fixtureSponsor['sponsor_name'] ?? 'Sponsor')) ?></strong>
                                                <?php endforeach; ?>
                                            <?php elseif (($isReferenceNextUpDesign || $isReferenceKickOff || $isReferenceRedCard || $isReferenceYellowCard) && $elementKey === 'sponsor_logos'): ?>
                                                <?php foreach ($templatePreviewSponsors as $previewSponsor): ?>
                                                    <?php
                                                    $previewSponsorLogo = trim((string) ($previewSponsor['white_logo_path'] ?? ''));
                                                    if ($previewSponsorLogo === '') $previewSponsorLogo = trim((string) ($previewSponsor['logo_path'] ?? ''));
                                                    ?>
                                                    <?php if ($previewSponsorLogo !== ''): ?><img src="/uploads/sponsors/<?= rawurlencode($previewSponsorLogo) ?>" alt="<?= hub_pack_ui_text((string) ($previewSponsor['name'] ?? 'Sponsor')) ?>"><?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php elseif ($isReferenceStartingXi && $elementKey === 'lineup'): ?>
                                                <?php foreach ([['01', 'J. SINCLAIR'], ['02', 'L. MCARTHUR'], ['03', 'R. BOYLE'], ['04', 'D. MILLER'], ['05', 'C. MCKENZIE'], ['06', 'A. WILSON'], ['07', 'K. MORRISON'], ['08', 'S. BROWN'], ['09', 'M. STEWART'], ['10', 'J. MURPHY'], ['11', 'R. CAMPBELL']] as $playerIndex => [$number, $playerName]): ?>
                                                    <span class="editorial-xi__player"><small><?= $number ?></small><strong><?= $playerName ?></strong><?= $playerIndex === 6 ? '<em>C</em>' : '' ?></span>
                                                <?php endforeach; ?>
                                            <?php elseif ($isReferenceStartingXi && $elementKey === 'substitutes'): ?>
                                                <strong>SUBSTITUTES</strong>
                                                <span>TAYLOR, REID, HUNTER, KELLY, ANDERSON, FRASER, YOUNG</span>
                                            <?php elseif ($isReferenceStartingXi && $elementKey === 'sponsor_logos'): ?>
                                                <?php foreach ($templatePreviewSponsors as $previewSponsor): ?>
                                                    <?php
                                                    $previewSponsorLogo = trim((string) ($previewSponsor['white_logo_path'] ?? ''));
                                                    if ($previewSponsorLogo === '') {
                                                        $previewSponsorLogo = trim((string) ($previewSponsor['logo_path'] ?? ''));
                                                    }
                                                    ?>
                                                    <?php if ($previewSponsorLogo !== ''): ?>
                                                        <img src="/uploads/sponsors/<?= rawurlencode($previewSponsorLogo) ?>" alt="<?= hub_pack_ui_text((string) ($previewSponsor['name'] ?? 'Sponsor')) ?>">
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php elseif ($isReferenceStartingXi && $elementKey === 'matchday_sponsors'): ?>
                                                <?php
                                                $previewMatchdayBallSeen = [];
                                                foreach ($templatePreviewFixtureSponsors as $previewFixtureSponsor):
                                                    $previewRole = (string) ($previewFixtureSponsor['sponsorship_role'] ?? '');
                                                    if (!in_array($previewRole, ['match_day', 'match_ball'], true) || isset($previewMatchdayBallSeen[$previewRole])) continue;
                                                    $previewMatchdayBallSeen[$previewRole] = true;
                                                    $previewMatchdayLogo = trim((string) ($previewFixtureSponsor['sponsor_logo'] ?? ''));
                                                ?>
                                                    <?php if ($previewMatchdayLogo !== ''): ?>
                                                        <img src="/uploads/sponsors/<?= rawurlencode($previewMatchdayLogo) ?>" alt="<?= hub_pack_ui_text((string) ($previewFixtureSponsor['sponsor_name'] ?? 'Sponsor')) ?>">
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php elseif ($isEditorialPack && $elementKey === 'sponsor'): ?>
                                                <img src="/uploads/competitions/competition-white-badge-20260721065339-0a69a31b.png" alt="">
                                            <?php elseif ($isReferenceScorePoster && $elementKey === 'headline_first'): ?>
                                                <?= $actionKey === 'half_time' ? 'Half' : 'Full' ?>
                                            <?php elseif ($isReferenceScorePoster && $elementKey === 'headline_second'): ?>
                                                Time
                                            <?php elseif ($isReferenceScorePoster && $elementKey === 'score'): ?>
                                                <span>2</span><i aria-hidden="true">–</i><span>1</span>
                                            <?php elseif ($isReferenceStartingXi && $elementKey === 'headline'): ?>
                                                STARTING XI V KELLO ROVERS
                                            <?php elseif ($isEditorialPack && $elementKey === 'fixture'): ?>
                                                <strong>SALTCOATS VICTORIA</strong><small>V</small><strong>KELLO ROVERS</strong>
                                            <?php elseif ($isEditorialPack && $elementKey === 'player_image'): ?>
                                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                                            <?php elseif ($isReferenceMonthlyFixtures && $elementKey === 'legend'): ?>
                                                <span class="monthly-fixtures-preview__chip monthly-fixtures-preview__chip--home">HOME</span>
                                                <span class="monthly-fixtures-preview__chip monthly-fixtures-preview__chip--away">AWAY</span>
                                            <?php elseif ($isReferenceMonthlyFixtures && $elementKey === 'month_heading'): ?>
                                                <strong>SEPTEMBER FIXTURES</strong>
                                            <?php elseif ($isReferenceMonthlyFixtures && $elementKey === 'fixtures'): ?>
                                                <div class="monthly-fixtures-preview__grid">
                                                    <?php
                                                    $monthlyPreviewFixtures = [
                                                        ['Kello Rovers.png', 'Kello Rovers', 'SEPT 13', true, null],
                                                        ['Carluke Rovers.png', 'Carluke Rovers', 'SEPT 22', false, null],
                                                        ['East Kilbride Thistle.png', 'East Kilbride Thistle', 'SEPT 27', true, '2-1'],
                                                        ['Rossvale.png', 'Rossvale', 'OCT 4', false, '0-0'],
                                                        ['Royal Albert.png', 'Royal Albert', 'OCT 18', true, null],
                                                        ['Wishaw FC.png', 'Wishaw FC', 'OCT 25', false, null],
                                                    ];
                                                    ?>
                                                    <?php foreach ($monthlyPreviewFixtures as [$previewBadge, $previewName, $previewDate, $previewIsHome, $previewScore]): ?>
                                                        <?php
                                                        // Illustrative only: tint the sample white badges the same way the
                                                        // live canvas does, regardless of the saved badge-style setting.
                                                        $previewTintColour = $previewIsHome ? (string) $brand['accent_color'] : (string) $brand['primary_color'];
                                                        $previewBadgeUrl = monthlyFixturesTintedBadgeUrl('/badges/white/' . $previewBadge, $previewTintColour);
                                                        $previewResult = null;
                                                        if ($previewScore !== null) {
                                                            [$previewHomeGoals, $previewAwayGoals] = array_map('intval', explode('-', $previewScore));
                                                            $previewOurGoals = $previewIsHome ? $previewHomeGoals : $previewAwayGoals;
                                                            $previewTheirGoals = $previewIsHome ? $previewAwayGoals : $previewHomeGoals;
                                                            $previewResult = $previewOurGoals > $previewTheirGoals ? 'W' : ($previewOurGoals < $previewTheirGoals ? 'L' : 'D');
                                                        }
                                                        ?>
                                                        <div class="monthly-fixtures-preview__card <?= $previewIsHome ? 'is-home' : 'is-away' ?>">
                                                            <time><?= $previewDate ?></time>
                                                            <span class="monthly-fixtures-preview__location"><?= $previewIsHome ? 'H' : 'A' ?></span>
                                                            <img src="<?= hub_pack_ui_text($previewBadgeUrl) ?>" alt="">
                                                            <strong><?= hub_pack_ui_text(strtoupper($previewName)) ?></strong>
                                                            <?php if ($previewResult !== null): ?>
                                                                <span class="monthly-fixtures-preview__result"><?= $previewResult ?></span>
                                                                <span class="monthly-fixtures-preview__score"><?= hub_pack_ui_text($previewScore) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($isReferenceMonthlyFixtures && $elementKey === 'footer_meta'): ?>
                                                <strong>ALL FIXTURES ARE SUBJECT TO CHANGE</strong>
                                                <span>SALTCOATS VICTORIA FC</span>
                                            <?php else: ?>
                                                <?= nl2br(hub_pack_ui_text(hub_pack_ui_preview_text($actionKey, (string) $elementKey))) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$isEditorialPack): ?><span class="pack-live-preview__brand-mark">SVFC</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="action-workspace">
                            <form class="artwork-panel" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_action_background">
                                <input type="hidden" name="pack_id" value="<?= $packId ?>">
                                <input type="hidden" name="action_key" value="<?= hub_pack_ui_text($actionKey) ?>">
                                <div
                                    class="artwork-preview <?= $backgroundUrl === '' ? 'is-empty' : '' ?>"
                                    data-artwork-preview
                                    data-artwork-drop
                                    role="button"
                                    tabindex="0"
                                    aria-label="<?= $backgroundUrl === '' ? 'Choose or drop a background image' : 'Choose or drop a replacement background image' ?>"
                                    aria-describedby="artwork-status-<?= hub_pack_ui_text($actionKey) ?>"
                                >
                                    <?php if ($backgroundUrl !== ''): ?><img src="<?= hub_pack_ui_text($backgroundUrl) ?>" alt="<?= hub_pack_ui_text($action['label']) ?> background"><?php endif; ?>
                                    <div class="artwork-empty">
                                        <i class="fa-regular fa-image"></i>
                                        <strong>No background yet</strong>
                                        <span>PNG, JPG or WEBP up to 25 MB</span>
                                    </div>
                                    <span class="artwork-preview__prompt"><i class="fa-solid fa-cloud-arrow-up"></i><?= $backgroundUrl === '' ? 'Drop or choose background' : 'Drop or choose replacement' ?></span>
                                    <span class="artwork-size"><?= $canvasWidth ?> × <?= $canvasHeight ?></span>
                                </div>
                                <input class="artwork-file-input" type="file" name="background" accept="image/png,image/jpeg,image/webp" data-artwork-input tabindex="-1" aria-hidden="true">
                                <div class="artwork-actions">
                                    <span class="small text-secondary" id="artwork-status-<?= hub_pack_ui_text($actionKey) ?>" data-artwork-status aria-live="polite">Uploads automatically after selection.</span>
                                    <?php if ($backgroundUrl !== ''): ?><button class="btn btn-link text-danger btn-sm" type="button" data-remove-background="<?= hub_pack_ui_text($actionKey) ?>">Remove</button><?php endif; ?>
                                </div>
                            </form>

                            <form class="layout-panel" method="post" data-layout-form>
                                <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_action_layout">
                                <input type="hidden" name="pack_id" value="<?= $packId ?>">
                                <input type="hidden" name="action_key" value="<?= hub_pack_ui_text($actionKey) ?>">
                                <input type="hidden" name="layout_json" value="<?= hub_pack_ui_text(json_encode($layout, JSON_UNESCAPED_SLASHES) ?: '{}') ?>" data-layout-json>
                                <div class="layout-panel__top">
                                    <div><h3>Canvas & layout</h3><p>Position key content on a consistent design canvas.</p></div>
                                    <button class="btn btn-brand btn-sm" type="submit" <?= $backendReady ? '' : 'disabled' ?>>Save layout</button>
                                </div>
                                <div class="canvas-fields">
                                    <label class="form-field"><span>Width</span><div class="input-group"><input class="form-control" type="number" name="canvas_width" min="320" max="4096" value="<?= $canvasWidth ?>"><span class="input-group-text">px</span></div></label>
                                    <label class="form-field"><span>Height</span><div class="input-group"><input class="form-control" type="number" name="canvas_height" min="320" max="4096" value="<?= $canvasHeight ?>"><span class="input-group-text">px</span></div></label>
                                    <label class="form-field"><span>Format</span><select class="form-select" name="layout"><option value="square" <?= ($layoutSettings['layout'] ?? 'square') === 'square' ? 'selected' : '' ?>>Square</option><option value="portrait" <?= ($layoutSettings['layout'] ?? '') === 'portrait' ? 'selected' : '' ?>>Portrait</option><option value="landscape" <?= ($layoutSettings['layout'] ?? '') === 'landscape' ? 'selected' : '' ?>>Landscape</option></select></label>
                                    <label class="form-field"><span>Background fit</span><select class="form-select" name="background_fit"><option value="cover" <?= ($layoutSettings['background_fit'] ?? 'cover') === 'cover' ? 'selected' : '' ?>>Cover</option><option value="contain" <?= ($layoutSettings['background_fit'] ?? '') === 'contain' ? 'selected' : '' ?>>Contain</option><option value="stretch" <?= ($layoutSettings['background_fit'] ?? '') === 'stretch' ? 'selected' : '' ?>>Stretch</option></select></label>
                                    <label class="form-field"><span>Gradient</span><input class="form-control" type="color" name="gradient_color" value="<?= hub_pack_ui_text($layoutSettings['gradient_color'] ?? '#000000') ?>"></label>
                                    <label class="form-field"><span>Gradient strength</span><div class="input-group"><input class="form-control" type="number" name="gradient_strength" min="0" max="100" value="<?= (int) ($layoutSettings['gradient_strength'] ?? 0) ?>"><span class="input-group-text">%</span></div></label>
                                    <?php if ($actionKey === 'monthly_fixtures'): ?>
                                        <label class="form-field"><span>Badge size</span><div class="input-group"><input class="form-control" type="number" name="badge_size" min="50" max="220" value="<?= (int) ($layoutSettings['badge_size'] ?? 165) ?>"><span class="input-group-text">px</span></div></label>
                                        <label class="form-field"><span>Card gap</span><div class="input-group"><input class="form-control" type="number" name="card_gap" min="0" max="42" value="<?= (int) ($layoutSettings['card_gap'] ?? 18) ?>"><span class="input-group-text">px</span></div></label>
                                        <label class="form-field"><span>Badge style</span><select class="form-select" name="badge_style"><option value="white" <?= ($layoutSettings['badge_style'] ?? 'white') === 'white' ? 'selected' : '' ?>>White</option><option value="colour" <?= ($layoutSettings['badge_style'] ?? 'white') === 'colour' ? 'selected' : '' ?>>Full colour</option></select></label>
                                    <?php endif; ?>
                                </div>
                                <div class="element-list" data-element-list>
                                    <?php foreach ($layout as $elementKey => $element): ?>
                                        <?php if (!is_array($element)) continue; ?>
                                        <div class="layout-element" data-layout-element data-element-key="<?= hub_pack_ui_text($elementKey) ?>">
                                            <button class="layout-element__toggle" type="button" aria-expanded="false">
                                                <span><i class="fa-solid fa-grip-vertical"></i><strong><?= hub_pack_ui_text($element['label'] ?? ucwords(str_replace('_', ' ', (string) $elementKey))) ?></strong></span>
                                                <i class="fa-solid fa-chevron-down"></i>
                                            </button>
                                            <div class="layout-element__fields" hidden>
                                                <?php foreach (['x' => 'X', 'y' => 'Y', 'width' => 'Width', 'height' => 'Height'] as $field => $label): ?>
                                                    <label class="form-field"><span><?= $label ?></span><input class="form-control" type="number" data-layout-field="<?= $field ?>" value="<?= hub_pack_ui_text($element[$field] ?? 0) ?>"></label>
                                                <?php endforeach; ?>
                                                <label class="switch-field"><span><strong>Visible</strong></span><input class="form-check-input" type="checkbox" role="switch" data-layout-field="visible" <?= !array_key_exists('visible', $element) || !empty($element['visible']) ? 'checked' : '' ?>></label>
                                                <div class="element-typography-controls">
                                                    <strong>Typography</strong>
                                                    <label class="form-field"><span>Font size</span><input class="form-control" type="number" data-layout-field="font_size" value="<?= hub_pack_ui_text($element['font_size'] ?? 0) ?>"></label>
                                                    <label class="form-field"><span>Alignment</span><select class="form-select" data-layout-field="text_align"><?php foreach (['left', 'center', 'right'] as $align): ?><option value="<?= $align ?>" <?= ($element['text_align'] ?? 'left') === $align ? 'selected' : '' ?>><?= ucfirst($align) ?></option><?php endforeach; ?></select></label>
                                                    <label class="form-field"><span>Colour</span><input class="form-control" type="color" data-layout-field="color" value="<?= hub_pack_ui_text($element['color'] ?? '#ffffff') ?>"></label>
                                                    <?php if (hub_pack_ui_is_text_element((string) $elementKey, $element)): ?>
                                                        <?php
                                                        $usesBodyDefault = in_array((string) $elementKey, ['fixture', 'substitutes', 'fixture_sponsors', 'player_sponsors', 'matchday_meta', 'player_number', 'venue'], true)
                                                            || ($actionKey === 'player_of_match' && $elementKey === 'player_name');
                                                        $defaultFontLabel = $usesBodyDefault ? 'Default body font' : 'Default heading font';
                                                        ?>
                                                        <label class="form-field form-field--wide"><span>Font</span><select class="form-select" data-layout-field="font_family" data-element-font-select><option value=""><?= hub_pack_ui_text($defaultFontLabel) ?></option><?php foreach ($brandFontChoices as $fontFamily => $fontLabel): ?><option value="<?= hub_pack_ui_text($fontFamily) ?>" <?= ($element['font_family'] ?? '') === $fontFamily ? 'selected' : '' ?>><?= hub_pack_ui_text($fontLabel) ?></option><?php endforeach; ?></select></label>
                                                        <label class="form-field"><span>Font weight</span><select class="form-select" data-layout-field="font_weight"><option value="">Style default</option><?php foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900] as $weight): ?><option value="<?= $weight ?>" <?= (int) ($element['font_weight'] ?? 0) === $weight ? 'selected' : '' ?>><?= $weight ?></option><?php endforeach; ?></select></label>
                                                        <label class="form-field"><span>Letter spacing</span><div class="input-group"><input class="form-control" type="number" min="-20" max="100" step="0.1" data-layout-field="letter_spacing" value="<?= array_key_exists('letter_spacing', $element) ? hub_pack_ui_text((string) $element['letter_spacing']) : '' ?>"><span class="input-group-text">px</span></div></label>
                                                        <label class="form-field"><span>Line height</span><input class="form-control" type="number" min="0.5" max="3" step="0.01" data-layout-field="line_height" value="<?= array_key_exists('line_height', $element) ? hub_pack_ui_text((string) $element['line_height']) : '' ?>" placeholder="Style default"></label>
                                                        <?php $textTransformValue = (string) ($element['text_transform'] ?? ''); ?>
                                                        <label class="form-field"><span>Text case</span><select class="form-select" data-layout-field="text_transform"><option value="" <?= $textTransformValue === '' ? 'selected' : '' ?>>Style default</option><option value="none" <?= $textTransformValue === 'none' ? 'selected' : '' ?>>As entered</option><option value="uppercase" <?= $textTransformValue === 'uppercase' ? 'selected' : '' ?>>UPPERCASE</option><option value="lowercase" <?= $textTransformValue === 'lowercase' ? 'selected' : '' ?>>lowercase</option><option value="capitalize" <?= $textTransformValue === 'capitalize' ? 'selected' : '' ?>>Capitalize Each Word</option></select></label>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($isReferenceNextUpDesign && $elementKey === 'fixture'): ?>
                                                    <?php
                                                    $fixtureFontChoices = array_keys($brandFontChoices);
                                                    ?>
                                                    <div class="element-typography-split">
                                                        <div>
                                                            <strong>Venue typography</strong>
                                                            <label class="form-field"><span>Font</span><select class="form-select" data-layout-field="venue_font"><?php foreach ($fixtureFontChoices as $font): ?><option value="<?= hub_pack_ui_text($font) ?>" <?= ($element['venue_font'] ?? 'Poppins') === $font ? 'selected' : '' ?>><?= hub_pack_ui_text($font) ?></option><?php endforeach; ?></select></label>
                                                            <label class="form-field"><span>Weight</span><select class="form-select" data-layout-field="venue_weight"><?php foreach ([300, 400, 500, 600, 700, 800, 900] as $weight): ?><option value="<?= $weight ?>" <?= (int) ($element['venue_weight'] ?? 600) === $weight ? 'selected' : '' ?>><?= $weight ?></option><?php endforeach; ?></select></label>
                                                        </div>
                                                        <div>
                                                            <strong>Date typography</strong>
                                                            <label class="form-field"><span>Font</span><select class="form-select" data-layout-field="date_font"><?php foreach ($fixtureFontChoices as $font): ?><option value="<?= hub_pack_ui_text($font) ?>" <?= ($element['date_font'] ?? 'Poppins') === $font ? 'selected' : '' ?>><?= hub_pack_ui_text($font) ?></option><?php endforeach; ?></select></label>
                                                            <label class="form-field"><span>Weight</span><select class="form-select" data-layout-field="date_weight"><?php foreach ([300, 400, 500, 600, 700, 800, 900] as $weight): ?><option value="<?= $weight ?>" <?= (int) ($element['date_weight'] ?? 500) === $weight ? 'selected' : '' ?>><?= $weight ?></option><?php endforeach; ?></select></label>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($layout === []): ?>
                                        <div class="layout-empty"><i class="fa-solid fa-object-group"></i><div><strong>Default layout</strong><span>Elements will inherit the renderer defaults until positions are added.</span></div></div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if (!empty($selectedPack['versions']) && is_array($selectedPack['versions'])): ?>
                    <section class="editor-panel versions-panel">
                        <div class="editor-panel__heading"><div><span class="section-kicker">History</span><h2>Published versions</h2><p>Fixtures retain the exact version assigned to them.</p></div></div>
                        <div class="version-list">
                            <?php foreach ($selectedPack['versions'] as $version): ?>
                                <div class="version-row"><span class="version-row__icon"><i class="fa-solid fa-code-branch"></i></span><div><strong>Version <?= hub_pack_ui_text($version['version_number'] ?? '') ?></strong><span><?= hub_pack_ui_text($version['published_at'] ?? '') ?></span></div><?php if ((int) ($version['id'] ?? 0) === (int) ($selectedPack['current_published_version_id'] ?? 0)): ?><span class="pack-badge is-published">Current</span><?php endif; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($selectedPack): ?>
<div class="modal fade" id="fontUploadModal" tabindex="-1" aria-labelledby="fontUploadTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content pack-modal font-upload-modal" method="post" enctype="multipart/form-data" data-font-upload-form>
            <div class="modal-header">
                <div>
                    <span class="section-kicker">Custom typography</span>
                    <h2 class="modal-title fs-5" id="fontUploadTitle">Add font</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_font">
                <input type="hidden" name="pack_id" value="<?= $packId ?>">
                <input type="hidden" name="font_role" value="library" data-font-modal-role>
                <p class="font-upload-modal__intro">Upload a web font once, then select it for any text element in this pack.</p>
                <label class="font-modal-drop" data-font-modal-drop>
                    <input type="file" name="font_file" required accept=".woff2,.woff,.ttf,.otf,font/woff2,font/woff,font/ttf,font/otf" data-font-modal-input>
                    <span class="font-modal-drop__icon"><i class="fa-solid fa-font"></i></span>
                    <strong data-font-modal-prompt>Choose a font file</strong>
                    <span>Drop a WOFF2, WOFF, TTF or OTF file here, or click to browse.</span>
                    <small>Maximum file size: 8 MB</small>
                </label>
                <div class="alert d-none mb-0" role="status" data-font-modal-status></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand" data-font-modal-submit><i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload font</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="createPackModal" tabindex="-1" aria-labelledby="createPackTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content pack-modal" method="post">
            <div class="modal-header">
                <div><span class="section-kicker">New design system</span><h2 class="modal-title fs-5" id="createPackTitle">Create a template pack</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_pack">
                <label class="form-field"><span>Pack name</span><input class="form-control" type="text" name="name" maxlength="100" required placeholder="e.g. 2026 Maroon"></label>
                <label class="form-field"><span>Description <small>Optional</small></span><textarea class="form-control" name="description" rows="3" maxlength="300" placeholder="What is this design pack for?"></textarea></label>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand">Create pack</button></div>
        </form>
    </div>
</div>

<form method="post" class="visually-hidden" data-pack-action-form>
    <input type="hidden" name="csrf_token" value="<?= hub_pack_ui_text(hub_auth_csrf_token()) ?>">
    <input type="hidden" name="action" value="">
    <input type="hidden" name="pack_id" value="">
    <input type="hidden" name="action_key" value="">
    <input type="hidden" name="name" value="">
</form>

<script src="/admin/assets/js/template-packs.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/template-packs.js') ?: time()) ?>" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
