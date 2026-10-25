<?php
declare(strict_types=1);

/** Scanner overview was merged into Event stats; keep old links working. */
header('Location: /scan/stats.php' . (isset($_GET['kind'], $_GET['id']) ? '?kind=' . rawurlencode((string) $_GET['kind']) . '&id=' . (int) $_GET['id'] : ''), true, 301);
exit;
