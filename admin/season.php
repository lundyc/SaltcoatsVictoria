<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/template_packs.php';

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
          if (!$season) {
                    echo '<div><div class="alert alert-danger">Season not found.</div></div>';
                    require_once __DIR__ . '/footer.php';
                    exit;
          }
}

$playerPricing = $isNew
          ? ['player_home_amount' => 50.00, 'player_away_amount' => 30.00, 'player_third_amount' => 20.00]
          : getSeasonPlayerPricing($pdo, $seasonId);
$matchPricing = $isNew
          ? ['home_amount' => 50.00, 'away_amount' => 20.00, 'match_ball_amount' => 25.00]
          : getMatchSeasonPricing($pdo, $seasonId);
$selectedCompetitionId = (int) ($season['competition_id'] ?? 0);
$availableTemplatePacks = template_packs_list_available($pdo);
$seasonTemplatePack = !$isNew ? template_packs_get_season_default($pdo, $seasonId) : null;
$globalTemplatePack = template_packs_get_global_default($pdo);
$seasonTicketTerms = seasonTicketTermsTemplateForSeason($season);
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="seasons.php">Seasons</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $isNew ? 'Add season' : h((string)$season['name']) ?></span></nav>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
          <div>
                    <h1 class="h3 mb-1"><?= $isNew ? 'Add Season' : h($season['name']) ?></h1>
                    <div class="text-muted">Season setup and match sponsorship pricing.</div>
          </div>
</div>

<?php if (isset($_GET['saved'])): ?>
          <div class="alert alert-success">Season saved.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
          <div class="alert alert-success">Season deleted.</div>
<?php endif; ?>

<div class="card shadow-sm">
          <div class="card-body">
                    <form method="post" action="season_save.php">
                              <?= csrf_field() ?>
                              <input type="hidden" name="season_id" value="<?= (int)($season['id'] ?? 0) ?>">
                              <div class="row g-3">
                                        <div class="col-md-6">
                                                  <label class="form-label">Season Name</label>
                                                  <input type="text" name="name" class="form-control" value="<?= h((string)($season['name'] ?? '')) ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                                  <label class="form-label">Start Date</label>
                                                  <input type="date" name="start_date" class="form-control" value="<?= h((string)($season['start_date'] ?? '')) ?>">
                                        </div>
                                        <div class="col-md-3">
                                                  <label class="form-label">End Date</label>
                                                  <input type="date" name="end_date" class="form-control" value="<?= h((string)($season['end_date'] ?? '')) ?>">
                                        </div>
                                        <div class="col-md-6">
                                                  <div class="form-check mt-4">
                                                            <input class="form-check-input" type="checkbox" name="is_current" value="1" id="isCurrent" <?= !empty($season['is_current']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="isCurrent">Current season</label>
                                                  </div>
                                        </div>
                                        <div class="col-md-6">
                                                  <div class="form-check mt-4">
                                                            <input class="form-check-input" type="checkbox" name="is_locked" value="1" id="isLocked" <?= !empty($season['is_locked']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="isLocked">Locked</label>
                                                  </div>
                                        </div>
                                        <div class="col-md-6">
                                                  <label class="form-label" for="sponsorshipDeadline">Sponsorship deadline</label>
                                                  <input type="date" class="form-control" id="sponsorshipDeadline" name="sponsorship_deadline" value="<?= h((string)($season['sponsorship_deadline'] ?? '')) ?>" <?= hub_auth_is_sponsorship_editor() ? '' : 'readonly' ?>>
                                                  <div class="form-text">Once this date passes, player sponsorships are locked for everyone (including the sponsorship editor) and can no longer be changed. Leave blank for no deadline.<?= hub_auth_is_sponsorship_editor() ? '' : ' Only the club owner can change this.' ?></div>
                                        </div>
                                        <div class="col-md-12">
                                                  <label class="form-label">League competition</label>
                                                  <select name="competition_id" class="form-select">
                                                            <option value="0">No league selected</option>
                                                            <?php foreach ($competitionOptions as $competition): ?>
                                                                      <option value="<?= (int) $competition['id'] ?>" <?= (int) $competition['id'] === $selectedCompetitionId ? 'selected' : '' ?>>
                                                                                <?= h((string) $competition['name']) ?>
                                                                                <?php if (!empty($competition['is_league'])): ?>
                                                                                          (League)
                                                                                <?php endif; ?>
                                                                      </option>
                                                            <?php endforeach; ?>
                                                  </select>
                                                  <div class="form-text">Pick the league this season belongs to. The hub league table uses this season’s selection.</div>
                                        </div>
                                        <div class="col-md-12">
                                                  <label class="form-label" for="seasonTicketTerms">Season ticket terms and conditions</label>
                                                  <textarea class="form-control" id="seasonTicketTerms" name="season_ticket_terms" rows="12"><?= h($seasonTicketTerms) ?></textarea>
                                                  <div class="form-text">Shown during public checkout. Use <code>{season_name}</code> and <code>{league_name}</code> to keep the terms reusable when the season or league changes.</div>
                                        </div>
                              </div>

                              <hr class="my-4">

                              <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-2 mb-3">
                                        <div>
                                                  <h5 class="mb-1">Template Pack</h5>
                                                  <p class="text-muted mb-0">Choose the coordinated graphic design used by fixtures in this season.</p>
                                        </div>
                                        <a class="btn btn-sm btn-outline-secondary" href="/admin/template_packs.php">Manage Template Packs</a>
                              </div>
                              <div class="row g-3">
                                        <div class="col-lg-8">
                                                  <label class="form-label" for="templatePackId">Season default</label>
                                                  <select class="form-select" id="templatePackId" name="template_pack_id">
                                                            <option value="0">
                                                                      Use global default<?= !empty($globalTemplatePack['pack_name']) ? ' — ' . h((string) $globalTemplatePack['pack_name']) : '' ?>
                                                            </option>
                                                            <?php foreach ($availableTemplatePacks as $templatePack): ?>
                                                                      <?php $templatePackId = (int) ($templatePack['id'] ?? 0); ?>
                                                                      <option value="<?= $templatePackId ?>" <?= (int) ($seasonTemplatePack['pack_id'] ?? 0) === $templatePackId ? 'selected' : '' ?>>
                                                                                <?= h((string) ($templatePack['name'] ?? 'Untitled pack')) ?>
                                                                                <?php if ((int) ($templatePack['current_version_number'] ?? 0) > 0): ?>
                                                                                          · Version <?= (int) $templatePack['current_version_number'] ?>
                                                                                <?php endif; ?>
                                                                      </option>
                                                            <?php endforeach; ?>
                                                  </select>
                                                  <div class="form-text">Only published packs are shown. A fixture can still override this selection, and fixtures already assigned to a pack version remain unchanged.</div>
                                        </div>
                              </div>

                              <?php if ($availableTemplatePacks === []): ?>
                                        <div class="alert alert-info mt-3 mb-0">No published template packs are available yet.</div>
                              <?php endif; ?>

                              <hr class="my-4">

                              <h5 class="mb-3">Player Sponsorship Pricing</h5>
                              <div class="row g-3">
                                        <div class="col-md-4">
                                                  <label class="form-label">Home</label>
                                                  <input type="number" step="0.01" min="0" name="player_home_amount" class="form-control" value="<?= h((string)$playerPricing['player_home_amount']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                                  <label class="form-label">Away</label>
                                                  <input type="number" step="0.01" min="0" name="player_away_amount" class="form-control" value="<?= h((string)$playerPricing['player_away_amount']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                                  <label class="form-label">Third</label>
                                                  <input type="number" step="0.01" min="0" name="player_third_amount" class="form-control" value="<?= h((string)$playerPricing['player_third_amount']) ?>">
                                        </div>
                              </div>

                              <hr class="my-4">

                              <h5 class="mb-3">Match Sponsorship Pricing</h5>
                              <div class="row g-3">
                                        <div class="col-md-4">
                                                  <label class="form-label">Match Day Home</label>
                                                  <input type="number" step="0.01" min="0" name="match_home_amount" class="form-control" value="<?= h((string)$matchPricing['home_amount']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                                  <label class="form-label">Match Day Away</label>
                                                  <input type="number" step="0.01" min="0" name="match_away_amount" class="form-control" value="<?= h((string)$matchPricing['away_amount']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                                  <label class="form-label">Match Ball</label>
                                                  <input type="number" step="0.01" min="0" name="match_ball_amount" class="form-control" value="<?= h((string)$matchPricing['match_ball_amount']) ?>">
                                        </div>
                              </div>

                              <div class="mt-4 d-flex flex-column-reverse flex-md-row gap-2">
                                        <button type="submit" class="btn btn-brand">Save Season</button>
                                        <a href="seasons.php" class="btn btn-outline-secondary">Cancel</a>
                              </div>
                    </form>
          </div>
</div>

<?php if (!$isNew): ?>
<section class="card border-danger-subtle mt-4"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3"><div><h2 class="h6 mb-1">Danger zone</h2><p class="text-muted small mb-0">Review the impact before permanently deleting this season.</p></div><a href="season_delete.php?id=<?= (int)$seasonId ?>" class="btn btn-outline-danger">Delete season</a></div></section>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
