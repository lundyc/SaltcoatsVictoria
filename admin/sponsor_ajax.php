<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
// sponsor_ajax.php — handles slot & payment actions
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);

$seasonId = getSelectedSeasonId($pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!isset($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found']);
    exit;
}

$action = $_POST['action'] ?? '';

if (!$canRecordPayments && in_array($action, ['add_payment', 'delete_payment', 'mark_sponsor_paid', 'unmark_sponsor_paid'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to record payments.']);
    exit;
}

try {
    switch ($action) {

        /* ==============================
         *  ADD SLOT
         * ============================== */
        case 'add_slot':
            $sponsor_id = (int)($_POST['sponsor_id'] ?? 0);
            $player_id  = (int)($_POST['player_id'] ?? 0);
            $slot       = trim($_POST['slot'] ?? '');
            $amount     = (float)($_POST['amount'] ?? 0);
            $allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);

            if (!$sponsor_id || !$player_id || !$slot || $amount <= 0 || !in_array(strtolower($slot), $allowedSlots, true)) {
                throw new Exception("Missing required fields");
            }

            assertSponsorshipEditable($pdo, $seasonId);

            $stmt = $pdo->prepare("
                INSERT INTO sponsorships (season_id, sponsor_id, player_id, slot, amount, notes, assigned_at, started_at)
                VALUES (:season_id, :sponsor_id, :player_id, :slot, :amount, '', NOW(), NOW())
            ");
            $stmt->execute([
                ':season_id'  => $seasonId,
                ':sponsor_id' => $sponsor_id,
                ':player_id'  => $player_id,
                ':slot'       => $slot,
                ':amount'     => $amount
            ]);
            syncPlayerSponsorshipAgreement($pdo, (int) $pdo->lastInsertId());
            auditLog($pdo, 'player_sponsorship_assigned', "Added {$slot} slot for player #{$player_id}, sponsor #{$sponsor_id} (£" . number_format($amount, 2) . ')');

            echo json_encode(['success' => true]);
            break;

        /* ==============================
         *  DELETE SLOT
         * ============================== */
        case 'delete_slot':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception("Invalid slot ID");

            $slotSeasonStmt = $pdo->prepare("SELECT season_id FROM sponsorships WHERE id = :id LIMIT 1");
            $slotSeasonStmt->execute([':id' => $id]);
            $slotSeasonId = (int) $slotSeasonStmt->fetchColumn();
            assertSponsorshipEditable($pdo, $slotSeasonId ?: $seasonId);

            $pdo->prepare("UPDATE sponsorships SET ended_at = NOW(), ended_reason = 'deleted' WHERE id = :id")
                ->execute([':id' => $id]);
            syncPlayerSponsorshipAgreement($pdo, $id);
            auditLog($pdo, 'player_sponsorship_removed', "Ended sponsorship slot #{$id}");

            echo json_encode(['success' => true]);
            break;

        /* ==============================
         *  ADD PAYMENT
         * ============================== */
        case 'add_payment':
            $slot_id = (int)($_POST['slot_id'] ?? 0);
            $amount  = (float)($_POST['amount'] ?? 0);
            $paid_at = $_POST['paid_at'] ?? date('Y-m-d');

            if (!$slot_id || $amount <= 0) {
                throw new Exception("Missing required fields");
            }

            $stmt = $pdo->prepare("
                INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, season_id)
                VALUES (:slot_id, :amount, :paid_at, :season_id)
            ");
            $stmt->execute([
                ':slot_id' => $slot_id,
                ':amount'  => $amount,
                ':paid_at' => $paid_at,
                ':season_id' => $seasonId
            ]);
            recomputePaidFlag($pdo, $slot_id);
            auditLog($pdo, 'player_sponsorship_payment_added', "Added payment of £" . number_format($amount, 2) . " for sponsorship slot #{$slot_id}");

            echo json_encode(['success' => true]);
            break;

        /* ==============================
         *  DELETE PAYMENT
         * ============================== */
        case 'delete_payment':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception("Invalid payment ID");

            $lookup = $pdo->prepare("SELECT sponsorship_id FROM sponsorship_payments WHERE id = :id");
            $lookup->execute([':id' => $id]);
            $sponsorshipId = (int) $lookup->fetchColumn();

            $pdo->prepare("DELETE FROM sponsorship_payments WHERE id = :id")
                ->execute([':id' => $id]);
            if ($sponsorshipId > 0) {
                recomputePaidFlag($pdo, $sponsorshipId);
                syncPlayerSponsorshipAgreement($pdo, $sponsorshipId);
            }
            auditLog($pdo, 'player_sponsorship_payment_removed', "Deleted payment #{$id} from sponsorship slot #{$sponsorshipId}");

            echo json_encode(['success' => true]);
            break;

        /* ==============================
         *  MARK SPONSOR PAID
         * ============================== */
        case 'mark_sponsor_paid':
            $sponsor_id = (int)($_POST['sponsor_id'] ?? 0);
            if (!$sponsor_id) throw new Exception("Invalid sponsor ID");

            $stmt = $pdo->prepare("
                SELECT sp.id, sp.amount, COALESCE(SUM(pay.amount),0) AS paid_total
                FROM sponsorships sp
                LEFT JOIN sponsorship_payments pay ON sp.id = pay.sponsorship_id
                WHERE sp.sponsor_id = :sid
                  AND sp.season_id = :season_id
                  AND sp.ended_at IS NULL
                GROUP BY sp.id, sp.amount
            ");
            $stmt->execute([':sid' => $sponsor_id, ':season_id' => $seasonId]);
            $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            $totalMarkedPaid = 0.0;
            try {
                foreach ($slots as $slot) {
                    $out = (float)$slot['amount'] - (float)$slot['paid_total'];
                    if ($out > 0) {
                        $ins = $pdo->prepare("
                            INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
                            VALUES (:sid, :amount, NOW(), :method, :note, :season_id)
                        ");
                        $ins->execute([
                            ':sid' => $slot['id'],
                            ':amount' => $out,
                            ':method' => 'overview_auto',
                            ':note' => 'overview-auto-paid',
                            ':season_id' => $seasonId
                        ]);
                        $totalMarkedPaid += $out;
                    }
                    recomputePaidFlag($pdo, (int)$slot['id']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $stmt = $pdo->prepare("
                SELECT
                    sp.id,
                    sp.sponsor_id,
                    sp.player_id,
                    sp.slot,
                    s.name AS sponsor_name,
                    sp.amount,
                    COALESCE(SUM(pay.amount), 0) AS paid_total,
                    a.id AS agreement_id
                FROM sponsorships sp
                JOIN sponsors s ON s.id = sp.sponsor_id
                LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
                LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'player' AND a.legacy_id = sp.id
                WHERE sp.sponsor_id = :sid
                  AND sp.season_id = :season_id
                  AND sp.ended_at IS NULL
                GROUP BY sp.id, sp.sponsor_id, sp.player_id, sp.slot, s.name, sp.amount, a.id
                ORDER BY sp.id
            ");
            $stmt->execute([':sid' => $sponsor_id, ':season_id' => $seasonId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $markPaidSponsorName = $rows[0]['sponsor_name'] ?? ('#' . $sponsor_id);
            auditLog($pdo, 'player_sponsorship_marked_paid', "Marked sponsor {$markPaidSponsorName} fully paid (£" . number_format($totalMarkedPaid, 2) . ' added across ' . count($slots) . ' slot(s))');

            echo json_encode(['success' => true, 'message' => 'Sponsor marked as fully paid', 'sponsorships' => $rows]);
            break;

        /* ==============================
         *  UNMARK SPONSOR PAID
         * ============================== */
        case 'unmark_sponsor_paid':
            $sponsor_id = (int)($_POST['sponsor_id'] ?? 0);
            if (!$sponsor_id) throw new Exception("Invalid sponsor ID");

            $stmt = $pdo->prepare("
                SELECT sp.id
                FROM sponsorships sp
                WHERE sp.sponsor_id = :sid
                  AND sp.season_id = :season_id
                  AND sp.ended_at IS NULL
            ");
            $stmt->execute([':sid' => $sponsor_id, ':season_id' => $seasonId]);
            $sponsorshipIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();
            try {
                if ($sponsorshipIds) {
                    $placeholders = implode(',', array_fill(0, count($sponsorshipIds), '?'));

                    $deleteSql = "
                        DELETE FROM sponsorship_payments
                        WHERE sponsorship_id IN ($placeholders)
                          AND (
                            method = 'overview_auto'
                            OR note = 'overview-auto-paid'
                            OR (
                              (method IS NULL OR method = '')
                              AND (note IS NULL OR note = '')
                              AND paid_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                            )
                          )
                    ";
                    $pdo->prepare($deleteSql)->execute($sponsorshipIds);

                    foreach ($sponsorshipIds as $sid) {
                        recomputePaidFlag($pdo, (int)$sid);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $stmt = $pdo->prepare("
                SELECT
                    sp.id,
                    sp.sponsor_id,
                    sp.player_id,
                    sp.slot,
                    s.name AS sponsor_name,
                    sp.amount,
                    COALESCE(SUM(pay.amount), 0) AS paid_total,
                    a.id AS agreement_id
                FROM sponsorships sp
                JOIN sponsors s ON s.id = sp.sponsor_id
                LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
                LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'player' AND a.legacy_id = sp.id
                WHERE sp.sponsor_id = :sid
                  AND sp.season_id = :season_id
                  AND sp.ended_at IS NULL
                GROUP BY sp.id, sp.sponsor_id, sp.player_id, sp.slot, s.name, sp.amount, a.id
                ORDER BY sp.id
            ");
            $stmt->execute([':sid' => $sponsor_id, ':season_id' => $seasonId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $unmarkSponsorName = $rows[0]['sponsor_name'] ?? ('#' . $sponsor_id);
            auditLog($pdo, 'player_sponsorship_payment_undone', "Undid auto-paid status for sponsor {$unmarkSponsorName} across " . count($sponsorshipIds) . ' slot(s)');

            echo json_encode(['success' => true, 'message' => 'Sponsor payment undone', 'sponsorships' => $rows]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
