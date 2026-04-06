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

// Get disbursements for this transaction
$disbursements = [];
$disbursement_stmt = $conn->prepare("
    SELECT * FROM disbursements 
    WHERE transaction_id = ? 
    ORDER BY month, payment_date, created_at
");
$disbursement_stmt->execute([$id]);
$disbursements = $disbursement_stmt->fetchAll();

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

// Handle disbursement operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add new disbursement
    if (isset($_POST['add_disbursement'])) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO disbursements (
                    transaction_id, month, amount, pt_vat, ewt, net_amount, 
                    check_no, check_date, payment_date, remarks, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $amount = floatval($_POST['amount'] ?? 0);
            $pt_vat = floatval($_POST['pt_vat'] ?? 0);
            $ewt = floatval($_POST['ewt'] ?? 0);
            $net_amount = $amount - $pt_vat - $ewt;
            
            $stmt->execute([
                $id,
                $_POST['month'] ?? date('F'),
                $amount,
                $pt_vat,
                $ewt,
                $net_amount,
                $_POST['check_no'] ?? null,
                $_POST['check_date'] ?? null,
                $_POST['payment_date'] ?? null,
                $_POST['remarks'] ?? null,
                $_POST['status'] ?? 'completed'
            ]);
            
            // Update transaction status and paid amount
            updateTransactionPaidStatus($conn, $id);
            
            $_SESSION['success'] = "Disbursement added successfully.";
        } catch(Exception $e) {
            $_SESSION['error'] = "Error adding disbursement: " . $e->getMessage();
        }
        header("Location: view.php?id=" . $id);
        exit();
    }
    
    // Update disbursement
    if (isset($_POST['update_disbursement'])) {
        try {
            $disbursement_id = $_POST['disbursement_id'];
            $amount = floatval($_POST['amount'] ?? 0);
            $pt_vat = floatval($_POST['pt_vat'] ?? 0);
            $ewt = floatval($_POST['ewt'] ?? 0);
            $net_amount = $amount - $pt_vat - $ewt;
            
            $stmt = $conn->prepare("
                UPDATE disbursements SET
                    month = ?,
                    amount = ?,
                    pt_vat = ?,
                    ewt = ?,
                    net_amount = ?,
                    check_no = ?,
                    check_date = ?,
                    payment_date = ?,
                    remarks = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND transaction_id = ?
            ");
            
            $stmt->execute([
                $_POST['month'] ?? date('F'),
                $amount,
                $pt_vat,
                $ewt,
                $net_amount,
                $_POST['check_no'] ?? null,
                $_POST['check_date'] ?? null,
                $_POST['payment_date'] ?? null,
                $_POST['remarks'] ?? null,
                $_POST['status'] ?? 'completed',
                $disbursement_id,
                $id
            ]);
            
            updateTransactionPaidStatus($conn, $id);
            
            $_SESSION['success'] = "Disbursement updated successfully.";
        } catch(Exception $e) {
            $_SESSION['error'] = "Error updating disbursement: " . $e->getMessage();
        }
        header("Location: view.php?id=" . $id);
        exit();
    }
    
    // Delete disbursement
    if (isset($_POST['delete_disbursement'])) {
        try {
            $disbursement_id = $_POST['disbursement_id'];
            $stmt = $conn->prepare("DELETE FROM disbursements WHERE id = ? AND transaction_id = ?");
            $stmt->execute([$disbursement_id, $id]);
            
            updateTransactionPaidStatus($conn, $id);
            
            $_SESSION['success'] = "Disbursement deleted successfully.";
        } catch(Exception $e) {
            $_SESSION['error'] = "Error deleting disbursement: " . $e->getMessage();
        }
        header("Location: view.php?id=" . $id);
        exit();
    }
    
    // Delete transaction
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
    
    // Update transaction details
    if (isset($_POST['update_transaction'])) {
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
                'sre' => !empty($_POST['sre']) ? floatval($_POST['sre']) : 0,
                'category' => !empty($_POST['category']) ? $_POST['category'] : null
            ];
            
            // Only add mo_no if it exists
            if (in_array('mo_no', $existing_columns)) {
                $field_mappings['mo_no'] = !empty($_POST['mo_no']) ? $_POST['mo_no'] : null;
            }
            
            // Build the UPDATE query - include all fields that exist in database
            foreach ($field_mappings as $field => $value) {
                if (in_array($field, $existing_columns)) {
                    $update_fields[] = "$field = :$field";
                    $params[":$field"] = $value;
                }
            }
            
            // Add updated_at
            $update_fields[] = "updated_at = CURRENT_TIMESTAMP";
            
            // Create and execute the dynamic UPDATE query
            $sql = "UPDATE transactions SET " . implode(', ', $update_fields) . " WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute($params);
            
            // Log the changes if transaction_logs table exists
            $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='transaction_logs'");
            if ($table_check->fetch()) {
                $changes = [];
                
                // Compare category if it exists
                if (in_array('category', $existing_columns)) {
                    if (($old_values['category'] ?? '') != ($_POST['category'] ?? '')) {
                        $old_cat = ($old_values['category'] ?? 'empty');
                        $new_cat = ($_POST['category'] ?? 'empty');
                        $changes[] = "Category: changed from '$old_cat' to '$new_cat'";
                    }
                }
                
                // Compare allocation values
                $allocation_fields = ['iplan_11', 'iplan_12', 'ibuild_21', 'ibuild_22', 'ireap_31', 'ireap_32', 'isupport', 'sre'];
                $allocation_labels = ['I-PLAN 1.1', 'I-PLAN 1.2', 'I-BUILD 2.1', 'I-BUILD 2.2', 'I-REAP 3.1', 'I-REAP 3.2', 'I-SUPPORT', 'SRE'];
                
                foreach ($allocation_fields as $index => $field) {
                    if (($old_values[$field] ?? 0) != floatval($_POST[$field] ?? 0)) {
                        $changes[] = $allocation_labels[$index] . ": changed from ₱" . number_format($old_values[$field] ?? 0, 2) . " to ₱" . number_format(floatval($_POST[$field] ?? 0), 2);
                    }
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
                }
            }
            
            $conn->commit();
            $_SESSION['success'] = "Transaction updated successfully.";
            header("Location: view.php?id=" . $id);
            exit();
            
        } catch(Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error updating transaction: " . $e->getMessage();
        }
    }
}

// Function to update transaction paid status based on disbursements
function updateTransactionPaidStatus($conn, $transaction_id) {
    // Get total paid amount
    $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(net_amount), 0) as total_paid FROM disbursements WHERE transaction_id = ?");
    $paid_stmt->execute([$transaction_id]);
    $total_paid = $paid_stmt->fetch(PDO::FETCH_ASSOC)['total_paid'];
    
    // Get transaction obligation
    $trans_stmt = $conn->prepare("SELECT adjusted_obligation FROM transactions WHERE id = ?");
    $trans_stmt->execute([$transaction_id]);
    $obligation = $trans_stmt->fetch(PDO::FETCH_ASSOC)['adjusted_obligation'] ?? 0;
    
    // Determine status
    if ($total_paid >= $obligation) {
        $status = 'paid';
        $unpaid = 0;
    } elseif ($total_paid > 0) {
        $status = 'partial';
        $unpaid = $obligation - $total_paid;
    } else {
        $status = 'pending';
        $unpaid = $obligation;
    }
    
    // Update transaction
    $update_stmt = $conn->prepare("
        UPDATE transactions 
        SET status = ?, unpaid_obligation = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $update_stmt->execute([$status, $unpaid, $transaction_id]);
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

$disbursement_statuses = ['pending', 'completed', 'cancelled'];
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
            --info: #3b82f6;
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
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .summary-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--secondary);
            letter-spacing: 0.5px;
        }
        
        .summary-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 5px;
        }
        
        .summary-card .value.positive {
            color: var(--success);
        }
        
        .summary-card .value.warning {
            color: var(--warning);
        }
        
        .summary-card .value.danger {
            color: var(--danger);
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
        
        .input-group-text {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: 8px 0 0 8px;
            color: var(--secondary);
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            border: none;
        }
        
        .btn-warning {
            background: var(--warning);
            border: none;
            color: white;
        }
        
        .table-disbursements {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        
        .table-disbursements th {
            background: var(--light);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px;
        }
        
        .table-disbursements td {
            padding: 10px 12px;
            vertical-align: middle;
            font-size: 0.9rem;
        }
        
        .badge-paid {
            background: var(--success);
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        .badge-partial {
            background: var(--warning);
            color: white;
        }
        
        .badge-pending {
            background: var(--secondary);
            color: white;
        }
        
        .modal-content {
            border-radius: 16px;
            border: none;
        }
        
        .modal-header {
            border-bottom: 1px solid var(--border);
            background: var(--light);
            border-radius: 16px 16px 0 0;
        }
        
        .tax-row {
            background: #fef3c7;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .current-value {
            font-size: 0.7rem;
            color: var(--secondary);
            margin-top: 2px;
        }
        
        .debug-info {
            background: #f1f5f9;
            border-left: 4px solid var(--primary);
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
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
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <?php
        $total_paid = array_sum(array_column($disbursements, 'net_amount'));
        $total_pt_vat = array_sum(array_column($disbursements, 'pt_vat'));
        $total_ewt = array_sum(array_column($disbursements, 'ewt'));
        $remaining = ($transaction['adjusted_obligation'] ?? 0) - $total_paid;
        ?>
        
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Obligation</div>
                <div class="value">₱<?php echo number_format($transaction['adjusted_obligation'] ?? 0, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Disbursed</div>
                <div class="value positive">₱<?php echo number_format($total_paid, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total PT/VAT</div>
                <div class="value warning">₱<?php echo number_format($total_pt_vat, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total EWT</div>
                <div class="value warning">₱<?php echo number_format($total_ewt, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Remaining Balance</div>
                <div class="value <?php echo $remaining > 0 ? 'danger' : 'positive'; ?>">
                    ₱<?php echo number_format($remaining, 2); ?>
                </div>
            </div>
        </div>
        
        <!-- Alerts -->
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Transaction Form -->
        <form method="POST" action="" id="transactionForm">
            <input type="hidden" name="update_transaction" value="1">
            
            <!-- Basic Information -->
            <div class="section-header">
                <i class="fas fa-info-circle"></i>BASIC INFORMATION
            </div>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Fund Year</label>
                    <input type="text" class="form-control" value="<?php echo safe_html($transaction['fund_year'] ?? ''); ?>" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Month No.</label>
                    <input type="text" class="form-control" name="mo_no" value="<?php echo safe_html($transaction['mo_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Month</label>
                    <select class="form-select" name="month">
                        <option value="">Select</option>
                        <?php foreach($months as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo isSelected($transaction['month'] ?? '', $m); ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="date_transaction" value="<?php echo safe_html($transaction['date_transaction'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ASA No.</label>
                    <input type="text" class="form-control" name="asa_no" value="<?php echo safe_html($transaction['asa_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">PAP</label>
                    <input type="text" class="form-control" name="pap_code" value="<?php echo safe_html($transaction['pap_code'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label">RC</label>
                    <input type="text" class="form-control" name="rc_code" value="<?php echo safe_html($transaction['rc_code'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ORS No.</label>
                    <input type="text" class="form-control" name="ors_no" value="<?php echo safe_html($transaction['ors_no'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
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
            <div class="row">
                <div class="col-md-12">
                    <input type="text" class="form-control" name="payee" value="<?php echo safe_html($transaction['payee'] ?? ''); ?>" placeholder="Payee name">
                </div>
            </div>
            
            <!-- Component -->
            <div class="section-header">
                <i class="fas fa-puzzle-piece"></i>COMPONENT
            </div>
            <div class="row g-3">
                <div class="col-md-3">
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
                <div class="col-md-2">
                    <label class="form-label">Comp No.</label>
                    <input type="text" class="form-control" name="component_no" value="<?php echo safe_html($transaction['component_no'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Unit</label>
                    <select class="form-select" name="unit" id="unit">
                        <option value="">Select</option>
                        <?php foreach($unit_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo isSelected($transaction['unit'] ?? '', $option); ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2" id="unit_other_container" style="display: <?php echo ($transaction['unit'] ?? '') == 'Others' ? 'block' : 'none'; ?>;">
                    <label class="form-label">Specify</label>
                    <input type="text" class="form-control" name="unit_other" value="<?php echo ($transaction['unit'] ?? '') == 'Others' ? safe_html($transaction['unit'] ?? '') : ''; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <?php if (in_array('category', $existing_columns)): ?>
                        <select class="form-select" name="category" id="category">
                            <option value="">Select</option>
                            <?php foreach($category_options as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo isSelected($transaction['category'] ?? '', $option); ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="Column missing" readonly disabled>
                        <input type="hidden" name="category" value="">
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="row g-3 mt-1">
                <div class="col-md-3">
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
                <div class="col-md-3">
                    <label class="form-label">UACS Code</label>
                    <input type="text" class="form-control" id="uacs_code" readonly value="<?php echo safe_html($transaction['account_uacs'] ?? $transaction['uacs_code'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Particulars</label>
                    <input type="text" class="form-control" name="particulars" value="<?php echo safe_html($transaction['particulars'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Financials -->
            <div class="section-header">
                <i class="fas fa-calculator"></i>FINANCIALS
            </div>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Obligation</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="obligation" id="obligation" value="<?php echo $transaction['obligation'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Adjustment</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="adjustment" id="adjustment" value="<?php echo $transaction['adjustment'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Adjusted</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="text" class="form-control" id="adjusted_obligation_display" readonly value="<?php echo safe_number_format($transaction['adjusted_obligation'] ?? 0); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">GOP</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="gop_amount" value="<?php echo $transaction['gop_amount'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">LP</label>
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" class="form-control" name="lp_amount" value="<?php echo $transaction['lp_amount'] ?? 0; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="pending" <?php echo isSelected($transaction['status'] ?? '', 'pending'); ?>>Pending</option>
                        <option value="partial" <?php echo isSelected($transaction['status'] ?? '', 'partial'); ?>>Partial</option>
                        <option value="paid" <?php echo isSelected($transaction['status'] ?? '', 'paid'); ?>>Paid</option>
                    </select>
                </div>
            </div>
            
            <!-- Disbursement Info -->
            <div class="section-header">
                <i class="fas fa-hand-holding-usd"></i>DISBURSEMENT INFO
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">eNGAS Date</label>
                    <input type="date" class="form-control" name="date_input_engas" value="<?php echo safe_html($transaction['date_input_engas'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">BOX D</label>
                    <input type="text" class="form-control" name="box_d_of_dv" value="<?php echo safe_html($transaction['box_d_of_dv'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">DV Received</label>
                    <input type="text" class="form-control" name="dv_received_for_lddap" value="<?php echo safe_html($transaction['dv_received_for_lddap'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">LDDAP No.</label>
                    <input type="text" class="form-control" name="lddap_no" value="<?php echo safe_html($transaction['lddap_no'] ?? ''); ?>">
                </div>
            </div>
            
            <!-- Component Allocations -->
            <div class="section-header">
                <i class="fas fa-chart-pie"></i>ALLOCATIONS
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">I-PLAN</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">1.1</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="iplan_11" value="<?php echo $transaction['iplan_11'] ?? 0; ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">1.2</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="iplan_12" value="<?php echo $transaction['iplan_12'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">I-BUILD</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">2.1</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="ibuild_21" value="<?php echo $transaction['ibuild_21'] ?? 0; ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">2.2</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="ibuild_22" value="<?php echo $transaction['ibuild_22'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">I-REAP</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">3.1</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="ireap_31" value="<?php echo $transaction['ireap_31'] ?? 0; ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">3.2</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="ireap_32" value="<?php echo $transaction['ireap_32'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">OTHERS</label>
                    <div class="row g-1">
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">I-SUP</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="isupport" value="<?php echo $transaction['isupport'] ?? 0; ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">SRE</span>
                                <input type="number" step="0.01" class="form-control allocation-field" name="sre" value="<?php echo $transaction['sre'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-2 mb-3">
                <div class="col-md-12 text-end">
                    <small class="text-secondary">
                        <i class="fas fa-calculator me-1"></i>
                        Total Allocations: ₱<span id="totalAllocations" class="fw-bold">0.00</span>
                    </small>
                </div>
            </div>
            
            <!-- Disbursements Section -->
            <div class="section-header">
                <i class="fas fa-receipt"></i>DISBURSEMENTS / PAYMENTS
                <button type="button" class="btn btn-sm btn-primary float-end" data-bs-toggle="modal" data-bs-target="#addDisbursementModal">
                    <i class="fas fa-plus"></i> Add Payment
                </button>
            </div>
            
            <?php if (empty($disbursements)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> No disbursements recorded yet. Click "Add Payment" to add.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-disbursements">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Gross Amount</th>
                                <th>PT/VAT (5%)</th>
                                <th>EWT</th>
                                <th>Net Amount</th>
                                <th>Check No.</th>
                                <th>Payment Date</th>
                                <th>Status</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($disbursements as $disb): ?>
                            <tr>
                                <td><?php echo safe_html($disb['month']); ?></td>
                                <td class="text-end">₱<?php echo number_format($disb['amount'], 2); ?></td>
                                <td class="text-end text-danger">₱<?php echo number_format($disb['pt_vat'], 2); ?></td>
                                <td class="text-end text-danger">₱<?php echo number_format($disb['ewt'], 2); ?></td>
                                <td class="text-end fw-bold">₱<?php echo number_format($disb['net_amount'], 2); ?></td>
                                <td><?php echo safe_html($disb['check_no']); ?></td>
                                <td><?php echo safe_html($disb['payment_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $disb['status'] == 'completed' ? 'success' : ($disb['status'] == 'pending' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo ucfirst($disb['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-disbursement" 
                                            data-id="<?php echo $disb['id']; ?>"
                                            data-month="<?php echo $disb['month']; ?>"
                                            data-amount="<?php echo $disb['amount']; ?>"
                                            data-pt_vat="<?php echo $disb['pt_vat']; ?>"
                                            data-ewt="<?php echo $disb['ewt']; ?>"
                                            data-check_no="<?php echo $disb['check_no']; ?>"
                                            data-check_date="<?php echo $disb['check_date']; ?>"
                                            data-payment_date="<?php echo $disb['payment_date']; ?>"
                                            data-remarks="<?php echo $disb['remarks']; ?>"
                                            data-status="<?php echo $disb['status']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-disbursement"
                                            data-id="<?php echo $disb['id']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td>TOTAL</td>
                                <td class="text-end">₱<?php echo number_format(array_sum(array_column($disbursements, 'amount')), 2); ?></td>
                                <td class="text-end">₱<?php echo number_format(array_sum(array_column($disbursements, 'pt_vat')), 2); ?></td>
                                <td class="text-end">₱<?php echo number_format(array_sum(array_column($disbursements, 'ewt')), 2); ?></td>
                                <td class="text-end">₱<?php echo number_format(array_sum(array_column($disbursements, 'net_amount')), 2); ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Change Log -->
            <?php if (!empty($logs)): ?>
            <div class="section-header">
                <i class="fas fa-history"></i>CHANGE LOG
            </div>
            <div class="change-log mb-4">
                <?php foreach($logs as $log): ?>
                <div class="change-log-item">
                    <div class="change-log-time">
                        <i class="far fa-clock"></i> <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                    </div>
                    <div class="small"><?php echo nl2br(safe_html($log['changes'])); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Form Actions -->
            <hr class="my-4">
            <div class="row">
                <div class="col-md-12 text-center">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i>Update Transaction
                    </button>
                    <button type="button" class="btn btn-danger px-4" onclick="confirmDelete()">
                        <i class="fas fa-trash me-2"></i>Delete Transaction
                    </button>
                    <a href="index.php" class="btn btn-secondary px-4">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
        
        <!-- Delete Transaction Form -->
        <form method="POST" id="deleteForm" style="display: none;">
            <input type="hidden" name="delete_transaction" value="1">
        </form>
    </div>
    
    <!-- Add Disbursement Modal -->
    <div class="modal fade" id="addDisbursementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="add_disbursement" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Disbursement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month" required>
                                <?php foreach($months as $m): ?>
                                <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gross Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" class="form-control gross-amount" name="amount" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">PT/VAT (5%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control pt-vat" name="pt_vat" value="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">EWT</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control ewt" name="ewt" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="tax-row mt-2">
                            <div class="row">
                                <div class="col-6">
                                    <strong>Net Amount:</strong>
                                </div>
                                <div class="col-6 text-end">
                                    <strong>₱<span class="net-amount-display">0.00</span></strong>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Check No.</label>
                                <input type="text" class="form-control" name="check_no">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Check Date</label>
                                <input type="date" class="form-control" name="check_date">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input type="date" class="form-control" name="payment_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="completed">Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Disbursement Modal -->
    <div class="modal fade" id="editDisbursementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="update_disbursement" value="1">
                    <input type="hidden" name="disbursement_id" id="edit_disbursement_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Disbursement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month" id="edit_month" required>
                                <?php foreach($months as $m): ?>
                                <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gross Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" class="form-control edit-gross-amount" name="amount" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">PT/VAT (5%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control edit-pt-vat" name="pt_vat">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">EWT</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" class="form-control edit-ewt" name="ewt">
                                </div>
                            </div>
                        </div>
                        <div class="tax-row mt-2">
                            <div class="row">
                                <div class="col-6">
                                    <strong>Net Amount:</strong>
                                </div>
                                <div class="col-6 text-end">
                                    <strong>₱<span class="edit-net-amount-display">0.00</span></strong>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Check No.</label>
                                <input type="text" class="form-control" name="check_no" id="edit_check_no">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Check Date</label>
                                <input type="date" class="form-control" name="check_date" id="edit_check_date">
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Payment Date</label>
                                <input type="date" class="form-control" name="payment_date" id="edit_payment_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="completed">Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" id="edit_remarks" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Disbursement Modal -->
    <div class="modal fade" id="deleteDisbursementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="delete_disbursement" value="1">
                    <input type="hidden" name="disbursement_id" id="delete_disbursement_id">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this disbursement?</p>
                        <p class="text-danger small">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Transaction Modal -->
    <div class="modal fade" id="deleteTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete Transaction</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this transaction?</p>
                    <p class="text-danger small">
                        <i class="fas fa-info-circle me-1"></i>
                        This will also delete all associated disbursements. This action cannot be undone.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="document.getElementById('deleteForm').submit();">Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
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
                $('.allocation-field').each(function() {
                    let val = parseFloat($(this).val());
                    if (!isNaN(val)) {
                        total += val;
                    }
                });
                $('#totalAllocations').text(total.toFixed(2));
                
                let obligation = parseFloat($('#obligation').val()) || 0;
                if (Math.abs(total - obligation) > 0.01) {
                    $('#totalAllocations').css('color', '#ef4444');
                } else {
                    $('#totalAllocations').css('color', 'inherit');
                }
            }
            
            $('.allocation-field, #obligation').on('input', calculateTotalAllocations);
            calculateTotalAllocations();
            
            // Calculate net amount for add disbursement
            function calculateAddNet() {
                let gross = parseFloat($('.gross-amount').val()) || 0;
                let ptVat = parseFloat($('.pt-vat').val()) || 0;
                let ewt = parseFloat($('.ewt').val()) || 0;
                let net = gross - ptVat - ewt;
                $('.net-amount-display').text(net.toFixed(2));
            }
            
            $('.gross-amount, .pt-vat, .ewt').on('input', calculateAddNet);
            
            // Calculate net amount for edit disbursement
            function calculateEditNet() {
                let gross = parseFloat($('.edit-gross-amount').val()) || 0;
                let ptVat = parseFloat($('.edit-pt-vat').val()) || 0;
                let ewt = parseFloat($('.edit-ewt').val()) || 0;
                let net = gross - ptVat - ewt;
                $('.edit-net-amount-display').text(net.toFixed(2));
            }
            
            $(document).on('input', '.edit-gross-amount, .edit-pt-vat, .edit-ewt', calculateEditNet);
            
            // Edit disbursement button handler
            $('.edit-disbursement').click(function() {
                $('#edit_disbursement_id').val($(this).data('id'));
                $('#edit_month').val($(this).data('month'));
                $('.edit-gross-amount').val($(this).data('amount'));
                $('.edit-pt-vat').val($(this).data('pt_vat'));
                $('.edit-ewt').val($(this).data('ewt'));
                $('#edit_check_no').val($(this).data('check_no'));
                $('#edit_check_date').val($(this).data('check_date'));
                $('#edit_payment_date').val($(this).data('payment_date'));
                $('#edit_remarks').val($(this).data('remarks'));
                $('#edit_status').val($(this).data('status'));
                calculateEditNet();
                $('#editDisbursementModal').modal('show');
            });
            
            // Delete disbursement button handler
            $('.delete-disbursement').click(function() {
                $('#delete_disbursement_id').val($(this).data('id'));
                $('#deleteDisbursementModal').modal('show');
            });
            
            // Prevent negative numbers
            $('input[type="number"]').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e') e.preventDefault();
            });
            
            // Form validation
            $('#transactionForm').on('submit', function(e) {
                let obligation = parseFloat($('#obligation').val()) || 0;
                let total = 0;
                $('.allocation-field').each(function() {
                    total += parseFloat($(this).val()) || 0;
                });
                
                if (Math.abs(total - obligation) > 0.01) {
                    if (!confirm('Warning: Total allocations (₱' + total.toFixed(2) + 
                               ') do not match obligation amount (₱' + obligation.toFixed(2) + 
                               '). Do you want to continue anyway?')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });
        
        function confirmDelete() {
            $('#deleteTransactionModal').modal('show');
        }
    </script>
</body>
</html>