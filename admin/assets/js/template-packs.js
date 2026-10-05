(function () {
    'use strict';

    var fontData = document.getElementById('hubPackFontFaces');
    if (fontData && 'FontFace' in window && document.fonts) {
        try {
            JSON.parse(fontData.textContent || '[]').forEach(function (font) {
                if (!font.family || !font.url) return;
                var face = new FontFace(font.family, 'url("' + String(font.url).replace(/"/g, '\\"') + '")');
                face.load().then(function (loaded) { document.fonts.add(loaded); }).catch(function () {});
            });
        } catch (error) {}
    }

    var actionForm = document.querySelector('[data-pack-action-form]');

    function submitAction(action, packId, extra) {
        if (!actionForm) return;
        actionForm.elements.action.value = action;
        actionForm.elements.pack_id.value = packId || '';
        Object.keys(extra || {}).forEach(function (key) {
            if (actionForm.elements[key]) actionForm.elements[key].value = extra[key];
        });
        actionForm.submit();
    }

    document.querySelectorAll('[data-duplicate-pack]').forEach(function (button) {
        button.addEventListener('click', function () {
            var sourceName = button.getAttribute('data-pack-name') || 'Template pack';
            var name = window.prompt('Name the duplicated pack:', sourceName + ' copy');
            if (!name || !name.trim()) return;
            submitAction('duplicate_pack', button.getAttribute('data-duplicate-pack'), { name: name.trim() });
        });
    });

    document.querySelectorAll('[data-archive-pack]').forEach(function (button) {
        button.addEventListener('click', function () {
            var mode = button.getAttribute('data-archive-mode') || 'archive';
            if (mode === 'archive' && !window.confirm('Archive this pack? Existing fixtures will keep their published version.')) return;
            submitAction(mode === 'restore' ? 'restore_pack' : 'archive_pack', button.getAttribute('data-archive-pack'));
        });
    });

    document.querySelectorAll('[data-publish-pack]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('Publish a new immutable version? New fixtures can use it while existing fixtures keep their assigned version.')) return;
            submitAction('publish_pack', button.getAttribute('data-publish-pack'));
        });
    });

    document.querySelectorAll('[data-remove-background]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('Remove this action background? Layout settings will be kept.')) return;
            var editor = button.closest('.action-editor');
            var packInput = editor ? editor.querySelector('[name="pack_id"]') : null;
            submitAction('remove_action_background', packInput ? packInput.value : '', {
                action_key: button.getAttribute('data-remove-background')
            });
        });
    });

    var search = document.querySelector('[data-pack-search]');
    var filter = document.querySelector('[data-pack-filter]');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-pack-card]'));
    var empty = document.querySelector('[data-pack-empty]');

    function filterPacks() {
        var query = search ? search.value.trim().toLowerCase() : '';
        var state = filter ? filter.value : 'active';
        var visible = 0;
        cards.forEach(function (card) {
            var status = card.getAttribute('data-status') || 'draft';
            var statusMatch = state === 'all' || state === status || (state === 'active' && status !== 'archived');
            var searchMatch = !query || (card.getAttribute('data-name') || '').indexOf(query) !== -1;
            card.hidden = !(statusMatch && searchMatch);
            if (!card.hidden) visible += 1;
        });
        if (empty) empty.hidden = visible !== 0;
    }
    if (search) search.addEventListener('input', filterPacks);
    if (filter) filter.addEventListener('change', filterPacks);
    filterPacks();

    function updateBrandPreview(property, value) {
        document.querySelectorAll('[data-action-preview]').forEach(function (preview) {
            preview.style.setProperty('--preview-' + property, value);
        });
    }

    document.querySelectorAll('[data-colour-input]').forEach(function (picker) {
        var text = picker.closest('.colour-field')?.querySelector('[data-colour-text]');
        if (!text) return;
        var property = picker.getAttribute('data-brand-colour');
        picker.addEventListener('input', function () {
            text.value = picker.value.toUpperCase();
            if (property) updateBrandPreview(property, picker.value);
        });
        text.addEventListener('input', function () {
            if (/^#[0-9a-f]{6}$/i.test(text.value)) {
                picker.value = text.value;
                if (property) updateBrandPreview(property, text.value);
            }
        });
    });

    document.querySelectorAll('.artwork-panel').forEach(function (form) {
        var input = form.querySelector('[data-artwork-input]');
        var dropZone = form.querySelector('[data-artwork-drop]');
        var status = form.querySelector('[data-artwork-status]');
        var editor = form.closest('[data-action-editor]');
        var liveBackground = editor?.querySelector('[data-preview-background]');
        if (!input || !dropZone) return;
        var uploading = false;

        function openArtworkPicker(event) {
            if (event) event.preventDefault();
            if (!uploading) input.click();
        }

        async function uploadSelectedArtwork() {
            var file = input.files && input.files[0];
            var preview = form.querySelector('[data-artwork-preview]');
            if (!file || !preview || !/^image\/(png|jpeg|webp)$/i.test(file.type)) {
                if (status) status.textContent = 'Choose a PNG, JPG or WEBP image.';
                input.value = '';
                return;
            }
            if (file.size > 25 * 1024 * 1024) {
                if (status) status.textContent = 'The image must be no larger than 25 MB.';
                input.value = '';
                return;
            }
            if (uploading) return;
            var oldImage = preview.querySelector('img');
            if (oldImage && oldImage.src.indexOf('blob:') === 0) URL.revokeObjectURL(oldImage.src);
            var image = oldImage || document.createElement('img');
            image.alt = 'Selected background preview';
            var localUrl = URL.createObjectURL(file);
            image.src = localUrl;
            preview.prepend(image);
            preview.classList.remove('is-empty');
            if (liveBackground) {
                liveBackground.src = localUrl;
                liveBackground.style.display = 'block';
                liveBackground.closest('[data-action-preview]')?.classList.add('has-background');
            }
            dropZone.classList.add('is-uploading');
            dropZone.setAttribute('aria-busy', 'true');
            if (status) {
                status.className = 'small text-secondary';
                status.textContent = 'Uploading artwork…';
            }
            uploading = true;
            try {
                var data = new FormData(form);
                data.set('ajax', '1');
                var response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var responseText = await response.text();
                var result;
                try {
                    result = JSON.parse(responseText);
                } catch (error) {
                    throw new Error('The server returned an invalid upload response.');
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'The artwork could not be uploaded.');
                }
                image.src = result.image_url;
                if (liveBackground) liveBackground.src = result.image_url;
                input.value = '';
                if (status) {
                    status.className = 'small text-success';
                    status.textContent = result.message || 'Background uploaded and saved.';
                }
                var state = editor?.querySelector('.configuration-state');
                if (state) {
                    state.classList.add('is-ready');
                    state.innerHTML = '<i class="fa-solid fa-circle"></i>Configured';
                }
            } catch (error) {
                if (status) {
                    status.className = 'small text-danger';
                    status.textContent = error.message || 'The artwork could not be uploaded.';
                }
            } finally {
                uploading = false;
                dropZone.classList.remove('is-uploading');
                dropZone.removeAttribute('aria-busy');
                window.setTimeout(function () { URL.revokeObjectURL(localUrl); }, 1000);
            }
        }

        input.addEventListener('change', uploadSelectedArtwork);
        dropZone.addEventListener('click', openArtworkPicker);
        dropZone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') openArtworkPicker(event);
        });
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (event) {
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
                dropZone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropZone.classList.remove('is-dragging');
            });
        });
        dropZone.addEventListener('drop', function (event) {
            var files = event.dataTransfer && event.dataTransfer.files;
            if (!files || !files.length) return;
            var transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
            uploadSelectedArtwork();
        });
    });

    function setFontPreview(role, family) {
        document.querySelectorAll('[data-font-sample="' + role + '"]').forEach(function (sample) {
            sample.style.fontFamily = '"' + family + '", sans-serif';
        });
        document.querySelectorAll('[data-action-preview]').forEach(function (preview) {
            preview.style.setProperty('--preview-' + role + '-font', '"' + family + '"');
        });
    }

    document.querySelectorAll('[data-font-select]').forEach(function (select) {
        var role = select.getAttribute('data-font-select');
        select.addEventListener('change', function () { setFontPreview(role, select.value); });
    });

    var fontModal = document.getElementById('fontUploadModal');
    var fontModalForm = fontModal?.querySelector('[data-font-upload-form]');
    var fontModalInput = fontModal?.querySelector('[data-font-modal-input]');
    var fontModalDrop = fontModal?.querySelector('[data-font-modal-drop]');
    var fontModalRole = fontModal?.querySelector('[data-font-modal-role]');
    var fontModalTitle = fontModal?.querySelector('#fontUploadTitle');
    var fontModalPrompt = fontModal?.querySelector('[data-font-modal-prompt]');
    var fontModalStatus = fontModal?.querySelector('[data-font-modal-status]');
    var fontModalSubmit = fontModal?.querySelector('[data-font-modal-submit]');

    function setFontModalStatus(type, message) {
        if (!fontModalStatus) return;
        fontModalStatus.className = 'alert mb-0 alert-' + type;
        fontModalStatus.textContent = message;
    }

    function updateFontModalFile() {
        var file = fontModalInput?.files?.[0];
        if (!fontModalDrop || !fontModalPrompt) return;
        fontModalDrop.classList.toggle('has-file', Boolean(file));
        if (file) fontModalPrompt.textContent = file.name;
    }

    document.querySelectorAll('[data-font-add]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!fontModal || !fontModalForm || !fontModalRole) return;
            fontModalForm.reset();
            fontModalRole.value = 'library';
            if (fontModalTitle) fontModalTitle.textContent = 'Add font';
            if (fontModalPrompt) fontModalPrompt.textContent = 'Choose a font file';
            if (fontModalStatus) fontModalStatus.className = 'alert d-none mb-0';
            fontModalDrop?.classList.remove('has-file', 'is-dragging');
            window.bootstrap?.Modal.getOrCreateInstance(fontModal).show();
        });
    });

    if (fontModalInput) fontModalInput.addEventListener('change', updateFontModalFile);
    if (fontModalDrop && fontModalInput) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            fontModalDrop.addEventListener(eventName, function (event) {
                event.preventDefault();
                fontModalDrop.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            fontModalDrop.addEventListener(eventName, function (event) {
                event.preventDefault();
                fontModalDrop.classList.remove('is-dragging');
            });
        });
        fontModalDrop.addEventListener('drop', function (event) {
            var files = event.dataTransfer && event.dataTransfer.files;
            if (!files || !files.length) return;
            var transfer = new DataTransfer();
            transfer.items.add(files[0]);
            fontModalInput.files = transfer.files;
            updateFontModalFile();
        });
    }

    if (fontModalForm && fontModalInput && fontModalRole) {
        fontModalForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            var file = fontModalInput.files && fontModalInput.files[0];
            var role = 'library';
            if (!file || !/\.(woff2?|ttf|otf)$/i.test(file.name) || file.size > 8 * 1024 * 1024) {
                setFontModalStatus('danger', 'Choose a WOFF2, WOFF, TTF or OTF font no larger than 8 MB.');
                return;
            }

            fontModalSubmit.disabled = true;
            fontModalSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Uploading…';
            setFontModalStatus('info', 'Uploading and preparing your font…');
            try {
                var data = new FormData(fontModalForm);
                data.set('ajax', '1');
                var response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var responseText = await response.text();
                var result;
                try {
                    result = JSON.parse(responseText);
                } catch (error) {
                    throw new Error('The server returned an invalid font upload response.');
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'The font could not be uploaded.');
                }

                var face = new FontFace(result.family, 'url("' + result.font_url + '")');
                await face.load();
                document.fonts.add(face);
                document.querySelectorAll('[data-pack-font-select]').forEach(function (select) {
                    var existing = Array.prototype.find.call(select.options, function (option) {
                        return option.value === result.family;
                    });
                    if (existing) return;
                    var option = document.createElement('option');
                    option.value = result.family;
                    option.textContent = result.label;
                    select.appendChild(option);
                });
                var library = document.querySelector('[data-font-library]');
                if (library && !library.querySelector('[data-font-library-item="' + CSS.escape(result.family) + '"]')) {
                    var item = document.createElement('span');
                    item.className = 'font-library__item';
                    item.setAttribute('data-font-library-item', result.family);
                    item.style.fontFamily = '"' + result.family.replace(/"/g, '') + '", sans-serif';
                    var itemName = document.createElement('strong');
                    itemName.textContent = result.label;
                    var itemSample = document.createElement('small');
                    itemSample.textContent = 'Aa Bb 123';
                    item.append(itemName, itemSample);
                    library.appendChild(item);
                }
                setFontModalStatus('success', result.message || 'Font added to this pack.');
                window.setTimeout(function () {
                    window.bootstrap?.Modal.getOrCreateInstance(fontModal).hide();
                    document.querySelector('[data-font-add="library"]')?.focus();
                }, 450);
            } catch (error) {
                setFontModalStatus('danger', error.message || 'The font could not be uploaded.');
            } finally {
                fontModalSubmit.disabled = false;
                fontModalSubmit.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload font';
            }
        });
    }

    var headingStyle = document.querySelector('[name="brand[heading_style]"]');
    if (headingStyle) {
        headingStyle.addEventListener('change', function () {
            var transform = headingStyle.value === 'uppercase' ? 'uppercase' : (headingStyle.value === 'title' ? 'capitalize' : 'none');
            document.querySelectorAll('.pack-live-preview__element--headline').forEach(function (element) {
                element.style.textTransform = transform;
            });
        });
    }

    document.querySelectorAll('.layout-element__toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            var fields = button.nextElementSibling;
            if (fields) fields.hidden = expanded;
        });
    });

    function resizeActionPreview(preview) {
        var viewport = preview.closest('[data-preview-viewport]');
        if (!viewport) return;
        var width = Math.max(320, Number(preview.dataset.width) || 1080);
        var height = Math.max(320, Number(preview.dataset.height) || 1080);
        var availableWidth = Math.max(240, viewport.clientWidth);
        var scale = Math.min(availableWidth / width, 620 / height);
        var renderedWidth = width * scale;
        var renderedHeight = height * scale;
        preview.style.transform = 'scale(' + scale + ')';
        preview.style.left = Math.max(0, (availableWidth - renderedWidth) / 2) + 'px';
        viewport.style.height = Math.max(220, renderedHeight) + 'px';
    }

    document.querySelectorAll('[data-action-preview]').forEach(function (preview) {
        resizeActionPreview(preview);
        if ('ResizeObserver' in window) {
            new ResizeObserver(function () { resizeActionPreview(preview); }).observe(preview.closest('[data-preview-viewport]'));
        }
    });

    var activeLayoutHistory = null;

    document.querySelectorAll('[data-layout-form]').forEach(function (form) {
        var editor = form.closest('[data-action-editor]');
        var preview = editor?.querySelector('[data-action-preview]');
        var dimensions = editor?.querySelector('[data-preview-dimensions]');
        var artworkSize = editor?.querySelector('.artwork-size');
        var undoStack = [];
        var redoStack = [];
        var applyingHistory = false;
        var interactionHistory = false;
        var historyLimit = 100;

        function updateLiveLayout() {
            if (!preview) return;
            var width = Math.max(320, Math.min(4096, Number(form.elements.canvas_width?.value) || 1080));
            var height = Math.max(320, Math.min(4096, Number(form.elements.canvas_height?.value) || 1080));
            preview.dataset.width = String(width);
            preview.dataset.height = String(height);
            preview.style.width = width + 'px';
            preview.style.height = height + 'px';
            if (dimensions) dimensions.textContent = width + ' × ' + height;
            if (artworkSize) artworkSize.textContent = width + ' × ' + height;

            var fit = form.elements.background_fit?.value || 'cover';
            var background = preview.querySelector('[data-preview-background]');
            if (background) background.style.objectFit = fit === 'stretch' ? 'fill' : fit;
            var gradient = preview.querySelector('[data-preview-gradient]');
            if (gradient) {
                var gradientColor = form.elements.gradient_color?.value || '#000000';
                var gradientStrength = Math.max(0, Math.min(100, Number(form.elements.gradient_strength?.value) || 0)) / 100;
                if (preview.classList.contains('pack-live-preview__canvas--score-poster')) {
                    var scoreStrength = Math.max(.35, gradientStrength);
                    gradient.style.background = 'linear-gradient(180deg, rgba(0,0,0,' + Math.min(1, scoreStrength + .12) + ') 0%, rgba(0,0,0,' + (scoreStrength * .1) + ') 38%, rgba(0,0,0,' + (scoreStrength * .14) + ') 62%, rgba(0,0,0,' + Math.min(1, scoreStrength + .18) + ') 100%)';
                    gradient.style.opacity = '1';
                } else if (preview.classList.contains('pack-live-preview__canvas--next-up')) {
                    var gradientHex = gradientColor.replace('#', '');
                    var gradientRed = parseInt(gradientHex.slice(0, 2), 16);
                    var gradientGreen = parseInt(gradientHex.slice(2, 4), 16);
                    var gradientBlue = parseInt(gradientHex.slice(4, 6), 16);
                    gradient.style.background = 'linear-gradient(180deg, rgba(' + gradientRed + ',' + gradientGreen + ',' + gradientBlue + ',' + (gradientStrength * .45) + ') 0%, rgba(' + gradientRed + ',' + gradientGreen + ',' + gradientBlue + ',' + (gradientStrength * .12) + ') 42%, rgba(' + gradientRed + ',' + gradientGreen + ',' + gradientBlue + ',' + gradientStrength + ') 100%)';
                    gradient.style.opacity = '1';
                } else {
                    gradient.style.background = gradientColor;
                    gradient.style.opacity = String(gradientStrength);
                }
            }

            form.querySelectorAll('[data-layout-element]').forEach(function (element) {
                var key = element.getAttribute('data-element-key');
                var target = preview.querySelector('[data-preview-element="' + key + '"]');
                if (!target) return;
                element.querySelectorAll('[data-layout-field]').forEach(function (field) {
                    var property = field.getAttribute('data-layout-field');
                    var value = field.type === 'checkbox' ? field.checked : field.value;
                    if (property === 'visible') target.style.display = value ? 'flex' : 'none';
                    if (property === 'x') target.style.left = Math.max(0, Number(value) || 0) + 'px';
                    if (property === 'y') target.style.top = Math.max(0, Number(value) || 0) + 'px';
                    if (property === 'width') target.style.width = Math.max(0, Number(value) || 0) + 'px';
                    if (property === 'height') target.style.height = Math.max(0, Number(value) || 0) + 'px';
                    if (property === 'font_size') {
                        var fontSize = Number(value);
                        if (fontSize > 0) {
                            // Keep editor typography responsive even when a
                            // specialised poster stylesheet sets its own font.
                            target.style.setProperty('font-size', fontSize + 'px', 'important');
                        } else {
                            target.style.removeProperty('font-size');
                        }
                    }
                    if (property === 'text_align') {
                        target.style.textAlign = value;
                        target.style.justifyContent = value === 'left' ? 'flex-start' : (value === 'right' ? 'flex-end' : 'center');
                    }
                    if (property === 'color') target.style.color = value;
                    if (property === 'font_family') {
                        if (value) {
                            target.style.setProperty('font-family', '"' + String(value).replace(/"/g, '') + '", Arial, sans-serif');
                        } else {
                            target.style.removeProperty('font-family');
                        }
                    }
                    if (property === 'font_weight') {
                        if (value) target.style.setProperty('font-weight', String(value));
                        else target.style.removeProperty('font-weight');
                    }
                    if (property === 'letter_spacing') {
                        if (value !== '') target.style.setProperty('letter-spacing', String(value) + 'px');
                        else target.style.removeProperty('letter-spacing');
                    }
                    if (property === 'line_height') {
                        if (value !== '') target.style.setProperty('line-height', String(value));
                        else target.style.removeProperty('line-height');
                    }
                    if (property === 'text_transform') {
                        if (value) target.style.setProperty('text-transform', String(value), 'important');
                        else target.style.removeProperty('text-transform');
                    }
                    if (property === 'venue_font') {
                        var venueTarget = target.querySelector('[data-next-up-venue]');
                        if (venueTarget) venueTarget.style.fontFamily = '"' + String(value).replace(/"/g, '') + '", Arial, sans-serif';
                    }
                    if (property === 'venue_weight') {
                        var venueWeightTarget = target.querySelector('[data-next-up-venue]');
                        if (venueWeightTarget) venueWeightTarget.style.fontWeight = String(value);
                    }
                    if (property === 'date_font') {
                        var dateTarget = target.querySelector('[data-next-up-date]');
                        if (dateTarget) dateTarget.style.fontFamily = '"' + String(value).replace(/"/g, '') + '", Arial, sans-serif';
                    }
                    if (property === 'date_weight') {
                        var dateWeightTarget = target.querySelector('[data-next-up-date]');
                        if (dateWeightTarget) dateWeightTarget.style.fontWeight = String(value);
                    }
                });
            });
            resizeActionPreview(preview);
        }

        function captureLayoutState() {
            var state = { canvas: {}, elements: {} };
            ['canvas_width', 'canvas_height', 'layout', 'background_fit', 'gradient_color', 'gradient_strength'].forEach(function (name) {
                var field = form.elements[name];
                if (field) state.canvas[name] = field.value;
            });
            form.querySelectorAll('[data-layout-element]').forEach(function (element) {
                var key = element.getAttribute('data-element-key');
                if (!key) return;
                state.elements[key] = {};
                element.querySelectorAll('[data-layout-field]').forEach(function (field) {
                    var property = field.getAttribute('data-layout-field');
                    if (!property) return;
                    state.elements[key][property] = field.type === 'checkbox' ? field.checked : field.value;
                });
            });
            return JSON.stringify(state);
        }

        function syncLayoutJsonField() {
            var jsonField = form.querySelector('[data-layout-json]');
            if (!jsonField) return;
            var layout = {};
            try { layout = JSON.parse(jsonField.value || '{}'); } catch (error) { layout = {}; }
            form.querySelectorAll('[data-layout-element]').forEach(function (element) {
                var key = element.getAttribute('data-element-key');
                if (!key) return;
                if (!layout[key] || typeof layout[key] !== 'object') layout[key] = {};
                element.querySelectorAll('[data-layout-field]').forEach(function (field) {
                    var property = field.getAttribute('data-layout-field');
                    if (field.type === 'checkbox') {
                        layout[key][property] = field.checked;
                    } else {
                        layout[key][property] = field.type === 'number'
                            ? (field.value === '' ? '' : Number(field.value))
                            : field.value;
                    }
                });
            });
            jsonField.value = JSON.stringify(layout);
        }

        function applyLayoutState(serializedState) {
            var state;
            try { state = JSON.parse(serializedState); } catch (error) { return false; }
            applyingHistory = true;
            Object.keys(state.canvas || {}).forEach(function (name) {
                if (form.elements[name]) form.elements[name].value = state.canvas[name];
            });
            Object.keys(state.elements || {}).forEach(function (key) {
                var control = layoutControlFor(key);
                if (!control) return;
                Object.keys(state.elements[key] || {}).forEach(function (property) {
                    var field = control.querySelector('[data-layout-field="' + property + '"]');
                    if (!field) return;
                    if (field.type === 'checkbox') {
                        field.checked = Boolean(state.elements[key][property]);
                    } else {
                        field.value = state.elements[key][property];
                    }
                });
            });
            updateLiveLayout();
            syncLayoutJsonField();
            applyingHistory = false;
            return true;
        }

        var committedLayoutState = captureLayoutState();

        function pushUndoState(previousState) {
            if (!previousState || undoStack[undoStack.length - 1] === previousState) return;
            undoStack.push(previousState);
            if (undoStack.length > historyLimit) undoStack.shift();
        }

        function recordLayoutChange() {
            activeLayoutHistory = historyApi;
            if (applyingHistory || interactionHistory) return;
            var currentState = captureLayoutState();
            if (currentState === committedLayoutState) return;
            pushUndoState(committedLayoutState);
            committedLayoutState = currentState;
            redoStack = [];
        }

        var historyApi = {
            undo: function () {
                if (!undoStack.length) return false;
                var currentState = captureLayoutState();
                var previousState = undoStack.pop();
                redoStack.push(currentState);
                if (!applyLayoutState(previousState)) return false;
                committedLayoutState = previousState;
                return true;
            },
            redo: function () {
                if (!redoStack.length) return false;
                var currentState = captureLayoutState();
                var nextState = redoStack.pop();
                pushUndoState(currentState);
                if (!applyLayoutState(nextState)) return false;
                committedLayoutState = nextState;
                return true;
            }
        };

        function layoutControlFor(key) {
            return Array.prototype.find.call(form.querySelectorAll('[data-layout-element]'), function (element) {
                return element.getAttribute('data-element-key') === key;
            }) || null;
        }

        function layoutNumber(control, property, fallback) {
            var field = control?.querySelector('[data-layout-field="' + property + '"]');
            var value = Number(field?.value);
            return Number.isFinite(value) ? value : fallback;
        }

        function selectPreviewElement(target) {
            if (!preview || !target) return null;
            var key = target.getAttribute('data-preview-element');
            var control = key ? layoutControlFor(key) : null;
            preview.querySelectorAll('[data-preview-element].is-selected').forEach(function (element) {
                element.classList.remove('is-selected');
                element.setAttribute('aria-pressed', 'false');
            });
            form.querySelectorAll('[data-layout-element].is-selected').forEach(function (element) {
                element.classList.remove('is-selected');
            });
            target.classList.add('is-selected');
            target.setAttribute('aria-pressed', 'true');
            if (control) {
                control.classList.add('is-selected');
                var toggle = control.querySelector('.layout-element__toggle');
                var fields = control.querySelector('.layout-element__fields');
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
                if (fields) fields.hidden = false;
            }
            return control;
        }

        function clearPreviewSelection() {
            if (!preview) return;
            preview.querySelectorAll('[data-preview-element].is-selected').forEach(function (element) {
                element.classList.remove('is-selected');
                element.setAttribute('aria-pressed', 'false');
            });
            form.querySelectorAll('[data-layout-element].is-selected').forEach(function (element) {
                element.classList.remove('is-selected');
            });
        }

        function syncGeometry(control, geometry) {
            if (!control) return;
            var changedField = null;
            ['x', 'y', 'width', 'height'].forEach(function (property) {
                var field = control.querySelector('[data-layout-field="' + property + '"]');
                if (!field || !Number.isFinite(geometry[property])) return;
                field.value = String(Math.round(geometry[property]));
                changedField = field;
            });
            if (changedField) {
                changedField.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        function previewScale() {
            if (!preview) return 1;
            var canvasWidth = Math.max(1, Number(preview.dataset.width) || preview.offsetWidth || 1);
            return Math.max(.01, preview.getBoundingClientRect().width / canvasWidth);
        }

        function clamp(value, minimum, maximum) {
            return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
        }

        if (preview) {
            preview.classList.add('is-editable');
            var interaction = null;

            preview.querySelectorAll('[data-preview-element]').forEach(function (target) {
                target.setAttribute('aria-pressed', 'false');
                ['nw', 'ne', 'sw', 'se'].forEach(function (corner) {
                    var handle = document.createElement('span');
                    handle.className = 'preview-resize-handle preview-resize-handle--' + corner;
                    handle.setAttribute('data-resize-handle', corner);
                    handle.setAttribute('aria-hidden', 'true');
                    target.appendChild(handle);
                });

                target.addEventListener('keydown', function (event) {
                    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Enter', ' '].includes(event.key)) return;
                    var control = selectPreviewElement(target);
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        return;
                    }
                    if (!control) return;
                    event.preventDefault();
                    activeLayoutHistory = historyApi;
                    var canvasWidth = Math.max(320, Number(preview.dataset.width) || 1080);
                    var canvasHeight = Math.max(320, Number(preview.dataset.height) || 1080);
                    var geometry = {
                        x: layoutNumber(control, 'x', target.offsetLeft),
                        y: layoutNumber(control, 'y', target.offsetTop),
                        width: layoutNumber(control, 'width', target.offsetWidth),
                        height: layoutNumber(control, 'height', target.offsetHeight)
                    };
                    var amount = event.shiftKey ? 10 : 1;
                    if (event.altKey) {
                        if (event.key === 'ArrowLeft') geometry.width = Math.max(30, geometry.width - amount);
                        if (event.key === 'ArrowRight') geometry.width = Math.min(canvasWidth - geometry.x, geometry.width + amount);
                        if (event.key === 'ArrowUp') geometry.height = Math.max(30, geometry.height - amount);
                        if (event.key === 'ArrowDown') geometry.height = Math.min(canvasHeight - geometry.y, geometry.height + amount);
                    } else {
                        if (event.key === 'ArrowLeft') geometry.x = Math.max(0, geometry.x - amount);
                        if (event.key === 'ArrowRight') geometry.x = Math.min(canvasWidth - geometry.width, geometry.x + amount);
                        if (event.key === 'ArrowUp') geometry.y = Math.max(0, geometry.y - amount);
                        if (event.key === 'ArrowDown') geometry.y = Math.min(canvasHeight - geometry.height, geometry.y + amount);
                    }
                    syncGeometry(control, geometry);
                });
            });

            preview.addEventListener('pointerdown', function (event) {
                if (event.button !== 0) return;
                var target = event.target.closest('[data-preview-element]');
                if (!target || !preview.contains(target)) {
                    clearPreviewSelection();
                    return;
                }
                var control = selectPreviewElement(target);
                if (!control) return;
                event.preventDefault();
                activeLayoutHistory = historyApi;
                interactionHistory = true;
                var canvasWidth = Math.max(320, Number(preview.dataset.width) || 1080);
                var canvasHeight = Math.max(320, Number(preview.dataset.height) || 1080);
                var handle = event.target.closest('[data-resize-handle]');
                interaction = {
                    pointerId: event.pointerId,
                    target: target,
                    control: control,
                    mode: handle ? 'resize' : 'move',
                    corner: handle?.getAttribute('data-resize-handle') || '',
                    startX: event.clientX,
                    startY: event.clientY,
                    canvasWidth: canvasWidth,
                    canvasHeight: canvasHeight,
                    geometry: {
                        x: layoutNumber(control, 'x', target.offsetLeft),
                        y: layoutNumber(control, 'y', target.offsetTop),
                        width: layoutNumber(control, 'width', target.offsetWidth),
                        height: layoutNumber(control, 'height', target.offsetHeight)
                    },
                    historyState: committedLayoutState
                };
                target.classList.add('is-interacting');
                target.setPointerCapture?.(event.pointerId);
            });

            preview.addEventListener('pointermove', function (event) {
                if (!interaction || interaction.pointerId !== event.pointerId) return;
                event.preventDefault();
                var scale = previewScale();
                var deltaX = (event.clientX - interaction.startX) / scale;
                var deltaY = (event.clientY - interaction.startY) / scale;
                var original = interaction.geometry;
                var next = { x: original.x, y: original.y, width: original.width, height: original.height };
                var minimumSize = 30;

                if (interaction.mode === 'move') {
                    next.x = clamp(original.x + deltaX, 0, interaction.canvasWidth - original.width);
                    next.y = clamp(original.y + deltaY, 0, interaction.canvasHeight - original.height);
                } else {
                    if (interaction.corner.includes('e')) {
                        next.width = clamp(original.width + deltaX, minimumSize, interaction.canvasWidth - original.x);
                    }
                    if (interaction.corner.includes('s')) {
                        next.height = clamp(original.height + deltaY, minimumSize, interaction.canvasHeight - original.y);
                    }
                    if (interaction.corner.includes('w')) {
                        next.x = clamp(original.x + deltaX, 0, original.x + original.width - minimumSize);
                        next.width = original.width + (original.x - next.x);
                    }
                    if (interaction.corner.includes('n')) {
                        next.y = clamp(original.y + deltaY, 0, original.y + original.height - minimumSize);
                        next.height = original.height + (original.y - next.y);
                    }
                }
                syncGeometry(interaction.control, next);
            });

            function finishPreviewInteraction(event) {
                if (!interaction || (event && interaction.pointerId !== event.pointerId)) return;
                interaction.target.classList.remove('is-interacting');
                interaction.target.releasePointerCapture?.(interaction.pointerId);
                var currentState = captureLayoutState();
                if (currentState !== interaction.historyState) {
                    pushUndoState(interaction.historyState);
                    committedLayoutState = currentState;
                    redoStack = [];
                }
                interactionHistory = false;
                interaction = null;
            }

            preview.addEventListener('pointerup', finishPreviewInteraction);
            preview.addEventListener('pointercancel', finishPreviewInteraction);
        }

        form.addEventListener('focusin', function () {
            activeLayoutHistory = historyApi;
        });
        form.addEventListener('input', function () {
            recordLayoutChange();
            updateLiveLayout();
        });
        form.addEventListener('change', function () {
            recordLayoutChange();
            updateLiveLayout();
        });
        updateLiveLayout();
        form.addEventListener('submit', function () {
            syncLayoutJsonField();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (!activeLayoutHistory || !(event.ctrlKey || event.metaKey) || event.altKey) return;
        var key = event.key.toLowerCase();
        var wantsUndo = key === 'z' && !event.shiftKey;
        var wantsRedo = key === 'y' || (key === 'z' && event.shiftKey);
        if (!wantsUndo && !wantsRedo) return;
        var target = event.target;
        if (target?.isContentEditable && !target.closest('[data-layout-form]')) return;
        var changed = wantsRedo ? activeLayoutHistory.redo() : activeLayoutHistory.undo();
        if (changed) event.preventDefault();
    });

    if (window.location.hash) {
        // Images across earlier action panels (badges, backgrounds) keep loading after
        // the browser's initial jump-to-anchor, growing the page and leaving the target
        // scrolled to the wrong spot. Re-jump once everything has actually settled.
        window.addEventListener('load', function () {
            var target = document.querySelector(window.location.hash);
            if (target) target.scrollIntoView({ block: 'start' });
        });
    }

    var editorLinks = Array.prototype.slice.call(document.querySelectorAll('[data-editor-nav]'));
    if ('IntersectionObserver' in window && editorLinks.length) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                editorLinks.forEach(function (link) {
                    link.classList.toggle('active', link.getAttribute('href') === '#' + entry.target.id);
                });
            });
        }, { rootMargin: '-15% 0px -70% 0px' });
        editorLinks.forEach(function (link) {
            var section = document.querySelector(link.getAttribute('href'));
            if (section) observer.observe(section);
        });
    }

    document.querySelectorAll('[data-pack-preview]').forEach(function (button) {
        button.addEventListener('click', function () {
            var firstArtwork = document.querySelector('.artwork-preview img');
            if (!firstArtwork) {
                window.alert('Add background artwork to an action before previewing this pack.');
                return;
            }
            window.open(firstArtwork.src, '_blank', 'noopener');
        });
    });
})();
