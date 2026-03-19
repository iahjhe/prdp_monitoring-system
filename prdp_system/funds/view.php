<!-- funds/view.php -->

<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get fund ID from URL
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    $_SESSION['error'] = "Fund ID is required";
    header("Location: index.php");
    exit();
}

// Get fund details with year information
$stmt = $conn->prepare("
    SELECT 
        f.*,
        y.year,
        y.description as year_description,
        y.is_active as year_is_active
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.id = ?
");
$stmt->execute([$id]);
$fund = $stmt->fetch();

if (!$fund) {
    $_SESSION['error'] = "Fund not found";
    header("Location: index.php");
    exit();
}

// Get transaction summary for this fund
$transactions_summary = $conn->prepare("
    SELECT 
        COUNT(*) as total_transactions,
        SUM(obligation) as total_obligations,
        SUM(adjusted_obligation) as total_adjusted,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
    FROM transactions 
    WHERE fund_id = ?
");
$transactions_summary->execute([$id]);
$summary = $transactions_summary->fetch();

// Get recent transactions for this fund
$recent_transactions = $conn->prepare("
    SELECT 
        t.*,
        c.component_code,
        a_t.account_title
    FROM transactions t
    LEFT JOIN components c ON t.component_id = c.id
    LEFT JOIN account_titles a_t ON t.account_title_id = a_t.id
    WHERE t.fund_id = ?
    ORDER BY t.date_transaction DESC
    LIMIT 10
");
$recent_transactions->execute([$id]);
$transactions = $recent_transactions->fetchAll();

// Get all years for dropdown
$years = $conn->query("SELECT * FROM years ORDER BY year DESC")->fetchAll();

// Get fund logs
$logs = [];
try {
    // Check if fund_logs table exists
    $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fund_logs'");
    if ($table_check->fetch()) {
        $logs_stmt = $conn->prepare("
            SELECT * FROM fund_logs 
            WHERE fund_id = ? 
            ORDER BY created_at DESC
        ");
        $logs_stmt->execute([$id]);
        $logs = $logs_stmt->fetchAll();
    }
} catch(Exception $e) {
    error_log("Error fetching logs: " . $e->getMessage());
}

// Handle form submission for updates
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();
        
        // Get old values for logging
        $old_values = $fund;
        
        // Get the year from dropdown
        $year_id = $_POST['year_id'];
        
        // Generate fund code from fund name if changed
        $fund_code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['fund_name']));
        $fund_code = substr($fund_code, 0, 15);
        
        // Update fund
        $stmt = $conn->prepare("
            UPDATE funds SET
                year_id = ?,
                fund_code = ?,
                fund_name = ?,
                fund_source = ?,
                allotment = ?,
                obligated = ?,
                disbursed = ?,
                balance = ?,
                status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        // Recalculate balance if allotment changed
        $new_allotment = $_POST['allotment'];
        $current_obligated = $fund['obligated'];
        $new_balance = $new_allotment - $current_obligated;
        
        $stmt->execute([
            $year_id,
            $fund_code,
            $_POST['fund_name'],
            $_POST['fund_source'],
            $new_allotment,
            $_POST['obligated'] ?? $fund['obligated'],
            $_POST['disbursed'] ?? $fund['disbursed'],
            $new_balance,
            $_POST['status'],
            $id
        ]);
        
        // Log the changes
        $changes = [];
        
        // Check which fields changed
        if ($old_values['year_id'] != $year_id) {
            $old_year = $conn->prepare("SELECT year FROM years WHERE id = ?");
            $old_year->execute([$old_values['year_id']]);
            $old_year_val = $old_year->fetchColumn();
            
            $new_year = $conn->prepare("SELECT year FROM years WHERE id = ?");
            $new_year->execute([$year_id]);
            $new_year_val = $new_year->fetchColumn();
            
            $changes[] = "Year: changed from {$old_year_val} to {$new_year_val}";
        }
        
        if ($old_values['fund_name'] != $_POST['fund_name']) {
            $changes[] = "Fund Name: changed from '{$old_values['fund_name']}' to '{$_POST['fund_name']}'";
        }
        
        if ($old_values['fund_source'] != $_POST['fund_source']) {
            $changes[] = "Fund Source: changed from '{$old_values['fund_source']}' to '{$_POST['fund_source']}'";
        }
        
        if ($old_values['allotment'] != $new_allotment) {
            $changes[] = "Allotment: changed from ₱" . number_format($old_values['allotment'], 2) . " to ₱" . number_format($new_allotment, 2);
        }
        
        if ($old_values['status'] != $_POST['status']) {
            $changes[] = "Status: changed from '{$old_values['status']}' to '{$_POST['status']}'";
        }
        
        // Insert change log if there are changes
        if (!empty($changes)) {
            $log_stmt = $conn->prepare("
                INSERT INTO fund_logs (fund_id, action, changes, created_at) 
                VALUES (?, 'updated', ?, CURRENT_TIMESTAMP)
            ");
            $log_stmt->execute([$id, implode("\n", $changes)]);
        }
        
        $conn->commit();
        
        $_SESSION['success'] = "Fund updated successfully.";
        header("Location: view.php?id=" . $id);
        exit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error updating fund: " . $e->getMessage();
    }
}

// Helper function to safely handle null values
function safe_html($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function to check if a value should be selected
function isSelected($value, $compare) {
    return ($value ?? '') == $compare ? 'selected' : '';
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    return match($status) {
        'active' => 'success',
        'inactive' => 'secondary',
        'deleted' => 'danger',
        default => 'secondary'
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View/Edit Fund - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            background: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 25px;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 20px 20px 0 0 !important;
            padding: 25px 30px;
            border: none;
        }
        .card-header h4 {
            font-weight: 700;
            margin-bottom: 5px;
            font-size: 1.8rem;
        }
        .card-header.bg-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
        }
        .card-header.bg-info {
            background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%) !important;
        }
        .card-body {
            padding: 30px;
        }
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 20px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 4px rgba(42,82,152,0.1);
            background: white;
        }
        .form-control[readonly] {
            background-color: #e9ecef;
            opacity: 0.8;
        }
        .input-group-text {
            background: #e9ecef;
            border: 2px solid #e9ecef;
            border-radius: 12px 0 0 12px;
            font-weight: 600;
            color: #2c3e50;
            padding: 12px 20px;
            font-size: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            border-radius: 12px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(42,82,152,0.4);
        }
        .btn-secondary {
            border-radius: 12px;
            padding: 12px 30px;
            font-weight: 600;
            background: #6c757d;
            border: none;
        }
        .info-card {
            background: linear-gradient(135deg, #f6f9fc 0%, #edf2f7 100%);
            border-radius: 15px;
            padding: 20px;
            height: 100%;
            border-left: 5px solid #2a5298;
        }
        .info-card h6 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 1rem;
        }
        .info-card .amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }
        .info-card .label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .stat-box {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .stat-box .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3c72;
        }
        .stat-box .label {
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .logs-container {
            max-height: 400px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
        }
        .log-item {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        .log-item:last-child {
            border-bottom: none;
        }
        .log-time {
            color: #6c757d;
            font-size: 0.8rem;
        }
        .log-action {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-right: 10px;
        }
        .log-action.created {
            background-color: #d4edda;
            color: #155724;
        }
        .log-action.updated {
            background-color: #fff3cd;
            color: #856404;
        }
        .log-action.deleted {
            background-color: #f8d7da;
            color: #721c24;
        }
        .log-action.restored {
            background-color: #cce5ff;
            color: #004085;
        }
        .balance-positive {
            color: #28a745;
        }
        .balance-negative {
            color: #dc3545;
        }
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 10px 20px;
            margin-right: 10px;
            border-radius: 30px;
        }
        .nav-tabs .nav-link:hover {
            border: none;
            color: #1e3c72;
            background: #f8f9fa;
        }
        .nav-tabs .nav-link.active {
            border: none;
            color: #1e3c72;
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-md-12">
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
                
                <!-- Navigation -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-coins me-2"></i>Fund Details</h2>
                    <div>
                        <a href="index.php" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left"></i> Back to Funds
                        </a>
                        <a href="../allotments/index.php?fund_id=<?php echo $id; ?>" class="btn btn-success">
                            <i class="fas fa-file-invoice"></i> View Allotments
                        </a>
                    </div>
                </div>
                
                <!-- Fund Information Card -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Fund</h4>
                            <small>Last updated: <?php echo date('M d, Y g:i A', strtotime($fund['updated_at'] ?? $fund['created_at'])); ?></small>
                        </div>
                        <span class="badge bg-light text-dark p-3">
                            <i class="fas fa-calendar me-1"></i> FY <?php echo $fund['year']; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="fundForm">
                            
                            <div class="row">
                                <!-- Left Column - Main Info -->
                                <div class="col-md-8">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-calendar-alt me-2 text-primary"></i>FISCAL YEAR
                                            </label>
                                            <select class="form-select" name="year_id" required>
                                                <option value="">Select Year</option>
                                                <?php foreach($years as $year): ?>
                                                <option value="<?php echo $year['id']; ?>" 
                                                    <?php echo isSelected($fund['year_id'], $year['id']); ?>>
                                                    <?php echo $year['year']; ?> - <?php echo $year['description']; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-tag me-2 text-success"></i>FUND NAME
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="fund_name" 
                                                   value="<?php echo safe_html($fund['fund_name']); ?>" 
                                                   required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-code me-2 text-info"></i>FUND CODE
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   value="<?php echo safe_html($fund['fund_code']); ?>" 
                                                   readonly>
                                            <small class="text-muted">Auto-generated from fund name</small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-database me-2 text-warning"></i>FUND SOURCE
                                            </label>
                                            <select class="form-select" name="fund_source">
                                                <option value="GOP" <?php echo isSelected($fund['fund_source'], 'GOP'); ?>>GOP</option>
                                                <option value="LP" <?php echo isSelected($fund['fund_source'], 'LP'); ?>>LP</option>
                                                <option value="WB" <?php echo isSelected($fund['fund_source'], 'WB'); ?>>World Bank</option>
                                                <option value="ADB" <?php echo isSelected($fund['fund_source'], 'ADB'); ?>>ADB</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-money-bill-wave me-2 text-success"></i>ALLOTMENT (₱)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" 
                                                       step="0.01" 
                                                       class="form-control" 
                                                       name="allotment" 
                                                       id="allotment"
                                                       value="<?php echo $fund['allotment']; ?>" 
                                                       required>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-hand-holding-usd me-2 text-danger"></i>OBLIGATED (₱)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" 
                                                       step="0.01" 
                                                       class="form-control" 
                                                       name="obligated" 
                                                       id="obligated"
                                                       value="<?php echo $fund['obligated']; ?>" 
                                                       readonly>
                                            </div>
                                            <small class="text-muted">Auto-calculated from transactions</small>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-check-circle me-2 text-info"></i>BALANCE (₱)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" 
                                                       step="0.01" 
                                                       class="form-control" 
                                                       id="balance_display"
                                                       value="<?php echo $fund['balance']; ?>" 
                                                       readonly>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-flag me-2 text-primary"></i>STATUS
                                            </label>
                                            <select class="form-select" name="status">
                                                <option value="active" <?php echo isSelected($fund['status'], 'active'); ?>>Active</option>
                                                <option value="inactive" <?php echo isSelected($fund['status'], 'inactive'); ?>>Inactive</option>
                                                <option value="deleted" <?php echo isSelected($fund['status'], 'deleted'); ?>>Deleted</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="fas fa-clock me-2 text-secondary"></i>CREATED
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   value="<?php echo date('M d, Y g:i A', strtotime($fund['created_at'])); ?>" 
                                                   readonly>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Right Column - Summary -->
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <h6><i class="fas fa-chart-pie me-2"></i>FINANCIAL SUMMARY</h6>
                                        <div class="mb-4">
                                            <div class="amount <?php echo $fund['balance'] < 0 ? 'balance-negative' : 'balance-positive'; ?>">
                                                ₱<?php echo number_format($fund['balance'], 2); ?>
                                            </div>
                                            <div class="label">Current Balance</div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="stat-box">
                                                    <div class="number">₱<?php echo number_format($fund['allotment'], 0); ?></div>
                                                    <div class="label">Allotment</div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stat-box">
                                                    <div class="number">₱<?php echo number_format($fund['obligated'], 0); ?></div>
                                                    <div class="label">Obligated</div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stat-box">
                                                    <div class="number">₱<?php echo number_format($fund['disbursed'], 0); ?></div>
                                                    <div class="label">Disbursed</div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stat-box">
                                                    <div class="number"><?php echo $summary['total_transactions'] ?? 0; ?></div>
                                                    <div class="label">Transactions</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <hr class="my-4">
                            <div class="row">
                                <div class="col-md-12 text-center">
                                    <button type="submit" class="btn btn-primary btn-lg px-5">
                                        <i class="fas fa-save me-2"></i>Update Fund
                                    </button>
                                    <a href="index.php" class="btn btn-secondary btn-lg px-5">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                            
                        </form>
                    </div>
                </div>
                
                <!-- Tabs for Transactions and Logs -->
                <ul class="nav nav-tabs" id="myTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" type="button" role="tab">
                            <i class="fas fa-exchange-alt me-2"></i>Recent Transactions
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab">
                            <i class="fas fa-history me-2"></i>Change Logs
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="myTabContent">
                    <!-- Transactions Tab -->
                    <div class="tab-pane fade show active" id="transactions" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Recent Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($transactions)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-folder-open fa-3x mb-3"></i>
                                        <p>No transactions found for this fund.</p>
                                        <a href="../transactions/create.php?fund_id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-plus-circle"></i> Add Transaction
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>ASA No.</th>
                                                    <th>Payee</th>
                                                    <th>Component</th>
                                                    <th>Account Title</th>
                                                    <th>Obligation</th>
                                                    <th>Status</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($transactions as $t): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($t['date_transaction'])); ?></td>
                                                    <td><?php echo safe_html($t['asa_no']); ?></td>
                                                    <td><?php echo safe_html(substr($t['payee'], 0, 20)) . '...'; ?></td>
                                                    <td><?php echo safe_html($t['component_code']); ?></td>
                                                    <td><?php echo safe_html(substr($t['account_title'], 0, 20)) . '...'; ?></td>
                                                    <td>₱<?php echo number_format($t['obligation'], 2); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $t['status'] == 'paid' ? 'success' : ($t['status'] == 'pending' ? 'warning' : 'secondary'); ?>">
                                                            <?php echo ucfirst($t['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="../transactions/view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-info">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-end mt-3">
                                        <a href="../transactions/index.php?fund_id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm">
                                            View All Transactions <i class="fas fa-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Logs Tab -->
                    <div class="tab-pane fade" id="logs" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Change History</h5>
                            </div>
                            <div class="card-body">
                                <div class="logs-container">
                                    <?php if (empty($logs)): ?>
                                        <p class="text-muted text-center py-3">No change logs yet.</p>
                                    <?php else: ?>
                                        <?php foreach($logs as $log): ?>
                                        <div class="log-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <span class="log-action <?php echo $log['action']; ?>">
                                                        <?php echo strtoupper($log['action']); ?>
                                                    </span>
                                                </div>
                                                <small class="log-time">
                                                    <i class="far fa-clock"></i> 
                                                    <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                                                </small>
                                            </div>
                                            <?php if (!empty($log['changes'])): ?>
                                            <div class="mt-2 small text-muted" style="white-space: pre-line;">
                                                <?php echo safe_html($log['changes']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
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
            // Initialize Select2 for better dropdowns
            $('.form-select').select2({
                width: '100%'
            });
            
            // Calculate and update balance when allotment changes
            function updateBalance() {
                const allotment = parseFloat($('#allotment').val()) || 0;
                const obligated = parseFloat($('#obligated').val()) || 0;
                const balance = allotment - obligated;
                
                $('#balance_display').val(balance.toFixed(2));
                
                // Update color based on balance
                if (balance < 0) {
                    $('#balance_display').removeClass('text-success').addClass('text-danger');
                } else {
                    $('#balance_display').removeClass('text-danger').addClass('text-success');
                }
            }
            
            $('#allotment').on('input', updateBalance);
            
            // Initial balance update
            updateBalance();
            
            // Prevent negative values in allotment
            $('#allotment').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e') {
                    e.preventDefault();
                }
            });
            
            // Form validation
            $('#fundForm').on('submit', function(e) {
                const allotment = parseFloat($('#allotment').val());
                
                if (allotment < 0) {
                    e.preventDefault();
                    alert('Allotment amount cannot be negative');
                }
            });
            
            // Auto-generate fund name preview
            $('#fund_name').on('input', function() {
                const name = $(this).val();
                const code = name.replace(/[^a-zA-Z0-9]/g, '').toUpperCase().substring(0, 15);
                $('input[value="<?php echo $fund['fund_code']; ?>"]').val(code);
            });
        });
    </script>
</body>
</html>