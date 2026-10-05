<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

hub_auth_logout();
header('Location: login.php?status=' . urlencode('Logged out.'));
exit;
