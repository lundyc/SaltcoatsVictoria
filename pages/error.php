<?php
/** 404 page. */
declare(strict_types=1);

set_meta(['title' => $title ?? 'Page not found']);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Error 404',
    'title'   => $title ?? 'Page not found',
    'sub'     => "We couldn't find that page. It may have moved, or the link may be out of date.",
]); ?>

<div class="page">
  <div class="container">
    <p class="notfound__lead">Try one of these instead:</p>
    <div class="notfound__links">
      <a href="<?= e(url('fixtures')) ?>"><b>Fixtures</b><span>Upcoming games &amp; tickets</span></a>
      <a href="<?= e(url('results')) ?>"><b>Results</b><span>Latest scores</span></a>
      <a href="<?= e(url('table')) ?>"><b>League table</b><span>Current standings</span></a>
      <a href="<?= e(url('news')) ?>"><b>News</b><span>Club updates &amp; match reports</span></a>
      <a href="<?= e(url('team')) ?>"><b>First team</b><span>Squad &amp; management</span></a>
      <a href="<?= e(url('shop')) ?>"><b>Club shop</b><span>Official merchandise</span></a>
    </div>
    <p class="notfound__home"><a class="linkarrow" href="<?= e(url()) ?>">Back to home</a></p>
  </div>
</div>
