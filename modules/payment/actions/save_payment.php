<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_add'])) {
    header("Location: ../../../index.php");
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../accounts/journal_entries.php");
    exit();
}

// Get form data
$invoice_id = $_POST['invoice_id'] ?? '';
$type = $_POST['type'] ?? '';
$payment_date = $_POST['payment_date'] ?? '';
$payment_method = $_POST['payment_method'] ?? '';
$bank_account_id = $_POST['bank_account_id'] ?? null;
$amount = $_POST['amount'] ?? 0;
$notes = $_POST['notes'] ?? '';
$reference_number = $_POST['reference_number'] ?? ''; // Keep for backward compatibility

// Validate required fields
if (empty($invoice_id) || empty($type) || 
    empty($payment_date) || empty($payment_method) || empty($amount)) {
    $response = [
        'success' => false,
        'message' => 'All required fields must be filled out'
    ];
    echo json_encode($response);
    exit();
}

// Validate amount
if (!is_numeric($amount) || $amount <= 0) {
    $response = [
        'success' => false,
        'message' => 'Payment amount must be greater than zero'
    ];
    echo json_encode($response);
    exit();
}

// Begin transaction
$conn->beginTransaction();

// Function to log debug information
function debug_log($message, $data = null) {
    $log = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data !== null) {
        $log .= ' - ' . json_encode($data);
    }
    error_log($log);
}

debug_log('Starting payment process', $_POST);

try {
    // Get invoice details
    if ($type == 'purchase') {
        $invoice_query = "SELECT pi.*, pi.total_amount as total, s.supplier_name, s.supplier_code, 
                         s.account_id as supplier_account_payable_id,
                         COALESCE(SUM(pp.amount), 0) as paid_amount,
                         fy.id as invoice_financial_year_id, fy.year_code as invoice_financial_year,
                         pi.supplier_id
                         FROM purchase_invoices pi
                         LEFT JOIN suppliers s ON pi.supplier_id = s.id
                         LEFT JOIN purchase_payments pp ON pi.id = pp.invoice_id AND pp.status = 'completed'
                         LEFT JOIN financial_years fy ON pi.financial_year_id = fy.id
                         WHERE pi.id = :invoice_id
                         GROUP BY pi.id";
        
        $invoice_stmt = $conn->prepare($invoice_query);
        $invoice_stmt->bindParam(':invoice_id', $invoice_id);
        $invoice_stmt->execute();
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            throw new Exception("Purchase invoice not found");
        }
        
        // Calculate remaining amount
        $remaining_amount = $invoice['total'] - $invoice['paid_amount'];
        
        // Validate payment amount
        if ($amount > $remaining_amount) {
            throw new Exception("Payment amount cannot exceed the remaining amount");
        }
        
        // Get settings for accounts
        $settings_query = "SELECT setting_key, setting_value FROM purchase_settings WHERE setting_group = 'accounting'";
        $settings_stmt = $conn->prepare($settings_query);
        $settings_stmt->execute();
        $settings = [];

        while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // First check if supplier has a specific accounts payable account
        $accounts_payable_id = null;
        
        if (!empty($invoice['supplier_account_payable_id'])) {
            debug_log('Using supplier-specific accounts payable account', $invoice['supplier_account_payable_id']);
            $accounts_payable_id = $invoice['supplier_account_payable_id'];
        } else {
            // Fall back to purchase settings accounts payable
            if (empty($settings['accounts_payable_account_id'])) {
                throw new Exception("Accounts Payable account not configured in Purchase Settings and supplier has no specific account.");
            }
            debug_log('Using default accounts payable account from settings', $settings['accounts_payable_account_id']);
            $accounts_payable_id = $settings['accounts_payable_account_id'];
        }

        if ($payment_method == 'Cash' && empty($settings['cash_account_id'])) {
            throw new Exception("Cash account not configured in Purchase Settings.");
        }
        
        // Get current financial year information for the payment date
        $fy_query = "SELECT id, year_code, is_closed FROM financial_years WHERE :payment_date BETWEEN start_date AND end_date";
        $fy_stmt = $conn->prepare($fy_query);
        $fy_stmt->bindParam(':payment_date', $payment_date);
        $fy_stmt->execute();
        $current_fy = $fy_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_fy) {
            throw new Exception("No active financial year found for the payment date. Please check your financial year settings.");
        }

        if ($current_fy['is_closed']) {
            throw new Exception("Cannot record payment in a closed financial year.");
        }

        // Use the financial year from the payment date, not from the invoice
        $payment_financial_year_id = $current_fy['id'];
        $payment_financial_year_code = $current_fy['year_code'];

        // Check if this is a cross-fiscal year payment
        $is_cross_fiscal_year = ($payment_financial_year_id != $invoice['invoice_financial_year_id']);
        
        // Generate voucher number
        $voucher_number_query = "SELECT MAX(CAST(SUBSTRING(reference_number, LOCATE('-', reference_number, LOCATE('-', reference_number) + 1) + 1) AS UNSIGNED)) as max_num 
                               FROM purchase_payments 
                               WHERE reference_number LIKE :prefix";
        debug_log('Voucher number query', $voucher_number_query);
        
        $voucher_number_stmt = $conn->prepare($voucher_number_query);
        $prefix = "PV-{$payment_financial_year_code}-%";
        debug_log('Voucher number prefix', $prefix);
        
        $voucher_number_stmt->bindParam(':prefix', $prefix);
        
        debug_log('Executing voucher number query');
        try {
            $voucher_number_stmt->execute();
            $result = $voucher_number_stmt->fetch(PDO::FETCH_ASSOC);
            debug_log('Voucher number query result', $result);
            $max_num = $result['max_num'] ?? 0;
            $next_num = $max_num ? $max_num + 1 : 1;
            $voucher_number = "PV-{$payment_financial_year_code}-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            debug_log('Generated voucher number', $voucher_number);
        } catch (PDOException $e) {
            debug_log('Voucher number generation failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // Use generated voucher number as reference number
        $reference_number = $voucher_number;

        // Insert payment record
        debug_log('Preparing payment insert', [
            'invoice_id' => $invoice_id,
            'payment_date' => $payment_date,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'reference_number' => $reference_number,
            'bank_account_id' => $bank_account_id,
            'notes' => $notes,
            'financial_year_id' => $payment_financial_year_id
        ]);
        
        $payment_query = "INSERT INTO purchase_payments (
                            invoice_id, payment_date, amount, payment_method, 
                            reference_number, bank_account_id, notes, 
                            financial_year_id, created_by, created_at
                        ) VALUES (
                            :invoice_id, :payment_date, :amount, :payment_method,
                            :reference_number, :bank_account_id, :notes,
                            :financial_year_id, :created_by, NOW()
                        )";

        debug_log('Payment query', $payment_query);
        $payment_stmt = $conn->prepare($payment_query);
        
        debug_log('Binding invoice_id', $invoice_id);
        $payment_stmt->bindParam(':invoice_id', $invoice_id);
        
        debug_log('Binding payment_date', $payment_date);
        $payment_stmt->bindParam(':payment_date', $payment_date);
        
        debug_log('Binding amount', $amount);
        $payment_stmt->bindParam(':amount', $amount);
        
        debug_log('Binding payment_method', $payment_method);
        $payment_stmt->bindParam(':payment_method', $payment_method);
        
        debug_log('Binding reference_number', $reference_number);
        $payment_stmt->bindParam(':reference_number', $reference_number);
        
        debug_log('Binding financial_year_id', $payment_financial_year_id);
        $payment_stmt->bindParam(':financial_year_id', $payment_financial_year_id);
        
        // Handle bank_account_id correctly for bindParam
        debug_log('Binding bank_account_id', $bank_account_id);
        if (!empty($bank_account_id)) {
            $payment_stmt->bindParam(':bank_account_id', $bank_account_id);
        } else {
            $payment_stmt->bindValue(':bank_account_id', null, PDO::PARAM_NULL);
        }
        
        // Handle notes correctly
        debug_log('Binding notes', $notes);
        if (!empty($notes)) {
            $payment_stmt->bindParam(':notes', $notes);
        } else {
            $payment_stmt->bindValue(':notes', null, PDO::PARAM_NULL);
        }
        
        debug_log('Binding created_by', $_SESSION['user_id']);
        $payment_stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        debug_log('Executing payment insert');
        try {
            $payment_stmt->execute();
            debug_log('Payment insert successful');
        } catch (PDOException $e) {
            debug_log('Payment insert failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        $payment_id = $conn->lastInsertId();
        debug_log('Payment ID', $payment_id);
        
        // Update invoice payment status
        $new_paid_amount = $invoice['paid_amount'] + $amount;
        
        // Get the adjusted total if there are returns
        $get_adjusted_total_query = "SELECT 
            total_amount as original_total,
            (SELECT COALESCE(SUM(pr.total_amount), 0) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved') as returned_amount,
            (total_amount - COALESCE((SELECT SUM(pr.total_amount) FROM purchase_returns pr WHERE pr.invoice_id = pi.id AND pr.status = 'approved'), 0)) as adjusted_total
        FROM purchase_invoices pi WHERE pi.id = :invoice_id";
        
        $adjusted_stmt = $conn->prepare($get_adjusted_total_query);
        $adjusted_stmt->bindParam(':invoice_id', $invoice_id);
        $adjusted_stmt->execute();
        $adjusted_data = $adjusted_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Use adjusted total if there are returns, otherwise use original total
        $compare_total = ($adjusted_data['returned_amount'] > 0) ? $adjusted_data['adjusted_total'] : $invoice['total'];
        
        // If adjusted total is 0 (fully returned) but there's a payment, mark as 'adjusted'
        if ($compare_total == 0 && $new_paid_amount > 0) {
            $payment_status = 'adjusted';
        } else {
            $payment_status = ($new_paid_amount >= $compare_total) ? 'paid' : 
                             (($new_paid_amount > 0) ? 'partial' : 'unpaid');
        }
        
        debug_log('Updating invoice payment status', [
            'new_paid_amount' => $new_paid_amount,
            'original_total' => $invoice['total'],
            'returned_amount' => $adjusted_data['returned_amount'],
            'adjusted_total' => $adjusted_data['adjusted_total'],
            'compare_total' => $compare_total,
            'payment_status' => $payment_status
        ]);
        
        // Calculate balance amount
        $balance_amount = $invoice['total'] - $new_paid_amount;
        
        $update_query = "UPDATE purchase_invoices SET 
                        payment_status = :payment_status,
                        paid_amount = :paid_amount,
                        balance_amount = :balance_amount,
                        updated_by = :updated_by,
                        updated_at = NOW()
                        WHERE id = :invoice_id";
        
        debug_log('Update invoice query', $update_query);
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':payment_status', $payment_status);
        $update_stmt->bindParam(':paid_amount', $new_paid_amount);
        $update_stmt->bindParam(':balance_amount', $balance_amount);
        $update_stmt->bindParam(':updated_by', $_SESSION['user_id']);
        $update_stmt->bindParam(':invoice_id', $invoice_id);
        
        debug_log('Executing invoice update');
        try {
            $update_stmt->execute();
            debug_log('Invoice update successful');
        } catch (PDOException $e) {
            debug_log('Invoice update failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // Create journal entry
        debug_log('Starting journal entry creation');
        
        // Generate entry number - Fix for MySQL SUBSTRING_INDEX function
        $entry_number_query = "SELECT MAX(CAST(SUBSTRING(entry_number, LOCATE('-', entry_number, LOCATE('-', entry_number) + 1) + 1) AS UNSIGNED)) as max_num 
                               FROM journal_entries 
                               WHERE entry_number LIKE :prefix";
        debug_log('Entry number query', $entry_number_query);
        
        $entry_number_stmt = $conn->prepare($entry_number_query);
        $prefix = "PMT-{$payment_financial_year_code}-%";
        debug_log('Entry number prefix', $prefix);
        
        $entry_number_stmt->bindParam(':prefix', $prefix);
        
        debug_log('Executing entry number query');
        try {
            $entry_number_stmt->execute();
            $result = $entry_number_stmt->fetch(PDO::FETCH_ASSOC);
            debug_log('Entry number query result', $result);
            $max_num = $result['max_num'] ?? 0;
            $next_num = $max_num ? $max_num + 1 : 1;
            $entry_number = "PMT-{$payment_financial_year_code}-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            debug_log('Generated entry number', $entry_number);
        } catch (PDOException $e) {
            debug_log('Entry number query failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // Create journal entry header
        $entry_description = "Payment for purchase invoice " . $invoice['invoice_number'] . " to " . $invoice['supplier_name'];

        // If this is a cross-fiscal year payment, add that information to the description
        if ($is_cross_fiscal_year) {
            $entry_description .= " (Invoice from FY {$invoice['invoice_financial_year']})";
        }
        
        debug_log('Journal entry description', $entry_description);
        
        $journal_query = "INSERT INTO journal_entries (
                            entry_number, entry_date, description, reference_number,
                            entry_type, financial_year_id, status, total_debit, total_credit,
                            created_by, created_at
                        ) VALUES (
                            :entry_number, :entry_date, :description, :reference_number,
                            'payment', :financial_year_id, 'approved', :total_debit, :total_credit,
                            :created_by, NOW()
                        )";

        debug_log('Journal entry query', $journal_query);
        
        $journal_stmt = $conn->prepare($journal_query);
        
        debug_log('Binding journal entry parameters', [
            'entry_number' => $entry_number,
            'entry_date' => $payment_date,
            'description' => $entry_description,
            'reference_number' => $reference_number,
            'financial_year_id' => $payment_financial_year_id,
            'amount' => $amount
        ]);
        
        $journal_stmt->bindParam(':entry_number', $entry_number);
        $journal_stmt->bindParam(':entry_date', $payment_date);
        $journal_stmt->bindParam(':description', $entry_description);
        $journal_stmt->bindParam(':reference_number', $reference_number);
        $journal_stmt->bindParam(':financial_year_id', $payment_financial_year_id); // Use payment's fiscal year
        $journal_stmt->bindParam(':total_debit', $amount);
        $journal_stmt->bindParam(':total_credit', $amount);
        $journal_stmt->bindParam(':created_by', $_SESSION['user_id']);
        
        debug_log('Executing journal entry insert');
        try {
            $journal_stmt->execute();
            debug_log('Journal entry insert successful');
        } catch (PDOException $e) {
            debug_log('Journal entry insert failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        $journal_id = $conn->lastInsertId();
        debug_log('Journal entry ID', $journal_id);
        
        // Create journal entry details
        debug_log('Creating journal entry details');
        
        // 1. Debit Accounts Payable
        debug_log('Creating debit entry for Accounts Payable', [
            'account_id' => $accounts_payable_id,
            'amount' => $amount
        ]);
        
        $detail_query = "INSERT INTO journal_entry_details (
                            journal_entry_id, account_id, description, debit_amount, credit_amount
                        ) VALUES (
                            :journal_entry_id, :account_id, :description, :debit_amount, :credit_amount
                        )";
        
        debug_log('Journal entry detail query', $detail_query);
        
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bindParam(':journal_entry_id', $journal_id);
        $detail_stmt->bindParam(':account_id', $accounts_payable_id);
        $detail_stmt->bindParam(':description', $entry_description);
        $detail_stmt->bindParam(':debit_amount', $amount);
        $detail_stmt->bindValue(':credit_amount', 0);
        
        debug_log('Executing debit entry insert');
        try {
            $detail_stmt->execute();
            debug_log('Debit entry insert successful');
        } catch (PDOException $e) {
            debug_log('Debit entry insert failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 2. Credit Cash/Bank
        $cash_account_id = null;
        
        if ($payment_method == 'Cash') {
            // Get cash account from settings - we already validated it exists above
            $cash_account_id = $settings['cash_account_id'];
            debug_log('Using cash account from settings', $cash_account_id);
        } else {
            // Get bank account
            if (empty($bank_account_id)) {
                throw new Exception("Bank account is required for this payment method");
            }
            
            debug_log('Getting bank account details', $bank_account_id);
            $bank_query = "SELECT account_id FROM bank_accounts WHERE id = :bank_account_id";
            $bank_stmt = $conn->prepare($bank_query);
            $bank_stmt->bindParam(':bank_account_id', $bank_account_id);
            
            try {
                $bank_stmt->execute();
                $bank = $bank_stmt->fetch(PDO::FETCH_ASSOC);
                debug_log('Bank account details', $bank);
                
                if (!$bank || empty($bank['account_id'])) {
                    throw new Exception("Bank account not properly configured");
                }
                
                $cash_account_id = $bank['account_id'];
                debug_log('Using bank account', $cash_account_id);
            } catch (PDOException $e) {
                debug_log('Bank account query failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
                throw $e;
            }
        }
        
        debug_log('Creating credit entry for Cash/Bank', [
            'account_id' => $cash_account_id,
            'amount' => $amount
        ]);
        
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bindParam(':journal_entry_id', $journal_id);
        $detail_stmt->bindParam(':account_id', $cash_account_id);
        $detail_stmt->bindParam(':description', $entry_description);
        $detail_stmt->bindValue(':debit_amount', 0);
        $detail_stmt->bindParam(':credit_amount', $amount);
        
        debug_log('Executing credit entry insert');
        try {
            $detail_stmt->execute();
            debug_log('Credit entry insert successful');
        } catch (PDOException $e) {
            debug_log('Credit entry insert failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // Note: Bank transactions are handled through journal entries
        // No separate bank_transactions table exists in this system
        debug_log('Bank transaction handling is done through journal entries');
        
        $redirect_url = "../../purchase/purchase_invoice_detail.php?id=" . $invoice_id;
        
    } else { // Sales invoice payment
        // Similar logic for sales invoices would go here
        throw new Exception("Sales invoice payments not yet implemented");
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response with additional information about cross-fiscal year payment
    $response = [
        'success' => true,
        'message' => 'Payment recorded successfully',
        'payment_id' => $payment_id,
        'is_cross_fiscal_year' => $is_cross_fiscal_year,
        'invoice_fiscal_year' => $invoice['invoice_financial_year'] ?? '',
        'payment_fiscal_year' => $payment_financial_year_code ?? ''
    ];
    echo json_encode($response);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollBack();
    
    // Return error response
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ];
    echo json_encode($response);
    exit();
}
?>
