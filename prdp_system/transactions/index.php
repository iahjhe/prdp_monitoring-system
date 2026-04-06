<!-- prdp_system/transactions/index.php -->

<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$fund_id = isset($_GET['fund_id']) ? $_GET['fund_id'] : null;
$component_id = isset($_GET['component_id']) ? $_GET['component_id'] : null;
$month = isset($_GET['month']) ? $_GET['month'] : null;

// Build query
$query = "
    SELECT 
        t.*,
        f.fund_code,
        f.fund_name,
        c.component_code,
        c.component_name,
        a_t.account_title,
        a_t.uacs_code
    FROM transactions t
    JOIN funds f ON t.fund_id = f.id
    JOIN components c ON t.component_id = c.id
    JOIN account_titles a_t ON t.account_title_id = a_t.id
    WHERE 1=1
";

$params = [];

if($fund_id) {
    $query .= " AND t.fund_id = ?";
    $params[] = $fund_id;
}

if($component_id) {
    $query .= " AND t.component_id = ?";
    $params[] = $component_id;
}

if($month) {
    $query .= " AND t.month = ?";
    $params[] = $month;
}

$query .= " ORDER BY t.date_transaction DESC, t.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Get months for filter
$months = $conn->query("SELECT DISTINCT month FROM transactions WHERE month IS NOT NULL ORDER BY 
    CASE month
        WHEN 'JANUARY' THEN 1
        WHEN 'FEBRUARY' THEN 2
        WHEN 'MARCH' THEN 3
        WHEN 'APRIL' THEN 4
        WHEN 'MAY' THEN 5
        WHEN 'JUNE' THEN 6
        WHEN 'JULY' THEN 7
        WHEN 'AUGUST' THEN 8
        WHEN 'SEPTEMBER' THEN 9
        WHEN 'OCTOBER' THEN 10
        WHEN 'NOVEMBER' THEN 11
        WHEN 'DECEMBER' THEN 12
    END")->fetchAll();

// Get funds for filter
$funds = $conn->query("SELECT * FROM funds WHERE status = 'active' ORDER BY fund_code")->fetchAll();

// Get components for filter
$components = $conn->query("SELECT * FROM components ORDER BY component_code")->fetchAll();

// Get summary statistics (minimal - just total count)
$total_count = count($transactions);
$paid_count = 0;
$pending_count = 0;
$total_obligation = 0;

foreach($transactions as $t) {
    $total_obligation += $t['obligation'] ?? 0;
    if (($t['status'] ?? '') == 'paid') $paid_count++;
    if (($t['status'] ?? '') == 'pending') $pending_count++;
}

// Helper function to safely handle null values
function safe_html($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function to safely format numbers
function safe_number_format($value, $decimals = 2) {
    return number_format($value ?? 0, $decimals);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border);
        }
        
        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }
        
        .page-header p {
            color: var(--secondary);
            margin: 5px 0 0;
            font-size: 0.95rem;
        }
        
        .btn-header {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        
        .btn-info {
            background: white;
            color: var(--dark);
            border: 1px solid var(--border);
        }
        
        .btn-info:hover {
            background: var(--light);
            border-color: var(--secondary);
        }
        
        /* Stats Bar - Minimal */
        .stats-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
            display: flex;
            gap: 30px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .stat-icon.primary { background: #e0f2fe; color: var(--primary); }
        .stat-icon.success { background: #dcfce7; color: var(--success); }
        .stat-icon.warning { background: #fff3cd; color: var(--warning); }
        
        .stat-info h4 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            line-height: 1.2;
        }
        
        .stat-info small {
            color: var(--secondary);
            font-size: 0.85rem;
        }
        
        /* Filter Section - Compact */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
        }
        
        .filter-section h5 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-section h5 i {
            color: var(--primary);
        }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--secondary);
            margin-bottom: 5px;
        }
        
        .form-select, .form-control {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.95rem;
            background: white;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .btn-apply {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 500;
            width: 100%;
            transition: all 0.2s;
        }
        
        .btn-apply:hover {
            background: var(--primary-dark);
        }
        
        /* Table Card */
        .table-card {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
        }
        
        .table-header h5 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .record-badge {
            background: var(--light);
            color: var(--secondary);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Table Styles */
        .table {
            margin: 0;
            font-size: 0.95rem;
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--secondary);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 15px 12px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 15px 12px;
            vertical-align: middle;
            color: var(--dark);
            border-bottom: 1px solid #f1f5f9;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Badge Styles */
        .badge-code {
            background: #f1f5f9;
            color: var(--secondary);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge-fund {
            background: #dbeafe;
            color: var(--primary-dark);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-paid {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-partial {
            background: #cffafe;
            color: #0891b2;
        }
        
        /* Amount Styles */
        .amount {
            font-weight: 600;
        }
        
        .amount-positive {
            color: #059669;
        }
        
        .amount-negative {
            color: var(--danger);
        }
        
        /* Action Buttons */
        .action-group {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.2s;
            color: white;
        }
        
        .btn-view {
            background: var(--primary);
        }
        
        .btn-view:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-disburse {
            background: var(--success);
        }
        
        .btn-disburse:hover {
            background: #0d9488;
            transform: translateY(-1px);
        }
        
        /* DataTable Customization */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            padding: 15px 20px;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 12px;
            margin-left: 10px;
        }
        
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 15px 20px;
        }
        
        .dataTables_paginate .paginate_button {
            border-radius: 6px !important;
            margin: 0 3px !important;
            padding: 5px 12px !important;
        }
        
        .dataTables_paginate .paginate_button.current {
            background: var(--primary) !important;
            color: white !important;
            border: none !important;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #cbd5e1;
        }
        
        .empty-state h5 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .table {
                font-size: 0.85rem;
            }
            
            .stats-bar {
                flex-wrap: wrap;
                gap: 15px;
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
                <h2><i class="fas fa-exchange-alt me-2" style="color: var(--primary);"></i>Transactions</h2>
                <p>Manage and track all financial transactions</p>
            </div>
            <div>
                <a href="create.php" class="btn-header btn-primary me-2">
                    <i class="fas fa-plus-circle me-2"></i>New Transaction
                </a>
                <a href="import.php" class="btn-header btn-info">
                    <i class="fas fa-file-import me-2"></i>Import
                </a>
            </div>
        </div>
        
        <!-- Minimal Stats Bar
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-icon primary">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-info">
                    <h4><?php echo $total_count; ?></h4>
                    <small>Total Transactions</small>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h4><?php echo $paid_count; ?></h4>
                    <small>Paid</small>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h4><?php echo $pending_count; ?></h4>
                    <small>Pending</small>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-icon primary">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info">
                    <h4>₱<?php echo safe_number_format($total_obligation / 1000000, 1); ?>M</h4>
                    <small>Total Value</small>
                </div>
            </div>
        </div> -->
        
        <!-- Compact Filters -->
        <div class="filter-section">
            <h5>
                <i class="fas fa-filter"></i>Filter Transactions
                <?php if($fund_id || $component_id || $month): ?>
                    <span class="record-badge">Filters Applied</span>
                <?php endif; ?>
            </h5>
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Fund</label>
                    <select class="form-select" name="fund_id">
                        <option value="">All Funds</option>
                        <?php foreach($funds as $fund): ?>
                        <option value="<?php echo $fund['id']; ?>" <?php echo $fund_id == $fund['id'] ? 'selected' : ''; ?>>
                            <?php echo $fund['fund_code']; ?> - <?php echo $fund['fund_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Component</label>
                    <select class="form-select" name="component_id">
                        <option value="">All Components</option>
                        <?php foreach($components as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" <?php echo $component_id == $comp['id'] ? 'selected' : ''; ?>>
                            <?php echo $comp['component_code']; ?> - <?php echo $comp['component_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <select class="form-select" name="month">
                        <option value="">All Months</option>
                        <?php foreach($months as $m): ?>
                        <option value="<?php echo $m['month']; ?>" <?php echo $month == $m['month'] ? 'selected' : ''; ?>>
                            <?php echo $m['month']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn-apply">
                        <i class="fas fa-search me-2"></i>Apply
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Transactions Table -->
        <div class="table-card">
            <div class="table-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-list"></i>Transaction List</h5>
                <span class="record-badge"><?php echo $total_count; ?> records</span>
            </div>
            <div class="p-3">
                <table class="table" id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>ASA No.</th>
                            <th>Fund</th>
                            <th>Component</th>
                            <th>Payee</th>
                            <th>Account</th>
                            <th>Obligation</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transactions as $trans): ?>
                        <tr>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($trans['date_transaction'])); ?></div>
                                <small class="text-secondary"><?php echo $trans['month']; ?></small>
                            </td>
                            <td>
                                <span class="badge-code"><?php echo $trans['asa_no']; ?></span>
                            </td>
                            <td>
                                <span class="badge-fund"><?php echo $trans['fund_code']; ?></span>
                            </td>
                            <td>
                                <span class="badge-code"><?php echo $trans['component_code']; ?></span>
                            </td>
                            <td>
                                <span title="<?php echo $trans['payee']; ?>">
                                    <?php echo substr($trans['payee'] ?? '', 0, 20); ?>
                                    <?php if(strlen($trans['payee'] ?? '') > 20): ?>…<?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <span title="<?php echo $trans['account_title']; ?>">
                                    <?php echo substr($trans['account_title'], 0, 15); ?>…
                                </span>
                            </td>
                            <td class="amount <?php echo ($trans['unpaid_obligation'] ?? 0) > 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                ₱<?php echo number_format($trans['obligation'], 0); ?>
                            </td>
                            <td>
                                <?php 
                                $status = $trans['status'] ?? 'pending';
                                $status_class = match($status) {
                                    'paid' => 'status-paid',
                                    'partial' => 'status-partial',
                                    'pending' => 'status-pending',
                                    default => 'status-pending'
                                };
                                ?>
                                <span class="badge-status <?php echo $status_class; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a href="view.php?id=<?php echo $trans['id']; ?>" class="btn-action btn-view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="disbursements.php?transaction_id=<?php echo $trans['id']; ?>" 
                                       class="btn-action btn-disburse" title="Disbursements">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <h5>No Transactions Found</h5>
                                    <p>Get started by creating your first transaction</p>
                                    <a href="create.php" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-2"></i>New Transaction
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#transactionsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: {
                    search: "Search:",
                    searchPlaceholder: "Filter records...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: '«',
                        previous: '‹',
                        next: '›',
                        last: '»'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [8] }
                ],
                initComplete: function() {
                    $('.dataTables_filter input').attr('placeholder', 'Search...');
                }
            });
        });
    </script>
</body>
</html>