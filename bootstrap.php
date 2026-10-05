<?php

declare(strict_types=1);

/*
 * Public site bootstrap.
 *
 * Deliberately does NOT include the Hub's auth.php / header.php: those force a
 * redirect to login.php for anonymous visitors. The public site is read-only
 * and unauthenticated, so it wires up just the pieces it needs.
 */

require __DIR__ . '/config.php';

/*
 * Shared Hub code — the complete coupling surface between the two apps. Keep
 * this list short and explicit so that splitting them later is a mechanical
 * job (copy these into public/lib/hub/ or point HUB_ROOT at a shared folder).
 *
 *   db.php            provides $pdo (PDO); also pulls in config.php + session
 *   lib/functions.php small view helpers (h(), gbp(), date formatting)
 */
require HUB_ROOT . '/db.php';
require HUB_ROOT . '/lib/functions.php';
require HUB_ROOT . '/lib/site_settings.php';
require HUB_ROOT . '/lib/news.php';
require HUB_ROOT . '/lib/club_pages.php';
require HUB_ROOT . '/lib/squad_public.php';
require HUB_ROOT . '/lib/history.php';

/** @var PDO $pdo — promoted to a global so db() can hand it to page/lib code. */
$GLOBALS['pdo'] = $pdo;

/*
 * Club configuration: shipped defaults overlaid with anything saved in the Hub.
 * Wrapped so a not-yet-migrated database still renders (defaults only).
 */
try {
    $GLOBALS['pub_settings'] = site_settings_all($pdo);
} catch (Throwable) {
    $GLOBALS['pub_settings'] = site_settings_defaults();
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/router.php';
require __DIR__ . '/lib/seo.php';
require __DIR__ . '/lib/imgopt.php';
require __DIR__ . '/lib/legacy_redirects.php';

// Read-only view-model queries for the public pages.
require __DIR__ . '/lib/fixtures.php';
require __DIR__ . '/lib/partners.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/staff.php';
require __DIR__ . '/lib/history.php';
