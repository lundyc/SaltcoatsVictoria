<?php
/** Route: /robots.txt */
declare(strict_types=1);

pub_raw();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = current_url_origin();
echo "User-agent: *\n";
echo "Allow: /\n";
// Private, transactional and utility areas. (Not a security control — every
// one of these is also protected by authentication or a noindex response.)
foreach ([
    'admin/', 'api/', 'members/', 'pos/', 'scan/', 'uploads/secretary_documents/',
    'shop/basket', 'shop/checkout', 'shop/order/', 'tickets/order/',
    'season-tickets/', 'playersponsors/', 'p/', 'table/pdf',
] as $path) {
    echo 'Disallow: ' . url($path) . "\n";
}
echo "\nSitemap: " . $origin . url('sitemap.xml') . "\n";
