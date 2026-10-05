<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$action = (string)($_GET['action'] ?? ($id > 0 ? 'edit' : 'new'));
if (!in_array($action, ['new', 'edit'], true)) {
          $action = $id > 0 ? 'edit' : 'new';
}

$pageHero = [
          'eyebrow' => 'Fixture management',
          'title' => $action === 'new' ? 'Add Opponent' : 'Edit Opponent',
          'subtitle' => $action === 'new'
                    ? 'Create a club profile with its identity, home venue, and artwork.'
                    : 'Keep the club identity, home venue, and graphic assets up to date.',
          'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

function opponent_prepare_logo_upload(array $file, string $prefix, string $label, array &$errors): ?array
{
          if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    return null;
          }
          if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors[] = $label . ' upload failed. Please try again.';
                    return null;
          }
          $tmpName = (string)($file['tmp_name'] ?? '');
          if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    $errors[] = 'Invalid ' . strtolower($label) . ' upload.';
                    return null;
          }
          if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
                    $errors[] = $label . ' must be 5MB or smaller.';
                    return null;
          }
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $mime = $finfo ? finfo_file($finfo, $tmpName) : null;
          if ($finfo) {
                    finfo_close($finfo);
          }
          $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
          if (!isset($allowed[$mime ?? ''])) {
                    $errors[] = 'Unsupported ' . strtolower($label) . ' format. Please use PNG, JPG, GIF or WebP.';
                    return null;
          }
          $uploadDir = __DIR__ . '/uploads/opponents';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Failed to prepare upload directory.';
                    return null;
          }
          try {
                    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
          } catch (Throwable $e) {
                    $errors[] = 'Failed to prepare filename for ' . strtolower($label) . '.';
                    return null;
          }

          return ['tmp_name' => $tmpName, 'destination' => $uploadDir . DIRECTORY_SEPARATOR . $filename, 'filename' => $filename];
}

$errors = [];
$schemaReady = true;
$venueOptions = getMatchVenues($pdo);
$currentLogo = null;
$currentWhiteLogo = null;
$pendingUpload = null;
$pendingWhiteUpload = null;
$deleteAfterCommit = null;
$deleteWhiteAfterCommit = null;
$formData = [
          'clubname' => '',
          'abbreviation' => '',
          'ground_location' => '',
];
$selectedVenueId = 0;

if ($action === 'edit') {
          if ($id <= 0) {
                    echo '<div><div class="alert alert-danger">Invalid opponent ID.</div></div>';
                    require __DIR__ . '/footer.php';
                    exit;
          }
          $opponent = getMatchOpponentById($pdo, $id);
          if (!$opponent) {
                    echo '<div><div class="alert alert-danger">Opponent not found.</div></div>';
                    require __DIR__ . '/footer.php';
                    exit;
          }
} else {
          $opponent = [
                    'clubname' => '',
                    'abbreviation' => '',
                    'logo_path' => null,
                    'white_logo_path' => null,
                    'ground_location' => '',
          ];
}

$formData['clubname'] = (string)($opponent['clubname'] ?? '');
$formData['abbreviation'] = (string)($opponent['abbreviation'] ?? '');
$formData['ground_location'] = (string)($opponent['ground_location'] ?? '');
$currentLogo = $opponent['logo_path'] ?? null;
$currentWhiteLogo = $opponent['white_logo_path'] ?? null;
$selectedVenueId = (int)($opponent['venue_id'] ?? 0);
if ($selectedVenueId <= 0 && $formData['ground_location'] !== '') {
          $matchedVenue = getMatchVenueByName($pdo, $formData['ground_location']);
          if ($matchedVenue) {
                    $selectedVenueId = (int)($matchedVenue['id'] ?? 0);
          }
}
$originalClubname = $formData['clubname'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $errors[] = 'Invalid security token. Please try again.';
          }

          $submittedOpponentId = $action === 'edit'
                    ? (int)($opponent['id'] ?? $id)
                    : (int)($_POST['opponent_id'] ?? 0);
          $submittedOriginalClubname = trim((string)($_POST['original_clubname'] ?? ''));
          $formData['clubname'] = trim((string)($_POST['clubname'] ?? ''));
          $formData['abbreviation'] = strtoupper(trim((string)($_POST['abbreviation'] ?? '')));
          $selectedVenueId = (int)($_POST['venue_id'] ?? 0);
          $selectedVenueName = '';
          if ($selectedVenueId > 0) {
                    $selectedVenue = getMatchVenueById($pdo, $selectedVenueId);
                    if ($selectedVenue) {
                              $selectedVenueName = trim((string)($selectedVenue['name'] ?? ''));
                    }
          }
          if ($selectedVenueName === '') {
                    $errors[] = 'Home venue is required.';
          }
          $formData['ground_location'] = $selectedVenueName;
          $newLogoPath = $currentLogo;
          $newWhiteLogoPath = $currentWhiteLogo;

          if ($submittedOpponentId <= 0 && $submittedOriginalClubname !== '') {
                    $existingOpponent = getMatchOpponentByClubname($pdo, $submittedOriginalClubname);
                    if ($existingOpponent) {
                              $submittedOpponentId = (int)$existingOpponent['id'];
                    }
          }

          if ($formData['clubname'] === '') {
                    $errors[] = 'Club name is required.';
          }

          if ($formData['abbreviation'] === '') {
                    $errors[] = 'Abbreviation is required.';
          } elseif (!preg_match('/^[A-Z0-9]{2,16}$/', $formData['abbreviation'])) {
                    $errors[] = 'Abbreviation must be 2-16 letters or numbers with no spaces.';
          }

          if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['logo'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                              $errors[] = 'Image upload failed. Please try again.';
                    } elseif (!is_uploaded_file($file['tmp_name'])) {
                              $errors[] = 'Invalid image upload.';
                    } elseif ($file['size'] > 5 * 1024 * 1024) {
                              $errors[] = 'Image must be 5MB or smaller.';
                    } else {
                              $finfo = finfo_open(FILEINFO_MIME_TYPE);
                              $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
                              if ($finfo) {
                                        finfo_close($finfo);
                              }
                              $allowed = [
                                        'image/jpeg' => 'jpg',
                                        'image/png' => 'png',
                                        'image/gif' => 'gif',
                                        'image/webp' => 'webp',
                              ];
                              if (!isset($allowed[$mime ?? ''])) {
                                        $errors[] = 'Unsupported image format. Please use PNG, JPG, GIF or WebP.';
                              } else {
                                        $uploadDir = __DIR__ . '/uploads/opponents';
                                        if (!is_dir($uploadDir)) {
                                                  if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                                                            $errors[] = 'Failed to prepare upload directory.';
                                                  }
                                        }
                                        if (!$errors) {
                                                  try {
                                                            $random = bin2hex(random_bytes(8));
                                                  } catch (Exception $e) {
                                                            $errors[] = 'Failed to prepare filename for image upload.';
                                                            $random = null;
                                                  }
                                                  if (!$errors && $random !== null) {
                                                            $filename = 'opponent_' . $random . '.' . $allowed[$mime];
                                                            $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                                                            $pendingUpload = [
                                                                      'tmp_name' => $file['tmp_name'],
                                                                      'destination' => $destination,
                                                            ];
                                                            $newLogoPath = $filename;
                                                  }
                                        }
                              }
                    }
          }

          $pendingWhiteUpload = opponent_prepare_logo_upload(
                    isset($_FILES['white_logo']) && is_array($_FILES['white_logo']) ? $_FILES['white_logo'] : [],
                    'opponent_white',
                    'White badge',
                    $errors
          );
          if ($pendingWhiteUpload) {
                    $newWhiteLogoPath = $pendingWhiteUpload['filename'];
          }

          if (!$errors) {
                    try {
                              $pdo->beginTransaction();

                              $savedId = saveMatchOpponentWithAbbreviation(
                                        $pdo,
                                        $submittedOpponentId > 0 ? $submittedOpponentId : null,
                                        $formData['clubname'],
                                        $formData['abbreviation'],
                                        $newLogoPath,
                                        $formData['ground_location'] !== '' ? $formData['ground_location'] : null,
                                        $selectedVenueId > 0 ? $selectedVenueId : null,
                                        $newWhiteLogoPath
                              );

                              if ($pendingUpload) {
                                        if (!move_uploaded_file($pendingUpload['tmp_name'], $pendingUpload['destination'])) {
                                                  throw new RuntimeException('Failed to store uploaded image.');
                                        }
                                        if ($currentLogo && $currentLogo !== $newLogoPath) {
                                                  $deleteAfterCommit = matchOpponentLogoFilePath($currentLogo);
                                        }
                              }

                              if ($pendingWhiteUpload) {
                                        if (!move_uploaded_file($pendingWhiteUpload['tmp_name'], $pendingWhiteUpload['destination'])) {
                                                  throw new RuntimeException('Failed to store uploaded white badge.');
                                        }
                                        if ($currentWhiteLogo && $currentWhiteLogo !== $newWhiteLogoPath) {
                                                  $deleteWhiteAfterCommit = matchOpponentLogoFilePath((string)$currentWhiteLogo);
                                        }
                              }

                              $pdo->commit();

                              auditLog($pdo, $submittedOpponentId > 0 ? 'opponent_updated' : 'opponent_created', ($submittedOpponentId > 0 ? 'Updated' : 'Created') . " opponent '{$formData['clubname']}'");

                              if (!empty($deleteAfterCommit)) {
                                        if (is_file($deleteAfterCommit)) {
                                                  @unlink($deleteAfterCommit);
                                        }
                              }
                              if (!empty($deleteWhiteAfterCommit) && is_file($deleteWhiteAfterCommit)) {
                                        @unlink($deleteWhiteAfterCommit);
                              }

                              header('Location: opponents.php?saved=1');
                              exit;
                    } catch (Throwable $e) {
                              if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                              }
                              if ($pendingUpload && isset($pendingUpload['destination']) && is_file($pendingUpload['destination'])) {
                                        @unlink($pendingUpload['destination']);
                              }
                              if ($pendingWhiteUpload && isset($pendingWhiteUpload['destination']) && is_file($pendingWhiteUpload['destination'])) {
                                        @unlink($pendingWhiteUpload['destination']);
                              }
                              $errors[] = $e->getMessage();
                    }
          }
}
?>

<?php
$selectedVenueLabel = '';
foreach ($venueOptions as $venueOption) {
          if ((int)$venueOption['id'] === $selectedVenueId) {
                    $selectedVenueLabel = matchVenueDisplayLabel($venueOption);
                    break;
          }
}
?>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-opponent.css">

<div class="opponent-editor">
          <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="opponents.php">Opponents</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add opponent' : 'Edit opponent' ?></span></nav>

          <?php if ($errors): ?>
                    <div class="alert alert-danger" role="alert">
                              <div class="fw-semibold mb-1">Please check the following:</div>
                              <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                                  <li><?= h($error) ?></li>
                                        <?php endforeach; ?>
                              </ul>
                    </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" id="opponentEditorForm" class="opponent-editor-grid" action="opponent.php?action=<?= h($action) ?><?= $id > 0 ? '&id=' . (int)$id : '' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="opponent_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="original_clubname" value="<?= h($originalClubname) ?>">

                    <section class="opponent-editor-card" aria-labelledby="opponentIdentityHeading">
                              <div class="opponent-editor-card__heading">
                                        <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                                        <div><h2 id="opponentIdentityHeading">Club identity</h2><p>The name and short code shown throughout fixtures and graphics.</p></div>
                              </div>
                              <div class="opponent-editor-card__body opponent-editor-fields">
                                        <div>
                                                  <label for="clubName" class="form-label">Club name</label>
                                                  <input type="text" class="form-control" id="clubName" name="clubname" value="<?= h($formData['clubname']) ?>" placeholder="e.g. Saltcoats Victoria" required autofocus>
                                        </div>
                                        <div>
                                                  <label for="clubAbbreviation" class="form-label">Short code</label>
                                                  <input type="text" class="form-control text-uppercase" id="clubAbbreviation" name="abbreviation" value="<?= h($formData['abbreviation']) ?>" maxlength="16" placeholder="e.g. SVC" required>
                                                  <div class="form-text">2–16 letters or numbers.</div>
                                        </div>
                              </div>
                    </section>

                    <section class="opponent-editor-card" aria-labelledby="opponentVenueHeading">
                              <div class="opponent-editor-card__heading">
                                        <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                                        <div><h2 id="opponentVenueHeading">Home venue</h2><p>Search by ground, club, town, or postcode.</p></div>
                              </div>
                              <div class="opponent-editor-card__body">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                  <label for="venueSearch" class="form-label fw-semibold mb-0">Venue</label>
                                                  <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addVenueModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add venue</button>
                                        </div>
                                        <input type="hidden" id="venueId" name="venue_id" value="<?= $selectedVenueId > 0 ? (int)$selectedVenueId : '' ?>">
                                        <div class="opponent-venue-picker" id="venuePicker">
                                                  <i class="fa-solid fa-magnifying-glass opponent-venue-picker__search-icon" aria-hidden="true"></i>
                                                  <input type="text" class="form-control opponent-venue-search" id="venueSearch" value="<?= h($selectedVenueLabel) ?>" placeholder="Start typing to find a venue…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="venueSearchResults" required>
                                                  <button type="button" class="opponent-venue-picker__clear<?= $selectedVenueId > 0 ? '' : ' d-none' ?>" id="venueClear" aria-label="Clear selected venue"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                                  <div class="opponent-venue-results" id="venueSearchResults" role="listbox">
                                                            <?php foreach ($venueOptions as $venue): ?>
                                                                      <?php
                                                                      $venueLabel = matchVenueDisplayLabel($venue);
                                                                      $venueSearchText = strtolower(implode(' ', array_filter([
                                                                                (string)($venue['name'] ?? ''),
                                                                                (string)($venue['club_name'] ?? ''),
                                                                                (string)($venue['address_line1'] ?? ''),
                                                                                (string)($venue['town'] ?? ''),
                                                                                (string)($venue['postcode'] ?? ''),
                                                                      ])));
                                                                      ?>
                                                                      <button type="button" class="opponent-venue-option" role="option" data-venue-id="<?= (int)$venue['id'] ?>" data-venue-label="<?= h($venueLabel) ?>" data-venue-search="<?= h($venueSearchText) ?>">
                                                                                <i class="fa-solid fa-location-dot" aria-hidden="true"></i><span><?= h($venueLabel) ?></span>
                                                                      </button>
                                                            <?php endforeach; ?>
                                                            <div class="opponent-venue-empty" id="venueSearchEmpty">No matching venues. Try another search or add a new venue.</div>
                                                  </div>
                                        </div>
                                        <div class="opponent-selected-venue" id="selectedVenueSummary">
                                                  <i class="fa-solid <?= $selectedVenueId > 0 ? 'fa-circle-check' : 'fa-circle-info' ?>" aria-hidden="true"></i>
                                                  <span><?= $selectedVenueId > 0 ? h($selectedVenueLabel) : 'Select a venue to link it to this opponent.' ?></span>
                                        </div>
                              </div>
                    </section>

                    <section class="opponent-editor-card opponent-editor-card--artwork" aria-labelledby="opponentArtworkHeading">
                              <div class="opponent-editor-card__heading">
                                        <span><i class="fa-solid fa-images" aria-hidden="true"></i></span>
                                        <div><h2 id="opponentArtworkHeading">Club artwork</h2><p>Drop an image into either area or click to browse. PNG, JPG, GIF or WebP up to 5MB.</p></div>
                              </div>
                              <div class="opponent-editor-card__body opponent-upload-grid">
                                        <div class="opponent-upload-field">
                                                  <div class="form-label">Colour badge</div>
                                                  <label class="opponent-upload-card" for="opponentLogo" data-upload-dropzone>
                                                            <input type="file" id="opponentLogo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" data-upload-input>
                                                            <span class="opponent-upload-card__preview" data-upload-preview>
                                                                      <?php if ($currentLogo): ?><img src="<?= h(matchOpponentLogoAssetUrl((string)$currentLogo)) ?>" alt="Current colour badge"><?php else: ?><i class="fa-regular fa-image" aria-hidden="true"></i><?php endif; ?>
                                                            </span>
                                                            <span class="opponent-upload-card__title">Drop colour badge here</span>
                                                            <span class="opponent-upload-card__help">or click to choose an image</span>
                                                            <span class="opponent-upload-card__status" data-upload-status aria-live="polite"><?= $currentLogo ? '<i class="fa-solid fa-circle-check text-success" aria-hidden="true"></i> Current badge saved' : 'No file selected' ?></span>
                                                  </label>
                                        </div>
                                        <div class="opponent-upload-field">
                                                  <div class="form-label">White badge <span class="text-muted fw-normal">(optional)</span></div>
                                                  <label class="opponent-upload-card opponent-upload-card--dark" for="opponentWhiteLogo" data-upload-dropzone>
                                                            <input type="file" id="opponentWhiteLogo" name="white_logo" accept="image/png,image/jpeg,image/gif,image/webp" data-upload-input>
                                                            <span class="opponent-upload-card__preview" data-upload-preview>
                                                                      <?php if ($currentWhiteLogo): ?><img src="<?= h(matchOpponentLogoAssetUrl((string)$currentWhiteLogo, true)) ?>" alt="Current white badge"><?php else: ?><i class="fa-regular fa-image" aria-hidden="true"></i><?php endif; ?>
                                                            </span>
                                                            <span class="opponent-upload-card__title">Drop white badge here</span>
                                                            <span class="opponent-upload-card__help">Best for graphics with dark backgrounds</span>
                                                            <span class="opponent-upload-card__status" data-upload-status aria-live="polite"><?= $currentWhiteLogo ? '<i class="fa-solid fa-circle-check text-success" aria-hidden="true"></i> Current badge saved' : 'No file selected' ?></span>
                                                  </label>
                                        </div>
                              </div>
                    </section>

                    <div class="opponent-editor-footer">
                              <div><a href="opponents.php" class="btn btn-outline-secondary">Cancel</a></div>
                              <div class="opponent-editor-footer__hint"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Changes are applied when you save.</div>
                              <button type="submit" class="btn btn-brand px-4" id="saveOpponentBtn"><i class="fa-solid fa-floppy-disk me-2" aria-hidden="true"></i><?= $action === 'new' ? 'Create opponent' : 'Save changes' ?></button>
                    </div>
          </form>

          <div class="modal fade" id="addVenueModal" tabindex="-1" aria-labelledby="addVenueModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                              <div class="modal-content">
                                        <form method="post" action="venue_quick_add.php" id="addVenueForm">
                                                  <div class="modal-header">
                                                            <h5 class="modal-title" id="addVenueModalLabel">Add Venue</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                                  <div class="modal-body">
                                                            <?= csrf_field() ?>
                                                            <div class="mb-0">
                                                                      <label for="quickVenueClubName" class="form-label">Club Name</label>
                                                                      <input type="text" class="form-control" id="quickVenueClubName" name="club_name" required>
                                                            </div>
                                                            <div class="mb-0 mt-3">
                                                                      <label for="quickVenueName" class="form-label">Venue Name</label>
                                                                      <input type="text" class="form-control" id="quickVenueName" name="name" required>
                                                            </div>
                                                            <div class="mb-3 mt-3">
                                                                      <label for="quickVenueAddress" class="form-label">Address</label>
                                                                      <input type="text" class="form-control" id="quickVenueAddress" name="address_line1">
                                                            </div>
                                                            <div class="row g-3">
                                                                      <div class="col-md-6">
                                                                                <label for="quickVenueTown" class="form-label">Town / City</label>
                                                                                <input type="text" class="form-control" id="quickVenueTown" name="town">
                                                                      </div>
                                                                      <div class="col-md-6">
                                                                                <label for="quickVenuePostcode" class="form-label">Postcode</label>
                                                                                <input type="text" class="form-control text-uppercase" id="quickVenuePostcode" name="postcode">
                                                                      </div>
                                                            </div>
                                                            <div class="mb-0 mt-3">
                                                                      <label for="quickVenueNotes" class="form-label">Notes</label>
                                                                      <textarea class="form-control" id="quickVenueNotes" name="notes" rows="3"></textarea>
                                                            </div>
                                                  </div>
                                                  <div class="modal-footer">
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-brand" id="saveVenueBtn">Save Venue</button>
                                                  </div>
                                        </form>
                              </div>
                    </div>
          </div>
</div>

<script>
          (function() {
                    var editorForm = document.getElementById('opponentEditorForm');
                    var venuePicker = document.getElementById('venuePicker');
                    var venueId = document.getElementById('venueId');
                    var venueSearch = document.getElementById('venueSearch');
                    var venueResults = document.getElementById('venueSearchResults');
                    var venueEmpty = document.getElementById('venueSearchEmpty');
                    var venueClear = document.getElementById('venueClear');
                    var venueSummary = document.getElementById('selectedVenueSummary');
                    var activeVenueIndex = -1;

                    function venueOptions() {
                              return venueResults ? Array.prototype.slice.call(venueResults.querySelectorAll('.opponent-venue-option')) : [];
                    }

                    function visibleVenueOptions() {
                              return venueOptions().filter(function(option) { return option.style.display !== 'none'; });
                    }

                    function openVenueResults() {
                              if (!venueResults || !venueSearch) return;
                              venueResults.classList.add('is-open');
                              venueSearch.setAttribute('aria-expanded', 'true');
                    }

                    function closeVenueResults() {
                              if (!venueResults || !venueSearch) return;
                              venueResults.classList.remove('is-open');
                              venueSearch.setAttribute('aria-expanded', 'false');
                              venueOptions().forEach(function(option) { option.classList.remove('is-active'); });
                              activeVenueIndex = -1;
                    }

                    function updateVenueSummary(label, selected) {
                              if (!venueSummary) return;
                              venueSummary.innerHTML = '<i class="fa-solid ' + (selected ? 'fa-circle-check' : 'fa-circle-info') + '" aria-hidden="true"></i><span></span>';
                              venueSummary.querySelector('span').textContent = selected ? label : 'Select a venue to link it to this opponent.';
                    }

                    function selectVenue(id, label) {
                              if (!venueId || !venueSearch) return;
                              venueId.value = String(id);
                              venueSearch.value = label;
                              venueSearch.setCustomValidity('');
                              if (venueClear) venueClear.classList.remove('d-none');
                              updateVenueSummary(label, true);
                              closeVenueResults();
                    }

                    function filterVenues(query) {
                              var needle = String(query || '').trim().toLowerCase();
                              var matches = 0;
                              venueOptions().forEach(function(option) {
                                        var haystack = (option.dataset.venueSearch || '') + ' ' + (option.dataset.venueLabel || '').toLowerCase();
                                        var visible = needle === '' || haystack.indexOf(needle) !== -1;
                                        option.style.display = visible ? '' : 'none';
                                        option.classList.remove('is-active');
                                        if (visible) matches++;
                              });
                              activeVenueIndex = -1;
                              if (venueEmpty) venueEmpty.classList.toggle('is-visible', matches === 0);
                              openVenueResults();
                    }

                    if (venuePicker && venueId && venueSearch && venueResults) {
                              venueResults.addEventListener('click', function(event) {
                                        var option = event.target.closest('.opponent-venue-option');
                                        if (!option) return;
                                        selectVenue(option.dataset.venueId || '', option.dataset.venueLabel || option.textContent.trim());
                              });

                              venueSearch.addEventListener('focus', function() { filterVenues(venueSearch.value); });
                              venueSearch.addEventListener('input', function() {
                                        venueId.value = '';
                                        venueSearch.setCustomValidity('');
                                        if (venueClear) venueClear.classList.toggle('d-none', venueSearch.value === '');
                                        updateVenueSummary('', false);
                                        filterVenues(venueSearch.value);
                              });
                              venueSearch.addEventListener('keydown', function(event) {
                                        var visible = visibleVenueOptions();
                                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                                  event.preventDefault();
                                                  if (!venueResults.classList.contains('is-open')) filterVenues(venueSearch.value);
                                                  activeVenueIndex += event.key === 'ArrowDown' ? 1 : -1;
                                                  if (activeVenueIndex < 0) activeVenueIndex = visible.length - 1;
                                                  if (activeVenueIndex >= visible.length) activeVenueIndex = 0;
                                                  visible.forEach(function(option, index) { option.classList.toggle('is-active', index === activeVenueIndex); });
                                                  if (visible[activeVenueIndex]) visible[activeVenueIndex].scrollIntoView({ block: 'nearest' });
                                        } else if (event.key === 'Enter' && activeVenueIndex >= 0 && visible[activeVenueIndex]) {
                                                  event.preventDefault();
                                                  visible[activeVenueIndex].click();
                                        } else if (event.key === 'Escape') {
                                                  closeVenueResults();
                                        }
                              });

                              if (venueClear) {
                                        venueClear.addEventListener('click', function() {
                                                  venueId.value = '';
                                                  venueSearch.value = '';
                                                  venueSearch.setCustomValidity('');
                                                  venueClear.classList.add('d-none');
                                                  updateVenueSummary('', false);
                                                  venueSearch.focus();
                                                  filterVenues('');
                                        });
                              }

                              document.addEventListener('click', function(event) {
                                        if (!venuePicker.contains(event.target)) closeVenueResults();
                              });
                    }

                    document.querySelectorAll('[data-upload-dropzone]').forEach(function(dropzone) {
                              var input = dropzone.querySelector('[data-upload-input]');
                              var preview = dropzone.querySelector('[data-upload-preview]');
                              var status = dropzone.querySelector('[data-upload-status]');
                              if (!input || !preview || !status) return;

                              function showError(message) {
                                        input.value = '';
                                        dropzone.classList.remove('is-ready', 'is-uploading');
                                        dropzone.classList.add('is-error');
                                        status.innerHTML = '<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span></span>';
                                        status.querySelector('span').textContent = message;
                              }

                              function handleFile(file) {
                                        dropzone.classList.remove('is-error', 'is-uploading', 'is-dragover');
                                        if (!file) return;
                                        if (!/^image\/(png|jpeg|gif|webp)$/i.test(file.type)) {
                                                  showError('Use a PNG, JPG, GIF or WebP image.');
                                                  return;
                                        }
                                        if (file.size > 5 * 1024 * 1024) {
                                                  showError('This image is larger than 5MB.');
                                                  return;
                                        }
                                        dropzone.classList.add('is-ready');
                                        status.innerHTML = '<i class="fa-solid fa-circle-check text-primary" aria-hidden="true"></i><span></span>';
                                        status.querySelector('span').textContent = 'Ready to upload: ' + file.name;

                                        var reader = new FileReader();
                                        reader.addEventListener('load', function() {
                                                  preview.innerHTML = '';
                                                  var image = document.createElement('img');
                                                  image.src = String(reader.result || '');
                                                  image.alt = 'Selected badge preview';
                                                  preview.appendChild(image);
                                        });
                                        reader.readAsDataURL(file);
                              }

                              input.addEventListener('change', function() { handleFile(input.files && input.files[0]); });
                              ['dragenter', 'dragover'].forEach(function(eventName) {
                                        dropzone.addEventListener(eventName, function(event) {
                                                  event.preventDefault();
                                                  dropzone.classList.add('is-dragover');
                                        });
                              });
                              ['dragleave', 'dragend'].forEach(function(eventName) {
                                        dropzone.addEventListener(eventName, function() { dropzone.classList.remove('is-dragover'); });
                              });
                              dropzone.addEventListener('drop', function(event) {
                                        event.preventDefault();
                                        dropzone.classList.remove('is-dragover');
                                        var file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
                                        if (!file) return;
                                        try {
                                                  var transfer = new DataTransfer();
                                                  transfer.items.add(file);
                                                  input.files = transfer.files;
                                        } catch (error) {
                                                  showError('Your browser could not attach that file. Click to browse instead.');
                                                  return;
                                        }
                                        handleFile(file);
                              });
                    });

                    var abbreviation = document.getElementById('clubAbbreviation');
                    if (abbreviation) {
                              abbreviation.addEventListener('input', function() { abbreviation.value = abbreviation.value.toUpperCase(); });
                    }

                    if (editorForm) {
                              editorForm.addEventListener('submit', function(event) {
                                        if (!venueId || !venueId.value) {
                                                  event.preventDefault();
                                                  if (venueSearch) {
                                                            venueSearch.setCustomValidity('Choose a venue from the search results.');
                                                            venueSearch.reportValidity();
                                                            venueSearch.focus();
                                                            filterVenues(venueSearch.value);
                                                  }
                                                  return;
                                        }

                                        event.preventDefault();
                                        document.querySelectorAll('[data-upload-dropzone]').forEach(function(dropzone) {
                                                  var input = dropzone.querySelector('[data-upload-input]');
                                                  var status = dropzone.querySelector('[data-upload-status]');
                                                  if (!input || !input.files || !input.files.length || !status) return;
                                                  dropzone.classList.remove('is-ready', 'is-error');
                                                  dropzone.classList.add('is-uploading');
                                                  status.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Uploading ' + input.files[0].name.replace(/[<>]/g, '') + '…</span>';
                                        });
                                        var opponentSaveButton = document.getElementById('saveOpponentBtn');
                                        if (opponentSaveButton) {
                                                  opponentSaveButton.disabled = true;
                                                  opponentSaveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving…';
                                        }
                                        window.setTimeout(function() { HTMLFormElement.prototype.submit.call(editorForm); }, 80);
                              });
                    }

                    var modalEl = document.getElementById('addVenueModal');
                    var formEl = document.getElementById('addVenueForm');
                    var inputEl = document.getElementById('quickVenueName');
                    var saveBtn = document.getElementById('saveVenueBtn');
                    if (!modalEl || !formEl || !inputEl || !venueId || !venueResults || typeof bootstrap === 'undefined') {
                              return;
                    }

                    var modal = new bootstrap.Modal(modalEl);

                    function ensureVenueOption(id, name) {
                              var existing = venueResults.querySelector('[data-venue-id="' + String(id).replace(/"/g, '\\"') + '"]');
                              if (existing) {
                                        selectVenue(id, existing.dataset.venueLabel || name);
                                        return;
                              }

                              var option = document.createElement('button');
                              option.type = 'button';
                              option.className = 'opponent-venue-option';
                              option.setAttribute('role', 'option');
                              option.dataset.venueId = String(id);
                              option.dataset.venueLabel = name;
                              option.dataset.venueSearch = name.toLowerCase();
                              option.innerHTML = '<i class="fa-solid fa-location-dot" aria-hidden="true"></i><span></span>';
                              option.querySelector('span').textContent = name;
                              venueResults.insertBefore(option, venueEmpty);
                              selectVenue(id, name);
                    }

                    modalEl.addEventListener('shown.bs.modal', function() {
                              window.setTimeout(function() {
                                        inputEl.focus();
                              }, 150);
                    });

                    formEl.addEventListener('submit', function(event) {
                              event.preventDefault();

                              if (saveBtn) {
                                        saveBtn.disabled = true;
                                        saveBtn.dataset.originalText = saveBtn.textContent || 'Save Venue';
                                        saveBtn.textContent = 'Saving...';
                              }

                              fetch(formEl.action, {
                                        method: 'POST',
                                        headers: {
                                                  'X-Requested-With': 'XMLHttpRequest'
                                        },
                                        body: new FormData(formEl)
                              }).then(function(response) {
                                        return response.text().then(function(text) {
                                                  var json = null;
                                                  try {
                                                            json = text ? JSON.parse(text) : null;
                                                  } catch (e) {
                                                            json = null;
                                                  }
                                                  return { ok: response.ok, json: json, text: text };
                                        });
                              }).then(function(result) {
                                        if (!result.ok || !result.json || !result.json.ok || !result.json.venue) {
                                                  throw new Error((result.json && result.json.error) ? result.json.error : (result.text || 'Unable to save venue.'));
                                        }

                                        ensureVenueOption(result.json.venue.id, result.json.venue.label || result.json.venue.name);
                                        inputEl.value = '';
                                        var extraFields = ['quickVenueClubName', 'quickVenueAddress', 'quickVenueTown', 'quickVenuePostcode', 'quickVenueNotes'];
                                        extraFields.forEach(function(fieldId) {
                                                  var field = document.getElementById(fieldId);
                                                  if (field) {
                                                            field.value = '';
                                                  }
                                        });
                                        modal.hide();
                              }).catch(function(error) {
                                        window.hubToast(error && error.message ? error.message : 'Unable to save venue.', 'danger');
                              }).finally(function() {
                                        if (saveBtn) {
                                                  saveBtn.disabled = false;
                                                  saveBtn.textContent = saveBtn.dataset.originalText || 'Save Venue';
                                        }
                              });
                    });
          })();
</script>

<?php require __DIR__ . '/footer.php'; ?>
