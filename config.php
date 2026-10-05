<?php

declare(strict_types=1);

/*
 * Public site configuration + portability knobs.
 * ---------------------------------------------------------------------------
 * During development the public site lives at  httpdocs/public/  and is served
 * from the URL path  /public .  When it goes live the Hub moves into its own
 * folder and the contents of  public/  move up to  httpdocs/ .  At that point
 * only the constants in this file need to change:
 *
 *   PUBLIC_BASE   -> ''            (served from the domain root)
 *   HUB_ROOT      -> <new path to the shared Hub code, or a vendored copy>
 *   UPLOADS_BASE  -> unchanged ('/uploads' stays at the domain root)
 *
 * See public/README.md for the full move procedure.
 */

// Absolute filesystem path of the public site itself.
define('PUBLIC_ROOT', __DIR__);

// Absolute filesystem path to the shared Hub code (config.php, db.php, lib/*).
// This is the ENTIRE filesystem coupling to the Hub — see bootstrap.php.
define('HUB_ROOT', __DIR__ . '/admin');

// URL path the public site is mounted at, no trailing slash. '' = domain root.
define('PUBLIC_BASE', '');

// URL path that user-uploaded media (crests, sponsor logos, photos) is served
// from. Lives at the domain root now and after the restructure, so it is kept
// separate from PUBLIC_BASE.
define('UPLOADS_BASE', '/uploads');

// Short-lived fragment cache (league-table scrape, expensive widgets).
define('PUBLIC_CACHE_DIR', PUBLIC_ROOT . '/cache');

/*
 * Club identity, brand colours, contact details and social links all live in
 * the `site_settings` table (edited from the Hub: settings_public.php). Read
 * them with club('key') — shipped defaults are in lib/site_settings.php
 * (site_settings_defaults). bootstrap.php loads them into $GLOBALS['pub_settings'].
 */
