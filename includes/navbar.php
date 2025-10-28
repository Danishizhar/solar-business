<?php
// We need the database connection for user role information
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get user's role name
$role_name = "Unknown";
try {
    $query = "SELECT r.role_name FROM roles r 
              JOIN users u ON u.role_id = r.id 
              WHERE u.id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $role_name = $row['role_name'];
    }
} catch (PDOException $e) {
    // Log error silently
    error_log("Error fetching user role in navbar: " . $e->getMessage());
}

// Get the correct base path
$current_path = $_SERVER['PHP_SELF'];
$is_in_module = strpos($current_path, '/modules/') !== false;
$is_in_auth = strpos($current_path, '/auth/') !== false;
$base_path = '';

if ($is_in_module) {
    $base_path = '../../';
} elseif ($is_in_auth) {
    $base_path = '../';
}
?>
<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <button type="button" id="sidebarCollapse" class="btn btn-info">
            <i class="fas fa-bars"></i>
        </button>
        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn btn-secondary dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?> 
                    <small>(<?php echo htmlspecialchars($role_name); ?>)</small>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?php echo $base_path; ?>auth/profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo $base_path; ?>auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
