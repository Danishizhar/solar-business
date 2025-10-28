<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('html_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Basic HTML page with no database queries
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Discount Page</title>
    <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h1>Simple Discount Page</h1>
                    </div>
                    <div class="card-body">
                        <p>This is a simple test page to check if basic PHP rendering works.</p>
                        <p>If you can see this page, then the issue is with the database queries or other code in the main discounts.php file.</p>
                        
                        <h3>Next Steps:</h3>
                        <ol>
                            <li>Run the SQL setup script: <a href="execute_discount_sql.php" class="btn btn-sm btn-warning">Run SQL Setup</a></li>
                            <li>Try the test discount page: <a href="test_discount_calculation.php" class="btn btn-sm btn-info">Test Discount</a></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
