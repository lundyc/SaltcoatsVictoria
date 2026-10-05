<?php
declare(strict_types=1);

$pageStyles = ['match-fixture-tabs.css'];

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$useSharedHubLayout = true;
$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? $_GET['id'] ?? 0);
$requestedSeasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;

if (!$fixture) {
    http_response_code(404);
    $pageHero = [
        'eyebrow' => 'Fixture management',
        'title' => 'POTM (Player of the Match)',
        'subtitle' => 'The requested fixture could not be found.',
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Fixture not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$seasonId = (int) ($fixture['season_id'] ?? $requestedSeasonId);
$season = getSeasonById($pdo, $seasonId);
$match = matches_find_by_id(matches_load_all(), (string) $fixtureId);
$existingEvent = null;
if ($match !== null && isset($match['events']) && is_array($match['events'])) {
    foreach ($match['events'] as $candidateEvent) {
        if (is_array($candidateEvent) && (string) ($candidateEvent['type'] ?? '') === 'player_of_match') {
            $existingEvent = $candidateEvent;
        }
    }
}

$errors = [];
$selectedPlayer = trim((string) ($_POST['event_player'] ?? $existingEvent['player'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$season) {
        $errors[] = 'Season not found.';
    } elseif ((int) ($season['is_locked'] ?? 0) === 1) {
        $errors[] = 'This season is locked.';
    } else {
        $eventPost = [
            'match_id' => (string) $fixtureId,
            'event_id' => (string) ($existingEvent['id'] ?? ''),
            'event_type' => 'player_of_match',
            'event_team' => 'svfc',
            'event_minute' => '',
            'event_player' => $selectedPlayer,
            'event_note' => 'Player of the Match',
        ];
        $result = $existingEvent !== null
            ? matches_handle_update_event($eventPost)
            : matches_handle_add_event($eventPost);

        if (!empty($result['ok'])) {
            $eventId = $existingEvent !== null
                ? (string) ($existingEvent['id'] ?? '')
                : (string) ($result['event']['id'] ?? '');
            auditLog($pdo, 'match_player_of_match_set', ($existingEvent !== null ? 'Updated' : 'Set') . ' Player of the Match for fixture vs ' . trim((string) ($fixture['opponent'] ?? 'Opponent')) . ': ' . $selectedPlayer);
            header(
                'Location: /admin/match_graphics.php?fixture_id=' . $fixtureId
                . '&season_id=' . $seasonId
                . '&event_saved=1&social_event_id=' . rawurlencode($eventId)
            );
            exit;
        }

        $errors = isset($result['errors']) && is_array($result['errors'])
            ? array_values(array_map('strval', $result['errors']))
            : [(string) ($result['message'] ?? 'Player of the Match could not be saved.')];
    }
}

$playerRows = $pdo->query("
    SELECT id, name, avatar, status
    FROM players
    WHERE active = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$playersByName = [];
foreach ($playerRows as $playerRow) {
    $name = trim((string) ($playerRow['name'] ?? ''));
    if ($name !== '') {
        $playersByName[$name] = $playerRow;
    }
}

$lineupNames = array_values(array_unique(array_merge(
    matchStarting11PrepareLineup($fixture['starting11_starters'] ?? []),
    matchStarting11PrepareLineup($fixture['starting11_substitutes'] ?? [])
)));

// Keep an already-recorded Player of the Match on the list even if the line-up was
// edited afterwards and no longer includes them.
if ($selectedPlayer !== '' && !in_array($selectedPlayer, $lineupNames, true)) {
    $lineupNames[] = $selectedPlayer;
}

// The award only makes sense for players who were in the match: the starting XI
// and the named bench. The full squad is only shown as a fallback when no
// line-up has been recorded for the fixture yet.
$lineupRecorded = $lineupNames !== [];

$orderedPlayers = [];
foreach ($lineupNames as $lineupName) {
    $orderedPlayers[] = $playersByName[$lineupName] ?? [
        'id' => 0,
        'name' => $lineupName,
        'avatar' => '',
        'status' => '',
    ];
    unset($playersByName[$lineupName]);
}
if (!$lineupRecorded) {
    foreach ($playersByName as $playerRow) {
        $orderedPlayers[] = $playerRow;
    }
}

$opponent = trim((string) ($fixture['opponent'] ?? 'Opponent'));
$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'POTM (Player of the Match)',
    'subtitle' => 'Select the standout player from Saltcoats Victoria v ' . $opponent . '.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
?>
<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>"><?= h($opponent) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">POTM</span></nav>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-player-of-match.css">

<div>
    <div class="potm-shell">
        <?php renderFixtureTabs($fixtureId, $seasonId, 'player_of_match'); ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endforeach; ?>

        <section class="potm-intro">
            <div>
                <span class="page-kicker">Match award</span>
                <h2>Who was Player of the Match?</h2>
                <p>Choose a player below. Saving will create the Pack 5 graphic and open it in the Social Composer ready to review, download or publish.</p>
            </div>
            <span class="potm-status<?= $existingEvent !== null ? ' is-recorded' : '' ?>"><?= $existingEvent !== null ? 'Award recorded' : 'Awaiting selection' ?></span>
        </section>

        <form method="post" id="playerOfMatchForm">
            <?= csrf_field() ?>
            <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
            <input type="hidden" name="season_id" value="<?= $seasonId ?>">

            <?php if (!$lineupRecorded): ?>
                <div class="alert alert-warning">No starting XI or bench has been recorded for this match yet, so the full squad is shown. <a href="/admin/match.php?id=<?= (int) $fixtureId ?>&amp;season_id=<?= (int) $seasonId ?>&amp;tab=starting11">Set the line-up</a> to limit this to the matchday squad.</div>
            <?php endif; ?>

            <div class="potm-grid" role="radiogroup" aria-label="Select Player of the Match">
                <?php foreach ($orderedPlayers as $player): ?>
                    <?php
                    $name = trim((string) ($player['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $avatar = basename(trim((string) ($player['avatar'] ?? '')));
                    $avatarFile = __DIR__ . '/uploads/players/' . $avatar;
                    $avatarUrl = $avatar !== '' && is_file($avatarFile)
                        ? '/uploads/players/' . rawurlencode($avatar)
                        : '';
                    $nameParts = preg_split('/\s+/', $name) ?: [];
                    $initials = '';
                    foreach (array_slice($nameParts, 0, 2) as $namePart) {
                        $initials .= mb_strtoupper(mb_substr($namePart, 0, 1));
                    }
                    ?>
                    <label class="potm-player">
                        <input type="radio" name="event_player" value="<?= h($name) ?>" <?= $selectedPlayer === $name ? 'checked' : '' ?> required>
                        <span class="potm-player__card">
                            <span class="potm-player__image">
                                <?php if ($avatarUrl !== ''): ?>
                                    <img src="<?= h($avatarUrl) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="potm-player__initials" aria-hidden="true"><?= h($initials) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="potm-player__name"><?= h($name) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="potm-actions">
                <div class="potm-actions__selection">
                    Selected player
                    <strong id="selectedPlayerLabel"><?= h($selectedPlayer !== '' ? $selectedPlayer : 'None selected') ?></strong>
                </div>
                <button class="btn btn-primary btn-lg px-4" type="submit">
                    <?= $existingEvent !== null ? 'Update & Create Graphic' : 'Save & Create Graphic' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('playerOfMatchForm');
    const label = document.getElementById('selectedPlayerLabel');
    if (!form || !label) return;

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.name === 'event_player') {
            label.textContent = event.target.value;
        }
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
