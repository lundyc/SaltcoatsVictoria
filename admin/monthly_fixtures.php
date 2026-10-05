<?php
declare(strict_types=1);

$pageStyles = ['monthly-fixtures.css'];
$pageHero = [
    'eyebrow' => 'Creative studio',
    'title' => 'Monthly Fixtures',
    'subtitle' => 'Design, download and publish a complete month of fixtures.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/monthly_fixtures.php';
require_once __DIR__ . '/lib/template_pack_render.php';
require_once __DIR__ . '/social_post_settings.php';

$seasonId = (int)($_REQUEST['season_id'] ?? getSelectedSeasonId($pdo));
$season = getSeasonById($pdo, $seasonId);
if (!$season) {
    $seasonId = getSelectedSeasonId($pdo);
    $season = getSeasonById($pdo, $seasonId);
}
if (!$season) {
    echo '<div class="alert alert-danger">Create a season before using monthly fixture graphics.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$months = monthlyFixturesSeasonMonths($pdo, $seasonId);
$selectedMonth = monthlyFixturesSelectMonth($months, (string)($_REQUEST['month'] ?? ''));
$layout = monthlyFixturesLayout((string)($_REQUEST['layout'] ?? 'block'));
$uploadError = '';

$ajaxRequest = (string)($_POST['ajax'] ?? '') === '1'
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/** @param array<string, mixed> $payload */
$sendMonthlyFixturesJson = static function (array $payload, int $status = 200): never {
    // header.php opens output buffering for the page chrome; discard it so the
    // response body is pure JSON, not [buffered HTML] + [JSON].
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $uploadError = 'Invalid security token. Please try again.';
        if ($ajaxRequest) {
            $sendMonthlyFixturesJson(['ok' => false, 'message' => $uploadError], 400);
        }
    } else {
        $action = (string)($_POST['action'] ?? 'upload_background');
        if ($action === 'remove_background') {
            monthlyFixturesRemoveBackground($seasonId);
            if ($ajaxRequest) {
                $sendMonthlyFixturesJson(['ok' => true, 'message' => 'Custom background removed. The theme artwork is now being used.']);
            }
            header('Location: monthly_fixtures.php?' . http_build_query(['season_id' => $seasonId, 'month' => $selectedMonth, 'layout' => $layout, 'background_removed' => 1]));
            exit;
        }
        $upload = monthlyFixturesStoreBackground($_FILES['background_image'] ?? [], $seasonId);
        if ($upload['error'] !== '') {
            $uploadError = $upload['error'];
            if ($ajaxRequest) {
                $sendMonthlyFixturesJson(['ok' => false, 'message' => $uploadError], 422);
            }
        } else {
            if ($ajaxRequest) {
                $imageUrl = monthlyFixturesBackgroundUrl($seasonId);
                $imageUrl .= $imageUrl !== '' ? '?v=' . (int)(@filemtime(monthlyFixturesBackgroundPath($seasonId)) ?: time()) : '';
                $sendMonthlyFixturesJson(['ok' => true, 'image_url' => $imageUrl, 'filename' => basename(monthlyFixturesBackgroundPath($seasonId)), 'message' => 'Monthly fixture background updated.']);
            }
            header('Location: monthly_fixtures.php?' . http_build_query(['season_id' => $seasonId, 'month' => $selectedMonth, 'layout' => $layout, 'background_uploaded' => 1]));
            exit;
        }
    }
}

$fixtures = monthlyFixturesForMonth($pdo, $seasonId, $selectedMonth);
$fixtureCount = count($fixtures);
$selectedMonthTimestamp = strtotime($selectedMonth . '-01') ?: time();
$selectedMonthLabel = date('F Y', $selectedMonthTimestamp);
$templateContext = monthlyFixturesTemplateContext($pdo, $seasonId);
$brand = (array)($templateContext['brand_settings'] ?? []);
$templateAction = (array)($templateContext['action'] ?? []);
$layoutSettings = (array)($templateAction['layout_settings'] ?? []);
$elements = monthlyFixturesDefaultElements();
foreach ((array)($layoutSettings['elements'] ?? []) as $key => $element) {
    if (isset($elements[$key]) && is_array($element)) {
        $elements[$key] = array_replace($elements[$key], $element);
    }
}

$canvasWidth = max(400, min(4096, (int)($layoutSettings['canvas_width'] ?? 981)));
$canvasHeight = max(400, min(4096, (int)($layoutSettings['canvas_height'] ?? 736)));
$primary = matchTemplatePackColour($brand, 'primary', '#6a2036');
$secondary = matchTemplatePackColour($brand, 'secondary', '#f6ecde');
$accent = matchTemplatePackColour($brand, 'accent', '#e0b42a');
$textColour = matchTemplatePackColour($brand, 'text', '#ffffff');
$headingFont = matchTemplatePackFont($brand, 'heading', 'Montserrat');
$bodyFont = matchTemplatePackFont($brand, 'body', 'Montserrat');
$headingControlFont = trim((string)($elements['legend']['font_family'] ?? '')) ?: $headingFont;
$bodyControlFont = trim((string)($elements['fixtures']['font_family'] ?? '')) ?: $bodyFont;
$legendControlSize = max(20, min(64, (int)($elements['legend']['font_size'] ?? 38)));
$fixtureControlSize = max(10, min(36, (int)($elements['fixtures']['font_size'] ?? 18)));
$badgeControlSize = max(50, min(220, (int)($layoutSettings['badge_size'] ?? 165)));
$cardGap = max(0, min(42, (int)($layoutSettings['card_gap'] ?? 18)));
$badgeStyle = (string)($layoutSettings['badge_style'] ?? '') === 'colour' ? 'colour' : 'white';
$clubBadgeWhite = '/badges/white/admin/Saltcoats Victoria FC -White_Transparent.png';
$clubBadgeColour = '/admin/assets/images/Saltcoats Victoria FC.png';
$gradientColour = preg_match('/^#[0-9a-f]{6}$/i', (string)($layoutSettings['gradient_color'] ?? '')) ? (string)$layoutSettings['gradient_color'] : '#160f12';
$gradientStrength = max(0, min(100, (int)($layoutSettings['gradient_strength'] ?? 35)));
// html2canvas (used for PNG export) cannot parse CSS color-mix(), so any colour
// blending needed on the exported canvas is precomputed here as plain rgba()/hex.
[$gradientRed, $gradientGreen, $gradientBlue] = monthlyFixturesHexToRgb($gradientColour);
$gradientStop1 = sprintf('rgba(%d,%d,%d,%s)', $gradientRed, $gradientGreen, $gradientBlue, number_format($gradientStrength / 100, 3, '.', ''));
$gradientStop2 = sprintf('rgba(%d,%d,%d,.85)', $gradientRed, $gradientGreen, $gradientBlue);
$primaryInk = monthlyFixturesScaleHex($primary, 0.8);
$customBackgroundUrl = monthlyFixturesBackgroundUrl($seasonId);
$themeBackgroundUrl = matchTemplatePackAssetUrl((string)($templateAction['background_path'] ?? ''));
$backgroundUrl = $customBackgroundUrl !== '' ? $customBackgroundUrl : $themeBackgroundUrl;
$themePack = (array)($templateContext['pack'] ?? []);
$themeVersion = (array)($templateContext['version'] ?? []);
$themePackId = (int)($themePack['id'] ?? 0);
$themeName = trim((string)($themePack['name'] ?? 'Hub defaults')) ?: 'Hub defaults';
$themeSettingsUrl = $themePackId > 0 ? '/template_packs.php?pack_id=' . $themePackId . '#action-monthly_fixtures' : '/template_packs.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];
$publishingPreferences = social_publishing_preferences_load();

$captionLines = [
    '🔴🟡 ' . strtoupper($selectedMonthLabel) . ' FIXTURES 🟡🔴',
    '',
    'Here are our fixtures for ' . $selectedMonthLabel . '.',
    '',
];
foreach ($fixtures as $fixture) {
    $date = strtotime((string)$fixture['match_date']);
    $captionLines[] = ($date ? date('D j M', $date) : (string)$fixture['match_date']) . ' · ' . ((int)$fixture['is_home'] === 1 ? 'Home v ' : 'Away v ') . (string)$fixture['opponent'];
}
$captionLines[] = '';
$captionLines[] = 'All fixtures are subject to change.';
$captionLines[] = '';
$captionLines[] = 'Our Club. Our Town. 🔴🟡';
$defaultCaption = implode("\n", $captionLines);

$fontFaceCss = matchTemplatePackFontFaceCss($brand);
if ($fontFaceCss !== '') {
    require_once __DIR__ . '/lib/dynamic_styles.php';
    $fontFaceHref = hub_dynamic_stylesheet_register($fontFaceCss);
    echo '<link rel="stylesheet" href="' . h($fontFaceHref) . '">';
}

$fixturesByDay = [];
foreach ($fixtures as $fixture) {
    $fixturesByDay[(int)substr((string)$fixture['match_date'], 8, 2)][] = $fixture;
}
$daysInMonth = (int)date('t', $selectedMonthTimestamp);
$firstWeekday = (int)date('N', $selectedMonthTimestamp);
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Montserrat:wght@400;500;600;700;800;900&amp;family=Oswald:wght@400;500;600;700&amp;family=Poppins:wght@400;500;600;700;800;900&amp;family=Roboto:wght@400;500;600;700;800;900&amp;display=swap">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="matches.php?season_id=<?= $seasonId ?>">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Monthly fixtures</span></nav>

<?php if (isset($_GET['background_uploaded'])): ?><div class="alert alert-success">Monthly fixture background updated.</div><?php endif; ?>
<?php if (isset($_GET['background_removed'])): ?><div class="alert alert-success">Custom background removed. The theme artwork is now being used.</div><?php endif; ?>
<?php if ($uploadError !== ''): ?><div class="alert alert-danger"><?= h($uploadError) ?></div><?php endif; ?>

<section class="monthly-creator-toolbar card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="get" class="monthly-creator-toolbar__form" id="monthlyFixtureViewForm">
            <input type="hidden" name="layout" id="monthlyFixtureLayout" value="<?= h($layout) ?>">
            <div class="monthly-creator-toolbar__filters">
                <label><span>Season</span><select class="form-select form-select-sm" name="season_id" data-monthly-submit><?php foreach (getSeasons($pdo) as $seasonOption): ?><option value="<?= (int)$seasonOption['id'] ?>" <?= (int)$seasonOption['id'] === $seasonId ? 'selected' : '' ?>><?= h((string)$seasonOption['name']) ?></option><?php endforeach; ?></select></label>
                <label><span>Month</span><select class="form-select form-select-sm" name="month" data-monthly-submit><?php foreach ($months as $monthOption): ?><option value="<?= h($monthOption['value']) ?>" <?= $monthOption['value'] === $selectedMonth ? 'selected' : '' ?>><?= h($monthOption['label']) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="btn-group monthly-view-toggle" role="group" aria-label="Graphic view">
                <button type="button" class="btn btn-sm <?= $layout === 'block' ? 'btn-brand' : 'btn-outline-secondary' ?>" data-monthly-layout="block" title="Block view" aria-label="Block view" aria-pressed="<?= $layout === 'block' ? 'true' : 'false' ?>"><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-sm <?= $layout === 'calendar' ? 'btn-brand' : 'btn-outline-secondary' ?>" data-monthly-layout="calendar" title="Calendar view" aria-label="Calendar view" aria-pressed="<?= $layout === 'calendar' ? 'true' : 'false' ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></button>
            </div>
            <div class="monthly-creator-toolbar__actions" role="group" aria-label="Graphic actions">
                <button type="button" class="btn btn-brand" id="monthlyDownload" title="Download PNG" aria-label="Download PNG"><i class="fa-solid fa-download" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline-primary monthly-publish" data-platform="facebook" title="Post to Facebook" aria-label="Post to Facebook"><i class="fa-brands fa-facebook" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline-primary monthly-publish" data-platform="instagram" title="Post to Instagram" aria-label="Post to Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline-dark monthly-publish" data-platform="x" title="Post to Twitter/X" aria-label="Post to Twitter/X"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i></button>
            </div>
            <div class="monthly-post-copy">
                <label class="form-label fw-semibold" for="monthlyCaption">Post text</label>
                <textarea class="form-control" id="monthlyCaption" rows="5"><?= h($defaultCaption) ?></textarea>
                <div class="form-text">Edit the text before publishing. Facebook and Instagram post directly; X opens the composer and downloads the graphic.</div>
            </div>
            <div class="alert d-none mb-0 monthly-publish-status" id="monthlyPublishStatus" role="status" aria-live="polite"></div>
        </form>
    </div>
</section>

<div class="monthly-creator" data-monthly-creator
     data-season-id="<?= $seasonId ?>" data-month="<?= h($selectedMonth) ?>" data-layout="<?= h($layout) ?>"
     data-canvas-width="<?= $canvasWidth ?>" data-canvas-height="<?= $canvasHeight ?>"
     data-csrf="<?= h($csrfToken) ?>" data-theme-version="<?= (int)($themeVersion['id'] ?? 0) ?>"
     data-confirm-publish="<?= !empty($publishingPreferences['global']['confirm_before_live_post']) ? '1' : '0' ?>">
    <aside class="monthly-tools">
        <section class="monthly-tool-card">
            <div class="monthly-tool-card__header"><span><i class="fa-solid fa-wand-magic-sparkles"></i></span><div><h2>Graphic tools</h2><p>Changes update the live canvas.</p></div></div>
            <div class="monthly-tool-card__body">
                <div class="monthly-theme-summary"><div><small>Current theme</small><strong><?= h($themeName) ?></strong></div><a href="<?= h($themeSettingsUrl) ?>" class="btn btn-sm btn-outline-secondary">Theme settings</a></div>

                <div class="monthly-control-group">
                    <h3>Typography</h3>
                    <label class="monthly-control"><span>Heading font</span><select class="form-select form-select-sm" data-graphic-control="heading-font"><?php foreach (array_unique([$headingControlFont, 'Montserrat', 'Inter', 'Poppins', 'Roboto', 'Oswald']) as $font): ?><option value="<?= h($font) ?>" <?= $font === $headingControlFont ? 'selected' : '' ?>><?= h($font) ?></option><?php endforeach; ?></select></label>
                    <label class="monthly-control"><span>Body font</span><select class="form-select form-select-sm" data-graphic-control="body-font"><?php foreach (array_unique([$bodyControlFont, 'Montserrat', 'Inter', 'Poppins', 'Roboto', 'Oswald']) as $font): ?><option value="<?= h($font) ?>" <?= $font === $bodyControlFont ? 'selected' : '' ?>><?= h($font) ?></option><?php endforeach; ?></select></label>
                    <label class="monthly-control"><span>Legend size <output data-control-output="legend-size"><?= $legendControlSize ?></output>px</span><input type="range" min="20" max="64" value="<?= $legendControlSize ?>" data-graphic-control="legend-size"></label>
                    <label class="monthly-control"><span>Fixture text <output data-control-output="fixture-size"><?= $fixtureControlSize ?></output>px</span><input type="range" min="10" max="36" value="<?= $fixtureControlSize ?>" data-graphic-control="fixture-size"></label>
                </div>

                <div class="monthly-control-group">
                    <h3>Badges</h3>
                    <div class="monthly-badge-toggle" role="group" aria-label="Badge style">
                        <button type="button" class="btn btn-sm <?= $badgeStyle === 'white' ? 'btn-brand' : 'btn-outline-secondary' ?>" data-graphic-control="badge-style" data-value="white" aria-pressed="<?= $badgeStyle === 'white' ? 'true' : 'false' ?>">White</button>
                        <button type="button" class="btn btn-sm <?= $badgeStyle === 'colour' ? 'btn-brand' : 'btn-outline-secondary' ?>" data-graphic-control="badge-style" data-value="colour" aria-pressed="<?= $badgeStyle === 'colour' ? 'true' : 'false' ?>">Full colour</button>
                    </div>
                    <label class="monthly-control"><span>Badge size <output data-control-output="badge-size"><?= $badgeControlSize ?></output>px</span><input type="range" min="50" max="220" value="<?= $badgeControlSize ?>" data-graphic-control="badge-size"></label>
                </div>

                <div class="monthly-control-group">
                    <h3>Layout</h3>
                    <label class="monthly-control"><span>Card gap <output data-control-output="card-gap"><?= $cardGap ?></output>px</span><input type="range" min="0" max="42" value="<?= $cardGap ?>" data-graphic-control="card-gap"></label>
                </div>
            </div>
        </section>

        <section class="monthly-tool-card">
            <div class="monthly-tool-card__header"><span><i class="fa-solid fa-up-down-left-right"></i></span><div><h2>Position and size</h2><p>Click an element on the graphic first.</p></div></div>
            <div class="monthly-tool-card__body">
                <div class="monthly-selection-status"><small>Selected element</small><strong data-selected-label>Nothing selected</strong></div>
                <div class="monthly-geometry-fields">
                    <?php foreach (['x' => 'X', 'y' => 'Y', 'width' => 'Width', 'height' => 'Height'] as $field => $label): ?><label><span><?= $label ?></span><input class="form-control form-control-sm" type="number" min="0" data-geometry-field="<?= $field ?>" disabled></label><?php endforeach; ?>
                </div>
                <p class="monthly-tool-hint">Drag an element to move it. Drag its gold corner handle to resize. Arrow keys nudge the selected element.</p>
                <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-reset-layout><i class="fa-solid fa-arrow-rotate-left"></i> Reset to theme layout</button>
            </div>
        </section>

        <section class="monthly-tool-card">
            <div class="monthly-tool-card__header"><span><i class="fa-regular fa-image"></i></span><div><h2>Background</h2><p>Override the current theme artwork.</p></div></div>
            <form method="post" enctype="multipart/form-data" class="monthly-tool-card__body" data-monthly-background-form>
                <?= csrf_field() ?><input type="hidden" name="season_id" value="<?= $seasonId ?>"><input type="hidden" name="month" value="<?= h($selectedMonth) ?>"><input type="hidden" name="layout" value="<?= h($layout) ?>"><input type="hidden" name="action" value="upload_background">
                <label class="monthly-background-dropzone" for="monthlyBackground" data-monthly-dropzone><input class="visually-hidden" type="file" id="monthlyBackground" name="background_image" accept="image/png,image/jpeg,image/webp" data-monthly-background-input><span class="monthly-background-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up"></i></span><span><strong>Drop a background here</strong><small>or click to browse · JPG, PNG or WebP · uploads automatically</small></span><span class="monthly-background-dropzone__filename" data-monthly-background-name><?= $customBackgroundUrl !== '' ? h(basename(monthlyFixturesBackgroundPath($seasonId))) : 'Using artwork from ' . h($themeName) ?></span></label>
                <div class="monthly-background-status" data-monthly-background-status aria-live="polite"></div>
                <noscript><button type="submit" class="btn btn-outline-primary w-100">Upload background</button></noscript>
            </form>
            <form method="post" class="monthly-tool-card__remove" data-monthly-remove-form style="<?= $customBackgroundUrl === '' ? 'display:none' : '' ?>"><?= csrf_field() ?><input type="hidden" name="season_id" value="<?= $seasonId ?>"><input type="hidden" name="month" value="<?= h($selectedMonth) ?>"><input type="hidden" name="layout" value="<?= h($layout) ?>"><input type="hidden" name="action" value="remove_background"><button type="submit" class="btn btn-link btn-sm text-danger">Return to theme background</button></form>
        </section>
    </aside>

    <section class="monthly-live-preview card shadow-sm border-0 overflow-hidden" aria-label="Monthly fixtures live graphic">
        <div class="monthly-live-preview__bar"><span><i class="fa-solid <?= $layout === 'calendar' ? 'fa-calendar-days' : 'fa-table-cells-large' ?>"></i> <?= $layout === 'calendar' ? 'Calendar view' : 'Block view' ?></span><span>Live HTML canvas · <?= $canvasWidth ?> × <?= $canvasHeight ?></span></div>
        <div class="monthly-graphic-stage" id="monthlyGraphicStage">
            <article class="monthly-graphic monthly-graphic--<?= h($layout) ?>" id="monthlyGraphic"
                     style="width:<?= $canvasWidth ?>px;height:<?= $canvasHeight ?>px;--monthly-primary:<?= h($primary) ?>;--monthly-secondary:<?= h($secondary) ?>;--monthly-accent:<?= h($accent) ?>;--monthly-text:<?= h($textColour) ?>;--monthly-heading-font:'<?= h($headingControlFont) ?>';--monthly-body-font:'<?= h($bodyControlFont) ?>';--monthly-legend-size:<?= $legendControlSize ?>px;--monthly-fixture-size:<?= $fixtureControlSize ?>px;--monthly-badge-size:<?= $badgeControlSize ?>px;--monthly-card-gap:<?= $cardGap ?>px;--monthly-gradient-stop-1:<?= h($gradientStop1) ?>;--monthly-gradient-stop-2:<?= h($gradientStop2) ?>;--monthly-primary-ink:<?= h($primaryInk) ?>;<?= $backgroundUrl !== '' ? '--monthly-background:url(\'' . h($backgroundUrl) . '\');' : '' ?>">
                <div class="monthly-graphic__background"></div><div class="monthly-graphic__overlay"></div><div class="monthly-graphic__texture"></div>
                <?php
                $renderElement = static function (string $key, array $element, string $content): void {
                    $label = (string)($element['label'] ?? ucwords(str_replace('_', ' ', $key)));
                    echo '<div class="monthly-graphic-element monthly-graphic-element--' . h($key) . '" data-editor-element="' . h($key) . '" data-element-label="' . h($label) . '" tabindex="0" style="' . h(matchTemplatePackElementStyle($element)) . '">' . $content . '<span class="monthly-resize-handle" data-resize-handle data-html2canvas-ignore="true"></span></div>';
                };
                ob_start(); ?><img src="<?= h($badgeStyle === 'colour' ? $clubBadgeColour : $clubBadgeWhite) ?>" data-badge-white="<?= h($clubBadgeWhite) ?>" data-badge-colour="<?= h($clubBadgeColour) ?>" alt="Saltcoats Victoria FC badge" crossorigin="anonymous"><?php $renderElement('club_badge', $elements['club_badge'], (string)ob_get_clean());
                ob_start(); ?><span class="monthly-legend-chip monthly-legend-chip--home">HOME</span><span class="monthly-legend-chip monthly-legend-chip--away">AWAY</span><?php $renderElement('legend', $elements['legend'], (string)ob_get_clean());
                ob_start(); ?><strong><?= h(strtoupper(date('F', $selectedMonthTimestamp))) ?> FIXTURES</strong><?php $renderElement('month_heading', $elements['month_heading'], (string)ob_get_clean());
                ob_start();
                if ($layout === 'calendar'): ?>
                    <div class="monthly-calendar-weekdays"><?php foreach (['MON','TUE','WED','THU','FRI','SAT','SUN'] as $weekday): ?><span><?= $weekday ?></span><?php endforeach; ?></div>
                    <div class="monthly-calendar-grid" style="--first-day:<?= $firstWeekday ?>"><?php for ($day = 1; $day <= $daysInMonth; $day++): $dayFixtures = $fixturesByDay[$day] ?? []; $fixture = $dayFixtures[0] ?? null; $isHome = $fixture && (int)$fixture['is_home'] === 1; $isCup = $fixture ? monthlyFixturesIsCupFixture($fixture) : false; ?><div class="monthly-calendar-day<?= $fixture ? ' has-fixture ' . ($isHome ? 'is-home' : 'is-away') . ($isCup ? ' has-cup' : '') : '' ?>"<?= $day === 1 ? ' style="grid-column:' . $firstWeekday . '"' : '' ?>><span class="monthly-calendar-day__number"><?= $day ?></span><?php if ($fixture): $badgeUrls = monthlyFixturesOpponentBadgeUrls($pdo, $fixture); $whiteTinted = $badgeUrls['white'] !== '' ? monthlyFixturesTintedBadgeUrl($badgeUrls['white'], $isHome ? $accent : $primary) : ''; $badgeUrl = $badgeStyle === 'colour' && $badgeUrls['colour'] !== '' ? $badgeUrls['colour'] : ($whiteTinted !== '' ? $whiteTinted : $badgeUrls['colour']); ?><span class="monthly-fixture-location"><?= $isHome ? 'H' : 'A' ?></span><?php if ($badgeUrl !== ''): ?><img src="<?= h($badgeUrl) ?>" data-badge-white="<?= h($whiteTinted) ?>" data-badge-colour="<?= h($badgeUrls['colour']) ?>" alt="" crossorigin="anonymous"><?php endif; ?><strong><?= h(strtoupper((string)$fixture['opponent'])) ?></strong><?php if (count($dayFixtures) > 1): ?><small>+<?= count($dayFixtures) - 1 ?> more</small><?php endif; ?><?php if ($isCup): ?><span class="monthly-fixture-cup-icon" aria-label="Cup fixture" title="Cup fixture"><i class="fa-solid fa-trophy" aria-hidden="true"></i></span><?php endif; ?><?php endif; ?></div><?php endfor; ?></div>
                <?php else: ?>
                    <?php $fixtureGridClass = 'monthly-block-grid is-count-' . min(max($fixtureCount, 1), 9); ?>
                    <div class="<?= h($fixtureGridClass) ?>"><?php foreach (array_slice($fixtures, 0, 9) as $fixture): $isHome = (int)$fixture['is_home'] === 1; $isCup = monthlyFixturesIsCupFixture($fixture); $badgeUrls = monthlyFixturesOpponentBadgeUrls($pdo, $fixture); $whiteTinted = $badgeUrls['white'] !== '' ? monthlyFixturesTintedBadgeUrl($badgeUrls['white'], $isHome ? $accent : $primary) : ''; $badgeUrl = $badgeStyle === 'colour' && $badgeUrls['colour'] !== '' ? $badgeUrls['colour'] : ($whiteTinted !== '' ? $whiteTinted : $badgeUrls['colour']); $timestamp = strtotime((string)$fixture['match_date']); $result = monthlyFixturesResult($fixture); $scoreLabel = monthlyFixturesScoreLabel($fixture); ?><article class="monthly-fixture-block <?= $isHome ? 'is-home' : 'is-away' ?><?= $isCup ? ' has-cup' : '' ?>"><time><?= $timestamp ? h(strtoupper(date('M j', $timestamp))) : 'DATE TBC' ?></time><span class="monthly-fixture-location"><?= $isHome ? 'H' : 'A' ?></span><?php if ($badgeUrl !== ''): ?><img src="<?= h($badgeUrl) ?>" data-badge-white="<?= h($whiteTinted) ?>" data-badge-colour="<?= h($badgeUrls['colour']) ?>" alt="<?= h((string)$fixture['opponent']) ?> badge" crossorigin="anonymous"><?php else: ?><span class="monthly-fixture-fallback"><i class="fa-solid fa-shield-halved"></i></span><?php endif; ?><strong><?= h(strtoupper((string)$fixture['opponent'])) ?></strong><?php if ($result !== null): ?><span class="monthly-fixture-result"><?= h($result) ?></span><span class="monthly-fixture-score"><?= h($scoreLabel) ?></span><?php endif; ?><?php if ($isCup): ?><span class="monthly-fixture-cup-icon" aria-label="Cup fixture" title="Cup fixture"><i class="fa-solid fa-trophy" aria-hidden="true"></i></span><?php endif; ?></article><?php endforeach; ?></div>
                <?php endif;
                $renderElement('fixtures', $elements['fixtures'], (string)ob_get_clean());
                $renderElement('footer_meta', $elements['footer_meta'], '<strong>ALL FIXTURES ARE SUBJECT TO CHANGE</strong><span>SALTCOATS VICTORIA FC · ' . h(strtoupper((string)$season['name'])) . '</span>');
                ?>
            </article>
        </div>
    </section>
</div>

<script src="/admin/assets/js/monthly-fixtures.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/monthly-fixtures.js') ?: time()) ?>" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
