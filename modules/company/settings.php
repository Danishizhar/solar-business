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
require_once '../../includes/company_helpers.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = substr($key, 8); // Remove 'setting_' prefix
                
                // Update using the helper function
                updateSystemSetting($db, $setting_key, $value, $_SESSION['user_id']);
            }
        }

        $db->commit();
        $success_message = "Settings updated successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

// Get all settings grouped by category using helper function
try {
    $all_settings = getSettingsByCategory($db);
    
    // Group settings by category
    $settings = [];
    foreach ($all_settings as $setting) {
        // Skip any settings that might be in the invoice group (for safety)
        if ($setting['setting_group'] !== 'invoice') {
            $settings[$setting['setting_group']][] = $setting;
        }
    }
} catch (PDOException $e) {
    $error_message = "Error fetching settings: " . $e->getMessage();
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
                    <h2>System Settings</h2>
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
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="nav flex-column nav-pills" role="tablist">
                                    <?php 
                                    $first = true;
                                    foreach ($settings as $group => $group_settings): 
                                    ?>
                                    <button class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                                            data-bs-toggle="pill" 
                                            data-bs-target="#<?php echo htmlspecialchars($group); ?>-tab" 
                                            type="button" 
                                            role="tab">
                                        <?php echo ucfirst(htmlspecialchars($group)); ?> Settings
                                    </button>
                                    <?php 
                                    $first = false;
                                    endforeach; 
                                    ?>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="tab-content">
                                    <?php 
                                    $first = true;
                                    foreach ($settings as $group => $group_settings): 
                                    ?>
                                    <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                                         id="<?php echo htmlspecialchars($group); ?>-tab" 
                                         role="tabpanel">
                                        <h4 class="mb-4"><?php echo ucfirst(htmlspecialchars($group)); ?> Settings</h4>
                                        <?php foreach ($group_settings as $setting): ?>
                                        <div class="mb-3">
                                            <label class="form-label">
                                                <?php echo isset($setting['setting_key']) ? ucwords(str_replace('_', ' ', htmlspecialchars($setting['setting_key']))) : ''; ?>
                                            </label>
                                            <?php if (isset($setting['setting_key']) && in_array($setting['setting_key'], ['enable_email_notifications', 'enable_sms_notifications'])): ?>
                                            <div class="form-check form-switch">
                                                <input type="checkbox" 
                                                       class="form-check-input" 
                                                       name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                                       value="1"
                                                       <?php echo (isset($setting['setting_value']) && $setting['setting_value'] == '1') ? 'checked' : ''; ?>>
                                            </div>
                                            <?php else: ?>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="setting_<?php echo isset($setting['setting_key']) ? htmlspecialchars($setting['setting_key']) : ''; ?>"
                                                   value="<?php echo isset($setting['setting_value']) ? htmlspecialchars($setting['setting_value']) : ''; ?>">
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php 
                                    $first = false;
                                    endforeach; 
                                    ?>
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
