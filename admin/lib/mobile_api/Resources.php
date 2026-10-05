<?php
declare(strict_types=1);

namespace MyClubHub\Api;

final class Resources
{
    private array $settings;

    public function __construct(private \PDO $pdo, private array $config)
    {
        // These files define functions only. Use pure helpers, never their ensure_schema calls.
        require_once dirname(__DIR__) . '/site_settings.php';
        require_once dirname(__DIR__) . '/squad_public.php';
        require_once dirname(__DIR__) . '/news.php';
        require_once dirname(__DIR__, 3) . '/lib/fixtures.php';
        $this->settings = \site_settings_defaults();
        foreach ($this->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    public function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function one(string $sql, array $params = []): array
    {
        $rows = $this->rows($sql, $params);
        if (!$rows) throw new ApiError(404, 'not_found', 'The requested resource was not found.');
        return $rows[0];
    }

    public function page(string $sql, array $params, string $order, array $query, callable $map): array
    {
        [$page, $limit, $offset] = Input::pagination($query);
        $count = $this->one('SELECT COUNT(*) AS total FROM (' . $sql . ') AS page_count', $params);
        $rows = $this->rows($sql . ' ORDER BY ' . $order . " LIMIT $limit OFFSET $offset", $params);
        return ['data' => array_map($map, $rows), 'meta' => ['page' => $page, 'per_page' => $limit,
            'total' => (int) $count['total'], 'total_pages' => (int) ceil((int) $count['total'] / $limit)]];
    }

    public function url(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') return null;
        if (preg_match('~^https?://~i', $path)) return $path;
        if (str_starts_with($path, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_contains($path, '\\')) return null;
        return $this->config['base_url'] . '/' . implode('/', array_map(static fn($p) => rawurlencode(rawurldecode($p)), explode('/', ltrim($path, '/'))));
    }

    public function club(): array
    {
        $out = [];
        foreach (['club_name','club_short_name','club_nickname','club_tagline','club_founded','ground_name','ground_address','contact_email','contact_phone'] as $key) {
            $out[$key] = (string) ($this->settings[$key] ?? '');
        }
        $out['crest_url'] = $this->url($this->settings['crest_url'] ?? '');
        $out['timezone'] = $this->config['timezone'];
        $out['website_url'] = $this->config['base_url'];
        // The existing /members/ directory is explicitly denied by its .htaccess.
        $out['registration_url'] = null;
        $out['password_reset_url'] = $this->url('/admin/forgot_password.php');
        return $out;
    }

    public function currentSeason(): int
    {
        $rows = $this->rows('SELECT id FROM seasons ORDER BY is_current DESC, start_date DESC, id DESC LIMIT 1');
        return (int) ($rows[0]['id'] ?? 0);
    }

    public function seasonId(array $query): int
    {
        $id = isset($query['season_id']) ? Input::integer($query['season_id'], 'season_id') : $this->currentSeason();
        if ($id > 0) $this->one('SELECT id FROM seasons WHERE id = ?', [$id]);
        return $id;
    }

    public function seasons(array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page('SELECT id,name,start_date,end_date,is_current FROM seasons', [], 'start_date DESC,id DESC', $query,
            static fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'start_date' => $r['start_date'],
                'end_date' => $r['end_date'], 'is_current' => (bool) $r['is_current']]);
    }

    private function fixtureSelect(): string
    {
        return str_replace('SELECT f.id,', 'SELECT f.season_id, f.id,', \pub_fixture_select());
    }

    private function fixtureView(array $r): array
    {
        $out = array_intersect_key($r, array_flip(['match_date','kickoff_time','competition','competition_stage','venue','status']));
        $out += ['id' => (int) $r['id'], 'season_id' => (int) $r['season_id'], 'is_home' => (bool) $r['is_home'],
            'opponent' => $r['opponent_name'] ?: $r['opponent'], 'timezone' => $this->config['timezone']];
        foreach (['full_time_home_score','full_time_away_score','half_time_home_score','half_time_away_score','home_penalties','away_penalties'] as $key) {
            $out[$key] = $r[$key] === null ? null : (int) $r[$key];
        }
        $out['outcome'] = \pub_fixture_outcome($r);
        return $out;
    }

    public function fixtures(array $query, bool $results): array
    {
        Input::only($query, ['page','per_page','season_id','competition']);
        $params = [$this->seasonId($query)];
        $sql = $this->fixtureSelect() . ' WHERE f.season_id = ?';
        if (isset($query['competition'])) {
            $sql .= ' AND f.competition = ?';
            $params[] = Input::text($query, 'competition', 150);
        }
        if ($results) {
            $sql .= " AND (f.status = 'played' OR (f.full_time_home_score IS NOT NULL AND f.full_time_away_score IS NOT NULL))";
        } else {
            $sql .= " AND (f.status IS NULL OR f.status <> 'played') AND f.full_time_home_score IS NULL AND f.match_date >= ?";
            $params[] = (new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone'])))->format('Y-m-d');
        }
        return $this->page($sql, $params, $results ? 'f.match_date DESC,f.kickoff_time DESC,f.id DESC' : 'f.match_date,f.kickoff_time,f.id', $query, $this->fixtureView(...));
    }

    public function fixture(int $id): array
    {
        return $this->fixtureView($this->one($this->fixtureSelect() . ' WHERE f.id = ?', [$id]));
    }

    private function articleView(array $r): array
    {
        $out = ['id' => (int) $r['id'], 'slug' => $r['slug'], 'title' => $r['title'], 'excerpt' => $r['excerpt'],
            'category' => $r['category'], 'author_name' => $r['author_name'], 'published_at' => self::timestamp($r['published_at']),
            'hero_image_url' => $this->url($r['hero_image_path']), 'is_featured' => (bool) $r['is_featured']];
        if (array_key_exists('body', $r)) $out['body_html'] = \news_render($r);
        return $out;
    }

    public function news(array $query): array
    {
        Input::only($query, ['page','per_page','category']);
        $sql = 'SELECT id,slug,title,excerpt,category,author_name,published_at,hero_image_path,is_featured FROM news_articles WHERE ' . \news_public_where();
        $params = [];
        if (isset($query['category'])) {
            $sql .= ' AND category = ?';
            $params[] = Input::text($query, 'category', 60);
        }
        return $this->page($sql, $params, 'published_at DESC,id DESC', $query, $this->articleView(...));
    }

    public function article(string $slug): array
    {
        return $this->articleView($this->one('SELECT id,slug,title,excerpt,category,author_name,published_at,hero_image_path,is_featured,body,body_format
            FROM news_articles WHERE slug = ? AND ' . \news_public_where(), [$slug]));
    }

    private function playerSelect(): string
    {
        // Preserve the website's current-player and visibility rules, without schema writes.
        return "SELECT p.id,p.name,p.position,p.squad_number,p.nationality,p.bio,p.avatar,p.website_slug
            FROM players p LEFT JOIN player_website_photos w ON w.player_id = p.id
            WHERE p.status = 'current' AND COALESCE(w.uploaded_to_website,1) = 1";
    }

    private function playerView(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'position' => \squad_position_label($r['position']),
            'position_group' => \squad_position_group($r['position']), 'squad_number' => $r['squad_number'] === null ? null : (int) $r['squad_number'],
            'nationality' => $r['nationality'], 'bio' => (string) $r['bio'],
            'photo_url' => $this->url($r['avatar'] ? '/uploads/players/' . $r['avatar'] : ''),
            'slug' => $r['website_slug'] ?: \squad_public_slug($r['name'], (int) $r['id'])];
    }

    public function players(array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page($this->playerSelect(), [], 'p.name,p.id', $query, $this->playerView(...));
    }

    public function player(int $id): array
    {
        return $this->playerView($this->one($this->playerSelect() . ' AND p.id = ?', [$id]));
    }

    public function table(array $query): array
    {
        Input::only($query, ['season_id']);
        $seasonId = $this->seasonId($query);
        $rows = []; $updated = null; $title = 'League table';
        if ($seasonId === $this->currentSeason()) {
            $path = dirname(__DIR__, 2) . '/cache/wosfl_table.json';
            if (is_file($path)) {
                $rows = json_decode((string) file_get_contents($path), true) ?: [];
                $updated = gmdate('c', (int) filemtime($path));
            }
            $path = dirname(__DIR__, 2) . '/league-config.json';
            $cfg = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            $title = (string) ($cfg['league_title'] ?? $title);
        } else {
            $record = $this->rows('SELECT standings_json,competition_title,updated_at,created_at FROM league_table_history WHERE season_id = ?', [$seasonId]);
            if ($record) {
                $rows = json_decode($record[0]['standings_json'], true) ?: [];
                $updated = self::timestamp($record[0]['updated_at'] ?: $record[0]['created_at']);
                $title = $record[0]['competition_title'];
            }
        }
        $mapped = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r)) continue;
            $item = ['club' => (string) ($r['club'] ?? '')];
            foreach (['pos' => 'position','p' => 'played','w' => 'won','d' => 'drawn','l' => 'lost','f' => 'goals_for','a' => 'goals_against','gd' => 'goal_difference','pts' => 'points'] as $source => $target) {
                $item[$target] = (int) ($r[$source] ?? 0);
            }
            $mapped[] = $item;
        }
        return ['season_id' => $seasonId, 'title' => $title, 'available' => $mapped !== [], 'updated_at' => $updated, 'rows' => $mapped];
    }

    public static function timestamp(?string $value): ?string
    {
        return $value ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null;
    }

    public function profile(array $actor): array
    {
        $r = $this->one('SELECT a.id AS account_id,a.email,p.id AS person_id,p.display_name,p.phone,p.address_line1,p.address_line2,
            p.town,p.postcode,p.country,p.profile_image_path FROM accounts a JOIN people p ON p.id = a.person_id WHERE a.id = ?', [$actor['account_id']]);
        $r['account_id'] = (int) $r['account_id'];
        $r['person_id'] = (int) $r['person_id'];
        $r['profile_image_url'] = $this->url($r['profile_image_path']);
        unset($r['profile_image_path']);
        return $r;
    }

    public function updateProfile(array $actor, array $input, Auth $auth): array
    {
        // Deliberately exclude login email, role, activation, DOB and identity links.
        $fields = ['display_name' => 190, 'phone' => 50, 'address_line1' => 190, 'address_line2' => 190, 'town' => 120, 'postcode' => 30, 'country' => 120];
        Input::only($input, array_keys($fields));
        if (!$input) throw new ApiError(422, 'validation_failed', 'Provide at least one profile field.');
        $values = [];
        foreach ($input as $key => $value) $values[$key] = Input::text($input, $key, $fields[$key], $key === 'display_name');
        $this->pdo->beginTransaction();
        try {
            $this->one('SELECT id FROM people WHERE id = ? FOR UPDATE', [$actor['person_id']]);
            $set = implode(',', array_map(static fn($key) => "$key = ?", array_keys($values)));
            $this->pdo->prepare('UPDATE people SET ' . $set . ' WHERE id = ?')->execute([...array_values($values), $actor['person_id']]);
            // Synchronize only these fields in the compatibility record, preserving all other data.
            $legacySet = implode(',', array_map(static fn($key) => 'h.' . ($key === 'display_name' ? 'name' : $key) . ' = ?', array_keys($values)));
            $this->pdo->prepare('UPDATE season_ticket_holders h JOIN identity_migration_map m ON m.old_holder_id = h.id SET '
                . $legacySet . ' WHERE m.person_id = ?')->execute([...array_values($values), $actor['person_id']]);
            $this->pdo->prepare("UPDATE season_ticket_holders h JOIN identity_migration_map m ON m.old_holder_id = h.id
                SET h.address = LEFT(CONCAT_WS(', ',NULLIF(h.address_line1,''),NULLIF(h.address_line2,''),NULLIF(h.town,''),NULLIF(h.country,''),NULLIF(h.postcode,'')),255)
                WHERE m.person_id = ?")->execute([$actor['person_id']]);
            $auth->audit('profile_updated', $actor['account_id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return $this->profile($actor);
    }

    public function passes(array $actor, array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page('SELECT sp.id,sp.season_id,t.name AS type_name,se.name AS season_name,e.status,e.valid_from,e.valid_until,
            c.token,c.manual_code,c.is_active,c.revoked_at
            FROM season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id
            JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id JOIN seasons se ON se.id = sp.season_id
            LEFT JOIN ticket_credentials c ON c.id = (SELECT MAX(tc.id) FROM ticket_credentials tc WHERE tc.entitlement_id = e.id)
            WHERE e.person_id = ?', [$actor['person_id']], 'se.start_date DESC,sp.id DESC', $query, function ($r) {
                $usable = $r['status'] === 'active' && (int) $r['is_active'] === 1 && $r['revoked_at'] === null;
                $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->config['timezone'])))->format('Y-m-d');
                $usable = $usable && (!$r['valid_from'] || $r['valid_from'] <= $today) && (!$r['valid_until'] || $r['valid_until'] >= $today);
                return ['id' => (int) $r['id'], 'season_id' => (int) $r['season_id'], 'season_name' => $r['season_name'],
                    'type_name' => $r['type_name'], 'status' => $r['status'], 'valid_from' => $r['valid_from'], 'valid_until' => $r['valid_until'],
                    'qr_payload' => $usable ? $r['token'] : null, 'manual_code' => $usable ? $r['manual_code'] : null];
            });
    }

    public function orders(array $actor, array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page('SELECT id,status,total_amount,currency,created_at FROM orders WHERE person_id = ?', [$actor['person_id']], 'created_at DESC,id DESC', $query,
            $this->orderView(...));
    }

    private function orderView(array $r): array
    {
        return ['id' => (int) $r['id'], 'status' => $r['status'], 'total_amount' => (string) $r['total_amount'],
            'currency' => $r['currency'], 'created_at' => self::timestamp($r['created_at'])];
    }

    public function order(array $actor, int $id): array
    {
        $r = $this->one('SELECT id,status,total_amount,currency,created_at FROM orders WHERE id = ? AND person_id = ?', [$id, $actor['person_id']]);
        $out = $this->orderView($r);
        $out['items'] = array_map(static fn($i) => ['id' => (int) $i['id'], 'product_type' => $i['product_type'],
            'description' => $i['description_snapshot'], 'quantity' => (int) $i['quantity'], 'unit_price' => (string) $i['unit_price'], 'line_total' => (string) $i['line_total']],
            $this->rows('SELECT id,product_type,description_snapshot,quantity,unit_price,line_total FROM order_items WHERE order_id = ? ORDER BY id', [$id]));
        return $out;
    }

    private function ticketSelect(): string
    {
        return 'SELECT t.id,t.fixture_id,t.ticket_label,t.admits_count,e.status,c.token,c.manual_code,c.is_active,c.revoked_at,
            o.id AS order_id,o.status AS order_status,f.opponent,f.match_date,f.kickoff_time,
            (SELECT MAX(a.admitted_at) FROM admissions a WHERE a.entitlement_id = e.id AND a.fixture_id = t.fixture_id) AS admitted_at
            FROM match_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id
            JOIN orders o ON o.id = oi.order_id JOIN match_fixtures f ON f.id = t.fixture_id
            LEFT JOIN ticket_credentials c ON c.id = (SELECT MAX(tc.id) FROM ticket_credentials tc WHERE tc.entitlement_id = e.id)
            WHERE (e.person_id = ? OR o.person_id = ?)';
    }

    private function ticketView(array $r): array
    {
        $usable = $r['status'] === 'active' && (int) $r['is_active'] === 1 && $r['revoked_at'] === null
            && in_array($r['order_status'], ['paid','complete','partially_refunded'], true);
        return ['id' => (int) $r['id'], 'fixture_id' => (int) $r['fixture_id'], 'order_id' => (int) $r['order_id'],
            'label' => $r['ticket_label'], 'admits_count' => (int) $r['admits_count'], 'status' => $r['status'],
            'opponent' => $r['opponent'], 'match_date' => $r['match_date'], 'kickoff_time' => $r['kickoff_time'],
            'timezone' => $this->config['timezone'], 'admitted_at' => self::timestamp($r['admitted_at']),
            'qr_payload' => $usable ? $r['token'] : null, 'manual_code' => $usable ? $r['manual_code'] : null];
    }

    public function tickets(array $actor, array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page($this->ticketSelect(), [$actor['person_id'], $actor['person_id']], 'f.match_date DESC,t.id DESC', $query, $this->ticketView(...));
    }

    public function ticket(array $actor, int $id): array
    {
        return $this->ticketView($this->one($this->ticketSelect() . ' AND t.id = ?', [$actor['person_id'], $actor['person_id'], $id]));
    }

    public function sessions(array $actor, array $query): array
    {
        Input::only($query, ['page','per_page']);
        return $this->page('SELECT id,device_name,created_at,last_used_at,refresh_expires_at FROM mobile_api_sessions
            WHERE account_id = ? AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP()', [$actor['account_id']], 'created_at DESC,id', $query,
            static fn($r) => ['id' => $r['id'], 'device_name' => $r['device_name'], 'is_current' => $r['id'] === $actor['session_id'],
                'created_at' => self::timestamp($r['created_at']), 'last_used_at' => self::timestamp($r['last_used_at']),
                'expires_at' => self::timestamp($r['refresh_expires_at'])]);
    }
}
