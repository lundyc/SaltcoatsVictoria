<?php
declare(strict_types=1);

/**
 * Starter Help Centre articles, inserted once by the help_articles migration
 * (INSERT IGNORE on slug, so later edits in the admin are never overwritten).
 * These are drafts: Site Admins refine them at /admin/help.php.
 * `caps` empty = everyone signed in; otherwise any one capability is enough.
 */
return [
    [
        'slug' => 'welcome',
        'area' => 'Getting started',
        'title' => 'Welcome to the Hub',
        'summary' => 'What the Hub is, how the menu works and where to find things.',
        'caps' => [],
        'sort' => 10,
        'body' => <<<'MD'
The Hub is where the club runs its day-to-day business: fixtures and results, players, sponsors, the shop, tickets, the secretary's records and the public website.

## You only see what you need
The menu on the left only shows areas your login has access to. If something you expect is missing, it is not a fault. Ask a Site Admin to check your access.

## Finding your way around
- **Club overview** is your landing page. It shows reminders and key figures for your areas.
- Menu sections (Match day, Sponsorship, Shop and so on) expand when you click them.
- On a phone, use the bottom bar for your most-used areas and **More** for the full menu.
- Use the **Season** selector at the top of the menu to switch season. Most lists and figures follow the selected season.

## Tips
- Nothing is lost by looking around. Pages only change data when you press a Save, Add or Delete button.
- Destructive actions (delete, refund) always ask you to confirm first.
- Hover over (or tap and hold) an icon or button to see a short hint.
- Log out when you finish, especially on a shared computer.

## Need help?
Ask a Site Admin, or send a message to the person who set up your login.
MD,
    ],
    [
        'slug' => 'your-account',
        'area' => 'Getting started',
        'title' => 'Your login and profile',
        'summary' => 'Signing in, updating your details and keeping your account safe.',
        'caps' => [],
        'sort' => 20,
        'body' => <<<'MD'
## Signing in
Use the email address and password you were given. If you forget your password, use **Forgot password** on the login page and follow the email.

## Updating your details
Choose **Edit profile** at the bottom of the menu to change your name, contact details and password.

## Keeping it safe
- Use a password you do not use anywhere else.
- Never share your login. Each committee member has their own so changes can be traced to the right person.
- Log out on shared or public computers.
- If you think someone else knows your password, change it straight away and tell a Site Admin.
MD,
    ],
    [
        'slug' => 'matchday-overview',
        'area' => 'Match day',
        'title' => 'Match day: fixtures, results and players',
        'summary' => 'An overview of the Match day section.',
        'caps' => ['matchday'],
        'sort' => 100,
        'body' => <<<'MD'
The **Match day** section covers everything around a game.

## What's in it
- **Fixtures & results** lists every match for the selected season. Open a match to manage line-ups, record events and results, and create graphics.
- **Stats** shows season statistics.
- **Matchday income** records money taken on the day.
- **Players** is the squad list. Open a player to see or edit their details.

## Tips
- Check the **Season** selector first, so you are working in the right season.
- Open a match from Fixtures & results to find everything for that game in one place.

_This article is a short overview. Site Admins can expand it with step-by-step guides for your own routines._
MD,
    ],
    [
        'slug' => 'sponsorship-overview',
        'area' => 'Sponsorship',
        'title' => 'Sponsorship: sponsors, agreements and packages',
        'summary' => 'An overview of the Sponsorship section.',
        'caps' => ['sponsorship', 'finance_view'],
        'sort' => 200,
        'body' => <<<'MD'
The **Sponsorship** section holds the club's commercial relationships.

## What's in it
- **Sponsors** lists each sponsor and their details.
- **Follow-ups** keeps track of who needs a call or reply.
- **Agreements** records what each sponsor has agreed to.
- **Bundles** and **Packages** define what sponsors can buy.

## Tips
- Keep contact details and follow-up dates up to date, so the next person can pick up where you left off.
- Payments are only recorded by people with the right access. If you cannot see them, ask a Site Admin.

_This article is a short overview. Site Admins can expand it with step-by-step guides._
MD,
    ],
    [
        'slug' => 'shop-and-tickets-overview',
        'area' => 'Shop & ticketing',
        'title' => 'Shop and ticketing: orders and products',
        'summary' => 'An overview of the Shop and Ticketing sections.',
        'caps' => ['shop', 'tickets_ops'],
        'sort' => 300,
        'body' => <<<'MD'
## Shop
- **Orders** shows what customers have bought and lets you update an order's status.
- **Products**, **Categories** and **Modifiers** control what is on sale.
- **Discount codes** and **Shipping zones** control offers and delivery.

## Ticketing
- **Season Ticket Orders** and **Renewals** cover season passes.
- **Match Tickets** and **Ticket Orders** cover single-match sales.
- **Types & Pricing** sets what can be bought and for how much.

## Tips
- Refunds and complimentary tickets need extra access. If you cannot see those options, ask a Site Admin.
- Always check the customer's name and order number before changing an order.

_This article is a short overview. Site Admins can expand it with step-by-step guides._
MD,
    ],
    [
        'slug' => 'secretary-overview',
        'area' => 'Secretary',
        'title' => 'Secretary: registrations, discipline and meetings',
        'summary' => 'An overview of the Secretary section.',
        'caps' => ['secretary_ops'],
        'sort' => 400,
        'body' => <<<'MD'
The **Secretary** section is the club's record of formal business.

## What's in it
- **Dashboard** shows deadlines and open items.
- **Discipline register** and **Player registrations** hold official records.
- **Tasks & planner** and **Correspondence** track what needs doing and what has been received.
- **Fixture changes** logs requests to move a match.
- **Committee & AGM** and **Documents** hold meeting records and files.
- **Emergency guide** answers "what do I do if...?" for common match-day problems.

## Tips
- Log things when they happen, with the date and time.
- Check live SFA/WoSFL rules before acting on a procedure. The Emergency guide is a working guide, not the rulebook.
MD,
    ],
    [
        'slug' => 'website-overview',
        'area' => 'Website',
        'title' => 'Website: news and club pages',
        'summary' => 'Publishing news and editing the public website.',
        'caps' => ['website', 'content_social'],
        'sort' => 500,
        'body' => <<<'MD'
## News
Use **News** to write and publish articles. Published articles appear on the public news page and the home page.

## Club pages
**Club pages** are the fixed pages on the website, such as history and contact information.

## Tips
- Use **View website** in the menu to check how your changes look to the public.
- Save as a draft if you are not ready to publish.
- Check names, scores and dates before publishing. Anyone can read them.

_This article is a short overview. Site Admins can expand it with step-by-step guides._
MD,
    ],
];
