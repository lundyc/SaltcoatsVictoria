<?php
declare(strict_types=1);

$useSharedHubLayout = true;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$fixtureId = (int)($_GET['fixture_id'] ?? $_GET['id'] ?? 0);
$requestedSeasonId = (int)($_GET['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          http_response_code(404);
          $pageHero = [
                    'eyebrow' => 'Fixture management',
                    'title' => 'Match Poster',
                    'subtitle' => 'Fixture not found',
          ];
          require_once __DIR__ . '/header.php';
          echo '<div class="alert alert-danger">Fixture not found.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$seasonId = (int)($fixture['season_id'] ?? $requestedSeasonId);
$season = getSeasonById($pdo, $seasonId);
$pageHero = [
          'eyebrow' => 'Fixture promotion',
          'title' => 'Match Poster',
          'subtitle' => trim((string)$fixture['opponent']) . ' · ' . ($season['name'] ?? 'Season'),
          'actions' => [],
];

if ($useSharedHubLayout) {
          require_once __DIR__ . '/header.php';
} else {
          require_once __DIR__ . '/header.php';
}

if ($useSharedHubLayout) {
          echo '<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=' . $fixtureId . '&amp;season_id=' . $seasonId . '">' . h((string)$fixture['opponent']) . '</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Match poster</span></nav>';
}

// Admission prices are read here purely to pre-fill the form inputs — the
// actual poster markup (which also reads these from $_GET) comes from the
// shared partial below, so the on-screen preview and the server-rendered
// PNG/PDF export can never show different numbers.
$adultPrice = trim((string)($_GET['adult'] ?? '6.00'));
$concessionPrice = trim((string)($_GET['concession'] ?? '3.00'));
$under16Price = trim((string)($_GET['under16'] ?? 'FREE'));
?>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-poster.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/player-sponsors-match-poster.css') ?: time()) ?>">

<div class="match-poster-workspace">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 match-poster-page-heading">
    <div>
      <h2 class="h4 mb-1">Auto-generated fixture poster</h2>
      <p class="text-muted mb-0">Fixture, badges, venue and postcode are populated from the match database.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-brand" id="downloadMatchPoster">
        <i class="fa-solid fa-download me-2"></i>Download PNG
      </button>
      <button type="button" class="btn btn-outline-secondary" id="printMatchPoster">
        <i class="fa-solid fa-file-pdf me-2"></i>Print / Save PDF
      </button>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm match-poster-controls">
        <div class="card-body">
          <h3 class="h5 mb-3">Admission prices</h3>
          <div class="mb-3">
            <label class="form-label" for="posterAdultPrice">Adults</label>
            <div class="input-group"><span class="input-group-text">£</span><input class="form-control" id="posterAdultPrice" value="<?= h($adultPrice) ?>"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="posterConcessionPrice">Concession</label>
            <div class="input-group"><span class="input-group-text">£</span><input class="form-control" id="posterConcessionPrice" value="<?= h($concessionPrice) ?>"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="posterUnder16Price">Under 16s</label>
            <input class="form-control" id="posterUnder16Price" value="<?= h($under16Price) ?>">
          </div>
          <div class="form-text">The poster updates as you type. Fixture details remain linked to fixture #<?= (int)$fixtureId ?>.</div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-8">
      <div class="card shadow-sm overflow-hidden">
        <div class="card-body p-3 p-lg-4">
          <div class="match-poster-stage" id="matchPosterStage">
            <?php
            if (!defined('MATCH_POSTER_PARTIAL_ALLOWED')) {
                define('MATCH_POSTER_PARTIAL_ALLOWED', true);
            }
            require __DIR__ . '/match_poster_render_partial.php';
            ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const stage = document.getElementById('matchPosterStage');
  const poster = document.getElementById('matchPoster');
  const downloadButton = document.getElementById('downloadMatchPoster');
  const printButton = document.getElementById('printMatchPoster');
  const adultInput = document.getElementById('posterAdultPrice');
  const concessionInput = document.getElementById('posterConcessionPrice');
  const under16Input = document.getElementById('posterUnder16Price');

  const resize = () => {
    const scale = Math.min(1, stage.clientWidth / 794);
    poster.style.transform = `scale(${scale})`;
    stage.style.height = `${1123 * scale}px`;
  };
  resize();
  window.addEventListener('resize', resize);

  const setText = (selector, value) => {
    const target = poster.querySelector(selector);
    if (target) target.textContent = value.trim() || '—';
  };
  adultInput.addEventListener('input', () => setText('[data-poster-adult]', adultInput.value));
  concessionInput.addEventListener('input', () => setText('[data-poster-concession]', concessionInput.value));
  under16Input.addEventListener('input', () => setText('[data-poster-under16]', under16Input.value));

  // The PNG/PDF export is rendered server-side by a real headless browser
  // screenshotting the exact same match-poster markup/CSS this page uses
  // (see match_poster_fragment.php + match_poster_render_partial.php) — this
  // is what guarantees the download always looks identical to this preview,
  // rather than approximating it with a client-side canvas library.
  const renderUrl = (format) => {
    const params = new URLSearchParams({
      fixture_id: <?= json_encode((string)$fixtureId) ?>,
      season_id: <?= json_encode((string)$seasonId) ?>,
      adult: adultInput.value,
      concession: concessionInput.value,
      under16: under16Input.value,
      format,
    });
    return 'match_poster_render.php?' + params.toString();
  };

  downloadButton.addEventListener('click', async () => {
    downloadButton.disabled = true;
    const originalLabel = downloadButton.innerHTML;
    downloadButton.textContent = 'Generating poster…';
    try {
      const response = await fetch(renderUrl('png'));
      if (!response.ok) {
        const contentType = response.headers.get('content-type') || '';
        const message = contentType.includes('text/plain')
          ? await response.text().catch(() => '')
          : '';
        throw new Error(message || 'The poster could not be generated.');
      }
      const blob = await response.blob();
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = <?= json_encode('fixture-' . $fixtureId . '-poster.png', JSON_UNESCAPED_SLASHES) ?>;
      document.body.append(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(link.href);
    } catch (error) {
      window.hubToast(error?.message || 'The poster could not be generated.', 'danger');
    } finally {
      downloadButton.disabled = false;
      downloadButton.innerHTML = originalLabel;
    }
  });

  printButton.addEventListener('click', () => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      window.hubToast('Allow pop-ups for this site to open the PDF print view.', 'danger');
      return;
    }
    printWindow.location.href = renderUrl('print');
  });
})();
</script>

<?php
if ($useSharedHubLayout) {
          require_once __DIR__ . '/footer.php';
} else {
          require_once __DIR__ . '/footer.php';
}
