// Function to show error message
function showError(message, isModal = false, isEditModal = false) {
    if (isEditModal) {
        // Show error in edit modal
        $('#editModalErrorMessage').text(message);
        $('#editModalErrorAlert').addClass('show').show();
        // Don't auto-hide modal errors so user can read them
    } else if (isModal) {
        // Show error in add modal
        $('#modalErrorMessage').text(message);
        $('#modalErrorAlert').addClass('show').show();
        // Don't auto-hide modal errors so user can read them
    } else {
        // Show error on main page
        $('#errorMessage').text(message);
        $('#errorAlert').addClass('show').show();
        setTimeout(function() {
            $('#errorAlert').removeClass('show').hide();
        }, 3000);
    }
}

// Function to show success message
function showSuccess(message) {
    $('#successMessage').text(message);
    $('#successAlert').addClass('show').show();
    setTimeout(function() {
        $('#successAlert').removeClass('show').hide();
    }, 3000);
}

// Handle Edit Entry Button Click
$(document).on('click', '.edit-entry', function() {
    var id = $(this).data('id');
    
    // Debug
    console.log('Edit entry clicked, ID:', id);
    
    // Load entry data
    $.ajax({
        url: 'actions/get_journal_entry.php',
        type: 'GET',
        data: {id: id},
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate form with entry data
                var entry = response.data.entry;
                var details = response.data.details;
                
                $('#edit_entry_id').val(entry.id);
                $('#edit_financial_year_id').val(entry.financial_year_id);
                $('#edit_entry_date').val(entry.entry_date);
                $('#edit_entry_type').val(entry.entry_type);
                $('#edit_cost_center_id').val(entry.cost_center_id);
                $('#edit_reference_number').val(entry.reference_number);
                $('#edit_description').val(entry.description);
                
                // Clear existing rows
                $('#editEntryDetailsTable tbody').empty();
                
                // Add rows for each detail
                $.each(details, function(index, detail) {
                    var newRow = $('<tr>');
                    
                    // Account select
                    var accountCell = $('<td>');
                    var accountSelect = $('<select>').addClass('form-select account-select').attr('name', 'accounts[]').attr('required', true);
                    accountSelect.append($('<option>').val('').text('Select Account'));
                    
                    // Clone the options from the add form
                    $('#addEntryForm .account-select:first option').each(function() {
                        if ($(this).val()) {
                            var option = $('<option>').val($(this).val()).text($(this).text());
                            if ($(this).val() == detail.account_id) {
                                option.attr('selected', true);
                            }
                            accountSelect.append(option);
                        }
                    });
                    
                    accountCell.append(accountSelect);
                    
                    // Description
                    var descCell = $('<td>');
                    var descInput = $('<input>').addClass('form-control').attr('type', 'text').attr('name', 'line_descriptions[]').val(detail.description || '');
                    descCell.append(descInput);
                    
                    // Debit
                    var debitCell = $('<td>');
                    var debitInput = $('<input>').addClass('form-control debit-amount').attr('type', 'number').attr('name', 'debits[]').attr('step', '0.01').val(detail.debit_amount);
                    debitCell.append(debitInput);
                    
                    // Credit
                    var creditCell = $('<td>');
                    var creditInput = $('<input>').addClass('form-control credit-amount').attr('type', 'number').attr('name', 'credits[]').attr('step', '0.01').val(detail.credit_amount);
                    creditCell.append(creditInput);
                    
                    // Actions
                    var actionsCell = $('<td>');
                    var removeBtn = $('<button>').addClass('btn btn-danger btn-sm remove-line').attr('type', 'button').html('<i class="fas fa-trash"></i>');
                    actionsCell.append(removeBtn);
                    
                    // Append cells to row
                    newRow.append(accountCell, descCell, debitCell, creditCell, actionsCell);
                    
                    // Append row to table
                    $('#editEntryDetailsTable tbody').append(newRow);
                });
                
                // Calculate totals
                calculateEditTotals();
                
                // Show modal
                $('#editEntryModal').modal('show');
            } else {
                showError(response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', error);
            console.error('Status:', status);
            console.error('Response:', xhr.responseText);
            
            // Try to parse the response to get more detailed error information
            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.debug) {
                    console.error('Error Code:', response.debug.error_code);
                    console.error('Error Message:', response.debug.error_message);
                    showError('Database Error: ' + response.debug.error_message, false, true);
                } else if (response && response.message) {
                    showError(response.message, false, true);
                } else {
                    showError('Error loading journal entry. Please try again.', false, true);
                }
            } catch (e) {
                showError('Error loading journal entry. Please try again.', false, true);
            }
        }
    });
});

// Function to calculate edit totals
function calculateEditTotals() {
    var totalDebit = 0;
    var totalCredit = 0;
    
    $('#editEntryDetailsTable .debit-amount').each(function() {
        totalDebit += parseFloat($(this).val()) || 0;
    });
    
    $('#editEntryDetailsTable .credit-amount').each(function() {
        totalCredit += parseFloat($(this).val()) || 0;
    });
    
    $('#editTotalDebit').text(totalDebit.toFixed(2));
    $('#editTotalCredit').text(totalCredit.toFixed(2));
    
    // Highlight totals if they don't match
    if (Math.abs(totalDebit - totalCredit) > 0.01) {
        $('#editTotalDebit, #editTotalCredit').addClass('text-danger').removeClass('text-success');
    } else {
        $('#editTotalDebit, #editTotalCredit').addClass('text-success').removeClass('text-danger');
    }
}

// Add line to edit form
$(document).on('click', '#addEditLine', function() {
    var newRow = $('<tr>');
    
    // Account select
    var accountCell = $('<td>');
    var accountSelect = $('<select>').addClass('form-select account-select').attr('name', 'accounts[]').attr('required', true);
    accountSelect.append($('<option>').val('').text('Select Account'));
    
    // Clone the options from the add form
    $('#addEntryForm .account-select:first option').each(function() {
        if ($(this).val()) {
            accountSelect.append($(this).clone());
        }
    });
    
    accountCell.append(accountSelect);
    
    // Description
    var descCell = $('<td>');
    var descInput = $('<input>').addClass('form-control').attr('type', 'text').attr('name', 'line_descriptions[]');
    descCell.append(descInput);
    
    // Debit
    var debitCell = $('<td>');
    var debitInput = $('<input>').addClass('form-control debit-amount').attr('type', 'number').attr('name', 'debits[]').attr('step', '0.01').val('0.00');
    debitCell.append(debitInput);
    
    // Credit
    var creditCell = $('<td>');
    var creditInput = $('<input>').addClass('form-control credit-amount').attr('type', 'number').attr('name', 'credits[]').attr('step', '0.01').val('0.00');
    creditCell.append(creditInput);
    
    // Actions
    var actionsCell = $('<td>');
    var removeBtn = $('<button>').addClass('btn btn-danger btn-sm remove-line').attr('type', 'button').html('<i class="fas fa-trash"></i>');
    actionsCell.append(removeBtn);
    
    // Append cells to row
    newRow.append(accountCell, descCell, debitCell, creditCell, actionsCell);
    
    // Append row to table
    $('#editEntryDetailsTable tbody').append(newRow);
    
    // Recalculate totals
    calculateEditTotals();
});

// Remove line from edit form
$(document).on('click', '#editEntryDetailsTable .remove-line', function() {
    $(this).closest('tr').remove();
    calculateEditTotals();
});

// Update totals when debit or credit changes in edit form
$(document).on('input', '#editEntryDetailsTable .debit-amount, #editEntryDetailsTable .credit-amount', function() {
    calculateEditTotals();
});

// Handle View Entry Button Click
$(document).on('click', '.view-entry', function() {
    var id = $(this).data('id');
    
    // Debug
    console.log('View entry clicked, ID:', id);
    
    // Store the current entry ID for printing
    $('#viewEntryModal').data('entry-id', id);
    
    // Clear previous data
    $('#view-entry-number').text('');
    $('#view-entry-date').text('');
    $('#view-entry-status').text('');
    $('#view-entry-type').text('');
    $('#view-reference-number').text('');
    $('#view-cost-center').text('');
    $('#view-description').text('');
    $('#view-created-by').text('');
    $('#view-created-at').text('');
    $('#view-posted-by').text('');
    $('#view-posted-at').text('');
    $('#view-approved-by').text('');
    $('#view-approved-at').text('');
    $('#viewEntryDetailsBody').empty();
    $('#view-total-debit').text('0.00');
    $('#view-total-credit').text('0.00');
    
    // Load entry data
    $.ajax({
        url: 'actions/get_journal_entry_details.php',
        type: 'POST',
        data: {id: id},
        dataType: 'json',
        success: function(response) {
            console.log('Response:', response);
            if (response && response.success) {
                // Populate modal with entry data
                var entry = response.entry;
                var details = response.details;
                
                // Debug
                console.log('Entry:', entry);
                console.log('Details:', details);
                
                if (entry) {
                    // Set entry header information
                    $('#view-entry-number').text(entry.entry_number || '');
                    $('#view-entry-date').text(entry.entry_date || '');
                    
                    var status = entry.status || '';
                    $('#view-entry-status').text(status ? status.charAt(0).toUpperCase() + status.slice(1) : '');
                    
                    var entryType = entry.entry_type || '';
                    $('#view-entry-type').text(entryType ? entryType.charAt(0).toUpperCase() + entryType.slice(1) : '');
                    
                    $('#view-reference-number').text(entry.reference_number || 'N/A');
                    $('#view-cost-center').text(entry.cost_center_name || 'N/A');
                    $('#view-description').text(entry.description || '');
                    
                    // Set tracking information
                    $('#view-created-by').text(entry.created_by_name || 'N/A');
                    $('#view-created-at').text(entry.created_at || 'N/A');
                    
                    // Handle posted information
                    var postedBy = entry.posted_by ? (entry.posted_by_name || 'User ' + entry.posted_by) : 'N/A';
                    $('#view-posted-by').text(postedBy);
                    $('#view-posted-at').text(entry.posted_at || 'N/A');
                    
                    // Show approval section if entry is approved or rejected
                    if (entry.status === 'approved' || entry.status === 'rejected') {
                        $('#view-approval-section').show();
                        var approvedBy = entry.approved_by ? (entry.approved_by_name || 'User ' + entry.approved_by) : 'N/A';
                        $('#view-approved-by').text(approvedBy);
                        $('#view-approved-at').text(entry.approved_at || 'N/A');
                    } else {
                        $('#view-approval-section').hide();
                    }
                }
                
                // Clear existing rows
                $('#viewEntryDetailsBody').empty();
                
                // Add rows for each detail
                var totalDebit = 0;
                var totalCredit = 0;
                
                if (details && details.length > 0) {
                    $.each(details, function(index, detail) {
                        if (detail) {
                            var debit = parseFloat(detail.debit_amount) || 0;
                            var credit = parseFloat(detail.credit_amount) || 0;
                            
                            totalDebit += debit;
                            totalCredit += credit;
                            
                            var newRow = $('<tr>');
                            newRow.append($('<td>').text((detail.account_code || '') + ' - ' + (detail.account_name || '')));
                            newRow.append($('<td>').text(detail.description || ''));
                            newRow.append($('<td class="text-end">').text(debit.toFixed(2)));
                            newRow.append($('<td class="text-end">').text(credit.toFixed(2)));
                            
                            $('#viewEntryDetailsBody').append(newRow);
                        }
                    });
                }
                
                // Update totals
                $('#view-total-debit').text(totalDebit.toFixed(2));
                $('#view-total-credit').text(totalCredit.toFixed(2));
            } else {
                showError(response && response.message ? response.message : 'Error loading journal entry details');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', error);
            console.error('Status:', status);
            console.error('Response:', xhr.responseText);
            
            // Try to parse the response to get more detailed error information
            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.debug) {
                    console.error('Error Code:', response.debug.error_code);
                    console.error('Error Message:', response.debug.error_message);
                    showError('Database Error: ' + response.debug.error_message);
                } else {
                    showError('Error loading journal entry details. Please try again.');
                }
            } catch (e) {
                showError('Error loading journal entry details. Please try again.');
            }
        },
        complete: function() {
            // Always show the modal, even if there's an error
            $('#viewEntryModal').modal('show');
        }
    });
});
