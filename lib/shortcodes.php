<?php
/**
 * Shortcodes for editable public content (club pages).
 *
 * Type a tag on its own in the page body and it is replaced with live club data:
 *   [fixtures limit="5"]                  next fixtures
 *   [results limit="5"]                   latest results
 *   [table]                               current league table
 *   [leaderboard stat="goals" limit="10"] all-time top scorers (stat: goals | appearances)
 *
 * Output is generated here from escaped values, so it is safe to drop into the
 * sanitised page HTML that pub_club_page_html() produces.
 */
declare(strict_types=1);

const PUB_SHORTCODE_PATTERN = '/\[(fixtures|results|table|leaderboard)((?:\s+[a-z_]+="[^"]{0,60}")*)\s*\]/i';

/** Replace every known shortcode in $html. Unknown text is left alone. */
function pub_shortcodes(string $html): string
{
    if (!str_contains($html, '[')) {
        return $html;
    }
    return (string) preg_replace_callback(PUB_SHORTCODE_PATTERN, static function (array $m): string {
        $atts = [];
        if (preg_match_all('/([a-z_]+)="([^"]*)"/', $m[2] ?? '', $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $p) {
                $atts[$p[1]] = $p[2];
            }
        }
        try {
            return match (strtolower($m[1])) {
                'fixtures'    => pub_shortcode_fixtures((int) ($atts['limit'] ?? 5)),
                'results'     => pub_shortcode_results((int) ($atts['limit'] ?? 5)),
                'table'       => pub_shortcode_table(),
                'leaderboard' => pub_shortcode_leaderboard((string) ($atts['stat'] ?? 'goals'), (int) ($atts['limit'] ?? 10)),
                default       => $m[0],
            };
        } catch (Throwable) {
            return ''; // a broken data source must never break the page
        }
    }, $html);
}

function pub_shortcode_fixtures(int $limit): string
{
    $rows = pub_upcoming_fixtures(max(1, min(20, $limit)));
    if (!$rows) {
        return '<p class="sc-empty">No upcoming fixtures.</p>';
    }
    $out = '<ul class="sc-list sc-fixtures">';
    foreach ($rows as $f) {
        $t = pub_fixture_teams($f);
        $out .= '<li><a href="' . e(url('match/' . (int) $f['id'])) . '">'
            . '<span class="sc-date">' . e(format_date($f['match_date'], 'j M')) . '</span>'
            . '<span class="sc-teams">' . e($t['home']) . ' v ' . e($t['away']) . '</span>'
            . '<span class="sc-meta">' . e(trim(($f['kickoff_time'] ? format_time($f['kickoff_time']) : '') . ' ' . pub_competition_short((string) ($f['competition'] ?? '')))) . '</span>'
            . '</a></li>';
    }
    return $out . '</ul>';
}

function pub_shortcode_results(int $limit): string
{
    $rows = pub_recent_results(max(1, min(20, $limit)));
    if (!$rows) {
        return '<p class="sc-empty">No results yet.</p>';
    }
    $out = '<ul class="sc-list sc-results">';
    foreach ($rows as $f) {
        $t = pub_fixture_teams($f);
        $score = (int) $f['full_time_home_score'] . '&ndash;' . (int) $f['full_time_away_score'];
        $out .= '<li><a href="' . e(url('match/' . (int) $f['id'])) . '">'
            . '<span class="sc-date">' . e(format_date($f['match_date'], 'j M')) . '</span>'
            . '<span class="sc-teams">' . e($t['home']) . ' ' . $score . ' ' . e($t['away']) . '</span>'
            . '</a></li>';
    }
    return $out . '</ul>';
}

function pub_shortcode_table(): string
{
    $table = pub_league_table();
    if (empty($table['ok']) || empty($table['rows'])) {
        return '<p class="sc-empty">The league table isn\'t available right now.</p>';
    }
    $out = '<div class="tablewrap"><table class="leaguetable sc-table"><thead><tr>'
        . '<th class="c">#</th><th>Club</th><th class="c">P</th><th class="c">W</th><th class="c">D</th><th class="c">L</th><th class="c">GD</th><th class="c">Pts</th>'
        . '</tr></thead><tbody>';
    foreach ($table['rows'] as $i => $row) {
        $isUs = pub_league_is_us($row);
        $out .= '<tr' . ($isUs ? ' class="is-us"' : '') . '>'
            . '<td class="c">' . e((string) ($row['pos'] ?: $i + 1)) . '</td>'
            . '<td class="club"><span>' . e((string) ($row['club'] ?? '')) . '</span></td>'
            . '<td class="c">' . e((string) ($row['p'] ?? '')) . '</td>'
            . '<td class="c">' . e((string) ($row['w'] ?? '')) . '</td>'
            . '<td class="c">' . e((string) ($row['d'] ?? '')) . '</td>'
            . '<td class="c">' . e((string) ($row['l'] ?? '')) . '</td>'
            . '<td class="c">' . e((string) ($row['gd'] ?? '')) . '</td>'
            . '<td class="c"><strong>' . e((string) ($row['pts'] ?? '')) . '</strong></td>'
            . '</tr>';
    }
    return $out . '</tbody></table></div>';
}

function pub_shortcode_leaderboard(string $stat, int $limit): string
{
    $limit = max(1, min(50, $limit));
    if ($stat === 'appearances') {
        $rows = pub_history_appearances($limit);
        $label = 'Appearances';
        $key = 'appearances';
    } else {
        $rows = pub_history_top_scorers($limit);
        $label = 'Goals';
        $key = 'goals';
    }
    if (!$rows) {
        return '<p class="sc-empty">No figures yet.</p>';
    }
    $out = '<ol class="sc-list sc-leaders"><li class="sc-leaders__head"><span>Player</span><span>' . e($label) . '</span></li>';
    foreach ($rows as $r) {
        $out .= '<li><span>' . e((string) $r['name']) . '</span><strong>' . (int) $r[$key] . '</strong></li>';
    }
    return $out . '</ol>';
}
