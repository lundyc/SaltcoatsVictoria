<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// match_photo_view.php — retired as a standalone page. Managing a photo (kit,
// tags, delete, edit) now happens in a modal on match_photos.php, so old links
// to this page (e.g. from people.php) redirect straight into that modal.
require_once __DIR__ . '/auth.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$photoId = (int) ($_GET['id'] ?? 0);
header('Location: /admin/match_photos.php' . ($photoId > 0 ? '?photo=' . $photoId : ''), true, 302);
exit;
