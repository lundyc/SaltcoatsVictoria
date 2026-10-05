<?php

declare(strict_types=1);

/**
 * Public news system: news_articles + news_images. Separate from the
 * members-only `announcements` feed. Tables defined in lib/news.php.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/news.php';
    news_ensure_schema($pdo);
};
