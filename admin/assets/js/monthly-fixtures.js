(function () {
    'use strict';

    var creator = document.querySelector('[data-monthly-creator]');
    var graphic = document.getElementById('monthlyGraphic');
    var stage = document.getElementById('monthlyGraphicStage');
    if (!creator || !graphic || !stage) return;

    var canvasWidth = Number(creator.dataset.canvasWidth) || 1080;
    var canvasHeight = Number(creator.dataset.canvasHeight) || 1350;
    var seasonId = Number(creator.dataset.seasonId) || 0;
    var month = creator.dataset.month || '';
    var layout = creator.dataset.layout || 'block';
    var csrfToken = creator.dataset.csrf || '';
    var confirmPublish = creator.dataset.confirmPublish === '1';
    // Element layout (position/size/font controls) is a template-level design
    // choice, not something that should vary month to month, so it's shared
    // across every month for this season+layout rather than keyed per month.
    var storageKey = ['monthly-fixtures-editor', seasonId, layout, creator.dataset.themeVersion || '0'].join(':');
    var legacyStorageKey = ['monthly-fixtures-editor', seasonId, month, layout, creator.dataset.themeVersion || '0'].join(':');
    var selectedElement = null;
    var interaction = null;
    var currentScale = 1;

    var form = document.getElementById('monthlyFixtureViewForm');
    var layoutInput = document.getElementById('monthlyFixtureLayout');
    document.querySelectorAll('[data-monthly-submit]').forEach(function (control) {
        control.addEventListener('change', function () { form.requestSubmit(); });
    });
    document.querySelectorAll('[data-monthly-layout]').forEach(function (button) {
        button.addEventListener('click', function () {
            layoutInput.value = button.dataset.monthlyLayout || 'block';
            form.requestSubmit();
        });
    });

    function resizeStage() {
        var available = Math.max(1, stage.clientWidth);
        currentScale = Math.min(1, available / canvasWidth);
        graphic.style.transform = 'scale(' + currentScale + ')';
        stage.style.height = Math.round(canvasHeight * currentScale) + 'px';
    }
    resizeStage();
    window.addEventListener('resize', resizeStage);

    var geometryFields = Array.from(document.querySelectorAll('[data-geometry-field]'));
    var selectedLabel = document.querySelector('[data-selected-label]');
    function elementGeometry(element) {
        return {
            x: Math.round(element.offsetLeft),
            y: Math.round(element.offsetTop),
            width: Math.round(element.offsetWidth),
            height: Math.round(element.offsetHeight)
        };
    }
    function updateGeometryFields() {
        var values = selectedElement ? elementGeometry(selectedElement) : {};
        geometryFields.forEach(function (field) {
            field.disabled = !selectedElement;
            field.value = selectedElement ? String(values[field.dataset.geometryField]) : '';
        });
        selectedLabel.textContent = selectedElement ? selectedElement.dataset.elementLabel : 'Nothing selected';
    }
    function selectElement(element) {
        document.querySelectorAll('[data-editor-element].is-selected').forEach(function (item) { item.classList.remove('is-selected'); });
        selectedElement = element || null;
        if (selectedElement) selectedElement.classList.add('is-selected');
        updateGeometryFields();
    }
    function setGeometry(element, values) {
        var width = Math.max(40, Math.min(canvasWidth, Number(values.width)));
        var height = Math.max(30, Math.min(canvasHeight, Number(values.height)));
        var x = Math.max(0, Math.min(canvasWidth - width, Number(values.x)));
        var y = Math.max(0, Math.min(canvasHeight - height, Number(values.y)));
        element.style.left = Math.round(x) + 'px';
        element.style.top = Math.round(y) + 'px';
        element.style.width = Math.round(width) + 'px';
        element.style.height = Math.round(height) + 'px';
    }

    function stateSnapshot() {
        var elements = {};
        document.querySelectorAll('[data-editor-element]').forEach(function (element) {
            elements[element.dataset.editorElement] = elementGeometry(element);
        });
        var controls = {};
        document.querySelectorAll('select[data-graphic-control], input[data-graphic-control]').forEach(function (control) {
            controls[control.dataset.graphicControl] = control.value;
        });
        var activeBadgeButton = document.querySelector('[data-graphic-control="badge-style"][aria-pressed="true"]');
        if (activeBadgeButton) controls['badge-style'] = activeBadgeButton.dataset.value;
        return { elements: elements, controls: controls };
    }
    function saveState() {
        try { localStorage.setItem(storageKey, JSON.stringify(stateSnapshot())); } catch (error) { /* Storage is optional. */ }
    }

    document.querySelectorAll('[data-editor-element]').forEach(function (element) {
        element.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            selectElement(element);
            var geometry = elementGeometry(element);
            interaction = {
                element: element,
                resize: Boolean(event.target.closest('[data-resize-handle]')),
                startX: event.clientX,
                startY: event.clientY,
                geometry: geometry
            };
            element.setPointerCapture(event.pointerId);
        });
        element.addEventListener('pointermove', function (event) {
            if (!interaction || interaction.element !== element) return;
            var deltaX = (event.clientX - interaction.startX) / currentScale;
            var deltaY = (event.clientY - interaction.startY) / currentScale;
            var next = Object.assign({}, interaction.geometry);
            if (interaction.resize) {
                next.width += deltaX;
                next.height += deltaY;
            } else {
                next.x += deltaX;
                next.y += deltaY;
            }
            setGeometry(element, next);
            updateGeometryFields();
        });
        ['pointerup', 'pointercancel'].forEach(function (eventName) {
            element.addEventListener(eventName, function () {
                if (!interaction) return;
                interaction = null;
                saveState();
            });
        });
        element.addEventListener('click', function (event) { event.stopPropagation(); selectElement(element); });
    });
    graphic.addEventListener('click', function (event) {
        if (event.target === graphic || event.target.closest('.monthly-graphic__background, .monthly-graphic__overlay, .monthly-graphic__texture')) selectElement(null);
    });

    geometryFields.forEach(function (field) {
        field.addEventListener('change', function () {
            if (!selectedElement) return;
            var values = elementGeometry(selectedElement);
            values[field.dataset.geometryField] = Number(field.value);
            setGeometry(selectedElement, values);
            updateGeometryFields();
            saveState();
        });
    });
    document.addEventListener('keydown', function (event) {
        if (!selectedElement || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        if (event.target.matches('input, textarea, select')) return;
        event.preventDefault();
        var step = event.shiftKey ? 10 : 1;
        var values = elementGeometry(selectedElement);
        if (event.key === 'ArrowLeft') values.x -= step;
        if (event.key === 'ArrowRight') values.x += step;
        if (event.key === 'ArrowUp') values.y -= step;
        if (event.key === 'ArrowDown') values.y += step;
        setGeometry(selectedElement, values);
        updateGeometryFields();
        saveState();
    });

    var controlMap = {
        'heading-font': function (value) {
            var font = value.replace(/["']/g, '');
            graphic.style.setProperty('--monthly-heading-font', "'" + font + "'");
            setPreviewStyle(['legend', 'month_heading'], 'font-family', '"' + font + '",Arial,sans-serif');
        },
        'body-font': function (value) {
            var font = value.replace(/["']/g, '');
            graphic.style.setProperty('--monthly-body-font', "'" + font + "'");
            setPreviewStyle(['fixtures', 'footer_meta'], 'font-family', '"' + font + '",Arial,sans-serif');
        },
        'legend-size': function (value) {
            var size = value + 'px';
            graphic.style.setProperty('--monthly-legend-size', size);
            setPreviewStyle(['legend', 'month_heading'], 'font-size', size);
        },
        'fixture-size': function (value) {
            var size = value + 'px';
            graphic.style.setProperty('--monthly-fixture-size', size);
            setPreviewStyle(['fixtures'], 'font-size', size);
        },
        'badge-size': function (value) { graphic.style.setProperty('--monthly-badge-size', value + 'px'); },
        'card-gap': function (value) { graphic.style.setProperty('--monthly-card-gap', value + 'px'); }
    };
    function setPreviewStyle(keys, property, value) {
        keys.forEach(function (key) {
            var element = graphic.querySelector('[data-editor-element="' + CSS.escape(key) + '"]');
            if (element) element.style.setProperty(property, value, 'important');
        });
    }
    function applyControl(control) {
        var key = control.dataset.graphicControl;
        if (controlMap[key]) controlMap[key](control.value);
        var output = document.querySelector('[data-control-output="' + key + '"]');
        if (output) output.textContent = control.value;
    }
    document.querySelectorAll('select[data-graphic-control], input[data-graphic-control]').forEach(function (control) {
        applyControl(control);
        control.addEventListener('input', function () { applyControl(control); saveState(); });
        control.addEventListener('change', function () { applyControl(control); saveState(); });
    });

    function applyBadgeStyle(value) {
        document.querySelectorAll('[data-graphic-control="badge-style"]').forEach(function (button) {
            var isActive = button.dataset.value === value;
            button.classList.toggle('btn-brand', isActive);
            button.classList.toggle('btn-outline-secondary', !isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        graphic.querySelectorAll('img[data-badge-white]').forEach(function (image) {
            var next = value === 'colour' ? image.dataset.badgeColour : image.dataset.badgeWhite;
            if (next) image.src = next;
        });
    }
    document.querySelectorAll('[data-graphic-control="badge-style"]').forEach(function (button) {
        button.addEventListener('click', function () { applyBadgeStyle(button.dataset.value); saveState(); });
    });

    try {
        var savedState = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (!savedState) {
            // One-time migration: adopt whatever was previously saved for the
            // month being viewed as the new shared baseline for every month.
            var legacyState = JSON.parse(localStorage.getItem(legacyStorageKey) || 'null');
            if (legacyState) {
                savedState = legacyState;
                try { localStorage.setItem(storageKey, JSON.stringify(legacyState)); } catch (error) { /* Storage is optional. */ }
            }
        }
        if (savedState && savedState.elements) {
            Object.keys(savedState.elements).forEach(function (key) {
                var element = graphic.querySelector('[data-editor-element="' + CSS.escape(key) + '"]');
                if (element) setGeometry(element, savedState.elements[key]);
            });
        }
        if (savedState && savedState.controls) {
            Object.keys(savedState.controls).forEach(function (key) {
                if (key === 'badge-style') { applyBadgeStyle(savedState.controls[key]); return; }
                var control = document.querySelector('[data-graphic-control="' + CSS.escape(key) + '"]');
                if (control) { control.value = savedState.controls[key]; applyControl(control); }
            });
        }
    } catch (error) { /* Invalid saved state falls back to the theme. */ }
    document.querySelector('[data-reset-layout]').addEventListener('click', function () {
        try {
            localStorage.removeItem(storageKey);
            localStorage.removeItem(legacyStorageKey);
        } catch (error) { /* Ignore. */ }
        window.location.reload();
    });

    var dropzone = document.querySelector('[data-monthly-dropzone]');
    var backgroundForm = document.querySelector('[data-monthly-background-form]');
    var backgroundInput = document.querySelector('[data-monthly-background-input]');
    var backgroundName = document.querySelector('[data-monthly-background-name]');
    var backgroundStatus = document.querySelector('[data-monthly-background-status]');
    var removeForm = document.querySelector('[data-monthly-remove-form]');
    var themeLabel = backgroundName ? backgroundName.textContent : '';

    function setBackgroundStatus(type, message) {
        if (!backgroundStatus) return;
        backgroundStatus.className = 'monthly-background-status' + (type ? ' is-' + type : '');
        backgroundStatus.textContent = message;
    }

    async function uploadBackgroundFile(file) {
        if (!file || !backgroundForm) return;
        if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) {
            setBackgroundStatus('danger', 'Choose a PNG, JPG or WEBP image.');
            return;
        }
        if (file.size > 12 * 1024 * 1024) {
            setBackgroundStatus('danger', 'The background image must be 12 MB or smaller.');
            return;
        }
        var localUrl = URL.createObjectURL(file);
        graphic.style.setProperty('--monthly-background', "url('" + localUrl + "')");
        if (backgroundName) backgroundName.textContent = file.name;
        dropzone.classList.add('is-uploading');
        dropzone.setAttribute('aria-busy', 'true');
        setBackgroundStatus('info', 'Uploading background…');
        try {
            var data = new FormData(backgroundForm);
            data.set('background_image', file);
            data.set('ajax', '1');
            var response = await fetch('monthly_fixtures.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            var result = await response.json().catch(function () { return null; });
            if (!response.ok || !result || !result.ok) throw new Error(result && result.message ? result.message : 'The background could not be uploaded.');
            graphic.style.setProperty('--monthly-background', "url('" + result.image_url + "')");
            if (backgroundName) backgroundName.textContent = result.filename || file.name;
            if (removeForm) removeForm.style.display = '';
            setBackgroundStatus('success', result.message || 'Background updated.');
        } catch (error) {
            graphic.style.removeProperty('--monthly-background');
            if (backgroundName) backgroundName.textContent = themeLabel;
            setBackgroundStatus('danger', error.message || 'The background could not be uploaded.');
        } finally {
            dropzone.classList.remove('is-uploading');
            dropzone.removeAttribute('aria-busy');
            backgroundInput.value = '';
            window.setTimeout(function () { URL.revokeObjectURL(localUrl); }, 1000);
        }
    }

    if (dropzone && backgroundInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) { event.preventDefault(); event.stopPropagation(); });
        });
        dropzone.addEventListener('dragenter', function () { dropzone.classList.add('is-dragging'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('is-dragging'); });
        dropzone.addEventListener('drop', function (event) {
            dropzone.classList.remove('is-dragging');
            var file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
            if (file) uploadBackgroundFile(file);
        });
        backgroundInput.addEventListener('change', function () {
            var file = backgroundInput.files && backgroundInput.files[0];
            if (file) uploadBackgroundFile(file);
        });
    }
    if (backgroundForm) {
        backgroundForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var file = backgroundInput.files && backgroundInput.files[0];
            if (file) uploadBackgroundFile(file);
        });
    }
    if (removeForm) {
        removeForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            setBackgroundStatus('info', 'Removing background…');
            try {
                var data = new FormData(removeForm);
                data.set('ajax', '1');
                var response = await fetch('monthly_fixtures.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                var result = await response.json().catch(function () { return null; });
                if (!response.ok || !result || !result.ok) throw new Error(result && result.message ? result.message : 'The background could not be removed.');
                graphic.style.removeProperty('--monthly-background');
                if (backgroundName) backgroundName.textContent = themeLabel;
                removeForm.style.display = 'none';
                setBackgroundStatus('success', result.message || 'Background removed.');
            } catch (error) {
                setBackgroundStatus('danger', error.message || 'The background could not be removed.');
            }
        });
    }

    function waitForAssets(root) {
        var images = Array.from(root.querySelectorAll('img'));
        return Promise.all([document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()].concat(images.map(function (image) {
            return image.complete ? Promise.resolve() : new Promise(function (resolve) {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        })));
    }
    // html2canvas does not support the CSS object-fit property: it stretches
    // an <img>'s pixel content to fill its box instead of preserving aspect
    // ratio, which distorts badges (object-fit:contain) and any custom
    // background photo (object-fit:cover). Resolve the same math object-fit
    // would apply and bake it into explicit width/height/position, just for
    // the exported clone.
    function resolveObjectFit(img, mode) {
        if (!img.naturalWidth || !img.naturalHeight) return;
        var box = img.getBoundingClientRect();
        if (!box.width || !box.height) return;
        var scale = mode === 'cover'
            ? Math.max(box.width / img.naturalWidth, box.height / img.naturalHeight)
            : Math.min(box.width / img.naturalWidth, box.height / img.naturalHeight);
        var renderedWidth = img.naturalWidth * scale;
        var renderedHeight = img.naturalHeight * scale;
        img.style.objectFit = '';
        if (mode === 'cover') {
            // The background layer is position:absolute and isolated from
            // document flow, so resizing the img itself doesn't shift anything.
            img.style.position = 'absolute';
            img.style.width = renderedWidth + 'px';
            img.style.height = renderedHeight + 'px';
            img.style.left = ((box.width - renderedWidth) / 2) + 'px';
            img.style.top = ((box.height - renderedHeight) / 2) + 'px';
        } else {
            // Badge images are flex items (e.g. stacked above a team name).
            // Shrinking the img itself to its true aspect ratio would shrink
            // its flex footprint too and shift the sibling text below it, so
            // wrap it in a same-sized placeholder and only resize the img
            // inside that wrapper.
            var wrapper = document.createElement('span');
            wrapper.style.cssText = 'display:flex;align-items:center;justify-content:center;width:' + box.width + 'px;height:' + box.height + 'px;';
            img.parentNode.insertBefore(wrapper, img);
            wrapper.appendChild(img);
            img.style.width = renderedWidth + 'px';
            img.style.height = renderedHeight + 'px';
        }
    }
    async function renderCanvas() {
        await waitForAssets(graphic);
        var clone = graphic.cloneNode(true);
        clone.removeAttribute('id');
        clone.classList.add('is-exporting');
        clone.style.transform = 'none';
        clone.style.position = 'fixed';
        clone.style.left = '-12000px';
        clone.style.top = '0';
        clone.querySelectorAll('.is-selected').forEach(function (element) { element.classList.remove('is-selected'); });
        var backgroundImg = null;
        var backgroundValue = getComputedStyle(graphic).getPropertyValue('--monthly-background');
        var backgroundMatch = backgroundValue && backgroundValue.match(/url\((['"]?)(.*?)\1\)/);
        if (backgroundMatch && backgroundMatch[2]) {
            var backgroundLayer = clone.querySelector('.monthly-graphic__background');
            if (backgroundLayer) {
                backgroundLayer.style.backgroundImage = 'none';
                backgroundImg = document.createElement('img');
                backgroundImg.src = backgroundMatch[2];
                backgroundImg.crossOrigin = 'anonymous';
                backgroundImg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;';
                backgroundLayer.appendChild(backgroundImg);
            }
        }
        document.body.appendChild(clone);
        try {
            await waitForAssets(clone);
            clone.querySelectorAll('img').forEach(function (img) {
                resolveObjectFit(img, img === backgroundImg ? 'cover' : 'contain');
            });
            return await html2canvas(clone, { useCORS: true, allowTaint: false, backgroundColor: null, scale: 1, width: canvasWidth, height: canvasHeight, windowWidth: canvasWidth, windowHeight: canvasHeight, imageTimeout: 15000, logging: false });
        } finally {
            clone.remove();
        }
    }
    function downloadCanvas(canvas) {
        var link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'monthly-fixtures-' + month + '-' + layout + '.png';
        document.body.appendChild(link); link.click(); link.remove();
    }

    var status = document.getElementById('monthlyPublishStatus');
    function setStatus(type, message) {
        status.className = 'alert mb-0 monthly-publish-status alert-' + type;
        status.textContent = message;
    }
    var downloadButton = document.getElementById('monthlyDownload');
    downloadButton.addEventListener('click', async function () {
        downloadButton.disabled = true;
        try { downloadCanvas(await renderCanvas()); setStatus('success', 'PNG downloaded.'); }
        catch (error) { setStatus('danger', error.message || 'The graphic could not be generated.'); }
        finally { downloadButton.disabled = false; }
    });

    async function publishDirect(platform, caption, canvas) {
        var data = new FormData();
        data.set('csrf_token', csrfToken);
        data.set('season_id', String(seasonId));
        data.set('month', month);
        data.set('layout', layout);
        data.set('target', platform);
        data.set('caption', caption);
        data.set('image_data', canvas.toDataURL('image/png'));
        var response = await fetch('post_monthly_fixtures.php', { method: 'POST', body: data });
        var result = await response.json().catch(function () { return null; });
        if (!response.ok || !result || !result.ok) throw new Error(result && result.message ? result.message : 'The post failed.');
        return result.message;
    }
    document.querySelectorAll('.monthly-publish').forEach(function (button) {
        button.addEventListener('click', async function () {
            var platform = button.dataset.platform;
            var caption = document.getElementById('monthlyCaption').value.trim();
            if (!caption) { setStatus('danger', 'Add the post text first.'); return; }
            var platformLabel = platform === 'x' ? 'X' : platform.charAt(0).toUpperCase() + platform.slice(1);
            if (confirmPublish && !window.confirm('Publish this Monthly Fixtures post to ' + platformLabel + '?')) return;
            var xWindow = platform === 'x' ? window.open('', '_blank') : null;
            button.disabled = true;
            setStatus('info', 'Preparing the ' + platformLabel + ' post…');
            try {
                var canvas = await renderCanvas();
                if (platform === 'x') {
                    if (xWindow) xWindow.location.href = 'https://x.com/intent/tweet?text=' + encodeURIComponent(caption);
                    downloadCanvas(canvas);
                    var record = new FormData();
                    record.set('csrf_token', csrfToken); record.set('post_type', 'monthly_fixtures'); record.set('caption', caption); record.set('event_id', 'season:' + seasonId + ':' + month + ':' + layout); record.set('image_url', '/admin/monthly_fixtures.php?season_id=' + seasonId + '&month=' + month + '&layout=' + layout);
                    fetch('/admin/record_manual_share.php', { method: 'POST', body: record }).catch(function () {});
                    setStatus(xWindow ? 'success' : 'warning', xWindow ? 'X composer opened and the PNG downloaded.' : 'The popup was blocked, but the PNG was downloaded.');
                } else {
                    setStatus('success', await publishDirect(platform, caption, canvas));
                }
            } catch (error) {
                if (xWindow && !xWindow.closed) xWindow.close();
                setStatus('danger', error.message || 'The post could not be prepared.');
            } finally {
                button.disabled = false;
            }
        });
    });
}());
