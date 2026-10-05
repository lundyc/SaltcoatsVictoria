<?php
declare(strict_types=1);

// Compatibility shim — the member auth system was merged into
// account_auth.php (staff + members are now one accounts table,
// distinguished by season_ticket_holders.role). Every member_auth_*
// function name and return shape is unchanged, so every file that
// requires this file keeps working without edits. See
// /root/.claude/plans/velvet-exploring-cook.md for the full design.
// Safe to require alongside auth.php in the same request (e.g.
// tickets_shop.php does) — both are require_once shims for the same
// underlying file, so it only actually loads once.

require_once __DIR__ . '/account_auth.php';
