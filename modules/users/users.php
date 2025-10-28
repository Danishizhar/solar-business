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
                $username = $_POST['username'];
                $email = $_POST['email'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $role_id = $_POST['role_id'];
                $status = isset($_POST['status']) ? 1 : 0;

                try {
                    // Check if username already exists
                    $query = "SELECT id FROM users WHERE username = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        throw new Exception("Username already exists!");
                    }

                    // Check if email already exists
                    $query = "SELECT id FROM users WHERE email = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        throw new Exception("Email already exists!");
                    }

                    $query = "INSERT INTO users (username, email, password, role_id, status, created_at, created_by) 
                             VALUES (?, ?, ?, ?, ?, NOW(), ?)";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$username, $email, $password, $role_id, $status, $_SESSION['user_id']]);
                    $success_message = "User added successfully!";
                } catch (Exception $e) {
                    $error_message = "Error adding user: " . $e->getMessage();
                }
                break;

            case 'edit':
                $user_id = $_POST['user_id'];
                $username = $_POST['username'];
                $email = $_POST['email'];
                $role_id = $_POST['role_id'];
                $status = isset($_POST['status']) ? 1 : 0;

                try {
                    // Check if username already exists (excluding current user)
                    $query = "SELECT id FROM users WHERE username = ? AND id != ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$username, $user_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Username already exists!");
                    }

                    // Check if email already exists (excluding current user)
                    $query = "SELECT id FROM users WHERE email = ? AND id != ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$email, $user_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Email already exists!");
                    }

                    // Don't allow changing own role
                    if ($user_id == $_SESSION['user_id'] && $role_id != $_SESSION['role_id']) {
                        throw new Exception("Cannot change your own role!");
                    }

                    $query = "UPDATE users SET username = ?, email = ?, role_id = ?, status = ?, 
                             updated_at = NOW(), updated_by = ? WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$username, $email, $role_id, $status, $_SESSION['user_id'], $user_id]);

                    // Update password if provided
                    if (!empty($_POST['password'])) {
                        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $query = "UPDATE users SET password = ? WHERE id = ?";
                        $stmt = $db->prepare($query);
                        $stmt->execute([$password, $user_id]);
                    }

                    $success_message = "User updated successfully!";
                } catch (Exception $e) {
                    $error_message = "Error updating user: " . $e->getMessage();
                }
                break;

            case 'delete':
                $user_id = $_POST['user_id'];
                
                try {
                    // Don't allow self-deletion
                    if ($user_id == $_SESSION['user_id']) {
                        throw new Exception("Cannot delete your own account!");
                    }

                    $query = "DELETE FROM users WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$user_id]);
                    $success_message = "User deleted successfully!";
                } catch (Exception $e) {
                    $error_message = "Error deleting user: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get all roles for dropdown
$roles = [];
try {
    $query = "SELECT id, role_name FROM roles ORDER BY role_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching roles: " . $e->getMessage();
}

// Get all users with role names and COALESCE for null values
$users = [];
try {
    $query = "SELECT u.*, 
              r.role_name,
              COALESCE(c.username, 'System') as created_by_name,
              COALESCE(up.username, '-') as updated_by_name
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.id
              LEFT JOIN users c ON u.created_by = c.id
              LEFT JOIN users up ON u.updated_by = up.id
              ORDER BY u.username";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching users: " . $e->getMessage();
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
                    <h2>User Management</h2>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus"></i> Add New User
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
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Created At</th>
                                    <th>Updated By</th>
                                    <th>Updated At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $user['status'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['created_by_name']); ?></td>
                                    <td><?php echo $user['created_at']; ?></td>
                                    <td><?php echo htmlspecialchars($user['updated_by_name']); ?></td>
                                    <td><?php echo $user['updated_at'] ?? '-'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary edit-user" 
                                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                data-id="<?php echo $user['id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                                data-role="<?php echo $user['role_id']; ?>"
                                                data-status="<?php echo $user['status']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button type="button" class="btn btn-sm btn-danger delete-user"
                                                data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                                data-id="<?php echo $user['id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="status" id="addStatus" checked>
                            <label class="form-check-label" for="addStatus">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" id="edit_username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password (leave blank to keep unchanged)</label>
                        <input type="password" class="form-control" name="password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role_id" id="edit_role" required>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="status" id="edit_status">
                            <label class="form-check-label" for="edit_status">Active</label>
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

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <p>Are you sure you want to delete user <strong id="delete_username"></strong>?</p>
                    <p class="text-danger">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle edit user modal
$('.edit-user').click(function() {
    $('#edit_user_id').val($(this).data('id'));
    $('#edit_username').val($(this).data('username'));
    $('#edit_email').val($(this).data('email'));
    $('#edit_role').val($(this).data('role'));
    $('#edit_status').prop('checked', $(this).data('status') == 1);
});

// Handle delete user modal
$('.delete-user').click(function() {
    $('#delete_user_id').val($(this).data('id'));
    $('#delete_username').text($(this).data('username'));
});
</script>

<?php include '../../includes/footer.php'; ?>
