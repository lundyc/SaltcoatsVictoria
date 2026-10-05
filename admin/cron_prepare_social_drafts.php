<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/publishing_automation.php';

$result = hub_publishing_prepare_match_drafts($pdo);
echo 'Prepared: ' . $result['prepared'] . PHP_EOL;
echo 'Skipped: ' . $result['skipped'] . PHP_EOL;
