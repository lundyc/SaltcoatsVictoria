# Admin Panel Design System — Plan & Status

**Status (2026-09-20): all phases of this plan are complete.** Phases 0, 1,
3, 4 and 5's governance note are implemented. Phase 2: all identified
bare-Bootstrap tables use `hub-data-table`; all `btn-maroon` usages renamed
to `btn-brand`; `table-modern` retired (renamed to
`hub-data-table--rounded`); `reports.php`'s primary filter button uses
`btn-brand`; a shared windowed `hub_render_pagination()` fixed a real
defect in `developer_sponsorships.php`; a shared
`hub_render_quick_add_modal()` was extracted for `match.php`'s duplicate
modals; the Phase 1 token scale has been adopted into every existing
`font-size`, `border-radius`, `margin`/`padding`/`gap` declaration across
all 50 CSS files (see the dedicated section near the bottom) — **including
a real duplicate-token bug this introduced and then caught and fixed
before it shipped**, detailed there.

Two items are intentionally still open, not because they're incomplete but
because finishing them would mean *guessing* rather than *knowing*:
- `reports.php`'s `rpt-btn--ghost` — a deliberately different visual
  treatment from `.btn-neutral`, not drift (see the Phase 2 log).
- Multi-value shorthand `margin`/`padding`/`gap` declarations (e.g.
  `padding: 0.5rem 1rem;`) and multi-corner `border-radius` — excluded
  from tokenization because splitting and tokenizing each value inside a
  shorthand property is meaningfully more error-prone than the single-value
  case, for a design-only payoff. See the token-adoption section for exact
  counts of what was and wasn't touched.

Empty-state "unification" and the "settings.php has 25 modals" premise for
modal extraction both turned out to be wrong on inspection — see the
corrections in the Phase 2 log below. Everything in this document has been
visually verified in the browser, including a targeted re-check after the
duplicate-token bug fix.

Scope: the Bootstrap-based admin panel — `admin/header.php`, `admin/footer.php`, the
~337 page-level PHP files, and `admin/assets/css/*.css`.

`admin/app/` is a separate, Tailwind-based layout living inside the same `admin/`
folder (its own color theme, own routes/controllers). It is **explicitly out of
scope** for this plan — decide separately whether it's deprecated, a future
migration target, or intentionally independent.

## Why this doc exists

An audit (2026-09-19) found the admin panel already has the bones of a design
system — `--brand-*` CSS variables, a `hub-data-table` convention, a
`hub_render_page_hero()` PHP function — but it's inconsistently applied:
duplicate button classes, ~50 page-specific CSS files that mostly hardcode
brand hex colors instead of referencing the shared variables, no real
type/spacing scale, and no shared PHP components below the page-hero level.

The plan is not "start over." It's: consolidate what already half-exists,
retire the duplicate variants, and add the missing layer (a real token scale
+ a handful of PHP component functions) so new pages inherit consistency
instead of reinventing it.

---

## Phase 0 — Freeze & audit sign-off

- This doc *is* the frozen record of the audit findings and decisions.
- Open decisions to resolve before Phase 1 work starts:
  - Fate of the `--compact-*` token set (`style.css:482-486`) — near-duplicate
    of `--brand-*`. Proposal: remap its handful of consumers to the
    equivalent `--brand-*` token and delete `--compact-*`.
  - The `var(--bs-primary, #6f1237)` fallback in
    `player-sponsors-match-overview-pane.css:138,166` — the fallback hex
    doesn't match the real brand primary (`#6a2036`). Fix the fallback value.
  - Per-case triage of near-duplicate maroons/reds found in the audit
    (`#672139`, `#68172b`, `#6e1530`, `#501329`, `#4b0d24`, `#661120`,
    `#8f1f26`, `#a3313c`, `#a23e35`, `#a43c33`, …): each one is either an
    intentional shade (promote to a named token, e.g.
    `--brand-primary-hover`) or drift (replace with the real token).

## Phase 1 — Tokens

Define once in `style.css` `:root`. Nothing new invented — formalizing what's
already there plus filling real gaps.

**Color** — keep the existing `--brand-primary/dark/darkest/accent/light/
success/danger/warning` set as canonical. Resolve the Phase 0 color
decisions above.

**Type scale** — collapse the ~136 ad hoc font-size values found in the audit
down to a fixed scale, e.g.:

```
--fs-xs    /* table cell / meta text */
--fs-sm    /* labels, secondary text */
--fs-base  /* body */
--fs-md    /* card titles */
--fs-lg    /* section headings */
--fs-xl    /* page titles */
--fs-2xl   /* hero / dashboard numbers */
```

Collapse font-weights to only what Inter actually loads (400/500/600/700/800)
— drop 650/750/820/850, which silently snap to a neighboring weight in the
browser since no such font file is loaded.

**Spacing scale** — replace the dozens of near-identical rem values with a
4px/8px-based scale:

```
--space-1  (4px)   --space-2  (8px)   --space-3  (12px)
--space-4  (16px)  --space-5  (20px)  --space-6  (24px)
--space-7  (28px)  --space-8  (32px)
```

Map existing values to the nearest step during migration rather than
preserving every pixel exactly.

**Radius** — standardize on px (already the majority convention):
`--radius-sm` (4-5px), `--radius-md` (6-8px), `--radius-lg` (~12px),
`--radius-pill` (999px).

**Layout** — document `--sidebar-width` (pull the real value out of the
`.navbar` / `#hubSideNavigation` rules; currently not a variable) and a
content max-width token if one should exist.

## Phase 2 — Component consolidation (CSS class layer)

Pick one canonical class per component; deprecate the rest.

- **Buttons** — canonical: `btn-brand` (already dominant, 205 uses). Alias
  `btn-primary` / `btn-maroon` to it during migration, then sweep pages off
  the old classes. Fold `reports.php`'s bespoke `rpt-btn--*` BEM set into the
  shared system.
- **Tables** — canonical: `hub-data-table` / `hub-data-table--responsive`
  (51 files already). Migrate the 28 files still on bare Bootstrap `table`.
  Retire the 3-file `table-modern` variant.
- **Badges / alerts** — already consistent (Bootstrap `text-bg-*`,
  `alert-*`). Document as "use these, don't invent new ones." No structural
  change needed.
- **Empty states** — ~~`.hub-empty-state` used in 37 files but with two
  different wrapper shapes (bare vs. `alert alert-info hub-empty-state`).
  Pick one.~~ **Correction (2026-09-19), after checking both in the
  browser**: these are two deliberate, different components, not drift. The
  bare `<div class="hub-empty-state">` (the ~35-file majority) is a
  polished, centered "nothing here yet" block with icon support and
  generous padding (`style.css:7174-7188`) — used for a genuinely empty
  page/section (e.g. `committee_meetings.php`'s "No meetings recorded
  yet"). The `alert alert-info mb-0 hub-empty-state` variant is a smaller
  inline notice for "no rows match this filter" within an otherwise
  populated list (e.g. `matches.php`). Don't merge them.
- **Modals** — ~~keep native Bootstrap modal markup. Extract genuinely
  repeated shapes (confirm-style, form-in-modal) into documented markup
  snippets; only build a PHP partial if duplication is high enough to
  justify it (check how many of `settings.php`'s 25 modals are actually
  distinct shapes vs. copy-paste).~~ **Correction (2026-09-20)**: the
  "settings.php has 25 modals" figure from the original audit was wrong —
  it actually has 3, all distinct. Recounted every file properly (see the
  Phase 2 log below): `match.php` is the real concentration, with 7, two of
  which (Add Competition / Add Venue) are genuine near-duplicates. Extracted
  those into a shared `hub_render_quick_add_modal()`.
- **Pagination** — only 4 files, each different. Standardize on one pattern
  (probably the JS-driven approach `matches.php` already uses).
- **Loading states** — no shared spinner. Standardize on Bootstrap's
  `spinner-border` for inline use. Leave the bespoke PDF-import
  loading-steps wizard UI alone — it's a different kind of component
  (progress indicator, not a generic spinner), not drift.

## Phase 3 — PHP component layer

`hub_render_page_hero()` (`header.php`) is the one place this codebase
already does "component as a function." Use it as the template for a new
`admin/lib/ui.php`:

- `hub_render_table_head($columns, $sortKey)` — every page currently
  hand-rolls sortable headers (`sponsors.php` reimplements this from
  scratch). A shared function + shared JS module removes the largest
  duplicated-logic item found in the audit (no shared table/sort/filter
  library exists anywhere in the panel).
- `hub_render_empty_state($message, $icon)`
- `hub_render_badge($status)` — for pages with bespoke status-badge logic.
- `hub_render_breadcrumbs($trail)` — breadcrumbs are hand-written in 58
  files today; match the page-hero pattern.

Stop at "function that echoes markup" — not a templating framework. Matches
the codebase's existing style and keeps migration low-risk.

## Phase 4 — Icons & cleanup

- Normalize the 4 `developer_*.php` files off legacy FA5/6 syntax (`fas`,
  `far`) onto FA7 (`fa-solid`, `fa-regular`). Small, mechanical, low risk.
- Delete dead/commented CSS found along the way (e.g. the commented-out
  gradient at `style.css:950`).

## Phase 5 — Governance

- Short "how to style a new admin page" note (top of `style.css` or here):
  which button/table/badge classes to use, where tokens live, and an
  explicit rule — don't hardcode hex, use `var(--brand-*)` — since raw hex
  outside `style.css` was the single largest source of drift found in the
  audit.
- Optional: a lint/grep check (e.g. a pre-commit grep for raw brand-adjacent
  hex values outside `style.css`) to catch new hardcoded colors before they
  multiply.

---

## Suggested order of attack

1. **Tokens** (Phase 1) — everything else references these; do it first.
2. **Buttons + tables** (Phase 2's two biggest items) — highest page-count
   impact.
3. **PHP component layer** (Phase 3) — biggest long-term payoff (kills the
   reimplemented sortable-table logic).
4. Everything else (empty states, pagination, icons, cleanup)
   opportunistically, page-by-page, rather than a big-bang sweep across 337
   files.

---

## Reference: audit findings (2026-09-19)

Kept here for traceability — the numbers behind the decisions above.

- **Buttons**: `btn-brand` (205 uses), `btn-primary` (40), `btn-maroon` (17),
  `btn-neutral` (21, "quiet" variant), plus `reports.php`'s own
  `rpt-btn` / `rpt-btn--primary` / `rpt-btn--ghost` used nowhere else.
- **Tables**: `hub-data-table` in 51 files; 28 files still use plain
  `table`; `table-modern` orphaned in 3 files.
- **Badges**: `text-bg-light` (34), `text-bg-secondary` (15),
  `text-bg-warning` (12), `text-bg-success` (11), `text-bg-danger` (4),
  `text-bg-primary` (3) — low inconsistency.
- **Color**: only 10 of 50 CSS files reference `var(--brand-primary)` at
  all; `#6a2036` is hardcoded directly in 13 files. Sampled files range from
  66 hex / 0 var (`people.css`) to 17 hex / 17 var (`index.css`, one of the
  better-behaved newer files).
- **Typography**: 45 distinct font-size values in `style.css` alone, 136
  across all admin CSS. Font-weights include 750 (39 uses), 850 (11), 650
  (8), 820 (1) — none of which correspond to a loaded Inter weight
  (400/500/600/700/800 are the only ones fetched).
- **Spacing**: no scale — dozens of near-identical rem values
  (0.35rem/0.4rem/0.45rem/0.5rem/0.55rem/0.6rem/0.62rem/0.65rem/0.7rem...).
- **Radius**: mixes px and rem in the same file — `999px`, `6px`, `5px`,
  `4px`, `8px`, `3px` alongside `0.9rem`, `0.85rem`, `1rem`, `0.75rem`,
  `0.65rem`, `0.72rem`, `0.7rem`, `0.8rem`, `1.15rem`.
- **JS libraries**: jQuery 3.7.1, Bootstrap 5.3.3 bundle, and a custom
  `assets/js/app.js` (`hubToast()`, `hubConfirm()`) form the shared layer.
  Per-page extras: Chart.js, html2canvas, Cropper.js, TinyMCE, EasyMDE. No
  DataTables/select2/Sortable.js/flatpickr anywhere — every page that needs
  sortable/filterable tables reimplements it by hand
  (e.g. `sponsors.php:683`).
- **Icons**: Font Awesome 7.0.1, mostly modern syntax (`fa-solid` 731,
  `fa-brands` 38, `fa-regular` 36); legacy `fas`/`far` syntax lingers only
  in `developer.php`, `developer_sponsorships.php`, `developer_db.php`,
  `developer_errors.php`.
- **Reusable includes**: `admin/header.php` / `admin/footer.php` are the
  real shared shell (page-hero renderer, nav, mobile nav, shared confirm
  modal, toast region). `admin/partials/*.php` holds only 5 files, all
  page-specific data fragments — no generic component partials
  (button/card/table/badge) exist yet.

---

## Implemented so far (2026-09-19)

- **Phase 0 decisions resolved**:
  - `--compact-maroon` (`table-card--compact`, `style.css`) now points at
    `var(--brand-dark)` instead of re-typing its hex literal — same value,
    single source of truth. `--compact-burgundy/amber/cream` were left as-is:
    they're scoped to `.table-card--compact` only (a deliberate "premium
    broadcast surface" tint set, not a global token collision) and don't
    duplicate any `--brand-*` value exactly.
  - Fixed the incorrect CSS fallback in
    `player-sponsors-match-overview-pane.css` — `var(--bs-primary, #6f1237)`
    → `var(--bs-primary, #6a2036)` (the real brand primary). `--bs-primary`
    is always set in practice (the page loads `header.php`), so this was a
    latent-only bug, not a live visual one.
  - Full triage of every near-duplicate hex from the audit was **not** done
    — most are gradient stops or hover-state shades in specific components
    where changing the color needs a visual check, not a blind find/replace.
    Left as a follow-up to do per-file, when each file is next touched.
- **Phase 1 tokens** added to `style.css` `:root`: `--fs-xs` through
  `--fs-2xl`, `--fw-regular` through `--fw-heavy` (only the 5 weights Inter
  actually loads), `--space-1` through `--space-8` (4px steps),
  `--radius-sm/md/lg/pill`, and `--sidebar-width` (280px — confirmed against
  the real value and wired into the 4 places that previously hardcoded
  `280px` for the sidebar/main-content layout). The type/spacing/radius
  scales are declared but not yet adopted by existing rules — that's Phase 2
  migration work, done gradually.
- **Phase 3 PHP component layer**: new `admin/lib/ui.php` (required from
  `header.php`, so available on every page automatically):
  - `hub_render_breadcrumbs($trail)`
  - `hub_render_empty_state($message, $icon)` — standardized on the
    alert-wrapped shape (`alert alert-info hub-empty-state`).
  - `hub_render_badge($label, $tone)`
  - `hub_render_table_head($columns)` — sortable `<thead>` generator, pairs
    with a new shared `window.initHubSortableTable()` in `assets/js/app.js`
    and `.hub-sort-button`/`.hub-sort-icon` CSS in `style.css`. Generalized
    from the sort logic `sponsors.php` built by hand for its own ledger
    table — `sponsors.php` itself hasn't been migrated onto the shared
    version yet (no behavior change to it), but any new sortable table can
    use `hub_render_table_head()` + `data-hub-sortable` on the `<table>`
    instead of reimplementing sorting.
  - None of these are wired into any existing page yet — they're additive
    and unused until a page opts in, so this carried zero regression risk.
- **Phase 4**: legacy Font Awesome 5/6 class prefixes (`fas`, `far`)
  converted to FA7 syntax (`fa-solid`, `fa-regular`) in the 4 flagged
  `developer_*.php` files. Icon names unchanged, prefixes only.
- **Phase 5 governance note** added at the top of `style.css`.
- **Phase 2 — tables**: 25 files / 49 `<table>` elements migrated onto
  `hub-data-table`:
  - 16 files / 27 tables already had `mb-0` — adding `hub-data-table` is a
    genuine no-op (it only sets `margin: 0`, which was already true).
    `assign_sponsors.php`, `developer_audit.php`, `fixture_starting_11.php`,
    `fixture_tickets.php`, `league_table_history.php`,
    `match_report_import.php`, `matchday_finance_edit.php`, `photo_albums.php`,
    `players_leave.php`, `playersponsors_orders.php`, `shop_modifiers.php`,
    `shop_order.php`, `shop_overview.php`, `stats.php` (11 tables),
    `ticket_orders.php` (1 of 2), `ticket_packages.php`.
  - `developer_analytics.php` (13 tables) and `match.php` (2 tables) also had
    no visual change — their `analytics-table`/`fixture-sponsorship-table`
    classes already set `margin: 0` / `margin-bottom: 0` via their own
    page CSS, so `hub-data-table` was redundant with what was already there.
  - 9 files / 9 tables had no margin control at all (default Bootstrap
    1rem bottom margin) — added both `mb-0` and `hub-data-table`, a small,
    deliberate spacing tightening to match the convention nearly every
    other table already follows: `developer_db.php`,
    `developer_errors.php`, `developer_sponsorships.php`,
    `matchday_stats.php`, `player_photo_review.php`, `sponsorship_types.php`,
    `ticket_orders.php` (2nd table). **Worth a glance next time you're in
    the admin panel** — this is the one genuinely visual change in this
    pass.
  - Deliberately **not** touched: `league_table.php` (its `standings-table`
    is a bespoke grid, not a Bootstrap `table` — different component, not
    drift) and `match_lineups.php`'s two tables (already have an
    intentional non-zero `mb-2`, not "missing" spacing like the others —
    changing that needs a judgment call, not a mechanical add).
- **Phase 2 — buttons**: all 17 `btn-maroon` usages (across
  `app_bootstrap.php`, `match_events.php`, `match_graphic.php`,
  `league_table_graphic.php`, `match_starting_11.php`,
  `match_templates.php`) renamed to `btn-brand`. Confirmed safe first: these
  pages render via `app_bootstrap.php` rather than `header.php`, but that
  path also loads `style.css` (its `styleVersion` cache-busting reads
  `style.css`'s mtime), so the same `.btn-primary, .btn-maroon` /
  `.btn-brand` alias rule in `style.css` applied to both — a pure rename,
  zero visual change. Bootstrap's own `btn-primary` (40 uses) was left
  alone deliberately: it's Bootstrap's native class name, not really
  "drift" the way the bespoke `btn-maroon` was, and renaming it has a
  higher (if still low) chance of interacting with Bootstrap JS/plugin
  expectations somewhere.

### Phase 2 continued (2026-09-19, second pass)

- **`table-modern` retired**: renamed to `hub-data-table--rounded` in both
  the CSS (`assets/css/player-sponsors-base.css`, ~12 rules) and its 3
  consumers (`assign_sponsors.php`, `developer_audit.php`, `players.php`).
  Pure rename, not a merge — same selectors, same rules, just moved off the
  old orphaned name and documented as an optional `hub-data-table` modifier
  instead. No JS referenced the old class name (checked first). Verified in
  browser on `players.php` and `developer_audit.php`.
- **`reports.php` button system, partially folded in**: `rpt-btn--primary`
  (used once, the "Apply filters" button) is CSS-identical to `btn-brand`
  — both resolve to `var(--brand-primary)` background / white text via
  `--rpt-primary: var(--brand-primary, ...)` — **except** `.btn-brand` had
  no hover state at all (a real, separate bug: `.btn-primary`/`.btn-maroon`
  had one via an older unscoped rule, but `.btn-brand` was never added to
  it). Fixed that gap first — added
  `.hub-main .btn-brand:hover/:focus { background/border: var(--brand-dark) }`
  to `style.css` (purely additive, gives every existing `.btn-brand` button
  in the panel a hover state it was silently missing) — then swapped
  `rpt-btn--primary` → `btn-brand` in `reports.php`. Verified in browser:
  "Apply filters" renders identically to before.
  - `rpt-btn--ghost` (3 uses: Reset, CSV export, PDF export) was **not**
    touched — it has a genuinely different rest/hover treatment from
    `.btn-neutral` (inverted: rests on a muted surface and lightens on
    hover, vs. `.btn-neutral` resting white and going to muted on hover),
    not simple drift. Left as a documented, deliberate local variant.
- **Empty-state "unification" reverted as a plan** — see the correction in
  the Phase 2 component-consolidation section above. No files touched.

### Phase 2 continued (2026-09-20, third pass): pagination

Audited all 4 files with pagination first, rather than assuming they needed
the same fix:
- `developer_analytics.php`'s `analytics_render_pagination()` and
  `season_ticket_orders.php`'s `sto_page_window()` are two **independent,
  already-correct** windowed pagers (first/last, current ±2, `…` gaps,
  prev/next) — real duplication of effort, but neither is broken, so
  neither was touched.
- `matches.php`'s pagination is client-side JS (filters rows already on the
  page without a reload) — a different problem entirely, not migrated.
- `developer_sponsorships.php` had a **naive, unwindowed `for ($i = 1; $i
  <= $totalPages; $i++)` loop** — a real defect: verified in the browser
  against live data, this page currently has 27 pages, so it was rendering
  27 separate page-number links in a row. This is the one that got fixed.

Added `hub_render_pagination(int $currentPage, int $totalPages, callable
$urlFor, string $ariaLabel)` to `admin/lib/ui.php`, generalising the
windowing algorithm proven twice already (`developer_analytics.php` /
`season_ticket_orders.php`) with a caller-supplied URL-building callback
(cleaner than coupling to `$_GET`, matches `season_ticket_orders.php`'s own
pattern). Used it to replace `developer_sponsorships.php`'s naive loop.
Verified in the browser: page now renders `« 1 2 3 … 27 »` instead of all
27 links, and clicking through to page 2 correctly loads the next 25 rows
and re-renders the window (`« 1 2 3 4 … 27 »`).

The two existing correct implementations were deliberately **not**
refactored onto the new shared function — they work, and swapping a
working implementation for a shared one with no visible bug to fix is
exactly the kind of change this document keeps warning against making
blindly. `hub_render_pagination()` exists for new pages and for
`developer_sponsorships.php`; consolidating the other two is optional
future cleanup, not required.

### Phase 2 continued (2026-09-20, fourth pass): modals

The plan's premise here ("settings.php has 25 modals") was wrong. Recounted
every file properly first, using the actual Bootstrap modal-root signature
(`<div class="modal ..." tabindex="-1">`, not just any element with "modal"
in a class name — an earlier looser count had falsely inflated some files
into the dozens by matching `modal-body`/`modal-header`/`modal-dialog` as
separate hits). Real counts: `match.php` 7, `settings.php` 3,
`template_packs.php`/`stats.php`/`player_view.php`/`match_graphics.php`/
`hidden_team_games.php` 2 each, everything else 0 or 1.

`match.php`'s 7 were inspected individually rather than assumed to be
duplicates:
- `addOpponentModal` — a genuinely unique two-stage confirm/details flow,
  left alone.
- `addCompetitionModal` / `addVenueModal` — byte-for-byte identical shape
  (one text field, 3 hidden context fields, Cancel/Save footer), differing
  only in id, title, form action, field name/label, and button text. Real
  duplication, worth extracting.
- The other 4 (`sponsorDetailsModal`, `editSponsorshipModal`,
  `assignmentsModal`, `sponsorShoutoutModal`) are each their own distinct
  form, not touched.
- No other file's pattern matched closely enough to reuse the same
  extraction (checked — only `match.php` and `opponent.php` reference this
  shape at all, and `opponent.php` doesn't use the exact form).

Added `hub_render_quick_add_modal()` to `admin/lib/ui.php` and used it for
`addCompetitionModal` / `addVenueModal`, preserving every id, name, and
label byte-for-byte so nothing that already targets these elements (the
`data-bs-target="#addVenueModal"` trigger, the field labels) needed to
change. Verified in the browser: clicked the real "Add new" venue trigger
on a live fixture, modal opened with correct title/field/buttons,
identical to before.

(Aside, not fixed: `addCompetitionModal` appears to have no trigger
anywhere in the codebase — not in scope for this pass, noted for whoever
next touches competition-adding on this page.)

## Phase 1 token adoption (2026-09-20): every existing CSS value, tokenized

The Phase 1 tokens (`--fs-*`, `--space-*`, `--radius-*`) existed but nothing
used them. The user asked to finish adopting them into the existing
~8,600-line `style.css` plus 49 page-specific CSS files — with an explicit
constraint agreed beforehand: **tokenize, don't round**. Consolidating the
136 font-size / ~40 spacing / mixed-unit radius values found in the
original audit down onto the original ~8-step scale would mean silently
changing real rendered sizes on rules I have no way to visually check one
by one across 337 pages, so that was ruled out. Instead: give every
distinct value its own token, so the *values* are centralized and named
even though the *number of distinct sizes* doesn't shrink. This trades some
of a design system's usual payoff (a smaller, more disciplined palette) for
zero risk of visual regression, which is a fair trade for this exercise.

### Method

For each property family, mechanically:
1. Scan every `assets/css/*.css` file for that property with a single
   scalar value (`px` or `rem` only — `em`, `%`, `clamp()`, multi-corner/
   multi-value shorthand, and already-`var()` declarations excluded; see
   "what was excluded" below).
2. Reuse an existing named token (`--fs-sm`, `--space-4`, `--radius-md`,
   etc.) wherever a value matches one exactly; otherwise mint a new token
   named after the value itself (e.g. `--fs-082` for `0.82rem`,
   `--radius-8px` for `8px`).
3. Add every new token to `style.css`'s `:root`.
4. Replace the literal in every file **except `style.css` itself** with
   `var(--token-name, <original-value>)` — always with a fallback, because
   `match_graphic.php`, `league_table_graphic.php`, and `match_starting_11.php`
   load their CSS standalone (via `app_bootstrap.php`, not `header.php`) and
   don't guarantee `style.css`'s tokens are in scope. The fallback means
   these pages render identically whether or not the token resolves.
5. Verify: brace-balance every file, diff against a pre-change backup to
   confirm only the target property's lines changed, then check a sample of
   real pages in the browser — including one of the standalone pages, to
   prove the fallback path actually works, not just that it looks safe.

### What got tokenized, and the exact numbers

| Property family | Files touched | Declarations replaced | New tokens minted |
|---|---|---|---|
| `font-size` | 32 (style.css + 31 others) | 661 (234 in style.css) | 64 (38 + 26) |
| `border-radius` (single-value only) | 35 | 448 | 42 |
| `margin`/`padding`/`gap` (+ directional and logical variants, single-value only) | 36 | 1,292 | 76 |

All three passes: brace-balanced afterward, diffed clean (only the target
property's lines changed in every sampled file), and visually verified in
the browser on both style.css-scoped pages and the 3 standalone pages that
rely on the fallback.

### What was deliberately excluded (not drift — different problem)

- `clamp(...)` font-sizes (7 instances) — responsive, per-component, not a
  simple scale value.
- `em`/`%` font-sizes, and `px` sizes already following a separate
  existing token (e.g. `var(--monthly-legend-size, 38px)`) — different
  semantics from the rem-based scale (relative to parent, not root).
- `border-radius: 50%` (circular avatars) and `inherit` — not part of a
  radius *scale*, they're structural keywords.
- 7 multi-corner `border-radius` declarations (e.g.
  `border-radius: .4rem 0 0 .4rem;`) — bespoke per-component shapes (tab
  cutouts, blob shapes), not shared scale values.
- Negative `margin` values (~8 instances) — few enough, and negative-margin
  is usually a one-off positioning hack rather than a scale value; forcing
  them into the token system would need a "negate this token" convention
  that isn't worth inventing for 8 uses.
- Multi-value shorthand `margin`/`padding`/`gap` (e.g.
  `padding: 0.5rem 1rem;`, ~450 instances) — tokenizing each value inside a
  shorthand property correctly (without accidentally matching a number
  inside `calc()`, a grid track size, or similar) is real additional
  complexity for a purely cosmetic win. Left as literals.

### A real bug, introduced and caught in this same pass

The token-naming scheme stripped a leading `"0."` when generating names
(so `0.6rem` → `--fs-6`), but a *different* existing value, plain `6rem`,
normalizes to the exact same name via the same rule. That produced **four
silent duplicate `:root` custom-property definitions** — `--fs-6`
(`0.6rem` vs `6rem`), `--space-18rem` (`.18rem` vs `1.8rem`),
`--space-22rem` (`.22rem` vs `2.2rem`), and `--space-6rem` (`.6rem` vs
`6rem`). CSS silently lets the later `:root` declaration win, so one value
in each pair would have been silently replaced by a value 10x too large
wherever it was used — e.g. a 0.6rem gap rendering as 6rem.

This was caught by re-running the "no duplicate custom-property names"
check as a matter of routine after the third (spacing) pass, *not* by the
browser spot-checks — the specific elements affected weren't in the sample
of pages checked, which is exactly why the automated check mattered more
than eyeballing pages here. Fixed by renaming the smaller value in each
pair to a disambiguated name (`--fs-06`, `--space-018rem`,
`--space-022rem`, `--space-06rem`) and updating every usage site — safely,
because every usage still carried its own correct fallback value, so each
site could be matched to the right token by its fallback text. Verified
afterward with a script comparing every token's `:root` value against
every usage site's fallback across all 50 files — zero mismatches — and a
second browser pass specifically on pages using the affected files
(`matchday_finance.php`, `media.php`, `player_graphics.php`), including one
page that intentionally uses the large `6rem` value for large initials
text, to confirm both the small and large values render correctly in their
respective places.

**Lesson for next time a tokenization pass like this is done**: run the
duplicate-name check as a required step immediately after minting new
token names, before applying any replacements — not as an afterthought.

### Not done / explicitly deferred
- `reports.php`'s `rpt-btn--ghost` (3 uses) — deliberately left as a
  distinct local variant, not drift (see above).
- Multi-value shorthand spacing and multi-corner radius (see above) — not
  drift, excluded by design for risk/reward reasons.
- ~~No visual regression testing was performed~~ — done in a follow-up pass
  (browser check of 10+ pages covering every change in this doc). No
  regressions found from the token/table/button work.
  - **Bonus fix, found via that QA, unrelated to the design-system plan**:
    `hub_render_page_hero()` in `header.php` was rendering the literal text
    of an action's `icon` value (e.g. `fa-arrow-left`) instead of an actual
    icon, whenever a page passed one explicitly rather than relying on
    label-based auto-detection — it returned the raw string instead of
    wrapping it in `<i class="fa-solid ...">` the way the auto-detected path
    already did. Affected `developer_analytics.php`'s "Developer dashboard"
    back-link and `scan_overview.php`'s "Enter Scan" action. Fixed at
    `header.php:241`; both pages verified fixed in the browser.
