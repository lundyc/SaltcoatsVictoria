<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/navigation_settings.php';
require_once __DIR__ . '/lib/navigation_menu.php';
require_once __DIR__ . '/lib/ui.php';

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isPublicPage = in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true);

if (!hub_auth_is_authenticated() && !$isPublicPage) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
$currentRole = $currentUser['role'] ?? 'guest';

// Volunteers and staff default-deny: only admin sees everything
// unconditionally. Everyone else only sees a page if it's in
// HUB_PAGE_CAPABILITIES and one of their committee positions grants that
// capability. Starts near-empty until an admin assigns a position with
// capabilities via /positions.php — expanding what someone can reach is
// just adding another page to HUB_PAGE_CAPABILITIES, same pattern.
// HUB_PAGE_GATE_EXEMPT lists pages this blanket check must not touch,
// because something else already grants or enforces access correctly
// (the shared dashboard, reports.php's own per-report-type gate, and the
// handful of pages already gated by the separate role-based
// tickets.*/pos.* permission layer, which grants volunteer/staff a
// ticket-ops baseline independent of committee position).
if (in_array($currentRole, ['volunteer', 'staff'], true) && !hub_auth_is_admin() && !in_array($currentScript, HUB_PAGE_GATE_EXEMPT, true)) {
    $roleCapabilities = HUB_PAGE_CAPABILITIES[$currentScript] ?? null;
    if ($roleCapabilities === null || !hub_auth_has_any_capability($roleCapabilities)) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>No access yet</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1.5rem;color:#2d2b2c;">'
            . '<h1 style="font-size:1.3rem;">No areas assigned yet</h1>'
            . '<p>Your account doesn\'t have access to any Hub pages yet. Ask an admin to give you a role on your profile.</p>'
            . '<p><a href="/admin/logout.php">Log out</a></p>'
            . '</body></html>';
        exit;
    }
}

$seasonContext = ['seasons' => [], 'season_id' => 0, 'season' => null];
if (hub_auth_is_authenticated() && isset($pdo)) {
    try {
        $seasonContext = getSeasonContext($pdo);
    } catch (Throwable $exception) {
        // The shell must remain usable while season data is unavailable.
    }
}

// "New order" menu notifications (see lib/order_alerts.php). Opening an orders page marks its
// section as read for this admin; anything already dealt with stops counting for everyone.
require_once __DIR__ . '/lib/order_alerts.php';
$hubOrderAlerts = array_fill_keys(ORDER_ALERT_SECTIONS, 0);
$hubOrderPrevSeen = [];
if (hub_auth_is_authenticated() && isset($pdo)) {
    try {
        $alertAccountId = (int) (hub_auth_current_user()['account_id'] ?? 0);
        $alertSection = ORDER_ALERT_PAGES[basename($_SERVER['PHP_SELF'] ?? '')] ?? null;
        if ($alertSection !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $hubOrderPrevSeen = order_alerts_seen_map($pdo, $alertAccountId);
            order_alerts_mark_seen($pdo, $alertAccountId, $alertSection);
        }
        $hubOrderAlerts = order_alerts_counts($pdo, $alertAccountId);
    } catch (Throwable $exception) {
        // The menu must keep working if the alert tables are unavailable.
    }
}

function activePage(string $page): string
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $page ? 'active' : '';
}

function activeGroup(array $pages): string
{
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    return in_array($current, $pages, true) ? 'active' : '';
}

function hub_nav_current(array $pages): string
{
    return in_array(basename($_SERVER['PHP_SELF'] ?? ''), $pages, true) ? ' aria-current="page"' : '';
}

function hub_default_page_hero(string $script): array
{
    $map = [
        'index.php' => [
            'eyebrow' => 'Club overview',
            'title' => 'Club Hub',
            'subtitle' => 'The latest club, sponsorship, fixture, and publishing activity.',
            'actions' => [
                ['label' => 'Add fixture', 'href' => '/match.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'Add player', 'href' => '/player_add.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'players.php' => [
            'eyebrow' => 'Club',
            'title' => 'Players',
            'subtitle' => 'Manage squad records, player statuses, and sponsorship links.',
            'actions' => [
                ['label' => 'Add player', 'href' => '/player_add.php', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'View sponsors', 'href' => '/sponsors.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'sponsors.php' => [
            'eyebrow' => 'Club',
            'title' => 'Sponsors',
            'subtitle' => 'Manage sponsor records, season status, and payment progress.',
            'actions' => [
                ['label' => 'Add sponsor', 'href' => '/sponsor.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'View agreements', 'href' => '/sponsorship_agreements.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'matches.php' => [
            'eyebrow' => 'Match Day',
            'title' => 'Matches',
            'subtitle' => 'Fixture management, sponsorships, and match-day workflow.',
            'actions' => [
                ['label' => 'Add fixture', 'href' => '/match.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'league_table.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'League Table',
            'subtitle' => 'Generate and publish league table graphics.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Template packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Facebook diagnostics', 'href' => '/facebook_diagnostics.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Settings', 'href' => '/settings.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'templates.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Template Packs',
            'subtitle' => 'Manage complete, reusable graphic design systems.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'template_packs.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Template Packs',
            'subtitle' => 'Create and manage consistent designs for every match graphic.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'template_pack.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Edit Template Pack',
            'subtitle' => 'Configure branding and layouts across the match-day graphic set.',
            'actions' => [
                ['label' => 'All packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'settings.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Settings',
            'subtitle' => 'Configure hub content, imagery, and publishing preferences.',
            'actions' => [
                ['label' => 'Template packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Facebook diagnostics', 'href' => '/facebook_diagnostics.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'facebook_diagnostics.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Facebook Diagnostics',
            'subtitle' => 'Recent Facebook publish attempts, duplicates blocked, and redacted API responses.',
            'actions' => [
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'admin_users.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Users',
            'subtitle' => 'Legacy users URL. Manage account access from Users.',
            'actions' => [
                ['label' => 'Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_people.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Users',
            'subtitle' => 'One record per person: supporters, staff, volunteers and committee. Login access, positions and season tickets attach to the person.',
            'actions' => [
                ['label' => 'Club positions', 'href' => '/positions.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_person.php' => [
            'eyebrow' => 'Administration',
            'title' => 'User',
            'subtitle' => 'Manage one person, their login account, club positions, dependants and ticket history.',
            'actions' => [
                ['label' => 'Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'media.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Media Library',
            'subtitle' => 'Browse, upload and edit the images used across the Hub.',
            'actions' => [],
        ],
        'match_photos.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Media Library',
            'subtitle' => 'Browse, upload and edit hub images, including every tagged match photo.',
            'actions' => [],
        ],
    ];

    $hero = $map[$script] ?? [
        'eyebrow' => 'Club management',
        'title' => 'Club Hub',
        'subtitle' => 'One site for club management, graphics, posting, and match tools.',
        'actions' => [],
    ];

    // Page operations live beside the content they affect. Default heroes are
    // deliberately informational so fallback pages do not reintroduce a
    // detached action strip.
    $hero['actions'] = [];
    return $hero;
}

/**
 * @param array{
 *   eyebrow?: string,
 *   title?: string,
 *   subtitle?: string,
 *   class?: string,
 *   breadcrumbs?: list<array{label: string, href?: string}>,
 *   actions?: list<array{label?: string, icon?: string, href?: string, class?: string, variant?: 'primary'|'secondary', tag?: 'a'|'button', attrs?: array<string, scalar>}>
 * } $hero
 */
function hub_render_page_hero(array $hero): void
{
    $eyebrow = (string) ($hero['eyebrow'] ?? '');
    $title = (string) ($hero['title'] ?? '');
    $subtitle = (string) ($hero['subtitle'] ?? '');
    $class = trim('page-hero ' . (string) ($hero['class'] ?? ''));
    $actions = isset($hero['actions']) && is_array($hero['actions']) ? $hero['actions'] : [];

    $actionIcon = static function (array $action): string {
        if (!empty($action['icon'])) {
            return '<i class="fa-solid ' . htmlspecialchars((string) $action['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
        }
        $label = strtolower(trim((string) ($action['label'] ?? '')));
        $iconClass = match (true) {
            preg_match('/^(add|create|new)\b/', $label) === 1 || str_contains($label, '+ add') => 'fa-plus',
            str_starts_with($label, 'back') || str_starts_with($label, 'all ') => 'fa-arrow-left',
            str_contains($label, 'export') => 'fa-download',
            str_contains($label, 'import') => 'fa-upload',
            str_contains($label, 'copy') => 'fa-copy',
            str_contains($label, 'preview') || str_contains($label, 'graphic') || str_contains($label, 'poster') => 'fa-image',
            str_contains($label, 'setting') || str_contains($label, 'pricing') => 'fa-gear',
            str_contains($label, 'fixture') || str_contains($label, 'match') => 'fa-futbol',
            str_contains($label, 'league') => 'fa-table',
            str_contains($label, 'sponsor') => 'fa-handshake',
            str_contains($label, 'agreement') => 'fa-file-signature',
            str_contains($label, 'package') || str_contains($label, 'pack') => 'fa-box-open',
            str_contains($label, 'report') => 'fa-chart-column',
            str_contains($label, 'wall') => 'fa-border-all',
            default => 'fa-arrow-right',
        };
        return '<i class="fa-solid ' . $iconClass . '" aria-hidden="true"></i>';
    };

    $primaryIndex = null;
    foreach ($actions as $index => $action) {
        $declaredVariant = (string) ($action['variant'] ?? '');
        $legacyClass = (string) ($action['class'] ?? '');
        if ($declaredVariant === 'primary' || str_contains($legacyClass, 'btn-light') || str_contains($legacyClass, 'btn-brand')) {
            $primaryIndex = $index;
            break;
        }
    }
    if ($primaryIndex === null) {
        foreach ($actions as $index => $action) {
            $label = strtolower(trim((string) ($action['label'] ?? '')));
            if (preg_match('/^(\+\s*)?(add|create|new|save)\b/', $label) === 1) {
                $primaryIndex = $index;
                break;
            }
        }
    }

    foreach ($actions as $index => &$action) {
        $action['_variant'] = $index === $primaryIndex ? 'primary' : 'secondary';
        $action['_index'] = $index;
        $action['_icon'] = $actionIcon($action);
    }
    unset($action);
    usort($actions, static function (array $left, array $right): int {
        $variantOrder = ['primary' => 0, 'secondary' => 1];
        $comparison = ($variantOrder[$left['_variant']] ?? 1) <=> ($variantOrder[$right['_variant']] ?? 1);
        return $comparison !== 0 ? $comparison : (($left['_index'] ?? 0) <=> ($right['_index'] ?? 0));
    });

    $visibleActions = count($actions) > 3 ? array_slice($actions, 0, 2) : $actions;
    $overflowActions = count($actions) > 3 ? array_slice($actions, 2) : [];

    $renderAction = static function (array $action, bool $inMenu = false): void {
        $tag = (string) ($action['tag'] ?? 'a');
        $icon = (string) ($action['_icon'] ?? '');
        $labelText = trim((string) ($action['label'] ?? ''));
        if (str_contains($icon, 'fa-plus')) {
            $labelText = preg_replace('/^\+\s*/', '', $labelText) ?? $labelText;
        }
        $label = htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8');
        $attrs = isset($action['attrs']) && is_array($action['attrs']) ? $action['attrs'] : [];
        $attrHtml = '';
        foreach ($attrs as $attrName => $attrValue) {
            $attrHtml .= ' ' . htmlspecialchars((string) $attrName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') . '"';
        }
        $class = $inMenu
            ? 'dropdown-item page-hero-action-menu__item'
            : 'btn page-hero-action page-hero-action--' . (($action['_variant'] ?? 'secondary') === 'primary' ? 'primary' : 'secondary');
        $content = $icon . '<span>' . $label . '</span>';
        if ($tag === 'button') {
            $buttonType = array_key_exists('type', $attrs) ? '' : ' type="button"';
            echo '<button class="' . $class . '"' . $buttonType . $attrHtml . '>' . $content . '</button>';
            return;
        }
        $href = htmlspecialchars((string) ($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
        echo '<a href="' . $href . '" class="' . $class . '"' . $attrHtml . '>' . $content . '</a>';
    };
    ?>
    <header class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="hubPageTitle">
        <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
            <div>
                <div class="page-hero-eyebrow"><?= htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') ?></div>
                <h1 class="page-hero-title" id="hubPageTitle"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-hero-subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if ($actions !== []): ?>
                <div class="page-hero-actions" aria-label="Page actions">
                    <?php foreach ($visibleActions as $action): $renderAction($action); endforeach; ?>
                    <?php if ($overflowActions !== []): ?>
                        <div class="dropdown page-hero-action-menu">
                            <button class="btn page-hero-action page-hero-action--more dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-ellipsis" aria-hidden="true"></i><span>More actions</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <?php foreach ($overflowActions as $action): $renderAction($action, true); endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <?php if (!empty($hero['breadcrumbs']) && is_array($hero['breadcrumbs'])): ?>
        <nav class="hub-breadcrumb" aria-label="Breadcrumb">
            <?php foreach (array_values($hero['breadcrumbs']) as $breadcrumbIndex => $breadcrumb): ?>
                <?php if ($breadcrumbIndex > 0): ?><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><?php endif; ?>
                <?php if (!empty($breadcrumb['href'])): ?>
                    <a href="<?= htmlspecialchars((string) $breadcrumb['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($breadcrumb['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= htmlspecialchars((string) ($breadcrumb['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php
}

/**
 * Render the single shared summary-card pattern used throughout the Hub.
 *
 * @param list<array{label:string,value:scalar,meta?:string,icon?:string,tone?:string,href?:string,value_attrs?:array<string,scalar>}> $metrics
 */
function hub_render_metric_grid(array $metrics, string $ariaLabel = 'Page summary'): void
{
    if ($metrics === []) {
        return;
    }
    $allowedTones = ['primary', 'success', 'warning', 'danger', 'info', 'neutral'];
    ?>
    <section class="hub-metric-grid" aria-label="<?= htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($metrics as $metric): ?>
            <?php
            $tone = (string) ($metric['tone'] ?? 'primary');
            $tone = in_array($tone, $allowedTones, true) ? $tone : 'primary';
            $icon = trim((string) ($metric['icon'] ?? 'fa-chart-simple'));
            $icon = str_starts_with($icon, 'fa-') && !str_contains($icon, ' ') ? 'fa-solid ' . $icon : $icon;
            $href = trim((string) ($metric['href'] ?? ''));
            $tag = $href !== '' ? 'a' : 'article';
            $valueAttrs = '';
            foreach (($metric['value_attrs'] ?? []) as $attrName => $attrValue) {
                $valueAttrs .= ' ' . htmlspecialchars((string) $attrName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') . '"';
            }
            ?>
            <<?= $tag ?> class="hub-metric-card<?= $href !== '' ? ' hub-metric-card--link' : '' ?>"<?= $href !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <span class="hub-metric-card__icon hub-metric-card__icon--<?= $tone ?>"><i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                <span class="hub-metric-card__body">
                    <span class="hub-metric-card__label"><?= htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong class="hub-metric-card__value"<?= $valueAttrs ?>><?= htmlspecialchars((string) ($metric['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (trim((string) ($metric['meta'] ?? '')) !== ''): ?>
                        <span class="hub-metric-card__meta"><?= htmlspecialchars((string) $metric['meta'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                        <span class="hub-metric-card__meta" aria-hidden="true">&nbsp;</span>
                    <?php endif; ?>
                </span>
                <?php if ($href !== ''): ?><i class="fa-solid fa-chevron-right hub-metric-card__arrow" aria-hidden="true"></i><?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
    <?php
}

if ($currentScript === 'sponsor_wall.php' && !isset($pageHero)) {
    // This creative workspace supplies a specialised, in-page workflow hero.
    $pageHero = [];
} elseif (!isset($pageHero) || !is_array($pageHero)) {
    $pageHero = hub_default_page_hero($currentScript);
}
$documentTitle = trim((string) ($pageDocumentTitle ?? ($pageHero['title'] ?? '')));
$documentTitle = $documentTitle !== '' ? $documentTitle . ' – ' . APP_NAME : APP_NAME;

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#4b0818">
    <link rel="manifest" href="/admin/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/admin/icon-192.png">
    <script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/admin/sw.js', { scope: '/admin/', updateViaCache: 'none' }).catch(function () {}); }); }</script>
    <title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preload" href="/admin/assets/vendor/fonts/inter-UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2" as="font" type="font/woff2" crossorigin>

    <link href="/admin/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/vendor/fonts/inter.css" rel="stylesheet">
    <link href="/admin/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
        <?php
        $pageStyle = basename((string) $pageStyle);
        $pageStylePath = __DIR__ . '/assets/css/' . $pageStyle;
        if ($pageStyle === '' || !is_file($pageStylePath)) {
            continue;
        }
        ?>
        <link href="/admin/assets/css/<?= htmlspecialchars($pageStyle, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)(@filemtime($pageStylePath) ?: time()) ?>" rel="stylesheet">
    <?php endforeach; ?>
    <script src="/admin/assets/vendor/jquery/jquery.min.js"></script>
    <script src="/admin/assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script>
    <link href="/admin/assets/css/help-global.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/help-global.css') ?: time()) ?>" rel="stylesheet">
    <script src="/admin/assets/js/help-tour.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/help-tour.js') ?: time()) ?>" defer></script>
    <script src="/admin/assets/js/help.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/help.js') ?: time()) ?>" defer></script>
    <script src="/admin/assets/js/app.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/app.js') ?: time()) ?>" defer></script>
</head>

<body class="hub-shell">
    <nav class="navbar navbar-expand-lg border-bottom shadow-sm navbar-dark" id="hubSideNavigation">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-brand" href="index.php">
                <img
                    src="club-logo-white-120.png"
                    alt="Saltcoats Victoria FC"
                    class="navbar-brand__logo"
                    loading="eager"
                >
                <span class="navbar-brand__text"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <div class="hub-sidebar-tools d-flex" id="hubSidebarTools">
                <?php if (($seasonContext['seasons'] ?? []) !== []): ?>
                    <button type="button" class="hub-sidebar-tool" id="hubSeasonToolButton" aria-expanded="false" aria-controls="hubSeasonPopover" aria-label="Working season" title="Working season: <?= htmlspecialchars((string) ($seasonContext['season']['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-bs-placement="bottom" data-bs-custom-class="hub-rail-tooltip">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>
                <button type="button" class="hub-sidebar-tool" data-hub-palette-open aria-haspopup="dialog" aria-controls="hubPalette" aria-label="Find a page" title="Find a page (Ctrl K)" data-bs-placement="bottom" data-bs-custom-class="hub-rail-tooltip">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
                <button class="hub-desktop-nav-hide d-none d-xl-inline-flex" id="hubDesktopNavHide" type="button" aria-controls="hubSideNavigation" aria-expanded="true" aria-label="Hide sidebar" title="Hide sidebar" data-bs-placement="bottom" data-bs-custom-class="hub-rail-tooltip">
                    <i class="fa-solid fa-table-columns" aria-hidden="true"></i>
                </button>
                <?php if (($seasonContext['seasons'] ?? []) !== []): ?>
                    <div class="hub-sidebar-tool-popover" id="hubSeasonPopover" hidden>
                        <form class="hub-season-switcher" method="get" action="<?= htmlspecialchars($currentScript, ENT_QUOTES, 'UTF-8') ?>" id="hubSeasonSwitcherFormDesktop">
                            <label for="hubSeasonSelectDesktop">Working season</label>
                            <div class="hub-season-switcher__control">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                <select id="hubSeasonSelectDesktop" name="season_id" data-season-select aria-describedby="hubSeasonHelpDesktop">
                                    <?php foreach ($seasonContext['seasons'] as $navSeason): ?>
                                        <option value="<?= (int) $navSeason['id'] ?>" <?= (int) $seasonContext['season_id'] === (int) $navSeason['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $navSeason['name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($navSeason['is_current']) ? ' · Current' : '' ?><?= !empty($navSeason['is_locked']) ? ' · Locked' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span id="hubSeasonHelpDesktop" class="visually-hidden">Changing this updates season-based information across the Hub.</span>
                        </form>
                        <script>
                            // Keep whatever report/filters/tab the user is on when they just switch season,
                            // instead of the plain season_id-only submit resetting the page to its defaults.
                            (function () {
                                var form = document.getElementById('hubSeasonSwitcherFormDesktop');
                                if (!form) return;
                                form.addEventListener('submit', function () {
                                    var params = new URLSearchParams(window.location.search);
                                    params.delete('season_id');
                                    params.forEach(function (value, key) {
                                        var input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = key;
                                        input.value = value;
                                        form.appendChild(input);
                                    });
                                });
                            })();
                        </script>
                    </div>
                <?php endif; ?>
            </div>

            <button class="navbar-toggler collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
                    aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="custom-toggler-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>

            <div class="collapse navbar-collapse justify-content-center" id="navbarMain">
                <?php
                $isAdmin = (string) $currentRole === 'admin';
                // The signed-in person's profile picture, when one is set and the file is there.
                $accountPhotoUrl = '';
                if (!empty($currentUser['person_id'])) {
                    $accountPhotoStmt = $pdo->prepare('SELECT profile_image_path FROM people WHERE id = :id');
                    $accountPhotoStmt->execute([':id' => (int) $currentUser['person_id']]);
                    $accountPhotoPath = trim((string) $accountPhotoStmt->fetchColumn());
                    if (str_starts_with($accountPhotoPath, 'uploads/member_profiles/') && is_file(__DIR__ . '/../' . $accountPhotoPath)) {
                        $accountPhotoUrl = '/' . $accountPhotoPath;
                    }
                }
                ?>
                <div class="nav-sections d-flex flex-column w-100">
                    <?php if (($seasonContext['seasons'] ?? []) !== []): ?>
                    <div class="d-none">
                        <form class="hub-season-switcher" method="get" action="<?= htmlspecialchars($currentScript, ENT_QUOTES, 'UTF-8') ?>" id="hubSeasonSwitcherForm">
                            <label for="hubSeasonSelect">Working season</label>
                            <div class="hub-season-switcher__control">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                <select id="hubSeasonSelect" name="season_id" data-season-select aria-describedby="hubSeasonHelp">
                                    <?php foreach ($seasonContext['seasons'] as $navSeason): ?>
                                        <option value="<?= (int) $navSeason['id'] ?>" <?= (int) $seasonContext['season_id'] === (int) $navSeason['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $navSeason['name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($navSeason['is_current']) ? ' · Current' : '' ?><?= !empty($navSeason['is_locked']) ? ' · Locked' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span id="hubSeasonHelp" class="visually-hidden">Changing this updates season-based information across the Hub.</span>
                        </form>
                        <script>
                            // Keep whatever report/filters/tab the user is on when they just switch season,
                            // instead of the plain season_id-only submit resetting the page to its defaults.
                            (function () {
                                var form = document.getElementById('hubSeasonSwitcherForm');
                                if (!form) return;
                                form.addEventListener('submit', function () {
                                    var params = new URLSearchParams(window.location.search);
                                    params.delete('season_id');
                                    params.forEach(function (value, key) {
                                        var input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = key;
                                        input.value = value;
                                        form.appendChild(input);
                                    });
                                });
                            })();
                        </script>
                    </div>
                    <?php endif; ?>
                    <?php
                    $hubMenu = hub_navigation_resolve();
                    $hubMostUsed = []; // "Most used" shortcuts switched off for now; hub_navigation_most_used() is kept in lib/navigation_menu.php
                    $hubRenderLink = static function (array $link) use ($hubOrderAlerts): void {
                        $external = !empty($link['external']);
                        $badge = order_alert_badge(hub_navigation_badge_total($hubOrderAlerts, $link['badge'] ?? []));
                        $tabs = count($link['tabs']) > 1 ? $link['tabs'] : [];
                        ?>
                        <li class="wp-item<?= $tabs !== [] ? ' wp-has-sub' : '' ?><?= $link['active'] ? ' wp-current' : '' ?>">
                            <a class="wp-top<?= $link['active'] ? ' active' : '' ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars((string) $link['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $external ? ' target="_blank" rel="noopener"' : '' ?>><i class="fa-solid <?= htmlspecialchars((string) $link['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span class="wp-label"><?= htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8') ?></span><?= $badge ?></a>
                            <?php if ($tabs !== []): ?>
                                <ul class="wp-submenu">
                                    <li class="wp-submenu-head"><?= htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php foreach ($tabs as $tab): ?>
                                        <li><a class="<?= !empty($tab['current']) ? 'current' : '' ?>"<?= !empty($tab['current']) ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars((string) $tab['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $tab['label'], ENT_QUOTES, 'UTF-8') ?><?= order_alert_badge(hub_navigation_badge_total($hubOrderAlerts, $tab['badge'] ?? [])) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                        <?php
                    };
                    ?>
                    <button type="button" class="hub-nav-search d-none" data-hub-palette-open aria-haspopup="dialog" aria-controls="hubPalette">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span>Find a page…</span><kbd>Ctrl K</kbd>
                    </button>
                    <ul class="hub-wp-menu" id="hubNavSections">
                    <?php foreach ($hubMenu as $hubSection): ?>
                        <?php if ($hubSection['key'] !== 'home'): ?>
                            <li class="wp-separator" role="presentation" aria-hidden="true"></li>
                        <?php endif; ?>
                        <li class="hub-nav-anchor" id="hubNavSection-<?= htmlspecialchars((string) $hubSection['key'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></li>
                        <?php foreach ($hubSection['links'] as $hubLink): $hubRenderLink($hubLink); endforeach; ?>
                    <?php endforeach; ?>
                    </ul>

                    <section class="nav-section nav-account" id="hubNavSection-account">
                        <div class="nav-account__identity"><?php if ($accountPhotoUrl !== ''): ?><img class="nav-account__photo" src="<?= htmlspecialchars($accountPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><i class="fa-solid fa-circle-user" aria-hidden="true"></i><?php endif; ?><span><strong><?= htmlspecialchars((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? 'Hub user'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst((string) $currentRole), ENT_QUOTES, 'UTF-8') ?></small></span></div>
                        <ul class="navbar-nav nav-main mb-0">
                            <?php if (hub_auth_is_developer()): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= activeGroup(['developer.php', 'developer_analytics.php', 'developer_audit.php', 'developer_db.php', 'developer_errors.php', 'developer_roles.php', 'developer_sponsorships.php']) ?>" href="/admin/developer.php"><i class="fa-solid fa-code me-1" aria-hidden="true"></i>Developer</a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link <?= activeGroup(['help.php', 'help_edit.php']) ?>" href="/admin/help.php"><i class="fa-solid fa-circle-question me-1" aria-hidden="true"></i>Help</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/admin/profile.php"><i class="fa-solid fa-user-pen me-1" aria-hidden="true"></i>Edit profile</a>
                            </li>
                            <li class="nav-item">
                                <button id="logoutBtn" class="nav-link text-start" aria-label="Log out" type="button" data-csrf-token="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fa-solid fa-right-from-bracket me-1"></i>Log out
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </nav>


    <div class="hub-palette" id="hubPalette" role="dialog" aria-modal="true" aria-label="Find a page" hidden>
        <div class="hub-palette__panel">
            <div class="hub-palette__field">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="text" id="hubPaletteInput" placeholder="Where do you want to go? e.g. orders, sponsors, roles" autocomplete="off" spellcheck="false" aria-controls="hubPaletteList" aria-label="Find a page">
                <kbd>Esc</kbd>
            </div>
            <ul class="hub-palette__list" id="hubPaletteList" role="listbox"></ul>
        </div>
    </div>
    <script>
        (function () {
            var items = <?= json_encode(hub_navigation_search_index(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
            var root = document.getElementById('hubPalette');
            var input = document.getElementById('hubPaletteInput');
            var list = document.getElementById('hubPaletteList');
            if (!root || !input || !list) return;
            var shown = [];
            var active = 0;
            var opener = null;

            function score(item, words) {
                var hay = (item.label + ' ' + item.where).toLowerCase();
                var label = item.label.toLowerCase();
                var total = 0;
                for (var i = 0; i < words.length; i++) {
                    var at = hay.indexOf(words[i]);
                    if (at < 0) return -1;
                    total += label.indexOf(words[i]) === 0 ? 3 : (label.indexOf(words[i]) > 0 ? 2 : 1);
                }
                return total;
            }
            function render() {
                var words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
                var ranked = [];
                items.forEach(function (item) {
                    var s = words.length ? score(item, words) : 0;
                    if (s >= 0) ranked.push({ item: item, s: s });
                });
                if (words.length) ranked.sort(function (a, b) { return b.s - a.s; });
                shown = ranked.slice(0, 9).map(function (r) { return r.item; });
                active = 0;
                list.innerHTML = '';
                if (!shown.length) {
                    var empty = document.createElement('li');
                    empty.className = 'hub-palette__empty';
                    empty.textContent = 'Nothing matches. Try the Help Centre.';
                    list.appendChild(empty);
                }
                shown.forEach(function (item, index) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.className = 'hub-palette__item' + (index === 0 ? ' is-active' : '');
                    var a = document.createElement('a');
                    a.href = item.href;
                    if (item.external) { a.target = '_blank'; a.rel = 'noopener'; }
                    var name = document.createElement('span');
                    name.textContent = item.label;
                    var where = document.createElement('small');
                    where.textContent = item.where;
                    a.appendChild(name);
                    a.appendChild(where);
                    li.appendChild(a);
                    list.appendChild(li);
                });
            }
            function mark() {
                var rows = list.querySelectorAll('.hub-palette__item');
                rows.forEach(function (row, i) {
                    row.classList.toggle('is-active', i === active);
                    if (i === active) row.scrollIntoView({ block: 'nearest' });
                });
            }
            function open() {
                opener = document.activeElement;
                root.hidden = false;
                document.body.classList.add('hub-palette-open');
                input.value = '';
                render();
                input.focus();
            }
            function close() {
                root.hidden = true;
                document.body.classList.remove('hub-palette-open');
                if (opener && opener.focus) opener.focus();
            }
            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-hub-palette-open]')) { event.preventDefault(); open(); return; }
                if (!root.hidden && event.target === root) close();
            });
            document.addEventListener('keydown', function (event) {
                var typing = /^(input|textarea|select)$/i.test((event.target.tagName || '')) || event.target.isContentEditable;
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    root.hidden ? open() : close();
                    return;
                }
                if (root.hidden) return;
                if (event.key === 'Escape') { event.preventDefault(); close(); }
                else if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(active + 1, shown.length - 1); mark(); }
                else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(active - 1, 0); mark(); }
                else if (event.key === 'Enter' && shown[active]) {
                    event.preventDefault();
                    if (shown[active].external) { window.open(shown[active].href, '_blank', 'noopener'); close(); }
                    else { window.location.href = shown[active].href; }
                }
            });
            input.addEventListener('input', render);
        })();
    </script>

    <?php
    // Collapsed-sidebar rail: one icon per menu section, plus the account avatar at the bottom.
    $railName = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? 'Hub user'));
    $railInitials = '';
    foreach (array_slice(preg_split('/\s+/', $railName) ?: [], 0, 2) as $railWord) {
        $railInitials .= mb_strtoupper(mb_substr($railWord, 0, 1));
    }
    $railInitials = $railInitials !== '' ? $railInitials : 'HU';
    ?>
    <nav class="hub-sidebar-rail" id="hubSidebarRail" aria-label="Sidebar sections">
        <button class="hub-desktop-nav-toggle hub-sidebar-rail__logo" id="hubDesktopNavToggle" type="button" aria-controls="hubSideNavigation" aria-expanded="false" aria-label="Show sidebar" title="Show sidebar" data-bs-placement="right" data-bs-custom-class="hub-rail-tooltip">
            <img class="hub-sidebar-rail__club" src="club-logo-white-120.png" alt="" width="30" height="30" loading="eager">
            <i class="fa-solid fa-table-columns hub-sidebar-rail__show" aria-hidden="true"></i>
        </button>
        <ul class="hub-sidebar-rail__list">
            <?php foreach ($hubMenu as $railSection): ?>
                <?php $railLabel = $railSection['label'] !== '' ? (string) $railSection['label'] : 'Home'; ?>
                <li>
                    <button type="button" class="hub-sidebar-rail__item<?= $railSection['active'] ? ' is-active' : '' ?>" data-nav-target="hubNavSection-<?= htmlspecialchars((string) $railSection['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($railLabel, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($railLabel, ENT_QUOTES, 'UTF-8') ?>"<?= $railSection['active'] ? ' aria-current="true"' : '' ?> data-bs-placement="right" data-bs-custom-class="hub-rail-tooltip">
                        <i class="fa-solid <?= htmlspecialchars((string) $railSection['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="hub-sidebar-rail__avatar" data-nav-target="hubNavSection-account" title="<?= htmlspecialchars($railName, ENT_QUOTES, 'UTF-8') ?>" aria-label="Account: <?= htmlspecialchars($railName, ENT_QUOTES, 'UTF-8') ?>" data-bs-placement="right" data-bs-custom-class="hub-rail-tooltip"><?php if ($accountPhotoUrl !== ''): ?><img class="hub-sidebar-rail__photo" src="<?= htmlspecialchars($accountPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php else: ?><?= htmlspecialchars($railInitials, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></button>
    </nav>

    <main class="hub-main" id="hubMainContent" tabindex="-1">
        <div class="container-fluid">
        <?php if (!empty($pageHero) && is_array($pageHero)): ?>
            <?php hub_render_page_hero($pageHero); ?>
        <?php endif; ?>
        <?php $hubStrip = hub_navigation_strip(); ?>
        <?php if ($hubStrip !== null): ?>
            <nav class="hub-section-tabs" aria-label="<?= htmlspecialchars($hubStrip['label'], ENT_QUOTES, 'UTF-8') ?> sections">
                <ul class="nav">
                    <?php foreach ($hubStrip['tabs'] as $stripTab): ?>
                        <li class="nav-item"><a class="nav-link<?= !empty($stripTab['current']) ? ' active' : '' ?>"<?= !empty($stripTab['current']) ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars((string) $stripTab['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $stripTab['label'], ENT_QUOTES, 'UTF-8') ?><?= order_alert_badge(hub_navigation_badge_total($hubOrderAlerts, $stripTab['badge'] ?? [])) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <script>
        (function() {
            var logoutButton = document.getElementById('logoutBtn');
            if (!logoutButton) {
                return;
            }

            logoutButton.addEventListener('click', function() {
                var button = this;
                var originalText = button.textContent;

                button.disabled = true;
                button.textContent = 'Logging Out...';

                fetch('auth_endpoint.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'csrf_token=' + encodeURIComponent(button.getAttribute('data-csrf-token') || '')
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Logout failed.');
                    }
                }).then(function() {
                    window.location.href = 'login.php';
                }).catch(function() {
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });
        })();
    </script>
