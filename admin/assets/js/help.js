(function () {
  'use strict';

  // Field hints: <button data-hub-help="text"> opens a popover on tap/click.
  function initHints() {
    if (!window.bootstrap) return;
    document.querySelectorAll('[data-hub-help]').forEach(function (el) {
      new bootstrap.Popover(el, {
        content: el.getAttribute('data-hub-help'),
        trigger: 'focus',
        placement: 'auto',
        container: 'body'
      });
    });
  }

  // Page help slide-out: load the articles linked to this page on first open.
  function initPanel() {
    var panel = document.getElementById('hubHelpPanel');
    if (!panel) return;
    var body = document.getElementById('hubHelpPanelBody');
    var loaded = false;
    panel.addEventListener('show.bs.offcanvas', function () {
      if (loaded) return;
      fetch('/admin/help_panel.php?page=' + encodeURIComponent(panel.getAttribute('data-page') || ''), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
        .then(function (html) { body.innerHTML = html; loaded = true; })
        .catch(function () {
          body.innerHTML = '<p class="text-muted">Help could not be loaded. <a href="/admin/help.php">Open the Help Centre</a>.</p>';
        });
    });
  }

  function init() { initHints(); initPanel(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* Lightbox for screenshots in help guides (works in articles and the ? panel). */
(function () {
  'use strict';

  var box = null, img, caption, counter, prevBtn, nextBtn, group = [], index = 0, opener = null;

  function build() {
    box = document.createElement('div');
    box.className = 'hub-lightbox';
    box.hidden = true;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Screenshot viewer');
    box.innerHTML =
      '<button type="button" class="hub-lightbox__btn hub-lightbox__close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>' +
      '<button type="button" class="hub-lightbox__btn hub-lightbox__nav hub-lightbox__prev" aria-label="Previous screenshot"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>' +
      '<figure class="hub-lightbox__figure"><img alt=""><figcaption><span class="hub-lightbox__caption"></span><span class="hub-lightbox__counter"></span></figcaption></figure>' +
      '<button type="button" class="hub-lightbox__btn hub-lightbox__nav hub-lightbox__next" aria-label="Next screenshot"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>';
    document.body.appendChild(box);
    img = box.querySelector('img');
    caption = box.querySelector('.hub-lightbox__caption');
    counter = box.querySelector('.hub-lightbox__counter');
    prevBtn = box.querySelector('.hub-lightbox__prev');
    nextBtn = box.querySelector('.hub-lightbox__next');

    box.addEventListener('click', function (e) {
      if (e.target.closest('.hub-lightbox__close') || e.target === box || e.target.closest('.hub-lightbox__figure') === null && !e.target.closest('.hub-lightbox__nav')) close();
    });
    prevBtn.addEventListener('click', function () { show(index - 1); });
    nextBtn.addEventListener('click', function () { show(index + 1); });
    document.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'ArrowLeft') { show(index - 1); }
      else if (e.key === 'ArrowRight') { show(index + 1); }
      else if (e.key === 'Tab') { e.preventDefault(); box.querySelector('.hub-lightbox__close').focus(); }
    }, true);
  }

  function show(i) {
    if (!group.length) return;
    index = (i + group.length) % group.length;
    var a = group[index], thumb = a.querySelector('img');
    img.src = a.getAttribute('href');
    img.alt = thumb ? thumb.alt : '';
    caption.textContent = thumb ? thumb.alt : '';
    var many = group.length > 1;
    counter.textContent = many ? (index + 1) + ' of ' + group.length : '';
    prevBtn.hidden = nextBtn.hidden = !many;
  }

  function open(link) {
    if (!box) build();
    var scope = link.closest('.help-prose, .help-article-body, .help-panel__article, .accordion-body') || document;
    group = Array.prototype.slice.call(scope.querySelectorAll('a.help-figure'));
    if (!group.length) group = [link];
    opener = link;
    box.hidden = false;
    document.documentElement.classList.add('hub-lightbox-open');
    show(group.indexOf(link));
    box.querySelector('.hub-lightbox__close').focus();
  }

  function close() {
    box.hidden = true;
    img.removeAttribute('src');
    document.documentElement.classList.remove('hub-lightbox-open');
    if (opener) opener.focus();
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest('a.help-figure');
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return; // modified clicks keep the normal new-tab link
    e.preventDefault();
    open(link);
  });
})();
