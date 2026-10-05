$(function () {

  /* ==============================
   *  Add Slot Modal
   * ============================== */
  $('#addSlotForm select[name="player_id"]').on('change', function () {
    let playerId = $(this).val();
    let sponsorId = $('#addSlotForm input[name="sponsor_id"]').val();

    if (!playerId) return;

    $.post('sponsor_ajax.php', {
      action: 'get_available_slots',
      player_id: playerId,
      sponsor_id: sponsorId
    }, function (resp) {
      let $slot = $('#addSlotForm select[name="slot"]');
      $slot.empty();

      if (resp.success) {
        if (resp.available.length) {
          resp.available.forEach(s => {
            $slot.append(`<option value="${s}">${s.toUpperCase()}</option>`);
          });
        } else {
          $slot.append('<option value="">No slots available</option>');
        }
        $('#addSlotForm input[name="amount"]').val(resp.price);
      } else {
        showToast('error', resp.message || 'Error fetching slots');
      }
    }, 'json');
  });

  $('#addSlotForm').on('submit', function (e) {
    e.preventDefault();
    $.post('sponsor_ajax.php', $(this).serialize(), function (resp) {
      if (resp.success) {
        showToast('success', 'Slot added successfully');
        location.reload();
      } else {
        showToast('error', resp.message || 'Error adding slot');
      }
    }, 'json');
  });

  /* ==============================
   *  Delete Slot
   * ============================== */
  $('.delete-slot').on('click', function () {
    if (!confirm('Are you sure you want to delete this slot?')) return;

    let id = $(this).data('id');
    $.post('sponsor_ajax.php', { action: 'delete_slot', id: id }, function (resp) {
      if (resp.success) {
        showToast('success', 'Slot deleted');
        location.reload();
      } else {
        showToast('error', resp.message || 'Error deleting slot');
      }
    }, 'json');
  });

  /* ==============================
   *  Add Payment Modal
   * ============================== */
  $('#addPaymentForm').on('submit', function (e) {
    e.preventDefault();
    $.post('sponsor_ajax.php', $(this).serialize(), function (resp) {
      if (resp.success) {
        showToast('success', 'Payment added successfully');
        location.reload();
      } else {
        showToast('error', resp.message || 'Error adding payment');
      }
    }, 'json');
  });

  /* ==============================
   *  Delete Payment
   * ============================== */
  $('.delete-payment').on('click', function () {
    if (!confirm('Are you sure you want to delete this payment?')) return;

    let id = $(this).data('id');
    $.post('sponsor_ajax.php', { action: 'delete_payment', id: id }, function (resp) {
      if (resp.success) {
        showToast('success', 'Payment deleted');
        location.reload();
      } else {
        showToast('error', resp.message || 'Error deleting payment');
      }
    }, 'json');
  });

  /* ==============================
   *  Edit Sponsor Details
   * ============================== */
  $('#editSponsorForm').on('submit', function (e) {
    e.preventDefault();
    $.post('sponsor_ajax.php', $(this).serialize(), function (resp) {
      if (resp.success) {
        showToast('success', 'Sponsor updated');
        location.reload();
      } else {
        showToast('error', resp.message || 'Error updating sponsor');
      }
    }, 'json');
  });

});

/* ==============================
 *  Bootstrap Toast Helper
 * ============================== */
function showToast(type, message) {
  let bg = (type === 'success') ? 'bg-success' : 'bg-danger';
  let $toast = $(`
    <div class="toast align-items-center text-white ${bg} border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body">${message}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  `);

  $('#toast-container').append($toast);
  new bootstrap.Toast($toast[0], { delay: 3000 }).show();
}
