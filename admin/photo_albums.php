<?php

declare(strict_types=1);

/**
 * Photo albums — the club photo galleries shown at /gallery.
 * List view: create an album, jump into one to manage its photos, and toggle
 * which albums appear on the public site (bulk).
 *
 * Hiding an album only removes it from the public website — every photo stays
 * in the Media Library (categorised "Club archive") for photo tagging.
 */

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Photo albums',
    'subtitle' => 'Galleries shown at /gallery. Create albums, upload photos, link them to a game, and choose which appear on the public site.',
    'actions' => [
        ['label' => 'New album', 'href' => 'photo_album.php?id=new', 'class' => 'btn btn-brand btn-sm'],
        ['label' => 'View gallery', 'href' => '/gallery', 'class' => 'btn btn-outline-secondary btn-sm', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/history.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/media_library.php';

if ((string) ($currentRole ?? 'guest') !== 'admin') {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage photo albums.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

history_ensure_schema($pdo);

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session could not be verified. Reload the page and try again.';
    } elseif ((string) ($_POST['action'] ?? '') === 'save_visibility') {
        $posted = is_array($_POST['display'] ?? null) ? $_POST['display'] : [];

        $current = [];
        foreach ($pdo->query('SELECT id, display_on_site FROM history_galleries') as $row) {
            $current[(int) $row['id']] = (int) $row['display_on_site'];
        }

        $changed = 0;
        $stmt = $pdo->prepare('UPDATE history_galleries SET display_on_site = :v WHERE id = :id');
        $pdo->beginTransaction();
        foreach ($current as $id => $was) {
            $now = !empty($posted[$id]) ? 1 : 0;
            if ($now !== $was) {
                $stmt->execute([':v' => $now, ':id' => $id]);
                $changed++;
            }
        }
        $pdo->commit();

        if ($changed > 0) {
            $visible = (int) $pdo->query('SELECT COUNT(*) FROM history_galleries WHERE display_on_site = 1')->fetchColumn();
            $total = (int) $pdo->query('SELECT COUNT(*) FROM history_galleries')->fetchColumn();
            auditLog($pdo, 'photo_albums_visibility_updated', "Changed visibility on {$changed} album(s); {$visible} of {$total} shown on the public site.");
        }
        header('Location: photo_albums.php?status=saved&n=' . $changed);
        exit;
    }
}

$albums = $pdo->query(
    "SELECT g.id, g.title, g.slug, g.album_date, g.cover_path, g.display_on_site, g.match_fixture_id,
            (SELECT COUNT(*) FROM history_gallery_photos p WHERE p.gallery_id = g.id) AS photo_count,
            f.match_date AS fixture_date,
            COALESCE(o.clubname, f.opponent) AS fixture_opponent,
            f.is_home AS fixture_is_home
     FROM history_galleries g
     LEFT JOIN match_fixtures f ON f.id = g.match_fixture_id
     LEFT JOIN match_opponents o ON o.id = f.opponent_id
     ORDER BY g.album_date IS NULL, g.album_date DESC, g.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$total = count($albums);
$visible = 0;
foreach ($albums as $a) {
    $visible += (int) $a['display_on_site'] === 1 ? 1 : 0;
}

$statusKey = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$savedCount = (int) ($_GET['n'] ?? 0);
$statusMessages = [
    'created' => 'Album created.',
    'deleted' => 'Album deleted.',
];
?>

<div class="photo-albums-page">
    <?php if ($statusKey === 'saved'): ?>
        <div class="alert alert-success"><?= $savedCount > 0 ? h((string) $savedCount) . ' album' . ($savedCount === 1 ? '' : 's') . ' updated.' : 'No changes to save.' ?></div>
    <?php elseif (isset($statusMessages[$statusKey])): ?>
        <div class="alert alert-success"><?= h($statusMessages[$statusKey]) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endforeach; ?>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="badge text-bg-primary"><?= h((string) $visible) ?> of <?= h((string) $total) ?> shown on site</span>
            <span class="text-muted small mb-0">Hidden albums stay in the Media Library under “Club archive”.</span>
        </div>
    </div>

    <?php if ($albums === []): ?>
        <div class="alert alert-info">No albums yet. Use <strong>New album</strong> to create one.</div>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_visibility">

            <div class="d-flex justify-content-end mb-2">
                <button type="submit" class="btn btn-brand btn-sm">Save visibility</button>
            </div>

            <div class="card hub-table-card">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 hub-data-table">
                        <thead>
                            <tr>
                                <th style="width:64px;">Cover</th>
                                <th>Album</th>
                                <th>Linked game</th>
                                <th style="width:80px;" class="text-center">Photos</th>
                                <th style="width:120px;" class="text-center">Show on site</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($albums as $album): ?>
                                <?php
                                $id = (int) $album['id'];
                                $cover = trim((string) $album['cover_path']);
                                $coverUrl = $cover !== '' ? hub_media_web_url('uploads/' . $cover) : '';
                                $fixtureLabel = '';
                                if ($album['match_fixture_id'] !== null && $album['fixture_opponent'] !== null) {
                                    $fixtureLabel = ((int) $album['fixture_is_home'] === 1 ? 'v ' : 'at ') . (string) $album['fixture_opponent']
                                        . ($album['fixture_date'] ? ' · ' . date('j M Y', strtotime((string) $album['fixture_date'])) : '');
                                }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($coverUrl !== ''): ?>
                                            <img src="<?= h($coverUrl) ?>" alt="" loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="photo_album.php?id=<?= $id ?>" class="fw-semibold"><?= h((string) $album['title'] !== '' ? (string) $album['title'] : 'Untitled album') ?></a>
                                        <div class="text-muted small">
                                            /gallery/<?= h((string) $album['slug']) ?>
                                            <?php if ($album['album_date']): ?> · <?= h(date('j M Y', strtotime((string) $album['album_date']))) ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="small"><?= $fixtureLabel !== '' ? h($fixtureLabel) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-center"><?= (int) $album['photo_count'] ?></td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-flex justify-content-center">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="display_<?= $id ?>" name="display[<?= $id ?>]" value="1"
                                                   <?= (int) $album['display_on_site'] === 1 ? 'checked' : '' ?>>
                                            <label class="visually-hidden" for="display_<?= $id ?>">Show “<?= h((string) $album['title']) ?>” on the site</label>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-brand btn-sm">Save visibility</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
