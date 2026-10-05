// assets/js/people.js
$(function () {
  function showError(msg = "Request failed") {
    window.alert(msg);
  }

  function csrfToken() {
    return $("#peopleCsrf").data("csrf") || "";
  }

  // === Inline rename ===
  $(document).on("change", ".people-name-input", function () {
    var input = $(this);
    var name = input.val().trim();
    if (!name) {
      showError("Name is required");
      return;
    }
    var category = input.closest(".people-card__header").find(".people-category-select").val();
    $.post("people_ajax.php", {
      action: "update_person",
      csrf_token: csrfToken(),
      id: input.data("id"),
      name: name,
      category: category,
    }).done((resp) => {
      if (!resp.success) showError(resp.error || "Error saving name");
    }).fail(() => showError());
  });

  // === Inline category change ===
  $(document).on("change", ".people-category-select", function () {
    var select = $(this);
    var card = select.closest(".people-card");
    var name = card.find(".people-name-input").val().trim();
    $.post("people_ajax.php", {
      action: "update_person",
      csrf_token: csrfToken(),
      id: select.data("id"),
      name: name,
      category: select.val(),
    }).done((resp) => {
      if (resp.success) {
        location.reload();
      } else {
        showError(resp.error || "Error saving category");
      }
    }).fail(() => showError());
  });

  // === Reference photo dropzones (one per person) ===
  $(".people-photo-upload-form").each(function () {
    var form = $(this);
    var dropzone = form.find(".people-photo-dropzone");
    var input = form.find('input[name="photos[]"]');

    function submitIfReady() {
      if (input[0].files && input[0].files.length > 0) {
        form[0].submit();
      }
    }

    input.on("change", submitIfReady);

    dropzone.on("dragover", function (e) {
      e.preventDefault();
      e.stopPropagation();
      form.addClass("drag-over");
    });

    dropzone.on("dragleave", function (e) {
      e.preventDefault();
      e.stopPropagation();
      form.removeClass("drag-over");
    });

    dropzone.on("drop", function (e) {
      e.preventDefault();
      e.stopPropagation();
      form.removeClass("drag-over");
      var dt = e.originalEvent.dataTransfer;
      if (!dt || !dt.files || dt.files.length === 0) return;
      input[0].files = dt.files;
      submitIfReady();
    });
  });
});
