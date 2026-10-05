(function () {
  const config = window.hubAnalyticsConfig || {};
  if (!config.endpoint || navigator.webdriver) return;

  const startedAt = Date.now();
  let maxScrollDepth = 0;
  let summarySent = false;
  const queue = [];

  const pagePath = () => window.location.pathname || "/";
  const pageTitle = () => (document.title || "").replace(/\s+[-–]\s+.*$/, "").trim();
  const viewport = () => ({
    viewport_width: Math.max(1, Math.round(window.innerWidth || document.documentElement.clientWidth || 0)),
    viewport_height: Math.max(1, Math.round(window.innerHeight || document.documentElement.clientHeight || 0))
  });

  const updateScrollDepth = () => {
    const doc = document.documentElement;
    const body = document.body;
    const scrollTop = window.scrollY || doc.scrollTop || body.scrollTop || 0;
    const scrollable = Math.max(1, Math.max(doc.scrollHeight, body.scrollHeight) - window.innerHeight);
    maxScrollDepth = Math.max(maxScrollDepth, Math.min(100, Math.round((scrollTop / scrollable) * 100)));
  };

  const selectorFor = element => {
    if (!element || element === document.body) return "";
    if (element.id) return "#" + CSS.escape(element.id);
    const dataLabel = element.getAttribute("data-analytics-label") || element.getAttribute("aria-label");
    const name = element.getAttribute("name");
    let selector = element.tagName.toLowerCase();
    if (name) selector += '[name="' + name.replace(/"/g, "") + '"]';
    if (dataLabel) selector += '[aria-label="' + dataLabel.replace(/"/g, "").slice(0, 50) + '"]';
    return selector;
  };

  const labelFor = element => {
    const labelled = element.closest("[data-analytics-label]");
    if (labelled) return (labelled.getAttribute("data-analytics-label") || "").trim();
    return (
      element.getAttribute("aria-label") ||
      element.getAttribute("title") ||
      element.textContent ||
      element.value ||
      element.name ||
      ""
    ).replace(/\s+/g, " ").trim().slice(0, 190);
  };

  const send = event => {
    queue.push(Object.assign({
      page_path: pagePath(),
      page_title: pageTitle(),
      referrer: document.referrer || "",
      scroll_depth: maxScrollDepth
    }, viewport(), event));
    flush(false);
  };

  const flush = useBeacon => {
    if (!queue.length) return;
    const events = queue.splice(0, queue.length);
    const body = JSON.stringify({ events });
    if (useBeacon && navigator.sendBeacon) {
      const blob = new Blob([body], { type: "application/json" });
      navigator.sendBeacon(config.endpoint, blob);
      return;
    }
    fetch(config.endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      keepalive: true,
      body
    }).catch(() => {});
  };

  const sendSummary = () => {
    if (summarySent) return;
    summarySent = true;
    updateScrollDepth();
    send({
      event_type: "page_summary",
      duration_ms: Math.max(0, Date.now() - startedAt),
      scroll_depth: maxScrollDepth
    });
    flush(true);
  };

  document.addEventListener("click", event => {
    updateScrollDepth();
    const target = event.target.closest("a, button, [role='button'], input[type='submit'], input[type='button']");
    if (!target) return;
    send({
      event_type: "click",
      feature_label: labelFor(target),
      element_selector: selectorFor(target),
      element_text: (target.textContent || target.value || "").replace(/\s+/g, " ").trim().slice(0, 190),
      target_href: target.href || target.formAction || ""
    });
  }, true);

  document.addEventListener("submit", event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    send({
      event_type: "form_submit",
      feature_label: form.getAttribute("data-analytics-label") || form.getAttribute("aria-label") || form.id || "Form submit",
      element_selector: selectorFor(form),
      target_href: form.action || ""
    });
  }, true);

  document.addEventListener("scroll", updateScrollDepth, { passive: true });
  window.addEventListener("resize", updateScrollDepth, { passive: true });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") sendSummary();
  });
  window.addEventListener("pagehide", sendSummary);

  updateScrollDepth();
  send({ event_type: "page_view" });
  setInterval(() => flush(false), 8000);
})();
