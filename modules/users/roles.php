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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $role_name = $_POST['role_name'];
                $description = isset($_POST['description']) ? $_POST['description'] : '';

                try {
                    // Check if role already exists
                    $query = "SELECT id FROM roles WHERE role_name = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_name]);
                    if ($stmt->fetch()) {
                        throw new Exception("Role name already exists!");
                    }

                    $query = "INSERT INTO roles (role_name, description, created_at, created_by) 
                             VALUES (?, ?, NOW(), ?)";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_name, $description, $_SESSION['user_id']]);
                    $success_message = "Role added successfully!";
                } catch (Exception $e) {
                    $error_message = "Error adding role: " . $e->getMessage();
                }
                break;

            case 'edit':
                $role_id = $_POST['role_id'];
                $role_name = $_POST['role_name'];
                $description = isset($_POST['description']) ? $_POST['description'] : '';

                try {
                    // Check if role already exists (excluding current role)
                    $query = "SELECT id FROM roles WHERE role_name = ? AND id != ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_name, $role_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Role name already exists!");
                    }

                    // Don't allow editing Admin role
                    if ($role_id == 1) {
                        throw new Exception("Cannot modify Admin role!");
                    }

                    $query = "UPDATE roles SET role_name = ?, description = ?, updated_at = NOW(), updated_by = ? 
                             WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_name, $description, $_SESSION['user_id'], $role_id]);
                    $success_message = "Role updated successfully!";
                } catch (Exception $e) {
                    $error_message = "Error updating role: " . $e->getMessage();
                }
                break;

            case 'delete':
                $role_id = $_POST['role_id'];
                
                try {
                    // Don't allow deleting Admin role
                    if ($role_id == 1) {
                        throw new Exception("Cannot delete Admin role!");
                    }

                    // Check if role is assigned to any users
                    $query = "SELECT COUNT(*) FROM users WHERE role_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_id]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception("Cannot delete role: There are users assigned to this role!");
                    }
                    
                    $query = "DELETE FROM roles WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$role_id]);
                    $success_message = "Role deleted successfully!";
                } catch (Exception $e) {
                    $error_message = "Error deleting role: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get all roles with COALESCE for null values
$roles = [];
try {
    $query = "SELECT r.*, 
              COALESCE(c.username, 'System') as created_by_name,
              COALESCE(up.username, '-') as updated_by_name,
              COALESCE(r.description, '') as description
              FROM roles r 
              LEFT JOIN users c ON r.created_by = c.id
              LEFT JOIN users up ON r.updated_by = up.id
              ORDER BY r.role_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching roles: " . $e->getMessage();
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
                    <h2>Role Management</h2>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                        <i class="fas fa-plus"></i> Add New Role
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
                                    <th>Role Name</th>
                                    <th>Description</th>
                                    <th>Created By</th>
                                    <th>Created At</th>
                                    <th>Updated By</th>
                                    <th>Updated At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roles as $role): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                                    <td><?php echo htmlspecialchars($role['description']); ?></td>
                                    <td><?php echo htmlspecialchars($role['created_by_name']); ?></td>
                                    <td><?php echo $role['created_at']; ?></td>
                                    <td><?php echo htmlspecialchars($role['updated_by_name']); ?></td>
                                    <td><?php echo $role['updated_at'] ?? '-'; ?></td>
                                    <td>
                                        <?php if ($role['id'] != 1): // Don't show edit/delete for Admin role ?>
                                        <button type="button" class="btn btn-sm btn-primary edit-role" 
                                                data-bs-toggle="modal" data-bs-target="#editRoleModal"
                                                data-id="<?php echo $role['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($role['role_name']); ?>"
                                                data-description="<?php echo htmlspecialchars($role['description']); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-role"
                                                data-bs-toggle="modal" data-bs-target="#deleteRoleModal"
                                                data-id="<?php echo $role['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($role['role_name']); ?>">
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

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Role Name</label>
                        <input type="text" class="form-control" name="role_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="role_id" id="edit_role_id">
                    <div class="mb-3">
                        <label class="form-label">Role Name</label>
                        <input type="text" class="form-control" name="role_name" id="edit_role_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
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

<!-- Delete Role Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="role_id" id="delete_role_id">
                    <p>Are you sure you want to delete role <strong id="delete_role_name"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle edit role modal
$('.edit-role').click(function() {
    $('#edit_role_id').val($(this).data('id'));
    $('#edit_role_name').val($(this).data('name'));
    $('#edit_description').val($(this).data('description'));
});

// Handle delete role modal
$('.delete-role').click(function() {
    $('#delete_role_id').val($(this).data('id'));
    $('#delete_role_name').text($(this).data('name'));
});
</script>

<?php include '../../includes/footer.php'; ?>
