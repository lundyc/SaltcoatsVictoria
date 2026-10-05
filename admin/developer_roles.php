<?php
// developer_roles.php — User Switcher (Impersonation)
require_once __DIR__ . '/header.php';

// Identifies "the developer" by email (HUB_DEVELOPER_EMAIL) rather than a
// hardcoded users.id === 1 — that assumption broke once staff accounts
// moved into season_ticket_holders under a different id space. Empty
// DEVELOPER_EMAIL or no matching staff/admin account disables the tool
// entirely (falls through to the same access-denied path).
$developerAccount = null;
if (DEVELOPER_EMAIL !== '') {
    $stmt = $pdo->prepare("SELECT id FROM season_ticket_holders WHERE email_normalized = :email AND role IN ('staff','admin') LIMIT 1");
    $stmt->execute([':email' => seasonTicketNormalizeEmail(DEVELOPER_EMAIL)]);
    $developerAccount = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$developerAccountId = $developerAccount ? (int) $developerAccount['id'] : 0;

// Restrict: only the developer OR someone already impersonating (so they
// can switch back) can access.
if ((!$developerAccountId || (int) ($_SESSION['user_id'] ?? 0) !== $developerAccountId) && empty($_SESSION['impersonating'])) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

// Handle switching
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_user'])) {
          $targetId = (int)$_POST['switch_user'];

          // Load target account (staff/admin only — impersonation stays
          // scoped to staff, not arbitrary members, same as before).
          $stmt = $pdo->prepare("SELECT id, name, username, role FROM season_ticket_holders WHERE id = :id AND role IN ('staff','admin') LIMIT 1");
          $stmt->execute([':id' => $targetId]);
          $target = $stmt->fetch(PDO::FETCH_ASSOC);

          if ($target) {
                    $_SESSION[HUB_AUTH_USER_ID_KEY] = (string) $target['id'];
                    $_SESSION['user_id']  = $target['id'];
                    $_SESSION['username'] = $target['name'] ?: $target['username'];
                    $_SESSION['role']     = $target['role'];
                    $_SESSION['impersonating'] = ($targetId !== $developerAccountId);
          }

          header("Location: index.php");
          exit;
}

// Fetch all staff/admin accounts for the dropdown
$stmt = $pdo->query("SELECT id, name, username, role FROM season_ticket_holders WHERE role IN ('staff','admin') ORDER BY id ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div>
          <h1 class="h3 mb-3"><i class="fa-solid fa-user-secret"></i> User Switcher</h1>

          <div class="card border-0 shadow-sm">
                    <div class="card-body">
                              <form method="post" class="row g-2 align-items-center">
                                        <div class="col-md-12">
                                                  <label class="form-label">Impersonate as:</label>
                                                  <select name="switch_user" class="form-select" required>
                                                            <?php foreach ($users as $u): ?>
                                                                      <option value="<?= $u['id'] ?>">
                                                                                <?= htmlspecialchars((string) ($u['name'] ?: $u['username'])) ?> (<?= htmlspecialchars($u['role']) ?>)
                                                                      </option>
                                                            <?php endforeach; ?>
                                                  </select>
                                        </div>
                                        <div class="col-md-12">
                                                  <button type="submit" class="btn btn-brand w-100">Switch</button>
                                        </div>
                              </form>

                              <?php if (!empty($_SESSION['impersonating'])): ?>
                                        <div class="alert alert-warning mt-3">
                                                  ⚠ You are impersonating another user (<?= htmlspecialchars($_SESSION['username']) ?>).
                                                  <br>
                                                  Use the dropdown to <strong>Return to Developer</strong>.
                                        </div>
                              <?php endif; ?>
                    </div>
          </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
