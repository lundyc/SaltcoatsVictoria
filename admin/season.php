<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/template_packs.php';
require_once __DIR__ . '/lib/league_table_history.php';
require_once __DIR__ . '/lib/season_wizard.php';

if (!hub_auth_is_authenticated()) {
          header('Location: /admin/login.php');
          exit;
}

template_packs_ensure_schema($pdo);

$action = $_GET['action'] ?? 'view';
$seasonId = (int)($_GET['id'] ?? 0);
$isNew = $action === 'new' || $seasonId <= 0;
$season = null;
$allCompetitions = getMatchCompetitions($pdo);
$leagueCompetitions = array_values(array_filter($allCompetitions, static function (array $competition): bool {
          return !empty($competition['is_league']);
}));
$competitionOptions = $leagueCompetitions !== [] ? $leagueCompetitions : $allCompetitions;

if (!$isNew) {
          $season = getSeasonById($pdo, $seasonId);
}

$pageHero = [
          'eyebrow' => 'Season settings',
          'title' => $isNew ? 'Add Season' : ($season ? (string) $season['name'] : 'Season'),
          'subtitle' => $isNew ? 'A step-by-step setup: dates, sponsor prices, season tickets, matchday and hospitality.' : 'Season dates, graphics, sponsorship pricing and season ticket terms.',
          'actions' => [],
];

require_once __DIR__ . '/header.php';

if (!$isNew && !$season) {
          echo '<div><div class="alert alert-danger">Season not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

// Add Season: optionally start from an earlier season's settings. Dates and the name stay blank.
$copySource = null;
$copySeasonOptions = [];
if ($isNew) {
          $copySeasonOptions = array_reverse(getSeasons($pdo)); // newest first
          $copyParam = $_GET['copy_from'] ?? null;
          $copyFromId = $copyParam === null
                    ? (int) (getCurrentSeason($pdo)['id'] ?? ($copySeasonOptions[0]['id'] ?? 0))
                    : (int) $copyParam;
          if ($copyFromId > 0) {
                    $copySource = getSeasonById($pdo, $copyFromId);
          }
}
$sourceId = $copySource ? (int) $copySource['id'] : 0;

$playerPricing = $isNew
          ? ($sourceId > 0 ? getSeasonPlayerPricing($pdo, $sourceId) : ['player_home_amount' => 50.00, 'player_away_amount' => 30.00, 'player_third_amount' => 20.00])
          : getSeasonPlayerPricing($pdo, $seasonId);
$matchPricing = $isNew
          ? ($sourceId > 0 ? getMatchSeasonPricing($pdo, $sourceId) : ['home_amount' => 50.00, 'away_amount' => 20.00, 'match_ball_amount' => 25.00])
          : getMatchSeasonPricing($pdo, $seasonId);
$selectedCompetitionId = (int) ($season['competition_id'] ?? ($copySource['competition_id'] ?? 0));
$selectedCompetitionName = '';
foreach ($competitionOptions as $competition) {
          if ((int) $competition['id'] === $selectedCompetitionId) {
                    $selectedCompetitionName = (string) $competition['name'];
                    break;
          }
}
$availableTemplatePacks = template_packs_list_available($pdo);
$seasonTemplatePack = !$isNew ? template_packs_get_season_default($pdo, $seasonId) : ($sourceId > 0 ? template_packs_get_season_default($pdo, $sourceId) : null);
$globalTemplatePack = template_packs_get_global_default($pdo);
$seasonTicketTerms = seasonTicketTermsTemplateForSeason($season ?? $copySource);
$canEditDeadline = hub_auth_is_sponsorship_editor();
$canArchive = !$isNew && !empty($season['is_current']);
if ($canArchive) {
          $archiveSnapshot = leagueTableHistoryLiveSnapshot($pdo, $season);
          $archivedAlready = leagueTableHistoryGet($pdo, $seasonId);
          $archiveErrors = [
                    'not_current' => 'Only the current season can be archived.',
                    'nothing' => 'Choose at least one thing to do.',
                    'no_table' => 'There is no live league table to save yet. Run "Update from WOSFL" on the League table page first.',
          ];
}

if ($isNew) {
          require __DIR__ . '/partials/season_wizard.php';
          require_once __DIR__ . '/footer.php';
          exit;
}

$money = static function (string $label, string $name, mixed $value): string {
          return '<div class="mb-3"><label class="form-label" for="' . h($name) . '">' . h($label) . '</label>'
                    . '<div class="input-group"><span class="input-group-text">&pound;</span>'
                    . '<input type="text" inputmode="decimal" autocomplete="off" id="' . h($name) . '" name="' . h($name) . '" class="form-control text-end" value="'
                    . h(number_format((float) $value, 2, '.', '')) . '" data-money></div></div>';
};
?>

<style>
.season-switch { min-height: 1.75rem; display: flex; align-items: center; gap: .65rem; padding-left: 0; }
.season-switch .form-check-input { margin: 0; width: 2.6rem; height: 1.4rem; cursor: pointer; float: none; flex: none; }
.season-switch .form-check-input:checked { background-color: #4b0818; border-color: #4b0818; }
.season-switch .form-check-input:focus { box-shadow: 0 0 0 .2rem rgba(75, 8, 24, .2); border-color: #4b0818; }
.season-switch .form-check-label { cursor: pointer; font-weight: 600; }
.season-archive-opt { display: flex; gap: .75rem; align-items: flex-start; padding: .85rem 1rem; margin-bottom: .6rem; border: 1px solid rgba(75, 8, 24, .15); border-radius: .7rem; background: #fff; }
.season-archive-opt .form-check-input { margin-top: .25rem; flex: none; }
.season-archive-opt label { cursor: pointer; }
.season-combo { position: relative; }
.season-combo__list { position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 4px); max-height: 18rem; overflow-y: auto; margin: 0; padding: .35rem; list-style: none; background: #fff; border: 1px solid rgba(75, 8, 24, .2); border-radius: .6rem; box-shadow: 0 12px 28px rgba(51, 4, 15, .16); }
.season-combo__list[hidden] { display: none; }
.season-combo__item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem .65rem; border-radius: .45rem; cursor: pointer; }
.season-combo__item:hover, .season-combo__item.is-active { background: rgba(75, 8, 24, .08); }
.season-combo__item[aria-selected="true"] { font-weight: 700; color: #4b0818; }
.season-combo__item mark { padding: 0; background: #fbe9a6; color: inherit; }
.season-combo__empty { padding: .6rem .65rem; color: #6c757d; font-size: .9rem; }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="seasons.php">Seasons</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= $isNew ? 'Add season' : h((string)$season['name']) ?></span></nav>

<?php if (isset($_GET['saved'])): ?>
          <div class="alert alert-success">
                    <?php if (isset($_GET['wizard'])): ?>
                              <strong>Season created.</strong>
                              <?= (int) ($_GET['types'] ?? 0) > 0 ? (int) $_GET['types'] . ' season ticket type' . ((int) $_GET['types'] === 1 ? '' : 's') . ' added. ' : '' ?>
                              <?php if ((int) ($_GET['applied'] ?? 0) > 0): ?><?= (int) $_GET['applied'] ?> club-wide price change<?= (int) $_GET['applied'] === 1 ? '' : 's' ?> are now live.<?php elseif ((int) ($_GET['planned'] ?? 0) === 1): ?>Your club-wide price changes (hospitality, matchday tickets, sponsorship packages) are saved and will go live when you mark this season as current.<?php endif; ?>
                    <?php else: ?>Season saved.<?php endif; ?>
          </div>
<?php endif; ?>
<?php
$pendingPlan = !$isNew ? season_wizard_pending_plan($pdo, $seasonId) : null;
if ($pendingPlan !== null):
          $pendingCount = count($pendingPlan['hospitality']) + count($pendingPlan['tickets']);
          foreach ($pendingPlan['packages'] as $pendingPackage) { $pendingCount += count($pendingPackage); }
?>
          <div class="alert alert-warning"><i class="fa-solid fa-clock me-1" aria-hidden="true"></i><strong><?= $pendingCount ?> club-wide price change<?= $pendingCount === 1 ? ' is' : 's are' ?> waiting</strong> (hospitality, matchday tickets, sponsorship packages). They go live when you tick <em>Current season</em> on the Details tab and save.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
          <div class="alert alert-success">Season deleted.</div>
<?php endif; ?>
<?php if (isset($_GET['archived'])): ?>
          <div class="alert alert-success"><strong>Season archived.</strong> Its final league table is now in the Historical League Tables.</div>
<?php endif; ?>
<?php if ($canArchive && isset($_GET['archive_error'])): ?>
          <div class="alert alert-danger"><?= h($archiveErrors[(string) $_GET['archive_error']] ?? 'The season could not be archived.') ?></div>
<?php endif; ?>

<?php if ($isNew): ?>
<div class="card hub-section mb-4"><div class="card-body d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1">
                    <strong>Start from an earlier season</strong>
                    <div class="text-muted small">Copies match and player prices, the template pack, season ticket terms and the league. The name and dates are left blank for you to fill in.</div>
          </div>
          <form method="get" class="d-flex align-items-center gap-2 m-0">
                    <input type="hidden" name="action" value="new">
                    <label class="visually-hidden" for="copyFrom">Copy settings from</label>
                    <select class="form-select" id="copyFrom" name="copy_from" onchange="this.form.submit()">
                              <option value="0" <?= !$copySource ? 'selected' : '' ?>>Start from defaults</option>
                              <?php foreach ($copySeasonOptions as $opt): ?>
                                        <option value="<?= (int) $opt['id'] ?>" <?= $copySource && (int) $opt['id'] === (int) $copySource['id'] ? 'selected' : '' ?>><?= h((string) $opt['name']) ?><?= !empty($opt['is_current']) ? ' (current)' : '' ?></option>
                              <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-outline-secondary" type="submit">Apply</button></noscript>
          </form>
</div></div>
<?php if ($copySource): ?>
<div class="alert alert-info"><i class="fa-solid fa-copy me-1" aria-hidden="true"></i>Prefilled from <strong><?= h((string) $copySource['name']) ?></strong>. Review each tab, then enter the season name and dates.</div>
<?php endif; ?>
<?php endif; ?>

<form method="post" action="season_save.php" id="seasonForm">
          <?= csrf_field() ?>
          <input type="hidden" name="season_id" value="<?= (int)($season['id'] ?? 0) ?>">

          <ul class="nav nav-tabs reports-tabs flex-nowrap mb-4" id="seasonTabs" role="tablist">
                    <li class="nav-item" role="presentation"><a class="nav-link active" id="tab-details" href="#pane-details" data-bs-toggle="tab" data-bs-target="#pane-details" role="tab" aria-controls="pane-details" aria-selected="true">Details</a></li>
                    <li class="nav-item" role="presentation"><a class="nav-link" id="tab-graphics" href="#pane-graphics" data-bs-toggle="tab" data-bs-target="#pane-graphics" role="tab" aria-controls="pane-graphics" aria-selected="false">Graphics</a></li>
                    <li class="nav-item" role="presentation"><a class="nav-link" id="tab-pricing" href="#pane-pricing" data-bs-toggle="tab" data-bs-target="#pane-pricing" role="tab" aria-controls="pane-pricing" aria-selected="false">Pricing</a></li>
                    <li class="nav-item" role="presentation"><a class="nav-link" id="tab-tickets" href="#pane-tickets" data-bs-toggle="tab" data-bs-target="#pane-tickets" role="tab" aria-controls="pane-tickets" aria-selected="false">Season tickets</a></li>
                    <?php if ($canArchive): ?>
                    <li class="nav-item" role="presentation"><a class="nav-link" id="tab-archive" href="#pane-archive" data-bs-toggle="tab" data-bs-target="#pane-archive" role="tab" aria-controls="pane-archive" aria-selected="false">Archive</a></li>
                    <?php endif; ?>
          </ul>

          <div class="tab-content">
                    <div class="tab-pane fade show active" id="pane-details" role="tabpanel" aria-labelledby="tab-details" tabindex="0">
                              <div class="row g-4">
                                        <div class="col-lg-6">
                                                  <div class="card hub-section h-100"><div class="card-body">
                                                            <h3 class="h6 mb-3">Season</h3>
                                                            <div class="mb-3">
                                                                      <label class="form-label" for="seasonName">Season name</label>
                                                                      <input type="text" id="seasonName" name="name" class="form-control" value="<?= h((string)($season['name'] ?? '')) ?>" required>
                                                            </div>
                                                            <div class="row g-3 mb-3">
                                                                      <div class="col-sm-6">
                                                                                <label class="form-label" for="startDate">Start date</label>
                                                                                <input type="date" id="startDate" name="start_date" class="form-control" value="<?= h((string)($season['start_date'] ?? '')) ?>">
                                                                      </div>
                                                                      <div class="col-sm-6">
                                                                                <label class="form-label" for="endDate">End date</label>
                                                                                <input type="date" id="endDate" name="end_date" class="form-control" value="<?= h((string)($season['end_date'] ?? '')) ?>">
                                                                      </div>
                                                            </div>
                                                            <div class="form-check form-switch season-switch mb-3">
                                                                      <input class="form-check-input" type="checkbox" role="switch" name="is_current" value="1" id="isCurrent" <?= !empty($season['is_current']) ? 'checked' : '' ?>>
                                                                      <label class="form-check-label" for="isCurrent">Current season</label>
                                                            </div>
                                                            <div class="form-check form-switch season-switch">
                                                                      <input class="form-check-input" type="checkbox" role="switch" name="is_locked" value="1" id="isLocked" <?= !empty($season['is_locked']) ? 'checked' : '' ?>>
                                                                      <label class="form-check-label" for="isLocked">Locked</label>
                                                            </div>
                                                  </div></div>
                                        </div>

                                        <div class="col-lg-6">
                                                  <div class="card hub-section h-100"><div class="card-body">
                                                            <h3 class="h6 mb-3">League &amp; deadline</h3>
                                                            <div class="mb-3">
                                                                      <label class="form-label" for="competitionSearch">League competition</label>
                                                                      <div class="season-combo" id="competitionCombo">
                                                                                <input type="hidden" name="competition_id" id="competitionId" value="<?= $selectedCompetitionId ?>">
                                                                                <div class="input-group">
                                                                                          <span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
                                                                                          <input type="text" class="form-control" id="competitionSearch" role="combobox" aria-expanded="false" aria-controls="competitionList" aria-autocomplete="list" autocomplete="off" placeholder="Search leagues&hellip;" value="<?= h($selectedCompetitionName) ?>">
                                                                                          <button type="button" class="btn btn-outline-secondary" id="competitionClear" aria-label="Clear league" title="Clear"<?= $selectedCompetitionId > 0 ? '' : ' hidden' ?>><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                                                                </div>
                                                                                <ul class="season-combo__list" id="competitionList" role="listbox" hidden>
                                                                                          <?php foreach ($competitionOptions as $competition): ?>
                                                                                                    <li class="season-combo__item" role="option" id="competition-opt-<?= (int) $competition['id'] ?>" data-id="<?= (int) $competition['id'] ?>" data-name="<?= h((string) $competition['name']) ?>" aria-selected="<?= (int) $competition['id'] === $selectedCompetitionId ? 'true' : 'false' ?>"><span><?= h((string) $competition['name']) ?></span></li>
                                                                                          <?php endforeach; ?>
                                                                                          <li class="season-combo__empty" id="competitionEmpty" hidden>No leagues match.</li>
                                                                                </ul>
                                                                      </div>
                                                                      <div class="form-text">Start typing to search. The hub league table uses this season&rsquo;s selection.</div>
                                                            </div>
                                                            <div class="mb-0">
                                                                      <label class="form-label" for="sponsorshipDeadline">Sponsorship deadline</label>
                                                                      <input type="date" class="form-control" id="sponsorshipDeadline" name="sponsorship_deadline" value="<?= h((string)($season['sponsorship_deadline'] ?? '')) ?>" <?= $canEditDeadline ? '' : 'readonly' ?>>
                                                                      <div class="form-text">Once this date passes, player sponsorships are locked for everyone (including the sponsorship editor). Leave blank for no deadline.<?= $canEditDeadline ? '' : ' Only the club owner can change this.' ?></div>
                                                            </div>
                                                  </div></div>
                                        </div>
                              </div>
                    </div>

                    <div class="tab-pane fade" id="pane-graphics" role="tabpanel" aria-labelledby="tab-graphics" tabindex="0">
                              <div class="row g-4">
                                        <div class="col-lg-8">
                                                  <div class="card hub-section h-100"><div class="card-body">
                                                            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                                                                      <h3 class="h6 mb-0">Template pack</h3>
                                                                      <a class="btn btn-sm btn-outline-secondary" href="/admin/template_packs.php">Manage template packs</a>
                                                            </div>
                                                            <label class="form-label" for="templatePackId">Season default</label>
                                                            <select class="form-select" id="templatePackId" name="template_pack_id">
                                                                      <option value="0">Use global default<?= !empty($globalTemplatePack['pack_name']) ? ' &mdash; ' . h((string) $globalTemplatePack['pack_name']) : '' ?></option>
                                                                      <?php foreach ($availableTemplatePacks as $templatePack): ?>
                                                                                <?php $templatePackId = (int) ($templatePack['id'] ?? 0); ?>
                                                                                <option value="<?= $templatePackId ?>" <?= (int) ($seasonTemplatePack['pack_id'] ?? 0) === $templatePackId ? 'selected' : '' ?>>
                                                                                          <?= h((string) ($templatePack['name'] ?? 'Untitled pack')) ?><?= (int) ($templatePack['current_version_number'] ?? 0) > 0 ? ' · Version ' . (int) $templatePack['current_version_number'] : '' ?>
                                                                                </option>
                                                                      <?php endforeach; ?>
                                                            </select>
                                                            <div class="form-text">The coordinated graphic design used by fixtures in this season. Only published packs are shown. A fixture can still override this, and fixtures already assigned to a pack version are not changed.</div>
                                                            <?php if ($availableTemplatePacks === []): ?>
                                                                      <div class="alert alert-info mt-3 mb-0">No published template packs are available yet.</div>
                                                            <?php endif; ?>
                                                  </div></div>
                                        </div>
                              </div>
                    </div>

                    <div class="tab-pane fade" id="pane-pricing" role="tabpanel" aria-labelledby="tab-pricing" tabindex="0">
                              <div class="row g-4">
                                        <div class="col-lg-6">
                                                  <div class="card hub-section h-100"><div class="card-body">
                                                            <h3 class="h6 mb-1">Player sponsorship</h3>
                                                            <p class="text-muted small mb-3">What a sponsor pays for a player, by kit.</p>
                                                            <?= $money('Home', 'player_home_amount', $playerPricing['player_home_amount']) ?>
                                                            <?= $money('Away', 'player_away_amount', $playerPricing['player_away_amount']) ?>
                                                            <?= $money('Third', 'player_third_amount', $playerPricing['player_third_amount']) ?>
                                                  </div></div>
                                        </div>
                                        <div class="col-lg-6">
                                                  <div class="card hub-section h-100"><div class="card-body">
                                                            <h3 class="h6 mb-1">Match sponsorship</h3>
                                                            <p class="text-muted small mb-3">What a sponsor pays for a match day or the match ball.</p>
                                                            <?= $money('Match Day Home', 'match_home_amount', $matchPricing['home_amount']) ?>
                                                            <?= $money('Match Day Away', 'match_away_amount', $matchPricing['away_amount']) ?>
                                                            <?= $money('Match Ball', 'match_ball_amount', $matchPricing['match_ball_amount']) ?>
                                                  </div></div>
                                        </div>
                              </div>
                              <?php if (!$isNew): ?>
                              <div class="card hub-section mt-4"><div class="card-body">
                                        <div class="form-check form-switch season-switch mb-1">
                                                  <input class="form-check-input" type="checkbox" role="switch" id="repriceExisting" name="reprice_existing" value="1">
                                                  <label class="form-check-label" for="repriceExisting">Also update existing sponsorships to these prices</label>
                                        </div>
                                        <p class="text-muted small mb-0">Off by default: changed prices only apply to sponsorships added from now on, so what current sponsors owe never changes by accident. Turn it on to re-price player and match-day sponsorships in this season. Paid placements and season deals keep their agreed amount.</p>
                              </div></div>
                              <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="pane-tickets" role="tabpanel" aria-labelledby="tab-tickets" tabindex="0">
                              <div class="card hub-section"><div class="card-body">
                                        <h3 class="h6 mb-1">Terms and conditions</h3>
                                        <p class="text-muted small mb-3">Shown to customers during public season ticket checkout.</p>
                                        <textarea class="form-control" id="seasonTicketTerms" name="season_ticket_terms" rows="14" aria-label="Season ticket terms and conditions"><?= h($seasonTicketTerms) ?></textarea>
                                        <div class="form-text">Use <code>{season_name}</code> and <code>{league_name}</code> to keep the terms reusable when the season or league changes.</div>
                              </div></div>
                    </div>

                    <?php if ($canArchive): ?>
                    <div class="tab-pane fade" id="pane-archive" role="tabpanel" aria-labelledby="tab-archive" tabindex="0">
                              <div class="card hub-section"><div class="card-body">
                                        <h3 class="h6 mb-1">Archive this season</h3>
                                        <p class="text-muted small mb-3">Run this once the season has finished. It closes the season off in one step.</p>

                                        <div class="season-archive-opt">
                                                  <input class="form-check-input" type="checkbox" id="archiveTable" name="archive_table" value="1" form="seasonArchiveForm" checked>
                                                  <label for="archiveTable">
                                                            <strong>Save the final league table to history</strong>
                                                            <span class="d-block text-muted small">
                                                                      <?= count($archiveSnapshot['rows']) ?> clubs from the live table<?= $archiveSnapshot['updated'] ? ', last updated ' . h(date('j M Y, H:i', $archiveSnapshot['updated'])) : '' ?>
                                                                      &middot; <?= h($archiveSnapshot['title'] !== '' ? $archiveSnapshot['title'] : 'competition not set') ?>.
                                                                      It then appears on the public season picker once this is no longer the current season.
                                                                      <?php if ($archivedAlready !== null): ?><span class="text-success d-block"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Already archived once &mdash; this replaces it.</span><?php endif; ?>
                                                            </span>
                                                  </label>
                                        </div>
                                        <div class="season-archive-opt">
                                                  <input class="form-check-input" type="checkbox" id="archiveLock" name="archive_lock" value="1" form="seasonArchiveForm" <?= empty($season['is_locked']) ? 'checked' : 'disabled' ?>>
                                                  <label for="archiveLock">
                                                            <strong>Lock the season</strong>
                                                            <span class="d-block text-muted small"><?= empty($season['is_locked']) ? 'Locked seasons can no longer be edited here.' : 'This season is already locked.' ?></span>
                                                  </label>
                                        </div>

                                        <?php if (!empty($season['end_date']) && strtotime((string) $season['end_date']) > time()): ?>
                                                  <div class="alert alert-warning py-2 mt-3 mb-0">This season&rsquo;s end date (<?= h(date('j M Y', strtotime((string) $season['end_date']))) ?>) hasn&rsquo;t passed yet.</div>
                                        <?php endif; ?>

                                        <div class="alert alert-light border small mt-3 mb-3">
                                                  <strong>Not done for you:</strong> moving to the next season. Afterwards, add the new season, mark it as current, and point the WOSFL link at the new league. Archive first, because the live table is replaced once the link changes.
                                        </div>

                                        <button type="submit" class="btn btn-brand" form="seasonArchiveForm" data-confirm="Archive <?= h((string) $season['name']) ?>? This saves its final table to history<?= empty($season['is_locked']) ? ' and can lock the season' : '' ?>." data-confirm-action="Archive"><i class="fa-solid fa-box-archive me-1" aria-hidden="true"></i>Archive season</button>
                              </div></div>
                    </div>
                    <?php endif; ?>
          </div>

          <div class="settings-savebar">
                    <?php if (!$isNew): ?>
                              <a href="season_delete.php?id=<?= (int)$seasonId ?>" class="btn btn-outline-danger"><i class="fa-regular fa-trash-can me-1" aria-hidden="true"></i>Delete season</a>
                    <?php else: ?>
                              <span></span>
                    <?php endif; ?>
                    <span class="d-flex gap-2">
                              <a href="seasons.php" class="btn btn-outline-secondary">Cancel</a>
                              <button type="submit" class="btn btn-primary">Save season</button>
                    </span>
          </div>
</form>

<?php if ($canArchive): ?>
<form method="post" action="season_archive.php" id="seasonArchiveForm" hidden>
          <?= csrf_field() ?>
          <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
</form>
<?php endif; ?>

<script>
(function () {
          // ---- Money inputs: digits and one decimal point, shown to 2dp ----
          function clean(v) {
                    v = String(v).replace(/[^0-9.]/g, '');
                    var parts = v.split('.');
                    return parts.length > 1 ? parts[0] + '.' + parts.slice(1).join('').slice(0, 2) : v;
          }
          document.querySelectorAll('[data-money]').forEach(function (input) {
                    input.addEventListener('input', function () {
                              var pos = input.selectionStart, before = input.value;
                              input.value = clean(before);
                              if (input.value !== before) { input.setSelectionRange(pos - 1, pos - 1); }
                    });
                    input.addEventListener('focus', function () { input.select(); });
                    input.addEventListener('blur', function () {
                              var n = parseFloat(clean(input.value));
                              input.value = (isNaN(n) ? 0 : n).toFixed(2);
                    });
          });

          // ---- Tabs: reopen the tab named in the address ----
          var hash = location.hash.replace('#', '');
          var trigger = hash ? document.querySelector('[data-bs-target="#' + hash + '"]') : null;
          if (trigger) {
                    window.addEventListener('load', function () {
                              if (window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(trigger).show(); }
                    });
          }
          document.querySelectorAll('#seasonTabs [data-bs-toggle="tab"]').forEach(function (tab) {
                    tab.addEventListener('click', function (e) { e.preventDefault(); });
                    tab.addEventListener('shown.bs.tab', function () { history.replaceState(null, '', tab.getAttribute('data-bs-target')); });
          });

          // ---- League competition: live-search combobox ----
          var combo = document.getElementById('competitionCombo');
          if (!combo) { return; }
          var input = document.getElementById('competitionSearch');
          var hidden = document.getElementById('competitionId');
          var list = document.getElementById('competitionList');
          var empty = document.getElementById('competitionEmpty');
          var clear = document.getElementById('competitionClear');
          var items = Array.prototype.slice.call(list.querySelectorAll('.season-combo__item'));
          var active = -1;

          function esc(t) { return t.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
          function visible() { return items.filter(function (li) { return !li.hidden; }); }
          function setActive(i) {
                    var v = visible();
                    v.forEach(function (li) { li.classList.remove('is-active'); });
                    active = v.length ? (i + v.length) % v.length : -1;
                    if (active >= 0) {
                              v[active].classList.add('is-active');
                              v[active].scrollIntoView({ block: 'nearest' });
                              input.setAttribute('aria-activedescendant', v[active].id);
                    }
          }
          function open() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
          function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; items.forEach(function (li) { li.classList.remove('is-active'); }); }
          function filter() {
                    var q = input.value.trim().toLowerCase();
                    items.forEach(function (li) {
                              var name = li.getAttribute('data-name');
                              var at = q === '' ? -1 : name.toLowerCase().indexOf(q);
                              li.hidden = q !== '' && at === -1;
                              li.firstChild.innerHTML = (q !== '' && at > -1)
                                        ? esc(name.slice(0, at)) + '<mark>' + esc(name.slice(at, at + q.length)) + '</mark>' + esc(name.slice(at + q.length))
                                        : esc(name);
                    });
                    empty.hidden = visible().length !== 0;
                    setActive(visible().length ? 0 : -1);
          }
          function choose(id, name) {
                    hidden.value = id;
                    input.value = name;
                    items.forEach(function (li) { li.setAttribute('aria-selected', li.getAttribute('data-id') === String(id) ? 'true' : 'false'); });
                    clear.hidden = !(parseInt(id, 10) > 0);
                    close();
          }
          function currentName() {
                    var sel = items.filter(function (li) { return li.getAttribute('data-id') === hidden.value; })[0];
                    return sel ? sel.getAttribute('data-name') : '';
          }

          input.addEventListener('focus', function () { input.select(); filterShowAll(); });
          function filterShowAll() { items.forEach(function (li) { li.hidden = false; li.firstChild.textContent = li.getAttribute('data-name'); }); empty.hidden = true; open(); }
          input.addEventListener('input', function () { open(); filter(); });
          input.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { filterShowAll(); } setActive(active + 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
                    else if (e.key === 'Enter') {
                              if (!list.hidden && active >= 0) { e.preventDefault(); var li = visible()[active]; choose(li.getAttribute('data-id'), li.getAttribute('data-name')); }
                              else if (!list.hidden) { e.preventDefault(); }
                    }
                    else if (e.key === 'Escape') { input.value = currentName(); close(); }
          });
          list.addEventListener('mousedown', function (e) {
                    var li = e.target.closest('.season-combo__item');
                    if (li) { e.preventDefault(); choose(li.getAttribute('data-id'), li.getAttribute('data-name')); }
          });
          clear.addEventListener('click', function () { choose(0, ''); input.focus(); });
          input.addEventListener('blur', function () {
                    // Leaving the box without picking restores the saved choice; an emptied box means "no league".
                    if (input.value.trim() === '') { hidden.value = 0; clear.hidden = true; items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); }); }
                    else { input.value = currentName(); }
                    close();
          });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
