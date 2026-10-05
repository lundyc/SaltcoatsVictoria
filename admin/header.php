<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/navigation_settings.php';
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
            . '<p>Your account doesn\'t have access to any Hub pages yet. Ask an admin to assign you an access template under Admin access on your profile.</p>'
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
            'title' => 'People & Users',
            'subtitle' => 'Legacy users URL. Manage account access from People & Users.',
            'actions' => [
                ['label' => 'People & Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_people.php' => [
            'eyebrow' => 'Administration',
            'title' => 'People & Users',
            'subtitle' => 'One record per person: supporters, staff, volunteers and committee. Login access, positions and season tickets attach to the person.',
            'actions' => [
                ['label' => 'Club roles', 'href' => '/positions.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_person.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Person & User',
            'subtitle' => 'Manage one person, their login account, club positions, dependants and ticket history.',
            'actions' => [
                ['label' => 'People & Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
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
$documentTitle = trim((string) ($pageHero['title'] ?? ''));
$documentTitle = $documentTitle !== '' ? $documentTitle . ' – ' . APP_NAME : APP_NAME;

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#4b0818">
    <title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="/admin/assets/js/app.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/app.js') ?: time()) ?>" defer></script>
</head>

<body class="hub-shell">
    <a class="hub-skip-link" href="#hubMainContent">Skip to main content</a>
    <nav class="navbar navbar-expand-lg border-bottom shadow-sm navbar-dark" id="hubSideNavigation">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-brand" href="index.php">
                <img
                    src="Saltcoats Victoria FC -White_Transparent.png"
                    alt="Saltcoats Victoria FC"
                    class="navbar-brand__logo"
                    loading="eager"
                >
                <span class="navbar-brand__text"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

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
                ?>
                <div class="nav-sections d-flex flex-column w-100">
                    <?php if (($seasonContext['seasons'] ?? []) !== []): ?>
                        <form class="hub-season-switcher" method="get" action="<?= htmlspecialchars($currentScript, ENT_QUOTES, 'UTF-8') ?>" id="hubSeasonSwitcherForm">
                            <label for="hubSeasonSelect">Working season</label>
                            <div class="hub-season-switcher__control">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                <select id="hubSeasonSelect" name="season_id" aria-describedby="hubSeasonHelp">
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
                    <?php endif; ?>
                    <?php if (hub_navigation_group_available('overview')): ?>
<section class="nav-section">
                        <div class="nav-section-label">Overview</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <?php if (hub_auth_is_developer()): ?>
                                <?php if (hub_navigation_item_visible('overview.developer_analytics')): ?><li class="nav-item"><a class="nav-link <?= activePage('developer_analytics.php') ?>"<?= hub_nav_current(['developer_analytics.php']) ?> href="/admin/developer_analytics.php"><i class="fa-solid fa-chart-line me-1" aria-hidden="true"></i>Product analytics</a></li><?php endif; ?>
                            <?php endif; ?>
                            <?php if (hub_navigation_item_visible('overview.index')): ?><li class="nav-item"><a class="nav-link <?= activePage('index.php') ?>"<?= hub_nav_current(['index.php']) ?> href="/admin/index.php"><i class="fa-solid fa-house me-1" aria-hidden="true"></i>Club overview</a></li><?php endif; ?>
                            <?php if (hub_auth_has_any_capability(['finance_view', 'matchday', 'club_setup', 'tickets_ops', 'secretary_ops'])): ?>
                                <?php if (hub_navigation_item_visible('overview.club_reminders')): ?><li class="nav-item"><a class="nav-link <?= activePage('club_reminders.php') ?>"<?= hub_nav_current(['club_reminders.php']) ?> href="/admin/club_reminders.php"><i class="fa-solid fa-bell me-1" aria-hidden="true"></i>Reminders</a></li><?php endif; ?>
                            <?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>

                    <?php if (hub_auth_has_any_capability(['matchday', 'tickets_ops', 'finance_view'])): ?>
                    <?php if (hub_navigation_group_available('matchday')): ?>
<section class="nav-section">
                        <?php
                        $matchdayPages = ['stats.php', 'matches.php', 'match.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php', 'match_media.php', 'players.php', 'player_add.php', 'player_edit.php', 'player_view.php', 'scan_overview.php', 'pos_overview.php', 'matchday_finance.php', 'matchday_finance_edit.php'];
                        $matchdayReqUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
                        $matchdayActive = (activeGroup($matchdayPages) === 'active' || str_starts_with($matchdayReqUri, '/scan') || str_starts_with($matchdayReqUri, '/pos')) ? 'active' : '';
                        // Match Day is the daily driver — open on first visit; the
                        // stored per-browser preference can still collapse it.
                        $matchdayOpen = true;
                        ?>
                        <button class="nav-section-toggle <?= $matchdayActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubMatchdayNavigation" data-nav-section="matchday" aria-expanded="<?= $matchdayOpen ? 'true' : 'false' ?>" aria-controls="hubMatchdayNavigation">
                            <span>Match Day</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $matchdayOpen ? 'show' : '' ?> mb-0" id="hubMatchdayNavigation">
                            <?php if (hub_auth_has_capability('matchday')): ?>
                            <?php if (hub_navigation_item_visible('matchday.matches')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['matches.php', 'match.php', 'match_lineups.php', 'match_record_events.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php', 'match_media.php']) ?>"<?= hub_nav_current(['matches.php', 'match.php', 'match_lineups.php', 'match_record_events.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php']) ?> href="/admin/matches.php"><i class="fa-solid fa-futbol me-1" aria-hidden="true"></i>Fixtures &amp; results</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('matchday.stats')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['stats.php', 'matchday_stats.php']) ?>"<?= hub_nav_current(['stats.php', 'matchday_stats.php']) ?> href="/admin/stats.php"><i class="fa-solid fa-chart-simple me-1" aria-hidden="true"></i>Stats</a></li><?php endif; ?>
                            <?php endif; ?>
                            <?php if (hub_auth_has_capability('tickets_ops')): ?>
                            <?php if (hub_navigation_item_visible('matchday.scan_overview')): ?><li class="nav-item"><a class="nav-link <?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/scan') ? 'active' : '' ?>"<?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/scan') ? ' aria-current="page"' : '' ?> href="/admin/scan_overview.php"><i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>Scan</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('matchday.pos_overview')): ?><li class="nav-item"><a class="nav-link <?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/pos') ? 'active' : '' ?>"<?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/pos') ? ' aria-current="page"' : '' ?> href="/admin/pos_overview.php"><i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>POS</a></li><?php endif; ?>
                            <?php endif; ?>
                            <?php if (hub_auth_has_any_capability(['finance_view', 'matchday'])): ?>
                            <?php if (hub_navigation_item_visible('matchday.matchday_finance')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['matchday_finance.php', 'matchday_finance_edit.php']) ?>"<?= hub_nav_current(['matchday_finance.php', 'matchday_finance_edit.php']) ?> href="/admin/matchday_finance.php"><i class="fa-solid fa-sterling-sign me-1" aria-hidden="true"></i>Matchday income</a></li><?php endif; ?>
                            <?php endif; ?>
                            <?php if (hub_auth_has_capability('matchday')): ?>
                            <?php if (hub_navigation_item_visible('matchday.players')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['players.php', 'player_add.php', 'player_edit.php', 'player_view.php']) ?>"<?= hub_nav_current(['players.php', 'player_add.php', 'player_edit.php', 'player_view.php']) ?> href="/admin/players.php"><i class="fa-solid fa-users me-1" aria-hidden="true"></i>Players</a></li><?php endif; ?>
                            <?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('finance_view')): ?>
                    <?php if (hub_navigation_group_available('sponsorship')): ?>
<section class="nav-section">
                        <?php $sponsorshipActive = activeGroup(['sponsors.php', 'sponsor.php', 'sponsor_followups.php', 'sponsorship_agreements.php', 'sponsorship_agreement.php', 'sponsorship_bundles.php', 'sponsorship_bundle.php', 'sponsorship_packages.php', 'sponsorship_package.php', 'sponsorship_types.php', 'sponsorship_type.php']); ?>
                        <button class="nav-section-toggle <?= $sponsorshipActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubSponsorshipNavigation" data-nav-section="sponsorship" aria-expanded="<?= $sponsorshipActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubSponsorshipNavigation">
                            <span>Sponsorship</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $sponsorshipActive === 'active' ? 'show' : '' ?> mb-0" id="hubSponsorshipNavigation">
                            <?php if (hub_navigation_item_visible('sponsorship.sponsors')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['sponsors.php','sponsor.php']) ?>"<?= hub_nav_current(['sponsors.php', 'sponsor.php']) ?> href="/admin/sponsors.php"><i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Sponsors</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('sponsorship.sponsor_followups')): ?><li class="nav-item"><a class="nav-link <?= activePage('sponsor_followups.php') ?>"<?= hub_nav_current(['sponsor_followups.php']) ?> href="/admin/sponsor_followups.php"><i class="fa-solid fa-phone-volume me-1" aria-hidden="true"></i>Follow-ups</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('sponsorship.sponsorship_agreements')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_agreements.php','sponsorship_agreement.php']) ?>"<?= hub_nav_current(['sponsorship_agreements.php', 'sponsorship_agreement.php']) ?> href="/admin/sponsorship_agreements.php"><i class="fa-solid fa-file-signature me-1" aria-hidden="true"></i>Agreements</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('sponsorship.sponsorship_bundles')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_bundles.php','sponsorship_bundle.php']) ?>"<?= hub_nav_current(['sponsorship_bundles.php', 'sponsorship_bundle.php']) ?> href="/admin/sponsorship_bundles.php"><i class="fa-solid fa-boxes-stacked me-1" aria-hidden="true"></i>Bundles</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('sponsorship.sponsorship_packages')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_packages.php','sponsorship_package.php', 'sponsorship_types.php', 'sponsorship_type.php']) ?>"<?= hub_nav_current(['sponsorship_packages.php', 'sponsorship_package.php']) ?> href="/admin/sponsorship_packages.php"><i class="fa-solid fa-box-open me-1" aria-hidden="true"></i>Packages</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>


                    <?php if (hub_auth_has_capability('shop')): ?>
                    <?php if (hub_navigation_group_available('shop')): ?>
<section class="nav-section">
                        <?php $shopActive = activeGroup(['shop_overview.php', 'shop_products.php', 'shop_product.php', 'shop_categories.php', 'shop_modifiers.php', 'shop_orders.php', 'shop_order.php', 'shop_settings.php', 'shop_discount_codes.php']); ?>
                        <button class="nav-section-toggle <?= $shopActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubShopNavigation" data-nav-section="shop" aria-expanded="<?= $shopActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubShopNavigation">
                            <span>Shop</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $shopActive === 'active' ? 'show' : '' ?> mb-0" id="hubShopNavigation">
                            <?php if (hub_navigation_item_visible('shop.shop_overview')): ?><li class="nav-item"><a class="nav-link <?= activePage('shop_overview.php') ?>"<?= hub_nav_current(['shop_overview.php']) ?> href="/admin/shop_overview.php"><i class="fa-solid fa-bag-shopping me-1" aria-hidden="true"></i>Overview</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_orders')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['shop_orders.php', 'shop_order.php']) ?>"<?= hub_nav_current(['shop_orders.php', 'shop_order.php']) ?> href="/admin/shop_orders.php"><i class="fa-solid fa-receipt me-1" aria-hidden="true"></i>Orders</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_products')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['shop_products.php', 'shop_product.php']) ?>"<?= hub_nav_current(['shop_products.php', 'shop_product.php']) ?> href="/admin/shop_products.php"><i class="fa-solid fa-shirt me-1" aria-hidden="true"></i>Products</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_categories')): ?><li class="nav-item"><a class="nav-link <?= activePage('shop_categories.php') ?>"<?= hub_nav_current(['shop_categories.php']) ?> href="/admin/shop_categories.php"><i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>Categories</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_modifiers')): ?><li class="nav-item"><a class="nav-link <?= activePage('shop_modifiers.php') ?>"<?= hub_nav_current(['shop_modifiers.php']) ?> href="/admin/shop_modifiers.php"><i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>Modifiers</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_discount_codes')): ?><li class="nav-item"><a class="nav-link <?= activePage('shop_discount_codes.php') ?>"<?= hub_nav_current(['shop_discount_codes.php']) ?> href="/admin/shop_discount_codes.php"><i class="fa-solid fa-tag me-1" aria-hidden="true"></i>Discount codes</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.shop_settings')): ?><li class="nav-item"><a class="nav-link <?= activePage('shop_settings.php') ?>"<?= hub_nav_current(['shop_settings.php']) ?> href="/admin/shop_settings.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Shop settings</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('shop.storefront')): ?><li class="nav-item"><a class="nav-link" href="/shop/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>View storefront</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('tickets_ops')): ?>
                    <?php if (hub_navigation_group_available('ticketing')): ?>
<section class="nav-section">
                        <?php $ticketingActive = activeGroup(['season_ticket_orders.php', 'season_ticket_order.php', 'fixture_tickets.php', 'ticket_orders.php', 'ticket_packages.php', 'season_ticket_renewals.php', 'season_ticket_free_codes.php', 'season_ticket_types.php', 'season_ticket_type.php']); ?>
                        <button class="nav-section-toggle <?= $ticketingActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubTicketingNavigation" data-nav-section="ticketing" aria-expanded="<?= $ticketingActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubTicketingNavigation">
                            <span>Ticketing</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $ticketingActive === 'active' ? 'show' : '' ?> mb-0" id="hubTicketingNavigation">
                            <?php if (hub_navigation_item_visible('ticketing.season_ticket_orders')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['season_ticket_orders.php', 'season_ticket_order.php']) ?>"<?= hub_nav_current(['season_ticket_orders.php', 'season_ticket_order.php']) ?> href="/admin/season_ticket_orders.php"><i class="fa-solid fa-id-card me-1" aria-hidden="true"></i>Season Ticket Orders</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.fixture_tickets')): ?><li class="nav-item"><a class="nav-link <?= activePage('fixture_tickets.php') ?>"<?= hub_nav_current(['fixture_tickets.php']) ?> href="/admin/fixture_tickets.php"><i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>Match Tickets</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.ticket_orders')): ?><li class="nav-item"><a class="nav-link <?= activePage('ticket_orders.php') ?>"<?= hub_nav_current(['ticket_orders.php']) ?> href="/admin/ticket_orders.php"><i class="fa-solid fa-list-check me-1" aria-hidden="true"></i>Ticket Orders</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.ticket_packages')): ?><li class="nav-item"><a class="nav-link <?= activePage('ticket_packages.php') ?>"<?= hub_nav_current(['ticket_packages.php']) ?> href="/admin/ticket_packages.php"><i class="fa-solid fa-box-open me-1" aria-hidden="true"></i>Ticket Packages</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.season_ticket_renewals')): ?><li class="nav-item"><a class="nav-link <?= activePage('season_ticket_renewals.php') ?>"<?= hub_nav_current(['season_ticket_renewals.php']) ?> href="/admin/season_ticket_renewals.php"><i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>Renewals</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.season_ticket_free_codes')): ?><li class="nav-item"><a class="nav-link <?= activePage('season_ticket_free_codes.php') ?>"<?= hub_nav_current(['season_ticket_free_codes.php']) ?> href="/admin/season_ticket_free_codes.php"><i class="fa-solid fa-key me-1" aria-hidden="true"></i>Free Signup Codes</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('ticketing.season_ticket_types')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['season_ticket_types.php', 'season_ticket_type.php']) ?>"<?= hub_nav_current(['season_ticket_types.php', 'season_ticket_type.php']) ?> href="/admin/season_ticket_types.php"><i class="fa-solid fa-tags me-1" aria-hidden="true"></i>Types &amp; Pricing</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                    <?php if (hub_navigation_group_available('supporter')): ?>
<section class="nav-section">
                        <?php $membersActive = activeGroup(['announcements.php', 'announcement.php', 'feedback.php', 'feedback_item.php', 'venue_reviews.php', 'motm.php']); ?>
                        <button class="nav-section-toggle <?= $membersActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubMembersNavigation" data-nav-section="supporter" aria-expanded="<?= $membersActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubMembersNavigation">
                            <span>Supporter content</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $membersActive === 'active' ? 'show' : '' ?> mb-0" id="hubMembersNavigation">
                            <?php if (hub_navigation_item_visible('supporter.announcements')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['announcements.php', 'announcement.php']) ?>"<?= hub_nav_current(['announcements.php', 'announcement.php']) ?> href="/admin/announcements.php"><i class="fa-solid fa-bullhorn me-1" aria-hidden="true"></i>Announcements</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('supporter.feedback')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['feedback.php', 'feedback_item.php']) ?>"<?= hub_nav_current(['feedback.php', 'feedback_item.php']) ?> href="/admin/feedback.php"><i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i>Feedback</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('supporter.venue_reviews')): ?><li class="nav-item"><a class="nav-link <?= activePage('venue_reviews.php') ?>"<?= hub_nav_current(['venue_reviews.php']) ?> href="/admin/venue_reviews.php"><i class="fa-solid fa-star me-1" aria-hidden="true"></i>Venue Reviews</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('supporter.motm')): ?><li class="nav-item"><a class="nav-link <?= activePage('motm.php') ?>"<?= hub_nav_current(['motm.php']) ?> href="/admin/motm.php"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i>Player of the Match</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('website')): ?>
                    <?php if (hub_navigation_group_available('website')): ?>
<section class="nav-section">
                        <?php $websiteActive = activeGroup(['news.php', 'news_edit.php', 'club_pages.php', 'settings_public.php', 'player_website.php']); ?>
                        <button class="nav-section-toggle <?= $websiteActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubWebsiteNavigation" data-nav-section="website" aria-expanded="<?= $websiteActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubWebsiteNavigation">
                            <span>Public website</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $websiteActive === 'active' ? 'show' : '' ?> mb-0" id="hubWebsiteNavigation">
                            <?php if (hub_navigation_item_visible('website.news')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['news.php', 'news_edit.php']) ?>"<?= hub_nav_current(['news.php', 'news_edit.php']) ?> href="/admin/news.php"><i class="fa-solid fa-newspaper me-1" aria-hidden="true"></i>News</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('website.club_pages')): ?><li class="nav-item"><a class="nav-link <?= activePage('club_pages.php') ?>"<?= hub_nav_current(['club_pages.php']) ?> href="/admin/club_pages.php"><i class="fa-solid fa-file-lines me-1" aria-hidden="true"></i>Club pages</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('website.settings_public')): ?><li class="nav-item"><a class="nav-link <?= activePage('settings_public.php') ?>"<?= hub_nav_current(['settings_public.php']) ?> href="/admin/settings_public.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Website settings</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('website.website')): ?><li class="nav-item"><a class="nav-link" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>View website</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_any_capability(['finance_view', 'matchday'])): ?>
                    <?php if (hub_navigation_group_available('finance')): ?>
<section class="nav-section">
                        <?php $financeActive = activeGroup(['reports.php', 'stripe_dashboard.php', 'matchday_finance.php', 'matchday_finance_edit.php']); ?>
                        <button class="nav-section-toggle <?= $financeActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubFinanceNavigation" data-nav-section="finance" aria-expanded="<?= $financeActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubFinanceNavigation">
                            <span>Finance</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $financeActive === 'active' ? 'show' : '' ?> mb-0" id="hubFinanceNavigation">
                            <?php if (hub_navigation_item_visible('finance.reports')): ?><li class="nav-item"><a class="nav-link <?= activePage('reports.php') ?>"<?= hub_nav_current(['reports.php']) ?> href="/admin/reports.php"><i class="fa-solid fa-chart-column me-1" aria-hidden="true"></i>Reports</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('finance.matchday_finance')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['matchday_finance.php', 'matchday_finance_edit.php']) ?>"<?= hub_nav_current(['matchday_finance.php', 'matchday_finance_edit.php']) ?> href="/admin/matchday_finance.php"><i class="fa-solid fa-sterling-sign me-1" aria-hidden="true"></i>Matchday income</a></li><?php endif; ?>
                            <?php if (hub_auth_has_capability('finance_view')): ?>
                            <?php if (hub_navigation_item_visible('finance.stripe_dashboard')): ?><li class="nav-item"><a class="nav-link <?= activePage('stripe_dashboard.php') ?>"<?= hub_nav_current(['stripe_dashboard.php']) ?> href="/admin/stripe_dashboard.php"><i class="fa-brands fa-stripe-s me-1" aria-hidden="true"></i>Stripe Dashboard</a></li><?php endif; ?>
                            <?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('secretary_ops')): ?>
                    <?php if (hub_navigation_group_available('secretary')): ?>
<section class="nav-section">
                        <?php $secretaryActive = activeGroup(['secretary_dashboard.php', 'discipline_register.php', 'discipline_incident.php', 'discipline_incident_delete.php', 'player_registrations.php', 'player_registration_edit.php', 'secretary_tasks.php', 'secretary_task_edit.php', 'secretary_correspondence.php', 'secretary_correspondence_edit.php', 'fixture_change_requests.php', 'fixture_change_request_edit.php', 'committee_meetings.php', 'committee_meeting_edit.php', 'committee_meeting_delete.php', 'secretary_documents.php', 'secretary_document_delete.php', 'secretary_guide.php']); ?>
                        <button class="nav-section-toggle <?= $secretaryActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubSecretaryNavigation" data-nav-section="secretary" aria-expanded="<?= $secretaryActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubSecretaryNavigation">
                            <span>Secretary</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $secretaryActive === 'active' ? 'show' : '' ?> mb-0" id="hubSecretaryNavigation">
                            <?php if (hub_navigation_item_visible('secretary.secretary_dashboard')): ?><li class="nav-item"><a class="nav-link <?= activePage('secretary_dashboard.php') ?>"<?= hub_nav_current(['secretary_dashboard.php']) ?> href="/admin/secretary_dashboard.php"><i class="fa-solid fa-user-tie me-1" aria-hidden="true"></i>Dashboard</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.discipline_register')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['discipline_register.php', 'discipline_incident.php', 'discipline_incident_delete.php']) ?>"<?= hub_nav_current(['discipline_register.php', 'discipline_incident.php', 'discipline_incident_delete.php']) ?> href="/admin/discipline_register.php"><i class="fa-solid fa-square-exclamation me-1" aria-hidden="true"></i>Discipline register</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.player_registrations')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['player_registrations.php', 'player_registration_edit.php']) ?>"<?= hub_nav_current(['player_registrations.php', 'player_registration_edit.php']) ?> href="/admin/player_registrations.php"><i class="fa-solid fa-id-card-clip me-1" aria-hidden="true"></i>Player registrations</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.secretary_tasks')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_tasks.php', 'secretary_task_edit.php']) ?>"<?= hub_nav_current(['secretary_tasks.php', 'secretary_task_edit.php']) ?> href="/admin/secretary_tasks.php"><i class="fa-solid fa-list-check me-1" aria-hidden="true"></i>Tasks &amp; planner</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.secretary_correspondence')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_correspondence.php', 'secretary_correspondence_edit.php']) ?>"<?= hub_nav_current(['secretary_correspondence.php', 'secretary_correspondence_edit.php']) ?> href="/admin/secretary_correspondence.php"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Correspondence</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.fixture_change_requests')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['fixture_change_requests.php', 'fixture_change_request_edit.php']) ?>"<?= hub_nav_current(['fixture_change_requests.php', 'fixture_change_request_edit.php']) ?> href="/admin/fixture_change_requests.php"><i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>Fixture changes</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.committee_meetings')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['committee_meetings.php', 'committee_meeting_edit.php', 'committee_meeting_delete.php']) ?>"<?= hub_nav_current(['committee_meetings.php', 'committee_meeting_edit.php', 'committee_meeting_delete.php']) ?> href="/admin/committee_meetings.php"><i class="fa-solid fa-people-group me-1" aria-hidden="true"></i>Committee &amp; AGM</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.secretary_documents')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_documents.php', 'secretary_document_delete.php']) ?>"<?= hub_nav_current(['secretary_documents.php', 'secretary_document_delete.php']) ?> href="/admin/secretary_documents.php"><i class="fa-solid fa-folder-tree me-1" aria-hidden="true"></i>Documents</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('secretary.secretary_guide')): ?><li class="nav-item"><a class="nav-link <?= activePage('secretary_guide.php') ?>"<?= hub_nav_current(['secretary_guide.php']) ?> href="/admin/secretary_guide.php"><i class="fa-solid fa-circle-question me-1" aria-hidden="true"></i>Emergency guide</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('club_setup')): ?>
                    <?php if (hub_navigation_group_available('clubsetup')): ?>
<section class="nav-section">
                        <?php $setupActive = activeGroup(['seasons.php', 'season.php', 'opponents.php', 'opponent.php', 'competitions.php', 'competition.php', 'venues.php', 'venue.php', 'facilities.php']); ?>
                        <button class="nav-section-toggle <?= $setupActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubSetupNavigation" data-nav-section="clubsetup" aria-expanded="<?= $setupActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubSetupNavigation">
                            <span>Club setup</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $setupActive === 'active' ? 'show' : '' ?> mb-0" id="hubSetupNavigation">
                            <?php if (hub_navigation_item_visible('clubsetup.seasons')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['seasons.php', 'season.php']) ?>" href="/admin/seasons.php"><i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>Seasons</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('clubsetup.opponents')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['opponents.php', 'opponent.php']) ?>" href="/admin/opponents.php"><i class="fa-solid fa-people-arrows me-1" aria-hidden="true"></i>Opponents</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('clubsetup.competitions')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['competitions.php', 'competition.php']) ?>" href="/admin/competitions.php"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i>Competitions</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('clubsetup.venues')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['venues.php', 'venue.php']) ?>" href="/admin/venues.php"><i class="fa-solid fa-map-location-dot me-1" aria-hidden="true"></i>Venues</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('clubsetup.facilities')): ?><li class="nav-item"><a class="nav-link <?= activePage('facilities.php') ?>"<?= hub_nav_current(['facilities.php']) ?> href="/admin/facilities.php"><i class="fa-solid fa-screwdriver-wrench me-1" aria-hidden="true"></i>Facilities</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('content_social')): ?>
                    <?php if (hub_navigation_group_available('publishing')): ?>
<section class="nav-section">
                        <?php $publishingActive = activeGroup(['league_table.php', 'template_packs.php', 'template_pack.php', 'templates.php']); ?>
                        <button class="nav-section-toggle <?= $publishingActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubPublishingNavigation" data-nav-section="publishing" aria-expanded="<?= $publishingActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubPublishingNavigation">
                            <span>Publishing</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $publishingActive === 'active' ? 'show' : '' ?> mb-0" id="hubPublishingNavigation">
                            <?php if (hub_navigation_item_visible('publishing.league_table')): ?><li class="nav-item"><a class="nav-link <?= activePage('league_table.php') ?>"<?= hub_nav_current(['league_table.php']) ?> href="/admin/league_table.php"><i class="fa-solid fa-table me-1" aria-hidden="true"></i>League table</a></li><?php endif; ?>
                            <?php if (hub_navigation_item_visible('publishing.template_packs')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['template_packs.php', 'template_pack.php', 'templates.php']) ?>" href="/admin/template_packs.php"><i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>Template packs</a></li><?php endif; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (hub_auth_has_capability('admin_settings')): ?>
                        <?php if (hub_navigation_group_available('admin')): ?>
<section class="nav-section">
                            <?php $adminActive = activeGroup(['match_photos.php', 'media.php', 'facebook_photo_import.php', 'people.php', 'photo_albums.php', 'photo_album.php', 'settings.php', 'social_post_settings.php', 'club_people.php', 'club_person.php', 'positions.php', 'access_roles.php', 'access_role_edit.php', 'access_review.php']); ?>
                            <button class="nav-section-toggle <?= $adminActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubAdminNavigation" data-nav-section="admin" aria-expanded="<?= $adminActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubAdminNavigation">
                                <span>Admin</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </button>
                            <ul class="navbar-nav nav-main nav-submenu collapse <?= $adminActive === 'active' ? 'show' : '' ?> mb-0" id="hubAdminNavigation">
                                <?php if (hub_navigation_item_visible('admin.match_photos')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['match_photos.php', 'media.php', 'facebook_photo_import.php']) ?>"<?= hub_nav_current(['match_photos.php', 'media.php', 'facebook_photo_import.php']) ?> href="/admin/match_photos.php"><i class="fa-solid fa-photo-film me-1" aria-hidden="true"></i>Media Library</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.people')): ?><li class="nav-item"><a class="nav-link <?= activePage('people.php') ?>"<?= hub_nav_current(['people.php']) ?> href="/admin/people.php"><i class="fa-solid fa-user-tag me-1" aria-hidden="true"></i>Photo tags</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.photo_albums')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['photo_albums.php', 'photo_album.php']) ?>"<?= hub_nav_current(['photo_albums.php', 'photo_album.php']) ?> href="/admin/photo_albums.php"><i class="fa-solid fa-images me-1" aria-hidden="true"></i>Photo albums</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.settings')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['settings.php', 'social_post_settings.php']) ?>"<?= hub_nav_current(['settings.php', 'social_post_settings.php']) ?> href="/admin/settings.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Settings</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.club_people')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['club_people.php', 'club_person.php']) ?>"<?= hub_nav_current(['club_people.php', 'club_person.php']) ?> href="/admin/club_people.php"><i class="fa-solid fa-address-book me-1" aria-hidden="true"></i>People &amp; Users</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.positions')): ?><li class="nav-item"><a class="nav-link <?= activePage('positions.php') ?>"<?= hub_nav_current(['positions.php']) ?> href="/admin/positions.php"><i class="fa-solid fa-sitemap me-1" aria-hidden="true"></i>Club roles</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.access_roles')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['access_roles.php', 'access_role_edit.php']) ?>"<?= hub_nav_current(['access_roles.php', 'access_role_edit.php']) ?> href="/admin/access_roles.php"><i class="fa-solid fa-table-cells me-1" aria-hidden="true"></i>Access templates</a></li><?php endif; ?>
                                <?php if (hub_navigation_item_visible('admin.access_review')): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['access_review.php']) ?>"<?= hub_nav_current(['access_review.php']) ?> href="/admin/access_review.php"><i class="fa-solid fa-clipboard-check me-1" aria-hidden="true"></i>Access review</a></li><?php endif; ?>
                            </ul>
                        </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <section class="nav-section nav-account">
                        <div class="nav-account__identity"><i class="fa-solid fa-circle-user" aria-hidden="true"></i><span><strong><?= htmlspecialchars((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? 'Hub user'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst((string) $currentRole), ENT_QUOTES, 'UTF-8') ?></small></span></div>
                        <ul class="navbar-nav nav-main mb-0">
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

    <button class="hub-desktop-nav-toggle d-none d-xl-inline-flex" id="hubDesktopNavToggle" type="button" aria-controls="hubSideNavigation" aria-expanded="true" aria-label="Hide side navigation" title="Hide side navigation">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
    </button>

    <main class="hub-main" id="hubMainContent" tabindex="-1">
        <div class="container-fluid">
        <?php if (!empty($pageHero) && is_array($pageHero)): ?>
            <?php hub_render_page_hero($pageHero); ?>
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
