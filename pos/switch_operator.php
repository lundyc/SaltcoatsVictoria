<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/auth.php';

hub_auth_logout();
header('Location: /pos/login.php?status=' . urlencode('Logged out of Hub. Log in as a POS operator.'));
exit;
