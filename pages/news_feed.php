<?php
/** Route: /news/feed.xml — RSS 2.0 of the latest published articles. */
declare(strict_types=1);

pub_raw();
header('Content-Type: application/rss+xml; charset=utf-8');

$origin = current_url_origin();
$articles = news_published(db(), ['limit' => 20]);
$clubName = club('club_name');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0"><channel>' . "\n";
echo '  <title>' . e($clubName . ' — News') . '</title>' . "\n";
echo '  <link>' . e($origin . url('news')) . '</link>' . "\n";
echo '  <description>' . e('Latest news from ' . $clubName) . '</description>' . "\n";
echo '  <language>en-gb</language>' . "\n";

foreach ($articles as $a) {
    $link = $origin . url('news/' . $a['slug']);
    $pub = $a['published_at'] ? date(DATE_RSS, strtotime((string) $a['published_at'])) : '';
    echo '  <item>' . "\n";
    echo '    <title>' . e($a['title']) . '</title>' . "\n";
    echo '    <link>' . e($link) . '</link>' . "\n";
    echo '    <guid isPermaLink="true">' . e($link) . '</guid>' . "\n";
    if ($pub !== '') {
        echo '    <pubDate>' . e($pub) . '</pubDate>' . "\n";
    }
    if (trim((string) $a['category']) !== '') {
        echo '    <category>' . e($a['category']) . '</category>' . "\n";
    }
    echo '    <description>' . e($a['excerpt'] ?: '') . '</description>' . "\n";
    echo '  </item>' . "\n";
}

echo '</channel></rss>' . "\n";
