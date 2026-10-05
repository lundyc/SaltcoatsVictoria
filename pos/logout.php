<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/../admin/lib/pos.php';

hub_auth_start_session();
unset($_SESSION[POS_OPERATOR_SESSION_KEY]);
header('Location: /pos/login.php');
exit;
