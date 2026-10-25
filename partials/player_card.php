<?php
/** Squad grid card. $player: row from squad_public_players(). */
declare(strict_types=1);

/** @var array<string,mixed> $player */
$avatar = trim((string) ($player['avatar'] ?? ''));
$photo = $avatar !== '' ? uploads('players/' . $avatar) : '';
$number = $player['squad_number'] !== null ? (int) $player['squad_number'] : null;
$href = url('team/' . $player['slug']);
$initials = '';
foreach (preg_split('/\s+/', trim((string) $player['name'])) ?: [] as $part) {
    $initials .= mb_substr($part, 0, 1);
}
$initials = mb_strtoupper(mb_substr($initials, 0, 2));
?>
<a class="pcard" href="<?= e($href) ?>">
  <div class="pcard__media">
    <?php if ($number !== null): ?><span class="pcard__num"><?= $number ?></span><?php endif; ?>
    <?php if ($photo !== ''): ?>
      <img src="<?= e($photo) ?>" alt="" <?= !empty($priority) ? 'fetchpriority="high"' : 'loading="lazy"' ?> data-sz="175" data-initials="<?= e($initials) ?>" onerror="this.onerror=null;this.src=this.dataset.ph;this.classList.add('pcard__ph')" data-ph="<?= e(asset('img/player-placeholder.png')) ?>">
    <?php else: ?>
      <img class="pcard__ph" src="<?= e(asset('img/player-placeholder.png')) ?>" alt="" loading="lazy">
    <?php endif; ?>
  </div>
  <div class="pcard__body">
    <span class="pcard__pos"><?= e($player['position_label']) ?></span>
    <span class="pcard__name"><?= e($player['name']) ?></span>
  </div>
</a>
