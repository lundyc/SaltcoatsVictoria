<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

header('Content-Type: application/json');

try {
          if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new RuntimeException('Invalid request method.');
          }

          if (!csrf_check()) {
                    throw new RuntimeException('Invalid CSRF token.');
          }

          $seasonId = (int)($_POST['season_id'] ?? 0);
          if ($seasonId <= 0) {
                    throw new RuntimeException('Missing season.');
          }

          saveMatchPosterLogoLayout($seasonId, [
                    'bar_one_x' => $_POST['bar_one_x'] ?? null,
                    'bar_one_y' => $_POST['bar_one_y'] ?? null,
                    'bar_one_w' => $_POST['bar_one_w'] ?? null,
                    'bar_one_h' => $_POST['bar_one_h'] ?? null,
                    'del_grecos_x' => $_POST['del_grecos_x'] ?? null,
                    'del_grecos_y' => $_POST['del_grecos_y'] ?? null,
                    'del_grecos_w' => $_POST['del_grecos_w'] ?? null,
                    'del_grecos_h' => $_POST['del_grecos_h'] ?? null,
          ]);

          echo json_encode(['ok' => true]);
} catch (Throwable $e) {
          http_response_code(400);
          echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
