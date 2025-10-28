<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Skip login check when running from command line
if (php_sapi_name() !== 'cli') {
    // Check if user is not logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../../../auth/login.php");
        exit();
    }

    // Permission check - only admin can create tables
    if ($_SESSION['role_id'] != 1) {
        header("Location: ../../../index.php");
        exit();
    }
}

// Include database connection with proper path handling
$base_path = __DIR__ . '/../../../';

// Check if the file exists
if (file_exists($base_path . 'config/database.php')) {
    require_once $base_path . 'config/database.php';
    $db = new Database();
    $conn = $db->getConnection();
    echo "Database connection established.<br>";
} else {
    die("Error: Database configuration file not found at: " . $base_path . 'config/database.php');
}

// Begin transaction
$conn->beginTransaction();

echo "Starting table creation process...<br>";

try {
    // Create purchase_payments table if not exists
    $purchase_payments_sql = "CREATE TABLE IF NOT EXISTS purchase_payments (
        id INT(11) NOT NULL AUTO_INCREMENT,
        invoice_id INT(11) NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        reference_number VARCHAR(100) NULL,
        bank_account_id INT(11) NULL,
        notes TEXT NULL,
        status ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed',
        created_by INT(11) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_by INT(11) NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (invoice_id) REFERENCES purchase_invoices(id) ON DELETE RESTRICT,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->exec($purchase_payments_sql);
    
    // Create sales_payments table if not exists
    $sales_payments_sql = "CREATE TABLE IF NOT EXISTS sales_payments (
        id INT(11) NOT NULL AUTO_INCREMENT,
        invoice_id INT(11) NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        reference_number VARCHAR(100) NULL,
        bank_account_id INT(11) NULL,
        notes TEXT NULL,
        status ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed',
        created_by INT(11) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_by INT(11) NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE RESTRICT,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->exec($sales_payments_sql);
    
    // Check if payment_status column exists in purchase_invoices table
    $check_column_sql = "SHOW COLUMNS FROM purchase_invoices LIKE 'payment_status'";
    $check_column_stmt = $conn->prepare($check_column_sql);
    $check_column_stmt->execute();
    
    if ($check_column_stmt->rowCount() == 0) {
        // Add payment_status column to purchase_invoices table
        $add_column_sql = "ALTER TABLE purchase_invoices 
                          ADD COLUMN payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid' 
                          AFTER status";
        $conn->exec($add_column_sql);
    }
    
    // Check if payment_status column exists in sales_invoices table
    $check_column_sql = "SHOW COLUMNS FROM sales_invoices LIKE 'payment_status'";
    $check_column_stmt = $conn->prepare($check_column_sql);
    $check_column_stmt->execute();
    
    if ($check_column_stmt->rowCount() == 0) {
        // Add payment_status column to sales_invoices table
        $add_column_sql = "ALTER TABLE sales_invoices 
                          ADD COLUMN payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid' 
                          AFTER status";
        $conn->exec($add_column_sql);
    }
    
    // Commit transaction
    $conn->commit();
    
    if (php_sapi_name() === 'cli') {
        echo "Payment tables created successfully\n";
    } else {
        echo "<div class='alert alert-success'>Payment tables created successfully</div>";
    }
    
} catch (PDOException $e) {
    // Rollback transaction
    $conn->rollBack();
    
    if (php_sapi_name() === 'cli') {
        echo "Error creating payment tables: " . $e->getMessage() . "\n";
    } else {
        echo "<div class='alert alert-danger'>Error creating payment tables: " . $e->getMessage() . "</div>";
    }
}
?>
