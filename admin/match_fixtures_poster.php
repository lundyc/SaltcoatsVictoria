<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$seasonId = getSelectedSeasonId($pdo);
if (isset($_GET['season_id'])) {
          $requestedSeasonId = (int)$_GET['season_id'];
          if ($requestedSeasonId > 0) {
                    $seasonId = $requestedSeasonId;
          }
}

$season = getSeasonById($pdo, $seasonId);

$defaultBackgroundImage = '../assets/images/background.png';
$backgroundDir = __DIR__ . '/uploads/match_fixtures';
$backgroundImage = trim((string)($_GET['background_image'] ?? (matchPosterSeasonBackgroundImage($seasonId) ?? $defaultBackgroundImage)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['background_image_file'])) {
          if (!csrf_check()) {
                    http_response_code(400);
                    exit('Invalid CSRF token.');
          }

          $file = $_FILES['background_image_file'];
          if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                              exit('Background upload failed.');
                    }
                    if (!is_uploaded_file($file['tmp_name'])) {
                              exit('Invalid background upload.');
                    }

                    $mimeInfo = @getimagesize($file['tmp_name']);
                    if (!$mimeInfo || !in_array($mimeInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
                              exit('Please upload a PNG, JPG, or WEBP image.');
                    }

                    if (!is_dir($backgroundDir) && !mkdir($backgroundDir, 0775, true) && !is_dir($backgroundDir)) {
                              exit('Failed to prepare background upload directory.');
                    }

                    $extension = match ($mimeInfo[2]) {
                              IMAGETYPE_PNG => 'png',
                              IMAGETYPE_JPEG => 'jpg',
                              IMAGETYPE_WEBP => 'webp',
                    };
                    $filename = sprintf('match_poster_bg_season_%d.%s', $seasonId, $extension);
                    $destination = $backgroundDir . DIRECTORY_SEPARATOR . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                              exit('Failed to store background image.');
                    }

                    $backgroundImage = 'uploads/match_fixtures/' . $filename;
                    header('Location: match_fixtures_poster.php?season_id=' . (int)$seasonId . '&background_image=' . urlencode($backgroundImage) . '&uploaded=1');
                    exit;
          }
}

$view = (($_GET['view'] ?? '') === 'results') ? 'results' : 'fixtures';

$fixtures = getMatchFixtures($pdo, $seasonId);

// Competitions present this season, for the "show only these" filter.
$competitionOptions = [];
foreach ($fixtures as $fixtureRow) {
          $competition = trim((string)($fixtureRow['competition'] ?? ''));
          if ($competition !== '') {
                    $competitionOptions[$competition] = true;
          }
}
$competitionOptions = array_keys($competitionOptions);
sort($competitionOptions, SORT_NATURAL | SORT_FLAG_CASE);

// null = show all. A subset is kept as-is; "all of them" and "none valid"
// both collapse back to null so the URL stays clean and the poster never
// comes back empty just because every box was unticked.
$selectedCompetitions = $_GET['competitions'] ?? null;
if (!is_array($selectedCompetitions)) {
          $selectedCompetitions = null;
} else {
          $selectedCompetitions = array_values(array_intersect($competitionOptions, array_map('strval', $selectedCompetitions)));
          if ($selectedCompetitions === [] || count($selectedCompetitions) === count($competitionOptions)) {
                    $selectedCompetitions = null;
          }
}

$commonParams = ['season_id' => $seasonId];
if (isset($_GET['background_image'])) {
          $commonParams['background_image'] = $backgroundImage;
}
if ($selectedCompetitions !== null) {
          $commonParams['competitions'] = $selectedCompetitions;
}

$previewParams = $commonParams + ['view' => $view];
$previewUrl = 'match_fixtures_poster_render.php?' . http_build_query($previewParams);

// Base for the view-toggle links — keeps background + competition choices.
$viewToggleBase = 'match_fixtures_poster.php?' . http_build_query($commonParams);
?>

<div class="page-hero mb-4">
                    <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                    <div>
                              <div class="page-hero-eyebrow">Creative studio</div>
                              <h1 class="page-hero-title">Fixture Poster</h1>
                              <p class="page-hero-subtitle"><?= h($season['name'] ?? 'Unknown season') ?></p>
                    </div>
          </div>
</div>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="matches.php?season_id=<?= (int)$seasonId ?>">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Fixture poster</span></nav>
<div class="hub-section-commandbar"><div><h2>Poster workspace</h2><p>Configure the season artwork and export the fixture list &mdash; or switch to results to show colour-coded W/L/D and scores.</p></div><div class="hub-local-actions">
          <div class="btn-group btn-group-sm" role="group" aria-label="Poster contents">
                    <a href="<?= h($viewToggleBase) ?>&amp;view=fixtures" class="btn btn-outline-secondary <?= $view === 'fixtures' ? 'active' : '' ?>"<?= $view === 'fixtures' ? ' aria-current="true"' : '' ?>>Fixtures</a>
                    <a href="<?= h($viewToggleBase) ?>&amp;view=results" class="btn btn-outline-secondary <?= $view === 'results' ? 'active' : '' ?>"<?= $view === 'results' ? ' aria-current="true"' : '' ?>>Fixtures &amp; results</a>
          </div>
          <?php if ($competitionOptions): ?>
          <div class="dropdown d-inline-block" id="posterCompetitionFilter">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                              <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Competitions<?php if ($selectedCompetitions !== null): ?> <span class="badge text-bg-secondary ms-1"><?= count($selectedCompetitions) ?>/<?= count($competitionOptions) ?></span><?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 22rem; max-width: 90vw;">
                              <form method="get">
                                        <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                        <input type="hidden" name="view" value="<?= h($view) ?>">
                                        <?php if (isset($_GET['background_image'])): ?><input type="hidden" name="background_image" value="<?= h($backgroundImage) ?>"><?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                  <span class="fw-semibold small text-uppercase text-muted">Show these competitions</span>
                                                  <button type="button" class="btn btn-link btn-sm p-0" data-poster-competitions-all>Select all</button>
                                        </div>
                                        <?php foreach ($competitionOptions as $competition): ?>
                                                  <?php $checkboxId = 'posterComp_' . substr(md5($competition), 0, 10); ?>
                                                  <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="competitions[]" value="<?= h($competition) ?>" id="<?= h($checkboxId) ?>" <?= ($selectedCompetitions === null || in_array($competition, $selectedCompetitions, true)) ? 'checked' : '' ?> data-poster-competition>
                                                            <label class="form-check-label" for="<?= h($checkboxId) ?>"><?= h($competition) ?></label>
                                                  </div>
                                        <?php endforeach; ?>
                                        <div class="d-grid mt-3">
                                                  <button type="submit" class="btn btn-brand btn-sm">Apply</button>
                                        </div>
                              </form>
                    </div>
          </div>
          <?php endif; ?>
          <a href="<?= h($previewUrl) ?>" class="btn btn-brand btn-sm" target="_blank"><i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download PNG</a>
</div></div>
<?php if ($competitionOptions): ?>
<script>
          (function () {
                    var wrap = document.getElementById('posterCompetitionFilter');
                    if (!wrap) { return; }
                    var selectAll = wrap.querySelector('[data-poster-competitions-all]');
                    if (selectAll) {
                              selectAll.addEventListener('click', function () {
                                        wrap.querySelectorAll('[data-poster-competition]').forEach(function (box) { box.checked = true; });
                              });
                    }
          })();
</script>
<?php endif; ?>

<?php if (isset($_GET['uploaded'])): ?>
          <div class="alert alert-success">Background image uploaded.</div>
<?php endif; ?>

<div class="row g-3">
          <div class="col-12 col-xl-4">
                    <div class="card shadow-sm">
                              <div class="card-body">
                                        <form method="get" class="vstack gap-3 mb-3">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="view" value="<?= h($view) ?>">
                                                  <div>
                                                            <label class="form-label">Season</label>
                                                            <select name="season_id" class="form-select">
                                                                      <?php foreach (getSeasons($pdo) as $option): ?>
                                                                                <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === (int)$seasonId ? 'selected' : '' ?>>
                                                                                          <?= h((string)$option['name']) ?>
                                                                               </option>
                                                                      <?php endforeach; ?>
                                                            </select>
                                                  </div>
                                                  <div class="d-grid">
                                                            <button type="submit" class="btn btn-outline-secondary">Change Season</button>
                                                  </div>
                                        </form>

                                        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                  <div>
                                                            <label class="form-label">Upload background image</label>
                                                            <input type="file" name="background_image_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                                                            <div class="form-text">Upload the full poster background. PNG, JPG, or WEBP only.</div>
                                                  </div>
                                                  <div class="d-grid gap-2">
                                                            <button type="submit" class="btn btn-brand">Upload Background</button>
                                                            <a href="<?= h($previewUrl) ?>" class="btn btn-outline-secondary" target="_blank">Open Render</a>
                                                  </div>
                                        </form>
                              </div>
                    </div>

          </div>

          <div class="col-12 col-xl-8">
                    <div class="card shadow-sm rounded-0 overflow-hidden">
                              <div class="card-body p-0">
                                        <div class="poster-stage">
                                                  <img src="<?= h($previewUrl) ?>" alt="Fixture poster preview" class="poster-stage__image">
                                        </div>
                              </div>
                    </div>
          </div>
</div>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-fixtures-poster.css">

<?php require_once __DIR__ . '/footer.php'; ?>
