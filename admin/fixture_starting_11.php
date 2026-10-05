<?php
$embedded = (string)($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1';
if ($embedded) {
          $pageHero = [];
}
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/template_pack_render.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/player_availability.php';

$ajaxHeader = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if (
          $_SERVER['REQUEST_METHOD'] === 'POST'
          && $ajaxHeader
          && empty($_POST)
          && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
          ob_clean();
          http_response_code(413);
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode([
                    'ok' => false,
                    'message' => 'That image is too large to upload. Please try a smaller image.',
          ], JSON_UNESCAPED_SLASHES);
          exit;
}

$seasonId = getSelectedSeasonId($pdo);
$fixtureId = (int)($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$requestedSeasonId = (int)($_GET['season_id'] ?? $_POST['season_id'] ?? 0);

if ($requestedSeasonId > 0) {
          $seasonId = $requestedSeasonId;
}

$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          echo '<div><div class="alert alert-warning">Fixture not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$seasonId = (int)$fixture['season_id'];
$season = getSeasonById($pdo, $seasonId);
$fixture = matchStarting11FixtureState($fixture);
$availableTemplatePacks = template_packs_list_available($pdo);
$packContext = matchTemplatePackContext($pdo, $fixtureId, 'starting_xi', true);
$packAssignment = isset($packContext['assignment']) && is_array($packContext['assignment']) ? $packContext['assignment'] : null;
$packIsAutomatic = $packAssignment !== null && ($packAssignment['assigned_by'] ?? null) === null;
$players = $pdo->query("
  SELECT id, name, status
  FROM players
  WHERE active = 1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$availabilityByPlayer = player_availability_by_fixture($pdo, $fixtureId);
$players = player_availability_merge_players($players, $availabilityByPlayer);
$availabilitySummary = player_availability_summary($players);
$availabilityStatuses = player_availability_selectable_statuses();
$playerNames = array_map(static fn(array $row): string => (string)$row['name'], $players);
$playerStatusesByName = [];
$playerAvailabilityByName = [];
foreach ($players as $playerRow) {
          $playerStatusesByName[(string)$playerRow['name']] = (string)($playerRow['status'] ?? '');
          $playerAvailabilityByName[(string)$playerRow['name']] = $playerRow;
}

$errors = [];
$saved = isset($_GET['saved']);
$ajaxRequest = (string)($_POST['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $errors[] = 'Invalid CSRF token.';
          } elseif (!$season) {
                    $errors[] = 'Season not found.';
          } elseif ((int)($season['is_locked'] ?? 0) === 1) {
                    $errors[] = 'This season is locked.';
          } else {
                    $packChoice = trim((string)($_POST['template_pack_choice'] ?? ''));
                    $currentPackId = (int)($packContext['pack']['id'] ?? 0);
                    $currentPackIsAutomatic = $packAssignment !== null && ($packAssignment['assigned_by'] ?? null) === null;
                    $userId = (int)($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0);
                    try {
                              if ($packChoice === 'automatic' && !$currentPackIsAutomatic) {
                                        template_packs_clear_fixture_pack($pdo, $fixtureId);
                                        $packContext = matchTemplatePackContext($pdo, $fixtureId, 'starting_xi', true);
                              } elseif (str_starts_with($packChoice, 'pack:')) {
                                        $selectedPackId = (int)substr($packChoice, 5);
                                        if ($selectedPackId > 0 && ($currentPackIsAutomatic || $selectedPackId !== $currentPackId)) {
                                                  template_packs_set_fixture_pack(
                                                            $pdo,
                                                            $fixtureId,
                                                            $selectedPackId,
                                                            null,
                                                            $userId > 0 ? $userId : null
                                                  );
                                                  $packContext = matchTemplatePackContext($pdo, $fixtureId, 'starting_xi', false);
                                        }
                              }
                              $packAssignment = isset($packContext['assignment']) && is_array($packContext['assignment']) ? $packContext['assignment'] : null;
                              $packIsAutomatic = $packAssignment !== null && ($packAssignment['assigned_by'] ?? null) === null;
                    } catch (Throwable $packError) {
                              $errors[] = $packError->getMessage();
                    }

                    // The visual design now comes from the resolved template pack.
                    // Keep the legacy fixture column on its neutral renderer key.
                    $template = matchStarting11TemplateConfig('starting_xi_classic');
                    $starterSlots = matchStarting11PrepareStarterSlots($_POST['starters'] ?? []);
                    $starters = matchStarting11PrepareLineup($starterSlots);
                    $substitutes = matchStarting11PrepareLineup($_POST['substitutes'] ?? []);
                    $squadNumbers = matchStarting11BuildSquadNumbers($starterSlots, $substitutes);
                    $captain = trim((string)($_POST['captain'] ?? ''));
                    $backgroundImage = trim((string)($fixture['starting11_background_image'] ?? ''));

                    if (count($starters) !== count(array_unique($starters))) {
                              $errors[] = 'A starter can only be selected once.';
                    }
                    if (count($substitutes) > 9) {
                              $errors[] = 'Select no more than 9 substitutes.';
                    }
                    if (count(array_intersect($starters, $substitutes)) > 0) {
                              $errors[] = 'A player cannot appear in both starters and substitutes.';
                    }
                    if ($captain !== '' && !in_array($captain, $starters, true)) {
                              $errors[] = 'Captain must be one of the selected starters.';
                    }

                    if (isset($_FILES['background_image']) && (int)($_FILES['background_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                              $template = matchStarting11TemplateConfig('starting_xi_classic');
                              $file = $_FILES['background_image'];
                              $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                              if ($errorCode !== UPLOAD_ERR_OK) {
                                        $errors[] = 'Background upload failed.';
                              } else {
                                        $tmpPath = (string)($file['tmp_name'] ?? '');
                                        $mime = (string)@mime_content_type($tmpPath);
                                        $extensionMap = [
                                                  'image/jpeg' => 'jpg',
                                                  'image/png' => 'png',
                                                  'image/webp' => 'webp',
                                        ];
                                        if (!isset($extensionMap[$mime])) {
                                                  $errors[] = 'Background must be a JPG, PNG, or WEBP image.';
                                        } else {
                                                  $uploadDir = __DIR__ . '/uploads/matches';
                                                  if (!is_dir($uploadDir)) {
                                                            @mkdir($uploadDir, 0775, true);
                                                  }
                                                  $filename = sprintf(
                                                            'fixture_%d_starting11_%s_%s.%s',
                                                            $fixtureId,
                                                            date('YmdHis'),
                                                            bin2hex(random_bytes(6)),
                                                            $extensionMap[$mime]
                                                  );
                                                  $targetPath = $uploadDir . '/' . $filename;
                                                  if (!move_uploaded_file($tmpPath, $targetPath)) {
                                                            $errors[] = 'Failed to store the background image.';
                                                  } else {
                                                            $previousPath = trim((string)($fixture['starting11_background_image'] ?? ''));
                                                            $backgroundImage = 'uploads/matches/' . $filename;
                                                            if ($previousPath !== '' && str_starts_with($previousPath, 'uploads/matches/')) {
                                                                      $absolutePreviousPath = __DIR__ . '/' . $previousPath;
                                                                      if (is_file($absolutePreviousPath)) {
                                                                                @unlink($absolutePreviousPath);
                                                                      }
                                                            }
                                                  }
                                        }
                              }
                    }

                    if (!$errors) {
                              $submitAction = (string)($_POST['submit_action'] ?? 'save');
                              $stmt = $pdo->prepare("
                    UPDATE match_fixtures
                    SET starting11_template_key = :template_key,
                        starting11_background_image = :background_image,
                        starting11_starters_json = :starters_json,
                        starting11_substitutes_json = :substitutes_json,
                        starting11_squad_numbers_json = :squad_numbers_json,
                        starting11_captain = :captain
                    WHERE id = :id
                    LIMIT 1
          ");
                              $stmt->execute([
                                        ':template_key' => $template['key'],
                                        ':background_image' => $backgroundImage !== '' ? $backgroundImage : null,
                                        ':starters_json' => json_encode(array_values($starterSlots), JSON_UNESCAPED_SLASHES),
                                        ':substitutes_json' => json_encode(array_values($substitutes), JSON_UNESCAPED_SLASHES),
                                        ':squad_numbers_json' => json_encode($squadNumbers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                        ':captain' => $captain !== '' ? $captain : null,
                                        ':id' => $fixtureId,
                              ]);

                              auditLog($pdo, 'match_lineup_saved', 'Saved starting XI for fixture vs ' . (string) ($fixture['opponent'] ?? '') . ' on ' . (string) ($fixture['match_date'] ?? ''));

                              if ($ajaxRequest) {
                                        ob_clean();
                                        header('Content-Type: application/json; charset=utf-8');
                                        echo json_encode([
                                                  'ok' => true,
                                                  'message' => 'Starting 11 saved.',
                                                  'background_image_url' => $backgroundImage !== ''
                                                            ? '/' . ltrim($backgroundImage, '/')
                                                            : matchTemplatePackAssetUrl((string)($packContext['action']['background_path'] ?? '')),
                                        ], JSON_UNESCAPED_SLASHES);
                                        exit;
                              }

                              if ($submitAction === 'graphic') {
                                        header('Location: match_starting_11_graphic.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId);
                                        exit;
                              }

                              header('Location: match_starting_11.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId . '&saved=1' . ($embedded ? '&embedded=1' : ''));
                              exit;
                    }

                    $fixture['starting11_template_key'] = $template['key'];
                    $fixture['starting11_background_image'] = $backgroundImage;
                    $fixture['starting11_starters'] = array_values($starterSlots);
                    $fixture['starting11_substitutes'] = array_pad($substitutes, 9, '');
                    $fixture['starting11_squad_numbers'] = $squadNumbers;
                    $fixture['starting11_captain'] = $captain;
          }

          if ($ajaxRequest) {
                    ob_clean();
                    http_response_code(422);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => false,
                              'message' => $errors ? implode(' ', $errors) : 'The Starting 11 could not be saved.',
                    ], JSON_UNESCAPED_SLASHES);
                    exit;
          }
}

$fixtureLabel = ($fixture['is_home'] ? 'Home v ' : 'Away v ') . (string)$fixture['opponent'];
$packBackgroundUrl = matchTemplatePackAssetUrl((string)($packContext['action']['background_path'] ?? ''));
$fixtureBackgroundUrl = !empty($fixture['starting11_background_image'])
          ? '/' . ltrim((string)$fixture['starting11_background_image'], '/')
          : '';
$effectiveBackgroundUrl = $fixtureBackgroundUrl !== '' ? $fixtureBackgroundUrl : $packBackgroundUrl;
?>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11.css">

<?php if ($embedded): ?>
<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11-2.css">
<?php endif; ?>

<div class="starting11-shell">
  <?php if (!$embedded): ?>
  <div class="page-hero mb-4">
    <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
      <div>
        <div class="page-hero-eyebrow">Match workflow</div>
        <h1 class="page-hero-title">Starting 11</h1>
        <p class="page-hero-subtitle"><?= h($fixtureLabel) ?><?php if (!empty($fixture['match_date'])): ?> · <?= h(date('d/m/Y', strtotime((string)$fixture['match_date']))) ?><?php endif; ?></p>
      </div>
    </div>
  </div>

  <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="match.php?id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>"><?= h((string)$fixture['opponent']) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Starting 11</span></nav>

  <div class="hub-section-commandbar">
    <div><h2>Line-up builder</h2><p>Changes are saved automatically as you select the squad.</p></div>
    <div class="hub-local-actions"><a href="match_starting_11_graphic.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-image me-1" aria-hidden="true"></i>Create graphic</a></div>
  </div>
  <?php endif; ?>

  <?php if ($saved): ?>
    <div class="alert alert-success">Starting 11 saved.</div>
  <?php endif; ?>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endforeach; ?>
  <?php if (isset($_GET['availability_saved'])): ?>
    <div class="alert alert-success">Player availability saved.</div>
  <?php endif; ?>
  <?php if (isset($_GET['availability_error'])): ?>
    <div class="alert alert-danger"><?= h((string) $_GET['availability_error']) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="starting11Form">
    <?= csrf_field() ?>
    <input type="hidden" name="fixture_id" value="<?= (int)$fixtureId ?>">
    <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
    <input type="hidden" name="template_key" value="starting_xi_classic">
    <?php if ($embedded): ?>
      <input type="hidden" name="embedded" value="1">
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-12 col-xl-8">
        <div class="starting11-panel p-4 mb-4">
          <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
              <div class="starting11-kicker">Lineup</div>
              <h2 class="starting11-title h4 mb-1">Starting XI</h2>
              <p class="text-muted mb-0">Set the display order and mark the captain.</p>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle starting11-table mb-0 hub-data-table">
              <thead>
                <tr>
                  <th style="width: 80px;">No.</th>
                  <th>Player</th>
                  <th style="width: 110px;">Captain</th>
                </tr>
              </thead>
              <tbody>
                <?php for ($i = 0; $i < 11; $i++): ?>
                  <?php $selectedStarter = (string)($fixture['starting11_starters'][$i] ?? ''); ?>
                  <tr>
                    <td><span class="starting11-number"><?= $i + 1 ?></span></td>
                    <td>
                      <select class="form-select starter-select" name="starters[]" data-slot="<?= $i ?>">
                        <option value="">Select player</option>
                        <?php foreach ($playerNames as $name): ?>
                          <?php
                          $availability = $playerAvailabilityByName[$name] ?? [];
                          $availabilityLabel = (string)($availability['availability_label'] ?? 'Unknown');
                          $availabilityBlocks = !empty($availability['availability_blocks_selection']);
                          ?>
                          <option value="<?= h($name) ?>" <?= $selectedStarter === $name ? 'selected' : '' ?> data-availability-status="<?= h((string)($availability['availability_status'] ?? 'unknown')) ?>">
                            <?= h($name) ?><?= ($playerStatusesByName[$name] ?? '') === 'trialist' ? ' (Trialist)' : '' ?><?= $availabilityBlocks ? ' - ' . h($availabilityLabel) : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td class="text-center">
                      <input class="form-check-input starting11-captain" type="radio" name="captain_choice" value="<?= $i ?>" <?= ($selectedStarter !== '' && $selectedStarter === (string)($fixture['starting11_captain'] ?? '')) ? 'checked' : '' ?>>
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
          <input type="hidden" name="captain" id="captainInput" value="<?= h((string)($fixture['starting11_captain'] ?? '')) ?>">
        </div>

        <div class="starting11-panel p-4">
          <div class="starting11-kicker">Bench</div>
          <h2 class="starting11-title h4 mb-1">Substitutes</h2>
          <p class="text-muted mb-3">Pick up to 9 substitutes. Players can only appear once.</p>

          <div class="starting11-subs">
            <?php foreach ($playerNames as $name): ?>
              <?php $substituteNumber = (int)($fixture['starting11_squad_numbers'][$name] ?? 0); ?>
              <?php
              $availability = $playerAvailabilityByName[$name] ?? [];
              $availabilityStatus = (string)($availability['availability_status'] ?? 'unknown');
              $availabilityBlocks = !empty($availability['availability_blocks_selection']);
              ?>
              <button type="button" class="starting11-sub-pill<?= in_array($name, $fixture['starting11_substitutes'] ?? [], true) ? ' is-selected' : '' ?><?= $availabilityBlocks ? ' is-unavailable' : '' ?>" data-player-name="<?= h($name) ?>" data-availability-status="<?= h($availabilityStatus) ?>" aria-pressed="<?= in_array($name, $fixture['starting11_substitutes'] ?? [], true) ? 'true' : 'false' ?>"<?= $availabilityBlocks ? ' title="' . h((string)$availability['availability_label']) . '"' : '' ?>>
                <span class="starting11-sub-pill-number"<?= $substituteNumber > 0 ? '' : ' hidden' ?>><?= $substituteNumber > 0 ? $substituteNumber : '' ?></span>
                <span class="starting11-sub-pill-name"><?= h($name) ?><?= ($playerStatusesByName[$name] ?? '') === 'trialist' ? ' (Trialist)' : '' ?><?= $availabilityBlocks ? ' - ' . h((string)$availability['availability_label']) : '' ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <div id="substitutesInputHost"></div>
        </div>
      </div>

      <div class="col-12 col-xl-4">
        <div class="starting11-panel p-4 mb-4" id="playerAvailabilityPanel">
          <div class="starting11-kicker">Squad welfare</div>
          <h2 class="starting11-title h4 mb-1">Availability</h2>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge text-bg-success"><?= (int)$availabilitySummary['available'] ?> available</span>
            <span class="badge text-bg-warning"><?= (int)$availabilitySummary['doubtful'] ?> doubtful</span>
            <span class="badge text-bg-danger"><?= (int)$availabilitySummary['blocked'] ?> unavailable</span>
            <span class="badge text-bg-light"><?= (int)$availabilitySummary['unknown'] ?> unknown</span>
          </div>
          <form method="post" action="/admin/player_availability_save.php" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="fixture_id" value="<?= (int)$fixtureId ?>">
            <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
            <div class="col-12">
              <label class="form-label small" for="availabilityPlayerId">Player</label>
              <select class="form-select form-select-sm" id="availabilityPlayerId" name="player_id" required>
                <option value="">Select player</option>
                <?php foreach ($players as $playerOption): ?>
                  <option value="<?= (int)$playerOption['id'] ?>"><?= h((string)$playerOption['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small" for="availabilityStatus">Status</label>
              <select class="form-select form-select-sm" id="availabilityStatus" name="status" required>
                <?php foreach ($availabilityStatuses as $statusKey => $statusLabel): ?>
                  <option value="<?= h((string)$statusKey) ?>"><?= h((string)$statusLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small" for="availabilityReason">Reason</label>
              <input type="text" class="form-control form-control-sm" id="availabilityReason" name="reason" maxlength="80" placeholder="Work, injury, suspension">
            </div>
            <div class="col-12">
              <label class="form-label small" for="availabilityNotes">Notes</label>
              <input type="text" class="form-control form-control-sm" id="availabilityNotes" name="notes" maxlength="255">
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-notes-medical me-1" aria-hidden="true"></i>Save availability</button>
            </div>
          </form>

          <?php $availabilityRows = array_values(array_filter($players, static fn(array $row): bool => (string)($row['availability_status'] ?? 'unknown') !== 'unknown')); ?>
          <?php if ($availabilityRows !== []): ?>
            <hr>
            <div class="small fw-semibold mb-2">Recorded for this fixture</div>
            <div class="d-grid gap-2">
              <?php foreach ($availabilityRows as $availabilityRow): ?>
                <div class="d-flex justify-content-between align-items-start gap-2 small">
                  <div>
                    <strong><?= h((string)$availabilityRow['name']) ?></strong>
                    <?php if (trim((string)$availabilityRow['availability_reason']) !== ''): ?><div class="text-muted"><?= h((string)$availabilityRow['availability_reason']) ?></div><?php endif; ?>
                  </div>
                  <span class="badge <?= h((string)$availabilityRow['availability_badge_class']) ?>"><?= h((string)$availabilityRow['availability_label']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="starting11-panel p-4 mb-4">
          <div class="starting11-kicker">Artwork</div>
          <h2 class="starting11-title h4 mb-1">Background</h2>
          <p class="text-muted">Upload the image used for this fixture's starting 11 artwork.</p>

          <label class="form-label" for="backgroundImageInput">Background image</label>
          <label class="starting11-upload-zone mb-3" id="backgroundUploadZone" for="backgroundImageInput">
            <input type="file" id="backgroundImageInput" name="background_image" accept="image/png,image/jpeg,image/webp">
            <i class="fa-solid fa-cloud-arrow-up fa-xl mb-2" aria-hidden="true"></i>
            <span class="d-block fw-semibold">Drop an image here or click to choose</span>
            <span class="d-block small text-muted">JPG, PNG or WEBP — uploads automatically</span>
            <span class="starting11-upload-zone__status" id="backgroundUploadZoneStatus" role="status" aria-live="polite"></span>
          </label>

          <label class="form-label" for="templatePackChoice">Template pack</label>
          <select class="form-select mb-2" name="template_pack_choice" id="templatePackChoice">
            <option value="automatic"<?= $packIsAutomatic ? ' selected' : '' ?>>
              Automatic<?= !empty($packContext['pack']['name']) ? ' — ' . h((string)$packContext['pack']['name']) : '' ?>
            </option>
            <?php foreach ($availableTemplatePacks as $availablePack): ?>
              <?php $availablePackId = (int)($availablePack['id'] ?? 0); ?>
              <option value="pack:<?= $availablePackId ?>"<?= !$packIsAutomatic && (int)($packContext['pack']['id'] ?? 0) === $availablePackId ? ' selected' : '' ?>>
                <?= h((string)($availablePack['name'] ?? 'Template pack')) ?> · v<?= (int)($availablePack['current_version_number'] ?? 1) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text mb-3">
            <?= $packIsAutomatic ? 'Inherited from the season or global default and locked to this fixture.' : 'This fixture has its own pack selection.' ?>
            <a href="/admin/template_packs.php" target="_top">Manage packs</a>
          </div>

          <div class="starting11-preview" id="backgroundPreview">
            <iframe
              id="starting11GraphicPreview"
              src="match_starting_11_graphic.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;preview_only=1"
              title="Selected Starting XI template preview"
              loading="eager"
            ></iframe>
          </div>
        </div>

        <div class="starting11-panel p-4">
          <div class="starting11-kicker">Continue</div>
          <h2 class="starting11-title h4 mb-1">Create the graphic</h2>
          <p class="text-muted">Your lineup and artwork save automatically.</p>
          <div class="starting11-save-status text-muted mb-2" id="starting11SaveStatus" role="status" aria-live="polite">All changes saved</div>

          <div class="d-grid gap-2">
            <button type="submit" name="submit_action" value="graphic" class="btn btn-brand" id="starting11NextButton"<?= $embedded ? ' formtarget="_top"' : '' ?> data-next-url="match_starting_11_graphic.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>">NEXT</button>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
  (function () {
    var starterSelects = Array.prototype.slice.call(document.querySelectorAll('.starter-select'));
    var captainRadios = Array.prototype.slice.call(document.querySelectorAll('input[name="captain_choice"]'));
    var captainInput = document.getElementById('captainInput');
    var subPills = Array.prototype.slice.call(document.querySelectorAll('.starting11-sub-pill'));
    var substitutesInputHost = document.getElementById('substitutesInputHost');
    var form = document.getElementById('starting11Form');
    var legacyTemplateInput = form ? form.querySelector('input[name="template_key"]') : null;
    var packSelect = document.getElementById('templatePackChoice');
    var fileInput = document.getElementById('backgroundImageInput');
    var uploadZone = document.getElementById('backgroundUploadZone');
    var zoneStatus = document.getElementById('backgroundUploadZoneStatus');
    var preview = document.getElementById('backgroundPreview');
    var previewFrame = document.getElementById('starting11GraphicPreview');
    var saveStatus = document.getElementById('starting11SaveStatus');
    var nextButton = document.getElementById('starting11NextButton');
    var saveRequested = false;
    var saving = false;
    var savePromise = Promise.resolve(true);
    var previewObjectUrl = '';
    var zoneStatusHideTimer = 0;

    function setZoneStatus(state, message) {
      if (!zoneStatus) return;
      window.clearTimeout(zoneStatusHideTimer);

      if (!message) {
        zoneStatus.className = 'starting11-upload-zone__status';
        zoneStatus.textContent = '';
        return;
      }

      zoneStatus.className = 'starting11-upload-zone__status is-visible state-' + state;
      zoneStatus.textContent = message;

      if (state === 'success') {
        zoneStatusHideTimer = window.setTimeout(function () {
          setZoneStatus('', '');
        }, 2500);
      }
    }

    function detectBackgroundFileKind(file) {
      var type = (file.type || '').toLowerCase();
      var name = (file.name || '').toLowerCase();

      if (type === 'image/jpeg' || type === 'image/png' || type === 'image/webp') {
        return 'ok';
      }
      if (type === 'image/heic' || type === 'image/heif' || /\.(heic|heif)$/.test(name)) {
        return 'heic';
      }
      if ((type === '' || type === 'application/octet-stream') && /\.(jpe?g|png|webp)$/.test(name)) {
        return 'ok';
      }
      return 'unsupported';
    }
    var allPlayerNames = <?=
      json_encode(array_values($playerNames), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ?>;
    var playerSelectLabels = <?=
      json_encode(array_reduce($players, static function (array $labels, array $playerRow): array {
        $name = (string)($playerRow['name'] ?? '');
        $label = $name;
        if ((string)($playerRow['status'] ?? '') === 'trialist') {
          $label .= ' (Trialist)';
        }
        if (!empty($playerRow['availability_blocks_selection'])) {
          $label .= ' - ' . (string)($playerRow['availability_label'] ?? 'Unavailable');
        }
        $labels[$name] = $label;
        return $labels;
      }, []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ?>;
    var playerAvailabilityStatuses = <?=
      json_encode(array_reduce($players, static function (array $statuses, array $playerRow): array {
        $statuses[(string)($playerRow['name'] ?? '')] = (string)($playerRow['availability_status'] ?? 'unknown');
        return $statuses;
      }, []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ?>;
    var selectedSubs = <?=
      json_encode(array_values(matchStarting11PrepareLineup($fixture['starting11_substitutes'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ?>;

    function setSaveStatus(message, state) {
      if (!saveStatus) return;
      saveStatus.textContent = message;
      saveStatus.classList.toggle('text-danger', state === 'error');
      saveStatus.classList.toggle('text-success', state === 'success');
      saveStatus.classList.toggle('text-muted', !state || state === 'saving');
    }

    function performAutosave() {
      var data = new FormData(form);
      var uploadedFile = fileInput && fileInput.files ? fileInput.files[0] : null;
      data.set('ajax', '1');
      data.set('submit_action', 'save');
      setSaveStatus(uploadedFile ? 'Uploading image…' : 'Saving changes…', 'saving');
      if (uploadedFile) {
        setZoneStatus('busy', 'Uploading…');
      }

      return fetch(form.action || window.location.href, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json().catch(function () {
          throw new Error('The server returned an invalid response.');
        }).then(function (result) {
          if (!response.ok || !result.ok) {
            throw new Error(result.message || 'The Starting 11 could not be saved.');
          }
          return result;
        });
      }).then(function (result) {
        if (uploadedFile && fileInput.files && fileInput.files[0] === uploadedFile) {
          fileInput.value = '';
        }
        if (previewFrame) {
          var previewUrl = new URL(previewFrame.src, window.location.href);
          previewUrl.searchParams.set('preview_version', Date.now().toString());
          previewFrame.src = previewUrl.toString();
        }
        setSaveStatus('All changes saved', 'success');
        if (uploadedFile) {
          setZoneStatus('success', 'Uploaded ✓');
        }
        return true;
      }).catch(function (error) {
        setSaveStatus(error.message || 'The Starting 11 could not be saved.', 'error');
        if (uploadedFile) {
          setZoneStatus('error', error.message || 'Upload failed. Please try again.');
        }
        return false;
      });
    }

    function requestAutosave() {
      saveRequested = true;
      if (saving) return savePromise;

      saving = true;
      savePromise = (function runSaveQueue() {
        saveRequested = false;
        return performAutosave().then(function (saved) {
          if (!saved) {
            saveRequested = false;
            saving = false;
            return false;
          }
          if (saveRequested) {
            return runSaveQueue();
          }
          saving = false;
          return true;
        });
      }());
      return savePromise;
    }

    function showLocalPreview(file) {
      if (!file || !preview || previewFrame) return;
      if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
      previewObjectUrl = URL.createObjectURL(file);
      preview.innerHTML = '';
      var image = document.createElement('img');
      image.src = previewObjectUrl;
      image.alt = 'Selected Starting 11 background preview';
      preview.appendChild(image);
    }

    function optimiseBackgroundFile(file) {
      var uploadTargetBytes = 8 * 1024 * 1024;
      if (!file || file.size <= uploadTargetBytes) {
        return Promise.resolve(file);
      }

      setSaveStatus('Preparing large image…', 'saving');
      setZoneStatus('busy', 'Preparing large image…');
      return new Promise(function (resolve, reject) {
        var sourceUrl = URL.createObjectURL(file);
        var sourceImage = new Image();
        sourceImage.onload = function () {
          URL.revokeObjectURL(sourceUrl);
          var maxDimension = 3000;
          var scale = Math.min(1, maxDimension / Math.max(sourceImage.width, sourceImage.height));
          var canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(sourceImage.width * scale));
          canvas.height = Math.max(1, Math.round(sourceImage.height * scale));
          var context = canvas.getContext('2d');
          if (!context) {
            reject(new Error('This browser could not prepare the image.'));
            return;
          }
          context.drawImage(sourceImage, 0, 0, canvas.width, canvas.height);
          canvas.toBlob(function (blob) {
            if (!blob) {
              reject(new Error('This browser could not prepare the image.'));
              return;
            }
            var baseName = file.name.replace(/\.[^.]+$/, '') || 'background';
            resolve(new File([blob], baseName + '.webp', {
              type: 'image/webp',
              lastModified: Date.now()
            }));
          }, 'image/webp', 0.88);
        };
        sourceImage.onerror = function () {
          URL.revokeObjectURL(sourceUrl);
          reject(new Error('The selected image could not be read.'));
        };
        sourceImage.src = sourceUrl;
      });
    }

    function selectBackgroundFile(file) {
      if (!file) return;

      var kind = detectBackgroundFileKind(file);
      if (kind === 'heic') {
        var heicMessage = 'iPhone HEIC photos aren\'t supported — please choose a JPG, PNG or WEBP.';
        setSaveStatus(heicMessage, 'error');
        setZoneStatus('error', heicMessage);
        return;
      }
      if (kind !== 'ok') {
        var typeMessage = 'Please choose a JPG, PNG or WEBP image.';
        setSaveStatus(typeMessage, 'error');
        setZoneStatus('error', typeMessage);
        return;
      }

      setZoneStatus('busy', 'Preparing…');
      showLocalPreview(file);
      optimiseBackgroundFile(file).then(function (preparedFile) {
        var transfer = new DataTransfer();
        transfer.items.add(preparedFile);
        fileInput.files = transfer.files;
        if (legacyTemplateInput) {
          legacyTemplateInput.value = 'starting_xi_classic';
        }
        requestAutosave();
      }).catch(function (error) {
        var message = error.message || 'The image could not be prepared.';
        setSaveStatus(message, 'error');
        setZoneStatus('error', message);
      });
    }

    function starterNames() {
      return starterSelects
        .map(function (select) { return select.value.trim(); })
        .filter(function (value, index, all) { return value !== '' && all.indexOf(value) === index; });
    }

    function syncStarterOptions() {
      var selectedValues = starterSelects.map(function (select) {
        return select.value.trim();
      });

      starterSelects.forEach(function (select, selectIndex) {
        var currentValue = selectedValues[selectIndex];
        var blocked = selectedValues.filter(function (value, valueIndex) {
          return value !== '' && valueIndex !== selectIndex;
        });

        var previousValue = currentValue;
        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select player';
        select.appendChild(placeholder);

        allPlayerNames.forEach(function (name) {
          if (blocked.indexOf(name) !== -1 && name !== previousValue) {
            return;
          }

          var option = document.createElement('option');
          option.value = name;
          option.textContent = playerSelectLabels[name] || name;
          option.dataset.availabilityStatus = playerAvailabilityStatuses[name] || 'unknown';
          if (name === previousValue) {
            option.selected = true;
          }
          select.appendChild(option);
        });

        if (previousValue === '') {
          select.value = '';
        }
      });
    }

    function syncCaptain() {
      var currentCaptain = '';
      captainRadios.forEach(function (radio, index) {
        var starter = starterSelects[index].value.trim();
        radio.disabled = starter === '';
        if (radio.checked && starter !== '') {
          currentCaptain = starter;
        }
        if (starter === '' && radio.checked) {
          radio.checked = false;
        }
      });
      captainInput.value = currentCaptain;
    }

    function syncSubs() {
      var starters = starterNames();
      var selectedCount = 0;
      subPills.forEach(function (pill) {
        var playerName = pill.getAttribute('data-player-name') || '';
        var isStarter = starters.indexOf(playerName) !== -1;
        if (isStarter) {
          selectedSubs = selectedSubs.filter(function (name) { return name !== playerName; });
        }
        if (selectedSubs.indexOf(playerName) !== -1 && !isStarter) {
          selectedCount++;
        }
      });

      subPills.forEach(function (pill) {
        var playerName = pill.getAttribute('data-player-name') || '';
        var isStarter = starters.indexOf(playerName) !== -1;
        var limitReached = selectedCount >= 9;
        var isSelected = selectedSubs.indexOf(playerName) !== -1;
        var selectedIndex = selectedSubs.indexOf(playerName);
        var numberBadge = pill.querySelector('.starting11-sub-pill-number');
        pill.disabled = isStarter || (limitReached && !isSelected);
        pill.classList.toggle('is-disabled', isStarter);
        pill.classList.toggle('is-selected', isSelected && !isStarter);
        pill.setAttribute('aria-pressed', isSelected && !isStarter ? 'true' : 'false');
        if (numberBadge) {
          var squadNumber = 12 + selectedIndex;
          if (squadNumber >= 13) squadNumber++;
          numberBadge.textContent = isSelected && !isStarter ? String(squadNumber) : '';
          numberBadge.hidden = !isSelected || isStarter;
        }
      });

      if (substitutesInputHost) {
        substitutesInputHost.innerHTML = '';
        selectedSubs.forEach(function (name) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'substitutes[]';
          input.value = name;
          substitutesInputHost.appendChild(input);
        });
      }
    }

    starterSelects.forEach(function (select) {
      select.addEventListener('change', function () {
        syncStarterOptions();
        syncCaptain();
        syncSubs();
        requestAutosave();
      });
    });

    captainRadios.forEach(function (radio, index) {
      radio.addEventListener('change', function () {
        if (radio.checked) {
          captainInput.value = starterSelects[index].value.trim();
        }
        requestAutosave();
      });
    });

    subPills.forEach(function (pill) {
      pill.addEventListener('click', function (event) {
        var playerName = pill.getAttribute('data-player-name') || '';
        if (!playerName || pill.disabled) {
          event.preventDefault();
          return;
        }

        event.preventDefault();
        if (selectedSubs.indexOf(playerName) !== -1) {
          selectedSubs = selectedSubs.filter(function (name) { return name !== playerName; });
        } else if (selectedSubs.length < 9) {
          selectedSubs.push(playerName);
        }
        syncSubs();
        requestAutosave();
      });
    });

    if (packSelect) {
      packSelect.addEventListener('change', requestAutosave);
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (file) {
          selectBackgroundFile(file);
        }
      });
    }

    if (uploadZone) {
      ['dragenter', 'dragover'].forEach(function (eventName) {
        uploadZone.addEventListener(eventName, function (event) {
          event.preventDefault();
          uploadZone.classList.add('is-dragging');
          setZoneStatus('busy', 'Drop to upload…');
        });
      });
      ['dragleave', 'drop'].forEach(function (eventName) {
        uploadZone.addEventListener(eventName, function (event) {
          event.preventDefault();
          uploadZone.classList.remove('is-dragging');
          if (eventName === 'dragleave') {
            setZoneStatus('', '');
          }
        });
      });
      uploadZone.addEventListener('drop', function (event) {
        var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
        if (!file) {
          setZoneStatus('error', 'No image file was found in that drop.');
          return;
        }
        selectBackgroundFile(file);
      });
    }

    if (form && nextButton) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        nextButton.disabled = true;
        requestAutosave().then(function (saved) {
          if (saved) {
            window.top.location.href = nextButton.getAttribute('data-next-url');
            return;
          }
          nextButton.disabled = false;
        });
      });
    }

    syncStarterOptions();
    syncCaptain();
    syncSubs();
  }());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
