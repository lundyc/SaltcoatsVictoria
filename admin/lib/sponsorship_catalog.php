<?php

declare(strict_types=1);

/**
 * Throws if the signed-in account must not change player sponsorship data
 * for this season — i.e. before doing anything that inserts, updates, ends,
 * or transfers a row in `sponsorships`. Checked in this order:
 *  1. Season's sponsorship deadline passed — blocks everyone, no exceptions
 *     (including the configured editor), so a deadline is a real freeze.
 *  2. Season is_locked — existing whole-season lock.
 *  3. Signed-in account isn't the configured sponsorship editor
 *     (hub_auth_is_sponsorship_editor()) — logged to the audit trail so
 *     blocked attempts are visible, not just successful edits.
 *
 * Call this from every entry point that touches the sponsorships table
 * (assign_sponsors.php, sponsorship_delete.php, sponsor_ajax.php's
 * add_slot/delete_slot, player_edit_ajax.php's sponsorship actions) rather
 * than duplicating these checks — that's how the season-lock check ended up
 * present in some of those files and missing from others.
 */
function assertSponsorshipEditable(PDO $pdo, int $seasonId): void
{
    $season = getSeasonById($pdo, $seasonId);
    if (!$season) {
        throw new RuntimeException('Invalid season selected.');
    }
    if (!empty($season['sponsorship_deadline']) && strtotime((string) $season['sponsorship_deadline']) <= time()) {
        throw new RuntimeException('The sponsorship deadline for this season has passed. Player sponsorships are locked.');
    }
    if ((int) $season['is_locked'] === 1) {
        throw new RuntimeException('This season is locked.');
    }
    if (!hub_auth_is_sponsorship_editor()) {
        $user = hub_auth_current_user();
        auditLog($pdo, 'player_sponsorship_edit_blocked', 'Blocked sponsorship edit attempt by ' . ($user['email'] ?? $user['username'] ?? 'unknown account') . '.');
        throw new RuntimeException('You do not have permission to edit player sponsorships.');
    }
}

function ensureSponsorshipCatalogSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS packages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM packages') as $row) {
        $columns[(string)$row['Field']] = true;
    }
    $definitions = [
        'code' => "VARCHAR(80) NULL AFTER name",
        'scope' => "VARCHAR(30) NOT NULL DEFAULT 'club' AFTER code",
        'category' => "VARCHAR(80) NOT NULL DEFAULT 'Club-wide' AFTER scope",
        'duration_type' => "VARCHAR(30) NOT NULL DEFAULT 'season' AFTER amount",
        'max_slots' => "INT UNSIGNED NULL AFTER duration_type",
        'graphic_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER max_slots",
        'graphic_placement' => "VARCHAR(80) NULL AFTER graphic_enabled",
        'default_logo_variant' => "VARCHAR(20) NOT NULL DEFAULT 'white' AFTER graphic_placement",
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER default_logo_variant",
        'sort_order' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active",
        'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($definitions as $column => $definition) {
        if (!isset($columns[$column])) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN {$column} {$definition}");
        }
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM packages') as $row) {
        $indexes[(string)$row['Key_name']] = true;
    }
    if (!isset($indexes['uq_packages_code'])) {
        $pdo->exec('ALTER TABLE packages ADD UNIQUE KEY uq_packages_code (code)');
    }
    if (!isset($indexes['idx_packages_scope_active'])) {
        $pdo->exec('ALTER TABLE packages ADD KEY idx_packages_scope_active (scope, is_active, sort_order)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sponsorship_agreements (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sponsor_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NULL,
        team_id INT UNSIGNED NULL,
        fixture_id INT UNSIGNED NULL,
        player_id INT UNSIGNED NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        agreed_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_complimentary TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        display_order INT UNSIGNED NOT NULL DEFAULT 0,
        logo_variant VARCHAR(20) NOT NULL DEFAULT 'package_default',
        notes TEXT NULL,
        legacy_source VARCHAR(30) NULL,
        legacy_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sponsorship_agreement_legacy (legacy_source, legacy_id),
        KEY idx_agreements_sponsor (sponsor_id, status),
        KEY idx_agreements_package (package_id, status),
        KEY idx_agreements_season (season_id, status),
        KEY idx_agreements_fixture (fixture_id),
        KEY idx_agreements_player (player_id),
        CONSTRAINT fk_agreements_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
        CONSTRAINT fk_agreements_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
        CONSTRAINT fk_agreements_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL,
        CONSTRAINT fk_agreements_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
        CONSTRAINT fk_agreements_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE SET NULL,
        CONSTRAINT fk_agreements_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $agreementComplimentaryColumn = $pdo->query("SHOW COLUMNS FROM sponsorship_agreements LIKE 'is_complimentary'")->fetch(PDO::FETCH_ASSOC);
    if (!$agreementComplimentaryColumn) {
        $pdo->exec("ALTER TABLE sponsorship_agreements ADD COLUMN is_complimentary TINYINT(1) NOT NULL DEFAULT 0 AFTER agreed_amount");
    }

    // Bundles group multiple agreements (a board + several players + several MOTMs, etc.)
    // for one sponsor so a single combined Stripe link can be generated for all of them —
    // see stripe_create_checkout_session_for_bundle() in lib/stripe.php. Must exist before
    // the sponsorship_agreements.bundle_id foreign key below.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sponsorship_bundles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sponsor_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NULL,
        name VARCHAR(150) NULL,
        notes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bundles_sponsor (sponsor_id, status),
        KEY idx_bundles_season (season_id),
        CONSTRAINT fk_bundles_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
        CONSTRAINT fk_bundles_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $agreementBundleColumn = $pdo->query("SHOW COLUMNS FROM sponsorship_agreements LIKE 'bundle_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$agreementBundleColumn) {
        $pdo->exec("ALTER TABLE sponsorship_agreements ADD COLUMN bundle_id INT UNSIGNED NULL AFTER season_id");
        $pdo->exec("ALTER TABLE sponsorship_agreements ADD KEY idx_agreements_bundle (bundle_id)");
        $pdo->exec("ALTER TABLE sponsorship_agreements ADD CONSTRAINT fk_agreements_bundle FOREIGN KEY (bundle_id) REFERENCES sponsorship_bundles(id) ON DELETE SET NULL");
    }

    // Sponsor profile fields are used across agreement, fixture, and graphic screens.
    // Keep these guards here so pages can read sponsor details without depending on
    // sponsor.php having been opened first.
    $sponsorColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM sponsors') as $row) {
        $sponsorColumns[(string) $row['Field']] = true;
    }
    $sponsorDefinitions = [
        'logo_path' => "VARCHAR(255) NULL AFTER name",
        'white_logo_path' => "VARCHAR(255) NULL AFTER logo_path",
        'facebook_page_url' => "VARCHAR(500) NULL",
        'facebook_page_id' => "VARCHAR(100) NULL",
        'facebook_page_name' => "VARCHAR(190) NULL",
        'instagram_url' => "VARCHAR(500) NULL",
        'twitter_url' => "VARCHAR(500) NULL",
        'is_business' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
        'address' => "VARCHAR(255) NULL",
        'website_url' => "VARCHAR(255) NULL",
        'contact_phone' => "VARCHAR(50) NULL",
        'contact_email' => "VARCHAR(190) NULL",
        'is_main_sponsor' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
        'sort_order' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($sponsorDefinitions as $column => $definition) {
        if (!isset($sponsorColumns[$column])) {
            $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sponsorship_agreement_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id INT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        paid_at DATE NOT NULL,
        method VARCHAR(50) NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_agreement_payments_agreement (agreement_id, paid_at),
        CONSTRAINT fk_agreement_payments_agreement FOREIGN KEY (agreement_id) REFERENCES sponsorship_agreements(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seedSponsorshipPackages($pdo);
    $done = true;
}

function seedSponsorshipPackages(PDO $pdo): void
{
    $packages = [
        ['Main Sponsor', 'main_sponsor', 'club', 'Club-wide', 0, 'season', null, 1, 'club_graphic_footer', 'white', 10, 'Primary season-long club partner displayed across club graphics.'],
        ['Official Club Partner', 'official_club_partner', 'club', 'Club-wide', 0, 'season', null, 1, 'club_partner_area', 'white', 20, 'Recognised club partner with agreed club-wide visibility.'],
        ['Website Sponsor', 'website_sponsor', 'digital', 'Digital & Media', 0, 'season', null, 1, 'website', 'colour', 30, 'Sponsor placement across the club website.'],
        ['Social Media Sponsor', 'social_media_sponsor', 'digital', 'Digital & Media', 0, 'season', null, 1, 'social_media', 'white', 40, 'Sponsor placement on club social media content.'],
        ['Matchday', 'match_day', 'match', 'Match', 50, 'fixture', 1, 1, 'matchday', 'package_default', 100, 'Sponsor of the overall matchday.'],
        ['Match Ball', 'match_ball', 'match', 'Match', 40, 'fixture', 1, 1, 'match_ball', 'package_default', 110, 'Sponsor of the match ball for a fixture.'],
        ['MOTM', 'motm', 'match', 'Match', 30, 'fixture', 1, 1, 'motm', 'package_default', 120, 'Sponsor of the Player of the Match award.'],
        ['Player Home Kit Sponsor', 'player_home', 'player', 'Player', 50, 'season', 1, 1, 'player_graphic', 'package_default', 200, 'Home-kit sponsorship for an individual player.'],
        ['Player Away Kit Sponsor', 'player_away', 'player', 'Player', 50, 'season', 1, 1, 'player_graphic', 'package_default', 210, 'Away-kit sponsorship for an individual player.'],
        ['Player Third Kit Sponsor', 'player_third', 'player', 'Player', 0, 'season', 1, 1, 'player_graphic', 'package_default', 220, 'Third-kit sponsorship for an individual player.'],
        ['Home Kit Sponsor', 'home_kit_sponsor', 'club', 'Kit & Apparel', 0, 'season', null, 1, 'home_kit', 'white', 230, 'Primary sponsor associated with the club home kit.'],
        ['Away Kit Sponsor', 'away_kit_sponsor', 'club', 'Kit & Apparel', 0, 'season', null, 1, 'away_kit', 'white', 240, 'Primary sponsor associated with the club away kit.'],
        ['Matchday Polo Shirt Sponsor', 'matchday_polo_shirt_sponsor', 'club', 'Kit & Apparel', 0, 'season', null, 1, 'matchday_polo', 'white', 250, 'Sponsor displayed on the club matchday polo shirt.'],
        ['Large Boards (8x4)', 'large_board_sponsor', 'club', 'Ground Advertising', 0, 'season', null, 0, 'large_board', 'colour', 263, 'Large-format advertising board at the club ground.'],
        ['Season Ticket Sponsor', 'season_ticket_sponsor', 'club', 'Print & Membership', 0, 'season', null, 1, 'season_ticket', 'colour', 270, 'Sponsor associated with the club season ticket.'],
        ['Club Photographer', 'club_photographer', 'club', 'Club Services', 0, 'season', 1, 1, 'photography_credit', 'white', 280, 'Official club photography partner and photography credit.'],
        ['Training Kit Sponsor', 'training_kit', 'club', 'Kit & Apparel', 0, 'season', null, 1, 'team_graphics', 'white', 255, 'Sponsor displayed on club training / run-out kit and related content.'],
        ['First Team Sponsor', 'first_team', 'team', 'Team & Season', 0, 'season', null, 1, 'team_graphics', 'white', 310, 'Season sponsorship assigned to the first team.'],
        ['Youth Team Sponsor', 'youth_team', 'team', 'Team & Season', 0, 'season', null, 1, 'team_graphics', 'white', 320, 'Season sponsorship assigned to a youth team.'],
        ['Home Kit Sponsor (Back/Sleeve)', 'home_kit_sponsor_back_sleeve', 'club', 'Kit & Apparel', 750, 'season', null, 0, null, 'white', 232, 'Sponsor on the back and/or sleeve of the home shirt for a full season.'],
        ['Away Kit Sponsor (Back/Sleeve)', 'away_kit_sponsor_back_sleeve', 'club', 'Kit & Apparel', 750, 'season', null, 0, null, 'white', 242, 'Sponsor on the back and/or sleeve of the away shirt for a full season.'],
        ['Kit Bag Sponsor', 'kit_bag_sponsor', 'club', 'Kit & Apparel', 800, 'season', null, 0, null, 'white', 256, 'Name/logo on all player kit bags for the season.'],
        ['Tracksuit Sponsor (Players)', 'tracksuit_sponsor_players', 'club', 'Kit & Apparel', 3000, 'season', null, 0, null, 'white', 257, 'Name/logo on the back of the players\' tracksuits worn at matches.'],
        ['Tracksuit Sponsor (Coaches)', 'tracksuit_sponsor_coaches', 'club', 'Kit & Apparel', 1000, 'season', null, 0, null, 'white', 258, 'Name/logo on the back of the coaching staff\'s tracksuits worn at matches.'],
        ['Warm-up Top Sponsor', 'warmup_top_sponsor', 'club', 'Kit & Apparel', 500, 'season', null, 0, null, 'white', 259, 'Feature on the training/warm-up tops worn at every game.'],
        ['Substitute Jacket Sponsor', 'substitute_jacket_sponsor', 'club', 'Kit & Apparel', 650, 'season', null, 0, null, 'white', 261, 'Feature on the winter substitute jackets worn at every game.'],
        ['Waterproof Jacket Sponsor', 'waterproof_jacket_sponsor', 'club', 'Kit & Apparel', 650, 'season', null, 0, null, 'white', 262, 'Feature on the winter waterproof jackets worn at every game.'],
        ['Digital Content Sponsor', 'digital_content_sponsor', 'digital', 'Digital & Media', 500, 'season', null, 0, null, 'white', 35, 'Logo featured in club-produced video content (highlights, interviews, behind-the-scenes clips).'],
        ['Social Media Takeover', 'social_media_takeover', 'digital', 'Digital & Media', 50, 'ongoing', null, 0, null, 'white', 45, 'Branding across all club social media platforms for a full week (discounts available for multiple weeks).'],
    ];
    $stmt = $pdo->prepare("INSERT INTO packages
        (name, code, scope, category, amount, duration_type, max_slots, graphic_enabled, graphic_placement, default_logo_variant, is_active, sort_order, description)
        VALUES (:name, :code, :scope, :category, :amount, :duration_type, :max_slots, :graphic_enabled, :graphic_placement, :default_logo_variant, 1, :sort_order, :description)
        ON DUPLICATE KEY UPDATE code=VALUES(code)");
    foreach ($packages as $package) {
        $stmt->execute([
            ':name' => $package[0], ':code' => $package[1], ':scope' => $package[2], ':category' => $package[3],
            ':amount' => $package[4], ':duration_type' => $package[5], ':max_slots' => $package[6],
            ':graphic_enabled' => $package[7], ':graphic_placement' => $package[8], ':default_logo_variant' => $package[9],
            ':sort_order' => $package[10], ':description' => $package[11],
        ]);
    }
}

function getSponsorshipPackages(PDO $pdo, bool $activeOnly = false, ?string $scope = null): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $where = [];
    $params = [];
    if ($activeOnly) {
        $where[] = 'is_active = 1';
    }
    if ($scope !== null && $scope !== '') {
        $where[] = 'scope = :scope';
        $params[':scope'] = $scope;
    }
    $sql = 'SELECT * FROM packages' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order, name, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSponsorshipPackage(PDO $pdo, int $id): ?array
{
    ensureSponsorshipCatalogSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM packages WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getSponsorshipPackageByCode(PDO $pdo, string $code): ?array
{
    ensureSponsorshipCatalogSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM packages WHERE code=:code LIMIT 1');
    $stmt->execute([':code' => $code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sponsorshipAgreementStatus(array $agreement, ?string $onDate = null): string
{
    $status = (string)($agreement['status'] ?? 'active');
    $date = $onDate ?: date('Y-m-d');
    if ($status === 'active' && !empty($agreement['end_date']) && (string)$agreement['end_date'] < $date) {
        return 'expired';
    }
    if ($status === 'active' && !empty($agreement['start_date']) && (string)$agreement['start_date'] > $date) {
        return 'scheduled';
    }
    return $status;
}

function getSponsorshipAgreements(PDO $pdo, array $filters = []): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $where = [];
    $params = [];
    foreach (['sponsor_id', 'package_id', 'season_id', 'fixture_id', 'player_id', 'bundle_id'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = 'a.' . $field . '=:' . $field;
            $params[':' . $field] = (int)$filters[$field];
        }
    }
    if (!empty($filters['status'])) {
        $where[] = 'a.status=:status';
        $params[':status'] = (string)$filters['status'];
    }
    $sql = "SELECT a.*, s.name sponsor_name, s.logo_path, s.white_logo_path, s.contact_email sponsor_contact_email,
        p.name package_name, p.code package_code, p.scope package_scope, p.category package_category,
        se.name season_name, pl.name player_name, f.opponent fixture_opponent, f.match_date fixture_date, t.name team_name,
        (COALESCE(pay.total_paid,0) + COALESCE(match_pay.total_paid,0) + COALESCE(player_pay.total_paid,0)) total_paid
        FROM sponsorship_agreements a
        JOIN sponsors s ON s.id=a.sponsor_id
        JOIN packages p ON p.id=a.package_id
        LEFT JOIN seasons se ON se.id=a.season_id
        LEFT JOIN players pl ON pl.id=a.player_id
        LEFT JOIN teams t ON t.id=a.team_id
        LEFT JOIN match_fixtures f ON f.id=a.fixture_id
        LEFT JOIN (SELECT agreement_id,SUM(amount) total_paid FROM sponsorship_agreement_payments GROUP BY agreement_id) pay ON pay.agreement_id=a.id"
        . " LEFT JOIN (SELECT match_sponsorship_id,SUM(amount) total_paid FROM match_sponsorship_payments GROUP BY match_sponsorship_id) match_pay ON a.legacy_source='match' AND match_pay.match_sponsorship_id=a.legacy_id"
        . " LEFT JOIN (SELECT sponsorship_id,SUM(amount) total_paid FROM sponsorship_payments GROUP BY sponsorship_id) player_pay ON a.legacy_source='player' AND player_pay.sponsorship_id=a.legacy_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY FIELD(a.status,\'active\',\'scheduled\',\'expired\',\'cancelled\'), a.display_order, a.start_date DESC, a.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['effective_status'] = sponsorshipAgreementStatus($row);
    }
    unset($row);
    return $rows;
}

function getSponsorshipAgreement(PDO $pdo, int $id): ?array
{
    $rows = getSponsorshipAgreements($pdo, []);
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * Validates and saves one sponsorship agreement (insert or update), then syncs it to
 * whichever legacy table its package scope maps to. Shared by the standalone Add/Edit
 * Agreement page (sponsorship_agreement.php) and the "add item" flow on a sponsorship
 * bundle (sponsorship_bundle.php) — both need identical validation (slot limits,
 * complimentary rules, main-sponsor dedupe), so this is the single place it lives.
 *
 * $input is read the same way $_POST is read on the standalone page: sponsor_id,
 * package_id, season_id, team_id, fixture_id, player_id, start_date, end_date,
 * agreed_amount, is_complimentary, status, display_order, logo_variant, notes.
 * An explicit bundle_id in $input attaches the agreement to that bundle; when absent,
 * an existing agreement's current bundle_id is preserved unchanged (use
 * detachSponsorshipAgreementFromBundle() to clear it instead).
 *
 * @param array<string, mixed> $input
 * @return array{id: int, errors: list<string>} id is 0 when errors is non-empty
 */
function saveSponsorshipAgreement(PDO $pdo, int $id, array $input): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $agreement = $id ? getSponsorshipAgreement($pdo, $id) : null;
    if ($id && !$agreement) {
        return ['id' => 0, 'errors' => ['Agreement not found.']];
    }

    $data = [];
    foreach (['sponsor_id', 'package_id', 'season_id', 'team_id', 'fixture_id', 'player_id', 'start_date', 'end_date', 'agreed_amount', 'status', 'display_order', 'logo_variant', 'notes'] as $f) {
        $data[$f] = trim((string)($input[$f] ?? ''));
    }
    $data['is_complimentary'] = isset($input['is_complimentary']) && $input['is_complimentary'] === '1' ? 1 : 0;
    $bundleId = isset($input['bundle_id']) && (int)$input['bundle_id'] > 0
        ? (int)$input['bundle_id']
        : (isset($agreement['bundle_id']) && $agreement['bundle_id'] !== null ? (int)$agreement['bundle_id'] : null);

    $errors = [];
    $package = getSponsorshipPackage($pdo, (int)$data['package_id']);
    if ((int)$data['sponsor_id'] <= 0 || !$package) $errors[] = 'Choose a sponsor and package.';
    if ($agreement && !empty($agreement['legacy_source']) && (int)$data['package_id'] !== (int)$agreement['package_id']) $errors[] = 'The package cannot be changed after this agreement has been linked to an existing sponsorship. Create a new agreement instead.';
    if (!is_numeric($data['agreed_amount']) || (float)$data['agreed_amount'] < 0) $errors[] = 'Agreement amount must be zero or more.';
    if ($package && $package['scope'] === 'match' && (int)$data['fixture_id'] <= 0) $errors[] = 'Choose the sponsored fixture.';
    if ($data['is_complimentary'] && (!$package || $package['scope'] !== 'match')) $errors[] = 'Complimentary promotion is currently available for match sponsorship agreements only.';
    if ($data['is_complimentary']) $data['agreed_amount'] = '0.00';
    if ($data['is_complimentary'] && $id > 0 && $agreement && (float)$agreement['total_paid'] > 0.0001) $errors[] = 'A sponsorship with recorded payments cannot be changed to complimentary. Remove or refund the payments first.';
    if ($package && $package['scope'] === 'player' && (int)$data['player_id'] <= 0) $errors[] = 'Choose the sponsored player.';
    if ($package && $package['scope'] === 'team' && (int)$data['team_id'] <= 0) $errors[] = 'Choose the sponsored team.';
    if ($package && $package['scope'] === 'match' && (int)$data['fixture_id'] > 0) {
        $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE package_id=:package AND fixture_id=:fixture AND status IN ('active','scheduled') AND id<>:id");
        $slotCheck->execute([':package' => $package['id'], ':fixture' => (int)$data['fixture_id'], ':id' => $id]);
        if ((int)$slotCheck->fetchColumn() >= max(1, (int)($package['max_slots'] ?? 1))) $errors[] = 'That fixture already has the maximum number of agreements for this package.';
    }
    if ($package && $package['scope'] === 'player' && (int)$data['player_id'] > 0) {
        $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE package_id=:package AND player_id=:player AND season_id=:season AND status IN ('active','scheduled') AND id<>:id");
        $slotCheck->execute([':package' => $package['id'], ':player' => (int)$data['player_id'], ':season' => $data['season_id'] === '' ? 0 : (int)$data['season_id'], ':id' => $id]);
        if ((int)$slotCheck->fetchColumn() >= max(1, (int)($package['max_slots'] ?? 1))) $errors[] = 'That player already has the maximum number of agreements for this package.';
    }
    if ($package && $package['code'] === 'main_sponsor') {
        $duplicate = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE sponsor_id=:sponsor AND package_id=:package AND (season_id=:season OR (season_id IS NULL AND :season_null IS NULL)) AND id<>:id");
        $seasonValue = $data['season_id'] === '' ? null : (int)$data['season_id'];
        $duplicate->execute([':sponsor' => (int)$data['sponsor_id'], ':package' => (int)$data['package_id'], ':season' => $seasonValue, ':season_null' => $seasonValue, ':id' => $id]);
        if ((int)$duplicate->fetchColumn() > 0) $errors[] = 'This sponsor already has a Main Sponsor agreement for the selected season.';
    }

    if ($errors) {
        return ['id' => 0, 'errors' => $errors];
    }

    $params = [
        ':sponsor' => (int)$data['sponsor_id'], ':package' => (int)$data['package_id'],
        ':season' => $data['season_id'] === '' ? null : (int)$data['season_id'],
        ':bundle' => $bundleId,
        ':team' => $data['team_id'] === '' ? null : (int)$data['team_id'],
        ':fixture' => $data['fixture_id'] === '' ? null : (int)$data['fixture_id'],
        ':player' => $data['player_id'] === '' ? null : (int)$data['player_id'],
        ':start' => $data['start_date'] ?: null, ':end' => $data['end_date'] ?: null,
        ':amount' => (float)$data['agreed_amount'], ':complimentary' => (int)$data['is_complimentary'],
        ':status' => $data['status'], ':display' => max(0, (int)$data['display_order']),
        ':variant' => $data['logo_variant'], ':notes' => $data['notes'] ?: null,
    ];

    if ($id) {
        $params[':id'] = $id;
        $stmt = $pdo->prepare('UPDATE sponsorship_agreements SET sponsor_id=:sponsor,package_id=:package,season_id=:season,bundle_id=:bundle,team_id=:team,fixture_id=:fixture,player_id=:player,start_date=:start,end_date=:end,agreed_amount=:amount,is_complimentary=:complimentary,status=:status,display_order=:display,logo_variant=:variant,notes=:notes WHERE id=:id');
    } else {
        $stmt = $pdo->prepare('INSERT INTO sponsorship_agreements(sponsor_id,package_id,season_id,bundle_id,team_id,fixture_id,player_id,start_date,end_date,agreed_amount,is_complimentary,status,display_order,logo_variant,notes) VALUES(:sponsor,:package,:season,:bundle,:team,:fixture,:player,:start,:end,:amount,:complimentary,:status,:display,:variant,:notes)');
    }

    $pdo->beginTransaction();
    try {
        $stmt->execute($params);
        $id = $id ?: ((int)$pdo->lastInsertId());
        if ($agreement && (string)$agreement['package_code'] === 'main_sponsor' && ((int)$agreement['sponsor_id'] !== (int)$data['sponsor_id'] || (string)$package['code'] !== 'main_sponsor')) {
            $pdo->prepare('UPDATE sponsors SET is_main_sponsor=0 WHERE id=:id')->execute([':id' => (int)$agreement['sponsor_id']]);
        }
        applySponsorshipAgreementToLegacy($pdo, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['id' => 0, 'errors' => [$e->getMessage()]];
    }

    return ['id' => $id, 'errors' => []];
}

/**
 * Detaches an agreement from its bundle (if any) without touching the agreement itself
 * or its payment history — the inverse of attaching via saveSponsorshipAgreement()'s
 * bundle_id input.
 */
function detachSponsorshipAgreementFromBundle(PDO $pdo, int $agreementId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $pdo->prepare('UPDATE sponsorship_agreements SET bundle_id=NULL WHERE id=:id')->execute([':id' => $agreementId]);
}

/**
 * Attaches an already-existing agreement to a bundle — the "retroactively group
 * agreements that were created separately" path (the actual motivating case: a
 * sponsor who already has 13 separate agreements). Only touches bundle_id; every
 * other field on the agreement is untouched.
 *
 * @throws RuntimeException if the agreement or bundle don't exist, or the agreement's
 *   sponsor doesn't match the bundle's sponsor
 */
function attachSponsorshipAgreementToBundle(PDO $pdo, int $agreementId, int $bundleId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $agreement = getSponsorshipAgreement($pdo, $agreementId);
    if (!$agreement) {
        throw new RuntimeException('Agreement not found.');
    }
    $bundle = getSponsorshipBundle($pdo, $bundleId);
    if (!$bundle) {
        throw new RuntimeException('Bundle not found.');
    }
    if ((int) $agreement['sponsor_id'] !== (int) $bundle['sponsor_id']) {
        throw new RuntimeException('That agreement belongs to a different sponsor than this bundle.');
    }
    $pdo->prepare('UPDATE sponsorship_agreements SET bundle_id=:bundle WHERE id=:id')->execute([':bundle' => $bundleId, ':id' => $agreementId]);
}

/**
 * @param array<string, mixed> $filters supports sponsor_id, season_id, status
 * @return list<array<string, mixed>> each row is a bundle header plus aggregated
 *   item_count/agreed_total/paid_total/outstanding_total computed from its member
 *   agreements (via getSponsorshipAgreements(), which already correctly sources
 *   total_paid across all three legacy payment tables — not re-derived here).
 */
function getSponsorshipBundles(PDO $pdo, array $filters = []): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $where = [];
    $params = [];
    foreach (['sponsor_id', 'season_id'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = 'b.' . $field . '=:' . $field;
            $params[':' . $field] = (int)$filters[$field];
        }
    }
    if (!empty($filters['status'])) {
        $where[] = 'b.status=:status';
        $params[':status'] = (string)$filters['status'];
    }
    $sql = "SELECT b.*, s.name sponsor_name, se.name season_name
        FROM sponsorship_bundles b
        JOIN sponsors s ON s.id=b.sponsor_id
        LEFT JOIN seasons se ON se.id=b.season_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY b.created_at DESC, b.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bundles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$bundles) {
        return [];
    }

    $agreements = getSponsorshipAgreements($pdo, []);
    $byBundle = [];
    foreach ($agreements as $agreement) {
        $bundleId = (int)($agreement['bundle_id'] ?? 0);
        if ($bundleId > 0) {
            $byBundle[$bundleId][] = $agreement;
        }
    }

    foreach ($bundles as &$bundle) {
        $members = $byBundle[(int)$bundle['id']] ?? [];
        $agreedTotal = 0.0;
        $paidTotal = 0.0;
        foreach ($members as $member) {
            $agreedTotal += (float)$member['agreed_amount'];
            $paidTotal += (float)$member['total_paid'];
        }
        $bundle['item_count'] = count($members);
        $bundle['agreed_total'] = $agreedTotal;
        $bundle['paid_total'] = $paidTotal;
        $bundle['outstanding_total'] = max(0.0, $agreedTotal - $paidTotal);
    }
    unset($bundle);

    return $bundles;
}

function getSponsorshipBundle(PDO $pdo, int $id): ?array
{
    $rows = getSponsorshipBundles($pdo, []);
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * @return array{id: int, errors: list<string>} id is 0 when errors is non-empty
 */
function saveSponsorshipBundle(PDO $pdo, int $id, array $input): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $sponsorId = (int)($input['sponsor_id'] ?? 0);
    $seasonId = trim((string)($input['season_id'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $status = trim((string)($input['status'] ?? 'active')) ?: 'active';

    $errors = [];
    if ($sponsorId <= 0) {
        $errors[] = 'Choose a sponsor.';
    }
    if ($errors) {
        return ['id' => 0, 'errors' => $errors];
    }

    $params = [
        ':sponsor' => $sponsorId,
        ':season' => $seasonId === '' ? null : (int)$seasonId,
        ':name' => $name ?: null,
        ':notes' => $notes ?: null,
        ':status' => $status,
    ];

    if ($id) {
        $params[':id'] = $id;
        $stmt = $pdo->prepare('UPDATE sponsorship_bundles SET sponsor_id=:sponsor,season_id=:season,name=:name,notes=:notes,status=:status WHERE id=:id');
    } else {
        $stmt = $pdo->prepare('INSERT INTO sponsorship_bundles(sponsor_id,season_id,name,notes,status) VALUES(:sponsor,:season,:name,:notes,:status)');
    }
    $stmt->execute($params);
    $id = $id ?: ((int)$pdo->lastInsertId());

    return ['id' => $id, 'errors' => []];
}

/**
 * Deletes a bundle header only — member agreements are detached (bundle_id set NULL
 * via the ON DELETE SET NULL foreign key) but never touched or deleted themselves.
 */
function deleteSponsorshipBundle(PDO $pdo, int $bundleId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $stmt = $pdo->prepare('DELETE FROM sponsorship_bundles WHERE id=:id');
    $stmt->execute([':id' => $bundleId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The bundle could not be deleted.');
    }
}

function deleteSponsorshipAgreement(PDO $pdo, int $agreementId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $agreement = getSponsorshipAgreement($pdo, $agreementId);
    if (!$agreement) {
        throw new RuntimeException('Agreement not found.');
    }

    $pdo->beginTransaction();
    try {
        $legacySource = (string)($agreement['legacy_source'] ?? '');
        $legacyId = (int)($agreement['legacy_id'] ?? 0);

        // Remove the legacy record as well, otherwise the catalogue sync would
        // recreate the agreement the next time an agreements page is opened.
        if ($legacySource === 'main_sponsor') {
            $stmt = $pdo->prepare('UPDATE sponsors SET is_main_sponsor=0 WHERE id=:id');
            $stmt->execute([':id' => (int)$agreement['sponsor_id']]);
        } elseif ($legacySource === 'match' && $legacyId > 0) {
            $stmt = $pdo->prepare('DELETE FROM match_sponsorship_payments WHERE match_sponsorship_id=:id');
            $stmt->execute([':id' => $legacyId]);
            $stmt = $pdo->prepare('DELETE FROM match_sponsorships WHERE id=:id');
            $stmt->execute([':id' => $legacyId]);
        } elseif ($legacySource === 'player' && $legacyId > 0) {
            $stmt = $pdo->prepare('DELETE FROM sponsorship_payments WHERE sponsorship_id=:id');
            $stmt->execute([':id' => $legacyId]);
            $stmt = $pdo->prepare('DELETE FROM sponsorships WHERE id=:id');
            $stmt->execute([':id' => $legacyId]);
        }

        $stmt = $pdo->prepare('DELETE FROM sponsorship_agreements WHERE id=:id');
        $stmt->execute([':id' => $agreementId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The agreement could not be deleted.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function syncLegacySponsorshipAgreements(PDO $pdo): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $currentSeason = $pdo->query('SELECT id,start_date,end_date FROM seasons WHERE is_current=1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $mainPackage = getSponsorshipPackageByCode($pdo, 'main_sponsor');
    if ($mainPackage && $currentSeason) {
        $stmt = $pdo->prepare("INSERT INTO sponsorship_agreements
            (sponsor_id,package_id,season_id,start_date,end_date,agreed_amount,status,display_order,logo_variant,legacy_source,legacy_id)
            SELECT id,:package_id,:season_id,:start_date,:end_date,0,'active',sort_order,'white','main_sponsor',id
            FROM sponsors WHERE is_active=1 AND is_main_sponsor=1
            ON DUPLICATE KEY UPDATE package_id=VALUES(package_id),season_id=VALUES(season_id),start_date=VALUES(start_date),end_date=VALUES(end_date),status='active',display_order=VALUES(display_order)");
        $stmt->execute([':package_id' => $mainPackage['id'], ':season_id' => $currentSeason['id'], ':start_date' => $currentSeason['start_date'], ':end_date' => $currentSeason['end_date']]);
    }

    $matchRows = $pdo->query('SELECT * FROM match_sponsorships')->fetchAll(PDO::FETCH_ASSOC);
    $matchInsert = $pdo->prepare("INSERT INTO sponsorship_agreements
        (sponsor_id,package_id,season_id,fixture_id,start_date,end_date,agreed_amount,is_complimentary,status,display_order,notes,legacy_source,legacy_id)
        VALUES (:sponsor_id,:package_id,:season_id,:fixture_id,:start_date,:end_date,:amount,:is_complimentary,:status,0,:notes,'match',:legacy_id)
        ON DUPLICATE KEY UPDATE sponsor_id=VALUES(sponsor_id),package_id=VALUES(package_id),fixture_id=VALUES(fixture_id),agreed_amount=VALUES(agreed_amount),is_complimentary=VALUES(is_complimentary),status=VALUES(status),notes=VALUES(notes)");
    foreach ($matchRows as $row) {
        $package = getSponsorshipPackageByCode($pdo, (string)$row['sponsorship_role']);
        if (!$package) continue;
        $matchInsert->execute([
            ':sponsor_id' => $row['sponsor_id'], ':package_id' => $package['id'], ':season_id' => $row['season_id'], ':fixture_id' => $row['fixture_id'],
            ':start_date' => !empty($row['started_at']) ? substr((string)$row['started_at'],0,10) : null,
            ':end_date' => !empty($row['ended_at']) ? substr((string)$row['ended_at'],0,10) : null,
            ':amount' => $row['amount'], ':is_complimentary' => (int)($row['is_complimentary'] ?? 0), ':status' => empty($row['ended_at']) ? 'active' : 'expired', ':notes' => $row['notes'], ':legacy_id' => $row['id'],
        ]);
    }

    $playerRows = $pdo->query('SELECT * FROM sponsorships')->fetchAll(PDO::FETCH_ASSOC);
    $playerInsert = $pdo->prepare("INSERT INTO sponsorship_agreements
        (sponsor_id,package_id,season_id,player_id,start_date,end_date,agreed_amount,status,display_order,notes,legacy_source,legacy_id)
        VALUES (:sponsor_id,:package_id,:season_id,:player_id,:start_date,:end_date,:amount,:status,0,:notes,'player',:legacy_id)
        ON DUPLICATE KEY UPDATE sponsor_id=VALUES(sponsor_id),package_id=VALUES(package_id),player_id=VALUES(player_id),agreed_amount=VALUES(agreed_amount),status=VALUES(status),notes=VALUES(notes)");
    foreach ($playerRows as $row) {
        $package = getSponsorshipPackageByCode($pdo, 'player_' . (string)$row['slot']);
        if (!$package) continue;
        $status = !empty($row['ended_at'])
            ? ((string)($row['ended_reason'] ?? '') === 'unassigned' ? 'cancelled' : 'expired')
            : 'active';
        $playerInsert->execute([
            ':sponsor_id' => $row['sponsor_id'], ':package_id' => $package['id'], ':season_id' => $row['season_id'], ':player_id' => $row['player_id'],
            ':start_date' => !empty($row['started_at']) ? substr((string)$row['started_at'],0,10) : null,
            ':end_date' => !empty($row['ended_at']) ? substr((string)$row['ended_at'],0,10) : null,
            ':amount' => $row['amount'], ':status' => $status, ':notes' => $row['notes'], ':legacy_id' => $row['id'],
        ]);
    }
}

function syncMatchSponsorshipAgreement(PDO $pdo, int $matchSponsorshipId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM match_sponsorships WHERE id=:id LIMIT 1');
    $stmt->execute([':id' => $matchSponsorshipId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $package = getSponsorshipPackageByCode($pdo, (string)$row['sponsorship_role']);
    if (!$package) return;
    $upsert = $pdo->prepare("INSERT INTO sponsorship_agreements
        (sponsor_id,package_id,season_id,fixture_id,start_date,end_date,agreed_amount,is_complimentary,status,notes,legacy_source,legacy_id)
        VALUES (:sponsor,:package,:season,:fixture,:start,:end,:amount,:is_complimentary,:status,:notes,'match',:legacy)
        ON DUPLICATE KEY UPDATE sponsor_id=VALUES(sponsor_id),package_id=VALUES(package_id),season_id=VALUES(season_id),fixture_id=VALUES(fixture_id),start_date=VALUES(start_date),end_date=VALUES(end_date),agreed_amount=VALUES(agreed_amount),is_complimentary=VALUES(is_complimentary),status=VALUES(status),notes=VALUES(notes)");
    $upsert->execute([':sponsor'=>$row['sponsor_id'],':package'=>$package['id'],':season'=>$row['season_id'],':fixture'=>$row['fixture_id'],':start'=>!empty($row['started_at'])?substr((string)$row['started_at'],0,10):null,':end'=>!empty($row['ended_at'])?substr((string)$row['ended_at'],0,10):null,':amount'=>$row['amount'],':is_complimentary'=>(int)($row['is_complimentary']??0),':status'=>empty($row['ended_at'])?'active':'expired',':notes'=>$row['notes'],':legacy'=>$row['id']]);
}

function syncPlayerSponsorshipAgreement(PDO $pdo, int $sponsorshipId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $stmt=$pdo->prepare('SELECT * FROM sponsorships WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$sponsorshipId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)return;
    $package=getSponsorshipPackageByCode($pdo,'player_'.(string)$row['slot']);if(!$package)return;
    $status=!empty($row['ended_at'])?(((string)($row['ended_reason']??''))==='unassigned'?'cancelled':'expired'):'active';
    $upsert=$pdo->prepare("INSERT INTO sponsorship_agreements(sponsor_id,package_id,season_id,player_id,start_date,end_date,agreed_amount,status,notes,legacy_source,legacy_id)
      VALUES(:sponsor,:package,:season,:player,:start,:end,:amount,:status,:notes,'player',:legacy)
      ON DUPLICATE KEY UPDATE sponsor_id=VALUES(sponsor_id),package_id=VALUES(package_id),season_id=VALUES(season_id),player_id=VALUES(player_id),start_date=VALUES(start_date),end_date=VALUES(end_date),agreed_amount=VALUES(agreed_amount),status=VALUES(status),notes=VALUES(notes)");
    $upsert->execute([':sponsor'=>$row['sponsor_id'],':package'=>$package['id'],':season'=>$row['season_id'],':player'=>$row['player_id'],':start'=>!empty($row['started_at'])?substr((string)$row['started_at'],0,10):null,':end'=>!empty($row['ended_at'])?substr((string)$row['ended_at'],0,10):null,':amount'=>$row['amount'],':status'=>$status,':notes'=>$row['notes'],':legacy'=>$row['id']]);
}

function applySponsorshipAgreementToLegacy(PDO $pdo, int $agreementId): void
{
    ensureSponsorshipCatalogSchema($pdo);
    $agreement=getSponsorshipAgreement($pdo,$agreementId);if(!$agreement)return;
    $active=in_array((string)$agreement['status'],['active','scheduled'],true);
    if((string)$agreement['package_code']==='main_sponsor'){
        $pdo->prepare("UPDATE sponsorship_agreements SET legacy_source='main_sponsor',legacy_id=:legacy WHERE id=:id")->execute([':legacy'=>(int)$agreement['sponsor_id'],':id'=>$agreementId]);
        $pdo->prepare('UPDATE sponsors SET is_main_sponsor=:active,sort_order=:sort WHERE id=:id')->execute([':active'=>$active?1:0,':sort'=>(int)$agreement['display_order'],':id'=>(int)$agreement['sponsor_id']]);
        return;
    }
    if((string)$agreement['package_scope']==='match'&&!empty($agreement['fixture_id'])&&!empty($agreement['season_id'])){
        $legacyId=(int)($agreement['legacy_source']==='match'?$agreement['legacy_id']:0);
        // match_sponsorships holds exactly one row per (season,fixture,role) — its own
        // uq_match_fixture_role unique key enforces that. But sponsorship_agreements
        // allows several historical rows for the same fixture+role over time (expired,
        // cancelled, replaced by a new sponsor), so an old one left undeleted can still
        // be the current owner of that single legacy row. Detach it and let this
        // agreement take the slot over — but only when it's safe to, i.e. the old
        // agreement has no payment history that would become invisible once its
        // legacy link is cleared (getAgreementPayments() reads payments via that link).
        if($legacyId<=0){
            $find=$pdo->prepare('SELECT id FROM match_sponsorships WHERE season_id=:season AND fixture_id=:fixture AND sponsorship_role=:role LIMIT 1');
            $find->execute([':season'=>$agreement['season_id'],':fixture'=>$agreement['fixture_id'],':role'=>$agreement['package_code']]);
            $legacyId=(int)$find->fetchColumn();
            if($legacyId>0){
                $ownerStmt=$pdo->prepare('SELECT id FROM sponsorship_agreements WHERE legacy_source=\'match\' AND legacy_id=:legacy AND id<>:agreement_id LIMIT 1');
                $ownerStmt->execute([':legacy'=>$legacyId,':agreement_id'=>$agreementId]);
                $ownerId=(int)$ownerStmt->fetchColumn();
                if($ownerId>0){
                    $paidStmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM match_sponsorship_payments WHERE match_sponsorship_id=:id');
                    $paidStmt->execute([':id'=>$legacyId]);
                    if((float)$paidStmt->fetchColumn()>0.0001){
                        throw new RuntimeException('Fixture #'.$agreement['fixture_id'].' already has a paid sponsorship agreement (#'.$ownerId.') for this package. Resolve or delete that agreement first before adding a new one for the same fixture and package.');
                    }
                    $pdo->prepare('UPDATE sponsorship_agreements SET legacy_source=NULL,legacy_id=NULL WHERE id=:id')->execute([':id'=>$ownerId]);
                }
            }
        }
        $complimentary=(int)($agreement['is_complimentary']??0)===1;
        $amount=$complimentary?0.0:(float)$agreement['agreed_amount'];
        if($legacyId>0){
            $legacyRowStmt=$pdo->prepare('SELECT * FROM match_sponsorships WHERE id=:id LIMIT 1');$legacyRowStmt->execute([':id'=>$legacyId]);$legacyRow=$legacyRowStmt->fetch(PDO::FETCH_ASSOC);
            if($complimentary){$paid=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM match_sponsorship_payments WHERE match_sponsorship_id=:id');$paid->execute([':id'=>$legacyId]);if((float)$paid->fetchColumn()>0.0001)throw new RuntimeException('A sponsorship with recorded payments cannot be changed to complimentary. Remove or refund the payments first.');}
            if($legacyRow&&function_exists('logMatchSponsorshipHistory'))logMatchSponsorshipHistory($pdo,$legacyRow,'update','update','agreement_sync');
            $stmt=$pdo->prepare('UPDATE match_sponsorships SET sponsor_id=:sponsor,season_id=:season,fixture_id=:fixture,sponsorship_role=:role,amount=:amount,paid=CASE WHEN :complimentary=1 THEN 0 ELSE paid END,is_complimentary=:complimentary,notes=:notes,ended_at=:ended,ended_reason=:reason WHERE id=:id');$stmt->execute([':sponsor'=>$agreement['sponsor_id'],':season'=>$agreement['season_id'],':fixture'=>$agreement['fixture_id'],':role'=>$agreement['package_code'],':amount'=>$amount,':complimentary'=>$complimentary?1:0,':notes'=>$agreement['notes'],':ended'=>$active?null:date('Y-m-d H:i:s'),':reason'=>$active?null:'agreement_status',':id'=>$legacyId]);
        }
        else{$stmt=$pdo->prepare('INSERT INTO match_sponsorships(season_id,fixture_id,sponsor_id,sponsorship_role,amount,paid,is_complimentary,notes,assigned_at,started_at,ended_at,ended_reason) VALUES(:season,:fixture,:sponsor,:role,:amount,0,:complimentary,:notes,NOW(),:start,:ended,:reason)');$stmt->execute([':season'=>$agreement['season_id'],':fixture'=>$agreement['fixture_id'],':sponsor'=>$agreement['sponsor_id'],':role'=>$agreement['package_code'],':amount'=>$amount,':complimentary'=>$complimentary?1:0,':notes'=>$agreement['notes'],':start'=>$agreement['start_date']?:date('Y-m-d'),':ended'=>$active?null:date('Y-m-d H:i:s'),':reason'=>$active?null:'agreement_status']);$legacyId=(int)$pdo->lastInsertId();}
        $pdo->prepare("UPDATE sponsorship_agreements SET legacy_source='match',legacy_id=:legacy WHERE id=:id")->execute([':legacy'=>$legacyId,':id'=>$agreementId]);return;
    }
    if((string)$agreement['package_scope']==='player'&&!empty($agreement['player_id'])&&!empty($agreement['season_id'])){
        $slot=str_replace('player_','',(string)$agreement['package_code']);if(!in_array($slot,['home','away','third'],true))return;
        $legacyId=(int)($agreement['legacy_source']==='player'?$agreement['legacy_id']:0);
        // Same single-slot-per-season/player/slot constraint (uq_season_player_slot) and
        // same detach-if-unpaid rule as the match branch above.
        if($legacyId<=0){
            $find=$pdo->prepare('SELECT id FROM sponsorships WHERE season_id=:season AND player_id=:player AND slot=:slot LIMIT 1');
            $find->execute([':season'=>$agreement['season_id'],':player'=>$agreement['player_id'],':slot'=>$slot]);
            $legacyId=(int)$find->fetchColumn();
            if($legacyId>0){
                $ownerStmt=$pdo->prepare('SELECT id FROM sponsorship_agreements WHERE legacy_source=\'player\' AND legacy_id=:legacy AND id<>:agreement_id LIMIT 1');
                $ownerStmt->execute([':legacy'=>$legacyId,':agreement_id'=>$agreementId]);
                $ownerId=(int)$ownerStmt->fetchColumn();
                if($ownerId>0){
                    $paidStmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM sponsorship_payments WHERE sponsorship_id=:id');
                    $paidStmt->execute([':id'=>$legacyId]);
                    if((float)$paidStmt->fetchColumn()>0.0001){
                        throw new RuntimeException('This player already has a paid sponsorship agreement (#'.$ownerId.') for this slot. Resolve or delete that agreement first before adding a new one for the same player and slot.');
                    }
                    $pdo->prepare('UPDATE sponsorship_agreements SET legacy_source=NULL,legacy_id=NULL WHERE id=:id')->execute([':id'=>$ownerId]);
                }
            }
        }
        if($legacyId>0){$stmt=$pdo->prepare('UPDATE sponsorships SET sponsor_id=:sponsor,season_id=:season,player_id=:player,slot=:slot,amount=:amount,notes=:notes,ended_at=:ended,ended_reason=:reason WHERE id=:id');$stmt->execute([':sponsor'=>$agreement['sponsor_id'],':season'=>$agreement['season_id'],':player'=>$agreement['player_id'],':slot'=>$slot,':amount'=>$agreement['agreed_amount'],':notes'=>$agreement['notes'],':ended'=>$active?null:date('Y-m-d H:i:s'),':reason'=>$active?null:'agreement_status',':id'=>$legacyId]);}
        else{$stmt=$pdo->prepare('INSERT INTO sponsorships(sponsor_id,season_id,player_id,slot,amount,notes,assigned_at,started_at,ended_at,ended_reason) VALUES(:sponsor,:season,:player,:slot,:amount,:notes,NOW(),:start,:ended,:reason)');$stmt->execute([':sponsor'=>$agreement['sponsor_id'],':season'=>$agreement['season_id'],':player'=>$agreement['player_id'],':slot'=>$slot,':amount'=>$agreement['agreed_amount'],':notes'=>$agreement['notes'],':start'=>$agreement['start_date']?:date('Y-m-d'),':ended'=>$active?null:date('Y-m-d H:i:s'),':reason'=>$active?null:'agreement_status']);$legacyId=(int)$pdo->lastInsertId();}
        $pdo->prepare("UPDATE sponsorship_agreements SET legacy_source='player',legacy_id=:legacy WHERE id=:id")->execute([':legacy'=>$legacyId,':id'=>$agreementId]);
    }
}

function getActiveMainSponsorAgreements(PDO $pdo, int $seasonId = 0, ?string $onDate = null): array
{
    ensureSponsorshipCatalogSchema($pdo);
    $date = $onDate ?: date('Y-m-d');
    $stmt = $pdo->prepare("SELECT s.id,s.name,s.logo_path,s.white_logo_path,a.display_order,a.logo_variant
        FROM sponsorship_agreements a
        JOIN packages p ON p.id=a.package_id AND p.code='main_sponsor'
        JOIN sponsors s ON s.id=a.sponsor_id AND s.is_active=1
        WHERE a.status='active'
          AND (:season_id=0 OR a.season_id IS NULL OR a.season_id=:season_id)
          AND (a.start_date IS NULL OR a.start_date<=:on_date)
          AND (a.end_date IS NULL OR a.end_date>=:on_date)
        ORDER BY a.display_order,s.sort_order,s.name,s.id");
    $stmt->execute([':season_id' => $seasonId, ':on_date' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        return $rows;
    }

    // Keep the established graphics working until Main Sponsor agreements are
    // deliberately created; do not bulk-import legacy sponsor flags.
    return $pdo->query("SELECT id,name,logo_path,white_logo_path,sort_order AS display_order,'package_default' AS logo_variant
        FROM sponsors
        WHERE is_active=1 AND is_main_sponsor=1
        ORDER BY sort_order,name,id")->fetchAll(PDO::FETCH_ASSOC);
}
