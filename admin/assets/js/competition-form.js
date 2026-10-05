(function () {
    'use strict';

    var editor = document.querySelector('[data-competition-editor]');
    if (!editor) {
        return;
    }

    var seasonSelect = editor.querySelector('[data-edition-season-select]');
    var addSeasonButton = editor.querySelector('[data-add-edition]');
    var editionList = editor.querySelector('[data-edition-list]');
    var emptyState = editor.querySelector('[data-editions-empty]');
    var count = editor.querySelector('[data-edition-count]');
    var typeInputs = Array.prototype.slice.call(editor.querySelectorAll('input[name="competition_type"]'));

    function editionItems() {
        return Array.prototype.slice.call(editor.querySelectorAll('[data-edition]'));
    }

    function visibleEditionItems() {
        return editionItems().filter(function (item) {
            var present = item.querySelector('[data-edition-present]');
            return present && present.value === '1' && !item.hidden;
        });
    }

    function updateSummary(item) {
        var displayName = item.querySelector('[data-edition-display-name]');
        var seasonName = item.getAttribute('data-season-name') || 'Season';
        var summary = item.querySelector('[data-edition-summary-text]');
        if (summary) {
            summary.textContent = displayName && displayName.value.trim() !== '' ? displayName.value.trim() : seasonName;
        }
    }

    function updateCount() {
        var total = visibleEditionItems().length;
        if (count) {
            count.textContent = total + (total === 1 ? ' season' : ' seasons');
        }
        if (emptyState) {
            emptyState.hidden = total !== 0;
        }
    }

    function setEditionOpen(item, open) {
        var body = item.querySelector('[data-edition-body]');
        var summary = item.querySelector('[data-edition-toggle]');
        item.classList.toggle('is-open', open);
        if (body) {
            body.hidden = !open;
        }
        if (summary) {
            summary.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function selectedCompetitionType() {
        var checked = typeInputs.find(function (input) { return input.checked; });
        return checked ? checked.value : 'cup';
    }

    function updateLeagueFields() {
        var isLeague = selectedCompetitionType() === 'league';
        editor.querySelectorAll('[data-edition-league-fields]').forEach(function (fields) {
            fields.hidden = !isLeague;
            fields.querySelectorAll('input, select, textarea').forEach(function (control) {
                if (!control.hasAttribute('data-locked-control')) {
                    control.disabled = !isLeague;
                }
            });
        });
    }

    function initialiseDropzones() {
        editor.querySelectorAll('[data-media-upload]').forEach(function (upload) {
            var dropzone = upload.querySelector('[data-dropzone]');
            var input = upload.querySelector('[data-dropzone-input]');
            var preview = upload.querySelector('[data-dropzone-preview]');
            var image = upload.querySelector('[data-dropzone-image]');
            var filename = upload.querySelector('[data-dropzone-filename]');
            var previewUrl = '';
            var dragDepth = 0;

            if (!dropzone || !input || !preview || !image || !filename) {
                return;
            }

            dropzone.tabIndex = 0;
            dropzone.setAttribute('role', 'button');
            dropzone.setAttribute('aria-label', 'Choose an image or drop it here');
            filename.setAttribute('aria-live', 'polite');

            function showFile(file) {
                if (!file) {
                    return;
                }
                if (file.type && file.type.indexOf('image/') !== 0) {
                    filename.textContent = 'Please choose a JPG, PNG or WebP image';
                    input.value = '';
                    return;
                }
                if (previewUrl !== '') {
                    URL.revokeObjectURL(previewUrl);
                }
                previewUrl = URL.createObjectURL(file);
                image.src = previewUrl;
                image.alt = 'Preview of ' + file.name;
                preview.hidden = false;
                filename.textContent = file.name;
            }

            input.addEventListener('change', function () {
                showFile(input.files && input.files[0] ? input.files[0] : null);
            });

            dropzone.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    input.click();
                }
            });

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                });
            });

            dropzone.addEventListener('dragenter', function () {
                dragDepth += 1;
                dropzone.classList.add('is-dragging');
            });
            dropzone.addEventListener('dragleave', function () {
                dragDepth = Math.max(0, dragDepth - 1);
                if (dragDepth === 0) {
                    dropzone.classList.remove('is-dragging');
                }
            });
            dropzone.addEventListener('drop', function (event) {
                dragDepth = 0;
                dropzone.classList.remove('is-dragging');
                var files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;
                var file = files && files[0] ? files[0] : null;
                if (!file) {
                    return;
                }
                try {
                    var transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                } catch (error) {
                    input.files = files;
                }
                showFile(file);
            });
        });
    }

    function addEdition() {
        if (!seasonSelect || seasonSelect.value === '') {
            return;
        }

        var seasonId = seasonSelect.value;
        var item = editor.querySelector('[data-edition][data-season-id="' + CSS.escape(seasonId) + '"]');
        var option = seasonSelect.options[seasonSelect.selectedIndex];
        if (!item || !option) {
            return;
        }

        var present = item.querySelector('[data-edition-present]');
        if (present) {
            present.value = '1';
        }
        item.hidden = false;
        item.setAttribute('data-added-now', 'true');
        option.hidden = true;
        option.disabled = true;
        seasonSelect.value = '';
        addSeasonButton.disabled = true;
        setEditionOpen(item, true);
        updateCount();
        updateLeagueFields();

        var firstInput = item.querySelector('input:not([type="hidden"]), select, textarea');
        if (firstInput) {
            firstInput.focus();
        }
    }

    function removeEdition(item) {
        if (item.getAttribute('data-protected') === 'true') {
            return;
        }

        var present = item.querySelector('[data-edition-present]');
        var seasonId = item.getAttribute('data-season-id') || '';
        if (present) {
            present.value = '0';
        }
        item.hidden = true;
        setEditionOpen(item, false);

        if (seasonSelect && seasonId !== '') {
            Array.prototype.forEach.call(seasonSelect.options, function (option) {
                if (option.value === seasonId) {
                    option.hidden = false;
                    option.disabled = false;
                }
            });
        }
        updateCount();
    }

    if (seasonSelect && addSeasonButton) {
        seasonSelect.addEventListener('change', function () {
            addSeasonButton.disabled = seasonSelect.value === '';
        });
        addSeasonButton.addEventListener('click', addEdition);
    }

    if (editionList) {
        editionList.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-edition-toggle]');
            if (toggle) {
                var toggleItem = toggle.closest('[data-edition]');
                setEditionOpen(toggleItem, !toggleItem.classList.contains('is-open'));
                return;
            }

            var removeButton = event.target.closest('[data-remove-edition]');
            if (removeButton) {
                removeEdition(removeButton.closest('[data-edition]'));
            }
        });

        editionList.addEventListener('input', function (event) {
            if (event.target.matches('[data-edition-display-name]')) {
                updateSummary(event.target.closest('[data-edition]'));
            }
        });
    }

    typeInputs.forEach(function (input) {
        input.addEventListener('change', updateLeagueFields);
    });

    editionItems().forEach(updateSummary);
    updateCount();
    updateLeagueFields();
    initialiseDropzones();
}());
