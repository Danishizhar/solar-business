<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Get user data
try {
    $query = "SELECT u.*, r.role_name 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.id 
              WHERE u.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching user data: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check which form was submitted
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            try {
                // Verify current password
                if (!password_verify($current_password, $user['password'])) {
                    throw new Exception("Current password is incorrect");
                }
                
                // Validate new password
                if (strlen($new_password) < 8) {
                    throw new Exception("New password must be at least 8 characters long");
                }
                
                // Check if passwords match
                if ($new_password !== $confirm_password) {
                    throw new Exception("New passwords do not match");
                }
                
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $query = "UPDATE users SET password = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$hashed_password, $_SESSION['user_id'], $_SESSION['user_id']]);
                
                $success_message = "Password updated successfully!";
                
                // Update user data
                $user['password'] = $hashed_password;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } elseif ($_POST['action'] == 'set_approval_pin') {
            $approval_pin = $_POST['approval_pin'];
            $confirm_approval_pin = $_POST['confirm_approval_pin'];
            
            try {
                // Validate PIN
                if (strlen($approval_pin) < 4 || strlen($approval_pin) > 6) {
                    throw new Exception("Approval PIN must be 4-6 digits");
                }
                
                if (!ctype_digit($approval_pin)) {
                    throw new Exception("Approval PIN must contain only digits");
                }
                
                // Check if PINs match
                if ($approval_pin !== $confirm_approval_pin) {
                    throw new Exception("Approval PINs do not match");
                }
                
                // Update approval PIN
                $query = "UPDATE users SET approval_pin = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$approval_pin, $_SESSION['user_id'], $_SESSION['user_id']]);
                
                $success_message = "Approval PIN updated successfully!";
                
                // Update user data
                $user['approval_pin'] = $approval_pin;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
    } else {
        // Original password change form
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        try {
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                throw new Exception("Current password is incorrect");
            }
            
            // Validate new password
            if (strlen($new_password) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }
            
            // Check if passwords match
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$hashed_password, $_SESSION['user_id'], $_SESSION['user_id']]);
            
            $success_message = "Password updated successfully!";
            
            // Update user data
            $user['password'] = $hashed_password;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Include header
include '../includes/header.php';
?>

<div class="wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h2>My Profile</h2>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Profile Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['role_name']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <input type="text" class="form-control" value="<?php echo $user['status'] ? 'Active' : 'Inactive'; ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Change Password</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Change Password</button>
                            </form>
                        </div>
                    </div>
                    
                    <?php if ($user['role_name'] == 'Admin' || $user['role_name'] == 'Manager'): ?>
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="card-title mb-0">Set Approval PIN</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                This PIN will be used for approving discounts that require manager or admin approval.
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="set_approval_pin">
                                <div class="mb-3">
                                    <label class="form-label">Approval PIN</label>
                                    <input type="password" class="form-control" name="approval_pin" required maxlength="6" pattern="[0-9]{4,6}">
                                    <div class="form-text">PIN must be 4-6 digits</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm Approval PIN</label>
                                    <input type="password" class="form-control" name="confirm_approval_pin" required maxlength="6" pattern="[0-9]{4,6}">
                                </div>
                                <button type="submit" class="btn btn-warning">Set Approval PIN</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
