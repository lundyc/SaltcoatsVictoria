<?php

declare(strict_types=1);

/**
 * Publishes scheduled news articles whose publish time has arrived.
 * Run every minute from cron; safe to run repeatedly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/news.php';

$count = news_publish_due($pdo);
if ($count > 0) {
    echo date('Y-m-d H:i:s') . ' — published ' . $count . ' scheduled article(s)' . PHP_EOL;
}
