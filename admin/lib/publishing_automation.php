<?php

declare(strict_types=1);

require_once __DIR__ . '/publishing_history.php';
require_once dirname(__DIR__) . '/social_post_settings.php';
require_once __DIR__ . '/match_sponsorship.php';

/** @return array{prepared: int, skipped: int} */
function hub_publishing_prepare_match_drafts(PDO $pdo): array
{
    $preferences = social_publishing_preferences_load();
    $stmt = $pdo->query(
        "SELECT id, match_date, kickoff_time, competition, is_home, venue,
                COALESCE(NULLIF(opponent, ''), 'Opponent') AS opponent,
                starting11_starters_json
         FROM match_fixtures
         WHERE status IN ('scheduled', 'postponed')
           AND match_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 35 DAY)
         ORDER BY match_date, kickoff_time"
    );
    $prepared = 0;
    $skipped = 0;
    foreach ($stmt->fetchAll() ?: [] as $fixture) {
        $fixtureId = (int)$fixture['id'];
        $match = $fixture;
        $fixtureAt = new DateTimeImmutable((string)$fixture['match_date'] . ' ' . ((string)$fixture['kickoff_time'] ?: '15:00:00'), new DateTimeZone('Europe/London'));
        $types = ['next_match'];
        $starters = json_decode((string)($fixture['starting11_starters_json'] ?? ''), true);
        if (is_array($starters) && count(array_filter($starters)) >= 11) {
            $types[] = 'starting_xi';
        }
        foreach ($types as $postType) {
            $rule = $preferences['automation'][$postType] ?? ['mode' => 'off', 'minutes_before' => 0];
            if (($rule['mode'] ?? 'off') === 'off') {
                $skipped++;
                continue;
            }
            $scheduledFor = $fixtureAt->modify('-' . max(0, (int)($rule['minutes_before'] ?? 0)) . ' minutes');
            foreach (['facebook', 'instagram', 'x'] as $platform) {
                if (empty($preferences['platforms'][$platform]['enabled'])) {
                    continue;
                }
                $page = $postType === 'starting_xi' ? 'match_starting_11_graphic.php' : 'match_next_match.php';
                $captionContext = $postType === 'next_match'
                    ? ['fixture_sponsor_credit' => getMatchSponsorshipPublicCredit($pdo, $fixtureId)]
                    : [];
                $ok = hub_publishing_history_upsert_draft($pdo, [
                    'fixture_id' => $fixtureId,
                    'post_type' => $postType,
                    'platform' => $platform,
                    'caption' => social_post_resolve_caption($platform, $postType, $match, $captionContext),
                    'image_url' => '/' . $page . '?fixture_id=' . $fixtureId,
                    'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
                    'status' => ($rule['mode'] ?? '') === 'auto' ? 'queued' : 'draft',
                ]);
                $ok ? $prepared++ : $skipped++;
            }
        }
    }
    return ['prepared' => $prepared, 'skipped' => $skipped];
}
