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
    "SELECT f.id, f.match_date, f.opponent, f.is_home, f.competition
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
          <label class="form-label" for="fixture_id">Link to match (optional)</label>
          <?php
            $fxLabel = static fn(array $f): string => date('d/m/y', strtotime((string) $f['match_date'])) . ' · ' . ($f['is_home'] ? 'H' : 'A') . ' v ' . (string) $f['opponent']
                . (($f['competition'] ?? '') !== '' ? ' · ' . $f['competition'] : '');
            $fxList = array_map(static fn(array $f): array => ['id' => (int) $f['id'], 'label' => $fxLabel($f)], $fixtures);
            $fxCurrent = '';
            foreach ($fxList as $x) { if ($x['id'] === (int) $data['fixture_id']) { $fxCurrent = $x['label']; } }
          ?>
          <div class="position-relative" id="fixture_combo">
            <input type="hidden" name="fixture_id" id="fixture_id" value="<?= (int) $data['fixture_id'] ?: '' ?>">
            <input type="text" class="form-control" id="fixture_search" autocomplete="off"
                   placeholder="Type an opponent, date or competition…" value="<?= h($fxCurrent) ?>">
            <div class="list-group position-absolute w-100 shadow d-none" id="fixture_results"
                 style="z-index:1050;max-height:260px;overflow-y:auto"></div>
          </div>
          <div class="form-text">Start typing to search; clear the box to remove the link.</div>
          <script>
          (function () {
            var fixtures = <?= json_encode($fxList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            var input = document.getElementById('fixture_search');
            var hidden = document.getElementById('fixture_id');
            var box = document.getElementById('fixture_results');
            function render() {
              var q = input.value.trim().toLowerCase();
              var hits = fixtures.filter(function (f) { return !q || f.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 30);
              box.innerHTML = '';
              if (!hits.length) {
                var n = document.createElement('div');
                n.className = 'list-group-item text-muted small';
                n.textContent = 'No matches found';
                box.appendChild(n);
              }
              hits.forEach(function (f) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action small';
                b.textContent = f.label;
                b.addEventListener('mousedown', function (e) {
                  e.preventDefault();
                  hidden.value = f.id; input.value = f.label; box.classList.add('d-none');
                });
                box.appendChild(b);
              });
              box.classList.remove('d-none');
            }
            input.addEventListener('input', function () { hidden.value = ''; render(); });
            input.addEventListener('focus', render);
            input.addEventListener('blur', function () {
              box.classList.add('d-none');
              if (hidden.value === '' && input.value.trim() !== '') { input.value = ''; }
            });
          })();
          </script>
        </div>
      </div>
    </div>

    <div class="card hub-section mt-4">
      <div class="card-body">
        <h2 class="h6">Hero image</h2>
        <p class="text-muted small">The big image at the top of the article and on cards. Pick from the Media Library or drop a new photo in.</p>

        <div id="hero-preview" class="mb-2 <?= $data['hero_image_path'] === '' ? 'd-none' : '' ?>">
          <div class="hf-pick" id="hf-pick" title="Click or drag to choose the focal point">
            <img src="<?= h(news_image_url((string) $data['hero_image_path'])) ?>" alt="" draggable="false">
            <span class="hf-dot" id="hf-dot"></span>
          </div>
          <div class="form-text mb-2">Pick a preview below, then click or drag on the photo to choose what stays in view for that one.</div>
          <label class="form-label small mb-0 mt-2" for="hf-zoom">Zoom for <b id="hf-which"></b> <span id="hf-zoom-val" class="text-muted"></span></label>
          <input type="range" class="form-range" id="hf-zoom" min="1" max="3" step="0.05">
          <div class="small fw-semibold mt-2">Preview — how it will look</div>
          <div class="hf-frames">
            <div><div class="hf-cap">Home page (main)</div><div class="hf-frame" data-k="home" style="aspect-ratio:2.4/1"><img class="hf-img" alt=""></div></div>
            <div><div class="hf-cap">News card</div><div class="hf-frame" data-k="card" style="aspect-ratio:16/9"><img class="hf-img" alt=""></div></div>
            <div><div class="hf-cap">Article banner</div><div class="hf-frame" data-k="banner" style="aspect-ratio:5.6/1"><img class="hf-img" alt=""></div></div>
          </div>
          <button type="button" class="btn btn-sm btn-link p-0 mt-1" id="hf-reset">Reset this one</button>
          <input type="hidden" name="hero_framing" id="hero_framing" value="<?= h((string) ($data['hero_framing'] ?? '')) ?>">
          <input type="hidden" id="hf-legacy" value="<?= h((float) $data['hero_focus_x'] . ',' . (float) $data['hero_focus_y'] . ',' . (float) $data['hero_zoom']) ?>">
        </div>
        <style>
          .hf-pick{position:relative;cursor:crosshair;border-radius:8px;overflow:hidden;border:1px solid #d7d2c8;line-height:0;touch-action:none;user-select:none}
          .hf-pick img{width:100%;display:block}
          .hf-dot{position:absolute;width:26px;height:26px;margin:-13px 0 0 -13px;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 2px #7a1f3d,0 0 8px rgba(0,0,0,.5);pointer-events:none}
          .hf-frames{display:grid;gap:.6rem;margin-top:.35rem}
          .hf-frame.is-active{outline:3px solid #7a1f3d;outline-offset:1px}
          .hf-cap{font-size:.72rem;color:#6b6f76;margin-bottom:2px}
          .hf-frame{cursor:pointer;position:relative;overflow:hidden;border-radius:6px;border:1px solid #d7d2c8;background:#222}
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

<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" referrerpolicy="origin"></script>
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

  tinymce.init({
    selector: '#body',
    license_key: 'gpl',
    menubar: 'edit format table',
    plugins: 'autolink autoresize lists advlist link image table code fullscreen wordcount visualblocks charmap searchreplace nonbreaking help',
    toolbar: [
      'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | removeformat',
      'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote link image table hr | visualblocks code fullscreen'
    ],
    toolbar_mode: 'wrap',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Quote=blockquote',
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
    ].join(''),
    automatic_uploads: true,
    images_upload_credentials: true,
    file_picker_types: 'image',
    images_upload_handler: function (blobInfo, progress) {
      return new Promise(function (resolve, reject) {
        uploadImage(blobInfo.blob(), resolve, function (msg) { reject({ message: msg, remove: true }); }, progress);
      });
    },
    setup: function (ed) {
      // keep the underlying <textarea> in sync for normal form submits
      ed.on('change input undo redo', function () { ed.save(); });
    }
  });


  // Hero framing editor (per-placement focal point + zoom, live previews)
  var hfPick = document.getElementById('hf-pick'), hfDot = document.getElementById('hf-dot');
  var hfField = document.getElementById('hero_framing');
  var hfZoom = document.getElementById('hf-zoom'), hfZoomVal = document.getElementById('hf-zoom-val'), hfWhich = document.getElementById('hf-which');
  var HF_NAMES = { home: 'Home page', card: 'News card', banner: 'Article banner' };
  var hfState = {}, hfActive = 'banner';
  (function () {
    var leg = (document.getElementById('hf-legacy').value || '50,50,1').split(',').map(parseFloat);
    var saved = {};
    try { saved = JSON.parse(hfField.value || '{}') || {}; } catch (e) {}
    ['home', 'card', 'banner'].forEach(function (k) {
      hfState[k] = saved[k] ? { x: +saved[k].x, y: +saved[k].y, z: +saved[k].z } : { x: leg[0] || 50, y: leg[1] || 50, z: leg[2] || 1 };
    });
  })();
  function hfSave() { hfField.value = JSON.stringify(hfState); }
  function hfRender() {
    var st = hfState[hfActive];
    hfDot.style.left = st.x + '%'; hfDot.style.top = st.y + '%';
    hfZoom.value = st.z; hfZoomVal.textContent = st.z.toFixed(2).replace(/\.?0+$/, '') + '×';
    hfWhich.textContent = HF_NAMES[hfActive];
    var src = hfPick.querySelector('img').src;
    document.querySelectorAll('.hf-frame').forEach(function (fr) {
      var k = fr.getAttribute('data-k'), s2 = hfState[k], im = fr.querySelector('.hf-img');
      fr.classList.toggle('is-active', k === hfActive);
      if (im.src !== src) im.src = src;
      im.style.objectPosition = s2.x + '% ' + s2.y + '%';
      im.style.transformOrigin = s2.x + '% ' + s2.y + '%';
      im.style.transform = 'scale(' + s2.z + ')';
    });
  }
  function hfSet(e) {
    var r = hfPick.getBoundingClientRect(), st = hfState[hfActive];
    st.x = Math.round(Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)) * 10) / 10;
    st.y = Math.round(Math.max(0, Math.min(100, (e.clientY - r.top) / r.height * 100)) * 10) / 10;
    hfSave(); hfRender();
  }
  var hfDrag = false;
  hfPick.addEventListener('pointerdown', function (e) { hfDrag = true; hfPick.setPointerCapture(e.pointerId); hfSet(e); });
  hfPick.addEventListener('pointermove', function (e) { if (hfDrag) hfSet(e); });
  hfPick.addEventListener('pointerup', function () { hfDrag = false; });
  hfZoom.addEventListener('input', function () { hfState[hfActive].z = parseFloat(hfZoom.value); hfSave(); hfRender(); });
  document.querySelectorAll('.hf-frame').forEach(function (fr) {
    fr.addEventListener('click', function () { hfActive = fr.getAttribute('data-k'); hfRender(); });
  });
  function hfResetAll() { ['home', 'card', 'banner'].forEach(function (k) { hfState[k] = { x: 50, y: 50, z: 1 }; }); hfSave(); hfRender(); }
  document.getElementById('hf-reset').addEventListener('click', function () { hfState[hfActive] = { x: 50, y: 50, z: 1 }; hfSave(); hfRender(); });
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
