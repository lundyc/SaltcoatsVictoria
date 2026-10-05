/* Public site — progressive enhancement only. The site is fully usable with
   this file absent. */
(function () {
  "use strict";

  var doc = document;

  /* Sticky header condense ---------------------------------------------------*/
  if (doc.querySelector(".site-header")) {
    /* Hysteresis (different on/off thresholds) stops the class flapping when
       scrollY hovers near a single value — e.g. while momentum-scrolling on
       mobile, or when the browser's address bar hide/show nudges the
       viewport, which otherwise made the header repeatedly grow/shrink
       ("shake") right at the boundary. rAF-throttled so we test at most
       once per frame instead of on every scroll event. */
    var stuck = false;
    var ticking = false;
    var applyScroll = function () {
      ticking = false;
      var y = window.scrollY;
      if (!stuck && y > 40) {
        stuck = true;
        doc.body.classList.add("is-stuck");
      } else if (stuck && y < 10) {
        stuck = false;
        doc.body.classList.remove("is-stuck");
      }
    };
    var onScroll = function () {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(applyScroll);
      }
    };
    applyScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* Primary navigation --------------------------------------------------- */
  var toggle = doc.querySelector(".nav-toggle");
  var nav = doc.querySelector(".primary-nav");
  var scrim = doc.querySelector(".nav-scrim");
  var navClose = doc.querySelector(".primary-nav__close");
  var deskMq = window.matchMedia("(min-width: 1025px)");
  var scrimTimer;

  var closePanels = function () {
    Array.prototype.forEach.call(doc.querySelectorAll(".primary-nav .subnav.is-open"), function (sub) {
      sub.classList.remove("is-open");
      var b = sub.parentElement.querySelector(".nav-item__toggle");
      if (b) b.setAttribute("aria-expanded", "false");
    });
  };

  var openDrawer = function () {
    if (scrim) { clearTimeout(scrimTimer); scrim.hidden = false; }
    requestAnimationFrame(function () {
      nav.classList.add("is-open");
      doc.body.classList.add("nav-open");
    });
    if (toggle) toggle.setAttribute("aria-expanded", "true");
  };

  var closeDrawer = function () {
    nav.classList.remove("is-open");
    doc.body.classList.remove("nav-open");
    if (toggle) toggle.setAttribute("aria-expanded", "false");
    if (scrim) scrimTimer = setTimeout(function () { scrim.hidden = true; }, 300);
    closePanels();
  };

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      if (nav.classList.contains("is-open")) closeDrawer(); else openDrawer();
    });
    if (navClose) navClose.addEventListener("click", closeDrawer);
    if (scrim) scrim.addEventListener("click", closeDrawer);

    /* tapping a real destination link closes the drawer */
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a") && nav.classList.contains("is-open")) closeDrawer();
    });

    /* per-item sub-panel toggles — work in both layouts (desktop also has :hover) */
    Array.prototype.forEach.call(doc.querySelectorAll(".nav-item__toggle"), function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var item = btn.closest(".nav-item");
        var sub = item && item.querySelector(".subnav");
        if (!sub) return;
        var willOpen = !sub.classList.contains("is-open");
        if (willOpen && deskMq.matches) closePanels();
        sub.classList.toggle("is-open", willOpen);
        btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
      });
    });

    /* desktop: click outside any nav item closes open panels */
    doc.addEventListener("click", function (e) {
      if (deskMq.matches && !e.target.closest(".nav-item")) closePanels();
    });

    /* Escape closes the drawer (mobile) or open panels (desktop) */
    doc.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      if (nav.classList.contains("is-open")) { closeDrawer(); if (toggle) toggle.focus(); }
      else closePanels();
    });

    /* reset drawer state when the viewport grows past the breakpoint */
    var onBpChange = function (ev) {
      if (!ev.matches) return;
      nav.classList.remove("is-open");
      doc.body.classList.remove("nav-open");
      if (toggle) toggle.setAttribute("aria-expanded", "false");
      if (scrim) scrim.hidden = true;
      closePanels();
    };
    if (deskMq.addEventListener) deskMq.addEventListener("change", onBpChange);
    else if (deskMq.addListener) deskMq.addListener(onBpChange);
  }

  /* Quantity steppers (shop product + basket + tickets) ------------------- */
  Array.prototype.forEach.call(doc.querySelectorAll("[data-stepper]"), function (stepper) {
    var input = stepper.querySelector("input");
    if (!input) return;
    Array.prototype.forEach.call(stepper.querySelectorAll("button[data-step]"), function (btn) {
      btn.addEventListener("click", function () {
        var min = parseInt(input.getAttribute("min") || "0", 10);
        var max = parseInt(input.getAttribute("max") || "999", 10);
        var v = parseInt(input.value || "0", 10) + parseInt(btn.getAttribute("data-step"), 10);
        input.value = Math.max(min, Math.min(max, isNaN(v) ? min : v));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
  });

  /* Image lightbox — photo albums + news-article galleries -------------- */
  var lbGrid = doc.querySelector("[data-lightbox]");
  var lightbox = doc.getElementById("lightbox");
  if (lbGrid && lightbox) {
    var lbImg = lightbox.querySelector(".lightbox__img");
    var lbCount = lightbox.querySelector(".lightbox__count");
    var lbCap = lightbox.querySelector(".lightbox__caption");
    var lbThumbsWrap = lightbox.querySelector(".lightbox__thumbs");
    var lbStage = lightbox.querySelector(".lightbox__stage");
    var items = Array.prototype.slice.call(lbGrid.querySelectorAll("[data-full]"));
    var cur = 0;
    var thumbEls = [];

    if (items.length < 2) lightbox.classList.add("lightbox--single");

    items.forEach(function (it, i) {
      var innerImg = it.querySelector("img");
      var t = doc.createElement("button");
      t.type = "button";
      t.className = "lightbox__thumb";
      t.setAttribute("aria-label", "Show image " + (i + 1));
      var tImg = doc.createElement("img");
      tImg.src = innerImg ? innerImg.getAttribute("src") : it.getAttribute("data-full");
      tImg.alt = "";
      t.appendChild(tImg);
      t.addEventListener("click", function () { show(i); });
      if (lbThumbsWrap) lbThumbsWrap.appendChild(t);
      thumbEls.push(t);
    });

    var show = function (i) {
      cur = (i + items.length) % items.length;
      lbImg.src = items[cur].getAttribute("data-full");
      var cap = items[cur].getAttribute("data-caption") || "";
      if (lbCap) { lbCap.textContent = cap; lbCap.hidden = cap === ""; }
      if (lbCount) lbCount.textContent = (cur + 1) + " / " + items.length;
      thumbEls.forEach(function (t, k) { t.classList.toggle("is-active", k === cur); });
      var act = thumbEls[cur];
      if (act && act.scrollIntoView) act.scrollIntoView({ inline: "center", block: "nearest" });
    };
    var open = function (i) {
      show(i);
      lightbox.hidden = false;
      doc.body.classList.add("nav-open");
      var c = lightbox.querySelector(".lightbox__close");
      if (c) c.focus();
    };
    var close = function () {
      lightbox.hidden = true;
      lbImg.src = "";
      doc.body.classList.remove("nav-open");
    };

    lbGrid.addEventListener("click", function (e) {
      var a = e.target.closest("[data-full]");
      if (!a) return;
      e.preventDefault();
      open(items.indexOf(a));
    });
    lightbox.addEventListener("click", function (e) {
      if (e.target.closest(".lightbox__nav--next")) show(cur + 1);
      else if (e.target.closest(".lightbox__nav--prev")) show(cur - 1);
      else if (e.target.closest(".lightbox__thumb")) { /* handled per-thumb */ }
      else if (e.target === lightbox || e.target === lbStage || e.target.closest(".lightbox__close")) close();
    });
    doc.addEventListener("keydown", function (e) {
      if (lightbox.hidden) return;
      if (e.key === "Escape") close();
      else if (e.key === "ArrowRight") show(cur + 1);
      else if (e.key === "ArrowLeft") show(cur - 1);
    });
  }

  /* Match-ticket order summary ------------------------------------------- */
  var tktForm = doc.querySelector("[data-ticket-form]");
  if (tktForm) {
    var tktItems = tktForm.querySelector(".js-tkt-items");
    var tktTotalEl = tktForm.querySelector(".js-tkt-total");
    var tktBtn = tktForm.querySelector(".js-tkt-submit");
    var tktLines = Array.prototype.slice.call(tktForm.querySelectorAll(".tkt-line"));
    var money = function (n) { return "£" + n.toFixed(2); };

    var tktRender = function () {
      var total = 0, count = 0, rows = "";
      tktLines.forEach(function (line) {
        var input = line.querySelector(".tkt-qty");
        if (!input) return;
        var qty = parseInt(input.value || "0", 10);
        if (isNaN(qty) || qty < 0) qty = 0;
        var price = parseFloat(line.getAttribute("data-price") || "0");
        if (qty > 0) {
          total += qty * price;
          count += qty;
          var name = line.getAttribute("data-name") || "Ticket";
          rows += '<div class="tkt-si"><span><b>' + qty + '×</b>' +
                  name.replace(/[&<>"]/g, function (c) {
                    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
                  }) +
                  '</span><span>' + money(qty * price) + "</span></div>";
        }
      });
      if (tktItems) {
        tktItems.innerHTML = rows ||
          '<p class="tkt-summary__empty">No tickets selected yet.</p>';
      }
      if (tktTotalEl) tktTotalEl.textContent = money(total);
      if (tktBtn) {
        tktBtn.disabled = count === 0;
        tktBtn.textContent = count === 0
          ? "Continue to payment"
          : "Continue to payment · " + money(total);
      }
    };

    tktForm.addEventListener("input", function (e) {
      if (e.target.classList.contains("tkt-qty")) tktRender();
    });
    tktForm.addEventListener("change", function (e) {
      if (e.target.classList.contains("tkt-qty")) tktRender();
    });
    tktRender();
  }

  /* Policy tabs — show one document at a time, deep-linkable via #slug ---- */
  var policyTabs = doc.querySelector("[data-policytabs]");
  if (policyTabs) {
    var pTabs = Array.prototype.slice.call(policyTabs.querySelectorAll(".policytabs__tab"));
    var pPanels = Array.prototype.slice.call(policyTabs.querySelectorAll(".policydoc"));
    var pIds = pPanels.map(function (panel) { return panel.id; });

    var pActivate = function (id, moveFocus) {
      if (pIds.indexOf(id) === -1) id = pIds[0];
      if (!id) return;
      pPanels.forEach(function (panel) { panel.hidden = panel.id !== id; });
      pTabs.forEach(function (tab) {
        var on = tab.getAttribute("href") === "#" + id;
        tab.classList.toggle("is-active", on);
        if (on) tab.setAttribute("aria-current", "page");
        else tab.removeAttribute("aria-current");
      });
      if (moveFocus) {
        var active = doc.getElementById(id);
        if (active) {
          active.setAttribute("tabindex", "-1");
          active.focus({ preventScroll: true });
        }
      }
    };

    pTabs.forEach(function (tab) {
      tab.addEventListener("click", function (e) {
        e.preventDefault();
        var id = tab.getAttribute("href").slice(1);
        if (window.history && history.pushState) history.pushState(null, "", "#" + id);
        else location.hash = id;
        pActivate(id, true);
        if (window.matchMedia("(max-width: 820px)").matches) {
          policyTabs.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      });
    });

    var pFromHash = function () { pActivate((location.hash || "").replace("#", ""), false); };
    window.addEventListener("hashchange", pFromHash);
    window.addEventListener("popstate", pFromHash);
    pFromHash();
  }

  /* Product image gallery ------------------------------------------------- */
  var galleryMain = doc.querySelector("[data-gallery-main]");
  if (galleryMain) {
    Array.prototype.forEach.call(doc.querySelectorAll("[data-gallery-thumb]"), function (thumb) {
      thumb.addEventListener("click", function () {
        galleryMain.style.backgroundImage = "url('" + thumb.dataset.src + "')";
        galleryMain.classList.remove("is-placeholder");
        Array.prototype.forEach.call(doc.querySelectorAll("[data-gallery-thumb]"), function (t) {
          t.classList.remove("is-active");
        });
        thumb.classList.add("is-active");
      });
    });
  }

  /* ------------------------------------------------------------------------
   * GA4 event tracking (additive — see ANALYTICS_EVENTS.md at the repo root
   * for the full reference: event names, params and where each fires).
   * Every call is routed through track() so it's a no-op when gtag isn't
   * loaded (no ga_measurement_id set) or analytics consent hasn't been
   * granted (gtag itself queues/drops events per the consent state set in
   * head.php / site_footer.php). Nothing here changes existing behaviour —
   * it only listens; it never calls preventDefault() or stopPropagation().
   * ------------------------------------------------------------------------ */
  var track = function (name, params) {
    if (typeof window.gtag === "function") {
      window.gtag("event", name, params || {});
    }
  };

  var linkText = function (el) {
    return (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 100);
  };

  /* Navigation clicks — utility bar, brand/logo, primary nav + dropdowns,
     mobile toggles, footer link groups. */
  (function () {
    var navGroups = [
      [".utility-bar__links a", "utility_bar"],
      [".masthead .brand", "logo"],
      [".primary-nav__list > .nav-item > .nav-item__link", "primary_nav"],
      [".primary-nav .subnav__link", "primary_nav_dropdown"],
      [".site-footer__cols nav a", "footer"],
      [".site-footer__legal a", "footer_legal"]
    ];
    navGroups.forEach(function (group) {
      Array.prototype.forEach.call(doc.querySelectorAll(group[0]), function (link) {
        link.addEventListener("click", function () {
          track("nav_click", {
            link_text: linkText(link),
            link_url: link.getAttribute("href") || "",
            nav_area: group[1]
          });
        });
      });
    });

    var mobileToggle = doc.querySelector(".nav-toggle");
    if (mobileToggle) {
      mobileToggle.addEventListener("click", function () {
        var opening = mobileToggle.getAttribute("aria-expanded") !== "true";
        track("nav_menu_toggle", { menu_name: "mobile_drawer", action: opening ? "open" : "close" });
      });
    }
    Array.prototype.forEach.call(doc.querySelectorAll(".nav-item__toggle"), function (btn) {
      btn.addEventListener("click", function () {
        var item = btn.closest(".nav-item");
        var label = item ? linkText(item.querySelector(".nav-item__link")) : "";
        var opening = btn.getAttribute("aria-expanded") !== "true";
        track("nav_menu_toggle", { menu_name: label || "submenu", action: opening ? "open" : "close" });
      });
    });
  })();

  /* Search — shop search box ("search" is a GA4-recommended event name). */
  (function () {
    var shopSearchForm = doc.querySelector(".shopsearch");
    if (!shopSearchForm) return;
    shopSearchForm.addEventListener("submit", function () {
      var q = shopSearchForm.querySelector('input[name="q"]');
      var term = q ? q.value.trim() : "";
      if (term !== "") track("search", { search_term: term, search_area: "shop" });
    });
  })();

  /* Filter interactions — shop category bar + news category chips. */
  (function () {
    Array.prototype.forEach.call(doc.querySelectorAll(".shopbar__cats a"), function (link) {
      link.addEventListener("click", function () {
        track("filter_select", { filter_type: "shop_category", filter_value: linkText(link) });
      });
    });
    Array.prototype.forEach.call(doc.querySelectorAll(".chipnav__item"), function (link) {
      link.addEventListener("click", function () {
        track("filter_select", { filter_type: "news_category", filter_value: linkText(link) });
      });
    });
  })();

  /* Copy-to-clipboard — order reference / ticket manual code buttons.
     data-copy holds the value to copy; data-copy-type is the event label
     only (the value itself is never sent to GA4). */
  (function () {
    Array.prototype.forEach.call(doc.querySelectorAll("[data-copy]"), function (btn) {
      var original = btn.textContent;
      var resetTimer;
      btn.addEventListener("click", function () {
        var value = btn.getAttribute("data-copy") || "";
        var done = function (ok) {
          track("copy_to_clipboard", { content_type: btn.getAttribute("data-copy-type") || "unknown", success: ok });
          if (!ok) return;
          btn.classList.add("is-copied");
          btn.textContent = "Copied";
          clearTimeout(resetTimer);
          resetTimer = setTimeout(function () {
            btn.classList.remove("is-copied");
            btn.textContent = original;
          }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value).then(function () { done(true); }, function () { done(false); });
          return;
        }
        var ta = doc.createElement("textarea");
        ta.value = value;
        ta.style.position = "fixed";
        ta.style.opacity = "0";
        doc.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = doc.execCommand("copy"); } catch (e) { ok = false; }
        doc.body.removeChild(ta);
        done(ok);
      });
    });
  })();

  /* Scroll depth — 25/50/75/100%, fired once each per page, and only on
     pages long enough that reaching each milestone is meaningful (content
     taller than 1.5x the viewport). */
  (function () {
    var doc2 = doc.documentElement;
    var pageHeight = Math.max(doc2.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
    if (pageHeight < window.innerHeight * 1.5) return;

    var thresholds = [25, 50, 75, 100];
    var fired = {};
    var ticking = false;

    var check = function () {
      ticking = false;
      var scrolled = window.scrollY + window.innerHeight;
      var pct = Math.min(100, Math.round((scrolled / pageHeight) * 100));
      thresholds.forEach(function (t) {
        if (pct >= t && !fired[t]) {
          fired[t] = true;
          track("scroll_depth", { percent_scrolled: t, page_path: location.pathname });
        }
      });
      if (fired[100]) window.removeEventListener("scroll", onScroll2);
    };
    var onScroll2 = function () {
      if (!ticking) { ticking = true; requestAnimationFrame(check); }
    };
    window.addEventListener("scroll", onScroll2, { passive: true });
  })();
})();
