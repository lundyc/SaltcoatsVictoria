(function () {
    'use strict';

    var config = document.getElementById('mediaLibraryConfig');
    var grid = document.getElementById('mediaGrid');
    if (!config || !grid) return;

    var csrf = config.dataset.csrf || '';
    var matchCsrf = config.dataset.matchCsrf || '';
    var endpoint = config.dataset.endpoint || '/admin/match_photos.php';
    var canManage = config.dataset.canManage === '1';

    var kitLabels = window.HUB_KIT_LABELS || {};
    var newPersonCategories = window.HUB_NEW_PERSON_CATEGORIES || {};
    var items = Array.isArray(window.HUB_MEDIA_ITEMS) ? window.HUB_MEDIA_ITEMS.slice() : [];

    var empty = document.getElementById('mediaEmpty');
    var count = document.getElementById('mediaVisibleCount');
    var totalCount = document.getElementById('mediaTotalCount');
    var search = document.getElementById('mediaSearch');
    var categoryFilter = document.getElementById('mediaCategoryFilter');
    var matchFilter = document.getElementById('mediaMatchFilter');
    var personFilter = document.getElementById('mediaPersonFilter');
    var kitFilter = document.getElementById('mediaKitFilter');
    var sort = document.getElementById('mediaSort');
    var batchSize = 24;
    var filteredItems = [];
    var renderedItems = 0;
    var loadingBatch = false;

    var loadMore = document.createElement('div');
    loadMore.className = 'media-load-more';
    loadMore.innerHTML = '<button type="button" class="btn btn-outline-primary"><i class="fa-solid fa-spinner" aria-hidden="true"></i><span>Load more images</span></button>';
    grid.insertAdjacentElement('afterend', loadMore);

    var escapeHtml = function (value) {
        return String(value).replace(/[&<>'"]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char];
        });
    };
    var formatSize = function (bytes) {
        return bytes < 1024 * 1024 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1024 / 1024).toFixed(1) + ' MB';
    };
    var formatDate = function (dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    };
    var matchLabel = function (item) {
        return (item.match_date ? formatDate(item.match_date) + ' vs ' : 'vs ') + (item.opponent || '');
    };
    var toast = function (message, error) {
        var region = document.getElementById('mediaToasts');
        if (!region) return;
        var el = document.createElement('div');
        el.className = 'media-toast' + (error ? ' is-error' : '');
        el.textContent = message;
        region.appendChild(el);
        setTimeout(function () { el.remove(); }, 4200);
    };

    // === Grid rendering ===
    function itemMarkup(item) {
        var badges = '';
        if (item.is_match_photo) {
            var chips = '';
            if (item.kit) chips += '<span class="media-item__badge">' + escapeHtml(kitLabels[item.kit] || item.kit) + '</span>';
            chips += '<span class="media-item__badge media-item__badge--muted">' + (item.tags.length ? item.tags.length + ' tagged' : 'Untagged') + '</span>';
            badges = '<span class="media-item__badges">' + chips + '</span>';
        }
        var title = item.is_match_photo ? matchLabel(item) : item.filename;
        return '<button type="button" class="media-item" data-path="' + escapeHtml(item.path) + '" aria-label="Manage ' + escapeHtml(item.filename) + '">' +
            '<span class="media-item__image"><img src="' + escapeHtml(item.url) + '?v=' + item.modified + '" alt="" loading="lazy" decoding="async">' +
            '<span class="media-item__edit"><i class="fa-solid fa-pen"></i></span>' + badges + '</span>' +
            '<span class="media-item__meta"><strong class="media-item__name" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</strong>' +
            '<span class="media-item__details"><span class="media-item__category">' + escapeHtml(item.category) + '</span><span>' + item.width + '×' + item.height + '</span></span></span></button>';
    }

    function appendBatch() {
        if (loadingBatch || renderedItems >= filteredItems.length) return;
        loadingBatch = true;
        var nextItems = filteredItems.slice(renderedItems, renderedItems + batchSize);
        grid.insertAdjacentHTML('beforeend', nextItems.map(itemMarkup).join(''));
        renderedItems += nextItems.length;
        count.textContent = renderedItems;
        loadMore.hidden = renderedItems >= filteredItems.length;
        loadingBatch = false;
    }

    function populateMatchFilterOptions() {
        if (!matchFilter) return;
        var seen = {};
        var list = [];
        items.forEach(function (item) {
            if (!item.is_match_photo || seen[item.fixture_id]) return;
            seen[item.fixture_id] = true;
            list.push(item);
        });
        list.sort(function (a, b) { return (b.match_date || '').localeCompare(a.match_date || ''); });
        matchFilter.innerHTML = '<option value="">All matches</option>' + list.map(function (item) {
            return '<option value="' + item.fixture_id + '">' + escapeHtml(matchLabel(item)) + '</option>';
        }).join('');
    }

    function render() {
        var term = search.value.trim().toLowerCase();
        var categoryVal = categoryFilter.value;
        var matchVal = matchFilter ? matchFilter.value : '';
        var personVal = personFilter ? personFilter.value : '';
        var kitVal = kitFilter ? kitFilter.value : '';

        filteredItems = items.filter(function (item) {
            if (categoryVal && item.category !== categoryVal) return false;
            if (matchVal && String(item.fixture_id) !== matchVal) return false;
            if (kitVal && item.kit !== kitVal) return false;
            if (personVal && !(item.tags || []).some(function (t) { return String(t.person_id) === personVal; })) return false;
            if (term) {
                var haystack = [item.filename, item.category, item.path, item.opponent || ''].concat((item.tags || []).map(function (t) { return t.name; })).join(' ').toLowerCase();
                if (haystack.indexOf(term) === -1) return false;
            }
            return true;
        });
        filteredItems.sort(function (a, b) {
            if (sort.value === 'name') return a.filename.localeCompare(b.filename, undefined, { numeric: true });
            return sort.value === 'oldest' ? a.modified - b.modified : b.modified - a.modified;
        });

        renderedItems = 0;
        grid.innerHTML = '';
        count.textContent = '0';
        empty.hidden = filteredItems.length !== 0;
        grid.hidden = filteredItems.length === 0;
        loadMore.hidden = filteredItems.length === 0;
        appendBatch();
    }

    [search, categoryFilter, matchFilter, personFilter, kitFilter, sort].forEach(function (control) {
        if (!control) return;
        control.addEventListener(control === search ? 'input' : 'change', render);
    });
    loadMore.querySelector('button').addEventListener('click', appendBatch);
    if ('IntersectionObserver' in window) {
        var batchObserver = new IntersectionObserver(function (entries) {
            if (entries.some(function (entry) { return entry.isIntersecting; })) appendBatch();
        }, { rootMargin: '200px 0px' });
        batchObserver.observe(loadMore);
    }

    populateMatchFilterOptions();
    render();

    // === Upload ===
    var filesInput = document.getElementById('mediaFiles');
    var dropzone = document.getElementById('mediaDropzone');
    var uploadButton = document.getElementById('mediaUploadButton');
    var queue = document.getElementById('mediaUploadQueue');
    var destinationSelect = document.getElementById('mediaUploadCategory');
    var matchFieldsWrap = document.getElementById('mediaUploadMatchFields');
    var genericHint = document.getElementById('mediaUploadGenericHint');
    var fixtureSelect = document.getElementById('mediaUploadFixture');
    var uploadKitSelect = document.getElementById('mediaUploadKit');
    var uploadProgress = document.getElementById('mediaUploadProgress');
    var uploadProgressBar = document.getElementById('mediaUploadProgressBar');
    var uploadProgressLabel = document.getElementById('mediaUploadProgressLabel');
    var selectedFiles = [];

    function isMatchDestination() {
        return !destinationSelect || destinationSelect.value === 'match_photos';
    }
    function syncDestinationUI() {
        var isMatch = isMatchDestination();
        if (matchFieldsWrap) matchFieldsWrap.hidden = !isMatch;
        if (genericHint) genericHint.hidden = isMatch;
    }
    if (destinationSelect && destinationSelect.tagName === 'SELECT') {
        destinationSelect.addEventListener('change', syncDestinationUI);
    }
    syncDestinationUI();

    function addFiles(fileList) {
        var existing = {};
        selectedFiles.forEach(function (file) { existing[[file.name, file.size, file.lastModified].join(':')] = true; });
        Array.prototype.filter.call(fileList, function (file) { return file.type.indexOf('image/') === 0; }).forEach(function (file) {
            var key = [file.name, file.size, file.lastModified].join(':');
            if (!existing[key]) { selectedFiles.push(file); existing[key] = true; }
        });
        uploadButton.disabled = selectedFiles.length === 0;
        if (queue) {
            queue.hidden = selectedFiles.length === 0;
            queue.textContent = selectedFiles.length ? selectedFiles.length + ' image' + (selectedFiles.length === 1 ? '' : 's') + ' ready: ' + selectedFiles.map(function (f) { return f.name; }).join(', ') : '';
        }
    }
    filesInput.addEventListener('change', function () { addFiles(filesInput.files); filesInput.value = ''; });
    ['dragenter', 'dragover'].forEach(function (name) { dropzone.addEventListener(name, function (e) { e.preventDefault(); dropzone.classList.add('is-dragging'); }); });
    ['dragleave', 'drop'].forEach(function (name) { dropzone.addEventListener(name, function (e) { e.preventDefault(); dropzone.classList.remove('is-dragging'); }); });
    dropzone.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });

    function resetUploadButton() {
        uploadButton.disabled = false;
        uploadButton.innerHTML = 'Upload';
    }

    function uploadMatchPhotos() {
        var fixtureId = fixtureSelect ? fixtureSelect.value : '';
        var kit = uploadKitSelect ? uploadKitSelect.value : 'home';
        if (!fixtureId) {
            toast('Choose a match first', true);
            resetUploadButton();
            return;
        }
        var CHUNK_SIZE = 10;
        var chunks = [];
        for (var i = 0; i < selectedFiles.length; i += CHUNK_SIZE) chunks.push(selectedFiles.slice(i, i + CHUNK_SIZE));
        var totalFiles = selectedFiles.length;
        var uploadedSoFar = 0, taggedSoFar = 0;
        var allErrors = [];
        if (uploadProgress) {
            dropzone.classList.add('d-none');
            uploadProgress.classList.remove('d-none');
            uploadProgressBar.style.width = '0%';
            uploadProgressLabel.textContent = 'Uploading 0 of ' + totalFiles + '…';
        }

        function finish() {
            var params = ['bulk_uploaded=' + encodeURIComponent(uploadedSoFar), 'bulk_tagged=' + encodeURIComponent(taggedSoFar)];
            if (allErrors.length) params.push('bulk_errors=' + encodeURIComponent(allErrors.slice(0, 5).join(' ')));
            window.location.href = 'match_photos.php?' + params.join('&');
        }

        function uploadChunk(index) {
            if (index >= chunks.length) { finish(); return; }
            var formData = new FormData();
            formData.append('csrf_token', matchCsrf);
            formData.append('fixture_id', fixtureId);
            formData.append('kit', kit);
            chunks[index].forEach(function (file) { formData.append('photos[]', file); });

            fetch('match_photo_upload.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp && resp.success) {
                        uploadedSoFar += resp.uploaded || 0;
                        taggedSoFar += resp.tagged || 0;
                        if (resp.errors && resp.errors.length) allErrors = allErrors.concat(resp.errors);
                    } else {
                        allErrors.push((resp && resp.error) || 'A batch of photos failed to upload.');
                    }
                })
                .catch(function () { allErrors.push('A batch of photos failed to upload (network error).'); })
                .then(function () {
                    var percent = Math.round(((index + 1) / chunks.length) * 100);
                    if (uploadProgressBar) uploadProgressBar.style.width = percent + '%';
                    if (uploadProgressLabel) uploadProgressLabel.textContent = 'Uploaded ' + uploadedSoFar + ' of ' + totalFiles + '…';
                    uploadChunk(index + 1);
                });
        }
        uploadChunk(0);
    }

    var conflictDialog = document.getElementById('mediaConflictDialog');
    var resolveConflict = null;
    function askConflict(existingUrl, newUrl, filename) {
        document.getElementById('mediaConflictExisting').src = existingUrl + (existingUrl.indexOf('?') > -1 ? '&' : '?') + 'conflict=' + Date.now();
        document.getElementById('mediaConflictNew').src = newUrl;
        document.getElementById('mediaConflictFilename').textContent = filename;
        conflictDialog.hidden = false;
        document.body.classList.add('media-conflict-open');
        conflictDialog.querySelector('[data-conflict-choice="rename"]').focus();
        return new Promise(function (resolve) { resolveConflict = resolve; });
    }
    if (conflictDialog) {
        conflictDialog.addEventListener('click', function (event) {
            var button = event.target.closest('[data-conflict-choice]');
            if (!button || !resolveConflict) return;
            var resolve = resolveConflict;
            resolveConflict = null;
            conflictDialog.hidden = true;
            document.body.classList.remove('media-conflict-open');
            resolve(button.dataset.conflictChoice);
        });
    }

    async function uploadGenericFiles() {
        var targetCategory = destinationSelect.value;
        try {
            var checkData = new FormData();
            checkData.append('action', 'check_upload_conflicts');
            checkData.append('csrf_token', csrf);
            checkData.append('category', targetCategory);
            checkData.append('files', JSON.stringify(selectedFiles.map(function (file) { return { name: file.name, type: file.type }; })));
            var checkResponse = await fetch(endpoint, { method: 'POST', body: checkData, credentials: 'same-origin' });
            var checkResult = await checkResponse.json();
            if (!checkResponse.ok || !checkResult.ok) throw new Error(checkResult.message || 'The filenames could not be checked.');

            var policies = {};
            for (var i = 0; i < (checkResult.conflicts || []).length; i++) {
                var conflict = checkResult.conflicts[i];
                var file = selectedFiles[conflict.index];
                if (!file) continue;
                var previewUrl = URL.createObjectURL(file);
                var choice = await askConflict(conflict.url, previewUrl, conflict.filename);
                URL.revokeObjectURL(previewUrl);
                if (choice === 'cancel') { resetUploadButton(); toast('Upload cancelled.'); return; }
                policies[String(conflict.index)] = choice;
            }

            var data = new FormData();
            data.append('action', 'upload');
            data.append('csrf_token', csrf);
            data.append('category', targetCategory);
            data.append('conflict_policies', JSON.stringify(policies));
            selectedFiles.forEach(function (file) { data.append('images[]', file); });
            var response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' });
            var result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Upload failed.');
            toast(result.message);
            if (result.errors && result.errors.length) toast(result.errors.join(' '), true);
            window.location.reload();
        } catch (error) {
            toast(error.message || 'Upload failed.', true);
            resetUploadButton();
        }
    }

    uploadButton.addEventListener('click', function () {
        if (!selectedFiles.length) return;
        uploadButton.disabled = true;
        uploadButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';
        if (isMatchDestination()) {
            uploadMatchPhotos();
        } else {
            uploadGenericFiles();
        }
    });

    // === Manage modal ===
    var manageModalEl = document.getElementById('mediaManageModal');
    var manageModal = manageModalEl && window.bootstrap && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(manageModalEl) : null;
    var manageContent = manageModalEl ? manageModalEl.querySelector('.modal-content') : null;
    var detailsFooter = document.querySelector('.media-manage-footer--details');
    var editFooter = document.querySelector('.media-manage-footer--edit');
    var current = null;

    function setView(view) {
        if (manageContent) manageContent.dataset.view = view;
        if (detailsFooter) detailsFooter.hidden = view !== 'details';
        if (editFooter) editFooter.hidden = view !== 'edit';
        if (view === 'edit') initCropperForCurrent();
    }

    function renderTags(item) {
        var wrap = document.getElementById('mediaManageTags');
        if (!wrap) return;
        if (!item.tags.length) { wrap.innerHTML = '<span class="text-muted small">No one tagged yet.</span>'; return; }
        wrap.innerHTML = item.tags.map(function (t) {
            return '<span class="match-media-tag match-media-tag--' + escapeHtml(t.source) + '" data-tag-id="' + t.tag_id + '">' + escapeHtml(t.name) +
                '<button type="button" class="match-media-tag__remove" data-tag-id="' + t.tag_id + '" title="Remove tag" aria-label="Remove tag for ' + escapeHtml(t.name) + '"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></span>';
        }).join('');
    }

    function renderManageDetails(item) {
        document.getElementById('mediaManageCategory').textContent = item.category;
        document.getElementById('mediaManageTitle').textContent = item.is_match_photo ? matchLabel(item) : item.filename;
        document.getElementById('mediaManageImage').src = item.url + '?v=' + item.modified;

        var rows = [
            ['Filename', item.filename],
            ['Category', item.category],
            ['Dimensions', item.width + ' × ' + item.height + ' px'],
            ['Size', formatSize(item.size)],
        ];
        document.getElementById('mediaManageMeta').innerHTML = rows.map(function (row) {
            return '<div class="media-manage-meta__row"><dt>' + escapeHtml(row[0]) + '</dt><dd>' + escapeHtml(String(row[1])) + '</dd></div>';
        }).join('');

        var matchWrap = document.getElementById('mediaManageMatchFields');
        if (matchWrap) matchWrap.hidden = !item.is_match_photo;
        if (item.is_match_photo) {
            var kitSelect = document.getElementById('mediaManageKit');
            if (kitSelect) kitSelect.value = item.kit || '';
            renderTags(item);
        }
    }

    function openManage(item) {
        if (!manageModal) return;
        current = item;
        setView('details');
        renderManageDetails(item);
        manageModal.show();
    }

    grid.addEventListener('click', function (event) {
        var button = event.target.closest('.media-item');
        if (!button) return;
        var item = items.find(function (entry) { return entry.path === button.dataset.path; });
        if (item) openManage(item);
    });

    var editBtn = document.getElementById('mediaManageEditBtn');
    if (editBtn) editBtn.addEventListener('click', function () { setView('edit'); });
    var backBtn = document.getElementById('mediaManageBackToDetails');
    if (backBtn) backBtn.addEventListener('click', function () { setView('details'); });

    // Kit correction
    var manageKit = document.getElementById('mediaManageKit');
    if (manageKit) {
        manageKit.addEventListener('change', function () {
            if (!current) return;
            fetch('match_photo_ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'set_kit', csrf_token: matchCsrf, photo_id: current.match_photo_id, kit: manageKit.value }),
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp.success) { toast(resp.error || 'Error updating kit', true); return; }
                current.kit = manageKit.value;
                var tile = grid.querySelector('.media-item[data-path="' + CSS.escape(current.path) + '"]');
                if (tile) tile.outerHTML = itemMarkup(current);
            }).catch(function () { toast('Request failed', true); });
        });
    }

    // Add a tag
    var addTagSelect = document.getElementById('mediaManageAddTag');
    if (addTagSelect) {
        addTagSelect.addEventListener('change', function () {
            var value = addTagSelect.value;
            if (!value || !current) return;
            if (value === '__new__') {
                addTagSelect.value = '';
                var form = document.getElementById('mediaManageNewPerson');
                form.classList.remove('d-none');
                form.querySelector('input[name="name"]').focus();
                return;
            }
            var personName = addTagSelect.options[addTagSelect.selectedIndex].text;
            fetch('match_photo_ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'add_tag', csrf_token: matchCsrf, photo_id: current.match_photo_id, person_id: value }),
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp.success) { toast(resp.error || 'Error adding tag', true); return; }
                current.tags.push({ tag_id: resp.tag_id, person_id: Number(value), name: resp.person_name || personName, source: 'manual' });
                renderTags(current);
                addTagSelect.value = '';
            }).catch(function () { toast('Request failed', true); });
        });
    }

    // Add a brand new person, tagged straight into this photo
    var newPersonForm = document.getElementById('mediaManageNewPerson');
    if (newPersonForm) {
        newPersonForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!current) return;
            var name = newPersonForm.querySelector('input[name="name"]').value.trim();
            var category = newPersonForm.querySelector('select[name="category"]').value;
            if (!name) return;
            fetch('match_photo_ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'create_and_tag', csrf_token: matchCsrf, photo_id: current.match_photo_id, name: name, category: category }),
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp.success) { toast(resp.error || 'Error adding person', true); return; }
                current.tags.push({ tag_id: resp.tag_id, person_id: resp.person_id, name: resp.person_name, source: 'manual' });
                renderTags(current);
                var label = newPersonCategories[resp.category] || resp.category;
                document.querySelectorAll('select#mediaManageAddTag, select#mediaPersonFilter').forEach(function (select) {
                    var option = document.createElement('option');
                    option.value = resp.person_id;
                    option.textContent = resp.person_name;
                    var group = Array.prototype.find.call(select.querySelectorAll('optgroup'), function (g) { return g.label === label; });
                    if (group) group.appendChild(option); else select.insertBefore(option, select.lastElementChild);
                });
                newPersonForm.classList.add('d-none');
                newPersonForm.reset();
            }).catch(function () { toast('Request failed', true); });
        });
    }
    var newPersonCancel = document.getElementById('mediaManageNewPersonCancel');
    if (newPersonCancel) newPersonCancel.addEventListener('click', function () { newPersonForm.classList.add('d-none'); newPersonForm.reset(); });

    // Remove a tag
    var tagsWrap = document.getElementById('mediaManageTags');
    if (tagsWrap) {
        tagsWrap.addEventListener('click', function (event) {
            var button = event.target.closest('.match-media-tag__remove');
            if (!button || !current) return;
            var tagId = button.dataset.tagId;
            fetch('match_photo_ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'remove_tag', csrf_token: matchCsrf, tag_id: tagId }),
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp.success) { toast(resp.error || 'Error removing tag', true); return; }
                current.tags = current.tags.filter(function (t) { return String(t.tag_id) !== String(tagId); });
                renderTags(current);
            }).catch(function () { toast('Request failed', true); });
        });
    }

    // Delete (match photo or generic file) — the click-time confirm dialog (app.js,
    // via data-confirm on the button) intercepts and re-dispatches this submit once
    // confirmed, so this handler only ever runs after the user has confirmed.
    var deleteForm = document.getElementById('mediaManageDeleteForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            // app.js's confirm dialog only re-arms a button's click-interception once
            // dataset.confirmed is no longer "true" — reset it now (the confirmation for
            // *this* delete has already happened) so the dialog reappears next time this
            // shared modal button is used for a different item.
            var deleteBtn = document.getElementById('mediaManageDeleteBtn');
            if (deleteBtn) deleteBtn.dataset.confirmed = 'false';
            if (!current) return;
            var deleting = current;
            var request;
            if (deleting.is_match_photo) {
                request = fetch('match_photo_delete.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams({ csrf_token: matchCsrf, photo_id: deleting.match_photo_id, season_id: deleting.season_id || 0 }),
                }).then(function (r) { return r.json(); }).then(function (resp) { return { ok: !!resp.success, message: resp.error }; });
            } else {
                var data = new FormData();
                data.append('action', 'delete_media');
                data.append('csrf_token', csrf);
                data.append('path', deleting.path);
                request = fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (resp) { return { ok: !!resp.ok, message: resp.message }; });
            }
            request.then(function (result) {
                if (!result.ok) { toast(result.message || 'Delete failed', true); return; }
                items = items.filter(function (i) { return i !== deleting; });
                if (totalCount) totalCount.textContent = items.length;
                populateMatchFilterOptions();
                render();
                if (manageModal) manageModal.hide();
                toast('Image deleted.');
            }).catch(function () { toast('Request failed', true); });
        });
    }

    if (manageModalEl) {
        manageModalEl.addEventListener('hidden.bs.modal', function () {
            if (cropper) { cropper.destroy(); cropper = null; }
            editorImage && editorImage.removeAttribute('src');
            current = null;
            setView('details');
            var url = new URL(window.location.href);
            if (url.searchParams.has('photo')) { url.searchParams.delete('photo'); window.history.replaceState(null, '', url); }
        });
    }

    // Auto-open the modal for ?photo=ID (used by retired match_photo_view.php links)
    var photoParam = new URLSearchParams(window.location.search).get('photo');
    if (photoParam) {
        var target = items.find(function (i) { return String(i.match_photo_id) === String(photoParam); });
        if (target) openManage(target);
    }

    // === Image editor (crop/rotate/resize/save) ===
    if (!canManage) return;

    var editorImage = document.getElementById('mediaEditorImage');
    var cropper = null, history = [], historyIndex = -1, restoring = false, sizeLocked = true, baseWidth = 0, baseHeight = 0;
    var widthInput = document.getElementById('mediaOutputWidth');
    var heightInput = document.getElementById('mediaOutputHeight');
    var scaleInput = document.getElementById('mediaScale');
    var scaleValue = document.getElementById('mediaScaleValue');
    var undo = document.getElementById('mediaUndo');
    var redo = document.getElementById('mediaRedo');
    var info = document.getElementById('mediaEditorInfo');

    function state() { return { data: cropper.getData(), canvas: cropper.getCanvasData(), cropBox: cropper.getCropBoxData(), aspect: cropper.options.aspectRatio, width: Number(widthInput.value), height: Number(heightInput.value), scale: Number(scaleInput.value) }; }
    function sameState(a, b) { return a && b && JSON.stringify(a) === JSON.stringify(b); }
    function pushHistory() { if (!cropper || restoring) return; var next = state(); if (sameState(history[historyIndex], next)) return; history = history.slice(0, historyIndex + 1); history.push(next); if (history.length > 40) history.shift(); historyIndex = history.length - 1; updateHistory(); updateInfo(); }
    function updateHistory() { undo.disabled = historyIndex <= 0; redo.disabled = historyIndex >= history.length - 1; }
    function restore(index) {
        if (!cropper || !history[index]) return;
        restoring = true; historyIndex = index;
        var s = history[index];
        cropper.setAspectRatio(Number.isNaN(s.aspect) ? NaN : s.aspect);
        cropper.setData(s.data); cropper.setCanvasData(s.canvas); cropper.setCropBoxData(s.cropBox);
        widthInput.value = s.width; heightInput.value = s.height; scaleInput.value = s.scale; scaleValue.textContent = s.scale + '%';
        document.querySelectorAll('#mediaRatios button').forEach(function (btn) {
            btn.classList.toggle('active', (Number.isNaN(Number(btn.dataset.ratio)) && Number.isNaN(s.aspect)) || Math.abs(Number(btn.dataset.ratio) - s.aspect) < .0001);
        });
        restoring = false; updateHistory(); updateInfo();
    }
    function updateInfo() { if (!cropper) return; var data = cropper.getData(true); info.textContent = Math.max(1, Math.round(data.width)) + ' × ' + Math.max(1, Math.round(data.height)) + ' px crop · Output ' + widthInput.value + ' × ' + heightInput.value + ' px'; }
    function syncOutputFromCrop() {
        if (!cropper || restoring) return;
        var data = cropper.getData(true);
        baseWidth = Math.max(1, Math.round(data.width)); baseHeight = Math.max(1, Math.round(data.height));
        var scale = Number(scaleInput.value) / 100;
        widthInput.value = Math.max(1, Math.round(baseWidth * scale)); heightInput.value = Math.max(1, Math.round(baseHeight * scale));
        scaleValue.textContent = scaleInput.value + '%';
        updateInfo();
    }

    function initCropperForCurrent() {
        if (!current) return;
        if (typeof Cropper === 'undefined') { toast('The image editor could not load. You can still browse and manage media.', true); setView('details'); return; }
        if (cropper) { cropper.destroy(); cropper = null; }
        history = []; historyIndex = -1;
        baseWidth = current.width; baseHeight = current.height;
        widthInput.value = current.width; heightInput.value = current.height;
        scaleInput.value = 100; scaleValue.textContent = '100%';
        document.getElementById('mediaSaveName').value = current.name + '-edited';
        editorImage.src = current.url + '?edit=' + Date.now();
        cropper = new Cropper(editorImage, {
            viewMode: 1, dragMode: 'move', autoCropArea: .9, background: false, responsive: true, checkCrossOrigin: false,
            ready: function () { setTimeout(function () { syncOutputFromCrop(); pushHistory(); }, 0); },
            cropend: function () { syncOutputFromCrop(); pushHistory(); },
            zoom: function () { setTimeout(pushHistory, 0); },
        });
    }

    document.getElementById('mediaRatios').addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || !cropper) return;
        var ratio = Number(button.dataset.ratio);
        cropper.setAspectRatio(ratio);
        document.querySelectorAll('#mediaRatios button').forEach(function (b) { b.classList.toggle('active', b === button); });
        setTimeout(function () { syncOutputFromCrop(); pushHistory(); }, 0);
    });
    document.getElementById('mediaRotateLeft').addEventListener('click', function () { cropper.rotate(-90); setTimeout(pushHistory, 0); });
    document.getElementById('mediaRotateRight').addEventListener('click', function () { cropper.rotate(90); setTimeout(pushHistory, 0); });
    document.getElementById('mediaFlipH').addEventListener('click', function () { var d = cropper.getData(); cropper.scaleX((d.scaleX || 1) * -1); setTimeout(pushHistory, 0); });
    document.getElementById('mediaFlipV').addEventListener('click', function () { var d = cropper.getData(); cropper.scaleY((d.scaleY || 1) * -1); setTimeout(pushHistory, 0); });
    undo.addEventListener('click', function () { restore(historyIndex - 1); });
    redo.addEventListener('click', function () { restore(historyIndex + 1); });
    document.getElementById('mediaReset').addEventListener('click', function () {
        cropper.reset(); cropper.setAspectRatio(NaN); scaleInput.value = 100;
        document.querySelectorAll('#mediaRatios button').forEach(function (b, i) { b.classList.toggle('active', i === 0); });
        setTimeout(function () { syncOutputFromCrop(); pushHistory(); }, 0);
    });
    document.getElementById('mediaSizeLock').addEventListener('click', function (event) {
        sizeLocked = !sizeLocked;
        event.currentTarget.classList.toggle('active', sizeLocked);
        event.currentTarget.setAttribute('aria-pressed', String(sizeLocked));
        event.currentTarget.innerHTML = '<i class="fa-solid fa-' + (sizeLocked ? 'link' : 'link-slash') + '"></i>';
    });
    widthInput.addEventListener('change', function () { if (sizeLocked && baseWidth) heightInput.value = Math.max(1, Math.round(Number(widthInput.value) * baseHeight / baseWidth)); pushHistory(); });
    heightInput.addEventListener('change', function () { if (sizeLocked && baseHeight) widthInput.value = Math.max(1, Math.round(Number(heightInput.value) * baseWidth / baseHeight)); pushHistory(); });
    scaleInput.addEventListener('input', function () {
        var scale = Number(scaleInput.value) / 100;
        widthInput.value = Math.max(1, Math.round(baseWidth * scale)); heightInput.value = Math.max(1, Math.round(baseHeight * scale));
        scaleValue.textContent = scaleInput.value + '%';
        updateInfo();
    });
    scaleInput.addEventListener('change', pushHistory);

    async function save(mode) {
        if (!cropper || !current) return;
        var button = mode === 'overwrite' ? document.getElementById('mediaSave') : document.getElementById('mediaSaveCopy');
        var old = button.innerHTML;
        button.disabled = true; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        try {
            var canvas = cropper.getCroppedCanvas({ width: Math.min(8000, Math.max(1, Number(widthInput.value))), height: Math.min(8000, Math.max(1, Number(heightInput.value))), imageSmoothingEnabled: true, imageSmoothingQuality: 'high', fillColor: current.mime === 'image/jpeg' ? '#ffffff' : undefined });
            if (!canvas) throw new Error('The edited image could not be rendered.');
            var outputMime = ['image/jpeg', 'image/png', 'image/webp'].indexOf(current.mime) > -1 ? current.mime : 'image/png';
            var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, outputMime, .92); });
            if (!blob) throw new Error('The edited image could not be prepared.');
            var data = new FormData();
            data.append('action', 'save_edit'); data.append('csrf_token', csrf); data.append('source_path', current.path); data.append('save_mode', mode);
            data.append('filename', document.getElementById('mediaSaveName').value);
            data.append('image', blob, 'edited.' + (outputMime === 'image/jpeg' ? 'jpg' : outputMime.split('/')[1]));
            var response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' });
            var result = await response.json();
            if (response.status === 409 && result.conflict) {
                var previewUrl = URL.createObjectURL(blob);
                var choice = await askConflict(result.url, previewUrl, result.filename);
                URL.revokeObjectURL(previewUrl);
                if (choice === 'cancel') { button.disabled = false; button.innerHTML = old; return; }
                data.set('conflict_policy', choice);
                response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' });
                result = await response.json();
            }
            if (!response.ok || !result.ok) throw new Error(result.message || 'Save failed.');
            toast(result.message);
            if (manageModal) manageModal.hide();
            setTimeout(function () { window.location.reload(); }, 250);
        } catch (error) {
            toast(error.message || 'Save failed.', true);
            button.disabled = false; button.innerHTML = old;
        }
    }
    document.getElementById('mediaSave').addEventListener('click', function () { save('overwrite'); });
    document.getElementById('mediaSaveCopy').addEventListener('click', function () { save('copy'); });
})();
