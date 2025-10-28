<?php
// This file contains the view entry modal for journal entries
?>

<!-- View Entry Modal -->
<div class="modal fade" id="viewEntryModal" tabindex="-1" aria-labelledby="viewEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewEntryModalLabel">View Journal Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="viewModalErrorAlert" class="alert alert-danger alert-dismissible fade" role="alert" style="display: none;">
                    <span id="viewModalErrorMessage"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Entry Number</label>
                        <p id="view-entry-number" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Entry Date</label>
                        <p id="view-entry-date" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <p id="view-entry-status" class="form-control-static"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Entry Type</label>
                        <p id="view-entry-type" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reference Number</label>
                        <p id="view-reference-number" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cost Center</label>
                        <p id="view-cost-center" class="form-control-static"></p>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Description</label>
                        <p id="view-description" class="form-control-static"></p>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="viewEntryDetailsTable">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody id="viewEntryDetailsBody">
                            <!-- Entry details will be loaded here -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="2" class="text-end">Total</th>
                                <th class="text-end" id="view-total-debit">0.00</th>
                                <th class="text-end" id="view-total-credit">0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-4">
                        <label class="form-label">Created By</label>
                        <p id="view-created-by" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Created At</label>
                        <p id="view-created-at" class="form-control-static"></p>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-4">
                        <label class="form-label">Posted By</label>
                        <p id="view-posted-by" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Posted At</label>
                        <p id="view-posted-at" class="form-control-static"></p>
                    </div>
                </div>
                
                <div class="row mt-3" id="view-approval-section" style="display: none;">
                    <div class="col-md-4">
                        <label class="form-label">Approved/Rejected By</label>
                        <p id="view-approved-by" class="form-control-static"></p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Approved/Rejected At</label>
                        <p id="view-approved-at" class="form-control-static"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printViewEntry">Print</button>
            </div>
        </div>
    </div>
</div>
