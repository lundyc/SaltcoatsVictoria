<?php
/** Route: /sitemap.xml */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';

pub_raw();
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = current_url_origin();
$loc = static fn (string $path): string => $origin . url($path);

/** @var list<array{0:string,1:?string}> $urls [loc, lastmod|null] — lastmod only where a real timestamp exists. */
$urls = [];
$add = static function (string $path, ?string $ts = null, ?string $image = null) use (&$urls, $loc, $origin): void {
    $lm = $ts && strtotime($ts) ? date('c', strtotime($ts)) : null;
    $img = $image ? (str_starts_with($image, 'http') ? $image : $origin . '/' . ltrim($image, '/')) : null;
    $urls[] = [$loc($path), $lm, $img];
};

// Real modification times, keyed for reuse below.
$fixtureMod = [];
foreach (db()->query('SELECT id, updated_at FROM match_fixtures')->fetchAll() as $r) {
    $fixtureMod[(int) $r['id']] = (string) $r['updated_at'];
}
$news = news_published(db(), ['limit' => 500]);
$newsMod = '';
foreach ($news as $a) {
    $newsMod = max($newsMod, (string) (($a['updated_at'] ?? '') ?: ($a['published_at'] ?? '')));
}
$fixturesMod = $fixtureMod ? max($fixtureMod) : null;

$add('', $newsMod ?: null);
$add('news', $newsMod ?: null);
$add('fixtures', $fixturesMod);
$add('results', $fixturesMod);
$add('table', $fixturesMod);
foreach (['team', 'staff', 'gallery', 'partners', 'contact', 'privacy', 'tickets'] as $p) {
    $add($p);
}

// Static club-info pages — router.php matches these before the club/{slug} CMS
// catch-all, so any club_pages row sharing one of these slugs is unreachable
// and must be skipped below to avoid a duplicate <url>.
$staticClubSlugs = ['history', 'stadium', 'officials', 'honours', 'player-awards', 'policies', 'records'];
foreach ($staticClubSlugs as $slug) {
    $add('club/' . $slug);
}

// Shop — only when enabled; storefront hub + one entry per active product.
$shopSettings = shop_get_settings(db());
if (($shopSettings['shop_enabled'] ?? '1') === '1') {
    $add('shop');
    foreach (shop_get_products(db(), ['active_only' => true]) as $product) {
        $add('shop/p/' . $product['slug'], (string) ($product['updated_at'] ?? ''));
    }
}

foreach (news_categories() as $cat) {
    $add('news/category/' . news_slugify($cat));
}
foreach ($news as $a) {
    $add('news/' . $a['slug'], (string) (($a['updated_at'] ?? '') ?: ($a['published_at'] ?? '')));
}
foreach (club_pages_menu(db()) as $cp) {
    if (!in_array($cp['slug'], $staticClubSlugs, true) && $cp['slug'] !== 'privacy') {
        $add('club/' . $cp['slug'], (string) ($cp['updated_at'] ?? ''));
    }
}
foreach (squad_public_players(db()) as $pl) {
    $add('team/' . $pl['slug']);
}
foreach (pub_galleries() as $g) {
    $add('gallery/' . $g['slug'], (string) ($g['updated_at'] ?? $g['album_date'] ?? ''), !empty($g['cover_path']) ? uploads((string) $g['cover_path']) : null);
}
// Match centre: played matches and future fixtures only. Past fixtures with no
// recorded result are noindex (see pages/match.php) so they stay out of here.
$today = date('Y-m-d');
foreach (pub_seasons() as $s) {
    foreach (pub_fixtures(['season_id' => (int) $s['id'], 'type' => 'all']) as $fx) {
        if (pub_fixture_is_played($fx) || $fx['match_date'] >= $today) {
            $add('match/' . (int) $fx['id'], $fixtureMod[(int) $fx['id']] ?? null);
        }
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach ($urls as [$u, $lastmod, $img]) {
    echo '  <url><loc>' . e($u) . '</loc>' . ($lastmod ? '<lastmod>' . e($lastmod) . '</lastmod>' : '')
        . ($img ? '<image:image><image:loc>' . e($img) . '</image:loc></image:image>' : '') . '</url>' . "\n";
}
echo '</urlset>' . "\n";
