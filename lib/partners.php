<?php

declare(strict_types=1);

/*
 * Public, read-only sponsor/partner queries against the Hub `sponsors` table.
 * Only active sponsors are ever returned.
 */

/**
 * Active CLUB partners — main sponsors first, then curated sort order, then
 * name. Includes sponsors without a logo (rendered as a name).
 *
 * Only genuine club partners appear. A sponsor qualifies if it is a flagged
 * main sponsor, or it holds a live club-level agreement — kit, ground boards,
 * team, digital, season ticket, photographer, etc. Deliberately excluded:
 *   - player sponsors (agreement has a player_id),
 *   - one-off match sponsorships: matchday / match ball / MOTM (package
 *     scope = 'match'),
 *   - sponsors with no agreement at all.
 * "Live" means the agreement's status is active or scheduled, so a lapsed
 * board/kit deal drops off on its own.
 *
 * @return list<array<string,mixed>>
 */
function pub_sponsors(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $select = 'SELECT s.id, s.name, s.logo_path, s.white_logo_path, s.website_url,
                      s.facebook_page_url, s.instagram_url, s.twitter_url,
                      s.address, s.contact_phone, s.is_main_sponsor, s.is_business
               FROM sponsors s
               WHERE s.is_active = 1';
    $order = ' ORDER BY s.is_main_sponsor DESC, s.sort_order ASC, s.name ASC';

    $partnerFilter =
        " AND (
             s.is_main_sponsor = 1
             OR EXISTS (
                 SELECT 1
                 FROM sponsorship_agreements sa
                 JOIN packages pk ON pk.id = sa.package_id
                 WHERE sa.sponsor_id = s.id
                   AND sa.player_id IS NULL
                   AND sa.status IN ('active', 'scheduled')
                   AND pk.scope NOT IN ('match', 'player')
             )
         )";

    try {
        $rows = db()->query($select . $partnerFilter . $order)->fetchAll();
    } catch (Throwable) {
        // sponsorship_agreements / packages missing (non-Hub schema) — show all active.
        $rows = db()->query($select . $order)->fetchAll();
    }
    // A logo file that is missing on disk would be a broken image: fall back to the sponsor's name.
    foreach ($rows as &$row) {
        foreach (['logo_path', 'white_logo_path'] as $col) {
            $f = trim((string) ($row[$col] ?? ''));
            if ($f !== '' && !is_file(PUBLIC_ROOT . '/uploads/sponsors/' . $f)) {
                $row[$col] = '';
            }
        }
    }
    unset($row);
    return $rows;
}

/** Active sponsors that have a logo (for the footer strip). @return list */
function pub_sponsors_with_logo(): array
{
    return array_values(array_filter(
        pub_sponsors(),
        static fn (array $s): bool => trim((string) $s['logo_path']) !== ''
    ));
}

/** Headline / principal partners. @return list */
function pub_main_sponsors(): array
{
    return array_values(array_filter(
        pub_sponsors(),
        static fn (array $s): bool => (int) $s['is_main_sponsor'] === 1
    ));
}

/** Non-headline active partners. @return list */
function pub_other_sponsors(): array
{
    return array_values(array_filter(
        pub_sponsors(),
        static fn (array $s): bool => (int) $s['is_main_sponsor'] !== 1
    ));
}

/**
 * Sponsors backing one player, grouped by kit slot. Home and Away slots are
 * always present (empty array = open for sponsorship); Third only appears when
 * it has a sponsor. Each sponsor is ['name','logo','link'].
 *
 * @return array<string,array{label:string,sponsors:list<array{name:string,logo:string,link:string}>}>
 */
function pub_player_sponsors(int $playerId): array
{
    $slots = [
        'home'  => ['label' => 'Home shirt',  'sponsors' => []],
        'away'  => ['label' => 'Away shirt',  'sponsors' => []],
        'third' => ['label' => 'Third shirt', 'sponsors' => []],
    ];
    if ($playerId <= 0) {
        unset($slots['third']);
        return $slots;
    }

    // Players who have left the club show no sponsor tiles at all.
    try {
        $st = db()->prepare("SELECT status FROM players WHERE id = :pid");
        $st->execute([':pid' => $playerId]);
        if ((string) $st->fetchColumn() !== 'current') {
            return [];
        }
    } catch (Throwable) {
        return [];
    }

    $map = ['player_home' => 'home', 'player_away' => 'away', 'player_third' => 'third'];
    try {
        $stmt = db()->prepare(
            "SELECT p.code AS pkg, s.name, s.logo_path, s.website_url,
                    s.facebook_page_url, s.instagram_url, s.twitter_url
             FROM sponsorship_agreements a
             JOIN packages p ON p.id = a.package_id
             JOIN sponsors s ON s.id = a.sponsor_id AND s.is_active = 1
             WHERE a.player_id = :pid
               AND a.status IN ('active', 'scheduled')
               AND a.season_id = :sid
               AND p.code IN ('player_home', 'player_away', 'player_third')
             ORDER BY p.code, s.name"
        );
        $stmt->execute([':pid' => $playerId, ':sid' => pub_current_season_id()]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        $rows = [];
    }

    $seen = [];
    foreach ($rows as $r) {
        $slot = $map[$r['pkg']] ?? null;
        if ($slot === null) {
            continue;
        }
        $name = trim((string) $r['name']);
        $key = $slot . '|' . strtolower($name);
        if ($name === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $slots[$slot]['sponsors'][] = [
            'name' => $name,
            'logo' => trim((string) $r['logo_path']),
            'link' => pub_sponsor_link($r),
        ];
    }

    if (empty($slots['third']['sponsors'])) {
        unset($slots['third']);
    }
    return $slots;
}

/**
 * The active/complimentary Player of the Match sponsor for one fixture, if any
 * (home matchday sponsorship — see admin/lib/match_sponsorship.php). Only a
 * paid or complimentary placement is ever shown publicly.
 *
 * @return array{name:string,logo:string}|null
 */
function pub_motm_sponsor(int $fixtureId): ?array
{
    try {
        $stmt = db()->prepare(
            "SELECT s.name, s.logo_path, ms.amount, ms.is_complimentary,
                    COALESCE((SELECT SUM(amount) FROM match_sponsorship_payments WHERE match_sponsorship_id = ms.id), 0) AS paid_total
             FROM match_sponsorships ms
             JOIN sponsors s ON s.id = ms.sponsor_id AND s.is_active = 1
             WHERE ms.fixture_id = :fid
               AND ms.sponsorship_role = 'motm'
               AND ms.ended_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':fid' => $fixtureId]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!$row) {
        return null;
    }

    $amount = max(0.0, (float) $row['amount']);
    $displayable = (int) $row['is_complimentary'] === 1
        || $amount === 0.0
        || (float) $row['paid_total'] + 0.005 >= $amount;
    if (!$displayable) {
        return null;
    }

    $name = trim((string) $row['name']);
    if ($name === '') {
        return null;
    }
    return ['name' => $name, 'logo' => trim((string) $row['logo_path'])];
}

/**
 * All active/complimentary sponsors of one fixture (matchday, match ball,
 * MOTM…), matchday first. Same "paid or complimentary" display rule as
 * pub_motm_sponsor().
 *
 * @return list<array{role:string,label:string,name:string,logo:string,link:string}>
 */
function pub_match_sponsors(int $fixtureId): array
{
    try {
        $stmt = db()->prepare(
            "SELECT s.name, s.logo_path, s.website_url, s.facebook_page_url, s.instagram_url, s.twitter_url,
                    ms.sponsorship_role, ms.amount, ms.is_complimentary, t.name AS type_name,
                    COALESCE((SELECT SUM(amount) FROM match_sponsorship_payments WHERE match_sponsorship_id = ms.id), 0) AS paid_total
             FROM match_sponsorships ms
             JOIN sponsors s ON s.id = ms.sponsor_id AND s.is_active = 1
             LEFT JOIN match_sponsorship_types t ON t.code = ms.sponsorship_role
             WHERE ms.fixture_id = :fid AND ms.ended_at IS NULL
             ORDER BY FIELD(ms.sponsorship_role, 'match_day', 'match_ball', 'motm'), ms.id"
        );
        $stmt->execute([':fid' => $fixtureId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $labels = ['match_day' => 'Matchday sponsor', 'match_ball' => 'Match ball sponsor', 'motm' => 'Player of the Match sponsor'];
    $out = [];
    foreach ($rows as $row) {
        $amount = max(0.0, (float) $row['amount']);
        if (!((int) $row['is_complimentary'] === 1 || $amount === 0.0 || (float) $row['paid_total'] + 0.005 >= $amount)) {
            continue;
        }
        $name = trim((string) $row['name']);
        if ($name === '') {
            continue;
        }
        $role = (string) $row['sponsorship_role'];
        $out[] = [
            'role'  => $role,
            'label' => $labels[$role] ?? (trim((string) $row['type_name']) !== '' ? (string) $row['type_name'] . ' sponsor' : 'Sponsor'),
            'name'  => $name,
            'logo'  => trim((string) $row['logo_path']),
            'link'  => pub_sponsor_link($row),
        ];
    }
    return $out;
}

/** First non-empty social link for a sponsor row, or ''. */
function pub_sponsor_link(array $s): string
{
    foreach (['website_url', 'facebook_page_url', 'instagram_url', 'twitter_url'] as $k) {
        $v = trim((string) ($s[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * Edge-to-edge version of a partner logo.
 *
 * Many logo files have a white (or transparent) margin baked in, which shows as a box inside the
 * tile. This makes a trimmed copy under uploads/sponsors/trim/ (the original is never touched) and
 * returns its URL and size. Logos that already have a coloured background, or that cannot be read
 * safely, are returned unchanged. At most a few trims are made per request so a page never stalls;
 * the rest are picked up on the next visit.
 *
 * @return array{url:string,w:int,h:int,trimmed:bool}|null
 */
function pub_partner_logo_fit(string $filename): ?array
{
    static $budget = 4;
    $src = PUBLIC_ROOT . '/uploads/sponsors/' . $filename;
    $info = is_file($src) ? @getimagesize($src) : false;
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        return null;
    }
    $plain = ['url' => '/uploads/sponsors/' . rawurlencode($filename), 'w' => (int) $info[0], 'h' => (int) $info[1], 'trimmed' => false];
    $name = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    $out = PUBLIC_ROOT . '/uploads/sponsors/trim/' . $name;
    $marker = $out . '.same';   // "nothing to trim" is remembered too

    if (is_file($marker) && filemtime($marker) >= filemtime($src)) {
        return $plain;
    }
    if (is_file($out) && filemtime($out) >= filemtime($src)) {
        $t = @getimagesize($out);
        return $t ? ['url' => '/uploads/sponsors/trim/' . rawurlencode($name), 'w' => (int) $t[0], 'h' => (int) $t[1], 'trimmed' => true] : $plain;
    }
    if ($budget <= 0 || !function_exists('imagewebp') || $info[0] * $info[1] > 20_000_000) {
        return $plain;
    }
    $budget--;
    @ini_set('memory_limit', '768M');
    $im = match ($info[2]) {
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        default => false,
    };
    $dir = dirname($out);
    if (!$im || (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))) {
        return $plain;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $isBg = static function (int $c): bool {
        $a = ($c >> 24) & 127;
        return $a >= 100 || ((($c >> 16) & 255) >= 232 && (($c >> 8) & 255) >= 232 && ($c & 255) >= 232);
    };
    // Only trim when the corners show white or transparent margin; coloured plates are left alone.
    $corners = [imagecolorat($im, 0, 0), imagecolorat($im, $w - 1, 0), imagecolorat($im, 0, $h - 1), imagecolorat($im, $w - 1, $h - 1)];
    $bgCorners = count(array_filter($corners, $isBg));
    if ($bgCorners < 3) {
        imagedestroy($im);
        @file_put_contents($marker, '1');
        return $plain;
    }
    $step = max(1, (int) floor(max($w, $h) / 800));
    $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if (!$isBg(imagecolorat($im, $x, $y))) {
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }
            }
        }
    }
    if ($maxX < 0) {
        imagedestroy($im);
        return $plain;
    }
    $minX = max(0, $minX - $step); $minY = max(0, $minY - $step);
    $maxX = min($w - 1, $maxX + $step); $maxY = min($h - 1, $maxY + $step);
    $cw = $maxX - $minX + 1;
    $ch = $maxY - $minY + 1;
    if ($cw * $ch > 0.95 * $w * $h) {   // barely any margin: keep the original
        imagedestroy($im);
        @file_put_contents($marker, '1');
        return $plain;
    }
    $crop = imagecrop($im, ['x' => $minX, 'y' => $minY, 'width' => $cw, 'height' => $ch]);
    imagedestroy($im);
    if (!$crop) {
        return $plain;
    }
    if ($cw > 700) {
        $scaled = imagescale($crop, 700, (int) round($ch * 700 / $cw), IMG_BICUBIC);
        if ($scaled) {
            imagedestroy($crop);
            $crop = $scaled;
        }
    }
    imagealphablending($crop, false);
    imagesavealpha($crop, true);
    $tmp = $out . '.' . getmypid() . '.tmp';
    if (!imagewebp($crop, $tmp, 88)) {
        return $plain;
    }
    rename($tmp, $out);
    $res = ['url' => '/uploads/sponsors/trim/' . rawurlencode($name), 'w' => imagesx($crop), 'h' => imagesy($crop), 'trimmed' => true];
    imagedestroy($crop);
    return $res;
}
