<?php
/**
 * Barcode Scanner Modal
 * Used for scanning barcodes using the device camera
 */
?>
<div class="modal fade" id="barcode-scanner-modal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="barcodeScannerModalLabel">Scan Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Position the barcode within the scanner viewport. The barcode will be detected automatically.
                </div>
                
                <div id="scanner-viewport" class="mb-3" style="width: 100%; height: 300px; overflow: hidden; position: relative; border: 3px solid #6c757d;">
                    <!-- Scanner will be initialized here -->
                </div>
                
                <div class="text-center">
                    <p class="text-muted">Supported formats: EAN-13, EAN-8, Code 128, Code 39, UPC</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
