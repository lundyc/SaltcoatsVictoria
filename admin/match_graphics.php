<?php
$pageStyles = ['match-fixture-tabs.css'];
$useSharedHubLayout = true;

if ($useSharedHubLayout) {
          require_once __DIR__ . '/db.php';
          require_once __DIR__ . '/lib/season.php';

          $headerSeasonId = (int)($_GET['season_id'] ?? 0);
          if ($headerSeasonId <= 0) {
                    $headerSeasonId = getSelectedSeasonId($pdo);
          }
          $headerSeason = getSeasonById($pdo, $headerSeasonId);
          $headerFixtureId = (int)($_GET['fixture_id'] ?? 0);
          $headerRenderUrl = '/match_graphic_render.php?fixture_id=' . rawurlencode((string)$headerFixtureId)
                    . '&season_id=' . rawurlencode((string)$headerSeasonId);
          $pageHero = [
                    'eyebrow' => 'Fixture management',
                    'title' => 'Match Fixture',
                    'subtitle' => 'Season: ' . ($headerSeason['name'] ?? 'Unknown'),
                    'actions' => [],
          ];
          require_once __DIR__ . '/header.php';
} else {
          require_once __DIR__ . '/header.php';
}
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/social_post_settings.php';

$layoutFooter = $useSharedHubLayout ? __DIR__ . '/footer.php' : __DIR__ . '/footer.php';

function matchGraphicsEventTitle(array $event): string
{
          $type = (string)($event['type'] ?? '');
          $team = (string)($event['team'] ?? '');
          $player = trim((string)($event['player'] ?? ''));
          $secondary = trim((string)($event['secondary_player'] ?? ''));
          $note = trim((string)($event['note'] ?? ''));
          if ($type === 'goal') {
                    if (!empty($event['own_goal'])) {
                              return 'Own Goal' . ($player !== '' && strcasecmp($player, 'Unknown Player') !== 0 ? ': ' . $player : '');
                    }
                    return ($team === 'opponent' ? 'Opponent goal' : 'Goal') . ($player !== '' ? ': ' . $player : '');
          }
          if ($type === 'card') {
                    $label = ucfirst((string)($event['card_type'] ?? '')) . ' Card';
                    return $label . ($player !== '' ? ': ' . $player : '');
          }
          if (in_array($type, ['yellow_card', 'red_card'], true)) {
                    $label = $type === 'yellow_card' ? 'Yellow Card' : 'Red Card';
                    return $label . ($player !== '' ? ': ' . $player : '');
          }
          if ($type === 'substitution' && $player !== '' && $secondary !== '') {
                    $substitutions = isset($event['substitutions']) && is_array($event['substitutions']) ? $event['substitutions'] : [];
                    if (count($substitutions) > 1) {
                              return 'Substitutions: ' . count($substitutions) . ' changes';
                    }
                    return 'Substitution: ' . $player . ' off, ' . $secondary . ' on';
          }
          if ($type === 'note' && $note !== '') {
                    return $note;
          }
          $title = matches_event_type_labels()[$type] ?? 'Match Event';
          $outcome = trim((string)($event['outcome'] ?? ''));
          if ($player !== '') {
                    $title .= ': ' . $player;
          }
          if ($outcome !== '') {
                    $title .= ' · ' . ucwords(str_replace('_', ' ', $outcome));
          }
          return $title;
}

$seasonId = getSelectedSeasonId($pdo);
$fixtureId = (int)($_GET['fixture_id'] ?? 0);
if (isset($_GET['season_id'])) {
          $requestedSeasonId = (int)$_GET['season_id'];
          if ($requestedSeasonId > 0) {
                    $seasonId = $requestedSeasonId;
          }
}
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if ($fixture && (int)$fixture['season_id'] !== $seasonId) {
          $seasonId = (int)$fixture['season_id'];
}
$fixtures = getMatchFixtures($pdo, $seasonId);
if (!$fixtureId && $fixtures) {
          $fixtureId = (int)$fixtures[0]['id'];
          $fixture = getMatchFixtureById($pdo, $fixtureId);
}
if (!$fixture) {
          echo '<div><div class="alert alert-warning">No fixtures found for this season.</div></div>';
          require_once $layoutFooter;
          exit;
}

$eventError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['record_match_event', 'delete_match_event'], true)) {
          if (!csrf_check()) {
                    $eventError = 'Invalid security token. Please try again.';
          } else {
                    $_POST['match_id'] = (string)$fixtureId;
                    $eventResult = (string)$_POST['action'] === 'delete_match_event'
                              ? matches_handle_delete_event($_POST)
                              : matches_handle_add_event($_POST);
                    if (!empty($eventResult['ok'])) {
                              $notice = (string)$_POST['action'] === 'delete_match_event' ? 'event_deleted=1' : 'event_saved=1';
                              if (
                                        (string)$_POST['action'] === 'record_match_event'
                                        && isset($eventResult['event'])
                                        && is_array($eventResult['event'])
                                        && in_array((string)($eventResult['event']['type'] ?? ''), ['kickoff', 'goal', 'half_time', 'full_time', 'yellow_card', 'red_card', 'penalty', 'substitution', 'player_of_match'], true)
                              ) {
                                        $notice .= '&social_event_id=' . rawurlencode((string)($eventResult['event']['id'] ?? ''));
                              }
                              header('Location: match_graphics.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId . '&' . $notice);
                              exit;
                    }
                    $eventError = (string)($eventResult['message'] ?? 'The match event could not be saved.');
                    if (!empty($eventResult['errors']) && is_array($eventResult['errors'])) {
                              $eventError .= ' ' . implode(' ', array_map('strval', $eventResult['errors']));
                    }
          }
}

$eventMatch = matches_find_by_id(matches_load_all(), (string)$fixtureId);
$matchEvents = $eventMatch && isset($eventMatch['events']) && is_array($eventMatch['events']) ? $eventMatch['events'] : [];
$timelineEvents = $matchEvents;
usort($timelineEvents, static function (array $left, array $right): int {
          return (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0);
});
$recordedPeriodTypes = [];
foreach ($matchEvents as $matchEvent) {
          $matchEventType = (string)($matchEvent['type'] ?? '');
          if (in_array($matchEventType, ['kickoff', 'half_time', 'second_half', 'full_time'], true)) {
                    $recordedPeriodTypes[$matchEventType] = true;
          }
}
$starterNames = matchStarting11PrepareLineup($fixture['starting11_starters'] ?? []);
$substituteNames = matchStarting11PrepareLineup($fixture['starting11_substitutes'] ?? []);
$playerNames = array_values(array_unique(array_merge($starterNames, $substituteNames)));
$saltcoatsPlayerNames = $playerNames !== [] ? $playerNames : ['Unknown Player'];
$opponentPlayerNames = ['Unknown Player'];
$lineupSquadNumbers = is_array($fixture['starting11_squad_numbers'] ?? null) ? $fixture['starting11_squad_numbers'] : [];
$saltcoatsSubstitutionLabels = [];
foreach ($playerNames as $lineupPlayerName) {
          $squadNumber = (int)($lineupSquadNumbers[$lineupPlayerName] ?? 0);
          $saltcoatsSubstitutionLabels[$lineupPlayerName] = $squadNumber > 0
                    ? $squadNumber . '. ' . $lineupPlayerName
                    : $lineupPlayerName;
}
$shareableEventTypes = ['kickoff', 'goal', 'half_time', 'full_time', 'yellow_card', 'red_card', 'penalty', 'substitution', 'player_of_match'];
$autoSocialEventId = isset($_GET['social_event_id']) && is_string($_GET['social_event_id'])
          ? trim($_GET['social_event_id'])
          : '';

$eventButtons = [
          ['label' => 'Goal', 'type' => 'goal', 'icon' => 'fa-futbol', 'tone' => 'success'],
          ['label' => 'Shot', 'type' => 'shot', 'icon' => 'fa-bullseye', 'tone' => 'primary'],
          ['label' => 'Chance', 'type' => 'chance', 'icon' => 'fa-star', 'tone' => 'info'],
          ['label' => 'Corner', 'type' => 'corner', 'icon' => 'fa-flag', 'tone' => 'primary'],
          ['label' => 'Free Kick', 'type' => 'free_kick', 'icon' => 'fa-person-running', 'tone' => 'primary'],
          ['label' => 'Penalty', 'type' => 'penalty', 'icon' => 'fa-circle-dot', 'tone' => 'danger'],
          ['label' => 'Offside', 'type' => 'off_side', 'icon' => 'fa-flag-checkered', 'tone' => 'secondary'],
          ['label' => 'Yellow Card', 'type' => 'yellow_card', 'card' => 'yellow', 'icon' => 'fa-square', 'tone' => 'warning'],
          ['label' => 'Red Card', 'type' => 'red_card', 'card' => 'red', 'icon' => 'fa-square', 'tone' => 'danger'],
          ['label' => 'Substitution', 'type' => 'substitution', 'icon' => 'fa-right-left', 'tone' => 'info'],
          ['label' => 'Mistake', 'type' => 'mistake', 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger'],
          ['label' => 'Good Play', 'type' => 'good_play', 'icon' => 'fa-thumbs-up', 'tone' => 'success'],
          ['label' => 'Highlight', 'type' => 'highlight', 'icon' => 'fa-clapperboard', 'tone' => 'dark'],
];
$periodEventButtons = [
          ['label' => 'Kick-Off', 'type' => 'kickoff', 'icon' => 'fa-play'],
          ['label' => 'Half-Time', 'type' => 'half_time', 'icon' => 'fa-pause'],
          ['label' => 'Second Half', 'type' => 'second_half', 'icon' => 'fa-forward-step'],
          ['label' => 'Full-Time', 'type' => 'full_time', 'icon' => 'fa-flag-checkered'],
];

$renderUrl = 'match_graphic_render.php?fixture_id=' . urlencode((string)$fixtureId) . '&season_id=' . urlencode((string)$seasonId);
?>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-graphics.css">

<?php if (!$useSharedHubLayout): ?>
<div class="page-hero mb-4">
          <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                    <div>
                              <div class="page-hero-eyebrow">Creative studio</div>
                              <h1 class="page-hero-title">Match Graphics</h1>
                              <p class="page-hero-subtitle"><?= h(($fixture['is_home'] ? 'Home' : 'Away') . ' v ' . $fixture['opponent']) ?></p>
                    </div>
          </div>
</div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>"><?= h((string)$fixture['opponent']) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Match graphics</span></nav>
<?php renderFixtureTabs((int)$fixtureId, (int)$seasonId, 'graphics'); ?>
<div class="hub-section-commandbar"><div><h2>Match graphics workspace</h2><p>Record events and generate the current match artwork.</p></div><div class="hub-local-actions"><a id="downloadBtn" href="<?= h($renderUrl) ?>" class="btn btn-brand btn-sm" download><i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download PNG</a></div></div>

<?php if (isset($_GET['event_saved'])): ?>
          <div class="alert alert-success">Match event saved.</div>
<?php endif; ?>
<?php if (isset($_GET['event_deleted'])): ?>
          <div class="alert alert-success">Match event deleted.</div>
<?php endif; ?>
<?php if ($eventError !== ''): ?>
          <div class="alert alert-danger"><?= h($eventError) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
          <div class="card-body">
                    <div class="small fw-semibold text-uppercase text-muted mb-2">Match Periods</div>
                    <div class="d-flex flex-wrap gap-2">
                              <?php foreach ($periodEventButtons as $periodButton): ?>
                                        <?php $periodRecorded = isset($recordedPeriodTypes[(string)$periodButton['type']]); ?>
                                        <button type="button" class="btn <?= $periodRecorded ? 'btn-outline-success' : 'btn-outline-secondary js-match-event-button' ?>" <?= $periodRecorded ? 'disabled aria-disabled="true" title="This match period has already been recorded"' : 'data-bs-toggle="modal" data-bs-target="#recordMatchEventModal"' ?> data-event-label="<?= h((string)$periodButton['label']) ?>" data-event-type="<?= h((string)$periodButton['type']) ?>" data-event-team="match" data-event-card="" data-event-note="">
                                                  <i class="fa-solid <?= $periodRecorded ? 'fa-circle-check' : h((string)$periodButton['icon']) ?> me-1"></i><?= h((string)$periodButton['label']) ?><?= $periodRecorded ? ' · Recorded' : '' ?>
                                        </button>
                              <?php endforeach; ?>
                    </div>
          </div>
</div>

<div class="row g-3 mb-4">
          <div class="col-12 col-xl-8">
                    <div class="card shadow-sm h-100">
                              <div class="card-body">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                                  <div>
                                                            <h2 class="h5 mb-1">Event Groups</h2>
                                                            <p class="text-muted mb-0">Choose the team, then select an event to open its recorder.</p>
                                                  </div>
                                                  <span class="badge text-bg-light"><?= count($matchEvents) ?> recorded</span>
                                        </div>
                                        <div class="mb-3">
                                                  <div class="small fw-semibold text-uppercase text-muted mb-2">Team</div>
                                                  <div class="btn-group w-100" id="matchEventBoardTeam" role="group" aria-label="Event team">
                                                            <button type="button" class="btn btn-primary active" data-board-team="svfc" aria-pressed="true">Saltcoats Victoria</button>
                                                            <button type="button" class="btn btn-outline-primary" data-board-team="opponent" aria-pressed="false"><?= h((string)$fixture['opponent']) ?></button>
                                                  </div>
                                        </div>
                                        <div class="match-event-grid">
                                                  <?php foreach ($eventButtons as $eventButton): ?>
                                                            <button
                                                                      type="button"
                                                                      class="btn btn-outline-<?= h((string)$eventButton['tone']) ?> match-event-grid__button js-match-event-button"
                                                                      data-bs-toggle="modal"
                                                                      data-bs-target="#recordMatchEventModal"
                                                                      data-event-label="<?= h((string)$eventButton['label']) ?>"
                                                                      data-event-type="<?= h((string)$eventButton['type']) ?>"
                                                                      data-event-team="selected"
                                                                      data-event-card="<?= h((string)($eventButton['card'] ?? '')) ?>"
                                                                      data-event-note="<?= h((string)($eventButton['note'] ?? '')) ?>"
                                                            >
                                                                      <i class="fa-solid <?= h((string)$eventButton['icon']) ?>"></i>
                                                                      <span><?= h((string)$eventButton['label']) ?></span>
                                                            </button>
                                                  <?php endforeach; ?>
                                        </div>
                              </div>
                    </div>
          </div>
          <div class="col-12 col-xl-4">
                    <div class="card shadow-sm h-100">
                              <div class="card-body">
                                        <h2 class="h5 mb-3">Match Timeline</h2>
                                        <?php if ($matchEvents === []): ?>
                                                  <p class="text-muted mb-0">No events recorded yet.</p>
                                        <?php else: ?>
                                                  <div class="match-event-timeline">
                                                            <?php foreach ($timelineEvents as $event): ?>
                                                                      <?php $eventIsShareable = in_array((string)($event['type'] ?? ''), $shareableEventTypes, true); ?>
                                                                      <div class="match-event-timeline__item d-flex justify-content-between align-items-start gap-2<?= $eventIsShareable ? ' match-event-timeline__item--shareable js-timeline-event' : '' ?>"<?= $eventIsShareable ? ' data-event-id="' . h((string)($event['id'] ?? '')) . '" role="button" tabindex="0" title="Prepare social post"' : '' ?>>
                                                                                <div>
                                                                                          <div class="fw-semibold"><?= h(matchGraphicsEventTitle($event)) ?></div>
                                                                                          <div class="small text-muted">
                                                                                                    <?= h(matches_event_type_labels()[(string)($event['type'] ?? '')] ?? 'Event') ?>
                                                                                                    <?php if (trim((string)($event['minute'] ?? '')) !== ''): ?> · <?= h((string)$event['minute']) ?>'<?php endif; ?>
                                                                                          </div>
                                                                                </div>
                                                                                <div class="d-flex gap-1">
                                                                                          <?php if ($eventIsShareable): ?>
                                                                                                    <button type="button" class="btn btn-sm btn-outline-primary js-social-event" data-event-id="<?= h((string)($event['id'] ?? '')) ?>" title="Prepare social post" aria-label="Prepare social post"><i class="fa-solid fa-share-nodes"></i></button>
                                                                                          <?php endif; ?>
                                                                                          <form method="post" data-confirm="Delete this event?" data-confirm-action="Delete">
                                                                                                    <?= csrf_field() ?>
                                                                                                    <input type="hidden" name="action" value="delete_match_event">
                                                                                                    <input type="hidden" name="event_id" value="<?= h((string)($event['id'] ?? '')) ?>">
                                                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete event" aria-label="Delete event"><i class="fa-solid fa-trash"></i></button>
                                                                                          </form>
                                                                                </div>
                                                                      </div>
                                                            <?php endforeach; ?>
                                                  </div>
                                        <?php endif; ?>
                              </div>
                    </div>
          </div>
</div>

<div class="modal fade" id="recordMatchEventModal" tabindex="-1" aria-labelledby="recordMatchEventModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                              <form method="post" id="recordMatchEventForm">
                                        <div class="modal-header">
                                                  <div>
                                                            <h2 class="modal-title h5 mb-1" id="recordMatchEventModalLabel">Record Match Event</h2>
                                                            <div class="small text-muted">Add this event to the match timeline.</div>
                                                  </div>
                                                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="action" value="record_match_event">
                                                  <input type="hidden" name="event_type" id="matchEventType" value="">
                                                  <input type="hidden" name="event_card_type" id="matchEventCardType" value="">
                                                  <div class="row g-3">
                                                            <fieldset class="col-12" id="matchEventTeamWrap">
                                                                      <legend class="form-label">Team</legend>
                                                                      <div class="match-event-choice-grid" role="group" aria-label="Team">
                                                                                <input type="radio" class="btn-check" name="event_team" id="matchEventTeamSvfc" value="svfc" autocomplete="off">
                                                                                <label class="btn btn-outline-primary" for="matchEventTeamSvfc">Saltcoats Victoria</label>
                                                                                <input type="radio" class="btn-check" name="event_team" id="matchEventTeamOpponent" value="opponent" autocomplete="off">
                                                                                <label class="btn btn-outline-primary" for="matchEventTeamOpponent"><?= h((string)$fixture['opponent']) ?></label>
                                                                                <input type="radio" class="btn-check" name="event_team" id="matchEventTeamMatch" value="match" autocomplete="off">
                                                                      </div>
                                                            </fieldset>
                                                            <div class="col-md-6" id="matchEventMinuteWrap">
                                                                      <label for="matchEventMinute" class="form-label">Minute</label>
                                                                      <input type="text" class="form-control" name="event_minute" id="matchEventMinute" placeholder="e.g. 23 or 45+2" inputmode="numeric">
                                                            </div>
                                                            <fieldset class="col-12 d-none" id="matchEventPlayerWrap">
                                                                      <legend class="form-label" id="matchEventPlayerLabel">Player</legend>
                                                                      <div class="match-event-player-team" data-player-team="svfc">
                                                                                <div class="match-event-choice-grid">
                                                                                          <?php foreach ($saltcoatsPlayerNames as $playerIndex => $playerName): ?>
                                                                                                    <input type="radio" class="btn-check js-match-event-player" name="event_player" id="matchEventSvfcPlayer<?= (int)$playerIndex ?>" value="<?= h((string)$playerName) ?>" autocomplete="off">
                                                                                                    <label class="btn btn-outline-secondary" for="matchEventSvfcPlayer<?= (int)$playerIndex ?>"><?= h((string)$playerName) ?></label>
                                                                                          <?php endforeach; ?>
                                                                                </div>
                                                                      </div>
                                                                      <div class="match-event-player-team d-none" data-player-team="opponent">
                                                                                <div class="match-event-choice-grid">
                                                                                          <?php foreach ($opponentPlayerNames as $playerIndex => $playerName): ?>
                                                                                                    <input type="radio" class="btn-check js-match-event-player" name="event_player" id="matchEventOpponentPlayer<?= (int)$playerIndex ?>" value="<?= h((string)$playerName) ?>" autocomplete="off">
                                                                                                    <label class="btn btn-outline-secondary" for="matchEventOpponentPlayer<?= (int)$playerIndex ?>"><?= h((string)$playerName) ?></label>
                                                                                          <?php endforeach; ?>
                                                                                </div>
                                                                      </div>
                                                            </fieldset>
                                                            <div class="col-12 d-none" id="matchEventOwnGoalWrap">
                                                                      <div class="form-check form-switch rounded border bg-light p-3 ps-5">
                                                                                <input class="form-check-input" type="checkbox" role="switch" name="event_own_goal" id="matchEventOwnGoal" value="1">
                                                                                <label class="form-check-label fw-semibold" for="matchEventOwnGoal">Own goal</label>
                                                                                <div class="small text-muted">Select the team awarded the goal above. The scorer choices will switch to the opposing team.</div>
                                                                      </div>
                                                            </div>
                                                            <div class="col-md-6 d-none" id="matchEventSecondaryPlayerWrap">
                                                                      <label for="matchEventSecondaryPlayer" class="form-label">Player On</label>
                                                                      <select class="form-select" name="event_secondary_player" id="matchEventSecondaryPlayer">
                                                                                <option value="">Select player</option>
                                                                                <?php foreach ($substituteNames !== [] ? $substituteNames : ($playerNames !== [] ? $playerNames : ['Unknown Player']) as $playerName): ?>
                                                                                          <option value="<?= h((string)$playerName) ?>"><?= h((string)$playerName) ?></option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                            </div>
                                                            <fieldset class="col-12 d-none" id="matchEventSubstitutionsWrap">
                                                                      <legend class="form-label mb-1">Substitutions</legend>
                                                                      <p class="small text-muted mb-3">Add each player coming off and the player replacing them.</p>
                                                                      <div class="d-grid gap-3" id="matchEventSubstitutionRows"></div>
                                                                      <button type="button" class="btn btn-outline-primary mt-3" id="addMatchEventSubstitution">
                                                                                <i class="fa-solid fa-plus me-1"></i>Add Another Substitution
                                                                      </button>
                                                                      <template id="matchEventSubstitutionRowTemplate">
                                                                                <div class="card border-0 bg-light match-event-substitution-row">
                                                                                          <div class="card-body p-3">
                                                                                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                                                                              <strong class="match-event-substitution-number">Substitution</strong>
                                                                                                              <button type="button" class="btn btn-sm btn-outline-danger match-event-substitution-remove" aria-label="Remove substitution">
                                                                                                                        <i class="fa-solid fa-trash"></i>
                                                                                                              </button>
                                                                                                    </div>
                                                                                                    <div class="row g-2">
                                                                                                              <div class="col-md-6">
                                                                                                                        <label class="form-label">Player Off</label>
                                                                                                                        <select class="form-select match-event-substitution-off" name="substitution_off[]" required></select>
                                                                                                              </div>
                                                                                                              <div class="col-md-6">
                                                                                                                        <label class="form-label">Player On</label>
                                                                                                                        <select class="form-select match-event-substitution-on" name="substitution_on[]" required></select>
                                                                                                              </div>
                                                                                                    </div>
                                                                                          </div>
                                                                                </div>
                                                                      </template>
                                                            </fieldset>
                                                            <fieldset class="col-12 d-none" id="matchEventOutcomeWrap">
                                                                      <legend class="form-label">Outcome</legend>
                                                                      <div class="match-event-choice-grid" id="matchEventOutcomeChoices"></div>
                                                            </fieldset>
                                                            <fieldset class="col-md-6 d-none" id="matchEventOriginWrap">
                                                                      <legend class="form-label">Event location</legend>
                                                                      <input type="hidden" name="event_origin" id="matchEventOrigin" value="">
                                                                      <div class="match-event-pitch-grid" id="matchEventOriginChoices" aria-label="Event location">
                                                                                <?php foreach (['Left Wing', 'Left Box', 'Centre', 'Right Box', 'Right Wing', 'Long Range'] as $location): ?>
                                                                                          <button type="button" class="match-event-pitch-zone" data-event-choice="origin" data-value="<?= h($location) ?>"><?= h($location) ?></button>
                                                                                <?php endforeach; ?>
                                                                      </div>
                                                            </fieldset>
                                                            <fieldset class="col-md-6 d-none" id="matchEventTargetWrap">
                                                                      <legend class="form-label">Shot target</legend>
                                                                      <input type="hidden" name="event_target" id="matchEventTarget" value="">
                                                                      <div class="match-event-goal-grid" id="matchEventTargetChoices" aria-label="Shot target">
                                                                                <?php foreach (['Top Left', 'Top Centre', 'Top Right', 'Bottom Left', 'Bottom Centre', 'Bottom Right'] as $target): ?>
                                                                                          <button type="button" class="match-event-goal-zone" data-event-choice="target" data-value="<?= h($target) ?>"><?= h($target) ?></button>
                                                                                <?php endforeach; ?>
                                                                      </div>
                                                            </fieldset>
                                                            <div class="col-12" id="matchEventNoteWrap">
                                                                      <label for="matchEventNote" class="form-label">Details / Notes</label>
                                                                      <textarea class="form-control" name="event_note" id="matchEventNote" rows="3" placeholder="Optional details"></textarea>
                                                            </div>
                                                  </div>
                                        </div>
                                        <div class="modal-footer">
                                                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                  <button type="submit" class="btn btn-brand">Record Event</button>
                                        </div>
                              </form>
                    </div>
          </div>
</div>

<div class="modal fade" id="socialEventModal" tabindex="-1" aria-labelledby="socialEventModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down social-composer-modal">
                    <div class="modal-content">
                              <div class="modal-header social-composer-header">
                                        <div class="d-flex align-items-center gap-3">
                                                  <span class="social-composer-header__icon"><i class="fa-solid fa-share-nodes"></i></span>
                                                  <div>
                                                            <div class="small text-uppercase fw-semibold opacity-75 mb-1">Social Composer</div>
                                                            <h2 class="modal-title h4 mb-1" id="socialEventModalLabel">Review Social Post</h2>
                                                            <div class="small opacity-75">Review the graphic and text, then choose where to publish.</div>
                                                  </div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body p-0">
                                        <input type="hidden" id="socialEventId" value="">
                                        <div class="row g-0">
                                                  <div class="col-lg-7 social-composer-preview-panel p-3 p-xl-4">
                                                            <div class="social-composer-preview-wrap">
                                                                      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                                                                <div>
                                                                                          <h3 class="h6 mb-1">Template preview</h3>
                                                                                          <div class="small text-muted">This is the graphic that will be downloaded and published.</div>
                                                                                </div>
                                                                                <span class="badge rounded-pill text-bg-success"><i class="fa-solid fa-circle me-1" style="font-size:.45rem"></i>Live</span>
                                                                      </div>
                                                                      <div class="social-event-preview d-flex align-items-center justify-content-center" id="socialEventPreview">
                                                                                <div class="text-center text-muted p-4" id="socialEventLoading">
                                                                                          <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Preparing preview…
                                                                                </div>
                                                                                <img id="socialEventImage" class="d-none" src="" alt="Generated match event graphic">
                                                                                <iframe id="socialEventLivePreview" class="d-none" src="about:blank" title="Live match event graphic preview"></iframe>
                                                                      </div>
                                                                      <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                                                                <div class="small text-muted" id="socialGraphicDimensions"><i class="fa-solid fa-image me-1"></i>Template-sized graphic</div>
                                                                      </div>
                                                            </div>
                                                  </div>

                                                  <div class="col-lg-5 social-composer-sidebar p-3 p-xl-4">
                                                            <div class="social-composer-template-note">
                                                                      <span class="social-composer-template-note__icon"><i class="fa-solid fa-layer-group"></i></span>
                                                                      <div>
                                                                                <div class="fw-semibold">Design controlled by the selected template</div>
                                                                                <div class="small opacity-75">Artwork, badges, typography and positioning come directly from the fixture’s template pack.</div>
                                                                      </div>
                                                            </div>
                                                            <section class="social-composer-section">
                                                                      <div class="social-composer-section__heading">
                                                                                <span class="social-composer-section__number"><i class="fa-regular fa-pen-to-square"></i></span>
                                                                                <div>
                                                                                          <h3 class="h6 mb-0">Social caption</h3>
                                                                                          <div class="small text-muted">Review or edit the text before publishing.</div>
                                                                                </div>
                                                                      </div>
                                                                      <textarea class="form-control social-composer-caption" id="socialEventCaption" rows="10" placeholder="Preparing post text…"></textarea>
                                                                      <div class="d-flex justify-content-end mt-2">
                                                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="socialCopyText"><i class="fa-regular fa-copy me-1"></i>Copy Text</button>
                                                                      </div>
                                                            </section>

                                                            <div class="alert d-none mt-3 mb-0" id="socialEventStatus" role="status"></div>
                                                            </div>
                                                  </div>
                                        </div>
                              <div class="modal-footer social-composer-footer justify-content-between gap-3">
                                        <div class="d-flex flex-wrap gap-2">
                                                  <a class="btn btn-outline-dark disabled" id="socialDownloadImage" href="#" download aria-disabled="true"><i class="fa-solid fa-download me-1"></i>Download Graphic</a>
                                                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close Without Posting</button>
                                        </div>
                                        <div class="d-flex flex-wrap justify-content-end gap-2 social-composer-footer__actions">
                                                  <button type="button" class="btn btn-primary social-publish-button" id="socialPostFacebook"><i class="fa-brands fa-facebook"></i>Facebook</button>
                                                  <button type="button" class="btn btn-danger social-publish-button" id="socialPostInstagram"><i class="fa-brands fa-instagram"></i>Instagram</button>
                                                  <button type="button" class="btn btn-dark social-publish-button" id="socialOpenX"><i class="fa-brands fa-x-twitter"></i>Open X</button>
                                        </div>
                              </div>
                    </div>
          </div>
</div>

<script>
  (() => {
    const modal = document.getElementById('recordMatchEventModal');
    const title = document.getElementById('recordMatchEventModalLabel');
    const typeInput = document.getElementById('matchEventType');
    const cardInput = document.getElementById('matchEventCardType');
    const teamWrap = document.getElementById('matchEventTeamWrap');
    const teamInputs = Array.from(document.querySelectorAll('input[name="event_team"]'));
    const minuteInput = document.getElementById('matchEventMinute');
    const playerWrap = document.getElementById('matchEventPlayerWrap');
    const playerInputs = Array.from(document.querySelectorAll('.js-match-event-player'));
    const playerTeamGroups = Array.from(document.querySelectorAll('.match-event-player-team'));
    const playerLabel = document.getElementById('matchEventPlayerLabel');
    const ownGoalWrap = document.getElementById('matchEventOwnGoalWrap');
    const ownGoalInput = document.getElementById('matchEventOwnGoal');
    const secondaryWrap = document.getElementById('matchEventSecondaryPlayerWrap');
    const secondaryInput = document.getElementById('matchEventSecondaryPlayer');
    const substitutionsWrap = document.getElementById('matchEventSubstitutionsWrap');
    const substitutionRows = document.getElementById('matchEventSubstitutionRows');
    const substitutionTemplate = document.getElementById('matchEventSubstitutionRowTemplate');
    const addSubstitutionButton = document.getElementById('addMatchEventSubstitution');
    const noteInput = document.getElementById('matchEventNote');
    const outcomeWrap = document.getElementById('matchEventOutcomeWrap');
    const outcomeChoices = document.getElementById('matchEventOutcomeChoices');
    const originWrap = document.getElementById('matchEventOriginWrap');
    const originInput = document.getElementById('matchEventOrigin');
    const targetWrap = document.getElementById('matchEventTargetWrap');
    const targetInput = document.getElementById('matchEventTarget');
    const boardTeamButtons = Array.from(document.querySelectorAll('[data-board-team]'));
    const stateTypes = ['kickoff', 'half_time', 'second_half', 'full_time'];
    const playerTypes = ['goal', 'shot', 'free_kick', 'penalty', 'off_side', 'yellow_card', 'red_card'];
    const originTypes = ['goal', 'shot', 'chance', 'free_kick', 'penalty'];
    const targetTypes = ['goal', 'shot', 'penalty'];
    const outcomeOptions = {
      shot: ['On Target', 'Off Target', 'Blocked'],
      chance: ['Created', 'Missed', 'Converted'],
      corner: ['Cross', 'Short', 'Cleared', 'Chance Created'],
      free_kick: ['Shot On Target', 'Shot Off Target', 'Blocked', 'Cross', 'Pass'],
      penalty: ['Scored', 'Saved', 'Missed', 'Woodwork'],
      mistake: ['Lost Possession', 'Led To Shot', 'Led To Goal'],
    };
    const substitutionPlayers = {
      svfc: {
        off: <?= json_encode($starterNames !== [] ? $starterNames : ['Unknown Player'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        on: <?= json_encode($substituteNames !== [] ? $substituteNames : ['Unknown Player'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      },
      opponent: {
        off: <?= json_encode($opponentPlayerNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        on: <?= json_encode($opponentPlayerNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      },
    };
    const substitutionPlayerLabels = {
      svfc: <?= json_encode($saltcoatsSubstitutionLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      opponent: {},
    };
    let boardTeam = 'svfc';

    if (!modal) return;

    const currentTeam = () => teamInputs.find((input) => input.checked)?.value || 'match';

    const selectTeam = (team) => {
      const input = teamInputs.find((candidate) => candidate.value === team)
        || teamInputs.find((candidate) => candidate.value === 'match');
      if (input) input.checked = true;
    };

    const setBoardTeam = (team) => {
      if (!['svfc', 'opponent'].includes(team)) return;
      boardTeam = team;
      boardTeamButtons.forEach((button) => {
        const active = button.dataset.boardTeam === team;
        button.classList.toggle('active', active);
        button.classList.toggle('btn-primary', active);
        button.classList.toggle('btn-outline-primary', !active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    };

    const renderOutcomeChoices = (type) => {
      outcomeChoices.replaceChildren();
      const options = outcomeOptions[type] || [];
      options.forEach((label, index) => {
        const value = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
        const input = document.createElement('input');
        input.type = 'radio';
        input.className = 'btn-check';
        input.name = 'event_outcome';
        input.id = `matchEventOutcome${index}`;
        input.value = value;
        input.autocomplete = 'off';
        if (['shot', 'free_kick', 'penalty'].includes(type) && index === 0) input.required = true;
        const buttonLabel = document.createElement('label');
        buttonLabel.className = 'btn btn-outline-secondary';
        buttonLabel.htmlFor = input.id;
        buttonLabel.textContent = label;
        outcomeChoices.append(input, buttonLabel);
      });
      outcomeWrap.classList.toggle('d-none', options.length === 0);
    };

    const syncPlayerChoices = (team, selectFallback = false, playerRequired = true) => {
      let visibleInputs = [];
      playerTeamGroups.forEach((group) => {
        const visible = group.dataset.playerTeam === team;
        group.classList.toggle('d-none', !visible);
        const inputs = Array.from(group.querySelectorAll('.js-match-event-player'));
        inputs.forEach((input) => {
          input.disabled = !visible;
          input.required = false;
        });
        if (visible) visibleInputs = inputs;
      });

      if (selectFallback && visibleInputs.length === 1 && visibleInputs[0].value === 'Unknown Player') {
        visibleInputs[0].checked = true;
      }
      if (visibleInputs.length > 0) visibleInputs[0].required = playerRequired;
    };

    const renumberSubstitutionRows = () => {
      const rows = Array.from(substitutionRows.querySelectorAll('.match-event-substitution-row'));
      rows.forEach((row, index) => {
        row.querySelector('.match-event-substitution-number').textContent = `Substitution ${index + 1}`;
        row.querySelector('.match-event-substitution-remove').classList.toggle('d-none', rows.length === 1);
      });
    };

    const fillSubstitutionSelect = (select, players, previousValue = '', playerLabels = {}) => {
      select.replaceChildren();
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select player';
      select.append(placeholder);
      players.forEach((player) => {
        const option = document.createElement('option');
        option.value = player;
        option.textContent = playerLabels[player] || player;
        select.append(option);
      });
      if (players.includes(previousValue)) select.value = previousValue;
      if (players.length === 1 && players[0] === 'Unknown Player') select.value = players[0];
    };

    const syncSubstitutionRows = () => {
      const team = currentTeam() === 'opponent' ? 'opponent' : 'svfc';
      const players = substitutionPlayers[team] || { off: ['Unknown Player'], on: ['Unknown Player'] };
      const playerLabels = substitutionPlayerLabels[team] || {};
      const rows = Array.from(substitutionRows.querySelectorAll('.match-event-substitution-row'));
      const selectedPlayers = new Map();
      rows.forEach((row) => {
        const off = row.querySelector('.match-event-substitution-off');
        const on = row.querySelector('.match-event-substitution-on');
        selectedPlayers.set(off, off.value);
        selectedPlayers.set(on, on.value);
      });

      rows.forEach((row) => {
        const off = row.querySelector('.match-event-substitution-off');
        const on = row.querySelector('.match-event-substitution-on');
        const usedByOtherRows = new Set(
          Array.from(selectedPlayers.entries())
            .filter(([select, value]) => select !== off && select !== on && value !== '' && value !== 'Unknown Player')
            .map(([, value]) => value)
        );
        const offValue = selectedPlayers.get(off) || '';
        const onValue = selectedPlayers.get(on) || '';
        const availableOff = players.off.filter((player) => player === offValue || !usedByOtherRows.has(player));
        const availableOn = players.on.filter((player) => player === onValue || !usedByOtherRows.has(player));
        fillSubstitutionSelect(off, availableOff, offValue, playerLabels);
        fillSubstitutionSelect(on, availableOn, onValue, playerLabels);
      });
    };

    const addSubstitutionRow = () => {
      const row = substitutionTemplate.content.firstElementChild.cloneNode(true);
      row.querySelectorAll('select').forEach((select) => select.addEventListener('change', syncSubstitutionRows));
      row.querySelector('.match-event-substitution-remove').addEventListener('click', () => {
        row.remove();
        syncSubstitutionRows();
        renumberSubstitutionRows();
      });
      substitutionRows.append(row);
      syncSubstitutionRows();
      renumberSubstitutionRows();
    };

    const resetSubstitutionRows = () => {
      substitutionRows.replaceChildren();
      addSubstitutionRow();
    };

    const syncFields = (selectFallback = false) => {
      const type = typeInput.value;
      const team = currentTeam();
      const isState = stateTypes.includes(type);
      const showPlayer = playerTypes.includes(type);
      const isSubstitution = type === 'substitution';
      const isOwnGoal = type === 'goal' && ownGoalInput.checked;
      const playerTeam = isOwnGoal
        ? (team === 'svfc' ? 'opponent' : (team === 'opponent' ? 'svfc' : team))
        : team;

      teamWrap.classList.toggle('d-none', isState);
      playerWrap.classList.toggle('d-none', !showPlayer);
      ownGoalWrap.classList.toggle('d-none', type !== 'goal');
      if (type !== 'goal') ownGoalInput.checked = false;
      secondaryWrap.classList.add('d-none');
      substitutionsWrap.classList.toggle('d-none', !isSubstitution);
      playerLabel.textContent = isOwnGoal
        ? 'Player who scored the own goal (optional)'
        : ({ goal: 'Goalscorer', shot: 'Shooter', penalty: 'Penalty Taker', free_kick: 'Free Kick Taker' }[type] || 'Player');
      minuteInput.required = !isState && type !== 'note';
      syncPlayerChoices(playerTeam, selectFallback, !isOwnGoal);
      playerInputs.forEach((input) => {
        if (!showPlayer) input.required = false;
      });
      secondaryInput.required = false;
      substitutionsWrap.querySelectorAll('select').forEach((select) => { select.disabled = !isSubstitution; });
      if (isSubstitution) syncSubstitutionRows();
      noteInput.required = type === 'note';
      noteInput.placeholder = type === 'note' ? 'Describe what happened' : 'Optional details';
      originWrap.classList.toggle('d-none', !originTypes.includes(type));
      targetWrap.classList.toggle('d-none', !targetTypes.includes(type));
      renderOutcomeChoices(type);
    };

    modal.addEventListener('show.bs.modal', (event) => {
      const button = event.relatedTarget;
      if (!button) return;
      document.getElementById('recordMatchEventForm').reset();
      title.textContent = `Record ${button.dataset.eventLabel || 'Match Event'}`;
      typeInput.value = button.dataset.eventType || '';
      cardInput.value = button.dataset.eventCard || '';
      const requestedTeam = button.dataset.eventTeam === 'selected' ? boardTeam : (button.dataset.eventTeam || 'match');
      selectTeam(requestedTeam);
      noteInput.value = button.dataset.eventNote || '';
      originInput.value = '';
      targetInput.value = '';
      resetSubstitutionRows();
      modal.querySelectorAll('.match-event-pitch-zone, .match-event-goal-zone').forEach((zone) => zone.classList.remove('is-selected'));
      syncFields(true);
    });

    teamInputs.forEach((input) => input.addEventListener('change', () => {
      if (input.checked && input.value !== 'match') setBoardTeam(input.value);
      syncFields(true);
    }));
    ownGoalInput.addEventListener('change', () => syncFields(true));
    addSubstitutionButton.addEventListener('click', addSubstitutionRow);
    boardTeamButtons.forEach((button) => button.addEventListener('click', () => setBoardTeam(button.dataset.boardTeam || 'svfc')));
    modal.querySelectorAll('[data-event-choice]').forEach((button) => button.addEventListener('click', () => {
      const choice = button.dataset.eventChoice;
      const input = choice === 'origin' ? originInput : targetInput;
      const selector = choice === 'origin' ? '[data-event-choice="origin"]' : '[data-event-choice="target"]';
      input.value = button.dataset.value || '';
      modal.querySelectorAll(selector).forEach((zone) => zone.classList.toggle('is-selected', zone === button));
    }));
  })();

  (() => {
    const modalElement = document.getElementById('socialEventModal');
    if (!modalElement || typeof bootstrap === 'undefined') return;

    const socialModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const eventIdInput = document.getElementById('socialEventId');
    const captionInput = document.getElementById('socialEventCaption');
    const previewFrame = document.getElementById('socialEventPreview');
    const graphicDimensions = document.getElementById('socialGraphicDimensions');
    const image = document.getElementById('socialEventImage');
    const livePreview = document.getElementById('socialEventLivePreview');
    const loading = document.getElementById('socialEventLoading');
    const download = document.getElementById('socialDownloadImage');
    const status = document.getElementById('socialEventStatus');
    const facebookButton = document.getElementById('socialPostFacebook');
    const instagramButton = document.getElementById('socialPostInstagram');
    const xButton = document.getElementById('socialOpenX');
    const matchId = <?= json_encode((string)$fixtureId) ?>;
    const socialCsrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    const confirmBeforeLivePost = <?= !empty(social_publishing_preferences_load()['global']['confirm_before_live_post']) ? 'true' : 'false' ?>;
    let graphicUrl = '';
    let socialEventType = '';
    let socialCaptions = {};
    let preparedCaption = '';

    const encodeBody = (data) => Object.keys(data)
      .map((key) => `${encodeURIComponent(key)}=${encodeURIComponent(data[key])}`)
      .join('&');

    const setStatus = (message, kind = 'info') => {
      status.className = `alert alert-${kind} mt-3 mb-0`;
      status.textContent = message;
    };

    const request = async (url, payload) => {
      payload.csrf_token = socialCsrfToken;
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: encodeBody(payload),
      });
      const responseText = await response.text();
      let result = null;
      try {
        result = responseText ? JSON.parse(responseText) : null;
      } catch (error) {
        throw new Error(response.ok
          ? 'The server returned an invalid publishing response. Please try again.'
          : `Publishing request failed (${response.status}).`);
      }
      if (!response.ok || !result || !result.ok) {
        const details = result && Array.isArray(result.details) ? ` ${result.details.join(' ')}` : '';
        const summary = result && (result.summary || result.error);
        const requestError = new Error((summary || `Request failed (${response.status}).`) + details);
        requestError.responseJson = result;
        throw requestError;
      }
      return result;
    };

    const prepare = async (eventId) => {
      eventIdInput.value = eventId;
      captionInput.value = '';
      captionInput.disabled = true;
      image.classList.add('d-none');
      image.removeAttribute('src');
      livePreview.classList.add('d-none');
      livePreview.src = 'about:blank';
      loading.classList.remove('d-none');
      loading.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Generating graphic…';
      download.classList.add('disabled');
      download.setAttribute('aria-disabled', 'true');
      download.href = '#';
      graphicUrl = '';
      previewFrame.classList.remove('is-portrait');
      graphicDimensions.innerHTML = '<i class="fa-solid fa-image me-1"></i>Template-sized graphic';
      status.classList.add('d-none');
      socialModal.show();

      try {
        const result = await request('/prepare_event_share.php', { match_id: matchId, event_id: eventId });
        socialCaptions = result.captions || {};
        preparedCaption = socialCaptions.facebook || result.text || '';
        captionInput.value = preparedCaption;
        socialEventType = result.event_type || '';
        facebookButton.textContent = result.facebook_enabled === false ? 'Post to Facebook Anyway' : 'Post to Facebook';
        facebookButton.title = result.facebook_enabled === false
          ? 'This event type does not auto-publish to Facebook under the matchday strategy. Posting will be recorded as a manual override.'
          : '';
        const isPortraitGraphic = ['kickoff', 'goal', 'half_time', 'full_time', 'player_of_match'].includes(socialEventType);
        previewFrame.classList.toggle('is-portrait', isPortraitGraphic);
        graphicDimensions.innerHTML = isPortraitGraphic
          ? '<i class="fa-solid fa-image me-1"></i>Portrait 1080 × 1350 graphic'
          : '<i class="fa-solid fa-image me-1"></i>Square 1080 × 1080 graphic';
        graphicUrl = result.download_url || '';
        if (graphicUrl) {
          image.src = graphicUrl;
          image.classList.remove('d-none');
          download.href = graphicUrl;
          download.classList.remove('disabled');
          download.removeAttribute('aria-disabled');
        }
        livePreview.src = `/match_event_graphic.php?id=${encodeURIComponent(matchId)}&event_id=${encodeURIComponent(eventId)}&render=1&live_preview=1&v=${Date.now()}`;
        loading.classList.add('d-none');
        captionInput.disabled = false;
        return true;
      } catch (error) {
        loading.textContent = 'The preview could not be generated.';
        captionInput.disabled = false;
        setStatus(error.message || 'The social post could not be prepared.', 'danger');
        return false;
      }
    };

    const publish = async (platform, button) => {
      const platformKey = platform === 'Facebook' ? 'facebook' : 'instagram';
      if (captionInput.value.trim() === preparedCaption.trim() && socialCaptions[platformKey]) {
        preparedCaption = socialCaptions[platformKey];
        captionInput.value = preparedCaption;
      }
      const eventId = eventIdInput.value;
      if (!eventId || !graphicUrl) {
        setStatus('Wait for the graphic to finish generating first.', 'warning');
        return;
      }
      if (confirmBeforeLivePost && !(await window.hubConfirm(`Publish this event to ${platform} now?`, { actionLabel: 'Post', actionClass: 'btn-primary' }))) return;

      const original = button.innerHTML;
      button.disabled = true;
      button.textContent = 'Posting…';
      setStatus(`Posting to ${platform}…`, 'info');

      if (platform !== 'Facebook') {
        try {
          const result = await request('/post_event_to_instagram.php', {
            match_id: matchId,
            event_id: eventId,
            caption: captionInput.value.trim(),
          });
          setStatus(result.summary || `${platform} post completed.`, 'success');
        } catch (error) {
          setStatus(error.message || `${platform} post failed.`, 'danger');
        } finally {
          button.disabled = false;
          button.innerHTML = original;
        }
        return;
      }

      const payload = { match_id: matchId, event_id: eventId, caption: captionInput.value.trim() };
      try {
        let result;
        try {
          result = await request('/post_event_to_facebook.php', payload);
        } catch (error) {
          const json = error && error.responseJson;
          // This event type doesn't auto-publish to Facebook under the
          // matchday strategy — offer a deliberate manual override rather
          // than just failing.
          if (json && json.blocked && json.can_override) {
            const overrideMessage = (json.summary || 'Facebook publishing is disabled for this event type.')
              + '\n\nPost to Facebook Anyway? This is an exceptional manual action and will be recorded as a manual override.';
            if (await window.hubConfirm(overrideMessage, { actionLabel: 'Post anyway', actionClass: 'btn-primary' })) {
              payload.override = '1';
              result = await request('/post_event_to_facebook.php', payload);
            } else {
              throw new Error('Facebook post not sent.');
            }
          } else if (json && json.requires_confirmation) {
            if (await window.hubConfirm(json.summary || 'Publish anyway?', { actionLabel: 'Publish anyway', actionClass: 'btn-primary' })) {
              payload.confirm_burst = '1';
              result = await request('/post_event_to_facebook.php', payload);
            } else {
              throw new Error('Facebook post cancelled.');
            }
          } else {
            throw error;
          }
        }
        setStatus(result.summary || 'Facebook post completed.', 'success');
      } catch (error) {
        setStatus(error.message || 'Facebook post failed.', 'danger');
      } finally {
        button.disabled = false;
        button.innerHTML = original;
      }
    };

    document.querySelectorAll('.js-social-event').forEach((button) => {
      button.addEventListener('click', () => prepare(button.dataset.eventId || ''));
    });
    document.querySelectorAll('.js-timeline-event').forEach((item) => {
      const openShareModal = (event) => {
        if (event.target.closest('.js-social-event, form, a')) return;
        prepare(item.dataset.eventId || '');
      };
      item.addEventListener('click', openShareModal);
      item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openShareModal(event);
        }
      });
    });
    document.getElementById('socialCopyText').addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(captionInput.value);
        setStatus('Post text copied.', 'success');
      } catch (error) {
        captionInput.select();
        document.execCommand('copy');
        setStatus('Post text copied.', 'success');
      }
    });
    facebookButton.addEventListener('click', () => publish('Facebook', facebookButton));
    instagramButton.addEventListener('click', () => publish('Instagram', instagramButton));
    livePreview.addEventListener('load', () => {
      if (livePreview.src === 'about:blank') return;
      image.classList.add('d-none');
      livePreview.classList.remove('d-none');
    });
    xButton.addEventListener('click', () => {
      if (captionInput.value.trim() === preparedCaption.trim() && socialCaptions.x) {
        preparedCaption = socialCaptions.x;
        captionInput.value = preparedCaption;
      }
      const text = captionInput.value.trim();
      if (!text) {
        setStatus('Wait for the post text to finish generating first.', 'warning');
        return;
      }
      if (graphicUrl) {
        setStatus('X cannot attach the image automatically. Download the image, then add it in the X composer.', 'info');
      }
      window.open(`https://x.com/intent/tweet?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
      request('/record_manual_share.php', { match_id: matchId, fixture_id: matchId, event_id: eventIdInput.value, post_type: socialEventType || 'match_update', caption: text, image_url: `/match_graphics.php?fixture_id=${encodeURIComponent(matchId)}` }).catch(function () {});
    });

    const autoEventId = <?= json_encode($autoSocialEventId) ?>;
    if (autoEventId) prepare(autoEventId);
  })();

</script>

<?php require_once $layoutFooter; ?>
