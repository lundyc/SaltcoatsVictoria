<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/announcements.php';
ensureAnnouncementsSchema($pdo);

$announcements = getAnnouncements($pdo, true);
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Club Updates</div>
            <h1>Announcements</h1>
            <p>Club news, sponsor deals and offers, just for season ticket holders.</p>
        </div>
    </section>

    <?php if (!$announcements): ?>
        <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">Nothing here yet - check back soon.</div></div>
    <?php endif; ?>

    <?php foreach ($announcements as $announcement): ?>
        <section class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="small text-muted mb-1"><?= h(date('d/m/Y', strtotime((string) $announcement['published_at']))) ?></div>
                <h2 class="h5"><?= h((string) $announcement['title']) ?></h2>
                <p class="mb-0" style="white-space:pre-wrap;"><?= h((string) $announcement['body']) ?></p>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
