# Saltcoats Victoria FC — public website

Fan-facing site (design pattern modelled on motherwellfc.co.uk), read-only views
over the existing Hub database. **No authentication** — it deliberately does not
touch `auth.php` / `header.php`.

## Where it runs

Development: served from `httpdocs/public/` at the URL path **`/public`**.
Go-live: the Hub moves into its own folder and the contents of this directory
move up to `httpdocs/` (the web root).

## Layout

```
index.php        front controller (all non-file requests rewrite here)
bootstrap.php    wires shared Hub code + helpers + view libs
config.php       portability knobs + club identity  ← edit on move
router.php       path → page-file table
helpers.php      url(), asset(), uploads(), e(), set_meta(), partial(), render…
lib/             read-only view-model queries (fixtures, partners, …)
pages/           one file per route, emits <main> content, may set_meta()
partials/        head, site_nav, site_footer, next_match, match_card, …
assets/          bespoke public.css + public.js + local fonts
cache/           short-lived fragment cache (writable)
```

`lib/`, `pages/`, `partials/`, `cache/` each carry a `Require all denied`
`.htaccess` — they are never requested directly.

## Coupling to the Hub

The **entire** dependency on Hub code is in `bootstrap.php`:

- `HUB_ROOT/db.php` — the `$pdo` connection (also pulls in `config.php` + session)
- `HUB_ROOT/lib/functions.php` — small view helpers

Uploaded media (crests, sponsor logos, player photos) is referenced by absolute
URL under `UPLOADS_BASE` (`/uploads`), which stays at the domain root.

## Go-live move procedure

1. Move the Hub's PHP out of `httpdocs/` into its own folder (e.g. `hub/`).
2. Move everything in `httpdocs/public/` up into `httpdocs/`.
3. In `config.php`: set `PUBLIC_BASE` to `''`, point `HUB_ROOT` at the Hub's new
   location **or** vendor `db.php` + `lib/functions.php` into `public/lib/hub/`.
4. In `.htaccess`: change `RewriteBase /public/` to `RewriteBase /`.
5. Point the Hub at its own subdomain / path and update its links.

## Build status — all steps complete

- [x] 1. Scaffold — bootstrap, router, theme, header/footer
- [x] 2. Composer deps (`league/commonmark`, `ezyang/htmlpurifier`) + migrations
- [x] 3. `site_settings` table + `lib/site_settings.php` + Hub `settings_public.php`
- [x] 4. News — `news_articles`/`news_images`, Hub `news.php`/`news_edit.php`/
      `news_image_upload.php`, public `/news`, `/news/{slug}`, `/news/category/{slug}`
- [x] 5. `/fixtures`, `/results`, `/table` (WOSFL cache), `/match/{id}` match centre
- [x] 6. `/team`, `/team/{slug}`, `/staff` + Hub `player_website.php`
- [x] 7. `club_pages` + Hub `club_pages.php`; `/club/{slug}`, `/privacy`, `/contact`
- [x] 8. Home: featured-news hero, next match, live league position, results, news
- [x] 9. SEO: `/sitemap.xml`, `/robots.txt`, `/news/feed.xml` (RSS), JSON-LD

### One manual step
Run `php database/migrate.php` once (blocked from automation) to record the four
2026_09_06 migrations. Tables already exist (created on first page load by the
`*_ensure_schema()` functions) — this just makes it formal.

## Content migrated from the old site

`tools/import_svfc_backup.php` imports the archived saltcoatsvictoria.co.uk
content from `/var/www/vhosts/lundy.me.uk/httpdocs/SVFC__Backup`. Re-runnable
and idempotent (news matched on title+date, club pages on slug):

```
php tools/import_svfc_backup.php --all           # news + club pages + settings
php tools/import_svfc_backup.php --news --dry-run
```

Already run — imported:
- **144 news articles** (2014–2026), 36 with hero images → `uploads/news/backup/`
  (29 MB). Bodies stored as HTML, sanitised by HTMLPurifier at render. Loose
  auto-categorisation (mostly "Club news") — retag in the Hub as needed.
- **12 club pages**: About / Campbell Park / Honours / Player awards /
  Mission & values / Links + 6 policies (privacy, child protection, code of
  conduct, EDI, ground regulations). Old-site links & dead images stripped,
  backup images localised to `uploads/club/` (3.2 MB).
- **Settings**: `club_founded` 1889, `club_nickname` "The Seasiders",
  `ground_address` "Blakely Rd, Saltcoats KA21 5JQ".

**Not imported** (no clean target / out of scope): 245 player profiles,
508 historical matches, 886 gallery photos, all-time stats leaderboard,
sponsor descriptions. The backup still has them if a home is built later.

## Shop + match tickets

Presentation ported into the site design; **all cart / order / Stripe logic
is reused unchanged** from `lib/shop.php`, `lib/shop_basket.php`,
`lib/match_tickets.php`. The live standalone `/shop` and `/tickets` are
untouched — these are a parallel copy under the public routes.

- `/shop`, `/shop/p/{slug}`, `/shop/basket`, `/shop/checkout`,
  `/shop/order/{token}` — full flow; Stripe success/cancel URLs point back at
  the public routes.
- `/tickets`, `/tickets/order/{token}` — **guest checkout only** (the live
  `/tickets` keeps its account/login options); QR carousel via
  `assets/js/vendor/qrcode.min.js` (copied from the Hub); the check-in status
  poll hits the Hub's `/ticket_order_status.php` (absolute path — revisit on
  the go-live move).
- The shared PHP session means a basket is the same object on `/shop` and
  `/public/shop`.
- Product page: the live shop's two-step "fit section → size dropdown" UI is
  simplified to flat option chips here.

## Coupling to the Hub (updated)

`bootstrap.php` includes: `db.php`, `lib/functions.php`, `lib/site_settings.php`,
`lib/news.php`, `lib/club_pages.php`, `lib/squad_public.php`. It also reads
`cache/wosfl_table.json`, `league-config.json` and `data/matches.json`, and
`pages/contact.php` uses `lib/mailer.php`. Uploaded media is referenced under
`/uploads` (crests, `/uploads/news`, `/uploads/players`, `/uploads/opponents`,
`/uploads/matches`) and league badges under `/badges`.
