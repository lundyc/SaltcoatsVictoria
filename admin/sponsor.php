<?php
// sponsor.php — Sponsor profile & editor
$pageHero = [];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/social_directory_sync.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/facebook_page_resolver.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/sponsor_workspace.php';

ensureSponsorshipCatalogSchema($pdo);

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);

function ensureSponsorProfileColumns(PDO $pdo, ?string &$error = null): bool
{
  static $checked = false;
  static $result = true;
  static $message = null;

  if ($checked) {
    $error = $message;
    return $result;
  }

  $checked = true;

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'logo_path'");
    $exists = (bool)$stmt->fetch();
    if (!$exists) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN logo_path VARCHAR(255) NULL AFTER name");
    }
    $mainSponsorStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'is_main_sponsor'");
    if (!(bool)$mainSponsorStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN is_main_sponsor TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_path");
    }
    $whiteLogoStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'white_logo_path'");
    if (!(bool)$whiteLogoStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN white_logo_path VARCHAR(255) NULL AFTER logo_path");
    }
    $sortOrderStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'sort_order'");
    if (!(bool)$sortOrderStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_main_sponsor");
    }
    $socialColumns = [
      'facebook_page_url' => "VARCHAR(500) NULL AFTER white_logo_path",
      'facebook_page_id' => "VARCHAR(100) NULL AFTER facebook_page_url",
      'facebook_page_name' => "VARCHAR(255) NULL AFTER facebook_page_id",
      'instagram_url' => "VARCHAR(500) NULL AFTER facebook_page_name",
      'twitter_url' => "VARCHAR(500) NULL AFTER instagram_url",
    ];
    foreach ($socialColumns as $column => $definition) {
      $columnStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE " . $pdo->quote($column));
      if (!(bool)$columnStmt->fetch()) {
        $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
      }
    }
    $businessColumns = [
      'is_business' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER twitter_url",
      'address' => "VARCHAR(255) NULL AFTER is_business",
      'website_url' => "VARCHAR(255) NULL AFTER address",
      'contact_phone' => "VARCHAR(50) NULL AFTER website_url",
      'contact_email' => "VARCHAR(190) NULL AFTER contact_phone",
    ];
    foreach ($businessColumns as $column => $definition) {
      $columnStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE " . $pdo->quote($column));
      if (!(bool)$columnStmt->fetch()) {
        $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
      }
    }
    $result = true;
    $message = null;
  } catch (PDOException $e) {
    $result = false;
    $message = 'Unable to prepare sponsor profile fields: ' . $e->getMessage();
  }

  $error = $message;
  return $result;
}

$schemaError = null;
$schemaReady = ensureSponsorProfileColumns($pdo, $schemaError);

function sponsorProfileDate(?string $date, string $fallback): string
{
  $date = trim((string)$date);
  if ($date === '') {
    return $fallback;
  }
  $timestamp = strtotime($date);
  return $timestamp ? date('d/m/Y', $timestamp) : $date;
}

function sponsorProfileDateTime(?string $date, string $fallback = 'Not recorded'): string
{
  $date = trim((string)$date);
  if ($date === '') {
    return $fallback;
  }
  $timestamp = strtotime($date);
  return $timestamp ? date('d/m/Y H:i', $timestamp) : $date;
}

function sponsorProfileStatusBadge(string $status): string
{
  $classes = [
    'active' => 'text-bg-success',
    'scheduled' => 'text-bg-info',
    'expired' => 'text-bg-secondary',
    'cancelled' => 'text-bg-dark',
  ];
  $class = $classes[$status] ?? 'text-bg-light';
  return '<span class="badge hub-status ' . h($class) . '">' . h(ucfirst($status)) . '</span>';
}

function sponsorProfileSlotLabel(?string $slot): string
{
  $slot = strtolower(trim((string)$slot));
  return $slot !== '' ? ucfirst($slot) . ' kit' : 'Player sponsorship';
}

$action = $_GET['action'] ?? 'view';
if (!in_array($action, ['new', 'edit'], true)) {
  $action = 'view';
}

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$formData = [
  'name' => '',
  'is_active' => 1,
  'is_main_sponsor' => 0,
  'sort_order' => 0,
  'facebook_page_url' => '',
  'facebook_page_id' => '',
  'facebook_page_name' => '',
  'instagram_url' => '',
  'twitter_url' => '',
];
$currentLogo = null;
$currentWhiteLogo = null;
$removeLogoChecked = false;
$removeWhiteLogoChecked = false;
$pendingUpload = null;
$pendingWhiteUpload = null;
$deleteAfterCommit = null;
$deleteWhiteAfterCommit = null;
$seasonAssignment = [
  'is_active' => 1,
  'joined_at' => null,
  'left_at' => null,
];

if ($action === 'view') {
  if ($id <= 0) {
    echo '<div><div class="alert alert-danger">Invalid sponsor ID.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM sponsors WHERE id = :id");
  $stmt->execute([':id' => $id]);
  $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$sponsor) {
    echo '<div><div class="alert alert-danger">Sponsor not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $workspaceAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
  $workspaceError = '';
  $workspaceMessage = (string)($_SESSION['sponsor_workspace_message'][$id] ?? '');
  unset($_SESSION['sponsor_workspace_message'][$id]);
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['workspace_action'])) {
    try {
      if (!csrf_check()) throw new RuntimeException('Your session expired. Please try again.');
      $workspaceAction = (string)$_POST['workspace_action'];
      $paymentAction = in_array($workspaceAction, ['add_payment', 'mark_paid', 'edit_payment', 'delete_payment'], true);
      if (!($paymentAction ? hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']) : hub_auth_has_capability('sponsorship'))) throw new RuntimeException('You do not have permission to make this change.');
      if (in_array($workspaceAction, ['archive_sponsor', 'restore_sponsor'], true)) {
        $isActive = $workspaceAction === 'restore_sponsor' ? 1 : 0;
        $pdo->prepare('UPDATE sponsors SET is_active = :active WHERE id = :id')->execute([':active' => $isActive, ':id' => $id]);
        $sponsor['is_active'] = $isActive;
        auditLog($pdo, $isActive ? 'sponsor_restored' : 'sponsor_archived', 'Sponsor #' . $id . ' (' . (string)$sponsor['name'] . ')');
        $workspaceMessage = $isActive ? 'Sponsor restored to the active list.' : 'Sponsor archived. All agreements, payments and notes have been kept.';
      } elseif ($paymentAction) {
        sponsorWorkspacePayment($pdo, $id, $_POST);
        $workspaceMessage = $workspaceAction === 'delete_payment' ? 'Payment removed. The balance has been updated.' : 'Payment saved. The balance has been updated.';
      } elseif ($workspaceAction === 'save_agreement') {
        sponsorWorkspaceSave($pdo, $id, $_POST);
        $workspaceMessage = 'Agreement saved.';
      } elseif ($workspaceAction === 'delete_agreement') {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        sponsorWorkspaceAgreement($pdo, $id, $agreementId);
        deleteSponsorshipAgreement($pdo, $agreementId);
        auditLog($pdo, 'sponsorship_agreement_deleted', "Deleted agreement #{$agreementId} for sponsor #{$id}");
        $workspaceMessage = 'Agreement deleted.';
      } else {
        throw new RuntimeException('Unknown action.');
      }
      if (!$workspaceAjax) {
        $_SESSION['sponsor_workspace_message'][$id] = $workspaceMessage;
        header('Location: sponsor.php?id=' . $id . '&tab=agreements');
        exit;
      }
    } catch (Throwable $e) {
      $workspaceError = $e->getMessage();
      if ($workspaceAjax) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $workspaceError]);
        exit;
      }
    }
  }

  $clubAgreements = getSponsorshipAgreements($pdo, ['sponsor_id' => $id]);
  if ($workspaceAjax && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['workspace_action'])) {
    ob_start();
    require __DIR__ . '/partials/sponsor_agreements.php';
    $workspaceHtml = ob_get_clean();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $workspaceHtml, 'count' => count($clubAgreements), 'sponsor_active' => (bool)$sponsor['is_active']]);
    exit;
  }

  $hasMainSponsorAgreement = false;
  foreach ($clubAgreements as $clubAgreement) {
    if ((string)$clubAgreement['package_code'] === 'main_sponsor' && (string)$clubAgreement['effective_status'] === 'active') {
      $hasMainSponsorAgreement = true;
      break;
    }
  }
  $notesStmt = $pdo->prepare("
        SELECT n.*
        FROM sponsor_notes n
        WHERE n.sponsor_id = :id
        ORDER BY n.created_at DESC
    ");
  $notesStmt->execute([':id' => $id]);
  $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

  $logoUrl = $sponsor['logo_path'] ?? null;
  $saved = isset($_GET['saved']);
  $assigned = isset($_GET['assigned']);
?>
  <div>
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsors.php">Sponsors</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string)$sponsor['name']) ?></span></nav>
    <?php if ($saved): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Sponsor details saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($assigned): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Sponsorship added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['payment_removed'])): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Payment removed. The outstanding balance has been updated.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($schemaError): ?>
      <div class="alert alert-warning"><?= h($schemaError) ?></div>
    <?php endif; ?>

    <div class="page-hero mb-4">
      <div class="page-hero-body">
        <div class="d-flex flex-column flex-lg-row align-items-start gap-3">
          <div>
            <div class="page-hero-eyebrow">Sponsor profile</div>
            <h1 class="page-hero-title"><i class="fa-solid fa-user-tie me-2"></i><?= h($sponsor['name']) ?></h1>
            <p class="page-hero-subtitle mb-0" id="sponsorStatus"><?= $sponsor['is_active'] ? 'Active' : 'Archived' ?></p>
          </div>
          <div class="ms-lg-auto d-flex flex-column align-items-start align-items-lg-end gap-3">
            <div class="d-flex flex-wrap gap-2">
              <a href="sponsor.php?action=edit&amp;id=<?= $id ?>" class="btn btn-outline-light btn-sm" title="Edit sponsor" aria-label="Edit sponsor"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
              <?php if (hub_auth_has_capability('sponsorship')): ?>
                <button type="button" id="archiveSponsorButton" class="btn btn-outline-light btn-sm" data-action="<?= $sponsor['is_active'] ? 'archive_sponsor' : 'restore_sponsor' ?>" title="<?= $sponsor['is_active'] ? 'Archive sponsor' : 'Restore sponsor' ?>" aria-label="<?= $sponsor['is_active'] ? 'Archive sponsor' : 'Restore sponsor' ?>"><i class="fa-solid <?= $sponsor['is_active'] ? 'fa-box-archive' : 'fa-rotate-left' ?>" aria-hidden="true"></i></button>
              <?php endif; ?>
              <?php if ($hasMainSponsorAgreement): ?>
                <span class="badge bg-warning text-dark">Main Sponsor</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (
      trim((string)($sponsor['facebook_page_url'] ?? '')) !== ''
      || trim((string)($sponsor['instagram_url'] ?? '')) !== ''
      || trim((string)($sponsor['twitter_url'] ?? '')) !== ''
    ): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
          <div>
            <div class="fw-semibold">Social channels</div>
            <div class="small text-muted">
              <?php if (trim((string)($sponsor['facebook_page_id'] ?? '')) !== ''): ?>
                Facebook Page ID: <?= h((string)$sponsor['facebook_page_id']) ?>
              <?php else: ?>
                Saved profiles for this sponsor.
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <?php if (trim((string)($sponsor['facebook_page_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= h((string)$sponsor['facebook_page_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-facebook me-1"></i>Facebook
              </a>
            <?php endif; ?>
            <?php if (trim((string)($sponsor['instagram_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-danger" href="<?= h((string)$sponsor['instagram_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-instagram me-1"></i>Instagram
              </a>
            <?php endif; ?>
            <?php if (trim((string)($sponsor['twitter_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-dark" href="<?= h((string)$sponsor['twitter_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-x-twitter me-1"></i>X / Twitter
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <style>
      .sponsor-tab-panel {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04);
        margin-bottom: 1rem;
        padding: 1rem;
      }
      .sponsor-tab-head {
        align-items: flex-start;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: .9rem;
      }
      .sponsor-tab-head h3,
      .sponsor-action-panel h4 {
        font-size: 1rem;
        line-height: 1.25;
        margin: 0;
      }
      .sponsor-tab-head p,
      .sponsor-action-panel p {
        color: #6c757d;
        font-size: .86rem;
        margin: .25rem 0 0;
      }
      .sponsor-tab-summary {
        display: grid;
        gap: .75rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
      .sponsor-tab-summary > div {
        background: #f8f9fa;
        border: 1px solid rgba(33, 37, 41, .06);
        border-radius: 8px;
        min-width: 0;
        padding: .8rem;
      }
      .sponsor-tab-summary span {
        color: #6c757d;
        display: block;
        font-size: .74rem;
        font-weight: 700;
        text-transform: uppercase;
      }
      .sponsor-tab-summary strong {
        display: block;
        font-size: 1.05rem;
        margin-top: .2rem;
      }
      .sponsor-profile-table-wrap {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
      }
      .sponsor-profile-table-wrap .table > :not(caption) > * > * {
        padding: .85rem;
      }
      .sponsor-action-panel {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
        padding: 1rem;
      }
      .sponsor-notes-list .list-group-item {
        border-color: rgba(33, 37, 41, .08);
        padding: 1rem;
      }
      @media (max-width: 991.98px) {
        .sponsor-tab-head {
          flex-direction: column;
        }
        .sponsor-tab-summary {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
      @media (max-width: 575.98px) {
        .sponsor-tab-summary {
          grid-template-columns: 1fr;
        }
      }
    </style>

    <ul class="nav nav-tabs reports-tabs" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" id="agreementsTab" data-bs-toggle="tab" data-bs-target="#agreements" type="button" role="tab" aria-controls="agreements" aria-selected="true">Agreements &amp; payments <span class="badge text-bg-light ms-1"><?= count($clubAgreements) ?></span></button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" id="notesTab" data-bs-toggle="tab" data-bs-target="#notes" type="button" role="tab" aria-controls="notes" aria-selected="false">Notes <span class="badge text-bg-light ms-1"><?= count($notes) ?></span></button></li>
    </ul>
    <div class="tab-content mt-3">
      <div class="tab-pane fade show active" id="agreements" role="tabpanel" aria-labelledby="agreementsTab">
        <div id="workspaceContent"><?php require __DIR__ . '/partials/sponsor_agreements.php'; ?></div>
      </div>

      <div class="tab-pane fade" id="notes" role="tabpanel" aria-labelledby="notesTab">
        <div class="sponsor-tab-panel">
          <div class="sponsor-tab-head">
            <div>
              <h3>Notes</h3>
              <p>Internal context, follow-up reminders and sponsor relationship notes.</p>
            </div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12 col-lg-7">
            <ul class="list-group sponsor-notes-list" id="notesList">
              <?php foreach ($notes as $note): ?>
                <li class="list-group-item">
                  <div><?= nl2br(h($note['note'])) ?></div>
                  <div class="text-muted small mt-2"><?= h(sponsorProfileDateTime($note['created_at'] ?? null)) ?></div>
                </li>
              <?php endforeach; ?>
              <?php if (!$notes): ?>
                <li class="list-group-item text-muted" data-placeholder="notes-empty">No notes yet.</li>
              <?php endif; ?>
            </ul>
          </div>
          <div class="col-12 col-lg-5">
            <div class="sponsor-action-panel h-100">
              <div>
                <h4>Add note</h4>
                <p>Keep this factual and useful for the next person reviewing the sponsor.</p>
              </div>
              <form method="post" action="sponsor_save.php" class="d-grid gap-2" id="noteForm">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="sponsor_id" value="<?= $id ?>">
                <textarea name="note" class="form-control form-control-sm" rows="6" placeholder="Write a note..." required></textarea>
                <button class="btn btn-sm btn-primary justify-self-start" type="submit">Add note</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <script>
        (function() {
          const form = document.getElementById('noteForm');
          if (!form) return;

          form.addEventListener('submit', function(e) {
            e.preventDefault();
            window.hubClearFormError(form);
            const submitButton = e.submitter || form.querySelector('button[type="submit"]');
            window.hubSetBusy(submitButton, true);
            const formData = new FormData(form);

            fetch('sponsor_save.php', {
                method: 'POST',
                body: formData
              })
              .then(res => res.json())
              .then(data => {
                if (!data.success) {
                  window.hubShowFieldError(form, data.field, data.error);
                  window.hubSetBusy(submitButton, false);
                  return;
                }

                const notesList = document.getElementById('notesList');
                if (!notesList) return;

                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.innerHTML = `
      <div>${data.note.note}</div>
      <div class="text-muted small mt-2">${data.note.created_at}</div>
    `;
                notesList.prepend(li);
                const placeholder = notesList.querySelector('[data-placeholder="notes-empty"]');
                if (placeholder) {
                  placeholder.remove();
                }

                form.reset();
                window.hubSetBusy(submitButton, false);
                window.hubToast('Note added.');
              })
              .catch(() => {
                window.hubShowFormError(form, 'Request failed. Please try again.');
                window.hubSetBusy(submitButton, false);
            });
          });
        })();
      </script>

    </div>
  </div>

<?php
  require __DIR__ . '/footer.php';
  exit;
}

if ($action === 'edit') {
  if ($id <= 0) {
    echo '<div><div class="alert alert-danger">Invalid sponsor ID.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM sponsors WHERE id = :id");
  $stmt->execute([':id' => $id]);
  $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$sponsor) {
    echo '<div><div class="alert alert-danger">Sponsor not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }
} else {
  $nextSponsorSortOrder = max(10, ((int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM sponsors WHERE is_main_sponsor = 1")->fetchColumn()) + 10);
  $sponsor = [
    'name' => '',
    'is_active' => 1,
    'is_main_sponsor' => 0,
    'sort_order' => $nextSponsorSortOrder,
    'logo_path' => null,
    'white_logo_path' => null,
    'facebook_page_url' => null,
    'facebook_page_id' => null,
    'facebook_page_name' => null,
    'instagram_url' => null,
    'twitter_url' => null,
    'is_business' => 0,
    'address' => null,
    'website_url' => null,
    'contact_phone' => null,
    'contact_email' => null,
  ];
}

$formData['name'] = $sponsor['name'] ?? '';
$formData['is_active'] = (int)($sponsor['is_active'] ?? 1);
$formData['is_main_sponsor'] = (int)($sponsor['is_main_sponsor'] ?? 0);
$formData['sort_order'] = max(0, (int)($sponsor['sort_order'] ?? 0));
$formData['facebook_page_url'] = trim((string)($sponsor['facebook_page_url'] ?? ''));
$formData['facebook_page_id'] = trim((string)($sponsor['facebook_page_id'] ?? ''));
$formData['facebook_page_name'] = trim((string)($sponsor['facebook_page_name'] ?? ''));
$formData['instagram_url'] = trim((string)($sponsor['instagram_url'] ?? ''));
$formData['twitter_url'] = trim((string)($sponsor['twitter_url'] ?? ''));
$formData['is_business'] = (int)($sponsor['is_business'] ?? 0);
$formData['address'] = trim((string)($sponsor['address'] ?? ''));
$formData['website_url'] = trim((string)($sponsor['website_url'] ?? ''));
$formData['contact_phone'] = trim((string)($sponsor['contact_phone'] ?? ''));
$formData['contact_email'] = trim((string)($sponsor['contact_email'] ?? ''));
$currentLogo = $sponsor['logo_path'] ?? null;
$currentWhiteLogo = $sponsor['white_logo_path'] ?? null;

if ($action === 'edit' && $id > 0) {
  $seasonStmt = $pdo->prepare("
    SELECT is_active, joined_at, left_at
    FROM sponsor_seasons
    WHERE season_id = :season_id
      AND sponsor_id = :sponsor_id
    LIMIT 1
  ");
  $seasonStmt->execute([
    ':season_id' => $seasonId,
    ':sponsor_id' => $id,
  ]);
  $existingSeasonAssignment = $seasonStmt->fetch(PDO::FETCH_ASSOC);
  if ($existingSeasonAssignment) {
    $seasonAssignment['is_active'] = (int)$existingSeasonAssignment['is_active'];
    $seasonAssignment['joined_at'] = $existingSeasonAssignment['joined_at'] ?: null;
    $seasonAssignment['left_at'] = $existingSeasonAssignment['left_at'] ?: null;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    $errors[] = 'Invalid security token. Please try again.';
  }

  $formData['name'] = trim((string)($_POST['name'] ?? ''));
  $formData['is_active'] = isset($_POST['is_active']) ? 1 : 0;
  $formData['is_main_sponsor'] = (int)($sponsor['is_main_sponsor'] ?? 0);
  $formData['sort_order'] = max(0, (int)($sponsor['sort_order'] ?? 0));
  $formData['facebook_page_url'] = trim((string)($_POST['facebook_page_url'] ?? ''));
  $formData['facebook_page_id'] = trim((string)($_POST['facebook_page_id'] ?? ''));
  $formData['facebook_page_name'] = trim((string)($_POST['facebook_page_name'] ?? ''));
  $formData['instagram_url'] = trim((string)($_POST['instagram_url'] ?? ''));
  $formData['twitter_url'] = trim((string)($_POST['twitter_url'] ?? ''));
  $formData['is_business'] = isset($_POST['is_business']) ? 1 : 0;
  $formData['address'] = trim((string)($_POST['address'] ?? ''));
  $formData['website_url'] = trim((string)($_POST['website_url'] ?? ''));
  $formData['contact_phone'] = trim((string)($_POST['contact_phone'] ?? ''));
  $formData['contact_email'] = trim((string)($_POST['contact_email'] ?? ''));
  if ($formData['contact_email'] !== '' && !filter_var($formData['contact_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid contact email address.';
  }
  $seasonStatus = $_POST['season_status'] ?? 'active';
  if (!in_array($seasonStatus, ['active', 'left'], true)) {
    $errors[] = 'Invalid sponsor season status.';
  }
  $removeLogoChecked = isset($_POST['remove_logo']) && $schemaReady;
  $removeWhiteLogoChecked = isset($_POST['remove_white_logo']) && $schemaReady;
  $removeLogo = $removeLogoChecked;
  $removeWhiteLogo = $removeWhiteLogoChecked;
  $newLogoPath = $currentLogo;
  $newWhiteLogoPath = $currentWhiteLogo;

  if ($formData['name'] === '') {
    $errors[] = 'Sponsor name is required.';
  }

  if ($formData['facebook_page_url'] !== '') {
    $normalisedFacebookUrl = sponsor_normalise_facebook_url($formData['facebook_page_url']);
    if ($normalisedFacebookUrl === null) {
      $errors[] = 'Facebook Page URL must be a valid facebook.com Page URL.';
    } else {
      $formData['facebook_page_url'] = $normalisedFacebookUrl;
    }
  }

  foreach ([
    'instagram_url' => 'Instagram URL',
    'twitter_url' => 'X / Twitter URL',
    'website_url' => 'Website URL',
  ] as $field => $label) {
    $value = $formData[$field];
    if ($value !== '' && (
      filter_var($value, FILTER_VALIDATE_URL) === false
      || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
    )) {
      $errors[] = $label . ' must be a complete http:// or https:// URL.';
    }
  }
  if (($formData['facebook_page_id'] === '' || $formData['facebook_page_name'] === '') && $formData['facebook_page_url'] !== '') {
    $resolvedFacebookPage = sponsor_resolve_facebook_page($formData['facebook_page_url']);
    if ($resolvedFacebookPage !== null) {
      $formData['facebook_page_url'] = $resolvedFacebookPage['url'];
      $formData['facebook_page_id'] = $resolvedFacebookPage['id'];
      if ($resolvedFacebookPage['name'] !== '') {
        $formData['facebook_page_name'] = $resolvedFacebookPage['name'];
      }
    }
  }
  if ($formData['facebook_page_id'] !== '' && preg_match('/^[0-9]{5,100}$/', $formData['facebook_page_id']) !== 1) {
    $errors[] = 'Facebook Page ID must contain numbers only.';
  }

  if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if (!$schemaReady) {
      $errors[] = 'Unable to upload images at this time.';
    } else {
      $file = $_FILES['logo'];
      if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Image upload failed. Please try again.';
      } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid image upload.';
      } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Image must be 5MB or smaller.';
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
        if ($finfo) {
          finfo_close($finfo);
        }
        $allowed = [
          'image/jpeg' => 'jpg',
          'image/png'  => 'png',
          'image/gif'  => 'gif',
          'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime ?? ''])) {
          $errors[] = 'Unsupported image format. Please use PNG, JPG, GIF or WebP.';
        } else {
          $uploadDir = __DIR__ . '/uploads/sponsors';
          if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
              $errors[] = 'Failed to prepare upload directory.';
            }
          }
          if (!$errors) {
            try {
              $random = bin2hex(random_bytes(8));
            } catch (Exception $e) {
              $errors[] = 'Failed to prepare filename for image upload.';
              $random = null;
            }
            if (!$errors && $random !== null) {
              $filename = 'sponsor_' . $random . '.' . $allowed[$mime];
              $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
              $pendingUpload = [
                'tmp_name' => $file['tmp_name'],
                'destination' => $destination,
              ];
              $newLogoPath = $filename; // Store only the filename in DB
              $removeLogo = false;
              $removeLogoChecked = false;
            }
          }
        }
      }
    }
  }

  if (!empty($_FILES['white_logo']) && $_FILES['white_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if (!$schemaReady) {
      $errors[] = 'Unable to upload images at this time.';
    } else {
      $file = $_FILES['white_logo'];
      if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'White image upload failed. Please try again.';
      } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid white image upload.';
      } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'White image must be 5MB or smaller.';
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
        if ($finfo) {
          finfo_close($finfo);
        }
        $allowed = [
          'image/jpeg' => 'jpg',
          'image/png'  => 'png',
          'image/gif'  => 'gif',
          'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime ?? ''])) {
          $errors[] = 'Unsupported white image format. Please use PNG, JPG, GIF or WebP.';
        } else {
          $uploadDir = __DIR__ . '/uploads/sponsors';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $errors[] = 'Failed to prepare upload directory.';
          }
          if (!$errors) {
            try {
              $random = bin2hex(random_bytes(8));
            } catch (Exception $e) {
              $errors[] = 'Failed to prepare filename for white image upload.';
              $random = null;
            }
            if (!$errors && $random !== null) {
              $filename = 'sponsor_white_' . $random . '.' . $allowed[$mime];
              $pendingWhiteUpload = [
                'tmp_name' => $file['tmp_name'],
                'destination' => $uploadDir . DIRECTORY_SEPARATOR . $filename,
              ];
              $newWhiteLogoPath = $filename;
              $removeWhiteLogo = false;
              $removeWhiteLogoChecked = false;
            }
          }
        }
      }
    }
  }

  if ($removeLogo && !$pendingUpload) {
    $newLogoPath = null;
  }
  if ($removeWhiteLogo && !$pendingWhiteUpload) {
    $newWhiteLogoPath = null;
  }

  if ($formData['is_main_sponsor'] && empty($newLogoPath)) {
    $errors[] = 'Upload a Sponsor Image before marking this as a Main Sponsor.';
  }

  if (!$schemaReady && $newLogoPath !== $currentLogo) {
    $errors[] = 'Sponsor images cannot be modified because the database update failed.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($action === 'new') {
        $stmt = $pdo->prepare("
          INSERT INTO sponsors (
            name, is_active, is_main_sponsor, sort_order, logo_path, white_logo_path,
            facebook_page_url, facebook_page_id, facebook_page_name, instagram_url, twitter_url,
            is_business, address, website_url, contact_phone, contact_email
          ) VALUES (
            :name, :is_active, :is_main_sponsor, :sort_order, :logo_path, :white_logo_path,
            :facebook_page_url, :facebook_page_id, :facebook_page_name, :instagram_url, :twitter_url,
            :is_business, :address, :website_url, :contact_phone, :contact_email
          )
        ");
        $stmt->execute([
          ':name' => $formData['name'],
          ':is_active' => $formData['is_active'],
          ':is_main_sponsor' => $formData['is_main_sponsor'],
          ':sort_order' => $formData['sort_order'],
          ':logo_path' => $newLogoPath,
          ':white_logo_path' => $newWhiteLogoPath,
          ':facebook_page_url' => $formData['facebook_page_url'] ?: null,
          ':facebook_page_id' => $formData['facebook_page_id'] ?: null,
          ':facebook_page_name' => $formData['facebook_page_name'] ?: null,
          ':instagram_url' => $formData['instagram_url'] ?: null,
          ':twitter_url' => $formData['twitter_url'] ?: null,
          ':is_business' => $formData['is_business'],
          ':address' => $formData['address'] ?: null,
          ':website_url' => $formData['website_url'] ?: null,
          ':contact_phone' => $formData['contact_phone'] ?: null,
          ':contact_email' => $formData['contact_email'] ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($seasonId > 0) {
          $seasonJoinedAt = $seasonStatus === 'active'
            ? ($seasonAssignment['joined_at'] ?: date('Y-m-d'))
            : ($seasonAssignment['joined_at'] ?: date('Y-m-d'));
          $seasonLeftAt = $seasonStatus === 'left' ? ($seasonAssignment['left_at'] ?: date('Y-m-d')) : null;
          upsertSponsorSeason(
            $pdo,
            $seasonId,
            $id,
            $seasonStatus === 'active' ? 1 : 0,
            $seasonJoinedAt,
            $seasonLeftAt,
            null
          );
        }
      } else {
        $stmt = $pdo->prepare("
          UPDATE sponsors
          SET name = :name,
              is_active = :is_active,
              is_main_sponsor = :is_main_sponsor,
              sort_order = :sort_order,
              logo_path = :logo_path,
              white_logo_path = :white_logo_path,
              facebook_page_url = :facebook_page_url,
              facebook_page_id = :facebook_page_id,
              facebook_page_name = :facebook_page_name,
              instagram_url = :instagram_url,
              twitter_url = :twitter_url,
              is_business = :is_business,
              address = :address,
              website_url = :website_url,
              contact_phone = :contact_phone,
              contact_email = :contact_email
          WHERE id = :id
        ");
        $stmt->execute([
          ':name' => $formData['name'],
          ':is_active' => $formData['is_active'],
          ':is_main_sponsor' => $formData['is_main_sponsor'],
          ':sort_order' => $formData['sort_order'],
          ':logo_path' => $newLogoPath,
          ':white_logo_path' => $newWhiteLogoPath,
          ':facebook_page_url' => $formData['facebook_page_url'] ?: null,
          ':facebook_page_id' => $formData['facebook_page_id'] ?: null,
          ':facebook_page_name' => $formData['facebook_page_name'] ?: null,
          ':instagram_url' => $formData['instagram_url'] ?: null,
          ':twitter_url' => $formData['twitter_url'] ?: null,
          ':is_business' => $formData['is_business'],
          ':address' => $formData['address'] ?: null,
          ':website_url' => $formData['website_url'] ?: null,
          ':contact_phone' => $formData['contact_phone'] ?: null,
          ':contact_email' => $formData['contact_email'] ?: null,
          ':id' => $id,
        ]);

        if ($seasonId > 0) {
          $seasonJoinedAt = $seasonAssignment['joined_at'] ?: date('Y-m-d');
          $seasonLeftAt = $seasonStatus === 'left' ? ($seasonAssignment['left_at'] ?: date('Y-m-d')) : null;
          upsertSponsorSeason(
            $pdo,
            $seasonId,
            $id,
            $seasonStatus === 'active' ? 1 : 0,
            $seasonJoinedAt,
            $seasonLeftAt,
            null
          );
        }
      }

      if ($pendingUpload) {
        if (!move_uploaded_file($pendingUpload['tmp_name'], $pendingUpload['destination'])) {
          throw new RuntimeException('Failed to store uploaded image.');
        }
        if ($currentLogo && $currentLogo !== $newLogoPath) {
          $deleteAfterCommit = $currentLogo;
        }
      } elseif ($removeLogo && $currentLogo) {
        $deleteAfterCommit = $currentLogo;
      } else {
        $deleteAfterCommit = null;
      }

      if ($pendingWhiteUpload) {
        if (!move_uploaded_file($pendingWhiteUpload['tmp_name'], $pendingWhiteUpload['destination'])) {
          throw new RuntimeException('Failed to store uploaded white image.');
        }
        if ($currentWhiteLogo && $currentWhiteLogo !== $newWhiteLogoPath) {
          $deleteWhiteAfterCommit = $currentWhiteLogo;
        }
      } elseif ($removeWhiteLogo && $currentWhiteLogo) {
        $deleteWhiteAfterCommit = $currentWhiteLogo;
      } else {
        $deleteWhiteAfterCommit = null;
      }

      $pdo->commit();
      player_sponsors_sync_social_directory();
      auditLog($pdo, $action === 'new' ? 'sponsor_created' : 'sponsor_updated', ($action === 'new' ? 'Created' : 'Updated') . " sponsor #{$id} ({$formData['name']})");

      if (!empty($deleteAfterCommit)) {
        $deletePath = __DIR__ . '/uploads/sponsors/' . $deleteAfterCommit;
        if (is_file($deletePath)) {
          @unlink($deletePath);
        }
      }
      if (!empty($deleteWhiteAfterCommit)) {
        $deletePath = __DIR__ . '/uploads/sponsors/' . $deleteWhiteAfterCommit;
        if (is_file($deletePath)) {
          @unlink($deletePath);
        }
      }

      header('Location: ' . ($action === 'edit' ? 'sponsors.php?saved=1' : 'sponsor.php?id=' . $id . '&saved=1'));
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      if ($pendingUpload && isset($pendingUpload['destination']) && is_file($pendingUpload['destination'])) {
        @unlink($pendingUpload['destination']);
      }
      if ($pendingWhiteUpload && isset($pendingWhiteUpload['destination']) && is_file($pendingWhiteUpload['destination'])) {
        @unlink($pendingWhiteUpload['destination']);
      }
      $errors[] = $e->getMessage();
    }
  }

  if ($removeLogoChecked && !$pendingUpload && $errors) {
    $currentLogo = null;
  }
  if ($removeWhiteLogoChecked && !$pendingWhiteUpload && $errors) {
    $currentWhiteLogo = null;
  }
}
?>

<div>
  <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsors.php">Sponsors</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add sponsor' : 'Edit sponsor' ?></span></nav>
  <h1 class="h3 mb-3"><?= $action === 'new' ? 'Add Sponsor' : 'Edit Sponsor' ?></h1>

  <?php if ($schemaError): ?>
    <div class="alert alert-warning"><?= h($schemaError) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
          <li><?= h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0" data-warn-unsaved>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label for="sponsorName" class="form-label">Sponsor Name</label>
        <input type="text" class="form-control" id="sponsorName" name="name" value="<?= h($formData['name']) ?>" required>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-share-nodes"></i></span>
            <div>
              <h2 class="h5 mb-1">Social channels</h2>
              <p class="small text-muted mb-0">These profiles can be used for sponsor credits and platform mentions in social captions.</p>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-lg-8">
              <label for="sponsorFacebookUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-facebook text-primary me-1"></i>Facebook Page URL
              </label>
              <input type="text" inputmode="url" class="form-control" id="sponsorFacebookUrl" name="facebook_page_url" value="<?= h($formData['facebook_page_url']) ?>" placeholder="www.facebook.com/your-page" autocomplete="url">
              <div class="form-text" id="sponsorFacebookLookupStatus">Paste the Page URL and its numeric ID will be found automatically.</div>
            </div>
            <div class="col-lg-4">
              <label for="sponsorFacebookId" class="form-label fw-semibold">Facebook Page ID</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control" id="sponsorFacebookId" name="facebook_page_id" value="<?= h($formData['facebook_page_id']) ?>" placeholder="Numeric Page ID">
              <div class="form-text">Used when an automated Facebook Page mention is supported.</div>
            </div>
            <div class="col-12">
              <label for="sponsorFacebookName" class="form-label fw-semibold">Facebook Page name</label>
              <input type="text" class="form-control" id="sponsorFacebookName" name="facebook_page_name" value="<?= h($formData['facebook_page_name']) ?>" placeholder="Found automatically from the Facebook Page">
              <div class="form-text">This is the official Page name displayed in Facebook sponsor mentions.</div>
            </div>
            <div class="col-md-6">
              <label for="sponsorInstagramUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-instagram text-danger me-1"></i>Instagram profile URL
              </label>
              <input type="url" class="form-control" id="sponsorInstagramUrl" name="instagram_url" value="<?= h($formData['instagram_url']) ?>" placeholder="https://www.instagram.com/your-account">
            </div>
            <div class="col-md-6">
              <label for="sponsorTwitterUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-x-twitter me-1"></i>X / Twitter profile URL
              </label>
              <input type="url" class="form-control" id="sponsorTwitterUrl" name="twitter_url" value="<?= h($formData['twitter_url']) ?>" placeholder="https://x.com/your-account">
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-envelope"></i></span>
            <div>
              <h2 class="h5 mb-1">Payments</h2>
              <p class="small text-muted mb-0">Used to email Stripe payment links from a sponsorship agreement.</p>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label for="sponsorContactEmail" class="form-label fw-semibold">Contact email</label>
              <input type="email" class="form-control" id="sponsorContactEmail" name="contact_email" value="<?= h($formData['contact_email']) ?>" placeholder="sponsor@example.com" autocomplete="email">
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-building"></i></span>
            <div>
              <h2 class="h5 mb-1">Business details</h2>
              <p class="small text-muted mb-0">For sponsors who are a business rather than an individual. Shown on the player graphic and available to insert into social post captions.</p>
            </div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="sponsorIsBusiness" name="is_business" value="1" <?= $formData['is_business'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="sponsorIsBusiness">This sponsor is a business</label>
          </div>
          <div id="sponsorBusinessFields" class="row g-3" <?= $formData['is_business'] ? '' : 'style="display:none"' ?>>
            <div class="col-12">
              <label for="sponsorAddress" class="form-label fw-semibold">Address</label>
              <input type="text" class="form-control" id="sponsorAddress" name="address" value="<?= h($formData['address']) ?>" placeholder="e.g. 12 High Street, Saltcoats, KA21 5AB" autocomplete="street-address">
            </div>
            <div class="col-md-6">
              <label for="sponsorWebsiteUrl" class="form-label fw-semibold">Website</label>
              <input type="url" class="form-control" id="sponsorWebsiteUrl" name="website_url" value="<?= h($formData['website_url']) ?>" placeholder="https://www.example.com" autocomplete="url">
            </div>
            <div class="col-md-6">
              <label for="sponsorContactPhone" class="form-label fw-semibold">Contact number</label>
              <input type="tel" class="form-control" id="sponsorContactPhone" name="contact_phone" value="<?= h($formData['contact_phone']) ?>" placeholder="01294 123456" autocomplete="tel">
            </div>
          </div>
        </div>
      </div>

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="sponsorActive" name="is_active" value="1" <?= $formData['is_active'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="sponsorActive">Sponsor is active</label>
      </div>

      <div class="alert alert-info">
        Sponsorship roles such as Main Sponsor are now managed as agreements.
        <?php if ($action === 'edit'): ?><a href="/admin/sponsorship_agreement.php?action=new&amp;sponsor_id=<?= $id ?>" class="alert-link">Add an agreement for this sponsor</a>.<?php endif; ?>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <label class="form-label mb-2 fw-semibold d-block">Current season status</label>
          <div class="btn-group sponsor-season-toggle w-100" role="group" aria-label="Current season status">
            <input class="btn-check" type="radio" name="season_status" id="seasonStatusActive" value="active" <?= (int)$seasonAssignment['is_active'] === 1 ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary sponsor-season-btn sponsor-season-active" for="seasonStatusActive">
              Active this season
            </label>

            <input class="btn-check" type="radio" name="season_status" id="seasonStatusLeft" value="left" <?= (int)$seasonAssignment['is_active'] === 0 ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary sponsor-season-btn sponsor-season-left" for="seasonStatusLeft">
              Left this season
            </label>
          </div>
          <div class="form-text mt-2">
            This controls the sponsor's status for the currently selected season.
          </div>
        </div>
      </div>

      <div class="mb-3">
        <h2 class="h5 mb-1">Sponsor Images</h2>
        <p class="small text-muted">Upload both versions so the logo remains readable on different graphic backgrounds.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
              <label for="sponsorLogo" class="form-label fw-semibold">Coloured Image</label>
              <?php if ($currentLogo): ?>
                <div class="mb-3">
                  <img src="/uploads/sponsors/<?= h(rawurlencode(basename((string)$currentLogo))) ?>" alt="<?= h($formData['name'] ?: 'Current sponsor') ?> coloured logo" class="rounded border bg-white p-2" style="width:220px; height:140px; object-fit:contain;">
                </div>
              <?php endif; ?>
              <input type="file" class="form-control" id="sponsorLogo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" <?= !$schemaReady ? 'disabled' : '' ?>>
              <div class="form-text">PNG, JPG, GIF or WebP up to 5MB.</div>
              <?php if ($currentLogo): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="removeLogo" name="remove_logo" value="1" <?= !$schemaReady ? 'disabled' : '' ?> <?= $removeLogoChecked ? 'checked' : '' ?>>
                  <label class="form-check-label" for="removeLogo">Remove coloured image</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
              <label for="sponsorWhiteLogo" class="form-label fw-semibold">White Image</label>
              <?php if ($currentWhiteLogo): ?>
                <div class="mb-3 rounded border bg-dark p-2 d-inline-flex">
                  <img src="/uploads/sponsors/<?= h(rawurlencode(basename((string)$currentWhiteLogo))) ?>" alt="<?= h($formData['name'] ?: 'Current sponsor') ?> white logo" style="width:220px; height:140px; object-fit:contain;">
                </div>
              <?php endif; ?>
              <input type="file" class="form-control" id="sponsorWhiteLogo" name="white_logo" accept="image/png,image/jpeg,image/gif,image/webp" <?= !$schemaReady ? 'disabled' : '' ?>>
              <div class="form-text">Transparent PNG or WebP recommended.</div>
              <?php if ($currentWhiteLogo): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="removeWhiteLogo" name="remove_white_logo" value="1" <?= !$schemaReady ? 'disabled' : '' ?> <?= $removeWhiteLogoChecked ? 'checked' : '' ?>>
                  <label class="form-check-label" for="removeWhiteLogo">Remove white image</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php if (!$schemaReady): ?>
          <div class="alert alert-warning mt-2 mb-0 py-2 px-3">Image uploads are currently unavailable.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-footer d-flex flex-column-reverse flex-md-row justify-content-between gap-2">
      <a href="<?= $action === 'new' ? 'sponsors.php' : 'sponsor.php?id=' . $id ?>" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-brand"><?= $action === 'new' ? 'Create Sponsor' : 'Save Changes' ?></button>
    </div>
  </form>
</div>

<script>
(() => {
  const urlInput = document.getElementById('sponsorFacebookUrl');
  const idInput = document.getElementById('sponsorFacebookId');
  const nameInput = document.getElementById('sponsorFacebookName');
  const status = document.getElementById('sponsorFacebookLookupStatus');
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (!urlInput || !idInput || !nameInput || !status || !csrfInput) return;

  let requestNumber = 0;

  const showStatus = (message, tone = 'muted') => {
    status.textContent = message;
    status.className = `form-text text-${tone}`;
  };

  const resolvePage = async () => {
    let url = urlInput.value.trim();
    if (!url) {
      showStatus('Paste the Page URL and its numeric ID will be found automatically.');
      return;
    }
    if (!/^https?:\/\//i.test(url)) {
      url = `https://${url.replace(/^\/+/, '')}`;
      urlInput.value = url;
    }

    const currentRequest = ++requestNumber;
    showStatus('Finding Facebook Page ID…', 'primary');

    const data = new FormData();
    data.set('csrf_token', csrfInput.value);
    data.set('facebook_page_url', url);

    try {
      const response = await fetch('resolve_facebook_page.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      const result = await response.json();
      if (currentRequest !== requestNumber) return;

      if (!response.ok || !result.ok) {
        showStatus(result.message || 'The Facebook Page ID could not be found automatically.', 'warning');
        return;
      }

      urlInput.value = result.url;
      idInput.value = result.id;
      if (result.name) nameInput.value = result.name;
      showStatus(`${result.name || 'Facebook Page'} found and linked.`, 'success');
    } catch (error) {
      if (currentRequest === requestNumber) {
        showStatus('The Facebook Page ID could not be checked just now. You can still save it manually.', 'warning');
      }
    }
  };

  urlInput.addEventListener('change', resolvePage);
  urlInput.addEventListener('paste', () => window.setTimeout(resolvePage, 0));
})();

(() => {
  const toggle = document.getElementById('sponsorIsBusiness');
  const fields = document.getElementById('sponsorBusinessFields');
  if (!toggle || !fields) return;

  toggle.addEventListener('change', () => {
    fields.style.display = toggle.checked ? '' : 'none';
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
