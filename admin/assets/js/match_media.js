// assets/js/match_media.js
$(function () {
  function showError(msg = "Request failed") {
    window.alert(msg);
  }

  function csrfToken() {
    return $("#matchMediaCsrf").data("csrf") || "";
  }

  // === Upload dropzone ===
  var dropzone = $("#matchPhotoDropzone");
  var input = $("#matchPhotoInput");
  var fileList = $("#matchPhotoFileList");

  function describeFiles(files) {
    if (!files || files.length === 0) {
      fileList.text("");
      return;
    }
    fileList.text(files.length === 1 ? files[0].name : files.length + " photos selected");
  }

  input.on("change", function () {
    describeFiles(this.files);
  });

  dropzone.on("dragover", function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass("drag-over");
  });

  dropzone.on("dragleave", function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass("drag-over");
  });

  dropzone.on("drop", function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass("drag-over");
    var dt = e.originalEvent.dataTransfer;
    if (!dt || !dt.files || dt.files.length === 0) return;
    input[0].files = dt.files;
    describeFiles(dt.files);
  });

  // === Chunked upload ===
  // Uploading everything in one request can hit server-side caps (max file count,
  // request size, execution time) once a batch gets into the dozens of photos — so
  // this always splits into small sequential requests instead, how ever many photos
  // are selected.
  var UPLOAD_CHUNK_SIZE = 10;

  $("#matchPhotoUploadForm").on("submit", function (e) {
    e.preventDefault();
    var form = $(this);
    var files = input[0].files;
    if (!files || files.length === 0) return;

    var fixtureId = form.find("[name='fixture_id']").val();
    var seasonId = form.find("input[name='season_id']").val() || "";
    var kit = form.find("select[name='kit']").val();

    if (!fixtureId) {
      showError("Choose a match first");
      return;
    }

    var chunks = [];
    for (var i = 0; i < files.length; i += UPLOAD_CHUNK_SIZE) {
      chunks.push(Array.prototype.slice.call(files, i, i + UPLOAD_CHUNK_SIZE));
    }

    var totalFiles = files.length;
    var uploadedSoFar = 0;
    var taggedSoFar = 0;
    var allErrors = [];
    var uploadButton = $("#matchPhotoUploadButton");
    var progressWrap = $("#matchPhotoProgress");
    var progressBar = $("#matchPhotoProgressBar");
    var progressLabel = $("#matchPhotoProgressLabel");

    uploadButton.prop("disabled", true);
    dropzone.addClass("d-none");
    progressWrap.removeClass("d-none");
    progressBar.css("width", "0%");
    progressLabel.text("Uploading 0 of " + totalFiles + "…");

    function finish() {
      var target = form.data("redirect") || "match_media.php";
      var params =
        target === "match_media.php"
          ? ["id=" + encodeURIComponent(fixtureId), "season_id=" + encodeURIComponent(seasonId)]
          : ["fixture_id=" + encodeURIComponent(fixtureId)];
      params.push("bulk_uploaded=" + encodeURIComponent(uploadedSoFar));
      params.push("bulk_tagged=" + encodeURIComponent(taggedSoFar));
      if (allErrors.length) {
        params.push("bulk_errors=" + encodeURIComponent(allErrors.slice(0, 5).join(" ")));
      }
      window.location.href = target + "?" + params.join("&");
    }

    function uploadChunk(index) {
      if (index >= chunks.length) {
        finish();
        return;
      }

      var formData = new FormData();
      formData.append("csrf_token", csrfToken());
      formData.append("fixture_id", fixtureId);
      formData.append("season_id", seasonId);
      formData.append("kit", kit);
      chunks[index].forEach(function (file) {
        formData.append("photos[]", file);
      });

      $.ajax({
        url: "match_photo_upload.php",
        method: "POST",
        data: formData,
        processData: false,
        contentType: false,
        headers: { "X-Requested-With": "XMLHttpRequest" },
      })
        .done(function (resp) {
          if (resp && resp.success) {
            uploadedSoFar += resp.uploaded || 0;
            taggedSoFar += resp.tagged || 0;
            if (resp.errors && resp.errors.length) {
              allErrors = allErrors.concat(resp.errors);
            }
          } else {
            allErrors.push((resp && resp.error) || "A batch of photos failed to upload.");
          }
        })
        .fail(function () {
          allErrors.push("A batch of photos failed to upload (network error).");
        })
        .always(function () {
          var percent = Math.round(((index + 1) / chunks.length) * 100);
          progressBar.css("width", percent + "%");
          progressLabel.text("Uploaded " + uploadedSoFar + " of " + totalFiles + "…");
          uploadChunk(index + 1);
        });
    }

    uploadChunk(0);
  });

  function appendTagChip(tagsWrap, personId, personName, tagId) {
    if (tagsWrap.find("[data-person-id='" + personId + "']").length > 0) return;
    var chip = $(
      '<span class="match-media-tag match-media-tag--manual" data-person-id="' + personId + '"></span>'
    )
      .text(personName)
      .append(
        $('<button type="button" class="match-media-tag__remove" title="Remove tag"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>').attr(
          "data-tag-id",
          tagId
        )
      );
    tagsWrap.append(chip);
  }

  var CATEGORY_LABELS = { manager: "Manager", staff: "Staff", fan: "Fan", other: "Other" };

  function appendOptionToAllSelects(category, personId, personName) {
    var label = CATEGORY_LABELS[category] || category;
    $(".match-media-add-tag select[name='person_id']").each(function () {
      var select = $(this);
      var group = select.find("optgroup[label='" + label + "']");
      var option = $('<option></option>').attr("value", personId).text(personName);
      if (group.length) {
        group.append(option);
      } else {
        select.find("option[value='__new__']").before(option);
      }
    });
  }

  // === Add a tag ===
  $(document).on("change", ".match-media-add-tag select[name='person_id']", function () {
    var select = $(this);
    var value = select.val();
    if (!value) return;

    if (value === "__new__") {
      select.val("");
      var form = select.closest(".match-media-item__body").find(".match-media-new-person");
      form.removeClass("d-none").find("input[name='name']").trigger("focus");
      return;
    }

    var personId = value;
    var personName = select.find("option:selected").text();
    var photoId = select.closest(".match-media-add-tag").data("photo-id");

    $.post("match_photo_ajax.php", {
      action: "add_tag",
      csrf_token: csrfToken(),
      photo_id: photoId,
      person_id: personId,
    })
      .done((resp) => {
        if (!resp.success) {
          showError(resp.error || "Error adding tag");
          return;
        }
        var tagsWrap = select.closest(".match-media-item__body").find(".match-media-tags");
        appendTagChip(tagsWrap, personId, resp.person_name || personName, resp.tag_id);
        select.val("");
      })
      .fail(() => showError());
  });

  // === Add a brand new person, tagged straight into this photo ===
  $(document).on("submit", ".match-media-new-person", function (e) {
    e.preventDefault();
    var form = $(this);
    var photoId = form.data("photo-id");
    var name = form.find("input[name='name']").val().trim();
    var category = form.find("select[name='category']").val();
    if (!name) return;

    $.post("match_photo_ajax.php", {
      action: "create_and_tag",
      csrf_token: csrfToken(),
      photo_id: photoId,
      name: name,
      category: category,
    })
      .done((resp) => {
        if (!resp.success) {
          showError(resp.error || "Error adding person");
          return;
        }
        var tagsWrap = form.closest(".match-media-item__body").find(".match-media-tags");
        appendTagChip(tagsWrap, resp.person_id, resp.person_name, resp.tag_id);
        appendOptionToAllSelects(resp.category, resp.person_id, resp.person_name);
        form.addClass("d-none")[0].reset();
      })
      .fail(() => showError());
  });

  $(document).on("click", ".match-media-new-person__cancel", function () {
    var form = $(this).closest(".match-media-new-person");
    form.addClass("d-none")[0].reset();
  });

  // === Remove a tag ===
  $(document).on("click", ".match-media-tag__remove", function () {
    var button = $(this);
    var chip = button.closest(".match-media-tag");
    var tagId = button.data("tag-id");

    if (!tagId) {
      chip.remove();
      return;
    }

    $.post("match_photo_ajax.php", {
      action: "remove_tag",
      csrf_token: csrfToken(),
      tag_id: tagId,
    })
      .done((resp) => {
        if (resp.success) {
          chip.fadeOut(200, function () {
            $(this).remove();
          });
        } else {
          showError(resp.error || "Error removing tag");
        }
      })
      .fail(() => showError());
  });

  // === Kit correction ===
  $(document).on("change", ".match-media-kit-select", function () {
    var select = $(this);
    $.post("match_photo_ajax.php", {
      action: "set_kit",
      csrf_token: csrfToken(),
      photo_id: select.data("photo-id"),
      kit: select.val(),
    }).fail(() => showError());
  });
});
