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
require_once __DIR__ . '/lib/facebook_publisher.php';
require_once __DIR__ . '/lib/audit.php';

/**
 * @return array<string, string>
 */
function match_events_empty_form_state(): array
{
    return [
        'event_id' => '',
        'event_type' => 'goal',
        'event_team' => 'svfc',
        'event_minute' => '',
        'event_player' => '',
        'event_secondary_player' => '',
        'event_card_type' => '',
    ];
}

/**
 * @return array<string, string>
 */
function match_events_form_state_from_post(array $post): array
{
    return [
        'event_id' => isset($post['event_id']) && is_string($post['event_id']) ? trim($post['event_id']) : '',
        'event_type' => isset($post['event_type']) && is_string($post['event_type']) ? trim($post['event_type']) : 'goal',
        'event_team' => isset($post['event_team']) && is_string($post['event_team']) ? trim($post['event_team']) : 'svfc',
        'event_minute' => isset($post['event_minute']) && is_string($post['event_minute']) ? trim($post['event_minute']) : '',
        'event_player' => isset($post['event_player']) && is_string($post['event_player']) ? trim($post['event_player']) : '',
        'event_secondary_player' => isset($post['event_secondary_player']) && is_string($post['event_secondary_player']) ? trim($post['event_secondary_player']) : '',
        'event_card_type' => isset($post['event_card_type']) && is_string($post['event_card_type']) ? trim($post['event_card_type']) : '',
    ];
}

/**
 * @return array<string, string>
 */
function match_events_form_state_from_event(array $event): array
{
    return [
        'event_id' => (string) ($event['id'] ?? ''),
        'event_type' => (string) ($event['type'] ?? 'goal'),
        'event_team' => (string) ($event['team'] ?? 'svfc'),
        'event_minute' => (string) ($event['minute'] ?? ''),
        'event_player' => (string) ($event['player'] ?? ''),
        'event_secondary_player' => (string) ($event['secondary_player'] ?? ''),
        'event_card_type' => (string) ($event['card_type'] ?? ''),
    ];
}

function match_events_find_event(array $events, string $eventId): ?array
{
    foreach ($events as $event) {
        if ((string) ($event['id'] ?? '') === $eventId) {
            return $event;
        }
    }

    return null;
}

function match_events_entry_title(array $event): string
{
    $labels = matches_event_type_labels();
    $type = (string) ($event['type'] ?? '');
    $team = (string) ($event['team'] ?? '');
    $player = trim((string) ($event['player'] ?? ''));
    $secondary = trim((string) ($event['secondary_player'] ?? ''));
    $note = trim((string) ($event['note'] ?? ''));
    $cardType = trim((string) ($event['card_type'] ?? ''));

    if ($type === 'goal') {
        if ($team === 'svfc' && $player !== '') {
            return 'Goal: ' . $player;
        }
        if ($note !== '') {
            return 'Goal: ' . $note;
        }
    }

    if ($type === 'card') {
        $cardLabel = $cardType !== '' ? ucfirst($cardType) . ' card' : 'Card';
        if ($player !== '') {
            return $cardLabel . ': ' . $player;
        }
        if ($note !== '') {
            return $cardLabel . ': ' . $note;
        }
    }

    if (in_array($type, ['yellow_card', 'red_card'], true)) {
        $cardLabel = $type === 'yellow_card' ? 'Yellow card' : 'Red card';
        return $cardLabel . ($player !== '' ? ': ' . $player : '');
    }

    if ($type === 'substitution') {
        if ($team === 'svfc' && $player !== '' && $secondary !== '') {
            return 'Substitution: ' . $player . ' off, ' . $secondary . ' on';
        }
        if ($note !== '') {
            return 'Substitution: ' . $note;
        }
    }

    if ($type === 'note' && $note !== '') {
        return $note;
    }

    return $labels[$type] ?? 'Event';
}

function match_events_entry_meta(array $event): string
{
    $teamLabels = matches_event_team_labels();
    $type = (string) ($event['type'] ?? '');
    $team = (string) ($event['team'] ?? '');
    $minute = trim((string) ($event['minute'] ?? ''));
    $parts = [];

    if ($minute !== '') {
        $parts[] = $minute . "'";
    }

    if (!in_array($type, ['kickoff', 'half_time', 'second_half', 'full_time'], true) && isset($teamLabels[$team])) {
        $parts[] = $teamLabels[$team];
    }

    return implode(' · ', $parts);
}

/**
 * @return list<string>
 */
function match_events_current_on_field_players(array $match): array
{
    $onField = matches_prepare_lineup($match['starters'] ?? []);
    $events = isset($match['events']) && is_array($match['events']) ? $match['events'] : [];

    foreach ($events as $event) {
        if (
            (string) ($event['type'] ?? '') !== 'substitution'
            || (string) ($event['team'] ?? '') !== 'svfc'
        ) {
            continue;
        }

        $playerOff = trim((string) ($event['player'] ?? ''));
        $playerOn = trim((string) ($event['secondary_player'] ?? ''));

        if ($playerOff !== '') {
            $onField = array_values(array_filter($onField, static function (string $name) use ($playerOff): bool {
                return $name !== $playerOff;
            }));
        }

        if ($playerOn !== '' && !in_array($playerOn, $onField, true)) {
            $onField[] = $playerOn;
        }
    }

    return array_values(array_unique(array_filter($onField, static function (string $name): bool {
        return trim($name) !== '';
    })));
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
$formState = match_events_empty_form_state();
$editingEventId = isset($_GET['edit']) && is_string($_GET['edit']) ? trim($_GET['edit']) : '';

if ($isAuthenticated && $match !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    $result = null;

    if ($action === 'quick_state') {
        $stateType = isset($_POST['state_type']) && is_string($_POST['state_type']) ? trim($_POST['state_type']) : '';
        $_POST['event_type'] = $stateType;
        $_POST['event_team'] = 'match';
        $_POST['event_minute'] = isset($_POST['state_minute']) && is_string($_POST['state_minute']) ? trim($_POST['state_minute']) : '';
        $result = matches_handle_add_event($_POST);
        if ($result['ok']) {
            auditLog($pdo, 'match_event_added', 'Recorded ' . (matches_event_type_labels()[$stateType] ?? $stateType) . ' marker for ' . matches_fixture_label($match));
        }
        if (!$result['ok']) {
            $formState = match_events_form_state_from_post($_POST);
        }
    } elseif ($action === 'add_event') {
        $result = matches_handle_add_event($_POST);
        if ($result['ok']) {
            auditLog($pdo, 'match_event_added', 'Added event "' . match_events_entry_title($result['event']) . '" to ' . matches_fixture_label($match));
        }
        if (!$result['ok']) {
            $formState = match_events_form_state_from_post($_POST);
        }
    } elseif ($action === 'update_event') {
        $preUpdateFormState = match_events_form_state_from_post($_POST);
        $result = matches_handle_update_event($_POST);
        if ($result['ok']) {
            $updatedEventForLog = [
                'type' => $preUpdateFormState['event_type'],
                'team' => $preUpdateFormState['event_team'],
                'player' => $preUpdateFormState['event_player'],
                'secondary_player' => $preUpdateFormState['event_secondary_player'],
                'card_type' => $preUpdateFormState['event_card_type'],
            ];
            auditLog($pdo, 'match_event_updated', 'Updated event "' . match_events_entry_title($updatedEventForLog) . '" on ' . matches_fixture_label($match));
        }
        if (!$result['ok']) {
            $formState = match_events_form_state_from_post($_POST);
            $editingEventId = $formState['event_id'];
        }
    } elseif ($action === 'delete_event') {
        $deletedEvent = match_events_find_event($match['events'] ?? [], isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '');
        $result = matches_handle_delete_event($_POST);
        if ($result['ok']) {
            auditLog($pdo, 'match_event_deleted', 'Deleted event "' . ($deletedEvent !== null ? match_events_entry_title($deletedEvent) : 'unknown') . '" from ' . matches_fixture_label($match));
        }
    }

    if ($result !== null) {
        $statusType = $result['ok'] ? 'success' : 'error';
        $statusMessage = $result['message'];
        $statusDetails = $result['errors'] ?? [];
        $matches = matches_load_all();
        $match = matches_find_by_id($matches, $matchId);
        if ($result['ok']) {
            $formState = match_events_empty_form_state();
            $editingEventId = '';
        }
    }
}

if ($match !== null && $editingEventId !== '') {
    $editingEvent = match_events_find_event($match['events'] ?? [], $editingEventId);
    if ($editingEvent !== null && $statusType !== 'error') {
        $formState = match_events_form_state_from_event($editingEvent);
    }
}

$events = $match !== null && isset($match['events']) && is_array($match['events']) ? $match['events'] : [];
$typeLabels = matches_event_type_labels();
$formTypeLabels = matches_event_form_type_labels();
$teamLabels = matches_event_team_labels();
$squadNames = matches_current_squad_names();
$goalPlayerOptions = $match !== null ? match_events_current_on_field_players($match) : [];
if ($goalPlayerOptions === [] && $match !== null) {
    $goalPlayerOptions = matches_prepare_lineup($match['starters'] ?? []);
}
if ($goalPlayerOptions === []) {
    $goalPlayerOptions = $squadNames;
}
if ($formState['event_player'] !== '' && !in_array($formState['event_player'], $goalPlayerOptions, true)) {
    $goalPlayerOptions[] = $formState['event_player'];
}
$substitutionOffOptions = $goalPlayerOptions;
$substitutionOnOptions = $match !== null ? matches_prepare_lineup($match['substitutes'] ?? []) : [];
if ($substitutionOnOptions === []) {
    $substitutionOnOptions = array_values(array_filter($squadNames, static function (string $name) use ($substitutionOffOptions): bool {
        return !in_array($name, $substitutionOffOptions, true);
    }));
}
if ($substitutionOnOptions === []) {
    $substitutionOnOptions = $squadNames;
}
if ($formState['event_secondary_player'] !== '' && !in_array($formState['event_secondary_player'], $substitutionOnOptions, true)) {
    $substitutionOnOptions[] = $formState['event_secondary_player'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $match !== null ? safe(matches_fixture_label($match)) . ' Match events – Club Hub' : 'Match events – Club Hub' ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/social-publishing.css?v=<?= $styleVersion ?>">
</head>

<body class="bg-cream legacy-shell">
<?php if (!$isAuthenticated): ?>
    <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria match events tool.'); ?>
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
                    <a class="btn btn-brand" href="matches.php">Back to Matches</a>
                </section>
            <?php else: ?>
                <section class="utility-panel matches-page-bar legacy-page-header mb-4">
                    <div>
                        <p class="page-kicker mb-1">Match Events</p>
                        <h1 class="feature-card__title mb-0"><?= safe(matches_fixture_label($match)) ?></h1>
                    </div>
                    <div class="dashboard-actions hub-actions">
                        <a class="btn btn-neutral" href="match.php?id=<?= safe((string) $match['id']) ?>">Match workspace</a>
                        <a class="btn btn-neutral" href="match_starting_11.php?id=<?= safe((string) $match['id']) ?>">Starting XI</a>
                        <button id="postLatestEventToFacebookBtn" class="btn btn-brand" type="button">Post latest event to Facebook</button>
                        <button id="shareLatestEventToTwitterBtn" class="btn btn-outline-maroon" type="button">Share latest event to X</button>
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

                <section class="nav-status mb-3" aria-live="polite">
                    <div id="eventFacebookPostStatus" class="d-none" role="status" aria-live="polite"></div>
                    <div id="eventTwitterShareStatus" class="d-none" role="status" aria-live="polite"></div>
                </section>

                <section class="matches-events-layout">
                    <article class="utility-panel matches-events-layout__entry hub-form-card">
                        <div class="players-section-heading">
                            <div>
                                <p class="page-kicker">Quick States</p>
                                <h2 class="feature-card__title">Match timeline markers</h2>
                            </div>
                        </div>

                        <div class="matches-event-quick-grid">
                            <?php foreach (['kickoff' => 'Kick Off', 'half_time' => 'Half Time', 'second_half' => 'Second Half', 'full_time' => 'Full Time'] as $stateKey => $stateLabel): ?>
                                <form method="post" class="match-event-quick-form">
                                    <input type="hidden" name="action" value="quick_state">
                                    <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                    <input type="hidden" name="state_type" value="<?= safe($stateKey) ?>">
                                    <button class="btn btn-brand" type="submit"><?= safe($stateLabel) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>

                        <div class="players-section-heading mt-4">
                            <div>
                                <p class="page-kicker"><?= $editingEventId !== '' ? 'Edit Event' : 'Add Event' ?></p>
                                <h2 class="feature-card__title"><?= $editingEventId !== '' ? 'Update timeline item' : 'Structured match event' ?></h2>
                            </div>
                        </div>

                        <form method="post" class="matches-form matches-form--events">
                            <input type="hidden" name="action" value="<?= $editingEventId !== '' ? 'update_event' : 'add_event' ?>">
                            <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                            <input type="hidden" name="event_id" value="<?= safe((string) $formState['event_id']) ?>">

                            <section class="players-form-section match-form-block">
                                <div class="matches-grid matches-grid--details">
                                    <fieldset class="players-choice-group matches-grid__span-2">
                                        <legend class="players-field__label">Event Type</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--center">
                                            <?php foreach (array_keys($formTypeLabels) as $typeKey): ?>
                                                <label class="players-pill players-pill--fill">
                                                    <input type="radio" name="event_type" value="<?= safe($typeKey) ?>" <?= $formState['event_type'] === $typeKey ? 'checked' : '' ?>>
                                                    <span><?= safe($formTypeLabels[$typeKey]) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                    <fieldset class="players-choice-group matches-grid__span-2">
                                        <legend class="players-field__label">Team</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--center">
                                            <label class="players-pill players-pill--fill">
                                                <input type="radio" name="event_team" value="svfc" <?= $formState['event_team'] === 'svfc' ? 'checked' : '' ?>>
                                                <span>Saltcoats Victoria</span>
                                            </label>
                                            <label class="players-pill players-pill--fill">
                                                <input type="radio" name="event_team" value="opponent" <?= $formState['event_team'] === 'opponent' ? 'checked' : '' ?>>
                                                <span>Opponent</span>
                                            </label>
                                        </div>
                                    </fieldset>

                                    <fieldset class="players-choice-group matches-grid__span-2 js-event-field" data-types="goal" data-teams="svfc">
                                        <legend class="players-field__label">Goalscorer</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--center">
                                            <?php foreach ($goalPlayerOptions as $name): ?>
                                                <label class="players-pill players-pill--fill">
                                                    <input type="radio" name="event_player" value="<?= safe($name) ?>" <?= $formState['event_player'] === $name ? 'checked' : '' ?>>
                                                    <span><?= safe($name) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                    <fieldset id="eventCardTypeField" class="players-choice-group matches-grid__span-2 js-event-field<?= $formState['event_type'] === 'card' ? '' : ' d-none' ?>" data-types="card">
                                        <legend class="players-field__label">Card Type</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--center">
                                            <label class="players-pill players-pill--fill match-events-card-toggle">
                                                <input type="radio" name="event_card_type" value="yellow" <?= $formState['event_card_type'] === 'yellow' ? 'checked' : '' ?>>
                                                <span>Yellow</span>
                                            </label>
                                            <label class="players-pill players-pill--fill match-events-card-toggle">
                                                <input type="radio" name="event_card_type" value="red" <?= $formState['event_card_type'] === 'red' ? 'checked' : '' ?>>
                                                <span>Red</span>
                                            </label>
                                        </div>
                                    </fieldset>

                                    <label class="players-field matches-grid__span-2 js-event-field" data-types="goal,card,substitution">
                                        <span class="players-field__label">Minute</span>
                                        <input class="form-control" type="text" name="event_minute" value="<?= safe((string) $formState['event_minute']) ?>" placeholder="23 or 45+2">
                                    </label>

                                    <fieldset class="players-choice-group matches-grid__span-2 js-event-field" data-types="card" data-teams="svfc">
                                        <legend class="players-field__label">Player</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--center">
                                            <?php foreach ($goalPlayerOptions as $name): ?>
                                                <label class="players-pill players-pill--fill">
                                                    <input type="radio" name="event_player" value="<?= safe($name) ?>" <?= $formState['event_player'] === $name ? 'checked' : '' ?>>
                                                    <span><?= safe($name) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                    <fieldset class="players-choice-group js-event-field" data-types="substitution" data-teams="svfc">
                                        <legend class="players-field__label">Player Off</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--stack">
                                            <?php foreach ($substitutionOffOptions as $name): ?>
                                                <label class="players-pill players-pill--fill">
                                                    <input type="radio" name="event_player" value="<?= safe($name) ?>" <?= $formState['event_player'] === $name ? 'checked' : '' ?>>
                                                    <span><?= safe($name) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                    <fieldset class="players-choice-group js-event-field" data-types="substitution" data-teams="svfc">
                                        <legend class="players-field__label">Player On</legend>
                                        <div class="players-pill-row players-pill-row--fill players-pill-row--stack">
                                            <?php foreach ($substitutionOnOptions as $name): ?>
                                                <label class="players-pill players-pill--fill">
                                                    <input type="radio" name="event_secondary_player" value="<?= safe($name) ?>" <?= $formState['event_secondary_player'] === $name ? 'checked' : '' ?>>
                                                    <span><?= safe($name) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>

                                </div>
                            </section>

                            <div class="dashboard-actions hub-actions">
                                <button class="btn btn-brand matches-events-submit" type="submit"><?= $editingEventId !== '' ? 'Update Event' : 'Add Event' ?></button>
                                <?php if ($editingEventId !== ''): ?>
                                    <a class="btn btn-neutral" href="match_events.php?id=<?= safe((string) $match['id']) ?>">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </article>

                    <aside class="utility-panel matches-events-layout__timeline hub-section">
                        <div class="players-section-heading">
                            <div>
                                <p class="page-kicker">Timeline</p>
                                <h2 class="feature-card__title">Recorded match events</h2>
                            </div>
                        </div>

                        <?php if ($events === []): ?>
                            <div class="hub-empty-state"><strong>No events recorded yet</strong><span>Use a quick state or add the first event from the form.</span></div>
                        <?php else: ?>
                            <div class="matches-events-list">
                                <?php foreach ($events as $event): ?>
                                    <article class="matches-event-card">
                                        <div class="matches-event-card__top">
                                            <span class="matches-event-card__type"><?= safe($typeLabels[(string) ($event['type'] ?? '')] ?? 'Event') ?></span>
                                            <?php if (trim((string) ($event['minute'] ?? '')) !== ''): ?>
                                                <span class="matches-event-card__minute"><?= safe((string) ($event['minute'] ?? '')) ?>'</span>
                                            <?php endif; ?>
                                        </div>
                                        <h3 class="matches-event-card__title"><?= safe(match_events_entry_title($event)) ?></h3>
                                        <?php $eventMeta = match_events_entry_meta($event); ?>
                                        <?php if ($eventMeta !== ''): ?>
                                            <p class="matches-event-card__meta"><?= safe($eventMeta) ?></p>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($event['note'] ?? '')) !== '' && (string) ($event['type'] ?? '') !== 'note'): ?>
                                            <p class="matches-event-card__note"><?= safe((string) ($event['note'] ?? '')) ?></p>
                                        <?php endif; ?>
                                        <?php $eventFacebookEnabled = facebook_post_type_config((string) ($event['type'] ?? ''))['facebook_enabled']; ?>
                                        <div class="matches-event-card__actions hub-actions">
                                            <button
                                                class="btn <?= $eventFacebookEnabled ? 'btn-neutral' : 'btn-outline-warning' ?> js-post-event-facebook"
                                                type="button"
                                                data-event-id="<?= safe((string) ($event['id'] ?? '')) ?>"
                                                <?= $eventFacebookEnabled ? '' : 'title="This event type does not auto-publish to Facebook. This will be recorded as a manual override."' ?>
                                            >
                                                <?= $eventFacebookEnabled ? 'Post to Facebook' : 'Post to Facebook Anyway' ?>
                                            </button>
                                            <button
                                                class="btn btn-neutral js-share-event-twitter"
                                                type="button"
                                                data-event-id="<?= safe((string) ($event['id'] ?? '')) ?>"
                                            >
                                                Share to X
                                            </button>
                                            <a class="btn btn-neutral" href="match_events.php?id=<?= safe((string) $match['id']) ?>&edit=<?= safe((string) ($event['id'] ?? '')) ?>">Edit</a>
                                            <form method="post" onsubmit="return confirm('Delete this match event? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_event">
                                                <input type="hidden" name="match_id" value="<?= safe((string) $match['id']) ?>">
                                                <input type="hidden" name="event_id" value="<?= safe((string) ($event['id'] ?? '')) ?>">
                                                <button class="btn btn-outline-danger" type="submit">Delete event</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </aside>
                </section>
            <?php endif; ?>
        </div>
    </main>
<?php endif; ?>

<?php app_render_auth_scripts($isAuthenticated); ?>
<?php if ($isAuthenticated && $match !== null): ?>
<script>
    (function() {
        var eventTypeInputs = Array.prototype.slice.call(document.querySelectorAll('input[name="event_type"]'));
        var eventTeamInputs = Array.prototype.slice.call(document.querySelectorAll('input[name="event_team"]'));
        var eventFields = Array.prototype.slice.call(document.querySelectorAll('.js-event-field'));
        var cardTypeInputs = Array.prototype.slice.call(document.querySelectorAll('input[name="event_card_type"]'));
        var latestFacebookButton = document.getElementById('postLatestEventToFacebookBtn');
        var latestTwitterButton = document.getElementById('shareLatestEventToTwitterBtn');
        var facebookStatus = document.getElementById('eventFacebookPostStatus');
        var twitterStatus = document.getElementById('eventTwitterShareStatus');
        var perEventFacebookButtons = Array.prototype.slice.call(document.querySelectorAll('.js-post-event-facebook'));
        var perEventTwitterButtons = Array.prototype.slice.call(document.querySelectorAll('.js-share-event-twitter'));
        var matchId = '<?= safe((string) $match['id']) ?>';
        var socialCsrfToken = <?= json_encode(auth_csrf_token(), JSON_UNESCAPED_SLASHES) ?>;

        if (!eventTypeInputs.length || !eventFields.length) {
            return;
        }

        function currentEventType() {
            var selected = 'goal';
            eventTypeInputs.forEach(function(input) {
                if (input.checked) {
                    selected = input.value;
                }
            });
            return selected;
        }

        function currentEventTeam() {
            var selected = 'svfc';
            eventTeamInputs.forEach(function(input) {
                if (input.checked) {
                    selected = input.value;
                }
            });
            return selected;
        }

        function syncEventFieldVisibility() {
            var selectedType = currentEventType();
            var selectedTeam = currentEventTeam();

            eventFields.forEach(function(field) {
                var types = (field.getAttribute('data-types') || '').split(',');
                var teams = field.getAttribute('data-teams');
                var visible = types.indexOf(selectedType) !== -1;
                if (visible && teams) {
                    visible = teams.split(',').indexOf(selectedTeam) !== -1;
                }
                field.classList.toggle('d-none', !visible);
            });

            if (selectedType !== 'card') {
                cardTypeInputs.forEach(function(input) {
                    input.checked = false;
                });
            }
        }

        eventTypeInputs.forEach(function(input) {
            input.addEventListener('change', syncEventFieldVisibility);
        });

        eventTeamInputs.forEach(function(input) {
            input.addEventListener('change', syncEventFieldVisibility);
        });

        syncEventFieldVisibility();

        function setStatus(element, type, message) {
            if (!element) {
                return;
            }

            element.className = 'alert mt-2';
            element.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');
            element.classList.remove('d-none');
            element.style.whiteSpace = 'pre-line';
            element.textContent = message;
        }

        function encodeBody(data) {
            return Object.keys(data).map(function(key) {
                return encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
            }).join('&');
        }

        function parseJsonResponse(response) {
            return response.json().then(function(json) {
                return {
                    ok: response.ok,
                    json: json
                };
            }).catch(function() {
                return {
                    ok: response.ok,
                    json: null
                };
            });
        }

        function callEventEndpoint(url, payload) {
            var controller = new AbortController();
            var timeoutId = window.setTimeout(function() {
                controller.abort();
            }, 45000);

            payload.csrf_token = socialCsrfToken;

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: encodeBody(payload),
                signal: controller.signal
            }).then(parseJsonResponse).catch(function(error) {
                if (error && error.name === 'AbortError') {
                    throw new Error('Request timed out. Please try again.');
                }
                throw error;
            }).finally(function() {
                window.clearTimeout(timeoutId);
            });
        }

        function toMessage(result, fallback) {
            if (!result.json) {
                return fallback;
            }

            var base = result.json.summary || result.json.message || result.json.error || fallback;
            var details = Array.isArray(result.json.details)
                ? result.json.details.filter(function(detail) {
                    return typeof detail === 'string' && detail.trim() !== '';
                })
                : [];

            if (!details.length) {
                return base;
            }

            return base + '\n\n' + details.join('\n');
        }

        function postEventToFacebookAttempt(payload) {
            return callEventEndpoint('post_event_to_facebook.php', payload).then(function(result) {
                // This event type doesn't auto-publish to Facebook under the
                // matchday strategy (e.g. Goal, Card, Substitution). Offer a
                // deliberate, clearly-labelled manual override instead of
                // just failing silently.
                if (result.json && result.json.blocked && result.json.can_override) {
                    var overrideMessage = (result.json.summary || 'Facebook publishing is disabled for this event type.')
                        + '\n\nPost to Facebook Anyway? This is an exceptional manual action and will be recorded as a manual override.';
                    if (window.confirm(overrideMessage)) {
                        payload.override = '1';
                        return postEventToFacebookAttempt(payload);
                    }
                    throw new Error('Facebook post not sent.');
                }
                if (result.json && result.json.blocked) {
                    throw new Error(toMessage(result, 'Facebook publishing is disabled for this event type.'));
                }

                // Posting-frequency safeguard: not a hard block, just a
                // confirmation prompt so an accidental burst of posts needs
                // an explicit "yes" before it goes any further.
                if (result.json && result.json.requires_confirmation) {
                    if (window.confirm(toMessage(result, 'Publish anyway?'))) {
                        payload.confirm_burst = '1';
                        return postEventToFacebookAttempt(payload);
                    }
                    throw new Error('Facebook post cancelled.');
                }

                if (!result.ok || !result.json || !result.json.ok) {
                    throw new Error(toMessage(result, 'Facebook post failed.'));
                }

                return result;
            });
        }

        function postEventToFacebook(eventId, button, initialLabel) {
            if (!window.confirm('Post this event to Facebook now?')) {
                return;
            }

            var label = initialLabel || button.textContent;
            button.disabled = true;
            button.textContent = 'Posting...';
            setStatus(facebookStatus, 'success', 'Posting event to Facebook...');

            var payload = {
                match_id: matchId
            };

            if (eventId) {
                payload.event_id = eventId;
            }

            postEventToFacebookAttempt(payload)
                .then(function(result) {
                    setStatus(facebookStatus, 'success', toMessage(result, 'Facebook event post completed.'));
                })
                .catch(function(error) {
                    var message = error && typeof error.message === 'string' && error.message.trim() !== ''
                        ? error.message.trim()
                        : 'Facebook post failed.';
                    setStatus(facebookStatus, 'error', message);
                })
                .finally(function() {
                    button.disabled = false;
                    button.textContent = label;
                });
        }

        function shareEventToTwitter(eventId, button, initialLabel) {
            if (!window.confirm('Prepare an X share for this event now?')) {
                return;
            }

            var label = initialLabel || button.textContent;
            button.disabled = true;
            button.textContent = 'Preparing...';
            setStatus(twitterStatus, 'success', 'Preparing X share…');

            var payload = {
                match_id: matchId
            };

            if (eventId) {
                payload.event_id = eventId;
            }

            callEventEndpoint('prepare_event_share.php', payload)
                .then(function(result) {
                    if (!result.ok || !result.json || !result.json.ok) {
                        throw new Error(toMessage(result, 'X share preparation failed.'));
                    }

                    if (result.json.compose_url) {
                        window.open(result.json.compose_url, '_blank');
                    }

                    if (result.json.download_url) {
                        var downloadLink = document.createElement('a');
                        downloadLink.href = result.json.download_url;
                        downloadLink.download = '';
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                    }

                    // Record the share for dedupe/audit purposes now that it has
                    // actually been dispatched — mirrors the pattern used by
                    // match_graphics.php, match_next_match.php, etc. Fire-and-forget:
                    // Hub cannot know whether the operator actually sends the tweet.
                    var shareRecord = {
                        match_id: matchId,
                        event_id: eventId || '',
                        post_type: result.json.event_type || 'match_update',
                        caption: result.json.text || '',
                        image_url: 'match_graphics.php?fixture_id=' + encodeURIComponent(matchId)
                    };
                    callEventEndpoint('record_manual_share.php', shareRecord).catch(function() {});

                    setStatus(twitterStatus, 'success', toMessage(result, 'X share prepared.'));
                })
                .catch(function(error) {
                    var message = error && typeof error.message === 'string' && error.message.trim() !== ''
                        ? error.message.trim()
                        : 'X share preparation failed.';
                    setStatus(twitterStatus, 'error', message);
                })
                .finally(function() {
                    button.disabled = false;
                    button.textContent = label;
                });
        }

        if (latestFacebookButton) {
            latestFacebookButton.addEventListener('click', function() {
                postEventToFacebook('', latestFacebookButton, 'Post Latest Event to Facebook');
            });
        }

        if (latestTwitterButton) {
            latestTwitterButton.addEventListener('click', function() {
                shareEventToTwitter('', latestTwitterButton, 'Share latest event to X');
            });
        }

        perEventFacebookButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var eventId = button.getAttribute('data-event-id') || '';
                postEventToFacebook(eventId, button, button.textContent.trim());
            });
        });

        perEventTwitterButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var eventId = button.getAttribute('data-event-id') || '';
                shareEventToTwitter(eventId, button, 'Share to X');
            });
        });
    })();
</script>
<?php endif; ?>
</body>

</html>
