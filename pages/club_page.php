<?php
/** Route: /club/{slug} — an editable static page from club_pages. */
declare(strict_types=1);

$page = club_page_by_slug(db(), (string) ($slug ?? ''));
if ($page === null) {
    http_response_code(404);
    set_meta(['title' => 'Page not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Page not found</h1><p><a class="linkarrow" href="' . e(url()) . '">Back to home</a></p></div></div>';
    return;
}

$html = pub_club_page_html($page);

set_meta([
    'title' => $page['title'],
    'description' => excerpt(strip_tags($html), 180),
    // /privacy is the primary route for this page; avoid a duplicate index entry.
    'canonical' => ($page['slug'] ?? '') === 'privacy' ? url('privacy') : '',
]);
seo_breadcrumbs([['Club', url('club/history')], [(string) $page['title'], url('club/' . ($page['slug'] ?? ''))]]);
?>
<?php partial('page_hero', ['eyebrow' => 'The club', 'title' => $page['title']]); ?>

<div class="page">
  <div class="container">
    <div class="prose"><?= $html /* sanitised */ ?></div>
  </div>
</div>
