<?php

declare(strict_types=1);

// Website settings now live in Settings > General.
header('Location: /admin/settings.php?tab=general#website-settings-section', true, 301);
exit;
