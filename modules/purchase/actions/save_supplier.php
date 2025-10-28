<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['purchase_add']) && !isset($_SESSION['permissions']['purchase_edit'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection and helpers
require_once '../../../config/database.php';
require_once '../../../includes/company_helpers.php';
$db = new Database();
$conn = $db->getConnection();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Log incoming request for debugging
file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . ' - POST data: ' . print_r($_POST, true) . "\n", FILE_APPEND);

/**
 * Create a supplier ledger account in the chart of accounts
 * 
 * @param PDO $conn Database connection
 * @param string $supplier_code Supplier code
 * @param string $supplier_name Supplier name
 * @param int $parent_account_id Parent account ID (Accounts Payable)
 * @param int $user_id User ID for created_by and updated_by
 * @return int|bool The new account ID or false on failure
 */
function createSupplierLedgerAccount($conn, $supplier_code, $supplier_name, $parent_account_id, $user_id = null) {
    try {
        // Log the function parameters for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - createSupplierLedgerAccount called with: supplier_code='$supplier_code', supplier_name='$supplier_name', parent_account_id=$parent_account_id, user_id=$user_id\n", FILE_APPEND);
        
        // Validate parent_account_id
        if (empty($parent_account_id) || !is_numeric($parent_account_id)) {
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR: Invalid parent_account_id: $parent_account_id\n", FILE_APPEND);
            return false;
        }
        
        // Get parent account information
        $parent_query = "SELECT account_code, level FROM chart_of_accounts WHERE id = ?";
        $parent_stmt = $conn->prepare($parent_query);
        $parent_stmt->execute([$parent_account_id]);
        $parent_info = $parent_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$parent_info) {
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR: Parent account not found with ID: $parent_account_id\n", FILE_APPEND);
            return false;
        }
        
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Found parent account: " . print_r($parent_info, true) . "\n", FILE_APPEND);
        
        // Generate account code for supplier (AP-S + supplier code)
        // Log the input supplier code for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Input supplier_code: '" . $supplier_code . "'\n", FILE_APPEND);
        
        // Make sure supplier code is not empty or just a dot
        if (empty($supplier_code) || $supplier_code == '.') {
            // Generate a default code
            $supplier_code = 'SUP-' . sprintf('%04d', rand(1, 9999));
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Empty supplier code detected, using generated code: '" . $supplier_code . "'\n", FILE_APPEND);
        }
        
        // Check if supplier code contains a hyphen
        if (strpos($supplier_code, '-') !== false) {
            $account_code = 'AP-S' . substr($supplier_code, strpos($supplier_code, '-') + 1);
        } else {
            // If no hyphen, use the whole code
            $account_code = 'AP-S' . $supplier_code;
        }
        
        // Make sure account code is not empty
        if (empty($account_code) || $account_code == 'AP-S') {
            $account_code = 'AP-S' . sprintf('%04d', rand(1, 9999));
        }
        
        // Log the generated account code
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Generated account_code: '" . $account_code . "'\n", FILE_APPEND);
        
        // Check if account code already exists
        $check_query = "SELECT id FROM chart_of_accounts WHERE account_code = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([$account_code]);
        
        if ($check_stmt->rowCount() > 0) {
            // Account already exists, return its ID
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Account code already exists, returning existing ID: " . $existing['id'] . "\n", FILE_APPEND);
            return $existing['id'];
        }
        
        // Create new supplier ledger account
        // Log the input supplier name for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Input supplier_name: '" . $supplier_name . "'\n", FILE_APPEND);
        
        // Make sure supplier name is not empty
        if (empty($supplier_name) || $supplier_name == '.' || trim($supplier_name) == '') {
            // If supplier name is empty or just a dot, use a default name with the code
            $supplier_name = 'Supplier ' . $supplier_code;
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Empty supplier name detected, using default: '" . $supplier_name . "'\n", FILE_APPEND);
        }
        
        // Double-check that supplier name is not empty after processing
        if (empty($supplier_name)) {
            $supplier_name = 'Unnamed Supplier ' . date('YmdHis');
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Still empty after check, using fallback name: '" . $supplier_name . "'\n", FILE_APPEND);
        }
        
        $account_name = 'AP - ' . $supplier_name;
        $description = 'Supplier account for ' . $supplier_name;
        
        // Log the generated account name
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Generated account_name: '" . $account_name . "'\n", FILE_APPEND);
        $level = $parent_info['level'] + 1;
        
        // Set default user_id if not provided
        if (empty($user_id) && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Using session user_id: $user_id\n", FILE_APPEND);
        } elseif (empty($user_id)) {
            $user_id = 1; // Default to admin if no user_id available
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - No user_id provided, using default: 1\n", FILE_APPEND);
        }
        
        // Get category_id from parent account
        $category_query = "SELECT category_id FROM chart_of_accounts WHERE id = ?";
        $category_stmt = $conn->prepare($category_query);
        $category_stmt->execute([$parent_account_id]);
        $category_info = $category_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category_info) {
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR: Could not get category_id from parent account\n", FILE_APPEND);
            return false;
        }
        
        $category_id = $category_info['category_id'];
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Using category_id: $category_id from parent account\n", FILE_APPEND);
        
        // Use direct values instead of subquery for better reliability
        $insert_query = "INSERT INTO chart_of_accounts 
                        (account_code, account_name, category_id, description, is_group, parent_id, level, 
                        opening_balance, current_balance, balance_type, is_bank_account, status, created_by, updated_by) 
                        VALUES 
                        (?, ?, ?, ?, 0, ?, ?, 0, 0, 'credit', 0, 1, ?, ?)";
        
        // Log the query and parameters for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Creating ledger account with query: " . $insert_query . "\n", FILE_APPEND);
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Parameters: " . $account_code . ", " . $account_name . ", " . $category_id . ", " . $description . ", " . $parent_account_id . ", " . $level . ", " . $user_id . ", " . $user_id . "\n", FILE_APPEND);
        
        $insert_stmt = $conn->prepare($insert_query);
        $result = $insert_stmt->execute([
            $account_code,
            $account_name,
            $category_id,
            $description,
            $parent_account_id,
            $level,
            $user_id,
            $user_id
        ]);
        
        if (!$result) {
            $error_info = $insert_stmt->errorInfo();
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR executing query: " . print_r($error_info, true) . "\n", FILE_APPEND);
            return false;
        }
        
        $new_id = $conn->lastInsertId();
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Successfully created ledger account with ID: $new_id\n", FILE_APPEND);
        return $new_id;
    } catch (PDOException $e) {
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - ERROR: PDOException: " . $e->getMessage() . "\n", FILE_APPEND);
        error_log("Error creating supplier ledger account: " . $e->getMessage());
        return false;
    }
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Log raw POST data for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Raw POST data: " . print_r($_POST, true) . "\n", FILE_APPEND);
        // Get form data
        $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
        $supplier_code = trim($_POST['supplier_code']);
        $supplier_name = trim($_POST['supplier_name']);
        
        // Log processed supplier code and name
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Processed supplier_code: '" . $supplier_code . "'\n", FILE_APPEND);
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Processed supplier_name: '" . $supplier_name . "'\n", FILE_APPEND);
        $contact_person = $_POST['contact_person'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $website = $_POST['website'];
        $address = $_POST['address'];
        $city = $_POST['city'];
        $state = $_POST['state'];
        $postal_code = $_POST['postal_code'];
        $country = $_POST['country'];
        $tax_number = $_POST['tax_number'];
        $payment_terms = $_POST['payment_terms'];
        $credit_limit = $_POST['credit_limit'];
        $account_id = !empty($_POST['account_id']) ? $_POST['account_id'] : null;
        $notes = $_POST['notes'];
        $user_id = $_SESSION['user_id'];
        
        // Validate required fields
        if (empty($supplier_code) || empty($supplier_name)) {
            $_SESSION['error'] = "Supplier code and name are required.";
            header("Location: ../suppliers.php");
            exit();
        }
        
        // Check if supplier code already exists (for new suppliers)
        if (!$supplier_id) {
            $check_query = "SELECT id FROM suppliers WHERE supplier_code = :code";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':code', $supplier_code);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error'] = "Supplier code already exists. Please use a different code.";
                header("Location: ../suppliers.php");
                exit();
            }
        } else {
            // Check if supplier code exists but belongs to a different supplier
            $check_query = "SELECT id FROM suppliers WHERE supplier_code = :code AND id != :id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':code', $supplier_code);
            $check_stmt->bindParam(':id', $supplier_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $_SESSION['error'] = "Supplier code already exists. Please use a different code.";
                header("Location: ../suppliers.php");
                exit();
            }
        }
        
        // Begin transaction
        $conn->beginTransaction();
        
        // If no account_id is provided, get the default accounts payable parent account
        if (empty($account_id)) {
            // Get default accounts payable parent account from settings
            $settings_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'default_accounts_payable_account'";
            $settings_stmt = $conn->prepare($settings_query);
            $settings_stmt->execute();
            $setting = $settings_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($setting && !empty($setting['setting_value'])) {
                $default_ap_account = $setting['setting_value'];
            } else {
                // Fallback: Get first liability account as parent
                $fallback_query = "SELECT coa.id FROM chart_of_accounts coa 
                                   JOIN account_categories ac ON coa.category_id = ac.id 
                                   WHERE ac.type = 'liability' AND coa.is_group = 1 
                                   LIMIT 1";
                $fallback_stmt = $conn->prepare($fallback_query);
                $fallback_stmt->execute();
                $fallback = $fallback_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($fallback) {
                    $default_ap_account = $fallback['id'];
                } else {
                    // No liability account found
                    throw new Exception("No liability account found for supplier ledger account creation.");
                }
            }
        }
        
        if ($supplier_id) {
            // For existing supplier, check if they already have a ledger account
            // Get current supplier data
            $supplier_query = "SELECT account_id FROM suppliers WHERE id = :id";
            $supplier_stmt = $conn->prepare($supplier_query);
            $supplier_stmt->bindParam(':id', $supplier_id);
            $supplier_stmt->execute();
            $current_supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
            
            // If supplier has no account_id OR the account_id is the parent account (not a supplier-specific account)
            if (empty($current_supplier['account_id'])) {
                // Store the parent account ID
                $parent_account_id = empty($account_id) ? $default_ap_account : $account_id;
                
                // Log the parent account ID
                file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Update: Parent account ID: " . $parent_account_id . "\n", FILE_APPEND);
                
                // Create new ledger account for supplier under the parent account
                $account_id = createSupplierLedgerAccount($conn, $supplier_code, $supplier_name, $parent_account_id, $user_id);
                
                if (!$account_id) {
                    throw new Exception("Failed to create supplier ledger account.");
                }
                
                // Log the account creation
                file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Created ledger account for existing supplier: ID " . $account_id . " under parent ID " . $parent_account_id . "\n", FILE_APPEND);
            } else {
                // Use the existing account ID
                $account_id = $current_supplier['account_id'];
                
                // Check if this is a supplier-specific account or just the parent account
                $check_account_query = "SELECT account_code, account_name FROM chart_of_accounts WHERE id = ?";
                $check_account_stmt = $conn->prepare($check_account_query);
                $check_account_stmt->execute([$account_id]);
                $account_info = $check_account_stmt->fetch(PDO::FETCH_ASSOC);
                
                // If this is just the parent account (not a supplier-specific account), create a new one
                if ($account_info && strpos($account_info['account_code'], 'AP-S') !== 0) {
                    // This is not a supplier-specific account, create a new one
                    $parent_account_id = $account_id;
                    
                    // Log that we're creating a new account because the existing one is not supplier-specific
                    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Existing account is not supplier-specific. Creating new one under parent ID: " . $parent_account_id . "\n", FILE_APPEND);
                    
                    // Create a new ledger account for the supplier
                    $account_id = createSupplierLedgerAccount($conn, $supplier_code, $supplier_name, $parent_account_id, $user_id);
                    
                    if (!$account_id) {
                        throw new Exception("Failed to create supplier ledger account.");
                    }
                    
                    // Log the account creation
                    file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Created new supplier-specific ledger account: ID " . $account_id . "\n", FILE_APPEND);
                }
            }
            
            // Update existing supplier using positional parameters to avoid binding issues
            $query = "UPDATE suppliers SET 
                      supplier_code = ?,
                      supplier_name = ?,
                      contact_person = ?,
                      phone = ?,
                      email = ?,
                      website = ?,
                      address = ?,
                      city = ?,
                      state = ?,
                      postal_code = ?,
                      country = ?,
                      tax_number = ?,
                      payment_terms = ?,
                      credit_limit = ?,
                      account_id = ?,
                      notes = ?,
                      updated_by = ?
                      WHERE id = ?";
            
            // Log the query and parameters for debugging
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Update SQL Query with params: " . 
                $query . " | ID: " . $supplier_id . ", Code: " . $supplier_code . ", Name: " . $supplier_name . "\n", FILE_APPEND);
            
            $stmt = $conn->prepare($query);
            $stmt->execute([
                $supplier_code,
                $supplier_name,
                $contact_person,
                $phone,
                $email,
                $website,
                $address,
                $city,
                $state,
                $postal_code,
                $country,
                $tax_number,
                $payment_terms,
                $credit_limit,
                $account_id,
                $notes,
                $user_id,
                $supplier_id
            ]);
            
            // Log activity
            $action = "update";
            $description = "Updated supplier: " . $supplier_name;
            $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                          VALUES (?, ?, 'purchase', ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([$user_id, $action, $supplier_id, $description]);
            
            $_SESSION['success'] = "Supplier updated successfully.";
        } else {
            // For new supplier, always create a new ledger account
            // Don't use the parent account directly as the supplier's account
            // Even if account_id is provided, it's the parent account ID, not the supplier's account
            
            // Store the parent account ID
            $parent_account_id = empty($account_id) ? $default_ap_account : $account_id;
            
            // Log the parent account ID
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Parent account ID: " . $parent_account_id . "\n", FILE_APPEND);
            
            // Create a new ledger account for the supplier under the parent account
            $account_id = createSupplierLedgerAccount($conn, $supplier_code, $supplier_name, $parent_account_id, $user_id);
            
            if (!$account_id) {
                throw new Exception("Failed to create supplier ledger account.");
            }
            
            // Log the account creation
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - Created new ledger account: ID " . $account_id . " under parent ID " . $parent_account_id . "\n", FILE_APPEND);
            
            // Insert new supplier with explicit values to avoid parameter binding issues
            $query = "INSERT INTO suppliers (
                      supplier_code, supplier_name, contact_person, phone, email, website,
                      address, city, state, postal_code, country, tax_number,
                      payment_terms, credit_limit, account_id, notes, status, created_by, updated_by
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)";
            
            // Log the query and parameters for debugging
            file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . " - SQL Query with params: " . 
                $query . " | " . $supplier_code . ", " . $supplier_name . ", " . $account_id . "\n", FILE_APPEND);
            
            $stmt = $conn->prepare($query);
            $stmt->execute([
                $supplier_code,
                $supplier_name,
                $contact_person,
                $phone,
                $email,
                $website,
                $address,
                $city,
                $state,
                $postal_code,
                $country,
                $tax_number,
                $payment_terms,
                $credit_limit,
                $account_id,
                $notes,
                $user_id,
                $user_id
            ]);
            
            $supplier_id = $conn->lastInsertId();
            
            // Log activity
            $action = "add";
            $description = "Added new supplier: " . $supplier_name;
            $log_query = "INSERT INTO activity_logs (user_id, action, module, record_id, description, created_at) 
                          VALUES (?, ?, 'purchase', ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([$user_id, $action, $supplier_id, $description]);
            
            $_SESSION['success'] = "Supplier added successfully.";
        }
        
        // Commit transaction
        $conn->commit();
        
        // Redirect back to suppliers page
        header("Location: ../suppliers.php");
        exit();
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        
        // Log the error for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . ' - PDO ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
        
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: ../suppliers.php");
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        
        // Log the error for debugging
        file_put_contents('../supplier_debug.log', date('Y-m-d H:i:s') . ' - ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
        
        $_SESSION['error'] = $e->getMessage();
        header("Location: ../suppliers.php");
        exit();
    }
} else {
    // If not POST request, redirect to suppliers page
    header("Location: ../suppliers.php");
    exit();
}
?>
