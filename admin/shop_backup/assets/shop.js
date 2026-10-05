/* Club Shop storefront — progressive enhancement only.
   Every form still works with JavaScript disabled. */
(function () {
    'use strict';

    var toastEl = document.querySelector('[data-toast]');
    var toastTimer = null;
    function toast(message, isError) {
        if (!toastEl) { return; }
        toastEl.textContent = message;
        toastEl.classList.toggle('shop-toast--error', !!isError);
        toastEl.hidden = false;
        // force reflow so the transition runs
        void toastEl.offsetWidth;
        toastEl.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toastEl.classList.remove('is-visible');
            window.setTimeout(function () { toastEl.hidden = true; }, 250);
        }, 3200);
    }

    function setBasketCount(n) {
        document.querySelectorAll('[data-basket-count]').forEach(function (el) {
            el.textContent = String(n);
            el.hidden = !n;
        });
    }

    /* ---- qty steppers ---- */
    document.querySelectorAll('[data-stepper]').forEach(function (stepper) {
        var input = stepper.querySelector('input');
        if (!input) { return; }
        var min = parseInt(input.getAttribute('min') || '1', 10);
        var max = parseInt(input.getAttribute('max') || '99', 10);
        stepper.querySelectorAll('button[data-step]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var v = parseInt(input.value || '1', 10) || min;
                v += parseInt(btn.getAttribute('data-step'), 10);
                v = Math.max(min, Math.min(max, v));
                if (String(v) === input.value) { return; }
                input.value = String(v);
                // Fires the input's own onchange (basket rows auto-submit; the
                // product page has no handler, so this is a harmless no-op there).
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    /* ---- two-step "choose fit, then size" ---- */
    document.querySelectorAll('[data-optgroup]').forEach(function (fieldset) {
        var fitRow = fieldset.querySelector('[data-fit-row]');
        var wrap = fieldset.querySelector('[data-size-wrap]');
        var select = fieldset.querySelector('[data-size-select]');
        if (!fitRow || !wrap || !select) { return; }

        // Enhanced state: reveal the fit chooser, hide + park the size list
        // until a fit is picked (disabled = skipped by validation & not sent).
        fitRow.hidden = false;
        wrap.hidden = true;
        select.disabled = true;

        function applyFit(fit) {
            Array.prototype.forEach.call(select.options, function (opt) {
                if (!opt.dataset.fit) { return; } // the "Select…" placeholder
                var match = opt.dataset.fit === fit;
                opt.hidden = !match;
                opt.disabled = !match || opt.dataset.soldout === '1';
            });
            Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (og) {
                og.hidden = og.dataset.fitGroup !== fit;
            });
            var current = select.options[select.selectedIndex];
            if (!current || current.dataset.fit !== fit) { select.value = ''; }
            select.disabled = false;
            wrap.hidden = false;
            try { select.focus(); } catch (e) { /* ignore */ }
        }

        fieldset.querySelectorAll('[data-fit-radio]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.checked) { applyFit(radio.value); }
            });
        });
    });

    /* ---- product gallery ---- */
    var mainImg = document.querySelector('[data-gallery-main]');
    if (mainImg) {
        document.querySelectorAll('[data-gallery-thumb]').forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                var src = thumb.getAttribute('data-src');
                if (src) { mainImg.style.backgroundImage = 'url("' + src.replace(/"/g, '\\"') + '")'; }
                document.querySelectorAll('[data-gallery-thumb]').forEach(function (t) { t.classList.remove('is-active'); });
                thumb.classList.add('is-active');
            });
        });
    }

    /* ---- ajax add to basket ---- */
    document.querySelectorAll('form[data-add-to-basket]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            // Two-step size groups: nudge the customer to pick a fit first.
            var parked = form.querySelector('[data-size-select]:disabled');
            if (parked) {
                ev.preventDefault();
                var fs = parked.closest('[data-optgroup]');
                var firstRadio = fs && fs.querySelector('[data-fit-radio]');
                if (firstRadio) { firstRadio.focus(); }
                toast('Please choose Adult or Kids first.', true);
                return;
            }
            if (!window.fetch) { return; }
            ev.preventDefault();
            var btn = form.querySelector('button[type="submit"], .shop-btn--primary');
            var original = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = 'Adding…'; }

            // Use the attribute, not form.action: the form has a hidden
            // <input name="action">, which shadows the .action property so it
            // returns that element instead of the URL string.
            var endpoint = form.getAttribute('action') || window.location.href;

            fetch(endpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: new FormData(form)
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
              .then(function (res) {
                  if (!res.ok || !res.body || !res.body.ok) {
                      toast((res.body && res.body.error) || 'Could not add to basket.', true);
                      return;
                  }
                  if (typeof res.body.basket_count === 'number') { setBasketCount(res.body.basket_count); }
                  toast(res.body.message || 'Added to your basket.');
              }).catch(function () {
                  toast('Could not add to basket. Please try again.', true);
              }).finally(function () {
                  if (btn) { btn.disabled = false; btn.innerHTML = original; }
              });
        });
    });
})();
