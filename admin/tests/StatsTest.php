<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/player_match_stats.php';

$harness->test('stats counts away wins and excludes incomplete or scheduled scores', function () use ($harness): void {
    $fixtures = [
        ['status' => 'played', 'is_home' => 0, 'full_time_home_score' => 0, 'full_time_away_score' => 2],
        ['status' => 'played', 'is_home' => 1, 'full_time_home_score' => 1, 'full_time_away_score' => 1],
        ['status' => 'played', 'is_home' => 1, 'full_time_home_score' => null, 'full_time_away_score' => null],
        ['status' => 'scheduled', 'is_home' => 1, 'full_time_home_score' => 0, 'full_time_away_score' => 0],
    ];
    $s = hub_stats_summary($fixtures);
    $harness->assertSame(3, $s['played']);
    $harness->assertSame(2, $s['scored']);
    $harness->assertSame(1, $s['wins']);
    $harness->assertSame(1, $s['draws']);
    $harness->assertSame(3, $s['goals_for']);
    $harness->assertSame(1, $s['clean_sheets']);
    $harness->assertSame(null, hub_stats_result($fixtures[2]));
    $harness->assertSame(0, hub_stats_summary([])['played']);
});

$harness->test('filtered player stats count substitutions and scored penalties without querying other matches', function () use ($harness): void {
    $pdo = new class extends PDO { public function __construct() {} };
    $fixtures = [['id' => 7, 'status' => 'played', 'is_home' => 1, 'starting11_starters_json' => '["Keeper", "Starter"]', 'starting11_substitutes_json' => '["Sub", "Unused"]', 'full_time_home_score' => 1, 'full_time_away_score' => 0]];
    $events = [7 => [
        ['type' => 'substitution', 'team' => 'svfc', 'minute' => '60', 'player' => 'Starter', 'secondary_player' => 'Sub'],
        ['type' => 'penalty', 'team' => 'svfc', 'player' => 'Sub', 'outcome' => 'scored'],
        ['type' => 'penalty', 'team' => 'svfc', 'player' => 'Sub', 'outcome' => 'missed'],
        ['type' => 'card', 'team' => 'svfc', 'player' => 'Sub', 'card_type' => 'yellow'],
        null,
    ]];
    $sub = hub_player_match_stats($pdo, 1, 'Sub', '', $fixtures, $events);
    $harness->assertSame(1, $sub['appearances']);
    $harness->assertSame(30, $sub['minutes_played']);
    $harness->assertSame(1, $sub['goals']);
    $harness->assertSame(1, $sub['yellow_cards']);
    $harness->assertSame(0, hub_player_match_stats($pdo, 1, 'Unused', '', $fixtures, $events)['appearances']);
    $harness->assertSame(60, hub_player_match_stats($pdo, 1, 'Starter', '', $fixtures, $events)['minutes_played']);
    $harness->assertSame(1, hub_player_match_stats($pdo, 1, 'Keeper', '', $fixtures, $events)['clean_sheets']);
});

$harness->test('own goals retain the benefiting team but do not identify opposition scorers as club players', function () use ($harness): void {
    $opponentOwnGoal = ['type' => 'goal', 'team' => 'svfc', 'player' => 'Opposition player', 'own_goal' => true];
    $clubOwnGoal = ['type' => 'goal', 'team' => 'opponent', 'player' => 'Club player', 'own_goal' => true];
    $harness->assertSame(false, hub_player_stats_is_club_player_event($opponentOwnGoal));
    $harness->assertSame(true, hub_player_stats_is_club_player_event($clubOwnGoal));
    $harness->assertSame(true, hub_player_stats_is_club_player_event(['team' => 'svfc', 'type' => 'goal']));
    $harness->assertSame(false, hub_player_stats_is_club_player_event(['team' => 'opponent', 'type' => 'goal']));
    $pdo = new class extends PDO { public function __construct() {} };
    $fixture = ['id' => 1, 'status' => 'played', 'is_home' => 1, 'starting11_starters_json' => '["Keeper", "Club player"]', 'starting11_substitutes_json' => '[]', 'full_time_home_score' => null, 'full_time_away_score' => null];
    $events = [1 => [$opponentOwnGoal, $clubOwnGoal, ['type' => 'full_time']]];
    $harness->assertSame(0, hub_player_match_stats($pdo, 1, 'Opposition player', '', [$fixture], $events)['goals']);
    $harness->assertSame(0, hub_player_match_stats($pdo, 1, 'Club player', '', [$fixture], $events)['goals']);
    $harness->assertSame(0, hub_player_match_stats($pdo, 1, 'Keeper', '', [$fixture], $events)['clean_sheets']);
});
