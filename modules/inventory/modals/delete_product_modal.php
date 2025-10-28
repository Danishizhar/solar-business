<div class="modal fade" id="deleteProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/delete_product.php" method="POST">
                <input type="hidden" name="product_id" id="delete_product_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete the product: <strong id="delete_product_name"></strong>?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Warning: This action cannot be undone.</strong>
                        <br>All product data, stock records, attributes, and images will be permanently deleted from the system.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="confirm-delete-product">Permanently Delete Product</button>
                </div>
                <script>
                    // Add extra confirmation for delete to ensure it's intentional
                    document.getElementById('confirm-delete-product').addEventListener('click', function(e) {
                        if (!confirm('Are you absolutely sure you want to permanently delete this product? This action CANNOT be undone.')) {
                            e.preventDefault();
                        }
                    });
                </script>
            </form>
        </div>
    </div>
</div>
