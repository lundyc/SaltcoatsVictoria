<?php

declare(strict_types=1);

/**
 * One-off backfill: populate the matchday_* tables from the existing
 * starting11_*_json line-ups and admin/data/matches.json event arrays.
 *
 * Logic lives in matchday_record_backfill() (lib/matchday_record.php). It is
 * idempotent — a fixture that already has matchday_* rows is skipped — so
 * re-running is safe. The legacy stores are read, not modified.
 *
 * Preview without writing:
 *     php admin/database/migrations/2026_09_09_002_matchday_record_backfill.php --dry-run
 */

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    $dryRun = in_array('--dry-run', $_SERVER['argv'], true);
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../lib/matchday_record.php';

    $report = matchday_record_backfill($pdo, $dryRun);
    fwrite(STDOUT, ($dryRun ? '[DRY RUN] ' : '') . "matchday backfill — " . count($report) . " fixture(s) considered\n");
    foreach ($report as $fixtureId => $result) {
        if (isset($result['skipped'])) {
            fwrite(STDOUT, sprintf("  #%-5s skipped (%s)\n", $fixtureId, $result['skipped']));
            continue;
        }
        fwrite(STDOUT, sprintf(
            "  #%-5s lineup=%d bench=%d events=%d subs=%d periods=%d%s%s\n",
            $fixtureId,
            $result['lineup'] ?? 0,
            $result['bench'] ?? 0,
            $result['events'] ?? 0,
            $result['subs'] ?? 0,
            $result['periods'] ?? 0,
            !empty($result['unmatched']) ? '  unmatched: ' . implode(', ', $result['unmatched']) : '',
            isset($result['error']) ? '  ERROR: ' . $result['error'] : ''
        ));
    }
    exit(0);
}

return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/matchday_record.php';

    matchday_record_backfill($pdo, false);
};
