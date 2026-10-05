<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Invalid request method.',
                    ]);
                    exit;
          }
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Invalid CSRF token.',
                    ]);
                    exit;
          }
          exit('Invalid CSRF token.');
}

$clubName = trim((string)($_POST['club_name'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$addressLine1 = trim((string)($_POST['address_line1'] ?? ''));
$town = trim((string)($_POST['town'] ?? ''));
$postcode = strtoupper(trim((string)($_POST['postcode'] ?? '')));
$notes = trim((string)($_POST['notes'] ?? ''));
$fixtureId = (int)($_POST['return_to_fixture_id'] ?? 0);
$seasonId = (int)($_POST['return_to_season_id'] ?? 0);
$action = trim((string)($_POST['return_action'] ?? 'view'));

if ($name === '') {
          http_response_code(400);
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Venue name is required.',
                    ]);
                    exit;
          }
          exit('Venue name is required.');
}

$existing = $clubName !== ''
          ? getMatchVenueByClubName($pdo, $clubName)
          : (($addressLine1 === '' && $town === '' && $postcode === '')
                    ? getMatchVenueByName($pdo, $name)
                    : getMatchVenueByExactDetails(
                              $pdo,
                              $name,
                              $addressLine1 !== '' ? $addressLine1 : null,
                              $town !== '' ? $town : null,
                              $postcode !== '' ? $postcode : null
                    ));
if ($existing) {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => true,
                              'venue' => [
                                        'id' => (int) $existing['id'],
                                        'club_name' => (string) ($existing['club_name'] ?? ''),
                                        'name' => (string) $existing['name'],
                                        'address_line1' => (string) ($existing['address_line1'] ?? ''),
                                        'town' => (string) ($existing['town'] ?? ''),
                                        'postcode' => (string) ($existing['postcode'] ?? ''),
                                        'notes' => (string) ($existing['notes'] ?? ''),
                                        'label' => matchVenueDisplayLabel($existing),
                              ],
                    ]);
                    exit;
          }
          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'venue_added' => (int)$existing['id'],
          ]);
          header('Location: ' . $redirect);
          exit;
}

try {
          $savedId = saveMatchVenue(
                    $pdo,
                    null,
                    $name,
                    $clubName !== '' ? $clubName : null,
                    $addressLine1 !== '' ? $addressLine1 : null,
                    $town !== '' ? $town : null,
                    $postcode !== '' ? $postcode : null,
                    $notes !== '' ? $notes : null
          );
          auditLog($pdo, 'venue_created', "Created venue '{$name}'");
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => true,
                              'venue' => [
                                        'id' => $savedId,
                                        'club_name' => $clubName,
                                        'name' => $name,
                                        'address_line1' => $addressLine1,
                                        'town' => $town,
                                        'postcode' => $postcode,
                                        'notes' => $notes,
                                        'label' => matchVenueDisplayLabel([
                                                  'name' => $name,
                                                  'address_line1' => $addressLine1,
                                                  'town' => $town,
                                                  'postcode' => $postcode,
                                        ]),
                              ],
                    ]);
                    exit;
          }
          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'venue_added' => $savedId,
          ]);
          header('Location: ' . $redirect);
          exit;
} catch (Throwable $e) {
          http_response_code(400);
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => false,
                              'error' => $e->getMessage(),
                    ]);
                    exit;
          }
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
