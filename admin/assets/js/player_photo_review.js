(() => {
    const form = document.getElementById('reference-upload');
    const input = document.getElementById('reference-files');
    const drop = document.getElementById('reference-drop');
    const status = document.getElementById('reference-upload-status');
    const errors = document.getElementById('reference-upload-errors');
    const button = document.getElementById('reference-upload-button');
    let files = [], uploading = false, dirty = false;
    const save = document.getElementById('reference-save');
    const choose = selected => { files = Array.from(selected); status.textContent = `${files.length} image(s) selected`; };
    input.addEventListener('change', () => choose(input.files));
    drop.addEventListener('dragover', event => { event.preventDefault(); if (!uploading) drop.classList.add('is-dragging'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('is-dragging'));
    drop.addEventListener('drop', event => { event.preventDefault(); drop.classList.remove('is-dragging'); if (!uploading) { input.value = ''; choose(event.dataTransfer.files); } });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (uploading) return;
        if (!files.length) { status.textContent = 'Choose at least one image.'; return; }
        uploading = true; button.disabled = true; input.disabled = true;
        const saveButton = save?.querySelector('button[type=submit]');
        if (saveButton) saveButton.disabled = true;
        errors.replaceChildren();
        let uploaded = 0;
        const failed = [];
        for (const [index, file] of files.entries()) {
            status.textContent = `Uploading ${index + 1} of ${files.length}: ${file.name}`;
            try {
                if (file.size > 5 * 1024 * 1024) throw new Error('Image exceeds 5MB.');
                const body = new FormData();
                body.append('csrf_token', form.querySelector('[name=csrf_token]').value);
                body.append('action', 'upload'); body.append('photo', file);
                const response = await fetch('/admin/player_photo_review.php', {method: 'POST', body});
                const result = await response.json().catch(() => { throw new Error('Upload failed. Check your connection or sign in again.'); });
                if (!response.ok || !result.success) throw new Error(result.error || 'Upload failed.');
                uploaded++;
            } catch (error) {
                failed.push(file);
                const li = document.createElement('li'); li.textContent = `${file.name}: ${error.message}`; errors.append(li);
            }
        }
        status.textContent = `${uploaded} image(s) uploaded. ${failed.length} failed.`;
        uploading = false; button.disabled = false; input.disabled = false;
        if (saveButton) saveButton.disabled = false;
        files = failed;
        if (uploaded) document.getElementById('reference-review-link').hidden = false;
        if (uploaded && !failed.length && !dirty) window.location.reload();
    });
    const references = JSON.parse(document.getElementById('reference-data').textContent);
    const known = document.getElementById('reference-known');
    const search = document.getElementById('reference-search');
    const options = Array.from(known.options).slice(1).map(option => ({value: option.value, text: option.text}));
    const gallery = document.getElementById('reference-known-photos');
    const knownStatus = document.getElementById('reference-known-status');
    const assign = document.getElementById('reference-assign');
    let active = null;
    function showReferences() {
        gallery.replaceChildren();
        const photos = references[known.value] || [];
        knownStatus.textContent = !known.value ? 'Choose a player to see their named photos.' : photos.length ? `${known.selectedOptions[0].text}: ${photos.length} reference photo(s)` : 'No reference photos available for this player.';
        photos.forEach(photo => {
            const link = document.createElement('a'); link.href = photo.url; link.target = '_blank'; link.rel = 'noopener';
            const img = document.createElement('img'); img.src = photo.url; img.alt = `${known.selectedOptions[0].text} — ${photo.label}`; img.loading = 'lazy';
            const label = document.createElement('span'); label.textContent = photo.label;
            link.append(img, label); gallery.append(link);
        });
        assign.disabled = !active || !known.value;
    }
    function focus(item) {
        active?.classList.remove('is-selected'); active = item; item.classList.add('is-selected');
        const image = item.querySelector('img'), preview = document.getElementById('reference-focus');
        preview.src = image.src; preview.alt = image.alt; preview.hidden = false;
        const selected = item.querySelector('select').value;
        if (selected !== '0') {
            search.value = ''; rebuildOptions(); known.value = selected;
        }
        showReferences();
    }
    function rebuildOptions() {
        const selected = known.value;
        known.replaceChildren(new Option('Choose a player', ''));
        options.filter(option => option.text.toLowerCase().includes(search.value.toLowerCase())).forEach(option => known.add(new Option(option.text, option.value)));
        known.value = Array.from(known.options).some(option => option.value === selected) ? selected : '';
    }
    search.addEventListener('input', () => { rebuildOptions(); showReferences(); });
    known.addEventListener('change', showReferences);
    const queueCount = document.getElementById('reference-queue-count');
    async function deleteQueueItem(item) {
        const queueId = item.dataset.queueId;
        const deleteButton = item.querySelector('.reference-delete');
        if (!queueId || deleteButton.disabled) return;
        if (!window.confirm('Remove this image from the review queue? This cannot be undone.')) return;
        deleteButton.disabled = true;
        try {
            const body = new FormData();
            body.append('csrf_token', form.querySelector('[name=csrf_token]').value);
            body.append('action', 'delete');
            body.append('queue_id', queueId);
            const response = await fetch('/admin/player_photo_review.php', {method: 'POST', body});
            const result = await response.json().catch(() => { throw new Error('Delete failed. Check your connection or sign in again.'); });
            if (!response.ok || !result.success) throw new Error(result.error || 'Delete failed.');
            if (active === item) {
                active = null;
                document.getElementById('reference-focus').hidden = true;
            }
            item.remove();
            if (queueCount) queueCount.textContent = String(Math.max(0, parseInt(queueCount.textContent, 10) - 1));
            if (!document.querySelector('.reference-item')) window.location.reload();
        } catch (error) {
            deleteButton.disabled = false;
            window.alert(error.message);
        }
    }
    document.querySelectorAll('.reference-item').forEach(item => {
        item.querySelector('.reference-inspect').addEventListener('click', () => focus(item));
        item.querySelector('select').addEventListener('change', () => { dirty = true; focus(item); });
        item.querySelector('.reference-delete').addEventListener('click', () => deleteQueueItem(item));
    });
    assign.addEventListener('click', () => {
        if (!active || !known.value) return;
        active.querySelector('select').value = known.value; dirty = true;
        knownStatus.textContent = `${known.selectedOptions[0].text} selected. Save selections to confirm.`;
    });
    save?.addEventListener('submit', () => { dirty = false; save.querySelector('button[type=submit]').disabled = true; });
    window.addEventListener('beforeunload', event => { if (dirty || uploading) { event.preventDefault(); event.returnValue = ''; } });
    const first = document.querySelector('.reference-item'); if (first) focus(first);
})();
