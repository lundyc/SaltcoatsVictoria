/* WordPress-style media modal for the Hub Media Library.
 *
 * - window.nbMediaPicker.open({...}) -> Promise<items|null>
 * - window.nbMediaUploadComponent    -> drop-in for Gutenberg's `editor.MediaUpload`
 *   (used by the Image, Gallery, Cover, Media & Text … blocks), so "Media Library"
 *   opens this modal exactly where WordPress would open its own.
 */
(function () {
  'use strict';

  var ENDPOINT = 'news_image_upload.php';
  var PAGE = 60;
  var catalogue = null, catPromise = null, byId = {}, byPath = {};

  /* ---------- helpers ---------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function csrf() { var i = document.querySelector('input[name="csrf_token"]'); return i ? i.value : ''; }
  function post(fd) {
    fd.append('csrf_token', csrf());
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || j.error) { throw new Error(j.error || j.message || 'Request failed (' + r.status + ')'); } return j; });
    });
  }
  function idFor(path) { var h = 5381; for (var i = 0; i < path.length; i++) { h = ((h << 5) + h + path.charCodeAt(i)) | 0; } return (h >>> 0) % 1999999999 + 1; }
  function register(it) { it.id = idFor(it.path); byId[it.id] = it; byPath[it.path] = it; return it; }
  function loadCatalogue(force) {
    if (catalogue && !force) { return Promise.resolve(catalogue); }
    if (catPromise && !force) { return catPromise; }
    var fd = new FormData(); fd.append('action', 'catalogue');
    catPromise = post(fd).then(function (j) {
      catalogue = (j.items || []).map(register); catPromise = null; return catalogue;
    }, function (e) { catPromise = null; throw e; });
    return catPromise;
  }
  function bytes(n) { if (n >= 1048576) { return (n / 1048576).toFixed(1) + ' MB'; } return Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function fdate(ts) { return ts ? new Date(ts * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : ''; }
  function thumb(it) { return /^\/uploads\//.test(it.url) ? '/img/400' + it.url : it.url; }

  /* Gutenberg attachment object (what WordPress hands to a block's onSelect). */
  function attachment(it) {
    var sub = (it.mime || 'image/jpeg').split('/')[1] || 'jpeg';
    return {
      id: it.id, url: it.url, link: it.url, alt: it.alt || '', caption: it.caption || '', title: it.title || it.name,
      mime: it.mime, type: 'image', subtype: sub, width: it.width, height: it.height, filename: it.filename,
      sizes: { full: { url: it.url, width: it.width, height: it.height, orientation: it.width >= it.height ? 'landscape' : 'portrait' } }
    };
  }
  function uploadFile(file, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest(), fd = new FormData();
      fd.append('action', 'library_upload'); fd.append('image', file, file.name || 'image.jpg'); fd.append('csrf_token', csrf());
      xhr.open('POST', ENDPOINT); xhr.withCredentials = true;
      if (xhr.upload && onProgress) { xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(Math.round(e.loaded / e.total * 100)); } }; }
      xhr.onload = function () {
        var j = null; try { j = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status >= 200 && xhr.status < 300 && j && j.item) { var it = register(j.item); if (catalogue) { catalogue.unshift(it); } resolve(it); }
        else { reject(new Error((j && (j.error || j.message)) || 'Upload failed (' + xhr.status + ')')); }
      };
      xhr.onerror = function () { reject(new Error('Could not reach the server.')); };
      xhr.send(fd);
    });
  }

  /* ---------- styles (WordPress media modal look, club colours) ---------- */
  var CSS = [
    '.nbm-overlay{position:fixed;inset:0;z-index:1000100;background:rgba(0,0,0,.7);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;font-size:13px;color:#1d2327}',
    '.nbm{position:absolute;inset:30px;background:#fff;box-shadow:0 5px 15px rgba(0,0,0,.7);display:flex;flex-direction:column;min-height:0}',
    '@media(max-width:900px){.nbm{inset:0}}',
    '.nbm-head{display:flex;align-items:center;justify-content:space-between;height:50px;flex:none;border-bottom:1px solid #dcdcde;padding:0 16px}',
    '.nbm-head h1{margin:0;font-size:22px;font-weight:600;line-height:50px}',
    '.nbm-x{width:50px;height:50px;margin-right:-16px;border:0;background:none;font-size:28px;line-height:1;cursor:pointer;color:#646970}.nbm-x:hover{color:#6d2231}',
    '.nbm-tabs{display:flex;gap:4px;padding:0 16px;border-bottom:1px solid #dcdcde;flex:none}',
    '.nbm-tab{padding:11px 14px;margin:0 0 -1px;border:1px solid transparent;border-bottom:0;background:none;font-size:14px;cursor:pointer;color:#1d2327}',
    '.nbm-tab:hover{color:#6d2231}.nbm-tab.is-on{background:#fff;border-color:#dcdcde;color:#1d2327;font-weight:600}',
    '.nbm-main{flex:1;min-height:0;display:flex;flex-direction:column}',
    '.nbm-pane{display:none;flex:1;min-height:0;flex-direction:column}.nbm-pane.is-on{display:flex}',
    '.nbm-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:12px 16px;flex:none}',
    '.nbm-toolbar select,.nbm-toolbar input[type=search]{height:32px;border:1px solid #8c8f94;border-radius:3px;padding:0 8px;font-size:13px;background:#fff;color:#1d2327;box-shadow:none;width:auto;max-width:none}',
    '.nbm-toolbar input[type=search]{width:220px;margin-left:auto}',
    '.nbm-count{color:#646970}',
    '.nbm-lib{flex:1;min-height:0;display:flex}',
    '.nbm-gridwrap{flex:1;min-width:0;overflow-y:auto;padding:2px 16px 16px}',
    '.nbm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}',
    '.nbm-tile{position:relative;aspect-ratio:1/1;background:#f0f0f1;cursor:pointer;overflow:hidden;padding:0;border:0;box-shadow:inset 0 0 0 1px rgba(0,0,0,.1)}',
    '.nbm-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#f6f7f7}',
    '.nbm-tile.is-sel{box-shadow:0 0 0 3px #fff,0 0 0 6px #6d2231;outline:0;z-index:1}',
    '.nbm-tile .nbm-chk{display:none;position:absolute;top:0;right:0;width:24px;height:24px;background:#6d2231;color:#fff;font-size:15px;line-height:24px;text-align:center;box-shadow:0 0 0 1px #fff}',
    '.nbm-tile.is-sel .nbm-chk{display:block}',
    '.nbm-tile:focus-visible{outline:2px solid #6d2231;outline-offset:2px}',
    '.nbm-empty{padding:40px 16px;color:#646970;text-align:center;grid-column:1/-1}',
    '.nbm-more{display:block;margin:16px auto 0;padding:6px 14px;border:1px solid #6d2231;border-radius:3px;background:#fff;color:#6d2231;cursor:pointer;font-size:13px}.nbm-more:hover{background:#faf5f6}',
    '.nbm-side{width:360px;flex:none;border-left:1px solid #dcdcde;background:#f6f7f7;overflow-y:auto;padding:16px}',
    '@media(max-width:900px){.nbm-side{display:none}}',
    '.nbm-side h2{margin:0 0 14px;font-size:14px;text-transform:uppercase;letter-spacing:.02em;color:#646970}',
    '.nbm-prev{display:flex;gap:12px;margin-bottom:12px}.nbm-prev img{width:120px;max-height:120px;object-fit:contain;background:#fff;border:1px solid #dcdcde}',
    '.nbm-meta{font-size:12px;color:#50575e;line-height:1.7;word-break:break-all}.nbm-meta strong{display:block;color:#1d2327;word-break:break-all}',
    '.nbm-field{display:block;margin-top:12px}.nbm-field span{display:block;margin-bottom:3px;color:#646970;font-size:12px}',
    '.nbm-field input,.nbm-field textarea{width:100%;border:1px solid #8c8f94;border-radius:3px;padding:6px 8px;font-size:13px;background:#fff;box-shadow:none;color:#1d2327;box-sizing:border-box}',
    '.nbm-field input:focus,.nbm-field textarea:focus{border-color:#6d2231;box-shadow:0 0 0 1px #6d2231;outline:0}',
    '.nbm-help{margin:3px 0 0;font-size:12px;color:#646970}.nbm-help a{color:#6d2231}',
    '.nbm-saved{height:16px;margin-top:6px;color:#1b8a4b;font-size:12px}',
    '.nbm-copy{margin-top:8px;padding:4px 10px;border:1px solid #6d2231;border-radius:3px;background:#fff;color:#6d2231;cursor:pointer;font-size:12px}',
    '.nbm-upload{align-items:center;justify-content:center;text-align:center;padding:24px}',
    '.nbm-drop{width:min(640px,100%);padding:60px 24px;border:4px dashed #b4b9be;border-radius:2px;background:#fff}',
    '.nbm-drop.is-over{border-color:#6d2231;background:#faf5f6}',
    '.nbm-drop h2{margin:0 0 14px;font-size:30px;font-weight:400;color:#3c434a}',
    '.nbm-drop p{margin:0 0 14px;color:#646970}',
    '.nbm-btn{display:inline-block;padding:0 12px;min-height:32px;line-height:30px;border:1px solid #6d2231;border-radius:3px;background:#fff;color:#6d2231;font-size:13px;cursor:pointer}',
    '.nbm-btn:hover{background:#faf5f6}.nbm-btn[disabled]{opacity:.5;cursor:default}',
    '.nbm-btn.is-primary{background:#6d2231;color:#fff}.nbm-btn.is-primary:hover:not([disabled]){background:#5e1d2a}',
    '.nbm-queue{width:min(640px,100%);margin:18px auto 0;text-align:left}',
    '.nbm-q{display:flex;align-items:center;gap:10px;padding:8px 10px;margin-bottom:6px;background:#fff;border:1px solid #dcdcde}',
    '.nbm-q b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}',
    '.nbm-bar{width:120px;height:6px;background:#dcdcde;border-radius:3px;overflow:hidden}.nbm-bar i{display:block;height:100%;width:0;background:#6d2231;transition:width .1s}',
    '.nbm-q.is-err{border-color:#b23b3b;color:#b23b3b}.nbm-q.is-ok .nbm-bar i{background:#1b8a4b}',
    '.nbm-foot{display:flex;align-items:center;justify-content:space-between;flex:none;min-height:60px;padding:0 16px;border-top:1px solid #dcdcde;background:#fff}',
    '.nbm-sel{display:flex;align-items:center;gap:12px;color:#1d2327;font-weight:600}',
    '.nbm-sel button{border:0;background:none;color:#6d2231;cursor:pointer;font-size:13px;text-decoration:underline;padding:0;font-weight:400}',
    '.nbm-err{color:#b23b3b;padding:0 16px 8px}'
  ].join('');
  function ensureCss() { if (!document.getElementById('nbm-css')) { var s = document.createElement('style'); s.id = 'nbm-css'; s.textContent = CSS; document.head.appendChild(s); } }

  /* ---------- the modal ---------- */
  function open(o) {
    o = o || {};
    ensureCss();
    return new Promise(function (resolve) {
      var multiple = !!o.multiple, tab = o.tab || 'library';
      var selected = [], active = null, shown = PAGE, items = [], q = '', cat = '', month = '';
      var prevFocus = document.activeElement;

      var ov = document.createElement('div');
      ov.className = 'nbm-overlay';
      ov.innerHTML =
        '<div class="nbm" role="dialog" aria-modal="true" aria-label="' + esc(o.title || 'Select or Upload Media') + '">' +
        '<div class="nbm-head"><h1>' + esc(o.title || 'Select or Upload Media') + '</h1><button type="button" class="nbm-x" aria-label="Close dialog">&times;</button></div>' +
        '<div class="nbm-tabs" role="tablist"><button type="button" class="nbm-tab" data-tab="upload">Upload files</button><button type="button" class="nbm-tab" data-tab="library">Media Library</button></div>' +
        '<div class="nbm-main">' +
          '<section class="nbm-pane nbm-upload" data-pane="upload"><div class="nbm-drop"><h2>Drop files to upload</h2><p>or</p><label class="nbm-btn">Select Files<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden></label><p style="margin-top:18px;font-size:12px">Maximum upload file size: 25 MB. JPG, PNG, WebP or GIF.</p></div><div class="nbm-queue"></div></section>' +
          '<section class="nbm-pane" data-pane="library">' +
            '<div class="nbm-toolbar"><select class="nbm-cat" aria-label="Filter by category"><option value="">All media items</option></select><select class="nbm-month" aria-label="Filter by date"><option value="">All dates</option></select><span class="nbm-count"></span><input type="search" class="nbm-q" placeholder="Search media items…" aria-label="Search media"></div>' +
            '<div class="nbm-lib"><div class="nbm-gridwrap"><div class="nbm-grid"></div></div><aside class="nbm-side" aria-label="Attachment details"></aside></div>' +
          '</section>' +
        '</div>' +
        '<div class="nbm-foot"><div class="nbm-sel"></div><button type="button" class="nbm-btn is-primary nbm-go" disabled>' + esc(o.button || 'Select') + '</button></div>' +
        '</div>';
      document.body.appendChild(ov);
      var $ = function (s) { return ov.querySelector(s); };
      var grid = $('.nbm-grid'), side = $('.nbm-side'), selEl = $('.nbm-sel'), goBtn = $('.nbm-go'), cntEl = $('.nbm-count');
      var prevOverflow = document.documentElement.style.overflow; document.documentElement.style.overflow = 'hidden';

      function close(result) {
        document.removeEventListener('keydown', onKey, true);
        document.documentElement.style.overflow = prevOverflow;
        ov.remove();
        try { prevFocus && prevFocus.focus && prevFocus.focus(); } catch (e) {}
        resolve(result);
      }
      function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); close(null); } }
      document.addEventListener('keydown', onKey, true);
      $('.nbm-x').addEventListener('click', function () { close(null); });
      ov.addEventListener('mousedown', function (e) { if (e.target === ov) { /* WordPress keeps the modal open on backdrop click */ } });

      function setTab(t) {
        tab = t;
        ov.querySelectorAll('.nbm-tab').forEach(function (b) { b.classList.toggle('is-on', b.dataset.tab === t); b.setAttribute('aria-selected', b.dataset.tab === t ? 'true' : 'false'); });
        ov.querySelectorAll('.nbm-pane').forEach(function (p) { p.classList.toggle('is-on', p.dataset.pane === t); });
        if (t === 'library') { setTimeout(function () { $('.nbm-q').focus(); }, 20); }
      }
      ov.querySelectorAll('.nbm-tab').forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });

      /* selection footer */
      function renderFoot() {
        var n = selected.length;
        selEl.innerHTML = n ? '<span>' + n + ' selected</span><button type="button">Clear</button>' : '';
        var c = selEl.querySelector('button'); if (c) { c.addEventListener('click', function () { selected = []; active = null; refreshTiles(); renderSide(); renderFoot(); }); }
        goBtn.disabled = !n;
      }
      function renderSide() {
        var it = active && byPath[active];
        if (!it) { side.innerHTML = '<h2>Attachment details</h2><p class="nbm-help">Select an item to see its details.</p>'; return; }
        side.innerHTML =
          '<h2>Attachment details</h2>' +
          '<div class="nbm-prev"><img src="' + esc(thumb(it)) + '" alt=""><div class="nbm-meta"><strong>' + esc(it.filename) + '</strong>' + esc(fdate(it.modified)) + '<br>' + esc(bytes(it.size)) + '<br>' + (it.width ? it.width + ' by ' + it.height + ' pixels' : '') + '</div></div>' +
          '<label class="nbm-field"><span>Alternative Text</span><textarea rows="2" data-f="alt">' + esc(it.alt) + '</textarea></label>' +
          '<p class="nbm-help"><a href="https://www.w3.org/WAI/tutorials/images/decision-tree/" target="_blank" rel="noopener">Learn how to describe the purpose of the image</a>. Leave empty if the image is purely decorative.</p>' +
          '<label class="nbm-field"><span>Title</span><input type="text" data-f="title" value="' + esc(it.title) + '"></label>' +
          '<label class="nbm-field"><span>Caption</span><textarea rows="2" data-f="caption">' + esc(it.caption) + '</textarea></label>' +
          '<label class="nbm-field"><span>Description</span><textarea rows="3" data-f="description">' + esc(it.description) + '</textarea></label>' +
          '<label class="nbm-field"><span>File URL</span><input type="text" readonly value="' + esc(location.origin + it.url) + '"></label>' +
          '<button type="button" class="nbm-copy">Copy URL to clipboard</button><div class="nbm-saved" aria-live="polite"></div>';
        var saved = side.querySelector('.nbm-saved'), timer = null;
        side.querySelectorAll('[data-f]').forEach(function (f) {
          f.addEventListener('change', function () {
            it[f.dataset.f] = f.value;
            var fd = new FormData(); fd.append('action', 'media_meta_save'); fd.append('path', it.path);
            ['alt', 'title', 'caption', 'description'].forEach(function (k) { fd.append(k, it[k] || ''); });
            saved.textContent = 'Saving…';
            post(fd).then(function () { saved.textContent = 'Saved.'; clearTimeout(timer); timer = setTimeout(function () { saved.textContent = ''; }, 1800); })
              .catch(function (e) { saved.style.color = '#b23b3b'; saved.textContent = e.message; });
          });
        });
        side.querySelector('.nbm-copy').addEventListener('click', function (e) {
          var url = location.origin + it.url, btn = e.currentTarget;
          (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { btn.textContent = 'Copied!'; }, function () { var i = side.querySelector('input[readonly]'); i.select(); document.execCommand('copy'); btn.textContent = 'Copied!'; });
        });
      }

      /* grid */
      function filtered() {
        var ql = q.toLowerCase();
        return (catalogue || []).filter(function (it) {
          if (cat && it.category !== cat) { return false; }
          if (month && new Date(it.modified * 1000).toISOString().slice(0, 7) !== month) { return false; }
          if (ql && (it.filename + ' ' + it.title + ' ' + it.alt + ' ' + it.caption + ' ' + it.category).toLowerCase().indexOf(ql) === -1) { return false; }
          return true;
        });
      }
      function refreshTiles() {
        grid.querySelectorAll('.nbm-tile').forEach(function (t) {
          var on = selected.indexOf(t.dataset.path) !== -1;
          t.classList.toggle('is-sel', on); t.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      }
      function toggle(path) {
        var i = selected.indexOf(path);
        if (multiple) {
          if (i === -1) { selected.push(path); active = path; } else { selected.splice(i, 1); active = selected[selected.length - 1] || null; }
        } else if (i === -1) { selected = [path]; active = path; } else { selected = []; active = null; }
        refreshTiles(); renderSide(); renderFoot();
      }
      function renderGrid() {
        items = filtered();
        cntEl.textContent = items.length + ' item' + (items.length === 1 ? '' : 's');
        var html = '';
        items.slice(0, shown).forEach(function (it) {
          html += '<button type="button" class="nbm-tile' + (selected.indexOf(it.path) !== -1 ? ' is-sel' : '') + '" data-path="' + esc(it.path) + '" aria-label="' + esc(it.title) + '" aria-pressed="false" title="' + esc(it.filename) + '"><img loading="lazy" src="' + esc(thumb(it)) + '" alt="" data-full="' + esc(it.url) + '"><span class="nbm-chk" aria-hidden="true">&#10003;</span></button>';
        });
        if (!items.length) { html = '<div class="nbm-empty">' + (catalogue && !catalogue.length ? 'No media items found. Use "Upload files" to add some.' : 'No media items found.') + '</div>'; }
        grid.innerHTML = html;
        if (items.length > shown) {
          var more = document.createElement('button'); more.type = 'button'; more.className = 'nbm-more'; more.textContent = 'Load more (' + (items.length - shown) + ' remaining)';
          more.addEventListener('click', function () { shown += PAGE; renderGrid(); });
          grid.parentNode.querySelector('.nbm-more') && grid.parentNode.querySelector('.nbm-more').remove();
          grid.parentNode.appendChild(more);
        } else { var m = grid.parentNode.querySelector('.nbm-more'); if (m) { m.remove(); } }
        grid.querySelectorAll('img').forEach(function (im) { im.addEventListener('error', function () { if (im.dataset.fb !== '1') { im.dataset.fb = '1'; im.src = im.dataset.full; } }); });
      }
      grid.addEventListener('click', function (e) { var t = e.target.closest('.nbm-tile'); if (t) { toggle(t.dataset.path); } });
      grid.addEventListener('dblclick', function (e) { var t = e.target.closest('.nbm-tile'); if (t && !multiple) { selected = [t.dataset.path]; finish(); } });

      function buildFilters() {
        var cats = {}, months = {};
        catalogue.forEach(function (it) { cats[it.category] = 1; months[new Date(it.modified * 1000).toISOString().slice(0, 7)] = 1; });
        $('.nbm-cat').innerHTML = '<option value="">All media items</option>' + Object.keys(cats).sort().map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('');
        $('.nbm-month').innerHTML = '<option value="">All dates</option>' + Object.keys(months).sort().reverse().map(function (m) { var d = new Date(m + '-01T12:00:00'); return '<option value="' + m + '">' + d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) + '</option>'; }).join('');
      }
      $('.nbm-cat').addEventListener('change', function () { cat = this.value; shown = PAGE; renderGrid(); });
      $('.nbm-month').addEventListener('change', function () { month = this.value; shown = PAGE; renderGrid(); });
      var qt = null; $('.nbm-q').addEventListener('input', function () { var v = this.value; clearTimeout(qt); qt = setTimeout(function () { q = v.trim(); shown = PAGE; renderGrid(); }, 150); });

      function finish() {
        var out = selected.map(function (p) { return byPath[p]; }).filter(Boolean);
        close(out.length ? out : null);
      }
      goBtn.addEventListener('click', finish);

      /* upload tab */
      var drop = $('.nbm-drop'), fileIn = ov.querySelector('input[type=file]'), queue = $('.nbm-queue');
      function handleFiles(files) {
        files = Array.prototype.slice.call(files).filter(function (f) { return /^image\/(jpeg|png|webp|gif)$/.test(f.type); });
        if (!files.length) { queue.innerHTML = '<div class="nbm-q is-err"><b>Choose JPG, PNG, WebP or GIF images.</b></div>'; return; }
        queue.innerHTML = '';
        var uploaded = [];
        var chain = Promise.resolve();
        files.forEach(function (f) {
          var row = document.createElement('div'); row.className = 'nbm-q'; row.innerHTML = '<b>' + esc(f.name) + '</b><span class="nbm-bar"><i></i></span><span class="nbm-st">0%</span>'; queue.appendChild(row);
          chain = chain.then(function () {
            return uploadFile(f, function (p) { row.querySelector('i').style.width = p + '%'; row.querySelector('.nbm-st').textContent = p + '%'; })
              .then(function (it) { row.classList.add('is-ok'); row.querySelector('i').style.width = '100%'; row.querySelector('.nbm-st').textContent = 'Done'; uploaded.push(it); },
                    function (err) { row.classList.add('is-err'); row.querySelector('.nbm-st').textContent = err.message; });
          });
        });
        chain.then(function () {
          if (!uploaded.length) { return; }
          buildFilters();
          selected = multiple ? uploaded.map(function (u) { return u.path; }) : [uploaded[0].path];
          active = selected[selected.length - 1];
          cat = ''; month = ''; q = ''; shown = PAGE; $('.nbm-q').value = '';
          setTab('library'); renderGrid(); renderSide(); renderFoot();
        });
      }
      fileIn.addEventListener('change', function () { handleFiles(this.files); this.value = ''; });
      ['dragenter', 'dragover'].forEach(function (ev) { ov.addEventListener(ev, function (e) { if (tab === 'upload' || (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1)) { e.preventDefault(); if (tab !== 'upload') { setTab('upload'); } drop.classList.add('is-over'); } }); });
      ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
      ov.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('is-over'); if (e.dataTransfer && e.dataTransfer.files.length) { handleFiles(e.dataTransfer.files); } });

      /* go */
      setTab(tab); renderSide(); renderFoot();
      grid.innerHTML = '<div class="nbm-empty">Loading the media library…</div>';
      if (o.allowedTypes && o.allowedTypes.length && !o.allowedTypes.some(function (t) { return t === 'image' || t === 'image/*' || /^image\//.test(t); })) {
        grid.innerHTML = '<div class="nbm-empty">This block needs a file type the media library does not hold.</div>'; return;
      }
      loadCatalogue().then(function () {
        buildFilters();
        (o.selectedIds || []).forEach(function (id) { var it = byId[id]; if (it && selected.indexOf(it.path) === -1 && (multiple || !selected.length)) { selected.push(it.path); } });
        active = selected[selected.length - 1] || null;
        renderGrid(); renderSide(); renderFoot();
      }).catch(function (e) { grid.innerHTML = '<div class="nbm-empty" style="color:#b23b3b">' + esc(e.message) + '</div>'; });
    });
  }

  /* ---------- Gutenberg `editor.MediaUpload` replacement ---------- */
  function NBMediaUpload(props) {
    var React = window.React;
    function openModal() {
      var gallery = !!props.gallery, multiple = !!props.multiple || gallery;
      var ids = props.value == null ? [] : (Array.isArray(props.value) ? props.value : [props.value]);
      ids = ids.map(function (v) { return typeof v === 'object' && v ? v.id : v; }).filter(Boolean);
      var title = props.title || (gallery ? (props.addToGallery ? 'Add to gallery' : (ids.length ? 'Edit gallery' : 'Create gallery')) : 'Select or Upload Media');
      var button = gallery ? (props.addToGallery ? 'Add to gallery' : (ids.length ? 'Update gallery' : 'Insert gallery')) : 'Select';
      loadCatalogue().catch(function () {}).then(function () {
        return open({ title: title, button: button, multiple: multiple, allowedTypes: props.allowedTypes, selectedIds: ids, tab: props.mode === 'upload' ? 'upload' : 'library' });
      }).then(function (picked) {
        if (picked && picked.length) {
          var atts = picked.map(attachment);
          props.onSelect(multiple ? atts : atts[0]);
        }
        if (props.onClose) { props.onClose(); }
      });
    }
    return props.render ? props.render({ open: openModal }) : React.createElement(React.Fragment, null);
  }

  window.nbMediaPicker = { open: open, uploadFile: uploadFile, attachment: attachment, load: loadCatalogue };
  window.nbMediaUploadComponent = NBMediaUpload;
})();
