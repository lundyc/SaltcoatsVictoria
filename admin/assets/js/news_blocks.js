/* News editor — WordPress-style block inserter for TinyMCE.
 * Adds an "Add block" drawer (blocks + patterns, searchable), a "/" quick
 * inserter, and the CSS the blocks need inside the editor. */
(function () {
  'use strict';

  var CONTENT_CSS = [
    "details{margin:1.2rem 0;border:1px solid #cfc8bb;border-radius:6px;background:#faf7f1}",
    "details>summary{cursor:pointer;padding:.7rem 1rem;font-weight:700}",
    "details>:not(summary){margin-left:1rem;margin-right:1rem}",
    ".nb-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1.2rem;margin:1.2rem 0}",
    ".nb-col{border:1px dashed #cfc8bb;padding:.6rem .8rem;border-radius:4px}",
    ".nb-buttons{margin:1.2rem 0}",
    "a.nb-button{display:inline-block;padding:.7rem 1.5rem;border-radius:999px;background:#6d2231;color:#fff;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;font-weight:700;text-decoration:none}",
    ".nb-buttons--gold a.nb-button{background:#e0b42a;color:#2e2c2d}",
    ".nb-callout{margin:1.2rem 0;padding:.9rem 1.2rem;border-left:5px solid #e0b42a;background:#faf7f1;border-radius:0 6px 6px 0}",
    ".nb-callout--alert{border-left-color:#b23b3b}",
    "blockquote.nb-pullquote{text-align:center;font-size:1.3rem;font-weight:700;font-style:normal;border:0;border-top:4px solid #e0b42a;border-bottom:4px solid #e0b42a;padding:1rem}",
    "pre{background:#f4efe6;border:1px solid #e7dccb;border-radius:6px;padding:.8rem 1rem;white-space:pre-wrap;font-size:.92rem;line-height:1.6}",
    "pre.nb-verse{font-family:inherit;font-style:italic;background:none;border:0;border-left:4px solid #e0b42a;border-radius:0}",
    "hr.nb-sep--dots{border:0;text-align:center}hr.nb-sep--dots::before{content:'\\2022 \\2022 \\2022';letter-spacing:.6em;color:#e0b42a;font-size:1.3rem}"
  ].join('');

  var UI_CSS = [
    ".nb-drawer{position:fixed;top:0;bottom:0;left:0;width:min(380px,100vw);z-index:1090;background:#fff;border-right:1px solid #dcdcde;box-shadow:6px 0 24px rgba(0,0,0,.14);display:none;flex-direction:column;font-family:-apple-system,'Segoe UI',Roboto,sans-serif}",
    ".nb-drawer.is-open{display:flex}",
    ".nb-head{display:flex;align-items:center;border-bottom:1px solid #dcdcde}",
    ".nb-tab{flex:0 0 auto;padding:1rem 1.1rem;background:none;border:0;border-bottom:3px solid transparent;font-size:.95rem;color:#1e1e1e;cursor:pointer}",
    ".nb-tab.is-on{border-bottom-color:#6d2231;font-weight:600}",
    ".nb-close{margin-left:auto;margin-right:.6rem;border:0;background:none;font-size:1.6rem;line-height:1;cursor:pointer;padding:.3rem .6rem}",
    ".nb-search{margin:1rem 1.1rem .4rem;padding:.65rem .8rem;border:0;border-radius:4px;background:#f0f0f1;font-size:.95rem;width:calc(100% - 2.2rem)}",
    ".nb-body{overflow-y:auto;padding:0 1.1rem 2rem;flex:1}",
    ".nb-group{margin:1.2rem 0 .3rem;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#757575}",
    ".nb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem}",
    ".nb-item{display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:.9rem .3rem;background:none;border:1px solid transparent;border-radius:4px;font-size:.8rem;color:#1e1e1e;cursor:pointer;text-align:center}",
    ".nb-item:hover,.nb-item:focus{border-color:#6d2231;background:#faf5f6;outline:0}",
    ".nb-ico{display:flex;align-items:center;justify-content:center;height:28px;font-size:1.25rem;font-weight:700;color:#1e1e1e}",
    ".nb-ico svg{width:26px;height:26px;fill:currentColor}",
    ".nb-pattern{display:block;width:100%;margin:.5rem 0;padding:.8rem 1rem;text-align:left;background:#fff;border:1px solid #dcdcde;border-radius:6px;cursor:pointer;font-size:.9rem}",
    ".nb-pattern:hover,.nb-pattern:focus{border-color:#6d2231;outline:0}",
    ".nb-pattern small{display:block;color:#757575;margin-top:.2rem}",
    ".nb-empty{color:#757575;font-size:.9rem;margin-top:1.4rem}"
  ].join('');

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  window.NB_CONTENT_CSS = CONTENT_CSS;

  window.nbSetup = function (ed) {
    var icons = (ed.ui.registry.getAll().icons) || {};
    function icon(name, glyph) { return icons[name] ? icons[name] : esc(glyph); }

    var BLOCKS = [
      // Text
      { id: 'paragraph', g: 'Text', l: 'Paragraph', i: ['paragraph', '¶'], html: '<p>{$caret}</p>' },
      { id: 'h2', g: 'Text', l: 'Heading', i: [null, 'H2'], html: '<h2>{$caret}</h2>' },
      { id: 'h3', g: 'Text', l: 'Subheading', i: [null, 'H3'], html: '<h3>{$caret}</h3>' },
      { id: 'ul', g: 'Text', l: 'List', i: ['unordered-list', '•'], html: '<ul><li>{$caret}</li></ul>' },
      { id: 'ol', g: 'Text', l: 'Numbered list', i: ['ordered-list', '1.'], html: '<ol><li>{$caret}</li></ol>' },
      { id: 'quote', g: 'Text', l: 'Quote', i: ['quote', '“'], html: '<blockquote><p>{$caret}</p></blockquote>' },
      { id: 'pullquote', g: 'Text', l: 'Pullquote', i: [null, '❝'], html: '<blockquote class="nb-pullquote"><p>{$caret}</p></blockquote>' },
      { id: 'code', g: 'Text', l: 'Code', i: ['sourcecode', '</>'], html: '<pre><code>{$caret}</code></pre>' },
      { id: 'pre', g: 'Text', l: 'Preformatted', i: [null, '▤'], html: '<pre>{$caret}</pre>' },
      { id: 'verse', g: 'Text', l: 'Verse', i: [null, '✒'], html: '<pre class="nb-verse">{$caret}</pre>' },
      { id: 'details', g: 'Text', l: 'Details', i: ['accordion', '▸'], html: '<details open><summary>Question or heading</summary><p>{$caret}Answer or hidden content.</p></details>' },
      { id: 'table', g: 'Text', l: 'Table', i: ['table', '▦'], run: function () { ed.execCommand('mceInsertTable', false, { rows: 3, columns: 3, options: { headerRows: 1 } }); } },
      // Design
      { id: 'button', g: 'Design', l: 'Button', i: ['link', '▭'], run: function () { buttonDialog(); } },
      { id: 'cols2', g: 'Design', l: '2 columns', i: [null, '◫'], html: '<div class="nb-columns"><div class="nb-col"><p>{$caret}</p></div><div class="nb-col"><p>&nbsp;</p></div></div><p>&nbsp;</p>' },
      { id: 'cols3', g: 'Design', l: '3 columns', i: [null, '▥'], html: '<div class="nb-columns"><div class="nb-col"><p>{$caret}</p></div><div class="nb-col"><p>&nbsp;</p></div><div class="nb-col"><p>&nbsp;</p></div></div><p>&nbsp;</p>' },
      { id: 'callout', g: 'Design', l: 'Callout', i: ['info', 'ℹ'], html: '<div class="nb-callout"><p>{$caret}</p></div><p>&nbsp;</p>' },
      { id: 'alert', g: 'Design', l: 'Alert', i: ['warning', '!'], html: '<div class="nb-callout nb-callout--alert"><p><strong>Important:</strong> {$caret}</p></div><p>&nbsp;</p>' },
      { id: 'sep', g: 'Design', l: 'Separator', i: ['horizontal-rule', '—'], html: '<hr>' },
      { id: 'dots', g: 'Design', l: 'Dots', i: [null, '•••'], html: '<hr class="nb-sep--dots">' },
      // Media
      { id: 'image', g: 'Media', l: 'Image', i: ['image', '▣'], run: function () { ed.execCommand('mceImage'); } },
      { id: 'link', g: 'Media', l: 'Link', i: ['link', '🔗'], run: function () { ed.execCommand('mceLink'); } }
    ];

    var PATTERNS = [
      { l: 'Call to action', d: 'Callout with a heading and a button', html: '<div class="nb-callout"><h3>Get involved</h3><p>Tell people what you would like them to do.</p><p class="nb-buttons"><a class="nb-button" href="https://">Find out more</a></p></div><p>&nbsp;</p>' },
      { l: 'Match details', d: 'Table with fixture, date, venue and kick-off', html: '<table><tbody><tr><th>Fixture</th><td>Saltcoats Victoria v …</td></tr><tr><th>Date</th><td>Saturday …</td></tr><tr><th>Venue</th><td>Campbell Park</td></tr><tr><th>Kick-off</th><td>2.00pm</td></tr></tbody></table><p>&nbsp;</p>' },
      { l: 'FAQ', d: 'Three expandable questions', html: '<details><summary>Question one?</summary><p>Answer one.</p></details><details><summary>Question two?</summary><p>Answer two.</p></details><details><summary>Question three?</summary><p>Answer three.</p></details><p>&nbsp;</p>' },
      { l: 'Sponsor thank-you', d: 'Pullquote, text and contact button', html: '<blockquote class="nb-pullquote"><p>Thank you for backing the Vics.</p></blockquote><p>Write a few words about the sponsor here.</p><p class="nb-buttons nb-buttons--gold"><a class="nb-button" href="https://">Visit their website</a></p>' },
      { l: 'Two-column feature', d: 'Text beside text, ready to fill in', html: '<div class="nb-columns"><div class="nb-col"><h3>Left heading</h3><p>Left text.</p></div><div class="nb-col"><h3>Right heading</h3><p>Right text.</p></div></div><p>&nbsp;</p>' },
      { l: 'Match report scoreboard', d: 'Result, scorers and sponsor line', html: '<h2>Full-time: Saltcoats Victoria 0–0 Opponent</h2><p><strong>Scorers:</strong> …</p><p><strong>Teams:</strong> …</p><p><em>Matchday sponsor: …</em></p><p><em>Our Club. Our Town.</em></p>' }
    ];

    function insert(b) {
      close();
      ed.focus();
      if (b.run) b.run(); else ed.insertContent(b.html);
    }

    function buttonDialog() {
      ed.windowManager.open({
        title: 'Button',
        body: { type: 'panel', items: [
          { type: 'input', name: 'text', label: 'Button text' },
          { type: 'input', name: 'url', label: 'Link (https://…)' },
          { type: 'selectbox', name: 'style', label: 'Colour', items: [{ value: 'maroon', text: 'Club maroon' }, { value: 'gold', text: 'Club gold' }] }
        ] },
        initialData: { text: 'Find out more', url: 'https://', style: 'maroon' },
        buttons: [{ type: 'cancel', text: 'Cancel' }, { type: 'submit', text: 'Insert button', buttonType: 'primary' }],
        onSubmit: function (api) {
          var d = api.getData(), url = String(d.url || '').trim();
          if (url && !/^(https?:\/\/|\/|mailto:|tel:)/i.test(url)) url = 'https://' + url;
          ed.insertContent('<p class="nb-buttons' + (d.style === 'gold' ? ' nb-buttons--gold' : '') + '"><a class="nb-button" href="' + esc(url) + '">' + esc(d.text || 'Button') + '</a></p>');
          api.close();
        }
      });
    }

    /* ---- drawer UI ---- */
    if (!document.getElementById('nb-ui-css')) {
      var st = document.createElement('style'); st.id = 'nb-ui-css'; st.textContent = UI_CSS; document.head.appendChild(st);
    }
    var drawer = document.createElement('aside');
    drawer.className = 'nb-drawer'; drawer.setAttribute('aria-label', 'Add a block');
    drawer.innerHTML =
      '<div class="nb-head"><button type="button" class="nb-tab is-on" data-tab="blocks">Blocks</button>' +
      '<button type="button" class="nb-tab" data-tab="patterns">Patterns</button>' +
      '<button type="button" class="nb-close" aria-label="Close">&times;</button></div>' +
      '<input type="search" class="nb-search" placeholder="Search" aria-label="Search blocks">' +
      '<div class="nb-body"></div>';
    document.body.appendChild(drawer);
    var bodyEl = drawer.querySelector('.nb-body'), searchEl = drawer.querySelector('.nb-search');
    var tab = 'blocks';

    function render() {
      var q = searchEl.value.trim().toLowerCase(), out = '', n = 0;
      if (tab === 'blocks') {
        var groups = {};
        BLOCKS.forEach(function (b) {
          if (q && b.l.toLowerCase().indexOf(q) === -1 && b.g.toLowerCase().indexOf(q) === -1) return;
          (groups[b.g] = groups[b.g] || []).push(b); n++;
        });
        Object.keys(groups).forEach(function (g) {
          out += '<div class="nb-group">' + esc(g) + '</div><div class="nb-grid">' + groups[g].map(function (b) {
            return '<button type="button" class="nb-item" data-id="' + b.id + '"><span class="nb-ico">' + (b.i[0] && icons[b.i[0]] ? icons[b.i[0]] : esc(b.i[1])) + '</span>' + esc(b.l) + '</button>';
          }).join('') + '</div>';
        });
      } else {
        PATTERNS.forEach(function (p, ix) {
          if (q && p.l.toLowerCase().indexOf(q) === -1 && p.d.toLowerCase().indexOf(q) === -1) return;
          out += '<button type="button" class="nb-pattern" data-p="' + ix + '">' + esc(p.l) + '<small>' + esc(p.d) + '</small></button>'; n++;
        });
      }
      bodyEl.innerHTML = out || '<p class="nb-empty">Nothing matches “' + esc(q) + '”.</p>';
    }
    function open() { drawer.classList.add('is-open'); render(); setTimeout(function () { searchEl.focus(); }, 30); }
    function close() { drawer.classList.remove('is-open'); }
    function toggle() { drawer.classList.contains('is-open') ? close() : open(); }

    drawer.addEventListener('click', function (e) {
      var t = e.target.closest('button'); if (!t) return;
      if (t.classList.contains('nb-close')) { close(); return; }
      if (t.dataset.tab) {
        tab = t.dataset.tab;
        drawer.querySelectorAll('.nb-tab').forEach(function (x) { x.classList.toggle('is-on', x === t); });
        render(); return;
      }
      if (t.dataset.id) { insert(BLOCKS.filter(function (b) { return b.id === t.dataset.id; })[0]); return; }
      if (t.dataset.p) { insert(PATTERNS[+t.dataset.p]); }
    });
    searchEl.addEventListener('input', render);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('is-open')) close(); });
    ed.on('remove', function () { drawer.remove(); });

    ed.ui.registry.addButton('addblock', { icon: 'plus', text: 'Add block', tooltip: 'Add a block', onAction: toggle });
    ed.ui.registry.addMenuItem('addblock', { icon: 'plus', text: 'Add block…', onAction: open });

    /* "/" quick inserter */
    ed.ui.registry.addAutocompleter('nbblocks', {
      trigger: '/', minChars: 0, columns: 1, maxResults: 12,
      fetch: function (pattern) {
        var q = pattern.toLowerCase();
        return Promise.resolve(BLOCKS.filter(function (b) { return !q || b.l.toLowerCase().indexOf(q) !== -1; }).map(function (b) {
          return { type: 'autocompleteitem', value: b.id, text: b.l, icon: b.i[0] && icons[b.i[0]] ? b.i[0] : undefined };
        }));
      },
      onAction: function (api, rng, value) {
        var b = BLOCKS.filter(function (x) { return x.id === value; })[0];
        ed.selection.setRng(rng); ed.execCommand('Delete'); api.hide();
        if (b) { if (b.run) b.run(); else ed.insertContent(b.html); }
      }
    });
  };
})();
