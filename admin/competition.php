<?php
$pageStyles = ['competition-form.css'];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/competition_structure.php';
require_once __DIR__ . '/lib/audit.php';

function competition_handle_image_upload(array $file, string $filenamePrefix, string $label): array
{
          $uploadDir = __DIR__ . '/uploads/competitions';
          if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    return ['path' => '', 'error' => ''];
          }
          if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    return ['path' => '', 'error' => $label . ' upload failed.'];
          }

          $tmpName = (string)($file['tmp_name'] ?? '');
          if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    return ['path' => '', 'error' => 'Uploaded image could not be validated.'];
          }
          $imageInfo = @getimagesize($tmpName);
          if ($imageInfo === false) {
                    return ['path' => '', 'error' => 'Please upload a valid image.'];
          }

          $extensionMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
          $mime = (string)($imageInfo['mime'] ?? '');
          if (!isset($extensionMap[$mime])) {
                    return ['path' => '', 'error' => 'Only JPG, PNG, or WebP images are allowed.'];
          }
          if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    return ['path' => '', 'error' => 'The upload directory could not be created.'];
          }

          $filename = $filenamePrefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensionMap[$mime];
          if (!move_uploaded_file($tmpName, $uploadDir . '/' . $filename)) {
                    return ['path' => '', 'error' => 'The uploaded image could not be saved.'];
          }
          return ['path' => 'uploads/competitions/' . $filename, 'error' => ''];
}

function competition_remove_unsaved_uploads(array $paths): void
{
          foreach ($paths as $path) {
                    if ($path === '') {
                              continue;
                    }
                    $absolutePath = __DIR__ . '/' . ltrim((string)$path, '/');
                    if (is_file($absolutePath)) {
                              @unlink($absolutePath);
                    }
          }
}

function competition_edition_form_values(array $edition, array $season): array
{
          return [
                    'id' => (int)($edition['id'] ?? 0),
                    'season_id' => (int)$season['id'],
                    'present' => !empty($edition['is_active']),
                    'display_name' => (string)($edition['display_name'] ?? ''),
                    'sponsor_title' => (string)($edition['sponsor_title'] ?? ''),
                    'competition_url' => (string)($edition['competition_url'] ?? ''),
                    'promotion_spots' => isset($edition['promotion_spots']) ? (string)$edition['promotion_spots'] : '',
                    'relegation_spots' => isset($edition['relegation_spots']) ? (string)$edition['relegation_spots'] : '',
                    'show_table_lines' => array_key_exists('show_table_lines', $edition) && $edition['show_table_lines'] !== null ? (string)(int)$edition['show_table_lines'] : '',
                    'notes' => (string)($edition['notes'] ?? ''),
                    'historical_confirm' => false,
          ];
}

$id = (int)($_GET['id'] ?? 0);
$action = (string)($_GET['action'] ?? ($id > 0 ? 'edit' : 'new'));
if (!in_array($action, ['new', 'edit'], true)) {
          $action = $id > 0 ? 'edit' : 'new';
}

ensureCompetitionStructureSchema($pdo);
$errors = [];
if ($action === 'edit') {
          if ($id <= 0) {
                    echo '<div class="alert alert-danger">Invalid competition ID.</div>';
                    require __DIR__ . '/footer.php';
                    exit;
          }
          $competition = getMatchCompetitionById($pdo, $id);
          if (!$competition) {
                    echo '<div class="alert alert-danger">Competition not found.</div>';
                    require __DIR__ . '/footer.php';
                    exit;
          }
} else {
          $competition = [
                    'name' => '', 'organiser' => '', 'competition_type' => 'cup',
                    'sort_order' => getNextMatchCompetitionSortOrder($pdo), 'is_league' => 0,
                    'league_url' => '', 'league_banner_image' => '', 'badge_image' => '',
                    'white_badge_image' => '', 'promotion_spots' => '', 'relegation_spots' => '',
                    'show_table_lines' => 1,
          ];
}

$formName = (string)($competition['name'] ?? '');
$formOrganiser = (string)($competition['organiser'] ?? '');
$formCompetitionType = (string)($competition['competition_type'] ?? (!empty($competition['is_league']) ? 'league' : 'other'));
if (!in_array($formCompetitionType, ['league', 'cup', 'friendly', 'tournament', 'other'], true)) {
          $formCompetitionType = 'other';
}
$formSortOrder = (string)($competition['sort_order'] ?? getNextMatchCompetitionSortOrder($pdo));
$formLeagueBannerImage = (string)($competition['league_banner_image'] ?? '');
$formBadgeImage = (string)($competition['badge_image'] ?? '');
$formWhiteBadgeImage = (string)($competition['white_badge_image'] ?? '');

$seasons = getSeasons($pdo);
usort($seasons, static function (array $left, array $right): int {
          $currentComparison = (int)!empty($right['is_current']) <=> (int)!empty($left['is_current']);
          if ($currentComparison !== 0) {
                    return $currentComparison;
          }
          $dateComparison = strcmp((string)($right['start_date'] ?? ''), (string)($left['start_date'] ?? ''));
          return $dateComparison !== 0 ? $dateComparison : ((int)$right['id'] <=> (int)$left['id']);
});
$seasonById = [];
foreach ($seasons as $season) {
          $seasonById[(int)$season['id']] = $season;
}

$existingEditions = $action === 'edit' ? competitionStructureListEditions($pdo, $id, null, false) : [];
$existingEditionBySeason = [];
foreach ($existingEditions as $edition) {
          $existingEditionBySeason[(int)$edition['season_id']] = $edition;
}
$formEditionBySeason = [];
foreach ($seasons as $season) {
          $seasonId = (int)$season['id'];
          $formEditionBySeason[$seasonId] = competition_edition_form_values($existingEditionBySeason[$seasonId] ?? [], $season);
}
$existingAliases = $action === 'edit' ? competitionStructureListAliases($pdo, $id) : [];
$genericAliases = array_values(array_filter($existingAliases, static function (array $alias): bool {
          return empty($alias['season_id'])
                    && trim((string)($alias['organiser'] ?? '')) === ''
                    && trim((string)($alias['source_name'] ?? '')) === ''
                    && trim((string)($alias['source_reference'] ?? '')) === '';
}));
$formAliases = array_values(array_unique(array_filter(array_map(
          static fn(array $alias): string => trim((string)($alias['alias_name'] ?? '')),
          $genericAliases
))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $errors[] = 'Invalid security token. Please try again.';
          }
          $formName = trim((string)($_POST['name'] ?? ''));
          $formOrganiser = trim((string)($_POST['organiser'] ?? ''));
          $formCompetitionType = (string)($_POST['competition_type'] ?? 'other');
          $formSortOrder = trim((string)($_POST['sort_order'] ?? ''));
          $postedAliases = preg_split('/\R/u', (string)($_POST['aliases'] ?? '')) ?: [];
          $formAliases = [];
          $aliasKeys = [];
          foreach ($postedAliases as $postedAlias) {
                    $postedAlias = trim($postedAlias);
                    if ($postedAlias === '') {
                              continue;
                    }
                    if (mb_strlen($postedAlias) > 190) {
                              $errors[] = 'Competition aliases must be 190 characters or fewer.';
                              continue;
                    }
                    $aliasKey = mb_strtolower($postedAlias);
                    if (isset($aliasKeys[$aliasKey])) {
                              continue;
                    }
                    $aliasKeys[$aliasKey] = true;
                    $formAliases[] = $postedAlias;
          }
          if ($formName === '') {
                    $errors[] = 'Competition name is required.';
          }
          if (!in_array($formCompetitionType, ['league', 'cup', 'friendly', 'tournament', 'other'], true)) {
                    $errors[] = 'Choose a valid competition type.';
                    $formCompetitionType = 'other';
          }

          $postedEditions = $_POST['editions'] ?? [];
          if (!is_array($postedEditions)) {
                    $postedEditions = [];
                    $errors[] = 'The season edition information is invalid.';
          }
          foreach ($seasons as $season) {
                    $seasonId = (int)$season['id'];
                    $row = isset($postedEditions[$seasonId]) && is_array($postedEditions[$seasonId]) ? $postedEditions[$seasonId] : [];
                    $existingEdition = $existingEditionBySeason[$seasonId] ?? null;
                    $isProtected = !empty($season['is_locked']) && !empty($existingEdition['is_active']);
                    if ($isProtected) {
                              $formEditionBySeason[$seasonId] = competition_edition_form_values($existingEdition, $season);
                              continue;
                    }

                    $present = (string)($row['present'] ?? '0') === '1';
                    $showTableLines = (string)($row['show_table_lines'] ?? '');
                    if (!in_array($showTableLines, ['', '0', '1'], true)) {
                              $showTableLines = '';
                    }
                    $formEditionBySeason[$seasonId] = [
                              'id' => (int)($existingEdition['id'] ?? 0),
                              'season_id' => $seasonId,
                              'present' => $present,
                              'display_name' => trim((string)($row['display_name'] ?? '')),
                              'sponsor_title' => trim((string)($row['sponsor_title'] ?? '')),
                              'competition_url' => trim((string)($row['competition_url'] ?? '')),
                              'promotion_spots' => trim((string)($row['promotion_spots'] ?? '')),
                              'relegation_spots' => trim((string)($row['relegation_spots'] ?? '')),
                              'show_table_lines' => $showTableLines,
                              'notes' => trim((string)($row['notes'] ?? '')),
                              'historical_confirm' => isset($row['historical_confirm']),
                    ];
                    if ($present && !empty($season['is_locked']) && empty($existingEdition['is_active']) && !isset($row['historical_confirm'])) {
                              $errors[] = 'Confirm the historical assignment for the locked ' . (string)$season['name'] . ' season.';
                    }
                    if ($present && $formEditionBySeason[$seasonId]['competition_url'] !== '' && filter_var($formEditionBySeason[$seasonId]['competition_url'], FILTER_VALIDATE_URL) === false) {
                              $errors[] = 'Enter a valid competition URL for ' . (string)$season['name'] . '.';
                    }
          }

          $previousBannerPath = (string)($competition['league_banner_image'] ?? '');
          $previousBadgePath = (string)($competition['badge_image'] ?? '');
          $previousWhiteBadgePath = (string)($competition['white_badge_image'] ?? '');
          $bannerUpload = competition_handle_image_upload($_FILES['league_banner_image'] ?? [], 'competition-table', 'League Table Image');
          $badgeUpload = competition_handle_image_upload($_FILES['badge_image'] ?? [], 'competition-badge', 'Badge');
          $whiteBadgeUpload = competition_handle_image_upload($_FILES['white_badge_image'] ?? [], 'competition-white-badge', 'White Badge');
          foreach ([$bannerUpload, $badgeUpload, $whiteBadgeUpload] as $upload) {
                    if ($upload['error'] !== '') {
                              $errors[] = $upload['error'];
                    }
          }

          if ($errors) {
                    competition_remove_unsaved_uploads([$bannerUpload['path'], $badgeUpload['path'], $whiteBadgeUpload['path']]);
          } else {
                    try {
                              ensureSeasonSchema($pdo);
                              ensureMatchSchema($pdo);
                              $pdo->beginTransaction();
                              $submittedCompetitionId = $action === 'edit' ? $id : null;
                              $savedCompetitionId = saveMatchCompetition(
                                        $pdo,
                                        $submittedCompetitionId,
                                        $formName,
                                        $formSortOrder === '' ? null : (int)$formSortOrder,
                                        (string)($competition['league_url'] ?? '') ?: null,
                                        $formCompetitionType === 'league',
                                        $bannerUpload['path'] !== '' ? $bannerUpload['path'] : ($previousBannerPath ?: null),
                                        isset($competition['promotion_spots']) ? (int)$competition['promotion_spots'] : null,
                                        isset($competition['relegation_spots']) ? (int)$competition['relegation_spots'] : null,
                                        !isset($competition['show_table_lines']) || !empty($competition['show_table_lines']),
                                        $badgeUpload['path'] !== '' ? $badgeUpload['path'] : ($previousBadgePath ?: null),
                                        $whiteBadgeUpload['path'] !== '' ? $whiteBadgeUpload['path'] : ($previousWhiteBadgePath ?: null)
                              );
                              competitionStructureSaveCanonicalMetadata($pdo, $savedCompetitionId, $formOrganiser !== '' ? $formOrganiser : null, $formCompetitionType);

                              $removeGenericAliases = $pdo->prepare(<<<'SQL'
                                        DELETE FROM competition_aliases
                                        WHERE competition_id = :competition_id
                                          AND season_id IS NULL
                                          AND organiser IS NULL
                                          AND source_name IS NULL
                                          AND source_reference IS NULL
                              SQL);
                              $removeGenericAliases->execute([':competition_id' => $savedCompetitionId]);
                              foreach ($formAliases as $aliasName) {
                                        if (strcasecmp($aliasName, $formName) === 0) {
                                                  continue;
                                        }
                                        competitionStructureSaveAlias($pdo, null, $savedCompetitionId, $aliasName, [
                                                  'review_status' => 'confirmed',
                                                  'notes' => 'Managed from the competition editor.',
                                        ]);
                              }

                              foreach ($seasons as $season) {
                                        $seasonId = (int)$season['id'];
                                        $values = $formEditionBySeason[$seasonId];
                                        $existingEdition = $existingEditionBySeason[$seasonId] ?? null;
                                        $isProtected = !empty($season['is_locked']) && !empty($existingEdition['is_active']);
                                        if ($isProtected) {
                                                  continue;
                                        }
                                        if (!$values['present']) {
                                                  if (!empty($existingEdition['id']) && !empty($existingEdition['is_active'])) {
                                                            competitionStructureSetEditionActive($pdo, (int)$existingEdition['id'], false);
                                                  }
                                                  if ($formCompetitionType === 'league' && empty($season['is_locked'])) {
                                                            $clearPrimary = $pdo->prepare('UPDATE seasons SET competition_id = NULL WHERE id = :season_id AND competition_id = :competition_id');
                                                            $clearPrimary->execute([':season_id' => $seasonId, ':competition_id' => $savedCompetitionId]);
                                                  }
                                                  continue;
                                        }

                                        competitionStructureSaveEdition($pdo, !empty($existingEdition['id']) ? (int)$existingEdition['id'] : null, $savedCompetitionId, $seasonId, [
                                                  'display_name' => $values['display_name'] !== '' ? $values['display_name'] : null,
                                                  'sponsor_title' => $values['sponsor_title'] !== '' ? $values['sponsor_title'] : null,
                                                  'competition_url' => $values['competition_url'] !== '' ? $values['competition_url'] : null,
                                                  'promotion_spots' => $values['promotion_spots'] !== '' ? (int)$values['promotion_spots'] : null,
                                                  'relegation_spots' => $values['relegation_spots'] !== '' ? (int)$values['relegation_spots'] : null,
                                                  'show_table_lines' => $values['show_table_lines'] === '' ? null : (int)$values['show_table_lines'],
                                                  'is_active' => true,
                                                  'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                                        ]);
                                        if ($formCompetitionType === 'league') {
                                                  $setPrimary = $pdo->prepare('UPDATE seasons SET competition_id = :competition_id WHERE id = :season_id AND (competition_id IS NULL OR competition_id = :competition_id)');
                                                  $setPrimary->execute([':competition_id' => $savedCompetitionId, ':season_id' => $seasonId]);
                                        }
                              }
                              $pdo->commit();

                              auditLog($pdo, $action === 'edit' ? 'competition_updated' : 'competition_created', ($action === 'edit' ? 'Updated' : 'Created') . " competition '{$formName}'");

                              foreach ([[$bannerUpload['path'], $previousBannerPath], [$badgeUpload['path'], $previousBadgePath], [$whiteBadgeUpload['path'], $previousWhiteBadgePath]] as [$newPath, $oldPath]) {
                                        if ($newPath !== '' && $oldPath !== '' && $newPath !== $oldPath) {
                                                  competition_remove_unsaved_uploads([$oldPath]);
                                        }
                              }
                              header('Location: competitions.php?saved=1');
                              exit;
                    } catch (Throwable $e) {
                              if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                              }
                              competition_remove_unsaved_uploads([$bannerUpload['path'], $badgeUpload['path'], $whiteBadgeUpload['path']]);
                              $errors[] = $e->getMessage();
                    }
          }
}

$assignedEditionCount = count(array_filter($formEditionBySeason, static fn(array $edition): bool => !empty($edition['present'])));
?>
<div class="competition-editor" data-competition-editor>
          <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="competitions.php">Competitions</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add competition' : 'Edit competition' ?></span></nav>
          <header class="competition-editor__header">
                    <div>
                              <div class="competition-editor__eyebrow">Competition management</div>
                              <h1><?= $action === 'new' ? 'Add competition' : h($formName) ?></h1>
                              <p>Keep the permanent competition identity separate from the sponsored details used in each season.</p>
                    </div>
                    <a href="competitions.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All competitions</a>
          </header>

          <?php if ($errors): ?>
                    <div class="alert alert-danger" role="alert">
                              <strong>Please check the following:</strong>
                              <ul class="mb-0 mt-2"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
                    </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" action="competition.php?action=<?= h($action) ?><?= $id > 0 ? '&amp;id=' . $id : '' ?>" data-warn-unsaved>
                    <?= csrf_field() ?>
                    <input type="hidden" name="competition_id" value="<?= $id ?>">
                    <div class="competition-editor__layout">
                              <div class="competition-editor__main">
                                        <section class="competition-panel" aria-labelledby="canonicalDetailsHeading">
                                                  <div class="competition-panel__header">
                                                            <span class="competition-panel__icon"><i class="fa-solid fa-trophy" aria-hidden="true"></i></span>
                                                            <div class="competition-panel__heading"><h2 id="canonicalDetailsHeading">Competition identity</h2><p>The lasting identity used across every season and sponsor-name change.</p></div>
                                                  </div>
                                                  <div class="competition-panel__body">
                                                            <div class="row g-3">
                                                                      <div class="col-12"><label for="competitionName" class="form-label fw-semibold">Canonical name</label><input type="text" class="form-control" id="competitionName" name="name" value="<?= h($formName) ?>" required><div class="form-text">Use the unsponsored name wherever possible.</div></div>
                                                                      <div class="col-md-7"><label for="competitionOrganiser" class="form-label fw-semibold">Organiser <span class="competition-field-note">Optional</span></label><input type="text" class="form-control" id="competitionOrganiser" name="organiser" value="<?= h($formOrganiser) ?>" placeholder="e.g. West of Scotland Football League"></div>
                                                                      <div class="col-md-5"><label for="competitionSortOrder" class="form-label fw-semibold">Display order <span class="competition-field-note">Optional</span></label><input type="number" min="0" step="1" class="form-control" id="competitionSortOrder" name="sort_order" value="<?= h($formSortOrder) ?>"><div class="form-text">Lower numbers appear first.</div></div>
                                                                      <div class="col-12">
                                                                                <span class="form-label fw-semibold d-block">Competition type</span>
                                                                                <div class="competition-type-options">
                                                                                          <label class="competition-type-option"><input class="form-check-input" type="radio" name="competition_type" value="league" <?= $formCompetitionType === 'league' ? 'checked' : '' ?>><span><strong>League</strong><small>Tables, promotion and relegation</small></span></label>
                                                                                          <label class="competition-type-option"><input class="form-check-input" type="radio" name="competition_type" value="cup" <?= $formCompetitionType === 'cup' ? 'checked' : '' ?>><span><strong>Cup</strong><small>Knockout or group-stage competition</small></span></label>
                                                                                          <label class="competition-type-option"><input class="form-check-input" type="radio" name="competition_type" value="tournament" <?= $formCompetitionType === 'tournament' ? 'checked' : '' ?>><span><strong>Tournament</strong><small>Short-format or invitational event</small></span></label>
                                                                                          <label class="competition-type-option"><input class="form-check-input" type="radio" name="competition_type" value="friendly" <?= $formCompetitionType === 'friendly' ? 'checked' : '' ?>><span><strong>Friendly</strong><small>Non-competitive match grouping</small></span></label>
                                                                                          <label class="competition-type-option"><input class="form-check-input" type="radio" name="competition_type" value="other" <?= $formCompetitionType === 'other' ? 'checked' : '' ?>><span><strong>Other</strong><small>Another competition format</small></span></label>
                                                                                </div>
                                                                      </div>
                                                            </div>
                                                  </div>
                                        </section>

                                        <section class="competition-panel" aria-labelledby="seasonEditionsHeading">
                                                  <div class="competition-panel__header">
                                                            <span class="competition-panel__icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                                                            <div class="competition-panel__heading"><h2 id="seasonEditionsHeading">Season editions</h2><p>Assign this competition to seasons and record the name and settings used at that time.</p></div>
                                                            <span class="badge rounded-pill text-bg-light ms-auto" data-edition-count><?= $assignedEditionCount ?> seasons</span>
                                                  </div>
                                                  <?php if ($seasons): ?>
                                                            <div class="competition-editions-toolbar">
                                                                      <select class="form-select" data-edition-season-select aria-label="Choose a season to assign">
                                                                                <option value="">Choose a season to add…</option>
                                                                                <?php foreach ($seasons as $season): $seasonId = (int)$season['id']; $edition = $formEditionBySeason[$seasonId]; ?>
                                                                                          <option value="<?= $seasonId ?>" <?= $edition['present'] ? 'hidden disabled' : '' ?>><?= h((string)$season['name']) ?><?= !empty($season['is_locked']) ? ' — locked historical season' : '' ?></option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                                      <button class="btn btn-brand" type="button" data-add-edition disabled><i class="fa-solid fa-plus" aria-hidden="true"></i> Add season</button>
                                                            </div>
                                                            <div class="competition-editions" data-edition-list>
                                                                      <?php foreach ($seasons as $season):
                                                                                $seasonId = (int)$season['id'];
                                                                                $edition = $formEditionBySeason[$seasonId];
                                                                                $existingEdition = $existingEditionBySeason[$seasonId] ?? null;
                                                                                $isLocked = !empty($season['is_locked']);
                                                                                $isProtected = $isLocked && !empty($existingEdition['is_active']);
                                                                                $summaryText = $edition['display_name'] !== '' ? $edition['display_name'] : (string)$season['name'];
                                                                      ?>
                                                                                <article class="competition-edition" data-edition data-season-id="<?= $seasonId ?>" data-season-name="<?= h((string)$season['name']) ?>" data-protected="<?= $isProtected ? 'true' : 'false' ?>" <?= $edition['present'] ? '' : 'hidden' ?>>
                                                                                          <input type="hidden" name="editions[<?= $seasonId ?>][present]" value="<?= $edition['present'] ? '1' : '0' ?>" data-edition-present>
                                                                                          <button class="competition-edition__summary" type="button" data-edition-toggle aria-expanded="false" aria-controls="editionBody<?= $seasonId ?>">
                                                                                                    <span class="competition-edition__season-icon"><i class="fa-solid fa-calendar" aria-hidden="true"></i></span>
                                                                                                    <span class="competition-edition__title"><strong><?= h((string)$season['name']) ?></strong><small data-edition-summary-text><?= h($summaryText) ?></small></span>
                                                                                                    <span class="competition-edition__badges"><?php if (!empty($season['is_current'])): ?><span class="badge text-bg-success">Current</span><?php endif; ?><?php if ($isLocked): ?><span class="badge text-bg-secondary"><i class="fa-solid fa-lock" aria-hidden="true"></i> Locked</span><?php endif; ?></span>
                                                                                                    <i class="fa-solid fa-chevron-down competition-edition__chevron" aria-hidden="true"></i>
                                                                                          </button>
                                                                                          <div class="competition-edition__body" id="editionBody<?= $seasonId ?>" data-edition-body hidden>
                                                                                                    <?php if ($isProtected): ?>
                                                                                                              <div class="competition-edition__locked-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>This historical relationship is protected because the season is locked. Its saved details are shown for reference.</span></div>
                                                                                                    <?php elseif ($isLocked): ?>
                                                                                                              <div class="competition-edition__historical-note"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><span>This fills a missing historical relationship without unlocking or changing the season's results.</span></div>
                                                                                                    <?php endif; ?>
                                                                                                    <div class="row g-3">
                                                                                                              <div class="col-md-6"><label class="form-label fw-semibold" for="editionDisplayName<?= $seasonId ?>">Display name <span class="competition-field-note">Optional</span></label><input type="text" class="form-control" id="editionDisplayName<?= $seasonId ?>" name="editions[<?= $seasonId ?>][display_name]" value="<?= h($edition['display_name']) ?>" placeholder="Defaults to canonical name" data-edition-display-name <?= $isProtected ? 'disabled data-locked-control' : '' ?>></div>
                                                                                                              <div class="col-md-6"><label class="form-label fw-semibold" for="editionSponsor<?= $seasonId ?>">Sponsor title <span class="competition-field-note">Optional</span></label><input type="text" class="form-control" id="editionSponsor<?= $seasonId ?>" name="editions[<?= $seasonId ?>][sponsor_title]" value="<?= h($edition['sponsor_title']) ?>" placeholder="Sponsor name for this season" <?= $isProtected ? 'disabled data-locked-control' : '' ?>></div>
                                                                                                              <div class="col-12"><label class="form-label fw-semibold" for="editionUrl<?= $seasonId ?>">Competition URL <span class="competition-field-note">Optional</span></label><input type="url" class="form-control" id="editionUrl<?= $seasonId ?>" name="editions[<?= $seasonId ?>][competition_url]" value="<?= h($edition['competition_url']) ?>" placeholder="https://…" <?= $isProtected ? 'disabled data-locked-control' : '' ?>></div>
                                                                                                              <div class="col-12" data-edition-league-fields <?= $formCompetitionType === 'league' ? '' : 'hidden' ?>>
                                                                                                                        <div class="row g-3">
                                                                                                                                  <div class="col-sm-4"><label class="form-label fw-semibold" for="editionPromotion<?= $seasonId ?>">Promotion spots</label><input type="number" min="0" step="1" class="form-control" id="editionPromotion<?= $seasonId ?>" name="editions[<?= $seasonId ?>][promotion_spots]" value="<?= h($edition['promotion_spots']) ?>" <?= $isProtected ? 'disabled data-locked-control' : '' ?>></div>
                                                                                                                                  <div class="col-sm-4"><label class="form-label fw-semibold" for="editionRelegation<?= $seasonId ?>">Relegation spots</label><input type="number" min="0" step="1" class="form-control" id="editionRelegation<?= $seasonId ?>" name="editions[<?= $seasonId ?>][relegation_spots]" value="<?= h($edition['relegation_spots']) ?>" <?= $isProtected ? 'disabled data-locked-control' : '' ?>></div>
                                                                                                                                  <div class="col-sm-4"><label class="form-label fw-semibold" for="editionLines<?= $seasonId ?>">Table lines</label><select class="form-select" id="editionLines<?= $seasonId ?>" name="editions[<?= $seasonId ?>][show_table_lines]" <?= $isProtected ? 'disabled data-locked-control' : '' ?>><option value="" <?= $edition['show_table_lines'] === '' ? 'selected' : '' ?>>Use default</option><option value="1" <?= $edition['show_table_lines'] === '1' ? 'selected' : '' ?>>Show</option><option value="0" <?= $edition['show_table_lines'] === '0' ? 'selected' : '' ?>>Hide</option></select></div>
                                                                                                                        </div>
                                                                                                              </div>
                                                                                                              <div class="col-12"><label class="form-label fw-semibold" for="editionNotes<?= $seasonId ?>">Historical notes <span class="competition-field-note">Optional</span></label><textarea class="form-control" id="editionNotes<?= $seasonId ?>" name="editions[<?= $seasonId ?>][notes]" rows="2" placeholder="Name changes or source notes for this season" <?= $isProtected ? 'disabled data-locked-control' : '' ?>><?= h($edition['notes']) ?></textarea></div>
                                                                                                              <?php if ($isLocked && !$isProtected): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="historicalConfirm<?= $seasonId ?>" name="editions[<?= $seasonId ?>][historical_confirm]" value="1" <?= $edition['historical_confirm'] ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="historicalConfirm<?= $seasonId ?>">Confirm this missing historical assignment</label></div></div><?php endif; ?>
                                                                                                    </div>
                                                                                                    <div class="competition-edition__actions"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-edition <?= $isProtected ? 'disabled title="Locked historical assignments cannot be removed"' : '' ?>><i class="fa-solid fa-xmark" aria-hidden="true"></i> Remove season</button></div>
                                                                                          </div>
                                                                                </article>
                                                                      <?php endforeach; ?>
                                                                      <div class="competition-editions-empty" data-editions-empty <?= $assignedEditionCount > 0 ? 'hidden' : '' ?>><i class="fa-regular fa-calendar-plus" aria-hidden="true"></i><strong>No seasons assigned yet</strong><div>Choose a season above to create its edition.</div></div>
                                                            </div>
                                                  <?php else: ?>
                                                            <div class="competition-editions-empty"><i class="fa-regular fa-calendar-xmark" aria-hidden="true"></i><strong>No seasons are available</strong><div>Create a season before assigning this competition.</div></div>
                                                  <?php endif; ?>
                                        </section>

                                        <section class="competition-panel" aria-labelledby="competitionAliasesHeading">
                                                  <div class="competition-panel__header">
                                                            <span class="competition-panel__icon"><i class="fa-solid fa-tags" aria-hidden="true"></i></span>
                                                            <div class="competition-panel__heading"><h2 id="competitionAliasesHeading">Known names</h2><p>Recognise historical, sponsored, or imported names without creating duplicate competitions.</p></div>
                                                  </div>
                                                  <div class="competition-panel__body">
                                                            <label class="form-label fw-semibold" for="competitionAliases">Aliases <span class="competition-field-note">One name per line</span></label>
                                                            <textarea class="form-control" id="competitionAliases" name="aliases" rows="4" placeholder="Emirates Scottish Junior Cup&#10;Scottish Junior Cup"><?= h(implode("\n", $formAliases)) ?></textarea>
                                                            <div class="form-text">These names resolve to this canonical competition during imports. Season-specific display names belong in the season edition above.</div>
                                                  </div>
                                        </section>
                              </div>

                              <aside class="competition-editor__sidebar">
                                        <section class="competition-panel" aria-labelledby="badgeArtworkHeading">
                                                  <div class="competition-panel__header"><span class="competition-panel__icon"><i class="fa-regular fa-image" aria-hidden="true"></i></span><div class="competition-panel__heading"><h2 id="badgeArtworkHeading">Badge artwork</h2><p>Used on match graphics.</p></div></div>
                                                  <div class="competition-panel__body">
                                                            <div class="competition-media-field" data-media-upload>
                                                                      <span class="form-label fw-semibold">Colour badge</span>
                                                                      <label class="competition-dropzone" for="competitionBadge" data-dropzone>
                                                                                <input type="file" class="visually-hidden" id="competitionBadge" name="badge_image" accept="image/png,image/jpeg,image/webp" data-dropzone-input>
                                                                                <span class="competition-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                                                                <span class="competition-dropzone__copy"><strong>Drop an image here</strong><small>or click to browse · JPG, PNG or WebP</small></span>
                                                                                <span class="competition-dropzone__filename" data-dropzone-filename><?= $formBadgeImage !== '' ? h(basename($formBadgeImage)) : 'No image selected' ?></span>
                                                                      </label>
                                                                      <div class="competition-media-preview" data-dropzone-preview <?= $formBadgeImage === '' ? 'hidden' : '' ?>><img<?= $formBadgeImage !== '' ? ' src="' . h('/' . ltrim($formBadgeImage, '/')) . '"' : '' ?> alt="Colour badge preview" data-dropzone-image></div>
                                                            </div>
                                                            <div class="competition-media-field" data-media-upload>
                                                                      <span class="form-label fw-semibold">White badge</span>
                                                                      <label class="competition-dropzone" for="competitionWhiteBadge" data-dropzone>
                                                                                <input type="file" class="visually-hidden" id="competitionWhiteBadge" name="white_badge_image" accept="image/png,image/jpeg,image/webp" data-dropzone-input>
                                                                                <span class="competition-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                                                                <span class="competition-dropzone__copy"><strong>Drop an image here</strong><small>or click to browse · JPG, PNG or WebP</small></span>
                                                                                <span class="competition-dropzone__filename" data-dropzone-filename><?= $formWhiteBadgeImage !== '' ? h(basename($formWhiteBadgeImage)) : 'No image selected' ?></span>
                                                                      </label>
                                                                      <div class="competition-media-preview competition-media-preview--dark" data-dropzone-preview <?= $formWhiteBadgeImage === '' ? 'hidden' : '' ?>><img<?= $formWhiteBadgeImage !== '' ? ' src="' . h('/' . ltrim($formWhiteBadgeImage, '/')) . '"' : '' ?> alt="White badge preview" data-dropzone-image></div>
                                                            </div>
                                                  </div>
                                        </section>
                                        <section class="competition-panel" aria-labelledby="leagueArtworkHeading" data-edition-league-fields <?= $formCompetitionType === 'league' ? '' : 'hidden' ?>>
                                                  <div class="competition-panel__header"><span class="competition-panel__icon"><i class="fa-solid fa-table-list" aria-hidden="true"></i></span><div class="competition-panel__heading"><h2 id="leagueArtworkHeading">League artwork</h2><p>Header used with league tables.</p></div></div>
                                                  <div class="competition-panel__body">
                                                            <div class="competition-media-field" data-media-upload>
                                                                      <span class="form-label fw-semibold">Table banner</span>
                                                                      <label class="competition-dropzone" for="competitionBanner" data-dropzone>
                                                                                <input type="file" class="visually-hidden" id="competitionBanner" name="league_banner_image" accept="image/png,image/jpeg,image/webp" data-dropzone-input>
                                                                                <span class="competition-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
                                                                                <span class="competition-dropzone__copy"><strong>Drop a banner here</strong><small>or click to browse · JPG, PNG or WebP</small></span>
                                                                                <span class="competition-dropzone__filename" data-dropzone-filename><?= $formLeagueBannerImage !== '' ? h(basename($formLeagueBannerImage)) : 'No image selected' ?></span>
                                                                      </label>
                                                                      <div class="competition-media-preview competition-media-preview--banner" data-dropzone-preview <?= $formLeagueBannerImage === '' ? 'hidden' : '' ?>><img<?= $formLeagueBannerImage !== '' ? ' src="' . h('/' . ltrim($formLeagueBannerImage, '/')) . '"' : '' ?> alt="League table banner preview" data-dropzone-image></div>
                                                            </div>
                                                  </div>
                                        </section>
                              </aside>
                    </div>
                    <div class="competition-editor__footer"><a href="competitions.php" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= $action === 'new' ? 'Create competition' : 'Save changes' ?></button></div>
          </form>
</div>

<script src="/admin/assets/js/competition-form.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/competition-form.js') ?: time()) ?>"></script>
<?php require __DIR__ . '/footer.php'; ?>
