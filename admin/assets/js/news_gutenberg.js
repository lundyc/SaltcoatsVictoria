/* News article editor — Gutenberg (Automattic Isolated Block Editor).
 * Attaches the WordPress block editor to the #body textarea. The textarea
 * receives standard block markup, which the form submits as before. */
(function () {
  'use strict';

  var PALETTE = [
    { name: 'Club maroon', slug: 'club-maroon', color: '#6d2231' },
    { name: 'Club gold', slug: 'club-gold', color: '#e0b42a' },
    { name: 'Ink', slug: 'ink', color: '#2e2c2d' },
    { name: 'Muted grey', slug: 'muted', color: '#7c7178' },
    { name: 'Cream', slug: 'cream', color: '#f4efe6' },
    { name: 'White', slug: 'white', color: '#ffffff' },
    { name: 'Green', slug: 'green', color: '#1b8a4b' },
    { name: 'Red', slug: 'red', color: '#b23b3b' },
    { name: 'Blue', slug: 'blue', color: '#2f6f9f' }
  ];

  // Blocks that need WordPress itself (database, REST API, themes) or content
  // types the club site doesn't host. Everything else in Gutenberg is available.
  var DISALLOW = [
    'core/shortcode', 'core/html', 'core/freeform', 'core/embed',
    'core/audio', 'core/video', 'core/file', 'core/more', 'core/nextpage',
    'core/latest-posts', 'core/latest-comments', 'core/archives', 'core/calendar',
    'core/categories', 'core/page-list', 'core/rss', 'core/search', 'core/tag-cloud',
    'core/loginout', 'core/social-links', 'core/social-link', 'core/navigation', 'core/navigation-link',
    'core/navigation-submenu', 'core/home-link', 'core/site-logo', 'core/site-title', 'core/site-tagline',
    'core/query', 'core/post-title', 'core/post-content', 'core/post-excerpt', 'core/post-featured-image',
    'core/post-date', 'core/post-author', 'core/post-terms', 'core/post-navigation-link',
    'core/post-template', 'core/template-part', 'core/block', 'core/pattern', 'core/comments',
    'core/legacy-widget', 'core/widget-group', 'core/avatar', 'core/read-more', 'core/term-description',
    'core/pullquote-legacy'
  ];

  function editorCss() {
    var css = [
      ".editor-styles-wrapper{font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:1.75;color:#2e2c2d}",
      ".editor-styles-wrapper h1,.editor-styles-wrapper h2,.editor-styles-wrapper h3,.editor-styles-wrapper h4{font-family:-apple-system,'Segoe UI',Roboto,sans-serif;font-weight:800;line-height:1.2}",
      ".editor-styles-wrapper a{color:#6d2231}",
      ".editor-styles-wrapper .wp-block-button__link{background:#6d2231;color:#fff;border-radius:999px;padding:.8em 1.6em;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;font-weight:700}"
    ];
    PALETTE.forEach(function (c) {
      css.push('.has-' + c.slug + '-color{color:' + c.color + ' !important}');
      css.push('.has-' + c.slug + '-background-color{background-color:' + c.color + ' !important}');
      css.push('.has-' + c.slug + '-border-color{border-color:' + c.color + ' !important}');
    });
    return css.join('');
  }

  // WordPress drives every blue accent (active buttons, focus rings, selected
  // blocks, links) from one variable; point it at the club maroon instead.
  var THEME_CSS = [
    ':root,.iso-editor,.components-popover,.components-modal__screen-overlay,.components-snackbar-list,.editor-styles-wrapper{',
    '--wp-admin-theme-color:#6d2231 !important;--wp-admin-theme-color--rgb:109,34,49 !important;',
    '--wp-admin-theme-color-darker-10:#5e1d2a !important;--wp-admin-theme-color-darker-10--rgb:94,29,42 !important;',
    '--wp-admin-theme-color-darker-20:#4f1824 !important;--wp-admin-theme-color-darker-20--rgb:79,24,36 !important}',
    '.iso-editor .components-button.is-primary,.iso-editor .components-button.is-pressed,.components-popover .components-button.is-primary{background:#6d2231;color:#fff}',
    '.iso-editor .components-button.is-primary:hover:not(:disabled){background:#5e1d2a;color:#fff}',
    '.iso-editor .edit-post-header-toolbar__inserter-toggle.is-pressed{background:#2e2c2d}',
    '.iso-editor .edit-post-header-toolbar__inserter-toggle.components-button.has-icon{background:#6d2231;color:#fff}',
    '.iso-editor .edit-post-header-toolbar__inserter-toggle.components-button.has-icon:hover{background:#5e1d2a;color:#fff}',
    '.iso-editor .edit-post-header-toolbar__inserter-toggle.components-button.has-icon.is-pressed{background:#2e2c2d}',
    '.iso-editor .block-editor-block-list__block.is-selected::after,.iso-editor .is-selected>.block-editor-block-list__block-edit-label{border-color:#6d2231}',
    '.iso-editor ::selection{background:rgba(224,180,42,.45)}'
  ].join('');

  window.nbGutenberg = function (textarea, uploadImage) {
    if (!window.wp || !window.wp.attachEditor) { return false; }
    if (!document.getElementById('nb-gb-theme')) {
      var st = document.createElement('style'); st.id = 'nb-gb-theme'; st.textContent = THEME_CSS; document.head.appendChild(st);
    }

    var settings = {
      iso: {
        preferencesKey: 'hub-news-editor',
        persistenceKey: null,
        blocks: { disallowBlocks: DISALLOW },
        toolbar: { navigation: true, undo: true, inspector: true, documentInspector: 'Article' },
        sidebar: { inserter: true, inspector: true },
        moreMenu: { editor: true, fullscreen: true, preview: false, topToolbar: true },
        allowApi: false,
        defaultPreferences: { fixedToolbar: false }
      },
      editor: {
        colors: PALETTE,
        disableCustomColors: false,
        disableCustomGradients: true,
        gradients: [],
        hasFixedToolbar: false,
        allowedMimeTypes: { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' },
        styles: [{ css: editorCss() }],
        // Drag/drop and "Upload" in block placeholders: straight into the Media Library.
        mediaUpload: function (o) {
          var files = Array.prototype.slice.call(o.filesList || []).filter(function (f) { return /^image\//.test(f.type); });
          if (!files.length) { o.onError && o.onError({ message: 'Only images can be uploaded here.' }); return; }
          var done = [], failed = 0;
          files.forEach(function (f) {
            window.nbMediaPicker.uploadFile(f).then(function (it) {
              done.push(window.nbMediaPicker.attachment(it));
              if (done.length + failed === files.length && done.length) { o.onFileChange(done); }
            }, function (err) {
              failed++; o.onError && o.onError({ message: f.name + ': ' + err.message });
              if (done.length && done.length + failed === files.length) { o.onFileChange(done); }
            });
          });
        }
      }
    };

    window.wp.attachEditor(textarea, settings);
    return true;
  };
})();
