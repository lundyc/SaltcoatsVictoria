/*
 * Phone and tablet helpers for long admin lists.
 *
 *  [data-list-limit="20"]        shows the first 20 items with "Show more" buttons.
 *      data-list-items=".sel"    which items count (a CSS selector, relative to the element)
 *      data-list-max-width="991.98"   only active at or below this width; wider screens show everything
 *      data-list-search="Search sponsors..."  add a quick search box (the placeholder text)
 *  [data-filter-collapse]        a filter card that collapses behind a "Filters" button on phones.
 *
 * Wider screens are left exactly as they were. Items are hidden with the `hidden` attribute, never removed,
 * so forms still submit and other scripts still find every row.
 */
(function () {
    'use strict';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    /* ---------- long lists ---------- */

    function initList(host) {
        var pageSize = parseInt(host.getAttribute('data-list-limit'), 10) || 20;
        var selector = host.getAttribute('data-list-items') || 'tbody tr';
        var maxWidth = parseFloat(host.getAttribute('data-list-max-width') || '991.98');
        var searchLabel = host.getAttribute('data-list-search');
        var mq = window.matchMedia('(max-width: ' + maxWidth + 'px)');

        var items = Array.prototype.slice.call(host.querySelectorAll(selector)).filter(function (n) {
            return !n.querySelector('td[colspan]') && !n.classList.contains('hub-record-empty');
        });
        if (items.length <= pageSize) { return; }

        var text = items.map(function (n) { return (n.textContent || '').toLowerCase().replace(/\s+/g, ' '); });
        var shown = pageSize;
        var query = '';

        var toolbar = el('div', 'hub-list-tools');
        var input = null;
        if (searchLabel) {
            var wrap = el('div', 'hub-list-tools__search');
            input = el('input', 'form-control');
            input.type = 'search';
            input.placeholder = searchLabel;
            input.setAttribute('aria-label', searchLabel);
            input.autocomplete = 'off';
            wrap.appendChild(input);
            toolbar.appendChild(wrap);
        }
        var count = el('div', 'hub-list-tools__count');
        count.setAttribute('role', 'status');
        count.setAttribute('aria-live', 'polite');
        toolbar.appendChild(count);

        var more = el('div', 'hub-list-more');
        var moreBtn = el('button', 'btn btn-outline-secondary');
        moreBtn.type = 'button';
        var allBtn = el('button', 'btn btn-link');
        allBtn.type = 'button';
        allBtn.textContent = 'Show all';
        more.appendChild(moreBtn);
        more.appendChild(allBtn);

        host.parentNode.insertBefore(toolbar, host);
        host.parentNode.insertBefore(more, host.nextSibling);

        function render() {
            var active = mq.matches;
            toolbar.hidden = !active;
            more.hidden = !active;
            if (!active) {
                items.forEach(function (n) { n.hidden = false; });
                return;
            }
            var matching = 0;
            var visible = 0;
            items.forEach(function (n, i) {
                var ok = query === '' || text[i].indexOf(query) !== -1;
                if (ok) { matching += 1; }
                var show = ok && matching <= shown;
                n.hidden = !show;
                if (show) { visible += 1; }
            });
            var remaining = matching - visible;
            count.textContent = query === ''
                ? 'Showing ' + visible + ' of ' + items.length
                : visible + ' of ' + matching + ' matching "' + query + '"';
            more.hidden = remaining <= 0;
            moreBtn.textContent = 'Show ' + Math.min(pageSize, remaining) + ' more';
            allBtn.textContent = 'Show all ' + matching;
            host.dispatchEvent(new CustomEvent('hub-list-change', { bubbles: true }));
        }

        moreBtn.addEventListener('click', function () { shown += pageSize; render(); });
        allBtn.addEventListener('click', function () { shown = items.length; render(); });
        if (input) {
            input.addEventListener('input', function () {
                query = input.value.trim().toLowerCase();
                shown = pageSize;
                render();
            });
        }
        if (mq.addEventListener) { mq.addEventListener('change', render); } else if (mq.addListener) { mq.addListener(render); }
        render();
    }

    /* ---------- collapsible filters (phones) ---------- */

    function activeFilterCount(card) {
        var n = 0;
        var groups = {};
        Array.prototype.forEach.call(card.querySelectorAll('select, input'), function (f) {
            if (f.type === 'hidden' || f.type === 'submit' || f.type === 'button') { return; }
            if (f.tagName === 'SELECT') {
                if (f.selectedIndex > 0 && f.value !== '' && f.value !== 'all') { n += 1; }
            } else if (f.type === 'checkbox' || f.type === 'radio') {
                var key = f.name || ('single-' + Math.random());
                groups[key] = groups[key] || { total: 0, checked: 0 };
                groups[key].total += 1;
                if (f.checked) { groups[key].checked += 1; }
            } else if (f.value.trim() !== '') {
                n += 1;
            }
        });
        Object.keys(groups).forEach(function (k) {
            var g = groups[k];
            // a set of checkboxes with every box ticked (or none) means "no filter"; a lone switch counts when it is on
            if (g.total === 1) { if (g.checked === 1) { n += 1; } } else if (g.checked > 0 && g.checked < g.total) { n += 1; }
        });
        return n;
    }

    function initFilters(card) {
        var body = card.querySelector('.card-body') || card;
        var mq = window.matchMedia('(max-width: 767.98px)');
        var btn = el('button', 'btn btn-outline-secondary hub-filter-toggle');
        btn.type = 'button';
        btn.setAttribute('aria-expanded', 'false');
        var label = el('span', 'hub-filter-toggle__label', 'Filters');
        var badge = el('span', 'hub-filter-toggle__badge');
        var chev = el('i', 'fa-solid fa-chevron-down hub-filter-toggle__chev');
        chev.setAttribute('aria-hidden', 'true');
        btn.appendChild(label);
        btn.appendChild(badge);
        btn.appendChild(chev);
        card.insertBefore(btn, card.firstChild);

        var open = false;
        function render() {
            var phone = mq.matches;
            btn.hidden = !phone;
            card.classList.toggle('hub-filter-collapsible', phone);
            body.hidden = phone && !open;
            var n = activeFilterCount(card);
            badge.textContent = n > 0 ? n + ' active' : '';
            badge.hidden = n === 0;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            chev.style.transform = open ? 'rotate(180deg)' : '';
        }
        btn.addEventListener('click', function () { open = !open; render(); });
        if (mq.addEventListener) { mq.addEventListener('change', render); } else if (mq.addListener) { mq.addListener(render); }
        render();
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-filter-collapse]'), initFilters);
        Array.prototype.forEach.call(document.querySelectorAll('[data-list-limit]'), initList);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
