<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Handle delete request
if (isset($_GET['delete']) && isset($_GET['id'])) {
    try {
        $conn->beginTransaction();
        
        $id = $_GET['id'];
        
        // Check if fund has transactions
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM transactions WHERE fund_id = ?");
        $check_stmt->execute([$id]);
        $transaction_count = $check_stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            // Soft delete - just update status
            $stmt = $conn->prepare("UPDATE funds SET status = 'deleted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);
            
            // Log the deletion
            $log_stmt = $conn->prepare("
                INSERT INTO fund_logs (fund_id, action, changes, created_at) 
                VALUES (?, 'deleted', 'Fund soft deleted (has transactions)', CURRENT_TIMESTAMP)
            ");
            $log_stmt->execute([$id]);
            
            $_SESSION['success'] = "Fund has been soft deleted.";
        } else {
            // Hard delete if no transactions
            $stmt = $conn->prepare("DELETE FROM funds WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = "Fund has been permanently deleted.";
        }
        
        $conn->commit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error deleting fund: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit();
}

// Handle permanent delete request
if (isset($_GET['permanent_delete']) && isset($_GET['id'])) {
    try {
        $conn->beginTransaction();
        
        $id = $_GET['id'];
        
        // First delete related logs
        $conn->prepare("DELETE FROM fund_logs WHERE fund_id = ?")->execute([$id]);
        
        // Then delete the fund
        $stmt = $conn->prepare("DELETE FROM funds WHERE id = ?");
        $stmt->execute([$id]);
        
        $conn->commit();
        
        $_SESSION['success'] = "Fund has been permanently deleted.";
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error permanently deleting fund: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit();
}

// Handle restore request
if (isset($_GET['restore']) && isset($_GET['id'])) {
    try {
        $conn->beginTransaction();
        
        $id = $_GET['id'];
        
        $stmt = $conn->prepare("UPDATE funds SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log the restoration
        $log_stmt = $conn->prepare("
            INSERT INTO fund_logs (fund_id, action, changes, created_at) 
            VALUES (?, 'restored', 'Fund restored to active', CURRENT_TIMESTAMP)
        ");
        $log_stmt->execute([$id]);
        
        $conn->commit();
        
        $_SESSION['success'] = "Fund has been restored.";
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error restoring fund: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit();
}

// Get all funds with year information and calculate totals - CORRECTED QUERY
$funds = $conn->query("
    SELECT 
        f.*,
        y.year,
        y.description as year_description,
        f.allotment as total_allotment,
        f.obligated as total_obligated,
        f.disbursed as total_disbursed,
        f.balance as remaining_balance
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.status != 'deleted'
    ORDER BY y.year DESC, f.created_at DESC
")->fetchAll();

// Get deleted funds for separate display
$deleted_funds = $conn->query("
    SELECT 
        f.*,
        y.year,
        y.description as year_description
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.status = 'deleted'
    ORDER BY f.updated_at DESC
")->fetchAll();

// Get fund logs - check if table exists first
$logs = [];
try {
    // Check if fund_logs table exists
    $table_check = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fund_logs'");
    if ($table_check->fetch()) {
        $logs = $conn->query("
            SELECT fl.*, f.fund_name, f.fund_code 
            FROM fund_logs fl
            LEFT JOIN funds f ON fl.fund_id = f.id
            ORDER BY fl.created_at DESC
            LIMIT 50
        ")->fetchAll();
    }
} catch(Exception $e) {
    error_log("Error fetching logs: " . $e->getMessage());
}

// Helper function to safely handle null values
function safe_html($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funds Management - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .card-header.bg-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
        }
        .card-header.bg-info {
            background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%) !important;
        }
        .btn-add {
            background: white;
            color: #667eea;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.3);
        }
        .action-buttons .btn {
            margin: 0 2px;
            padding: 0.25rem 0.5rem;
        }
        .logs-container {
            max-height: 300px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }
        .log-item {
            padding: 10px;
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
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
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
        .deleted-row {
            background-color: #fff3f3 !important;
        }
        .balance-positive {
            color: #28a745;
            font-weight: 600;
        }
        .balance-negative {
            color: #dc3545;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid py-4">
        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <h2>Funds Management</h2>
                <p class="text-muted">Manage your funds and allotments</p>
            </div>
            <div class="col-md-6 text-end">
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add New Fund
                </a>
            </div>
        </div>
        
        <!-- Active Funds Card -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Active Funds</h5>
                <span class="badge bg-light text-dark"><?php echo count($funds); ?> funds</span>
            </div>
            <div class="card-body">
                <table class="table table-striped" id="fundsTable">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Fund Name</th>
                            <th>Fund Code</th>
                            <th>Source</th>
                            <th>Allotment</th>
                            <th>Obligated</th>
                            <th>Disbursed</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($funds as $fund): ?>
                        <tr>
                            <td>
                                <span class="badge bg-info"><?php echo safe_html($fund['year']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo safe_html($fund['fund_name']); ?></strong>
                            </td>
                            <td>
                                <code><?php echo safe_html($fund['fund_code']); ?></code>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo safe_html($fund['fund_source'] ?? 'GOP'); ?></span>
                            </td>
                            <td>₱<?php echo number_format($fund['total_allotment'] ?? 0, 2); ?></td>
                            <td>₱<?php echo number_format($fund['total_obligated'] ?? 0, 2); ?></td>
                            <td>₱<?php echo number_format($fund['total_disbursed'] ?? 0, 2); ?></td>
                            <td>
                                <span class="<?php echo ($fund['remaining_balance'] ?? 0) < 0 ? 'balance-negative' : 'balance-positive'; ?>">
                                    ₱<?php echo number_format($fund['remaining_balance'] ?? 0, 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $fund['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst(safe_html($fund['status'])); ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <a href="view.php?id=<?php echo $fund['id']; ?>" class="btn btn-sm btn-info" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="../allotments/index.php?fund_id=<?php echo $fund['id']; ?>" class="btn btn-sm btn-success" title="View Allotments">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $fund['id']; ?>, '<?php echo safe_html($fund['fund_name']); ?>')" 
                                   class="btn btn-sm btn-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($funds)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="fas fa-folder-open fa-3x mb-3"></i>
                                <p>No active funds found. Click "Add New Fund" to create one.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Deleted Funds Card -->
        <?php if (!empty($deleted_funds)): ?>
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-trash-alt me-2"></i>Deleted Funds</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Fund Name</th>
                                <th>Fund Code</th>
                                <th>Source</th>
                                <th>Deleted Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($deleted_funds as $fund): ?>
                            <tr class="deleted-row">
                                <td><span class="badge bg-secondary"><?php echo safe_html($fund['year']); ?></span></td>
                                <td><?php echo safe_html($fund['fund_name']); ?></td>
                                <td><code><?php echo safe_html($fund['fund_code']); ?></code></td>
                                <td><span class="badge bg-secondary"><?php echo safe_html($fund['fund_source'] ?? 'GOP'); ?></span></td>
                                <td><small><?php echo date('M d, Y', strtotime($fund['updated_at'] ?? 'now')); ?></small></td>
                                <td>
                                    <a href="?restore=1&id=<?php echo $fund['id']; ?>" class="btn btn-sm btn-success" title="Restore" onclick="return confirm('Restore this fund?')">
                                        <i class="fas fa-undo"></i> Restore
                                    </a>
                                    <a href="javascript:void(0);" onclick="confirmPermanentDelete(<?php echo $fund['id']; ?>, '<?php echo safe_html($fund['fund_name']); ?>')" 
                                       class="btn btn-sm btn-danger" title="Permanently Delete">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Activity Logs Card -->
        <?php if (!empty($logs)): ?>
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity Logs</h5>
            </div>
            <div class="card-body">
                <div class="logs-container">
                    <?php foreach($logs as $log): ?>
                    <div class="log-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="log-action <?php echo $log['action']; ?>"><?php echo strtoupper($log['action']); ?></span>
                                <strong><?php echo safe_html($log['fund_name'] ?? 'Unknown'); ?></strong> (<?php echo safe_html($log['fund_code'] ?? 'N/A'); ?>)
                            </div>
                            <small class="log-time">
                                <i class="far fa-clock"></i> <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                            </small>
                        </div>
                        <?php if (!empty($log['changes'])): ?>
                        <div class="mt-2 small text-muted">
                            <?php echo nl2br(safe_html($log['changes'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete fund: <strong id="deleteFundName"></strong>?</p>
                    <p class="text-danger small">
                        <i class="fas fa-exclamation-triangle"></i> 
                        If this fund has transactions, it will be soft deleted (hidden from main list).
                        If it has no transactions, it will be permanently deleted.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Permanent Delete Confirmation Modal -->
    <div class="modal fade" id="permanentDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirm Permanent Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete fund: <strong id="permanentDeleteFundName"></strong>?</p>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>This action cannot be undone!</strong> All related data will be permanently removed.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmPermanentDeleteBtn" class="btn btn-danger">Permanently Delete</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#fundsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25
            });
        });
        
        function confirmDelete(id, fundName) {
            document.getElementById('deleteFundName').textContent = fundName;
            document.getElementById('confirmDeleteBtn').href = '?delete=1&id=' + id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        function confirmPermanentDelete(id, fundName) {
            document.getElementById('permanentDeleteFundName').textContent = fundName;
            document.getElementById('confirmPermanentDeleteBtn').href = '?permanent_delete=1&id=' + id;
            new bootstrap.Modal(document.getElementById('permanentDeleteModal')).show();
        }
    </script>
</body>
</html>