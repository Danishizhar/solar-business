<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_add'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();

        // Validate required fields
        if (empty($_POST['entry_date']) || empty($_POST['entry_type']) || empty($_POST['description'])) {
            throw new Exception("Please fill all required fields");
        }

        // Generate entry number (Format: JE-YYYYMM-XXXX)
        $year_month = date('Ym', strtotime($_POST['entry_date']));
        $number_query = "
            SELECT MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)) as last_number 
            FROM journal_entries 
            WHERE entry_number LIKE :pattern
            AND status != 'deleted'
        ";
        $pattern = "JE-$year_month-%";
        $number_stmt = $conn->prepare($number_query);
        $number_stmt->bindParam(':pattern', $pattern, PDO::PARAM_STR);
        $number_stmt->execute();
        $row = $number_stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = str_pad(($row['last_number'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
        $entry_number = "JE-$year_month-$next_number";

        // Calculate totals from details
        $total_debit = 0;
        $total_credit = 0;
        foreach ($_POST['details'] as $detail) {
            $total_debit += floatval($detail['debit_amount']);
            $total_credit += floatval($detail['credit_amount']);
        }

        // Validate debit and credit equality
        if (abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Debit and Credit totals must be equal");
        }

        // Verify cost center if provided
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;
        if ($cost_center_id) {
            $cc_query = "
                SELECT id 
                FROM cost_centers 
                WHERE id = :cost_center_id 
                AND status = 1
            ";
            $cc_stmt = $conn->prepare($cc_query);
            $cc_stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
            $cc_stmt->execute();
            
            if (!$cc_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Cost center not found");
            }
        }

        // Insert journal entry header
        $insert_query = "
            INSERT INTO journal_entries (
                entry_number, 
                entry_date, 
                entry_type, 
                reference_number, 
                description, 
                cost_center_id, 
                total_debit, 
                total_credit,
                status, 
                created_by, 
                created_at,
                updated_by,
                updated_at
            ) VALUES (
                :entry_number,
                :entry_date,
                :entry_type,
                :reference_number,
                :description,
                :cost_center_id,
                :total_debit,
                :total_credit,
                'draft',
                :user_id,
                NOW(),
                :user_id2,
                NOW()
            )
        ";

        $stmt = $conn->prepare($insert_query);
        
        $reference_number = !empty($_POST['reference_number']) ? $_POST['reference_number'] : null;
        
        $stmt->bindParam(':entry_number', $entry_number, PDO::PARAM_STR);
        $stmt->bindParam(':entry_date', $_POST['entry_date'], PDO::PARAM_STR);
        $stmt->bindParam(':entry_type', $_POST['entry_type'], PDO::PARAM_STR);
        $stmt->bindParam(':reference_number', $reference_number, PDO::PARAM_STR);
        $stmt->bindParam(':description', $_POST['description'], PDO::PARAM_STR);
        $stmt->bindParam(':cost_center_id', $cost_center_id, PDO::PARAM_INT);
        $stmt->bindParam(':total_debit', $total_debit, PDO::PARAM_STR);
        $stmt->bindParam(':total_credit', $total_credit, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            throw new Exception("Error saving journal entry");
        }
        
        $entry_id = $conn->lastInsertId();

        // Insert journal entry details
        $detail_query = "
            INSERT INTO journal_entry_details (
                journal_entry_id, 
                line_number,
                account_id, 
                description, 
                debit_amount,
                credit_amount,
                cost_center_id,
                created_by,
                created_at,
                updated_by,
                updated_at
            ) VALUES (
                :entry_id,
                :line_number,
                :account_id,
                :description,
                :debit_amount,
                :credit_amount,
                :detail_cost_center_id,
                :user_id,
                NOW(),
                :user_id2,
                NOW()
            )
        ";

        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bindParam(':entry_id', $entry_id, PDO::PARAM_INT);
        $detail_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $detail_stmt->bindParam(':user_id2', $_SESSION['user_id'], PDO::PARAM_INT);

        $line_number = 1;
        foreach ($_POST['details'] as $detail) {
            if (empty($detail['account_id'])) {
                throw new Exception("Account is required for all lines");
            }

            // Verify account exists
            $account_query = "
                SELECT id 
                FROM chart_of_accounts 
                WHERE id = :account_id 
                AND status = 1
            ";
            $account_stmt = $conn->prepare($account_query);
            $account_stmt->bindParam(':account_id', $detail['account_id'], PDO::PARAM_INT);
            $account_stmt->execute();
            
            if (!$account_stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Account not found: " . $detail['account_id']);
            }

            $debit = floatval($detail['debit_amount']);
            $credit = floatval($detail['credit_amount']);

            // Skip if both debit and credit are zero
            if ($debit == 0 && $credit == 0) {
                continue;
            }

            // Validate that only one side (debit or credit) has value
            if ($debit > 0 && $credit > 0) {
                throw new Exception("An account cannot have both debit and credit amounts");
            }

            // Get detail cost center if provided
            $detail_cost_center_id = !empty($detail['cost_center_id']) ? $detail['cost_center_id'] : null;

            $detail_stmt->bindParam(':line_number', $line_number, PDO::PARAM_INT);
            $detail_stmt->bindParam(':account_id', $detail['account_id'], PDO::PARAM_INT);
            $detail_stmt->bindParam(':description', $detail['description'], PDO::PARAM_STR);
            $detail_stmt->bindParam(':debit_amount', $debit, PDO::PARAM_STR);
            $detail_stmt->bindParam(':credit_amount', $credit, PDO::PARAM_STR);
            $detail_stmt->bindParam(':detail_cost_center_id', $detail_cost_center_id, PDO::PARAM_INT);

            if (!$detail_stmt->execute()) {
                throw new Exception("Error saving entry details for line " . $line_number);
            }

            $line_number++;
        }

        // Log the activity
        $log_query = "INSERT INTO activity_log (module, action, record_id, description, created_by, created_at) 
                      VALUES ('journal_entries', 'create', :record_id, :description, :user_id, NOW())";
        $log_stmt = $conn->prepare($log_query);
        $description = "Created journal entry: {$entry_number}";
        $log_stmt->bindParam(':record_id', $entry_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $log_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $log_stmt->execute();

        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Journal entry created successfully',
            'data' => [
                'id' => $entry_id,
                'entry_number' => $entry_number,
                'entry_date' => $_POST['entry_date'],
                'total_amount' => $total_debit,
                'status' => 'draft'
            ]
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in save_entry.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
