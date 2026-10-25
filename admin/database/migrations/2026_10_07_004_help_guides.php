<?php
declare(strict_types=1);

// Step-by-step Help Centre guides for every area. INSERT IGNORE on slug, so
// articles already edited (or deleted and re-created) in the admin are never
// overwritten. Idempotent.

return static function (PDO $pdo): void {
    $guides = require __DIR__ . '/../../lib/help_seed_guides.php';
    $insert = $pdo->prepare('INSERT IGNORE INTO help_articles
        (slug, area, title, summary, body, capabilities, pages, sort_order, updated_by)
        VALUES (:slug, :area, :title, :summary, :body, :caps, :pages, :sort, :by)');
    foreach ($guides as $a) {
        $insert->execute([
            ':slug' => $a['slug'], ':area' => $a['area'], ':title' => $a['title'],
            ':summary' => $a['summary'], ':body' => $a['body'],
            ':caps' => implode(',', $a['caps']), ':pages' => $a['pages'],
            ':sort' => $a['sort'], ':by' => 'system',
        ]);
    }
};
