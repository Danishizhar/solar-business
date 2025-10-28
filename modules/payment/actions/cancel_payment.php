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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['payment_cancel'])) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to cancel payments'
    ]);
    exit();
}

// Include database connection
require_once '../../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check if payment ID and type are provided
if (!isset($_POST['id']) || empty($_POST['id']) || !isset($_POST['type']) || empty($_POST['type'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Payment ID and type are required'
    ]);
    exit();
}

$payment_id = $_POST['id'];
$type = $_POST['type'];

// Validate type
$valid_types = ['purchase', 'sales'];
if (!in_array($type, $valid_types)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid payment type'
    ]);
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

debug_log('Starting payment cancellation process', ['payment_id' => $payment_id, 'type' => $type]);

try {
    // Get payment details based on type
    if ($type == 'purchase') {
        $payment_query = "SELECT pp.*, pi.id as invoice_id, pi.invoice_number, pi.total_amount as invoice_total,
                          s.supplier_name, s.account_id as supplier_account_payable_id,
                          fy.id as payment_financial_year_id, fy.year_code as payment_financial_year_code,
                          fy_invoice.id as invoice_financial_year_id, fy_invoice.year_code as invoice_financial_year_code
                          FROM purchase_payments pp
                          JOIN purchase_invoices pi ON pp.invoice_id = pi.id
                          JOIN suppliers s ON pi.supplier_id = s.id
                          JOIN financial_years fy ON pp.financial_year_id = fy.id
                          JOIN financial_years fy_invoice ON pi.financial_year_id = fy_invoice.id
                          WHERE pp.id = :payment_id";
        
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bindParam(':payment_id', $payment_id);
        $payment_stmt->execute();
        $payment = $payment_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            throw new Exception("Payment not found");
        }
        
        debug_log('Payment details retrieved', $payment);
        
        // Check if payment is already cancelled
        if ($payment['status'] === 'cancelled') {
            throw new Exception("This payment has already been cancelled");
        }
        
        // Extract necessary data from payment record
        $invoice_id = $payment['invoice_id'];
        $amount = $payment['amount'];
        $payment_date = $payment['payment_date'];
        $payment_method = $payment['payment_method'];
        $reference_number = $payment['reference_number'];
        $bank_account_id = $payment['bank_account_id'];
        $payment_financial_year_id = $payment['payment_financial_year_id'];
        $payment_financial_year_code = $payment['payment_financial_year_code'];
        $invoice_financial_year_code = $payment['invoice_financial_year_code'];
        $is_cross_fiscal_year = ($payment['payment_financial_year_id'] != $payment['invoice_financial_year_id']);
        
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
        
        if (!empty($payment['supplier_account_payable_id'])) {
            debug_log('Using supplier-specific accounts payable account', $payment['supplier_account_payable_id']);
            $accounts_payable_id = $payment['supplier_account_payable_id'];
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
        
        // Get current financial year for today's date (for the reversal)
        $today = date('Y-m-d');
        $fy_query = "SELECT id, year_code, is_closed FROM financial_years WHERE :today BETWEEN start_date AND end_date";
        $fy_stmt = $conn->prepare($fy_query);
        $fy_stmt->bindParam(':today', $today);
        $fy_stmt->execute();
        $current_fy = $fy_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_fy) {
            throw new Exception("No active financial year found for today's date. Please check your financial year settings.");
        }

        if ($current_fy['is_closed']) {
            throw new Exception("Current financial year is closed. Cannot cancel payment.");
        }
        
        // Set reversal financial year information (using current financial year for the reversal)
        $reversal_financial_year_id = $current_fy['id'];
        $reversal_financial_year_code = $current_fy['year_code'];
        
        debug_log('Reversal financial year', [
            'id' => $reversal_financial_year_id,
            'code' => $reversal_financial_year_code
        ]);
        
        // 1. Update payment status to cancelled
        debug_log('Updating payment status to cancelled', ['payment_id' => $payment_id]);
        
        $update_payment_query = "UPDATE purchase_payments SET 
                                status = 'cancelled',
                                updated_by = :updated_by,
                                updated_at = NOW()
                                WHERE id = :payment_id";
        
        debug_log('Update payment query', $update_payment_query);
        $update_payment_stmt = $conn->prepare($update_payment_query);
        $update_payment_stmt->bindParam(':updated_by', $_SESSION['user_id']);
        $update_payment_stmt->bindParam(':payment_id', $payment_id);
        
        try {
            $update_payment_stmt->execute();
            debug_log('Payment status update successful');
        } catch (PDOException $e) {
            debug_log('Payment status update failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 2. Recalculate invoice payment status by summing all non-cancelled payments
        debug_log('Recalculating invoice payment status', ['invoice_id' => $invoice_id]);
        
        $recalc_query = "SELECT 
                           pi.total_amount as invoice_total,
                           COALESCE(SUM(pp.amount), 0) as total_paid
                         FROM purchase_invoices pi
                         LEFT JOIN purchase_payments pp ON pi.id = pp.invoice_id AND pp.status = 'completed'
                         WHERE pi.id = :invoice_id
                         GROUP BY pi.id";
        
        $recalc_stmt = $conn->prepare($recalc_query);
        $recalc_stmt->bindParam(':invoice_id', $invoice_id);
        
        try {
            $recalc_stmt->execute();
            $recalc_result = $recalc_stmt->fetch(PDO::FETCH_ASSOC);
            debug_log('Recalculation result', $recalc_result);
            
            if (!$recalc_result) {
                throw new Exception("Invoice not found during recalculation");
            }
            
            $invoice_total = $recalc_result['invoice_total'];
            $total_paid = $recalc_result['total_paid'];
            
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
            $compare_total = ($adjusted_data['returned_amount'] > 0) ? $adjusted_data['adjusted_total'] : $invoice_total;
            
            // Determine new payment status
            if ($compare_total == 0 && $total_paid > 0) {
                // If adjusted total is 0 (fully returned) but there's a payment, mark as 'adjusted'
                $payment_status = 'adjusted';
            } else {
                $payment_status = 'unpaid';
                if ($total_paid >= $compare_total) {
                    $payment_status = 'paid';
                } else if ($total_paid > 0) {
                    $payment_status = 'partial';
                }
            }
            
            $balance_amount = $compare_total - $total_paid;
            
            debug_log('New invoice payment status', [
                'total_paid' => $total_paid,
                'original_total' => $invoice_total,
                'returned_amount' => $adjusted_data['returned_amount'],
                'adjusted_total' => $adjusted_data['adjusted_total'],
                'compare_total' => $compare_total,
                'payment_status' => $payment_status,
                'balance_amount' => $balance_amount
            ]);
            
            // Update invoice payment status
            $update_query = "UPDATE purchase_invoices SET 
                            payment_status = :payment_status,
                            paid_amount = :paid_amount,
                            balance_amount = :balance_amount,
                            updated_by = :updated_by,
                            updated_at = NOW()
                            WHERE id = :invoice_id";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':payment_status', $payment_status);
            $update_stmt->bindParam(':paid_amount', $total_paid);
            $update_stmt->bindParam(':balance_amount', $balance_amount);
            $update_stmt->bindParam(':updated_by', $_SESSION['user_id']);
            $update_stmt->bindParam(':invoice_id', $invoice_id);
            
            $update_stmt->execute();
            debug_log('Invoice payment status updated successfully');
            
        } catch (PDOException $e) {
            debug_log('Invoice recalculation failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 3. Find the original journal entry for this payment
        debug_log('Finding original journal entry', ['reference_number' => $reference_number]);
        
        $find_journal_query = "SELECT * FROM journal_entries 
                              WHERE reference_number = :reference_number 
                              AND entry_type = 'payment'";
                              
        $find_journal_stmt = $conn->prepare($find_journal_query);
        $find_journal_stmt->bindParam(':reference_number', $reference_number);
        
        try {
            $find_journal_stmt->execute();
            $original_journal = $find_journal_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$original_journal) {
                throw new Exception("Original journal entry not found for payment");
            }
            
            debug_log('Found original journal entry', $original_journal);
            
        } catch (PDOException $e) {
            debug_log('Finding journal entry failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 4. Generate a reversal entry number
        $entry_number_query = "SELECT MAX(CAST(SUBSTRING(entry_number, LOCATE('-', entry_number, LOCATE('-', entry_number) + 1) + 1) AS UNSIGNED)) as max_num 
                              FROM journal_entries 
                              WHERE entry_number LIKE :prefix";
        
        $entry_number_stmt = $conn->prepare($entry_number_query);
        $prefix = "REV-{$reversal_financial_year_code}-%";
        
        $entry_number_stmt->bindParam(':prefix', $prefix);
        
        try {
            $entry_number_stmt->execute();
            $result = $entry_number_stmt->fetch(PDO::FETCH_ASSOC);
            $max_num = $result['max_num'] ?? 0;
            $next_num = $max_num ? $max_num + 1 : 1;
            $reversal_entry_number = "REV-{$reversal_financial_year_code}-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            debug_log('Generated reversal entry number', $reversal_entry_number);
        } catch (PDOException $e) {
            debug_log('Reversal entry number generation failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 5. Create reversal journal entry header
        $today = date('Y-m-d');
        $reversal_description = "Reversal of payment {$reference_number} for purchase invoice {$payment['invoice_number']} to {$payment['supplier_name']}";
        
        // If this was a cross-fiscal year payment, add that information to the description
        if ($is_cross_fiscal_year) {
            $reversal_description .= " (Original payment was cross-fiscal year)";
        }
        
        debug_log('Creating reversal journal entry', [
            'entry_number' => $reversal_entry_number,
            'description' => $reversal_description,
            'amount' => $amount
        ]);
        
        $journal_query = "INSERT INTO journal_entries (
                            entry_number, entry_date, description, reference_number,
                            entry_type, financial_year_id, status, total_debit, total_credit,
                            created_by, created_at, reversal_of
                        ) VALUES (
                            :entry_number, :entry_date, :description, :reference_number,
                            'reversal', :financial_year_id, 'approved', :total_debit, :total_credit,
                            :created_by, NOW(), :reversal_of
                        )";
        
        $journal_stmt = $conn->prepare($journal_query);
        $journal_stmt->bindParam(':entry_number', $reversal_entry_number);
        $journal_stmt->bindParam(':entry_date', $today);
        $journal_stmt->bindParam(':description', $reversal_description);
        $journal_stmt->bindParam(':reference_number', $reference_number);
        $journal_stmt->bindParam(':financial_year_id', $reversal_financial_year_id);
        $journal_stmt->bindParam(':total_debit', $amount);
        $journal_stmt->bindParam(':total_credit', $amount);
        $journal_stmt->bindParam(':created_by', $_SESSION['user_id']);
        $journal_stmt->bindParam(':reversal_of', $original_journal['id']);
        
        try {
            $journal_stmt->execute();
            $reversal_journal_id = $conn->lastInsertId();
            debug_log('Reversal journal entry created', ['reversal_journal_id' => $reversal_journal_id]);
        } catch (PDOException $e) {
            debug_log('Reversal journal entry creation failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 6. Find the original journal entry details to reverse them
        debug_log('Finding original journal entry details', ['original_journal_id' => $original_journal['id']]);
        
        $find_details_query = "SELECT * FROM journal_entry_details WHERE journal_entry_id = :journal_entry_id";
        $find_details_stmt = $conn->prepare($find_details_query);
        $find_details_stmt->bindParam(':journal_entry_id', $original_journal['id']);
        
        try {
            $find_details_stmt->execute();
            $original_details = $find_details_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($original_details)) {
                throw new Exception("Original journal entry details not found");
            }
            
            debug_log('Found original journal entry details', ['count' => count($original_details)]);
            
        } catch (PDOException $e) {
            debug_log('Finding journal entry details failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        }
        
        // 7. Create reversal journal entry details (swap debit and credit)
        debug_log('Creating reversal journal entry details');
        
        $detail_query = "INSERT INTO journal_entry_details (
                            journal_entry_id, account_id, description, debit_amount, credit_amount
                        ) VALUES (
                            :journal_entry_id, :account_id, :description, :debit_amount, :credit_amount
                        )";
        
        $detail_stmt = $conn->prepare($detail_query);
        
        foreach ($original_details as $original) {
            // Create a reversal entry by swapping debit and credit amounts
            $reversal_description = "Reversal of " . $original['description'];
            
            // Swap debit and credit amounts
            $debit_amount = $original['credit_amount'];
            $credit_amount = $original['debit_amount'];
            
            debug_log('Creating reversal detail entry', [
                'account_id' => $original['account_id'],
                'original_debit' => $original['debit_amount'],
                'original_credit' => $original['credit_amount'],
                'reversal_debit' => $debit_amount,
                'reversal_credit' => $credit_amount
            ]);
            
            $detail_stmt->bindParam(':journal_entry_id', $reversal_journal_id);
            $detail_stmt->bindParam(':account_id', $original['account_id']);
            $detail_stmt->bindParam(':description', $reversal_description);
            $detail_stmt->bindParam(':debit_amount', $debit_amount);
            $detail_stmt->bindParam(':credit_amount', $credit_amount);
            
            try {
                $detail_stmt->execute();
                debug_log('Reversal detail entry created successfully');
            } catch (PDOException $e) {
                debug_log('Reversal detail entry creation failed', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
                throw $e;
            }
        }
        
        // Note: Bank transactions are handled through journal entries
        // No separate bank_transactions table exists in this system
        debug_log('Payment reversal completed successfully');
        
        // Add a note to the payment record about the cancellation
        if (!empty($cancellation_notes)) {
            $notes_query = "UPDATE purchase_payments SET cancellation_notes = :notes WHERE id = :payment_id";
            $notes_stmt = $conn->prepare($notes_query);
            $notes_stmt->bindParam(':notes', $cancellation_notes);
            $notes_stmt->bindParam(':payment_id', $payment_id);
            
            try {
                $notes_stmt->execute();
                debug_log('Cancellation notes added successfully');
            } catch (PDOException $e) {
                debug_log('Failed to add cancellation notes', ['error' => $e->getMessage()]);
                // Not critical, so don't throw exception
            }
        }
        
        $redirect_url = "../../purchase/purchase_invoice_detail.php?id=" . $invoice_id;
        
    } else { // Sales invoice payment
        // Similar logic for sales invoices would go here
        throw new Exception("Sales invoice payments not yet implemented");
    }
    
    // Commit transaction
    $conn->commit();
    
    // Return success response
    $response = [
        'success' => true,
        'message' => 'Payment cancelled successfully',
        'payment_id' => $payment_id,
        'invoice_id' => $invoice_id,
        'reversal_journal_id' => $reversal_journal_id ?? null,
        'reversal_financial_year' => $reversal_financial_year_code ?? ''
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
