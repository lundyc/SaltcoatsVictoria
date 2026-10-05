<?php
// developer_errors.php — Error Log Viewer
require_once __DIR__ . '/header.php';

// Restrict access
if (!hub_auth_is_developer()) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

// Try configured path first, then auto-detect.
$logPath = null;
$manualLogPath = defined('ERROR_LOG_PATH') ? trim((string)ERROR_LOG_PATH) : '';

if ($manualLogPath !== '' && file_exists($manualLogPath)) {
          $logPath = $manualLogPath;
}

if (!$logPath) {
          if (in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'])) {
                    $candidates = [
                              'C:/xampp/php/logs/php_error_log',
                              'C:/xampp/apache/logs/error.log'
                    ];
          } else {
                    $candidates = [
                              '/var/log/php8.3-fpm.log',
                              '/var/log/php8.2-fpm.log',
                              '/var/log/php_errors.log',
                              '/var/log/apache2/error.log',
                              '/var/log/httpd/error_log'
                    ];
          }

          foreach ($candidates as $file) {
                    if (file_exists($file)) {
                              $logPath = $file;
                              break;
                    }
          }
}

// Handle clear log request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log']) && $logPath) {
          if (is_writable($logPath)) {
                    file_put_contents($logPath, ''); // truncate file
                    $cleared = true;
          } else {
                    $clearError = 'The log file is not writable by the web server, so it cannot be cleared from this page.';
          }
}

$canClear = $logPath && is_writable($logPath);

// Load last 200 lines
$lines = [];
if ($logPath) {
          $raw = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
          if ($raw !== false) {
                    $lines = array_slice($raw, -200);
          }
}
?>

<div>
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
                    <h1 class="mb-0"><i class="fa-solid fa-bug"></i> Error Log Viewer</h1>
                    <?php if ($canClear): ?>
                              <!-- Clear log button -->
                              <form method="post" data-confirm="Are you sure you want to clear the log? This cannot be undone." data-confirm-action="Clear log">
                                        <button type="submit" name="clear_log" value="1" class="btn btn-danger">
                                                  <i class="fa-solid fa-trash-alt"></i> Clear Log
                                        </button>
                              </form>

                    <?php endif; ?>
          </div>

          <?php if (!empty($cleared)): ?>
                    <div class="alert alert-success">✅ Log file has been cleared.</div>
          <?php endif; ?>

          <?php if (!empty($clearError)): ?>
                    <div class="alert alert-warning"><?= h($clearError) ?></div>
          <?php endif; ?>

          <?php if ($logPath): ?>
                    <p>
                              <strong>Log File:</strong> <code><?= htmlspecialchars($logPath) ?></code>
                    </p>



                    <div class="table-responsive w-100">
                              <table class="table table-sm table-bordered table-striped align-middle w-100 mb-0 hub-data-table">
                                        <thead class="table-dark">
                                                  <tr>
                                                            <th style="width: 200px;">Timestamp</th>
                                                            <th style="width: 120px;">Severity</th>
                                                            <th class="log-message">Message</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php
                                                  $pattern = '/^\[(.*?)\]\s*(.*)$/'; // [timestamp] message
                                                  foreach ($lines as $line):
                                                            $timestamp = '';
                                                            $message   = $line;
                                                            $severity  = 'Info';
                                                            $rowClass  = '';

                                                            if (preg_match($pattern, $line, $m)) {
                                                                      $timestamp = $m[1];
                                                                      $message   = $m[2];
                                                            }

                                                            if (stripos($line, 'Deprecated') !== false) {
                                                                      $severity = 'Deprecated';
                                                                      $rowClass = 'table-warning';
                                                            } elseif (stripos($line, 'Warning') !== false) {
                                                                      $severity = 'Warning';
                                                                      $rowClass = 'table-warning';
                                                            } elseif (stripos($line, 'Fatal') !== false) {
                                                                      $severity = 'Fatal';
                                                                      $rowClass = 'table-danger';
                                                            } elseif (stripos($line, 'Error') !== false) {
                                                                      $severity = 'Error';
                                                                      $rowClass = 'table-danger';
                                                            }

                                                            // Split into main + details
                                                            $mainMsg = $message;
                                                            $details = '';

                                                            if (preg_match('/^(.*?)( in .*| on line .*|$)/i', $message, $mm)) {
                                                                      $mainMsg = trim($mm[1]);
                                                                      $details = trim(substr($message, strlen($mm[1])));
                                                            }

                                                            // Stack trace handling
                                                            if (stripos($message, 'Stack trace:') !== false) {
                                                                      $parts   = explode('Stack trace:', $message, 2);
                                                                      $mainMsg = trim($parts[0]);
                                                                      $details = "<pre class='mb-0'>" . htmlspecialchars("Stack trace:" . $parts[1]) . "</pre>";
                                                            }

                                                            // Extract metadata before the actual PHP error keyword
                                                            $meta = '';
                                                            $cleanMsg = $mainMsg;
                                                            if (preg_match('/^(.*?)\s+(PHP|Uncaught|Fatal|Deprecated|Warning|Error)/i', $message, $mm)) {
                                                                      $meta     = trim($mm[1]);
                                                                      $cleanMsg = substr($message, strlen($meta));
                                                            }
                                                  ?>
                                                            <tr class="<?= $rowClass ?>">
                                                                      <td><code><?= htmlspecialchars($timestamp) ?></code></td>
                                                                      <td>
                                                                                <span class="badge bg-<?= $rowClass ? ($rowClass === 'table-warning' ? 'warning text-dark' : 'danger') : 'secondary' ?>">
                                                                                          <?= htmlspecialchars($severity) ?>
                                                                                </span>
                                                                      </td>
                                                                      <td class="log-message">
                                                                                <?php if ($meta): ?>
                                                                                          <div class="text-muted small"><?= htmlspecialchars($meta) ?></div>
                                                                                <?php endif; ?>

                                                                                <div><strong><?= htmlspecialchars($cleanMsg) ?></strong></div>

                                                                                <?php if ($details): ?>
                                                                                          <div class="text-muted small"><?= $details ?></div>
                                                                                <?php endif; ?>
                                                                      </td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                        </tbody>
                              </table>
                    </div>
          <?php else: ?>
                    <div class="alert alert-warning">
                              ⚠ Could not detect error log file automatically.<br>
                              Set <code>ERROR_LOG_PATH</code> in <code>config.php</code> to the absolute log path, for example:<br>
                              <pre>define('ERROR_LOG_PATH', '/var/log/php8.3-fpm.log');</pre>
                    </div>
          <?php endif; ?>
</div>

<link rel="stylesheet" href="/admin/assets/css/player-sponsors-developer-errors.css">

<?php require_once __DIR__ . '/footer.php'; ?>
