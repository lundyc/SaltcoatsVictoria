window.sponsorWorkspaceInit = () => {
    'use strict';
    const source = document.getElementById('workspaceData');
    if (!source) return;
    const data = JSON.parse(source.textContent);
    const agreements = data.agreements;
    const money = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
    const search = document.getElementById('workspaceSearch');
    const season = document.getElementById('workspaceSeason');
    const status = document.getElementById('workspaceStatus');
    const storageKey = 'sponsor-workspace:' + new URLSearchParams(location.search).get('id');
    try {
        const filters = JSON.parse(sessionStorage.getItem(storageKey));
        if (filters) { search.value = filters.search || ''; season.value = filters.season || ''; status.value = filters.status || ''; }
    } catch (_) { /* Filters are optional when storage is unavailable. */ }
    const filter = () => {
        let count = 0, total = 0, paid = 0, outstanding = 0;
        document.querySelectorAll('[data-workspace-row]').forEach(row => {
            const a = agreements[row.dataset.workspaceRow];
            const matches = (!season.value || (season.value === 'none' ? !a.season_id : String(a.season_id) === season.value))
                && (!status.value || (status.value === 'outstanding' ? a.balance > 0 : a.balance <= 0))
                && (a.package_name + ' ' + a.target_label + ' ' + (a.season_name || '')).toLowerCase().includes(search.value.trim().toLowerCase());
            row.hidden = !matches;
            if (matches) { count++; total += Number(a.agreed_amount); paid += Number(a.total_paid); outstanding += Number(a.balance); }
        });
        document.getElementById('workspaceTotal').textContent = money.format(total);
        document.getElementById('workspacePaid').textContent = money.format(paid);
        document.getElementById('workspaceOutstanding').textContent = money.format(outstanding);
        document.getElementById('workspaceCount').textContent = `${count} agreement${count === 1 ? '' : 's'} · totals include all shown agreements`;
        document.getElementById('workspaceEmpty').hidden = count > 0;
        try { sessionStorage.setItem(storageKey, JSON.stringify({ search: search.value, season: season.value, status: status.value })); } catch (_) {}
    };
    [search, season, status].forEach(el => el.addEventListener('input', filter));
    filter();

    const agreementModal = document.getElementById('workspaceAgreementModal');
    const agreementForm = document.getElementById('workspaceAgreementForm');
    const paymentModal = document.getElementById('workspacePaymentModal');
    const paymentForm = document.getElementById('workspacePaymentForm');
    const setFields = (form, values) => {
        Object.entries(values).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (!field || name === 'csrf_token') return;
            if (field.type === 'checkbox') field.checked = String(value) === '1';
            else field.value = value == null ? '' : value;
        });
    };
    const clearError = form => { const error = form.querySelector('.workspace-form-error'); error.hidden = true; error.textContent = ''; };
    let editingAgreement = null;
    const syncScope = () => {
        if (!agreementForm) return;
        const packageSelect = agreementForm.elements.package_id;
        const scope = packageSelect.selectedOptions[0]?.dataset.scope || '';
        agreementForm.querySelectorAll('[data-scope-field]').forEach(wrapper => {
            wrapper.hidden = wrapper.dataset.scopeField !== scope;
            const field = wrapper.querySelector('select');
            field.disabled = wrapper.hidden;
            field.required = !wrapper.hidden;
        });
        agreementForm.elements.season_id.required = scope === 'player';
        document.getElementById('workspaceComplimentaryWrap').hidden = scope !== 'match';
        agreementForm.elements.is_complimentary.disabled = scope !== 'match';
        const complimentary = scope === 'match' && agreementForm.elements.is_complimentary.checked;
        agreementForm.elements.agreed_amount.readOnly = complimentary;
        if (complimentary) agreementForm.elements.agreed_amount.value = '0.00';
        const selectedSeason = agreementForm.elements.season_id.value;
        Array.from(agreementForm.elements.fixture_id.options).forEach(option => {
            option.hidden = !!option.value && !!selectedSeason && option.dataset.season !== selectedSeason && option.value !== String(editingAgreement?.fixture_id || '');
            option.disabled = option.hidden;
        });
    };
    const setupAgreement = id => {
        agreementForm.reset(); clearError(agreementForm);
        editingAgreement = agreements[id] || null;
        document.getElementById('workspaceAgreementTitle').textContent = editingAgreement ? 'Edit agreement' : 'Add agreement';
        const selectedSeason = Array.from(agreementForm.elements.season_id.options).find(o => o.value === String(data.seasonId));
        setFields(agreementForm, editingAgreement ? { ...editingAgreement, agreement_id: id } : {
            agreement_id: 0, season_id: data.seasonId, agreed_amount: '0.00', status: 'active',
            start_date: selectedSeason?.dataset.start || data.today, end_date: selectedSeason?.dataset.end || ''
        });
        Array.from(agreementForm.elements.package_id.options).forEach(option => {
            option.disabled = (!!editingAgreement?.legacy_source && option.value !== String(editingAgreement.package_id))
                || (option.dataset.active === '0' && option.value !== String(editingAgreement?.package_id || ''));
        });
        syncScope();
    };
    if (agreementModal) {
        agreementModal.addEventListener('show.bs.modal', event => { if (event.relatedTarget) setupAgreement(event.relatedTarget.dataset.agreementId); });
        agreementForm.elements.package_id.addEventListener('change', () => {
            if (!editingAgreement) agreementForm.elements.agreed_amount.value = agreementForm.elements.package_id.selectedOptions[0]?.dataset.amount || '0.00';
            syncScope();
        });
        agreementForm.elements.season_id.addEventListener('change', () => {
            if (!editingAgreement) {
                const selected = agreementForm.elements.season_id.selectedOptions[0];
                agreementForm.elements.start_date.value = selected?.dataset.start || '';
                agreementForm.elements.end_date.value = selected?.dataset.end || '';
                agreementForm.elements.fixture_id.value = '';
            }
            syncScope();
        });
        agreementForm.elements.fixture_id.addEventListener('change', () => {
            const selected = agreementForm.elements.fixture_id.selectedOptions[0];
            if (!selected?.value) return;
            agreementForm.elements.season_id.value = selected.dataset.season;
            if (!editingAgreement) {
                agreementForm.elements.start_date.value = selected.dataset.date;
                agreementForm.elements.end_date.value = selected.dataset.date;
            }
        });
        agreementForm.elements.is_complimentary.addEventListener('change', syncScope);
    }
    const setupPayment = (id, action, paymentId) => {
        paymentForm.reset(); clearError(paymentForm);
        const a = agreements[id];
        if (!a) return;
        const payment = a.payments.find(p => String(p.id) === String(paymentId));
        const title = action === 'mark_paid' ? 'Mark agreement paid' : (action === 'edit_payment' ? 'Edit payment' : 'Record payment');
        document.getElementById('workspacePaymentTitle').textContent = title;
        document.getElementById('workspacePaymentLabel').textContent = a.package_name + ' · ' + a.target_label;
        document.getElementById('workspacePaymentHelp').textContent = action === 'mark_paid'
            ? `Record the remaining ${money.format(a.balance)} as received. Choose the date and payment method below.`
            : `Outstanding: ${money.format(a.balance)}. Save the full balance to mark this agreement paid, or enter a smaller amount for a part payment.`;
        document.getElementById('workspacePaymentSubmit').textContent = action === 'mark_paid' ? 'Confirm paid' : 'Save payment';
        setFields(paymentForm, { workspace_action: action, agreement_id: id, payment_id: paymentId || '',
            amount: payment ? payment.amount : (a.balance > 0 ? Number(a.balance).toFixed(2) : ''),
            paid_at: payment ? payment.paid_at.slice(0, 10) : data.today, method: payment?.method || '', note: payment?.note || '' });
        paymentForm.elements.amount.readOnly = action === 'mark_paid';
    };
    if (paymentModal) paymentModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        if (button) setupPayment(button.dataset.agreementId, button.dataset.paymentAction, button.dataset.paymentId);
    });
    let saving = false;
    const submitLive = async (formData, form = null, button = null) => {
        if (saving) return;
        saving = true;
        if (button) button.disabled = true;
        const error = form?.querySelector('.workspace-form-error') || document.getElementById('workspaceLiveError');
        error.hidden = true;
        try {
            const response = await fetch('sponsor.php?id=' + encodeURIComponent(new URLSearchParams(location.search).get('id')), {
                method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Could not save the change.');
            const openModal = [agreementModal, paymentModal].find(el => el?.classList.contains('show'));
            if (openModal) {
                await new Promise(resolve => {
                    openModal.addEventListener('hidden.bs.modal', resolve, { once: true });
                    bootstrap.Modal.getOrCreateInstance(openModal).hide();
                });
            }
            [agreementModal, paymentModal].filter(Boolean).forEach(el => bootstrap.Modal.getInstance(el)?.dispose());
            document.getElementById('workspaceContent').innerHTML = result.html;
            if (typeof result.sponsor_active === 'boolean') {
                const statusLabel = document.getElementById('sponsorStatus');
                if (statusLabel) statusLabel.textContent = result.sponsor_active ? 'Active' : 'Archived';
                const archiveButton = document.getElementById('archiveSponsorButton');
                if (archiveButton) {
                    const label = result.sponsor_active ? 'Archive sponsor' : 'Restore sponsor';
                    archiveButton.dataset.action = result.sponsor_active ? 'archive_sponsor' : 'restore_sponsor';
                    archiveButton.title = label;
                    archiveButton.setAttribute('aria-label', label);
                    archiveButton.querySelector('i').className = 'fa-solid ' + (result.sponsor_active ? 'fa-box-archive' : 'fa-rotate-left');
                }
            }
            const count = document.querySelector('#agreementsTab .badge');
            if (count) count.textContent = result.count;
            window.sponsorWorkspaceInit();
        } catch (failure) {
            error.textContent = failure instanceof SyntaxError ? 'Could not read the response. Check the agreement before retrying, or sign in again if your session expired.' : failure.message;
            error.hidden = false;
            error.scrollIntoView({ block: 'nearest' });
        } finally {
            saving = false;
            if (button) button.disabled = false;
        }
    };
    const confirmChange = (message, label) => window.hubConfirm ? window.hubConfirm(message, { actionLabel: label }) : Promise.resolve(window.confirm(message));
    const archiveSponsorButton = document.getElementById('archiveSponsorButton');
    if (archiveSponsorButton && !archiveSponsorButton.dataset.bound) {
        archiveSponsorButton.dataset.bound = '1';
        archiveSponsorButton.addEventListener('click', async () => {
            const restoring = archiveSponsorButton.dataset.action === 'restore_sponsor';
            const message = restoring ? 'Restore this sponsor to the active list?' : 'Archive this sponsor? They will be hidden from the active list. All agreements, payments and notes will be kept.';
            if (!await confirmChange(message, restoring ? 'Restore sponsor' : 'Archive sponsor')) return;
            const input = new FormData();
            input.set('csrf_token', data.csrf);
            input.set('workspace_action', archiveSponsorButton.dataset.action);
            await submitLive(input, null, archiveSponsorButton);
        });
    }
    document.querySelectorAll('.workspace-confirm-form').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = event.submitter || form.querySelector('button');
        if (await confirmChange(button.dataset.workspaceConfirm, 'Remove payment')) await submitLive(new FormData(form), null, button);
    }));
    document.querySelectorAll('.workspace-delete-agreement').forEach(button => button.addEventListener('click', async () => {
        if (!await confirmChange(button.dataset.workspaceConfirm, 'Delete agreement')) return;
        const input = new FormData();
        input.set('csrf_token', data.csrf);
        input.set('workspace_action', 'delete_agreement');
        input.set('agreement_id', button.dataset.agreementId);
        await submitLive(input, null, button);
    }));
    [agreementForm, paymentForm].filter(Boolean).forEach(form => form.addEventListener('submit', event => {
        event.preventDefault();
        submitLive(new FormData(form), form, event.submitter || form.querySelector('button[type="submit"]'));
    }));
    // Preserve existing links to the retired Players / Payments tabs.
    const wanted = new URLSearchParams(location.search).get('tab') || location.hash.slice(1);
    if (wanted === 'notes') {
        const tab = document.querySelector('[data-bs-target="#notes"]');
        if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
    }
    if (data.error && data.submitted) {
        const submitted = data.submitted;
        const isAgreement = submitted.workspace_action === 'save_agreement';
        const form = isAgreement ? agreementForm : paymentForm;
        const modal = isAgreement ? agreementModal : paymentModal;
        if (form && (isAgreement || ['add_payment', 'mark_paid', 'edit_payment'].includes(submitted.workspace_action))) {
            if (isAgreement) setupAgreement(submitted.agreement_id);
            else setupPayment(submitted.agreement_id, submitted.workspace_action, submitted.payment_id);
            setFields(form, submitted);
            if (isAgreement) syncScope();
            const error = form.querySelector('.workspace-form-error'); error.textContent = data.error; error.hidden = false;
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    }
};
window.sponsorWorkspaceInit();
