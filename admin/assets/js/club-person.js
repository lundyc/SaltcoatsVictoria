document.addEventListener('DOMContentLoaded', () => {
  const page = document.querySelector('[data-club-person-page]');
  if (!page) return;

  const csrfToken = page.dataset.csrfToken || '';
  const personId = page.dataset.personId || '';
  const positionTable = document.querySelector('[data-people-position-table]');
  const relationshipTable = document.querySelector('[data-people-relationship-table]');
  const positionOptionsSource = document.querySelector('[data-people-ajax="position-add"] select[name="position_id"]');

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const setStatus = (message, tone = 'success') => {
    let status = document.querySelector('[data-people-inline-status]');
    if (!status) {
      status = document.createElement('div');
      status.setAttribute('data-people-inline-status', '');
      status.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
      const tabs = document.getElementById('clubPersonTabs');
      if (tabs) tabs.insertAdjacentElement('afterend', status);
    }
    status.className = 'alert alert-' + tone + ' py-2 mt-0 mb-3';
    status.textContent = message;
  };

  const submitAjax = async (form, submitter = null) => {
    const data = new FormData(form);
    data.set('ajax', '1');
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);

    const button = submitter || form.querySelector('button[type="submit"]');
    window.hubSetBusy?.(button, true);
    try {
      const response = await fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data,
      });
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        const body = await response.text();
        const loginRedirect = response.url && response.url.includes('login.php');
        if (loginRedirect) {
          throw new Error('Your session has expired. Reload the page and sign in again.');
        }
        throw new Error('The server returned HTML instead of JSON. Reload the page and try again.');
      }
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'The update could not be saved.');
      }
      return payload;
    } finally {
      window.hubSetBusy?.(button, false);
    }
  };

  const optionHtml = (source, selectedValue) => {
    if (!source) return '';
    return Array.from(source.options).map((option) => {
      const selected = String(option.value) === String(selectedValue) ? ' selected' : '';
      return `<option value="${escapeHtml(option.value)}"${selected}>${escapeHtml(option.textContent)}</option>`;
    }).join('');
  };

  const formatUkDate = (value, fallback = '—') => {
    if (!value) return fallback;
    const parts = String(value).split('-');
    if (parts.length !== 3) return value;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  };

  const setPositionRowEditing = (row, editing) => {
    row.classList.toggle('is-editing', editing);
    row.querySelectorAll('[data-view-field]').forEach((item) => { item.hidden = editing; });
    row.querySelectorAll('.people-inline-edit-control').forEach((item) => { item.hidden = !editing; });
    row.querySelector('[data-position-edit]').hidden = editing;
    row.querySelector('[data-position-save]').hidden = !editing;
    row.querySelector('[data-position-cancel]').hidden = !editing;
    row.querySelector('.people-inline-delete-form').hidden = editing;
  };

  const updatePositionRow = (row, position) => {
    row.dataset.positionRow = position.id;
    row.querySelector('[data-view-field="position"]').textContent = position.position_name || '';
    row.querySelector('[data-view-field="start_date"]').textContent = formatUkDate(position.start_date);
    row.querySelector('[data-view-field="end_date"]').textContent = formatUkDate(position.end_date, 'Ongoing');
    row.querySelector('[data-view-field="notes"]').textContent = position.notes && String(position.notes).trim() ? position.notes : '—';
    row.querySelector('[data-edit-field="position_id"]').value = String(position.position_id || '');
    row.querySelector('[data-edit-field="start_date"]').value = position.start_date || '';
    row.querySelector('[data-edit-field="end_date"]').value = position.end_date || '';
    row.querySelector('[data-edit-field="notes"]').value = position.notes || '';
    row.querySelector('input[name="position_row_id"]').value = String(position.id);
    setPositionRowEditing(row, false);
  };

  const positionRowHtml = (position) => {
    const rowId = String(position.id);
    const formId = 'positionEditForm' + rowId;
    return `
      <tr data-position-row="${escapeHtml(rowId)}">
        <td data-label="Position">
          <span data-view-field="position">${escapeHtml(position.position_name || '')}</span>
          <select class="form-select form-select-sm people-inline-edit-control" name="position_id" form="${escapeHtml(formId)}" data-edit-field="position_id" hidden>${optionHtml(positionOptionsSource, position.position_id)}</select>
        </td>
        <td data-label="Start date">
          <span data-view-field="start_date">${escapeHtml(formatUkDate(position.start_date))}</span>
          <input class="form-control form-control-sm people-inline-edit-control" type="date" name="start_date" form="${escapeHtml(formId)}" data-edit-field="start_date" value="${escapeHtml(position.start_date || '')}" required hidden>
        </td>
        <td data-label="End date">
          <span data-view-field="end_date">${escapeHtml(formatUkDate(position.end_date, 'Ongoing'))}</span>
          <input class="form-control form-control-sm people-inline-edit-control" type="date" name="end_date" form="${escapeHtml(formId)}" data-edit-field="end_date" value="${escapeHtml(position.end_date || '')}" hidden>
        </td>
        <td data-label="Notes">
          <span data-view-field="notes">${escapeHtml(position.notes && String(position.notes).trim() ? position.notes : '—')}</span>
          <input class="form-control form-control-sm people-inline-edit-control" name="notes" form="${escapeHtml(formId)}" data-edit-field="notes" value="${escapeHtml(position.notes || '')}" placeholder="Optional" hidden>
        </td>
        <td class="text-end people-inline-actions" data-label="Actions">
          <form method="post" id="${escapeHtml(formId)}" data-people-ajax="position-update" hidden>
            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
            <input type="hidden" name="person_id" value="${escapeHtml(personId)}">
            <input type="hidden" name="ajax_action" value="update_position">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="position_row_id" value="${escapeHtml(rowId)}">
          </form>
          <button class="btn btn-outline-primary btn-sm" type="button" data-position-edit>Edit</button>
          <button class="btn btn-brand btn-sm" type="submit" form="${escapeHtml(formId)}" data-position-save hidden>Save</button>
          <button class="btn btn-outline-secondary btn-sm" type="button" data-position-cancel hidden>Cancel</button>
          <form method="post" class="people-inline-delete-form" data-people-ajax="position-delete" data-confirm="This permanently removes this position entry." data-confirm-title="Remove this position?" data-confirm-action="Remove">
            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
            <input type="hidden" name="person_id" value="${escapeHtml(personId)}">
            <input type="hidden" name="ajax_action" value="delete_position">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="position_row_id" value="${escapeHtml(rowId)}">
            <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
          </form>
        </td>
      </tr>`;
  };

  const insertPositionRow = (position) => {
    const tbody = positionTable.querySelector('tbody');
    const emptyRow = positionTable.querySelector('[data-people-empty-positions]');
    const newStart = String(position.start_date || '');
    const rows = Array.from(tbody.querySelectorAll('tr[data-position-row]'));
    const beforeRow = rows.find((row) => {
      const value = row.querySelector('[data-edit-field="start_date"]')?.value || '';
      return value < newStart;
    });
    (beforeRow || emptyRow).insertAdjacentHTML('beforebegin', positionRowHtml(position));
  };

  const relationshipRowHtml = (relationship) => {
    const rowId = String(relationship.relationship_id || relationship.id);
    return `
      <tr data-relationship-row="${escapeHtml(rowId)}">
        <td data-label="Dependant">${escapeHtml(relationship.display_name || '')}</td>
        <td data-label="Email">${escapeHtml(relationship.email || '—')}</td>
        <td class="text-end people-inline-actions" data-label="Actions">
          <form method="post" class="people-inline-delete-form" data-people-ajax="relationship-delete" data-confirm="This removes the dependant relationship. Person records and ticket history are preserved." data-confirm-title="Remove this dependant?" data-confirm-action="Remove">
            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
            <input type="hidden" name="person_id" value="${escapeHtml(personId)}">
            <input type="hidden" name="ajax_action" value="remove_relationship">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="relationship_id" value="${escapeHtml(rowId)}">
            <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
          </form>
        </td>
      </tr>`;
  };

  const applyAjaxResult = (form, payload) => {
    if (form.dataset.peopleAjax === 'position-add') {
      positionTable.querySelector('[data-people-empty-positions]').hidden = true;
      insertPositionRow(payload.position);
      form.reset();
    } else if (form.dataset.peopleAjax === 'position-update') {
      const row = document.querySelector(`[data-position-row="${CSS.escape(String(payload.position.id))}"]`);
      if (row) updatePositionRow(row, payload.position);
    } else if (form.dataset.peopleAjax === 'position-delete') {
      const row = form.closest('tr');
      row?.remove();
      if (!positionTable.querySelector('tbody tr[data-position-row]')) {
        positionTable.querySelector('[data-people-empty-positions]').hidden = false;
      }
    } else if (form.dataset.peopleAjax === 'relationship-add') {
      relationshipTable.querySelector('[data-people-empty-relationships]').hidden = true;
      const emptyRow = relationshipTable.querySelector('[data-people-empty-relationships]');
      emptyRow.insertAdjacentHTML('beforebegin', relationshipRowHtml(payload.relationship));
      form.reset();
    } else if (form.dataset.peopleAjax === 'relationship-delete') {
      const row = form.closest('tr');
      row?.remove();
      if (!relationshipTable.querySelector('tbody tr[data-relationship-row]')) {
        relationshipTable.querySelector('[data-people-empty-relationships]').hidden = false;
      }
    }
    setStatus(payload.message || 'Saved.');
  };

  const handleAjaxForm = async (form, submitter = null) => {
    if (form.hasAttribute('data-confirm')) {
      const message = form.getAttribute('data-confirm') || 'This action cannot be undone.';
      if (!window.confirm(message)) return;
    }

    try {
      const payload = await submitAjax(form, submitter);
      applyAjaxResult(form, payload);
    } catch (error) {
      setStatus(error.message || 'The update could not be saved.', 'danger');
    }
  };

  document.addEventListener('click', (event) => {
    const ajaxSubmitButton = event.target.closest('button[type="submit"]');
    const ajaxForm = ajaxSubmitButton?.form;
    if (ajaxForm instanceof HTMLFormElement && ajaxForm.dataset.peopleAjax) {
      event.preventDefault();
      event.stopImmediatePropagation();
      handleAjaxForm(ajaxForm, ajaxSubmitButton);
      return;
    }

    const editButton = event.target.closest('[data-position-edit]');
    if (editButton) {
      setPositionRowEditing(editButton.closest('tr'), true);
      return;
    }
    const cancelButton = event.target.closest('[data-position-cancel]');
    if (cancelButton) {
      setPositionRowEditing(cancelButton.closest('tr'), false);
    }
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.peopleAjax) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    handleAjaxForm(form, event.submitter || null);
  }, true);
});
