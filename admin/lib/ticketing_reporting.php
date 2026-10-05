<?php
declare(strict_types=1);

require_once __DIR__ . '/admissions.php';

function getFixtureAttendanceSummary(PDO $pdo, int $fixtureId): array
{
    ensureAdmissionsSchema($pdo);
    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(quantity), 0) AS total,
            COALESCE(SUM(CASE WHEN source = 'match_ticket_qr' THEN quantity ELSE 0 END), 0) AS online_match_tickets,
            COALESCE(SUM(CASE WHEN source = 'matchday_qr' THEN quantity ELSE 0 END), 0) AS season_passes,
            COALESCE(SUM(CASE WHEN source = 'pos' THEN quantity ELSE 0 END), 0) AS pos_gate,
            COALESCE(SUM(CASE WHEN source = 'complimentary' THEN quantity ELSE 0 END), 0) AS complimentary,
            COALESCE(SUM(CASE WHEN source NOT IN ('match_ticket_qr','matchday_qr','pos','complimentary') THEN quantity ELSE 0 END), 0) AS other
        FROM admissions
        WHERE fixture_id = :fixture");
    $stmt->execute([':fixture' => $fixtureId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => (int) ($row['total'] ?? 0),
        'online_match_tickets' => (int) ($row['online_match_tickets'] ?? 0),
        'season_passes' => (int) ($row['season_passes'] ?? 0),
        'pos_gate' => (int) ($row['pos_gate'] ?? 0),
        'complimentary' => (int) ($row['complimentary'] ?? 0),
        'other' => (int) ($row['other'] ?? 0),
    ];
}

function getFixtureRevenueSummary(PDO $pdo, int $fixtureId): array
{
    ensureAdmissionsSchema($pdo);
    $onlineStmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN pay.status IN ('paid','refunded') THEN oi.line_total ELSE 0 END), 0) AS gross,
            COALESCE(SUM(CASE WHEN pay.status = 'paid' THEN oi.line_total ELSE 0 END), 0) AS paid_gross,
            COALESCE(SUM(r.amount), 0) AS refunds
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN payments pay ON pay.order_id = o.id
        LEFT JOIN refunds r ON r.payment_id = pay.id AND r.status IN ('complete','succeeded','paid')
        WHERE oi.product_type = 'match_ticket'
          AND JSON_UNQUOTE(JSON_EXTRACT(oi.metadata_json, '$.fixture_id')) = :fixture");
    $onlineStmt->execute([':fixture' => $fixtureId]);
    $online = $onlineStmt->fetch(PDO::FETCH_ASSOC) ?: ['gross' => 0, 'paid_gross' => 0, 'refunds' => 0];

    $posStmt = $pdo->prepare("SELECT COALESCE(SUM(psi.line_total), 0)
        FROM pos_admission_links pal
        JOIN pos_sale_items psi ON psi.id = pal.pos_sale_item_id
        JOIN pos_sales ps ON ps.id = pal.pos_sale_id
        WHERE pal.fixture_id = :fixture AND ps.status = 'complete'");
    $posStmt->execute([':fixture' => $fixtureId]);
    $posGross = (float) $posStmt->fetchColumn();

    $refunds = (float) ($online['refunds'] ?? 0);
    $onlineGross = (float) ($online['gross'] ?? 0);
    return [
        'online_gross' => $onlineGross,
        'gate_gross' => $posGross,
        'refunds' => $refunds,
        'net_ticket_revenue' => max(0.0, $onlineGross - $refunds) + $posGross,
    ];
}

function getFixtureTicketingDashboard(PDO $pdo, int $fixtureId): array
{
    ensureAdmissionsSchema($pdo);
    $attendance = getFixtureAttendanceSummary($pdo, $fixtureId);
    $finance = getFixtureRevenueSummary($pdo, $fixtureId);

    $onlineStmt = $pdo->prepare("SELECT oi.description_snapshot AS label, COALESCE(SUM(oi.quantity),0) AS qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN payments pay ON pay.order_id = o.id
        WHERE oi.product_type = 'match_ticket'
          AND pay.status = 'paid'
          AND JSON_UNQUOTE(JSON_EXTRACT(oi.metadata_json, '$.fixture_id')) = :fixture
        GROUP BY oi.description_snapshot
        ORDER BY oi.description_snapshot");
    $onlineStmt->execute([':fixture' => $fixtureId]);

    $posStmt = $pdo->prepare("SELECT pal.admission_type AS label, COALESCE(SUM(pal.quantity),0) AS qty
        FROM pos_admission_links pal
        JOIN pos_sales ps ON ps.id = pal.pos_sale_id
        WHERE pal.fixture_id = :fixture AND ps.status = 'complete'
        GROUP BY pal.admission_type
        ORDER BY pal.admission_type");
    $posStmt->execute([':fixture' => $fixtureId]);

    $compStmt = $pdo->prepare("SELECT COALESCE(category, 'Complimentary') AS label, COALESCE(SUM(quantity),0) AS qty
        FROM admissions
        WHERE fixture_id = :fixture AND source = 'complimentary'
        GROUP BY COALESCE(category, 'Complimentary')
        ORDER BY label");
    $compStmt->execute([':fixture' => $fixtureId]);

    return [
        'attendance' => $attendance,
        'finance' => $finance,
        'online_match_tickets' => $onlineStmt->fetchAll(PDO::FETCH_ASSOC),
        'pos_gate' => $posStmt->fetchAll(PDO::FETCH_ASSOC),
        'complimentary' => $compStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}
