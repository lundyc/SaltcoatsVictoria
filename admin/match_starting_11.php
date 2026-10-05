<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// Fixture management links use fixture_id/season_id and belong to the hub
// Starting XI builder. Keep the separate socials builder available via ?id=.
if ((int)($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0) > 0) {
    require __DIR__ . '/fixture_starting_11.php';
    exit;
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/audit.php';

function match_starting_11_is_ajax_request(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

$app = app_bootstrap_state();
$isAuthenticated = $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];
$statusType = '';
$statusMessage = '';
$statusDetails = [];

$matchId = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$matches = matches_load_all();
$match = $matchId !== '' ? matches_find_by_id($matches, $matchId) : null;

if (
    $match === null
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && match_starting_11_is_ajax_request()
) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Match could not be found.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAuthenticated && $match !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    if ($action === 'save') {
        $result = matches_handle_update_lineup($_POST, $_FILES);
        if ($result['ok'] && empty($_POST['autosave'])) {
            auditLog($pdo, 'match_lineup_saved', 'Saved starting XI for ' . matches_fixture_label($match));
        }
        if (match_starting_11_is_ajax_request()) {
            header('Content-Type: application/json; charset=UTF-8');
            if ($result['ok']) {
                echo json_encode([
                    'ok' => true,
                    'message' => $result['message'],
                    'background_image' => isset($result['background_image']) ? (string) $result['background_image'] : '',
                    'lineup_background' => isset($result['lineup_background']) ? (string) $result['lineup_background'] : '',
                ], JSON_UNESCAPED_SLASHES);
            } else {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'message' => $result['message'],
                    'errors' => $result['errors'] ?? [],
                ], JSON_UNESCAPED_SLASHES);
            }
            exit;
        }

        if ($result['ok']) {
            $redirectMatchId = isset($result['match_id']) && is_string($result['match_id']) && $result['match_id'] !== ''
                ? $result['match_id']
                : $matchId;
            header('Location: match_starting_11.php?id=' . rawurlencode($redirectMatchId) . '&status=' . rawurlencode('saved'));
            exit;
        }

        $statusType = 'error';
        $statusMessage = $result['message'];
        $statusDetails = $result['errors'] ?? [];
        $template = matches_template_config(isset($_POST['template_key']) && is_string($_POST['template_key']) ? $_POST['template_key'] : null);
        $match = array_merge(matches_empty_form_state(), [
            'id' => isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : $matchId,
            'opponent' => (string) ($match['opponent'] ?? ''),
            'competition' => (string) ($match['competition'] ?? ''),
            'season' => (string) ($match['season'] ?? ''),
            'venue' => matches_normalize_venue((string) ($match['venue'] ?? 'H')),
            'match_date' => (string) ($match['match_date'] ?? ''),
            'kickoff_time' => (string) ($match['kickoff_time'] ?? ''),
            'template_key' => $template['key'],
            'title_primary' => isset($_POST['title_primary']) && is_string($_POST['title_primary']) ? trim($_POST['title_primary']) : $template['title_primary'],
            'title_accent' => isset($_POST['title_accent']) && is_string($_POST['title_accent']) ? trim($_POST['title_accent']) : $template['title_accent'],
            'opponent_prefix' => isset($_POST['opponent_prefix']) && is_string($_POST['opponent_prefix']) ? trim($_POST['opponent_prefix']) : $template['opponent_prefix'],
            'background_image' => isset($_POST['existing_background']) && is_string($_POST['existing_background']) ? trim($_POST['existing_background']) : '',
            'starters' => array_pad(matches_prepare_lineup($_POST['starters'] ?? []), 11, ''),
            'substitutes' => array_pad(matches_prepare_lineup($_POST['substitutes'] ?? []), 9, ''),
            'captain' => isset($_POST['captain']) && is_string($_POST['captain']) ? trim($_POST['captain']) : '',
        ]);
    }
}

if ($statusMessage === '' && isset($_GET['status']) && is_string($_GET['status']) && $_GET['status'] === 'saved') {
    $statusType = 'success';
    $statusMessage = 'Starting XI saved successfully.';
}

$templateOptions = matches_template_options();
$squadNames = matches_current_squad_names();
$templateConfig = matches_template_config(isset($match['template_key']) && is_string($match['template_key']) ? $match['template_key'] : null);
$starterCount = $match !== null ? count(array_filter(matches_prepare_lineup($match['starters'] ?? []), static function (string $name): bool {
    return trim($name) !== '';
})) : 0;
$substituteCount = $match !== null ? count(array_filter(matches_prepare_lineup($match['substitutes'] ?? []), static function (string $name): bool {
    return trim($name) !== '';
})) : 0;
$hasBackground = $match !== null && trim((string) ($match['background_image'] ?? '')) !== '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $match !== null ? safe(matches_fixture_label($match)) . ' Starting XI – Club Hub' : 'Starting XI – Club Hub' ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/social-publishing.css?v=<?= $styleVersion ?>">
    <link rel="stylesheet" href="/admin/assets/css/match_starting_11.css">
</head>

<body class="bg-cream legacy-shell">
    <?php if (!$isAuthenticated): ?>
        <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria match tools.'); ?>
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
                    <section class="utility-grid dashboard-grid mb-4">
                        <article class="utility-panel dashboard-hero">
                            <p class="utility-panel__eyebrow">Starting XI</p>
                            <h1 class="page-title mb-3"><?= safe(matches_fixture_label($match)) ?></h1>
                            <p class="utility-panel__copy dashboard-hero__copy">
                                Build the lineup and graphic setup here. The background you upload on this screen is the same one used by the preview and post screens.
                            </p>
                            <div class="dashboard-actions hub-actions">
                                <a class="btn btn-brand js-preview-link" href="match_graphic.php?id=<?= safe((string) $match['id']) ?>">Preview graphic</a>
                                <a class="btn btn-neutral" href="match_events.php?id=<?= safe((string) $match['id']) ?>">Add events</a>
                                <a class="btn btn-neutral" href="match.php?id=<?= safe((string) $match['id']) ?>">Match workspace</a>
                                <a class="btn btn-neutral" href="match_templates.php?id=<?= safe((string) $match['id']) ?>">Match templates</a>
                                <button id="clearTeamTopBtn" class="btn btn-outline-danger" type="button">Clear team</button>
                            </div>
                        </article>

                        <article class="utility-panel">
                            <p class="utility-panel__eyebrow">Graphic Handoff</p>
                            <h2 class="utility-panel__title">Ready for preview</h2>
                            <ul class="status-list">
                                <li>Template: <?= safe($templateConfig['label']) ?></li>
                                <li><?= $hasBackground ? 'Background: uploaded and ready' : 'Background: not uploaded yet' ?></li>
                                <li>Starting XI: <?= $starterCount ?>/11 filled</li>
                                <li>Substitutes: <?= $substituteCount ?> selected</li>
                            </ul>
                            <p class="utility-panel__copy mt-3">
                                <?= $hasBackground ? 'Once you save this screen, the preview graphic will use this lineup and background straight away. Match Templates remains the place for the broader match graphic pack and captions.' : 'Upload the lineup background here first. Match Templates remains the place for the broader match graphic pack and captions.' ?>
                            </p>
                        </article>
                    </section>

                    <div id="lineupAutosaveStatus" class="alert alert-success d-none mb-3" role="status" aria-live="polite"></div>
                    <div id="lineupSaveMicro" class="d-none mb-3" role="status" aria-live="polite" style="display:flex;align-items:center;gap:.55rem;font-weight:600;">
                        <span id="lineupSaveMicroDot" style="width:.7rem;height:.7rem;border-radius:999px;background:#6c757d;display:inline-block;"></span>
                        <span id="lineupSaveMicroText">Saving…</span>
                    </div>

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

                    <section class="matches-layout">
                        <article class="utility-panel matches-editor hub-form-card">
                            <div class="players-section-heading">
                                <div>
                                    <p class="page-kicker">Starting XI</p>
                                    <h2 class="feature-card__title">Starting XI and graphic setup</h2>
                                    <p class="match-dialog__copy">Choose the template, adjust the titles, and upload the background here so the match graphic screen is already ready to preview.</p>
                                </div>
                            </div>

                            <form method="post" enctype="multipart/form-data" class="matches-form" id="matchesForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="current_tool" value="starting_11">
                                <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                <input type="hidden" name="existing_background" value="<?= safe((string) ($match['background_image'] ?? '')) ?>">
                                <section class="players-form-section">
                                    <div class="players-form-section__header">
                                        <p class="page-kicker mb-1">Squad</p>
                                        <h3 class="player-section-title">Team sheet</h3>
                                    </div>

                                    <div class="matches-lineup-grid">
                                        <div class="matches-lineup-column">
                                            <div class="matches-lineup-column__header">
                                                <h4 class="matches-lineup-title">Starting XI</h4>
                                                <span class="players-field__hint">Select 11 starters in display order and mark the captain on the same row.</span>
                                            </div>

                                            <div class="matches-lineup-table-wrap">
                                                <table class="matches-starting-table">
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">No.</th>
                                                            <th scope="col">Player</th>
                                                            <th scope="col">Captain</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php for ($i = 0; $i < 11; $i++): ?>
                                                            <tr>
                                                                <td class="matches-starting-table__number-cell" data-label="No.">
                                                                    <span class="matches-shirt-number"><?= $i + 1 ?></span>
                                                                </td>
                                                                <td class="matches-starting-table__player-cell" data-label="Player">
                                                                    <label class="players-field matches-starting-table__field">
                                                                        <select class="form-select js-starter-select" name="starters[]">
                                                                            <option value="">Select player</option>
                                                                            <?php foreach ($squadNames as $name): ?>
                                                                                <option value="<?= safe($name) ?>" <?= ((($match['starters'][$i] ?? '') === $name) ? 'selected' : '') ?>><?= safe($name) ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </label>
                                                                </td>
                                                                <td class="matches-starting-table__captain-cell" data-label="Captain">
                                                                    <fieldset class="players-choice-group matches-starting-table__captain">
                                                                        <label class="matches-captain-star">
                                                                            <input
                                                                                class="js-captain-radio"
                                                                                type="radio"
                                                                                name="captain_choice"
                                                                                value="<?= $i ?>"
                                                                                <?= (($match['captain'] ?? '') !== '' && (($match['starters'][$i] ?? '') === ($match['captain'] ?? ''))) ? 'checked' : '' ?>
                                                                            >
                                                                            <span aria-hidden="true">&#9733;</span>
                                                                        </label>
                                                                    </fieldset>
                                                                </td>
                                                            </tr>
                                                        <?php endfor; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <input type="hidden" name="captain" id="captainInput" value="<?= safe((string) ($match['captain'] ?? '')) ?>">
                                        </div>

                                        <div class="matches-lineup-column">
                                            <div class="matches-lineup-column__header">
                                                <h4 class="matches-lineup-title">Substitutes</h4>
                                                <span class="players-field__hint">Only players not already in the Starting XI appear here. Tap a name to toggle them on.</span>
                                            </div>

                                            <div class="matches-subs-wrap">
                                                <div id="subsPillList" class="matches-subs-pills">
                                                    <?php foreach ($squadNames as $name): ?>
                                                        <?php $isSelectedSub = in_array($name, $match['substitutes'] ?? [], true); ?>
                                                        <label class="players-pill players-pill--toggle matches-sub-pill<?= $isSelectedSub ? ' is-selected' : '' ?>">
                                                            <input class="js-sub-toggle" type="checkbox" name="substitutes[]" value="<?= safe($name) ?>" <?= $isSelectedSub ? 'checked' : '' ?>>
                                                            <span><?= safe($name) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <p id="subsEmptyState" class="players-field__hint matches-subs-empty d-none">All available players are currently in the Starting XI.</p>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <section class="players-form-section">
                            <div class="players-form-section__header">
                                <p class="page-kicker mb-1">Graphic</p>
                                <h3 class="player-section-title">Template and lineup text</h3>
                                <p class="match-dialog__copy">Upload the starting XI background image here, then set the template and lineup text.</p>
                            </div>

                            <div class="matches-graphic-layout">
                                <div class="matches-graphic-layout__form">
                                    <div class="matches-grid matches-grid--template">
                                        <label class="players-field">
                                            <span class="players-field__label">Starting XI Background</span>
                                            <input class="form-control" type="file" id="backgroundImageInput" name="background_image" accept="image/png,image/jpeg,image/webp">
                                            <span class="players-field__hint">Accepted formats: PNG, JPG, WEBP.</span>
                                        </label>

                                        <label class="players-field">
                                            <span class="players-field__label">Template</span>
                                            <select class="form-select" name="template_key">
                                                <?php foreach ($templateOptions as $key => $template): ?>
                                                    <option value="<?= safe($key) ?>" <?= ((($match['template_key'] ?? '') === $key) ? 'selected' : '') ?>><?= safe($template['label']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>

                                                <label class="players-field">
                                                    <span class="players-field__label">Primary Title</span>
                                                    <input class="form-control" name="title_primary" value="<?= safe((string) ($match['title_primary'] ?? '')) ?>">
                                                </label>

                                                <label class="players-field">
                                                    <span class="players-field__label">Accent Title</span>
                                                    <input class="form-control" name="title_accent" value="<?= safe((string) ($match['title_accent'] ?? '')) ?>">
                                                </label>

                                                <label class="players-field">
                                                    <span class="players-field__label">Opponent Prefix</span>
                                                    <input class="form-control" name="opponent_prefix" value="<?= safe((string) ($match['opponent_prefix'] ?? '')) ?>">
                                                </label>
                                            </div>
                                        </div>

                                        <aside class="matches-graphic-layout__preview">
                                            <div class="matches-background-preview matches-background-preview--large">
                                                <?php $lineupBackground = matches_match_lineup_background($match); ?>
                                                <?php if ($lineupBackground !== ''): ?>
                                                    <img id="lineupBackgroundPreviewImage" src="<?= safe($lineupBackground) ?>" alt="Kick-off artwork preview" loading="lazy">
                                                <?php else: ?>
                                                    <div id="lineupBackgroundPreviewEmpty" class="matches-background-preview__empty">
                                                        <span>No kick-off artwork uploaded yet</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <p class="players-field__hint mb-0">
                                                Uploading a new image here replaces the current starting XI background for this fixture.
                                            </p>
                                        </aside>
                                    </div>
                                </section>

                                <div class="dashboard-actions hub-actions">
                                    <a class="btn btn-brand js-preview-link" href="match_graphic.php?id=<?= safe((string) $match['id']) ?>">Preview graphic</a>
                                    <a class="btn btn-neutral" href="match.php?id=<?= safe((string) $match['id']) ?>">Match workspace</a>
                                </div>
                            </form>
                        </article>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <?php app_render_auth_scripts($isAuthenticated); ?>
    <?php if ($isAuthenticated && $match !== null): ?>
        <script>
            (function() {
                var form = document.getElementById('matchesForm');
                var starterSelects = Array.prototype.slice.call(document.querySelectorAll('.js-starter-select'));
                var captainRadios = Array.prototype.slice.call(document.querySelectorAll('.js-captain-radio'));
                var captainInput = document.getElementById('captainInput');
                var existingBackgroundInput = form.querySelector('input[name="existing_background"]');
                var backgroundImageInput = document.getElementById('backgroundImageInput');
                var previewLinks = Array.prototype.slice.call(document.querySelectorAll('.js-preview-link'));
                var previewWrap = document.querySelector('.matches-background-preview--large');
                var subToggles = Array.prototype.slice.call(document.querySelectorAll('.js-sub-toggle'));
                var subsPillList = document.getElementById('subsPillList');
                var subsEmptyState = document.getElementById('subsEmptyState');
                var clearTeamTopBtn = document.getElementById('clearTeamTopBtn');
                var autosaveStatus = document.getElementById('lineupAutosaveStatus');
                var saveMicro = document.getElementById('lineupSaveMicro');
                var saveMicroDot = document.getElementById('lineupSaveMicroDot');
                var saveMicroText = document.getElementById('lineupSaveMicroText');
                var saveTimer = 0;
                var saveInFlight = false;
                var pendingSave = false;
                var pendingPreviewHref = '';
                var lastSaveFailed = false;
                var isDirty = false;
                if (!form || !starterSelects.length || !captainInput) {
                    return;
                }

                function setAutosaveStatus(type, message) {
                    if (!autosaveStatus) {
                        return;
                    }

                    autosaveStatus.className = 'alert mb-3';
                    autosaveStatus.classList.add(type === 'error' ? 'alert-danger' : 'alert-success');
                    autosaveStatus.classList.remove('d-none');
                    autosaveStatus.textContent = message;
                }

                function setSaveMicro(state, message) {
                    if (!saveMicro || !saveMicroDot || !saveMicroText) {
                        return;
                    }

                    saveMicro.classList.remove('d-none');
                    saveMicroText.textContent = message;
                    saveMicroDot.style.animation = '';

                    if (state === 'busy') {
                        saveMicroDot.style.background = '#b21f2d';
                        saveMicroDot.style.animation = 'lineupPulse 1s ease-in-out infinite';
                    } else if (state === 'success') {
                        saveMicroDot.style.background = '#198754';
                    } else if (state === 'error') {
                        saveMicroDot.style.background = '#dc3545';
                    } else {
                        saveMicroDot.style.background = '#6c757d';
                    }
                }

                function hideSaveMicroLater() {
                    if (!saveMicro) {
                        return;
                    }
                    window.setTimeout(function() {
                        saveMicro.classList.add('d-none');
                    }, 1400);
                }

                function runAutosave() {
                    var formData = new FormData(form);
                    var hasBackgroundUpload = !!(backgroundImageInput && backgroundImageInput.files && backgroundImageInput.files.length > 0);

                    if (saveInFlight) {
                        pendingSave = true;
                        return;
                    }

                    saveInFlight = true;
                    pendingSave = false;
                    setAutosaveStatus('success', hasBackgroundUpload ? 'Uploading background and saving lineup...' : 'Saving lineup...');
                    setSaveMicro('busy', hasBackgroundUpload ? 'Uploading image…' : 'Saving changes…');
                    formData.append('autosave', '1');

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    }).then(function(response) {
                        return response.text().then(function(text) {
                            var json = null;

                            try {
                                json = JSON.parse(text);
                            } catch (error) {
                                json = null;
                            }

                            return {
                                ok: response.ok,
                                status: response.status,
                                text: text,
                                json: json
                            };
                        });
                    }).then(function(result) {
                        if (!result.ok || !result.json || !result.json.ok) {
                            var fallbackMessage = 'Auto-save failed.';
                            if (result.json && result.json.message) {
                                fallbackMessage = result.json.message;
                            } else if (result.text) {
                                fallbackMessage = 'Auto-save failed: HTTP ' + result.status + ' ' + result.text.slice(0, 160).replace(/\s+/g, ' ').trim();
                            }
                            throw new Error(fallbackMessage);
                        }

                        lastSaveFailed = false;
                        isDirty = false;
                        if (result.json.background_image && existingBackgroundInput) {
                            existingBackgroundInput.value = result.json.background_image;
                        }

                        if (result.json.lineup_background && previewWrap) {
                            var currentImage = document.getElementById('lineupBackgroundPreviewImage');
                            var emptyState = document.getElementById('lineupBackgroundPreviewEmpty');
                            var refreshedUrl = result.json.lineup_background + '?v=' + Date.now();

                            if (!currentImage) {
                                currentImage = document.createElement('img');
                                currentImage.id = 'lineupBackgroundPreviewImage';
                                currentImage.alt = 'Kick-off artwork preview';
                                currentImage.loading = 'lazy';
                                if (emptyState) {
                                    emptyState.remove();
                                }
                                previewWrap.appendChild(currentImage);
                            }

                            currentImage.src = refreshedUrl;
                        }

                        if (result.json.background_image && backgroundImageInput) {
                            backgroundImageInput.value = '';
                        }

                        setAutosaveStatus('success', hasBackgroundUpload ? 'Background uploaded and saved.' : 'Lineup saved.');
                        setSaveMicro('success', hasBackgroundUpload ? 'Upload complete and saved.' : 'All changes saved.');
                        hideSaveMicroLater();
                    }).catch(function(error) {
                        lastSaveFailed = true;
                        console.error('Starting XI autosave failed', error);
                        setAutosaveStatus('error', error.message || 'Auto-save failed.');
                        setSaveMicro('error', 'Save failed. Please try again.');
                    }).finally(function() {
                        saveInFlight = false;
                        if (pendingSave) {
                            runAutosave();
                            return;
                        }

                        if (pendingPreviewHref !== '') {
                            if (!lastSaveFailed && !isDirty) {
                                var href = pendingPreviewHref;
                                pendingPreviewHref = '';
                                window.location.href = href;
                            } else {
                                pendingPreviewHref = '';
                            }
                        }
                    });
                }

                function queueAutosave() {
                    window.clearTimeout(saveTimer);
                    isDirty = true;
                    saveTimer = window.setTimeout(runAutosave, 180);
                }

                function flushSaveThenNavigate(href) {
                    pendingPreviewHref = href;

                    if (saveInFlight) {
                        setSaveMicro('busy', 'Finishing save before preview…');
                        return;
                    }

                    window.clearTimeout(saveTimer);
                    if (isDirty) {
                        setSaveMicro('busy', 'Saving latest changes before preview…');
                        runAutosave();
                        return;
                    }

                    window.location.href = href;
                }

                function syncStarterOptions() {
                    var selectedByRow = starterSelects.map(function(select) {
                        return select.value.trim();
                    });
                    var selectedSubs = subToggles.filter(function(toggle) {
                        return toggle.checked;
                    }).map(function(toggle) {
                        return toggle.value.trim();
                    });

                    starterSelects.forEach(function(select, rowIndex) {
                        var currentValue = selectedByRow[rowIndex];
                        Array.prototype.slice.call(select.options).forEach(function(option) {
                            var optionValue = option.value.trim();
                            if (optionValue === '') {
                                option.disabled = false;
                                option.hidden = false;
                                return;
                            }

                            var usedElsewhere = selectedByRow.some(function(selectedValue, selectedIndex) {
                                return selectedIndex !== rowIndex && selectedValue !== '' && selectedValue === optionValue;
                            });
                            var usedAsSub = selectedSubs.indexOf(optionValue) !== -1;

                            option.disabled = usedElsewhere || usedAsSub;
                            option.hidden = (usedElsewhere || usedAsSub) && optionValue !== currentValue;
                        });
                    });
                }

                function getUniqueStarters() {
                    return starterSelects.map(function(select) {
                        return select.value.trim();
                    }).filter(function(value, index, array) {
                        return value !== '' && array.indexOf(value) === index;
                    });
                }

                function syncCaptainState() {
                    var starters = getUniqueStarters();
                    var currentCaptain = captainInput.value.trim();

                    captainRadios.forEach(function(radio, index) {
                        var selectedPlayer = starterSelects[index].value.trim();
                        var wrapper = radio.closest('.matches-captain-star');
                        var canUseCaptain = selectedPlayer !== '';

                        radio.disabled = !canUseCaptain;
                        if (!canUseCaptain) {
                            radio.checked = false;
                        } else if (selectedPlayer === currentCaptain) {
                            radio.checked = true;
                        }

                        if (wrapper) {
                            wrapper.classList.toggle('is-disabled', !canUseCaptain);
                        }
                    });

                    if (currentCaptain !== '' && starters.indexOf(currentCaptain) === -1) {
                        captainInput.value = '';
                        captainRadios.forEach(function(radio) {
                            radio.checked = false;
                        });
                    }
                }

                function syncSubstitutes() {
                    var starters = getUniqueStarters();
                    var visibleCount = 0;

                    subToggles.forEach(function(toggle) {
                        var playerName = toggle.value.trim();
                        var pill = toggle.closest('.matches-sub-pill');
                        var isStarter = starters.indexOf(playerName) !== -1;

                        if (isStarter) {
                            toggle.checked = false;
                        }

                        toggle.disabled = isStarter;
                        if (pill) {
                            pill.classList.toggle('d-none', isStarter);
                            pill.classList.toggle('is-selected', toggle.checked && !isStarter);
                        }

                        if (!isStarter) {
                            visibleCount += 1;
                        }
                    });

                    if (subsPillList) {
                        subsPillList.classList.toggle('is-empty', visibleCount === 0);
                    }
                    if (subsEmptyState) {
                        subsEmptyState.classList.toggle('d-none', visibleCount !== 0);
                    }
                }

                starterSelects.forEach(function(select) {
                    select.addEventListener('change', function() {
                        syncStarterOptions();
                        syncCaptainState();
                        syncSubstitutes();
                        queueAutosave();
                    });
                });

                captainRadios.forEach(function(radio, index) {
                    radio.addEventListener('change', function() {
                        if (!radio.checked) {
                            return;
                        }

                        captainInput.value = starterSelects[index].value.trim();
                        syncCaptainState();
                        queueAutosave();
                    });
                });

                subToggles.forEach(function(toggle) {
                    toggle.addEventListener('change', function() {
                        var pill = toggle.closest('.matches-sub-pill');
                        if (pill) {
                            pill.classList.toggle('is-selected', toggle.checked);
                        }
                        syncStarterOptions();
                        syncCaptainState();
                        queueAutosave();
                    });
                });

                if (backgroundImageInput) {
                    backgroundImageInput.addEventListener('change', function() {
                        if (backgroundImageInput.files && backgroundImageInput.files.length > 0) {
                            setSaveMicro('busy', 'Image selected. Uploading…');
                            queueAutosave();
                        }
                    });
                }

                previewLinks.forEach(function(link) {
                    link.addEventListener('click', function(event) {
                        event.preventDefault();
                        flushSaveThenNavigate(link.getAttribute('href') || '');
                    });
                });

                if (clearTeamTopBtn) {
                    clearTeamTopBtn.addEventListener('click', function() {
                        if (!window.confirm('Clear the full starting XI, captain, and substitutes?')) {
                            return;
                        }

                        starterSelects.forEach(function(select) {
                            select.value = '';
                        });

                        subToggles.forEach(function(toggle) {
                            toggle.checked = false;
                            var pill = toggle.closest('.matches-sub-pill');
                            if (pill) {
                                pill.classList.remove('is-selected');
                            }
                        });

                        captainInput.value = '';
                        captainRadios.forEach(function(radio) {
                            radio.checked = false;
                        });

                        syncStarterOptions();
                        syncCaptainState();
                        syncSubstitutes();
                        queueAutosave();
                    });
                }

                syncStarterOptions();
                syncCaptainState();
                syncSubstitutes();
            })();
        </script>
    <?php endif; ?>
</body>

</html>
