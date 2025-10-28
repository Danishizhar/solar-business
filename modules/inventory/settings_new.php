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
    try {
        // Start transaction
        $db->beginTransaction();

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = substr($key, 8); // Remove 'setting_' prefix
                
                $query = "UPDATE inventory_settings SET 
                         setting_value = ?,
                         updated_at = NOW(),
                         updated_by = ?
                         WHERE setting_key = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$value, $_SESSION['user_id'], $setting_key]);
            }
        }

        $db->commit();
        $success_message = "Inventory settings updated successfully!";
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Error updating settings: " . $e->getMessage();
        error_log("Error in inventory settings update: " . $e->getMessage());
    }
}

// Check if barcode settings exist and install them if they don't
try {
    $check_barcode_query = "SELECT COUNT(*) as count FROM inventory_settings WHERE setting_group = 'barcode'";
    $check_barcode_stmt = $db->prepare($check_barcode_query);
    $check_barcode_stmt->execute();
    $result = $check_barcode_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] < 1) {
        // Install barcode settings
        $sql_file = file_get_contents('sql/add_barcode_settings.sql');
        if ($sql_file) {
            // Split into individual statements
            $statements = explode(';', $sql_file);
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }
            
            $success_message = "Barcode settings installed successfully.";
        }
    }
} catch (PDOException $e) {
    $error_message = "Error checking barcode settings: " . $e->getMessage();
    error_log("Error checking barcode settings: " . $e->getMessage());
}

// Get all settings grouped by category
try {
    $query = "SELECT * FROM inventory_settings ORDER BY setting_group, display_order, display_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $all_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group settings by category
    $settings = [];
    foreach ($all_settings as $setting) {
        $settings[$setting['setting_group']][] = $setting;
    }
} catch (PDOException $e) {
    $error_message = "Error fetching settings: " . $e->getMessage();
    error_log("Error fetching inventory settings: " . $e->getMessage());
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
                    <h2>Inventory Settings</h2>
                    <p class="text-muted">Configure your inventory module to match your business needs</p>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <!-- Settings Tabs -->
                        <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                            <?php 
                            $first_tab = true;
                            foreach ($settings as $group => $group_settings): 
                                $group_id = strtolower(str_replace(' ', '-', $group));
                            ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $first_tab ? 'active' : ''; ?>" 
                                        id="<?php echo $group_id; ?>-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#<?php echo $group_id; ?>" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="<?php echo $group_id; ?>" 
                                        aria-selected="<?php echo $first_tab ? 'true' : 'false'; ?>">
                                    <?php echo htmlspecialchars(ucfirst($group)); ?>
                                </button>
                            </li>
                            <?php 
                                $first_tab = false;
                            endforeach; 
                            ?>
                            <!-- Add Barcode Settings Tab if not already present -->
                            <?php if (!isset($settings['barcode'])): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" 
                                        id="barcode-settings-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#barcode-settings" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="barcode-settings" 
                                        aria-selected="false">
                                    Barcode
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                        
                        <!-- Tab Content -->
                        <div class="tab-content">
                            <?php 
                            $first_tab = true;
                            foreach ($settings as $group => $group_settings): 
                                $group_id = strtolower(str_replace(' ', '-', $group));
                            ?>
                            <div class="tab-pane fade <?php echo $first_tab ? 'show active' : ''; ?>" 
                                 id="<?php echo $group_id; ?>" 
                                 role="tabpanel"
                                 aria-labelledby="<?php echo $group_id; ?>-tab">
                                <h4 class="mb-4"><?php echo ucfirst(htmlspecialchars($group)); ?> Settings</h4>
                                
                                <?php foreach ($group_settings as $setting): ?>
                                <div class="mb-3">
                                    <label class="form-label">
                                        <?php echo htmlspecialchars($setting['display_name']); ?>
                                        <?php if (!empty($setting['description'])): ?>
                                        <i class="fas fa-info-circle text-muted" 
                                           data-bs-toggle="tooltip" 
                                           title="<?php echo htmlspecialchars($setting['description']); ?>"></i>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['input_type'] == 'checkbox'): ?>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" 
                                               class="form-check-input" 
                                               name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                               value="1"
                                               <?php echo ($setting['setting_value'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label">Enabled</label>
                                    </div>
                                    
                                    <?php elseif ($setting['input_type'] == 'select' && !empty($setting['input_options'])): ?>
                                    <select class="form-select" 
                                            name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>">
                                        <?php 
                                        $options = explode(',', $setting['input_options']);
                                        foreach ($options as $option):
                                            $selected = ($setting['setting_value'] == $option) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <?php elseif ($setting['input_type'] == 'textarea'): ?>
                                    <textarea class="form-control" 
                                              name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                              rows="3"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                                    
                                    <?php elseif ($setting['input_type'] == 'number'): ?>
                                    <input type="number" 
                                           class="form-control" 
                                           name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    
                                    <?php else: ?>
                                    <input type="text" 
                                           class="form-control" 
                                           name="setting_<?php echo htmlspecialchars($setting['setting_key']); ?>"
                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php 
                                $first_tab = false;
                            endforeach; 
                            ?>
                            
                            <!-- Barcode Settings Tab Content -->
                            <?php if (!isset($settings['barcode'])): ?>
                            <div class="tab-pane fade" id="barcode-settings" role="tabpanel" aria-labelledby="barcode-settings-tab">
                                <h4 class="mb-4" id="barcode-settings-header">Barcode Settings</h4>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Configure barcode generation settings for your inventory items.
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Default Barcode Format
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="The default format to use when generating barcodes"></i>
                                    </label>
                                    <select name="setting_default_barcode_format" class="form-control">
                                        <option value="EAN13" selected>EAN13</option>
                                        <option value="CODE128">CODE128</option>
                                        <option value="CODE39">CODE39</option>
                                        <option value="UPC">UPC</option>
                                        <option value="EAN8">EAN8</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Barcode Prefix
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Optional prefix to add to automatically generated barcodes (e.g., country code)"></i>
                                    </label>
                                    <input type="text" name="setting_barcode_prefix" class="form-control" value="" placeholder="Optional prefix">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Auto-Generate Barcodes
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Automatically generate barcodes for new products"></i>
                                    </label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" name="setting_auto_generate_barcode" class="form-check-input" value="1">
                                        <label class="form-check-label">Enable auto-generation</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Default Label Size
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Default size for barcode labels"></i>
                                    </label>
                                    <select name="setting_barcode_label_size" class="form-control">
                                        <option value="small">Small (38mm x 25mm)</option>
                                        <option value="medium" selected>Medium (50mm x 30mm)</option>
                                        <option value="large">Large (100mm x 50mm)</option>
                                        <option value="a4-30">A4 - 30 per sheet</option>
                                        <option value="a4-24">A4 - 24 per sheet</option>
                                        <option value="a4-16">A4 - 16 per sheet</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">
                                        Default Label Style
                                        <i class="fas fa-info-circle text-muted" data-bs-toggle="tooltip" title="Default style for barcode labels"></i>
                                    </label>
                                    <select name="setting_barcode_label_style" class="form-control">
                                        <option value="basic" selected>Basic</option>
                                        <option value="compact">Compact</option>
                                        <option value="detailed">Detailed</option>
                                        <option value="minimal">Minimal</option>
                                    </select>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="button" id="install-barcode-settings" class="btn btn-outline-primary">
                                        <i class="fas fa-wrench"></i> Install Barcode Settings
                                    </button>
                                    <small class="text-muted ms-2">Click to create these settings in the database</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-4">
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include settings JS -->
<script src="js/settings.js"></script>

<?php include '../../includes/footer.php'; ?>
