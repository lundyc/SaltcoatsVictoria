<?php
/** Homepage: featured-news hero (or branded fallback) + next match +
 *  league position + recent results + latest news + partner strip (footer). */
declare(strict_types=1);

$seasonLabel = seo_season_label();
set_meta([
    'title' => club('club_name') . ' (' . club('club_short_name', club('club_name')) . ') | Official Club Website',
    'title_full' => '1',
    'description' => 'Official website of ' . club('club_name') . '. The latest fixtures, results, news, squad information, tickets and club information'
        . (club('ground_name') !== '' ? ' from ' . club('ground_name') : '') . '.',
]);

pub_jsonld(seo_club_node());
pub_jsonld([
    '@type' => 'WebSite',
    'name' => club('club_name'),
    'url' => current_url_origin() . '/',
    'publisher' => ['@id' => current_url_origin() . '/#club'],
    'inLanguage' => 'en-GB',
]);

$featured = news_featured(db(), 4);
$next = pub_next_fixture();
$results = pub_recent_results(4);
$latestNews = news_published(db(), ['limit' => 6]);
$ourRow = pub_league_our_row();
?>
<h1 class="sr-only"><?= e(club('club_name')) ?> — official website</h1>
<?php if ($featured !== []): ?>
  <?php partial('hero_featured', ['items' => $featured]); ?>
<?php else: ?>
  <section class="hero">
    <div class="container">
      <img class="hero__crest" src="<?= e(club_crest_reverse()) ?>" alt="">
      <p class="hero__name"><?= e(club('club_name')) ?></p>
      <p><?= e(club('club_nickname')) ?> &middot; <?= e(club('club_tagline')) ?></p>
      <div class="hero__actions">
        <a class="btn btn--light" href="<?= e(url('fixtures')) ?>">Fixtures</a>
        <a class="btn btn--ghost" href="<?= e(url('news')) ?>">Latest news</a>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="matchbar">
  <div class="container">
    <?php partial('next_match', ['fixture' => $next]); ?>

    <a class="matchbar__league" href="<?= e(url('table')) ?>">
      <img class="matchbar__league__crest" src="<?= e(uploads('competitions/competition-white-badge-20260721065339-0a69a31b.png')) ?>" alt="">
      <?php if ($ourRow): ?>
        <span class="matchbar__pos">
          <b><?= e(pub_ordinal((int) ($ourRow['pos'] ?: 0))) ?></b>
        </span>
        <span class="matchbar__divider"></span>
        <span class="matchbar__stats">
          <table>
            <thead><tr><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead>
            <tbody><tr>
              <td><?= e($ourRow['p'] ?? '') ?></td>
              <td><?= e($ourRow['w'] ?? '') ?></td>
              <td><?= e($ourRow['d'] ?? '') ?></td>
              <td><?= e($ourRow['l'] ?? '') ?></td>
              <td><?= e($ourRow['gd'] ?? '') ?></td>
              <td><?= e($ourRow['pts'] ?? '') ?></td>
            </tr></tbody>
          </table>
        </span>
      <?php else: ?>
        <span class="matchbar__pos"><b>&mdash;</b></span>
        <span class="matchbar__empty">League standings will appear here once available.</span>
      <?php endif; ?>
    </a>
  </div>
</section>

<?php if ($results !== []): ?>
<section class="band">
  <div class="container">
    <div class="band__head">
      <div><span class="eyebrow">Match day</span><h2>Recent results</h2></div>
      <a class="linkarrow" href="<?= e(url('results')) ?>">All results</a>
    </div>
    <div class="results-rail">
      <?php foreach ($results as $fixture): ?>
        <?php partial('match_card', ['fixture' => $fixture]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="band band--surface">
  <div class="container">
    <div class="band__head">
      <div><span class="eyebrow">Latest</span><h2>Club news</h2></div>
      <a class="linkarrow" href="<?= e(url('news')) ?>">All news</a>
    </div>
    <?php if ($latestNews === []): ?>
      <div class="emptystate"><p>The club news feed is being set up and will appear here shortly.</p></div>
    <?php else: ?>
      <div class="news-grid">
        <?php foreach ($latestNews as $article): ?>
          <?php partial('news_card', ['article' => $article]); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
