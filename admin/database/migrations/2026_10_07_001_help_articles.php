<?php
declare(strict_types=1);

// Help Centre: editable articles shown at /admin/help.php. `capabilities` is a
// comma-separated list of capability slugs; empty means every signed-in user.
// Seeds starter articles once (INSERT IGNORE on slug). Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS help_articles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        area VARCHAR(80) NOT NULL,
        title VARCHAR(190) NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        body MEDIUMTEXT NOT NULL,
        capabilities VARCHAR(255) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 100,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        updated_by VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_help_articles_slug (slug),
        KEY idx_help_articles_area (area, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seed = require __DIR__ . '/../../lib/help_seed.php';
    $insert = $pdo->prepare('INSERT IGNORE INTO help_articles
        (slug, area, title, summary, body, capabilities, sort_order, updated_by)
        VALUES (:slug, :area, :title, :summary, :body, :caps, :sort, :by)');
    foreach ($seed as $a) {
        $insert->execute([
            ':slug' => $a['slug'], ':area' => $a['area'], ':title' => $a['title'],
            ':summary' => $a['summary'], ':body' => $a['body'],
            ':caps' => implode(',', $a['caps']), ':sort' => $a['sort'], ':by' => 'system',
        ]);
    }
};
