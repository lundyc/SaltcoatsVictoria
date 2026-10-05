(function () {
  "use strict";

  const root = document.getElementById("facebookPhotoImport");
  if (!root) return;

  let fixtures = Array.isArray(window.FACEBOOK_IMPORT_FIXTURES) ? window.FACEBOOK_IMPORT_FIXTURES : [];
  let people = Array.isArray(window.FACEBOOK_IMPORT_PEOPLE) ? window.FACEBOOK_IMPORT_PEOPLE : [];
  const csrf = root.dataset.csrf || "";
  const fetchButton = document.getElementById("facebookImportFetch");
  const saveButton = document.getElementById("facebookImportSave");
  const rejectButton = document.getElementById("facebookImportReject");
  const albumSelect = document.getElementById("facebookImportAlbum");
  const limitSelect = document.getElementById("facebookImportLimit");
  const status = document.getElementById("facebookImportStatus");
  const grid = document.getElementById("facebookImportGrid");
  const loadMoreWrap = document.getElementById("facebookImportLoadMore");
  const loadMoreButton = document.getElementById("facebookImportMore");
  const loadAllButton = document.getElementById("facebookImportAll");
  const selection = document.getElementById("facebookImportSelection");
  const selectedCount = document.getElementById("facebookImportSelectedCount");
  let photos = [];
  let nextAfter = "";
  let isFetching = false;
  let isLoadingAll = false;
  const loadedImageIds = new Set();
  const selectedIds = new Set();

  const esc = value => String(value || "").replace(/[&<>'"]/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", "\"": "&quot;" }[char]));
  const fixtureById = id => fixtures.find(fixture => Number(fixture.id) === Number(id));
  const categoryLabels = { player: "Players", manager: "Managers", staff: "Staff", fan: "Fans", other: "Other" };

  function setStatus(message, tone) {
    status.className = "facebook-import-status" + (tone ? " is-" + tone : "");
    status.textContent = message || "";
  }

  function batchLabel() {
    return String(limitSelect ? limitSelect.value : "25");
  }

  function updateLoadMoreUi() {
    const hasMore = !!nextAfter;
    loadMoreWrap.hidden = !hasMore;
    if (loadMoreButton) {
      loadMoreButton.disabled = !hasMore || isFetching || isLoadingAll;
      loadMoreButton.innerHTML = isFetching && !isLoadingAll
        ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading...'
        : `<i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Load ${esc(batchLabel())} more photos`;
    }
    if (loadAllButton) {
      loadAllButton.disabled = !hasMore || isFetching || isLoadingAll;
      if (!isLoadingAll) {
        loadAllButton.innerHTML = '<i class="fa-solid fa-layer-group" aria-hidden="true"></i> Load all';
      }
    }
  }

  function fixtureOptions(selectedId) {
    return '<option value="">Choose match...</option><option value="__add_fixture__">Add new fixture...</option>' + fixtures.map(fixture => {
      const selected = Number(fixture.id) === Number(selectedId) ? " selected" : "";
      return `<option value="${fixture.id}"${selected}>${esc(fixture.label)}</option>`;
    }).join("");
  }

  function inferFixtureDefaults(photo) {
    const message = String((photo && photo.post_message) || "");
    const date = new Date((photo && photo.created_time) || "");
    const defaults = {
      match_date: Number.isNaN(date.getTime()) ? new Date().toISOString().slice(0, 10) : date.toISOString().slice(0, 10),
      opponent: "",
      competition: "",
      is_home: "1"
    };
    const compact = message.replace(/\s+/g, " ").trim();
    const opponentPatterns = [
      /\b(?:vs?|versus|against)\s+([A-Z0-9][A-Za-z0-9 '&.\-]+?)(?:\s+(?:fc|afc|jfc|cfc))?(?:[.!?\n\r]|$|\s+(?:on|at|today|tomorrow|yesterday|in|ko|kick|match|photos|gallery|friendly|cup|league)\b)/i,
      /\baway\s+(?:to|at)\s+([A-Z0-9][A-Za-z0-9 '&.\-]+?)(?:[.!?\n\r]|$|\s+(?:on|at|today|tomorrow|yesterday|in|ko|kick|match|photos|gallery)\b)/i,
      /\bhome\s+(?:to|against)\s+([A-Z0-9][A-Za-z0-9 '&.\-]+?)(?:[.!?\n\r]|$|\s+(?:on|at|today|tomorrow|yesterday|in|ko|kick|match|photos|gallery)\b)/i
    ];
    for (const pattern of opponentPatterns) {
      const match = compact.match(pattern);
      if (match && match[1]) {
        defaults.opponent = match[1].replace(/\b(?:photos?|gallery|matchday|friendly|cup|league)\b.*$/i, "").trim();
        break;
      }
    }
    if (/\baway\s+(?:to|at)\b/i.test(compact) || /\b@\s*[A-Z]/.test(compact)) defaults.is_home = "0";
    const competition = compact.match(/\b(?:cup|league|trophy|shield|friendly|division|premier|championship)\b[^.!?\n\r]*/i);
    if (competition) defaults.competition = competition[0].trim().slice(0, 80);
    return defaults;
  }

  function newFixtureForm(photo) {
    const defaults = inferFixtureDefaults(photo);
    return `<div class="facebook-import-new-fixture">
        <label>Date <input type="date" class="form-control facebook-import-new-fixture-date" value="${esc(defaults.match_date)}"></label>
        <label>Opponent <input type="text" class="form-control facebook-import-new-fixture-opponent" value="${esc(defaults.opponent)}" placeholder="Opponent name"></label>
        <label>Venue <select class="form-select facebook-import-new-fixture-home">
          <option value="1"${defaults.is_home === "1" ? " selected" : ""}>Home</option>
          <option value="0"${defaults.is_home === "0" ? " selected" : ""}>Away</option>
        </select></label>
        <label>Competition <input type="text" class="form-control facebook-import-new-fixture-competition" value="${esc(defaults.competition)}" placeholder="Optional"></label>
        <button type="button" class="btn btn-outline-primary btn-sm facebook-import-new-fixture-save">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Add fixture
        </button>
      </div>`;
  }

  function peopleOptions(selectedId) {
    const grouped = people.reduce((acc, person) => {
      const category = person.category || "other";
      if (!acc[category]) acc[category] = [];
      acc[category].push(person);
      return acc;
    }, {});
    let html = '<option value="">Choose person...</option><option value="__add__">Add new person...</option>';
    ["player", "manager", "staff", "fan", "other"].forEach(category => {
      if (!grouped[category] || !grouped[category].length) return;
      html += `<optgroup label="${esc(categoryLabels[category] || category)}">`;
      grouped[category].forEach(person => {
        const selected = Number(person.id) === Number(selectedId) ? " selected" : "";
        html += `<option value="${person.id}"${selected}>${esc(person.name)}</option>`;
      });
      html += "</optgroup>";
    });
    return html;
  }

  function newPersonForm() {
    return `<div class="facebook-import-new-person">
        <input type="text" class="form-control facebook-import-new-person-name" placeholder="Name"><br />
        <select class="form-select facebook-import-new-person-category">
          <option value="staff">Staff / coach</option>
          <option value="manager">Manager</option>
          <option value="fan">Fan</option>
          <option value="other">Other</option>
        </select>
        <button type="button" class="btn btn-outline-primary btn-sm facebook-import-new-person-save">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Add
        </button>
      </div>`;
  }

  function dateLabel(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "Unknown date" : date.toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
  }

  function defaultKitFor(photo) {
    if (photo.kit) return photo.kit;
    const fixtureId = photo.fixture_id || (photo.suggestion && photo.suggestion.fixture_id);
    const fixture = fixtureById(fixtureId);
    return fixture ? fixture.default_kit : "home";
  }

  function matchFields(photo, selectedFixture) {
    const selectedKit = defaultKitFor(photo);
    return `<div class="facebook-import-fields facebook-import-fields--match" data-fields-mode="match">
        <label>Match <select class="form-select facebook-import-fixture">${fixtureOptions(selectedFixture)}</select></label>
        <label>Kit <select class="form-select facebook-import-kit">
          <option value="home"${selectedKit === "home" ? " selected" : ""}>Home kit</option>
          <option value="away"${selectedKit === "away" ? " selected" : ""}>Away kit</option>
          <option value="third"${selectedKit === "third" ? " selected" : ""}>Third kit</option>
        </select></label>
      </div>`;
  }

  function referenceFields(photo) {
    const referenceType = photo.reference_type || "single";
    return `<div class="facebook-import-fields facebook-import-fields--reference" data-fields-mode="reference">
        <label>Reference type <select class="form-select facebook-import-reference-type">
          <option value="single"${referenceType === "single" ? " selected" : ""}>Single person</option>
          <option value="group"${referenceType === "group" ? " selected" : ""}>Group photo</option>
        </select></label>
        ${referenceType === "group" ? groupReferenceFields(photo) : `<label>Person <select class="form-select facebook-import-person">${peopleOptions(photo.person_id || "")}</select></label>`}
      </div>`;
  }

  function groupReferenceFields(photo) {
    const faces = Array.isArray(photo.reference_faces) ? photo.reference_faces : [];
    const facesHtml = faces.length ? faces.map((face, index) => `
      <div class="facebook-import-face" data-face-index="${index}">
        ${face.thumbnail ? `<img src="${esc(face.thumbnail)}" alt="">` : '<div class="facebook-import-face__empty"></div>'}
        <label>Face ${index + 1}<select class="form-select facebook-import-face-person">
          ${peopleOptions(face.person_id || "")}
        </select></label>
        ${face.match_name ? `<div class="facebook-import-face-match"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> ${esc(face.match_name)}${face.match_score ? ` (${Math.round(Number(face.match_score) * 100)}%)` : ""}</div>` : ""}
        ${!face.match_name && face.possible_name ? `<div class="facebook-import-face-match is-possible"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> Possible: ${esc(face.possible_name)}${face.possible_score ? ` (${Math.round(Number(face.possible_score) * 100)}%)` : ""} - not selected</div>` : ""}
      </div>
    `).join("") : '<p class="facebook-import-face-help">Find faces, then assign each visible player or coach you recognise.</p>';

    return `<div class="facebook-import-group-reference">
        <button type="button" class="btn btn-outline-primary btn-sm facebook-import-detect-faces">
          <i class="fa-solid fa-user-tag" aria-hidden="true"></i> Find faces
        </button>
        <div class="facebook-import-faces">${facesHtml}</div>
      </div>`;
  }

  function cardMarkup(photo, index) {
    const suggestion = photo.suggestion || {};
    const confidence = Number(suggestion.confidence || 0);
    const selectedFixture = photo.fixture_id || suggestion.fixture_id || "";
    const reason = suggestion.reason || "No match suggestion";
    const message = photo.post_message || "";
    const mode = photo.save_mode || "match";
    const photoId = String(photo.facebook_photo_id || index);
    const imageAttrs = loadedImageIds.has(photoId)
      ? `src="${esc(photo.image_url)}"`
      : `data-src="${esc(photo.image_url)}"`;
    return `
      <article class="facebook-import-card" data-index="${index}" data-photo-id="${esc(photoId)}">
        <label class="facebook-import-card__check">
          <input type="checkbox" class="facebook-import-check"${selectedIds.has(photoId) ? " checked" : ""}>
          <span>Select</span>
        </label>
        <div class="facebook-import-card__image">
          <img ${imageAttrs} alt="" loading="lazy" referrerpolicy="no-referrer" class="facebook-import-lazy-image">
        </div>
        <div class="facebook-import-card__body">
          <div class="facebook-import-card__meta">
            <span>${esc(dateLabel(photo.created_time))}</span>
            ${photo.permalink_url ? `<a href="${esc(photo.permalink_url)}" target="_blank" rel="noopener">Post</a>` : ""}
          </div>
          <button type="button" class="facebook-import-card__reject" data-reject-one="${esc(photo.facebook_photo_id)}"><i class="fa-solid fa-ban" aria-hidden="true"></i> Reject</button>
          <p class="facebook-import-card__text">${esc(message).slice(0, 260) || "No post text returned by Facebook."}</p>
          <div class="facebook-import-suggestion ${confidence >= 70 ? "is-strong" : confidence >= 35 ? "is-medium" : "is-weak"}">
            ${confidence ? confidence + "% match - " : ""}${esc(reason)}
          </div>
          <div class="facebook-import-save-mode">
            <label>Save as <select class="form-select facebook-import-card-mode">
              <option value="match"${mode === "match" ? " selected" : ""}>Match photo</option>
              <option value="reference"${mode === "reference" ? " selected" : ""}>People reference</option>
            </select></label>
          </div>
          <div class="facebook-import-card-fields">
            ${mode === "reference" ? referenceFields(photo) : matchFields(photo, selectedFixture)}
          </div>
        </div>
      </article>
    `;
  }

  function updateSelection() {
    grid.querySelectorAll(".facebook-import-card").forEach(card => {
      const checkbox = card.querySelector(".facebook-import-check");
      const photo = photos[Number(card.dataset.index)];
      const id = photo && photo.facebook_photo_id ? String(photo.facebook_photo_id) : "";
      if (checkbox && id) {
        if (checkbox.checked) {
          selectedIds.add(id);
        } else {
          selectedIds.delete(id);
        }
      }
      card.classList.toggle("is-selected", !!checkbox && checkbox.checked);
    });
    const availableIds = new Set(photos.map(photo => String(photo.facebook_photo_id)));
    Array.from(selectedIds).forEach(id => {
      if (!availableIds.has(id)) selectedIds.delete(id);
    });
    const checked = selectedIds.size;
    selectedCount.textContent = String(checked);
    saveButton.disabled = checked === 0;
    if (rejectButton) rejectButton.disabled = checked === 0;
    selection.hidden = photos.length === 0;
  }

  function render() {
    grid.innerHTML = photos.map(cardMarkup).join("");
    observeLazyImages();
    updateLoadMoreUi();
    updateSelection();
  }

  const imageObserver = "IntersectionObserver" in window ? new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const img = entry.target;
      loadLazyImage(img);
      imageObserver.unobserve(img);
    });
  }, { rootMargin: "650px 0px" }) : null;

  const moreObserver = "IntersectionObserver" in window ? new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting && nextAfter && !isFetching && !isLoadingAll) {
        fetchPhotos(true);
      }
    });
  }, { rootMargin: "900px 0px" }) : null;

  function loadLazyImage(img) {
    const src = img.dataset.src;
    if (!src || img.src) return;
    const card = img.closest(".facebook-import-card");
    if (card && card.dataset.photoId) {
      loadedImageIds.add(String(card.dataset.photoId));
    }
    img.addEventListener("load", () => img.classList.add("is-loaded"), { once: true });
    img.src = src;
    img.removeAttribute("data-src");
  }

  function observeLazyImages() {
    const images = grid.querySelectorAll(".facebook-import-lazy-image[data-src]");
    if (!imageObserver) {
      images.forEach(loadLazyImage);
      return;
    }
    images.forEach(img => imageObserver.observe(img));
  }

  async function post(action, extra) {
    const data = new FormData();
    data.append("csrf_token", csrf);
    data.append("action", action);
    Object.entries(extra || {}).forEach(([key, value]) => data.append(key, value));
    const response = await fetch("/admin/facebook_photo_import.php", { method: "POST", body: data, credentials: "same-origin" });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(result.message || "Request failed.");
    }
    return result;
  }

  async function fetchPhotos(append) {
    if (isFetching || (!append && isLoadingAll) || (append && !nextAfter)) {
      return false;
    }
    isFetching = true;
    const button = append ? loadMoreButton : fetchButton;
    if (button) {
      button.disabled = true;
      button.innerHTML = append
        ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading...'
        : '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Fetching...';
    }
    if (!append && loadAllButton) loadAllButton.disabled = true;
    updateLoadMoreUi();
    if (!append) {
      grid.innerHTML = "";
      photos = [];
      nextAfter = "";
      loadedImageIds.clear();
      selectedIds.clear();
      updateSelection();
    }
    setStatus(append ? "Fetching older Facebook photos..." : "Fetching Facebook photos...", "");
    try {
      const result = await post("fetch", { limit: limitSelect.value, after: append ? nextAfter : "", album_id: albumSelect ? albumSelect.value : "" });
      const incoming = result.photos || [];
      let added = 0;
      incoming.forEach(photo => {
        if (!photo.save_mode) photo.save_mode = "match";
      });
      if (append) {
        const known = new Set(photos.map(photo => String(photo.facebook_photo_id)));
        incoming.forEach(photo => {
          if (!known.has(String(photo.facebook_photo_id))) {
            photos.push(photo);
            added++;
          }
        });
      } else {
        photos = incoming;
        added = incoming.length;
      }
      nextAfter = result.next_after || "";
      render();
      setStatus(photos.length ? `${photos.length} photo${photos.length === 1 ? "" : "s"} ready to review.` : "No photos were returned.", photos.length ? "success" : "warning");
      return added > 0 || !!nextAfter;
    } catch (error) {
      setStatus(error.message || "Could not fetch Facebook photos.", "error");
      return false;
    } finally {
      isFetching = false;
      if (button) {
        button.disabled = false;
        button.innerHTML = append
          ? `<i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Load ${esc(batchLabel())} more photos`
          : '<i class="fa-brands fa-facebook" aria-hidden="true"></i> Fetch photos';
      }
      updateLoadMoreUi();
    }
  }

  fetchButton.addEventListener("click", () => {
    fetchPhotos(false);
  });

  loadMoreButton.addEventListener("click", () => {
    if (nextAfter) fetchPhotos(true);
  });

  if (loadAllButton) {
    loadAllButton.addEventListener("click", async () => {
      if (!nextAfter || isFetching || isLoadingAll) return;
      isLoadingAll = true;
      fetchButton.disabled = true;
      loadAllButton.disabled = true;
      loadMoreButton.disabled = true;
      let batches = 0;
      try {
        while (nextAfter) {
          batches++;
          loadAllButton.innerHTML = `<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading batch ${batches}...`;
          const moved = await fetchPhotos(true);
          if (!moved) break;
          await new Promise(resolve => setTimeout(resolve, 120));
        }
        setStatus(`${photos.length} photo${photos.length === 1 ? "" : "s"} loaded from this source. Images will load as you scroll.`, photos.length ? "success" : "warning");
      } finally {
        isLoadingAll = false;
        fetchButton.disabled = false;
        if (loadAllButton) loadAllButton.innerHTML = '<i class="fa-solid fa-layer-group" aria-hidden="true"></i> Load all';
        updateLoadMoreUi();
      }
    });
  }

  if (moreObserver) {
    moreObserver.observe(loadMoreWrap);
  }

  if (limitSelect) {
    limitSelect.addEventListener("change", updateLoadMoreUi);
  }

  grid.addEventListener("change", event => {
    const card = event.target.closest(".facebook-import-card");
    if (!card) return;
    if (event.target.classList.contains("facebook-import-fixture")) {
      const photo = photos[Number(card.dataset.index)];
      if (event.target.value === "__add_fixture__") {
        showNewFixtureForm(event.target, photo);
        updateSelection();
        return;
      }
      const fixture = fixtureById(event.target.value);
      if (photo && fixture) {
        photo.fixture_id = event.target.value;
        photo.kit = fixture.default_kit || "home";
        card.querySelector(".facebook-import-kit").value = photo.kit;
        removeNewFixtureForm(event.target);
      }
    }
    if (event.target.classList.contains("facebook-import-kit")) {
      const photo = photos[Number(card.dataset.index)];
      if (photo) photo.kit = event.target.value;
    }
    if (event.target.classList.contains("facebook-import-person")) {
      const photo = photos[Number(card.dataset.index)];
      if (event.target.value === "__add__") {
        showNewPersonForm(event.target);
      } else if (photo) {
        photo.person_id = event.target.value;
        removeNewPersonForm(event.target);
      }
    }
    if (event.target.classList.contains("facebook-import-reference-type")) {
      const photo = photos[Number(card.dataset.index)];
      if (photo) {
        photo.reference_type = event.target.value;
        const fields = card.querySelector(".facebook-import-card-fields");
        if (fields) fields.innerHTML = referenceFields(photo);
        if (photo.reference_type === "group" && (!Array.isArray(photo.reference_faces) || photo.reference_faces.length === 0)) {
          const button = fields ? fields.querySelector(".facebook-import-detect-faces") : null;
          if (button) detectFaces(button);
        }
      }
    }
    if (event.target.classList.contains("facebook-import-face-person")) {
      const photo = photos[Number(card.dataset.index)];
      const face = event.target.closest(".facebook-import-face");
      const faceIndex = face ? Number(face.dataset.faceIndex) : -1;
      if (event.target.value === "__add__") {
        showNewPersonForm(event.target);
      } else if (photo && Array.isArray(photo.reference_faces) && photo.reference_faces[faceIndex]) {
        photo.reference_faces[faceIndex].person_id = event.target.value;
        removeNewPersonForm(event.target);
      }
    }
    if (event.target.classList.contains("facebook-import-card-mode")) {
      const photo = photos[Number(card.dataset.index)];
      if (photo) {
        photo.save_mode = event.target.value;
        const fields = card.querySelector(".facebook-import-card-fields");
        const selectedFixture = photo.fixture_id || (photo.suggestion && photo.suggestion.fixture_id) || "";
        if (fields) fields.innerHTML = photo.save_mode === "reference" ? referenceFields(photo) : matchFields(photo, selectedFixture);
      }
    }
    updateSelection();
  });

  grid.addEventListener("click", event => {
    const rejectOne = event.target.closest("[data-reject-one]");
    if (rejectOne) {
      rejectPhotos([rejectOne.dataset.rejectOne]);
      return;
    }
    const detectButton = event.target.closest(".facebook-import-detect-faces");
    if (detectButton) {
      detectFaces(detectButton);
      return;
    }
    const addPersonButton = event.target.closest(".facebook-import-new-person-save");
    if (addPersonButton) {
      addPerson(addPersonButton);
      return;
    }
    const addFixtureButton = event.target.closest(".facebook-import-new-fixture-save");
    if (addFixtureButton) {
      addFixture(addFixtureButton);
      return;
    }
    const image = event.target.closest(".facebook-import-card__image");
    if (!image) return;
    const card = image.closest(".facebook-import-card");
    const checkbox = card && card.querySelector(".facebook-import-check");
    if (!checkbox) return;
    checkbox.checked = !checkbox.checked;
    updateSelection();
  });

  function selectedPhotoIds() {
    updateSelection();
    return Array.from(selectedIds);
  }

  function showNewFixtureForm(select, photo) {
    removeNewFixtureForm(select);
    select.closest("label").insertAdjacentHTML("afterend", newFixtureForm(photo));
    const form = select.closest("label").nextElementSibling;
    const opponent = form && form.querySelector(".facebook-import-new-fixture-opponent");
    const date = form && form.querySelector(".facebook-import-new-fixture-date");
    if (opponent && opponent.value === "") {
      opponent.focus();
    } else if (date) {
      date.focus();
    }
  }

  function removeNewFixtureForm(select) {
    const label = select.closest("label");
    const next = label ? label.nextElementSibling : null;
    if (next && next.classList.contains("facebook-import-new-fixture")) {
      next.remove();
    }
  }

  function refreshFixtureSelects(selectedSelect, fixtureId) {
    grid.querySelectorAll(".facebook-import-fixture").forEach(select => {
      const value = select === selectedSelect ? String(fixtureId) : select.value;
      select.innerHTML = fixtureOptions(value);
      select.value = value;
    });
  }

  function syncFixtureSelection(select, fixtureId) {
    const card = select.closest(".facebook-import-card");
    const photo = card ? photos[Number(card.dataset.index)] : null;
    const fixture = fixtureById(fixtureId);
    if (!photo || !fixture) return;
    photo.fixture_id = String(fixtureId);
    photo.kit = fixture.default_kit || "home";
    const kit = card.querySelector(".facebook-import-kit");
    if (kit) kit.value = photo.kit;
  }

  async function addFixture(button) {
    const form = button.closest(".facebook-import-new-fixture");
    const fieldWrap = form ? form.parentElement : null;
    const select = fieldWrap ? fieldWrap.querySelector(".facebook-import-fixture") : null;
    const dateInput = form ? form.querySelector(".facebook-import-new-fixture-date") : null;
    const opponentInput = form ? form.querySelector(".facebook-import-new-fixture-opponent") : null;
    const homeInput = form ? form.querySelector(".facebook-import-new-fixture-home") : null;
    const competitionInput = form ? form.querySelector(".facebook-import-new-fixture-competition") : null;
    const matchDate = dateInput ? dateInput.value : "";
    const opponent = opponentInput ? opponentInput.value.trim() : "";
    if (!select || !matchDate || !opponent) {
      setStatus("Enter a date and opponent before adding the fixture.", "warning");
      if (opponentInput && !opponent) opponentInput.focus();
      return;
    }

    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Adding...';
    try {
      const result = await post("add_fixture", {
        match_date: matchDate,
        opponent,
        is_home: homeInput ? homeInput.value : "1",
        competition: competitionInput ? competitionInput.value.trim() : ""
      });
      fixtures = Array.isArray(result.fixtures) ? result.fixtures : fixtures.concat([result.fixture]);
      const fixtureId = result.fixture && result.fixture.id ? result.fixture.id : "";
      syncFixtureSelection(select, fixtureId);
      render();
      setStatus(`Fixture added: ${result.fixture ? result.fixture.label : opponent}.`, "success");
    } catch (error) {
      setStatus(error.message || "Could not add that fixture.", "error");
      button.disabled = false;
      button.innerHTML = oldHtml;
    }
  }

  function showNewPersonForm(select) {
    removeNewPersonForm(select);
    select.closest("label").insertAdjacentHTML("afterend", newPersonForm());
    const form = select.closest("label").nextElementSibling;
    const name = form && form.querySelector(".facebook-import-new-person-name");
    if (name) name.focus();
  }

  function removeNewPersonForm(select) {
    const label = select.closest("label");
    const next = label ? label.nextElementSibling : null;
    if (next && next.classList.contains("facebook-import-new-person")) {
      next.remove();
    }
  }

  function refreshPeopleSelects(selectedSelect, selectedPersonId) {
    grid.querySelectorAll(".facebook-import-person, .facebook-import-face-person").forEach(select => {
      const value = select === selectedSelect ? String(selectedPersonId) : select.value;
      select.innerHTML = peopleOptions(value);
      select.value = value;
    });
  }

  function syncPersonSelection(select, personId) {
    const card = select.closest(".facebook-import-card");
    const photo = card ? photos[Number(card.dataset.index)] : null;
    if (!photo) return;
    if (select.classList.contains("facebook-import-person")) {
      photo.person_id = String(personId);
      return;
    }
    const face = select.closest(".facebook-import-face");
    const faceIndex = face ? Number(face.dataset.faceIndex) : -1;
    if (Array.isArray(photo.reference_faces) && photo.reference_faces[faceIndex]) {
      photo.reference_faces[faceIndex].person_id = String(personId);
    }
  }

  async function addPerson(button) {
    const form = button.closest(".facebook-import-new-person");
    const fieldWrap = form ? form.parentElement : null;
    const select = fieldWrap ? fieldWrap.querySelector(".facebook-import-person, .facebook-import-face-person") : null;
    const nameInput = form ? form.querySelector(".facebook-import-new-person-name") : null;
    const categoryInput = form ? form.querySelector(".facebook-import-new-person-category") : null;
    const name = nameInput ? nameInput.value.trim() : "";
    const category = categoryInput ? categoryInput.value : "other";
    if (!select || !name) {
      setStatus("Enter a name before adding the person.", "warning");
      if (nameInput) nameInput.focus();
      return;
    }

    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Adding...';
    try {
      const result = await post("add_person", { name, category });
      people = Array.isArray(result.people) ? result.people : people.concat([result.person]);
      const personId = result.person && result.person.id ? result.person.id : "";
      refreshPeopleSelects(select, personId);
      syncPersonSelection(select, personId);
      removeNewPersonForm(select);
      setStatus(`${name} added.`, "success");
    } catch (error) {
      setStatus(error.message || "Could not add that person.", "error");
      button.disabled = false;
      button.innerHTML = oldHtml;
    }
  }

  async function detectFaces(button) {
    const card = button.closest(".facebook-import-card");
    const photo = card ? photos[Number(card.dataset.index)] : null;
    if (!card || !photo || !photo.image_url) {
      setStatus("Choose a Facebook photo before finding faces.", "warning");
      return;
    }
    const oldHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Finding...';
    setStatus("Finding faces in the selected group photo...", "");
    try {
      const result = await post("detect_faces", { image_url: photo.image_url });
      photo.reference_type = "group";
      photo.reference_faces = Array.isArray(result.faces) ? result.faces.map(face => {
        if (face.person_id && !face.match_score && face.match) {
          face.match_score = face.match.score || 0;
        }
        return face;
      }) : [];
      const fields = card.querySelector(".facebook-import-card-fields");
      if (fields) fields.innerHTML = referenceFields(photo);
      const matched = photo.reference_faces.filter(face => face.person_id).length;
      setStatus(photo.reference_faces.length ? `${photo.reference_faces.length} face${photo.reference_faces.length === 1 ? "" : "s"} found, ${matched} preselected from known people. Check the assignments before saving.` : "No usable faces were found in that photo.", photo.reference_faces.length ? "success" : "warning");
    } catch (error) {
      setStatus(error.message || "Could not find faces in that photo.", "error");
    } finally {
      button.disabled = false;
      button.innerHTML = oldHtml;
    }
  }

  async function rejectPhotos(photoIds) {
    const ids = Array.from(new Set((photoIds || []).filter(Boolean).map(String)));
    if (!ids.length) {
      setStatus("Select at least one photo to reject.", "warning");
      return;
    }
    const oldRejectHtml = rejectButton ? rejectButton.innerHTML : "";
    if (rejectButton) {
      rejectButton.disabled = true;
      rejectButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Rejecting...';
    }
    try {
      await post("reject", { photo_ids: JSON.stringify(ids) });
      const rejected = new Set(ids);
      rejected.forEach(id => selectedIds.delete(id));
      photos = photos.filter(photo => !rejected.has(String(photo.facebook_photo_id)));
      render();
      setStatus(`${ids.length} photo${ids.length === 1 ? "" : "s"} rejected. They will not be loaded again.`, "success");
    } catch (error) {
      setStatus(error.message || "Could not reject selected photos.", "error");
      updateSelection();
    } finally {
      if (rejectButton) {
        rejectButton.innerHTML = oldRejectHtml || '<i class="fa-solid fa-ban" aria-hidden="true"></i> Reject selected';
        updateSelection();
      }
    }
  }

  if (rejectButton) {
    rejectButton.addEventListener("click", () => {
      rejectPhotos(selectedPhotoIds());
    });
  }

  saveButton.addEventListener("click", async () => {
    const items = [];
    grid.querySelectorAll(".facebook-import-card").forEach(card => {
      const checkbox = card.querySelector(".facebook-import-check");
      if (!checkbox || !checkbox.checked) return;
      const photo = photos[Number(card.dataset.index)];
      if (!photo) return;
      const modeControl = card.querySelector(".facebook-import-card-mode");
      const mode = modeControl ? modeControl.value : (photo.save_mode || "match");
      const base = {
        facebook_photo_id: photo.facebook_photo_id,
        facebook_post_id: photo.facebook_post_id,
        image_url: photo.image_url,
        post_message: photo.post_message || "",
        save_mode: mode
      };
      if (mode === "reference") {
        const referenceType = card.querySelector(".facebook-import-reference-type");
        const type = referenceType ? referenceType.value : (photo.reference_type || "single");
        if (type === "group") {
          const referenceFaces = [];
          card.querySelectorAll(".facebook-import-face").forEach(face => {
            const faceIndex = Number(face.dataset.faceIndex);
            const person = face.querySelector(".facebook-import-face-person");
            const detectedFace = Array.isArray(photo.reference_faces) ? photo.reference_faces[faceIndex] : null;
            if (person && person.value && detectedFace && detectedFace.box) {
              referenceFaces.push({ person_id: person.value, box: detectedFace.box });
            }
          });
          if (referenceFaces.length) items.push(Object.assign(base, { reference_type: "group", reference_faces: referenceFaces }));
        } else {
          const person = card.querySelector(".facebook-import-person");
          if (person && person.value) items.push(Object.assign(base, { reference_type: "single", person_id: person.value }));
        }
      } else {
        const fixture = card.querySelector(".facebook-import-fixture");
        const kit = card.querySelector(".facebook-import-kit");
        if (fixture && fixture.value && kit) items.push(Object.assign(base, { fixture_id: fixture.value, kit: kit.value }));
      }
    });

    if (!items.length) {
      setStatus("Select photos and complete the match/person fields before saving.", "warning");
      return;
    }

    saveButton.disabled = true;
    saveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Saving...';
    setStatus(`Downloading and importing ${items.length} selected photo${items.length === 1 ? "" : "s"}...`, "");
    try {
      const result = await post("import", { items: JSON.stringify(items), mode: "mixed" });
      const summary = result.summary || {};
      const errors = (summary.errors || []).slice(0, 4).join(" ");
      setStatus(`${summary.imported || 0} photo${(summary.imported || 0) === 1 ? "" : "s"} imported, ${summary.skipped || 0} skipped, ${summary.tagged || 0} auto-tag${(summary.tagged || 0) === 1 ? "" : "s"} applied.${errors ? " " + errors : ""}`, "success");
      const savedIds = new Set(items.map(item => String(item.facebook_photo_id)));
      savedIds.forEach(id => selectedIds.delete(id));
      photos = photos.filter(photo => !savedIds.has(String(photo.facebook_photo_id)));
      render();
    } catch (error) {
      setStatus(error.message || "Import failed.", "error");
    } finally {
      saveButton.disabled = false;
      saveButton.innerHTML = '<i class="fa-solid fa-download" aria-hidden="true"></i> Save selected';
      updateSelection();
    }
  });
})();
