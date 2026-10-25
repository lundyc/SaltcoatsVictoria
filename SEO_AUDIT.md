# SEO audit, 2026-10-20 (saltcoatsvictoria.co.uk)

Method: crawled all 834 sitemap URLs plus 55 further internal link targets, 16 other templates, headers/redirects, JSON-LD, and lab Core Web Vitals (headless Chrome, 390px, 4x CPU, ~1.6 Mbps, 150 ms latency). Backups of edited files: `/root/perf_backups/20261020/seo/`.

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | sitemap.xml | Already done | 834 URLs, all 200, canonical == URL; submitted to GSC (as www URL, see owner tasks) |
| 2 | robots.txt | Already done | 200, references sitemap; blocks only admin/api/members/pos/scan/checkout/order/`/table/pdf` (intentional) |
| 3 | noindex | OK | 0 of 834 indexable pages noindex; 404s are `noindex, follow` |
| 4 | Canonicals | Already done | 834/834 absolute https, equal to sitemap URL |
| 5 | Titles | Already done | none missing, no duplicates, none over 65 chars |
| 6 | Descriptions | Already done | none missing, no duplicates |
| 7 | One H1 | OK | 834/834 pages have exactly one |
| 8 | Heading order | **Fixed** | 30 pages skipped levels; now 0 (news cards, article/policy/match-report body, match sidebar). New `seo_normalize_headings()` in `lib/seo.php` |
| 9 | Alt text | OK | 0 images without an alt attribute (news-card images are `alt=""` decorative; the title is the link text) |
| 10 | Schema | Already done | SportsTeam+WebSite (home), NewsArticle, SportsEvent, BreadcrumbList; all parse. Not run through Google's Rich Results Test (needs a browser session) |
| 11 | Internal links | OK | 1,832 distinct links |
| 12 | Broken links | OK | all 55 non-sitemap internal targets return 200 |
| 13 | Images | Already done | `lib/imgopt.php` (WebP, srcset, lazy; LCP image has fetchpriority=high) |
| 14 | Core Web Vitals | See below | no field data checked |
| 15 | Mobile | OK | no horizontal overflow on checked pages; match page fixed earlier today |
| 16 | HTTPS | Mostly OK | http and www 301 to https non-www; no http:// resources on home. No HSTS header (owner decision) |
| 17 | Slugs | OK, no change | readable slugs; old-site URLs already redirected by `lib/legacy_redirects.php` |
| 18 | Open Graph / Twitter | Already done | og:image and twitter:card present on 834/834 |
| 19 | Search Console | Owner action | sitemap read OK: 0 errors, 835 submitted, last read 19 Oct; submitted under the **www** URL |
| 20 | llms.txt | **Added** | `/llms.txt`, public pages only, all links return 200 |

## Lab Core Web Vitals (mobile, throttled)
LCP: home 2.1 s, article 2.7 s, /news 2.95 s, /gallery 3.4 s (over 2.5 s); match, fixtures, table, tickets under 0.5 s. CLS 0 to 0.022 everywhere. Desktop LCP 0.23-0.77 s. /news and /gallery LCP is already a high-priority 800px image (about 70 KB); the delay is the throttled link shared with other images (the crest is fetched at three sizes). Not changed, see remaining items.

## Remaining
- Owner: resubmit the sitemap as `https://saltcoatsvictoria.co.uk/sitemap.xml` in Search Console; click Validate fix on the 404 item; decide on HSTS.
- Optional: stop `http://host/news/` taking two redirects (https, then trailing-slash removal) in nginx/Apache.
- Optional: serve one crest size per page; smaller first image on /news and /gallery for mobile.
- /shop returns 200 and indexable while the shop is closed; consider noindex while closed.
- Rich Results Test and PageSpeed Insights field data need a manual check.
