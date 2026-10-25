<?php
/** Route: /robots.txt */
declare(strict_types=1);

pub_raw();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = current_url_origin();
echo "User-agent: *\n";
echo "Allow: /\n";
// Private tools, customer-specific URLs and downloads. (Not a security control —
// every one of these is also protected by authentication or a noindex response.)
//
// Deliberately NOT listed: shop/basket, shop/checkout (they send noindex —
// see SEO_NOINDEX_ROUTES — and Google can only obey a noindex it is allowed to
// fetch; blocking them here produced Search Console "Blocked by robots.txt"
// alerts) and the public /season-tickets and /playersponsors landing pages,
// which fans search for. Only their checkout steps are blocked.
foreach ([
    'admin/', 'api/', 'members/', 'pos/', 'scan/', 'uploads/secretary_documents/',
    'shop/order/', 'tickets/order/',
    'season-tickets/checkout', 'playersponsors/checkout', 'p/', 'table/pdf',
] as $path) {
    echo 'Disallow: ' . url($path) . "\n";
}
echo "\nSitemap: " . $origin . url('sitemap.xml') . "\n";
