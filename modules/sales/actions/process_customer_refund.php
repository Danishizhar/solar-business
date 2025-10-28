<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../login.php");
    exit();
}

// Check permissions
if ($_SESSION['role_id'] != 1 && (!isset($_SESSION['permissions']['sales_payments']) || !in_array('create', $_SESSION['permissions']['sales_payments']))) {
    header("Location: ../../../index.php?error=You don't have permission to access this page");
    exit();
}

// Include necessary files
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/company_helpers.php';

// Initialize database connection
$db = new Database();
$conn = $db->getConnection();

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../customer_refund.php?error=Invalid request method");
    exit();
}

// Get form data
$refund_type = $_POST['refund_type'];
$customer_id = $_POST['customer_id'];
$invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
$financial_year_id = $_POST['financial_year_id'];
$notes = $_POST['notes'];

// Debug initial form data
error_log('=== INITIAL FORM DATA DEBUG ===');
error_log('refund_type: ' . $refund_type);
error_log('customer_id: ' . $customer_id . ' (type: ' . gettype($customer_id) . ')');
error_log('invoice_id: ' . $invoice_id . ' (type: ' . gettype($invoice_id) . ')');
error_log('financial_year_id: ' . $financial_year_id);
error_log('notes: ' . $notes);
error_log('=== END INITIAL DEBUG ===');

// Validate required fields
if (empty($customer_id) || empty($refund_type) || empty($financial_year_id)) {
    header("Location: ../customer_refund.php?error=Please fill all required fields");
    exit();
}

// Get financial year details to validate dates
$financial_year_query = "SELECT * FROM financial_years WHERE id = :financial_year_id";
$financial_year_stmt = $conn->prepare($financial_year_query);
$financial_year_stmt->bindValue(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
$financial_year_stmt->execute();
$financial_year = $financial_year_stmt->fetch(PDO::FETCH_ASSOC);

if (!$financial_year) {
    header("Location: ../customer_refund.php?error=Invalid financial year selected");
    exit();
}

// Get customer details
$customer_query = "SELECT * FROM customers WHERE id = :customer_id";
$customer_stmt = $conn->prepare($customer_query);
$customer_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
$customer_stmt->execute();
$customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: ../customer_refund.php?error=Invalid customer selected");
    exit();
}

// Get invoice or credit note details if provided
$invoice = null;
$credit_note = null;
$source_type = 'invoice';

if ($invoice_id > 0) {
    // First check if it's a credit note (include both active and partially_used)
    $credit_note_query = "SELECT cn.*, 'credit_note' as source_type FROM credit_notes cn WHERE cn.id = :id AND cn.customer_id = :customer_id AND (cn.status = 'active' OR cn.status = 'partially_used')";
    $credit_note_stmt = $conn->prepare($credit_note_query);
    $credit_note_stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
    $credit_note_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $credit_note_stmt->execute();
    $credit_note = $credit_note_stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log('=== CREDIT NOTE LOOKUP DEBUG ===');
    error_log('Looking for credit note with ID: ' . $invoice_id);
    error_log('Credit note found: ' . ($credit_note ? 'YES' : 'NO'));
    if ($credit_note) {
        error_log('Credit note status: ' . $credit_note['status']);
        error_log('Credit note remaining amount: ' . ($credit_note['remaining_amount'] ?: 'NULL'));
    }
    error_log('=== END CREDIT NOTE LOOKUP ===');
    
    if ($credit_note) {
        // It's a credit note
        $invoice = $credit_note;
        $source_type = 'credit_note';
    } else {
        // Check if it's a regular invoice
        $invoice_query = "SELECT *, 'invoice' as source_type FROM sales_invoices WHERE id = :invoice_id AND customer_id = :customer_id";
        $invoice_stmt = $conn->prepare($invoice_query);
        $invoice_stmt->bindParam(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $invoice_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $invoice_stmt->execute();
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            header("Location: ../customer_refund.php?error=Invalid invoice or credit note selected");
            exit();
        }
    }
}

// Enable error logging
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_log('Starting customer refund process');

// Dump POST data for debugging
error_log('POST data: ' . print_r($_POST, true));

// Function to debug SQL queries
function debugSql($query, $params = []) {
    $keys = array();
    $values = array();
    
    // Build a regular expression for each parameter
    foreach ($params as $key => $value) {
        if (is_string($key)) {
            $keys[] = '/:' . $key . '\b/';
        } else {
            $keys[] = '/[?]/';
        }
        
        if (is_null($value)) {
            $values[] = 'NULL';
        } elseif (is_string($value)) {
            $values[] = "'" . addslashes($value) . "'";
        } elseif (is_bool($value)) {
            $values[] = ($value ? '1' : '0');
        } else {
            $values[] = $value;
        }
    }
    
    $query = preg_replace($keys, $values, $query, 1, $count);
    return $query;
}

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Process based on refund type
    if ($refund_type === 'refund') {
        // Process direct refund to customer
        $amount = floatval($_POST['amount']);
        $refund_date = $_POST['refund_date'];
        $payment_method = $_POST['payment_method'];
        
        // Validate refund date falls within the selected financial year
        $refund_date_obj = new DateTime($refund_date);
        $start_date = new DateTime($financial_year['start_date']);
        $end_date = new DateTime($financial_year['end_date']);
        
        if ($refund_date_obj < $start_date || $refund_date_obj > $end_date) {
            throw new Exception("Refund date must be within the selected financial year's date range: " . 
                              date('d M Y', strtotime($financial_year['start_date'])) . ' to ' . 
                              date('d M Y', strtotime($financial_year['end_date'])));
        }
        
        if ($financial_year['is_closed']) {
            throw new Exception("Cannot create accounting entries in closed financial year {$financial_year['year_code']}.");
        }
        $reference_details = $_POST['reference_details'];
        
        // Get bank account or cash account based on payment method
        $bank_account_id = null;
        $account_id = null;
        
        if ($payment_method == 'Cash') {
            // Use cash account
            if (isset($_POST['cash_account_id']) && !empty($_POST['cash_account_id'])) {
                $account_id = $_POST['cash_account_id'];
            } else {
                // Try to find cash account
                $cash_account_query = "SELECT id FROM chart_of_accounts WHERE account_code = 'CASH' OR account_name LIKE '%cash%' LIMIT 1";
                $cash_account_stmt = $conn->prepare($cash_account_query);
                $cash_account_stmt->execute();
                $cash_account = $cash_account_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($cash_account) {
                    $account_id = $cash_account['id'];
                } else {
                    throw new Exception("Cash account not found");
                }
            }
        } else if (in_array($payment_method, ['Bank Transfer', 'Cheque', 'Credit Card', 'Online Payment'])) {
            // Use bank account
            if (!isset($_POST['bank_account_id']) || empty($_POST['bank_account_id'])) {
                throw new Exception("Bank account is required for {$payment_method} payment method");
            }
            
            $bank_account_id = $_POST['bank_account_id'];
            
            // Get account_id from bank_account
            $bank_account_query = "SELECT account_id FROM bank_accounts WHERE id = :bank_account_id";
            $bank_account_stmt = $conn->prepare($bank_account_query);
            $bank_account_stmt->bindParam(':bank_account_id', $bank_account_id, PDO::PARAM_INT);
            $bank_account_stmt->execute();
            $bank_account = $bank_account_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($bank_account) {
                $account_id = $bank_account['account_id'];
            } else {
                throw new Exception("Bank account not found");
            }
        } else {
            // For 'Other' payment method, check if bank account is provided
            if (isset($_POST['bank_account_id']) && !empty($_POST['bank_account_id'])) {
                $bank_account_id = $_POST['bank_account_id'];
                
                // Get account_id from bank_account
                $bank_account_query = "SELECT account_id FROM bank_accounts WHERE id = :bank_account_id";
                $bank_account_stmt = $conn->prepare($bank_account_query);
                $bank_account_stmt->bindParam(':bank_account_id', $bank_account_id, PDO::PARAM_INT);
                $bank_account_stmt->execute();
                $bank_account = $bank_account_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($bank_account) {
                    $account_id = $bank_account['account_id'];
                }
            } else {
                // Try to use cash account as fallback
                $cash_account_query = "SELECT id FROM chart_of_accounts WHERE account_code = 'CASH' OR account_name LIKE '%cash%' LIMIT 1";
                $cash_account_stmt = $conn->prepare($cash_account_query);
                $cash_account_stmt->execute();
                $cash_account = $cash_account_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($cash_account) {
                    $account_id = $cash_account['id'];
                } else {
                    throw new Exception("No payment account specified");
                }
            }
        }
        
        if ($amount <= 0) {
            throw new Exception("Refund amount must be greater than zero");
        }
        
        // Generate reference number
        $year_month = date('Ym', strtotime($refund_date));
        
        // Get next refund number
        $next_number_query = "SELECT COUNT(*) + 1 AS next_number FROM customer_refunds WHERE reference_number LIKE :prefix";
        $next_number_stmt = $conn->prepare($next_number_query);
        $prefix = "SR-$year_month-%";
        $next_number_stmt->bindParam(':prefix', $prefix);
        $next_number_stmt->execute();
        $next_number_result = $next_number_stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = str_pad($next_number_result['next_number'], 4, '0', STR_PAD_LEFT);
        
        $reference_number = "SR-$year_month-$next_number";
        
        // For refund type, we should not use invoice_id at all since it's a credit note ID
        // and would violate the foreign key constraint with sales_invoices
        $refund_query = "INSERT INTO customer_refunds (reference_number, customer_id, amount, refund_date, payment_method, bank_account_id, reference_details, notes, status, created_by, created_at) VALUES (:reference_number, :customer_id, :amount, :refund_date, :payment_method, :bank_account_id, :reference_details, :notes, 'completed', :created_by, NOW())";
        
        // Debug the clean query
        error_log('=== SQL QUERY STRUCTURE DEBUG ===');
        error_log('Clean SQL Query: ' . $refund_query);
        error_log('Query length: ' . strlen($refund_query));
        error_log('=== END SQL DEBUG ===');
                    
        // Debug the actual SQL query with parameters
        $debug_params = [
            ':reference_number' => $reference_number,
            ':customer_id' => $customer_id,
            ':amount' => $amount,
            ':refund_date' => $refund_date,
            ':payment_method' => $payment_method,
            ':bank_account_id' => $bank_account_id,
            ':reference_details' => $reference_details,
            ':notes' => $notes,
            ':created_by' => $_SESSION['user_id']
        ];
        error_log('Refund SQL Query with params: ' . debugSql($refund_query, $debug_params));
        
        // Prepare statement
        $refund_stmt = $conn->prepare($refund_query);
        
        // Bind all parameters using bindValue instead of bindParam to avoid reference issues
        $refund_stmt->bindValue(':reference_number', $reference_number);
        
        $refund_stmt->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
        $refund_stmt->bindValue(':amount', $amount);
        $refund_stmt->bindValue(':refund_date', $refund_date);
        $refund_stmt->bindValue(':payment_method', $payment_method);
        
        // Handle bank_account_id properly - can be NULL or integer
        if ($bank_account_id && $bank_account_id > 0) {
            $refund_stmt->bindValue(':bank_account_id', $bank_account_id, PDO::PARAM_INT);
        } else {
            $refund_stmt->bindValue(':bank_account_id', null, PDO::PARAM_NULL);
        }
        
        $refund_stmt->bindValue(':reference_details', $reference_details);
        $refund_stmt->bindValue(':notes', $notes);
        $refund_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        
        // Debug: Count parameters in SQL vs bound parameters
        preg_match_all('/:([a-zA-Z0-9_]+)/', $refund_query, $matches);
        $sql_params = $matches[1];
        error_log('=== REFUND QUERY DEBUG ===');
        error_log('Parameters in SQL: ' . implode(', ', $sql_params));
        error_log('Total SQL parameters: ' . count($sql_params));
        error_log('Bound parameters:');
        error_log('- reference_number: ' . $reference_number);
        error_log('- invoice_id: ' . ($invoice_id ?: 'NULL'));
        error_log('- customer_id: ' . $customer_id);
        error_log('- amount: ' . $amount);
        error_log('- refund_date: ' . $refund_date);
        error_log('- payment_method: ' . $payment_method);
        error_log('- bank_account_id: ' . ($bank_account_id ?: 'NULL'));
        error_log('- reference_details: ' . $reference_details);
        error_log('- notes: ' . $notes);
        error_log('- created_by: ' . $_SESSION['user_id']);
        error_log('=== END DEBUG ===');
        
        try {
            $refund_stmt->execute();
            error_log('Refund query executed successfully');
        } catch (PDOException $e) {
            error_log('PDO Error in refund query: ' . $e->getMessage());
            error_log('PDO Error Code: ' . $e->getCode());
            
            // Check for parameter binding issues
            $errorInfo = $refund_stmt->errorInfo();
            error_log('SQL Error Info: ' . print_r($errorInfo, true));
            
            // Debug the actual query that was attempted
            error_log('Full SQL Query:');
            error_log($refund_query);
            
            throw $e;
        }
        
        $refund_id = $conn->lastInsertId();
        
        // Create journal entry for the refund
        createRefundJournalEntry($conn, $refund_id, $reference_number, $refund_date, $customer_id, $amount, $invoice, $financial_year_id);
        
        // Update invoice or credit note if provided
        error_log('=== CREDIT NOTE UPDATE SECTION ===');
        error_log('Invoice exists: ' . ($invoice ? 'YES' : 'NO'));
        if ($invoice) {
            error_log('Source type: ' . $source_type);
            error_log('Invoice ID: ' . $invoice['id']);
            error_log('Payment method used: ' . $payment_method);
            error_log('Bank account ID: ' . ($bank_account_id ?: 'NULL'));
            error_log('Account ID for journal: ' . ($account_id ?: 'NULL'));
            
            if ($source_type === 'credit_note') {
                // Calculate remaining amount on the credit note
                // Use current remaining_amount if available, otherwise use the full amount
                $current_remaining = isset($invoice['remaining_amount']) && $invoice['remaining_amount'] !== null ? $invoice['remaining_amount'] : $invoice['amount'];
                $remaining_amount = $current_remaining - $amount;
                
                error_log('Credit note calculation: current_remaining=' . $current_remaining . ', refund_amount=' . $amount . ', new_remaining=' . $remaining_amount);
                
                // Update credit note with new balance and status
                // Fix: Use separate parameters to avoid duplicate binding
                $update_credit_note_query = "UPDATE credit_notes 
                                          SET 
                                              status = CASE 
                                                  WHEN :remaining_amount_check <= 0 THEN 'used'
                                                  ELSE 'partially_used'
                                              END,
                                              remaining_amount = :remaining_amount_update,
                                              updated_by = :updated_by, 
                                              updated_at = NOW() 
                                          WHERE id = :id";
                
                error_log('=== CREDIT NOTE UPDATE DEBUG ===');
                error_log('Credit note ID: ' . $invoice['id']);
                error_log('Remaining amount: ' . $remaining_amount);
                error_log('User ID: ' . $_SESSION['user_id']);
                error_log('Update query: ' . $update_credit_note_query);
                error_log('=== END CREDIT NOTE DEBUG ===');
                
                $update_credit_note_stmt = $conn->prepare($update_credit_note_query);
                $update_credit_note_stmt->bindValue(':remaining_amount_check', $remaining_amount);
                $update_credit_note_stmt->bindValue(':remaining_amount_update', $remaining_amount);
                $update_credit_note_stmt->bindValue(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
                $update_credit_note_stmt->bindValue(':id', $invoice['id'], PDO::PARAM_INT);
                
                try {
                    error_log('Executing credit note update...');
                    $update_credit_note_stmt->execute();
                    $affected_rows = $update_credit_note_stmt->rowCount();
                    error_log('Credit note updated successfully. Affected rows: ' . $affected_rows);
                    
                    // Verify the update by checking the database
                    $verify_query = "SELECT id, status, remaining_amount FROM credit_notes WHERE id = :id";
                    $verify_stmt = $conn->prepare($verify_query);
                    $verify_stmt->bindValue(':id', $invoice['id'], PDO::PARAM_INT);
                    $verify_stmt->execute();
                    $updated_credit_note = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($updated_credit_note) {
                        error_log('Verified credit note after update:');
                        error_log('- ID: ' . $updated_credit_note['id']);
                        error_log('- Status: ' . $updated_credit_note['status']);
                        error_log('- Remaining Amount: ' . $updated_credit_note['remaining_amount']);
                    } else {
                        error_log('ERROR: Could not verify credit note update!');
                    }
                    
                } catch (PDOException $e) {
                    error_log('PDO Error in credit note update: ' . $e->getMessage());
                    error_log('PDO Error Code: ' . $e->getCode());
                    throw $e;
                }
            } else {
                // No need to update the invoice as it's already fully returned and balanced
            }
        }
        
        $success_message = "Customer refund processed successfully. Reference: $reference_number";
        
    } else if ($refund_type === 'adjust') {
        // Process adjustment against another invoice
        $target_invoice_id = $_POST['target_invoice_id'];
        $adjustment_amount = floatval($_POST['adjustment_amount']);
        $adjustment_date = $_POST['adjustment_date'];
        
        // Validate adjustment date falls within the selected financial year
        $adjustment_date_obj = new DateTime($adjustment_date);
        $start_date = new DateTime($financial_year['start_date']);
        $end_date = new DateTime($financial_year['end_date']);
        
        if ($adjustment_date_obj < $start_date || $adjustment_date_obj > $end_date) {
            throw new Exception("Adjustment date must be within the selected financial year's date range: " . 
                              date('d M Y', strtotime($financial_year['start_date'])) . ' to ' . 
                              date('d M Y', strtotime($financial_year['end_date'])));
        }
        
        if ($financial_year['is_closed']) {
            throw new Exception("Cannot create accounting entries in closed financial year {$financial_year['year_code']}.");
        }
        
        // Get bank account or cash account based on payment method
        $bank_account_id = null;
        $account_id = null;
        $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
        $reference_details = '';
        
        // For adjustments, we don't need a physical account, but we'll use the same logic for consistency
        // and to ensure the database schema is consistent
        
        if (empty($target_invoice_id)) {
            throw new Exception("Please select a target invoice for adjustment");
        }
        
        if ($adjustment_amount <= 0) {
            throw new Exception("Adjustment amount must be greater than zero");
        }
        
        // Get target invoice details
        $target_invoice_query = "SELECT * FROM sales_invoices WHERE id = :invoice_id AND customer_id = :customer_id";
        $target_invoice_stmt = $conn->prepare($target_invoice_query);
        $target_invoice_stmt->bindParam(':invoice_id', $target_invoice_id, PDO::PARAM_INT);
        $target_invoice_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $target_invoice_stmt->execute();
        $target_invoice = $target_invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$target_invoice) {
            throw new Exception("Invalid target invoice selected");
        }
        
        if ($target_invoice['balance_amount'] <= 0) {
            throw new Exception("Target invoice has no outstanding balance");
        }
        
        // Limit adjustment amount to the target invoice's balance
        if ($adjustment_amount > $target_invoice['balance_amount']) {
            $adjustment_amount = $target_invoice['balance_amount'];
        }
        
        // Generate reference number
        $year_month = date('Ym', strtotime($adjustment_date));
        
        // Get next adjustment number
        $next_number_query = "SELECT COUNT(*) + 1 AS next_number FROM customer_refunds WHERE reference_number LIKE :prefix";
        $next_number_stmt = $conn->prepare($next_number_query);
        $prefix = "SA-$year_month-%";
        $next_number_stmt->bindParam(':prefix', $prefix);
        $next_number_stmt->execute();
        $next_number_result = $next_number_stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = str_pad($next_number_result['next_number'], 4, '0', STR_PAD_LEFT);
        
        $reference_number = "SA-$year_month-$next_number";
        
        error_log('Preparing to insert adjustment record');
        // Insert adjustment record into customer_refunds table
        $adjustment_query = "INSERT INTO customer_refunds (
                        reference_number,
                        invoice_id,
                        customer_id,
                        amount,
                        refund_date,
                        payment_method,
                        bank_account_id,
                        reference_details,
                        notes,
                        status,
                        created_by,
                        created_at
                    ) VALUES (
                        :reference_number,
                        :invoice_id,
                        :customer_id,
                        :amount,
                        :refund_date,
                        :payment_method,
                        :bank_account_id,
                        :reference_details,
                        :notes,
                        'completed',
                        :created_by,
                        NOW()
                    )";
                    
        // Prepare all parameters in advance to avoid binding issues
        $payment_method_value = 'Credit Adjustment';
        
        // Set reference details based on source type
        if ($source_type === 'credit_note') {
            $reference_details = "Adjustment from credit note {$invoice['note_number']}";
        } else {
            $reference_details = "Adjustment from overpayment" . ($invoice ? " on invoice {$invoice['invoice_number']}" : "");
        }
        
        // Debug the actual SQL query with parameters
        $debug_params = [
            ':reference_number' => $reference_number,
            ':invoice_id' => $target_invoice_id,
            ':customer_id' => $customer_id,
            ':amount' => $adjustment_amount,
            ':refund_date' => $adjustment_date,
            ':payment_method' => $payment_method_value,
            ':bank_account_id' => $bank_account_id,
            ':reference_details' => $reference_details,
            ':notes' => $notes,
            ':created_by' => $_SESSION['user_id']
        ];
        error_log('SQL Query with params: ' . debugSql($adjustment_query, $debug_params));
        
        // Prepare statement
        $adjustment_stmt = $conn->prepare($adjustment_query);
        
        // Bind parameters
        $adjustment_stmt->bindParam(':reference_number', $reference_number);
        $adjustment_stmt->bindParam(':invoice_id', $target_invoice_id, PDO::PARAM_INT);
        $adjustment_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
        $adjustment_stmt->bindParam(':amount', $adjustment_amount);
        $adjustment_stmt->bindParam(':refund_date', $adjustment_date);
        $adjustment_stmt->bindParam(':payment_method', $payment_method_value);
        if (!empty($bank_account_id)) {
            $adjustment_stmt->bindParam(':bank_account_id', $bank_account_id, PDO::PARAM_INT);
        } else {
            $adjustment_stmt->bindValue(':bank_account_id', null, PDO::PARAM_NULL);
        }
        $adjustment_stmt->bindParam(':reference_details', $reference_details);
        $adjustment_stmt->bindParam(':notes', $notes);
        $adjustment_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        
        // Debug: Count parameters in SQL vs bound parameters for adjustment
        preg_match_all('/:([a-zA-Z0-9_]+)/', $adjustment_query, $adj_matches);
        $adj_sql_params = $adj_matches[1];
        error_log('=== ADJUSTMENT QUERY DEBUG ===');
        error_log('Parameters in SQL: ' . implode(', ', $adj_sql_params));
        error_log('Total SQL parameters: ' . count($adj_sql_params));
        error_log('Bound parameters:');
        error_log('- reference_number: ' . $reference_number);
        error_log('- invoice_id (target): ' . $target_invoice_id);
        error_log('- customer_id: ' . $customer_id);
        error_log('- amount: ' . $adjustment_amount);
        error_log('- refund_date: ' . $adjustment_date);
        error_log('- payment_method: ' . $payment_method_value);
        error_log('- bank_account_id: ' . ($bank_account_id ?: 'NULL'));
        error_log('- reference_details: ' . $reference_details);
        error_log('- notes: ' . $notes);
        error_log('- created_by: ' . $_SESSION['user_id']);
        error_log('=== END ADJUSTMENT DEBUG ===');
        
        try {
            error_log('Executing adjustment query...');
            $adjustment_stmt->execute();
            error_log('Adjustment query executed successfully');
        } catch (PDOException $e) {
            error_log('PDO Error in adjustment query: ' . $e->getMessage());
            error_log('PDO Error Code: ' . $e->getCode());
            // Check for parameter binding issues
            $errorInfo = $adjustment_stmt->errorInfo();
            error_log('SQL Error: ' . print_r($errorInfo, true));
            
            // Try to identify missing parameters
            preg_match_all('/:([a-zA-Z0-9_]+)/', $adjustment_query, $matches);
            $sql_params = $matches[1];
            error_log('Parameters in SQL: ' . implode(', ', $sql_params));
            
            throw $e;
        }
        
        $payment_id = $conn->lastInsertId();
        
        // Update target invoice paid and balance amounts
        $new_paid_amount = $target_invoice['paid_amount'] + $adjustment_amount;
        $new_balance_amount = $target_invoice['balance_amount'] - $adjustment_amount;
        
        // Determine payment status
        $payment_status = 'unpaid';
        if ($new_balance_amount <= 0) {
            $payment_status = 'paid';
        } else if ($new_paid_amount > 0) {
            $payment_status = 'partial';
        }
        
        $update_invoice_query = "UPDATE sales_invoices 
                                SET paid_amount = :paid_amount,
                                    balance_amount = :balance_amount,
                                    payment_status = :payment_status,
                                    updated_by = :updated_by,
                                    updated_at = NOW()
                                WHERE id = :invoice_id";
                                
        $update_invoice_stmt = $conn->prepare($update_invoice_query);
        $update_invoice_stmt->bindParam(':paid_amount', $new_paid_amount);
        $update_invoice_stmt->bindParam(':balance_amount', $new_balance_amount);
        $update_invoice_stmt->bindParam(':payment_status', $payment_status);
        $update_invoice_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $update_invoice_stmt->bindParam(':invoice_id', $target_invoice_id, PDO::PARAM_INT);
        $update_invoice_stmt->execute();
        
        // Update credit note status if the source is a credit note
        if ($invoice && $source_type === 'credit_note') {
            // Calculate remaining amount on the credit note
            $current_remaining = isset($invoice['remaining_amount']) && $invoice['remaining_amount'] !== null ? 
                                $invoice['remaining_amount'] : $invoice['amount'];
            $new_remaining_amount = $current_remaining - $adjustment_amount;
            
            error_log('Credit note adjustment calculation:');
            error_log('- Credit note ID: ' . $invoice['id']);
            error_log('- Current remaining: ' . $current_remaining);
            error_log('- Adjustment amount: ' . $adjustment_amount);
            error_log('- New remaining amount: ' . $new_remaining_amount);
            
            // Determine status based on remaining amount
            $new_status = $new_remaining_amount <= 0 ? 'used' : 'partially_used';
            
            // Update credit note status and remaining amount
            $update_credit_note_query = "UPDATE credit_notes 
                                     SET status = :status, 
                                         remaining_amount = :remaining_amount,
                                         updated_by = :updated_by, 
                                         updated_at = NOW() 
                                     WHERE id = :id";
            $update_credit_note_stmt = $conn->prepare($update_credit_note_query);
            $update_credit_note_stmt->bindParam(':status', $new_status);
            $update_credit_note_stmt->bindParam(':remaining_amount', $new_remaining_amount);
            $update_credit_note_stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_credit_note_stmt->bindParam(':id', $invoice['id'], PDO::PARAM_INT);
            $update_credit_note_stmt->execute();
            
            // Verify the update
            $verify_query = "SELECT id, status, remaining_amount FROM credit_notes WHERE id = :id";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bindParam(':id', $invoice['id'], PDO::PARAM_INT);
            $verify_stmt->execute();
            $updated_credit_note = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($updated_credit_note) {
                error_log('Credit note after adjustment:');
                error_log('- Status: ' . $updated_credit_note['status']);
                error_log('- Remaining Amount: ' . $updated_credit_note['remaining_amount']);
            }
        }
        
        // Create journal entry for the adjustment
        createAdjustmentJournalEntry($conn, $payment_id, $payment_number, $adjustment_date, $customer_id, $adjustment_amount, $invoice, $target_invoice, $financial_year_id, $source_type);
        
        $success_message = "Payment adjustment processed successfully. Reference: $payment_number";
    } else {
        throw new Exception("Invalid refund type");
    }
    
    // Commit transaction
    error_log('=== COMMITTING TRANSACTION ===');
    error_log('Transaction active: ' . ($conn->inTransaction() ? 'YES' : 'NO'));
    $conn->commit();
    error_log('Transaction committed successfully');
    error_log('=== END TRANSACTION COMMIT ===');
    
    // Redirect with success message
    $success_params = array('success' => $success_message);
    $redirect_url = "../sales_invoices.php?" . http_build_query($success_params);
    header("Location: " . $redirect_url);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    error_log('=== EXCEPTION CAUGHT ===');
    error_log('Exception message: ' . $e->getMessage());
    error_log('Exception file: ' . $e->getFile());
    error_log('Exception line: ' . $e->getLine());
    
    if ($conn->inTransaction()) {
        error_log('Rolling back transaction...');
        $conn->rollBack();
        error_log('Transaction rolled back');
    } else {
        error_log('No active transaction to rollback');
    }
    error_log('=== END EXCEPTION HANDLING ===');
    
    // Redirect with error message
    $error_params = array(
        'error' => $e->getMessage()
    );
    
    // Only add customer_id if it's valid
    if (!empty($customer_id) && is_numeric($customer_id)) {
        $error_params['customer_id'] = $customer_id;
    }
    
    // Only add invoice_id if it's valid
    if (!empty($invoice_id) && is_numeric($invoice_id)) {
        $error_params['invoice_id'] = $invoice_id;
    }
    
    $redirect_url = "../customer_refund.php?" . http_build_query($error_params);
    header("Location: " . $redirect_url);
    exit();
}

/**
 * Create journal entry for customer refund
 */
function createRefundJournalEntry($conn, $refund_id, $reference_number, $refund_date, $customer_id, $amount, $invoice, $financial_year_id) {
    global $account_id, $payment_method; // Use the account_id and payment_method determined earlier
    
    // Determine if source is a credit note
    $source_type = isset($invoice['source_type']) ? $invoice['source_type'] : 'invoice';
    
    // Get customer account
    $customer_account_query = "SELECT account_id FROM customers WHERE id = :customer_id";
    $customer_account_stmt = $conn->prepare($customer_account_query);
    $customer_account_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_account_stmt->execute();
    $customer_account = $customer_account_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer_account || !$customer_account['account_id']) {
        throw new Exception("Customer has no associated account");
    }
    
    // Verify that we have a valid account_id for the payment
    if (!$account_id) {
        throw new Exception("Payment account not found");
    }
    
    // Generate entry number
    $year_code = date('Y', strtotime($refund_date));
    $entry_number = "SR-FY$year_code-" . str_pad($refund_id, 4, '0', STR_PAD_LEFT);
    
    // Get customer name for better description
    $customer_name_query = "SELECT customer_name, customer_code FROM customers WHERE id = :customer_id";
    $customer_name_stmt = $conn->prepare($customer_name_query);
    $customer_name_stmt->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_name_stmt->execute();
    $customer_data = $customer_name_stmt->fetch(PDO::FETCH_ASSOC);
    $customer_name = $customer_data ? $customer_data['customer_name'] . ' (' . $customer_data['customer_code'] . ')' : 'Unknown customer';
    
    // Create detailed description - We are RECEIVING refund FROM customer
    $description = "Refund received from customer " . $customer_name . ", Ref: " . $reference_number;
    if ($invoice) {
        if ($source_type === 'credit_note') {
            // Credit notes use note_number field directly
            $description .= ", Credit Note: " . $invoice['note_number'];
        } else {
            $description .= ", Invoice: " . $invoice['invoice_number'];
        }
    }
    $description .= ", Amount: " . formatNumber($conn, $amount) . ", Date: " . date('d-m-Y', strtotime($refund_date));
    
    // Log the invoice data structure for debugging
    error_log('Invoice data structure: ' . print_r($invoice, true));
    error_log('Creating journal entry with description: ' . $description);
    
    // Create journal entry with consistent parameter names
    $je_query = "INSERT INTO journal_entries (
                entry_number,
                entry_date,
                reference_number,
                description,
                entry_type,
                total_debit,
                total_credit,
                status,
                financial_year_id,
                created_by,
                created_at
            ) VALUES (
                :entry_number,
                :entry_date,
                :reference_number,
                :description,
                'payment',
                :total_debit,
                :total_credit,
                'approved',
                :financial_year_id,
                :created_by,
                NOW()
            )";
    
    // Log the SQL query with parameter substitution for debugging
    $debug_params = [
        ':entry_number' => $entry_number,
        ':entry_date' => $refund_date,
        ':reference_number' => $reference_number,
        ':description' => $description,
        ':total_debit' => $amount,
        ':total_credit' => $amount,
        ':financial_year_id' => $financial_year_id,
        ':created_by' => $_SESSION['user_id']
    ];
    error_log('Journal entry SQL with params: ' . debugSql($je_query, $debug_params));
    
    // Prepare and bind parameters with consistent names
    $je_stmt = $conn->prepare($je_query);
    $je_stmt->bindValue(':entry_number', $entry_number);
    $je_stmt->bindValue(':entry_date', $refund_date);
    $je_stmt->bindValue(':reference_number', $reference_number);
    $je_stmt->bindValue(':description', $description);
    $je_stmt->bindValue(':total_debit', $amount);
    $je_stmt->bindValue(':total_credit', $amount);
    $je_stmt->bindValue(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
    $je_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $je_stmt->execute();
        error_log('Journal entry created successfully');
    } catch (PDOException $e) {
        error_log('Error creating journal entry: ' . $e->getMessage());
        throw $e;
    }
    
    $journal_entry_id = $conn->lastInsertId();
    
    // Create journal entry details
    
    // 1. Credit customer account (AP decreases)
    $detail_query = "INSERT INTO journal_entry_details (
                    journal_entry_id,
                    account_id,
                    description,
                    debit_amount,
                    credit_amount,
                    created_by,
                    created_at
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    :description,
                    :debit_amount,
                    :credit_amount,
                    :created_by,
                    NOW()
                )";
                
    // Create description first - We RECEIVED refund FROM customer
    if ($source_type === 'credit_note') {
        // Credit notes use note_number field directly
        $detail_description = "Refund received from customer for credit note {$invoice['note_number']}: " . $reference_number;
    } else {
        $detail_description = "Refund received from customer: " . $reference_number;
    }
    
    error_log('Creating journal entry detail with description: ' . $detail_description);
    $zero = 0;
    
    $detail_stmt = $conn->prepare($detail_query);
    // Use bindValue for all parameters
    $detail_stmt->bindValue(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
    $detail_stmt->bindValue(':account_id', $customer_account['account_id'], PDO::PARAM_INT);
    $detail_stmt->bindValue(':description', $detail_description);
    $detail_stmt->bindValue(':debit_amount', $zero);
    $detail_stmt->bindValue(':credit_amount', $amount);
    $detail_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $detail_stmt->execute();
        error_log('Journal entry detail created successfully');
    } catch (PDOException $e) {
        error_log('Error creating journal entry detail: ' . $e->getMessage());
        throw $e;
    }
    
    // 2. Debit cash/bank account
    // Set appropriate description based on payment method
    // We RECEIVED cash/payment FROM customer as refund
    if ($payment_method == 'Cash') {
        $detail_description = "Cash received from customer refund: " . $reference_number;
    } else if (in_array($payment_method, ['Bank Transfer', 'Cheque'])) {
        $detail_description = "{$payment_method} received from customer refund: " . $reference_number;
    } else {
        $detail_description = "Payment received via {$payment_method} from customer refund: " . $reference_number;
    }
    
    error_log('Creating second journal entry detail with description: ' . $detail_description);
    
    $detail_stmt = $conn->prepare($detail_query);
    // Use bindValue for all parameters
    $detail_stmt->bindValue(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
    $detail_stmt->bindValue(':account_id', $account_id, PDO::PARAM_INT);
    $detail_stmt->bindValue(':description', $detail_description);
    $detail_stmt->bindValue(':debit_amount', $amount);
    $detail_stmt->bindValue(':credit_amount', $zero);
    $detail_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $detail_stmt->execute();
        error_log('Second journal entry detail created successfully');
    } catch (PDOException $e) {
        error_log('Error creating second journal entry detail: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Create journal entry for payment adjustment
 */
function createAdjustmentJournalEntry($conn, $payment_id, $payment_number, $adjustment_date, $customer_id, $amount, $source_invoice, $target_invoice, $financial_year_id, $source_type = 'invoice') {
    global $account_id, $payment_method; // Use the global variables
    // Get customer account
    $customer_account_query = "SELECT account_id FROM customers WHERE id = :customer_id";
    $customer_account_stmt = $conn->prepare($customer_account_query);
    $customer_account_stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_account_stmt->execute();
    $customer_account = $customer_account_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer_account || !$customer_account['account_id']) {
        throw new Exception("Customer has no associated account");
    }
    
    // Generate entry number
    $year_code = date('Y', strtotime($adjustment_date));
    $entry_number = "PA-FY$year_code-" . str_pad($payment_id, 4, '0', STR_PAD_LEFT);
    
    // Create journal entry
    $je_query = "INSERT INTO journal_entries (
                entry_number,
                entry_date,
                reference_number,
                description,
                entry_type,
                total_debit,
                total_credit,
                status,
                financial_year_id,
                created_by,
                created_at
            ) VALUES (
                :entry_number,
                :entry_date,
                :reference_number,
                :description,
                'payment',
                :total_debit,
                :total_credit,
                'approved',
                :financial_year_id,
                :created_by,
                NOW()
            )";
            
    // Get customer name for better description
    $customer_name_query = "SELECT customer_name, customer_code FROM customers WHERE id = :customer_id";
    $customer_name_stmt = $conn->prepare($customer_name_query);
    $customer_name_stmt->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
    $customer_name_stmt->execute();
    $customer_data = $customer_name_stmt->fetch(PDO::FETCH_ASSOC);
    $customer_name = $customer_data ? $customer_data['customer_name'] . ' (' . $customer_data['customer_code'] . ')' : 'Unknown customer';
    
    // Create detailed description
    $description = "Payment adjustment for " . $customer_name . ", Ref: " . $payment_number;
    if ($source_invoice) {
        if ($source_type === 'credit_note') {
            $description .= ", Credit Note: " . $source_invoice['note_number'] . " to Invoice: " . $target_invoice['invoice_number'];
        } else {
            $description .= ", From Invoice: " . $source_invoice['invoice_number'] . " to Invoice: " . $target_invoice['invoice_number'];
        }
    } else {
        $description .= ", To Invoice: " . $target_invoice['invoice_number'];
    }
    
    // Add amount and date to all descriptions
    $description .= ", Amount: " . formatNumber($conn, $amount) . ", Date: " . date('d-m-Y', strtotime($adjustment_date));
    
    error_log('Creating adjustment journal entry with description: ' . $description);
    
    $je_stmt = $conn->prepare($je_query);
    // Use bindValue instead of bindParam for all parameters to avoid reference issues
    $je_stmt->bindValue(':entry_number', $entry_number);
    $je_stmt->bindValue(':entry_date', $adjustment_date);
    $je_stmt->bindValue(':reference_number', $payment_number);
    $je_stmt->bindValue(':description', $description);
    $je_stmt->bindValue(':total_debit', $amount);
    $je_stmt->bindValue(':total_credit', $amount);
    $je_stmt->bindValue(':financial_year_id', $financial_year_id, PDO::PARAM_INT);
    $je_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $je_stmt->execute();
        error_log('Adjustment journal entry created successfully');
    } catch (PDOException $e) {
        error_log('Error creating adjustment journal entry: ' . $e->getMessage());
        throw $e;
    }
    
    $journal_entry_id = $conn->lastInsertId();
    
    // No actual cash movement, just an adjustment between invoices
    // Both sides affect the customer account
    
    // Create journal entry details - this is a contra entry within the customer account
    $detail_query = "INSERT INTO journal_entry_details (
                    journal_entry_id,
                    account_id,
                    description,
                    debit_amount,
                    credit_amount,
                    created_by,
                    created_at
                ) VALUES (
                    :journal_entry_id,
                    :account_id,
                    :description,
                    :debit_amount,
                    :credit_amount,
                    :created_by,
                    NOW()
                )";
    
    // 1. Credit customer account for source invoice (reducing the overpayment)
    // Create description first
    if ($source_type === 'credit_note') {
        $source_description = "Adjustment from credit note {$source_invoice['note_number']}";
    } else {
        $source_description = "Adjustment from overpayment" . ($source_invoice ? " on invoice {$source_invoice['invoice_number']}" : "");
    }
    
    error_log('Creating adjustment journal entry detail with description: ' . $source_description);
    $zero = 0;
    
    $detail_stmt = $conn->prepare($detail_query);
    // Use bindValue for all parameters
    $detail_stmt->bindValue(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
    $detail_stmt->bindValue(':account_id', $customer_account['account_id'], PDO::PARAM_INT);
    $detail_stmt->bindValue(':description', $source_description);
    $detail_stmt->bindValue(':debit_amount', $zero);
    $detail_stmt->bindValue(':credit_amount', $amount);
    $detail_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $detail_stmt->execute();
        error_log('Adjustment journal entry detail created successfully');
    } catch (PDOException $e) {
        error_log('Error creating adjustment journal entry detail: ' . $e->getMessage());
        throw $e;
    }
    
    // 2. Debit customer account for target invoice (applying the payment)
    $target_description = "Payment applied to invoice {$target_invoice['invoice_number']}";
    
    error_log('Creating target journal entry detail with description: ' . $target_description);
    
    $detail_stmt = $conn->prepare($detail_query);
    // Use bindValue for all parameters
    $detail_stmt->bindValue(':journal_entry_id', $journal_entry_id, PDO::PARAM_INT);
    $detail_stmt->bindValue(':account_id', $customer_account['account_id'], PDO::PARAM_INT);
    $detail_stmt->bindValue(':description', $target_description);
    $detail_stmt->bindValue(':debit_amount', $amount);
    $detail_stmt->bindValue(':credit_amount', $zero);
    $detail_stmt->bindValue(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    try {
        $detail_stmt->execute();
        error_log('Target journal entry detail created successfully');
    } catch (PDOException $e) {
        error_log('Error creating target journal entry detail: ' . $e->getMessage());
        throw $e;
    }
}
?>
