<?php

declare(strict_types=1);

// The website profile now lives on the main player edit page.
header('Location: player_edit.php?id=' . (int) ($_GET['id'] ?? 0) . '#website-profile', true, 301);
exit;
