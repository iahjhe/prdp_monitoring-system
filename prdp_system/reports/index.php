<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get all years for filter dropdown
$years = $conn->query("SELECT * FROM years ORDER BY year DESC")->fetchAll();

// Get all funds for filter dropdown
$funds = $conn->query("
    SELECT f.*, y.year 
    FROM funds f
    JOIN years y ON f.year_id = y.id
    WHERE f.status = 'active'
    ORDER BY y.year DESC, f.fund_name
")->fetchAll();

// Get filter parameters from URL
$selected_year_id = isset($_GET['year_id']) ? $_GET['year_id'] : null;
$selected_fund_id = isset($_GET['fund_id']) ? $_GET['fund_id'] : null;

// Build filter conditions
$filter_conditions = " WHERE 1=1";
$params = [];

if ($selected_year_id) {
    $filter_conditions .= " AND strftime('%Y', t.date_transaction) = (SELECT year FROM years WHERE id = :year_id)";
    $params[':year_id'] = $selected_year_id;
}

if ($selected_fund_id) {
    $filter_conditions .= " AND t.fund_id = :fund_id";
    $params[':fund_id'] = $selected_fund_id;
}

// Get selected fund details if any
$selected_fund = null;
if ($selected_fund_id) {
    $stmt = $conn->prepare("SELECT f.*, y.year FROM funds f JOIN years y ON f.year_id = y.id WHERE f.id = ?");
    $stmt->execute([$selected_fund_id]);
    $selected_fund = $stmt->fetch();
}

// Get selected year details if any
$selected_year = null;
if ($selected_year_id) {
    $stmt = $conn->prepare("SELECT * FROM years WHERE id = ?");
    $stmt->execute([$selected_year_id]);
    $selected_year = $stmt->fetch();
}

// Get summary statistics from funds table
if ($selected_fund_id) {
    $fund_summary = $selected_fund;
} else {
    $fund_summary = [
        'allotment' => 0,
        'obligated' => 0,
        'disbursed' => 0,
        'balance' => 0
    ];
}

// Get transaction summary - FIXED: removed t.gop_amount and t.lp_amount references
$summary_query = "
    SELECT 
        COALESCE(SUM(t.adjusted_obligation), 0) as total_obligated,
        COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.adjusted_obligation ELSE 0 END), 0) as total_disbursed,
        COUNT(*) as total_transactions,
        COUNT(DISTINCT t.payee) as unique_payees
    FROM transactions t
    $filter_conditions
";
$stmt = $conn->prepare($summary_query);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$summary = $stmt->fetch();

// Get monthly summary for charts
$monthly_data = [];
if ($selected_fund_id || $selected_year_id) {
    $monthly_query = "
        SELECT 
            t.month,
            COALESCE(SUM(t.adjusted_obligation), 0) as total_obligated,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.adjusted_obligation ELSE 0 END), 0) as total_disbursed,
            COUNT(*) as transaction_count
        FROM transactions t
        $filter_conditions
        GROUP BY t.month
        ORDER BY 
            CASE t.month
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
            END
    ";
    $stmt = $conn->prepare($monthly_query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $monthly_data = $stmt->fetchAll();
}

// Get component-wise summary
$component_data = [];
if ($selected_fund_id || $selected_year_id) {
    $comp_query = "
        SELECT 
            c.component_code,
            c.component_name,
            COUNT(t.id) as transaction_count,
            COALESCE(SUM(t.adjusted_obligation), 0) as total_obligated,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.adjusted_obligation ELSE 0 END), 0) as total_disbursed
        FROM components c
        LEFT JOIN transactions t ON c.id = t.component_id $filter_conditions
        GROUP BY c.id
        ORDER BY c.component_code
    ";
    $stmt = $conn->prepare($comp_query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $component_data = $stmt->fetchAll();
}

// Get top payees
$top_payees = [];
if ($selected_fund_id || $selected_year_id) {
    $payee_query = "
        SELECT 
            t.payee,
            COUNT(*) as transaction_count,
            COALESCE(SUM(t.adjusted_obligation), 0) as total_amount
        FROM transactions t
        $filter_conditions
        GROUP BY t.payee
        ORDER BY total_amount DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($payee_query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $top_payees = $stmt->fetchAll();
}

// Get account titles summary
$account_data = [];
if ($selected_fund_id || $selected_year_id) {
    $account_query = "
        SELECT 
            a.uacs_code,
            a.account_title,
            a.category,
            COUNT(t.id) as transaction_count,
            COALESCE(SUM(t.adjusted_obligation), 0) as total_amount
        FROM account_titles a
        LEFT JOIN transactions t ON a.id = t.account_title_id $filter_conditions
        GROUP BY a.id
        HAVING total_amount > 0
        ORDER BY total_amount DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($account_query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $account_data = $stmt->fetchAll();
}

// Get quarterly summary
$quarterly_data = [];
if ($selected_fund_id || $selected_year_id) {
    $quarterly_query = "
        SELECT 
            CASE 
                WHEN t.month IN ('JANUARY', 'FEBRUARY', 'MARCH') THEN 'Q1'
                WHEN t.month IN ('APRIL', 'MAY', 'JUNE') THEN 'Q2'
                WHEN t.month IN ('JULY', 'AUGUST', 'SEPTEMBER') THEN 'Q3'
                WHEN t.month IN ('OCTOBER', 'NOVEMBER', 'DECEMBER') THEN 'Q4'
            END as quarter,
            COALESCE(SUM(t.adjusted_obligation), 0) as total_obligated,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.adjusted_obligation ELSE 0 END), 0) as total_disbursed,
            COUNT(*) as transaction_count
        FROM transactions t
        $filter_conditions
        GROUP BY quarter
        HAVING quarter IS NOT NULL
        ORDER BY quarter
    ";
    $stmt = $conn->prepare($quarterly_query);
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $quarterly_data = $stmt->fetchAll();
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="page-title"><i class="fas fa-chart-pie me-2"></i>PRDP Reports & Analytics Dashboard</h2>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Fiscal Year</label>
                            <select class="form-control select2" name="year_id">
                                <option value="">-- All Years --</option>
                                <?php foreach($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $selected_year_id == $year['id'] ? 'selected' : ''; ?>>
                                    <?php echo $year['year']; ?> - <?php echo htmlspecialchars($year['description']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Fund</label>
                            <select class="form-control select2" name="fund_id">
                                <option value="">-- All Funds --</option>
                                <?php foreach($funds as $fund): ?>
                                <option value="<?php echo $fund['id']; ?>" <?php echo $selected_fund_id == $fund['id'] ? 'selected' : ''; ?>>
                                    <?php echo $fund['year']; ?> - <?php echo htmlspecialchars($fund['fund_name']); ?> (₱<?php echo number_format($fund['allotment'], 0); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($selected_fund_id || $selected_year_id): ?>
        
        <!-- Fund Summary Cards -->
        <?php if ($selected_fund_id): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <strong>Selected Fund:</strong> <?php echo htmlspecialchars($selected_fund['fund_name']); ?> 
                    (FY <?php echo $selected_fund['year']; ?> | Source: <?php echo $selected_fund['fund_source']; ?>)
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white-50 mb-2">Total Allotment</h6>
                                <h3 class="text-white mb-0">₱<?php echo number_format($fund_summary['allotment'], 2); ?></h3>
                            </div>
                            <i class="fas fa-coins fa-3x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white-50 mb-2">Obligated</h6>
                                <h3 class="text-white mb-0">₱<?php echo number_format($fund_summary['obligated'], 2); ?></h3>
                            </div>
                            <i class="fas fa-file-invoice fa-3x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white-50 mb-2">Disbursed</h6>
                                <h3 class="text-white mb-0">₱<?php echo number_format($fund_summary['disbursed'], 2); ?></h3>
                            </div>
                            <i class="fas fa-hand-holding-usd fa-3x text-white-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-white-50 mb-2">Remaining Balance</h6>
                                <h3 class="text-white mb-0">₱<?php echo number_format($fund_summary['balance'], 2); ?></h3>
                            </div>
                            <i class="fas fa-chart-line fa-3x text-white-50"></i>
                        </div>
                        <div class="progress mt-3" style="height: 10px;">
                            <?php $utilization = $fund_summary['allotment'] > 0 ? ($fund_summary['disbursed'] / $fund_summary['allotment']) * 100 : 0; ?>
                            <div class="progress-bar bg-white" style="width: <?php echo $utilization; ?>%"></div>
                        </div>
                        <small class="text-white-50"><?php echo number_format($utilization, 1); ?>% Utilized</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Transaction Summary Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50 mb-2">Total Transactions</h6>
                        <h4 class="text-white mb-0"><?php echo number_format($summary['total_transactions']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50 mb-2">Unique Payees</h6>
                        <h4 class="text-white mb-0"><?php echo number_format($summary['unique_payees']); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50 mb-2">Total Obligated</h6>
                        <h5 class="text-white mb-0">₱<?php echo number_format($summary['total_obligated'], 2); ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50 mb-2">Total Disbursed</h6>
                        <h5 class="text-white mb-0">₱<?php echo number_format($summary['total_disbursed'], 2); ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row mb-4">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Obligation vs Disbursement Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="monthlyChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2 text-info"></i>Component-wise Obligations</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="componentChart" height="300"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2 text-warning"></i>Quarterly Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="quarterlyChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Payees Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Top 10 Payees</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Payee</th>
                                        <th>Transactions</th>
                                        <th>Total Amount</th>
                                        <th>% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_amount = array_sum(array_column($top_payees, 'total_amount'));
                                    foreach($top_payees as $index => $payee): 
                                        $percentage = $total_amount > 0 ? ($payee['total_amount'] / $total_amount) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-<?php echo $index < 3 ? 'warning' : 'secondary'; ?>">#<?php echo $index + 1; ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($payee['payee']); ?></strong></td>
                                        <td><?php echo number_format($payee['transaction_count']); ?></td>
                                        <td>₱<?php echo number_format($payee['total_amount'], 2); ?></td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-<?php echo $index < 3 ? 'warning' : 'info'; ?>" 
                                                     style="width: <?php echo $percentage; ?>%;">
                                                    <?php echo number_format($percentage, 1); ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Titles Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-book me-2 text-info"></i>Top Account Titles</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>UACS Code</th>
                                        <th>Account Title</th>
                                        <th>Category</th>
                                        <th>Transactions</th>
                                        <th>Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($account_data as $acc): ?>
                                    <tr>
                                        <td><code><?php echo $acc['uacs_code']; ?></code></td>
                                        <td><?php echo htmlspecialchars($acc['account_title']); ?></td>
                                        <td><span class="badge bg-info"><?php echo $acc['category']; ?></span></td>
                                        <td><?php echo number_format($acc['transaction_count']); ?></td>
                                        <td>₱<?php echo number_format($acc['total_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Buttons -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <button class="btn btn-success me-2" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print Report
                </button>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-info text-center p-5">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h4>Select Filters to Generate Report</h4>
            <p class="mb-0">Please select a year and/or fund from the dropdowns above to view the reports dashboard.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Charts JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script>
$(document).ready(function() {
    $('.select2').select2({
        width: '100%'
    });
});

<?php if ($selected_fund_id || $selected_year_id): ?>
    
// Monthly Chart
const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
const months = <?php echo json_encode(array_column($monthly_data, 'month')); ?>;
const obligated = <?php echo json_encode(array_column($monthly_data, 'total_obligated')); ?>;
const disbursed = <?php echo json_encode(array_column($monthly_data, 'total_disbursed')); ?>;

new Chart(monthlyCtx, {
    type: 'line',
    data: {
        labels: months.map(m => m.charAt(0) + m.slice(1).toLowerCase()),
        datasets: [{
            label: 'Obligated',
            data: obligated,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.1)',
            tension: 0.4,
            fill: true
        }, {
            label: 'Disbursed',
            data: disbursed,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ₱' + context.raw.toLocaleString(undefined, {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Component Chart
const componentCtx = document.getElementById('componentChart').getContext('2d');
const componentLabels = <?php echo json_encode(array_column($component_data, 'component_code')); ?>;
const componentAmounts = <?php echo json_encode(array_column($component_data, 'total_obligated')); ?>;

new Chart(componentCtx, {
    type: 'bar',
    data: {
        labels: componentLabels,
        datasets: [{
            label: 'Obligated Amount',
            data: componentAmounts,
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            borderColor: 'rgb(54, 162, 235)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '₱' + context.raw.toLocaleString(undefined, {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Quarterly Chart
const quarterlyCtx = document.getElementById('quarterlyChart').getContext('2d');
const quarters = <?php echo json_encode(array_column($quarterly_data, 'quarter')); ?>;
const quarterlyObligated = <?php echo json_encode(array_column($quarterly_data, 'total_obligated')); ?>;

new Chart(quarterlyCtx, {
    type: 'pie',
    data: {
        labels: quarters,
        datasets: [{
            data: quarterlyObligated,
            backgroundColor: [
                'rgba(255, 99, 132, 0.8)',
                'rgba(54, 162, 235, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(75, 192, 192, 0.8)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.raw || 0;
                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                        let percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ₱' + value.toLocaleString() + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

<?php endif; ?>
</script>

<style>
.stat-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    transition: transform 0.3s;
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.stat-card.bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-card.bg-success { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
.stat-card.bg-info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stat-card.bg-warning { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
.stat-card.bg-secondary { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
.text-white-50 { color: rgba(255,255,255,0.8) !important; }
.progress { background-color: rgba(255,255,255,0.3); border-radius: 10px; }
.progress-bar { border-radius: 10px; }
.card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
.card-header { 
    background: white; 
    border-bottom: 1px solid rgba(0,0,0,0.05);
    padding: 20px 25px;
    border-radius: 15px 15px 0 0 !important;
    font-weight: 600;
}
.table thead th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 0.9rem;
}
</style>

<?php include '../includes/footer.php'; ?>