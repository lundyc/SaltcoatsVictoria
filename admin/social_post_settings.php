<?php

declare(strict_types=1);

// This file is also loaded by Hub publishing pages for the helper functions
// below. When it is requested directly, send the user to the full settings UI.
$socialPostSettingsScript = isset($_SERVER['SCRIPT_FILENAME'])
    ? realpath((string) $_SERVER['SCRIPT_FILENAME'])
    : false;
if ($socialPostSettingsScript !== false && $socialPostSettingsScript === __FILE__) {
    header('Location: /admin/settings.php?tab=publishing');
    exit;
}

require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

const SOCIAL_POST_SETTINGS_FILE = __DIR__ . '/data/social_post_settings.json';
const SOCIAL_BADGE_OVERRIDES_FILE = __DIR__ . '/data/wosfl_badge_overrides.json';
const SOCIAL_PUBLISHING_PREFERENCES_FILE = __DIR__ . '/data/publishing_preferences.json';

/**
 * Facebook and X deliberately use different default caption templates for
 * the live-commentary event types (kickoff through match_update): Facebook
 * carries only the major matchday stages with fuller, club-voice wording;
 * X is the live commentary feed and stays short/punchy. Neither forces the
 * other's template — each is edited independently in Settings → Publishing
 * → Templates, and stored under its own channel key in
 * data/social_post_settings.json.
 *
 * @return array<string, array{label: string, default: string}>
 */
function social_post_content_definitions(string $channel): array
{
    $isX = $channel === 'x';

    $leagueDefault = $isX
        ? 'Updated {League Name} table.'
        : '📊 {League Name} table — automatically updated ⚽';

    $nextMatchDefault = "🔴🟡 𝗡𝗘𝗫𝗧 𝗠𝗔𝗧𝗖𝗛 🟡🔴\n\n{Next Match Introduction}\n\n{Fixture Sponsor Credit}\n\n🆚 {Opponent}\n📅 {Match Date Long}\n⏰ {Kickoff Time 12h} Kick-Off\n📍 {Ground}\n\n{Admission Details}\n\n{Match Call To Action}\n\nOur Club. Our Town. 🔴🟡";

    // Matchday is a distinct, same-day post from Next Match (the earlier
    // advance announcement) — shorter, "it's today" framing, still posted
    // from the Next Match graphic editor (match_next_match.php) via its
    // post-type toggle, reusing the same graphic.
    $matchdayDefault = $isX
        ? "⚽️ MATCHDAY\n\n{Opponent} today, {Kickoff Time 12h} KO\n📍 {Ground}"
        : "🔴🟡 𝗠𝗔𝗧𝗖𝗛𝗗𝗔𝗬! 🟡🔴\n\n{Opponent} at {Ground} today!\n\n{Fixture Sponsor Credit}\n\n⏰ {Kickoff Time 12h} Kick-Off\n📍 {Ground}\n\n{Admission Details}\n\nOur Club. Our Town. 🔴🟡";

    // Facebook only automatically receives the major matchday stages
    // (Matchday/next_match, Starting XI, Kick-off, Half-time, Second Half,
    // Full-time — see facebook_post_type_defaults()). Goal/card/sub/penalty/
    // match_update Facebook templates below are still used for the manual
    // "Post to Facebook Anyway" override. Sponsor credit (where configured)
    // is appended automatically by social_post_resolve_caption(), not baked
    // into these templates.
    $facebookLiveTemplates = [
        'kickoff' => "⚽ 𝗞𝗜𝗖𝗞-𝗢𝗙𝗙\n\nWe're underway at {Ground}!\n\n{Score Full}",
        'goal' => "⚽ {Minute} GOAL | {Score Full}",
        'player_of_match' => "PLAYER OF THE MATCH ⭐\n\n{Player}\n\n{Score}",
        'half_time' => "⏱️ 𝗛𝗔𝗟𝗙-𝗧𝗜𝗠𝗘\n\n{Score Full}\n\nHalf-time at {Ground}.",
        'second_half' => "⏱️ 𝗦𝗘𝗖𝗢𝗡𝗗 𝗛𝗔𝗟𝗙\n\nWe're back underway at {Ground}.\n\n{Score Full}",
        'full_time' => "⏱️ 𝗙𝗨𝗟𝗟-𝗧𝗜𝗠𝗘\n\n{Score Full}\n\n{Scorer Credits}",
        'substitution' => "🔄 {Minute} SUB | {Team Full}\nOFF: {Player Off} · ON: {Player On}",
        'yellow_card' => "🟨 {Minute} YELLOW CARD | {Team Full}",
        'red_card' => "🟥 {Minute} RED CARD | {Team Full}",
        'penalty' => "⚠️ {Minute} PENALTY | {Team Full}",
        'match_update' => "{Event Headline}\n{Score Full}",
    ];

    // X is the live commentary platform — every event type is eligible and
    // stays short. No sponsor/venue boilerplate; {Score Full} carries the
    // context instead of a longer sentence.
    $xLiveTemplates = [
        'kickoff' => "⚽ KICK-OFF\n\n{Home Team} v {Away Team}",
        'goal' => "⚽ {Minute} GOAL!\n\n{Score Full}\n\n{Player}",
        'player_of_match' => "⭐ PLAYER OF THE MATCH\n\n{Player}",
        'half_time' => "⏱️ HT\n\n{Score Full}",
        'second_half' => "⏱️ Back underway.\n\n{Score Full}",
        'full_time' => "⏱️ FT\n\n{Score Full}",
        'substitution' => "🔄 {Minute} SUBSTITUTION\n\n⬅️ {Player Off}\n➡️ {Player On}",
        'yellow_card' => "🟨 {Minute} Yellow card\n\n{Team Full}",
        'red_card' => "🟥 {Minute} RED CARD\n\n{Team Full}",
        'penalty' => "⚠️ {Minute} PENALTY\n\n{Team Full}",
        'match_update' => "{Event Headline}\n{Score Full}",
    ];

    $liveTemplates = $isX ? $xLiveTemplates : $facebookLiveTemplates;

    return [
        'league_table' => ['label' => 'League Table', 'default' => $leagueDefault],
        'next_match' => ['label' => 'Next Match', 'default' => $nextMatchDefault],
        'matchday' => ['label' => 'Matchday', 'default' => $matchdayDefault],
        'monthly_fixtures' => ['label' => 'Monthly Fixtures', 'default' => "🔴🟡 {Month} FIXTURES 🟡🔴\n\nHere are our fixtures for {Month}.\n\nAll fixtures are subject to change.\n\nOur Club. Our Town. 🔴🟡"],
        'starting_xi' => ['label' => 'Starting XI', 'default' => "Today's Starting XI against {Opponent}"],
        'kickoff' => ['label' => 'Kick Off', 'default' => $liveTemplates['kickoff']],
        'goal' => ['label' => 'Goal', 'default' => $liveTemplates['goal']],
        'player_of_match' => ['label' => 'Player of the Match', 'default' => $liveTemplates['player_of_match']],
        'half_time' => ['label' => 'Half Time', 'default' => $liveTemplates['half_time']],
        'second_half' => ['label' => 'Second Half', 'default' => $liveTemplates['second_half']],
        'full_time' => ['label' => 'Full Time', 'default' => $liveTemplates['full_time']],
        'substitution' => ['label' => 'Substitution', 'default' => $liveTemplates['substitution']],
        'yellow_card' => ['label' => 'Yellow Card', 'default' => $liveTemplates['yellow_card']],
        'red_card' => ['label' => 'Red Card', 'default' => $liveTemplates['red_card']],
        'penalty' => ['label' => 'Penalty', 'default' => $liveTemplates['penalty']],
        'match_update' => ['label' => 'General Match Update', 'default' => $liveTemplates['match_update']],
        'sponsor_shoutout' => ['label' => 'Match Sponsors', 'default' => "🙌 TODAY'S MATCH SPONSORS 🙌\n\n{Sponsor Shoutout Lines}\n\nThank you for your support of {Home Team} v {Away Team}!\n\nOur Club. Our Town. 🔴🟡"],
    ];
}

/**
 * @return array<string, array<string, array<string, string>>>
 */
function social_post_template_definitions(): array
{
    return [
        'facebook' => [
            'label' => 'Facebook',
            'graphics' => social_post_content_definitions('facebook'),
        ],
        'instagram' => [
            'label' => 'Instagram',
            'graphics' => social_post_content_definitions('instagram'),
        ],
        'x' => [
            'label' => 'X',
            'graphics' => social_post_content_definitions('x'),
        ],
    ];
}

/**
 * @return array<string, array<string, array<string, array{label: string, caption: string}>>>
 */
function social_post_preset_definitions(): array
{
    $definitions = social_post_template_definitions();

    return [
        'facebook' => [
            'league_table' => [
                'default' => [
                    'label' => 'Default',
                    'caption' => $definitions['facebook']['graphics']['league_table']['default'],
                ],
                'short' => [
                    'label' => 'Short',
                    'caption' => '{League Name} table, updated.',
                ],
                'plain' => [
                    'label' => 'Plain',
                    'caption' => '{League Name} Table',
                ],
                'fresh' => [
                    'label' => 'Fresh',
                    'caption' => 'Fresh {League Name} standings.',
                ],
            ],
        ],
        'instagram' => [
            'league_table' => [
                'default' => [
                    'label' => 'Default',
                    'caption' => $definitions['instagram']['graphics']['league_table']['default'],
                ],
                'short' => [
                    'label' => 'Short',
                    'caption' => '{League Name} table, updated.',
                ],
                'plain' => [
                    'label' => 'Plain',
                    'caption' => '{League Name} Table',
                ],
                'fresh' => [
                    'label' => 'Fresh',
                    'caption' => 'Fresh {League Name} standings.',
                ],
            ],
        ],
        'x' => [
            'league_table' => [
                'default' => [
                    'label' => 'Default',
                    'caption' => $definitions['x']['graphics']['league_table']['default'],
                ],
                'short' => [
                    'label' => 'Short',
                    'caption' => 'Updated {League Name} table.',
                ],
                'plain' => [
                    'label' => 'Plain',
                    'caption' => '{League Name} Table',
                ],
                'fresh' => [
                    'label' => 'Fresh',
                    'caption' => 'Fresh standings update for {League Name}.',
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, array<string, string|array<string, array{label: string, caption: string}>>>
 */
function social_post_settings_defaults(): array
{
    $definitions = social_post_template_definitions();
    $presets = social_post_preset_definitions();
    $defaults = [];

    foreach ($definitions as $channelKey => $channel) {
        $defaults[$channelKey] = ['presets' => $presets[$channelKey] ?? []];
        foreach ($channel['graphics'] as $graphicKey => $graphic) {
            $defaults[$channelKey][$graphicKey] = $graphic['default'];
        }
    }

    return $defaults;
}

/**
 * @return array<string, array<string, string|array<string, array{label: string, caption: string}>>>
 */
function social_post_settings_load(): array
{
    $settings = social_post_settings_defaults();
    if (!is_file(SOCIAL_POST_SETTINGS_FILE)) {
        return $settings;
    }

    $json = file_get_contents(SOCIAL_POST_SETTINGS_FILE);
    if ($json === false || trim($json) === '') {
        return $settings;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $settings;
    }

    $definitions = social_post_template_definitions();
    $presetDefinitions = social_post_preset_definitions();

    foreach ($definitions as $channelKey => $channel) {
        $channelSettings = isset($decoded[$channelKey]) && is_array($decoded[$channelKey]) ? $decoded[$channelKey] : [];

        foreach ($channel['graphics'] as $graphicKey => $graphic) {
            if (isset($channelSettings[$graphicKey]) && is_string($channelSettings[$graphicKey])) {
                $settings[$channelKey][$graphicKey] = social_post_normalize_template($channelSettings[$graphicKey], $graphic['default']);
            }
        }

        if (isset($channelSettings['presets']) && is_array($channelSettings['presets'])) {
            foreach ($presetDefinitions[$channelKey] ?? [] as $graphicKey => $presetMap) {
                $channelPresetSettings = isset($channelSettings['presets'][$graphicKey]) && is_array($channelSettings['presets'][$graphicKey])
                    ? $channelSettings['presets'][$graphicKey]
                    : [];

                foreach ($presetMap as $presetKey => $preset) {
                    $submitted = isset($channelPresetSettings[$presetKey]) && is_array($channelPresetSettings[$presetKey])
                        ? $channelPresetSettings[$presetKey]
                        : [];
                    $label = isset($submitted['label']) && is_string($submitted['label'])
                        ? social_post_normalize_template($submitted['label'], $preset['label'])
                        : $preset['label'];
                    $caption = isset($submitted['caption']) && is_string($submitted['caption'])
                        ? social_post_normalize_template($submitted['caption'], $preset['caption'])
                        : $preset['caption'];

                    $settings[$channelKey]['presets'][$graphicKey][$presetKey] = [
                        'label' => $label,
                        'caption' => $caption,
                    ];
                }
            }
        }
    }

    return $settings;
}

/**
 * @param array<string, array<string, string|array<string, array{label: string, caption: string}>>> $settings
 */
function social_post_settings_save(array $settings): bool
{
    if (!is_dir(dirname(SOCIAL_POST_SETTINGS_FILE)) && !@mkdir(dirname(SOCIAL_POST_SETTINGS_FILE), 0775, true) && !is_dir(dirname(SOCIAL_POST_SETTINGS_FILE))) {
        return false;
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return file_put_contents(SOCIAL_POST_SETTINGS_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

/** @return array<string, mixed> */
function social_publishing_preferences_defaults(): array
{
    $automation = [];
    $facebookDefaults = facebook_post_type_defaults();
    foreach (social_post_content_definitions('facebook') as $key => $definition) {
        $fbDefault = $facebookDefaults[$key] ?? ['priority' => 'low', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false];
        $automation[$key] = [
            'mode' => in_array($key, ['league_table', 'next_match'], true) ? 'draft' : 'confirm',
            'minutes_before' => $key === 'next_match' ? 1440 : 0,
            'grace_seconds' => in_array($key, ['goal', 'yellow_card', 'red_card', 'penalty', 'substitution'], true) ? 20 : 0,
            'facebook_priority' => $fbDefault['priority'],
            'facebook_enabled' => $fbDefault['facebook_enabled'],
            'x_enabled' => $fbDefault['x_enabled'],
            'facebook_include_sponsor' => $fbDefault['facebook_include_sponsor'],
        ];
    }

    return [
        'global' => [
            'facebook_hashtags' => '',
            'instagram_hashtags' => '',
            'x_hashtags' => '',
            'include_player_sponsor' => true,
            'confirm_before_live_post' => true,
        ],
        'visuals' => [
            'starting_xi' => ['white_badges' => false, 'white_sponsor_logos' => true, 'gradient_color' => '#000000', 'gradient_strength' => 70, 'layout' => 'list'],
            'next_match' => ['white_badges' => true, 'white_sponsor_logos' => true, 'gradient_color' => '#000000', 'gradient_strength' => 70, 'layout' => 'portrait'],
            'monthly_fixtures' => ['white_badges' => false, 'gradient_color' => '#000000', 'gradient_strength' => 55, 'layout' => 'block'],
            'events' => ['white_badges' => true, 'white_sponsor_logos' => true, 'gradient_color' => '#000000', 'gradient_strength' => 70, 'layout' => 'square'],
        ],
        'automation' => $automation,
        'platforms' => [
            'facebook' => ['enabled' => true],
            'instagram' => ['enabled' => true],
            'x' => ['enabled' => true],
        ],
    ];
}

/** @return array<string, mixed> */
function social_publishing_preferences_load(): array
{
    $defaults = social_publishing_preferences_defaults();
    if (!is_file(SOCIAL_PUBLISHING_PREFERENCES_FILE)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents(SOCIAL_PUBLISHING_PREFERENCES_FILE), true);
    return is_array($decoded) ? array_replace_recursive($defaults, $decoded) : $defaults;
}

/** @param array<string, mixed> $preferences */
function social_publishing_preferences_save(array $preferences): bool
{
    $json = json_encode($preferences, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== false && file_put_contents(SOCIAL_PUBLISHING_PREFERENCES_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

function social_publishing_preference_bool(array $source, string $key, bool $fallback = false): bool
{
    if (!array_key_exists($key, $source)) {
        return $fallback;
    }
    return in_array(strtolower((string) $source[$key]), ['1', 'true', 'yes', 'on'], true);
}

function social_publishing_platform_enabled(string $channel): bool
{
    $preferences = social_publishing_preferences_load();
    return !empty($preferences['platforms'][$channel]['enabled']);
}

/**
 * @return array<string, string>
 */
function social_badge_overrides_load(): array
{
    if (!is_file(SOCIAL_BADGE_OVERRIDES_FILE)) {
        return [];
    }

    $json = file_get_contents(SOCIAL_BADGE_OVERRIDES_FILE);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $overrides = [];
    foreach ($decoded as $clubName => $badgeFile) {
        if (!is_string($clubName) || !is_string($badgeFile)) {
            continue;
        }

        $clubName = trim($clubName);
        $badgeFile = trim($badgeFile);
        if ($clubName === '' || $badgeFile === '') {
            continue;
        }

        $overrides[$clubName] = $badgeFile;
    }

    ksort($overrides, SORT_NATURAL | SORT_FLAG_CASE);

    return $overrides;
}

/**
 * @param array<string, string> $overrides
 */
function social_badge_overrides_save(array $overrides): bool
{
    if (!is_dir(dirname(SOCIAL_BADGE_OVERRIDES_FILE)) && !@mkdir(dirname(SOCIAL_BADGE_OVERRIDES_FILE), 0775, true) && !is_dir(dirname(SOCIAL_BADGE_OVERRIDES_FILE))) {
        return false;
    }

    $clean = [];
    foreach ($overrides as $clubName => $badgeFile) {
        $clubName = trim((string) $clubName);
        $badgeFile = trim((string) $badgeFile);
        if ($clubName === '' || $badgeFile === '') {
            continue;
        }

        $clean[$clubName] = $badgeFile;
    }

    ksort($clean, SORT_NATURAL | SORT_FLAG_CASE);

    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $dir = dirname(SOCIAL_BADGE_OVERRIDES_FILE);
    $tmpFile = tempnam($dir, 'badge-overrides-');
    if ($tmpFile === false) {
        return false;
    }

    $tmpJsonFile = $tmpFile . '.json';
    if (!@rename($tmpFile, $tmpJsonFile)) {
        @unlink($tmpFile);
        return false;
    }

    $bytesWritten = file_put_contents($tmpJsonFile, $json . PHP_EOL, LOCK_EX);
    if ($bytesWritten === false) {
        @unlink($tmpJsonFile);
        return false;
    }

    if (is_file(SOCIAL_BADGE_OVERRIDES_FILE) && !@unlink(SOCIAL_BADGE_OVERRIDES_FILE)) {
        @unlink($tmpJsonFile);
        return false;
    }

    if (!@rename($tmpJsonFile, SOCIAL_BADGE_OVERRIDES_FILE)) {
        @unlink($tmpJsonFile);
        return false;
    }

    @chmod(SOCIAL_BADGE_OVERRIDES_FILE, 0664);

    return true;
}

function social_post_normalize_template(string $value, string $fallback): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim($value));
    return $normalized !== '' ? $normalized : $fallback;
}

/**
 * Resolve the competition name from the fixture or the current season.
 *
 * @param array<string, mixed>|null $match
 * @param array<string, mixed> $context
 */
function social_post_competition_name(?array $match = null, array $context = []): string
{
    $contextName = trim((string)($context['league_name'] ?? ''));
    if ($contextName !== '') {
        return $contextName;
    }

    $matchCompetition = trim((string)($match['competition'] ?? ''));
    if ($matchCompetition !== '') {
        return $matchCompetition;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO && is_file(__DIR__ . '/db.php')) {
        try {
            require_once __DIR__ . '/db.php';
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
            }
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("
                SELECT mc.name
                FROM seasons s
                INNER JOIN match_competitions mc ON mc.id = s.competition_id
                WHERE s.is_current = 1
                ORDER BY s.id DESC
                LIMIT 1
            ");
            $currentCompetition = trim((string)$stmt->fetchColumn());
            if ($currentCompetition !== '') {
                return $currentCompetition;
            }
        } catch (Throwable $e) {
            // A neutral fallback keeps caption rendering available during setup.
        }
    }

    return 'Competition';
}

/**
 * @param array<string, mixed>|null $match
 * @param array<string, mixed> $context
 * @return array<string, string>
 */
function social_post_placeholder_values(?array $match, array $context = []): array
{
    $teamName = trim((string) ($match['opponent'] ?? ''));
    $competition = trim((string) ($match['competition'] ?? ''));
    $matchDateRaw = trim((string) ($match['match_date'] ?? ''));
    $kickoffTimeRaw = trim((string) ($match['kickoff_time'] ?? ''));
    $venueCode = strtoupper(trim((string) ($match['venue'] ?? 'H')));
    if (isset($match['is_home'])) {
        $venueCode = !empty($match['is_home']) ? 'H' : 'A';
    }
    $homeTeam = trim((string) ($context['home_team'] ?? ($venueCode === 'A' ? $teamName : 'Saltcoats Victoria')));
    $awayTeam = trim((string) ($context['away_team'] ?? ($venueCode === 'A' ? 'Saltcoats Victoria' : $teamName)));
    $leagueName = social_post_competition_name($match, $context);

    $matchDate = $matchDateRaw;
    $matchDateLong = $matchDateRaw;
    $matchDay = '';
    if ($matchDateRaw !== '') {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $matchDateRaw);
        if ($date instanceof DateTimeImmutable) {
            $matchDate = $date->format('d/m/Y');
            $matchDateLong = $date->format('l j F');
            $matchDay = $date->format('l');
        }
    }

    $kickoffTime = $kickoffTimeRaw;
    $kickoffTime12h = $kickoffTimeRaw;
    if ($kickoffTimeRaw !== '') {
        $time = DateTimeImmutable::createFromFormat('!H:i:s', $kickoffTimeRaw)
            ?: DateTimeImmutable::createFromFormat('!H:i', $kickoffTimeRaw);
        if ($time instanceof DateTimeImmutable) {
            $kickoffTime = $time->format('H:i');
            $kickoffTime12h = strtolower($time->format('g:ia'));
        }
    }

    $ground = trim((string) ($context['ground'] ?? ($match['ground'] ?? ($match['venue_name'] ?? ($match['venue'] ?? '')))));
    if ($ground === '' || in_array(strtoupper($ground), ['H', 'A', 'HOME', 'AWAY'], true)) {
        $ground = $venueCode === 'A' ? 'Away' : 'Campbell Park';
    }

    $competitionFull = trim((string) ($context['competition_full'] ?? ''));
    if ($competitionFull === '') {
        $competitionFull = $competition;
        if ($competition !== ''
            && preg_match('/^(Premier|First|Second|Third|Fourth) Division$/i', $competition) === 1) {
            $competitionFull = 'West of Scotland Football League ' . $competition;
        }
    }

    $opponent = $teamName !== '' ? $teamName : 'the opposition';
    $isLeagueFixture = preg_match('/\b(?:Premier|First|Second|Third|Fourth) Division\b/i', $competitionFull) === 1
        || stripos($competitionFull, 'League') !== false;
    $matchKind = $isLeagueFixture ? 'League football' : 'Match action';
    if ($venueCode === 'A') {
        $nextMatchIntroduction = 'The Vics are on the road this ' . $matchDay
            . ' as we travel to face ' . $opponent . ($competitionFull !== '' ? ' in the ' . $competitionFull : '') . '.';
        $admissionDetails = '';
        $matchCallToAction = 'Make the trip and get behind the Vics!';
    } else {
        $nextMatchIntroduction = $matchKind . ' returns to ' . $ground . ' this ' . $matchDay
            . ' as we welcome ' . $opponent . ($competitionFull !== '' ? ' in the ' . $competitionFull : '') . '.';
        $admissionDetails = "☕ Food and refreshments available.\n\n🎟️ Admission\nAdults: £6\nConcessions: £3";
        $matchCallToAction = 'Get along to ' . $ground . ', get behind the team, and cheer the Vics on!';
    }

    return [
        '{Team Name}' => $teamName !== '' ? $teamName : 'the opposition',
        '{Opponent}' => $teamName !== '' ? $teamName : 'the opposition',
        '{Competition}' => $competition,
        '{Competition Full}' => $competitionFull,
        '{Match Date}' => $matchDate,
        '{Match Date Long}' => $matchDateLong,
        '{Match Day}' => $matchDay,
        '{Kickoff Time}' => $kickoffTime,
        '{Kickoff Time 12h}' => $kickoffTime12h,
        '{Venue}' => $venueCode === 'A' ? 'Away' : 'Home',
        '{Ground}' => $ground,
        '{Home Team}' => $homeTeam,
        '{Away Team}' => $awayTeam,
        '{League Name}' => $leagueName,
        '{Score}' => trim((string) ($context['score'] ?? '')),
        '{Scorer Credits}' => trim((string) ($context['scorer_credits'] ?? '')),
        '{Minute}' => trim((string) ($context['minute'] ?? '')),
        '{Player}' => trim((string) ($context['player'] ?? '')),
        '{Player Off}' => trim((string) ($context['player_off'] ?? '')),
        '{Player On}' => trim((string) ($context['player_on'] ?? '')),
        '{Outcome}' => trim((string) ($context['outcome'] ?? '')),
        '{Sponsor Credit}' => trim((string) ($context['sponsor_credit'] ?? '')),
        '{Fixture Sponsor Credit}' => trim((string) ($context['fixture_sponsor_credit'] ?? '')),
        '{Next Match Introduction}' => $nextMatchIntroduction,
        '{Admission Details}' => $admissionDetails,
        '{Match Call To Action}' => $matchCallToAction,
        '{Month}' => trim((string) ($context['month'] ?? 'this month')),
        '{Event Headline}' => trim((string) ($context['event_headline'] ?? '')),
        '{Event Detail}' => trim((string) ($context['event_detail'] ?? '')),
        '{Match Day Sponsor}' => trim((string) ($context['match_day_sponsor'] ?? '')),
        '{Match Ball Sponsor}' => trim((string) ($context['match_ball_sponsor'] ?? '')),
        '{Sponsor Shoutout Lines}' => trim((string) ($context['sponsor_shoutout_lines'] ?? '')),
        '{Score Full}' => trim((string) ($context['score_full'] ?? '')),
        '{Team Full}' => trim((string) ($context['team_full'] ?? '')),
    ];
}

/**
 * @param array<string, mixed>|null $match
 */
function social_post_resolve_caption(string $channel, string $graphicType, ?array $match = null, array $context = []): string
{
    $settings = social_post_settings_load();
    $definitions = social_post_template_definitions();
    $graphicType = $graphicType === 'match' ? 'starting_xi' : $graphicType;
    $default = $definitions[$channel]['graphics'][$graphicType]['default'] ?? '';
    $template = $settings[$channel][$graphicType] ?? $default;

    if (in_array($graphicType, ['starting_xi', 'kickoff'], true) && is_array($match)) {
        $kickOffTemplate = matches_match_graphic_template($match, 'kick_off');
        $matchSpecificCaption = trim((string) ($kickOffTemplate['captions'][$channel] ?? ''));
        if ($matchSpecificCaption !== '') {
            $template = $matchSpecificCaption;
        }
    }

    $caption = strtr($template, social_post_placeholder_values($match, $context));
    $caption = preg_replace("/[ \t]+\n/", "\n", $caption);
    $caption = preg_replace("/\n{3,}/", "\n\n", (string) $caption);
    $caption = trim((string) $caption);

    // Sponsor/boilerplate content is only auto-appended for post types
    // configured to carry it (Settings → Publishing → Automation). Live
    // match event posts (goal, cards, etc) default to excluded so Facebook
    // feed posts stay concise; higher-value posts (half-time, full-time,
    // next match, sponsor shoutouts, ...) default to included.
    $includeSponsor = !in_array($channel, ['facebook', 'x'], true) || facebook_post_type_config($graphicType)['facebook_include_sponsor'];

    $fixtureSponsorCredit = trim((string)($context['fixture_sponsor_credit'] ?? ''));
    if ($includeSponsor && $fixtureSponsorCredit !== '' && !str_contains($caption, $fixtureSponsorCredit)) {
        $caption .= ($caption !== '' ? "\n\n" : '') . $fixtureSponsorCredit;
    }

    $preferences = social_publishing_preferences_load();
    $hashtags = trim((string) ($preferences['global'][$channel . '_hashtags'] ?? ''));
    if ($includeSponsor && $hashtags !== '' && $caption !== '' && !str_contains($caption, $hashtags)) {
        $caption .= "\n\n" . $hashtags;
    }

    return $caption !== '' ? $caption : $default;
}

/**
 * Resolve a caption override, falling back to the configured default.
 *
 * @param array<string, mixed>|null $match
 */
function social_post_resolve_caption_with_override(
    string $channel,
    string $graphicType,
    ?array $match = null,
    ?string $override = null,
    array $context = []
): string {
    $override = trim(str_replace(["\r\n", "\r"], "\n", (string) $override));
    if ($override !== '') {
        return trim(strtr($override, social_post_placeholder_values($match, $context)));
    }

    return social_post_resolve_caption($channel, $graphicType, $match, $context);
}

/**
 * @return array<string, array<string, array{label: string, caption: string}>>
 */
function social_post_get_preset_groups(string $channel, string $graphicType): array
{
    $settings = social_post_settings_load();
    $presetDefinitions = social_post_preset_definitions();
    $defaults = $presetDefinitions[$channel][$graphicType] ?? [];
    $current = $settings[$channel]['presets'][$graphicType] ?? [];
    $configuredDefaultCaption = social_post_resolve_caption($channel, $graphicType);

    if (!is_array($current)) {
        $current = [];
    }

    $resolved = [];
    foreach ($defaults as $presetKey => $preset) {
        if ($presetKey === 'default' && $configuredDefaultCaption !== '') {
            $caption = $configuredDefaultCaption;
        } else {
            $caption = isset($current[$presetKey]['caption']) && is_string($current[$presetKey]['caption'])
                ? social_post_normalize_template($current[$presetKey]['caption'], $preset['caption'])
                : $preset['caption'];
            $caption = str_replace('{League Name}', social_post_competition_name(), $caption);
        }
        $resolved[$presetKey] = [
            'label' => isset($current[$presetKey]['label']) && is_string($current[$presetKey]['label'])
                ? social_post_normalize_template($current[$presetKey]['label'], $preset['label'])
                : $preset['label'],
            'caption' => $caption,
        ];
    }

    return $resolved;
}
