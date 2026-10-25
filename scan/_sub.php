<?php
declare(strict_types=1);

/**
 * Shared frame for the scanner's own sub pages (Scanner overview, Event stats): same maroon/gold look as /scan/,
 * with a back button to the scanner home. Staff only; sends people to the login page and back again.
 */
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/admissions.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/scan/')));
    exit;
}

function scan_sub_open(string $title, string $subtitle = ''): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f3c53a">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?> | Ticket Scanner</title>
    <link rel="manifest" href="/scan/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/scan/icon-192.png">
    <link href="/admin/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        :root { --scan-maroon:#4b0818; --scan-gold:#e0b42a; --scan-bg:#f7f4ef; --scan-ink:#24151b; --scan-muted:#746872; --scan-line:#eadfdf; }
        html, body { min-height:100%; margin:0; background:var(--scan-bg); color:var(--scan-ink); font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body { padding-bottom:calc(24px + env(safe-area-inset-bottom)); }
        .sub-bar { display:flex; align-items:center; gap:.9rem; padding:calc(12px + env(safe-area-inset-top)) 16px 14px; background:linear-gradient(180deg,#f3c53a 0%,#e9b522 100%); color:#3a0713; }
        .sub-back { width:44px; height:44px; flex:none; border-radius:50%; border:1px solid rgba(58,7,19,.35); background:rgba(58,7,19,.08); color:#3a0713; display:grid; place-items:center; text-decoration:none; }
        .sub-bar h1 { font-size:1.35rem; font-weight:850; margin:0; line-height:1.15; }
        .sub-bar small { display:block; color:rgba(58,7,19,.78); font-size:.85rem; font-weight:500; }
        .sub-body { padding:16px; max-width:760px; margin:0 auto; }
        .sub-tabs { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.25rem; margin-bottom:1rem; }
        .sub-tab { flex:none; padding:.55rem .95rem; border-radius:999px; border:1px solid rgba(75,8,24,.25); background:#fff; color:var(--scan-maroon); font-weight:700; text-decoration:none; font-size:.92rem; }
        .sub-tab.is-on { background:var(--scan-maroon); color:#fff; border-color:var(--scan-maroon); }
        .sub-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:1rem; }
        .sub-stat { background:#fff; border:1px solid var(--scan-line); border-radius:16px; padding:.9rem 1rem; }
        .sub-stat span { display:block; color:var(--scan-muted); font-size:.82rem; font-weight:600; }
        .sub-stat strong { display:block; font-size:2rem; line-height:1.1; font-variant-numeric:tabular-nums; }
        .sub-stat small { color:var(--scan-muted); }
        .sub-stat.is-good strong { color:#15803d; }
        .sub-stat.is-bad strong { color:#b42335; }
        .sub-panel { background:#fff; border:1px solid var(--scan-line); border-radius:16px; padding:1rem; margin-bottom:1rem; }
        .sub-panel h2 { font-size:1rem; font-weight:850; color:var(--scan-maroon); margin:0 0 .7rem; }
        .sub-bars { display:flex; align-items:flex-end; gap:3px; height:80px; }
        .sub-bars span { flex:1; background:var(--scan-maroon); opacity:.85; border-radius:3px 3px 0 0; min-height:2px; }
        .sub-bartext { display:flex; justify-content:space-between; color:var(--scan-muted); font-size:.78rem; margin-top:.3rem; }
        .sub-progress { height:10px; background:#eee4e4; border-radius:99px; overflow:hidden; margin-top:.6rem; }
        .sub-progress div { height:100%; background:#15803d; width:0; transition:width .4s; }
        .sub-row { display:flex; justify-content:space-between; gap:1rem; border-radius:12px; padding:.7rem .8rem; margin-bottom:.5rem; border:1px solid var(--scan-line); }
        .sub-row.is-success { background:#eefaf3; border-color:#bce8cc; }
        .sub-row.is-danger { background:#fff0f1; border-color:#f4b8c0; }
        .sub-row strong { display:block; overflow-wrap:anywhere; }
        .sub-row span { color:#6f6470; font-size:.85rem; }
        .sub-row .sub-time { text-align:right; font-weight:800; color:var(--scan-maroon); white-space:nowrap; }
        .sub-alert { border-radius:12px; padding:.7rem .9rem; margin-bottom:.75rem; font-weight:600; font-size:.92rem; background:#fff7e0; border:1px solid #f1d98a; color:#5b4300; }
        .sub-alert.is-danger { background:#fff0f1; border-color:#f4b8c0; color:#7a1020; }
        .sub-empty { color:var(--scan-muted); padding:1.5rem 0; text-align:center; }
        .sub-note { color:var(--scan-muted); font-size:.8rem; text-align:center; }
    </style>
</head>
<body>
<header class="sub-bar">
    <a class="sub-back" href="/scan/" aria-label="Back to the scanner"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
    <h1><?= h($title) ?><?php if ($subtitle !== ''): ?><small><?= h($subtitle) ?></small><?php endif; ?></h1>
</header>
<main class="sub-body">
<?php
}

function scan_sub_close(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** Today's matches and events as tab links to $page, with the chosen one marked. */
function scan_sub_tabs(array $targets, ?array $current, string $page): void
{
    if (count($targets) < 2) {
        return;
    }
    echo '<nav class="sub-tabs" aria-label="Today">';
    foreach ($targets as $t) {
        $on = $current && $t['kind'] === $current['kind'] && $t['id'] === $current['id'];
        echo '<a class="sub-tab' . ($on ? ' is-on' : '') . '" href="' . h($page . '?kind=' . $t['kind'] . '&id=' . (int) $t['id']) . '">' . h($t['title']) . '</a>';
    }
    echo '</nav>';
}
