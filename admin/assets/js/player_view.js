$(function () {
  const playerId = new URLSearchParams(window.location.search).get("id");
  const playerViewUrl = `player_view.php${playerId ? `?id=${encodeURIComponent(playerId)}` : ""}`;
  const modal = document.getElementById("addPaymentModal");

  if (modal) {
    modal.addEventListener("show.bs.modal", function (event) {
      const button = event.relatedTarget;
      if (!button) return;

      const sponsorId = button.getAttribute("data-sponsor") || "";
      const remaining = Number.parseFloat(button.getAttribute("data-remaining") || "0");
      const sponsorInput = document.getElementById("modalSponsorId");
      const remainingInput = document.getElementById("modalRemaining");
      const amountInput = document.getElementById("modalAmount");
      const fillRemaining = document.getElementById("fillRemaining");

      if (sponsorInput) sponsorInput.value = sponsorId;
      if (remainingInput) remainingInput.value = remaining.toFixed(2);
      if (amountInput) amountInput.value = "";

      if (fillRemaining && amountInput) {
        fillRemaining.onclick = function () {
          amountInput.value = remaining.toFixed(2);
        };
      }
    });
  }

  const modalSponsorshipComplimentary = $("#modalSponsorshipComplimentary");
  const modalSponsorshipAmountField = $("#modalSponsorshipAmountField");

  function toggleModalSponsorshipAmount() {
    const isComplimentary = modalSponsorshipComplimentary.is(":checked");
    modalSponsorshipAmountField.toggle(!isComplimentary);
    if (isComplimentary) {
      modalSponsorshipAmountField.find("input").val("");
    }
  }

  modalSponsorshipComplimentary.on("change", toggleModalSponsorshipAmount);
  toggleModalSponsorshipAmount();

  $("#addSponsorshipForm").on("submit", function (e) {
    e.preventDefault();
    $.post("player_edit_ajax.php", $(this).serialize() + "&action=add_sponsorship", function (resp) {
      if (resp.success) {
        location.reload();
      } else {
        alert(resp.error || "Error adding sponsorship");
      }
    }, "json").fail(function () {
      alert("Error adding sponsorship");
    });
  });

  $("#addPaymentForm").on("submit", function (e) {
    e.preventDefault();
    $.post(playerViewUrl, $(this).serialize() + "&ajax_add_payment=1", function (resp) {
      if (resp.success) {
        location.reload();
      } else {
        alert("Error saving payment");
      }
    }, "json");
  });

  $(document).on("click", ".delete-payment", function (e) {
    e.preventDefault();
    if (!confirm("Are you sure you want to delete this payment?")) return;

    const row = $(this).closest("tr");
    const paymentId = $(this).data("id");

    $.post(playerViewUrl, {
      ajax_delete_payment: 1,
      payment_id: paymentId,
      csrf_token: window.PLAYER_VIEW_CSRF || "",
    }, function (resp) {
      if (resp && resp.success) {
        row.css("background-color", "#f8d7da").fadeOut(400, function () {
          window.location.reload();
        });
      } else {
        alert((resp && resp.error) ? resp.error : "Error deleting payment");
      }
    }, "json").fail(function (xhr) {
      let message = "Error deleting payment";
      try {
        const parsed = JSON.parse(xhr.responseText);
        if (parsed && parsed.error) message = parsed.error;
      } catch (e) {}
      alert(message);
    });
  });

  $("#addNoteForm").on("submit", function (e) {
    e.preventDefault();

    const form = $(this);
    const noteText = form.find('textarea[name="note"]').val();

    $.ajax({
      url: playerViewUrl,
      method: "POST",
      data: form.serialize() + "&ajax_add_note=1",
      dataType: "json",
      success: function (resp) {
        if (resp.success) {
          const timestamp = new Date().toLocaleString("en-GB", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
          });
          const safeNote = $("<div>").text(noteText).html().replace(/\n/g, "<br>");
          const newNote = `
          <li class="list-group-item">
            <div class="d-flex justify-content-between">
              <span>${safeNote}</span>
              <small class="text-muted">${timestamp}</small>
            </div>
          </li>`;

          $("#notesList").prepend(newNote);
          form[0].reset();

          const successToast = document.getElementById("toastNoteSuccess");
          if (successToast) new bootstrap.Toast(successToast).show();
        } else {
          const errorToast = document.getElementById("toastNoteError");
          if (errorToast) new bootstrap.Toast(errorToast).show();
        }
      },
      error: function () {
        const errorToast = document.getElementById("toastNoteError");
        if (errorToast) new bootstrap.Toast(errorToast).show();
      },
    });
  });
});

// Action-shots lightbox — click a thumbnail to open, arrows/keys/thumbnail
// strip to browse. Ported from the public site's [data-lightbox] pattern.
(function () {
  const grid = document.querySelector("[data-lightbox]");
  const lightbox = document.getElementById("actionShotsLightbox");
  if (!grid || !lightbox) return;

  const img = lightbox.querySelector(".lightbox__img");
  const countEl = lightbox.querySelector(".lightbox__count");
  const captionEl = lightbox.querySelector(".lightbox__caption");
  const thumbsWrap = lightbox.querySelector(".lightbox__thumbs");
  const stage = lightbox.querySelector(".lightbox__stage");
  const items = Array.from(grid.querySelectorAll("[data-full]"));
  const thumbEls = [];
  let current = 0;

  if (items.length < 2) lightbox.classList.add("lightbox--single");

  items.forEach((item, index) => {
    const innerImg = item.querySelector("img");
    const thumb = document.createElement("button");
    thumb.type = "button";
    thumb.className = "lightbox__thumb";
    thumb.setAttribute("aria-label", "Show image " + (index + 1));
    const thumbImg = document.createElement("img");
    thumbImg.src = innerImg ? innerImg.getAttribute("src") : item.getAttribute("data-full");
    thumbImg.alt = "";
    thumb.appendChild(thumbImg);
    thumb.addEventListener("click", () => show(index));
    if (thumbsWrap) thumbsWrap.appendChild(thumb);
    thumbEls.push(thumb);
  });

  function show(index) {
    current = (index + items.length) % items.length;
    img.src = items[current].getAttribute("data-full");
    const caption = items[current].getAttribute("data-caption") || "";
    if (captionEl) { captionEl.textContent = caption; captionEl.hidden = caption === ""; }
    if (countEl) countEl.textContent = (current + 1) + " / " + items.length;
    thumbEls.forEach((thumb, i) => thumb.classList.toggle("is-active", i === current));
    const active = thumbEls[current];
    if (active && active.scrollIntoView) active.scrollIntoView({ inline: "center", block: "nearest" });
  }

  function open(index) {
    show(index);
    lightbox.hidden = false;
    document.body.classList.add("lightbox-open");
    const closeButton = lightbox.querySelector(".lightbox__close");
    if (closeButton) closeButton.focus();
  }

  function close() {
    lightbox.hidden = true;
    img.src = "";
    document.body.classList.remove("lightbox-open");
  }

  grid.addEventListener("click", (event) => {
    const item = event.target.closest("[data-full]");
    if (!item) return;
    event.preventDefault();
    open(items.indexOf(item));
  });

  lightbox.addEventListener("click", (event) => {
    if (event.target.closest(".lightbox__nav--next")) show(current + 1);
    else if (event.target.closest(".lightbox__nav--prev")) show(current - 1);
    else if (event.target.closest(".lightbox__thumb")) { /* handled per-thumb */ }
    else if (event.target === lightbox || event.target === stage || event.target.closest(".lightbox__close")) close();
  });

  document.addEventListener("keydown", (event) => {
    if (lightbox.hidden) return;
    if (event.key === "Escape") close();
    else if (event.key === "ArrowRight") show(current + 1);
    else if (event.key === "ArrowLeft") show(current - 1);
  });
})();
