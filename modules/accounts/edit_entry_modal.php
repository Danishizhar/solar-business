<?php
// This file contains the edit entry modal for journal entries
?>

<!-- Edit Entry Modal -->
<div class="modal fade" id="editEntryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Journal Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editEntryForm" method="post">
                <div class="modal-body">
                    <!-- Modal Error Alert -->
                    <div class="alert alert-danger alert-dismissible fade" role="alert" id="editModalErrorAlert" style="display: none;">
                        <span id="editModalErrorMessage"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <input type="hidden" name="id" id="edit_entry_id">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Financial Year *</label>
                            <select class="form-select" name="financial_year_id" id="edit_financial_year_id" required>
                                <option value="">Select Financial Year</option>
                                <?php foreach ($financial_years as $year): 
                                    // Skip closed financial years
                                    if ($year['is_closed'] == 1) continue;
                                ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_code'] . ' (' . date('d M Y', strtotime($year['start_date'])) . ' - ' . date('d M Y', strtotime($year['end_date'])) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Date *</label>
                            <input type="date" class="form-control" name="entry_date" id="edit_entry_date" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Type *</label>
                            <select class="form-select" name="entry_type" id="edit_entry_type" required>
                                <option value="">Select Type</option>
                                <option value="manual">Manual</option>
                                <option value="sales">Sales</option>
                                <option value="purchase">Purchase</option>
                                <option value="payment">Payment</option>
                                <option value="receipt">Receipt</option>
                                <option value="contra">Contra</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cost Center</label>
                            <select class="form-select" name="cost_center_id" id="edit_cost_center_id">
                                <option value="">Select Cost Center</option>
                                <?php 
                                // Get all cost centers - reusing the same data from the add form
                                foreach ($cost_centers as $cost_center): 
                                ?>
                                <option value="<?php echo $cost_center['id']; ?>">
                                    <?php echo htmlspecialchars($cost_center['code'] . ' - ' . $cost_center['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="edit_reference_number">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2" required></textarea>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="editEntryDetailsTable">
                                    <thead>
                                        <tr>
                                            <th width="30%">Account *</th>
                                            <th width="30%">Description</th>
                                            <th width="15%">Debit</th>
                                            <th width="15%">Credit</th>
                                            <th width="10%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Entry details will be loaded dynamically -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2">
                                                <button type="button" class="btn btn-success btn-sm" id="addEditLine">
                                                    <i class="fas fa-plus"></i> Add Line
                                                </button>
                                            </td>
                                            <td>
                                                <strong>Total Debit:</strong> <span id="editTotalDebit">0.00</span>
                                            </td>
                                            <td>
                                                <strong>Total Credit:</strong> <span id="editTotalCredit">0.00</span>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="updateEntry">Update Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>
