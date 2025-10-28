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
        if (empty($_POST['year_code']) || empty($_POST['start_date']) || empty($_POST['end_date'])) {
            throw new Exception("Please fill all required fields");
        }

        // Parse values
        $year_code = $_POST['year_code'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        // Always set status to active (1) as the inactive option has been removed
        $status = 1;

        // Validate dates
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);

        if ($start_timestamp === false || $end_timestamp === false) {
            throw new Exception("Invalid date format");
        }

        if ($start_timestamp >= $end_timestamp) {
            throw new Exception("End date must be after start date");
        }

        // Check if year code already exists
        $check_query = "SELECT id FROM financial_years WHERE year_code = :year_code";
        $stmt = $conn->prepare($check_query);
        $stmt->bindParam(':year_code', $year_code, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            throw new Exception("Financial year code already exists");
        }

        // Check for overlapping dates with existing financial years
        $overlap_query = "
            SELECT id FROM financial_years 
            WHERE (:start_date BETWEEN start_date AND end_date) 
               OR (:end_date BETWEEN start_date AND end_date) 
               OR (start_date BETWEEN :start_date2 AND :end_date2) 
               OR (end_date BETWEEN :start_date3 AND :end_date3)
        ";
        $stmt = $conn->prepare($overlap_query);
        $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
        $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
        $stmt->bindParam(':start_date2', $start_date, PDO::PARAM_STR);
        $stmt->bindParam(':end_date2', $end_date, PDO::PARAM_STR);
        $stmt->bindParam(':start_date3', $start_date, PDO::PARAM_STR);
        $stmt->bindParam(':end_date3', $end_date, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            throw new Exception("The date range overlaps with an existing financial year");
        }

        // Insert financial year
        $insert_query = "
            INSERT INTO financial_years (
                year_code, start_date, end_date, is_closed, status, created_by, updated_by
            ) VALUES (:year_code, :start_date, :end_date, 0, :status, :created_by, :updated_by)
        ";

        $stmt = $conn->prepare($insert_query);
        $stmt->bindParam(':year_code', $year_code, PDO::PARAM_STR);
        $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
        $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);

        if (!$stmt->execute()) {
            throw new Exception("Error creating financial year");
        }

        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Financial year created successfully']);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Database error in save_financial_year.php: " . $e->getMessage());
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
