<?php
declare(strict_types=1);

/*
 * SEO regression checks. Pure helpers are tested directly; page behaviour is
 * checked over HTTP against SEO_TEST_BASE (default: the production origin), so
 * these run read-only against a live site. Targeted assertions, no HTML snapshots.
 */

require_once dirname(__DIR__, 2) . '/lib/seo.php';

$seoBase = rtrim((string) (getenv('SEO_TEST_BASE') ?: 'https://myclubhub.co.uk'), '/');

$seoFetch = static function (string $path) use ($seoBase): array {
    $ch = curl_init($seoBase . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => strtolower(substr($raw, 0, $hs)), 'body' => substr($raw, $hs)];
};
$seoTag = static function (string $html, string $re): ?string {
    return preg_match($re, $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};

$harness->test('seo_prune drops empty values but keeps zero/false', function () use ($harness): void {
    $out = seo_prune(['a' => '', 'b' => null, 'c' => 0, 'd' => ['x' => '', 'y' => 'ok'], 'e' => []]);
    $harness->assertSame(['c' => 0, 'd' => ['y' => 'ok']], $out);
});

$harness->test('homepage: canonical, robots, title, JSON-LD', function () use ($harness, $seoFetch, $seoTag, $seoBase): void {
    $r = $seoFetch('/?utm_source=test&fbclid=1');
    $harness->assertSame(200, $r['status']);
    $harness->assertSame($seoBase . '/', $seoTag($r['body'], '#<link rel="canonical" href="([^"]+)"#'));
    $harness->assertTrue(str_starts_with((string) $seoTag($r['body'], '#<meta name="robots" content="([^"]+)"#'), 'index'));
    $harness->assertTrue(substr_count($r['body'], '<h1') === 1, 'exactly one h1');
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $r['body'], $m);
    $harness->assertTrue(count($m[1]) >= 1, 'has JSON-LD');
    foreach ($m[1] as $json) {
        $harness->assertTrue(is_array(json_decode($json, true)), 'JSON-LD parses');
    }
});

$harness->test('robots.txt disallows private areas and lists the sitemap', function () use ($harness, $seoFetch): void {
    $r = $seoFetch('/robots.txt');
    $harness->assertSame(200, $r['status']);
    foreach (['Disallow: /admin/', 'Disallow: /shop/checkout', 'Sitemap: '] as $needle) {
        $harness->assertTrue(str_contains($r['body'], $needle), "robots.txt contains $needle");
    }
});

$harness->test('sitemap is valid XML, served as XML, and excludes private routes', function () use ($harness, $seoFetch): void {
    $r = $seoFetch('/sitemap.xml');
    $harness->assertSame(200, $r['status']);
    $harness->assertTrue(str_contains($r['headers'], 'application/xml'), 'xml content type');
    $xml = simplexml_load_string($r['body']);
    $harness->assertTrue($xml !== false, 'parses');
    $locs = [];
    foreach ($xml->url as $u) {
        $locs[] = (string) $u->loc;
    }
    $harness->assertTrue(count($locs) === count(array_unique($locs)), 'no duplicate URLs');
    foreach ($locs as $loc) {
        $harness->assertTrue(!preg_match('#/(admin|api|members|shop/(basket|checkout|order)|tickets/order)(/|$)#', $loc), "private route in sitemap: $loc");
        $harness->assertTrue(!str_contains($loc, '?'), "query string in sitemap: $loc");
    }
});

$harness->test('unknown URLs return a real 404 with noindex and no canonical', function () use ($harness, $seoFetch): void {
    $r = $seoFetch('/definitely-not-a-page-xyz');
    $harness->assertSame(404, $r['status']);
    $harness->assertTrue(str_contains($r['headers'], 'x-robots-tag: noindex'), 'X-Robots-Tag noindex');
    $harness->assertTrue(!str_contains($r['body'], 'rel="canonical"'), 'no canonical on 404');
});

$harness->test('trailing slash 301s to the single canonical URL', function () use ($harness, $seoFetch): void {
    $r = $seoFetch('/team/');
    $harness->assertSame(301, $r['status']);
    $harness->assertTrue(preg_match('#location: [^\n]*?/team\r?\n#', $r['headers']) === 1, 'location has no slash');
});

$harness->test('transactional pages are noindex', function () use ($harness, $seoFetch, $seoTag): void {
    $r = $seoFetch('/shop/basket');
    $harness->assertSame('noindex, follow', $seoTag($r['body'], '#<meta name="robots" content="([^"]+)"#'));
});

$harness->test('news articles have unique titles/descriptions and Article JSON-LD', function () use ($harness, $seoFetch, $seoTag): void {
    $sm = $seoFetch('/sitemap.xml')['body'];
    preg_match_all('#<loc>[^<]*?(/news/[a-z0-9-]+)</loc>#', $sm, $m);
    $paths = array_slice(array_values(array_filter($m[1], static fn ($p) => !str_contains($p, '/category/'))), 0, 4);
    $harness->assertTrue(count($paths) >= 2, 'has articles to compare');
    $descs = [];
    foreach ($paths as $p) {
        $r = $seoFetch($p);
        $descs[] = $seoTag($r['body'], '#<meta name="description" content="([^"]*)"#');
        $harness->assertTrue(str_contains($r['body'], '"@type":"NewsArticle"'), "NewsArticle on $p");
        $harness->assertTrue(str_contains($r['body'], 'property="og:type" content="article"'), "og:type on $p");
    }
    $harness->assertTrue(count($descs) === count(array_unique($descs)), 'unique descriptions');
});

$harness->test('news pagination canonicals point at their own page', function () use ($harness, $seoFetch, $seoTag, $seoBase): void {
    $r = $seoFetch('/news?page=2');
    $harness->assertSame($seoBase . '/news?page=2', $seoTag($r['body'], '#<link rel="canonical" href="([^"]+)"#'));
});

$harness->test('match page: score title and SportsEvent JSON-LD', function () use ($harness, $seoFetch, $seoTag): void {
    $sm = $seoFetch('/sitemap.xml')['body'];
    preg_match('#<loc>[^<]*?(/match/\d+)</loc>#', $sm, $m);
    $r = $seoFetch($m[1]);
    $harness->assertSame(200, $r['status']);
    $harness->assertTrue(str_contains($r['body'], '"@type":"SportsEvent"'), 'SportsEvent');
    $harness->assertTrue(str_contains($r['body'], '"@type":"BreadcrumbList"'), 'BreadcrumbList');
});
