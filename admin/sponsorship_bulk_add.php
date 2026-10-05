<?php
// This page's functionality was merged into sponsorship_agreement.php (New Agreement
// shows a multi-fixture checklist for match-scope packages) so there's only one place
// to learn/maintain. Kept as a redirect so old links/bookmarks still land somewhere.
require_once __DIR__.'/db.php';
$params = [];
foreach (['bundle_id', 'sponsor_id', 'package_id', 'season_id'] as $key) {
    if (!empty($_GET[$key])) {
        $params[$key] = (int)$_GET[$key];
    }
}
header('Location: sponsorship_agreement.php' . ($params ? ('?' . http_build_query($params)) : ''));
exit;
