<?php

declare(strict_types=1);

/**
 * News — create / edit / delete an article for the public website.
 * Rich-text (HTML) body via TinyMCE; images upload through news_image_upload.php.
 * Legacy markdown bodies are converted to HTML on first edit.
 */

$id = (int) ($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Public website',
    'title' => $id > 0 ? 'Edit article' : 'Write article',
    'subtitle' => 'Published articles appear on the club website news feed and home page.',
    'actions' => [
        ['label' => '← All news', 'href' => 'news.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/news.php';
require_once __DIR__ . '/lib/audit.php';

news_ensure_schema($pdo);

$errors = [];
$article = $id > 0 ? news_find($pdo, $id) : null;
if ($id > 0 && !$article) {
    echo '<div class="alert alert-danger">Article not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

/** Fixtures for the "link to match" selector (most recent first). */
$fixtures = $pdo->query(
    "SELECT f.id, f.match_date, f.kickoff_time, f.opponent, f.is_home, f.competition, f.status,
            f.full_time_home_score AS hs, f.full_time_away_score AS aws
     FROM match_fixtures f
     ORDER BY f.match_date DESC LIMIT 1000"
)->fetchAll(PDO::FETCH_ASSOC);

$data = array_merge([
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    'body' => '',
    'body_format' => 'markdown',
    'hero_image_path' => '',
    'hero_focus_x' => 50,
    'hero_focus_y' => 50,
    'hero_zoom' => 1,
    'hero_framing' => '',
    'hero_caption' => '',
    'category' => 'Club news',
    'author_name' => (string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''),
    'status' => 'draft',
    'published_at' => '',
    'is_featured' => 0,
    'fixture_id' => null,
    'meta_title' => '',
    'meta_description' => '',
], $article ?: []);

if (!empty($article['published_at'])) {
    $data['published_at'] = date('Y-m-d\TH:i', strtotime((string) $article['published_at']));
}

// The editor is WYSIWYG (HTML). Any legacy markdown body is converted to HTML
// for editing and will be saved back as HTML.
$bodyForEditor = (($data['body_format'] ?? 'html') === 'markdown' && trim((string) $data['body']) !== '')
    ? news_render_markdown((string) $data['body'])
    : (string) $data['body'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $formAction = (string) ($_POST['form_action'] ?? 'save');

    if ($formAction === 'delete' && $id > 0) {
        if (!$errors) {
            news_delete($pdo, $id);
            auditLog($pdo, 'news_deleted', "Deleted news article '" . (string) $data['title'] . "'");
            header('Location: news.php?deleted=1');
            exit;
        }
    } elseif ($formAction === 'gallery_delete' && $id > 0) {
        if (!$errors) {
            news_gallery_delete($pdo, (int) ($_POST['image_id'] ?? 0), $id);
            header('Location: news_edit.php?id=' . $id);
            exit;
        }
    } else {
        foreach (['title', 'slug', 'excerpt', 'body', 'hero_image_path', 'hero_caption', 'hero_framing', 'category', 'author_name', 'status', 'published_at', 'meta_title', 'meta_description'] as $field) {
            $data[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $data['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
        $data['fixture_id'] = (int) ($_POST['fixture_id'] ?? 0) ?: null;
        $data['body_format'] = 'html';
        $bodyForEditor = (string) $data['body'];
        // hero_image_path is either "YYYY/MM/x.jpg" (upload) or a Media Library
        // path (uploads/… | badges/… | assets/…). Reject traversal.
        $data['hero_image_path'] = str_contains($data['hero_image_path'], '..')
            ? ''
            : ltrim((string) $data['hero_image_path'], '/');

        if ($data['title'] === '') {
            $errors[] = 'A title is required.';
        }
        if ($data['body'] === '') {
            $errors[] = 'The article body is empty.';
        }

        if (!$errors) {
            $newId = news_save($pdo, $id ?: null, $data, (int) ($currentUser['id'] ?? 0) ?: null);

            // Gallery uploads — classic file input (no-JS fallback).
            if (!empty($_FILES['gallery']['name'][0])) {
                $count = count($_FILES['gallery']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $one = [
                        'name' => $_FILES['gallery']['name'][$i],
                        'type' => $_FILES['gallery']['type'][$i],
                        'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                        'error' => $_FILES['gallery']['error'][$i],
                        'size' => $_FILES['gallery']['size'][$i],
                    ];
                    $stored = news_store_upload($one);
                    if ($stored['ok']) {
                        news_gallery_add($pdo, $newId, $stored['path']);
                    } else {
                        $errors[] = 'Gallery image skipped: ' . $stored['error'];
                    }
                }
            }

            // Gallery — photos dropped in and pre-uploaded this session via the
            // drag-and-drop zone (news_image_upload.php returns uploads/news paths).
            foreach ((array) ($_POST['gallery_new'] ?? []) as $rel) {
                $rel = trim((string) $rel);
                if ($rel !== ''
                    && preg_match('#^\d{4}/\d{2}/[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$#i', $rel)
                    && is_file(news_uploads_dir() . '/' . $rel)) {
                    news_gallery_add($pdo, $newId, $rel);
                }
            }

            // Gallery — items chosen from the Media Library (copied into uploads/news/).
            foreach ((array) ($_POST['gallery_library'] ?? []) as $src) {
                $src = trim((string) $src);
                if ($src === '') {
                    continue;
                }
                $res = news_gallery_add_from_library($pdo, $newId, $src);
                if (!$res['ok']) {
                    $errors[] = 'Media Library image skipped: ' . $res['error'];
                }
            }

            auditLog($pdo, $id > 0 ? 'news_updated' : 'news_created', ($id > 0 ? 'Updated' : 'Created') . " news article '{$data['title']}'");
            if (!$errors) {
                header('Location: news.php?saved=1');
                exit;
            }
            $id = $newId;
            $article = news_find($pdo, $newId);
        }
    }
}

$gallery = $id > 0 ? news_gallery($pdo, $id) : [];
$styleV = (int) (@filemtime(__DIR__ . '/assets/css/style.css') ?: time());
?>
<style>
  .ng-grid { display: flex; flex-wrap: wrap; gap: .6rem; }
  .ng-tile { position: relative; margin: 0; width: 120px; }
  .ng-tile img { width: 120px; height: 84px; object-fit: cover; border-radius: 6px; border: 1px solid #d7d2c8; display: block; background: #efe9df; }
  .ng-tile--pending img { outline: 2px solid #6d2231; outline-offset: 1px; }
  .ng-tile__x { position: absolute; top: -8px; right: -8px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: #b23b3b; color: #fff; font-size: 15px; line-height: 22px; padding: 0; cursor: pointer; }
  .ng-drop { display: flex; align-items: center; justify-content: center; gap: .55rem; padding: 1.1rem; border: 2px dashed #c9c2b4; border-radius: 8px; background: #faf7f1; color: #6c665c; text-align: center; cursor: pointer; transition: border-color .15s, background .15s, color .15s; }
  .ng-drop.is-over { border-color: #6d2231; background: #f3e9ea; color: #4c1521; }
  .ng-drop:focus-visible { outline: 2px solid #6d2231; outline-offset: 2px; }
  .ng-lib-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: .7rem; }
  .ng-lib-tile { border: 1px solid #d7d2c8; border-radius: 8px; background: #fff; padding: 0; overflow: hidden; cursor: pointer; text-align: left; }
  .ng-lib-tile img { width: 100%; height: 96px; object-fit: cover; display: block; background: #efe9df; }
  .ng-lib-tile span { display: block; padding: .35rem .5rem; font-size: .72rem; color: #6c665c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ng-lib-tile.is-sel { border-color: #6d2231; box-shadow: inset 0 0 0 2px #6d2231; }

  /* upload progress + result tiles */
  .ng-tile--pending img { animation: ng-fadein .25s ease; }
  @keyframes ng-fadein { from { opacity: 0; } to { opacity: 1; } }
  .ng-tile--uploading, .ng-tile--error {
    width: 150px; height: 96px; display: flex; flex-direction: column;
    justify-content: center; gap: .3rem; padding: .55rem .6rem;
    border: 1px solid #d7d2c8; border-radius: 6px; background: #fff;
  }
  .ng-tile--uploading { border-color: #6d2231; }
  .ng-tile__name { font-size: .68rem; color: #6c665c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ng-tile__prog { height: 7px; border-radius: 4px; background: #efe4d3; overflow: hidden; }
  .ng-tile__bar { display: block; height: 100%; width: 0; border-radius: 4px; background: linear-gradient(90deg, #6d2231, #e0b42a); transition: width .2s ease; }
  .ng-tile--uploading.is-indeterminate .ng-tile__prog { position: relative; }
  .ng-tile--uploading.is-indeterminate .ng-tile__bar { width: 45%; animation: ng-indet 1.1s ease-in-out infinite; }
  @keyframes ng-indet { 0% { margin-left: -45%; } 100% { margin-left: 100%; } }
  .ng-tile__pct { font-size: .62rem; font-weight: 700; color: #6d2231; text-align: right; }
  .ng-tile--error { border-color: #f0b7b7; background: #fdecec; }
  .ng-tile__err { font-size: .68rem; color: #9a2f2f; line-height: 1.3; overflow: hidden; }

  /* status line */
  .ng-status {
    display: flex; align-items: flex-start; gap: .5rem;
    margin-top: .7rem; padding: .55rem .8rem; border-radius: 6px;
    font-size: .82rem; line-height: 1.35; border: 1px solid transparent;
  }
  .ng-status__spin {
    flex: 0 0 auto; width: 14px; height: 14px; margin-top: .12rem;
    border: 2px solid currentColor; border-right-color: transparent;
    border-radius: 50%; animation: ng-spin .7s linear infinite;
  }
  @keyframes ng-spin { to { transform: rotate(360deg); } }
  .ng-status.is-busy { background: #eef4fb; border-color: #cfe0f2; color: #24597f; }
  .ng-status.is-ok   { background: #e9f5ee; border-color: #bfe2cd; color: #1b6b3d; }
  .ng-status.is-err  { background: #fdecec; border-color: #f0b7b7; color: #9a2f2f; }
  .ng-status.is-ok .ng-status__spin, .ng-status.is-err .ng-status__spin { display: none; }

  /* hero uploading box */
  .ng-hero-up {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: .55rem; min-height: 120px; margin-bottom: .5rem; padding: 1rem;
    border: 2px dashed #6d2231; border-radius: 8px; background: #faf7f1;
  }
  .ng-hero-up__name { font-size: .78rem; color: #6c665c; max-width: 90%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ng-hero-up__prog { width: min(320px, 80%); height: 8px; border-radius: 4px; background: #efe4d3; overflow: hidden; }
  .ng-hero-up__bar { display: block; height: 100%; width: 0; border-radius: 4px; background: linear-gradient(90deg, #6d2231, #e0b42a); transition: width .2s ease; }
  .ng-hero-up__pct { font-size: .74rem; font-weight: 700; color: #6d2231; }
</style>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-4">
  <?= csrf_field() ?>

  <div class="col-lg-8">
    <div class="card hub-section">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="title">Title</label>
          <input class="form-control form-control-lg" id="title" name="title" value="<?= h((string) $data['title']) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="slug">URL slug</label>
          <div class="input-group">
            <span class="input-group-text">/news/</span>
            <input class="form-control" id="slug" name="slug" value="<?= h((string) $data['slug']) ?>" placeholder="auto from title">
          </div>
          <div class="form-text">Leave blank to generate from the title. Changing it after publishing breaks old links.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="excerpt">Summary</label>
          <textarea class="form-control" id="excerpt" name="excerpt" rows="2" maxlength="400" placeholder="One or two sentences shown on cards and previews"><?= h((string) $data['excerpt']) ?></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label" for="body">Article</label>
          <?php $edClassic = (($_GET['editor'] ?? '') === 'classic'); $edQs = $id > 0 ? 'id=' . $id . '&' : ''; ?>
          <a class="small ms-2" href="?<?= $edQs ?>editor=<?= $edClassic ? 'blocks' : 'classic' ?>" data-no-dirty><?= $edClassic ? 'Switch to the block editor' : 'Use the classic editor' ?></a>
          <textarea id="body" name="body"><?= h($bodyForEditor) ?></textarea>
          <input type="hidden" name="body_format" value="html">
        </div>
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">Gallery</h2>
        <p class="text-muted small">Extra photos shown below the article on the website — pick from the Media Library or drop new photos in. They’re added when you save.</p>

        <?php if ($gallery): ?>
          <div class="ng-grid mb-3">
            <?php foreach ($gallery as $img): ?>
              <figure class="ng-tile">
                <img src="<?= h(news_image_url((string) $img['file_path'])) ?>" alt="">
                <button type="button" class="ng-tile__x" title="Remove" name="form_action" value="gallery_delete" formnovalidate
                        onclick="this.form.image_id.value='<?= (int) $img['id'] ?>'">&times;</button>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <input type="hidden" name="image_id" value="">

        <label class="ng-drop" data-ng-drop role="button" tabindex="0">
          <input type="file" name="gallery[]" accept="image/*" multiple hidden data-ng-file>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5m0 0l-4 4m4-4l4 4"/><path d="M5 19h14"/></svg>
          <span><strong>Drag &amp; drop photos</strong> here, or <u>browse</u></span>
        </label>

        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-ng-open-gallery>
            Choose from Media Library
          </button>
        </div>

        <div class="ng-grid mt-3" data-ng-pending hidden></div>
        <div class="ng-status" data-ng-status hidden><span class="ng-status__spin" aria-hidden="true"></span><span data-ng-status-text></span></div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card hub-section">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <?php foreach (['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'] as $v => $l): ?>
              <option value="<?= h($v) ?>" <?= $data['status'] === $v ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="published_at">Publish date &amp; time</label>
          <input class="form-control" type="datetime-local" id="published_at" name="published_at" value="<?= h((string) $data['published_at']) ?>">
          <div class="form-text">Set a date: future = Scheduled (goes live automatically), now or past = Published immediately. Blank + Published = now.</div>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="is_featured" name="is_featured" value="1" <?= (int) $data['is_featured'] === 1 ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_featured">Feature on the home page</label>
        </div>
        <div class="mb-3">
          <label class="form-label" for="category">Category</label>
          <select class="form-select" id="category" name="category">
            <?php foreach (news_categories() as $cat): ?>
              <option value="<?= h($cat) ?>" <?= $data['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="author_name">Byline</label>
          <input class="form-control" id="author_name" name="author_name" value="<?= h((string) $data['author_name']) ?>">
        </div>
        <div class="mb-0">
          <label class="form-label" for="fixture_pick_btn">Link to match (optional)</label>
          <?php
            $fxLabel = static fn(array $f): string => date('d/m/y', strtotime((string) $f['match_date'])) . ' · ' . ($f['is_home'] ? 'H' : 'A') . ' v ' . (string) $f['opponent']
                . (($f['competition'] ?? '') !== '' ? ' · ' . $f['competition'] : '');
            $fxList = array_map(static function (array $f) use ($fxLabel): array {
                $score = ($f['status'] === 'played' && $f['hs'] !== null && $f['aws'] !== null) ? ($f['hs'] . '–' . $f['aws']) : '';
                return ['id' => (int) $f['id'], 'label' => $fxLabel($f), 'date' => (string) $f['match_date'], 'ko' => substr((string) ($f['kickoff_time'] ?? ''), 0, 5), 'opp' => (string) $f['opponent'],
                    'home' => (int) $f['is_home'], 'comp' => (string) ($f['competition'] ?? ''), 'status' => (string) $f['status'], 'score' => $score];
            }, $fixtures);
            $fxCurrent = '';
            foreach ($fxList as $x) { if ($x['id'] === (int) $data['fixture_id']) { $fxCurrent = $x['label']; } }
          ?>
          <input type="hidden" name="fixture_id" id="fixture_id" value="<?= (int) $data['fixture_id'] ?: '' ?>">
          <div class="fx-card">
            <div id="fixture_current" class="fx-current"><?php
              $fxCur = null;
              foreach ($fxList as $x) { if ($x['id'] === (int) $data['fixture_id']) { $fxCur = $x; } }
              if ($fxCur): ?>
              <div class="fx-current__title"><span><?= h($fxCur['opp']) ?></span><span class="fx-current__venue">(<?= $fxCur['home'] ? 'Home' : 'Away' ?>)</span></div>
              <div class="fx-current__meta"><?= h(date('D j M Y', strtotime($fxCur['date']))) ?><?= $fxCur['ko'] !== '' ? ' @ ' . h($fxCur['ko']) : '' ?><?= $fxCur['comp'] !== '' ? ' - ' . h(preg_replace('/^West of Scotland Football League\s*/i', 'WOSFL - ', $fxCur['comp'])) : '' ?></div>
              <?php else: ?><span class="small text-muted">No match linked</span><?php endif; ?></div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <button type="button" class="btn btn-sm btn-link text-danger p-0 <?= $fxCur ? '' : 'invisible' ?>" id="fixture_clear">Remove</button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="fixture_pick_btn" data-bs-toggle="modal" data-bs-target="#fixtureModal">Select Match</button>
            </div>
          </div>
        </div>

        <style>
.fx-card{border:1px solid #dee2e6;border-radius:.5rem;padding:.75rem 1rem;background:#fff}
.fx-current{line-height:1.35}
.fx-current__title{display:flex;justify-content:space-between;gap:.75rem;font-weight:700;color:#4b0818}
.fx-current__venue{font-weight:600;white-space:nowrap}
.fx-current__meta{font-style:italic;font-size:.88rem;color:#6c665c}
</style>
        <div class="modal fade" id="fixtureModal" tabindex="-1" aria-labelledby="fixtureModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <h2 class="modal-title h5" id="fixtureModalLabel">Link to a match</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="fx_filter_toggle" aria-expanded="false" aria-controls="fx_filters">
                  <i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>Filter &amp; search
                </button>
                <div id="fx_filters" class="row g-2 mb-3" hidden>
                  <div class="col-md-6"><label class="form-label small mb-1" for="fx_q">Search</label><input type="search" class="form-control form-control-sm" id="fx_q" placeholder="Opponent, competition or date…" autocomplete="off"></div>
                  <div class="col-md-6"><label class="form-label small mb-1" for="fx_comp">Competition</label><select class="form-select form-select-sm" id="fx_comp"><option value="">All competitions</option></select></div>
                  <div class="col-md-4"><label class="form-label small mb-1" for="fx_venue">Venue</label><select class="form-select form-select-sm" id="fx_venue"><option value="">Home &amp; away</option><option value="1">Home</option><option value="0">Away</option></select></div>
                  <div class="col-md-4"><label class="form-label small mb-1" for="fx_status">Show</label><select class="form-select form-select-sm" id="fx_status"><option value="past">Played / past</option><option value="upcoming">Upcoming</option><option value="all">All</option></select></div>
                  <div class="col-md-4 d-flex align-items-end"><button type="button" class="btn btn-sm btn-link p-0" id="fx_reset">Reset filters</button></div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm table-hover align-middle mb-2">
                    <thead><tr><th scope="col">Date</th><th scope="col">Match</th><th scope="col">Competition</th><th scope="col">Score</th><th scope="col"><span class="visually-hidden">Select</span></th></tr></thead>
                    <tbody id="fx_rows"></tbody>
                  </table>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <span class="small text-muted" id="fx_count"></span>
                  <div class="btn-group btn-group-sm" role="group" aria-label="Pagination">
                    <button type="button" class="btn btn-outline-secondary" id="fx_prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Newer</button>
                    <button type="button" class="btn btn-outline-secondary" id="fx_next">Older <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <script>
        (function () {
          var fixtures = <?= json_encode($fxList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
          var PER = 5, page = 0;
          var today = new Date().toISOString().slice(0, 10);
          var hidden = document.getElementById('fixture_id');
          var modalEl = document.getElementById('fixtureModal');
          var rowsEl = document.getElementById('fx_rows');
          var q = document.getElementById('fx_q'), comp = document.getElementById('fx_comp'), venue = document.getElementById('fx_venue'), status = document.getElementById('fx_status');
          var toggle = document.getElementById('fx_filter_toggle'), panel = document.getElementById('fx_filters');
          var cur = document.getElementById('fixture_current'), clearBtn = document.getElementById('fixture_clear');
          document.body.appendChild(modalEl);

          var comps = [];
          fixtures.forEach(function (f) { if (f.comp && comps.indexOf(f.comp) === -1) comps.push(f.comp); });
          comps.sort().forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = c; comp.appendChild(o); });

          function fmt(d) { var p = d.split('-'); return p[2] + '/' + p[1] + '/' + p[0].slice(2); }
          function filtered() {
            var term = q.value.trim().toLowerCase();
            var list = fixtures.filter(function (f) {
              var past = f.date <= today;
              if (status.value === 'past' && !past) return false;
              if (status.value === 'upcoming' && past) return false;
              if (comp.value && f.comp !== comp.value) return false;
              if (venue.value !== '' && String(f.home) !== venue.value) return false;
              if (term && (f.label + ' ' + f.date).toLowerCase().indexOf(term) === -1) return false;
              return true;
            });
            list.sort(function (a, b) { return status.value === 'upcoming' ? (a.date < b.date ? -1 : 1) : (a.date < b.date ? 1 : -1); });
            return list;
          }
          function render() {
            var list = filtered(), pages = Math.max(1, Math.ceil(list.length / PER));
            if (page >= pages) page = pages - 1;
            var slice = list.slice(page * PER, page * PER + PER);
            rowsEl.innerHTML = '';
            if (!slice.length) {
              var tr = document.createElement('tr'), td = document.createElement('td');
              td.colSpan = 5; td.className = 'text-muted small'; td.textContent = 'No matches found.';
              tr.appendChild(td); rowsEl.appendChild(tr);
            }
            slice.forEach(function (f) {
              var tr = document.createElement('tr');
              if (String(f.id) === hidden.value) tr.className = 'table-active';
              function cell(t, cls) { var c = document.createElement('td'); c.textContent = t; if (cls) c.className = cls; tr.appendChild(c); return c; }
              cell(fmt(f.date), 'text-nowrap');
              cell((f.home ? 'H' : 'A') + ' v ' + f.opp);
              cell(f.comp || '—', 'small');
              cell(f.score || '—', 'text-nowrap');
              var act = document.createElement('td'); act.className = 'text-end';
              var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-primary'; b.textContent = 'Select';
              b.addEventListener('click', function () { choose(f); });
              act.appendChild(b); tr.appendChild(act); rowsEl.appendChild(tr);
            });
            document.getElementById('fx_count').textContent = list.length
              ? 'Showing ' + (page * PER + 1) + '–' + (page * PER + slice.length) + ' of ' + list.length + ' matches' : '';
            document.getElementById('fx_prev').disabled = page === 0;
            document.getElementById('fx_next').disabled = page >= pages - 1;
          }
          function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
          function showCurrent(f) {
            if (!f) { cur.innerHTML = '<span class="small text-muted">No match linked</span>'; return; }
            var d = new Date(f.date + 'T12:00:00');
            var ds = d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).replace(',', '');
            cur.innerHTML = '<div class="fx-current__title"><span>' + esc(f.opp) + '</span><span class="fx-current__venue">(' + (f.home ? 'Home' : 'Away') + ')</span></div>'
                + '<div class="fx-current__meta">' + esc(ds) + (f.ko ? ' @ ' + esc(f.ko) : '') + (f.comp ? ' - ' + esc(f.comp.replace(/^West of Scotland Football League\s*/i, 'WOSFL - ')) : '') + '</div>';
          }
          function choose(f) {
            hidden.value = f.id; showCurrent(f);
            clearBtn.classList.remove('invisible');
            bootstrap.Modal.getInstance(modalEl).hide();
          }
          clearBtn.addEventListener('click', function () {
            hidden.value = ''; showCurrent(null);
            clearBtn.classList.add('invisible');
          });
          toggle.addEventListener('click', function () {
            panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) q.focus();
          });
          [q, comp, venue, status].forEach(function (el) { el.addEventListener('input', function () { page = 0; render(); }); });
          document.getElementById('fx_reset').addEventListener('click', function () { q.value = ''; comp.value = ''; venue.value = ''; status.value = 'past'; page = 0; render(); });
          document.getElementById('fx_prev').addEventListener('click', function () { page--; render(); });
          document.getElementById('fx_next').addEventListener('click', function () { page++; render(); });
          modalEl.addEventListener('show.bs.modal', function () { page = 0; render(); });
        })();
        </script>
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">Hero image</h2>
        <p class="text-muted small">The big image at the top of the article and on cards. Pick from the Media Library or drop a new photo in.</p>

        <div id="hero-preview" class="mb-2 <?= $data['hero_image_path'] === '' ? 'd-none' : '' ?>">
          <div class="hf-pick d-none" id="hf-pick"><img src="<?= h(news_image_url((string) $data['hero_image_path'])) ?>" alt="" draggable="false"></div>
          <div class="small fw-semibold mt-2">Preview &mdash; how it will look</div>
          <div class="form-text mb-2">Drag inside any preview to move the photo for that one, and use its slider or the mouse wheel to zoom. Each preview is set separately.</div>
          <div class="hf-frames">
            <?php foreach ([['home', 'Home page (main)', '2.4/1'], ['card', 'News card', '16/9'], ['banner', 'Article banner', '5.6/1']] as [$hfK, $hfLabel, $hfRatio]): ?>
            <div>
              <div class="hf-cap d-flex align-items-center gap-2">
                <span class="me-auto"><?= h($hfLabel) ?></span>
                <input type="range" class="form-range hf-zoom" data-k="<?= $hfK ?>" min="1" max="3" step="0.05" style="width:7rem" aria-label="Zoom for <?= h($hfLabel) ?>">
                <span class="text-muted hf-zoom-val" data-k="<?= $hfK ?>" style="min-width:2.4rem;text-align:right"></span>
                <button type="button" class="btn btn-sm btn-link p-0 hf-reset" data-k="<?= $hfK ?>">Reset</button>
              </div>
              <div class="hf-frame" data-k="<?= $hfK ?>" style="aspect-ratio:<?= $hfRatio ?>" title="Drag to reposition"><img class="hf-img" alt="" draggable="false"></div>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="hero_framing" id="hero_framing" value="<?= h((string) ($data['hero_framing'] ?? '')) ?>">
          <input type="hidden" id="hf-legacy" value="<?= h((float) $data['hero_focus_x'] . ',' . (float) $data['hero_focus_y'] . ',' . (float) $data['hero_zoom']) ?>">
        </div>
        <style>
          .hf-pick{position:relative;cursor:crosshair;border-radius:8px;overflow:hidden;border:1px solid #d7d2c8;line-height:0;touch-action:none;user-select:none}
          .hf-pick img{width:100%;display:block}
          .hf-dot{position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 2px #7a1f3d,0 0 8px rgba(0,0,0,.5);pointer-events:none}
          .hf-frames{display:grid;gap:.6rem;margin-top:.35rem}
          .hf-frame.is-drag{outline:3px solid #7a1f3d;outline-offset:1px}
          .hf-cap{font-size:.72rem;color:#6b6f76;margin-bottom:2px}
          .hf-frame{cursor:grab;touch-action:none;user-select:none;position:relative;overflow:hidden;border-radius:6px;border:1px solid #d7d2c8;background:#222}
          .hf-frame img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
        </style>
        <input type="hidden" id="hero_image_path" name="hero_image_path" value="<?= h((string) $data['hero_image_path']) ?>">

        <div class="ng-hero-up d-none" data-ng-hero-up></div>

        <label class="ng-drop" data-ng-hero-drop role="button" tabindex="0">
          <input type="file" id="hero_file" accept="image/*" hidden>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5m0 0l-4 4m4-4l4 4"/><path d="M5 19h14"/></svg>
          <span><strong>Drag &amp; drop</strong> a hero image, or <u>browse</u></span>
        </label>

        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-ng-open-hero>Choose from Media Library</button>
          <button type="button" class="btn btn-sm btn-link text-danger p-0 <?= $data['hero_image_path'] === '' ? 'd-none' : '' ?>" data-ng-hero-clear>Remove</button>
        </div>
        <div class="ng-status" data-ng-hero-status hidden><span class="ng-status__spin" aria-hidden="true"></span><span data-ng-hero-status-text></span></div>

        <label class="form-label mt-2" for="hero_caption">Caption / credit</label>
        <input class="form-control form-control-sm" id="hero_caption" name="hero_caption" value="<?= h((string) $data['hero_caption']) ?>">
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">SEO overrides</h2>
        <label class="form-label" for="meta_title">Meta title</label>
        <input class="form-control form-control-sm mb-2" id="meta_title" name="meta_title" value="<?= h((string) $data['meta_title']) ?>">
        <label class="form-label" for="meta_description">Meta description</label>
        <textarea class="form-control form-control-sm" id="meta_description" name="meta_description" rows="2" maxlength="300"><?= h((string) $data['meta_description']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="col-12 d-flex justify-content-between">
    <?php if ($id > 0): ?>
      <button class="btn btn-outline-danger" name="form_action" value="delete" formnovalidate
              data-confirm="This permanently deletes the article." data-confirm-title="Delete this article?" data-confirm-action="Delete">Delete</button>
    <?php else: ?><span></span><?php endif; ?>
    <button class="btn btn-brand btn-lg" name="form_action" value="save" type="submit"><?= $id > 0 ? 'Save changes' : 'Create article' ?></button>
  </div>
</form>

<div class="modal fade" id="ngLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Media Library</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <input type="search" class="form-control form-control-sm" style="max-width:280px" placeholder="Search by name or folder…" data-ng-lib-search>
          <select class="form-select form-select-sm" style="max-width:240px" data-ng-lib-cat>
            <option value="">All categories</option>
          </select>
          <span class="small text-muted ms-auto align-self-center" data-ng-lib-count></span>
        </div>
        <div class="ng-lib-grid" data-ng-lib-grid>
          <p class="text-muted small m-0">Opening the library…</p>
        </div>
      </div>
      <div class="modal-footer">
        <span class="small text-muted me-auto" data-ng-lib-sel>Nothing selected</span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-brand" data-ng-lib-add disabled>Add selected</button>
      </div>
    </div>
  </div>
</div>

<?php if (($_GET['editor'] ?? '') === 'classic'): ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" referrerpolicy="origin"></script>
<script src="/admin/assets/js/news_blocks.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/news_blocks.js') ?: time()) ?>"></script>
<?php else:
  $gbDir = '/admin/assets/vendor/isolated-block-editor/';
  $gbFs = __DIR__ . '/assets/vendor/isolated-block-editor/';
  $gbV = (int) (@filemtime($gbFs . 'isolated-block-editor.js') ?: time()); ?>
<link rel="stylesheet" href="<?= $gbDir ?>base-layout.css?v=<?= $gbV ?>">
<link rel="stylesheet" href="<?= $gbDir ?>core.css?v=<?= $gbV ?>">
<link rel="stylesheet" href="<?= $gbDir ?>isolated-block-editor.css?v=<?= $gbV ?>">
<script crossorigin src="https://cdn.jsdelivr.net/npm/react@18.3.1/umd/react.production.min.js"></script>
<script crossorigin src="https://cdn.jsdelivr.net/npm/react-dom@18.3.1/umd/react-dom.production.min.js"></script>
<script src="<?= $gbDir ?>isolated-block-editor.js?v=<?= $gbV ?>"></script>
<script src="/admin/assets/js/media_picker.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/media_picker.js') ?: time()) ?>"></script>
<script src="/admin/assets/js/news_gutenberg.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/news_gutenberg.js') ?: time()) ?>"></script>
<?php endif; ?>
<script>
(function () {
  var csrf = document.querySelector('input[name="csrf_token"]').value;

  var esc = function (s) { return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); };

  function ngHttpErr(s) {
    if (s === 413) return 'That image is too large for the server.';
    if (s === 401 || s === 400 || s === 419) return 'Your session expired — reload the page and try again.';
    if (s === 0) return 'The upload was interrupted.';
    return 'Upload failed (error ' + s + ').';
  }

  /* Downscale big photos in the browser before upload — keeps uploads fast and
     under server limits. Returns the original file if no shrink is worthwhile. */
  function ngPrep(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpe?g|png|webp)$/i.test(file.type) || file.size < 1200000) { resolve(file); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var MAX = 2400, w = img.naturalWidth, h = img.naturalHeight;
        var scale = Math.min(1, MAX / Math.max(w || 1, h || 1));
        if (scale === 1 && file.size < 4000000) { resolve(file); return; }
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w * scale));
        c.height = Math.max(1, Math.round(h * scale));
        try { c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); } catch (e) { resolve(file); return; }
        var isPng = /png/i.test(file.type);
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          try { blob.name = (file.name || 'image').replace(/\.[^.]+$/, '') + (isPng ? '.png' : '.jpg'); } catch (e) {}
          resolve(blob);
        }, isPng ? 'image/png' : 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  /* XHR upload with real progress. cbs: {onProgress(pct), onDone(url,path), onError(msg)} */
  function ngUpload(file, cbs) {
    cbs = cbs || {};
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'news_image_upload.php', true);
    xhr.withCredentials = true;
    xhr.timeout = 180000;
    if (xhr.upload) {
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && cbs.onProgress) cbs.onProgress(Math.min(99, Math.round(e.loaded / e.total * 100)));
      };
    }
    xhr.onload = function () {
      var j = null; try { j = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status >= 200 && xhr.status < 300 && j && j.url) { cbs.onDone && cbs.onDone(j.url, j.path); }
      else { cbs.onError && cbs.onError((j && j.error) || ngHttpErr(xhr.status)); }
    };
    xhr.onerror = function () { cbs.onError && cbs.onError('Could not reach the server — check your connection and try again.'); };
    xhr.ontimeout = function () { cbs.onError && cbs.onError('The upload timed out — try a smaller image.'); };
    var fd = new FormData();
    fd.append('image', file, (file && file.name) || 'image.jpg');
    fd.append('csrf_token', csrf);
    xhr.send(fd);
  }

  /* Shape used by TinyMCE + simple callers. */
  function uploadImage(file, onSuccess, onError, onProgress) {
    ngPrep(file).then(function (f) {
      ngUpload(f, { onProgress: onProgress, onDone: onSuccess, onError: onError });
    });
  }

  /* status line: state = 'busy' | 'ok' | 'err' | falsy (hide) */
  function ngStatus(box, text, state) {
    if (!box) return;
    box.classList.remove('is-busy', 'is-ok', 'is-err');
    if (!state) { box.hidden = true; return; }
    box.hidden = false;
    box.classList.add('is-' + state);
    var t = box.querySelector('[data-ng-status-text], [data-ng-hero-status-text]');
    if (t) t.textContent = text || '';
  }

  if (window.tinymce) tinymce.init({
    selector: '#body',
    license_key: 'gpl',
    menubar: 'edit insert format table',
    menu: { insert: { title: 'Insert', items: 'addblock | image link table hr charmap nonbreaking' } },
    plugins: 'autolink autoresize lists advlist link image table code fullscreen wordcount visualblocks charmap searchreplace nonbreaking help',
    toolbar: [
      'addblock | undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | removeformat',
      'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote link image table hr | visualblocks code fullscreen'
    ],
    toolbar_mode: 'wrap',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Quote=blockquote; Preformatted=pre',
    extended_valid_elements: 'details[open|class],summary,div[class|style],a[href|target|rel|class|title],hr[class],pre[class],blockquote[class|style],p[class|style]',
    custom_elements: 'details,summary',
    color_map: [
      '6d2231', 'Club maroon', 'e0b42a', 'Club gold', '2e2c2d', 'Ink',
      '7c7178', 'Muted grey', 'ffffff', 'White',
      '1b8a4b', 'Green', 'b23b3b', 'Red', '2f6f9f', 'Blue'
    ],
    custom_colors: false,
    branding: false,
    promotion: false,
    resize: true,
    min_height: 480,
    autoresize_bottom_margin: 28,
    skin: 'oxide',
    content_css: 'default',
    content_style: [
      "body{font-family:Georgia,'Times New Roman',serif;font-size:17px;line-height:1.75;color:#2e2c2d;max-width:46rem;margin:1.6rem auto;padding:0 1.25rem;}",
      "h1,h2,h3,h4{font-family:-apple-system,'Segoe UI',Roboto,sans-serif;font-weight:800;line-height:1.2;margin:1.7rem 0 .6rem;}",
      "h1{font-size:1.9rem} h2{font-size:1.5rem} h3{font-size:1.25rem} h4{font-size:1.05rem}",
      "p{margin:0 0 1rem} a{color:#6d2231}",
      "img{max-width:100%;height:auto;border-radius:6px}",
      "blockquote{border-left:3px solid #6d2231;margin:1.2rem 0;padding:.25rem 0 .25rem 1.1rem;color:#5c5560;font-style:italic}",
      "table{border-collapse:collapse;width:100%;margin:1rem 0} td,th{border:1px solid #cfc8bb;padding:.45rem .7rem} th{background:#f4efe6}",
      "hr{border:0;border-top:2px solid #e7dccb;margin:1.6rem 0}"
    ].join('') + (window.NB_CONTENT_CSS || ''),
    automatic_uploads: true,
    images_upload_credentials: true,
    file_picker_types: 'image',
    images_upload_handler: function (blobInfo, progress) {
      return new Promise(function (resolve, reject) {
        uploadImage(blobInfo.blob(), resolve, function (msg) { reject({ message: msg, remove: true }); }, progress);
      });
    },
    setup: function (ed) {
      if (window.nbSetup) window.nbSetup(ed);
      // keep the underlying <textarea> in sync for normal form submits
      ed.on('change input undo redo', function () { ed.save(); });
    }
  });

  if (!window.tinymce && window.nbGutenberg) { window.nbGutenberg(document.getElementById('body'), uploadImage); }


  // Hero framing editor: every preview is its own editor (drag to reposition, slider/wheel to zoom)
  var hfPick = document.getElementById('hf-pick');
  var hfField = document.getElementById('hero_framing');
  var HF_KEYS = ['home', 'card', 'banner'];
  var hfState = {};
  (function () {
    var leg = (document.getElementById('hf-legacy').value || '50,50,1').split(',').map(parseFloat);
    var saved = {};
    try { saved = JSON.parse(hfField.value || '{}') || {}; } catch (e) {}
    HF_KEYS.forEach(function (k) {
      hfState[k] = saved[k] ? { x: +saved[k].x, y: +saved[k].y, z: +saved[k].z } : { x: leg[0] || 50, y: leg[1] || 50, z: leg[2] || 1 };
    });
  })();
  function hfSave() { hfField.value = JSON.stringify(hfState); }
  function hfRender() {
    var src = hfPick.querySelector('img').src;
    document.querySelectorAll('.hf-frame').forEach(function (fr) {
      var k = fr.getAttribute('data-k'), s2 = hfState[k], im = fr.querySelector('.hf-img');
      if (im.src !== src) im.src = src;
      im.style.objectPosition = s2.x + '% ' + s2.y + '%';
      im.style.transformOrigin = s2.x + '% ' + s2.y + '%';
      im.style.transform = 'scale(' + s2.z + ')';
      var sl = document.querySelector('.hf-zoom[data-k="' + k + '"]'), vl = document.querySelector('.hf-zoom-val[data-k="' + k + '"]');
      if (sl) sl.value = s2.z;
      if (vl) vl.textContent = s2.z.toFixed(2).replace(/\.?0+$/, '') + '×';
    });
  }
  function hfClamp(v) { return Math.round(Math.max(0, Math.min(100, v)) * 10) / 10; }
  document.querySelectorAll('.hf-frame').forEach(function (fr) {
    var k = fr.getAttribute('data-k'), im = fr.querySelector('.hf-img'), drag = null;
    function range(axis) {
      // How many pixels the photo can travel along this axis at the current zoom.
      var fw = fr.clientWidth, fh = fr.clientHeight, iw = im.naturalWidth || 16, ih = im.naturalHeight || 9;
      var sc = Math.max(fw / iw, fh / ih), z = hfState[k].z;
      return axis === 'x' ? (iw * sc - fw) * z + fw * (z - 1) : (ih * sc - fh) * z + fh * (z - 1);
    }
    fr.addEventListener('pointerdown', function (e) {
      drag = { px: e.clientX, py: e.clientY, x: hfState[k].x, y: hfState[k].y };
      fr.setPointerCapture(e.pointerId); fr.classList.add('is-drag'); fr.style.cursor = 'grabbing';
    });
    fr.addEventListener('pointermove', function (e) {
      if (!drag) return;
      var rx = range('x'), ry = range('y');
      if (rx > 1) hfState[k].x = hfClamp(drag.x - (e.clientX - drag.px) / rx * 100);
      if (ry > 1) hfState[k].y = hfClamp(drag.y - (e.clientY - drag.py) / ry * 100);
      hfSave(); hfRender();
    });
    function end() { drag = null; fr.classList.remove('is-drag'); fr.style.cursor = ''; }
    fr.addEventListener('pointerup', end);
    fr.addEventListener('pointercancel', end);
    fr.addEventListener('wheel', function (e) {
      e.preventDefault();
      hfState[k].z = Math.max(1, Math.min(3, Math.round((hfState[k].z - e.deltaY * 0.002) * 100) / 100));
      hfSave(); hfRender();
    }, { passive: false });
  });
  document.querySelectorAll('.hf-zoom').forEach(function (sl) {
    sl.addEventListener('input', function () { hfState[sl.getAttribute('data-k')].z = parseFloat(sl.value); hfSave(); hfRender(); });
  });
  document.querySelectorAll('.hf-reset').forEach(function (b) {
    b.addEventListener('click', function () { hfState[b.getAttribute('data-k')] = { x: 50, y: 50, z: 1 }; hfSave(); hfRender(); });
  });
  function hfResetAll() { HF_KEYS.forEach(function (k) { hfState[k] = { x: 50, y: 50, z: 1 }; }); hfSave(); hfRender(); }
  hfPick.querySelector('img').addEventListener('load', hfRender);
  hfRender();

  // Hero image — drag-and-drop / browse / Media Library
  var heroFile = document.getElementById('hero_file');
  var heroDrop = document.querySelector('[data-ng-hero-drop]');
  var heroClear = document.querySelector('[data-ng-hero-clear]');
  var heroStatusBox = document.querySelector('[data-ng-hero-status]');
  var heroUpBox = document.querySelector('[data-ng-hero-up]');
  var heroPath = document.getElementById('hero_image_path');
  var heroWrap = document.getElementById('hero-preview');

  function heroSay(m, state) { ngStatus(heroStatusBox, m, state || (m ? 'busy' : null)); }
  function setHero(url, path) {
    heroPath.value = path != null ? path : String(url).replace(/^\/uploads\/news\//, '');
    heroWrap.querySelector('.hf-pick img').src = url;
    hfResetAll();
    heroWrap.classList.remove('d-none');
    if (heroClear) heroClear.classList.remove('d-none');
  }
  function heroUpShow(name) {
    heroUpBox.className = 'ng-hero-up';
    heroUpBox.innerHTML =
      '<span class="ng-hero-up__name">' + esc(name) + '</span>' +
      '<div class="ng-hero-up__prog"><span class="ng-hero-up__bar"></span></div>' +
      '<span class="ng-hero-up__pct">Preparing…</span>';
    heroWrap.classList.add('d-none');
  }
  function heroUpHide() { heroUpBox.className = 'ng-hero-up d-none'; heroUpBox.innerHTML = ''; }
  function uploadHero(file) {
    if (!file || !/^image\//.test(file.type)) { heroSay('Choose an image file (JPG, PNG, WebP or GIF).', 'err'); return; }
    var name = file.name || 'image';
    heroUpShow(name);
    heroSay('Uploading “' + name + '”…', 'busy');
    ngPrep(file).then(function (f) {
      var pctEl = heroUpBox.querySelector('.ng-hero-up__pct');
      var barEl = heroUpBox.querySelector('.ng-hero-up__bar');
      if (pctEl) pctEl.textContent = '0%';
      ngUpload(f, {
        onProgress: function (p) { if (barEl) barEl.style.width = p + '%'; if (pctEl) pctEl.textContent = p + '%'; },
        onDone: function (url, path) { heroUpHide(); setHero(url, path); heroSay('Hero image set — save the article to keep it.', 'ok'); },
        onError: function (msg) { heroUpHide(); if (heroPath.value) heroWrap.classList.remove('d-none'); heroSay('“' + name + '” didn’t upload — ' + msg, 'err'); }
      });
    });
  }

  if (heroFile) {
    heroFile.addEventListener('change', function () {
      if (this.files && this.files[0]) uploadHero(this.files[0]);
      this.value = '';
    });
  }
  if (heroDrop && heroFile) {
    heroDrop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); heroFile.click(); }
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      heroDrop.addEventListener(ev, function (e) { e.preventDefault(); heroDrop.classList.add('is-over'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
      heroDrop.addEventListener(ev, function () { heroDrop.classList.remove('is-over'); });
    });
    heroDrop.addEventListener('drop', function (e) {
      e.preventDefault();
      var f = (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) || null;
      if (f) uploadHero(f);
    });
  }
  if (heroClear) {
    heroClear.addEventListener('click', function () {
      heroPath.value = '';
      heroWrap.classList.add('d-none');
      heroClear.classList.add('d-none');
      heroSay('Hero image removed — save to apply.', 'ok');
    });
  }

  // Auto-slug from title while creating
  var slug = document.getElementById('slug');
  var title = document.getElementById('title');
  if (slug && title && !slug.value) {
    title.addEventListener('blur', function () {
      if (slug.value) return;
      slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 180);
    });
  }

  /* ---- Gallery: drag-and-drop uploader + Media Library picker ---------- */
  var pending = document.querySelector('[data-ng-pending]');
  var galStatusBox = document.querySelector('[data-ng-status]');
  var drop = document.querySelector('[data-ng-drop]');
  var dropFile = document.querySelector('[data-ng-file]');
  var galSay = function (m, state) { ngStatus(galStatusBox, m, state || (m ? 'busy' : null)); };
  var galBusy = 0;

  function galRemovable(fig) {
    var x = fig.querySelector('.ng-tile__x');
    if (x) x.addEventListener('click', function () { fig.remove(); if (!pending.children.length) pending.hidden = true; });
  }

  // final "picked / uploaded" tile carrying the hidden field
  function addPendingTile(fieldName, value, url) {
    if (!pending) return;
    pending.hidden = false;
    var fig = document.createElement('figure');
    fig.className = 'ng-tile ng-tile--pending';
    fig.innerHTML = '<img src="' + esc(url) + '" alt=""><button type="button" class="ng-tile__x" title="Remove">&times;</button>';
    var hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = fieldName; hidden.value = value;
    fig.appendChild(hidden);
    galRemovable(fig);
    pending.appendChild(fig);
  }

  function galUploadingTile(name) {
    pending.hidden = false;
    var fig = document.createElement('figure');
    fig.className = 'ng-tile ng-tile--uploading is-indeterminate';
    fig.innerHTML =
      '<span class="ng-tile__name">' + esc(name) + '</span>' +
      '<div class="ng-tile__prog"><span class="ng-tile__bar"></span></div>' +
      '<span class="ng-tile__pct">…</span>';
    pending.appendChild(fig);
    return fig;
  }
  function galTileDone(fig, url, path) {
    fig.className = 'ng-tile ng-tile--pending';
    fig.innerHTML = '<img src="' + esc(url) + '" alt=""><button type="button" class="ng-tile__x" title="Remove">&times;</button>';
    var hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'gallery_new[]';
    hidden.value = path || String(url).replace(/^\/uploads\/news\//, '');
    fig.appendChild(hidden);
    galRemovable(fig);
  }
  function galTileError(fig, name, msg) {
    fig.className = 'ng-tile ng-tile--error';
    fig.innerHTML = '<span class="ng-tile__err">⚠ ' + esc(name) + ' — ' + esc(msg) + '</span>' +
                    '<button type="button" class="ng-tile__x" title="Dismiss">&times;</button>';
    galRemovable(fig);
  }

  function uploadDropped(file) {
    if (!/^image\//.test(file.type)) { galSay('“' + (file.name || 'that file') + '” isn’t an image.', 'err'); return; }
    var name = file.name || 'image';
    var fig = galUploadingTile(name);
    galBusy++;
    galSay('Uploading “' + name + '”…', 'busy');
    ngPrep(file).then(function (f) {
      var bar = fig.querySelector('.ng-tile__bar');
      var pct = fig.querySelector('.ng-tile__pct');
      pct.textContent = '0%';
      ngUpload(f, {
        onProgress: function (p) { fig.classList.remove('is-indeterminate'); bar.style.width = p + '%'; pct.textContent = p + '%'; },
        onDone: function (url, path) {
          galTileDone(fig, url, path);
          if (--galBusy <= 0) galSay('Uploaded — save the article to keep it.', 'ok');
        },
        onError: function (msg) {
          galTileError(fig, name, msg);
          galBusy--;
          galSay('“' + name + '” didn’t upload — ' + msg, 'err');
        }
      });
    });
  }

  if (drop && dropFile) {
    dropFile.addEventListener('change', function () {
      Array.prototype.forEach.call(this.files || [], uploadDropped);
      this.value = '';
    });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropFile.click(); }
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function () { drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      var files = (e.dataTransfer && e.dataTransfer.files) || [];
      Array.prototype.forEach.call(files, uploadDropped);
    });
  }

  var modalEl = document.getElementById('ngLibraryModal');
  if (modalEl) {
    var grid = modalEl.querySelector('[data-ng-lib-grid]');
    var lsearch = modalEl.querySelector('[data-ng-lib-search]');
    var lcat = modalEl.querySelector('[data-ng-lib-cat]');
    var lcount = modalEl.querySelector('[data-ng-lib-count]');
    var lsel = modalEl.querySelector('[data-ng-lib-sel]');
    var laddBtn = modalEl.querySelector('[data-ng-lib-add]');
    var catalogue = null;
    var selected = {};
    var libMode = 'gallery';   // 'gallery' (multi) | 'hero' (single)
    var CAP = 300;

    function refreshSel() {
      var n = Object.keys(selected).length;
      if (libMode === 'hero') {
        lsel.textContent = n ? '1 image chosen' : 'Choose an image';
        laddBtn.textContent = 'Use this image';
        laddBtn.disabled = n !== 1;
      } else {
        lsel.textContent = n ? (n + ' selected') : 'Nothing selected';
        laddBtn.textContent = 'Add selected';
        laddBtn.disabled = !n;
      }
    }

    function render() {
      if (!catalogue) return;
      var q = (lsearch.value || '').trim().toLowerCase();
      var cat = lcat.value;
      var matches = catalogue.filter(function (m) {
        if (cat && m.category !== cat) return false;
        return !q || (m.name + ' ' + m.path).toLowerCase().indexOf(q) !== -1;
      });
      lcount.textContent = matches.length + ' image' + (matches.length === 1 ? '' : 's') +
        (matches.length > CAP ? ' — showing first ' + CAP : '');
      grid.innerHTML = '';
      if (!matches.length) { grid.innerHTML = '<p class="text-muted small m-0">No images match.</p>'; return; }
      var frag = document.createDocumentFragment();
      matches.slice(0, CAP).forEach(function (m) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ng-lib-tile' + (selected[m.path] ? ' is-sel' : '');
        b.title = m.path;
        b.innerHTML = '<img loading="lazy" src="' + esc(m.url) + '" alt=""><span>' + esc(m.name) + '</span>';
        b.addEventListener('click', function () {
          if (libMode === 'hero') {
            var wasSel = !!selected[m.path];
            grid.querySelectorAll('.ng-lib-tile.is-sel').forEach(function (t) { t.classList.remove('is-sel'); });
            selected = {};
            if (!wasSel) { selected[m.path] = m; b.classList.add('is-sel'); }
          } else if (selected[m.path]) {
            delete selected[m.path]; b.classList.remove('is-sel');
          } else {
            selected[m.path] = m; b.classList.add('is-sel');
          }
          refreshSel();
        });
        frag.appendChild(b);
      });
      grid.appendChild(frag);
    }

    function openLib(mode) {
      libMode = mode;
      selected = {};
      refreshSel();
      if (catalogue) render();
      if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modalEl).show(); }
    }
    var og = document.querySelector('[data-ng-open-gallery]');
    var oh = document.querySelector('[data-ng-open-hero]');
    if (og) og.addEventListener('click', function () { openLib('gallery'); });
    if (oh) oh.addEventListener('click', function () { openLib('hero'); });

    function loadCatalogue() {
      if (catalogue) { render(); return; }
      grid.innerHTML = '<p class="text-muted small m-0">Loading the library…</p>';
      var fd = new FormData();
      fd.append('action', 'catalogue');
      fd.append('csrf_token', csrf);
      fetch('news_image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.message) || 'Could not load the library.');
          catalogue = j.items || [];
          var cats = {};
          catalogue.forEach(function (m) { cats[m.category] = 1; });
          Object.keys(cats).sort().forEach(function (c) {
            var o = document.createElement('option'); o.value = c; o.textContent = c; lcat.appendChild(o);
          });
          render();
        })
        .catch(function (e) { grid.innerHTML = '<p class="text-danger small m-0">' + esc(e.message) + '</p>'; });
    }

    modalEl.addEventListener('shown.bs.modal', loadCatalogue);
    // a11y: don't let focus stay inside the modal when Bootstrap hides it
    modalEl.addEventListener('hide.bs.modal', function () {
      if (modalEl.contains(document.activeElement)) { document.activeElement.blur(); }
    });
    lsearch.addEventListener('input', render);
    lcat.addEventListener('change', render);
    function closeLib() {
      if (modalEl.contains(document.activeElement)) { document.activeElement.blur(); }
      if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); }
    }

    laddBtn.addEventListener('click', function () {
      var keys = Object.keys(selected);
      if (!keys.length) return;

      if (libMode === 'hero') {
        var m = selected[keys[0]];
        heroSay('Using “' + m.name + '” from the library…', 'busy');
        laddBtn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'copy_from_library');
        fd.append('source', m.path);
        fd.append('csrf_token', csrf);
        fetch('news_image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j && j.url) { setHero(j.url, j.path); heroSay('Hero image set — save the article to keep it.', 'ok'); closeLib(); }
            else { heroSay((j && j.error) || 'Could not use that image.', 'err'); laddBtn.disabled = false; }
          })
          .catch(function () { heroSay('Could not use that image.', 'err'); laddBtn.disabled = false; });
        return;
      }

      keys.forEach(function (p) { addPendingTile('gallery_library[]', selected[p].path, selected[p].url); });
      galSay(keys.length + ' library image' + (keys.length === 1 ? '' : 's') + ' added — save the article to keep them.', 'ok');
      selected = {}; refreshSel(); render();
      closeLib();
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
