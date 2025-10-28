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
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

require_once '../../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    try {
        // Get journal entry header
        $header_query = "
            SELECT 
                je.*,
                c.username as created_by_name,
                u.username as updated_by_name,
                a.username as approved_by_name,
                cc.code as cost_center_code,
                cc.name as cost_center_name
            FROM journal_entries je
            LEFT JOIN users c ON je.created_by = c.id
            LEFT JOIN users u ON je.updated_by = u.id
            LEFT JOIN users a ON je.approved_by = a.id
            LEFT JOIN cost_centers cc ON je.cost_center_id = cc.id
            WHERE je.id = :id AND je.status != 'deleted'";

        $stmt = $conn->prepare($header_query);
        $stmt->bindParam(':id', $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            echo json_encode(['success' => false, 'message' => 'Journal entry not found']);
            exit();
        }

        // Get journal entry details
        $details_query = "
            SELECT 
                jed.*,
                coa.account_code,
                coa.account_name,
                cc.code as cost_center_code,
                cc.name as cost_center_name
            FROM journal_entry_details jed
            JOIN chart_of_accounts coa ON jed.account_id = coa.id
            LEFT JOIN cost_centers cc ON jed.cost_center_id = cc.id
            WHERE jed.journal_entry_id = :entry_id
            AND coa.status = 1
            ORDER BY jed.id";

        $stmt = $conn->prepare($details_query);
        $stmt->bindParam(':entry_id', $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get attachments if any
        $attachments_query = "
            SELECT 
                id,
                file_name,
                file_path,
                file_type,
                file_size,
                created_at,
                created_by
            FROM journal_entry_attachments
            WHERE journal_entry_id = :entry_id
            AND status = 1
            ORDER BY created_at DESC";

        $stmt = $conn->prepare($attachments_query);
        $stmt->bindParam(':entry_id', $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        $debit = 0;
        $credit = 0;
        foreach ($details as $detail) {
            $debit += floatval($detail['debit_amount'] ?? 0);
            $credit += floatval($detail['credit_amount'] ?? 0);
        }

        // Log the activity
        $log_query = "INSERT INTO activity_log (module, action, record_id, description, created_by, created_at) 
                      VALUES ('journal_entries', 'view', :record_id, :description, :user_id, NOW())";
        $stmt = $conn->prepare($log_query);
        $description = "Viewed journal entry: {$entry['entry_number']}";
        $stmt->bindParam(':record_id', $_GET['id'], PDO::PARAM_INT);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();

        $response = [
            'success' => true,
            'data' => [
                'id' => $entry['id'],
                'entry_number' => $entry['entry_number'],
                'entry_date' => $entry['entry_date'],
                'entry_type' => $entry['entry_type'],
                'reference_number' => $entry['reference_number'],
                'description' => $entry['description'],
                'status' => $entry['status'],
                'created_by_name' => $entry['created_by_name'],
                'created_at' => $entry['created_at'],
                'updated_by_name' => $entry['updated_by_name'],
                'updated_at' => $entry['updated_at'],
                'approved_by_name' => $entry['approved_by_name'],
                'approved_at' => $entry['approved_at'],
                'cost_center_code' => $entry['cost_center_code'],
                'cost_center_name' => $entry['cost_center_name'],
                'details' => $details,
                'attachments' => $attachments,
                'total_debit' => $debit,
                'total_credit' => $credit
            ]
        ];

        echo json_encode($response);

    } catch (PDOException $e) {
        error_log("Database error in get_entry.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again later.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing ID']);
}
