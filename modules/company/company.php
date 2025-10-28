<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user has admin role
if ($_SESSION['role_id'] != 1) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_name = $_POST['company_name'];
    $address = $_POST['address'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $website = $_POST['website'];
    $tax_number = $_POST['tax_number'];
    $registration_number = $_POST['registration_number'];
    $currency = $_POST['currency'];
    $timezone = $_POST['timezone'];
    $fiscal_year_start = $_POST['fiscal_year_start'];

    try {
        // Check if company exists
        $query = "SELECT id, logo_path FROM company LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle logo upload
        $logo_path = $company ? $company['logo_path'] : null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed)) {
                throw new Exception("Invalid file format. Allowed formats: " . implode(', ', $allowed));
            }

            // Create uploads directory if it doesn't exist
            $upload_dir = '../../uploads/company/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Generate unique filename
            $new_filename = uniqid() . '.' . $ext;
            $new_path = $upload_dir . $new_filename;

            // Move uploaded file
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $new_path)) {
                // Delete old logo if exists
                if ($logo_path && file_exists('../../' . $logo_path)) {
                    unlink('../../' . $logo_path);
                }
                $logo_path = 'uploads/company/' . $new_filename;
            } else {
                throw new Exception("Error uploading file.");
            }
        }

        if ($company) {
            // Update existing company
            $query = "UPDATE company SET 
                     company_name = ?, 
                     address = ?, 
                     phone = ?, 
                     email = ?, 
                     website = ?,
                     tax_number = ?,
                     registration_number = ?,
                     currency = ?,
                     timezone = ?,
                     fiscal_year_start = ?,
                     logo_path = ?,
                     updated_at = NOW(),
                     updated_by = ?
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $company_name, $address, $phone, $email, $website,
                $tax_number, $registration_number, $currency, $timezone, $fiscal_year_start,
                $logo_path, $_SESSION['user_id'], $company['id']
            ]);
        } else {
            // Insert new company
            $query = "INSERT INTO company (
                     company_name, address, phone, email, website,
                     tax_number, registration_number, currency, timezone, fiscal_year_start,
                     logo_path, created_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $company_name, $address, $phone, $email, $website,
                $tax_number, $registration_number, $currency, $timezone, $fiscal_year_start,
                $logo_path, $_SESSION['user_id']
            ]);
        }

        $success_message = "Company information saved successfully!";
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get company data
try {
    $query = "SELECT * FROM company LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Make sure we have company data
    if (!$company) {
        $company = [];
    }
} catch (PDOException $e) {
    $error_message = "Error fetching company data: " . $e->getMessage();
    $company = [];
}

// Include header
include '../../includes/header.php';
?>

<div class="wrapper">
    <?php include '../../includes/sidebar.php'; ?>

    <div id="content">
        <?php include '../../includes/navbar.php'; ?>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h2>Company Profile</h2>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            


            <div class="card">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <!-- Basic Information -->
                            <div class="col-md-6">
                                <h4 class="mb-4">Basic Information</h4>
                                
                                <div class="mb-3">
                                    <label class="form-label">Company Name</label>
                                    <?php
                                    $query_name = "SELECT company_name FROM company LIMIT 1";
                                    $stmt_name = $db->prepare($query_name);
                                    $stmt_name->execute();
                                    $company_name = $stmt_name->fetchColumn();
                                    ?>
                                    <input type="text" 
                                           class="form-control" 
                                           name="company_name" 
                                           value="<?php echo htmlspecialchars($company_name ?? ''); ?>" 
                                           required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <?php
                                    $query_address = "SELECT address FROM company LIMIT 1";
                                    $stmt_address = $db->prepare($query_address);
                                    $stmt_address->execute();
                                    $address = $stmt_address->fetchColumn();
                                    ?>
                                    <textarea class="form-control" 
                                              name="address" 
                                              rows="3"><?php echo htmlspecialchars($address ?? ''); ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <?php
                                    $query_phone = "SELECT phone FROM company LIMIT 1";
                                    $stmt_phone = $db->prepare($query_phone);
                                    $stmt_phone->execute();
                                    $phone = $stmt_phone->fetchColumn();
                                    ?>
                                    <input type="tel" 
                                           class="form-control" 
                                           name="phone" 
                                           value="<?php echo htmlspecialchars($phone ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <?php
                                    $query_email = "SELECT email FROM company LIMIT 1";
                                    $stmt_email = $db->prepare($query_email);
                                    $stmt_email->execute();
                                    $email = $stmt_email->fetchColumn();
                                    ?>
                                    <input type="email" 
                                           class="form-control" 
                                           name="email" 
                                           value="<?php echo htmlspecialchars($email ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Website</label>
                                    <?php
                                    $query_website = "SELECT website FROM company LIMIT 1";
                                    $stmt_website = $db->prepare($query_website);
                                    $stmt_website->execute();
                                    $website = $stmt_website->fetchColumn();
                                    ?>
                                    <input type="url" 
                                           class="form-control" 
                                           name="website" 
                                           value="<?php echo htmlspecialchars($website ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Company Logo</label>
                                    <?php
                                    $query_logo = "SELECT logo_path FROM company LIMIT 1";
                                    $stmt_logo = $db->prepare($query_logo);
                                    $stmt_logo->execute();
                                    $logo_path = $stmt_logo->fetchColumn();
                                    
                                    if ($logo_path): 
                                    ?>
                                    <div class="mb-2">
                                        <img src="../../<?php echo htmlspecialchars($logo_path); ?>" 
                                             alt="Company Logo" 
                                             class="img-thumbnail" 
                                             style="max-height: 100px;">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" 
                                           class="form-control" 
                                           name="logo" 
                                           accept="image/jpeg,image/png,image/gif">
                                    <small class="text-muted">Allowed formats: JPG, PNG, GIF</small>
                                </div>
                            </div>

                            <!-- Legal & Financial Information -->
                            <div class="col-md-6">
                                <h4 class="mb-4">Legal & Financial Information</h4>

                                <div class="mb-3">
                                    <label class="form-label">Tax Number</label>
                                    <?php
                                    $query_tax = "SELECT tax_number FROM company LIMIT 1";
                                    $stmt_tax = $db->prepare($query_tax);
                                    $stmt_tax->execute();
                                    $tax_number = $stmt_tax->fetchColumn();
                                    ?>
                                    <input type="text" 
                                           class="form-control" 
                                           name="tax_number" 
                                           value="<?php echo htmlspecialchars($tax_number ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Registration Number</label>
                                    <?php
                                    $query_reg = "SELECT registration_number FROM company LIMIT 1";
                                    $stmt_reg = $db->prepare($query_reg);
                                    $stmt_reg->execute();
                                    $registration_number = $stmt_reg->fetchColumn();
                                    ?>
                                    <input type="text" 
                                           class="form-control" 
                                           name="registration_number" 
                                           value="<?php echo htmlspecialchars($registration_number ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Currency</label>
                                    <?php
                                    $query_currency = "SELECT currency FROM company LIMIT 1";
                                    $stmt_currency = $db->prepare($query_currency);
                                    $stmt_currency->execute();
                                    $currency_value = $stmt_currency->fetchColumn();
                                    ?>
                                    <select class="form-control" name="currency">
                                        <option value="PKR" <?php echo ($currency_value == 'PKR') ? 'selected' : ''; ?>>Pakistani Rupee (PKR)</option>
                                        <option value="USD" <?php echo ($currency_value == 'USD') ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                        <option value="EUR" <?php echo ($currency_value == 'EUR') ? 'selected' : ''; ?>>Euro (EUR)</option>
                                        <option value="GBP" <?php echo ($currency_value == 'GBP') ? 'selected' : ''; ?>>British Pound (GBP)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Timezone</label>
                                    <?php
                                    $query_timezone = "SELECT timezone FROM company LIMIT 1";
                                    $stmt_timezone = $db->prepare($query_timezone);
                                    $stmt_timezone->execute();
                                    $timezone_value = $stmt_timezone->fetchColumn();
                                    ?>
                                    <select class="form-control" name="timezone">
                                        <option value="Asia/Karachi" <?php echo ($timezone_value == 'Asia/Karachi') ? 'selected' : ''; ?>>Pakistan (Asia/Karachi)</option>
                                        <option value="UTC" <?php echo ($timezone_value == 'UTC') ? 'selected' : ''; ?>>UTC</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Fiscal Year Start</label>
                                    <?php
                                    $query_fiscal = "SELECT fiscal_year_start FROM company LIMIT 1";
                                    $stmt_fiscal = $db->prepare($query_fiscal);
                                    $stmt_fiscal->execute();
                                    $fiscal_year_start = $stmt_fiscal->fetchColumn();
                                    ?>
                                    <input type="date" 
                                           class="form-control" 
                                           name="fiscal_year_start" 
                                           value="<?php echo $fiscal_year_start ?? ''; ?>">
                                    <small class="text-muted">This will be used for financial reporting</small>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
