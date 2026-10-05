<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/matches_lib.php';

$app = app_bootstrap_state();
$isAuthenticated = $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];
$statusType = '';
$statusMessage = '';
$statusDetails = [];
$graphicTemplateDefinitions = matches_graphic_template_definitions();
$graphicTemplateChannels = matches_graphic_template_channels();

$matchId = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$matches = matches_load_all();
$match = $matchId !== '' ? matches_find_by_id($matches, $matchId) : null;
$templateConfig = $match !== null ? matches_template_config((string) ($match['template_key'] ?? null)) : matches_template_config(null);
$matchLabel = $match !== null ? matches_fixture_label($match) : 'Match Templates';
$matchDate = $match !== null ? app_format_uk_date((string) ($match['match_date'] ?? ''), 'Not set') : 'Not set';
$matchVenue = $match !== null ? matches_format_venue((string) ($match['venue'] ?? 'H')) : 'Home';
$matchCompetition = $match !== null ? trim((string) ($match['competition'] ?? '')) : '';

if ($isAuthenticated && $match !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'save_match_background') {
        $result = matches_handle_save_background_asset($_POST, $_FILES);
        if ($result['ok']) {
            $redirectMatchId = isset($result['match_id']) && is_string($result['match_id']) && $result['match_id'] !== ''
                ? $result['match_id']
                : $matchId;
            header(
                'Location: match_templates.php?id=' . rawurlencode($redirectMatchId)
                . '&status=' . rawurlencode('background_saved')
            );
            exit;
        }

        $statusType = 'error';
        $statusMessage = $result['message'];
        $statusDetails = $result['errors'] ?? [];
        $matches = matches_load_all();
        $match = $matchId !== '' ? matches_find_by_id($matches, $matchId) : null;
    } elseif ($action === 'delete_match_background') {
        $result = matches_handle_delete_background_asset($_POST);
        if ($result['ok']) {
            $redirectMatchId = isset($result['match_id']) && is_string($result['match_id']) && $result['match_id'] !== ''
                ? $result['match_id']
                : $matchId;
            header(
                'Location: match_templates.php?id=' . rawurlencode($redirectMatchId)
                . '&status=' . rawurlencode('background_deleted')
            );
            exit;
        }

        $statusType = 'error';
        $statusMessage = $result['message'];
    } elseif ($action === 'save_graphic_template') {
        $result = matches_handle_save_graphic_template($_POST, $_FILES);
        if ($result['ok']) {
            header(
                'Location: match_templates.php?id=' . rawurlencode($matchId)
                . '&status=' . rawurlencode('saved')
                . '&template=' . rawurlencode((string) ($_POST['template_type'] ?? ''))
            );
            exit;
        }

        $statusType = 'error';
        $statusMessage = $result['message'];
        $statusDetails = $result['errors'] ?? [];
        $matches = matches_load_all();
        $match = $matchId !== '' ? matches_find_by_id($matches, $matchId) : null;
        if ($match !== null) {
            $templateType = isset($_POST['template_type']) && is_string($_POST['template_type']) ? trim($_POST['template_type']) : '';
            $templates = matches_normalize_graphic_templates($match['graphics_templates'] ?? []);
            if ($templateType !== '' && isset($templates[$templateType])) {
                foreach ($graphicTemplateChannels as $channelKey => $channelLabel) {
                    $templates[$templateType]['captions'][$channelKey] = isset($_POST['captions'][$channelKey]) && is_string($_POST['captions'][$channelKey])
                        ? trim($_POST['captions'][$channelKey])
                        : (string) ($templates[$templateType]['captions'][$channelKey] ?? '');
                }
                $match['graphics_templates'] = $templates;
            }
        }
    } elseif ($action === 'delete_graphic_template') {
        $result = matches_handle_delete_graphic_template($_POST);
        if ($result['ok']) {
            header(
                'Location: match_templates.php?id=' . rawurlencode($matchId)
                . '&status=' . rawurlencode('deleted')
                . '&template=' . rawurlencode((string) ($_POST['template_type'] ?? ''))
            );
            exit;
        }

        $statusType = 'error';
        $statusMessage = $result['message'];
    }
}

if ($statusMessage === '' && isset($_GET['status']) && is_string($_GET['status'])) {
    $templateConfig = matches_graphic_template_config(isset($_GET['template']) && is_string($_GET['template']) ? $_GET['template'] : null);
    $templateLabel = $templateConfig['key'] !== '' ? $templateConfig['label'] : 'Graphic template';
    if ($_GET['status'] === 'background_saved') {
        $statusType = 'success';
        $statusMessage = 'Starting XI background saved successfully.';
    } elseif ($_GET['status'] === 'background_deleted') {
        $statusType = 'success';
        $statusMessage = 'Starting XI background removed successfully.';
    } elseif ($_GET['status'] === 'saved') {
        $statusType = 'success';
        $statusMessage = $templateLabel . ' saved successfully.';
    } elseif ($_GET['status'] === 'deleted') {
        $statusType = 'success';
        $statusMessage = $templateLabel . ' deleted successfully.';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $match !== null ? safe(matches_fixture_label($match)) . ' Templates – Club Hub' : 'Match templates – Club Hub' ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/social-publishing.css?v=<?= $styleVersion ?>">
</head>

<body class="bg-cream legacy-shell">
    <?php if (!$isAuthenticated): ?>
        <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria match templates.'); ?>
    <?php endif; ?>

    <?php if ($isAuthenticated): ?>
        <header class="site-header py-3">
            <div class="container-fluid">
                <?php app_render_primary_nav('matches'); ?>
            </div>
        </header>

        <main id="hubMainContent" class="pb-4" tabindex="-1">
            <div class="container-fluid">
                <?php if ($match === null): ?>
                    <section class="utility-panel">
                        <p class="page-kicker">Match Not Found</p>
                        <h1 class="feature-card__title">This fixture does not exist.</h1>
                        <p class="utility-panel__copy">Go back to the matches list and open a valid fixture.</p>
                        <a class="btn btn-brand" href="matches.php">Back to Matches</a>
                    </section>
                <?php else: ?>
                    <section class="mb-4">
                        <article class="utility-panel dashboard-hero">
                            <p class="utility-panel__eyebrow">Match Templates</p>
                            <h1 class="page-title mb-3"><?= safe($matchLabel) ?></h1>
                            <p class="utility-panel__copy dashboard-hero__copy">
                                This is the fixture workbench. Edit the match-specific copy of the master templates here, then move straight into the Starting XI, events, preview, and posting steps.
                            </p>
                            <div class="dashboard-actions hub-actions">
                                <a class="btn btn-brand" href="match.php?id=<?= safe((string) $match['id']) ?>">Back to Match</a>
                                <a class="btn btn-neutral" href="match_starting_11.php?id=<?= safe((string) $match['id']) ?>">Starting XI</a>
                                <a class="btn btn-neutral" href="match_events.php?id=<?= safe((string) $match['id']) ?>">Match Events</a>
                                <a class="btn btn-neutral" href="match_graphic.php?id=<?= safe((string) $match['id']) ?>">Preview Graphic</a>
                                <a class="btn btn-neutral" href="templates.php">Manage Master Templates</a>
                            </div>
                        </article>
                    </section>

                    <section class="utility-panel hub-section mb-4">
                        <div class="players-section-heading">
                            <div>
                                <p class="page-kicker mb-0">Fixture Snapshot</p>
                                <h2 class="feature-card__title mb-0">What this match is using</h2>
                            </div>
                        </div>
                        <p class="utility-panel__copy">
                            The template set below belongs to this fixture only. The master artwork stays in Templates and feeds new matches when they are created.
                        </p>
                        <div class="db-stats">
                            <div class="db-stat">
                                <span class="db-stat__value"><?= safe($matchDate) ?></span>
                                <span class="db-stat__label">Match date</span>
                            </div>
                            <div class="db-stat">
                                <span class="db-stat__value"><?= safe($matchVenue) ?></span>
                                <span class="db-stat__label">Venue</span>
                            </div>
                            <div class="db-stat">
                                <span class="db-stat__value"><?= safe($matchCompetition !== '' ? $matchCompetition : 'Not set') ?></span>
                                <span class="db-stat__label">Competition</span>
                            </div>
                            <div class="db-stat">
                                <span class="db-stat__value"><?= safe($templateConfig['label']) ?></span>
                                <span class="db-stat__label">Base template</span>
                            </div>
                        </div>
                    </section>

                    <section class="utility-panel hub-form-card mb-4">
                        <div class="players-section-heading">
                            <div>
                                <p class="page-kicker mb-0">Starting XI Asset</p>
                                <h2 class="feature-card__title mb-0">Lineup background</h2>
                            </div>
                        </div>
                        <p class="utility-panel__copy">
                            Keep the Canva artwork for the Starting XI on the same page as the rest of the fixture graphics. This background is used by the Starting XI editor, preview, download, and posting flow.
                        </p>

                        <div class="match-template-grid">
                            <article class="match-template-card">
                                <div class="match-template-card__preview">
                                    <?php if (($match['background_image'] ?? '') !== ''): ?>
                                        <img src="<?= safe((string) ($match['background_image'] ?? '')) ?>" alt="Starting XI background preview" loading="lazy">
                                    <?php else: ?>
                                        <div class="match-template-card__empty">
                                            <span>No Starting XI background uploaded yet</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="match-template-card__body">
                                    <div class="match-template-card__header">
                                        <div>
                                            <p class="page-kicker mb-1">Background</p>
                                            <h3 class="player-section-title">Starting XI graphic</h3>
                                        </div>
                                    </div>

                                    <form method="post" enctype="multipart/form-data" class="matches-form">
                                        <input type="hidden" name="action" value="save_match_background">
                                        <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">

                                        <label class="players-field">
                                            <span class="players-field__label">Background image</span>
                                            <input class="form-control" type="file" name="background_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                            <span class="players-field__hint">Upload the Canva background used for the Starting XI card.</span>
                                        </label>

                                        <div class="dashboard-actions hub-actions">
                                            <button class="btn btn-brand" type="submit">Save Starting XI Background</button>
                                            <a class="btn btn-neutral" href="match_starting_11.php?id=<?= safe((string) $match['id']) ?>">Open Starting XI</a>
                                        </div>
                                    </form>

                                    <p class="players-field__hint mb-0">
                                        Titles, lineup order, and captain still live in Starting XI. This page now keeps all match artwork together.
                                    </p>

                                    <?php if (($match['background_image'] ?? '') !== ''): ?>
                                        <form method="post" class="player-card__delete-form" onsubmit="return confirm('Remove the Starting XI background for this fixture?');">
                                            <input type="hidden" name="action" value="delete_match_background">
                                            <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                            <button class="btn btn-outline-danger" type="submit">Remove background</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    </section>

                    <?php if ($statusMessage !== ''): ?>
                        <section class="status-banner hub-status-banner status-banner--<?= safe($statusType !== '' ? $statusType : 'info') ?>">
                            <p class="status-banner__summary"><?= safe($statusMessage) ?></p>
                            <?php if ($statusDetails !== []): ?>
                                <ul class="status-banner__details">
                                    <?php foreach ($statusDetails as $detail): ?>
                                        <li><?= safe($detail) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <section class="utility-panel hub-form-card">
                        <div class="players-section-heading">
                            <div>
                                <p class="page-kicker mb-0">Templates</p>
                                <h2 class="feature-card__title mb-0">Match graphics and channel captions</h2>
                            </div>
                        </div>
                        <p class="utility-panel__copy">
                            Each card is a match-specific copy of the master set. Edit the artwork or captions here without changing the shared Canva source.
                        </p>

                        <div class="match-template-grid">
                            <?php $graphicTemplates = matches_normalize_graphic_templates($match['graphics_templates'] ?? []); ?>
                            <?php foreach ($graphicTemplateDefinitions as $templateKey => $templateDefinition): ?>
                                <?php $templateItem = $graphicTemplates[$templateKey] ?? matches_graphic_template_empty(); ?>
                                <article class="match-template-card">
                                    <div class="match-template-card__preview">
                                        <?php if (($templateItem['image'] ?? '') !== ''): ?>
                                            <img src="<?= safe((string) ($templateItem['image'] ?? '')) ?>" alt="<?= safe($templateDefinition['label']) ?> template preview" loading="lazy">
                                        <?php else: ?>
                                            <div class="match-template-card__empty">
                                                <span>No graphic uploaded yet</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="match-template-card__body">
                                        <div class="match-template-card__header">
                                            <div>
                                                <p class="page-kicker mb-1">Graphic</p>
                                                <h3 class="player-section-title"><?= safe($templateDefinition['label']) ?></h3>
                                            </div>
                                        </div>

                                        <form method="post" enctype="multipart/form-data" class="matches-form">
                                            <input type="hidden" name="action" value="save_graphic_template">
                                            <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                            <input type="hidden" name="template_type" value="<?= safe($templateKey) ?>">

                                            <label class="players-field">
                                                <span class="players-field__label">Background image</span>
                                                <input class="form-control" type="file" name="graphic_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                                <span class="players-field__hint">Upload the match-specific artwork for the <?= safe($templateDefinition['label']) ?> graphic.</span>
                                            </label>

                                            <?php foreach ($graphicTemplateChannels as $channelKey => $channelLabel): ?>
                                                <label class="players-field">
                                                    <span class="players-field__label"><?= safe($channelLabel) ?> caption</span>
                                                    <textarea class="form-control" name="captions[<?= safe($channelKey) ?>]" rows="3"><?= safe((string) ($templateItem['captions'][$channelKey] ?? '')) ?></textarea>
                                                </label>
                                            <?php endforeach; ?>

                                            <div class="dashboard-actions hub-actions">
                                                <button class="btn btn-brand" type="submit">Save <?= safe($templateDefinition['label']) ?></button>
                                            </div>
                                        </form>

                                        <p class="players-field__hint mb-0">
                                            Use this when the fixture needs its own version of the <?= safe($templateDefinition['label']) ?> artwork or caption text.
                                        </p>

                                        <?php if (($templateItem['image'] ?? '') !== '' || array_filter($templateItem['captions'] ?? [])): ?>
                                            <form method="post" class="player-card__delete-form" onsubmit="return confirm('Delete the <?= safe($templateDefinition['label']) ?> match template and its saved captions?');">
                                                <input type="hidden" name="action" value="delete_graphic_template">
                                                <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                                <input type="hidden" name="template_type" value="<?= safe($templateKey) ?>">
                                                <button class="btn btn-outline-danger" type="submit">Delete template</button>
                                            </form>
                                        <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="utility-panel mt-4">
                    <div class="players-section-heading">
                        <div>
                            <p class="page-kicker mb-0">Next Steps</p>
                            <h2 class="feature-card__title mb-0">Keep the match flow moving</h2>
                        </div>
                    </div>
                    <p class="utility-panel__copy">
                        Once the template copy is right, move in order: build the Starting XI, record events, preview the graphic, then post to social.
                    </p>
                    <div class="dashboard-actions hub-actions">
                        <a class="btn btn-brand" href="match_starting_11.php?id=<?= safe((string) $match['id']) ?>">Build Starting XI</a>
                        <a class="btn btn-neutral" href="match_events.php?id=<?= safe((string) $match['id']) ?>">Record Events</a>
                        <a class="btn btn-neutral" href="match_graphic.php?id=<?= safe((string) $match['id']) ?>">Preview and Post</a>
                        <a class="btn btn-neutral" href="match.php?id=<?= safe((string) $match['id']) ?>">Return to Match Workspace</a>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <?php app_render_auth_scripts($isAuthenticated); ?>
</body>

</html>
