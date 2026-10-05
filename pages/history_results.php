<?php
/**
 * Route: /club/results — retired. The results archive was merged into the live
 * match system (tools/merge_history_into_fixtures.php); every season now lives on
 * /results behind its season filter. Kept as a permanent redirect so old links
 * and search results still land somewhere sensible.
 */
declare(strict_types=1);

header('Location: ' . url('results'), true, 301);
exit;
