<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/player_match_stats.php';

$playerId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $playerId]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    echo '<div class="alert alert-danger">Player not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$currentSeason = getCurrentSeason($pdo);
$stats = $currentSeason ? hub_player_match_stats($pdo, (int) $currentSeason['id'], (string) $player['name'], __DIR__ . '/../admin/data/matches.json') : null;
?>

<nav class="mb-3"><a href="sponsor.php">&larr; Sponsor a player</a></nav>

<div class="d-flex align-items-center gap-3 mb-4">
    <?php if (!empty($player['avatar'])): ?>
        <img src="/<?= h((string) $player['avatar']) ?>" alt="" class="rounded-circle" style="width:72px;height:72px;object-fit:cover;">
    <?php endif; ?>
    <div>
        <h1 class="h3 mb-0"><?= h((string) $player['name']) ?></h1>
        <p class="text-muted mb-0"><?= h(ucfirst((string) $player['status'])) ?><?= $currentSeason ? ' · ' . h((string) $currentSeason['name']) : '' ?></p>
    </div>
</div>

<?php if ($stats): ?>
<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent"><h2 class="h5 mb-0">Season stats</h2></div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['appearances'] ?></div><div class="small text-muted">Appearances</div></div>
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['starts'] ?></div><div class="small text-muted">Starts</div></div>
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['goals'] ?></div><div class="small text-muted">Goals</div></div>
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['minutes_played'] ?></div><div class="small text-muted">Minutes</div></div>
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['yellow_cards'] ?></div><div class="small text-muted">Yellow cards</div></div>
            <div class="col-4 col-md-2"><div class="h4 mb-0"><?= (int) $stats['red_cards'] ?></div><div class="small text-muted">Red cards</div></div>
        </div>
        <?php if (!empty($stats['is_goalkeeper'])): ?>
            <p class="text-center text-muted small mt-3 mb-0"><?= (int) $stats['clean_sheets'] ?> clean sheet<?= (int) $stats['clean_sheets'] === 1 ? '' : 's' ?></p>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
<p class="text-muted">No stats available yet for this season.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
