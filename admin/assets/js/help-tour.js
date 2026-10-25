(function () {
  'use strict';

  var DRIVER_JS = 'https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.js.iife.js';
  var DRIVER_CSS = 'https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.css';
  var cfg = window.hubHelpConfig || {};
  var driverReady = null;

  function loadDriver() {
    if (driverReady) return driverReady;
    driverReady = new Promise(function (resolve, reject) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = DRIVER_CSS;
      document.head.appendChild(css);
      var js = document.createElement('script');
      js.src = DRIVER_JS;
      js.onload = resolve;
      js.onerror = reject;
      document.head.appendChild(js);
    });
    return driverReady;
  }

  function saveState(key, status) {
    var body = new URLSearchParams({ csrf_token: cfg.csrf || '', key: key, status: status });
    return fetch('/admin/help_tour.php', { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
  }

  function resetState(key) {
    var body = new URLSearchParams({ csrf_token: cfg.csrf || '', key: key, action: 'reset' });
    return fetch('/admin/help_tour.php', { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
  }

  function visible(el) {
    return !!(el && el.getClientRects().length);
  }

  function start(key) {
    var opened = [];
    var isMobile = window.matchMedia('(max-width: 991.98px)').matches;

    function restore() {
      opened.forEach(function (el) { el.classList.remove('show'); });
    }

    Promise.all([
      fetch('/admin/help_tour.php?key=' + encodeURIComponent(key), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); }),
      loadDriver()
    ]).then(function (res) {
      var tour = res[0];
      var wantsNav = tour.steps.some(function (s) { return s.nav; });
      if (isMobile && wantsNav) {
        var nav = document.getElementById('navbarMain');
        if (nav && !nav.classList.contains('show')) { nav.classList.add('show'); opened.push(nav); }
      }
      var steps = [];
      tour.steps.forEach(function (s) {
        if (s.expand) {
          var section = document.querySelector(s.expand);
          if (section && !section.classList.contains('show')) { section.classList.add('show'); opened.push(section); }
        }
        if (s.element && !visible(document.querySelector(s.element))) return;
        var step = { popover: { title: s.title, description: s.text } };
        if (s.element) step.element = s.element;
        steps.push(step);
      });
      if (!steps.length) {
        restore();
        window.alert('There is nothing to show for this tour on your screen.');
        return;
      }
      var d = window.driver.js.driver({
        showProgress: true,
        allowClose: true,
        nextBtnText: 'Next',
        prevBtnText: 'Back',
        doneBtnText: 'Finish',
        progressText: '{{current}} of {{total}}',
        steps: steps,
        onDestroyStarted: function () {
          saveState(key, d.isLastStep() ? 'completed' : 'skipped');
          restore();
          d.destroy();
        }
      });
      d.drive();
    }).catch(function () {
      restore();
      window.alert('The tour could not be started. Please try again later.');
    });
  }

  window.hubTour = { start: start };

  function init() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-hub-tour]');
      if (trigger) { e.preventDefault(); start(trigger.getAttribute('data-hub-tour')); return; }
      var reset = e.target.closest('[data-hub-prompt-reset]');
      if (reset) {
        e.preventDefault();
        resetState('prompt').then(function () { reset.textContent = 'Done. The welcome prompt will show on the Club overview.'; reset.disabled = true; });
        return;
      }
      var act = e.target.closest('[data-hub-prompt]');
      if (act) {
        var banner = document.getElementById('hubTourPrompt');
        var choice = act.getAttribute('data-hub-prompt');
        if (banner) banner.hidden = true;
        if (choice === 'start') start('welcome');
        else saveState('prompt', choice);
      }
    });

    var params = new URLSearchParams(window.location.search);
    if (params.get('tour')) {
      var key = params.get('tour');
      params.delete('tour');
      var qs = params.toString();
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
      start(key);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
