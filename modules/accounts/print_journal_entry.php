<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user doesn't have permission
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['accounts_view'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "Journal entry ID is required.";
    exit();
}

$id = $_GET['id'];

try {
    // Get journal entry with proper joins
    $query = "SELECT * FROM journal_entries WHERE id = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$entry) {
        echo "Journal entry not found.";
        exit();
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
    
    // Get journal entry details
    $details_query = "
        SELECT 
            jed.*,
            coa.account_code,
            coa.account_name
        FROM journal_entry_details jed
        LEFT JOIN chart_of_accounts coa ON jed.account_id = coa.id AND coa.status = 1
        WHERE jed.journal_entry_id = :journal_entry_id
        ORDER BY jed.id ASC";
    
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->bindParam(':journal_entry_id', $id, PDO::PARAM_INT);
    $details_stmt->execute();
    
    $details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $total_debit = 0;
    $total_credit = 0;
    
    foreach ($details as $detail) {
        $total_debit += floatval($detail['debit_amount']);
        $total_credit += floatval($detail['credit_amount']);
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
    exit();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit();
}

// Include company helpers
require_once '../../includes/company_helpers.php';

// Get company information
try {
    // Use helper function instead of direct query
    $company = getCompanyInfo($conn);
    
    if (!$company) {
        // Fallback if company info not found
        $company = [
            'company_name' => 'Solar Business',
            'address' => '',
            'phone' => '',
            'email' => '',
            'website' => ''
        ];
    }
} catch (PDOException $e) {
    error_log("Error getting company info: " . $e->getMessage());
    // Fallback if there's an error
    $company = [
        'company_name' => 'Solar Business',
        'address' => '',
        'phone' => '',
        'email' => '',
        'website' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entry #<?php echo htmlspecialchars($entry['entry_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            padding: 0;
            font-size: 14px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .company-details {
            margin-bottom: 5px;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            margin: 15px 0;
            text-align: center;
        }
        .info-section {
            margin-bottom: 15px;
        }
        .info-row {
            display: flex;
            margin-bottom: 5px;
        }
        .info-label {
            font-weight: bold;
            width: 150px;
        }
        .info-value {
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .text-end {
            text-align: right;
        }
        .total-row {
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        .signature-section {
            width: 30%;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                margin: 0;
                padding: 15px;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print();" class="btn btn-primary">Print</button>
        <button onclick="window.close();" class="btn btn-secondary">Close</button>
    </div>
    
    <div class="header">
        <?php if (!empty($company['logo_path'])): ?>
        <div style="text-align: center; margin-bottom: 10px;">
            <img src="<?php echo '../../' . $company['logo_path']; ?>" alt="Company Logo" style="max-height: 80px; max-width: 200px;">
        </div>
        <?php endif; ?>
        <div class="company-name"><?php echo htmlspecialchars($company['company_name'] ?? 'Solar Business'); ?></div>
        <?php if (!empty($company['address'])): ?>
        <div class="company-details"><?php echo htmlspecialchars($company['address']); ?></div>
        <?php endif; ?>
        <?php if (!empty($company['phone']) || !empty($company['email'])): ?>
        <div class="company-details">
            <?php if (!empty($company['phone'])): ?>
            Phone: <?php echo htmlspecialchars($company['phone']); ?>
            <?php endif; ?>
            <?php if (!empty($company['phone']) && !empty($company['email'])): ?> | <?php endif; ?>
            <?php if (!empty($company['email'])): ?>
            Email: <?php echo htmlspecialchars($company['email']); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($company['website'])): ?>
        <div class="company-details">Website: <?php echo htmlspecialchars($company['website']); ?></div>
        <?php endif; ?>
        <?php if (!empty($company['tax_number'])): ?>
        <div class="company-details">Tax Number: <?php echo htmlspecialchars($company['tax_number']); ?></div>
        <?php endif; ?>
    </div>
    
    <div class="document-title">JOURNAL ENTRY #<?php echo htmlspecialchars($entry['entry_number']); ?></div>
    
    <div class="info-section">
        <div class="info-row">
            <div class="info-label">Entry Number:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['entry_number']); ?></div>
            <div class="info-label">Entry Date:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['entry_date']); ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Status:</div>
            <div class="info-value"><?php echo ucfirst(htmlspecialchars($entry['status'] ?? 'draft')); ?></div>
            <div class="info-label">Entry Type:</div>
            <div class="info-value"><?php echo ucfirst(htmlspecialchars($entry['entry_type'])); ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Reference Number:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['reference_number'] ?? 'N/A'); ?></div>
            <div class="info-label">Cost Center:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['cost_center_name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Description:</div>
            <div class="info-value" colspan="3"><?php echo htmlspecialchars($entry['description']); ?></div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>Description</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Credit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($details as $detail): ?>
            <tr>
                <td><?php echo htmlspecialchars(($detail['account_code'] ?? '') . ' - ' . ($detail['account_name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($detail['description'] ?? ''); ?></td>
                <td class="text-end"><?php echo formatNumber($conn, $detail['debit_amount']); ?></td>
                <td class="text-end"><?php echo formatNumber($conn, $detail['credit_amount']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2" class="text-end">Total</td>
                <td class="text-end"><?php echo formatNumber($conn, $total_debit); ?></td>
                <td class="text-end"><?php echo formatNumber($conn, $total_credit); ?></td>
            </tr>
        </tfoot>
    </table>
    
    <div class="info-section">
        <div class="info-row">
            <div class="info-label">Created By:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['created_by_name'] ?? 'N/A'); ?></div>
            <div class="info-label">Created At:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['created_at'] ?? 'N/A'); ?></div>
        </div>
        
        <div class="info-row">
            <div class="info-label">Posted By:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['posted_by_name'] ?? 'N/A'); ?></div>
            <div class="info-label">Posted At:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['posted_at'] ?? 'N/A'); ?></div>
        </div>
        
        <?php if ($entry['status'] === 'approved' || $entry['status'] === 'rejected'): ?>
        <div class="info-row">
            <div class="info-label">Approved/Rejected By:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['approved_by_name'] ?? 'N/A'); ?></div>
            <div class="info-label">Approved/Rejected At:</div>
            <div class="info-value"><?php echo htmlspecialchars($entry['approved_at'] ?? 'N/A'); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        <div class="signature-section">
            <div class="signature-line">Prepared By</div>
        </div>
        <div class="signature-section">
            <div class="signature-line">Checked By</div>
        </div>
        <div class="signature-section">
            <div class="signature-line">Approved By</div>
        </div>
    </div>
    
    <script>
        // Auto print when page loads
        window.onload = function() {
            // Add a small delay to ensure everything is loaded
            setTimeout(function() {
                // Uncomment the line below to auto-print
                // window.print();
            }, 500);
        };
    </script>
</body>
</html>
