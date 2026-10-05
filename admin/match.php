<?php
$pageStyles = ['match-fixture-tabs.css', 'player-sponsors-match.css', 'player-sponsors-match-overview-pane.css', 'player-sponsors-match-graphics.css'];
$pdoBootstrapLoaded = false;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';

$useSharedHubLayout = true;

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$action = $_GET['action'] ?? 'view';
$fixtureId = (int)($_GET['id'] ?? 0);
$requestedSeasonId = (int)($_GET['season_id'] ?? 0);
$seasonFromRequest = $requestedSeasonId > 0 ? getSeasonById($pdo, $requestedSeasonId) : null;
$errors = [];
$fixtureTab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($fixtureTab, ['overview', 'details', 'sponsorships', 'starting11'], true)) {
          $fixtureTab = 'overview';
}

if ($seasonFromRequest) {
          $seasonId = (int)$seasonFromRequest['id'];
          $season = $seasonFromRequest;
}

if ($useSharedHubLayout) {
          $pageHero = [
                    'eyebrow' => 'Fixture management',
                    'title' => $action === 'new' ? 'Add Fixture' : 'Match Fixture',
                    'subtitle' => 'Season: ' . ($season['name'] ?? 'Unknown'),
                    'actions' => [],
          ];
}

if ($useSharedHubLayout) {
          require_once __DIR__ . '/header.php';
} else {
          require_once __DIR__ . '/header.php';
}

require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/lib/match_overview.php';
require_once __DIR__ . '/lib/match_sponsor_share.php';
require_once __DIR__ . '/lib/match_checklist.php';
require_once __DIR__ . '/lib/matchday_staffing.php';
require_once __DIR__ . '/matches_lib.php';

if ($action === 'new') {
          $fixture = [
                    'id' => 0,
                    'season_id' => $seasonId,
                    'match_date' => date('Y-m-d'),
                    'kickoff_time' => '',
                    'opponent' => '',
                    'competition' => '',
                    'competition_season_id' => null,
                    'competition_stage' => '',
                    'venue' => '',
                    'is_home' => 1,
                    'status' => 'scheduled',
                    'notes' => '',
          ];
} else {
          if ($fixtureId <= 0) {
                    $fixtureId = 0;
          }
          $fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
          if (!$fixture && $action !== 'new') {
                    echo '<div><div class="alert alert-danger">Fixture not found.</div></div>';
                    require_once __DIR__ . '/footer.php';
                    exit;
          }
}

$isNew = !$fixture || (int)($fixture['id'] ?? 0) === 0;
if ($isNew && $fixtureTab === 'overview') {
          $fixtureTab = 'details';
}
$seasonId = !$isNew && !empty($fixture['season_id']) ? (int)$fixture['season_id'] : $seasonId;
$season = getSeasonById($pdo, $seasonId) ?: $season;
$fixtureRows = $isNew ? [] : getMatchSponsorshipRows($pdo, (int)$fixture['id']);
$sponsorShareRows = $isNew ? [] : match_sponsor_share_rows($pdo, (int)$fixture['id']);
$activeSponsors = getActiveSponsorsForSeason($pdo, $seasonId);
$sponsorshipTypes = getMatchSponsorshipTypes($pdo);
$sponsorshipTypesByCode = [];
foreach ($sponsorshipTypes as $sponsorshipType) {
          $sponsorshipTypesByCode[(string)$sponsorshipType['code']] = $sponsorshipType;
}
$opponents = getMatchOpponents($pdo);
$selectedOpponentId = 0;
$addedOpponentId = (int)($_GET['opponent_added'] ?? 0);
if ($addedOpponentId > 0) {
          $selectedOpponentId = $addedOpponentId;
}
if (!$isNew) {
          if ($selectedOpponentId <= 0 && !empty($fixture['opponent_id'])) {
                    $selectedOpponentId = (int)$fixture['opponent_id'];
          } elseif ($selectedOpponentId <= 0 && !empty($fixture['opponent'])) {
                    foreach ($opponents as $opponent) {
                              if (strcasecmp((string)$opponent['clubname'], (string)$fixture['opponent']) === 0) {
                                        $selectedOpponentId = (int)$opponent['id'];
                                        break;
                              }
                    }
          }
}

function match_label(array $fixture): string
{
          $opponent = (string)($fixture['opponent_name'] ?? $fixture['opponent'] ?? '');
          return ($fixture['is_home'] ? 'Home v ' : 'Away v ') . $opponent;
}

function match_label_html(array $fixture): string
{
          $opponent = (string)($fixture['opponent_name'] ?? $fixture['opponent'] ?? '');
          $prefix = $fixture['is_home'] ? 'Home v' : 'Away v';
          if (isDeletedOpponentLabel($opponent)) {
                    return '<span class="fixtures-opponent-prefix">' . h($prefix) . '</span> <span class="fixtures-opponent-deleted">Team Deleted</span>';
          }

          return h($prefix . ' ' . $opponent);
}

$competitionOptions = getMatchCompetitionSeasons($pdo, $seasonId);
$venueOptions = getMatchVenues($pdo);
$selectedCompetition = trim((string)($fixture['competition'] ?? ''));
$selectedCompetitionSeasonId = (int)($fixture['competition_season_id'] ?? 0);
$selectedVenue = trim((string)($fixture['venue'] ?? ''));
$addedCompetitionId = (int)($_GET['competition_added'] ?? 0);
$addedVenueId = (int)($_GET['venue_added'] ?? 0);
if ($addedCompetitionId > 0) {
          $addedCompetition = getMatchCompetitionById($pdo, $addedCompetitionId);
          if ($addedCompetition) {
                    $selectedCompetition = (string)$addedCompetition['name'];
          }
}
if ($addedVenueId > 0) {
          $addedVenue = getMatchVenueById($pdo, $addedVenueId);
          if ($addedVenue) {
                    $selectedVenue = (string)$addedVenue['name'];
          }
}
$venueKnown = false;
foreach ($venueOptions as $venueOption) {
          if (strcasecmp((string)$venueOption['name'], $selectedVenue) === 0) {
                    $venueKnown = true;
                    break;
          }
}
if ($selectedVenue !== '' && !$venueKnown) {
          array_unshift($venueOptions, ['id' => 0, 'name' => $selectedVenue]);
}
$scoreHomeTeam = (int)($fixture['is_home'] ?? 1) === 1
          ? 'Saltcoats Victoria'
          : (string)($fixture['opponent'] ?? 'Opponent');
$scoreAwayTeam = (int)($fixture['is_home'] ?? 1) === 1
          ? (string)($fixture['opponent'] ?? 'Opponent')
          : 'Saltcoats Victoria';
$overviewMatch = !$isNew ? matches_find_by_id(matches_load_all(), (string)$fixture['id']) : null;
$overviewEvents = $overviewMatch && isset($overviewMatch['events']) && is_array($overviewMatch['events'])
          ? matchOverviewSortedEvents($overviewMatch['events'])
          : [];
$overviewFinished = !$isNew && matchOverviewIsFinished($fixture, $overviewEvents);
$overviewStats = matchOverviewEventStats($overviewEvents);
$overviewStarters = !$isNew ? matchStarting11PrepareLineup($fixture['starting11_starters'] ?? []) : [];
$overviewSubstitutes = !$isNew ? matchStarting11PrepareLineup($fixture['starting11_substitutes'] ?? []) : [];
$checklistOpponent = !$isNew ? trim((string)($fixture['opponent'] ?? 'Opponent')) : '';
$checklistSystemItems = !$isNew
          ? match_checklist_system_items($pdo, $fixture, $overviewEvents, $overviewStarters, $sponsorShareRows, $checklistOpponent)
          : [];
$checklistItems = !$isNew ? match_checklist_build_list($pdo, (int)$fixture['id'], $checklistSystemItems) : [];
$staffingAssignments = !$isNew ? matchday_staffing_assignments($pdo, (int)$fixture['id']) : [];
$staffingSummary = matchday_staffing_summary($staffingAssignments);
$staffingPeopleOptions = !$isNew ? matchday_staffing_people_options($pdo) : [];
$staffingRoles = matchday_staffing_roles();
$staffingStatuses = matchday_staffing_statuses();
?>

<?php if ($useSharedHubLayout): ?><nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $isNew ? 'Add fixture' : h((string)($fixture['opponent'] ?? 'Fixture')) ?></span></nav><?php endif; ?>

<?php if (!$useSharedHubLayout): ?>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div>
                              <h1 class="h3 mb-1"><?= $isNew ? 'Add Fixture' : match_label_html($fixture) ?></h1>
                              <div class="text-muted">Season: <?= h($season['name'] ?? 'Unknown') ?></div>
                    </div>
                    <div class="d-flex gap-2">
                              <a href="matches.php" class="btn btn-outline-secondary">Back to Fixtures</a>
                              <?php if (!$isNew): ?>
                                        <a href="match_lineups.php?fixture_id=<?= (int)$fixture['id'] ?>&season_id=<?= (int)$seasonId ?>" class="btn btn-outline-secondary">Line-ups</a>
                                        <a href="match_graphics.php?fixture_id=<?= (int)$fixture['id'] ?>&season_id=<?= (int)$seasonId ?>" class="btn btn-brand">Match Graphics</a>
                                        <form method="post" action="match_delete.php" data-confirm="Delete this fixture? This will remove the fixture and its linked sponsorships." data-confirm-action="Delete" class="d-inline">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="fixture_id" value="<?= (int)$fixture['id'] ?>">
                                                  <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                  <button type="submit" class="btn btn-outline-danger">Delete Fixture</button>
                                        </form>
                              <?php endif; ?>
                    </div>
          </div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
          <div class="alert alert-success">Fixture saved.</div>
<?php endif; ?>
<?php if (isset($_GET['opponent_added'])): ?>
          <div class="alert alert-success">Opponent added and selected.</div>
<?php endif; ?>
<?php if (isset($_GET['competition_added'])): ?>
          <div class="alert alert-success">Competition added and selected.</div>
<?php endif; ?>
<?php if (isset($_GET['venue_added'])): ?>
          <div class="alert alert-success">Venue added and selected.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
          <div class="alert alert-success">Match sponsorship ended.</div>
<?php endif; ?>
<?php if (isset($_GET['moved'])): ?>
          <div class="alert alert-success">Match sponsorship moved.</div>
<?php endif; ?>
<?php if (isset($_GET['paid'])): ?>
          <div class="alert alert-success">Payment recorded.</div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
          <div class="alert alert-danger"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($useSharedHubLayout && !$isNew): ?>
          <?php renderFixtureTabs((int)$fixture['id'], (int)$seasonId, $fixtureTab, true); ?>
<?php endif; ?>

<div class="tab-content fixture-workspace">
          <?php if (!$isNew): ?>
                    <div class="tab-pane fade <?= $fixtureTab === 'overview' ? 'show active' : '' ?>" id="fixture-overview-pane" role="tabpanel" aria-labelledby="fixture-overview-tab" tabindex="0">
                              <?php require __DIR__ . '/match_overview_pane.php'; ?>
                    </div>
          <?php endif; ?>
          <div class="tab-pane fade <?= $fixtureTab === 'details' ? 'show active' : '' ?>" id="fixture-details-pane" role="tabpanel" aria-labelledby="fixture-details-tab" tabindex="0">
                    <?php if (!$isNew): ?>
                              <div class="card shadow-sm mb-3 fixture-panel fixture-score-card">
                                        <div class="card-body">
                                                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                                                            <div>
                                                                      <div class="fixture-panel-kicker">Scoreline</div>
                                                                      <h5 class="fixture-panel-title">Quick Score</h5>
                                                                      <div class="text-muted small">Enter either score, then use <strong>Save Changes</strong> below. A full-time score marks the fixture as played.</div>
                                                            </div>
                                                            <a href="match_graphics.php?fixture_id=<?= (int)$fixture['id'] ?>&season_id=<?= (int)$seasonId ?>" class="btn btn-outline-secondary btn-sm">Open Match Graphics</a>
                                                  </div>
                                                  <div class="row g-3 align-items-end">
                                                            <div class="col-lg-6">
                                                                      <div class="fw-semibold mb-2">Half Time</div>
                                                                      <div class="row g-2">
                                                                                <label class="col-6">
                                                                                          <span class="form-label small"><?= h($scoreHomeTeam) ?></span>
                                                                                          <input type="number" form="fixtureSaveForm" name="half_time_home_score" class="form-control" min="0" max="99" inputmode="numeric" value="<?= $fixture['half_time_home_score'] !== null ? (int)$fixture['half_time_home_score'] : '' ?>">
                                                                                </label>
                                                                                <label class="col-6">
                                                                                          <span class="form-label small"><?= h($scoreAwayTeam) ?></span>
                                                                                          <input type="number" form="fixtureSaveForm" name="half_time_away_score" class="form-control" min="0" max="99" inputmode="numeric" value="<?= $fixture['half_time_away_score'] !== null ? (int)$fixture['half_time_away_score'] : '' ?>">
                                                                                </label>
                                                                      </div>
                                                            </div>
                                                            <div class="col-lg-6">
                                                                      <div class="fw-semibold mb-2">Full Time</div>
                                                                      <div class="row g-2">
                                                                                <label class="col-6">
                                                                                          <span class="form-label small"><?= h($scoreHomeTeam) ?></span>
                                                                                          <input type="number" form="fixtureSaveForm" name="full_time_home_score" class="form-control" min="0" max="99" inputmode="numeric" value="<?= $fixture['full_time_home_score'] !== null ? (int)$fixture['full_time_home_score'] : '' ?>">
                                                                                </label>
                                                                                <label class="col-6">
                                                                                          <span class="form-label small"><?= h($scoreAwayTeam) ?></span>
                                                                                          <input type="number" form="fixtureSaveForm" name="full_time_away_score" class="form-control" min="0" max="99" inputmode="numeric" value="<?= $fixture['full_time_away_score'] !== null ? (int)$fixture['full_time_away_score'] : '' ?>">
                                                                                </label>
                                                                      </div>
                                                            </div>
                                                  </div>
                                        </div>
                              </div>
                    <?php endif; ?>
                    <div class="card shadow-sm mb-3 fixture-panel fixture-details-card">
                              <div class="card-body">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                                  <div>
                                                            <div class="fixture-panel-kicker">Fixture setup</div>
                                                            <h5 class="fixture-panel-title"><?= $isNew ? 'Create Fixture' : 'Fixture Details' ?></h5>
                                                  </div>
                                                  <?php if (!$isNew): ?>
                                                            <span class="badge text-bg-light"><?= h(ucfirst((string)$fixture['status'])) ?></span>
                                                  <?php endif; ?>
                                        </div>
                                        <form method="post" action="match_save.php" id="fixtureSaveForm">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                  <input type="hidden" name="fixture_id" value="<?= (int)($fixture['id'] ?? 0) ?>">
                                                  <div class="row g-3">
                                                            <div class="col-md-6">
                                                                      <label class="form-label">Match Date</label>
                                                                      <input type="date" name="match_date" class="form-control" value="<?= h((string)$fixture['match_date']) ?>" required>
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <label class="form-label">Kickoff Time</label>
                                                                      <input type="time" name="kickoff_time" class="form-control" value="<?= h((string)($fixture['kickoff_time'] ?? '')) ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                                                <label class="form-label mb-0">Competition</label>
                                                                                <a class="btn btn-link btn-sm p-0 text-decoration-none" href="competitions.php">Manage season competitions</a>
                                                                      </div>
                                                                      <input type="hidden" name="competition" value="<?= h($selectedCompetition) ?>">
                                                                      <select name="competition_season_id" class="form-select">
                                                                                <option value="">Select a competition for <?= h((string)($season['name'] ?? 'this season')) ?></option>
                                                                                <?php if ($selectedCompetition !== '' && $selectedCompetitionSeasonId <= 0): ?>
                                                                                          <option value="" selected><?= h($selectedCompetition) ?> (legacy saved value)</option>
                                                                                <?php endif; ?>
                                                                                <?php foreach ($competitionOptions as $competition): ?>
                                                                                          <option value="<?= (int)$competition['id'] ?>" <?= (int)$competition['id'] === $selectedCompetitionSeasonId ? 'selected' : '' ?>><?= h((string)$competition['display_title']) ?></option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                                      <?php if ($competitionOptions === []): ?>
                                                                                <div class="form-text">No competitions have been assigned to this season yet.</div>
                                                                      <?php endif; ?>
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <label class="form-label">Round / stage</label>
                                                                      <input type="text" name="competition_stage" class="form-control" maxlength="100" value="<?= h((string)($fixture['competition_stage'] ?? '')) ?>" placeholder="e.g. Second Round or Semi-final">
                                                                      <div class="form-text">Optional. Use this for cup rounds or group stages.</div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                                                <label class="form-label mb-0">Venue</label>
                                                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#addVenueModal">Add new</button>
                                                                      </div>
                                                                      <select name="venue" class="form-select">
                                                                                <option value="">Select venue</option>
                                                                                <?php foreach ($venueOptions as $venue): ?>
                                                                                          <option value="<?= h((string)$venue['name']) ?>" <?= strcasecmp((string)$venue['name'], $selectedVenue) === 0 ? 'selected' : '' ?>><?= h((string)$venue['name']) ?></option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                            </div>
                                                            <div class="col-md-12">
                                                                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                                                <div class="d-flex align-items-center gap-2">
                                                                                          <label for="opponentSearch" class="form-label mb-0">Opponent</label>
                                                                                </div>
                                                                      </div>
                                                                      <?php
                                                                      $selectedOpponentName = '';
                                                                      foreach ($opponents as $opponent) {
                                                                                if ($selectedOpponentId === (int)$opponent['id']) {
                                                                                          $selectedOpponentName = (string)$opponent['clubname'];
                                                                                          break;
                                                                                }
                                                                      }
                                                                      ?>
                                                                      <div class="position-relative" id="opponentSearchWrap">
                                                                                <input type="text" id="opponentSearch" name="opponent" class="form-control" value="<?= h($selectedOpponentName) ?>" placeholder="Start typing a team name…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="opponentSearchResults" required>
                                                                                <input type="hidden" id="opponentId" name="opponent_id" value="<?= $selectedOpponentId > 0 ? (int)$selectedOpponentId : '' ?>">
                                                                                <div id="opponentSearchResults" class="list-group position-absolute top-100 start-0 end-0 shadow-sm d-none match-opponent-search-results" role="listbox"></div>
                                                                      </div>
                                                                      <div class="form-text">Search by club name or abbreviation.</div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <label class="form-label d-block">Home / Away</label>
                                                                      <div class="btn-group fixture-toggle-group w-100" role="group" aria-label="Home or Away">
                                                                                <input type="radio" class="btn-check" name="is_home" id="fixture-home" value="1" autocomplete="off" <?= (int)$fixture['is_home'] === 1 ? 'checked' : '' ?>>
                                                                                <label class="btn btn-outline-secondary fixture-toggle-btn fixture-toggle-home" for="fixture-home">Home</label>

                                                                                <input type="radio" class="btn-check" name="is_home" id="fixture-away" value="0" autocomplete="off" <?= (int)$fixture['is_home'] === 0 ? 'checked' : '' ?>>
                                                                                <label class="btn btn-outline-secondary fixture-toggle-btn fixture-toggle-away" for="fixture-away">Away</label>
                                                                      </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                      <label class="form-label">Status</label>
                                                                      <select name="status" class="form-select">
                                                                                <?php foreach (['scheduled' => 'Scheduled', 'played' => 'Played', 'postponed' => 'Postponed', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                                                                                          <option value="<?= h($value) ?>" <?= (string)$fixture['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                            </div>
                                                            <div class="col-md-12">
                                                                      <label class="form-label">Notes</label>
                                                                      <textarea name="notes" class="form-control" rows="3"><?= h((string)($fixture['notes'] ?? '')) ?></textarea>
                                                            </div>
                                                  </div>
                                        </form>
                                        <?php if (!$isNew): ?>
                                        <?php require_once __DIR__ . '/lib/member_matches.php'; ensureMemberMatchSchema($pdo); ?>
                                        <div class="row g-2 align-items-end mt-1">
                                                  <div class="col-12">
                                                            <label class="form-label">VEO footage link</label>
                                                            <input type="url" form="fixtureSaveForm" name="veo_url" class="form-control" placeholder="https://app.veo.co/matches/..." value="<?= h((string)($fixture['veo_url'] ?? '')) ?>">
                                                            <div class="form-text">Shown to season ticket holders on this match's summary page.</div>
                                                  </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mt-3 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                                  <div>
                                                            <?php if (!$isNew): ?>
                                                                      <form method="post" action="match_delete.php" data-confirm="Delete this fixture? This will remove the fixture and its linked sponsorships." data-confirm-action="Delete" class="m-0">
                                                                                <?= csrf_field() ?>
                                                                                <input type="hidden" name="fixture_id" value="<?= (int)$fixture['id'] ?>">
                                                                                <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                                                <button type="submit" class="btn btn-outline-danger">Delete Fixture</button>
                                                                      </form>
                                                            <?php endif; ?>
                                                  </div>
                                                  <button type="submit" form="fixtureSaveForm" class="btn btn-brand ms-auto"><?= $isNew ? 'Create Fixture' : 'Save Changes' ?></button>
                                        </div>
                              </div>
                    </div>
          </div>

          <div class="tab-pane fade <?= $fixtureTab === 'sponsorships' ? 'show active' : '' ?>" id="fixture-sponsorships-pane" role="tabpanel" aria-labelledby="fixture-sponsorships-tab" tabindex="0">
                    <div class="card shadow-sm fixture-panel fixture-sponsorship-card">
                              <div class="card-body">
                                        <div class="fixture-sponsorships-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                                  <div>
                                                            <div class="fixture-panel-kicker">Commercial</div>
                                                            <h5 class="fixture-panel-title">Sponsorships</h5>
                                                            <?php if (!$isNew): ?>
                                                                      <div class="small text-muted">Current sponsorships for this fixture.</div>
                                                            <?php endif; ?>
                                                  </div>
                                                  <?php if (!$isNew): ?>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                      <?php if ($sponsorShareRows !== []): ?>
                                                                                <button type="button" class="btn btn-outline-primary" id="openSponsorShoutout" data-bs-toggle="modal" data-bs-target="#sponsorShoutoutModal">
                                                                                          <i class="fa-solid fa-share-nodes me-1" aria-hidden="true"></i>Post Match Sponsors
                                                                                </button>
                                                                      <?php endif; ?>
                                                                      <button type="button" class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#assignmentsModal">
                                                                                Add
                                                                      </button>
                                                            </div>
                                                  <?php endif; ?>
                                        </div>

                                        <?php if ($isNew): ?>
                                                  <p class="text-muted mb-0">Save the fixture first to assign sponsors.</p>
                                        <?php else: ?>
                                                  <div class="table-responsive">
                                                            <table class="fixture-sponsorship-table table table-sm align-middle hub-data-table">
                                                                      <thead>
                                                                                <tr>
                                                                                          <th>Role</th>
                                                                                          <th>Sponsor</th>
                                                                                          <th>Amount</th>
                                                                                          <th>Payment</th>
                                                                                          <th class="text-center">Actions</th>
                                                                                </tr>
                                                                      </thead>
                                                                      <tbody>
                                                                                <?php foreach ($fixtureRows as $row): ?>
                                                                                          <?php
                                                                                         $amount = (float)$row['amount'];
                                                                                         $paidTotal = (float)$row['paid_total'];
                                                                                          $isComplimentary = (int)($row['is_complimentary'] ?? 0) === 1;
                                                                                          $outstanding = $amount - $paidTotal;
                                                                                          $isPaid = !$isComplimentary && ($paidTotal + 0.0001) >= $amount;
                                                                                          $paidIcon = $isComplimentary
                                                                                                    ? '<span class="badge bg-info text-dark">Complimentary</span>'
                                                                                                    : ($isPaid
                                                                                                    ? '<i class="fa-solid fa-circle-check text-success" title="Paid" aria-label="Paid"></i>'
                                                                                                    : '<i class="fa-solid fa-circle-xmark text-danger" title="Unpaid" aria-label="Unpaid"></i>');
                                                                                          $roleValue = (string)$row['sponsorship_role'];
                                                                                          $roleLabel = (string)($sponsorshipTypesByCode[$roleValue]['name'] ?? strtoupper(str_replace('_', ' ', $roleValue)));
                                                                                          $sponsorLogo = trim((string)($row['sponsor_logo'] ?? ''));
                                                                                          $sponsorWhiteLogo = trim((string)($row['sponsor_white_logo'] ?? ''));
                                                                                          $sponsorLogoUrl = $sponsorLogo !== '' ? '/uploads/sponsors/' . rawurlencode(basename($sponsorLogo)) : '';
                                                                                          $sponsorWhiteLogoUrl = $sponsorWhiteLogo !== '' ? '/uploads/sponsors/' . rawurlencode(basename($sponsorWhiteLogo)) : '';
                                                                                          ?>
                                                                                          <tr>
                                                                                                    <td><?= h($roleLabel) ?></td>
                                                                                                    <td>
                                                                                                              <button
                                                                                                                        type="button"
                                                                                                                        class="btn btn-link p-0 fw-semibold text-decoration-none sponsor-detail-trigger"
                                                                                                                        data-bs-toggle="modal"
                                                                                                                        data-bs-target="#sponsorDetailsModal"
                                                                                                                        data-sponsor-id="<?= (int)$row['sponsor_id'] ?>"
                                                                                                                        data-sponsor-name="<?= h((string)$row['sponsor_name']) ?>"
                                                                                                                        data-sponsor-logo="<?= h($sponsorLogoUrl) ?>"
                                                                                                                        data-sponsor-white-logo="<?= h($sponsorWhiteLogoUrl) ?>"
                                                                                                                        data-sponsor-website="<?= h((string)($row['sponsor_website_url'] ?? '')) ?>"
                                                                                                                        data-sponsor-facebook="<?= h((string)($row['sponsor_facebook_page_url'] ?? '')) ?>"
                                                                                                                        data-sponsor-instagram="<?= h((string)($row['sponsor_instagram_url'] ?? '')) ?>"
                                                                                                                        data-sponsor-twitter="<?= h((string)($row['sponsor_twitter_url'] ?? '')) ?>"
                                                                                                                        data-sponsor-phone="<?= h((string)($row['sponsor_contact_phone'] ?? '')) ?>"
                                                                                                                        data-sponsor-email="<?= h((string)($row['sponsor_contact_email'] ?? '')) ?>"
                                                                                                                        data-sponsor-address="<?= h((string)($row['sponsor_address'] ?? '')) ?>"
                                                                                                                        data-sponsor-business="<?= (int)($row['sponsor_is_business'] ?? 0) === 1 ? '1' : '0' ?>"
                                                                                                                        data-sponsor-main="<?= (int)($row['sponsor_is_main_sponsor'] ?? 0) === 1 ? '1' : '0' ?>"
                                                                                                                        data-sponsor-sort-order="<?= (int)($row['sponsor_sort_order'] ?? 0) ?>"
                                                                                                                        data-sponsor-active="<?= (int)($row['sponsor_is_active'] ?? 1) === 1 ? '1' : '0' ?>"
                                                                                                                        data-sponsor-role="<?= h($roleLabel) ?>"
                                                                                                              ><?= h($row['sponsor_name']) ?></button>
                                                                                                    </td>
                                                                                                    <td><?= $isComplimentary ? '<span class="badge bg-info text-dark">Complimentary</span>' : gbp((float)$row['amount']) ?></td>
                                                                                                    <td class="text-center sponsorship-paid-state"><?= $paidIcon ?></td>
                                                                                                    <td class="text-nowrap text-center">
                                                                                                              <button
                                                                                                                        type="button"
                                                                                                                        class="btn btn-sm btn-outline-secondary sponsorship-action-btn"
                                                                                                                        title="Edit"
                                                                                                                        aria-label="Edit"
                                                                                                                        data-bs-toggle="modal"
                                                                                                                        data-bs-target="#editSponsorshipModal"
                                                                                                                        data-sponsorship-id="<?= (int)$row['id'] ?>"
                                                                                                                        data-sponsor-id="<?= (int)$row['sponsor_id'] ?>"
                                                                                                                        data-sponsor-name="<?= h((string)$row['sponsor_name']) ?>"
                                                                                                                        data-role="<?= h($roleValue) ?>"
                                                                                                                        data-role-label="<?= h($roleLabel) ?>"
                                                                                                                        data-amount="<?= h((string)$row['amount']) ?>"
                                                                                                                        data-default-amount="<?= h((string)calculateMatchSponsorshipAmount($pdo, $seasonId, $roleValue, (int)$fixture['is_home'] === 1)) ?>"
                                                                                                                        data-notes="<?= h((string)($row['notes'] ?? '')) ?>"
                                                                                                                        data-paid="<?= $isPaid ? '1' : '0' ?>"
                                                                                                                        data-complimentary="<?= $isComplimentary ? '1' : '0' ?>"
                                                                                                              >
                                                                                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                                                                              </button>
                                                                                                              <?php if ($isComplimentary): ?>
                                                                                                                        <span class="badge bg-info text-dark me-1">Free promotion</span>
                                                                                                              <?php elseif ((float)$row['paid_total'] > 0): ?>
                                                                                                                        <form method="post" action="match_sponsorship_unpay.php" class="d-inline">
                                                                                                                                  <?= csrf_field() ?>
                                                                                                                                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                                                                                                  <button type="submit" class="btn btn-sm btn-danger sponsorship-action-btn" title="Unpay" aria-label="Unpay">
                                                                                                                                            <i class="fa-solid fa-circle-xmark"></i>
                                                                                                                                  </button>
                                                                                                                        </form>
                                                                                                             <?php elseif ($outstanding > 0): ?>
                                                                                                                        <form method="post" action="match_sponsorship_paid.php" class="d-inline">
                                                                                                                                 <?= csrf_field() ?>
                                                                                                                                 <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                                                                                                 <button type="submit" class="btn btn-sm btn-success sponsorship-action-btn" title="Mark as Paid" aria-label="Mark as Paid">
                                                                                                                                           <i class="fa-solid fa-circle-check"></i>
                                                                                                                                 </button>
                                                                                                                       </form>
                                                                                                             <?php else: ?>
                                                                                                                        <span class="badge bg-brand-paid me-1">Paid</span>
                                                                                                             <?php endif; ?>
                                                                                                              <?php if (!$isComplimentary && $outstanding > 0 && !empty($row['agreement_id'])): ?>
                                                                                                              <a href="sponsorship_agreement.php?id=<?= (int)$row['agreement_id'] ?>#stripePaymentCard" class="btn btn-sm btn-outline-primary sponsorship-action-btn" title="Send Stripe payment link" aria-label="Send Stripe payment link">
                                                                                                                        <i class="fa-brands fa-stripe-s"></i>
                                                                                                              </a>
                                                                                                              <?php endif; ?>
                                                                                                              <a href="match_sponsorship_move.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary sponsorship-action-btn" title="Move" aria-label="Move">
                                                                                                                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                                                                                                              </a>
                                                                                                              <a href="match_sponsorship_delete.php?id=<?= (int)$row['id'] ?>&match_id=<?= (int)$fixture['id'] ?>" class="btn btn-sm btn-outline-danger sponsorship-action-btn" title="End" aria-label="End">
                                                                                                                        <i class="fa-solid fa-trash"></i>
                                                                                                              </a>
                                                                                                    </td>
                                                                                          </tr>
                                                                                          <?php if (!empty($row['notes'])): ?>
                                                                                                    <tr class="table-light sponsorship-notes-row">
                                                                                                              <td colspan="5" class="small text-muted">Notes: <?= h((string)$row['notes']) ?></td>
                                                                                                    </tr>
                                                                                          <?php endif; ?>
                                                                                <?php endforeach; ?>
                                                                                <?php if (!$fixtureRows): ?>
                                                                                          <tr class="sponsorship-empty-row">
                                                                                                    <td colspan="5" class="text-center text-muted">No sponsorships assigned yet.</td>
                                                                                          </tr>
                                                                                <?php endif; ?>
                                                                      </tbody>
                                                            </table>
                                                  </div>
                                        <?php endif; ?>
                              </div>
                    </div>
          </div>
          <?php if (!$isNew): ?>
                    <div class="tab-pane fade <?= $fixtureTab === 'starting11' ? 'show active' : '' ?>" id="fixture-starting11-pane" role="tabpanel" aria-labelledby="fixture-starting11-tab" tabindex="0">
                              <div class="fixture-starting11-shell">
                                        <div class="fixture-starting11-toolbar">
                                                  <div>
                                                            <div class="fixture-panel-kicker">Team sheet</div>
                                                            <h2 class="fixture-panel-title">Line-ups</h2>
                                                  </div>
                                                  <a class="btn btn-outline-secondary btn-sm" href="match_lineups.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>">
                                                            <i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i>Open full editor
                                                  </a>
                                        </div>
                                        <iframe
                                                  class="fixture-starting11-frame"
                                                  src="match_lineups.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>&amp;embedded=1"
                                                  title="Line-ups editor"
                                                  loading="lazy"
                                        ></iframe>
                              </div>
                    </div>
          <?php endif; ?>
</div>

<div class="modal fade" id="addOpponentModal" tabindex="-1" aria-labelledby="addOpponentModalLabel" aria-hidden="true">
          <div class="modal-dialog">
                    <div class="modal-content">
                              <form method="post" action="opponent_quick_add.php" enctype="multipart/form-data" id="quickOpponentForm">
                                        <div class="modal-header">
                                                  <h5 class="modal-title" id="addOpponentModalLabel">Team not found</h5>
                                                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="return_to_fixture_id" value="<?= (int)($fixture['id'] ?? 0) ?>">
                                                  <input type="hidden" name="return_to_season_id" value="<?= (int)$seasonId ?>">
                                                  <input type="hidden" name="return_action" value="<?= h($action) ?>">

                                                  <div id="quickOpponentConfirm">
                                                            <p class="mb-1">There is no team called <strong id="missingOpponentName"></strong> in the database.</p>
                                                            <p class="text-muted mb-0">Would you like to add it?</p>
                                                  </div>
                                                  <div id="quickOpponentDetails" class="d-none">
                                                  <div class="mb-3">
                                                            <label for="quickOpponentClubName" class="form-label">Club Name</label>
                                                            <input type="text" class="form-control" id="quickOpponentClubName" name="clubname" required>
                                                  </div>

                                                  <div class="mb-3">
                                                            <label for="quickOpponentAbbreviation" class="form-label">Abbreviation</label>
                                                            <input type="text" class="form-control text-uppercase" id="quickOpponentAbbreviation" name="abbreviation" maxlength="16" required>
                                                  </div>

                                                  <div class="mb-3">
                                                            <label for="quickOpponentGroundLocation" class="form-label">Ground Location</label>
                                                            <textarea class="form-control" id="quickOpponentGroundLocation" name="ground_location" rows="3"></textarea>
                                                  </div>

                                                  <div class="mb-0">
                                                            <label for="quickOpponentLogo" class="form-label">Upload club badge</label>
                                                            <input type="file" class="form-control" id="quickOpponentLogo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                                                            <div class="form-text">PNG, JPG, GIF or WebP, up to 5MB. You can leave this blank if no badge is available.</div>
                                                  </div>
                                                  </div>
                                        </div>
                                        <div class="modal-footer">
                                                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="rejectOpponentButton">No, don't add</button>
                                                  <button type="button" class="btn btn-brand" id="acceptOpponentButton">Yes, add team</button>
                                                  <button type="submit" class="btn btn-brand d-none" id="saveOpponentButton">Save team</button>
                                        </div>
                              </form>
                    </div>
          </div>
</div>

<?php
hub_render_quick_add_modal(
    'addCompetitionModal',
    'Add Competition',
    'competition_quick_add.php',
    'quickCompetitionName',
    'name',
    'Competition Name',
    [
        'return_to_fixture_id' => (int) ($fixture['id'] ?? 0),
        'return_to_season_id' => (int) $seasonId,
        'return_action' => $action,
    ],
    'Save Competition'
);
hub_render_quick_add_modal(
    'addVenueModal',
    'Add Venue',
    'venue_quick_add.php',
    'quickVenueName',
    'name',
    'Venue Name',
    [
        'return_to_fixture_id' => (int) ($fixture['id'] ?? 0),
        'return_to_season_id' => (int) $seasonId,
        'return_action' => $action,
    ],
    'Save Venue'
);
?>

<?php if (!$useSharedHubLayout): ?>
<div class="card shadow-sm">
          <div class="card-body">
                    <div class="fixture-sponsorships-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                              <div>
                                        <h5 class="card-title mb-0">Sponsorships</h5>
                                        <?php if (!$isNew): ?>
                                                  <div class="small text-muted">Current sponsorships for this fixture.</div>
                                        <?php endif; ?>
                              </div>
                              <?php if (!$isNew): ?>
                                        <button type="button" class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#assignmentsModal">
                                                  Add
                                        </button>
                              <?php endif; ?>
                    </div>

                    <?php if ($isNew): ?>
                              <p class="text-muted mb-0">Save the fixture first to assign sponsors.</p>
                    <?php else: ?>
                              <div class="table-responsive">
                                        <table class="fixture-sponsorship-table table table-sm align-middle hub-data-table">
                                                  <thead>
                                                            <tr>
                                                                      <th>Role</th>
                                                                      <th>Sponsor</th>
                                                                      <th>Amount</th>
                                                                      <th>Payment</th>
                                                                      <th class="text-center">Actions</th>
                                                            </tr>
                                                  </thead>
                                                  <tbody>
                                                            <?php foreach ($fixtureRows as $row): ?>
                                                                      <?php
                                                                     $amount = (float)$row['amount'];
                                                                     $paidTotal = (float)$row['paid_total'];
                                                                      $isComplimentary = (int)($row['is_complimentary'] ?? 0) === 1;
                                                                      $outstanding = $amount - $paidTotal;
                                                                      $isPaid = !$isComplimentary && ($paidTotal + 0.0001) >= $amount;
                                                                      $paidIcon = $isComplimentary
                                                                                ? '<span class="badge bg-info text-dark">Complimentary</span>'
                                                                                : ($isPaid
                                                                                ? '<i class="fa-solid fa-circle-check text-success" title="Paid" aria-label="Paid"></i>'
                                                                                : '<i class="fa-solid fa-circle-xmark text-danger" title="Unpaid" aria-label="Unpaid"></i>');
                                                                      $roleValue = (string)$row['sponsorship_role'];
                                                                      $roleLabel = (string)($sponsorshipTypesByCode[$roleValue]['name'] ?? strtoupper(str_replace('_', ' ', $roleValue)));
                                                                      $sponsorLogo = trim((string)($row['sponsor_logo'] ?? ''));
                                                                      $sponsorWhiteLogo = trim((string)($row['sponsor_white_logo'] ?? ''));
                                                                      $sponsorLogoUrl = $sponsorLogo !== '' ? '/uploads/sponsors/' . rawurlencode(basename($sponsorLogo)) : '';
                                                                      $sponsorWhiteLogoUrl = $sponsorWhiteLogo !== '' ? '/uploads/sponsors/' . rawurlencode(basename($sponsorWhiteLogo)) : '';
                                                                      ?>
                                                                      <tr>
                                                                                <td><?= h($roleLabel) ?></td>
                                                                                <td>
                                                                                          <button
                                                                                                    type="button"
                                                                                                    class="btn btn-link p-0 fw-semibold text-decoration-none sponsor-detail-trigger"
                                                                                                    data-bs-toggle="modal"
                                                                                                    data-bs-target="#sponsorDetailsModal"
                                                                                                    data-sponsor-id="<?= (int)$row['sponsor_id'] ?>"
                                                                                                    data-sponsor-name="<?= h((string)$row['sponsor_name']) ?>"
                                                                                                    data-sponsor-logo="<?= h($sponsorLogoUrl) ?>"
                                                                                                    data-sponsor-white-logo="<?= h($sponsorWhiteLogoUrl) ?>"
                                                                                                    data-sponsor-website="<?= h((string)($row['sponsor_website_url'] ?? '')) ?>"
                                                                                                    data-sponsor-facebook="<?= h((string)($row['sponsor_facebook_page_url'] ?? '')) ?>"
                                                                                                    data-sponsor-instagram="<?= h((string)($row['sponsor_instagram_url'] ?? '')) ?>"
                                                                                                    data-sponsor-twitter="<?= h((string)($row['sponsor_twitter_url'] ?? '')) ?>"
                                                                                                    data-sponsor-phone="<?= h((string)($row['sponsor_contact_phone'] ?? '')) ?>"
                                                                                                    data-sponsor-email="<?= h((string)($row['sponsor_contact_email'] ?? '')) ?>"
                                                                                                    data-sponsor-address="<?= h((string)($row['sponsor_address'] ?? '')) ?>"
                                                                                                    data-sponsor-business="<?= (int)($row['sponsor_is_business'] ?? 0) === 1 ? '1' : '0' ?>"
                                                                                                    data-sponsor-main="<?= (int)($row['sponsor_is_main_sponsor'] ?? 0) === 1 ? '1' : '0' ?>"
                                                                                                    data-sponsor-sort-order="<?= (int)($row['sponsor_sort_order'] ?? 0) ?>"
                                                                                                    data-sponsor-active="<?= (int)($row['sponsor_is_active'] ?? 1) === 1 ? '1' : '0' ?>"
                                                                                                    data-sponsor-role="<?= h($roleLabel) ?>"
                                                                                          ><?= h($row['sponsor_name']) ?></button>
                                                                                </td>
                                                                                <td><?= $isComplimentary ? '<span class="badge bg-info text-dark">Complimentary</span>' : gbp((float)$row['amount']) ?></td>
                                                                                <td class="text-center sponsorship-paid-state"><?= $paidIcon ?></td>
                                                                                <td class="text-nowrap text-center">
                                                                                          <button
                                                                                                    type="button"
                                                                                                    class="btn btn-sm btn-outline-secondary sponsorship-action-btn"
                                                                                                    title="Edit"
                                                                                                    aria-label="Edit"
                                                                                                    data-bs-toggle="modal"
                                                                                                    data-bs-target="#editSponsorshipModal"
                                                                                                    data-sponsorship-id="<?= (int)$row['id'] ?>"
                                                                                                    data-sponsor-id="<?= (int)$row['sponsor_id'] ?>"
                                                                                                    data-sponsor-name="<?= h((string)$row['sponsor_name']) ?>"
                                                                                                    data-role="<?= h($roleValue) ?>"
                                                                                                    data-role-label="<?= h($roleLabel) ?>"
                                                                                                    data-amount="<?= h((string)$row['amount']) ?>"
                                                                                                    data-default-amount="<?= h((string)calculateMatchSponsorshipAmount($pdo, $seasonId, $roleValue, (int)$fixture['is_home'] === 1)) ?>"
                                                                                                    data-notes="<?= h((string)($row['notes'] ?? '')) ?>"
                                                                                                    data-paid="<?= $isPaid ? '1' : '0' ?>"
                                                                                                    data-complimentary="<?= $isComplimentary ? '1' : '0' ?>"
                                                                                          >
                                                                                                    <i class="fa-regular fa-pen-to-square"></i>
                                                                                          </button>
                                                                                          <?php if ($isComplimentary): ?>
                                                                                                    <span class="badge bg-info text-dark me-1">Free promotion</span>
                                                                                          <?php elseif ((float)$row['paid_total'] > 0): ?>
                                                                                                    <form method="post" action="match_sponsorship_unpay.php" class="d-inline">
                                                                                                              <?= csrf_field() ?>
                                                                                                              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                                                                              <button type="submit" class="btn btn-sm btn-danger sponsorship-action-btn" title="Unpay" aria-label="Unpay">
                                                                                                                        <i class="fa-solid fa-circle-xmark"></i>
                                                                                                              </button>
                                                                                                    </form>
                                                                                         <?php elseif ($outstanding > 0): ?>
                                                                                                    <form method="post" action="match_sponsorship_paid.php" class="d-inline">
                                                                                                             <?= csrf_field() ?>
                                                                                                             <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                                                                             <button type="submit" class="btn btn-sm btn-success sponsorship-action-btn" title="Mark as Paid" aria-label="Mark as Paid">
                                                                                                                       <i class="fa-solid fa-circle-check"></i>
                                                                                                             </button>
                                                                                                   </form>
                                                                                         <?php else: ?>
                                                                                                    <span class="badge bg-brand-paid me-1">Paid</span>
                                                                                         <?php endif; ?>
                                                                                          <?php if (!$isComplimentary && $outstanding > 0 && !empty($row['agreement_id'])): ?>
                                                                                          <a href="sponsorship_agreement.php?id=<?= (int)$row['agreement_id'] ?>#stripePaymentCard" class="btn btn-sm btn-outline-primary sponsorship-action-btn" title="Send Stripe payment link" aria-label="Send Stripe payment link">
                                                                                                    <i class="fa-brands fa-stripe-s"></i>
                                                                                          </a>
                                                                                          <?php endif; ?>
                                                                                          <a href="match_sponsorship_move.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary sponsorship-action-btn" title="Move" aria-label="Move">
                                                                                                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                                                                                          </a>
                                                                                          <a href="match_sponsorship_delete.php?id=<?= (int)$row['id'] ?>&match_id=<?= (int)$fixture['id'] ?>" class="btn btn-sm btn-outline-danger sponsorship-action-btn" title="End" aria-label="End">
                                                                                                    <i class="fa-solid fa-trash"></i>
                                                                                          </a>
                                                                                </td>
                                                                      </tr>
                                                                      <?php if (!empty($row['notes'])): ?>
                                                                                <tr class="table-light sponsorship-notes-row">
                                                                                          <td colspan="5" class="small text-muted">Notes: <?= h((string)$row['notes']) ?></td>
                                                                                </tr>
                                                                      <?php endif; ?>
                                                            <?php endforeach; ?>
                                                            <?php if (!$fixtureRows): ?>
                                                                      <tr class="sponsorship-empty-row">
                                                                                <td colspan="5" class="text-center text-muted">No sponsorships assigned yet.</td>
                                                                      </tr>
                                                            <?php endif; ?>
                                                  </tbody>
                                        </table>
                              </div>
                    <?php endif; ?>
          </div>
</div>
<?php endif; ?>

<?php if (!$isNew): ?>
          <div class="modal fade" id="sponsorDetailsModal" tabindex="-1" aria-labelledby="sponsorDetailsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                              <div class="modal-content">
                                        <div class="modal-header match-modal-header">
                                                  <div class="match-modal-header__title">
                                                            <h5 class="modal-title mb-1" id="sponsorDetailsModalLabel">Sponsor Details</h5>
                                                            <div class="text-muted small" id="sponsorDetailsRole">Fixture sponsor</div>
                                                  </div>
                                                  <div class="match-modal-header__actions">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" id="sponsorDetailsEditButton">
                                                                      <i class="fa-regular fa-pen-to-square me-1" aria-hidden="true"></i>Edit sponsor
                                                            </button>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                        </div>
                                        <div class="modal-body">
                                                  <div class="alert alert-danger d-none" id="sponsorDetailsError"></div>
                                                  <div class="sponsor-details-modal" id="sponsorDetailsView">
                                                            <div class="sponsor-details-modal__logos" id="sponsorDetailsLogos"></div>
                                                            <div class="sponsor-details-modal__content">
                                                                      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                                                <h3 class="h5 mb-0" id="sponsorDetailsName">Sponsor</h3>
                                                                                <span class="badge text-bg-light" id="sponsorDetailsBusiness" hidden>Business</span>
                                                                                <span class="badge text-bg-primary" id="sponsorDetailsMain" hidden>Main Sponsor</span>
                                                                                <span class="badge text-bg-secondary" id="sponsorDetailsInactive" hidden>Inactive</span>
                                                                      </div>
                                                                      <dl class="sponsor-details-list mb-0">
                                                                                <div id="sponsorDetailsWebsiteRow" hidden>
                                                                                          <dt>Website</dt>
                                                                                          <dd><a id="sponsorDetailsWebsite" href="#" target="_blank" rel="noopener"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsFacebookRow" hidden>
                                                                                          <dt>Facebook</dt>
                                                                                          <dd><a id="sponsorDetailsFacebook" href="#" target="_blank" rel="noopener"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsInstagramRow" hidden>
                                                                                          <dt>Instagram</dt>
                                                                                          <dd><a id="sponsorDetailsInstagram" href="#" target="_blank" rel="noopener"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsTwitterRow" hidden>
                                                                                          <dt>X / Twitter</dt>
                                                                                          <dd><a id="sponsorDetailsTwitter" href="#" target="_blank" rel="noopener"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsPhoneRow" hidden>
                                                                                          <dt>Phone</dt>
                                                                                          <dd><a id="sponsorDetailsPhone" href="#"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsEmailRow" hidden>
                                                                                          <dt>Email</dt>
                                                                                          <dd><a id="sponsorDetailsEmail" href="#"></a></dd>
                                                                                </div>
                                                                                <div id="sponsorDetailsAddressRow" hidden>
                                                                                          <dt>Address</dt>
                                                                                          <dd id="sponsorDetailsAddress"></dd>
                                                                                </div>
                                                                      </dl>
                                                                      <div class="text-muted small mt-3" id="sponsorDetailsEmpty" hidden>No extra contact details have been added for this sponsor.</div>
                                                            </div>
                                                  </div>
                                                  <form class="sponsor-details-edit d-none" id="sponsorDetailsForm" enctype="multipart/form-data">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="sponsor_id" id="sponsorDetailsSponsorId" value="">
                                                            <div class="row g-3">
                                                                      <div class="col-12">
                                                                                <label class="form-label" for="sponsorDetailsEditName">Sponsor name</label>
                                                                                <input type="text" class="form-control" id="sponsorDetailsEditName" name="name" required maxlength="190">
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <div class="form-check form-switch">
                                                                                          <input class="form-check-input" type="checkbox" role="switch" id="sponsorDetailsEditActive" name="is_active" value="1">
                                                                                          <label class="form-check-label" for="sponsorDetailsEditActive">Active sponsor</label>
                                                                                </div>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <div class="form-check form-switch">
                                                                                          <input class="form-check-input" type="checkbox" role="switch" id="sponsorDetailsEditBusiness" name="is_business" value="1">
                                                                                          <label class="form-check-label" for="sponsorDetailsEditBusiness">Business sponsor</label>
                                                                                </div>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <div class="form-check form-switch">
                                                                                          <input class="form-check-input" type="checkbox" role="switch" id="sponsorDetailsEditMain" name="is_main_sponsor" value="1">
                                                                                          <label class="form-check-label" for="sponsorDetailsEditMain">Main sponsor</label>
                                                                                </div>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label" for="sponsorDetailsEditSortOrder">Display order</label>
                                                                                <input type="number" class="form-control" id="sponsorDetailsEditSortOrder" name="sort_order" min="0" step="1">
                                                                      </div>
                                                                      <div class="col-12">
                                                                                <label class="form-label" for="sponsorDetailsEditAddress">Address</label>
                                                                                <input type="text" class="form-control" id="sponsorDetailsEditAddress" name="address" maxlength="255">
                                                                      </div>
                                                                      <div class="col-12">
                                                                                <label class="form-label" for="sponsorDetailsEditWebsite">Website</label>
                                                                                <input type="url" class="form-control" id="sponsorDetailsEditWebsite" name="website_url" maxlength="255" placeholder="https://www.example.com">
                                                                      </div>
                                                                      <div class="col-md-4">
                                                                                <label class="form-label" for="sponsorDetailsEditFacebook">Facebook</label>
                                                                                <input type="url" class="form-control" id="sponsorDetailsEditFacebook" name="facebook_page_url" maxlength="500" placeholder="https://www.facebook.com/page">
                                                                      </div>
                                                                      <div class="col-md-4">
                                                                                <label class="form-label" for="sponsorDetailsEditInstagram">Instagram</label>
                                                                                <input type="url" class="form-control" id="sponsorDetailsEditInstagram" name="instagram_url" maxlength="500" placeholder="https://www.instagram.com/name">
                                                                      </div>
                                                                      <div class="col-md-4">
                                                                                <label class="form-label" for="sponsorDetailsEditTwitter">X / Twitter</label>
                                                                                <input type="url" class="form-control" id="sponsorDetailsEditTwitter" name="twitter_url" maxlength="500" placeholder="https://x.com/name">
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label" for="sponsorDetailsEditPhone">Phone</label>
                                                                                <input type="tel" class="form-control" id="sponsorDetailsEditPhone" name="contact_phone" maxlength="50">
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label" for="sponsorDetailsEditEmail">Email</label>
                                                                                <input type="email" class="form-control" id="sponsorDetailsEditEmail" name="contact_email" maxlength="190">
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label" for="sponsorDetailsEditLogo">Colour logo</label>
                                                                                <input type="file" class="form-control" id="sponsorDetailsEditLogo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                                                                                <div class="sponsor-details-logo-preview mt-2" id="sponsorDetailsEditLogoPreview" hidden>
                                                                                          <img src="" alt="Current colour logo" id="sponsorDetailsEditLogoPreviewImage">
                                                                                          <span>Current colour logo</span>
                                                                                </div>
                                                                                <div class="form-check mt-2" id="sponsorDetailsRemoveLogoWrap">
                                                                                          <input class="form-check-input" type="checkbox" id="sponsorDetailsRemoveLogo" name="remove_logo" value="1">
                                                                                          <label class="form-check-label" for="sponsorDetailsRemoveLogo">Remove current colour logo</label>
                                                                                </div>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label" for="sponsorDetailsEditWhiteLogo">White logo</label>
                                                                                <input type="file" class="form-control" id="sponsorDetailsEditWhiteLogo" name="white_logo" accept="image/png,image/jpeg,image/gif,image/webp">
                                                                                <div class="sponsor-details-logo-preview sponsor-details-logo-preview--dark mt-2" id="sponsorDetailsEditWhiteLogoPreview" hidden>
                                                                                          <img src="" alt="Current white logo" id="sponsorDetailsEditWhiteLogoPreviewImage">
                                                                                          <span>Current white logo</span>
                                                                                </div>
                                                                                <div class="form-check mt-2" id="sponsorDetailsRemoveWhiteLogoWrap">
                                                                                          <input class="form-check-input" type="checkbox" id="sponsorDetailsRemoveWhiteLogo" name="remove_white_logo" value="1">
                                                                                          <label class="form-check-label" for="sponsorDetailsRemoveWhiteLogo">Remove current white logo</label>
                                                                                </div>
                                                                      </div>
                                                            </div>
                                                  </form>
                                        </div>
                                        <div class="modal-footer d-none" id="sponsorDetailsEditFooter">
                                                  <button type="button" class="btn btn-outline-secondary" id="sponsorDetailsCancelEdit">Cancel</button>
                                                  <button type="submit" class="btn btn-brand" form="sponsorDetailsForm" id="sponsorDetailsSaveButton">
                                                            <i class="fa-regular fa-floppy-disk me-1" aria-hidden="true"></i>Save sponsor
                                                  </button>
                                        </div>
                                  </div>
                    </div>
          </div>

          <div class="modal fade" id="editSponsorshipModal" tabindex="-1" aria-labelledby="editSponsorshipModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                              <div class="modal-content">
                                        <div class="modal-header match-modal-header">
                                                  <div class="match-modal-header__title">
                                                            <h5 class="modal-title mb-1" id="editSponsorshipModalLabel">Edit Sponsorship</h5>
                                                            <div class="text-muted small">Update the sponsor, amount, notes, and paid state.</div>
                                                  </div>
                                                  <div class="match-modal-header__actions">
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                        </div>
                                        <div class="modal-body">
                                                  <form method="post" action="match_sponsorship_update.php" id="editSponsorshipForm">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id" id="editSponsorshipId" value="">
                                                            <input type="hidden" name="sponsorship_role" id="editSponsorshipRole" value="">
                                                            <div class="row g-3">
                                                                      <div class="col-md-6">
                                                                                <label class="form-label">Sponsor</label>
                                                                                <select name="sponsor_id" id="editSponsorshipSponsorId" class="form-select" required>
                                                                                          <option value="">Select sponsor</option>
                                                                                          <?php foreach ($activeSponsors as $sponsor): ?>
                                                                                                    <option value="<?= (int)$sponsor['id'] ?>"><?= h($sponsor['name']) ?></option>
                                                                                          <?php endforeach; ?>
                                                                                </select>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label">Role</label>
                                                                                <div class="form-control-plaintext fw-semibold" id="editSponsorshipRoleLabel">Match Day</div>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label class="form-label">Amount</label>
                                                                                <input type="number" step="0.01" min="0" class="form-control" name="amount" id="editSponsorshipAmount" required>
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <div class="form-check form-switch mt-4 pt-2">
                                                                                          <input class="form-check-input" type="checkbox" role="switch" id="editSponsorshipPaid" name="paid" value="1">
                                                                                          <label class="form-check-label" for="editSponsorshipPaid">Paid</label>
                                                                                </div>
                                                                      </div>
                                                                      <div class="col-12">
                                                                                <div class="form-check form-switch">
                                                                                          <input class="form-check-input" type="checkbox" role="switch" id="editSponsorshipComplimentary" name="is_complimentary" value="1">
                                                                                          <label class="form-check-label fw-semibold" for="editSponsorshipComplimentary">Complimentary / Free promotion</label>
                                                                                </div>
                                                                                <div class="form-text">The sponsor appears in match graphics, but no income or payment is recorded.</div>
                                                                      </div>
                                                                      <div class="col-12">
                                                                                <label class="form-label">Notes</label>
                                                                                <textarea name="notes" id="editSponsorshipNotes" class="form-control" rows="3"></textarea>
                                                                      </div>
                                                            </div>
                                                            <div class="modal-footer px-0 pb-0">
                                                                      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                      <button type="submit" class="btn btn-brand">Save Changes</button>
                                                            </div>
                                                  </form>
                                        </div>
                              </div>
                    </div>
          </div>
          <div class="modal fade" id="assignmentsModal" tabindex="-1" aria-labelledby="assignmentsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                              <div class="modal-content assignment-modal">
                                        <form method="post" action="match_sponsorship_save.php" id="assignmentForm">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="fixture_id" value="<?= (int)$fixture['id'] ?>">
                                                  <div class="modal-header assignment-modal__header">
                                                            <div class="d-flex align-items-center gap-3">
                                                                      <span class="assignment-modal__header-icon"><i class="fa-solid fa-handshake" aria-hidden="true"></i></span>
                                                                      <div>
                                                                                <h5 class="modal-title mb-1" id="assignmentsModalLabel">Assign match sponsorship</h5>
                                                                                <div class="small opacity-75">Select one or more packages for this fixture.</div>
                                                                      </div>
                                                            </div>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                                  <div class="modal-body p-4">
                                                            <section class="assignment-modal__section">
                                                                      <div class="assignment-modal__step">1</div>
                                                                      <div class="flex-grow-1">
                                                                                <label class="form-label fw-semibold" for="assignmentSponsor">Choose sponsor</label>
                                                                                <select name="sponsor_id" id="assignmentSponsor" class="form-select form-select-lg" required>
                                                                                          <option value="">Select a current sponsor</option>
                                                                                          <?php foreach ($activeSponsors as $sponsor): ?>
                                                                                                    <option value="<?= (int)$sponsor['id'] ?>"><?= h($sponsor['name']) ?></option>
                                                                                          <?php endforeach; ?>
                                                                                </select>
                                                                      </div>
                                                            </section>

                                                            <section class="assignment-modal__section">
                                                                      <div class="assignment-modal__step">2</div>
                                                                      <div class="flex-grow-1 min-w-0">
                                                                                <div class="fw-semibold mb-1">Select packages</div>
                                                                                <div class="text-muted small mb-3">Choose every package this sponsor is taking. You can select one, two, or all three.</div>
                                                                                <div class="assignment-package-grid" role="group" aria-label="Sponsorship packages">
                                                                                          <?php foreach ($sponsorshipTypes as $sponsorshipType): ?>
                                                                                                    <?php
                                                                                                    $typeCode = (string)$sponsorshipType['code'];
                                                                                                    $typeName = (string)$sponsorshipType['name'];
                                                                                                    $typeAmount = calculateMatchSponsorshipAmount($pdo, $seasonId, $typeCode, (int)$fixture['is_home'] === 1);
                                                                                                    $typeIcon = match ($typeCode) {
                                                                                                              'match_day' => 'fa-calendar-day',
                                                                                                              'match_ball' => 'fa-futbol',
                                                                                                              'motm' => 'fa-trophy',
                                                                                                              default => 'fa-star',
                                                                                                    };
                                                                                                    ?>
                                                                                                    <input type="checkbox" class="btn-check assignment-package-toggle" name="sponsorship_roles[]" id="role-<?= h(str_replace('_', '-', $typeCode)) ?>" value="<?= h($typeCode) ?>" data-label="<?= h($typeName) ?>" autocomplete="off">
                                                                                                    <label class="assignment-package-option" for="role-<?= h(str_replace('_', '-', $typeCode)) ?>">
                                                                                                              <span class="assignment-package-option__icon"><i class="fa-solid <?= h($typeIcon) ?>" aria-hidden="true"></i></span>
                                                                                                              <span>
                                                                                                                        <span class="assignment-package-option__name"><?= h($typeName) ?></span>
                                                                                                                        <span class="assignment-package-option__price"><?= gbp($typeAmount) ?></span>
                                                                                                              </span>
                                                                                                              <i class="fa-solid fa-circle-check assignment-package-option__check" aria-hidden="true"></i>
                                                                                                    </label>
                                                                                          <?php endforeach; ?>
                                                                                </div>
                                                                                <div class="text-danger small mt-2 d-none" id="assignmentPackageError">Select at least one sponsorship package.</div>
                                                                      </div>
                                                            </section>

                                                            <section class="assignment-modal__section d-none" id="assignmentAmountsSection">
                                                                      <div class="assignment-modal__step">3</div>
                                                                      <div class="flex-grow-1 min-w-0">
                                                                                <div class="fw-semibold mb-1">Payments / amounts</div>
                                                                                <div class="text-muted small mb-3">Review the season pricing and choose how these assignments should be recorded.</div>
                                                                                <div class="row g-3 mb-3">
                                                                                          <div class="col-md-6">
                                                                                                    <div class="assignment-option-card h-100">
                                                                                                              <div class="form-check form-switch">
                                                                                                                        <input class="form-check-input" type="checkbox" role="switch" id="complimentaryToggle" name="is_complimentary" value="1">
                                                                                                                        <label class="form-check-label fw-semibold" for="complimentaryToggle">Complimentary / Free promotion</label>
                                                                                                              </div>
                                                                                                              <div class="small text-muted mt-1">Show the sponsor without recording income.</div>
                                                                                                    </div>
                                                                                          </div>
                                                                                          <div class="col-md-6">
                                                                                                    <div class="assignment-option-card h-100">
                                                                                                              <div class="form-check form-switch">
                                                                                                                        <input class="form-check-input" type="checkbox" role="switch" id="markPaidToggle" name="mark_paid" value="1">
                                                                                                                        <label class="form-check-label fw-semibold" for="markPaidToggle">Paid</label>
                                                                                                              </div>
                                                                                                              <div class="small text-muted mt-1">Record the full combined total as paid immediately.</div>
                                                                                                    </div>
                                                                                          </div>
                                                                                </div>
                                                                                <div class="row g-3" id="assignmentAmountGrid">
                                                                                          <?php foreach ($sponsorshipTypes as $sponsorshipType): ?>
                                                                                                    <?php
                                                                                                    $typeCode = (string)$sponsorshipType['code'];
                                                                                                    $typeName = (string)$sponsorshipType['name'];
                                                                                                    $typeAmount = calculateMatchSponsorshipAmount($pdo, $seasonId, $typeCode, (int)$fixture['is_home'] === 1);
                                                                                                    ?>
                                                                                                    <div class="col-md-6 col-xl-4 assignment-amount-card d-none" data-role="<?= h($typeCode) ?>">
                                                                                                              <label class="form-label small fw-semibold" for="amount-<?= h(str_replace('_', '-', $typeCode)) ?>"><?= h($typeName) ?></label>
                                                                                                              <div class="input-group input-group-lg">
                                                                                                                        <span class="input-group-text">£</span>
                                                                                                                        <input type="number" step="0.01" min="0" class="form-control assignment-package-amount" name="amounts[<?= h($typeCode) ?>]" id="amount-<?= h(str_replace('_', '-', $typeCode)) ?>" data-role="<?= h($typeCode) ?>" value="<?= h(number_format($typeAmount, 2, '.', '')) ?>" disabled>
                                                                                                              </div>
                                                                                                    </div>
                                                                                          <?php endforeach; ?>
                                                                                </div>
                                                                                <div class="assignment-total mt-3">
                                                                                          <span>Total</span>
                                                                                          <strong id="assignmentTotal">£0.00</strong>
                                                                                </div>
                                                                      </div>
                                                            </section>

                                                            <section class="assignment-modal__section">
                                                                      <div class="assignment-modal__step">4</div>
                                                                      <div class="flex-grow-1 min-w-0">
                                                                                <label class="form-label fw-semibold" for="assignmentNotes">Notes <span class="text-muted fw-normal">(optional)</span></label>
                                                                                <textarea name="notes" id="assignmentNotes" class="form-control" rows="3" placeholder="Add any useful information about this assignment"></textarea>
                                                                      </div>
                                                            </section>
                                                  </div>
                                                  <div class="modal-footer assignment-modal__footer">
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-brand px-4"><i class="fa-solid fa-check me-2" aria-hidden="true"></i>Save assignments</button>
                                                  </div>
                                        </form>
                              </div>
                    </div>
          </div>

          <div class="modal fade" id="sponsorShoutoutModal" tabindex="-1" aria-labelledby="sponsorShoutoutModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down social-composer-modal">
                              <div class="modal-content">
                                        <div class="modal-header social-composer-header">
                                                  <div class="d-flex align-items-center gap-3">
                                                            <span class="social-composer-header__icon"><i class="fa-solid fa-share-nodes"></i></span>
                                                            <div>
                                                                      <div class="small text-uppercase fw-semibold opacity-75 mb-1">Social Composer</div>
                                                                      <h2 class="modal-title h4 mb-1" id="sponsorShoutoutModalLabel">Match Sponsors Shoutout</h2>
                                                                      <div class="small opacity-75">Thank the Match Day and Match Ball sponsors, then choose where to publish.</div>
                                                            </div>
                                                  </div>
                                                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-0">
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
                                                                                <div class="social-event-preview d-flex align-items-center justify-content-center is-portrait" id="sponsorSharePreview">
                                                                                          <div class="text-center text-muted p-4" id="sponsorShareLoading">
                                                                                                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Preparing preview…
                                                                                          </div>
                                                                                          <img id="sponsorShareImage" class="d-none" src="" alt="Generated match sponsors graphic">
                                                                                          <iframe id="sponsorShareLivePreview" class="d-none" src="about:blank" title="Live match sponsors graphic preview"></iframe>
                                                                                </div>
                                                                                <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                                                                          <div class="small text-muted"><i class="fa-solid fa-image me-1"></i>Portrait 1080 × 1350 graphic</div>
                                                                                </div>
                                                                      </div>
                                                            </div>

                                                            <div class="col-lg-5 social-composer-sidebar p-3 p-xl-4">
                                                                      <div class="social-composer-template-note">
                                                                                <span class="social-composer-template-note__icon"><i class="fa-solid fa-layer-group"></i></span>
                                                                                <div>
                                                                                          <div class="fw-semibold">Sponsors pulled from this fixture</div>
                                                                                          <div class="small opacity-75">Only paid, complimentary, or zero-value Match Day / Match Ball sponsorships are included.</div>
                                                                                </div>
                                                                      </div>
                                                                      <section class="social-composer-section">
                                                                                <div class="social-composer-section__heading">
                                                                                          <span class="social-composer-section__number"><i class="fa-regular fa-pen-to-square"></i></span>
                                                                                          <div>
                                                                                                    <div class="fw-semibold">Post text</div>
                                                                                                    <div class="small text-muted">Copied to X, or sent directly with the graphic on Facebook / Instagram.</div>
                                                                                          </div>
                                                                                </div>
                                                                                <textarea class="form-control social-composer-caption" id="sponsorShareCaption" rows="10" placeholder="Preparing post text…"></textarea>
                                                                      </section>

                                                                      <div class="alert d-none mt-3 mb-0" id="sponsorShareStatus" role="status"></div>
                                                            </div>
                                                  </div>
                                        </div>
                                        <div class="modal-footer social-composer-footer justify-content-between gap-3">
                                                  <div class="d-flex flex-wrap gap-2">
                                                            <a class="btn btn-outline-dark disabled" id="sponsorShareDownload" href="#" download aria-disabled="true"><i class="fa-solid fa-download me-1"></i>Download Graphic</a>
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close Without Posting</button>
                                                  </div>
                                                  <div class="d-flex flex-wrap justify-content-end gap-2 social-composer-footer__actions">
                                                            <button type="button" class="btn btn-primary social-publish-button" id="sponsorSharePostFacebook"><i class="fa-brands fa-facebook"></i>Facebook</button>
                                                            <button type="button" class="btn btn-danger social-publish-button" id="sponsorSharePostInstagram"><i class="fa-brands fa-instagram"></i>Instagram</button>
                                                            <button type="button" class="btn btn-dark social-publish-button" id="sponsorShareOpenX"><i class="fa-brands fa-x-twitter"></i>Open X</button>
                                                  </div>
                                        </div>
                              </div>
                    </div>
          </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const starting11Frame = document.querySelector('.fixture-starting11-frame');
  if (starting11Frame) {
    const resizeStarting11Frame = () => {
      try {
        const frameDocument = starting11Frame.contentDocument;
        if (!frameDocument) return;
        const contentHeight = Math.max(
          frameDocument.documentElement ? frameDocument.documentElement.scrollHeight : 0,
          frameDocument.body ? frameDocument.body.scrollHeight : 0
        );
        if (contentHeight > 0) {
          starting11Frame.style.height = Math.max(900, contentHeight + 24) + 'px';
        }
      } catch (error) {
        // The editor is same-origin; retain the CSS fallback if access is unavailable.
      }
    };

    starting11Frame.addEventListener('load', () => {
      resizeStarting11Frame();
      try {
        const frameBody = starting11Frame.contentDocument && starting11Frame.contentDocument.body;
        if (frameBody && typeof ResizeObserver === 'function') {
          new ResizeObserver(resizeStarting11Frame).observe(frameBody);
        }
      } catch (error) {
        // Retain the CSS fallback height.
      }
    });
  }

  const assignmentForm = document.getElementById('assignmentForm');
  const roleInputs = Array.from(document.querySelectorAll('.assignment-package-toggle'));
  const amountCards = Array.from(document.querySelectorAll('.assignment-amount-card'));
  const amountInputs = Array.from(document.querySelectorAll('.assignment-package-amount'));
  const amountsSection = document.getElementById('assignmentAmountsSection');
  const packageError = document.getElementById('assignmentPackageError');
  const total = document.getElementById('assignmentTotal');
  const paidToggle = document.getElementById('markPaidToggle');
  const complimentaryToggle = document.getElementById('complimentaryToggle');
  const editModal = document.getElementById('editSponsorshipModal');
  const editForm = document.getElementById('editSponsorshipForm');
  const editId = document.getElementById('editSponsorshipId');
  const editSponsor = document.getElementById('editSponsorshipSponsorId');
  const editRole = document.getElementById('editSponsorshipRole');
  const editRoleLabel = document.getElementById('editSponsorshipRoleLabel');
  const editAmount = document.getElementById('editSponsorshipAmount');
  const editNotes = document.getElementById('editSponsorshipNotes');
  const editPaid = document.getElementById('editSponsorshipPaid');
  const editComplimentary = document.getElementById('editSponsorshipComplimentary');
  const sponsorDetailsModal = document.getElementById('sponsorDetailsModal');

  const opponents = <?= json_encode(array_map(static function (array $opponent): array {
    return [
      'id' => (int)$opponent['id'],
      'name' => (string)$opponent['clubname'],
      'abbreviation' => (string)($opponent['abbreviation'] ?? ''),
    ];
  }, $opponents), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const opponentSearch = document.getElementById('opponentSearch');
  const opponentId = document.getElementById('opponentId');
  const opponentResults = document.getElementById('opponentSearchResults');
  const fixtureForm = document.getElementById('fixtureSaveForm');
  const addOpponentModalElement = document.getElementById('addOpponentModal');
  const quickOpponentForm = document.getElementById('quickOpponentForm');
  const quickOpponentConfirm = document.getElementById('quickOpponentConfirm');
  const quickOpponentDetails = document.getElementById('quickOpponentDetails');
  const missingOpponentName = document.getElementById('missingOpponentName');
  const quickClubName = document.getElementById('quickOpponentClubName');
  const quickAbbreviation = document.getElementById('quickOpponentAbbreviation');
  const acceptOpponentButton = document.getElementById('acceptOpponentButton');
  const saveOpponentButton = document.getElementById('saveOpponentButton');
  const rejectOpponentButton = document.getElementById('rejectOpponentButton');
  let rejectedOpponentName = '';

  const normaliseOpponent = (value) => value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
  const exactOpponent = (value) => opponents.find((opponent) => normaliseOpponent(opponent.name) === normaliseOpponent(value));
  const makeAbbreviation = (name) => {
    const words = name.toUpperCase().split(/\s+/).map((word) => word.replace(/[^A-Z0-9]/g, '')).filter((word) => word && !['FC', 'AFC', 'SC', 'THE'].includes(word));
    if (words.length >= 3) return words.map((word) => word.charAt(0)).join('').slice(0, 3);
    return (words[0] || 'TBC').slice(0, 3);
  };

  const chooseOpponent = (opponent) => {
    opponentSearch.value = opponent.name;
    opponentId.value = String(opponent.id);
    opponentSearch.setCustomValidity('');
    opponentResults.classList.add('d-none');
    opponentSearch.setAttribute('aria-expanded', 'false');
  };

  const showAddOpponent = (name) => {
    name = name.trim().replace(/\s+/g, ' ');
    if (!name || exactOpponent(name) || typeof bootstrap === 'undefined') return;
    quickClubName.value = name;
    quickAbbreviation.value = makeAbbreviation(name);
    missingOpponentName.textContent = name;
    quickOpponentConfirm.classList.remove('d-none');
    quickOpponentDetails.classList.add('d-none');
    acceptOpponentButton.classList.remove('d-none');
    saveOpponentButton.classList.add('d-none');
    rejectOpponentButton.textContent = "No, don't add";
    opponentResults.classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(addOpponentModalElement).show();
  };

  if (opponentSearch && opponentId && opponentResults && addOpponentModalElement) {
    const renderOpponentResults = () => {
      const query = normaliseOpponent(opponentSearch.value);
      opponentId.value = '';
      opponentResults.replaceChildren();
      if (!query) {
        opponentResults.classList.add('d-none');
        opponentSearch.setAttribute('aria-expanded', 'false');
        return;
      }

      const matches = opponents.filter((opponent) => normaliseOpponent(opponent.name).includes(query) || normaliseOpponent(opponent.abbreviation).includes(query)).slice(0, 12);
      matches.forEach((opponent) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        button.setAttribute('role', 'option');
        const name = document.createElement('span');
        name.textContent = opponent.name;
        button.appendChild(name);
        if (opponent.abbreviation) {
          const abbreviation = document.createElement('small');
          abbreviation.className = 'text-muted';
          abbreviation.textContent = opponent.abbreviation;
          button.appendChild(abbreviation);
        }
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => chooseOpponent(opponent));
        opponentResults.appendChild(button);
      });

      const exact = exactOpponent(opponentSearch.value);
      if (exact) {
        opponentId.value = String(exact.id);
      } else {
        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'list-group-item list-group-item-action text-primary fw-semibold';
        addButton.textContent = `Add “${opponentSearch.value.trim()}” as a new team`;
        addButton.addEventListener('mousedown', (event) => event.preventDefault());
        addButton.addEventListener('click', () => showAddOpponent(opponentSearch.value));
        opponentResults.appendChild(addButton);
      }
      opponentResults.classList.remove('d-none');
      opponentSearch.setAttribute('aria-expanded', 'true');
    };

    opponentSearch.addEventListener('input', () => {
      rejectedOpponentName = '';
      renderOpponentResults();
    });
    opponentSearch.addEventListener('focus', renderOpponentResults);
    opponentSearch.addEventListener('blur', () => {
      window.setTimeout(() => {
        opponentResults.classList.add('d-none');
        const exact = exactOpponent(opponentSearch.value);
        if (exact) chooseOpponent(exact);
        else if (opponentSearch.value.trim() && normaliseOpponent(opponentSearch.value) !== rejectedOpponentName) showAddOpponent(opponentSearch.value);
      }, 150);
    });

    fixtureForm?.addEventListener('submit', (event) => {
      const exact = exactOpponent(opponentSearch.value);
      if (exact) {
        chooseOpponent(exact);
        return;
      }
      event.preventDefault();
      opponentSearch.setCustomValidity('Select an existing team or add this team first.');
      showAddOpponent(opponentSearch.value);
    });

    acceptOpponentButton?.addEventListener('click', () => {
      quickOpponentConfirm.classList.add('d-none');
      quickOpponentDetails.classList.remove('d-none');
      acceptOpponentButton.classList.add('d-none');
      saveOpponentButton.classList.remove('d-none');
      rejectOpponentButton.textContent = 'Cancel';
      document.getElementById('quickOpponentLogo')?.focus();
    });
    addOpponentModalElement.addEventListener('hidden.bs.modal', () => {
      rejectedOpponentName = normaliseOpponent(opponentSearch.value);
    });
  }

  const formatMoney = (value) => {
    const amount = Number(value || 0);
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(amount);
  };

  const updateTotal = () => {
    if (!total) return;
    const combined = amountInputs
      .filter((input) => !input.disabled)
      .reduce((sum, input) => sum + (parseFloat(input.value || '0') || 0), 0);
    total.textContent = formatMoney(combined);
  };

  const syncComplimentaryAssignment = () => {
    if (!complimentaryToggle) return;
    const complimentary = complimentaryToggle.checked;
    amountInputs.forEach((input) => {
      if (complimentary) {
        if (Number(input.value) > 0) input.dataset.chargeableValue = input.value;
        input.value = '0.00';
      } else if (input.dataset.chargeableValue) {
        input.value = input.dataset.chargeableValue;
      }
      input.readOnly = complimentary;
    });
    if (paidToggle) {
      if (complimentary) paidToggle.checked = false;
      paidToggle.disabled = complimentary;
      paidToggle.parentElement?.classList.toggle('is-paid', paidToggle.checked);
    }
    updateTotal();
  };

  const updateSelectedPackages = () => {
    const selectedRoles = new Set(roleInputs.filter((input) => input.checked).map((input) => input.value));
    amountCards.forEach((card) => {
      const selected = selectedRoles.has(card.dataset.role || '');
      card.classList.toggle('d-none', !selected);
      const input = card.querySelector('.assignment-package-amount');
      if (input) input.disabled = !selected;
    });
    amountsSection?.classList.toggle('d-none', selectedRoles.size === 0);
    packageError?.classList.add('d-none');
    updateTotal();
  };

  roleInputs.forEach((input) => input.addEventListener('change', () => {
    updateSelectedPackages();
    syncComplimentaryAssignment();
  }));
  amountInputs.forEach((input) => input.addEventListener('input', updateTotal));

  if (paidToggle) {
    paidToggle.addEventListener('change', () => {
      paidToggle.closest('.assignment-option-card')?.classList.toggle('border-success', paidToggle.checked);
    });
  }

  complimentaryToggle?.addEventListener('change', syncComplimentaryAssignment);
  assignmentForm?.addEventListener('submit', (event) => {
    if (roleInputs.some((input) => input.checked)) return;
    event.preventDefault();
    packageError?.classList.remove('d-none');
    roleInputs[0]?.focus();
  });

  const syncComplimentaryEdit = () => {
    if (!editComplimentary || !editAmount || !editPaid) return;
    const complimentary = editComplimentary.checked;
    if (complimentary) {
      if (editAmount.value !== '0' && editAmount.value !== '0.00') {
        editAmount.dataset.chargeableValue = editAmount.value;
      }
      editAmount.value = '0.00';
      editPaid.checked = false;
    } else if (editAmount.value === '0.00' || editAmount.value === '0') {
      editAmount.value = editAmount.dataset.chargeableValue || editAmount.dataset.defaultAmount || '';
    }
    editAmount.readOnly = complimentary;
    editPaid.disabled = complimentary;
  };
  editComplimentary?.addEventListener('change', syncComplimentaryEdit);

  if (editModal && editForm && editId && editSponsor && editRole && editRoleLabel && editAmount && editNotes && editPaid && editComplimentary) {
    editModal.addEventListener('show.bs.modal', (event) => {
      const button = event.relatedTarget;
      if (!button) return;

      editId.value = button.getAttribute('data-sponsorship-id') || '';
      editSponsor.value = button.getAttribute('data-sponsor-id') || '';
      editRole.value = button.getAttribute('data-role') || '';
      editRoleLabel.textContent = button.getAttribute('data-role-label') || '';
      editAmount.value = button.getAttribute('data-amount') || '';
      editAmount.dataset.defaultAmount = button.getAttribute('data-default-amount') || '';
      editAmount.dataset.chargeableValue = button.getAttribute('data-default-amount') || '';
      editNotes.value = button.getAttribute('data-notes') || '';
      editPaid.checked = (button.getAttribute('data-paid') || '0') === '1';
      editComplimentary.checked = (button.getAttribute('data-complimentary') || '0') === '1';
      syncComplimentaryEdit();
    });
  }

  if (sponsorDetailsModal) {
    let sponsorDetailsTrigger = null;
    let sponsorDetailsState = {};
    const view = document.getElementById('sponsorDetailsView');
    const form = document.getElementById('sponsorDetailsForm');
    const footer = document.getElementById('sponsorDetailsEditFooter');
    const editButton = document.getElementById('sponsorDetailsEditButton');
    const cancelEditButton = document.getElementById('sponsorDetailsCancelEdit');
    const saveButton = document.getElementById('sponsorDetailsSaveButton');
    const errorBox = document.getElementById('sponsorDetailsError');
    const normaliseUrl = (value) => {
      value = (value || '').trim();
      if (!value) return '';
      return /^https?:\/\//i.test(value) ? value : 'https://' + value;
    };
    const setRow = (rowId, valueId, value, options = {}) => {
      const row = document.getElementById(rowId);
      const target = document.getElementById(valueId);
      if (!row || !target) return false;
      const trimmed = (value || '').trim();
      row.hidden = trimmed === '';
      if (trimmed === '') return false;
      if (target.tagName === 'A') {
        target.textContent = trimmed;
        target.href = options.href || trimmed;
      } else {
        target.textContent = trimmed;
      }
      return true;
    };
    const setSponsorEditMode = (editing) => {
      view?.classList.toggle('d-none', editing);
      form?.classList.toggle('d-none', !editing);
      footer?.classList.toggle('d-none', !editing);
      if (editButton) {
        editButton.classList.toggle('d-none', editing);
      }
      if (errorBox) {
        errorBox.classList.add('d-none');
        errorBox.textContent = '';
      }
    };
    const readSponsorTrigger = (button) => ({
      id: button.getAttribute('data-sponsor-id') || '',
      name: button.getAttribute('data-sponsor-name') || 'Sponsor',
      logo: button.getAttribute('data-sponsor-logo') || '',
      whiteLogo: button.getAttribute('data-sponsor-white-logo') || '',
      website: button.getAttribute('data-sponsor-website') || '',
      facebook: button.getAttribute('data-sponsor-facebook') || '',
      instagram: button.getAttribute('data-sponsor-instagram') || '',
      twitter: button.getAttribute('data-sponsor-twitter') || '',
      phone: button.getAttribute('data-sponsor-phone') || '',
      email: button.getAttribute('data-sponsor-email') || '',
      address: button.getAttribute('data-sponsor-address') || '',
      business: (button.getAttribute('data-sponsor-business') || '0') === '1',
      main: (button.getAttribute('data-sponsor-main') || '0') === '1',
      sortOrder: button.getAttribute('data-sponsor-sort-order') || '0',
      active: (button.getAttribute('data-sponsor-active') || '1') === '1',
      role: button.getAttribute('data-sponsor-role') || 'Fixture sponsor',
    });
    const renderSponsorDetails = (state) => {
      document.getElementById('sponsorDetailsModalLabel').textContent = state.name;
      document.getElementById('sponsorDetailsName').textContent = state.name;
      document.getElementById('sponsorDetailsRole').textContent = state.role;
      document.getElementById('sponsorDetailsBusiness').hidden = !state.business;
      document.getElementById('sponsorDetailsMain').hidden = !state.main;
      document.getElementById('sponsorDetailsInactive').hidden = state.active;

      const logos = document.getElementById('sponsorDetailsLogos');
      logos.replaceChildren();
      [[state.logo, 'Colour logo'], [state.whiteLogo, 'White logo']].forEach(([src, label]) => {
        if (!src) return;
        const wrap = document.createElement('div');
        wrap.className = 'sponsor-details-logo';
        const img = document.createElement('img');
        img.src = src;
        img.alt = label + ' for ' + state.name;
        const caption = document.createElement('span');
        caption.textContent = label;
        wrap.append(img, caption);
        logos.appendChild(wrap);
      });
      logos.hidden = logos.children.length === 0;

      const shown = [
        setRow('sponsorDetailsWebsiteRow', 'sponsorDetailsWebsite', state.website, { href: normaliseUrl(state.website) }),
        setRow('sponsorDetailsFacebookRow', 'sponsorDetailsFacebook', state.facebook, { href: normaliseUrl(state.facebook) }),
        setRow('sponsorDetailsInstagramRow', 'sponsorDetailsInstagram', state.instagram, { href: normaliseUrl(state.instagram) }),
        setRow('sponsorDetailsTwitterRow', 'sponsorDetailsTwitter', state.twitter, { href: normaliseUrl(state.twitter) }),
        setRow('sponsorDetailsPhoneRow', 'sponsorDetailsPhone', state.phone, { href: 'tel:' + state.phone.replace(/\s+/g, '') }),
        setRow('sponsorDetailsEmailRow', 'sponsorDetailsEmail', state.email, { href: 'mailto:' + state.email }),
        setRow('sponsorDetailsAddressRow', 'sponsorDetailsAddress', state.address),
      ];
      document.getElementById('sponsorDetailsEmpty').hidden = shown.some(Boolean) || logos.children.length > 0;
    };
    const populateSponsorForm = (state) => {
      if (!form) return;
      document.getElementById('sponsorDetailsSponsorId').value = state.id;
      document.getElementById('sponsorDetailsEditName').value = state.name;
      document.getElementById('sponsorDetailsEditActive').checked = state.active;
      document.getElementById('sponsorDetailsEditBusiness').checked = state.business;
      document.getElementById('sponsorDetailsEditMain').checked = state.main;
      document.getElementById('sponsorDetailsEditSortOrder').value = state.sortOrder;
      document.getElementById('sponsorDetailsEditAddress').value = state.address;
      document.getElementById('sponsorDetailsEditWebsite').value = state.website;
      document.getElementById('sponsorDetailsEditFacebook').value = state.facebook;
      document.getElementById('sponsorDetailsEditInstagram').value = state.instagram;
      document.getElementById('sponsorDetailsEditTwitter').value = state.twitter;
      document.getElementById('sponsorDetailsEditPhone').value = state.phone;
      document.getElementById('sponsorDetailsEditEmail').value = state.email;
      document.getElementById('sponsorDetailsEditLogo').value = '';
      document.getElementById('sponsorDetailsEditWhiteLogo').value = '';
      document.getElementById('sponsorDetailsRemoveLogo').checked = false;
      document.getElementById('sponsorDetailsRemoveWhiteLogo').checked = false;
      document.getElementById('sponsorDetailsRemoveLogoWrap').hidden = state.logo === '';
      document.getElementById('sponsorDetailsRemoveWhiteLogoWrap').hidden = state.whiteLogo === '';
      const logoPreview = document.getElementById('sponsorDetailsEditLogoPreview');
      const logoPreviewImage = document.getElementById('sponsorDetailsEditLogoPreviewImage');
      const whiteLogoPreview = document.getElementById('sponsorDetailsEditWhiteLogoPreview');
      const whiteLogoPreviewImage = document.getElementById('sponsorDetailsEditWhiteLogoPreviewImage');
      if (logoPreview && logoPreviewImage) {
        logoPreview.hidden = state.logo === '';
        logoPreviewImage.src = state.logo;
      }
      if (whiteLogoPreview && whiteLogoPreviewImage) {
        whiteLogoPreview.hidden = state.whiteLogo === '';
        whiteLogoPreviewImage.src = state.whiteLogo;
      }
    };
    const applySponsorUpdate = (sponsor) => {
      const state = {
        ...sponsorDetailsState,
        id: String(sponsor.id || sponsorDetailsState.id || ''),
        name: sponsor.name || 'Sponsor',
        logo: sponsor.logo_url || '',
        whiteLogo: sponsor.white_logo_url || '',
        website: sponsor.website_url || '',
        facebook: sponsor.facebook_page_url || '',
        instagram: sponsor.instagram_url || '',
        twitter: sponsor.twitter_url || '',
        phone: sponsor.contact_phone || '',
        email: sponsor.contact_email || '',
        address: sponsor.address || '',
        business: Number(sponsor.is_business || 0) === 1,
        main: Number(sponsor.is_main_sponsor || 0) === 1,
        sortOrder: String(sponsor.sort_order || 0),
        active: Number(sponsor.is_active || 0) === 1,
      };
      sponsorDetailsState = state;
      document.querySelectorAll('.sponsor-detail-trigger[data-sponsor-id]').forEach((button) => {
        if (button.getAttribute('data-sponsor-id') !== state.id) return;
        button.textContent = state.name;
        button.setAttribute('data-sponsor-name', state.name);
        button.setAttribute('data-sponsor-logo', state.logo);
        button.setAttribute('data-sponsor-white-logo', state.whiteLogo);
        button.setAttribute('data-sponsor-website', state.website);
        button.setAttribute('data-sponsor-facebook', state.facebook);
        button.setAttribute('data-sponsor-instagram', state.instagram);
        button.setAttribute('data-sponsor-twitter', state.twitter);
        button.setAttribute('data-sponsor-phone', state.phone);
        button.setAttribute('data-sponsor-email', state.email);
        button.setAttribute('data-sponsor-address', state.address);
        button.setAttribute('data-sponsor-business', state.business ? '1' : '0');
        button.setAttribute('data-sponsor-main', state.main ? '1' : '0');
        button.setAttribute('data-sponsor-sort-order', state.sortOrder);
        button.setAttribute('data-sponsor-active', state.active ? '1' : '0');
      });
      renderSponsorDetails(state);
      populateSponsorForm(state);
    };

    sponsorDetailsModal.addEventListener('show.bs.modal', (event) => {
      const button = event.relatedTarget;
      if (!button) return;
      sponsorDetailsTrigger = button;
      sponsorDetailsState = readSponsorTrigger(button);
      renderSponsorDetails(sponsorDetailsState);
      populateSponsorForm(sponsorDetailsState);
      setSponsorEditMode(false);
    });
    editButton?.addEventListener('click', () => {
      populateSponsorForm(sponsorDetailsState);
      setSponsorEditMode(true);
      document.getElementById('sponsorDetailsEditName')?.focus();
    });
    cancelEditButton?.addEventListener('click', () => setSponsorEditMode(false));
    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!sponsorDetailsTrigger) return;
      if (errorBox) {
        errorBox.classList.add('d-none');
        errorBox.textContent = '';
      }
      saveButton.disabled = true;
      try {
        const response = await fetch('/admin/match_sponsor_details_save.php', {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || 'Unable to save sponsor details.');
        }
        applySponsorUpdate(payload.sponsor || {});
        setSponsorEditMode(false);
      } catch (error) {
        if (errorBox) {
          errorBox.textContent = error.message || 'Unable to save sponsor details.';
          errorBox.classList.remove('d-none');
        }
      } finally {
        saveButton.disabled = false;
      }
    });
  }

  const fixtureTabs = document.getElementById('fixtureTabs');
  if (fixtureTabs && typeof bootstrap !== 'undefined') {
    const currentFixtureId = <?= (int)($fixture['id'] ?? 0) ?>;
    const tabStoragePrefix = 'hub.match.tab.';
    const tabStorageKey = tabStoragePrefix + currentFixtureId;
    const tabExpiryMs = 60 * 60 * 1000;
    const tabButtons = Array.from(fixtureTabs.querySelectorAll('button[data-bs-toggle="tab"][data-bs-target]'));
    const tabFromQuery = new URLSearchParams(window.location.search).get('tab');
    try {
      Object.keys(window.localStorage).forEach((key) => {
        if (key.startsWith(tabStoragePrefix) && key !== tabStorageKey) {
          window.localStorage.removeItem(key);
        }
      });
    } catch (error) {}
    if (!tabFromQuery) {
      try {
        const stored = JSON.parse(window.localStorage.getItem(tabStorageKey) || 'null');
        if (stored && Number(stored.fixtureId || 0) === currentFixtureId && stored.tab && Date.now() - Number(stored.savedAt || 0) <= tabExpiryMs) {
          const button = tabButtons.find((item) => item.id === `fixture-${stored.tab}-tab`);
          if (button) bootstrap.Tab.getOrCreateInstance(button).show();
        } else {
          window.localStorage.removeItem(tabStorageKey);
        }
      } catch (error) {
        window.localStorage.removeItem(tabStorageKey);
      }
    }
    tabButtons.forEach((button) => {
      button.addEventListener('shown.bs.tab', () => {
        const tab = button.id.replace(/^fixture-/, '').replace(/-tab$/, '');
        try {
          window.localStorage.setItem(tabStorageKey, JSON.stringify({ fixtureId: currentFixtureId, tab, savedAt: Date.now() }));
        } catch (error) {}
      });
    });
  }

  updateSelectedPackages();
  syncComplimentaryAssignment();
});
</script>

<?php if (!$isNew && $sponsorShareRows !== []): ?>
<script>
(() => {
  const modalElement = document.getElementById('sponsorShoutoutModal');
  if (!modalElement || typeof bootstrap === 'undefined') return;

  const socialModal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const captionInput = document.getElementById('sponsorShareCaption');
  const previewFrame = document.getElementById('sponsorSharePreview');
  const image = document.getElementById('sponsorShareImage');
  const livePreview = document.getElementById('sponsorShareLivePreview');
  const loading = document.getElementById('sponsorShareLoading');
  const download = document.getElementById('sponsorShareDownload');
  const status = document.getElementById('sponsorShareStatus');
  const facebookButton = document.getElementById('sponsorSharePostFacebook');
  const instagramButton = document.getElementById('sponsorSharePostInstagram');
  const xButton = document.getElementById('sponsorShareOpenX');
  const openButton = document.getElementById('openSponsorShoutout');
  const fixtureId = <?= json_encode((string)$fixture['id']) ?>;
  const sponsorCsrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
  const confirmBeforeLivePost = <?= !empty(social_publishing_preferences_load()['global']['confirm_before_live_post']) ? 'true' : 'false' ?>;
  let graphicUrl = '';
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
    payload.csrf_token = sponsorCsrfToken;
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
      throw new Error((summary || `Request failed (${response.status}).`) + details);
    }
    return result;
  };

  const prepare = async () => {
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
    status.classList.add('d-none');

    try {
      const result = await request('/prepare_sponsor_share.php', { fixture_id: fixtureId });
      socialCaptions = result.captions || {};
      preparedCaption = socialCaptions.facebook || result.text || '';
      captionInput.value = preparedCaption;
      graphicUrl = result.download_url || '';
      if (graphicUrl) {
        image.src = graphicUrl;
        image.classList.remove('d-none');
        download.href = graphicUrl;
        download.classList.remove('disabled');
        download.removeAttribute('aria-disabled');
      }
      livePreview.src = `/match_sponsors_graphic.php?id=${encodeURIComponent(fixtureId)}&render=1&live_preview=1&v=${Date.now()}`;
      loading.classList.add('d-none');
      captionInput.disabled = false;
      return true;
    } catch (error) {
      loading.textContent = 'The preview could not be generated.';
      captionInput.disabled = false;
      setStatus(error.message || 'The sponsor shoutout could not be prepared.', 'danger');
      return false;
    }
  };

  const publish = async (platform, button) => {
    const platformKey = platform === 'Facebook' ? 'facebook' : 'instagram';
    if (captionInput.value.trim() === preparedCaption.trim() && socialCaptions[platformKey]) {
      preparedCaption = socialCaptions[platformKey];
      captionInput.value = preparedCaption;
    }
    if (!graphicUrl) {
      setStatus('Wait for the graphic to finish generating first.', 'warning');
      return;
    }
    if (confirmBeforeLivePost && !(await window.hubConfirm(`Publish the match sponsors shoutout to ${platform} now?`, { actionLabel: 'Post', actionClass: 'btn-primary' }))) return;

    const original = button.innerHTML;
    button.disabled = true;
    button.textContent = 'Posting…';
    setStatus(`Posting to ${platform}…`, 'info');
    const endpoint = platform === 'Facebook'
      ? '/post_sponsor_shoutout_to_facebook.php'
      : '/post_sponsor_shoutout_to_instagram.php';
    try {
      const result = await request(endpoint, {
        fixture_id: fixtureId,
        caption: captionInput.value.trim(),
      });
      setStatus(result.summary || `${platform} post completed.`, 'success');
    } catch (error) {
      setStatus(error.message || `${platform} post failed.`, 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  };

  modalElement.addEventListener('show.bs.modal', () => { prepare(); });
  document.getElementById('sponsorShareCopyText')?.addEventListener('click', async () => {
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
    request('/record_manual_share.php', { fixture_id: fixtureId, post_type: 'sponsor_shoutout', caption: text, image_url: `/match.php?id=${encodeURIComponent(fixtureId)}&tab=sponsorships` }).catch(function () {});
  });
})();
</script>
<?php endif; ?>


<?php
if ($useSharedHubLayout) {
          require_once __DIR__ . '/footer.php';
} else {
          require_once __DIR__ . '/footer.php';
}
