(function () {
  'use strict';

  var input = document.getElementById('helpSearch');
  if (!input) return;
  var clear = document.getElementById('helpClear');
  var results = document.getElementById('helpResults');
  var browse = document.getElementById('helpBrowse');
  var chips = Array.prototype.slice.call(document.querySelectorAll('.help-chip'));
  var area = (document.querySelector('.help-chip.is-active') || {}).dataset ? document.querySelector('.help-chip.is-active').dataset.area : '';
  var timer = null, seq = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function applyBrowse() {
    document.querySelectorAll('[data-help-area]').forEach(function (s) {
      s.hidden = !!area && s.getAttribute('data-help-area') !== area;
    });
    document.querySelectorAll('[data-help-start]').forEach(function (s) { s.hidden = !!area; });
  }

  function render(data) {
    results.textContent = '';
    var q = data.query;
    var head = el('div', 'help-results__head');
    head.appendChild(el('strong', null, data.results.length + (data.results.length === 1 ? ' result' : ' results')));
    head.appendChild(document.createTextNode(' for “' + q + '”' + (area ? ' in ' + area : '')));
    results.appendChild(head);

    if (!data.results.length) {
      var empty = el('div', 'help-empty help-empty--inline');
      empty.appendChild(el('h2', null, 'No guides match your search'));
      empty.appendChild(el('p', null, 'Try fewer or different words, or browse by area.'));
      if (area) {
        var all = el('button', 'btn btn-outline-secondary btn-sm', 'Search all areas');
        all.type = 'button';
        all.addEventListener('click', function () { setArea(''); });
        empty.appendChild(all);
      }
      results.appendChild(empty);
      return;
    }
    var list = el('ol', 'help-results__list');
    data.results.forEach(function (r) {
      var li = el('li');
      var a = el('a', 'help-result');
      a.href = 'help.php?a=' + encodeURIComponent(r.slug);
      a.appendChild(el('span', 'help-result__area', r.area + (r.draft ? ' · Draft' : '')));
      a.appendChild(el('span', 'help-result__title', r.title));
      var snip = el('span', 'help-result__snippet');
      snip.innerHTML = r.snippet; // escaped by the server; only <mark> tags are added
      a.appendChild(snip);
      li.appendChild(a);
      list.appendChild(li);
    });
    results.appendChild(list);
  }

  function search() {
    var q = input.value.trim();
    clear.hidden = q === '';
    if (q === '') {
      results.hidden = true;
      browse.hidden = false;
      applyBrowse();
      return;
    }
    var mine = ++seq;
    fetch(input.getAttribute('data-endpoint') + '?q=' + encodeURIComponent(q) + '&area=' + encodeURIComponent(area), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (data) {
        if (mine !== seq) return;
        browse.hidden = true;
        results.hidden = false;
        render(data);
      })
      .catch(function () {
        if (mine !== seq) return;
        results.hidden = false;
        results.textContent = 'Search is unavailable right now. You can still browse the guides below.';
        browse.hidden = false;
      });
  }

  function setArea(value) {
    area = value;
    chips.forEach(function (c) {
      var on = c.dataset.area === value;
      c.classList.toggle('is-active', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    var url = new URL(window.location.href);
    if (value) url.searchParams.set('area', value); else url.searchParams.delete('area');
    history.replaceState(null, '', url.toString());
    if (input.value.trim() !== '') search(); else applyBrowse();
  }

  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 180); });
  clear.addEventListener('click', function () { input.value = ''; search(); input.focus(); });
  chips.forEach(function (c) { c.addEventListener('click', function () { setArea(c.dataset.area || ''); }); });

  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
    if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey && !e.altKey) { e.preventDefault(); input.focus(); input.select(); }
    if (e.key === 'Escape' && document.activeElement === input && input.value) { input.value = ''; search(); }
  });

  var preset = new URLSearchParams(window.location.search).get('q');
  if (preset) { input.value = preset; search(); } else { applyBrowse(); }
})();
