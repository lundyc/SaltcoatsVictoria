# GA4 event tracking — reference

Added: 2026-09-18. Covers the custom GA4 events layered onto the public site.
Nothing here changes existing markup behaviour or navigation — every handler
only *listens*; none call `preventDefault()` or `stopPropagation()`, and all
events are routed through a `track()` helper that no-ops if `gtag` isn't
loaded (no `ga_measurement_id` configured) or the visitor hasn't granted
analytics consent (handled by the existing consent-mode wiring in
[partials/head.php](partials/head.php) and [partials/site_footer.php](partials/site_footer.php)).

Implementation lives in one place: [assets/js/public.js](assets/js/public.js),
in a block near the end of the file, clearly separated from the pre-existing
UI code (drawer nav, lightbox, ticket carousel, etc.) it sits alongside.

## Note on scope

The original brief asked for tracking on "code samples" and "component
listings" — this site has neither (it's a football club platform: fixtures,
news, shop, tickets, gallery), so those two categories were mapped onto the
closest real equivalents instead:

| Brief asked for | Adapted to |
|---|---|
| Click events on navigation elements | Primary nav, dropdowns, mobile drawer toggle, utility bar, footer link groups |
| Copy-to-clipboard on code samples | Shop order reference + digital ticket manual code (the two places visitors actually need to copy a value) |
| Filter/search on component listings | Shop category filter bar + search box, news category chips |
| Scroll depth on long pages | Any page whose content exceeds 1.5× the viewport height (auto-detected, not a hardcoded page list) |

## Events

### `nav_click`
Fires on click of any navigation link. `event_category`-style segmentation is
done via `nav_area` rather than separate event names, per GA4 convention of
narrow event vocab + descriptive params.

| Param | Type | Example |
|---|---|---|
| `link_text` | string | `"Fixtures"` |
| `link_url` | string | `/fixtures` |
| `nav_area` | string | `utility_bar` \| `logo` \| `primary_nav` \| `primary_nav_dropdown` \| `footer` \| `footer_legal` |

Sources: [partials/site_nav.php](partials/site_nav.php), [partials/head.php](partials/head.php) (utility bar + brand), [partials/site_footer.php](partials/site_footer.php).

### `nav_menu_toggle`
Fires when a menu is opened/closed rather than navigated to: the mobile
hamburger drawer, and each dropdown's caret button.

| Param | Type | Example |
|---|---|---|
| `menu_name` | string | `mobile_drawer` \| `"Matches"` \| `"Club"` \| ... |
| `action` | string | `open` \| `close` |

### `search`
GA4's own recommended event name for on-site search — reused rather than
inventing a custom one, so it slots into any GA4 reports/explorations built
against the standard `search` event.

| Param | Type | Example |
|---|---|---|
| `search_term` | string | `"home shirt"` |
| `search_area` | string | `shop` |

Fires on submit of the shop search box ([pages/shop_index.php](pages/shop_index.php)), only when the query is non-empty.

### `filter_select`
Fires when a category filter is chosen.

| Param | Type | Example |
|---|---|---|
| `filter_type` | string | `shop_category` \| `news_category` |
| `filter_value` | string | `"Home kit"` \| `"Match reports"` |

Sources: [partials/shop_bar.php](partials/shop_bar.php) (`.shopbar__cats`), [pages/news_index.php](pages/news_index.php) (`.chipnav__item`).

### `copy_to_clipboard`
Fires on click of a "Copy" button next to a value visitors are likely to
need elsewhere (quoting a reference to the club, re-entering a ticket code).
**The copied value itself is never sent to GA4** — only which kind of value
it was, via `content_type`.

| Param | Type | Example |
|---|---|---|
| `content_type` | string | `order_reference` \| `ticket_manual_code` |
| `success` | boolean | `true` if the clipboard write succeeded |

New "Copy" buttons were added in two places (small, additive markup only):
- [pages/shop_order.php](pages/shop_order.php) — the order reference on the confirmation page.
- [pages/ticket_order.php](pages/ticket_order.php) — the manual fallback code on each digital ticket, for when a QR code won't scan.

Styling: `.copybtn` in [assets/css/public.css](assets/css/public.css).

### `scroll_depth`
Fires once per threshold per page load, only on pages tall enough (content
> 1.5× viewport height) for the milestones to be meaningful — e.g. news
articles, club history, honours, policies. Short pages (a single fixture
card, a form) never fire this event, by design.

| Param | Type | Example |
|---|---|---|
| `percent_scrolled` | number | `25` \| `50` \| `75` \| `100` |
| `page_path` | string | `/news/end-of-season-2025-26` |

Note: GA4's built-in Enhanced Measurement already reports a single automatic
`scroll` event at 90% depth — that's unrelated and keeps working unchanged.
`scroll_depth` is additive and gives the four finer-grained milestones the
brief asked for.

## How to verify

1. Open the site with GA4 DebugView enabled (`?gtm_debug=1` isn't used here —
   instead install the [GA Debugger extension](https://chromewebstore.google.com/detail/google-analytics-debugger)
   or check `window.dataLayer` in the browser console) and accept the cookie
   banner (analytics events are queued/dropped until consent is granted).
2. Click through the primary nav, a dropdown, and the mobile drawer toggle —
   confirm `nav_click` / `nav_menu_toggle` in DebugView.
3. Search the shop, then click a category filter and a news category chip —
   confirm `search` and `filter_select`.
4. Complete a shop order or open a ticket order link, click "Copy" — confirm
   `copy_to_clipboard` with `success: true`.
5. Open a long news article or the club history page and scroll to the
   bottom — confirm four `scroll_depth` events at 25/50/75/100.

## Files touched

- `assets/js/public.js` — all event-listener logic (additive block at the end of the file).
- `assets/css/public.css` — `.copybtn` styling.
- `pages/shop_order.php` — added a Copy button next to the order reference.
- `pages/ticket_order.php` — added a Copy button next to each ticket's manual code.

No routing, PHP data flow, or existing DOM structure/classes were changed.
