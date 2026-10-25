# Help Centre — how it works and how to maintain it

Everything lives in the admin (`/admin/help.php`). Site Admins (the Administrator access template) edit it; everyone signed in can read the guides their access allows.

## Day to day (Site Admins)
- **Edit or add a guide:** open it in the Help Centre and press **Edit**, or **New article**. Written in Markdown. Fields:
  - *Area* groups it on the Help Centre.
  - *Show on these pages* links it to the **?** button on those admin pages (file names, `*` wildcard, e.g. `shop_*`). A guide that names the exact page is listed first.
  - *Who can see it*: tick none for everyone; otherwise people need at least one of the ticked permissions.
  - *Published*: untick to keep a draft that only Site Admins see.
- **Add a screenshot:** use **Add image** in the editor (PNG/JPG/WebP under 2 MB). Images are stored in `admin/storage/help_images/` (not reachable by URL) and served by `help_image.php` to signed-in users only. Clicking a screenshot opens a lightbox.
- **Screenshot rules:** never show real names, emails, phone numbers, photos of people or real money figures. Use made-up names and amounts (or blur).
- When a screen changes, update its guide the same day.

## Where things are (developers)
| Thing | File |
|---|---|
| Help Centre page, search, guide view | `help.php`, `help_search.php`, `assets/js/help-centre.js` |
| Editor, image upload/serve | `help_edit.php`, `help_image_upload.php`, `help_image.php` |
| `?` slide-out for the current page | `help_panel.php`, `assets/js/help.js` |
| Guided tours + first-login prompt | `lib/help_tours.php` (tour steps), `help_tour.php`, `assets/js/help-tour.js` |
| Shared PHP (search, Markdown, helpers) | `lib/help.php` |
| Styles | `assets/css/help.css` (Help Centre), `assets/css/help-global.css` (button, panel, hints, lightbox, prompt) |
| Starter content | `lib/help_seed.php`, `lib/help_seed_guides.php` (inserted once by migrations; later edits are never overwritten) |

Database: `help_articles` (the guides) and `user_help_state` (per-person tour progress and prompt choices).

## Field hints
`hub_help_hint('Plain text')` (in `lib/help.php`) returns a small ⓘ button that opens a popover on tap or click. Put it inside a `<label>` or column heading.

## Tours
Tours are defined in code (`lib/help_tours.php`) because they point at things on the page. Steps whose target is not visible (for example the bottom bar on a desktop) are skipped automatically. Add `?tour=<key>` to any admin URL to start one.

## Access rules
- Reading: any signed-in user; each guide is filtered by its permissions.
- Editing, uploading images: Administrator only.
- `help.php` and `help_edit.php` are in `HUB_PAGE_GATE_EXEMPT` (`lib/positions.php`) so limited accounts can open the Help Centre; the editor checks for admin itself.

## Features that are switched off
The Help Centre follows the menu settings (Settings → menu visibility). When a menu item or section is switched off:
- guides whose *Show on these pages* all belong to switched-off items are hidden from everyone, and their tours and tour steps disappear (Site Admins see a "Hidden: features switched off" list at the bottom of the Help Centre, and can still open and edit those guides);
- they return automatically when the feature is switched back on.

For one line inside a guide (for example a bullet that mentions Follow-ups), end it with `[[needs:sponsorship.sponsor_followups]]` (a menu item) or `[[needs:secretary]]` (a whole section). `[[needs:!secretary]]` shows a line only while the feature is off, so you can write an alternative wording.
