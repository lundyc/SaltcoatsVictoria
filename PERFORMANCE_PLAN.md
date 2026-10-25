# Performance and stability plan

Written 2026-10-16 after a read-only audit. Rule for everything below: **the site must look identical afterwards.** Each phase is small, reversible, and checked before the next starts. Tick items off as they are done.

## Baseline (measured 2026-10-16)

- PHP 8.4 + OPcache, MariaDB 10.11, nginx in front of Apache, HTTP/2, gzip on.
- Response time: `/` 0.124s, `/fixtures` 0.128s, `/news` 0.107s, `/shop` 0.104s (TTFB).
- Home page HTML 71 KB. `uploads` 646 MB: 2,047 JPG, 455 PNG, 15 WebP. 357 images over 500 KB. Generated graphics are 8-11 MB PNGs.
- Public pages sent `no-store`, and a `PHPSESSID` cookie is set for every anonymous visitor.
- `innodb_buffer_pool_size` 128 MB, slow query log off. `cache/` is 126 MB.
- Bootstrap 5.3.3, Font Awesome, Chart.js, jQuery and Google Fonts are loaded from CDNs; Tailwind CDN on two pages.

## Corrections after checking the code (2026-10-16, same day)

The first audit overstated several items. Checked against the real code:

- **Images: already handled.** `lib/imgopt.php` resizes public images on demand to WebP at 400/800/1200/1600px, adds `srcset`, width/height and lazy loading, and caches under `cache/img/`. Originals are untouched. Phase 1 is therefore mostly DONE; only generated social graphics (8-11 MB PNG) remain, and they are never shown on the public site.
- **Compression: already Brotli.** `public.css` (283 KB raw) is sent as `br`, with a 1-year immutable cache.
- **Front-end libraries: public site loads none.** The public pages load no third-party scripts, only their own CSS/JS and self-hosted fonts. Bootstrap, Font Awesome, Chart.js, jQuery and the Tailwind CDN are used only inside the Hub (staff pages), so Phase 4 affects staff only.
- **Menu images:** the two large CTA WebPs sit in a hidden dropdown as CSS backgrounds, so browsers should not fetch them until the menu shows. Not confirmed in a real browser.
- **Database memory (3.1):** real data is about 24 MB and already fits in the 128 MB buffer pool, so raising it gains almost nothing. Dropped.

## What is genuinely left

| # | Item | Gain | Risk |
|---|------|------|------|
| A | Page cache + no PHP session for anonymous visitors (2.1-2.3) | TTFB ~0.11s to ~0.01s on public pages | Medium (forms, tokens) |
| B | Hub: self-host Bootstrap/Font Awesome/Chart.js, replace Tailwind CDN (4.1-4.3) | Faster, steadier Hub | Low-medium |
| C | Slow query log for a week (3.2) | Evidence only | Very low |
| D | PHPStan + tests (5.x) | Stability | None to the site |
| E | Prune old `uploads/matches` renders and `cache/` (6.x) | Disk tidy | Low, owner confirms |

Every phase is done or closed as of 2026-10-17. Still open: 3.3 only (needs a week of slow-log data; log empty on 2026-10-17).

## Phase 1: Images (mostly already done, see corrections)

- [x] 1.1 (already covered by `lib/imgopt.php`, see corrections) Find where public pages output images (helpers, templates) and which sizes they actually need.
- [x] 1.2 (already covered by `lib/imgopt.php`, see corrections) Generate WebP copies next to the originals (originals are never touched or deleted).
- [x] 1.3 (already covered by `lib/imgopt.php`, see corrections) Serve WebP to browsers that accept it (nginx/.htaccess rule), falling back to the original.
- [x] 1.4 (already covered by `lib/imgopt.php`, see corrections) New uploads: create the WebP copy and a size-capped version on upload.
- [x] ~~1.5~~ Not doing: generated graphics are never shown on the public site. Generated graphics (Next Match etc.): keep the PNG for download quality, add a compressed JPEG for social posting.
- [x] 1.6 (already covered by `lib/imgopt.php`, see corrections) Add `width`/`height` and `loading="lazy"` on below-the-fold images where missing.
- [x] Check: nothing changed in Phase 1, so no before/after needed.

## Phase 2: Caching

- [x] 2.1 Anonymous cacheable pages already had the session cookie stripped (the first audit used a HEAD request, which skips that code). Cache hits now never start PHP or a session at all. 404s still create a session file (left as is).
- [x] 2.2 Short public cache (`lib/page_cache.php`, files in `cache/pages/`, 60s; bypass for any PHPSESSID/sv_mbr cookie or query string; whitelist of read-only routes). Short public cache for read-only pages (home, news, fixtures, table, results) with a bypass for logged-in users, baskets, checkout, forms and anything with a token.
- [x] 2.3 Invalidation is the 60s expiry, the same staleness browsers already had. Delete `cache/pages/` to switch it off.
- [x] ~~2.4 APCu~~ Not installed and pointless now; skipped. APCu object cache for league table and sponsor lists, if available.
- [x] Check: TTFB 0.11s to 0.04s measured; output identical; logged-in admin and checkout behave exactly as before.

## Phase 3: Database

- [x] ~~3.1 Raise `innodb_buffer_pool_size` to 512 MB (Plesk/MariaDB config, restart in a quiet moment).~~ Dropped: data is 24 MB.
- [x] 3.2 (on since 2026-10-17, review ~2026-10-24; config `/etc/mysql/mariadb.conf.d/99-slowlog.cnf`, log `/var/lib/mysql/frosty-mclaren-slow.log`) Turn on the slow query log (threshold 0.5s) for a week, then review.
- [ ] 3.3 (waiting for a week of slow-log data, review ~2026-10-24) Add indexes only for queries the log proves are slow.
- [x] 3.4 Listed 2026-10-17 (nothing dropped). Site DBs: `saltcoats_hub` 23.6 MB, `PlayerSponsors` 28.1 MB (fallback/legacy), `mch_security_restore_20260921` 24.5 MB (a restore snapshot, archive candidate). Others on the server (wp_mu7en, project_1, snooker, Groundhopper, DSGaming, spavenues, sweepstake, LastManStanding, lms_production, myclubhub, mch_os, hidden_team) are unrelated projects, all under 7 MB.

## Phase 4: Front-end libraries

- [x] 4.1 Done: Bootstrap 5.3.3 (CSS+JS), Font Awesome 7.0.1 + webfonts, Chart.js 4.4.6, jQuery 3.7.1 and Inter (latin and other subsets) now served from `admin/assets/vendor/`. 29 files rewritten (originals in `/root/perf_backups/20261017/admin/`). Left on CDN, each used by one or two pages: TinyMCE, EasyMDE, html2canvas, cropperjs, and the extra Google Fonts families on the graphics pages.
- [x] ~~4.2~~ Skipped on purpose. The two Tailwind layouts (`app/Views/layouts/app.php`, `auth.php`) belong to an unrouted MVC skeleton with no entry point, and they set colours per club at runtime, which a compiled stylesheet would change.
- [x] 4.3 Already the case: Chart.js is loaded only by index.php, reports.php, stats_extra.php and developer_analytics.php.
- [x] 4.4 Latin Inter file preloaded in `admin/header.php`, `font-display: swap` kept, dead preconnects removed.
- [x] Check: 2026-10-17, checked in the owner's logged-in browser. Login, dashboard (doughnut chart) and Reports render correctly; every library and Inter loads from `/admin/assets/vendor/`, no CDN requests, no console errors, 69 Font Awesome icons on the dashboard.

## Phase 5: Stability

- [x] 5.1 PHPStan 2 installed outside the web root (`/root/phpstan-tool`); `phpstan.neon` at level 0 over the public layer; clean, baseline empty. Run: `/root/phpstan-tool/vendor/bin/phpstan analyse -c phpstan.neon`.
- [x] 5.2 `.github/workflows/lint.yml` runs php -l and PHPStan. The test suite needs the live database, so it stays a server-side run (`cd admin && php tests/run.php`, 40 passing).
- [x] 5.3 Standing rule, nothing to do now.

## Phase 6: Housekeeping

- [x] 6.1 `ops/cache-cleanup.sh` runs daily 04:10 (site user crontab): page cache older than 1 hour, resized images untouched for 180 days. Nothing was old enough to delete today.
- [x] 6.2 Rule set by owner: keep generated match graphics until 3 days after the match. `ops/prune-match-graphics.php` runs daily 04:20, deletes top-level `uploads/matches/fixture_<id>_*` for matches older than 3 days and clears the matching `match_fixtures` background columns. `uploads/matches/gallery` (real photos, 118 MB) is never touched. First run on 2026-10-17 removed 6 files (18.7 MB; backup in `/root/perf_backups/20261017/matches/`). Fixture 54 (24 Oct) is kept.
- [x] ~~6.3~~ Closed, not doing. A persistent Chrome would be a long-running process on the live server, and the plan's own "Not changing" list keeps the renderer as is (`admin/render.js` launches Chrome per render; renders are occasional). Reuse one headless Chrome for graphics rendering (lowest priority).

## Not changing

PHP, MariaDB, Bootstrap, Plesk, and the headless-Chrome graphics renderer. No framework rewrite.

## Safety rules

1. Back up any file or config before editing it (copy to `/root/perf_backups/<date>/`).
2. One phase at a time; test the public site and the Hub after each.
3. Nothing is deleted without the owner's confirmation.
4. Server-level changes (database settings, nginx) are listed for the owner before they are applied.

## Log

- 2026-10-16: plan written; audit corrected after reading `lib/imgopt.php` and measuring real pages. No site files changed.
- 2026-10-17: Phase 2 done (page cache). Slow query log enabled. Backups in `/root/perf_backups/20261017/`. Deleted admin/NAVIGATION.md at owner's request.
- 2026-10-17: Phases 4, 5, 6 worked through. Vendor libraries and Inter self-hosted, PHPStan and lint workflow added, cache cleanup cron installed. Tests 40/40 pass.
- 2026-10-17: 6.3 closed as not doing. Slow log checked: no entries yet.
- 2026-10-17: 6.2 done with the owner's 3-day rule. Hub checked in the owner's browser.
- 2026-10-17: Help guides refreshed (separate from performance): all 50 guides rewritten for the new menu and features, 19 new guides added, 97 new redacted screenshots, 60 outdated images removed. Backups in `/root/perf_backups/20261017/help/`. Temporary redaction endpoint deleted.
- 2026-10-17: /scan reviewed and fixed. Post address, permission mismatch and wrong account id (every scan would have hit a foreign-key error); schema setup now runs once (`admin/lib/schema_guard.php`, flags in `cache/schema/`); scanner problem log (`scan_problems` table, `admin/scan_problems.php`); Bootstrap/Font Awesome now local; service worker + manifest in `/scan/`.
