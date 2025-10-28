<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Permission check
if ($_SESSION['role_id'] != 1 && !isset($_SESSION['permissions']['sales_view'])) {
    header("Location: ../../index.php");
    exit();
}

// Include database connection
require_once '../../config/database.php';
require_once '../../includes/company_helpers.php';
$db = new Database();
$conn = $db->getConnection();

// Get company information for currency
$company = getCompanyInfo($conn, 'currency');
$currency_symbol = $company['currency'] ?? '₹'; // Default to rupee if not set

// Get customer ID from URL
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01'); // First day of current month
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d'); // Today

if (!$customer_id) {
    $_SESSION['error'] = "Customer ID is required.";
    header("Location: customers.php");
    exit();
}

// Get customer details with account ID
$customer_query = "SELECT s.*, s.account_id as customer_account_id FROM customers s WHERE s.id = :customer_id";
$customer_stmt = $conn->prepare($customer_query);
$customer_stmt->bindParam(':customer_id', $customer_id);
$customer_stmt->execute();
$customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    $_SESSION['error'] = "Customer not found.";
    header('Location: customers.php');
    exit;
}

// Get the customer's account ID for journal entry filtering
$customer_account_id = $customer['customer_account_id'];

// If account_id is not set, display an error
if (!$customer_account_id) {
    $_SESSION['error'] = "Customer does not have an associated account. Please set up the customer account first.";
    header('Location: customers.php');
    exit;
}

// Get opening balance from journal entries
$opening_query = "SELECT 
    SUM(CASE WHEN jed.debit_amount > 0 THEN jed.debit_amount ELSE 0 END) as opening_debit,
    SUM(CASE WHEN jed.credit_amount > 0 THEN jed.credit_amount ELSE 0 END) as opening_credit
FROM journal_entries je
JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
WHERE jed.account_id = :customer_account_id
AND je.entry_date < :from_date
AND (je.status = 'posted' OR je.status = 'approved')
AND je.reversal_of IS NULL";

$opening_stmt = $conn->prepare($opening_query);
$opening_stmt->bindParam(':customer_account_id', $customer_account_id);
$opening_stmt->bindParam(':from_date', $from_date);
$opening_stmt->execute();
$opening = $opening_stmt->fetch(PDO::FETCH_ASSOC);

// For customers, typically credit balance is positive (we owe them)
// and debit balance is negative (they owe us)
$opening_debit = $opening['opening_debit'] ?? 0;
$opening_credit = $opening['opening_credit'] ?? 0;
$opening_balance = $opening_credit - $opening_debit;

// Check if journal_entries table exists
$check_je_query = "SHOW TABLES LIKE 'journal_entries'";
$check_je_stmt = $conn->prepare($check_je_query);
$check_je_stmt->execute();
$journal_entries_exists = $check_je_stmt->rowCount() > 0;

// Get all transactions from journal entries for this customer
$transactions_query = "";
if ($journal_entries_exists) {
    $transactions_query = "SELECT 
        je.id as journal_entry_id,
        je.entry_type as type,
        je.entry_number as transaction_id,
        je.reference_number as reference,
        je.entry_date as transaction_date,
        je.description,
        jed.debit_amount as debit,
        jed.credit_amount as credit,
        0.00 as balance_amount,
        COALESCE(u.username, '') as created_by_name,
        -- Additional fields to help with transaction identification
        je.entry_type,
        je.notes
    FROM journal_entries je
    JOIN journal_entry_details jed ON je.id = jed.journal_entry_id
    LEFT JOIN users u ON je.created_by = u.id
    WHERE jed.account_id = :customer_account_id
    AND je.entry_date BETWEEN :from_date AND :to_date
    AND (je.status = 'posted' OR je.status = 'approved')
    ORDER BY je.entry_date ASC, je.id ASC";
}

// Get original transaction details using separate queries to avoid parameter binding issues

// Array to store all original transactions
$original_transactions = [];

// 1. Get sales invoices
$invoices_query = "SELECT 
    'sales' as source_type,
    id as source_id,
    invoice_number as source_reference,
    invoice_date as source_date,
    total_amount as source_amount,
    notes as source_notes,
    status as source_status
FROM sales_invoices 
WHERE customer_id = ? 
AND invoice_date BETWEEN ? AND ?
AND status != 'cancelled' AND status != 'draft'";

$invoices_stmt = $conn->prepare($invoices_query);
$invoices_stmt->execute([$customer_id, $from_date, $to_date]);
$original_transactions = array_merge($original_transactions, $invoices_stmt->fetchAll(PDO::FETCH_ASSOC));

// 2. Get sales payments
$payments_query = "SELECT 
    'payment' as source_type,
    pp.id as source_id,
    COALESCE(pp.reference_number, CONCAT('PAY-', pp.id)) as source_reference,
    pp.payment_date as source_date,
    pp.amount as source_amount,
    pp.notes as source_notes,
    pp.status as source_status
FROM sales_payments pp
JOIN sales_invoices pi ON pp.invoice_id = pi.id
WHERE pi.customer_id = ? 
AND pp.payment_date BETWEEN ? AND ?
AND pp.status != 'cancelled' AND pp.status != 'draft'";

$payments_stmt = $conn->prepare($payments_query);
$payments_stmt->execute([$customer_id, $from_date, $to_date]);
$original_transactions = array_merge($original_transactions, $payments_stmt->fetchAll(PDO::FETCH_ASSOC));

// 3. Get customer refunds
$refunds_query = "SELECT 
    'refund' as source_type,
    id as source_id,
    reference_number as source_reference,
    refund_date as source_date,
    amount as source_amount,
    notes as source_notes,
    status as source_status
FROM customer_refunds 
WHERE customer_id = ? 
AND refund_date BETWEEN ? AND ?
AND status != 'cancelled' AND status != 'draft'";

$refunds_stmt = $conn->prepare($refunds_query);
$refunds_stmt->execute([$customer_id, $from_date, $to_date]);
$original_transactions = array_merge($original_transactions, $refunds_stmt->fetchAll(PDO::FETCH_ASSOC));

// 4. Get sales returns
$returns_query = "SELECT 
    'return' as source_type,
    id as source_id,
    return_number as source_reference,
    return_date as source_date,
    total_amount as source_amount,
    notes as source_notes,
    status as source_status
FROM sales_returns 
WHERE customer_id = ? 
AND return_date BETWEEN ? AND ?
AND status != 'cancelled' AND status != 'draft'";

$returns_stmt = $conn->prepare($returns_query);
$returns_stmt->execute([$customer_id, $from_date, $to_date]);
$original_transactions = array_merge($original_transactions, $returns_stmt->fetchAll(PDO::FETCH_ASSOC));

// 5. Check if credit_notes table exists and get credit notes if it does
$check_table_query = "SHOW TABLES LIKE 'credit_notes'";
$check_table_stmt = $conn->prepare($check_table_query);
$check_table_stmt->execute();

if ($check_table_stmt->rowCount() > 0) {
    $credit_notes_query = "SELECT 
        'credit_note' as source_type,
        id as source_id,
        note_number as source_reference,
        created_at as source_date,
        amount as source_amount,
        NULL as source_notes,
        status as source_status
    FROM credit_notes 
    WHERE customer_id = ? 
    AND created_at BETWEEN ? AND ?
    AND status != 'cancelled' AND status != 'draft'";
    
    $credit_notes_stmt = $conn->prepare($credit_notes_query);
    $credit_notes_stmt->execute([$customer_id, $from_date, $to_date]);
    $original_transactions = array_merge($original_transactions, $credit_notes_stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Execute journal entries query if the table exists
$journal_entries = [];
if ($journal_entries_exists && !empty($transactions_query)) {
    $transactions_stmt = $conn->prepare($transactions_query);
    $transactions_stmt->bindParam(':customer_account_id', $customer_account_id);
    $transactions_stmt->bindParam(':from_date', $from_date);
    $transactions_stmt->bindParam(':to_date', $to_date);
    $transactions_stmt->execute();
    $journal_entries = $transactions_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// If no journal entries, create transactions directly from original transactions
if (empty($journal_entries) && !empty($original_transactions)) {
    foreach ($original_transactions as $transaction) {
        $debit = 0;
        $credit = 0;
        
        // Set debit/credit based on transaction type
        switch ($transaction['source_type']) {
            case 'sales': // Invoice - credit to customer
                $credit = $transaction['source_amount'];
                break;
            case 'payment': // Payment - debit to customer
                $debit = $transaction['source_amount'];
                break;
            case 'refund': // Refund - credit to customer
                $credit = $transaction['source_amount'];
                break;
            case 'return': // Return - debit to customer
                $debit = $transaction['source_amount'];
                break;
            case 'credit_note': // Credit note - debit to customer
                $debit = $transaction['source_amount'];
                break;
        }
        
        // Create a journal entry-like structure
        $journal_entries[] = [
            'journal_entry_id' => $transaction['source_id'],
            'type' => $transaction['source_type'],
            'transaction_id' => $transaction['source_id'],
            'reference' => $transaction['source_reference'],
            'transaction_date' => $transaction['source_date'],
            'description' => ucfirst($transaction['source_type']) . ' ' . $transaction['source_reference'],
            'debit' => $debit,
            'credit' => $credit,
            'balance_amount' => 0.00,
            'created_by_name' => '',
            'entry_type' => $transaction['source_type'],
            'notes' => $transaction['source_notes'],
            'original_status' => $transaction['source_status']
        ];
    }
    
    // Sort transactions by date
    usort($journal_entries, function($a, $b) {
        return strtotime($a['transaction_date']) - strtotime($b['transaction_date']);
    });
}

// Note: Original transactions are now fetched using separate queries above

// Debug: Check if we're getting any journal entries or original transactions
$debug_info = [
    'customer_id' => $customer_id,
    'customer_account_id' => $customer_account_id,
    'from_date' => $from_date,
    'to_date' => $to_date,
    'journal_entries_count' => count($journal_entries),
    'original_transactions_count' => count($original_transactions),
    'opening_balance' => $opening_balance,
    'opening_debit' => $opening['opening_debit'],
    'opening_credit' => $opening['opening_credit'],
    'journal_entries_sample' => !empty($journal_entries) ? array_slice($journal_entries, 0, 1) : [],
    'original_transactions_sample' => !empty($original_transactions) ? array_slice($original_transactions, 0, 1) : [],
    'journal_entries_query' => $transactions_query
];

// Uncomment for debugging
// echo '<pre>Debug Info: ' . print_r($debug_info, true) . '</pre>';

// Create a lookup array for original transactions
$transaction_lookup = [];
foreach ($original_transactions as $transaction) {
    $key = $transaction['source_type'] . '-' . $transaction['source_reference'];
    $transaction_lookup[$key] = $transaction;
}

// Merge journal entries with original transaction details
$transactions = [];
foreach ($journal_entries as $entry) {
    // Try to match with original transaction
    $transaction_type = $entry['entry_type'];
    $reference = $entry['reference'];
    
    // Map journal entry types to source types
    $source_type = '';
    switch ($transaction_type) {
        case 'sales':
            $source_type = 'sales';
            break;
        case 'payment':
            $source_type = 'payment';
            break;
        case 'adjustment':
            // Could be return or credit note
            if (strpos($entry['description'], 'Return') !== false) {
                $source_type = 'return';
            } else {
                $source_type = 'credit_note';
            }
            break;
    }
    
    // Look for matching original transaction
    $lookup_key = $source_type . '-' . $reference;
    $original_data = isset($transaction_lookup[$lookup_key]) ? $transaction_lookup[$lookup_key] : null;
    
    // Create transaction record with enriched data
    $transaction = [
        'type' => $transaction_type,
        'transaction_id' => $entry['transaction_id'],
        'reference' => $entry['reference'],
        'transaction_date' => $entry['transaction_date'],
        'debit' => $entry['debit'],
        'credit' => $entry['credit'],
        'balance_amount' => 0.00,
        'description' => $entry['description'],
        'created_by_name' => $entry['created_by_name'],
        'journal_entry_id' => $entry['journal_entry_id'],
        'original_status' => $original_data ? $original_data['source_status'] : null
    ];
    
    $transactions[] = $transaction;
}

// Sort transactions by date
usort($transactions, function($a, $b) {
    $date_compare = strtotime($a['transaction_date']) - strtotime($b['transaction_date']); // Sort by date in ascending order
    if ($date_compare == 0) {
        return $a['journal_entry_id'] - $b['journal_entry_id']; // Sort by journal entry ID in ascending order
    }
    return $date_compare;
});

// Calculate running balance
$running_balance = $opening_balance;
foreach ($transactions as &$transaction) {
    // For customers: credit increases balance (we owe them), debit decreases balance (they owe us)
    // This is the standard accounting convention for liability accounts
    $running_balance = $running_balance + $transaction['credit'] - $transaction['debit'];
    $transaction['running_balance'] = $running_balance;
}
unset($transaction); // Break the reference

// Include helper functions
require_once '../../includes/company_helpers.php';

// Set page title
$page_title = "Customer Statement - " . $customer['customer_name'];

// Calculate totals
$total_debit = !empty($transactions) ? array_sum(array_column($transactions, 'debit')) : 0;
$total_credit = !empty($transactions) ? array_sum(array_column($transactions, 'credit')) : 0;

// Calculate closing balance
$closing_balance = empty($transactions) ? $opening_balance : end($transactions)['running_balance'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
    <style>
        .balance-card {
            border-left: 4px solid #007bff;
        }
        
        /* Enhanced balance cards styling */
        .balance-card .card-body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 120px;
        }
        
        .card.h-100 {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease-in-out;
            border: 1px solid #dee2e6;
        }
        
        .card.h-100:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .card.h-100 .card-body {
            min-height: 120px;
            padding: 1.5rem 1rem;
        }
        
        .card-title i {
            margin-right: 0.5rem;
        }
        
        .debit-amount {
            color: #dc3545 !important;
            font-weight: 700;
        }
        
        .credit-amount {
            color: #28a745 !important;
            font-weight: 700;
        }
        
        .card-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .balance-amount h4 {
            font-size: 1.5rem;
            margin-bottom: 0;
        }
        
        .balance-amount small {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        /* Responsive balance cards */
        @media (max-width: 1199px) {
            .card.h-100 .card-body {
                min-height: 110px;
                padding: 1.25rem 0.75rem;
            }
        }
        
        @media (max-width: 991px) {
            .card.h-100 .card-body {
                min-height: 100px;
                padding: 1rem 0.75rem;
            }
            .card-title {
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 767px) {
            .row.g-3 {
                margin-bottom: 1.5rem !important;
            }
            .card.h-100 .card-body {
                min-height: 90px;
                padding: 1rem;
            }
            .balance-amount h4, .debit-amount, .credit-amount {
                font-size: 1.25rem;
            }
        }
        
        @media (max-width: 575px) {
            .col-sm-6 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }
        
        /* Table styling to match system standard */
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
            padding: 0.75rem;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .table-responsive {
            border-radius: 0.375rem;
            overflow: hidden;
        }
        
        /* Table footer styling */
        .table tfoot th {
            background-color: #e9ecef;
            border-top: 2px solid #dee2e6;
            font-weight: 700;
            color: #495057;
            font-size: 0.9rem;
            padding: 1rem 0.75rem;
        }
        
        .table tfoot .table-info {
            background-color: #d1ecf1 !important;
        }
        
        .table tfoot .debit-amount {
            color: #dc3545 !important;
            font-weight: 700 !important;
        }
        
        .table tfoot .credit-amount {
            color: #28a745 !important;
            font-weight: 700 !important;
        }
        
        .table tfoot .fw-bold {
            font-weight: 700 !important;
            font-size: 1rem;
        }
        
        .table tfoot small {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        .debit-amount {
            color: #dc3545;
        }
        .credit-amount {
            color: #28a745;
        }
        .balance-amount {
            font-weight: bold;
        }
        .d-flex.gap-2 {
            gap: 0.5rem !important;
        }
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        .justify-content-end {
            justify-content: flex-end !important;
        }
        @media (max-width: 768px) {
            .d-flex.flex-wrap.justify-content-end {
                justify-content: flex-end !important;
            }
            .btn-sm {
                margin-bottom: 0.25rem;
            }
        }
        /* Button alignment styles */
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: flex-end;
            align-items: center;
        }
        
        @media (max-width: 991px) {
            .filter-buttons {
                justify-content: flex-end !important;
                margin-top: 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .filter-buttons {
                justify-content: center !important;
                margin-top: 1rem;
            }
            .col-sm-12 .filter-buttons {
                width: 100%;
                justify-content: center;
            }
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar, .navbar {
                display: none !important;
            }
            body {
                font-size: 12px;
            }
            .card {
                border: none;
                box-shadow: none;
            }
            .table thead th {
                background-color: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                border: 1px solid #000 !important;
            }
            .table td {
                border: 1px solid #000 !important;
            }
            .table {
                page-break-inside: avoid;
            }
            .table tfoot th {
                background-color: #e9ecef !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                border: 1px solid #000 !important;
                font-weight: 700 !important;
            }
            .table tfoot .debit-amount {
                color: #dc3545 !important;
            }
            .table tfoot .credit-amount {
                color: #28a745 !important;
            }
            .card-header {
                background: #f8f9fa !important;
                color: #333 !important;
                border: 1px solid #dee2e6 !important;
            }
            .table {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div id="content">
        <?php include '../../includes/navbar.php'; ?>
        
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-6">
                    <h2><i class="fas fa-file-invoice-dollar"></i> Customer Statement</h2>
                </div>
                <div class="col-md-6 text-end">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-end">
                            <li class="breadcrumb-item"><a href="../../index.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="customers.php">Customers</a></li>
                            <li class="breadcrumb-item active">Statement</li>
                        </ol>
                    </nav>
                </div>
            </div>
        <!-- Customer Info Card -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <div class="row">
                    <div class="col-md-8">
                        <h4 class="mb-0"><i class="fas fa-building"></i> <?php echo htmlspecialchars($customer['customer_name']); ?></h4>
                        <small>Code: <?php echo htmlspecialchars($customer['customer_code']); ?></small>
                    </div>
                    <div class="col-md-4 text-end">
                        <small><strong>Statement Period</strong><br>
                        <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?></small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong><i class="fas fa-user"></i> Contact Person:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($customer['contact_person'] ?: 'Not specified'); ?></span>
                    </div>
                    <div class="col-md-4">
                        <strong><i class="fas fa-phone"></i> Phone:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($customer['phone'] ?: 'Not specified'); ?></span>
                    </div>
                    <div class="col-md-4">
                        <strong><i class="fas fa-envelope"></i> Email:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($customer['email'] ?: 'Not specified'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-filter"></i> Filter Options</h5>
            </div>
            <div class="card-body">
                <form method="GET">
                    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-4 col-sm-6">
                            <label for="from_date" class="form-label"><strong>From Date</strong></label>
                            <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo $from_date; ?>" required>
                        </div>
                        <div class="col-lg-3 col-md-4 col-sm-6">
                            <label for="to_date" class="form-label"><strong>To Date</strong></label>
                            <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo $to_date; ?>" required>
                        </div>
                        <div class="col-lg-6 col-md-4 col-sm-12">
                            <div class="filter-buttons">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <button type="button" class="btn btn-success btn-sm" onclick="prepareAndPrint()">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button type="button" class="btn btn-info btn-sm" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel"></i> Excel
                                </button>
                                <a href="customers.php" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="row mb-4 g-3">
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card balance-card h-100">
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h6 class="card-title mb-2"><i class="fas fa-balance-scale"></i> Opening Balance</h6>
                        <h4 class="<?php echo $opening_balance >= 0 ? 'debit-amount' : 'credit-amount'; ?> mb-0">
                            <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($opening_balance)); ?>
                            <small class="d-block"><?php echo $opening_balance >= 0 ? 'Dr' : 'Cr'; ?></small>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card h-100">
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h6 class="card-title mb-2"><i class="fas fa-arrow-up text-danger"></i> Total Debit</h6>
                        <h4 class="debit-amount mb-0"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $total_debit); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card h-100">
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h6 class="card-title mb-2"><i class="fas fa-arrow-down text-success"></i> Total Credit</h6>
                        <h4 class="credit-amount mb-0"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $total_credit); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6">
                <div class="card balance-card h-100">
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h6 class="card-title mb-2"><i class="fas fa-calculator"></i> Closing Balance</h6>
                        <h4 class="<?php echo $closing_balance >= 0 ? 'debit-amount' : 'credit-amount'; ?> mb-0">
                            <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($closing_balance)); ?>
                            <small class="d-block"><?php echo $closing_balance >= 0 ? 'Dr' : 'Cr'; ?></small>
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Transaction Details</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($opening_balance != 0): ?>
                            <tr class="table-info">
                                <td><?php echo date('d-m-Y', strtotime($from_date . ' -1 day')); ?></td>
                                <td><span class="badge bg-info">Opening</span></td>
                                <td>-</td>
                                <td>Opening Balance</td>
                                <td class="text-end">
                                    <?php if ($opening_balance < 0): // If negative, it's a debit balance for customer ?>
                                        <span class="debit-amount"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($opening_balance)); ?></span>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($opening_balance > 0): // If positive, it's a credit balance for customer ?>
                                        <span class="credit-amount"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $opening_balance); ?></span>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td class="text-end fw-bold">
                                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($opening_balance)); ?>
                                    <small><?php echo $opening_balance >= 0 ? 'Cr' : 'Dr'; ?></small>
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">No transactions found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($transaction['transaction_date'])); ?></td>
                                        <td>
                                            <?php 
                                            $badge_class = '';
                                            $type_label = '';
                                            switch ($transaction['type']) {
                                                case 'sales':
                                                    $badge_class = 'bg-primary';
                                                    $type_label = 'Invoice';
                                                    break;
                                                case 'payment':
                                                    $badge_class = 'bg-success';
                                                    $type_label = 'Payment';
                                                    break;
                                                case 'adjustment':
                                                    if (strpos($transaction['description'], 'Return') !== false) {
                                                        $badge_class = 'bg-warning';
                                                        $type_label = 'Return';
                                                    } else {
                                                        $badge_class = 'bg-info';
                                                        $type_label = 'Credit Note';
                                                    }
                                                    break;
                                                case 'contra':
                                                    $badge_class = 'bg-secondary';
                                                    $type_label = 'Contra';
                                                    break;
                                                default:
                                                    $badge_class = 'bg-secondary';
                                                    $type_label = ucfirst($transaction['type']);
                                            }
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $type_label; ?>
                                            </span>
                                            <?php if (!empty($transaction['original_status']) && $transaction['original_status'] != 'completed'): ?>
                                                <br>
                                                <small class="badge bg-<?php echo ($transaction['original_status'] == 'cancelled' ? 'danger' : 'secondary'); ?>">
                                                    <?php echo ucfirst($transaction['original_status']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction['transaction_id']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['reference']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($transaction['description']); ?>
                                            <?php if (!empty($transaction['journal_entry_id'])): ?>
                                                <br>
                                                <small class="text-muted">Journal Entry ID: <?php echo $transaction['journal_entry_id']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($transaction['debit'] > 0): ?>
                                                <span class="debit-amount"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $transaction['debit']); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($transaction['credit'] > 0): ?>
                                                <span class="credit-amount"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $transaction['credit']); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($transaction['running_balance'])); ?>
                                            <small><?php echo $transaction['running_balance'] >= 0 ? 'Cr' : 'Dr'; ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="4" class="text-end">Total:</th>
                                <th class="text-end"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'debit'))); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'credit'))); ?></th>
                                <th class="text-end">
                                    <?php 
                                    $final_balance = empty($transactions) ? $opening_balance : end($transactions)['running_balance'];
                                    echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($final_balance)); ?>
                                    <small><?php echo $final_balance >= 0 ? 'Cr' : 'Dr'; ?></small>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- Print Section (Hidden) -->
<div id="print-section">
    <style>
        /* Hide print section by default but show when printing */
        #print-section {
            display: none;
        }
        
        /* Add global print styles */
        @media print {
            /* Hide the regular page content */
            body > .wrapper {
                display: none;
            }
            
            /* Show the print section */
            #print-section {
                display: block !important;
            }
            
            /* Comfortable page margins */
            @page {
                margin: 1.5cm;
                size: A4 portrait;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 11px;
                line-height: 1.3;
                color: #000;
                background: #fff;
                margin: 0;
                padding: 0;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            .container {
                width: 100%;
                max-width: 100%;
                padding: 0;
                margin: 0 auto;
                box-sizing: border-box;
                overflow: hidden;
            }
            .print-header {
                border-bottom: 1px solid #ddd;
                padding-bottom: 5px;
                margin-bottom: 10px;
                width: 100%;
                display: table;
                clear: both;
            }
            .print-title {
                font-size: 16px;
                font-weight: bold;
                color: #333;
                margin-top: 0;
                background-color: #ffff00;
                padding: 5px;
            }
            .print-subtitle {
                font-size: 12px;
                color: #666;
                line-height: 1.3;
            }
            .print-box {
                border: 1px solid #ddd;
                padding: 5px;
                margin-bottom: 10px;
                border-radius: 3px;
                width: 95%;
                margin-left: auto;
                margin-right: auto;
            }
            .print-box-title {
                font-weight: bold;
                border-bottom: 1px solid #ddd;
                padding-bottom: 3px;
                background-color: #ffff00;
                padding: 3px;
            }
            .print-table {
                width: 95%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 9px;
                table-layout: fixed;
                max-width: 95%;
                overflow-wrap: break-word;
                word-wrap: break-word;
                margin-left: auto;
                margin-right: auto;
            }
            .print-table th, .print-table td {
                border: 1px solid #ddd;
                padding: 4px;
                font-size: 9px;
                text-align: left;
                overflow-wrap: break-word;
                word-wrap: break-word;
                white-space: normal;
            }
            
            /* Column width adjustments - removed Type and Reference columns */
            .print-table th:first-child {
                width: 15%;
            }
            .print-table th:nth-child(2) {
                width: 35%;
            }
            .print-table th:nth-child(3),
            .print-table th:nth-child(4) {
                width: 16%;
            }
            .print-table th:last-child {
                width: 18%;
            }
            .print-table th {
                background-color: #f5f5f5;
                font-weight: bold;
                text-align: left;
            }
            .print-table tfoot tr {
                background-color: #f5f5f5;
                font-weight: bold;
            }
            /* Prevent table from breaking across pages */
            .print-table thead {
                display: table-header-group;
            }
            .print-table tfoot {
                display: table-footer-group;
            }
            .text-right {
                text-align: right;
            }
            .company-logo {
                max-height: 60px;
                max-width: 180px;
                display: block;
                margin-bottom: 5px;
                object-fit: contain;
                object-position: left center;
            }
            
            /* Fix for logo not showing in print */
            img.company-logo {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                print-color-adjust: exact !important;
                max-height: 60px !important;
                max-width: 180px !important;
                height: auto !important;
                width: auto !important;
                display: inline-block !important;
            }
            .mb-4 {
                margin-bottom: 20px;
            }
            .col-12 {
                width: 100%;
            }
            .summary-box {
                display: flex;
                flex-wrap: wrap;
                margin-bottom: 10px;
                page-break-inside: avoid;
                width: 95%;
                margin-left: auto;
                margin-right: auto;
            }
            .summary-item {
                width: 25%;
                padding: 4px;
                box-sizing: border-box;
                text-align: center;
                border: 1px solid #ddd;
                font-size: 9px;
            }
            .summary-title {
                font-weight: bold;
                margin-bottom: 5px;
            }
            .summary-value {
                font-size: 16px;
            }
            .debit-amount {
                color: #dc3545;
            }
            .credit-amount {
                color: #28a745;
            }
        }
    </style>
    
    <div class="container">
        <!-- Header with Company and Statement Info -->
        <div class="row print-header" style="width:95%; margin:0 auto; padding:0;">
            <div class="col-6" style="float: left; width: 49%; margin-right: 1%; padding:0;">
                <?php 
                // Get full company details with all fields
                $full_company = getCompanyInfo($conn, '*');
                
                // Handle logo display - try multiple possible paths
                if (!empty($full_company['logo_path'])): 
                    // Try different path formats
                    $possible_paths = [
                        // Standard relative path
                        ['path' => '../../' . $full_company['logo_path'], 
                         'full' => __DIR__ . '/../../' . $full_company['logo_path']],
                        // Direct path
                        ['path' => $full_company['logo_path'], 
                         'full' => __DIR__ . '/' . $full_company['logo_path']],
                        // Root-relative path
                        ['path' => '/' . ltrim($full_company['logo_path'], '/'), 
                         'full' => $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($full_company['logo_path'], '/')],
                        // Assets folder path
                        ['path' => '../../assets/img/logo.png', 
                         'full' => __DIR__ . '/../../assets/img/logo.png']
                    ];
                    
                    $logo_found = false;
                    foreach ($possible_paths as $path_info) {
                        if (file_exists($path_info['full'])) {
                            $logo_found = true;
                            ?>
                            <img src="<?php echo htmlspecialchars($path_info['path']); ?>" 
                                 alt="Company Logo" 
                                 class="company-logo" 
                                 id="company-logo-print">
                            <?php
                            break;
                        }
                    }
                    
                    if (!$logo_found) {
                        // Log error for debugging
                        $paths_tried = array_column($possible_paths, 'full');
                        error_log("Logo file not found. Tried: " . implode(", ", $paths_tried));
                    }
                endif; ?>
                <h3 class="print-title"><?php echo htmlspecialchars($full_company['company_name'] ?? 'Solar Business'); ?></h3>
                <div class="print-subtitle">
                    <?php if (!empty($full_company['address'])): ?>
                    <div><?php echo nl2br(htmlspecialchars($full_company['address'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($full_company['phone'])): ?>
                    <div>Phone: <?php echo htmlspecialchars($full_company['phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($full_company['email'])): ?>
                    <div>Email: <?php echo htmlspecialchars($full_company['email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($full_company['website'])): ?>
                    <div>Web: <?php echo htmlspecialchars($full_company['website']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($full_company['tax_number'])): ?>
                    <div>Tax #: <?php echo htmlspecialchars($full_company['tax_number']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-6" style="float: right; width: 49%; margin-left: 1%; padding:0; text-align: right;">
                <h2>CUSTOMER STATEMENT</h2>
                <div class="print-subtitle">
                    <div><strong>Period:</strong> <?php echo date('d M Y', strtotime($from_date)); ?> to <?php echo date('d M Y', strtotime($to_date)); ?></div>
                    <div><strong>Print Date:</strong> <?php echo date('d M Y'); ?></div>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>
        
        <!-- Customer Information -->
        <div class="row mb-4">
            <div class="col-12 print-box" style="margin-left: 30px; width: calc(100% - 60px);">
                <div class="print-box-title" style="background-color: #ffff00;">Customer Information</div>
                <table style="width: 100%">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <strong><?php echo htmlspecialchars($customer['customer_name']); ?></strong> (<?php echo htmlspecialchars($customer['customer_code']); ?>)<br>
                            <?php if (!empty($customer['address'])): ?>
                            <?php echo nl2br(htmlspecialchars($customer['address'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($customer['contact_person'])): ?>
                            Contact: <?php echo htmlspecialchars($customer['contact_person']); ?><br>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; vertical-align: top;">
                            <?php if (!empty($customer['phone'])): ?>
                            Phone: <?php echo htmlspecialchars($customer['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($customer['email'])): ?>
                            Email: <?php echo htmlspecialchars($customer['email']); ?><br>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="summary-box">
            <div class="summary-item">
                <div class="summary-title">Opening Balance</div>
                <div class="summary-value <?php echo $opening_balance >= 0 ? 'credit-amount' : 'debit-amount'; ?>">
                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($opening_balance)); ?>
                    <small><?php echo $opening_balance >= 0 ? 'Cr' : 'Dr'; ?></small>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-title">Total Debit</div>
                <div class="summary-value debit-amount">
                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'debit'))); ?>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-title">Total Credit</div>
                <div class="summary-value credit-amount">
                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'credit'))); ?>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-title">Closing Balance</div>
                <div class="summary-value <?php echo $closing_balance >= 0 ? 'credit-amount' : 'debit-amount'; ?>">
                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($closing_balance)); ?>
                    <small><?php echo $closing_balance >= 0 ? 'Cr' : 'Dr'; ?></small>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="row mb-4">
            <div class="col-12 print-box" style="margin-left: 30px; width: calc(100% - 60px);">
                <div class="print-box-title" style="background-color: #ffff00;">Transaction Details</div>
                <table class="print-table" cellspacing="0" cellpadding="0">
                    <thead>
                        <tr>
                            <th style="width:15%;">Date</th>
                            <!-- Type and Reference columns removed for better print layout -->
                            <th style="width:35%;">Description</th>
                            <th style="width:16%;" class="text-right">Debit</th>
                            <th style="width:16%;" class="text-right">Credit</th>
                            <th style="width:18%;" class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($opening_balance != 0): ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($from_date . ' -1 day')); ?></td>
                            <td>Opening</td>
                            <td>-</td>
                            <td>Opening Balance</td>
                            <td class="text-right"><?php echo $opening_balance < 0 ? htmlspecialchars($currency_symbol) . '&nbsp;' . formatNumber($conn, abs($opening_balance)) : '-'; ?></td>
                            <td class="text-right"><?php echo $opening_balance > 0 ? htmlspecialchars($currency_symbol) . '&nbsp;' . formatNumber($conn, $opening_balance) : '-'; ?></td>
                            <td class="text-right">
                                <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($opening_balance)); ?>
                                <?php echo $opening_balance >= 0 ? 'Cr' : 'Dr'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php
                        // Set initial balance
                        $running_balance = $opening_balance;
                        
                        // Loop through transactions
                        foreach ($transactions as $transaction) {
                            // Update running balance
                            $running_balance += $transaction['debit'] - $transaction['credit'];
                            
                            // Format description with type and reference
                            $type_text = ucfirst($transaction['type']);
                            $description = $type_text . ' #' . $transaction['transaction_id'];
                            if (!empty($transaction['description'])) {
                                $description .= ': ' . $transaction['description'];
                            }
                        ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($transaction['transaction_date'])); ?></td>
                            <td style="text-align:left;"><?php echo htmlspecialchars($description); ?></td>
                            <td class="text-right">
                                <?php if ($transaction['debit'] > 0): ?>
                                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $transaction['debit']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($transaction['credit'] > 0): ?>
                                    <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $transaction['credit']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, $running_balance); ?>
                                <small><?php echo $running_balance >= 0 ? 'Dr' : 'Cr'; ?></small>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th class="text-right" colspan="2">Total:</th>
                            <th class="text-right"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'debit'))); ?></th>
                            <th class="text-right"><?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, array_sum(array_column($transactions, 'credit'))); ?></th>
                            <th class="text-right">
                                <?php echo htmlspecialchars($currency_symbol); ?>&nbsp;<?php echo formatNumber($conn, abs($closing_balance)); ?>
                                <small><?php echo $closing_balance >= 0 ? 'Dr' : 'Cr'; ?></small>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="row" style="margin-top: 30px; text-align: center; width: 95%; margin-left: auto; margin-right: auto;">
            <div class="col-12" style="border-top: 1px solid #ddd; padding-top: 10px; font-size: 10px; color: #666;">
                Design and developed by Logic Layer Pvt Ltd
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
// Sidebar toggle functionality
$(document).ready(function() {
    // Sidebar toggle
    $('#sidebarCollapse').on('click', function() {
        $('#sidebar').toggleClass('active');
        $('#content').toggleClass('active');
    });
    
    // Close sidebar on small screens when clicking outside
    $(document).on('click', function(e) {
        if ($(window).width() <= 768) {
            if (!$(e.target).closest('#sidebar, #sidebarCollapse').length) {
                $('#sidebar').removeClass('active');
                $('#content').removeClass('active');
            }
        }
    });
});

// Completely revamped print functionality
function prepareAndPrint() {
    // Show print section before printing to ensure it's in the DOM
    var printSection = document.getElementById('print-section');
    if (printSection) {
        printSection.style.display = 'block';
    }
    
    // Preload company logo if it exists
    var logoImg = document.getElementById('company-logo-print');
    if (logoImg) {
        // Force reload with timestamp to prevent caching issues
        var currentSrc = logoImg.getAttribute('data-original-src') || logoImg.src;
        logoImg.setAttribute('data-original-src', currentSrc);
        logoImg.src = currentSrc + '?t=' + new Date().getTime();
        
        // Set up print after logo loads or fails
        var printWithDelay = function() {
            setTimeout(function() {
                window.print();
                // Hide print section after printing
                setTimeout(function() {
                    printSection.style.display = 'none';
                }, 1000);
            }, 300);
        };
        
        if (logoImg.complete) {
            printWithDelay();
        } else {
            logoImg.onload = printWithDelay;
            logoImg.onerror = function() {
                console.error('Logo failed to load: ' + logoImg.src);
                printWithDelay();
            };
            // Fallback if image takes too long
            setTimeout(printWithDelay, 1500);
        }
    } else {
        // No logo, just print
        setTimeout(function() {
            window.print();
            // Hide print section after printing
            setTimeout(function() {
                if (printSection) printSection.style.display = 'none';
            }, 1000);
        }, 300);
    }
}

// Export to Excel function
function exportToExcel() {
    // Get customer name for filename
    var customerName = '<?php echo addslashes($customer["customer_name"]); ?>';
    var fromDate = '<?php echo $from_date; ?>';
    var toDate = '<?php echo $to_date; ?>';
    var companyName = '<?php echo addslashes($full_company["company_name"] ?? "Solar Business"); ?>';
    var companyAddress = '<?php echo addslashes(str_replace("\n", " ", $full_company["address"] ?? "")); ?>';
    var companyPhone = '<?php echo addslashes($full_company["phone"] ?? ""); ?>';
    var companyEmail = '<?php echo addslashes($full_company["email"] ?? ""); ?>';
    var companyTaxNumber = '<?php echo addslashes($full_company["tax_number"] ?? ""); ?>';
    var currencySymbol = '<?php echo addslashes($currency_symbol); ?>';
    var customerCode = '<?php echo addslashes($customer["customer_code"]); ?>';
    var customerContact = '<?php echo addslashes($customer["contact_person"] ?? ""); ?>';
    var customerPhone = '<?php echo addslashes($customer["phone"] ?? ""); ?>';
    var customerEmail = '<?php echo addslashes($customer["email"] ?? ""); ?>';
    var openingBalance = <?php echo $opening_balance; ?>;
    var openingBalanceText = currencySymbol + ' ' + Math.abs(openingBalance).toFixed(2) + (openingBalance >= 0 ? ' Cr' : ' Dr');
    var totalDebit = <?php echo array_sum(array_column($transactions, 'debit')); ?>;
    var totalCredit = <?php echo array_sum(array_column($transactions, 'credit')); ?>;
    var closingBalance = <?php echo $closing_balance; ?>;
    var closingBalanceText = currencySymbol + ' ' + Math.abs(closingBalance).toFixed(2) + (closingBalance >= 0 ? ' Cr' : ' Dr');
    
    // Create a new workbook
    var wb = XLSX.utils.book_new();
    
    // Create worksheet data with company header
    var wsData = [];
    
    // Add company header
    wsData.push([companyName]);
    if (companyAddress) wsData.push([companyAddress]);
    if (companyPhone) wsData.push(['Phone: ' + companyPhone]);
    if (companyEmail) wsData.push(['Email: ' + companyEmail]);
    if (companyTaxNumber) wsData.push(['Tax #: ' + companyTaxNumber]);
    
    // Add statement title and period
    wsData.push([]);
    wsData.push(['CUSTOMER STATEMENT']);
    wsData.push(['Period: ' + fromDate + ' to ' + toDate]);
    wsData.push(['Print Date: ' + new Date().toLocaleDateString()]);
    
    // Add customer information
    wsData.push([]);
    wsData.push(['Customer Information']);
    wsData.push(['Name: ' + customerName + ' (' + customerCode + ')']);
    if (customerContact) wsData.push(['Contact: ' + customerContact]);
    if (customerPhone) wsData.push(['Phone: ' + customerPhone]);
    if (customerEmail) wsData.push(['Email: ' + customerEmail]);
    
    // Add balance summary
    wsData.push([]);
    wsData.push(['Balance Summary']);
    wsData.push(['Opening Balance:', openingBalanceText]);
    wsData.push(['Total Debit:', currencySymbol + ' ' + totalDebit.toFixed(2)]);
    wsData.push(['Total Credit:', currencySymbol + ' ' + totalCredit.toFixed(2)]);
    wsData.push(['Closing Balance:', closingBalanceText]);
    
    // Add transaction table header
    wsData.push([]);
    wsData.push(['Transaction Details']);
    wsData.push(['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance']);
    
    // Add opening balance row if not zero
    if (openingBalance != 0) {
        var openingDate = new Date('<?php echo $from_date; ?>');
        openingDate.setDate(openingDate.getDate() - 1);
        var formattedDate = openingDate.toLocaleDateString();
        
        wsData.push([
            formattedDate,
            'Opening',
            '-',
            'Opening Balance',
            openingBalance < 0 ? Math.abs(openingBalance).toFixed(2) : '',
            openingBalance > 0 ? openingBalance.toFixed(2) : '',
            openingBalanceText
        ]);
    }
    
    // Add transaction rows
    var runningBalance = openingBalance;
    <?php foreach ($transactions as $transaction): ?>
        runningBalance = runningBalance + <?php echo $transaction['credit']; ?> - <?php echo $transaction['debit']; ?>;
        var balanceText = currencySymbol + ' ' + Math.abs(runningBalance).toFixed(2) + (runningBalance >= 0 ? ' Cr' : ' Dr');
        
        wsData.push([
            '<?php echo date('d-m-Y', strtotime($transaction["transaction_date"])); ?>',
            '<?php echo ucfirst($transaction["type"]); ?>',
            '<?php echo addslashes($transaction["transaction_id"]); ?>',
            '<?php echo addslashes($transaction["description"]); ?>',
            <?php echo $transaction['debit'] > 0 ? $transaction['debit'] . '.toFixed(2)' : '""'; ?>,
            <?php echo $transaction['credit'] > 0 ? $transaction['credit'] . '.toFixed(2)' : '""'; ?>,
            balanceText
        ]);
    <?php endforeach; ?>
    
    // Add total row
    wsData.push([
        '', '', '', 'Total:',
        totalDebit.toFixed(2),
        totalCredit.toFixed(2),
        closingBalanceText
    ]);
    
    // Create worksheet from data
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    
    // Set column widths
    var wscols = [
        {wch: 12}, // Date
        {wch: 10}, // Type
        {wch: 15}, // Reference
        {wch: 30}, // Description
        {wch: 12}, // Debit
        {wch: 12}, // Credit
        {wch: 15}  // Balance
    ];
    ws['!cols'] = wscols;
    
    // Add worksheet to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Statement');
    
    // Generate filename
    var filename = customerName + '_Statement_' + fromDate + '_to_' + toDate + '.xlsx';
    
    // Save file
    XLSX.writeFile(wb, filename);
}
</script>

<!-- Include SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</body>
</html>
