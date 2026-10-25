<?php
declare(strict_types=1);

/**
 * Step-by-step Help Centre guides, inserted once by the help_guides migration
 * (INSERT IGNORE on slug, so edits made in the admin are never overwritten).
 * Written from what each screen actually offers. Site Admins refine them at
 * /admin/help.php. Row: slug, area, title, summary, caps, pages, sort, body.
 */
$g = [];
$add = static function (string $slug, string $area, string $title, string $summary, array $caps, string $pages, int $sort, string $body) use (&$g): void {
    $g[] = ['slug' => $slug, 'area' => $area, 'title' => $title, 'summary' => $summary, 'caps' => $caps, 'pages' => $pages, 'sort' => $sort, 'body' => trim($body)];
};

/* ----------------------------- Getting started ---------------------------- */

$add('club-overview', 'Getting started', 'Using the Club overview', 'Your landing page: quick actions, reminders and key figures.', [], 'index.php', 30, <<<'MD'
The **Club overview** is the page you land on after signing in. It only shows boxes for the areas you have access to.

## Common actions
Buttons near the top let you start the most frequent jobs in one click: **Add fixture**, **Add player**, **Add sponsor**, **Add sponsorship agreement**, **Write news article** and **Add shop product**. You only see the ones you are allowed to use.

## Things that need attention
Counters flag work waiting for someone, such as:
- players without sponsors and unpaid sponsorships
- fixtures that need details
- open or unactioned shop orders
- overdue secretary tasks, facilities jobs and sponsor follow-ups
- cash-up variances from matchday

Click a counter to go to the place where you can fix it. Counters clear themselves once the underlying record is updated.

## Other boxes
Depending on your access you will also see upcoming fixtures, recent results, the league position, club birthdays and income for the season.

## Tip
If you are not sure where to start, open **Reminders** in the menu. It lists everything due or overdue in one place.
MD);

$add('club-reminders', 'Getting started', 'Reminders', 'One list of due and overdue work across the club.', ['finance_view', 'matchday', 'club_setup', 'tickets_ops', 'secretary_ops'], 'club_reminders.php', 40, <<<'MD'
**Reminders** gathers operational work from football, finance, secretary, facilities and matchday into one list.

## Reading the list
- **Area** says which part of the Hub the reminder comes from.
- **Due** is the date it needs doing by.
- **Severity** is Urgent (overdue or high priority), Warning (needs attention soon) or Info (lower-risk follow-up).
- **Action** takes you to the record to deal with.

## Changing the window
Use **Window** to choose how many days ahead to look, then press **Refresh**.

## Clearing a reminder
You do not tick reminders off here. They are generated from live records, so a reminder disappears when you update the source record (for example, completing the task or recording the payment).
MD);

$add('seasons-and-locking', 'Getting started', 'Seasons and what "locked" means', 'Why some pages are read-only and how the season selector works.', [], '', 50, <<<'MD'
## The working season
The **Working season** box at the top of the menu decides which season you are looking at. Lists, stats and reports follow it. If something looks empty or wrong, check this first.

## Locked seasons
A season can be **locked** once it is finished. In a locked season you can still view fixtures, sponsor membership and events, but changes are switched off. You will see a message such as "This season is locked".

## Sponsorship deadline
Separately, each season has a **sponsorship deadline**. After it passes, player sponsorships are locked for everyone so the line-up of sponsors on kits and graphics stays fixed.

## If you need to change something in a locked season
Ask a Site Admin. Do not look for a workaround.
MD);

$add('glossary', 'Getting started', 'Glossary: club and Hub terms', 'Plain-English meanings for terms you will see in the Hub.', [], '', 60, <<<'MD'
**Access template** – a named bundle of permissions. Each person is given one or more. It decides what they can see and do.

**Agreement** – a record that a sponsor has agreed to a package for a period, with a price.

**Bundle** – several agreements for one sponsor grouped so they can be paid with a single Stripe link.

**Club role** – a label for someone's position (Treasurer, Manager and so on). It grants no access by itself.

**COMET** – the SFA's registration system. The Hub records the status you have checked there. It does not replace it.

**Complimentary** – free. A complimentary sponsorship shows on graphics but no money is due. A complimentary admission is free entry on matchday.

**Credential** – the digital pass (such as a QR code) attached to a season ticket.

**Fixture** – a match, past or future.

**Float** – the starting cash put in a till before a match, used when reconciling matchday income.

**Package** – something a sponsor can buy, such as Match Day or Match Ball sponsorship.

**Pre-order** – a shop item paid for now and collected once stock arrives.

**SFA / WoSFL** – the Scottish FA and the West of Scotland Football League, whose rules the Secretary section follows.

**Template pack** – the set of designs used to produce match graphics.

**VSN** – items marked "Manufactured by VSN" in the shop go onto a combined order form for that supplier.
MD);

$add('cannot-find-something', 'Getting started', 'I cannot see a page or button I expect', 'Why something may be missing and what to do.', [], '', 70, <<<'MD'
This is almost never a fault. The Hub hides anything your login is not allowed to use.

## Check these first
1. **Season** – is the Working season (top of the menu) the one you want?
2. **Filters** – lists remember filters. Press **Reset** or **Clear**.
3. **Locked season** – buttons are disabled in locked seasons.
4. **Phone** – on a small screen open **More** to see the full menu.

## Still missing?
Ask a Site Admin to look at your access. Tell them the page or button you were looking for and what you were trying to do. They can check your access templates and any individual extras on your person record.
MD);

/* -------------------------------- Match day ------------------------------- */

$add('find-and-filter-fixtures', 'Match day', 'Finding fixtures and results', 'Filters, search and the season scope on the Matches page.', ['matchday'], 'matches.php', 110, <<<'MD'
**Fixtures & results** lists matches for the working season, upcoming first and finished matches newest first.

## Narrowing the list
Use the filter bar:
- **Season scope** – the current season, an older one, or **All seasons** for the whole history.
- **View** – All matches, Upcoming or Finished.
- **Venue** – Home & away, Home or Away.
- **Outcome** – Wins, Draws or Losses.
- **Competition** and the **search box** (opponent or date).
Press **Reset** to clear everything.

## Reading a row
Each row shows the date, opponent, result and, for the working season, sponsor slots, the total due and what is still outstanding.

## Opening a match
Click a match to open it. Everything for that game (score, sponsors, line-up, graphics) starts there.

## Importing fixtures
Under **More**, **Import fixtures** accepts a CSV or Excel file. Download the template first and keep its headings exactly. Existing fixtures are matched and updated, so check the spreadsheet carefully before importing.
MD);

$add('add-a-fixture', 'Match day', 'Adding or editing a fixture', 'Create a match and fill in its details.', ['matchday'], 'match.php', 120, <<<'MD'
## Add a fixture
Use **Add fixture** on the Club overview. Complete:
- **Match date** and **Kickoff time**
- **Competition** and, for cups or groups, **Round / stage** (optional)
- **Venue**
- **Opponent** and **Home / Away**
- **Status**
- **Notes** and, if you have it, a **VEO footage link**

Press **Save Changes**.

## If the opponent, competition or venue is missing
You can add a new one from the same screen. Search by club name or abbreviation first, to avoid duplicates. If the opponent is not found you will be asked whether to add it. Upload a badge if you have one (PNG, JPG, GIF or WebP up to 5 MB), but you can leave it blank.

## Competitions not showing?
Only competitions assigned to the season appear. Use **Manage season competitions** to assign them.

## Deleting a fixture
**Delete Fixture** removes it. Only do this for a fixture created by mistake. Use the status field for postponed or cancelled matches instead.
MD);

$add('record-a-result', 'Match day', 'Recording a result', 'Enter the score and mark the match as played.', ['matchday'], 'match.php,match_record_events.php', 130, <<<'MD'
1. Open **Fixtures & results** and click the match.
2. Find **Quick Score**.
3. Enter either score. A **full-time score marks the fixture as played**.
4. Press **Save Changes**.

## Then add the detail
- **Match events** (goals, cards and so on) go in the Match events screen. See "Recording match events".
- **Line-ups** feed player appearances and stats. See "Building the starting 11".

## Checks
- Is the score entered for the right team? Home is listed first.
- Is the Working season the right one? In a locked season results cannot be changed.
MD);

$add('record-match-events', 'Match day', 'Recording match events', 'Goals, cards and substitutions with minute and player.', ['matchday'], 'match_record_events.php,match_events.php', 140, <<<'MD'
Match events build player stats, the match timeline and graphics.

## Before you start
Fill in the **line-up** first. The player list then auto-completes when you record events.

## Adding an event
Choose:
- **Type** (goal, card and so on)
- **Team**
- **Minute**
- **Player** and, where relevant, **Assisted by / related player**
- an optional **Note**

Press **Apply**.

## Fixing a mistake
Open the event list on the same screen and correct or remove the entry. In a locked season events can be viewed but not changed.

## Why accuracy matters
Stats pages count goals, cards, appearances and clean sheets from these records. A missing event means a missing stat.
MD);

$add('starting-11', 'Match day', 'Building the starting 11', 'Pick the XI and substitutes, record availability and make the graphic.', ['matchday'], 'fixture_starting_11.php,match_starting_11*.php,match_lineups.php', 150, <<<'MD'
Open the match and choose **Line-ups** (Starting 11).

## Starting XI
Choose a player for each position, set the display order and mark the **captain**. A player can appear only once.

## Substitutes
Pick up to **9 substitutes**.

## Availability
Use the **Availability** tab to record who is available, with a **Status**, **Reason** (work, injury, suspension) and **Notes**. Press **Save availability**.

## Graphic
Under **Background** upload the image for the artwork (JPG, PNG or WEBP, uploads automatically), choose a **Template pack**, then create the graphic.

## Saving
The line-up and artwork **save automatically**. "All changes saved" tells you it is safe to leave.
MD);

$add('match-graphics-and-sponsor-thanks', 'Match day', 'Match graphics and sponsor thank-yous', 'Create and download graphics, including the sponsor shoutout.', ['matchday', 'content_social'], 'match_graphics.php,match_poster.php,match_next_match.php,match_fixtures_poster.php,match_event_graphic.php', 160, <<<'MD'
## Match graphics
From a match, open **Match Graphics**. The workspace builds images for the match using the chosen template pack. Use **Download PNG** to save one.

## Thanking sponsors
On the match page, **Match Sponsors Shoutout** produces a thank-you graphic for the Match Day and Match Ball sponsors.
- Only **paid, complimentary or zero-value** Match Day / Match Ball sponsorships are included.
- Check the **template preview**, then **Download Graphic**.
- If you are posting, the **Social Composer** lets you choose where. Use **Close Without Posting** to leave without publishing.

## Before posting anything
Read the text and check names, the score and the date. Posts are public.
MD);

$add('players-add-and-manage', 'Match day', 'Adding and managing players', 'Create players, set their status and keep records tidy.', ['matchday'], 'players.php,player_add.php,player_edit.php,player_view.php', 170, <<<'MD'
## Find a player
**Players** lists the squad. Use **Search players** or filter by **Status** and **Sponsor**. The status chips show counts for Current squad, Trialists, Injured, On loan, Former players and Retired.

## Add a player
Use **Add player** and complete **Name**, **Date of birth**, **Status** and the joined/left dates, then tick **Active squad member** if they are in the squad. Press **Save player**.

Save first. Only then can you upload a **profile picture** and **action shots**.

## Editing
Open a player and choose **Edit**. You can change details, status and position, upload a square photo, and manage sponsorships.

## Photos
- **Profile picture**: a square JPG, PNG, GIF or WebP works best.
- **Action shots**: extra photos used for Player of the Match, Goal and Player Sponsor graphics. Tagged match photos appear on the player's page but are not used for those graphics.

## Notes
The **Player notes** box on a player's page is private. Add a note with **Add note**.

## Deleting
Delete a player record only when it should be removed from the system altogether. For someone who has left, change their **Status** (and Left at date) instead.

## Exporting
**Export** on the Players page downloads the list.
MD);

$add('matchday-income-balance-sheet', 'Match day', 'Matchday income: the balance sheet', 'Record floats, takings, outgoings and sign-off for a home game.', ['matchday', 'finance_view'], 'matchday_finance.php,matchday_finance_edit.php', 180, <<<'MD'
**Matchday income** has one balance sheet per home game.

## Open a sheet
Choose the fixture from the list. The **Sheet** column shows **Not recorded**, **Draft** or **Final**.

## Fill it in
1. **Takings on the day** – for each cash point enter the **Starting float** and the amount **Counted at close**. Takings are worked out for you.
2. **Other income** – money not counted as cash in the tills.
3. **Outgoings** – anything paid out on the day. Leave blank if there was none.
4. **Matchday balance** – the totals work themselves out.
5. **Attendance & sign-off** – enter **Attendance**, **Completed by**, **Checked by** and the **Status**.

## Draft or Final
Keep **Draft** while you are still counting. Change to **Final** once checked by a second person.

## Tip
Do the count with a second person and write the names in. It protects everyone.
MD);

$add('stats-and-reports', 'Match day', 'Using Stats and opponent reports', 'Team and player figures, year-on-year comparisons and scouting reports.', ['matchday'], 'stats.php', 190, <<<'MD'
## Team and player stats
**Stats** shows recent form, home and away performance, player contributions, Player of the Match awards and a match-by-match breakdown.
- Click a column heading to sort, and again to reverse.
- Stats come from recorded line-ups and match events. Minutes are estimated over 90, unused substitutes do not count as appearances and clean sheets apply to the starting goalkeeper.

## Comparing seasons
Tick **at least two seasons** under "Seasons to compare", optionally narrow by competition or venue, choose which player metrics to show and press **Compare**.

## Opponent scouting reports
Drag a **COMET matches export (JSON)** for an upcoming opponent onto the upload box, or click to browse. Once uploaded you can preview it or download it as a **PDF**, a **PowerPoint**, or create a short **video (MP4)**.

## If the numbers look wrong
Check that results, line-ups and events have been recorded for the matches in question.
MD);

/* ------------------------------- Sponsorship ------------------------------ */

$add('add-a-sponsor', 'Sponsorship', 'Adding and editing a sponsor', 'Details, logos, social links and notes.', ['sponsorship', 'finance_view'], 'sponsors.php,sponsor.php', 210, <<<'MD'
## Find a sponsor
**Sponsors** is the directory. Search by name or notes and filter by payment, status, coverage, Facebook link or package. Tick **Main sponsors only** or **Missing logo only** to narrow it down.

## Add a sponsor
Use **Add sponsor**, then fill in the business details:
- **Sponsor name**, **address**, **website**, **contact number** and **contact email** (this email is used to send Stripe payment links)
- tick **This sponsor is a business** if it is one
- tick **Sponsor is active**

## Logos
Upload **both** a coloured and a white image so the logo stays readable on different graphic backgrounds. Transparent PNG or WebP is best (up to 5 MB).

## Social channels
Paste the sponsor's **Facebook Page** URL and its numeric ID is found automatically. This lets social captions mention the sponsor.

## This season
**Current season status** records whether the sponsor is **Active this season** or **Left this season**.

## Notes
Use **Add note** for anything the next person should know.

## Roles and money
Sponsorship roles such as Main Sponsor are now handled by **agreements**. See "Creating a sponsorship agreement".
MD);

$add('create-sponsorship-agreement', 'Sponsorship', 'Creating a sponsorship agreement', 'Connect a sponsor to a package, period and club asset.', ['sponsorship', 'finance_view'], 'sponsorship_agreements.php,sponsorship_agreement.php', 220, <<<'MD'
An agreement records **who sponsors what, for which period, and how much is due**.

## Create one
Use **Add agreement** (or **Add sponsorship agreement** on the Club overview).
1. Choose the **Sponsor** first.
2. Choose the **Package**. The form changes to suit it.
3. Set the **Season**, and the **Team**, **Fixture** or **Player** it applies to if relevant.
4. Enter **Start date** and **End date**.
5. Enter the **Agreed amount** and **Status**.
6. Add **Notes**, choose the **Logo version** and the graphic **display order** (lower numbers appear first).
7. Press **Save agreement**.

## Match-day packages
Matchday, Match Ball and MOTM packages show a **fixture checklist** so you can cover several games at once. Use **Select all** or **Select none**, and tick **Home fixtures only** if needed.

## Pricing several fixtures
- **£ per fixture** prices each game separately.
- **One total, split across ticked fixtures** is for a deal such as "£450 for the rest of the season". It divides evenly (any odd penny lands on the last fixture).

## Free promotion
Tick **Complimentary / Free promotion** and the sponsor appears on graphics but no money is due or can be recorded.

## Filtering the list
The agreements list can be filtered by status, sponsor, package and season.
MD);

$add('record-sponsor-payment', 'Sponsorship', 'Recording and correcting sponsor payments', 'Add a payment, chase a balance and generate an invoice.', ['sponsorship_payments', 'finance_manage'], 'sponsorship_agreement.php,sponsor.php', 230, <<<'MD'
Payments are recorded against an **agreement**.

## Add a payment
1. Open the agreement.
2. Under **Agreement payments**, add the **date**, **method** (Cash, Bank Transfer, Cheque, Card, Stripe or Other), **amount** and a **note**.
3. Save. The **Outstanding balance** updates straight away.

## Mark everything paid at creation
When creating an agreement you can tick **Mark every created agreement as paid in full now** and give the paid date and method.

## Correcting a mistake
Remove the wrong payment from the list and add the correct one. The outstanding balance is recalculated.

## Invoices
Use **Generate invoice** on the agreement. You can then **Copy link**, **Copy message** or **Email to sponsor**.

## Things you cannot do here
Refunds and Stripe payment links need extra access. If you cannot see those options, ask a Site Admin.

## Complimentary agreements
Nothing is due and no payment can be recorded.
MD);

$add('sponsor-bundles', 'Sponsorship', 'Bundles: one payment link for several agreements', 'Group a sponsor\'s agreements so they pay once.', ['sponsorship', 'finance_view'], 'sponsorship_bundles.php,sponsorship_bundle.php', 240, <<<'MD'
A **bundle** groups several agreements for one sponsor so they can be paid with a **single Stripe link**.

## Create a bundle
Use **Add bundle**, choose the sponsor and add the agreements to include.

## Reading the list
Filter by status or sponsor. Each bundle shows how many items it holds, its value and what is outstanding.

## Removing an agreement from a bundle
The agreement and its payment history are unchanged. It is simply no longer grouped.

## Deleting a bundle
Deleting a bundle keeps its agreements. They are just no longer grouped.
MD);

$add('sponsor-follow-ups', 'Sponsorship', 'Sponsor follow-ups', 'Track renewal calls, leads and next steps.', ['sponsorship', 'finance_view'], 'sponsor_followups.php', 250, <<<'MD'
**Follow-ups** is the commercial to-do list.

## Add one
Use **Add follow-up** and enter the **Sponsor**, what it is about (for example "Renew pitch-side board"), the **Stage**, **Owner**, **Next contact** date, **Expected value** and **Priority**. Add **Notes** for context.

## Working the list
- **Overdue** and **Urgent** counts show what needs doing first.
- After a call, edit the follow-up, record the **Last contact**, set the next date and **Save**.
- When it is won or finished, close it. It moves to **Closed follow-ups**.

## Tip
Always give a follow-up an owner and a next contact date. Items without them get forgotten.
MD);

$add('assign-player-sponsors', 'Sponsorship', 'Assigning sponsors to players', 'Choose home, away and third kit sponsors for each player.', ['sponsorship_assign', 'sponsorship'], 'assign_sponsors.php', 260, <<<'MD'
Use **Assign Sponsors** to set which sponsor appears on each player's kit.

1. Find a player with the filter ("Type a player name…").
2. Choose the **Home**, **Away** and **Third** sponsor slot sponsors.
3. Changes **save automatically**.

The summary shows current players, players with sponsors, total slots and active sponsors.

## When you cannot change anything
After the season's **sponsorship deadline** player sponsorships are locked for everyone. Some people can view this page without editing it.
MD);

$add('sponsorship-packages', 'Sponsorship', 'Sponsorship packages', 'What sponsors can buy.', ['sponsorship', 'finance_view'], 'sponsorship_packages.php,sponsorship_package.php,sponsorship_types.php,sponsorship_type.php', 270, <<<'MD'
**Packages** define what sponsors can support across the club, teams, matches, players and digital channels.

The catalogue shows each package's order, default price, duration, graphics and status. Use **Add package** to create one, or open one to edit it.

Packages are the choices offered when creating an **agreement**. Changing a package's default price does not change agreements that already exist.
MD);

/* ------------------------------ Shop & ticketing -------------------------- */

$add('shop-overview-page', 'Shop & ticketing', 'The shop overview', 'Sales, orders and stock at a glance.', ['shop'], 'shop_overview.php', 310, <<<'MD'
The **Club Shop** overview shows **Revenue**, **Paid orders**, orders **Awaiting collection** and **Low stock**, with recent orders and best sellers.

## Useful buttons
- **Add product**, **View orders**, **View storefront**
- **Export sales CSV**
- **Order form (PDF)** and **Mark all as sent** for VSN items

## Warnings to act on
- **Stripe is not configured yet** – customers cannot pay. Tell a Site Admin.
- **The storefront is currently switched off** – the public cannot buy.
- **VSN items not yet sent** – send the order form, then mark them as sent.

Use the **From** date and **Filter** to change the period.
MD);

$add('shop-process-orders', 'Shop & ticketing', 'Handling shop orders', 'Check, collect, cancel and refund orders.', ['shop'], 'shop_orders.php,shop_order.php', 320, <<<'MD'
## Find an order
**Orders** can be filtered by status, searched by reference, name or email, and narrowed to **Pre-orders only**. **Export CSV** downloads the list.

## Open an order
You will see the items, customer details, a timeline and the available actions.

## Actions
- **Mark as paid (manual)** – for payments taken outside the website, such as bank transfer. Add a note saying how.
- **Mark as collected** – once the customer has their items (paid orders only).
- **Cancel order** – for orders that have not been paid.
- **Refund** – enter the amount (up to the amount available) and a reason: Customer request, Duplicate, Fraudulent or Other.
- **Generate invoice**.
- **Add internal timeline note** – a private note for the team.

## Adding an order yourself
Use **Add manual order** for sales taken in person or by phone.

## VSN items
**Download VSN order form (PDF)** lists only VSN-manufactured items, with size and customer details. After sending it, use **Mark all as sent to VSN**.

## Careful
Check the customer's name and order reference before changing an order. Refunds cannot be undone.
MD);

$add('shop-add-a-product', 'Shop & ticketing', 'Adding and editing products', 'Prices, stock, images, sizes and pre-orders.', ['shop'], 'shop_products.php,shop_product.php,shop_categories.php,shop_modifiers.php', 330, <<<'MD'
Open **Products** and choose **Add product**, or click an existing one.

## Basics
**Product name**, a **Short description** (one line under the name) and a full **Product description**. Tick every **category** it belongs to (the first ticked is the main one).

## Price and sale
Enter the price. Use **Sale starts** and **Sale ends** for a timed offer. Leave blank for no sale.

## Stock
Tick **Track stock quantity**, enter the **Quantity** and choose how to treat orders when it runs out (**Do not allow**, **Allow, but notify customer** or **Allow**). The **Low stock threshold** drives the warning on the shop overview.

## Sizes and options
Add attributes such as sizes. If there are none, customers simply add the item to their basket.

## Images
JPG, PNG or WebP up to 6 MB. Click an image to mark it for removal, then save.

## Special settings
- **Manufactured by VSN** – puts it on the VSN order form.
- **Sell this item as a pre-order** – pay now, collect when stock arrives.
- **Sold individually** – limits to one per order.
- **Featured product** – highlights it.
- **Visible in the storefront** – untick to hide it.

## Shipping
Set **Weight**, **Dimensions** and a **Shipping class** so delivery is priced correctly.

Press **Save** and use **View storefront** to check it.
MD);

$add('shop-discounts-and-shipping', 'Shop & ticketing', 'Discount codes and shipping zones', 'Offers at checkout and what delivery costs.', ['shop'], 'shop_discount_codes.php,shop_shipping.php,shop_shipping_classes.php,shop_settings.php', 340, <<<'MD'
## Discount codes
Under **Discount codes** create a **Code** (for example SUPPORTER10), choose its **Type** and **Value**, and optionally set a **Min spend**, **Max uses**, and **Starts** and **Ends** dates. Untick **Active** to switch it off without deleting it.

## Shipping zones
**Shipping zones** says where you deliver and what each region pays.
- Pick a zone on the left or add a new one (for example Ayrshire, Rest of UK, Europe).
- Add the countries it covers. UK zones can be limited to **postcode areas** (such as KA, G, PA).
- Add a delivery **method** with a name customers see and a **price rule**: a fixed price, free over a basket value, or weight bands.
- Zones are matched in order, and the "Everywhere else" zone catches the rest.

A zone with no methods means customers there cannot choose delivery.
MD);

$add('season-tickets-orders', 'Shop & ticketing', 'Season ticket orders', 'Find an order, add a ticket, invoice, cancel or refund.', ['tickets_ops'], 'season_ticket_orders.php,season_ticket_order.php', 350, <<<'MD'
**Season Ticket Orders** shows season passes attached to people, with their order, payment and credential records.

## Find an order
Search by name, email, code or order number, and filter by season, ticket type, order status, payment, method, ticket status and credential. **Reset** clears the filters.

## Add a ticket
Use **Add ticket** and choose the person and ticket type. People come from **People & Users**, so create the person there first if needed.

## Invoices
**Generate invoice** on the order.

## Cancel or refund
- **Cancel Ticket** asks for a reason, cancels the ticket and **revokes the credential**.
- **Record Refund** takes an amount and reason. A **full refund revokes the credential**.

Refunds need extra access. If the button is missing, ask a Site Admin.

## Free sign-up codes
**Free links** takes you to free signup codes.
MD);

$add('season-tickets-renewals-and-types', 'Shop & ticketing', 'Renewals and ticket types', 'Remind lapsed holders and manage what is on sale.', ['tickets_ops'], 'season_ticket_renewals.php,season_ticket_types.php,season_ticket_type.php,season_ticket_free_codes.php', 360, <<<'MD'
## Renewals
**Renewal reminders** lists holders who paid last season but have not yet renewed. Tick the people to chase and press **Send reminder to selected**. The list shows the last time each person was reminded, so you do not nag.

It needs a current season and a previous season to compare. If everyone has renewed it will say so.

## Types and pricing
**Types & Pricing** is the catalogue of season ticket types, with order, name, code, price and status. Use **Add ticket type** to create one.

## Free signup codes
**Free Signup Codes** creates codes that let someone sign up without paying.
MD);

$add('match-tickets', 'Shop & ticketing', 'Match tickets: setup and orders', 'Choose what is sold for each home game and manage orders.', ['tickets_ops'], 'fixture_tickets.php,ticket_orders.php,ticket_order.php,ticket_packages.php,fixture_ticketing_dashboard.php', 370, <<<'MD'
## Set up a fixture
1. Open **Match Tickets**.
2. Choose the **Home fixture** and press **Load fixture**.
3. For each **Package**, set its **Price** and **Online allocation**, and tick **Active** for the ones to sell.
4. Press **Save Ticket Setup**.

The **Sold** column shows how many have gone.

## Manage orders
**Ticket Orders** lists public purchases with payment state and digital ticket links. Open an order to:
- **Send Ticket Email** again
- **Generate invoice**
- **Cancel Ticket** or **Refund Ticket** (each asks for a reason)

## Good to know
Digital QR tickets only scan once the order is marked as **paid**. The order timeline records when it was created, paid, emailed and checked in.
MD);

$add('complimentary-admission-guide', 'Shop & ticketing', 'Complimentary admissions', 'Record free entry for guests, officials and media.', ['tickets_ops', 'tickets_refund_comp'], 'complimentary_admission.php', 380, <<<'MD'
Use **Complimentary Admission** to record sponsor guests, officials, media, committee guests and anyone else given free matchday entry.

1. Choose the **Fixture**.
2. Choose the **Category**.
3. Enter the **Quantity**.
4. Add a **Reason / notes**.
5. Press **Record Complimentary Entry**.

Recording these keeps attendance honest and shows who got in free.
MD);

/* -------------------------------- Secretary -------------------------------- */

$add('secretary-tasks', 'Secretary', 'Tasks and season planner', 'Every deadline in one place, each with an owner and a date.', ['secretary_ops'], 'secretary_tasks.php,secretary_task_edit.php,secretary_dashboard.php', 410, <<<'MD'
The **Dashboard** shows what needs your attention and what is due within 14 days. **Tasks & planner** is the full list.

## Add a task
Use **Add task** and enter:
- the **task**, for example "Submit cup entry form"
- **Category**: Correspondence, Registration, Discipline, Fixture, Committee/Governance, Compliance or Other
- **Owner** (who is doing it) and **Due date**
- **Priority**: Low, Normal or Urgent
- **Status**: Open, Waiting or Done
- **Source** (for example "WoSFL email") and **Notes**

## Rule of thumb
Every action should have an **owner** and a **due date**.

## Working the list
Open tasks appear soonest due first. Completed tasks move to **Completed**. Overdue and urgent counts show what to do first.
MD);

$add('secretary-correspondence', 'Secretary', 'Logging correspondence', 'SFA, WoSFL, competition and opposition emails and letters.', ['secretary_ops'], 'secretary_correspondence.php,secretary_correspondence_edit.php', 420, <<<'MD'
Use **Log correspondence** for anything that needs a record or a response.

## Fill in
**Subject**, **From**, **Received** date, **Deadline**, **Action owner**, **Status**, **File/email reference** (where the original is saved) and **Notes**.

## Labels
Choose the label that matches the next step: ACTION - URGENT, ACTION, WAITING, FIXTURE, REGISTRATION, DISCIPLINE, COMMITTEE or FILED.

## Deadlines
If the item contains a deadline, **put the deadline in the task system before closing it**.

## Closing
When dealt with, set the label to **FILED**. It moves out of the open list.
MD);

$add('secretary-registrations', 'Secretary', 'Player registrations (COMET)', 'Record the checked registration status for each player.', ['secretary_ops'], 'player_registrations.php,player_registration_edit.php', 430, <<<'MD'
The **Player Registrations** page shows each current player's registration status, latest event, COMET status and when eligibility was checked. Players are marked **Confirmed eligible**, **Needs checking** or **Not eligible**.

## Log a registration
Use **Log registration** and enter:
- **Player** and **Registration type**
- **COMET reference** and **Submitted date/time**
- **Current COMET status**
- **Confirmation to give the manager** and when it was sent
- where the evidence is stored
- tick that the **competition-specific eligibility/registration deadline** was checked

## The golden rule
**Entered is not the same as accepted.** Check the status again in COMET and record what you actually saw before telling anyone a player is available.

## History
Previous events for the player are listed on the same screen.
MD);

$add('secretary-discipline', 'Secretary', 'The discipline register', 'Log cards, verify them and track suspensions.', ['secretary_ops'], 'discipline_register.php,discipline_incident.php', 440, <<<'MD'
**Discipline register** helps you verify logged cards against COMET and the SFA and track active suspensions.

## Log an incident
Use **Log incident** and enter the **Player**, **Card type**, **Fixture** (or "Not tied to a specific fixture"), **Incident date** and **Competition**.

## Verify and record the outcome
Set the **Status**:
- **Unverified** – logged but not yet checked
- **Suspension confirmed** – checked and confirmed
- **Cleared** – checked and closed, kept for reference

Then fill in the **Suspension summary** (for example "2-match ban, WoSFL League only – confirmed via COMET"), **Eligible again from**, **Next check date** and the **evidence / reference**.

## The dashboard
- **Cards awaiting Secretary action** lists cards that still need checking.
- **Active suspensions** counts people currently banned.
- Cleared entries are kept for reference.

## Tip
A red card can mean an automatic suspension straight away. Check, do not wait to be emailed.
MD);

$add('secretary-fixture-changes', 'Secretary', 'Fixture change requests', 'Track the approval trail for moving a match.', ['secretary_ops'], 'fixture_change_requests.php,fixture_change_request_edit.php', 450, <<<'MD'
**An agreement between two clubs does not make a change official.** This page tracks the approval trail.

## Log a request
Use **Log request** and enter the **Fixture**, who **requested** it, the **reason**, and the requested **new date**, **kick-off** and **venue**.

## Approval checklist
Work through the checklist on the request, following the competition's rules. Set the **Status** as it progresses.

## Outcome
Finished requests move to **Confirmed & rejected**. Until a change is confirmed, treat the original fixture as the official one.
MD);

$add('secretary-meetings-and-documents', 'Secretary', 'Committee meetings, AGMs and documents', 'Minutes, actions and where to keep records.', ['secretary_ops'], 'committee_meetings.php,committee_meeting_edit.php,secretary_documents.php', 460, <<<'MD'
## Meetings
Use **Add meeting** and choose the **type**, **date**, **status** and **location**, and the date of the next meeting.
- Record **Attended** and **Apologies** (one name per line).
- Write **Minutes**: decisions made, not a transcript of every argument.
- Add **Actions** with an owner and due date.

For an AGM, work through the **AGM checklist**: constitution version, notice issued correctly, reports and accounts prepared, nominations and elections, and attendance/quorum.

## Documents & records
**Add a document** by choosing a **Category**, a **Title**, a **File** and a short **Description**, then press **Upload**. Tick **Restricted** for sensitive or safeguarding content so it is protected.

## Emergency guide
The **Emergency guide** answers "what do I do if...?" for common problems, such as a red card or an unplayable pitch. Always check the live SFA/WoSFL rules before acting.
MD);

/* --------------------------------- Website -------------------------------- */

$add('website-write-news', 'Website', 'Writing and publishing a news article', 'From draft to scheduled or live.', ['website', 'content_social'], 'news.php,news_edit.php', 510, <<<'MD'
Open **News** and press **Write article**.

## The article
- **Title** and a **Summary** (one or two sentences shown on cards).
- **Article** text in the editor.
- **URL slug** is generated from the title. Do not change it after publishing, or old links break.

## Images
- **Hero image** is the big image at the top and on cards. Pick from the Media Library or drop a new photo in, then click or drag on the preview to choose what stays in view.
- **Gallery** adds extra photos below the article.

## Settings
- **Status** and **Publish date & time**. A **future date means Scheduled** and goes live automatically. Now or past publishes immediately.
- **Feature on the home page**
- **Category** and **Byline**
- **Link to match** (optional)
- **SEO overrides** for meta title and description if needed

## Save
Drafts and future-dated posts stay hidden from the public. Use the link in the list to see the live article.

## Before publishing
Read it through, and check names, scores and dates.
MD);

$add('website-club-pages', 'Website', 'Editing club pages', 'The fixed pages on the website, written in Markdown.', ['website'], 'club_pages.php', 520, <<<'MD'
**Club pages** are static pages shown under /club on the website.

## Edit or create
Select a page on the left or choose **New page**. Fill in:
- **Title** and **Slug** (generated from the title if blank)
- **Menu label** – leave blank to **hide it from menus**
- **Sort order** and **Published**
- **Content** in Markdown

## Markdown quick guide
- `## Heading` for a heading
- `- item` for a bullet list
- `**bold**` for bold
- `[text](https://example.com)` for a link

Save and use the link to check how it looks. **Delete** removes a page permanently.
MD);

$add('website-settings-guide', 'Website', 'Website settings', 'Identity, colours, contact details and social links.', ['website'], 'settings_public.php', 530, <<<'MD'
**Website settings** control identity, brand colours, ground and contact details, invoicing and social links.

Changes are **live on the website immediately**, so check before saving.

The **Invoicing** section sets the letterhead shown on every generated invoice (sponsorship agreements, shop and ticket orders). Leave a field blank to omit it.

Use **Open website** to see the result.
MD);

$add('media-library-guide', 'Website', 'The Media Library', 'Upload, find and edit images.', ['content_social', 'website', 'admin_settings'], 'match_photos.php,media.php,photo_albums.php,photo_album.php,people.php', 540, <<<'MD'
The **Media Library** holds every Hub image: match photos, sponsors, badges and more.

## Upload
Use **Upload images**. For match photos choose the **match**, and optionally the **kit** worn. People in each photo are **tagged automatically**, so check the results afterwards.

If a file with the same name exists you can **Continue with a new name** or **Overwrite existing**.

## Find
Search by name or folder and filter by category, match, person or kit. Sort newest or oldest first.

## Edit
Open an image and use **Edit image** to crop, change the aspect ratio or transform it. You can **Undo**, **Redo** and **Reset all edits**, then **Save** or **Save as new**.

## Photo tags and albums
**Photo tags** manages the people recognised in photos. **Photo albums** groups photos for the website.
MD);

/* ------------------------------ Finance & reports -------------------------- */

$add('reports-guide', 'Finance & reports', 'Building and exporting reports', 'Choose a report, filter it and download it.', ['finance_view', 'matchday'], 'reports.php', 610, <<<'MD'
**Reports** lets you build the report you need, filter it your way, then export it. You only see reports you have access to.

## Steps
1. Pick a report from its group.
2. Set the filters, such as season, sponsor, player, payment method or status, and press **Apply filters**.
3. Use **Search this report** for a quick look-up.
4. Export the result.

## Examples
- **Outstanding balances** – who owes what.
- **Renewals watchlist** – sponsors coming up for renewal.
- **Agreement ledger** and **Payment ledger** – sponsorship money.
- **Season ticket ledger** and **comparison**.
- **Fixtures & results**, **Squad roster & coverage** and **Goals & bookings log**.

## Comparing seasons
The detailed season comparison lets you choose a main season and one to compare against.

## Tip
If a number looks wrong, check the Working season and the filters before assuming the data is wrong.
MD);

/* ------------------------------- Administration --------------------------- */

$add('admin-add-committee-member', 'Administration', 'Giving a committee member a login', 'Create the person, give them an account and choose their access.', ['admin_settings'], 'club_people.php,club_person.php', 710, <<<'MD'
Everything starts at **People & Users**. There is one record per real person.

## Steps
1. Find the person with **Search**. If they are not there, press **Add person** and fill in their name and **email**.
2. Open them and go to the **Account** tab. If it says "Hub access has not been created", create the account. An email address must be on their Details tab first.
3. Either **Set password now**, or choose **Email reset link** (or **Copy reset link**) so they set their own. A reset link is safer.
4. Under **Access & Permissions**, tick the **access templates** that fit their job and **Save templates**.
5. Optional: give **individual extras and removals**, which add or take away one permission on top of the templates.
6. On the **Club Position(s)** section add their role, such as Treasurer, with a start date.

## Remember
- **Club roles are labels only.** They grant no access. Access comes only from templates and individual extras.
- The "What they can actually do" summary shows their real access. Check it.
- Give people only what they need.

## Tell them
Send them the login link, and point them to the Help Centre and the **Welcome** tour.
MD);

$add('admin-access-templates', 'Administration', 'Access templates and club roles', 'How permissions are organised.', ['admin_settings'], 'access_roles.php,access_role_edit.php,positions.php,access_review.php', 720, <<<'MD'
## Access templates
An **access template** is a named set of permissions. Everyone on a template changes when you edit it. Open **Access templates** to see each template's capabilities, how many people use it and which club roles suggest it.

## Club roles
**Club roles** group people on People & Users (Committee, Football Department, Other). A role can **suggest** an access template, but only suggests. It grants nothing on its own.

## Access review
**Access review** helps check who has what. Use it periodically, especially when people leave.

## Principles
- Prefer templates over individual extras.
- Remove access when someone steps down.
- The Administrator template gives everything. Keep it to Site Admins.
MD);

$add('admin-maintain-help', 'Administration', 'Maintaining the Help Centre', 'How Site Admins add and edit help and tours.', ['admin_settings'], 'help.php,help_edit.php', 730, <<<'MD'
## Edit an article
Open any article and press **Edit**, or press **New article** on the Help Centre.

- **Area** groups it on the Help Centre.
- **Show on these pages** links it to the **?** button on those pages. Use page file names (for example `sponsors.php`); `*` is a wildcard (`shop_*`).
- **Who can see it**: tick none for everyone, or choose the permissions needed. People need at least one.
- **Order** – lower numbers appear first.
- **Published** – untick to keep a draft that only Site Admins see.

## Writing style
Short steps, plain words, and the exact button names people see on screen. Link to a related guide in the same way you would write any Markdown link, using `/admin/help.php?a=the-article-slug`.

## Field hints
Developers can add small **ⓘ** hints beside confusing fields with `hub_help_hint('text')`.

## Tours
Guided tours are defined in code (`lib/help_tours.php`) because they point at things on the page. Ask the developer if a tour needs changing.

## Keep it fresh
When a screen changes, update its guide the same day.
MD);

return $g;
