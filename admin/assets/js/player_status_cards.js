$(function () {
          const statusMeta = {
                    trialist: {
                              label: 'Trialist',
                              help: 'Record when the player first started trialing. Trialists remain in the squad unless you choose otherwise.',
                    },
                    current: {
                              label: 'Current',
                              help: 'Record the player signing date and whether they are an active squad member.',
                    },
                    left: {
                              label: 'Left',
                              help: 'Record the player leaving date and move or unassign their sponsorships.',
                    },
                    retired: {
                              label: 'Retired',
                              help: 'Record the retirement date and move or unassign their sponsorships.',
                    },
                    loan: {
                              label: 'On Loan',
                              help: 'This player is still a club member but not currently part of the active squad.',
                    },
                    injured: {
                              label: 'Injured',
                              help: 'This player is still a club member but currently unavailable for the active squad.',
                    },
          };

          const statusCards = $('.player-status-card');
          if (!statusCards.length) {
                    return;
          }

          const modal = $('#playerStatusModal');
          const statusPickerLabel = $('#playerStatusModalSelected');
          const statusHelp = $('#playerStatusModalHelp');
          const statusHidden = $('#playerStatusModalStatus');
          const joinedGroup = $('#playerStatusModalJoinedGroup');
          const leftGroup = $('#playerStatusModalLeftGroup');
          const activeGroup = $('#playerStatusModalActiveGroup');
          const sponsorActionGroup = $('#playerStatusModalSponsorActionGroup');
          const replacementHomeGroup = $('#playerStatusModalReplacementHomeGroup');
          const replacementAwayGroup = $('#playerStatusModalReplacementAwayGroup');
          const sponsorActionRadios = modal.find('[name="playerStatusModalSponsorshipAction"]');
          const replacementHomeSelect = modal.find('#playerStatusModalReplacementHomePlayer');
          const replacementAwaySelect = modal.find('#playerStatusModalReplacementAwayPlayer');
          const modalSponsorSlotsData = $('#playerStatusModalSponsorSlotsData');
          const hasHomeSponsor = modalSponsorSlotsData.data('has-home-sponsor') === 1 || modalSponsorSlotsData.data('has-home-sponsor') === '1';
          const hasAwaySponsor = modalSponsorSlotsData.data('has-away-sponsor') === 1 || modalSponsorSlotsData.data('has-away-sponsor') === '1';
          const joinedInput = $('#playerStatusModalJoinedAt');
          const leftInput = $('#playerStatusModalLeftAt');
          const activeInput = $('#playerStatusModalActive');
          const saveButton = $('#playerStatusModalSave');

          const editData = $('#playerStatusData');
          const isEditPage = editData.length > 0;
          const playerId = isEditPage ? editData.data('player-id') : null;

          const selectedState = {
                    status: isEditPage ? editData.data('status') : ($('#playerStatusAdd').val() || 'current'),
                    joined_at: isEditPage ? editData.data('joined') : $('#playerJoinedAdd').val() || '',
                    left_at: isEditPage ? editData.data('left') : $('#playerLeftAdd').val() || '',
                    active: isEditPage ? editData.data('active') === 1 || editData.data('active') === '1' : $('#playerActiveAddHidden input[name="active"]').length > 0,
          };

          function showToast(ok, msg = null) {
                    if (!ok) {
                              window.alert(msg || 'The change could not be saved');
                    }
          }

          function formatDateForDisplay(value) {
                    return value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Not recorded';
          }

          function getToday() {
                    return new Date().toISOString().slice(0, 10);
          }

          function updateSummary() {
                    const summaryState = $('#playerStatusSummaryState');
                    const summaryJoined = $('#playerStatusSummaryJoined');
                    const summaryLeft = $('#playerStatusSummaryLeft');
                    const summaryActive = $('#playerStatusSummaryActive');

                    if (summaryState.length) {
                              summaryState.text(statusMeta[selectedState.status]?.label || 'Unknown');
                    }
                    if (summaryJoined.length) {
                              summaryJoined.text(selectedState.joined_at ? formatDateForDisplay(selectedState.joined_at) : 'Not recorded');
                    }
                    if (summaryLeft.length) {
                              summaryLeft.text(selectedState.left_at ? formatDateForDisplay(selectedState.left_at) : '—');
                    }
                    if (summaryActive.length) {
                              summaryActive.text(selectedState.active ? 'Yes' : 'No');
                    }
          }

          function setSelectedCard(status) {
                    statusCards.removeClass('active');
                    statusCards.filter(`[data-status="${status}"]`).addClass('active');
          }

          function updateReplacementGroups() {
                    const isTransfer = sponsorActionRadios.filter(':checked').val() === 'transfer';
                    replacementHomeGroup.toggleClass('d-none', !(isTransfer && hasHomeSponsor));
                    replacementAwayGroup.toggleClass('d-none', !(isTransfer && hasAwaySponsor));
          }

          function setSponsorVisibility(status) {
                    const showSponsorOptions = ['left', 'retired'].includes(status);

                    if (showSponsorOptions) {
                              sponsorActionGroup.removeClass('d-none');
                              updateReplacementGroups();
                    } else {
                              sponsorActionGroup.addClass('d-none');
                              replacementHomeGroup.addClass('d-none');
                              replacementAwayGroup.addClass('d-none');
                              sponsorActionRadios.filter('[value="keep"]').prop('checked', true);
                    }
          }

          function openStatusModal(status) {
                    const meta = statusMeta[status] || statusMeta.current;
                    statusHidden.val(status);
                    statusPickerLabel.text(meta.label);
                    statusHelp.text(meta.help);

                    const showJoined = ['trialist', 'current'].includes(status);
                    const showLeft = ['left', 'retired'].includes(status);
                    const showActive = ['trialist', 'current', 'loan', 'injured', 'left', 'retired'].includes(status);

                    joinedGroup.toggle(showJoined);
                    leftGroup.toggle(showLeft);
                    activeGroup.toggle(showActive);
                    setSponsorVisibility(status);

                    if (showJoined) {
                              joinedInput.val(selectedState.joined_at || getToday());
                    } else {
                              joinedInput.val('');
                    }

                    if (showLeft) {
                              leftInput.val(selectedState.left_at || getToday());
                    } else {
                              leftInput.val('');
                    }

                    if (showActive) {
                              activeInput.prop('disabled', false);
                              activeInput.prop('checked', selectedState.active);
                    } else {
                              activeInput.prop('disabled', true);
                              activeInput.prop('checked', false);
                    }

                    modal.modal('show');
          }

          function updateStatusCardSelection(status) {
                    setSelectedCard(status);
                    selectedState.status = status;

                    if (['left', 'retired', 'loan', 'injured'].includes(status)) {
                              selectedState.active = false;
                    }
                    if (['left', 'retired'].includes(status)) {
                              selectedState.left_at = selectedState.left_at || getToday();
                    }
                    if (['trialist', 'current'].includes(status)) {
                              selectedState.joined_at = selectedState.joined_at || getToday();
                    }

                    if (!isEditPage) {
                              $('#playerStatusAdd').val(selectedState.status);
                              $('#playerJoinedAdd').val(selectedState.joined_at);
                              $('#playerLeftAdd').val(selectedState.left_at);
                              const activeContainer = $('#playerActiveAddHidden');
                              activeContainer.empty();
                              if (selectedState.active) {
                                        activeContainer.append('<input type="hidden" name="active" id="playerActiveAdd" value="1">');
                              }
                    }

                    updateSummary();
          }

          function saveEditStatus() {
                    const status = statusHidden.val();
                    const joinedAt = joinedInput.val();
                    const leftAt = leftInput.val();
                    const active = activeInput.is(':checked') ? 1 : 0;
                    const sponsorshipAction = sponsorActionGroup.is(':visible')
                              ? sponsorActionRadios.filter(':checked').val() || 'keep'
                              : 'keep';
                    const replacementHomePlayerId = sponsorshipAction === 'transfer' ? replacementHomeSelect.val() : '';
                    const replacementAwayPlayerId = sponsorshipAction === 'transfer' ? replacementAwaySelect.val() : '';

                    if (sponsorshipAction === 'transfer' && hasHomeSponsor === false && hasAwaySponsor === false) {
                              showToast(false, 'There are no transferable Home or Away sponsorships for this player.');
                              return;
                    }
                    if (sponsorshipAction === 'transfer' && !replacementHomePlayerId && !replacementAwayPlayerId) {
                              showToast(false, 'Select a replacement player for at least one sponsorship slot.');
                              return;
                    }

                    $.post('player_edit_ajax.php', {
                              action: 'update_player_status_with_sponsorships',
                              id: playerId,
                              status,
                              joined_at: joinedAt,
                              left_at: leftAt,
                              active,
                              sponsorship_action: sponsorshipAction,
                              replacement_home_player_id: replacementHomePlayerId,
                              replacement_away_player_id: replacementAwayPlayerId,
                    })
                              .done((resp) => {
                                        if (resp.success) {
                                                  selectedState.status = status;
                                                  selectedState.joined_at = joinedAt;
                                                  selectedState.left_at = leftAt;
                                                  selectedState.active = active === 1;
                                                  updateStatusCardSelection(status);
                                                  showToast(true, 'Player status updated');
                                                  modal.modal('hide');
                                        } else {
                                                  showToast(false, resp.error || 'Could not save status changes');
                                        }
                              })
                              .fail(() => {
                                        showToast(false, 'Could not save status changes');
                              });
          }

          sponsorActionRadios.on('change', function () {
                    updateReplacementGroups();
          });

          statusCards.on('click', function () {
                    openStatusModal($(this).data('status'));
          });

          saveButton.on('click', function () {
                    if (isEditPage) {
                              saveEditStatus();
                              return;
                    }

                    const status = statusHidden.val();
                    const joinedAt = joinedInput.val();
                    const leftAt = leftInput.val();
                    const active = activeInput.is(':checked');

                    selectedState.status = status;
                    selectedState.joined_at = joinedAt;
                    selectedState.left_at = leftAt;
                    selectedState.active = active;

                    $('#playerStatusAdd').val(status);
                    $('#playerJoinedAdd').val(joinedAt);
                    $('#playerLeftAdd').val(leftAt);
                    const activeContainer = $('#playerActiveAddHidden');
                    activeContainer.empty();
                    if (active) {
                              activeContainer.append('<input type="hidden" name="active" id="playerActiveAdd" value="1">');
                    }

                    updateSummary();
                    modal.modal('hide');
          });

          $('#playerStatusModal').on('hidden.bs.modal', function () {
                    statusHidden.val(selectedState.status);
          });

          setSelectedCard(selectedState.status);
          updateSummary();
});
