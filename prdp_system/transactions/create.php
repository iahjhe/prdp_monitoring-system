<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// First, let's check what columns actually exist in the transactions table
$columns = [];
try {
    $col_stmt = $conn->query("PRAGMA table_info(transactions)");
    $column_info = $col_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($column_info as $col) {
        $columns[] = $col['name'];
    }
    error_log("Existing columns: " . implode(", ", $columns));
} catch(Exception $e) {
    error_log("Error checking columns: " . $e->getMessage());
}

// Get active funds
$funds = $conn->query("
    SELECT f.*, y.year 
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.status = 'active' AND y.is_active = 1
    ORDER BY y.year DESC, f.fund_code
")->fetchAll();

// Get components
$components = $conn->query("SELECT * FROM components ORDER BY component_code")->fetchAll();

// Get account titles
$account_titles = $conn->query("SELECT * FROM account_titles ORDER BY uacs_code")->fetchAll();

// Months array
$months = [
    'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE',
    'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'
];

// Unit options
$unit_options = [
    'InfoACE Unit',
    'Institutional Development Unit',
    'MEL Unit',
    'Procurement Unit',
    'SES Unit',
    'Others'
];

// Category options
$category_options = [
    'CO',
    'IOC',
    'IOC - Donation',
    'Trainings'
];

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn->beginTransaction();
        
        // Generate ASA No. if not provided
        $asa_no = $_POST['asa_no'] ?: 'ASA No. ' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Handle unit selection
        $unit = $_POST['unit'];
        if ($unit == 'Others' && !empty($_POST['unit_other'])) {
            $unit = $_POST['unit_other'];
        }
        
        // Get account title details if selected
        $uacs_code = null;
        if (!empty($_POST['account_title_id'])) {
            $acc_stmt = $conn->prepare("SELECT uacs_code FROM account_titles WHERE id = ?");
            $acc_stmt->execute([$_POST['account_title_id']]);
            $account = $acc_stmt->fetch();
            $uacs_code = $account ? $account['uacs_code'] : null;
        }
        
        // Calculate adjusted obligation
        $obligation = !empty($_POST['obligation']) ? $_POST['obligation'] : 0;
        $adjustment = !empty($_POST['adjustment']) ? $_POST['adjustment'] : 0;
        $adjusted_obligation = $obligation - $adjustment;
        
        // Build the INSERT query dynamically based on existing columns
        $fields = [];
        $placeholders = [];
        $values = [];
        
        // Map form fields to database columns
        $field_map = [
            'fund_id' => 'fund_id',
            'month' => 'month',
            'date_transaction' => 'date_transaction',
            'asa_no' => 'asa_no',
            'pap_code' => 'pap_code',
            'rc_code' => 'rc_code',
            'ors_no' => 'ors_no',
            'fund_source' => 'fund_source',
            'payee' => 'payee',
            'component_id' => 'component_id',
            'component_no' => 'component_no',
            'unit' => 'unit',
            'category' => 'category',
            'account_title_id' => 'account_title_id',
            'uacs_code' => 'uacs_code',
            'particulars' => 'particulars',
            'obligation' => 'obligation',
            'adjustment' => 'adjustment',
            'adjusted_obligation' => 'adjusted_obligation',
            'gop_amount' => 'gop_amount',
            'lp_amount' => 'lp_amount',
            'status' => 'status',
            'unpaid_obligation' => 'unpaid_obligation',
            'date_input_engas' => 'date_input_engas',
            'box_d_of_dv' => 'box_d_of_dv',
            'dv_received_for_lddap' => 'dv_received_for_lddap',
            'lddap_no' => 'lddap_no',
            'iplan_11' => 'iplan_11',
            'iplan_12' => 'iplan_12',
            'ibuild_21' => 'ibuild_21',
            'ibuild_22' => 'ibuild_22',
            'ireap_31' => 'ireap_31',
            'ireap_32' => 'ireap_32',
            'isupport' => 'isupport',
            'sre' => 'sre'
        ];
        
        // Special handling for mo_no (month number)
        if (in_array('mo_no', $columns)) {
            $field_map['mo_no'] = 'mo_no';
        }
        
        // Build the query dynamically
        foreach ($field_map as $form_field => $db_field) {
            if (in_array($db_field, $columns)) {
                $fields[] = $db_field;
                $placeholders[] = '?';
                
                // Get the value based on form field
                if ($form_field == 'mo_no') {
                    $values[] = !empty($_POST['mo_no']) ? $_POST['mo_no'] : null;
                } elseif ($form_field == 'fund_source') {
                    $values[] = 'GOP'; // Default value since we removed it from form
                } elseif ($form_field == 'unpaid_obligation') {
                    $values[] = $adjusted_obligation;
                } elseif ($form_field == 'uacs_code') {
                    $values[] = $uacs_code;
                } elseif (in_array($form_field, ['obligation', 'adjustment', 'gop_amount', 'lp_amount', 
                          'iplan_11', 'iplan_12', 'ibuild_21', 'ibuild_22', 'ireap_31', 'ireap_32', 
                          'isupport', 'sre'])) {
                    $values[] = !empty($_POST[$form_field]) ? $_POST[$form_field] : 0;
                } elseif ($form_field == 'adjusted_obligation') {
                    $values[] = $adjusted_obligation;
                } elseif ($form_field == 'status') {
                    $values[] = !empty($_POST['status']) ? $_POST['status'] : 'pending';
                } elseif ($form_field == 'unit') {
                    $values[] = !empty($unit) ? $unit : null;
                } else {
                    $values[] = !empty($_POST[$form_field]) ? $_POST[$form_field] : null;
                }
            }
        }
        
        // Create and execute the dynamic INSERT query
        $sql = "INSERT INTO transactions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        error_log("Dynamic SQL: " . $sql);
        error_log("Values: " . print_r($values, true));
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
        
        $transaction_id = $conn->lastInsertId();
        
        // Update fund totals only if fund_id is provided and obligation > 0
        if (!empty($_POST['fund_id']) && $adjusted_obligation > 0) {
            $stmt = $conn->prepare("
                UPDATE funds 
                SET obligated = obligated + ?,
                    balance = balance - ?
                WHERE id = ?
            ");
            $stmt->execute([$adjusted_obligation, $adjusted_obligation, $_POST['fund_id']]);
        }
        
        $conn->commit();
        
        $_SESSION['success'] = "Transaction recorded successfully. ASA No: " . $asa_no;
        header("Location: index.php");
        exit();
        
    } catch(Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error recording transaction: " . $e->getMessage();
        error_log("Transaction error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Transaction - PRDP Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .section-header {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
            font-weight: 600;
        }
        .form-label {
            font-weight: 500;
            color: #495057;
        }
        .pr-2 {
            padding-right: 1rem !important;
        }
        .fund-year-display {
            background-color: #e9ecef;
            font-weight: 500;
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
                
                <!-- Debug info - remove in production -->
                <div class="alert alert-info small">
                    <strong>Debug:</strong> Database columns: <?php echo implode(', ', $columns); ?>
                </div>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Record New Transaction</h4>
                        <small>Based on PRDP Monitoring Format</small>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="transactionForm">
                            
                            <!-- Basic Information Section -->
                            <div class="section-header">
                                <i class="fas fa-info-circle me-2"></i>BASIC INFORMATION
                            </div>
                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Fund Year</label>
                                    <input type="text" class="form-control fund-year-display" name="fund_year" 
                                           id="fund_year" readonly placeholder="Auto-filled">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Month No.</label>
                                    <input type="text" class="form-control" name="mo_no" id="mo_no" 
                                           placeholder="Auto-filled" readonly>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Month</label>
                                    <select class="form-control" name="month" id="month">
                                        <option value="">Select Month</option>
                                        <?php foreach($months as $m): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == strtoupper(date('F')) ? 'selected' : ''; ?>>
                                            <?php echo $m; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Date</label>
                                    <input type="date" class="form-control" name="date_transaction" 
                                           id="date_transaction" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">ASA No.</label>
                                    <input type="text" class="form-control" name="asa_no" 
                                           placeholder="Auto-generated if blank">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">PAP</label>
                                    <input type="text" class="form-control" name="pap_code" 
                                           placeholder="e.g., 310500300010000">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">RC</label>
                                    <input type="text" class="form-control" name="rc_code" 
                                           placeholder="e.g., 05-001-03-00014-24-01">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">ORS No.</label>
                                    <input type="text" class="form-control" name="ors_no" 
                                           placeholder="e.g., 02-02101151-2025-03-000001">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Fund</label>
                                    <select class="form-control select2" name="fund_id">
                                        <option value="">Select Fund (Optional)</option>
                                        <?php foreach($funds as $fund): ?>
                                        <option value="<?php echo $fund['id']; ?>">
                                            <?php echo htmlspecialchars($fund['year'] . ' - ' . $fund['fund_code'] . ' - ' . $fund['fund_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Payee Information -->
                            <div class="section-header mt-4">
                                <i class="fas fa-user me-2"></i>PAYEE INFORMATION
                            </div>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Payee</label>
                                    <input type="text" class="form-control" name="payee" 
                                           placeholder="e.g., ARNEL V. GAGUJAS">
                                </div>
                            </div>
                            
                            <!-- Component Information -->
                            <div class="section-header mt-4">
                                <i class="fas fa-puzzle-piece me-2"></i>COMPONENT INFORMATION
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Component</label>
                                    <select class="form-control select2" name="component_id">
                                        <option value="">Select Component (Optional)</option>
                                        <?php foreach($components as $comp): ?>
                                        <option value="<?php echo $comp['id']; ?>">
                                            <?php echo htmlspecialchars($comp['component_code'] . ' - ' . $comp['component_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3 pr-2">
                                    <label class="form-label">Component No.</label>
                                    <input type="text" class="form-control" name="component_no" 
                                           placeholder="e.g., 1.1, 2.1" style="padding-right: 20px;">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Unit</label>
                                    <select class="form-control" name="unit" id="unit">
                                        <option value="">Select Unit (Optional)</option>
                                        <?php foreach($unit_options as $option): ?>
                                        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3" id="unit_other_container" style="display: none;">
                                    <label class="form-label">Specify Unit</label>
                                    <input type="text" class="form-control" name="unit_other" 
                                           placeholder="Please specify">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-control" name="category">
                                        <option value="">Select Category (Optional)</option>
                                        <?php foreach($category_options as $option): ?>
                                        <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Account Title</label>
                                    <select class="form-control select2" name="account_title_id">
                                        <option value="">Select Account Title (Optional)</option>
                                        <?php foreach($account_titles as $acc): ?>
                                        <option value="<?php echo $acc['id']; ?>" data-uacs="<?php echo $acc['uacs_code']; ?>">
                                            <?php echo htmlspecialchars($acc['uacs_code'] . ' - ' . $acc['account_title']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">UACS Code</label>
                                    <input type="text" class="form-control" id="uacs_code" name="uacs_code" readonly 
                                           placeholder="Will auto-populate from account title">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Particulars</label>
                                    <textarea class="form-control" name="particulars" rows="2" 
                                              placeholder="Enter transaction details"></textarea>
                                </div>
                            </div>
                            
                            <!-- Financial Details -->
                            <div class="section-header mt-4">
                                <i class="fas fa-calculator me-2"></i>FINANCIAL DETAILS
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Obligation (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="obligation" 
                                           id="obligation" value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Adjustment (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="adjustment" 
                                           id="adjustment" value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Adjusted Obligation (₱)</label>
                                    <input type="text" class="form-control" id="adjusted_obligation_display" 
                                           readonly placeholder="0.00">
                                    <input type="hidden" name="adjusted_obligation" id="adjusted_obligation">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">GOP Amount (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="gop_amount" value="0">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">LP Amount (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="lp_amount" value="0">
                                </div>
                            </div>
                            
                            <!-- Disbursement Information -->
                            <div class="section-header mt-4">
                                <i class="fas fa-hand-holding-usd me-2"></i>DISBURSEMENT INFORMATION
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-control" name="status">
                                        <option value="pending">Pending</option>
                                        <option value="paid">Paid</option>
                                        <option value="partial">Partial</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Date of Input (eNGAS)</label>
                                    <input type="date" class="form-control" name="date_input_engas">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">BOX D OF DV</label>
                                    <input type="text" class="form-control" name="box_d_of_dv">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">DV Received for LDDAP</label>
                                    <input type="text" class="form-control" name="dv_received_for_lddap">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">LDDAP No.</label>
                                    <input type="text" class="form-control" name="lddap_no">
                                </div>
                            </div>
                            
                            <!-- Component-Specific Allocations (Summary) -->
                            <div class="section-header mt-4">
                                <i class="fas fa-chart-pie me-2"></i>COMPONENT ALLOCATIONS
                            </div>
                            <div class="row">
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-PLAN (1.1)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="iplan_11" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-PLAN (1.2)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="iplan_12" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-BUILD (2.1)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="ibuild_21" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-BUILD (2.2)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="ibuild_22" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-REAP (3.1)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="ireap_31" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-REAP (3.2)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="ireap_32" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">I-SUPPORT</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="isupport" value="0">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">SRE</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="sre" value="0">
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <hr class="my-4">
                            <div class="row">
                                <div class="col-md-12 text-center">
                                    <button type="submit" class="btn btn-primary btn-lg px-5">
                                        <i class="fas fa-save me-2"></i>Save Transaction
                                    </button>
                                    <a href="index.php" class="btn btn-secondary btn-lg px-5">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                            
                        </form>
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
            // Initialize Select2
            $('.select2').select2({
                width: '100%'
            });
            
            // Auto-populate UACS code when account title is selected
            $('select[name="account_title_id"]').change(function() {
                var selected = $(this).find('option:selected');
                var uacs = selected.data('uacs');
                $('#uacs_code').val(uacs || '');
            });
            
            // Function to update fund year and month no based on selected date
            function updateFundYearAndMonthNo() {
                var dateStr = $('#date_transaction').val();
                if (dateStr) {
                    var date = new Date(dateStr);
                    var year = date.getFullYear();
                    var month = date.getMonth() + 1; // JavaScript months are 0-indexed
                    
                    // Update fund year
                    $('#fund_year').val(year);
                    
                    // Update month no.
                    $('#mo_no').val(month);
                    
                    // Update month dropdown if not already selected
                    var monthNames = [
                        'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE',
                        'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'
                    ];
                    var selectedMonth = monthNames[month - 1];
                    
                    // Only auto-select if current value is empty
                    if ($('select[name="month"]').val() === '') {
                        $('select[name="month"]').val(selectedMonth);
                    }
                }
            }
            
            // Initial update on page load
            updateFundYearAndMonthNo();
            
            // Update when date changes
            $('#date_transaction').change(function() {
                updateFundYearAndMonthNo();
            });
            
            // Calculate adjusted obligation
            function calculateAdjusted() {
                var obligation = parseFloat($('input[name="obligation"]').val()) || 0;
                var adjustment = parseFloat($('input[name="adjustment"]').val()) || 0;
                var adjusted = obligation - adjustment;
                
                $('#adjusted_obligation_display').val(adjusted.toFixed(2));
                $('input[name="adjusted_obligation"]').val(adjusted);
            }
            
            $('input[name="obligation"], input[name="adjustment"]').on('input', calculateAdjusted);
            calculateAdjusted();
            
            // Auto-populate component number based on selected component
            $('select[name="component_id"]').change(function() {
                var text = $(this).find('option:selected').text();
                if (text && text !== 'Select Component (Optional)') {
                    var code = text.split(' - ')[0];
                    $('input[name="component_no"]').val(code);
                }
            });
            
            // Show/hide "Others" input for unit
            $('#unit').change(function() {
                if ($(this).val() === 'Others') {
                    $('#unit_other_container').show();
                } else {
                    $('#unit_other_container').hide();
                    $('input[name="unit_other"]').val(''); // Clear the value
                }
            });
            
            // Prevent negative values
            $('input[type="number"]').on('keydown', function(e) {
                if (e.key === '-' || e.key === 'e') {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>