<?php
declare(strict_types=1);

require_once __DIR__ . '/header.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to view this page.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

http_response_code(410);
?>
<div class="alert alert-warning">
    Legacy Hub user deletion is retired. Disable login access or archive the person in <a href="/admin/club_people.php">People &amp; Users</a>.
</div>
<?php require __DIR__ . '/footer.php'; ?>
