<?php
// lib/functions.php

/**
 * Thrown by *_save.php AJAX endpoints to report a validation failure tied to
 * a specific form field, so the JSON response can point the client at the
 * exact input to mark invalid instead of only a generic top-of-form message.
 */
class HubFieldValidationException extends Exception
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Return pricing rules for a season.
 *
 * Pricing is season-specific and stored on the season record.
 */
function getSponsorshipSeasonRules(PDO $pdo, ?int $seasonId = null): array
{
       $pricing = $seasonId !== null && $seasonId > 0
              ? getSeasonPlayerPricing($pdo, $seasonId)
              : ['player_home_amount' => 50.00, 'player_away_amount' => 30.00, 'player_third_amount' => 20.00];

       $allowedSlots = ['home', 'away'];
       if ((float)$pricing['player_third_amount'] > 0) {
              $allowedSlots[] = 'third';
       }

       return [
              'allowed_slots' => $allowedSlots,
              'slot_amounts' => [
                     1 => (float)$pricing['player_home_amount'],
                     2 => (float)$pricing['player_away_amount'],
                     3 => (float)$pricing['player_third_amount'],
              ],
       ];
}

function getAllowedSponsorshipSlots(PDO $pdo, ?int $seasonId = null): array
{
       $rules = getSponsorshipSeasonRules($pdo, $seasonId);
       return $rules['allowed_slots'];
}

function getSponsorshipSlotAmounts(PDO $pdo, ?int $seasonId = null): array
{
       $rules = getSponsorshipSeasonRules($pdo, $seasonId);
       return $rules['slot_amounts'];
}

/**
 * Recalculate sponsorship amounts for a given sponsor/player.
 */
function recalculateSponsorAmounts(PDO $pdo, int $playerId, int $sponsorId, ?int $seasonId = null): void
{
       $sql = "
        SELECT id, slot
        FROM sponsorships
        WHERE player_id = :pid
          AND sponsor_id = :sid
          AND complimentary = 0
    ";
       if ($seasonId !== null && $seasonId > 0) {
              $sql .= " AND season_id = :season_id";
       }
       $sql .= " ORDER BY FIELD(UPPER(slot),'HOME','AWAY','THIRD')";
       $stmt = $pdo->prepare($sql);
       $params = [':pid' => $playerId, ':sid' => $sponsorId];
       if ($seasonId !== null && $seasonId > 0) {
              $params[':season_id'] = $seasonId;
       }
       $stmt->execute($params);
       $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

       $count = count($rows);
       if ($count === 0) return;

       $amounts = getSponsorshipSlotAmounts($pdo, $seasonId);

       // Update each sponsorship row
       $upd = $pdo->prepare("UPDATE sponsorships SET amount=:amt WHERE id=:id");
       foreach ($rows as $i => $r) {
              $slotNumber = $i + 1;
              $amt = $amounts[$slotNumber] ?? 0;
              $upd->execute([':amt' => $amt, ':id' => $r['id']]);
       }
}

function ensurePlayerGraphicLayoutSchema(PDO $pdo): void
{
       static $done = false;
       if ($done) {
              return;
       }
       $done = true;

       $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'player_graphic_layouts'")->fetchColumn();
       if (!$tableExists) {
              return;
       }

       $columns = [];
       $stmt = $pdo->query("SHOW COLUMNS FROM player_graphic_layouts");
       foreach ($stmt as $row) {
              $columns[$row['Field']] = true;
       }

       $defs = [
              'home_z' => "INT NOT NULL DEFAULT 10 AFTER home_height",
              'away_z' => "INT NOT NULL DEFAULT 20 AFTER away_height",
              'same_z' => "INT NOT NULL DEFAULT 30 AFTER same_height",
              'third_z' => "INT NOT NULL DEFAULT 40 AFTER third_height",
              'home_locked' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER home_z",
              'away_locked' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER away_z",
              'same_locked' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER same_z",
              'third_locked' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER third_z",
       ];

       foreach ($defs as $column => $ddl) {
              if (!isset($columns[$column])) {
                     $pdo->exec("ALTER TABLE player_graphic_layouts ADD COLUMN {$column} {$ddl}");
              }
       }
}

/**
 * Escape HTML safely
 */
function h(?string $s): string
{
       return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Currency format (GBP)
 */
function gbp($n): string
{
       return '£' . number_format((float)$n, 2);
}

/**
 * CSRF token field (call inside forms)
 */
function csrf_field(): string
{
       if (session_status() !== PHP_SESSION_ACTIVE) session_start();
       if (empty($_SESSION['csrf_token'])) {
              $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
       }
       return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}

/**
 * CSRF validate — call on POST actions
 */
function csrf_check(): bool
{
       if (session_status() !== PHP_SESSION_ACTIVE) session_start();
       return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
              && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Verifies the CSRF token for POST actions and exits with 400 on failure.
 */
function verify_csrf(): void
{
       $ok = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
       if (!$ok) {
              http_response_code(400);
              echo "<div style='padding:1rem;font-family:system-ui'><strong>Bad Request:</strong> Invalid CSRF token.</div>";
              exit;
       }
}


/* ==========================================================
   Sponsor Status + Chips
   ========================================================== */

/**
 * Decide display class + label from sponsor presence, paid flag and notes.
 */
function sponsorStatus(?string $name, ?int $paid, ?string $notes): array
{
       if (!$name) return ['available', 'Available'];

       $n = strtolower((string)$notes);
       $hasTbc    = str_contains($n, 'tbc') || str_contains($n, 'pending') || str_contains($n, 'await') || str_contains($n, 'confirm');
       $hasFollow = str_contains($n, 'follow') || str_contains($n, 'issue') || str_contains($n, 'delay');

       if ((int)$paid === 1) return ['paid', 'Confirmed & Paid'];
       if ($hasFollow)       return ['followup', 'Follow-up Needed'];
       if ($hasTbc)          return ['pending', 'TBC / Pending Info'];
       return ['unpaid', 'Confirmed, Not Paid'];
}

/**
 * Render the coloured chip (or “Available” if no sponsor).
 */
function renderSponsorChip(?string $name, ?int $paid, ?string $notes): string
{
       [$cls, $label] = sponsorStatus($name, $paid, $notes);
       if ($cls === 'available') {
              return '<span class="status-chip status-available">Available</span>';
       }
       $safe = h($name ?? '');
       return '<span class="status-chip status-' . $cls . '" title="' . h($label) . '">' . $safe . '</span>';
}

/* ==========================================================
   Sponsorship Data Fetch
   ========================================================== */

/**
 * Return players with sponsor info per slot (name, paid, notes).
 */
function getPlayersWithSponsors(PDO $pdo, ?int $seasonId = null): array
{
       $seasonFilter = '';
       $params = [];
       if ($seasonId !== null && $seasonId > 0) {
              $seasonFilter = ' AND sp.season_id = :season_id';
              $params[':season_id'] = $seasonId;
       }
       $sql = "
    SELECT 
      p.id, p.name, p.active,

      sh.name  AS home_name,
      sph.paid AS home_paid,
      sph.notes AS home_notes,

      sa.name  AS away_name,
      spa.paid AS away_paid,
      spa.notes AS away_notes,

      st.name  AS third_name,
      spt.paid AS third_paid,
      spt.notes AS third_notes

    FROM players p

    LEFT JOIN sponsorships sph ON sph.player_id = p.id AND sph.slot = 'home' {$seasonFilter}
    LEFT JOIN sponsors     sh  ON sh.id = sph.sponsor_id

    LEFT JOIN sponsorships spa ON spa.player_id = p.id AND spa.slot = 'away' {$seasonFilter}
    LEFT JOIN sponsors     sa  ON sa.id = spa.sponsor_id

    LEFT JOIN sponsorships spt ON spt.player_id = p.id AND spt.slot = 'third' {$seasonFilter}
    LEFT JOIN sponsors     st  ON st.id = spt.sponsor_id

    ORDER BY p.name ASC
  ";
       $stmt = $pdo->prepare($sql);
       $stmt->execute($params);
       return $stmt->fetchAll();
}

/* ==========================================================
   Bundle Pricing + Payments
   ========================================================== */

/**
 * If a sponsor holds both home & away for the same player:
 * set Home=£50, Away=£30 (total £80).
 */
function applyBundlePricing(PDO $pdo, int $sponsor_id, int $player_id, ?int $seasonId = null): void
{
       $rules = getSponsorshipSeasonRules($pdo, $seasonId);
       $sql = "SELECT id, slot FROM sponsorships WHERE sponsor_id=:sid AND player_id=:pid AND slot IN ('home','away')";
       $params = [':sid' => $sponsor_id, ':pid' => $player_id];
       if ($seasonId !== null && $seasonId > 0) {
              $sql .= " AND season_id = :season_id";
              $params[':season_id'] = $seasonId;
       }
       $q = $pdo->prepare($sql);
       $q->execute($params);
       $homeId = null;
       $awayId = null;
       foreach ($q->fetchAll() as $r) {
              if ($r['slot'] === 'home') $homeId = (int)$r['id'];
              if ($r['slot'] === 'away') $awayId = (int)$r['id'];
       }
       if ($homeId && $awayId) {
              $pdo->prepare("UPDATE sponsorships SET amount=:amt WHERE id=:id")->execute([':amt' => (float)$rules['slot_amounts'][1], ':id' => $homeId]);
              $pdo->prepare("UPDATE sponsorships SET amount=:amt WHERE id=:id")->execute([':amt' => (float)$rules['slot_amounts'][2], ':id' => $awayId]);
       }
}

/** Sum of payments for a sponsorship */
function getPaidTotal(PDO $pdo, int $sponsorship_id): float
{
       $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sponsorship_payments WHERE sponsorship_id=:id");
       $stmt->execute([':id' => $sponsorship_id]);
       return (float)$stmt->fetchColumn();
}

/** Recalculate the boolean paid flag */
function recomputePaidFlag(PDO $pdo, int $sponsorship_id): void
{
       $stmt = $pdo->prepare("SELECT amount FROM sponsorships WHERE id=:id");
       $stmt->execute([':id' => $sponsorship_id]);
       $amount = (float)$stmt->fetchColumn();
       $paidTotal = getPaidTotal($pdo, $sponsorship_id);
       $flag = ($paidTotal + 0.0001) >= $amount ? 1 : 0;
       $pdo->prepare("UPDATE sponsorships SET paid=:p WHERE id=:id")->execute([':p' => $flag, ':id' => $sponsorship_id]);
}

/* ==========================================================
   Reporting
   ========================================================== */

/** Financial summary for dashboard */
function getFinancialSummary(PDO $pdo, ?int $seasonId = null): array
{
       $seasonFilter = '';
       $params = [];
       if ($seasonId !== null && $seasonId > 0) {
              $seasonFilter = ' WHERE sp.season_id = :season_id';
              $params[':season_id'] = $seasonId;
       }
       $sql = "
    SELECT 
      COUNT(DISTINCT s.id) AS sponsors_total,
      COALESCE(SUM(sp.amount),0) AS amount_total,
      COALESCE(SUM(CASE WHEN sp.paid=1 THEN sp.amount ELSE 0 END),0) AS amount_paid
    FROM sponsors s
    LEFT JOIN sponsorships sp ON sp.sponsor_id = s.id
    {$seasonFilter}
  ";
       $stmt = $pdo->prepare($sql);
       $stmt->execute($params);
       $row = $stmt->fetch();
       $due = (float)$row['amount_total'] - (float)$row['amount_paid'];
       $pct = (float)$row['amount_total'] > 0 ? round($row['amount_paid'] * 100.0 / $row['amount_total'], 1) : 0.0;
       return [
              'sponsors_total' => (int)$row['sponsors_total'],
              'amount_total' => (float)$row['amount_total'],
              'amount_paid' => (float)$row['amount_paid'],
              'amount_due' => $due,
              'pct_paid' => $pct
       ];
}

/* ==========================================================
   Audit Logging
   ========================================================== */
function audit_log(PDO $pdo, $userId, $action, $details = null)
{
       $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (:uid, :action, :details)");
       $stmt->execute([
              ':uid'    => $userId,
              ':action' => $action,
              ':details' => $details
       ]);
}


function shortDate(?string $ts): string
{
       if (!$ts) return '';
       return date('Y-m-d', strtotime($ts));
}

function dd($var): void
{
       if (defined('APP_DEBUG') && APP_DEBUG) {
              echo "<pre>" . h(print_r($var, true)) . "</pre>";
              exit;
       }
}

/**
 * Escape any value for HTML output. Kept alongside h() because a lot of the
 * older publishing/graphics pages call safe() rather than h(); both now live
 * here so those pages no longer need app_bootstrap.php just for this.
 *
 * @param mixed $value
 */
if (!function_exists('safe')) {
       function safe($value): string
       {
              return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
       }
}

/**
 * Format a Y-m-d date string as d/m/Y, passing through anything that is not a
 * plain ISO date and using $fallback for empty values.
 */
if (!function_exists('app_format_uk_date')) {
       function app_format_uk_date(?string $value, string $fallback = 'Not set'): string
       {
              $value = trim((string) $value);
              if ($value === '') {
                     return $fallback;
              }

              $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
              if ($date === false) {
                     return $value;
              }

              return $date->format('d/m/Y');
       }
}
