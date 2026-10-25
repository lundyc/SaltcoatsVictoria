<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/scan_problems.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/admin/scan_problems.php')));
    exit;
}
hub_auth_require_capability('tickets_ops');
scan_problems_ensure_schema($pdo);

$kindFilter = (string) ($_GET['kind'] ?? '');
if (!isset(SCAN_PROBLEM_KINDS[$kindFilter])) {
    $kindFilter = '';
}
$days = in_array((int) ($_GET['days'] ?? 7), [1, 7, 30, 90], true) ? (int) $_GET['days'] : 7;

$summary = $pdo->prepare('SELECT kind, COUNT(*) AS n FROM scan_problems WHERE created_at > (NOW() - INTERVAL ' . $days . ' DAY) GROUP BY kind ORDER BY n DESC');
$summary->execute();
$counts = $summary->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = 'SELECT p.*, f.opponent, f.match_date FROM scan_problems p LEFT JOIN match_fixtures f ON f.id = p.fixture_id
    WHERE p.created_at > (NOW() - INTERVAL ' . $days . ' DAY)' . ($kindFilter !== '' ? ' AND p.kind = :kind' : '') . ' ORDER BY p.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($kindFilter !== '' ? [':kind' => $kindFilter] : []);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Scanner problems</h1>
        <p class="text-muted mb-0">Errors, sessions that expired, no signal, camera trouble, unknown codes and slow scans. Ticket results are on the <a href="/admin/scan_overview.php">Scan</a> page.</p>
    </div>
    <form method="get" class="d-flex gap-2">
        <select class="form-select" name="days" onchange="this.form.submit()">
            <?php foreach ([1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label): ?>
                <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select" name="kind" onchange="this.form.submit()">
            <option value="">All kinds</option>
            <?php foreach (SCAN_PROBLEM_KINDS as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $k === $kindFilter ? 'selected' : '' ?>><?= h($label) ?><?= isset($counts[$k]) ? ' (' . (int) $counts[$k] . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="alert alert-success">No scanner problems recorded in this period.</div>
<?php else: ?>
    <div class="table-responsive bg-white border rounded-3">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When</th><th>Kind</th><th>Match</th><th>Message</th><th>Ref</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= h(date('D j M H:i:s', strtotime((string) $r['created_at']))) ?></td>
                    <td><span class="badge text-bg-<?= in_array($r['kind'], ['server_error', 'client_error', 'bad_response'], true) ? 'danger' : (in_array($r['kind'], ['unknown_code', 'slow_scan'], true) ? 'secondary' : 'warning') ?>"><?= h(SCAN_PROBLEM_KINDS[$r['kind']] ?? $r['kind']) ?></span>
                        <?php if ($r['source'] === 'client'): ?><span class="badge text-bg-light border">phone</span><?php endif; ?></td>
                    <td><?= $r['opponent'] ? h('vs ' . $r['opponent']) : '' ?></td>
                    <td style="min-width:260px">
                        <?= h((string) $r['message']) ?>
                        <?php if ($r['detail'] !== null && $r['detail'] !== ''): ?>
                            <details><summary class="small text-muted">Details</summary><pre class="small mb-0" style="white-space:pre-wrap"><?= h((string) $r['detail']) ?></pre>
                                <div class="small text-muted"><?= h((string) $r['user_agent']) ?> · <?= h((string) $r['ip']) ?><?= $r['http_status'] ? ' · HTTP ' . (int) $r['http_status'] : '' ?><?= $r['duration_ms'] ? ' · ' . (int) $r['duration_ms'] . ' ms' : '' ?></div></details>
                        <?php endif; ?>
                    </td>
                    <td><code><?= h((string) $r['ref']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small mt-2">Showing the latest 200. Entries are kept for 90 days.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
