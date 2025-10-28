<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user has admin role
if ($_SESSION['role_id'] != 1) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Get all managers (users)
try {
    $query = "SELECT id, username, email FROM users WHERE status = 1 ORDER BY username";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching managers: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $branch_name = isset($_POST['branch_name']) ? $_POST['branch_name'] : '';
        $branch_code = isset($_POST['branch_code']) ? $_POST['branch_code'] : '';
        $address = isset($_POST['address']) ? $_POST['address'] : '';
        $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
        $email = isset($_POST['email']) ? $_POST['email'] : '';
        $manager_id = isset($_POST['manager_id']) ? $_POST['manager_id'] : null;
        $is_main_branch = isset($_POST['is_main_branch']) ? 1 : 0;
        $status = isset($_POST['status']) ? 1 : 0;

        try {
            switch ($_POST['action']) {
                case 'add':
                    // Check if branch code already exists
                    $query = "SELECT id FROM branches WHERE branch_code = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$branch_code]);
                    if ($stmt->fetch()) {
                        throw new Exception("Branch code already exists!");
                    }

                    // If this is main branch, update other branches
                    if ($is_main_branch) {
                        $query = "UPDATE branches SET is_main_branch = 0";
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                    }

                    $query = "INSERT INTO branches (branch_name, branch_code, address, phone, email, 
                             manager_id, is_main_branch, status, created_by) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        $branch_name, $branch_code, $address, $phone, $email,
                        $manager_id, $is_main_branch, $status, $_SESSION['user_id']
                    ]);
                    $success_message = "Branch added successfully!";
                    break;

                case 'edit':
                    $branch_id = $_POST['branch_id'];

                    // Check if branch code already exists for other branches
                    $query = "SELECT id FROM branches WHERE branch_code = ? AND id != ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$branch_code, $branch_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Branch code already exists!");
                    }

                    // If this is main branch, update other branches
                    if ($is_main_branch) {
                        $query = "UPDATE branches SET is_main_branch = 0 WHERE id != ?";
                        $stmt = $db->prepare($query);
                        $stmt->execute([$branch_id]);
                    }

                    $query = "UPDATE branches SET 
                             branch_name = ?, 
                             branch_code = ?, 
                             address = ?, 
                             phone = ?, 
                             email = ?, 
                             manager_id = ?, 
                             is_main_branch = ?,
                             status = ?,
                             updated_at = NOW(),
                             updated_by = ?
                             WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        $branch_name, $branch_code, $address, $phone, $email,
                        $manager_id, $is_main_branch, $status, $_SESSION['user_id'], $branch_id
                    ]);
                    $success_message = "Branch updated successfully!";
                    break;

                case 'delete':
                    $branch_id = $_POST['branch_id'];

                    // Check if it's the main branch
                    $query = "SELECT is_main_branch FROM branches WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$branch_id]);
                    $branch = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($branch['is_main_branch']) {
                        throw new Exception("Cannot delete the main branch!");
                    }

                    $query = "DELETE FROM branches WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$branch_id]);
                    $success_message = "Branch deleted successfully!";
                    break;
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Get all branches with manager names
try {
    $query = "SELECT b.*, u.username as manager_name,
              c.username as created_by_name,
              up.username as updated_by_name
              FROM branches b 
              LEFT JOIN users u ON b.manager_id = u.id
              LEFT JOIN users c ON b.created_by = c.id
              LEFT JOIN users up ON b.updated_by = up.id
              ORDER BY b.is_main_branch DESC, b.branch_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching branches: " . $e->getMessage();
}

// Include header
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h2>Branch Management</h2>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                        <i class="fas fa-plus"></i> Add New Branch
                    </button>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Branch Name</th>
                                    <th>Code</th>
                                    <th>Address</th>
                                    <th>Contact</th>
                                    <th>Manager</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branches as $branch): ?>
                                <tr>
                                    <td><?php echo isset($branch['branch_name']) ? htmlspecialchars($branch['branch_name']) : ''; ?></td>
                                    <td><?php echo isset($branch['branch_code']) ? htmlspecialchars($branch['branch_code']) : ''; ?></td>
                                    <td><?php echo isset($branch['address']) ? htmlspecialchars($branch['address']) : ''; ?></td>
                                    <td>
                                        Phone: <?php echo isset($branch['phone']) ? htmlspecialchars($branch['phone']) : ''; ?><br>
                                        Email: <?php echo isset($branch['email']) ? htmlspecialchars($branch['email']) : ''; ?>
                                    </td>
                                    <td><?php echo isset($branch['manager_name']) ? htmlspecialchars($branch['manager_name']) : ''; ?></td>
                                    <td>
                                        <?php if (isset($branch['status']) && $branch['status']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (isset($branch['is_main_branch']) && $branch['is_main_branch']): ?>
                                            <span class="badge bg-primary">Main Branch</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Branch</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary edit-branch" 
                                                data-bs-toggle="modal" data-bs-target="#editBranchModal"
                                                data-id="<?php echo isset($branch['id']) ? $branch['id'] : ''; ?>"
                                                data-name="<?php echo isset($branch['branch_name']) ? htmlspecialchars($branch['branch_name']) : ''; ?>"
                                                data-code="<?php echo isset($branch['branch_code']) ? htmlspecialchars($branch['branch_code']) : ''; ?>"
                                                data-address="<?php echo isset($branch['address']) ? htmlspecialchars($branch['address']) : ''; ?>"
                                                data-phone="<?php echo isset($branch['phone']) ? htmlspecialchars($branch['phone']) : ''; ?>"
                                                data-email="<?php echo isset($branch['email']) ? htmlspecialchars($branch['email']) : ''; ?>"
                                                data-manager="<?php echo isset($branch['manager_id']) ? $branch['manager_id'] : ''; ?>"
                                                data-main="<?php echo isset($branch['is_main_branch']) ? $branch['is_main_branch'] : '0'; ?>"
                                                data-status="<?php echo isset($branch['status']) ? $branch['status'] : '0'; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (!isset($branch['is_main_branch']) || !$branch['is_main_branch']): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-branch"
                                                data-bs-toggle="modal" data-bs-target="#deleteBranchModal"
                                                data-id="<?php echo isset($branch['id']) ? $branch['id'] : ''; ?>"
                                                data-name="<?php echo isset($branch['branch_name']) ? htmlspecialchars($branch['branch_name']) : ''; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Branch Modal -->
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch Name</label>
                                <input type="text" class="form-control" name="branch_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Branch Code</label>
                                <input type="text" class="form-control" name="branch_code" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Branch Manager</label>
                                <select class="form-control" name="manager_id" required>
                                    <option value="">Select Manager</option>
                                    <?php foreach ($managers as $manager): ?>
                                    <option value="<?php echo $manager['id']; ?>">
                                        <?php echo htmlspecialchars($manager['username']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="is_main_branch" id="add_is_main_branch">
                                    <label class="form-check-label" for="add_is_main_branch">Set as Main Branch</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="status" id="add_status" checked>
                                    <label class="form-check-label" for="add_status">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Branch Modal -->
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="branch_id" id="edit_branch_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch Name</label>
                                <input type="text" class="form-control" name="branch_name" id="edit_branch_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Branch Code</label>
                                <input type="text" class="form-control" name="branch_code" id="edit_branch_code" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" id="edit_phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="edit_email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Branch Manager</label>
                                <select class="form-control" name="manager_id" id="edit_manager_id" required>
                                    <option value="">Select Manager</option>
                                    <?php foreach ($managers as $manager): ?>
                                    <option value="<?php echo $manager['id']; ?>">
                                        <?php echo htmlspecialchars($manager['username']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="is_main_branch" id="edit_is_main_branch">
                                    <label class="form-check-label" for="edit_is_main_branch">Set as Main Branch</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="status" id="edit_status">
                                    <label class="form-check-label" for="edit_status">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Branch Modal -->
<div class="modal fade" id="deleteBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="branch_id" id="delete_branch_id">
                    <p>Are you sure you want to delete branch <strong id="delete_branch_name"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle edit branch modal
$('.edit-branch').click(function() {
    $('#edit_branch_id').val($(this).data('id'));
    $('#edit_branch_name').val($(this).data('name'));
    $('#edit_branch_code').val($(this).data('code'));
    $('#edit_address').val($(this).data('address'));
    $('#edit_phone').val($(this).data('phone'));
    $('#edit_email').val($(this).data('email'));
    $('#edit_manager_id').val($(this).data('manager'));
    $('#edit_is_main_branch').prop('checked', $(this).data('main') == 1);
    $('#edit_status').prop('checked', $(this).data('status') == 1);
});

// Handle delete branch modal
$('.delete-branch').click(function() {
    $('#delete_branch_id').val($(this).data('id'));
    $('#delete_branch_name').text($(this).data('name'));
});
</script>

<?php include '../../includes/footer.php'; ?>
