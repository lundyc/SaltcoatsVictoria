<?php
// Renamed to inbox.php; keep old bookmarks working.
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: /admin/inbox.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
