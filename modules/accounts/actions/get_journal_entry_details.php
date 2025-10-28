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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    try {
        error_log("Starting get_journal_entry_details.php with request: " . json_encode($_POST));
        
        if (!isset($_POST['id']) || empty($_POST['id'])) {
            throw new Exception("Missing journal entry ID");
        }
        
        $id = $_POST['id'];
        error_log("Processing journal entry ID: " . $id);
        
        // First verify the tables exist
        $tables_query = "SHOW TABLES LIKE 'journal_entries'";
        $tables_stmt = $conn->prepare($tables_query);
        $tables_stmt->execute();
        $table_exists = ($tables_stmt->rowCount() > 0);
        
        if (!$table_exists) {
            throw new Exception("Journal entries table not found");
        }
        
        error_log("Journal entries table exists");
        
        // Get journal entry with simplified query to avoid JOIN issues
        $query = "SELECT * FROM journal_entries WHERE id = :id";
        
        // Debug query
        error_log("Query: " . $query);
        error_log("ID: " . $id);
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("Entry found: " . ($entry ? 'Yes' : 'No'));
        if ($entry) {
            error_log("Entry data: " . json_encode($entry));
        }
        
        if (!$entry) {
            throw new Exception("Journal entry not found");
        }
        
        // Get cost center name if cost_center_id exists
        if (!empty($entry['cost_center_id'])) {
            $cc_query = "SELECT name FROM cost_centers WHERE id = :id";
            $cc_stmt = $conn->prepare($cc_query);
            $cc_stmt->bindParam(':id', $entry['cost_center_id'], PDO::PARAM_INT);
            $cc_stmt->execute();
            $cc = $cc_stmt->fetch(PDO::FETCH_ASSOC);
            if ($cc) {
                $entry['cost_center_name'] = $cc['name'];
            }
        }
        
        // Get created_by username
        if (!empty($entry['created_by'])) {
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $conn->prepare($user_query);
            $user_stmt->bindParam(':id', $entry['created_by'], PDO::PARAM_INT);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $entry['created_by_name'] = $user['username'];
            }
        }
        
        // Get updated_by username
        if (!empty($entry['updated_by'])) {
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $conn->prepare($user_query);
            $user_stmt->bindParam(':id', $entry['updated_by'], PDO::PARAM_INT);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $entry['updated_by_name'] = $user['username'];
            }
        }
        
        // Get posted_by username
        if (!empty($entry['posted_by'])) {
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $conn->prepare($user_query);
            $user_stmt->bindParam(':id', $entry['posted_by'], PDO::PARAM_INT);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $entry['posted_by_name'] = $user['username'];
            }
        }
        
        // Get approved_by username
        if (!empty($entry['approved_by'])) {
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $conn->prepare($user_query);
            $user_stmt->bindParam(':id', $entry['approved_by'], PDO::PARAM_INT);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $entry['approved_by_name'] = $user['username'];
            }
        }
        
        // Get journal entry details with the correct chart_of_accounts table
        $details_query = "
            SELECT 
                jed.*,
                coa.account_code,
                coa.account_name
            FROM journal_entry_details jed
            LEFT JOIN chart_of_accounts coa ON jed.account_id = coa.id AND coa.status = 1
            WHERE jed.journal_entry_id = :journal_entry_id
            ORDER BY jed.id ASC";
        
        error_log("Details query: " . $details_query);
        
        $details_stmt = $conn->prepare($details_query);
        $details_stmt->bindParam(':journal_entry_id', $id, PDO::PARAM_INT);
        $details_stmt->execute();
        
        $details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Details count: " . count($details));
        
        echo json_encode([
            'success' => true,
            'entry' => $entry,
            'details' => $details
        ]);
        
    } catch (PDOException $e) {
        error_log("PDOException in get_journal_entry_details.php: " . $e->getMessage());
        error_log("PDOException Code: " . $e->getCode());
        error_log("PDOException Trace: " . $e->getTraceAsString());
        
        // Following PDO standards from memory - log errors but return user-friendly messages
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred. Please try again later.',
            'debug' => [
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage()
            ]
        ]);
    } catch (Exception $e) {
        error_log("Exception in get_journal_entry_details.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
