<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// Matchday Staffing & Volunteers and the Matchday Checklist are hidden for now.
// Flip these back to true (and nothing else) to restore both cards.
$showMatchdayStaffing = false;
$showMatchdayChecklist = false;

$overviewOpponent = trim((string)($fixture['opponent'] ?? 'Opponent')) ?: 'Opponent';
$overviewIsHome = (int)($fixture['is_home'] ?? 1) === 1;
$overviewHomeTeam = $overviewIsHome ? 'Saltcoats Victoria' : $overviewOpponent;
$overviewAwayTeam = $overviewIsHome ? $overviewOpponent : 'Saltcoats Victoria';
$overviewHomeScore = $fixture['full_time_home_score'] ?? null;
$overviewAwayScore = $fixture['full_time_away_score'] ?? null;
$overviewDate = trim((string)($fixture['match_date'] ?? ''));
$overviewKickoff = trim((string)($fixture['kickoff_time'] ?? ''));
$overviewVenueName = trim((string)($fixture['venue'] ?? ''));
if ($overviewVenueName === '') {
          $overviewVenueName = trim((string)($fixture['opponent_ground_location'] ?? ''));
}
if ($overviewVenueName === '') {
          $overviewVenueName = 'the venue';
}
$overviewSquadNumbers = is_array($fixture['starting11_squad_numbers'] ?? null)
          ? $fixture['starting11_squad_numbers']
          : [];
$overviewCaptain = trim((string)($fixture['starting11_captain'] ?? ''));
$overviewEventLabels = [
          'goals' => 'Goals logged',
          'shots' => 'Shots',
          'chances' => 'Chances',
          'cards' => 'Cards',
          'substitutions' => 'Changes',
];
?>

<div class="card match-overview-hero shadow-sm mb-4">
  <div class="card-body p-4 p-lg-5">
    <div class="match-overview-hero__meta small fw-semibold mb-3">
      <?= h((string)($fixture['competition'] ?? 'Fixture')) ?>
      <?php if (trim((string)($fixture['competition_stage'] ?? '')) !== ''): ?> · <?= h((string)$fixture['competition_stage']) ?><?php endif; ?>
      <?php if ($overviewDate !== ''): ?> · <?= h(date('D j M Y', strtotime($overviewDate))) ?><?php endif; ?>
      <?php if ($overviewKickoff !== ''): ?> · <?= h(date('H:i', strtotime($overviewKickoff))) ?><?php endif; ?>
    </div>
    <div class="match-overview-score text-center">
      <div class="match-overview-score__team text-end"><?= h($overviewHomeTeam) ?></div>
      <div class="match-overview-score__numbers">
        <?php if ($overviewHomeScore !== null && $overviewAwayScore !== null): ?>
          <?= (int)$overviewHomeScore ?> <span class="opacity-50">–</span> <?= (int)$overviewAwayScore ?>
        <?php else: ?>
          <span class="fs-5"><?= $overviewKickoff !== '' ? h(date('H:i', strtotime($overviewKickoff))) : 'v' ?></span>
        <?php endif; ?>
      </div>
      <div class="match-overview-score__team text-start"><?= h($overviewAwayTeam) ?></div>
    </div>
    <div class="match-overview-hero__venue text-center mt-3">
      <i class="fa-solid fa-location-dot me-1"></i><?= h(trim((string)($fixture['venue'] ?? 'Venue TBC')) ?: 'Venue TBC') ?>
      <?php if ($overviewFinished): ?>
        <span class="badge rounded-pill text-bg-light ms-2">Full time</span>
      <?php endif; ?>
    </div>
    <div class="text-center mt-4">
      <a class="btn btn-light match-overview-hero__action" href="match_poster.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>">
        <i class="fa-solid fa-file-arrow-down me-2"></i>Download Poster
      </a>
    </div>
  </div>
</div>

<?php if (isset($_GET['imported'])): ?>
  <div class="alert alert-success">Match report imported. The Starting XI, substitutes and match events below have been updated.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <div class="small fw-semibold text-uppercase text-muted mb-1">COMET</div>
      <h2 class="h4 mb-1">Import match report</h2>
      <div class="text-muted small">Pull the Starting XI, substitutes, goals, cards and substitutions straight from the COMET PDF. You review everything before it saves.</div>
    </div>
    <a class="btn btn-brand" href="match_report_import.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>">
      <i class="fa-solid fa-file-import me-2" aria-hidden="true"></i>Import from PDF
    </a>
  </div>
</div>

<?php if ($showMatchdayStaffing): ?>
<?php if (isset($_GET['staffing_saved'])): ?>
  <div class="alert alert-success">Matchday staffing updated.</div>
<?php endif; ?>
<?php if (isset($_GET['staffing_deleted'])): ?>
  <div class="alert alert-success">Matchday staffing assignment removed.</div>
<?php endif; ?>
<?php if (isset($_GET['staffing_error'])): ?>
  <div class="alert alert-danger"><?= h((string) $_GET['staffing_error']) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4" id="matchdayStaffingCard">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="small fw-semibold text-uppercase text-muted mb-1">Matchday</div>
        <h2 class="h4 mb-0">Staffing & Volunteers</h2>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="badge text-bg-light"><?= (int) $staffingSummary['active'] ?> active</span>
        <span class="badge text-bg-primary"><?= (int) $staffingSummary['confirmed'] ?> confirmed</span>
        <span class="badge text-bg-success"><?= (int) $staffingSummary['checked_in'] ?> checked in</span>
      </div>
    </div>

    <?php if ($staffingAssignments === []): ?>
      <div class="alert alert-info mb-3">No matchday roles assigned yet.</div>
    <?php else: ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Role</th>
              <th>Person</th>
              <th>Report</th>
              <th>Status</th>
              <th>Notes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($staffingAssignments as $assignment): ?>
              <?php $assignmentStatus = (string) ($assignment['status'] ?? 'planned'); ?>
              <tr>
                <td class="fw-semibold"><?= h((string) $assignment['role_label']) ?></td>
                <td>
                  <?= h((string) ($assignment['person_name'] ?: 'Unassigned')) ?>
                  <?php if (!empty($assignment['person_email'])): ?><div class="small text-muted"><?= h((string) $assignment['person_email']) ?></div><?php endif; ?>
                </td>
                <td><?= !empty($assignment['report_time']) ? h(substr((string) $assignment['report_time'], 0, 5)) : '<span class="text-muted">-</span>' ?></td>
                <td><span class="badge <?= h(matchday_staffing_badge_class($assignmentStatus)) ?>"><?= h(matchday_staffing_status_label($assignmentStatus)) ?></span></td>
                <td><?= trim((string) ($assignment['notes'] ?? '')) !== '' ? h((string) $assignment['notes']) : '<span class="text-muted">-</span>' ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm" role="group" aria-label="Staffing actions">
                    <?php foreach (['confirmed' => 'Confirm', 'checked_in' => 'In', 'checked_out' => 'Out'] as $nextStatus => $label): ?>
                      <?php if ($assignmentStatus !== $nextStatus): ?>
                        <form method="post" action="/admin/matchday_staffing_action.php" class="d-inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                          <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                          <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                          <input type="hidden" name="action" value="status">
                          <input type="hidden" name="status" value="<?= h($nextStatus) ?>">
                          <button type="submit" class="btn btn-outline-secondary"><?= h($label) ?></button>
                        </form>
                      <?php endif; ?>
                    <?php endforeach; ?>
                    <form method="post" action="/admin/matchday_staffing_action.php" class="d-inline" data-confirm="Remove this staffing assignment?" data-confirm-action="Remove">
                      <?= csrf_field() ?>
                      <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                      <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                      <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                      <input type="hidden" name="action" value="delete">
                      <button type="submit" class="btn btn-outline-danger" aria-label="Remove staffing assignment"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <form method="post" action="/admin/matchday_staffing_action.php" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
      <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
      <input type="hidden" name="action" value="save">
      <div class="col-md-3">
        <label class="form-label small" for="staffRoleKey">Role</label>
        <select class="form-select form-select-sm" id="staffRoleKey" name="role_key" required>
          <?php foreach ($staffingRoles as $roleKey => $roleLabel): ?>
            <option value="<?= h((string) $roleKey) ?>"><?= h((string) $roleLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small" for="staffPersonId">Person</label>
        <select class="form-select form-select-sm" id="staffPersonId" name="person_id">
          <option value="0">Unassigned</option>
          <?php foreach ($staffingPeopleOptions as $personOption): ?>
            <option value="<?= (int) $personOption['id'] ?>"><?= h((string) $personOption['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small" for="staffReportTime">Report time</label>
        <input type="time" class="form-control form-control-sm" id="staffReportTime" name="report_time">
      </div>
      <div class="col-md-2">
        <label class="form-label small" for="staffStatus">Status</label>
        <select class="form-select form-select-sm" id="staffStatus" name="status">
          <?php foreach ($staffingStatuses as $statusKey => $statusLabel): ?>
            <option value="<?= h((string) $statusKey) ?>"><?= h((string) $statusLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small" for="staffNotes">Notes</label>
        <input type="text" class="form-control form-control-sm" id="staffNotes" name="notes" maxlength="255">
      </div>
      <div class="col-12 text-end">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add role</button>
      </div>
    </form>
  </div>
</div>
<?php endif; /* $showMatchdayStaffing */ ?>

<?php if ($showMatchdayChecklist): ?>
<?php
$checklistDoneCount = 0;
foreach ($checklistItems as $checklistItem) {
  if ($checklistItem['checked']) {
    $checklistDoneCount++;
  }
}
$checklistTotalCount = count($checklistItems);
csrf_field(); // Ensure $_SESSION['csrf_token'] exists before this is read below.
?>
<div class="card shadow-sm mb-4" id="matchChecklistCard" data-fixture-id="<?= (int)$fixture['id'] ?>" data-csrf-token="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="small fw-semibold text-uppercase text-muted mb-1">Matchday</div>
        <h2 class="h4 mb-0">Checklist</h2>
      </div>
      <span class="badge text-bg-light match-checklist-progress" id="matchChecklistProgress"><?= $checklistDoneCount ?> / <?= $checklistTotalCount ?> done</span>
    </div>
    <ul class="match-checklist-list" id="matchChecklistList">
      <?php foreach ($checklistItems as $checklistItem): ?>
        <?php
        $isCustomItem = !empty($checklistItem['is_custom']);
        $itemDomId = $isCustomItem ? 'custom-' . (int)$checklistItem['id'] : 'system-' . (string)$checklistItem['key'];
        ?>
        <li class="match-checklist-item<?= $checklistItem['checked'] ? ' is-checked' : '' ?>"
            data-checklist-item
            data-kind="<?= $isCustomItem ? 'custom' : 'system' ?>"
            <?= $isCustomItem ? 'data-id="' . (int)$checklistItem['id'] . '"' : 'data-item-key="' . h((string)$checklistItem['key']) . '"' ?>>
          <label class="match-checklist-item__label" for="checklist-<?= h($itemDomId) ?>">
            <input type="checkbox" class="form-check-input" id="checklist-<?= h($itemDomId) ?>"
                   <?= $checklistItem['checked'] ? 'checked' : '' ?>
                   <?= !empty($checklistItem['auto']) ? 'disabled' : '' ?>>
            <span class="match-checklist-item__text"><?= h($checklistItem['label']) ?></span>
          </label>
          <?php if (!empty($checklistItem['auto'])): ?>
            <span class="badge text-bg-success-subtle text-success-emphasis match-checklist-item__auto-badge" title="Detected automatically from posted graphics">Auto</span>
          <?php endif; ?>
          <?php if ($isCustomItem): ?>
            <button type="button" class="match-checklist-item__delete" data-checklist-delete aria-label="Remove checklist item"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if ($checklistItems === []): ?>
        <li class="text-muted small" id="matchChecklistEmpty">Nothing on the checklist yet.</li>
      <?php endif; ?>
    </ul>
    <form class="d-flex gap-2 match-checklist-add" id="matchChecklistAddForm">
      <input type="text" class="form-control" id="matchChecklistNewLabel" placeholder="Add a matchday task…" maxlength="255">
      <button class="btn btn-outline-secondary text-nowrap" type="submit"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add</button>
    </form>
    <div class="alert alert-danger d-none mt-3 mb-0" id="matchChecklistStatus" role="status"></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const card = document.getElementById('matchChecklistCard');
  if (!card) return;
  const fixtureId = card.dataset.fixtureId;
  const csrfToken = card.dataset.csrfToken;
  const list = document.getElementById('matchChecklistList');
  const progress = document.getElementById('matchChecklistProgress');
  const status = document.getElementById('matchChecklistStatus');
  const addForm = document.getElementById('matchChecklistAddForm');
  const newLabelInput = document.getElementById('matchChecklistNewLabel');
  const emptyRow = document.getElementById('matchChecklistEmpty');

  const setStatus = (message) => {
    if (!message) {
      status.classList.add('d-none');
      status.textContent = '';
      return;
    }
    status.classList.remove('d-none');
    status.textContent = message;
  };

  const updateProgress = () => {
    const items = Array.from(list.querySelectorAll('[data-checklist-item]'));
    const done = items.filter((item) => item.classList.contains('is-checked')).length;
    progress.textContent = `${done} / ${items.length} done`;
  };

  const post = async (payload) => {
    const body = new URLSearchParams({ fixture_id: fixtureId, csrf_token: csrfToken, ...payload });
    const response = await fetch('/admin/match_checklist_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
    const text = await response.text();
    let result = null;
    try {
      result = text ? JSON.parse(text) : null;
    } catch (error) {
      throw new Error('The checklist could not be updated. Please try again.');
    }
    if (!response.ok || !result || !result.ok) {
      throw new Error((result && result.error) || 'The checklist could not be updated.');
    }
    return result;
  };

  list.addEventListener('change', async (event) => {
    const checkbox = event.target.closest('input[type="checkbox"]');
    if (!checkbox || checkbox.disabled) return;
    const item = checkbox.closest('[data-checklist-item]');
    const checked = checkbox.checked;
    setStatus('');
    try {
      if (item.dataset.kind === 'custom') {
        await post({ action: 'toggle_custom', id: item.dataset.id, checked: checked ? '1' : '0' });
      } else {
        await post({ action: 'toggle_system', item_key: item.dataset.itemKey, checked: checked ? '1' : '0' });
      }
      item.classList.toggle('is-checked', checked);
      updateProgress();
    } catch (error) {
      checkbox.checked = !checked;
      setStatus(error.message);
    }
  });

  list.addEventListener('click', async (event) => {
    const deleteButton = event.target.closest('[data-checklist-delete]');
    if (!deleteButton) return;
    const item = deleteButton.closest('[data-checklist-item]');
    setStatus('');
    try {
      await post({ action: 'delete_custom', id: item.dataset.id });
      item.remove();
      updateProgress();
      if (!list.querySelector('[data-checklist-item]') && emptyRow) {
        emptyRow.classList.remove('d-none');
      }
    } catch (error) {
      setStatus(error.message);
    }
  });

  addForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const label = newLabelInput.value.trim();
    if (!label) return;
    setStatus('');
    const submitButton = addForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
      const result = await post({ action: 'add_custom', label });
      emptyRow?.remove();
      const li = document.createElement('li');
      li.className = 'match-checklist-item';
      li.dataset.checklistItem = '';
      li.dataset.kind = 'custom';
      li.dataset.id = String(result.id);
      li.innerHTML = `
        <label class="match-checklist-item__label" for="checklist-custom-${result.id}">
          <input type="checkbox" class="form-check-input" id="checklist-custom-${result.id}">
          <span class="match-checklist-item__text"></span>
        </label>
        <button type="button" class="match-checklist-item__delete" data-checklist-delete aria-label="Remove checklist item"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      `;
      li.querySelector('.match-checklist-item__text').textContent = result.label;
      list.appendChild(li);
      newLabelInput.value = '';
      updateProgress();
    } catch (error) {
      setStatus(error.message);
    } finally {
      submitButton.disabled = false;
      newLabelInput.focus();
    }
  });
});
</script>
<?php endif; /* $showMatchdayChecklist */ ?>

<div class="row g-4 mb-4">
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm h-100">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
          <div>
            <div class="small fw-semibold text-uppercase text-muted mb-1"><?= $overviewFinished ? 'Match report' : 'Match preview' ?></div>
            <h2 class="h4 mb-0"><?= $overviewFinished ? 'Match overview' : 'Fixture overview' ?></h2>
          </div>
          <?php if ($fixture['half_time_home_score'] !== null && $fixture['half_time_away_score'] !== null): ?>
            <span class="badge text-bg-light">HT <?= (int)$fixture['half_time_home_score'] ?>–<?= (int)$fixture['half_time_away_score'] ?></span>
          <?php endif; ?>
        </div>
        <p class="lead fs-6 mb-4"><?= h(matchOverviewNarrative($fixture, $overviewEvents)) ?></p>

        <?php if ($overviewFinished && $overviewEvents !== []): ?>
          <div class="row row-cols-2 row-cols-md-5 g-2">
            <?php foreach ($overviewEventLabels as $statKey => $statLabel): ?>
              <div class="col">
                <div class="match-overview-stat h-100">
                  <span class="match-overview-stat__value"><?= (int)($overviewStats[$statKey] ?? 0) ?></span>
                  <span class="small text-muted"><?= h($statLabel) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif (!$overviewFinished && trim((string)($fixture['notes'] ?? '')) !== ''): ?>
          <div class="rounded-3 bg-body-tertiary p-3">
            <div class="small fw-semibold text-uppercase text-muted mb-1">Fixture notes</div>
            <?= nl2br(h((string)$fixture['notes'])) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body p-4 match-overview-weather" id="matchOverviewWeather" data-weather-url="/admin/match_weather.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;v=2">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
          <div>
            <h2 class="h4 mb-1">Weather Forcast</h2>
            <div class="small text-muted mb-3">
              <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><span data-weather-location><?= h($overviewVenueName) ?></span>
            </div>
          </div>
          <i class="fa-solid fa-cloud-sun fs-3 text-primary" aria-hidden="true"></i>
        </div>
        <div class="d-flex align-items-center gap-2 text-muted" data-weather-loading>
          <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
          Loading weather…
        </div>
        <div class="d-none" data-weather-content>
          <div class="d-flex align-items-end gap-3 mb-3">
            <div class="match-overview-weather__temperature"><span data-weather-temperature></span>°</div>
            <div class="pb-1">
              <div class="fw-semibold" data-weather-description></div>
              <div class="small text-muted"><span data-weather-kind></span> for <span data-weather-time></span></div>
            </div>
          </div>
          <div class="row row-cols-2 g-2 small">
            <div class="col"><strong data-weather-feels></strong><br><span class="text-muted">Feels like</span></div>
            <div class="col"><strong data-weather-rain></strong><br><span class="text-muted">Rain chance</span></div>
            <div class="col"><strong data-weather-wind></strong><br><span class="text-muted">Wind</span></div>
            <div class="col"><strong data-weather-gusts></strong><br><span class="text-muted">Gusts</span></div>
          </div>
        </div>
        <div class="alert alert-light border mb-0 d-none" data-weather-error></div>
        <div class="small text-muted mt-3">Weather data: <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-12 col-xl-7">
    <div class="card shadow-sm h-100">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <div class="small fw-semibold text-uppercase text-muted mb-1">Team sheet</div>
            <h2 class="h4 mb-0">Starting XI</h2>
          </div>
          <a class="btn btn-sm btn-outline-secondary" href="match.php?id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>&amp;tab=starting11">Edit lineup</a>
        </div>
        <?php if ($overviewStarters === []): ?>
          <p class="text-muted mb-0">No starting lineup has been selected yet.</p>
        <?php else: ?>
          <div class="match-overview-lineup">
            <?php foreach ($overviewStarters as $player): ?>
              <div class="match-overview-player">
                <span class="match-overview-player__number"><?= (int)($overviewSquadNumbers[$player] ?? 0) ?: '–' ?></span>
                <span class="text-truncate fw-semibold"><?= h(matchStarting11PublicPlayerName($pdo, $player)) ?></span>
                <?php if ($player === $overviewCaptain): ?><span class="badge text-bg-warning ms-auto">C</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card shadow-sm h-100">
      <div class="card-body p-4">
        <div class="small fw-semibold text-uppercase text-muted mb-1">Bench</div>
        <h2 class="h4 mb-3">Substitutes</h2>
        <?php if ($overviewSubstitutes === []): ?>
          <p class="text-muted mb-0">No substitutes have been selected yet.</p>
        <?php else: ?>
          <div class="d-grid gap-2">
            <?php foreach ($overviewSubstitutes as $player): ?>
              <div class="match-overview-player">
                <span class="match-overview-player__number"><?= (int)($overviewSquadNumbers[$player] ?? 0) ?: '–' ?></span>
                <span class="text-truncate fw-semibold"><?= h(matchStarting11PublicPlayerName($pdo, $player)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($overviewFinished): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <div class="small fw-semibold text-uppercase text-muted mb-1">From Match Graphics</div>
          <h2 class="h4 mb-0">Match timeline</h2>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="match_graphics.php?fixture_id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>">Open Match Graphics</a>
      </div>
      <?php if ($overviewEvents === []): ?>
        <p class="text-muted mb-0">No events were logged for this match.</p>
      <?php else: ?>
        <div class="match-overview-timeline">
          <?php foreach ($overviewEvents as $event): ?>
            <div class="match-overview-timeline__event">
              <div class="fw-semibold"><?= h(matchOverviewEventTitle($event, $overviewOpponent)) ?></div>
              <div class="small text-muted">
                <?= trim((string)($event['minute'] ?? '')) !== '' ? h((string)$event['minute']) . '\'' : 'Match event' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var weather = document.getElementById('matchOverviewWeather');
  if (!weather || weather.dataset.loaded === '1') return;
  weather.dataset.loaded = '1';
  var loading = weather.querySelector('[data-weather-loading]');
  var content = weather.querySelector('[data-weather-content]');
  var error = weather.querySelector('[data-weather-error]');
  var setText = function (selector, value) {
    var element = weather.querySelector(selector);
    if (element) element.textContent = value;
  };

  fetch(weather.dataset.weatherUrl, { headers: { Accept: 'application/json' } })
    .then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (!response.ok || !json.ok) throw new Error(json.message || 'Weather data is temporarily unavailable.');
        return json;
      });
    })
    .then(function (data) {
      setText('[data-weather-temperature]', Math.round(Number(data.temperature)));
      setText('[data-weather-description]', data.description || 'Match-day conditions');
      setText('[data-weather-kind]', data.observed ? 'Conditions' : 'Forecast');
      setText('[data-weather-time]', data.time || 'kick-off');
      setText('[data-weather-location]', data.location || 'the venue');
      setText('[data-weather-feels]', Math.round(Number(data.feels_like)) + '°C');
      setText('[data-weather-rain]', data.precipitation_probability === null ? '—' : Math.round(Number(data.precipitation_probability)) + '%');
      setText('[data-weather-wind]', data.wind_speed === null ? '—' : Math.round(Number(data.wind_speed)) + ' mph');
      setText('[data-weather-gusts]', data.wind_gusts === null ? '—' : Math.round(Number(data.wind_gusts)) + ' mph');
      loading.classList.add('d-none');
      content.classList.remove('d-none');
    })
    .catch(function (problem) {
      loading.classList.add('d-none');
      error.textContent = problem.message || 'Weather data is temporarily unavailable.';
      error.classList.remove('d-none');
    });
});
</script>
