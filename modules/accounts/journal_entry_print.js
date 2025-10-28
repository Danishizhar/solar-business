// Handle Print Button Click in View Modal
$(document).on('click', '#printViewEntry', function() {
    var id = $('#viewEntryModal').data('entry-id');
    if (id) {
        // Open print page in a new window
        window.open('print_journal_entry.php?id=' + id, '_blank');
    } else {
        showError('Error: Could not find entry ID for printing.');
    }
});

// Handle Print Button Click in Actions column
$(document).on('click', '.print-entry', function() {
    var id = $(this).data('id');
    if (id) {
        // Open print page in a new window
        window.open('print_journal_entry.php?id=' + id, '_blank');
    } else {
        showError('Error: Could not find entry ID for printing.');
    }
});
