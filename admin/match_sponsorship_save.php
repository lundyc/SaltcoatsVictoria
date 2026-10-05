<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$sponsorId = (int)($_POST['sponsor_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$markPaid = $canRecordPayments && isset($_POST['mark_paid']) && (string)$_POST['mark_paid'] === '1';
$isComplimentary = isset($_POST['is_complimentary']) && (string)$_POST['is_complimentary'] === '1';
$postedRoles = $_POST['sponsorship_roles'] ?? [];
if (!is_array($postedRoles)) {
          $postedRoles = [$postedRoles];
}

// Retain compatibility with older forms while the new modal submits an array.
$legacyRole = strtolower(trim((string)($_POST['sponsorship_role'] ?? '')));
if ($postedRoles === [] && $legacyRole !== '') {
          $postedRoles = $legacyRole === 'both' ? ['match_day', 'match_ball'] : [$legacyRole];
}

$roles = [];
foreach ($postedRoles as $postedRole) {
          $normalizedRole = strtolower(trim((string)$postedRole));
          if ($normalizedRole !== '' && !in_array($normalizedRole, $roles, true)) {
                    $roles[] = $normalizedRole;
          }
}

if ($isComplimentary && $markPaid) {
          exit('A complimentary sponsorship cannot also be marked as paid.');
}

if ($fixtureId <= 0 || $sponsorId <= 0) {
          exit('Missing fixture or sponsor.');
}

if ($roles === []) {
          exit('Select at least one sponsorship package.');
}

foreach ($roles as $role) {
          if (!getMatchSponsorshipTypeByCode($pdo, $role)) {
                    exit('Invalid sponsorship package.');
          }
}

$postedAmounts = isset($_POST['amounts']) && is_array($_POST['amounts']) ? $_POST['amounts'] : [];
$amounts = [];
foreach ($roles as $role) {
          $rawAmount = $postedAmounts[$role] ?? null;
          if ($rawAmount === null) {
                    $rawAmount = match ($role) {
                              'match_day' => $_POST['amount_match_day'] ?? $_POST['amount'] ?? null,
                              'match_ball' => $_POST['amount_match_ball'] ?? $_POST['amount'] ?? null,
                              default => $_POST['amount'] ?? null,
                    };
          }
          if ($rawAmount !== null && $rawAmount !== '' && (!is_numeric($rawAmount) || (float)$rawAmount < 0)) {
                    exit('Invalid sponsorship amount.');
          }
          $amounts[$role] = $rawAmount !== null && $rawAmount !== '' ? (float)$rawAmount : null;
}

try {
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $pdo->beginTransaction();

          $rows = [];

          foreach ($roles as $singleRole) {
                    $row = assignMatchSponsorship($pdo, $fixtureId, $sponsorId, $singleRole, $amounts[$singleRole], $notes !== '' ? $notes : null, $isComplimentary);
                    $rows[$singleRole] = $row;
          }

          if ($markPaid) {
                    foreach ($rows as $row) {
                              if (!empty($row['id'])) {
                                        markMatchSponsorshipPaid($pdo, (int)$row['id']);
                              }
                    }
          }

          $fixture = getMatchFixtureById($pdo, $fixtureId);
          $seasonId = (int)($fixture['season_id'] ?? 0);
          $pdo->commit();
          $firstRow = reset($rows);
          $sponsorNameForLog = $firstRow['sponsor_name'] ?? ('#' . $sponsorId);
          $opponentForLog = $firstRow['opponent'] ?? '';
          auditLog($pdo, 'match_sponsorship_created', "Assigned " . implode('/', $roles) . " sponsorship to '{$sponsorNameForLog}' (vs {$opponentForLog})" . ($markPaid ? ', marked paid' : ''));
          header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&saved=1');
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
