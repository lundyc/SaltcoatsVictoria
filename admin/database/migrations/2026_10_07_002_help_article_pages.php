<?php
declare(strict_types=1);

// Link help articles to the admin pages they explain, so the "?" slide-out on
// each page can show them. `pages` is a comma-separated list of page file
// names; a trailing * is a wildcard (e.g. shop_*). Only starter articles that
// have not been edited (updated_by = 'system') get a default mapping. Idempotent.

return static function (PDO $pdo): void {
    $has = $pdo->query("SHOW COLUMNS FROM help_articles LIKE 'pages'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE help_articles ADD COLUMN pages VARCHAR(500) NOT NULL DEFAULT '' AFTER capabilities");
    }
    $map = [
        'welcome' => 'index.php',
        'your-account' => 'profile.php',
        'matchday-overview' => 'matches.php,match.php,match_*,fixture_starting_11.php,players.php,player_*,matchday_*,stats.php,monthly_fixtures.php',
        'sponsorship-overview' => 'sponsors.php,sponsor.php,sponsor_followups.php,sponsorship_*',
        'shop-and-tickets-overview' => 'shop_*,season_ticket_*,fixture_tickets.php,ticket_*',
        'secretary-overview' => 'secretary_*,discipline_*,player_registration*,fixture_change_request*,committee_meeting*',
        'website-overview' => 'news.php,news_edit.php,club_pages.php,settings_public.php',
    ];
    $stmt = $pdo->prepare("UPDATE help_articles SET pages = :pages WHERE slug = :slug AND pages = '' AND updated_by = 'system'");
    foreach ($map as $slug => $pages) {
        $stmt->execute([':pages' => $pages, ':slug' => $slug]);
    }
};
