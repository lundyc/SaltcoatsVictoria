<?php
// ==========================================
// player_graphics.php — Player sponsor graphic preview (HTML/CSS)
// ==========================================
$pageHero = [
  'class' => 'player-graphics-hero',
  'eyebrow' => 'Creative studio',
  'title' => 'Player Sponsor Graphic',
  'subtitle' => 'Preview the player photo + sponsor panel graphic.',
  'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/player_graphic_html.php';
require_once __DIR__ . '/lib/media_library.php';

$seasonId = getSelectedSeasonId($pdo);

// ----------------------------------------------------------------------------------
// Fetch players for selector
// ----------------------------------------------------------------------------------
$players = $pdo->query("
  SELECT id, name
  FROM players
  WHERE active = 1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$playerId = (int)($_GET['player_id'] ?? 0);
if (!$playerId && $players) {
  $playerId = (int)$players[0]['id'];
}
if (!$playerId) {
  echo '<main><div class="alert alert-warning">No players found.</div></main>';
  require __DIR__ . '/footer.php';
  exit;
}

$graphic = buildPlayerSponsorGraphic($pdo, $playerId, $seasonId);
if (!$graphic) {
  echo '<main><div class="alert alert-danger">Player not found.</div></main>';
  require __DIR__ . '/footer.php';
  exit;
}

$currentPlayerIndex = null;
foreach ($players as $playerIndex => $p) {
  if ((int)$p['id'] === $playerId) {
    $currentPlayerIndex = $playerIndex;
    break;
  }
}
$prevPlayer = ($currentPlayerIndex !== null && $currentPlayerIndex > 0) ? $players[$currentPlayerIndex - 1] : null;
$nextPlayer = ($currentPlayerIndex !== null && $currentPlayerIndex < count($players) - 1) ? $players[$currentPlayerIndex + 1] : null;

$playerName = $graphic['name'];
$hasPhoto = $graphic['hasPhoto'];
$homeSponsorId = $graphic['homeSponsorId'];
$awaySponsorId = $graphic['awaySponsorId'];
$homeSponsorName = $graphic['homeSponsorName'];
$awaySponsorName = $graphic['awaySponsorName'];
$homeLogoSize = $graphic['homeLogoSize'];
$awayLogoSize = $graphic['awayLogoSize'];
$homeLogoHidden = $graphic['homeLogoHidden'];
$awayLogoHidden = $graphic['awayLogoHidden'];
$homeHasLogo = $graphic['homeHasLogo'];
$awayHasLogo = $graphic['awayHasLogo'];
$homeIsBusiness = $graphic['homeIsBusiness'];
$awayIsBusiness = $graphic['awayIsBusiness'];
$homeAddress = $graphic['homeAddress'];
$awayAddress = $graphic['awayAddress'];
$homeContactPhone = $graphic['homeContactPhone'];
$awayContactPhone = $graphic['awayContactPhone'];
$homeTextSettings = $graphic['homeTextSettings'];
$awayTextSettings = $graphic['awayTextSettings'];

$sponsorImagePickerItems = (!$homeHasLogo && $homeSponsorId) || (!$awayHasLogo && $awaySponsorId)
  ? hub_media_catalogue()
  : [];

// Show/hide + font-size controls for a sponsor's name, address or contact
// number text — saved per sponsor (like the logo controls above), see
// sponsor_graphic_text_visibility_save.php and sponsor_graphic_text_size_save.php.
function renderSponsorTextControl(string $slot, string $field, string $label, int $sponsorId, bool $hidden, ?int $size, int $min, int $max, int $default): void
{
  $controlId = $slot . ucfirst($field);
  ?>
  <div class="sponsor-text-control mt-3">
    <div class="form-check form-switch">
      <input class="form-check-input js-text-visible" type="checkbox" role="switch" id="<?= h($controlId) ?>Visible"
        data-sponsor-id="<?= $sponsorId ?>" data-slot="<?= h($slot) ?>" data-field="<?= h($field) ?>"
        <?= !$hidden ? 'checked' : '' ?>>
      <label class="form-check-label" for="<?= h($controlId) ?>Visible">Show <?= h($label) ?></label>
    </div>
    <label for="<?= h($controlId) ?>Size" class="form-label mt-1 small">Text size</label>
    <input type="range" class="form-range form-range-sm js-text-size" id="<?= h($controlId) ?>Size"
      min="<?= $min ?>" max="<?= $max ?>" step="1"
      value="<?= $size ?? $default ?>" data-sponsor-id="<?= $sponsorId ?>" data-slot="<?= h($slot) ?>" data-field="<?= h($field) ?>"
      <?= $hidden ? 'disabled' : '' ?>>
  </div>
  <?php
}

?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@100..900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/css/player_graphics.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/player_graphics.css') ?: time()) ?>">

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Player sponsor graphic</span></nav>
<div class="player-graphics-page">
  <div class="graphics-context">
    <div class="graphics-context__identity">
      <a class="graphics-nav-btn<?= !$prevPlayer ? ' is-disabled' : '' ?>" href="?player_id=<?= $prevPlayer ? (int)$prevPlayer['id'] : (int)$playerId ?>" aria-label="Previous player"<?= !$prevPlayer ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </a>
      <div class="graphics-context__player-picker">
        <label for="playerSelect" class="visually-hidden">Player</label>
        <select id="playerSelect" name="player_id" class="form-select">
          <?php foreach ($players as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $p['id'] === $playerId ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <a class="graphics-nav-btn<?= !$nextPlayer ? ' is-disabled' : '' ?>" href="?player_id=<?= $nextPlayer ? (int)$nextPlayer['id'] : (int)$playerId ?>" aria-label="Next player"<?= !$nextPlayer ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </a>
    </div>
    <div class="graphics-context__slots">
      <span class="<?= $hasPhoto ? 'is-filled' : '' ?>"><i class="fa-solid fa-image" aria-hidden="true"></i>Photo</span>
      <span class="<?= $homeSponsorName ? 'is-filled' : '' ?>"><i class="fa-solid fa-house" aria-hidden="true"></i>Home</span>
      <span class="<?= $awaySponsorName ? 'is-filled' : '' ?>"><i class="fa-solid fa-plane-departure" aria-hidden="true"></i>Away</span>
    </div>
  </div>

  <?php if (!$hasPhoto): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><div>No player photo uploaded — the graphic will show initials instead. <a href="player_edit.php?id=<?= (int)$playerId ?>">Upload a photo</a>.</div></div>
  <?php endif; ?>

  <div class="graphics-workspace">
    <aside class="graphics-sidebar editor-panel">
      <form id="editorControls">
        <section class="graphics-control">
          <div class="graphics-control__heading">
            <span class="graphics-control__step">1</span>
            <div><h3>Sponsor image &amp; text</h3><p>Saved per sponsor — settings follow the sponsor if reassigned to another player. Hide the image for sponsors who are individuals and just want their name shown.</p></div>
          </div>

          <div class="sponsor-text-control-group">
            <p class="sponsor-text-control-group__title">Home sponsor</p>
            <?php if ($homeSponsorId && !$homeHasLogo): ?>
              <button type="button" class="btn btn-outline-primary btn-sm js-add-sponsor-image" data-slot="home" data-sponsor-id="<?= (int)$homeSponsorId ?>" data-sponsor-name="<?= h((string)$homeSponsorName) ?>">
                <i class="fa-solid fa-image me-1"></i>Add home sponsor image
              </button>
            <?php elseif ($homeSponsorId): ?>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="homeLogoVisible"
                  data-sponsor-id="<?= (int)$homeSponsorId ?>"
                  <?= (!$homeLogoHidden) ? 'checked' : '' ?>>
                <label class="form-check-label" for="homeLogoVisible">Show home sponsor image</label>
              </div>
              <label for="homeLogoSize" class="form-label mt-1 small">Image size</label>
              <input type="range" class="form-range form-range-sm" id="homeLogoSize"
                min="<?= SPONSOR_GRAPHIC_MIN_LOGO_SIZE ?>" max="<?= SPONSOR_GRAPHIC_MAX_LOGO_SIZE ?>" step="2"
                value="<?= (int)$homeLogoSize ?>" data-sponsor-id="<?= (int)$homeSponsorId ?>"
                <?= (!$homeLogoHidden) ? '' : 'disabled' ?>>
            <?php else: ?>
              <div class="form-text">No home sponsor assigned.</div>
            <?php endif; ?>

            <?php if ($homeSponsorId): ?>
              <?php renderSponsorTextControl('home', 'name', 'sponsor name', (int)$homeSponsorId, $homeTextSettings['name_hidden'], $homeTextSettings['name_size'], SPONSOR_GRAPHIC_MIN_NAME_SIZE, SPONSOR_GRAPHIC_MAX_NAME_SIZE, SPONSOR_GRAPHIC_DEFAULT_NAME_SIZE); ?>
              <?php if ($homeIsBusiness && trim((string)$homeAddress) !== ''): ?>
                <?php renderSponsorTextControl('home', 'address', 'address', (int)$homeSponsorId, $homeTextSettings['address_hidden'], $homeTextSettings['address_size'], SPONSOR_GRAPHIC_MIN_TEXT_SIZE, SPONSOR_GRAPHIC_MAX_TEXT_SIZE, SPONSOR_GRAPHIC_DEFAULT_TEXT_SIZE); ?>
              <?php endif; ?>
              <?php if ($homeIsBusiness && trim((string)$homeContactPhone) !== ''): ?>
                <?php renderSponsorTextControl('home', 'contact', 'contact number', (int)$homeSponsorId, $homeTextSettings['contact_hidden'], $homeTextSettings['contact_size'], SPONSOR_GRAPHIC_MIN_TEXT_SIZE, SPONSOR_GRAPHIC_MAX_TEXT_SIZE, SPONSOR_GRAPHIC_DEFAULT_TEXT_SIZE); ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div class="sponsor-text-control-group mt-3">
            <p class="sponsor-text-control-group__title">Away sponsor</p>
            <?php if ($awaySponsorId && !$awayHasLogo): ?>
              <button type="button" class="btn btn-outline-primary btn-sm js-add-sponsor-image" data-slot="away" data-sponsor-id="<?= (int)$awaySponsorId ?>" data-sponsor-name="<?= h((string)$awaySponsorName) ?>">
                <i class="fa-solid fa-image me-1"></i>Add away sponsor image
              </button>
            <?php elseif ($awaySponsorId): ?>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="awayLogoVisible"
                  data-sponsor-id="<?= (int)$awaySponsorId ?>"
                  <?= (!$awayLogoHidden) ? 'checked' : '' ?>>
                <label class="form-check-label" for="awayLogoVisible">Show away sponsor image</label>
              </div>
              <label for="awayLogoSize" class="form-label mt-1 small">Image size</label>
              <input type="range" class="form-range form-range-sm" id="awayLogoSize"
                min="<?= SPONSOR_GRAPHIC_MIN_LOGO_SIZE ?>" max="<?= SPONSOR_GRAPHIC_MAX_LOGO_SIZE ?>" step="2"
                value="<?= (int)$awayLogoSize ?>" data-sponsor-id="<?= (int)$awaySponsorId ?>"
                <?= (!$awayLogoHidden) ? '' : 'disabled' ?>>
            <?php else: ?>
              <div class="form-text">No away sponsor assigned.</div>
            <?php endif; ?>

            <?php if ($awaySponsorId): ?>
              <?php renderSponsorTextControl('away', 'name', 'sponsor name', (int)$awaySponsorId, $awayTextSettings['name_hidden'], $awayTextSettings['name_size'], SPONSOR_GRAPHIC_MIN_NAME_SIZE, SPONSOR_GRAPHIC_MAX_NAME_SIZE, SPONSOR_GRAPHIC_DEFAULT_NAME_SIZE); ?>
              <?php if ($awayIsBusiness && trim((string)$awayAddress) !== ''): ?>
                <?php renderSponsorTextControl('away', 'address', 'address', (int)$awaySponsorId, $awayTextSettings['address_hidden'], $awayTextSettings['address_size'], SPONSOR_GRAPHIC_MIN_TEXT_SIZE, SPONSOR_GRAPHIC_MAX_TEXT_SIZE, SPONSOR_GRAPHIC_DEFAULT_TEXT_SIZE); ?>
              <?php endif; ?>
              <?php if ($awayIsBusiness && trim((string)$awayContactPhone) !== ''): ?>
                <?php renderSponsorTextControl('away', 'contact', 'contact number', (int)$awaySponsorId, $awayTextSettings['contact_hidden'], $awayTextSettings['contact_size'], SPONSOR_GRAPHIC_MIN_TEXT_SIZE, SPONSOR_GRAPHIC_MAX_TEXT_SIZE, SPONSOR_GRAPHIC_DEFAULT_TEXT_SIZE); ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </section>

        <section class="graphics-control">
          <div class="graphics-control__heading">
            <span class="graphics-control__step">2</span>
            <div><h3>Export</h3><p>Download the graphic for this player, or all players at once.</p></div>
          </div>
          <div class="graphics-button-grid graphics-button-grid--two">
            <button id="downloadBtn" type="button" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-image me-1"></i>Download PNG</button>
            <button id="downloadAllBtn" type="button" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-file-zipper me-1"></i>Download all as ZIP</button>
          </div>
          <div id="downloadStatus" class="form-text mt-2" style="display:none;">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span id="downloadStatusText">Rendering…</span>
          </div>
          <a id="hiddenDownloadLink" style="display:none"></a>
        </section>
      </form>
    </aside>

    <section class="graphics-canvas-panel">
      <header class="graphics-canvas-panel__header">
        <div><p>Live preview</p><h2><?= e($playerName) ?></h2></div>
      </header>

      <div id="canvas-shell">
        <div id="canvas-wrap">
          <div class="sponsor-graphic" id="graphic-preview">
            <?= $graphic['html'] ?>
          </div>
        </div>
      </div>
    </section>
  </div><!-- /graphics-workspace -->
</div>

<div class="modal fade" id="sponsorImageModal" tabindex="-1" aria-labelledby="sponsorImageModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="sponsorImageModalTitle">Add sponsor image</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="sponsorImageStatus" class="alert alert-danger d-none" role="alert"></div>

        <label class="sponsor-image-dropzone" id="sponsorImageDropzone" for="sponsorImageFile">
          <input class="visually-hidden" type="file" id="sponsorImageFile" accept="image/jpeg,image/png,image/webp,image/gif">
          <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
          <strong>Drop an image here</strong>
          <span class="small">or click to choose a JPG, PNG, WEBP or GIF — used directly, no need to pick it below too</span>
        </label>

        <div class="sponsor-image-picker-heading">
          <span>or choose from the media library</span>
          <input type="search" class="form-control form-control-sm w-auto" id="sponsorImagePickerSearch" placeholder="Search images…">
        </div>
        <div class="sponsor-image-picker-grid" id="sponsorImagePickerGrid"></div>
        <div class="text-muted small d-none" id="sponsorImagePickerEmpty">No images match your search.</div>
      </div>
      <div class="modal-footer">
        <div id="sponsorImageUploadStatus" class="form-text me-auto"></div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.EditorConfig = {
    playerId: <?= (int)$playerId ?>,
    seasonId: <?= (int)$seasonId ?>,
    playerName: <?= json_encode($playerName) ?>,
    players: <?= json_encode($players) ?>,
    csrfToken: <?= json_encode($_SESSION['csrf_token'] ?? '') ?>,
    mediaItems: <?= json_encode($sponsorImagePickerItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    endpoints: {
      fragment: 'player_graphic_fragment.php',
      saveLogoSize: 'sponsor_graphic_logo_size_save.php',
      saveLogoVisibility: 'sponsor_graphic_visibility_save.php',
      selectSponsorImage: 'sponsor_logo_select.php',
      saveTextVisibility: 'sponsor_graphic_text_visibility_save.php',
      saveTextSize: 'sponsor_graphic_text_size_save.php'
    }
  };
</script>

<script src="/admin/assets/js/vendor/jszip.min.js"></script>
<script src="/admin/assets/js/vendor/dom-to-image-more.min.js"></script>
<script src="/admin/assets/js/player_graphics_editor.js?v=<?= time() ?>"></script>

<?php require __DIR__ . '/footer.php'; ?>
