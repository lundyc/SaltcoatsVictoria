# Ticket Scanner: events, demo mode, safer match picker

Planning only (2026-10-17). No code has been changed for anything in this file.

## 1. Can the club run a non-match event (speakers night, tribute night)?

**Short answer: partly. The selling, QR tickets, scanning and recording all work, but only for a match. There is no "event" object yet.**

What exists and would be reused:
- Stripe checkout for tickets, QR tickets emailed to buyers, manual codes.
- Scanner, scan log, "already scanned" protection, complimentary admissions, door sales (POS) admissions.
- Attendance counts and reporting.

Why it does not work as an event today:
- Every ticket, package price, admission and scan points at a row in `match_fixtures` (foreign keys with cascade). The scanner and the ticket shop show "vs Opponent".
- Packages are priced per fixture (`fixture_ticket_packages`). Hospitality bookings are also tied to a fixture.
- There is no title, description, venue, image, capacity or event page. (No capacity limit was found in the ticket code; confirm before selling a limited-seat event.)
- Faking an event as a fixture would put it on the public Fixtures page, results, tables, stats, calendar sync and social drafts. Not recommended.

Recommended build (small, and it shares groundwork with demo mode):
1. Add a `kind` (match / event) and a `visibility` (public / hidden) to the fixture record, or a separate `events` table that tickets can point at. Preferred: the same row with `kind` and `visibility`, because tickets, scanning and POS then work unchanged.
2. Events get: title, date/time, venue, description, image, capacity, ticket prices.
3. A public Events page and event ticket page (own URL, not in Fixtures/Results/Table/Stats).
4. Scanner shows events with their title instead of "vs Opponent".
5. Event sales report (sold, scanned, no-shows, revenue).
6. Hide events from every match-only feature (graphics, social drafts, calendar, stats).

Rough size: about 115 files mention `match_fixtures` (47 in `lib/`, the rest in admin and pages), so the filter changes need an audit, not a guess. Estimate 2 to 4 days including testing.

## 2. Demo mode for committee and volunteer feedback

### 2.1 Where the demo lives (decision needed)

| Option | How | Pros | Cons |
|---|---|---|---|
| A. Separate demo site (recommended) | `demo.saltcoatsvictoria.co.uk`, copy of the code, its own database (a scrubbed copy), Stripe test keys, mail turned off, noindex and password | Cannot affect live data, public site or Stripe. Can be reset to a clean state before each demo. Testers can break things freely | Needs a deploy/sync step and a DB copy; `.env` setup must be done by you (I am not allowed to edit `.env`) |
| B. Demo flag inside the live system | `visibility = hidden` on a demo fixture | One system | Must be filtered out of about 115 places; one miss leaks demo data into live reports or the public site; demo tickets share the live Stripe and email settings |

Recommendation: A. It also satisfies "a demo match must never show on the public site" by construction. Option B can follow later when events need `visibility` anyway.

Demo data rules:
- Fictional ticket holders only (no real names or emails).
- Demo tickets are complimentary (no payment, no email to anyone).
- A "demo ticket sheet" page prints about 30 QR codes covering: valid, valid twice, wrong match, cancelled/inactive, unknown code, season ticket, match ticket, group order link.
- A "Reset demo" button restores the starting data.

### 2.2 What gets recorded (demo site only)

Server side:
- Every scan request: time, participant code, ticket result, server time, round trip time.
- Existing `scan_logs` and `scan_problems` keep working.

On the phone (a small script loaded only when `DEMO_MODE` is on):
- Every tap: screen, element label, position as a percentage of the screen, timestamp. Taps on things that are not buttons are flagged as mis-taps. Several rapid taps on one spot are flagged as frustration.
- Screen views and time on each, scroll, back navigation, orientation and screen size.
- Scanner timings: camera start time, time from opening the page to the first successful scan, time from QR in view to result on screen.
- Manual entry: keystrokes, corrections, time to submit.
- Errors and hesitation (more than 5 seconds with no tap).
- Device and browser, connection type.
- No camera images are stored, only decoded text.
- Events are batched every few seconds and kept in the phone's storage if there is no signal.

Facilitator tools:
- A floating "Feedback" button for a comment, and an "I'm stuck" button.
- A task list shown in a panel. The tester taps Start and Done per task. After each task a 1 to 5 ease rating.
- Participants get a code (P01, P02), not a name. A sheet records role, phone model and confidence with tech.

Optional extra: Microsoft Clarity or PostHog session replay and heatmaps on the demo site only, with input masking. Free, but third party, so only use it if the committee is told.

### 2.3 Suggested tasks to test
1. Open the scanner and choose today's match.
2. Scan a valid ticket.
3. Scan the same ticket again.
4. Scan a ticket for a different match.
5. Scan a ticket that is cancelled.
6. Type a code by hand.
7. Turn on airplane mode and scan, then turn it off.
8. Find the recent check-ins and the total.
9. Get logged out (reset the session) and recover.
10. Scan a group order link.

### 2.4 Reviewing it afterwards
A demo-site-only review page:
- Session list with participant, device, date and task results.
- Timeline replay of one session (taps in order, with timings).
- Heatmap of taps per screen, plus mis-taps.
- Task funnel: completion rate, time, errors, help requests.
- Scan speed charts and a list of problems.
- Comment inbox, and CSV export.
- A "findings" list where each issue is tagged with a priority, so it feeds the improvement backlog.

Targets to judge the scanner against:
- Result on screen within 1.5 seconds of the QR being in view.
- No participant picks the wrong match.
- 9 of 10 tasks completed without help.
- Every error message understood without explanation.

### 2.5 Privacy
- Tell participants what is recorded and why, and that it is for improving the scanner.
- Only participant codes, device type and behaviour, no personal data.
- Delete raw interaction data after 90 days. Keep anonymous summaries.

### 2.6 Suggested order
1. Choose Option A or B.
2. Build the demo site and demo data, plus the demo ticket sheet.
3. Add the interaction recorder and feedback panel.
4. Dry run with one person.
5. Run the sessions.
6. Review, then implement the changes in the live scanner.

## 3. Safer match picker

Problem: the picker lists several future home matches, so a steward could choose the wrong one.

Proposed behaviour:
1. One large card at the top: **"Scanning now"**, the home match or event closest to today. Today's match wins. If there are two today, both are shown. If nothing is today, it shows the next one.
2. A clear label on the card: TODAY, TOMORROW, or "in 6 days".
3. Everything else sits under a collapsed **"Other matches and events (N)"** section.
4. If a steward opens a match that is not today, show a confirmation: "This match is on Sat 24 Oct, not today. Scan for it anyway?"
5. While scanning, a large persistent header shows the opponent or event name, date and a TODAY badge (not only small text at the bottom).
6. Colour cue: a green header for today, amber for a different day.
7. A match that is not today and is scanned anyway is recorded as a "wrong day" problem in the log, but not blocked, so the gate is never stopped.
8. Optional: event tickets and match tickets appear in the same list once events exist.

Decided (2026-10-17):
- A match stays as "Scanning now" until it is marked **played**, then the next one takes over.
- Postponed or cancelled matches are hidden from the main card.
- Demo site vs flag: owner will decide later.

## 4. Events module (detailed plan)

Owner's brief: events live in their own section, with their own pricing and a limit on how many tickets can be allocated, working like FanBase or other event ticketing systems, across different venues that hold different numbers of people. (I have not checked FanBase's current feature list; the list below is the usual feature set of event ticketing platforms. Confirm which parts you want.)

This replaces the earlier idea of marking events inside the fixture table. A separate `events` table is cleaner: events cannot leak into Fixtures, Results, Table, Stats, calendar or social drafts, and none of the ~115 match-related files need changing.

### 4.1 Admin: new "Events" section
- **Venues**: name, address, map link, photo, **total capacity**, optional named areas with their own capacity (for example Main Hall 200, Bar 60, Boardroom 20), parking and access notes. Venues are reusable across events.
- **Events**: title, short and long description, image, date, doors time, start and end time, venue (and which areas are used), status (draft / on sale / sold out / cancelled / postponed / finished), age limit, public or hidden, organiser contact.
- **Ticket types per event**, each with:
  - name and description (Standard, Member, Under-16, VIP, Table of 8)
  - price (including free)
  - **allocation** (how many of this type can be sold)
  - maximum per order, and tickets admitted per ticket (a table of 8 admits 8)
  - sale start and end
  - visibility (public / hidden code-only)
  - Optional member or season-ticket-holder price.
- **Event capacity rule**: the sum of all allocations may not exceed the venue (or area) capacity unless you override it. A warning shows remaining capacity while you set allocations.
- **Holds**: tickets in someone's checkout are held for about 15 minutes so the last tickets cannot be oversold. Unpaid holds are released automatically.
- **Promo and invite codes**: percentage or fixed discount, or hidden ticket types released by code (sponsors, committee, speakers' guests).
- **Complimentary tickets and guest list**: counted against capacity, with a reason recorded.
- **Add-ons (optional)**: meal choice, dietary needs, programme, drinks voucher.
- **Door sales (POS)**: sell and admit on the night, counted against capacity.

### 4.2 Public side
- **Events page** listing upcoming events (own section of the site, not Fixtures).
- **Event page**: image, details, venue and map, ticket types with prices and "x left" / "Sold out", buy button, add to calendar.
- **Checkout** using the existing Stripe flow, email with QR tickets (one QR per ticket), order page to view or resend tickets.
- **Search engine support**: structured Event data and a sitemap entry.
- **Share graphic** for each event in the same style as the Next Match graphic.

### 4.3 After the sale
- **Sales dashboard per event**: sold, held, remaining by type, revenue, scanned, no-shows, sales over time.
- **Attendee list** with search, export (CSV), resend ticket, edit name, refund, cancel.
- **Messages to ticket holders** (time change, cancellation) using the existing noreply mail.
- **Refunds and cancellation**: refund one ticket or the whole event, with the club's refund policy shown at checkout.
- **Waiting list** for sold-out events (optional).

### 4.4 Scanner changes
- The picker lists matches and events together under the "Scanning now" rules from section 3 (the event shows its title, time and venue).
- Scanning an event ticket checks: right event, paid, not already used, and admits the right number of people for that ticket.
- Live count shows "142 of 200 admitted" against capacity.
- Entry records and scan logs gain an event reference alongside the fixture reference (the existing tables require a fixture, so these columns need a careful, backed-up change).

### 4.5 Build order
1. Venues and events (admin only).
2. Ticket types, allocation, and the capacity rule.
3. Public event page and checkout with holds.
4. Tickets, emails, order page.
5. Scanner support for events.
6. Dashboard, attendee list, exports, refunds.
7. Promo codes, add-ons, waiting list, share graphic (nice to have).

### 4.6 Decisions made (2026-10-17)
1. **Seating**: general admission by default, with optional **tables**, **reserved seats** and an **accessibility allocation**.
2. **Fees**: a **booking fee** added at checkout, set to cover what Stripe takes (see 4.7 for the legal points to check).
3. **Venues**: a **separate venues table just for events** (not mixed with match grounds). First venue: **Big J's, Campbell Park** (home ground).
4. **Members**: discounted member prices **and early access**.
5. **Free events**: a ticket is still booked and emailed, but no Stripe payment and no booking fee.
6. **Refunds**: industry standard (see 4.8).
7. **Access**: admins and committee for now; permissions are split so it can be refined later.
8. **VAT**: none. All payments through the existing Stripe account.
9. **Age limits**: set per event (none, 16+, 18+ and so on).

### 4.7 Booking fee: how it works and what to check
- Calculation: the fee is worked out so the club receives the full ticket price after Stripe's fee, using a fixed formula `fee = (price x rate + fixed) / (1 - rate)`, rounded up to the nearest penny. Rate and fixed amount are settings, so they can be changed when Stripe's prices change. Stripe's real fee varies by card type (UK or international, standard or premium), so the booking fee is a fair average, not an exact match for each payment. Confirm the current Stripe rates in the Stripe dashboard before choosing the numbers.
- Free events and complimentary tickets: no fee.
- **Check before launch (my understanding, not legal advice):**
  1. UK law restricts surcharges on consumer card payments, so the fee should be a **booking fee charged the same however the customer pays**, and should not be described as a "card fee" or "Stripe fee" on the page.
  2. Since April 2025 the UK rules on "drip pricing" require unavoidable fees to be **included in the price shown up front**, not revealed only at the last step. So the event page should show the total (for example "GBP 10.50, including GBP 0.50 booking fee"), with the fee itemised at checkout.
  3. Confirm both with the Competition and Markets Authority guidance or an adviser before going live.
- Refund of the fee: refunded when the club cancels the event; not refunded for a customer's change of mind.

### 4.8 Refunds and changes (industry standard)
- Tickets are **non-refundable** for a change of mind. (The 14-day cooling-off period does not apply to tickets for a specific date.) This must be shown clearly at checkout with a tick box.
- **Event cancelled**: full refund of the ticket and the booking fee, automatically through Stripe.
- **Event postponed or rescheduled**: tickets stay valid for the new date; a refund can be requested within a set window (suggested 14 days) if the new date does not suit.
- **Material change** (different venue, headline act or time): refund option, as above.
- **Name changes / transfers**: allowed up to a cut-off (suggested 24 hours before), by admin or via the order page.
- **Lost ticket**: resend from the order page.
- Partial refunds and goodwill refunds: admin only, always with a reason, recorded in the audit log.

### 4.9 Tables, reserved seating and accessibility
- Phase 1 (with the first release): **general admission** plus **ticket types that admit several people** (Table of 8, Table of 10).
- Phase 2: **tables**: a venue layout with numbered tables and a number of seats each; the customer picks a table or "best available"; admin can hold tables for sponsors and guests.
- Phase 3: **reserved seats**: a simple seat map for a venue. The most complex part, so only if a real event needs it.
- **Accessibility** (a pool of places held back from general sale):
  - Wheelchair spaces and step-free places, with a **free companion/carer ticket**.
  - Hearing-loop or other access needs recorded on the booking.
  - Released to customers who request them (or shown as a separate ticket type with a short explanation), and unsold spaces can be released to general sale by admin a set time before the event.
  - Access needs about a person's health are sensitive personal data: collect only what is needed, tell the customer why, keep it with the booking and delete it after the event period.

### 4.10 Early access and member prices
- Each ticket type has a **sale window** and an **audience**: everyone, members only, or members first (early window, then everyone).
- Suggested definition of a member: a person with an **active season ticket** for the current season (this uses the existing accounts and the members portal login). To be confirmed whether you also have another kind of membership.
- Early access length is set per event (for example 48 hours before general sale).
- Alternative for people without an account: an emailed early-access code.
- The member price shows only to a logged-in member.

### 4.11 Age-limited events
- Each event has a **minimum age** (none, 14+, 16+, 18+, or "under 16 with an adult").
- The event page and the ticket show the restriction clearly, and checkout requires a tick box to confirm the attendees meet it.
- For age-limited events the buyer enters a **name for each ticket**.
- ~~The scanner shows a "Check ID: 18+" prompt.~~ **Dropped by the owner (2026-10-18): not needed.** The age limit is shown and confirmed at checkout only.

### 4.12 Venues (separate from match grounds)
- New table for **event venues**: name, address and postcode, map link, photo, notes (parking, access), total capacity, optional areas (name and capacity), accessibility facilities.
- First record: **Big J's, Campbell Park**. A photo already exists on the site (`uploads/site/big-jays.jpg`).
- The capacity rule from 4.1 applies per venue, or per area when an event uses only part of the venue.

### 4.13 Permissions
Separate capabilities so the committee's access can be narrowed later:
- `events_view` (see events and sales), `events_manage` (create and edit), `events_refund` (refund and cancel), `events_scan` (door and scanner only; volunteers).
- For now admins and committee get all but scan-only.

### 4.14 Still needed from you
1. **Big J's capacity** and any areas within it (and which are used for events).
2. **Members**: is a member only an active season ticket holder, or is there another membership?
3. **Early access** length (for example 48 hours?) and default member discount (a percentage or a set price per event?).
4. **Booking fee**: percentage plus pence per ticket (confirm Stripe rates) or a simple flat amount per order, and a cap?
5. **Maximum tickets per order** (default suggestion: 10).
6. **Refund window** for postponed events (suggested 14 days) and name-change cut-off (suggested 24 hours).
7. **Which committee role(s)** should get events access (the roles that already exist in the system).
8. Who the **organiser contact** and reply-to email should be for events.

## 5. Progress log

### 2026-10-18: Phase 1 (safer scanner) built
Superseded decisions: section 1 (kind/visibility on fixtures) is replaced by the separate events table in section 4. Section 3 changed: the scanner now shows **only today's home match**, and scanning any other day is **blocked** (refused by the server and logged as "Wrong day"), not just warned.

Built: today-only picker with opponent badge; no bottom nav on the picker; Back closes Manual/Recent first, then needs a second press to leave; result screens (success 2.5s, refusal 4s, tap to dismiss); repeated codes ignored until out of camera view; clearer refusal wording (cancelled, unknown); problems log no longer stores full order tokens; x buttons on sheets.

Test setup (remove when finished): hidden season id 92 "TEST (scanner)", fixtures 664 and 665, free test orders 42 to 48, QR sheet `admin/scan_test_sheet.php`, usage recorder `scan/ui_log.js` and `admin/scan_ui_log.php` (table `scan_ui_events`, season 92 only).

Decisions for Phase 2 (events) recorded: capacity 30 (Big J's, no areas); member = active season ticket holder, 48h early access, 10% default discount; booking fee on the whole order 1.5% + 20p; no per-order ticket limit; postponement refund window 28 days; name-change cut-off 24h; admins + all committee (refunds admin-only); reply-to to be decided (suggested tickets@saltcoatsvictoria.co.uk); Google Wallet only for now. Build order: picker (done), events MVP, demo site.

### 2026-10-18: Phase 2 (Events MVP) steps 1 to 5 built, plus clean-up pass
Built: events and venues, ticket types with allocation and capacity rule, member prices and early access, 31-minute holds with atomic ordering, booking fee (1.5% + 20p once per order, editable on Events > Settings), public Events pages, free-ticket checkout (verified live), Stripe checkout and webhook (NOT tested: Stripe is live), QR order page and confirmation email (reply-to setting), scanner support for events in the same `/scan` app (own tables, partial admission for table tickets, same-day lock), complimentary tickets (admin, reason recorded), Events links on the public site when public events exist, hold-hoarding limit (3 open unpaid orders per email or address), automatic expired-hold tidy-up, `Members first` requires a sale start time, automated tests (`admin/tests/EventsTest.php`).
Decisions: no "Check ID" prompt anywhere; age limit shown on event page, order page and emails; the scanner "today" runs until 5am; Recent check-ins ignore tickets that belong to a different match or event.
Still to build: step 6 (sales dashboard, attendee list and CSV, resend, refunds and cancellation, name changes, messages to ticket holders), accessibility pool and consent, abandoned-checkout and waiting-list emails, ticket transfer, Google Wallet, offline scanning, multi-gate door dashboard, payout reconciliation, share graphic. Then Phase 3 (demo site).
Test data still in the live database (remove when finished): hidden test season 92 with fixtures 664/665, test orders 42 to 48, QR sheet `admin/scan_test_sheet.php`, usage recorder (`scan/ui_log.js`, `admin/scan_ui_log.php`, table `scan_ui_events`); event 4 "TEST"; hidden event 7 "Free Checkout Test" (orders 99 and 100).

### 2026-10-18: test data removed
All test data was deleted after a full database backup: hidden TEST season 92 with fixtures 664/665, test orders 42 to 48, event orders 99/100, events 4 and 7, the QR test sheet, and the usage recorder (script, endpoint and table). The hidden-season filter on the public season lists was reverted. Step 6 is next.


### 2026-10-18: Step 6 built (after-the-sale tools)
Admin: per-event navigation (Details, Tickets, Sales, Attendees, Messages). **Sales** dashboard (seats taken, money in net of refunds, arrivals and no-shows, per-ticket-type sold/held/left/revenue, sales per day, cancel event and "refund everyone" in batches of 20). **Attendees** (search, filters, change a name, email the tickets again, refund one ticket or a whole order, CSV download that neutralises spreadsheet formulas). **Messages** (templates for postponed, cancelled and time change, test copy to yourself, send to all holders, history). Buyers can change the name on their ticket from the order page until 24 hours before (this is the ticket transfer: send the new holder the page). Refunds use Stripe with an idempotency key so a retry can never refund twice; seats are counted per ticket so a refunded ticket frees its seat. Permissions: view = Events view; names, resend, messages, CSV = Events manage; refunds and cancelling = Events refund (admins until granted).
NOT tested against real Stripe or real customer email (Stripe is live; the database logic was tested with a fake refund function and a fake mail function). First real refund and first real broadcast are their first live tests.
Remaining extras: accessibility ticket pool and consent line, abandoned-checkout and waiting-list emails, Google Wallet passes, offline scanning, multi-gate live door dashboard, payout reconciliation report, share graphic, promo codes. Then Phase 3 (demo site).

### 2026-10-18: offline scanning, multi-gate door dashboard, payout reconciliation built
**Offline scanning.** All scan rules now live in `admin/lib/scan_processor.php` (`scan_process_ticket`), used by live scans, offline sync and the dashboard feed. The scanner (`scan/offline.js`) keeps a saved list of today's tickets for the open match or event (hashed: SHA-256 tokens, PBKDF2 manual codes, per-target-per-day salt, no readable QR values), refreshed every 90s while online. With no signal, scans are checked on the phone and admissions are queued; the queue syncs to `admin/scan_sync.php`, which replays each scan through the same rules keeping its real time, once only (client id remembered). A ticket admitted at two offline gates is admitted by whichever syncs first; the other is flagged ("Offline duplicate" in the scan problems list). Refused offline scans are not queued. Unknown codes offline say "Not on the saved list". The service worker keeps a fallback copy of the scanner page only for offline reloads. Each phone can be given a gate name. Old scans over 48h are refused. Day rule for an offline scan uses its own time.
**Door dashboard** (`admin/door_dashboard.php`, data from `door_dashboard_data.php`, refreshes every 5s): people in vs capacity, arrivals per minute and per 5 minutes, per-gate counts, phones reporting (heartbeat every 20s) with silent/offline/queued alerts, latest scans, duplicates.
**Payout reconciliation** (`admin/payout_reconciliation.php`, `lib/payout_reconciliation.php`, finance_view): reads Stripe payouts and their transactions (read-only), matches each payment to its Hub record (event/match/season tickets, shop, sponsorship links, Hidden Team...), checks gross - refunds - fees = payout, flags payments the Hub does not recognise, amount mismatches, Hub Stripe payments missing from payouts, event booking fee vs Stripe fee, CSV export. Stripe cannot itemise manual payouts (shown as "not itemised"); a payout out by exactly an earlier manual payout is explained, not flagged. Settled payouts are cached in `stripe_payout_cache` (contains customer names/emails already held in Stripe and the Hub). First real run (2026-10-18): 4 payouts all reconcile; 3 payments (GBP 25.50) carry nothing the Hub recognises.
Not tested live: a real phone losing real signal at a gate (simulated by blocking the scan request); the legacy season-ticket branch of scanning with a real season pass (code moved unchanged).

## Progress log: promo codes, accessibility, follow-ups, Wallet (2026-10-18)
- Promo codes (percent/amount/unlock; admin tab "Promo codes"), accessible places pool with access-needs consent (deleted 30 days after), abandoned-checkout reminder (opt-in, one email 60 min after hold lapses) and waiting list (FIFO, never more people than tickets, 3h claim window, admin panel on Sales page) built and tested.
- Follow-ups run opportunistically from the event page and via `php admin/cron/events_maintenance.php` (NOT scheduled yet: add to cron/Plesk, e.g. every 10 minutes).
- Google Wallet: `admin/lib/google_wallet.php`, settings on Event Settings (issuer id, service account email, key file path outside web root), button on the order page only when configured. Untested against real Google credentials. Passes are snapshots.
- Deferred by owner: share graphic, Phase 3 demo site.
