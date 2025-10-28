<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Create initial roles
    $roles = [
        ['Admin', 'Full system access'],
        ['Manager', 'Management access'],
        ['Sales', 'Sales related access'],
        ['Accountant', 'Accounts related access'],
        ['Inventory', 'Inventory related access']
    ];
    
    $roleStmt = $db->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");
    foreach ($roles as $role) {
        $roleStmt->execute($role);
    }
    
    // Create admin user
    $adminPassword = password_hash("admin123", PASSWORD_DEFAULT);
    $userStmt = $db->prepare("INSERT INTO users (username, password, email, role_id) VALUES (?, ?, ?, ?)");
    $userStmt->execute(['admin', $adminPassword, 'admin@solar.com', 1]); // role_id 1 is Admin
    
    // Create basic permissions
    $permissions = [
        'view_dashboard',
        'manage_users',
        'manage_roles',
        'manage_company',
        'manage_accounts',
        'manage_inventory',
        'manage_purchases',
        'manage_sales',
        'view_reports',
        'export_reports'
    ];
    
    $permStmt = $db->prepare("INSERT INTO permissions (permission_name) VALUES (?)");
    foreach ($permissions as $perm) {
        $permStmt->execute([$perm]);
    }
    
    // Assign all permissions to admin role
    $rolePermStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
    for ($i = 1; $i <= count($permissions); $i++) {
        $rolePermStmt->execute([1, $i]); // role_id 1 (Admin) gets all permissions
    }
    
    echo "Database initialized successfully!\n";
    echo "Default admin credentials:\n";
    echo "Username: admin\n";
    echo "Password: admin123\n";
    echo "\nPlease change these credentials after first login.";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
