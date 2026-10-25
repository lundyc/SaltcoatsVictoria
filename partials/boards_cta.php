<?php
/** "Advertise on a pitchside board" call to action. Links to the public /pitchside-boards page. */
declare(strict_types=1);

$boardFrom = null;
try {
    require_once HUB_ROOT . '/lib/sponsorship_catalog.php';
    $amounts = [];
    foreach (['pitchside_board_small', 'pitchside_board_medium', 'pitchside_board_large'] as $boardCode) {
        $boardPkg = getSponsorshipPackageByCode(db(), $boardCode);
        if ($boardPkg && (int) $boardPkg['is_active'] === 1) {
            $amounts[] = (float) $boardPkg['amount'];
        }
    }
    $boardFrom = $amounts ? min($amounts) : null;
} catch (Throwable) {
    $boardFrom = null;
}
?>
<?php
// Photos of our boards that slowly pan behind the banner: every .webp in uploads/site/boards/ (add more any time).
$boardPhotos = [];
foreach (glob(PUBLIC_ROOT . '/uploads/site/boards/*.webp') ?: [] as $bf) {
    $boardPhotos[] = uploads('site/boards/' . basename($bf));
}
?>
<section class="boardscta" data-board-photos="<?= e((string) json_encode($boardPhotos, JSON_UNESCAPED_SLASHES)) ?>">
  <div class="container">
    <div class="boardscta__box">
      <div class="boardscta__slides" aria-hidden="true"></div>
      <div class="boardscta__text">
        <span class="eyebrow">Advertise at Campbell Park</span>
        <h2>Put your business on a pitchside board</h2>
        <p>Season-long advertising around the pitch, in front of our supporters at every home game.<?= $boardFrom !== null ? ' Boards from <strong>' . e(gbp($boardFrom)) . '</strong>, with digital coverage and social media shout-outs included.' : '' ?></p>
      </div>
      <a class="boardscta__btn" href="<?= e(url('pitchside-boards')) ?>">View pitchside boards <span aria-hidden="true">&rarr;</span></a>
    </div>
  </div>
</section>
