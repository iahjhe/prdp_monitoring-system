<!-- prdp_system/transactions/view.php -->

<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// First, check what columns actually exist
$existing_columns = [];
try {
    $col_stmt = $conn->query("PRAGMA table_info(transactions)");
    while ($col = $col_stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $col['name'];
    }
    error_log("Existing columns: " . implode(', ', $existing_columns));
} catch(Exception $e) {
    error_log("Error checking columns: " . $e->getMessage());
}

// Get transaction ID from URL
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    $_SESSION['error'] = "Transaction ID is required";
    header("Location: index.php");
    exit();
}

// Get transaction details with joins
$stmt = $conn->prepare("
    SELECT 
        t.*,
        f.fund_code,
        f.fund_name,
        f.year_id,
        y.year as fund_year,
        c.component_code,
        c.component_name,
        a_t.account_title,
        a_t.uacs_code as account_uacs
    FROM transactions t
    JOIN funds f ON t.fund_id = f.id
    JOIN years y ON f.year_id = y.id
    JOIN components c ON t.component_id = c.id
    JOIN account_titles a_t ON t.account_title_id = a_t.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$transaction = $stmt->fetch();

if (!$transaction) {
    $_SESSION['error'] = "Transaction not found";
    header("Location: index.php");
    exit();
}

// Get active funds for dropdown
$funds = $conn->query("
    SELECT f.*, y.year 
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.status = 'active'
    ORDER BY y.year DESC, f.fund_code
")->fetchAll();

// Get components for dropdown
$components = $conn->query("SELECT * FROM components ORDER BY component_code")->fetchAll();

// Get account titles for dropdown
$account_titles = $conn->query("SELECT * FROM account_titles ORDER BY uacs_code")->fetchAll();

// Get change logs for this transaction
$logs = [];
try {
    $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='transaction_logs'");
    if ($table_check->fetch()) {
        $logs_stmt = $conn->prepare("
            SELECT * FROM transaction_logs 
            WHERE transaction_id = ? 
            ORDER BY created_at DESC
        ");
        $logs_stmt->execute([$id]);
        $logs = $logs_stmt->fetchAll();
    }
} catch(Exception $e) {
    error_log("Error fetching logs: " . $e->getMessage());
}

// Handle delete request
if (isset($_POST['delete_transaction'])) {
    try {
        $conn->beginTransaction();
        
        // Check if transaction has disbursements
        $check_disbursements = $conn->prepare("SELECT COUNT(*) FROM disbursements WHERE transaction_id = ?");
        $check_disbursements->execute([$id]);
        $disbursement_count = $check_disbursements->fetchColumn();
        
        if ($disbursement_count > 0) {
            $_SESSION['error'] = "Cannot delete transaction with existing disbursements. Delete disbursements first.";
        } else {
            // Delete the transaction
            $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ?");
            $stmt->execute([$id]);
            
            $conn->commit();
            $_SESSION['success'] = "Transaction deleted successfully.";
            header("Location: index.php");
            exit();
        }
        
        $conn->commit();
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error deleting transaction: " . $e->getMessage();
    }
}

// Handle form submission for updates
if($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_transaction'])) {
    try {
        $conn->beginTransaction();
        
        // Get old values for logging
        $old_values = $transaction;
        
        // Handle unit selection
        $unit = $_POST['unit'] ?? '';
        if ($unit == 'Others' && !empty($_POST['unit_other'])) {
            $unit = $_POST['unit_other'];
        }
        
        // Calculate adjusted obligation
        $obligation = !empty($_POST['obligation']) ? floatval($_POST['obligation']) : 0;
        $adjustment = !empty($_POST['adjustment']) ? floatval($_POST['adjustment']) : 0;
        $adjusted_obligation = $obligation - $adjustment;
        
        // Get account title details if selected
        $uacs_code = null;
        if (!empty($_POST['account_title_id'])) {
            $acc_stmt = $conn->prepare("SELECT uacs_code FROM account_titles WHERE id = ?");
            $acc_stmt->execute([$_POST['account_title_id']]);
            $account = $acc_stmt->fetch();
            $uacs_code = $account ? $account['uacs_code'] : null;
        }
        
        // Build UPDATE query dynamically based on existing columns
        $update_fields = [];
        $params = [':id' => $id];
        
        // Define all possible fields and their values
        $field_mappings = [
            'fund_id' => !empty($_POST['fund_id']) ? $_POST['fund_id'] : null,
            'month' => !empty($_POST['month']) ? $_POST['month'] : null,
            'date_transaction' => !empty($_POST['date_transaction']) ? $_POST['date_transaction'] : null,
            'asa_no' => !empty($_POST['asa_no']) ? $_POST['asa_no'] : null,
            'pap_code' => !empty($_POST['pap_code']) ? $_POST['pap_code'] : null,
            'rc_code' => !empty($_POST['rc_code']) ? $_POST['rc_code'] : null,
            'ors_no' => !empty($_POST['ors_no']) ? $_POST['ors_no'] : null,
            'payee' => !empty($_POST['payee']) ? $_POST['payee'] : null,
            'component_id' => !empty($_POST['component_id']) ? $_POST['component_id'] : null,
            'component_no' => !empty($_POST['component_no']) ? $_POST['component_no'] : null,
            'unit' => !empty($unit) ? $unit : null,
            'account_title_id' => !empty($_POST['account_title_id']) ? $_POST['account_title_id'] : null,
            'uacs_code' => $uacs_code,
            'particulars' => !empty($_POST['particulars']) ? $_POST['particulars'] : null,
            'obligation' => $obligation,
            'adjustment' => $adjustment,
            'adjusted_obligation' => $adjusted_obligation,
            'gop_amount' => !empty($_POST['gop_amount']) ? floatval($_POST['gop_amount']) : 0,
            'lp_amount' => !empty($_POST['lp_amount']) ? floatval($_POST['lp_amount']) : 0,
            'status' => !empty($_POST['status']) ? $_POST['status'] : 'pending',
            'unpaid_obligation' => $adjusted_obligation,
            'date_input_engas' => !empty($_POST['date_input_engas']) ? $_POST['date_input_engas'] : null,
            'box_d_of_dv' => !empty($_POST['box_d_of_dv']) ? $_POST['box_d_of_dv'] : null,
            'dv_received_for_lddap' => !empty($_POST['dv_received_for_lddap']) ? $_POST['dv_received_for_lddap'] : null,
            'lddap_no' => !empty($_POST['lddap_no']) ? $_POST['lddap_no'] : null,
            'iplan_11' => !empty($_POST['iplan_11']) ? floatval($_POST['iplan_11']) : 0,
            'iplan_12' => !empty($_POST['iplan_12']) ? floatval($_POST['iplan_12']) : 0,
            'ibuild_21' => !empty($_POST['ibuild_21']) ? floatval($_POST['ibuild_21']) : 0,
            'ibuild_22' => !empty($_POST['ibuild_22']) ? floatval($_POST['ibuild_22']) : 0,
            'ireap_31' => !empty($_POST['ireap_31']) ? floatval($_POST['ireap_31']) : 0,
            'ireap_32' => !empty($_POST['ireap_32']) ? floatval($_POST['ireap_32']) : 0,
            'isupport' => !empty($_POST['isupport']) ? floatval($_POST['isupport']) : 0,
            'sre' => !empty($_POST['sre']) ? floatval($_POST['sre']) : 0
        ];
        
        // Only add category if it exists in the database
        if (in_array('category', $existing_columns)) {
            $field_mappings['category'] = !empty($_POST['category']) ? $_POST['category'] : null;
        }
        
        // Add mo_no only if it exists
        if (in_array('mo_no', $existing_columns)) {
            $field_mappings['mo_no'] = !empty($_POST['mo_no']) ? $_POST['mo_no'] : null;
        }
        
        // Log the values we're trying to update
        error_log("Updating transaction ID: " . $id);
        error_log("Category exists in DB: " . (in_array('category', $existing_columns) ? 'Yes' : 'No'));
        if (in_array('category', $existing_columns)) {
            error_log("Category value: " . ($_POST['category'] ?? 'empty'));
        }
        error_log("iplan_11 value: " . ($_POST['iplan_11'] ?? '0'));
        
        // Build the UPDATE query
        foreach ($field_mappings as $field => $value) {
            if (in_array($field, $existing_columns)) {
                $update_fields[] = "$field = :$field";
                $params[":$field"] = $value;
                error_log("Field $field = " . ($value ?? 'NULL'));
            } else {
                error_log("Field $field does not exist in database - skipping");
            }
        }
        
        // Add updated_at
        $update_fields[] = "updated_at = CURRENT_TIMESTAMP";
        
        // Create and execute the dynamic UPDATE query
        $sql = "UPDATE transactions SET " . implode(', ', $update_fields) . " WHERE id = :id";
        error_log("SQL: " . $sql);
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            error_log("Update successful");
            
            // Verify the update by fetching the record again
            $verify_fields = ['iplan_11'];
            if (in_array('category', $existing_columns)) {
                $verify_fields[] = 'category';
            }
            
            $verify_sql = "SELECT " . implode(', ', $verify_fields) . " FROM transactions WHERE id = ?";
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->execute([$id]);
            $updated = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (in_array('category', $existing_columns)) {
                error_log("After update - Category: " . ($updated['category'] ?? 'NULL'));
            }
            error_log("After update - iplan_11: " . ($updated['iplan_11'] ?? '0'));
        } else {
            error_log("Update failed");
        }
        
        // Log the changes if transaction_logs table exists
        $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='transaction_logs'");
        if ($table_check->fetch()) {
            $changes = [];
            
            // Compare category if it exists
            if (in_array('category', $existing_columns)) {
                if (($old_values['category'] ?? '') != ($_POST['category'] ?? '')) {
                    $changes[] = "Category: changed from '" . ($old_values['category'] ?? 'empty') . "' to '" . ($_POST['category'] ?? 'empty') . "'";
                }
            }
            
            // Compare allocation values
            if (($old_values['iplan_11'] ?? 0) != floatval($_POST['iplan_11'] ?? 0)) {
                $changes[] = "I-PLAN 1.1: changed from ₱" . number_format($old_values['iplan_11'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['iplan_11'] ?? 0), 2);
            }
            
            if (($old_values['iplan_12'] ?? 0) != floatval($_POST['iplan_12'] ?? 0)) {
                $changes[] = "I-PLAN 1.2: changed from ₱" . number_format($old_values['iplan_12'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['iplan_12'] ?? 0), 2);
            }
            
            if (($old_values['ibuild_21'] ?? 0) != floatval($_POST['ibuild_21'] ?? 0)) {
                $changes[] = "I-BUILD 2.1: changed from ₱" . number_format($old_values['ibuild_21'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['ibuild_21'] ?? 0), 2);
            }
            
            if (($old_values['ibuild_22'] ?? 0) != floatval($_POST['ibuild_22'] ?? 0)) {
                $changes[] = "I-BUILD 2.2: changed from ₱" . number_format($old_values['ibuild_22'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['ibuild_22'] ?? 0), 2);
            }
            
            if (($old_values['ireap_31'] ?? 0) != floatval($_POST['ireap_31'] ?? 0)) {
                $changes[] = "I-REAP 3.1: changed from ₱" . number_format($old_values['ireap_31'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['ireap_31'] ?? 0), 2);
            }
            
            if (($old_values['ireap_32'] ?? 0) != floatval($_POST['ireap_32'] ?? 0)) {
                $changes[] = "I-REAP 3.2: changed from ₱" . number_format($old_values['ireap_32'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['ireap_32'] ?? 0), 2);
            }
            
            if (($old_values['isupport'] ?? 0) != floatval($_POST['isupport'] ?? 0)) {
                $changes[] = "I-SUPPORT: changed from ₱" . number_format($old_values['isupport'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['isupport'] ?? 0), 2);
            }
            
            if (($old_values['sre'] ?? 0) != floatval($_POST['sre'] ?? 0)) {
                $changes[] = "SRE: changed from ₱" . number_format($old_values['sre'] ?? 0, 2) . " to ₱" . number_format(floatval($_POST['sre'] ?? 0), 2);
            }
            
            $fields_to_check = [
                'fund_id' => 'Fund',
                'payee' => 'Payee',
                'obligation' => 'Obligation',
                'adjustment' => 'Adjustment',
                'adjusted_obligation' => 'Adjusted Obligation',
                'status' => 'Status',
                'month' => 'Month',
                'date_transaction' => 'Date',
                'asa_no' => 'ASA No.'
            ];
            
            foreach ($fields_to_check as $field => $label) {
                if (!in_array($field, $existing_columns)) continue;
                
                $old_value = $old_values[$field] ?? '';
                $new_value = $field_mappings[$field] ?? '';
                
                if ($field == 'fund_id' && $old_value != $new_value) {
                    $old_fund = $conn->prepare("SELECT fund_code FROM funds WHERE id = ?");
                    $old_fund->execute([$old_value ?: 0]);
                    $old_fund_code = $old_fund->fetchColumn() ?: 'None';
                    
                    $new_fund = $conn->prepare("SELECT fund_code FROM funds WHERE id = ?");
                    $new_fund->execute([$new_value ?: 0]);
                    $new_fund_code = $new_fund->fetchColumn() ?: 'None';
                    
                    $changes[] = "$label: changed from '$old_fund_code' to '$new_fund_code'";
                }
                elseif (in_array($field, ['obligation', 'adjustment', 'adjusted_obligation']) && 
                        floatval($old_value) != floatval($new_value)) {
                    $changes[] = "$label: changed from ₱" . number_format(floatval($old_value), 2) . " to ₱" . number_format(floatval($new_value), 2);
                }
                elseif ($field == 'status' && $old_value != $new_value) {
                    $changes[] = "$label: changed from '$old_value' to '$new_value'";
                }
                elseif (!in_array($field, ['fund_id', 'component_id', 'account_title_id']) && 
                        $old_value != $new_value && 
                        !empty($new_value)) {
                    $changes[] = "$label: changed from '$old_value' to '$new_value'";
                }
            }
            
            // Insert change log if there are changes
            if (!empty($changes)) {
                $log_stmt = $conn->prepare("
                    INSERT INTO transaction_logs (transaction_id, changes, created_at) 
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                ");
                $log_stmt->execute([$id, implode("\n", $changes)]);
                error_log("Changes logged: " . implode("\n", $changes));
            } else {
                error_log("No changes detected for logging");
            }
        }
        
        $conn->commit();
        
        $_SESSION['success'] = "Transaction updated successfully.";
        header("Location: view.php?id=" . $id);
        exit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error updating transaction: " . $e->getMessage();
        error_log("Update error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

// Helper functions
function safe_html($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function safe_number_format($value, $decimals = 2) {
    return number_format($value ?? 0, $decimals);
}

function isSelected($value, $compare) {
    return ($value ?? '') == $compare ? 'selected' : '';
}

// Months array
$months = [
    'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE',
    'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'
];

$unit_options = [
    'InfoACE Unit', 'Institutional Development Unit', 'MEL Unit', 
    'Procurement Unit', 'SES Unit', 'Others'
];

$category_options = ['CO', 'IOC', 'IOC - Donation', 'Trainings'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Transaction - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --light: #f8fafc;
            --dark: #1e293b;
            --border: #e2e8f0;
        }
        
        body {
            background: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h4 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }
        
        .debug-info {
            background: #f1f5f9;
            border-left: 4px solid var(--primary);
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .section-header {
            background: var(--light);
            padding: 10px 15px;
            border-radius: 8px;
            margin: 20px 0 15px 0;
            border-left: 4px solid var(--primary);
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }
        
        .section-header i {
            color: var(--primary);
            margin-right: 8px;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--secondary);
            margin-bottom: 5px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .form-control, .form-select {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.95rem;
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .form-control[readonly] {
            background-color: var(--light);
        }
        
        .input-group-text {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: 8px 0 0 8px;
            color: var(--secondary);
            padding: 8px 12px;
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-danger {
            background: var(--danger);
            border: none;
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 500;
        }
        
        .btn-secondary {
            background: white;
            border: 1px solid var(--border);
            color: var(--dark);
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: var(--light);
        }
        
        .change-log {
            background: var(--light);
            border-radius: 8px;
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border);
        }
        
        .change-log-item {
            padding: 8px;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        
        .change-log-item:last-child {
            border-bottom: none;
        }
        
        .change-log-time {
            color: var(--secondary);
            font-size: 0.75rem;
            margin-bottom: 3px;
        }
        
        .row-custom {
            margin-bottom: 12px;
        }
        
        .col-custom {
            padding: 0 8px;
        }
        
        .current-value {
            font-size: 0.8rem;
            color: var(--secondary);
            margin-top: 2px;
        }
        
        .missing-column-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="main-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-edit me-2" style="color: var(--primary);"></i>Edit Transaction</h4>
                <small class="text-secondary">ASA No: <?php echo safe_html($transaction['asa_no']); ?></small>
            </div>
            <div>
                <a href="index.php" class="btn btn-secondary btn-sm me-2">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
        
        <!-- Missing Column Warning -->
        <?php if (!in_array('category', $existing_columns)): ?>
        <div class="missing-column-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning:</strong> The 'category' column does not exist in your database. 
            Please run this SQL command to add it:
            <code class="d-block mt-2 p-2 bg-white rounded">ALTER TABLE transactions ADD COLUMN category VARCHAR(50);</code>
        </div>
        <?php endif; ?>
        
        <!-- Debug Info - Remove in production -->
        <div class="debug-info">
            <strong>Debug:</strong> 
            <?php if (in_array('category', $existing_columns)): ?>
                Current Category: <span class="badge bg-primary"><?php echo $transaction['category'] ?? 'Not set'; ?></span>
            <?php else: ?>
                Category column not found in database
            <?php endif; ?>
            | I-PLAN 1.1: ₱<?php echo number_format($transaction['iplan_11'] ?? 0, 2); ?>
            | I-PLAN 1.2: ₱<?php echo number_format($transaction['iplan_12'] ?? 0, 2); ?>
        </div>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="transactionForm">
            <!-- Basic Information -->
            <div class="section-header">
                <i class="fas fa-info-circle"></i>BASIC INFORMATION
            </div>
            <div class="row row-custom">
                <div class="col-md-2 col-custom">
                    <label class="form-label">Fund Year</label>
                    <input type="text" class="form-control" value="<?php echo safe_html($transaction['fund_year'] ?? ''); ?>" readonly>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Month No.</label>
                    <input type="text" class="form-control" name="mo_no" value="<?php echo safe_html($transaction['mo_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Month</label>
                    <select class="form-select" name="month">
                        <option value="">Select</option>
                        <?php foreach($months as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo isSelected($transaction['month'] ?? '', $m); ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="date_transaction" value="<?php echo safe_html($transaction['date_transaction'] ?? ''); ?>">
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">ASA No.</label>
                    <input type="text" class="form-control" name="asa_no" value="<?php echo safe_html($transaction['asa_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">PAP</label>
                    <input type="text" class="form-control" name="pap_code" value="<?php echo safe_html($transaction['pap_code'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="row row-custom">
                <div class="col-md-3 col-custom">
                    <label class="form-label">RC</label>
                    <input type="text" class="form-control" name="rc_code" value="<?php echo safe_html($transaction['rc_code'] ?? ''); ?>">
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">ORS No.</label>
                    <input type="text" class="form-control" name="ors_no" value="<?php echo safe_html($transaction['ors_no'] ?? ''); ?>">
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">Fund</label>
                    <select class="form-select select2" name="fund_id">
                        <option value="">Select</option>
                        <?php foreach($funds as $fund): ?>
                        <option value="<?php echo $fund['id']; ?>" <?php echo isSelected($transaction['fund_id'] ?? '', $fund['id']); ?>>
                            <?php echo safe_html($fund['year'] . ' - ' . $fund['fund_code'] . ' - ' . $fund['fund_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Payee -->
            <div class="section-header">
                <i class="fas fa-user"></i>PAYEE
            </div>
            <div class="row row-custom">
                <div class="col-md-12">
                    <input type="text" class="form-control" name="payee" value="<?php echo safe_html($transaction['payee'] ?? ''); ?>" placeholder="Payee name">
                </div>
            </div>
            
            <!-- Component -->
            <div class="section-header">
                <i class="fas fa-puzzle-piece"></i>COMPONENT
            </div>
            <div class="row row-custom">
                <div class="col-md-3 col-custom">
                    <label class="form-label">Component</label>
                    <select class="form-select select2" name="component_id" id="component_id">
                        <option value="">Select</option>
                        <?php foreach($components as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" <?php echo isSelected($transaction['component_id'] ?? '', $comp['id']); ?>>
                            <?php echo safe_html($comp['component_code'] . ' - ' . $comp['component_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Comp No.</label>
                    <input type="text" class="form-control" name="component_no" value="<?php echo safe_html($transaction['component_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Unit</label>
                    <select class="form-select" name="unit" id="unit">
                        <option value="">Select</option>
                        <?php foreach($unit_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo isSelected($transaction['unit'] ?? '', $option); ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-custom" id="unit_other_container" style="display: <?php echo ($transaction['unit'] ?? '') == 'Others' ? 'block' : 'none'; ?>;">
                    <label class="form-label">Specify</label>
                    <input type="text" class="form-control" name="unit_other" value="<?php echo ($transaction['unit'] ?? '') == 'Others' ? safe_html($transaction['unit'] ?? '') : ''; ?>">
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Category</label>
                    <?php if (in_array('category', $existing_columns)): ?>
                        <select class="form-select" name="category" id="category">
                            <option value="">Select</option>
                            <?php foreach($category_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo isSelected($transaction['category'] ?? '', $option); ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="current-value">Current: <?php echo $transaction['category'] ?? 'Not set'; ?></div>
                    <?php else: ?>
                        <input type="text" class="form-control" value="Column missing - run SQL to add" readonly disabled>
                        <input type="hidden" name="category" value="">
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="row row-custom">
                <div class="col-md-3 col-custom">
                    <label class="form-label">Account Title</label>
                    <select class="form-select select2" name="account_title_id" id="account_title_id">
                        <option value="">Select</option>
                        <?php foreach($account_titles as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" data-uacs="<?php echo $acc['uacs_code']; ?>" <?php echo isSelected($transaction['account_title_id'] ?? '', $acc['id']); ?>>
                            <?php echo safe_html($acc['uacs_code'] . ' - ' . $acc['account_title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">UACS Code</label>
                    <input type="text" class="form-control" id="uacs_code" readonly value="<?php echo safe_html($transaction['account_uacs'] ?? $transaction['uacs_code'] ?? ''); ?>">
                </div>
                <div class="col-md-6 col-custom">
                    <label class="form-label">Particulars</label>
                    <input type="text" class="form-control" name="particulars" value="<?php echo safe_html($transaction['particulars'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Financials -->
            <div class="section-header">
                <i class="fas fa-calculator"></i>FINANCIALS
            </div>
            <div class="row row-custom">
                <div class="col-md-2 col-custom">
                    <label class="form-label">Obligation</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="obligation" id="obligation" value="<?php echo $transaction['obligation'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Adjustment</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="adjustment" id="adjustment" value="<?php echo $transaction['adjustment'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Adjusted</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="text" class="form-control" id="adjusted_obligation_display" readonly value="<?php echo safe_number_format($transaction['adjusted_obligation'] ?? 0); ?>">
                    </div>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">GOP</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="gop_amount" value="<?php echo $transaction['gop_amount'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">LP</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="lp_amount" value="<?php echo $transaction['lp_amount'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2 col-custom">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="pending" <?php echo isSelected($transaction['status'] ?? '', 'pending'); ?>>Pending</option>
                        <option value="paid" <?php echo isSelected($transaction['status'] ?? '', 'paid'); ?>>Paid</option>
                        <option value="partial" <?php echo isSelected($transaction['status'] ?? '', 'partial'); ?>>Partial</option>
                    </select>
                </div>
            </div>
            
            <!-- Disbursement -->
            <div class="section-header">
                <i class="fas fa-hand-holding-usd"></i>DISBURSEMENT
            </div>
            <div class="row row-custom">
                <div class="col-md-3 col-custom">
                    <label class="form-label">eNGAS Date</label>
                    <input type="date" class="form-control" name="date_input_engas" value="<?php echo safe_html($transaction['date_input_engas'] ?? ''); ?>">
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">BOX D</label>
                    <input type="text" class="form-control" name="box_d_of_dv" value="<?php echo safe_html($transaction['box_d_of_dv'] ?? ''); ?>">
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">DV Received</label>
                    <input type="text" class="form-control" name="dv_received_for_lddap" value="<?php echo safe_html($transaction['dv_received_for_lddap'] ?? ''); ?>">
                </div>
                <div class="col-md-3 col-custom">
                    <label class="form-label">LDDAP No.</label>
                    <input type="text" class="form-control" name="lddap_no" value="<?php echo safe_html($transaction['lddap_no'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Component Allocations -->
            <div class="section-header">
                <i class="fas fa-chart-pie"></i>ALLOCATIONS
            </div>
            <div class="row row-custom">
                <div class="col-md-3 col-custom">
                    <label class="form-label">I-PLAN</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">1.1</span>
                                <input type="number" step="0.01" class="form-control" name="iplan_11" value="<?php echo $transaction['iplan_11'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['iplan_11'] ?? 0, 2); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">1.2</span>
                                <input type="number" step="0.01" class="form-control" name="iplan_12" value="<?php echo $transaction['iplan_12'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['iplan_12'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-custom">
                    <label class="form-label">I-BUILD</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">2.1</span>
                                <input type="number" step="0.01" class="form-control" name="ibuild_21" value="<?php echo $transaction['ibuild_21'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['ibuild_21'] ?? 0, 2); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">2.2</span>
                                <input type="number" step="0.01" class="form-control" name="ibuild_22" value="<?php echo $transaction['ibuild_22'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['ibuild_22'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-custom">
                    <label class="form-label">I-REAP</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">3.1</span>
                                <input type="number" step="0.01" class="form-control" name="ireap_31" value="<?php echo $transaction['ireap_31'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['ireap_31'] ?? 0, 2); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">3.2</span>
                                <input type="number" step="0.01" class="form-control" name="ireap_32" value="<?php echo $transaction['ireap_32'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['ireap_32'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-custom">
                    <label class="form-label">OTHERS</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">I-SUP</span>
                                <input type="number" step="0.01" class="form-control" name="isupport" value="<?php echo $transaction['isupport'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['isupport'] ?? 0, 2); ?></div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">SRE</span>
                                <input type="number" step="0.01" class="form-control" name="sre" value="<?php echo $transaction['sre'] ?? 0; ?>">
                            </div>
                            <div class="current-value">Current: ₱<?php echo number_format($transaction['sre'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Total Allocations -->
            <div class="row mt-2 mb-3">
                <div class="col-md-12 text-end">
                    <small class="text-secondary">
                        <i class="fas fa-calculator me-1"></i>
                        Total Allocations: ₱<span id="totalAllocations">0.00</span>
                    </small>
                </div>
            </div>
            
            <!-- Change Log -->
            <div class="section-header">
                <i class="fas fa-history"></i>CHANGE LOG
            </div>
            <div class="change-log mb-4">
                <?php if (empty($logs)): ?>
                    <p class="text-muted mb-0 small">No changes recorded</p>
                <?php else: ?>
                    <?php foreach($logs as $log): ?>
                    <div class="change-log-item">
                        <div class="change-log-time">
                            <i class="far fa-clock"></i> <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                        </div>
                        <div class="small"><?php echo nl2br(safe_html($log['changes'])); ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Form Actions -->
            <hr class="my-4">
            <div class="row">
                <div class="col-md-12 text-center">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i>Update
                    </button>
                    <button type="button" class="btn btn-danger px-4" onclick="confirmDelete()">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                    <a href="index.php" class="btn btn-secondary px-4">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Delete Form -->
        <form method="POST" id="deleteForm" style="display: none;">
            <input type="hidden" name="delete_transaction" value="1">
        </form>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this transaction?</p>
                    <p class="text-danger small">
                        <i class="fas fa-info-circle me-1"></i>
                        This action cannot be undone. Make sure this transaction has no disbursements.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="document.getElementById('deleteForm').submit();">
                        <i class="fas fa-trash me-2"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({ width: '100%' });
            
            // Auto-populate UACS code
            $('#account_title_id').change(function() {
                var selected = $(this).find('option:selected');
                var uacs = selected.data('uacs');
                $('#uacs_code').val(uacs || '');
            });
            
            // Calculate adjusted obligation
            function calculateAdjusted() {
                var obligation = parseFloat($('#obligation').val()) || 0;
                var adjustment = parseFloat($('#adjustment').val()) || 0;
                var adjusted = obligation - adjustment;
                $('#adjusted_obligation_display').val(adjusted.toFixed(2));
            }
            
            $('#obligation, #adjustment').on('input', calculateAdjusted);
            
            // Auto-populate component number
            $('#component_id').change(function() {
                var text = $(this).find('option:selected').text();
                if (text && text !== 'Select') {
                    var code = text.split(' - ')[0];
                    $('input[name="component_no"]').val(code);
                }
            });
            
            // Show/hide "Others" input
            $('#unit').change(function() {
                if ($(this).val() === 'Others') {
                    $('#unit_other_container').show();
                } else {
                    $('#unit_other_container').hide();
                    $('input[name="unit_other"]').val('');
                }
            });
            $('#unit').trigger('change');
            
            // Calculate total allocations
            function calculateTotalAllocations() {
                let total = 0;
                $('input[name="iplan_11"], input[name="iplan_12"], input[name="ibuild_21"], input[name="ibuild_22"], input[name="ireap_31"], input[name="ireap_32"], input[name="isupport"], input[name="sre"]').each(function() {
                    total += parseFloat($(this).val()) || 0;
                });
                $('#totalAllocations').text(total.toFixed(2));
            }
            
            $('input[name="iplan_11"], input[name="iplan_12"], input[name="ibuild_21"], input[name="ibuild_22"], input[name="ireap_31"], input[name="ireap_32"], input[name="isupport"], input[name="sre"]').on('input', calculateTotalAllocations);
            calculateTotalAllocations();
            
            // Prevent negative numbers
            $('input[type="number"]').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e') e.preventDefault();
            });
            
            // Log category changes for debugging (if element exists)
            $('#category').change(function() {
                console.log('Category changed to:', $(this).val());
            });
        });
        
        function confirmDelete() {
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>
</body>
</html>