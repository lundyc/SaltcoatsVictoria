// assets/js/player_edit.js
$(function () {
  function showError(msg = "Request failed") {
    window.alert(msg);
  }

  $("#playerSelect").on("change", function () {
    const playerId = $(this).val();
    if (playerId) {
      window.location.href = "player_edit.php?id=" + encodeURIComponent(playerId);
    }
  });
  const avatarDropzone = $('#avatarDropzone');
  const avatarInput = $('#avatarUploadForm input[name="avatar"]');

  function submitAvatarFormIfReady() {
    const input = avatarInput[0];
    if (input && input.files && input.files.length > 0) {
      $('#avatarUploadForm')[0].submit();
    }
  }

  avatarInput.on('change', submitAvatarFormIfReady);

  avatarDropzone.on('dragover', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass('drag-over');
  });

  avatarDropzone.on('dragleave', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('drag-over');
  });

  avatarDropzone.on('drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('drag-over');
    const dt = e.originalEvent.dataTransfer;
    if (!dt || !dt.files || dt.files.length === 0) return;
    avatarInput[0].files = dt.files;
    submitAvatarFormIfReady();
  });

  const actionShotDropzone = $('#actionShotDropzone');
  const actionShotInput = $('#actionShotUploadForm input[name="action_shots[]"]');

  function submitActionShotFormIfReady() {
    const input = actionShotInput[0];
    if (input && input.files && input.files.length > 0) {
      $('#actionShotUploadForm')[0].submit();
    }
  }

  actionShotInput.on('change', submitActionShotFormIfReady);

  actionShotDropzone.on('dragover', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).closest('.player-action-shot--add').addClass('drag-over');
  });

  actionShotDropzone.on('dragleave', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).closest('.player-action-shot--add').removeClass('drag-over');
  });

  actionShotDropzone.on('drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).closest('.player-action-shot--add').removeClass('drag-over');
    const dt = e.originalEvent.dataTransfer;
    if (!dt || !dt.files || dt.files.length === 0) return;
    actionShotInput[0].files = dt.files;
    submitActionShotFormIfReady();
  });

  // === Mark Paid ===
  $(document).on("click", ".mark-paid", function () {
    const id = $(this).data("id");
    const remaining = $(this).data("remaining");

    $.post("player_edit_ajax.php", {
      action: "mark_paid",
      id,
      amount: remaining,
    })
      .done((resp) => {
        if (resp.success) {
          setTimeout(() => location.reload(), 600);
        } else {
          showError(resp.error || "Error marking as paid");
        }
      })
      .fail(() => {
        showError();
      });
  });

  // === Inline player updates ===
  // Name → live save while typing
  $("#playerName").on("input", function () {
    const el = $(this);
    $.post("player_edit_ajax.php", {
      action: "update_player",
      id: el.data("id"),
      field: el.data("field"),
      value: el.val(),
    })
      .done((resp) => {
        if (!resp.success) showError(resp.error || "Error saving player");
      })
      .fail(() => showError());
  });

  function syncStatusChange(el) {
    const value = el.val();
    if (value === 'left') {
      const leftDate = $('#playerLeft');
      if (!leftDate.val()) {
        leftDate.val(new Date().toISOString().slice(0, 10)).trigger('change');
      }
      const activeCheckbox = $('#playerActive');
      if (activeCheckbox.is(':checked')) {
        activeCheckbox.prop('checked', false).trigger('change');
      }
    }
  }

  // Other fields → save on change
  $("[data-field]").not("#playerName").on("change", function () {
    const el = $(this);
    if (el.data('field') === 'status') {
      syncStatusChange(el);
    }

    let value =
      el.attr("type") === "checkbox" ? (el.is(":checked") ? 1 : 0) : el.val();

    $.post("player_edit_ajax.php", {
      action: "update_player",
      id: el.data("id"),
      field: el.data("field"),
      value,
    })
      .done((resp) => {
        if (!resp.success) showError(resp.error || "Error saving player");
      })
      .fail(() => showError());
  });

  // === Inline sponsorship notes (save on blur/change only) ===
  $(".inline-note").on("blur change", function () {
    const el = $(this);
    $.post("player_edit_ajax.php", {
      action: "update_sponsorship",
      id: el.data("id"),
      field: el.data("field"),
      value: el.val(),
    })
      .done((resp) => {
        if (!resp.success) showError(resp.error || "Error saving sponsorship");
      })
      .fail(() => showError());
  });

  // === Delete sponsorship ===
  $(".delete-sponsorship").on("click", function (e) {
    e.preventDefault();
    if (!confirm("Delete this sponsorship?")) return;
    const id = $(this).data("id");

    $.post("player_edit_ajax.php", { action: "delete_sponsorship", id })
      .done((resp) => {
        if (resp.success) {
          $(`tr[data-id='${id}']`).fadeOut(300, function () {
            $(this).remove();
          });
        } else {
          showError(resp.error || "Error deleting sponsorship");
        }
      })
      .fail(() => {
        showError();
      });
  });

  // === Add new sponsorship ===
  const addSponsorshipComplimentary = $("#addSponsorshipComplimentary");
  const addSponsorshipAmountField = $("#addSponsorshipForm .player-edit-amount-field");

  function toggleAddSponsorshipAmount() {
    const isComplimentary = addSponsorshipComplimentary.is(":checked");
    addSponsorshipAmountField.toggleClass("is-hidden", isComplimentary);
    if (isComplimentary) {
      addSponsorshipAmountField.find("input").val("");
    }
  }

  addSponsorshipComplimentary.on("change", toggleAddSponsorshipAmount);
  toggleAddSponsorshipAmount();

  $("#addSponsorshipForm").on("submit", function (e) {
    e.preventDefault();
    const form = $(this);

    $.post("player_edit_ajax.php", form.serialize() + "&action=add_sponsorship")
      .done((resp) => {
        if (resp.success) {
          setTimeout(() => location.reload(), 600);
        } else {
          showError(resp.error || "Error adding sponsorship");
        }
      })
      .fail(() => {
        showError();
      });
  });

  // === Transfer sponsorships to a replacement player ===
  $("#transferSponsorshipForm").on("submit", function (e) {
    e.preventDefault();
    const form = $(this);

    $.post("player_edit_ajax.php", form.serialize())
      .done((resp) => {
        if (resp.success) {
          setTimeout(() => location.reload(), 700);
        } else {
          showError(resp.error || "Error transferring sponsorships");
        }
      })
      .fail(() => {
        showError();
      });
  });

  // Enable tooltips
  $("[data-bs-toggle='tooltip']").tooltip();
});
