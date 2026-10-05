<?php

declare(strict_types=1);

function ensureVenueReviewsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS venue_reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        fixture_id INT UNSIGNED NOT NULL,
        venue_name VARCHAR(150) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        comment TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_venue_reviews_holder_fixture (holder_id, fixture_id),
        KEY idx_venue_reviews_person (person_id, fixture_id),
        KEY idx_venue_reviews_venue (venue_name),
        CONSTRAINT fk_venue_reviews_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_venue_reviews_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE,
        CONSTRAINT fk_venue_reviews_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/**
 * Ground details for a fixture, where we have them — away grounds are
 * looked up via match_opponents.venue_id -> match_venues. Home fixtures
 * (and any away fixture without a linked venue) fall back to just the
 * free-text venue name already on the fixture.
 *
 * @return array<string, mixed>|null
 */
function getVenueInfoForFixture(PDO $pdo, array $fixture): ?array
{
    if (!empty($fixture['opponent_id'])) {
        $stmt = $pdo->prepare('SELECT v.* FROM match_opponents o
            JOIN match_venues v ON v.id = o.venue_id
            WHERE o.id = :opponent_id LIMIT 1');
        $stmt->execute([':opponent_id' => (int) $fixture['opponent_id']]);
        $venue = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($venue) {
            return $venue;
        }
    }

    $name = trim((string) ($fixture['venue'] ?? ''));
    if ($name === '') {
        return null;
    }

    return ['name' => $name, 'club_name' => null, 'address_line1' => null, 'town' => null, 'postcode' => null, 'notes' => null];
}

function venueReviewFixtureName(array $fixture): string
{
    $name = trim((string) ($fixture['venue'] ?? ''));
    return $name !== '' ? $name : ('Fixture #' . (int) $fixture['id']);
}

/**
 * @return array{average: float, count: int}
 */
function getVenueRatingSummary(PDO $pdo, string $venueName): array
{
    ensureVenueReviewsSchema($pdo);
    $stmt = $pdo->prepare('SELECT AVG(rating) AS average, COUNT(*) AS count FROM venue_reviews WHERE venue_name = :name');
    $stmt->execute([':name' => $venueName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['average' => null, 'count' => 0];
    return ['average' => round((float) ($row['average'] ?? 0), 1), 'count' => (int) ($row['count'] ?? 0)];
}

function getMyVenueReview(PDO $pdo, int $personId, int $fixtureId, ?int $legacyHolderId = null): ?array
{
    ensureVenueReviewsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM venue_reviews WHERE person_id = :person_id AND fixture_id = :fixture_id LIMIT 1');
    $stmt->execute([':person_id' => $personId, ':fixture_id' => $fixtureId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review && $legacyHolderId !== null && $legacyHolderId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM venue_reviews WHERE holder_id = :holder_id AND fixture_id = :fixture_id LIMIT 1');
        $stmt->execute([':holder_id' => $legacyHolderId, ':fixture_id' => $fixtureId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $review ?: null;
}

/**
 * One row per venue with its average rating and how many reviews it has —
 * the admin overview's summary cards.
 *
 * @return list<array{venue_name: string, review_count: int, average_rating: float}>
 */
function getVenueReviewSummaries(PDO $pdo): array
{
    ensureVenueReviewsSchema($pdo);
    $stmt = $pdo->query('SELECT venue_name, COUNT(*) AS review_count, AVG(rating) AS average_rating
        FROM venue_reviews GROUP BY venue_name ORDER BY review_count DESC, venue_name ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['review_count'] = (int) $row['review_count'];
        $row['average_rating'] = round((float) $row['average_rating'], 1);
    }
    unset($row);
    return $rows;
}

/**
 * Every individual review, most recent first, optionally narrowed to one venue.
 *
 * @return list<array<string, mixed>>
 */
function getAllVenueReviews(PDO $pdo, string $venueName = ''): array
{
    ensureVenueReviewsSchema($pdo);
    $sql = "SELECT r.*, COALESCE(p.display_name, h.name, 'Member') AS holder_name, f.opponent, f.match_date, f.is_home
        FROM venue_reviews r
        LEFT JOIN people p ON p.id = r.person_id
        LEFT JOIN season_ticket_holders h ON h.id = r.holder_id
        JOIN match_fixtures f ON f.id = r.fixture_id";
    $params = [];
    if ($venueName !== '') {
        $sql .= ' WHERE r.venue_name = :venue_name';
        $params[':venue_name'] = $venueName;
    }
    $sql .= ' ORDER BY r.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveVenueReview(PDO $pdo, int $personId, int $fixtureId, string $venueName, int $rating, string $comment, ?int $legacyHolderId = null): void
{
    ensureVenueReviewsSchema($pdo);
    if ($personId <= 0) {
        throw new InvalidArgumentException('Person is required.');
    }
    $rating = max(1, min(5, $rating));
    $existing = $pdo->prepare('SELECT id FROM venue_reviews WHERE person_id = :person_id AND fixture_id = :fixture_id LIMIT 1');
    $existing->execute([':person_id' => $personId, ':fixture_id' => $fixtureId]);
    $reviewId = $existing->fetchColumn();
    if ($reviewId !== false) {
        $pdo->prepare('UPDATE venue_reviews SET venue_name = :venue_name, rating = :rating, comment = :comment WHERE id = :id')
            ->execute([
                ':venue_name' => $venueName,
                ':rating' => $rating,
                ':comment' => trim($comment) !== '' ? trim($comment) : null,
                ':id' => (int) $reviewId,
            ]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO venue_reviews (person_id, fixture_id, venue_name, rating, comment)
        VALUES (:person_id, :fixture_id, :venue_name, :rating, :comment)
        ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), venue_name = VALUES(venue_name), rating = VALUES(rating), comment = VALUES(comment)');
    $stmt->execute([
        ':person_id' => $personId,
        ':fixture_id' => $fixtureId,
        ':venue_name' => $venueName,
        ':rating' => $rating,
        ':comment' => trim($comment) !== '' ? trim($comment) : null,
    ]);
}
