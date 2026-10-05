(() => {
  if (!window.EditorConfig) return;
  const EC = window.EditorConfig;

  const playerSelect = document.getElementById('playerSelect');
  if (playerSelect) {
    playerSelect.addEventListener('change', () => {
      const url = new URL(window.location.href);
      url.searchParams.set('player_id', playerSelect.value);
      window.location.href = url.toString();
    });
  }

  // ------------------------------------------------------------------------
  // #canvas-wrap is a fixed 768x768 layout box (see player_graphics.css for
  // why) — scale it visually to fit whatever width #canvas-shell actually
  // has, without touching its real layout size.
  // ------------------------------------------------------------------------
  const canvasShell = document.getElementById('canvas-shell');
  const canvasWrap = document.getElementById('canvas-wrap');
  if (canvasShell && canvasWrap) {
    const updateCanvasScale = () => {
      const scale = canvasShell.clientWidth / 768;
      canvasWrap.style.setProperty('--canvas-scale', scale);
    };
    updateCanvasScale();
    window.addEventListener('resize', updateCanvasScale);
    if (window.ResizeObserver) {
      new ResizeObserver(updateCanvasScale).observe(canvasShell);
    }
  }

  // ------------------------------------------------------------------------
  // Sponsor logo size — saved per sponsor (see sponsor_graphic_logo_size_save.php),
  // so it follows the sponsor if they're reassigned to a different player/slot.
  // ------------------------------------------------------------------------
  const preview = document.getElementById('graphic-preview');
  const homeSlider = document.getElementById('homeLogoSize');
  const awaySlider = document.getElementById('awayLogoSize');

  // When the home and away slots share the same sponsor, the server renders
  // a single merged row (see lib/player_graphic_html.php) instead of
  // separate home/away rows — target that one row regardless of which
  // slider/toggle (home or away) the change came from.
  function chipFor(slotClass) {
    if (!preview) return null;
    return preview.querySelector('.sponsor-graphic__row--merged .sponsor-graphic__logo-chip')
      || preview.querySelector(`.sponsor-graphic__row--${slotClass} .sponsor-graphic__logo-chip`);
  }

  function setChipSize(slotClass, px) {
    const chip = chipFor(slotClass);
    if (chip) chip.style.setProperty('--logo-size', `${px}px`);
  }

  async function saveSponsorLogoSize(sponsorId, size) {
    const fd = new FormData();
    fd.append('sponsor_id', sponsorId);
    fd.append('size', size);
    const res = await fetch(EC.endpoints.saveLogoSize, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) {
      throw new Error(json && json.error ? json.error : 'Unknown error');
    }
  }

  function hookLogoSlider(slider, slotClass, otherSlider) {
    if (!slider || slider.disabled) return;
    const sponsorId = slider.dataset.sponsorId;

    slider.addEventListener('input', () => {
      setChipSize(slotClass, slider.value);
      // Home and away can be the same sponsor — keep both sliders/chips in sync.
      if (otherSlider && !otherSlider.disabled && otherSlider.dataset.sponsorId === sponsorId) {
        otherSlider.value = slider.value;
        setChipSize(slotClass === 'home' ? 'away' : 'home', slider.value);
      }
    });

    slider.addEventListener('change', async () => {
      try {
        await saveSponsorLogoSize(sponsorId, slider.value);
      } catch (err) {
        alert(`Couldn't save sponsor logo size: ${err.message}`);
      }
    });
  }

  hookLogoSlider(homeSlider, 'home', awaySlider);
  hookLogoSlider(awaySlider, 'away', homeSlider);

  // ------------------------------------------------------------------------
  // Sponsor image visibility — saved per sponsor (see
  // sponsor_graphic_visibility_save.php), same pattern as logo size above.
  // Lets sponsors who are individuals rather than a business have just their
  // name shown on the graphic, with no logo image.
  // ------------------------------------------------------------------------
  const homeVisibleToggle = document.getElementById('homeLogoVisible');
  const awayVisibleToggle = document.getElementById('awayLogoVisible');

  function rowFor(slotClass) {
    if (!preview) return null;
    return preview.querySelector('.sponsor-graphic__row--merged')
      || preview.querySelector(`.sponsor-graphic__row--${slotClass}`);
  }

  function setLogoVisible(slotClass, visible) {
    const row = rowFor(slotClass);
    if (!row) return;
    const logoEl = row.querySelector('.sponsor-graphic__row-logo');
    if (logoEl) logoEl.style.display = visible ? '' : 'none';
    row.classList.toggle('sponsor-graphic__row--no-logo', !visible);
  }

  async function saveSponsorLogoVisibility(sponsorId, visible) {
    const fd = new FormData();
    fd.append('sponsor_id', sponsorId);
    fd.append('hidden', visible ? '0' : '1');
    const res = await fetch(EC.endpoints.saveLogoVisibility, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) {
      throw new Error(json && json.error ? json.error : 'Unknown error');
    }
  }

  function hookVisibilityToggle(toggle, slotClass, otherToggle, slider, otherSlider) {
    if (!toggle || toggle.disabled) return;
    const sponsorId = toggle.dataset.sponsorId;

    toggle.addEventListener('change', async () => {
      const visible = toggle.checked;
      setLogoVisible(slotClass, visible);
      if (slider) slider.disabled = !visible;

      // Home and away can be the same sponsor — keep both toggles/sliders in sync.
      if (otherToggle && !otherToggle.disabled && otherToggle.dataset.sponsorId === sponsorId) {
        otherToggle.checked = visible;
        setLogoVisible(slotClass === 'home' ? 'away' : 'home', visible);
        if (otherSlider) otherSlider.disabled = !visible;
      }

      try {
        await saveSponsorLogoVisibility(sponsorId, visible);
      } catch (err) {
        alert(`Couldn't save sponsor image visibility: ${err.message}`);
        toggle.checked = !visible;
        setLogoVisible(slotClass, !visible);
        if (slider) slider.disabled = visible;
      }
    });
  }

  hookVisibilityToggle(homeVisibleToggle, 'home', awayVisibleToggle, homeSlider, awaySlider);
  hookVisibilityToggle(awayVisibleToggle, 'away', homeVisibleToggle, awaySlider, homeSlider);

  // ------------------------------------------------------------------------
  // "Add sponsor image" — for a sponsor with no logo uploaded yet. Opens a
  // modal to either drop/choose a new image or pick one already in the
  // media library; either way it's assigned via sponsor_logo_select.php,
  // then the page reloads so the server-rendered sidebar controls and
  // preview both pick up the new image consistently.
  // ------------------------------------------------------------------------
  const addImageButtons = Array.from(document.querySelectorAll('.js-add-sponsor-image'));
  const sponsorImageModalEl = document.getElementById('sponsorImageModal');
  if (addImageButtons.length && sponsorImageModalEl && window.bootstrap) {
    const sponsorImageModal = new bootstrap.Modal(sponsorImageModalEl);
    const modalTitle = document.getElementById('sponsorImageModalTitle');
    const statusEl = document.getElementById('sponsorImageStatus');
    const uploadStatusEl = document.getElementById('sponsorImageUploadStatus');
    const dropzone = document.getElementById('sponsorImageDropzone');
    const fileInput = document.getElementById('sponsorImageFile');
    const searchInput = document.getElementById('sponsorImagePickerSearch');
    const grid = document.getElementById('sponsorImagePickerGrid');
    const emptyEl = document.getElementById('sponsorImagePickerEmpty');
    const mediaItems = EC.mediaItems || [];

    let activeSponsorId = null;
    let busy = false;

    function showError(message) {
      statusEl.textContent = message;
      statusEl.classList.remove('d-none');
    }

    function clearError() {
      statusEl.classList.add('d-none');
      statusEl.textContent = '';
    }

    function setBusy(isBusy, label) {
      busy = isBusy;
      uploadStatusEl.textContent = isBusy ? (label || 'Working…') : '';
      dropzone.style.pointerEvents = isBusy ? 'none' : '';
      dropzone.style.opacity = isBusy ? '.6' : '';
    }

    function renderGrid(filter) {
      const term = (filter || '').trim().toLowerCase();
      const filtered = term
        ? mediaItems.filter((item) => item.name.toLowerCase().includes(term) || item.category.toLowerCase().includes(term))
        : mediaItems;

      grid.innerHTML = '';
      emptyEl.classList.toggle('d-none', filtered.length !== 0);

      filtered.slice(0, 300).forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sponsor-image-picker-item';
        button.title = `${item.name} — ${item.category}`;
        button.innerHTML = `<img src="${item.url}" alt="${item.name}" loading="lazy"><span>${item.name}</span>`;
        button.addEventListener('click', () => assignImage({ media_path: item.path }));
        grid.appendChild(button);
      });
    }

    async function assignImage(payload) {
      if (busy || !activeSponsorId) return;
      clearError();
      setBusy(true, payload.image ? 'Uploading…' : 'Assigning…');

      const fd = new FormData();
      fd.append('sponsor_id', String(activeSponsorId));
      fd.append('csrf_token', EC.csrfToken || '');
      if (payload.image) {
        fd.append('image', payload.image);
      } else if (payload.media_path) {
        fd.append('media_path', payload.media_path);
      }

      try {
        const res = await fetch(EC.endpoints.selectSponsorImage, { method: 'POST', body: fd });
        const json = await res.json().catch(() => null);
        if (!res.ok || !json || !json.ok) {
          throw new Error((json && json.error) || 'Could not set the sponsor image.');
        }
        window.location.reload();
      } catch (err) {
        setBusy(false);
        showError(err.message);
      }
    }

    addImageButtons.forEach((button) => {
      button.addEventListener('click', () => {
        activeSponsorId = button.dataset.sponsorId || null;
        modalTitle.textContent = `Add image — ${button.dataset.sponsorName || 'sponsor'}`;
        clearError();
        setBusy(false);
        fileInput.value = '';
        searchInput.value = '';
        renderGrid('');
        sponsorImageModal.show();
      });
    });

    searchInput.addEventListener('input', () => renderGrid(searchInput.value));

    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files[0]) {
        assignImage({ image: fileInput.files[0] });
      }
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragover');
      });
    });
    dropzone.addEventListener('drop', (event) => {
      const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
      if (file) assignImage({ image: file });
    });
  }

  // ------------------------------------------------------------------------
  // Sponsor name / address / contact number — each independently shown or
  // hidden and resized, saved per sponsor (see
  // sponsor_graphic_text_visibility_save.php and
  // sponsor_graphic_text_size_save.php). Same merged-row-aware targeting and
  // home/away sync pattern as the logo controls above.
  // ------------------------------------------------------------------------
  const textFieldVars = { name: '--name-font-size', address: '--address-font-size', contact: '--contact-font-size' };
  const textFieldSelectors = {
    name: '.sponsor-graphic__value',
    address: '.sponsor-graphic__business-line--address',
    contact: '.sponsor-graphic__business-line--contact',
  };

  function textRowFor(slotClass) {
    if (!preview) return null;
    return preview.querySelector('.sponsor-graphic__row--merged')
      || preview.querySelector(`.sponsor-graphic__row--${slotClass}`);
  }

  function textElementFor(slotClass, field) {
    const row = textRowFor(slotClass);
    return row ? row.querySelector(textFieldSelectors[field]) : null;
  }

  function setTextVisible(slotClass, field, visible) {
    const el = textElementFor(slotClass, field);
    if (el) el.style.display = visible ? '' : 'none';
  }

  function setTextSize(slotClass, field, px) {
    const el = textElementFor(slotClass, field);
    if (el) el.style.setProperty(textFieldVars[field], `${px}px`);
  }

  function sizeSliderFor(slotClass, field) {
    return document.getElementById(`${slotClass}${field.charAt(0).toUpperCase()}${field.slice(1)}Size`);
  }

  async function saveTextVisibility(sponsorId, field, visible) {
    const fd = new FormData();
    fd.append('sponsor_id', sponsorId);
    fd.append('field', field);
    fd.append('hidden', visible ? '0' : '1');
    fd.append('csrf_token', EC.csrfToken || '');
    const res = await fetch(EC.endpoints.saveTextVisibility, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'Unknown error');
  }

  async function saveTextSize(sponsorId, field, size) {
    const fd = new FormData();
    fd.append('sponsor_id', sponsorId);
    fd.append('field', field);
    fd.append('size', size);
    fd.append('csrf_token', EC.csrfToken || '');
    const res = await fetch(EC.endpoints.saveTextSize, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'Unknown error');
  }

  document.querySelectorAll('.js-text-visible').forEach((toggle) => {
    const slot = toggle.dataset.slot;
    const field = toggle.dataset.field;
    const sponsorId = toggle.dataset.sponsorId;
    const otherSlot = slot === 'home' ? 'away' : 'home';
    const otherToggle = document.querySelector(`.js-text-visible[data-slot="${otherSlot}"][data-field="${field}"]`);
    const sizeSlider = sizeSliderFor(slot, field);
    const otherSizeSlider = sizeSliderFor(otherSlot, field);

    toggle.addEventListener('change', async () => {
      const visible = toggle.checked;
      setTextVisible(slot, field, visible);
      if (sizeSlider) sizeSlider.disabled = !visible;

      if (otherToggle && otherToggle.dataset.sponsorId === sponsorId) {
        otherToggle.checked = visible;
        setTextVisible(otherSlot, field, visible);
        if (otherSizeSlider) otherSizeSlider.disabled = !visible;
      }

      try {
        await saveTextVisibility(sponsorId, field, visible);
      } catch (err) {
        alert(`Couldn't save: ${err.message}`);
        toggle.checked = !visible;
        setTextVisible(slot, field, !visible);
        if (sizeSlider) sizeSlider.disabled = visible;
      }
    });
  });

  document.querySelectorAll('.js-text-size').forEach((slider) => {
    const slot = slider.dataset.slot;
    const field = slider.dataset.field;
    const sponsorId = slider.dataset.sponsorId;
    const otherSlot = slot === 'home' ? 'away' : 'home';
    const otherSlider = document.querySelector(`.js-text-size[data-slot="${otherSlot}"][data-field="${field}"]`);

    slider.addEventListener('input', () => {
      setTextSize(slot, field, slider.value);
      if (otherSlider && otherSlider.dataset.sponsorId === sponsorId) {
        otherSlider.value = slider.value;
        setTextSize(otherSlot, field, slider.value);
      }
    });

    slider.addEventListener('change', async () => {
      try {
        await saveTextSize(sponsorId, field, slider.value);
      } catch (err) {
        alert(`Couldn't save text size: ${err.message}`);
      }
    });
  });

  // ------------------------------------------------------------------------
  // Export: render the HTML/CSS graphic to PNG via dom-to-image-more.
  // ------------------------------------------------------------------------
  const downloadBtn = document.getElementById('downloadBtn');
  const downloadAllBtn = document.getElementById('downloadAllBtn');
  const downloadStatus = document.getElementById('downloadStatus');
  const downloadStatusText = document.getElementById('downloadStatusText');
  const hiddenDownloadLink = document.getElementById('hiddenDownloadLink');

  if (!downloadBtn && !downloadAllBtn) return;

  function showDownloadStatus(text) {
    if (!downloadStatus) return;
    downloadStatus.style.display = '';
    if (downloadStatusText) downloadStatusText.textContent = text || 'Rendering…';
    if (downloadBtn) downloadBtn.disabled = true;
    if (downloadAllBtn) downloadAllBtn.disabled = true;
  }

  function hideDownloadStatus() {
    if (!downloadStatus) return;
    downloadStatus.style.display = 'none';
    if (downloadBtn) downloadBtn.disabled = false;
    if (downloadAllBtn) downloadAllBtn.disabled = false;
  }

  function safeFileName(name) {
    return `${String(name || 'player').replace(/[^a-zA-Z0-9_-]/g, '_')}.png`;
  }

  function createOffscreenContainer() {
    const el = document.createElement('div');
    el.style.position = 'fixed';
    el.style.left = '-9999px';
    el.style.top = '0';
    el.style.width = '768px';
    el.style.height = '768px';
    el.style.overflow = 'hidden';
    document.body.appendChild(el);
    return el;
  }

  function waitForImages(root) {
    const imgs = Array.from(root.querySelectorAll('img'));
    return Promise.all(imgs.map((img) => {
      if (img.complete && img.naturalWidth > 0) return Promise.resolve();
      return new Promise((resolve) => {
        img.addEventListener('load', resolve, { once: true });
        img.addEventListener('error', resolve, { once: true });
      });
    }));
  }

  async function renderPlayerToBlob(offscreen, playerId) {
    const url = `${EC.endpoints.fragment}?player_id=${encodeURIComponent(playerId)}&season_id=${encodeURIComponent(EC.seasonId)}`;
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('Could not load graphic for this player');
    const html = await resp.text();
    offscreen.innerHTML = html;
    const node = offscreen.firstElementChild;
    await Promise.all([waitForImages(node), document.fonts.ready]);
    return domtoimage.toBlob(node, { width: 768, height: 768, bgcolor: '#faf4e9' });
  }

  // Re-fetching the graphic from the server (like renderPlayerToBlob does)
  // re-reads the sponsor's saved logo size/visibility from the database —
  // but dragging a slider or flipping a toggle updates the live preview
  // instantly while its save request is still in flight. Downloading the
  // player currently on screen should always match exactly what's visible,
  // so it clones the already-rendered preview instead of re-fetching it.
  async function renderCurrentPreviewToBlob(offscreen) {
    if (!preview) throw new Error('Preview is not available');
    const node = preview.cloneNode(true);
    node.removeAttribute('id');
    offscreen.appendChild(node);
    await Promise.all([waitForImages(node), document.fonts.ready]);
    return domtoimage.toBlob(node, { width: 768, height: 768, bgcolor: '#faf4e9' });
  }

  function triggerBlobDownload(blob, filename) {
    const link = hiddenDownloadLink;
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    setTimeout(() => {
      URL.revokeObjectURL(link.href);
      link.remove();
    }, 1000);
  }

  if (downloadBtn) {
    downloadBtn.addEventListener('click', async () => {
      showDownloadStatus('Rendering PNG…');
      const offscreen = createOffscreenContainer();
      try {
        const blob = await renderCurrentPreviewToBlob(offscreen);
        triggerBlobDownload(blob, safeFileName(EC.playerName));
      } catch (err) {
        alert(`Download failed: ${err.message}`);
      } finally {
        offscreen.remove();
        hideDownloadStatus();
      }
    });
  }

  if (downloadAllBtn) {
    downloadAllBtn.addEventListener('click', async () => {
      const players = EC.players || [];
      if (!players.length) return;

      showDownloadStatus(`Rendering 0/${players.length}…`);
      const offscreen = createOffscreenContainer();
      const zip = new JSZip();
      let count = 0;

      for (const p of players) {
        const filename = safeFileName(p.name);
        try {
          const blob = await renderPlayerToBlob(offscreen, p.id);
          zip.file(filename, blob);
        } catch (err) {
          zip.file(filename.replace('.png', '.txt'), `Error rendering PNG for ${p.name}: ${err.message}`);
        }
        count++;
        showDownloadStatus(`Rendering ${count}/${players.length}…`);
      }

      offscreen.remove();

      try {
        const content = await zip.generateAsync({ type: 'blob' });
        triggerBlobDownload(content, 'player_graphics.zip');
      } catch (err) {
        alert(`ZIP download failed: ${err.message}`);
      } finally {
        hideDownloadStatus();
      }
    });
  }
})();
