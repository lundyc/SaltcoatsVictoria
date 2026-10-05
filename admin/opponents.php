<?php
$pageHero = [
          'eyebrow' => 'Fixture management',
          'title' => 'Opponents',
          'subtitle' => 'Manage club names, abbreviations, logos, and ground locations.',
          'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$opponents = getMatchOpponents($pdo);
$deletedNotice = isset($_GET['deleted']) ? 'Opponent deleted.' : '';
?>

<?php if (isset($_GET['saved'])): ?>
          <div class="alert alert-success">Opponent saved.</div>
<?php endif; ?>
<div id="opponentNoticeArea">
          <?php if ($deletedNotice !== ''): ?>
                    <div class="alert alert-success js-auto-dismiss-notice">Opponent deleted.</div>
          <?php endif; ?>
</div>

<div class="card shadow-sm hub-section hub-table-card">
          <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 hub-toolbar">
                              <div class="form-check">
                                        <input class="form-check-input js-select-all-opponents" type="checkbox" id="selectAllOpponents">
                                        <label class="form-check-label fw-semibold" for="selectAllOpponents">Select all</label>
                              </div>
                              <div class="hub-local-actions">
                                        <button type="button" class="btn btn-outline-danger" id="bulkDeleteBtn" disabled>Delete selected</button>
                                        <a href="opponent.php?action=new" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add opponent</a>
                              </div>
                    </div>

                    <div class="d-xl-none d-flex flex-column gap-2">
                              <?php foreach ($opponents as $opponent): ?>
                                        <article class="fixtures-mobile-card hub-record-card">
                                                  <div class="d-flex justify-content-between align-items-start gap-2">
                                                            <div class="form-check">
                                                                      <input
                                                                                class="form-check-input js-opponent-select"
                                                                                type="checkbox"
                                                                                value="<?= (int) $opponent['id'] ?>"
                                                                                data-opponent-name="<?= h($opponent['clubname']) ?>"
                                                                                id="opponent-mobile-<?= (int) $opponent['id'] ?>"
                                                                          >
                                                            </div>
                                                            <div class="flex-grow-1 d-flex align-items-center gap-2">
                                                                      <?php if (!empty($opponent['logo_path'])): ?>
                                                                                <img src="<?= h(matchOpponentLogoAssetUrl((string) $opponent['logo_path'])) ?>" alt="<?= h($opponent['clubname']) ?> logo" loading="lazy" style="width:32px;height:32px;object-fit:contain;" class="flex-shrink-0">
                                                                      <?php endif; ?>
                                                                      <div>
                                                                                <div class="fw-semibold"><?= h($opponent['clubname']) ?></div>
                                                                      <div class="small text-muted"><?= h((string)($opponent['abbreviation'] ?? '')) ?></div>
                                                                      </div>
                                                            </div>
                                                            <div class="btn-group hub-actions" role="group" aria-label="Opponent actions">
                                                                      <a href="opponent.php?id=<?= (int)$opponent['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                                      <button
                                                                                type="button"
                                                                                class="btn btn-sm btn-outline-danger js-delete-opponent"
                                                                                data-opponent-id="<?= (int)$opponent['id'] ?>"
                                                                                data-opponent-name="<?= h($opponent['clubname']) ?>"
                                                                      >Delete</button>
                                                            </div>
                                                  </div>
                                                  <div class="small text-muted mt-3">
                                                            <?= h((string)($opponent['ground_location'] ?? '')) ?>
                                                  </div>
                                        </article>
                              <?php endforeach; ?>
                              <?php if (!$opponents): ?>
                                        <div class="alert alert-info mb-0 hub-empty-state">No opponents created yet.</div>
                              <?php endif; ?>
                    </div>

                    <div class="d-none d-xl-block">
                              <div class="table-responsive">
                              <table class="table table-sm hub-data-table align-middle">
                                        <thead>
                                                  <tr>
                                                            <th style="width: 2.5rem;">
                                                                      <input class="form-check-input js-select-all-opponents" type="checkbox" aria-label="Select all opponents">
                                                            </th>
                                                            <th>Club</th>
                                                            <th>Abbreviation</th>
                                                            <th>Ground Location</th>
                                                            <th>Actions</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php foreach ($opponents as $opponent): ?>
                                                            <tr>
                                                                      <td>
                                                                                <input
                                                                                          class="form-check-input js-opponent-select"
                                                                                          type="checkbox"
                                                                                          value="<?= (int) $opponent['id'] ?>"
                                                                                          data-opponent-name="<?= h($opponent['clubname']) ?>"
                                                                                          id="opponent-desktop-<?= (int) $opponent['id'] ?>"
                                                                                >
                                                                      </td>
                                                                      <td>
                                                                                <div class="d-flex align-items-center gap-2">
                                                                                          <?php if (!empty($opponent['logo_path'])): ?>
                                                                                                    <img src="<?= h(matchOpponentLogoAssetUrl((string) $opponent['logo_path'])) ?>" alt="<?= h($opponent['clubname']) ?> logo" loading="lazy" style="width:28px;height:28px;object-fit:contain;" class="flex-shrink-0">
                                                                                          <?php endif; ?>
                                                                                         <span><?= h($opponent['clubname']) ?></span>
                                                                                </div>
                                                                      </td>
                                                                      <td><?= h((string)($opponent['abbreviation'] ?? '')) ?></td>
                                                                      <td><?= h((string)($opponent['ground_location'] ?? '')) ?></td>
                                                                      <td class="text-nowrap">
                                                                                <a href="opponent.php?id=<?= (int)$opponent['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                                                <button
                                                                                          type="button"
                                                                                          class="btn btn-sm btn-outline-danger js-delete-opponent"
                                                                                          data-opponent-id="<?= (int)$opponent['id'] ?>"
                                                                                          data-opponent-name="<?= h($opponent['clubname']) ?>"
                                                                                >Delete</button>
                                                                      </td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                                  <?php if (!$opponents): ?>
                                                            <tr>
                                                                      <td colspan="5" class="text-center text-muted py-4 hub-record-empty">No opponents created yet.</td>
                                                            </tr>
                                                  <?php endif; ?>
                                        </tbody>
                              </table>
                              </div>
                    </div>
          </div>
</div>

<div class="modal fade" id="deleteOpponentModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                              <div class="modal-header">
                                        <h5 class="modal-title">Delete Opponent</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body">
                                        <p class="mb-2">Delete <strong id="deleteOpponentName"></strong>?</p>
                                        <p class="text-muted mb-0">This will remove the opponent from fixtures and cannot be undone.</p>
                                        <div id="bulkDeleteSummary" class="mt-3 d-none">
                                                  <div class="small text-muted mb-2">Selected opponents:</div>
                                                  <ul id="bulkDeleteList" class="small mb-0"></ul>
                                        </div>
                              </div>
                              <div class="modal-footer">
                                        <form id="deleteOpponentForm" method="post" class="d-inline">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="bulk" id="deleteOpponentBulkFlag" value="0">
                                                  <div id="bulkDeleteIds"></div>
                                                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                  <button type="submit" class="btn btn-danger" id="deleteOpponentSubmitBtn">Delete Opponent</button>
                                        </form>
                              </div>
                    </div>
          </div>
</div>

<script>
          (function() {
                    var modalEl = document.getElementById('deleteOpponentModal');
                    var nameEl = document.getElementById('deleteOpponentName');
                    var formEl = document.getElementById('deleteOpponentForm');
                    var noticeArea = document.getElementById('opponentNoticeArea');
                    var bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
                    var selectAllInputs = document.querySelectorAll('.js-select-all-opponents');
                    var opponentCheckboxes = document.querySelectorAll('.js-opponent-select');
                    var bulkSummary = document.getElementById('bulkDeleteSummary');
                    var bulkList = document.getElementById('bulkDeleteList');
                    var bulkIdsWrap = document.getElementById('bulkDeleteIds');
                    var bulkFlag = document.getElementById('deleteOpponentBulkFlag');
                    var submitBtn = document.getElementById('deleteOpponentSubmitBtn');
                    if (!modalEl || !nameEl || !formEl || typeof bootstrap === 'undefined') {
                              return;
                    }

                    var modal = new bootstrap.Modal(modalEl);
                    var deleteMode = 'single';
                    var selectedIds = [];

                    function showNotice(message) {
                              if (!noticeArea) {
                                        return;
                              }

                              noticeArea.innerHTML = '<div class="alert alert-success js-auto-dismiss-notice">' + message + '</div>';
                              var notice = noticeArea.querySelector('.js-auto-dismiss-notice');
                              if (notice) {
                                        window.setTimeout(function() {
                                                  notice.remove();
                                        }, 5000);
                              }
                    }

                    function getSelectedCheckboxes() {
                              return Array.prototype.slice.call(document.querySelectorAll('.js-opponent-select:checked'));
                    }

                    function updateBulkButton() {
                              var count = getSelectedCheckboxes().length;
                              if (bulkDeleteBtn) {
                                        bulkDeleteBtn.disabled = count === 0;
                                        bulkDeleteBtn.textContent = count > 0 ? ('Delete Selected (' + count + ')') : 'Delete Selected';
                              }
                    }

                    function syncSelectAllState() {
                              var checked = getSelectedCheckboxes().length;
                              var total = opponentCheckboxes.length;
                              selectAllInputs.forEach(function(input) {
                                        input.checked = total > 0 && checked === total;
                                        input.indeterminate = checked > 0 && checked < total;
                              });
                              updateBulkButton();
                    }

                    function setModalForSingle(opponentId, opponentName) {
                              deleteMode = 'single';
                              selectedIds = [opponentId];
                              nameEl.textContent = opponentName;
                              if (bulkSummary) {
                                        bulkSummary.classList.add('d-none');
                              }
                              if (bulkIdsWrap) {
                                        bulkIdsWrap.innerHTML = '';
                              }
                              if (bulkFlag) {
                                        bulkFlag.value = '0';
                              }
                              if (submitBtn) {
                                        submitBtn.textContent = 'Delete Opponent';
                              }
                              formEl.action = 'opponent_delete.php?id=' + encodeURIComponent(opponentId);
                    }

                    function setModalForBulk() {
                              deleteMode = 'bulk';
                              var selected = getSelectedCheckboxes();
                              selectedIds = selected.map(function(input) {
                                        return input.value;
                              });

                              nameEl.textContent = selectedIds.length + ' selected opponents';
                              if (bulkSummary && bulkList) {
                                        bulkList.innerHTML = '';
                                        selected.forEach(function(input) {
                                                  var li = document.createElement('li');
                                                  li.textContent = input.dataset.opponentName || ('Opponent ' + input.value);
                                                  bulkList.appendChild(li);
                                        });
                                        bulkSummary.classList.remove('d-none');
                              }
                              if (bulkFlag) {
                                        bulkFlag.value = '1';
                              }
                              if (bulkIdsWrap) {
                                        bulkIdsWrap.innerHTML = '';
                                        selectedIds.forEach(function(id) {
                                                  var hidden = document.createElement('input');
                                                  hidden.type = 'hidden';
                                                  hidden.name = 'ids[]';
                                                  hidden.value = id;
                                                  bulkIdsWrap.appendChild(hidden);
                                        });
                              }
                              if (submitBtn) {
                                        submitBtn.textContent = 'Delete Selected';
                              }
                              formEl.action = 'opponent_bulk_delete.php';
                    }

                    if (window.sessionStorage.getItem('hubOpponentDeleted') === '1') {
                              window.sessionStorage.removeItem('hubOpponentDeleted');
                              showNotice('Opponent deleted.');
                    }

                    document.querySelectorAll('.js-delete-opponent').forEach(function(button) {
                              button.addEventListener('click', function() {
                                        var opponentId = button.dataset.opponentId || '';
                                        var opponentName = button.dataset.opponentName || 'this opponent';
                                        setModalForSingle(opponentId, opponentName);
                                        modal.show();
                              });
                    });

                    selectAllInputs.forEach(function(input) {
                              input.addEventListener('change', function() {
                                        var checked = !!input.checked;
                                        opponentCheckboxes.forEach(function(checkbox) {
                                                  checkbox.checked = checked;
                                        });
                                        syncSelectAllState();
                              });
                    });

                    opponentCheckboxes.forEach(function(checkbox) {
                              checkbox.addEventListener('change', syncSelectAllState);
                    });

                    if (bulkDeleteBtn) {
                              bulkDeleteBtn.addEventListener('click', function() {
                                        if (getSelectedCheckboxes().length === 0) {
                                                  return;
                                        }
                                        setModalForBulk();
                                        modal.show();
                              });
                    }

                    formEl.addEventListener('submit', function(event) {
                              event.preventDefault();

                              if (submitBtn) {
                                        submitBtn.disabled = true;
                                        submitBtn.dataset.originalText = submitBtn.textContent || 'Delete Opponent';
                                        submitBtn.textContent = 'Deleting...';
                              }

                              fetch(formEl.action, {
                                        method: 'POST',
                                        headers: {
                                                  'X-Requested-With': 'XMLHttpRequest'
                                        },
                                        body: new FormData(formEl)
                              }).then(function(response) {
                                        return response.text().then(function(text) {
                                                  var json = null;
                                                  try {
                                                            json = text ? JSON.parse(text) : null;
                                                  } catch (e) {
                                                            json = null;
                                                  }
                                                  return { ok: response.ok, json: json, text: text };
                                        });
                              }).then(function(result) {
                                        if (!result.ok || !result.json || !result.json.ok) {
                                                  throw new Error((result.json && result.json.error) ? result.json.error : (result.text || 'Delete failed.'));
                                        }

                                        window.sessionStorage.setItem('hubOpponentDeleted', '1');
                                        modalEl.addEventListener('hidden.bs.modal', function() {
                                                  window.location.reload();
                                        }, { once: true });
                                        modal.hide();
                              }).catch(function(error) {
                                        showNotice(error && error.message ? error.message : 'Delete failed.');
                              }).finally(function() {
                                if (submitBtn) {
                                          submitBtn.disabled = false;
                                          submitBtn.textContent = submitBtn.dataset.originalText || 'Delete Opponent';
                                }
                              });
                    });

                    syncSelectAllState();
          })();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
