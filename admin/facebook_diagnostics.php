<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Facebook Diagnostics</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/admin/assets/css/style.css" rel="stylesheet"></head><body class="hub-shell"><main class="hub-main"><div class="container-fluid"><div class="alert alert-danger mb-0">You do not have permission to view Facebook diagnostics.</div></div></main></body></html>';
    exit;
}

require_once __DIR__ . '/header.php';

$posts = $pdo->query(
    "SELECT sp.*, COALESCE(u.display_name, u.username, 'System') AS created_by_name,
            (SELECT COUNT(*) FROM facebook_duplicate_attempts fda WHERE fda.matched_post_id = sp.id) AS duplicate_attempts
     FROM social_posts sp
     LEFT JOIN users u ON u.id = sp.created_by
     WHERE sp.platform = 'facebook'
     ORDER BY sp.created_at DESC, sp.id DESC
     LIMIT 150"
)->fetchAll();

$xShares = $pdo->query(
    "SELECT sp.*, COALESCE(u.display_name, u.username, 'System') AS created_by_name,
            (SELECT COUNT(*) FROM facebook_duplicate_attempts fda WHERE fda.matched_post_id = sp.id) AS duplicate_attempts
     FROM social_posts sp
     LEFT JOIN users u ON u.id = sp.created_by
     WHERE sp.platform = 'x'
     ORDER BY sp.created_at DESC, sp.id DESC
     LIMIT 100"
)->fetchAll();

$duplicates = $pdo->query(
    "SELECT fda.*, sp.post_type AS matched_post_type, sp.caption AS matched_caption, sp.external_post_id AS matched_external_post_id, sp.platform AS matched_platform,
            COALESCE(u.display_name, u.username, 'System') AS attempted_by_name
     FROM facebook_duplicate_attempts fda
     LEFT JOIN social_posts sp ON sp.id = fda.matched_post_id
     LEFT JOIN users u ON u.id = fda.attempted_by
     ORDER BY fda.attempted_at DESC, fda.id DESC
     LIMIT 100"
)->fetchAll();

$overrideCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM social_posts WHERE platform = 'facebook' AND is_override = 1"
)->fetchColumn();

$matchLabelCache = [];
function facebook_diagnostics_match_label(?int $fixtureId): string
{
    global $matchLabelCache;
    if ($fixtureId === null || $fixtureId <= 0) {
        return '—';
    }
    if (array_key_exists($fixtureId, $matchLabelCache)) {
        return $matchLabelCache[$fixtureId];
    }
    $match = matches_find_by_id(matches_load_all(), (string) $fixtureId);
    $label = $match !== null ? matches_fixture_label($match) : ('Fixture #' . $fixtureId);
    $matchLabelCache[$fixtureId] = $label;
    return $label;
}

function facebook_diagnostics_status_badge(string $status): string
{
    $map = [
        'published' => 'success',
        'prepared' => 'info',
        'failed' => 'danger',
        'publishing' => 'warning',
        'draft' => 'secondary',
        'queued' => 'info',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

function facebook_diagnostics_origin_badge(array $post): string
{
    if (!empty($post['is_override'])) {
        return '<span class="badge text-bg-warning">Manual override</span>';
    }
    return !empty($post['is_automatic']) ? '<span class="badge text-bg-secondary">Automatic</span>' : '<span class="badge text-bg-light text-dark">Manual</span>';
}

/**
 * Defence-in-depth redaction at render time too, in case of any legacy rows
 * written before this column existed. Never let a token or proof reach the
 * page.
 */
function facebook_diagnostics_safe_response(?string $json): string
{
    if ($json === null || trim($json) === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    }
    $redacted = facebook_redact_response($decoded);
    return htmlspecialchars((string) json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}
?>
<div class="container-fluid">
    <div class="settings-card hub-form-card mb-4">
        <div class="settings-card__header">
            <div>
                <p class="settings-card__eyebrow">Publishing</p>
                <h2 class="settings-card__title">Recent Facebook Posts</h2>
                <p class="settings-card__subtitle">Every Facebook publish attempt Hub actually reserved — automatic, manual, and manual overrides. Access tokens are never shown here.</p>
                <p class="settings-card__subtitle">Under the matchday publishing strategy, most live events (Goal, Card, Substitution, Penalty, general updates) are configured off for automatic Facebook publishing — those routine skips are logged to <code>logs/facebook_post.log</code> only and deliberately do not create a row here, so this table isn't cluttered with events nobody chose to publish. Rows below with a "Manual override" badge are the exceptions an administrator chose to publish anyway (<?= $overrideCount ?> to date).</p>
            </div>
        </div>
        <div class="table-responsive hub-table-card">
            <table class="table hub-data-table align-middle">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Match</th>
                        <th>Post Type</th>
                        <th>Caption</th>
                        <th>Facebook Post ID</th>
                        <th>Media ID</th>
                        <th>Status</th>
                        <th>Automatic/Manual</th>
                        <th>Duplicate Status</th>
                        <th>Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($posts === []): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No Facebook posts recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($posts as $post): ?>
                        <?php
                        $fixtureId = $post['fixture_id'] !== null ? (int) $post['fixture_id'] : null;
                        $captionSnippet = mb_strimwidth((string) $post['caption'], 0, 80, '…');
                        $duplicateAttempts = (int) $post['duplicate_attempts'];
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= htmlspecialchars((string) $post['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(facebook_diagnostics_match_label($fixtureId), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $post['post_type'], ENT_QUOTES, 'UTF-8') ?><?= $post['event_type'] !== '' && $post['event_type'] !== $post['post_type'] ? ' <span class="text-muted small">(' . htmlspecialchars((string) $post['event_type'], ENT_QUOTES, 'UTF-8') . ')</span>' : '' ?></td>
                            <td class="text-truncate" style="max-width: 240px;" title="<?= htmlspecialchars((string) $post['caption'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($captionSnippet, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-nowrap small"><?= htmlspecialchars((string) $post['external_post_id'], ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                            <td class="text-nowrap small"><?= htmlspecialchars((string) $post['media_id'], ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                            <td><?= facebook_diagnostics_status_badge((string) $post['status']) ?></td>
                            <td><?= facebook_diagnostics_origin_badge($post) ?></td>
                            <td><?= $duplicateAttempts > 0 ? '<span class="badge text-bg-warning">' . $duplicateAttempts . ' blocked</span>' : '<span class="text-muted">Original</span>' ?></td>
                            <td>
                                <?php $responseHtml = facebook_diagnostics_safe_response($post['api_response'] ?? null); ?>
                                <?php if ($responseHtml !== ''): ?>
                                    <details><summary class="small">View</summary><pre class="small mb-0" style="max-width: 360px; white-space: pre-wrap;"><?= $responseHtml ?></pre></details>
                                <?php elseif ($post['error_message']): ?>
                                    <span class="text-danger small"><?= htmlspecialchars((string) $post['error_message'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="settings-card hub-form-card mb-4">
        <div class="settings-card__header">
            <div>
                <p class="settings-card__eyebrow">Publishing</p>
                <h2 class="settings-card__title">Recent X (Twitter) Shares</h2>
                <p class="settings-card__subtitle">X publishing today is a manual compose-intent handoff (see limitations in the project report) — Hub can confirm it prepared the content and image for a human to send, not that a tweet was actually posted, hence status "Prepared" rather than "Published".</p>
            </div>
        </div>
        <div class="table-responsive hub-table-card">
            <table class="table hub-data-table align-middle">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Match</th>
                        <th>Post Type</th>
                        <th>Caption</th>
                        <th>Status</th>
                        <th>Duplicate Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($xShares === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No X shares prepared yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($xShares as $share): ?>
                        <?php
                        $shareFixtureId = $share['fixture_id'] !== null ? (int) $share['fixture_id'] : null;
                        $shareCaptionSnippet = mb_strimwidth((string) $share['caption'], 0, 80, '…');
                        $shareDuplicateAttempts = (int) $share['duplicate_attempts'];
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= htmlspecialchars((string) $share['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(facebook_diagnostics_match_label($shareFixtureId), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $share['post_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars((string) $share['caption'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($shareCaptionSnippet, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= facebook_diagnostics_status_badge((string) $share['status']) ?></td>
                            <td><?= $shareDuplicateAttempts > 0 ? '<span class="badge text-bg-warning">' . $shareDuplicateAttempts . ' blocked</span>' : '<span class="text-muted">Original</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="settings-card hub-form-card">
        <div class="settings-card__header">
            <div>
                <p class="settings-card__eyebrow">Global deduplication</p>
                <h2 class="settings-card__title">Blocked Duplicate Attempts</h2>
                <p class="settings-card__subtitle">Publish requests rejected server-side because identical content was already published or in flight.</p>
            </div>
        </div>
        <div class="table-responsive hub-table-card">
            <table class="table hub-data-table align-middle">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Platform</th>
                        <th>Post Type</th>
                        <th>Matched Post</th>
                        <th>Attempted By</th>
                        <th>Fingerprint</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($duplicates === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No duplicate publish attempts have been blocked.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($duplicates as $duplicate): ?>
                        <tr>
                            <td class="text-nowrap"><?= htmlspecialchars((string) $duplicate['attempted_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(ucfirst((string) ($duplicate['matched_platform'] ?? $duplicate['platform'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $duplicate['post_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($duplicate['matched_post_id']): ?>
                                    #<?= (int) $duplicate['matched_post_id'] ?>
                                    <?php if (!empty($duplicate['matched_external_post_id'])): ?> — <?= htmlspecialchars((string) $duplicate['matched_external_post_id'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    <div class="text-muted small text-truncate" style="max-width: 260px;"><?= htmlspecialchars(mb_strimwidth((string) $duplicate['matched_caption'], 0, 70, '…'), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) $duplicate['attempted_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars(substr((string) $duplicate['content_fingerprint'], 0, 16), ENT_QUOTES, 'UTF-8') ?>…</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
