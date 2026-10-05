// assets/js/app.js — global JS helpers

// Restore the desktop navigation preference before the page is painted.
try {
  const isMembersArea = window.location.pathname.startsWith("/members/");
  if (!isMembersArea && window.localStorage.getItem("hubDesktopNavCollapsed") === "true") {
    document.documentElement.classList.add("hub-nav-collapsed");
  }
} catch (error) {
  // Storage can be unavailable in private browsing; the toggle still works for this page.
}

// Bootstrap tooltips — style every hover hint consistently instead of leaving
// most controls with the browser's inconsistent, untouchable native tooltip.
// This must run before Tooltip() below: Bootstrap moves `title` to
// `data-bs-original-title` once a tooltip is attached, so anything reading
// the raw title attribute (like the aria-label fallback here) has to go first.
document.querySelectorAll('[title]').forEach(el => {
  if (el.tagName === 'IFRAME') return; // title here is an accessible name, not a hint.
  const titleText = (el.getAttribute('title') || '').trim();
  if (!titleText) return;

  if (!el.hasAttribute('aria-label') && el.textContent.trim() === '') {
    el.setAttribute('aria-label', titleText);
  }
  if (!el.hasAttribute('data-bs-toggle')) {
    el.setAttribute('data-bs-toggle', 'tooltip');
  }
});
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
  new bootstrap.Tooltip(el);
});

// Shared helpers for form submission feedback. Several pages hijack a
// form's submit with fetch() and previously reported failures with a
// blocking alert() and no busy indicator on the button — these give every
// page a consistent, non-blocking way to do both.
window.hubSetBusy = function (button, busy) {
  if (!button) return;
  if (busy) {
    if (button.dataset.busyOriginalHtml === undefined) {
      button.dataset.busyOriginalHtml = button.innerHTML;
    }
    button.disabled = true;
    const label = button.dataset.busyOriginalHtml.replace(/<[^>]+>/g, '').trim();
    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + label;
  } else {
    button.disabled = false;
    if (button.dataset.busyOriginalHtml !== undefined) {
      button.innerHTML = button.dataset.busyOriginalHtml;
    }
  }
};

window.hubClearFormError = function (form) {
  form.querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
  form.querySelectorAll('[data-hub-field-error]').forEach(el => el.remove());
  if (form._hubErrorEl && form._hubErrorEl.isConnected) {
    form._hubErrorEl.remove();
  }
};

window.hubShowFormError = function (form, message) {
  let alertEl = form._hubErrorEl;
  if (!alertEl || !alertEl.isConnected) {
    alertEl = document.createElement('div');
    alertEl.className = 'alert alert-danger hub-form-error mb-3';
    alertEl.setAttribute('role', 'alert');
    form._hubErrorEl = alertEl;
    const modalBody = form.querySelector('.modal-body');
    if (modalBody) {
      modalBody.prepend(alertEl);
    } else {
      form.before(alertEl);
    }
  }
  alertEl.textContent = message;
};

// Marks a single named field invalid using Bootstrap's is-invalid/invalid-feedback
// pattern, falling back to the generic banner above when the server didn't name
// a field or the field can't be found in this form.
window.hubShowFieldError = function (form, field, message) {
  const input = field ? form.querySelector('[name="' + field + '"]') : null;
  if (!input) {
    window.hubShowFormError(form, message);
    return;
  }

  input.classList.add('is-invalid');
  let feedback = input.nextElementSibling && input.nextElementSibling.hasAttribute('data-hub-field-error')
    ? input.nextElementSibling
    : null;
  if (!feedback) {
    feedback = document.createElement('div');
    feedback.className = 'invalid-feedback';
    feedback.setAttribute('data-hub-field-error', '');
    input.insertAdjacentElement('afterend', feedback);
  }
  feedback.textContent = message;

  const clear = () => {
    input.classList.remove('is-invalid');
    feedback.remove();
  };
  input.addEventListener('input', clear, { once: true });
  input.addEventListener('change', clear, { once: true });
};

// Give plain (non-AJAX) form submissions the same busy feedback. Forms that
// hijack submission themselves already call event.preventDefault() before
// this runs, so defaultPrevented is the natural signal to leave them alone.
document.addEventListener('submit', event => {
  if (event.defaultPrevented) return;
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) return;

  const button = event.submitter;
  if (!button || button.tagName !== 'BUTTON' || button.disabled) return;
  if (button.querySelector('.spinner-border')) return;

  // The browser builds the submitted form data from the submit event's
  // submitter *after* this listener returns, and a disabled submitter is
  // dropped from that data — so name/value submit buttons (like
  // "Remove photo") would silently lose their value if disabled here
  // synchronously. Deferring one tick lets the real submission serialize
  // first; the busy state still appears effectively immediately.
  setTimeout(() => window.hubSetBusy(button, true), 0);
});

// Auto-hide success alerts after a few seconds
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".nav-link.active").forEach(link => {
    if (!link.hasAttribute("aria-current")) link.setAttribute("aria-current", "page");
  });

  // Give responsive record tables usable mobile labels without requiring every
  // legacy list page to duplicate the column heading in its cell markup.
  document.querySelectorAll(".hub-data-table--responsive").forEach(table => {
    const headings = Array.from(table.querySelectorAll("thead th")).map(heading =>
      heading.textContent.trim()
    );
    table.querySelectorAll("tbody tr").forEach(row => {
      Array.from(row.children).forEach((cell, index) => {
        if (!cell.hasAttribute("data-label") && headings[index]) {
          cell.setAttribute("data-label", headings[index]);
        }
      });
    });
  });

  const seasonSelect = document.getElementById("hubSeasonSelect");
  if (seasonSelect) {
    seasonSelect.addEventListener("change", () => {
      seasonSelect.closest("form").submit();
      seasonSelect.disabled = true;
    });
  }

  const desktopNavToggle = document.getElementById("hubDesktopNavToggle");
  if (desktopNavToggle) {
    const updateDesktopNavToggle = () => {
      const collapsed = document.documentElement.classList.contains("hub-nav-collapsed");
      const label = collapsed ? "Show side navigation" : "Hide side navigation";

      desktopNavToggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      desktopNavToggle.setAttribute("aria-label", label);
      desktopNavToggle.setAttribute("title", label);
      desktopNavToggle.innerHTML = '<i class="fa-solid fa-chevron-' + (collapsed ? "right" : "left") + '" aria-hidden="true"></i>';
    };

    desktopNavToggle.addEventListener("click", () => {
      document.documentElement.classList.toggle("hub-nav-collapsed");
      try {
        window.localStorage.setItem(
          "hubDesktopNavCollapsed",
          document.documentElement.classList.contains("hub-nav-collapsed") ? "true" : "false"
        );
      } catch (error) {
        // The visual state still changes if storage is unavailable.
      }
      updateDesktopNavToggle();
    });

    updateDesktopNavToggle();
  }

  document.querySelectorAll('.alert-success, .alert-danger, .alert-warning, .alert-info').forEach(alert => {
    if (!alert.hasAttribute("role")) {
      alert.setAttribute("role", alert.classList.contains("alert-danger") ? "alert" : "status");
    }
    if (!alert.querySelector(".btn-close") && !alert.classList.contains("alert-permanent")) {
      alert.classList.add("alert-dismissible");
      const closeButton = document.createElement("button");
      closeButton.type = "button";
      closeButton.className = "btn-close";
      closeButton.setAttribute("data-bs-dismiss", "alert");
      closeButton.setAttribute("aria-label", "Dismiss message");
      alert.appendChild(closeButton);
    }
  });
});

// Lightweight, non-blocking success/error feedback for AJAX flows that update
// the page in place (no reload) and so have no `.alert-*` banner to show one.
// Renders into the `.hub-toast-region` live region every authenticated page
// already has in footer.php, using Bootstrap's own Toast component.
window.hubToast = function (message, type) {
  var region = document.querySelector('.hub-toast-region');
  if (!region || typeof bootstrap === 'undefined') return;

  type = type === 'danger' ? 'danger' : 'success';
  var toastEl = document.createElement('div');
  toastEl.className = 'toast align-items-center text-bg-' + type + ' border-0';
  toastEl.setAttribute('role', type === 'danger' ? 'alert' : 'status');
  toastEl.setAttribute('aria-atomic', 'true');
  toastEl.innerHTML =
    '<div class="d-flex">' +
      '<div class="toast-body"></div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
    '</div>';
  toastEl.querySelector('.toast-body').textContent = message;
  region.appendChild(toastEl);

  var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
  toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
  toast.show();
};

// Consistent confirmation for destructive links and forms — and, via
// window.hubConfirm(), for imperative JS flows too. Previously the declarative
// data-confirm path used this branded modal while dozens of inline <script>
// blocks fell back to the browser's native confirm()/alert(), an unstyled,
// blocking dialog that looked like a bug next to everything else in the Hub.
// window.hubConfirm() gives that JS the same modal as a Promise<boolean>:
//   window.hubConfirm('Delete this?', { actionLabel: 'Delete' }).then(ok => { ... })
document.addEventListener("DOMContentLoaded", () => {
  const dialogElement = document.getElementById("hubConfirmDialog");
  const actionButton = document.getElementById("hubConfirmDialogAction");
  const title = document.getElementById("hubConfirmDialogTitle");
  const message = document.getElementById("hubConfirmDialogMessage");
  if (!dialogElement || !actionButton || typeof bootstrap === "undefined") return;

  const dialog = new bootstrap.Modal(dialogElement);
  let resolvePending = null;

  const settle = (result) => {
    if (!resolvePending) return;
    const resolve = resolvePending;
    resolvePending = null;
    resolve(result);
  };

  window.hubConfirm = function (messageText, options) {
    options = options || {};
    return new Promise((resolve) => {
      settle(false); // an overlapping call shouldn't normally happen, but don't leak it if it does
      resolvePending = resolve;
      title.textContent = options.title || "Confirm this action";
      message.textContent = messageText || "This action cannot be undone.";
      actionButton.textContent = options.actionLabel || "Continue";
      actionButton.className = "btn " + (options.actionClass || "btn-danger");
      dialog.show();
    });
  };

  const confirmFromAttrs = (trigger) => window.hubConfirm(trigger.getAttribute("data-confirm") || "This action cannot be undone.", {
    title: trigger.getAttribute("data-confirm-title"),
    actionLabel: trigger.getAttribute("data-confirm-action"),
    actionClass: trigger.getAttribute("data-confirm-class"),
  });

  document.addEventListener("click", event => {
    const link = event.target.closest("a[data-confirm]");
    if (link) {
      event.preventDefault();
      confirmFromAttrs(link).then(ok => { if (ok) window.location.assign(link.href); });
      return;
    }

    const submitButton = event.target.closest("button[data-confirm]");
    if (!submitButton || submitButton.dataset.confirmed === "true" || !submitButton.form) return;
    event.preventDefault();
    confirmFromAttrs(submitButton).then(ok => {
      if (!ok) return;
      submitButton.dataset.confirmed = "true";
      submitButton.form.requestSubmit(submitButton);
    });
  });

  document.addEventListener("submit", event => {
    const form = event.target;
    if (!form.matches("form[data-confirm]") || form.dataset.confirmed === "true") return;
    event.preventDefault();
    event.stopImmediatePropagation();
    confirmFromAttrs(form).then(ok => {
      if (!ok) return;
      form.dataset.confirmed = "true";
      form.requestSubmit(event.submitter || undefined);
    });
  }, true);

  actionButton.addEventListener("click", () => {
    dialog.hide();
    settle(true);
  });

  dialogElement.addEventListener("hidden.bs.modal", () => { settle(false); });
});

// Close the mobile navbar after using a navigation link.
document.addEventListener("click", (e) => {
  const link = e.target.closest("#navbarMain .nav-link, #navbarMain .dropdown-item");
  if (!link) return;
  if (link.classList.contains("dropdown-toggle")) return;
  if (window.innerWidth >= 992) return;

  const navbar = document.getElementById("navbarMain");
  if (!navbar) return;

  const collapse = bootstrap.Collapse.getInstance(navbar);
  if (collapse) {
    collapse.hide();
  }
});

// Unsaved-changes guard — opt-in via <form data-warn-unsaved> on long or
// complex forms, where losing edits to a stray back-button or an expired
// session is costly. Warns before leaving the page while the form has
// uncommitted edits; a real submit clears the flag so saving never warns.
document.querySelectorAll('form[data-warn-unsaved]').forEach(function (form) {
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('change', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });

  window.addEventListener('beforeunload', function (event) {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
});

// Sidebar sections — personalisation. Every collapse/expand the user makes is
// remembered per browser (localStorage "hubNavSections") and reapplied on
// every page. The one exception: the section that contains the current page
// is revealed on load so you can always see where you are — but that reveal
// is transient and does NOT change the stored preference, so it collapses
// again as soon as you navigate elsewhere.
(function () {
  const KEY = "hubNavSections";
  const toggles = document.querySelectorAll(".nav-section-toggle[data-nav-section]");
  if (!toggles.length) return;

  let store = {};
  try {
    store = JSON.parse(window.localStorage.getItem(KEY) || "{}") || {};
  } catch (error) {
    store = {};
  }
  const save = () => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(store));
    } catch (error) {
      /* storage unavailable (private mode) — preference just won't persist */
    }
  };

  const setOpen = (btn, panel, open) => {
    panel.classList.toggle("show", open);
    btn.setAttribute("aria-expanded", open ? "true" : "false");
  };

  toggles.forEach((btn) => {
    const key = btn.getAttribute("data-nav-section");
    const target = btn.getAttribute("data-bs-target");
    const panel = target ? document.querySelector(target) : null;
    if (!panel) return;

    // 1. Apply the remembered preference (falls back to the server render).
    if (Object.prototype.hasOwnProperty.call(store, key)) {
      setOpen(btn, panel, store[key] === 1);
    }
    // 2. Always reveal the section you're currently in — without recording it.
    if (panel.querySelector(".nav-link.active") && !panel.classList.contains("show")) {
      setOpen(btn, panel, true);
    }
    // 3. Any deliberate toggle from here on is the preference.
    panel.addEventListener("shown.bs.collapse", () => { store[key] = 1; save(); });
    panel.addEventListener("hidden.bs.collapse", () => { store[key] = 0; save(); });
  });
})();

// Dashboard sections — same idea as the sidebar above, but for the index.php
// widgets (Stripe activity, snapshot, operations, club business, match day).
// Previously there was no way to collapse a section you don't care about, so
// every visit meant scrolling past all of it. Defaults to fully expanded
// (unchanged behaviour) until someone actually collapses one.
(function () {
  const KEY = "hubDashboardSections";
  const sections = document.querySelectorAll(".hub-index-section");
  if (!sections.length) return;

  let store = {};
  try {
    store = JSON.parse(window.localStorage.getItem(KEY) || "{}") || {};
  } catch (error) {
    store = {};
  }
  const save = () => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(store));
    } catch (error) {
      /* storage unavailable (private mode) — preference just won't persist */
    }
  };

  sections.forEach((section) => {
    const header = section.querySelector(".hub-index-section__header");
    const heading = header ? header.querySelector("h2[id]") : null;
    if (!header || !heading) return;

    const key = heading.id;
    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "hub-index-section__toggle";
    toggle.setAttribute("aria-controls", key);
    toggle.innerHTML = '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
    header.appendChild(toggle);

    const setCollapsed = (collapsed) => {
      section.classList.toggle("is-collapsed", collapsed);
      toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
      toggle.setAttribute("aria-label", (collapsed ? "Expand" : "Collapse") + " section: " + heading.textContent.trim());
    };

    setCollapsed(store[key] === 1);

    toggle.addEventListener("click", () => {
      const collapsed = !section.classList.contains("is-collapsed");
      setCollapsed(collapsed);
      store[key] = collapsed ? 1 : 0;
      save();
    });
  });
})();

// Shared click-to-sort table behaviour. Pairs with hub_render_table_head()
// in admin/lib/ui.php (see admin/DESIGN_SYSTEM.md Phase 3) — before this,
// every page that wanted a sortable table (e.g. sponsors.php) reimplemented
// this by hand. Sorts rows client-side by a th's data-sort-key, reading each
// row's matching data-sort-<key> attribute; data-sort-type="number" sorts
// numerically, anything else sorts as text.
window.initHubSortableTable = function (table, options) {
  if (!table) return;
  options = options || {};
  var storageKey = options.storageKey || null;
  var headers = Array.prototype.slice.call(table.querySelectorAll('thead th[data-sort-key]'));
  var tableBody = table.querySelector('tbody');
  if (!headers.length || !tableBody) return;

  function attrName(key) {
    return 'sort' + key.replace(/(^|-)([a-z])/g, function (_, __, letter) {
      return letter.toUpperCase();
    });
  }

  function sortValue(row, key, type) {
    var value = row.dataset[attrName(key)] || '';
    if (type === 'number') {
      var numericValue = parseFloat(value);
      return isNaN(numericValue) ? 0 : numericValue;
    }
    return String(value).toLowerCase();
  }

  function updateHeaders(activeHeader, direction) {
    headers.forEach(function (header) {
      var button = header.querySelector('button');
      var isActive = header === activeHeader;
      header.setAttribute('aria-sort', isActive ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
      if (button) {
        button.setAttribute('aria-label', header.textContent.trim() + (isActive ? ', sorted ' + (direction === 'asc' ? 'ascending' : 'descending') : ', sort column'));
      }
    });
  }

  function applySort(header, direction) {
    var key = header.dataset.sortKey;
    var type = header.dataset.sortType || 'text';
    var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr'));

    rows.sort(function (left, right) {
      var result = type === 'number'
        ? sortValue(left, key, type) - sortValue(right, key, type)
        : sortValue(left, key, type).localeCompare(sortValue(right, key, type), undefined, { numeric: true, sensitivity: 'base' });
      return direction === 'asc' ? result : -result;
    });

    headers.forEach(function (otherHeader) {
      if (otherHeader !== header) delete otherHeader.dataset.sortDirection;
    });
    header.dataset.sortDirection = direction;
    if (storageKey) {
      try {
        sessionStorage.setItem(storageKey, JSON.stringify({ key: key, direction: direction }));
      } catch (error) { /* Browser storage is optional. */ }
    }
    updateHeaders(header, direction);
    rows.forEach(function (row) { tableBody.appendChild(row); });
  }

  headers.forEach(function (header) {
    header.setAttribute('aria-sort', 'none');
    var button = header.querySelector('button');
    if (!button) return;
    button.addEventListener('click', function () {
      var direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
      applySort(header, direction);
    });
  });
  updateHeaders(null, 'asc');

  var remembered = null;
  if (storageKey) {
    try {
      remembered = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    } catch (error) {
      remembered = null;
    }
  }
  if (remembered) {
    var rememberedHeader = headers.find(function (header) {
      return header.dataset.sortKey === remembered.key;
    });
    if (rememberedHeader) {
      applySort(rememberedHeader, remembered.direction);
    }
  }
};

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('table[data-hub-sortable]').forEach(function (table) {
    window.initHubSortableTable(table, { storageKey: table.getAttribute('data-hub-sortable') || null });
  });
});
