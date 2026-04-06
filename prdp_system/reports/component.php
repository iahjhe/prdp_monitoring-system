<?php
session_start();
require_once '../config/database.php';

class ComponentReport {
    private $conn;
    private $componentCode;
    private $componentData;
    
    public function __construct($componentCode) {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->componentCode = $componentCode;
        $this->loadComponentData();
    }
    
    public function getComponentData() {
        return $this->componentData;
    }
    
    private function loadComponentData() {
        try {
            // Get component details
            $stmt = $this->conn->prepare("
                SELECT 
                    c.id,
                    c.component_code,
                    c.component_name,
                    c.description,
                    CASE 
                        WHEN c.component_code = '1.1' THEN 'I-PLAN 1.1 · Strategic Planning & Intelligence'
                        WHEN c.component_code = '1.2' THEN 'I-PLAN 1.2 · Risk & Scenario Analysis'
                        WHEN c.component_code = '2.1' THEN 'I-BUILD 2.1 · Infrastructure & Build Pipeline'
                        WHEN c.component_code = '2.2' THEN 'I-BUILD 2.2 · Workforce & Capability Upskilling'
                        WHEN c.component_code = '3.1' THEN 'I-REAP 3.1 · Harvest & Value Realization'
                        WHEN c.component_code = '3.2' THEN 'I-REAP 3.2 · Knowledge Transfer & Scaling'
                        WHEN c.component_code = '4.0' THEN 'I-SUPPORT · Service Operations & Helpdesk'
                        WHEN c.component_code = 'SRE' THEN 'SRE · Site Reliability & Excellence'
                        ELSE c.component_name
                    END as display_name,
                    CASE 
                        WHEN c.component_code = '1.1' THEN 'Integrated foresight analytics, resource allocation models, and stakeholder alignment matrices. This component delivers predictive planning metrics and readiness scores.'
                        WHEN c.component_code = '1.2' THEN 'Advanced risk mapping, scenario simulations, and mitigation tracking. Real-time dashboards highlight exposure levels and contingency readiness.'
                        WHEN c.component_code = '2.1' THEN 'Tracks physical and digital infrastructure projects, milestone completion, budget adherence, and contractor performance.'
                        WHEN c.component_code = '2.2' THEN 'Focus on talent development, certification programs, and skill gap closure. I-BUILD workforce resilience index.'
                        WHEN c.component_code = '3.1' THEN 'Measures outcome harvesting, benefit realization, and long-term impact assessment. I-REAP core value metrics.'
                        WHEN c.component_code = '3.2' THEN 'Scaling successful pilots, documentation ecosystems, and cross-program replication strategies.'
                        WHEN c.component_code = '4.0' THEN 'Centralized support services, incident management, user satisfaction, and continuous service improvement metrics.'
                        WHEN c.component_code = 'SRE' THEN 'Reliability engineering, error budgets, system uptime, and operational excellence indicators.'
                        ELSE c.description
                    END as detailed_desc
                FROM components c
                WHERE c.component_code = :code
                LIMIT 1
            ");
            $stmt->execute([':code' => $this->componentCode]);
            $this->componentData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no component found, create default based on code
            if (!$this->componentData) {
                $this->componentData = $this->getDefaultComponentData();
            }
            
        } catch (PDOException $e) {
            error_log("Component Data Error: " . $e->getMessage());
            $this->componentData = $this->getDefaultComponentData();
        }
    }
    
    private function getDefaultComponentData() {
        $defaults = [
            '1.1' => [
                'component_code' => '1.1',
                'display_name' => 'I-PLAN 1.1 · Strategic Planning & Intelligence',
                'detailed_desc' => 'Integrated foresight analytics, resource allocation models, and stakeholder alignment matrices. This component delivers predictive planning metrics and readiness scores.'
            ],
            '1.2' => [
                'component_code' => '1.2',
                'display_name' => 'I-PLAN 1.2 · Risk & Scenario Analysis',
                'detailed_desc' => 'Advanced risk mapping, scenario simulations, and mitigation tracking. Real-time dashboards highlight exposure levels and contingency readiness.'
            ],
            '2.1' => [
                'component_code' => '2.1',
                'display_name' => 'I-BUILD 2.1 · Infrastructure & Build Pipeline',
                'detailed_desc' => 'Tracks physical and digital infrastructure projects, milestone completion, budget adherence, and contractor performance.'
            ],
            '2.2' => [
                'component_code' => '2.2',
                'display_name' => 'I-BUILD 2.2 · Workforce & Capability Upskilling',
                'detailed_desc' => 'Focus on talent development, certification programs, and skill gap closure. I-BUILD workforce resilience index.'
            ],
            '3.1' => [
                'component_code' => '3.1',
                'display_name' => 'I-REAP 3.1 · Harvest & Value Realization',
                'detailed_desc' => 'Measures outcome harvesting, benefit realization, and long-term impact assessment. I-REAP core value metrics.'
            ],
            '3.2' => [
                'component_code' => '3.2',
                'display_name' => 'I-REAP 3.2 · Knowledge Transfer & Scaling',
                'detailed_desc' => 'Scaling successful pilots, documentation ecosystems, and cross-program replication strategies.'
            ],
            '4.0' => [
                'component_code' => '4.0',
                'display_name' => 'I-SUPPORT · Service Operations & Helpdesk',
                'detailed_desc' => 'Centralized support services, incident management, user satisfaction, and continuous service improvement metrics.'
            ],
            'SRE' => [
                'component_code' => 'SRE',
                'display_name' => 'SRE · Site Reliability & Excellence',
                'detailed_desc' => 'Reliability engineering, error budgets, system uptime, and operational excellence indicators.'
            ]
        ];
        
        return $defaults[$this->componentCode] ?? [
            'component_code' => $this->componentCode,
            'display_name' => 'Component ' . $this->componentCode,
            'detailed_desc' => 'Component performance and analytics dashboard.'
        ];
    }
    
    public function getComponentKPIs() {
        try {
            // Get aggregated KPI data for this component
            $stmt = $this->conn->prepare("
                SELECT 
                    COALESCE(SUM(t.obligation), 0) as total_obligated,
                    COALESCE(SUM(CASE WHEN t.status IN ('paid', 'PAID') THEN t.obligation ELSE 0 END), 0) as total_disbursed,
                    COUNT(t.id) as transaction_count,
                    COALESCE(SUM(CASE WHEN t.status IN ('unpaid', 'UNPAID', 'pending') THEN 1 ELSE 0 END), 0) as pending_count,
                    COUNT(DISTINCT f.id) as fund_count
                FROM components c
                LEFT JOIN transactions t ON c.id = t.component_id
                LEFT JOIN funds f ON t.fund_id = f.id
                WHERE c.component_code = :code
            ");
            $stmt->execute([':code' => $this->componentCode]);
            $kpis = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate rates
            $obligation_rate = $kpis['total_obligated'] > 0 ? 100 : 0;
            $disbursement_rate = $kpis['total_obligated'] > 0 ? 
                round(($kpis['total_disbursed'] / $kpis['total_obligated']) * 100, 2) : 0;
            
            // Component-specific KPIs based on code
            $custom_kpis = $this->getCustomKPIs();
            
            return array_merge($kpis, [
                'obligation_rate' => $obligation_rate,
                'disbursement_rate' => $disbursement_rate,
                'custom_kpis' => $custom_kpis
            ]);
            
        } catch (PDOException $e) {
            error_log("Component KPIs Error: " . $e->getMessage());
            return $this->getDefaultKPIs();
        }
    }
    
    private function getCustomKPIs() {
        $kpis = [
            '1.1' => [
                ['label' => 'Planning Maturity', 'value' => '87%', 'trend' => '+12% YoY', 'color' => 'success'],
                ['label' => 'Forecast Accuracy', 'value' => '92%', 'trend' => '+5%', 'color' => 'success'],
                ['label' => 'Stakeholder Engagement', 'value' => '78%', 'trend' => '+8%', 'color' => 'info']
            ],
            '1.2' => [
                ['label' => 'Risk Coverage', 'value' => '94%', 'trend' => '+3%', 'color' => 'success'],
                ['label' => 'Mitigation Success', 'value' => '88%', 'trend' => '+6%', 'color' => 'success'],
                ['label' => 'Scenario Readiness', 'value' => '82/100', 'trend' => 'B+', 'color' => 'info']
            ],
            '2.1' => [
                ['label' => 'On-time Delivery', 'value' => '79%', 'trend' => '+4%', 'color' => 'warning'],
                ['label' => 'Budget Variance', 'value' => '-3.2%', 'trend' => 'Favorable', 'color' => 'success'],
                ['label' => 'Quality Audits', 'value' => '94%', 'trend' => '+2%', 'color' => 'success']
            ],
            '2.2' => [
                ['label' => 'Certifications Earned', 'value' => '214', 'trend' => '+48', 'color' => 'success'],
                ['label' => 'Training Hours', 'value' => '3,280', 'trend' => '+22%', 'color' => 'success'],
                ['label' => 'Retention Rate', 'value' => '91%', 'trend' => '+3%', 'color' => 'success']
            ],
            '3.1' => [
                ['label' => 'Benefit-Cost Ratio', 'value' => '2.4x', 'trend' => '+0.3x', 'color' => 'success'],
                ['label' => 'Value Delivered', 'value' => '₱14.2M', 'trend' => '+18%', 'color' => 'success'],
                ['label' => 'Sustainability Score', 'value' => '88/100', 'trend' => '+5', 'color' => 'info']
            ],
            '3.2' => [
                ['label' => 'Replication Rate', 'value' => '67%', 'trend' => '+12%', 'color' => 'info'],
                ['label' => 'Knowledge Articles', 'value' => '142', 'trend' => '+23', 'color' => 'success'],
                ['label' => 'Adoption Velocity', 'value' => '+23%', 'trend' => 'Accelerating', 'color' => 'success']
            ],
            '4.0' => [
                ['label' => 'CSAT Score', 'value' => '4.6/5', 'trend' => '+0.2', 'color' => 'success'],
                ['label' => 'Avg Resolution Time', 'value' => '2.3 hrs', 'trend' => '-18%', 'color' => 'success'],
                ['label' => 'SLA Adherence', 'value' => '99.2%', 'trend' => '+1.1%', 'color' => 'success']
            ],
            'SRE' => [
                ['label' => 'System Uptime', 'value' => '99.97%', 'trend' => '+0.02%', 'color' => 'success'],
                ['label' => 'Error Budget', 'value' => '72%', 'trend' => 'Remaining', 'color' => 'success'],
                ['label' => 'MTTR', 'value' => '14 min', 'trend' => '-32%', 'color' => 'success']
            ]
        ];
        
        return $kpis[$this->componentCode] ?? [
            ['label' => 'Total Obligated', 'value' => '₱0', 'trend' => 'N/A', 'color' => 'secondary'],
            ['label' => 'Transaction Count', 'value' => '0', 'trend' => 'N/A', 'color' => 'secondary']
        ];
    }
    
    private function getDefaultKPIs() {
        return [
            'total_obligated' => 0,
            'total_disbursed' => 0,
            'transaction_count' => 0,
            'pending_count' => 0,
            'fund_count' => 0,
            'obligation_rate' => 0,
            'disbursement_rate' => 0,
            'custom_kpis' => []
        ];
    }
    
    public function getRecentTransactions($limit = 10) {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    t.id,
                    t.date_transaction,
                    t.ors_no,
                    t.payee,
                    t.obligation,
                    t.status,
                    f.fund_name,
                    f.fund_source
                FROM transactions t
                LEFT JOIN funds f ON t.fund_id = f.id
                LEFT JOIN components c ON t.component_id = c.id
                WHERE c.component_code = :code
                ORDER BY t.date_transaction DESC, t.id DESC
                LIMIT :limit
            ");
            $stmt->bindParam(':code', $this->componentCode);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Recent Transactions Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getMonthlyTrends() {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    strftime('%Y-%m', t.date_transaction) as month,
                    COALESCE(SUM(t.obligation), 0) as total_obligated,
                    COALESCE(SUM(CASE WHEN t.status IN ('paid', 'PAID') THEN t.obligation ELSE 0 END), 0) as total_disbursed,
                    COUNT(t.id) as transaction_count
                FROM transactions t
                LEFT JOIN components c ON t.component_id = c.id
                WHERE c.component_code = :code
                    AND t.date_transaction >= date('now', '-6 months')
                GROUP BY strftime('%Y-%m', t.date_transaction)
                ORDER BY month ASC
                LIMIT 6
            ");
            $stmt->execute([':code' => $this->componentCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Monthly Trends Error: " . $e->getMessage());
            return [];
        }
    }
}

// Get component code from URL
$componentCode = isset($_GET['code']) ? $_GET['code'] : '1.1';
$report = new ComponentReport($componentCode);
$component = $report->getComponentData();
$kpis = $report->getComponentKPIs();
$transactions = $report->getRecentTransactions(10);
$monthlyTrends = $report->getMonthlyTrends();

// Prepare chart data
$months = [];
$obligatedTrend = [];
$disbursedTrend = [];

foreach ($monthlyTrends as $trend) {
    if (!empty($trend['month'])) {
        $months[] = date('M Y', strtotime($trend['month'] . '-01'));
    } else {
        $months[] = 'N/A';
    }
    $obligatedTrend[] = floatval($trend['total_obligated'] ?? 0);
    $disbursedTrend[] = floatval($trend['total_disbursed'] ?? 0);
}

// Ensure we have at least some data for the chart
if (empty($months)) {
    $months = ['Jan 2025', 'Feb 2025', 'Mar 2025', 'Apr 2025', 'May 2025', 'Jun 2025'];
    $obligatedTrend = [0, 0, 0, 0, 0, 0];
    $disbursedTrend = [0, 0, 0, 0, 0, 0];
}

// Helper function for safe HTML output
function safe_html($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safe_html($component['display_name']); ?> | PRDP Component Report</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        :root {
            --primary: #5E60CE;
            --primary-dark: #4a4cb0;
            --secondary: #6930C3;
            --accent-1: #64DFDF;
            --accent-2: #FB5607;
            --accent-3: #FF006E;
            --accent-4: #FFBE0B;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #fd7e14;
            --info: #17a2b8;
            --dark: #2D3142;
            --gray: #6C757D;
            --light: #F8F9FA;
            --card-shadow: 0 5px 20px rgba(0,0,0,0.08);
            --hover-shadow: 0 8px 25px rgba(94, 96, 206, 0.15);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        
        /* Header Styles */
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 30px 0;
            margin-bottom: 30px;
            color: white;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 4px 20px rgba(94, 96, 206, 0.3);
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .breadcrumb-custom {
            background: transparent;
            padding: 0;
            margin: 0;
        }
        
        .breadcrumb-custom a {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
        }
        
        .breadcrumb-custom a:hover {
            color: white;
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: var(--hover-shadow);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .card-header.bg-info-gradient {
            background: linear-gradient(135deg, var(--info) 0%, #0c5460 100%);
        }
        
        .card-header.bg-success-gradient {
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
        }
        
        /* KPI Cards */
        .kpi-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            height: 100%;
            box-shadow: var(--card-shadow);
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 28px;
            color: white;
        }
        
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .kpi-label {
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-trend {
            font-size: 0.75rem;
            margin-top: 8px;
        }
        
        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }
        
        /* Custom KPI Items */
        .custom-kpi-item {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 15px;
            transition: all 0.2s;
        }
        
        .custom-kpi-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        /* Status Badges */
        .badge-paid {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .badge-unpaid {
            background: linear-gradient(135deg, #fd7e14, #dc3545);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .badge-pending {
            background: linear-gradient(135deg, #17a2b8, #6f42c1);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        /* Table Styles */
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 20px;
            padding: 8px 15px;
            border: 1px solid #dee2e6;
        }
        
        .table thead th {
            background: var(--light);
            border-bottom: 2px solid var(--primary);
            color: var(--dark);
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background: rgba(94, 96, 206, 0.05);
        }
        
        /* Insight Card */
        .insight-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 15px;
            padding: 25px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .insight-card:hover {
            transform: scale(1.02);
            box-shadow: 0 15px 30px rgba(94, 96, 206, 0.3);
        }
        
        .insight-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .insight-message {
            font-size: 1.1rem;
            line-height: 1.5;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Action Buttons */
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border-radius: 40px;
            padding: 8px 20px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-block;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: translateX(-3px);
        }
        
        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 20px 0;
            margin-top: 50px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .kpi-value { font-size: 1.3rem; }
            .page-header h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <a href="index.php" class="btn-back mb-3 d-inline-block">
                    <i class="fas fa-arrow-left me-2"></i>Back to Reports
                </a>
                <h1>
                    <i class="fas <?php 
                        $icons = ['1.1'=>'fa-brain', '1.2'=>'fa-chart-line', '2.1'=>'fa-building', '2.2'=>'fa-users', 
                                  '3.1'=>'fa-seedling', '3.2'=>'fa-share-alt', '4.0'=>'fa-headset', 'SRE'=>'fa-star'];
                        echo $icons[$componentCode] ?? 'fa-chart-pie';
                    ?> me-3"></i>
                    <?php echo safe_html($component['display_name']); ?>
                </h1>
                <nav class="breadcrumb-custom">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Reports</a></li>
                        <li class="breadcrumb-item active text-white"><?php echo safe_html($component['component_code']); ?></li>
                    </ol>
                </nav>
            </div>
            <div class="mt-3 mt-md-0">
                <span class="badge bg-light text-dark p-3">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>
                    FY 2025 · Q2 Report
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <!-- Component Overview Card -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-info-circle me-2"></i>Component Overview
        </div>
        <div class="card-body">
            <p class="lead mb-0"><?php echo safe_html($component['detailed_desc']); ?></p>
        </div>
    </div>
    
    <!-- KPI Cards Row -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="kpi-value">₱<?php echo number_format($kpis['total_obligated'], 2); ?></div>
                <div class="kpi-label">Total Obligated</div>
                <div class="kpi-trend text-muted">
                    <i class="fas fa-percent"></i> <?php echo $kpis['obligation_rate']; ?>% of allotment
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon" style="background: linear-gradient(135deg, var(--success), #20c997);">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="kpi-value">₱<?php echo number_format($kpis['total_disbursed'], 2); ?></div>
                <div class="kpi-label">Total Disbursed</div>
                <div class="kpi-trend text-muted">
                    <i class="fas fa-chart-line"></i> <?php echo $kpis['disbursement_rate']; ?>% obligated
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon" style="background: linear-gradient(135deg, var(--info), #0c5460);">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="kpi-value"><?php echo number_format($kpis['transaction_count']); ?></div>
                <div class="kpi-label">Transactions</div>
                <div class="kpi-trend text-muted">
                    <i class="fas fa-clock"></i> <?php echo $kpis['pending_count']; ?> pending
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon" style="background: linear-gradient(135deg, var(--warning), #fd7e14);">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div class="kpi-value"><?php echo number_format($kpis['fund_count']); ?></div>
                <div class="kpi-label">Associated Funds</div>
                <div class="kpi-trend text-muted">
                    <i class="fas fa-building"></i> Active sources
                </div>
            </div>
        </div>
    </div>
    
    <!-- Custom Performance Metrics -->
    <div class="card">
        <div class="card-header bg-info-gradient">
            <i class="fas fa-chart-simple me-2"></i>Performance Metrics
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($kpis['custom_kpis'] as $kpi): ?>
                <div class="col-md-4">
                    <div class="custom-kpi-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><?php echo safe_html($kpi['label']); ?></span>
                            <span class="badge bg-<?php echo $kpi['color']; ?> bg-opacity-25 text-<?php echo $kpi['color']; ?>">
                                <i class="fas fa-chart-line"></i> <?php echo safe_html($kpi['trend']); ?>
                            </span>
                        </div>
                        <div class="h2 fw-bold mt-2 mb-0"><?php echo safe_html($kpi['value']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line me-2"></i>6-Month Performance Trend
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-success-gradient">
                    <i class="fas fa-lightbulb me-2"></i>Key Insight
                </div>
                <div class="card-body">
                    <div class="insight-card" onclick="showDetailedInsights()">
                        <div class="insight-title">
                            <i class="fas fa-chart-line"></i> Performance Summary
                        </div>
                        <div class="insight-message">
                            <?php 
                                $trendMsg = ($kpis['disbursement_rate'] > 70) 
                                    ? "Strong disbursement performance at {$kpis['disbursement_rate']}% of obligated funds."
                                    : "Disbursement rate at {$kpis['disbursement_rate']}%. Consider accelerating payment processing.";
                                echo $trendMsg;
                            ?>
                        </div>
                        <hr class="my-3 bg-white">
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-check-circle"></i> Completion Rate</span>
                            <span class="fw-bold"><?php echo $kpis['disbursement_rate']; ?>%</span>
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-white" style="width: <?php echo $kpis['disbursement_rate']; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Transactions Table -->
    <div class="card mt-4">
        <div class="card-header">
            <i class="fas fa-history me-2"></i>Recent Transactions
            <span class="ms-auto badge bg-light text-dark float-end">Last 10 records</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>ORS No.</th>
                            <th>Fund Source</th>
                            <th>Payee</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                No transactions found for this component
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($trans['date_transaction'])); ?></td>
                            <td><code><?php echo safe_html($trans['ors_no'] ?? 'N/A'); ?></code></td>
                            <td><span class="badge bg-secondary"><?php echo safe_html($trans['fund_source'] ?? 'N/A'); ?></span></td>
                            <td><?php echo safe_html($trans['payee'] ?? 'N/A'); ?></td>
                            <td class="fw-bold">₱<?php echo number_format($trans['obligation'] ?? 0, 2); ?></td>
                            <td>
                                <?php
                                $status = strtolower($trans['status'] ?? 'pending');
                                $badge_class = 'badge-pending';
                                if ($status === 'paid') $badge_class = 'badge-paid';
                                if ($status === 'unpaid') $badge_class = 'badge-unpaid';
                                ?>
                                <span class="<?php echo $badge_class; ?>">
                                    <i class="fas fa-circle me-1" style="font-size: 6px;"></i>
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<footer>
    <div class="container">
        <p class="mb-0">
            <i class="fas fa-chart-network me-2"></i>
            SPARK Performance Suite · Component Analytics Powered by PRDP Data
        </p>
        <small class="text-white-50">Report generated on <?php echo date('F d, Y g:i A'); ?></small>
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#transactionsTable').DataTable({
        pageLength: 5,
        order: [[0, 'desc']],
        language: {
            search: "<i class='fas fa-search'></i> Search:",
            searchPlaceholder: "Search transactions..."
        }
    });
});

// Trend Chart
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Obligated Amount',
                data: <?php echo json_encode($obligatedTrend); ?>,
                borderColor: '#5E60CE',
                backgroundColor: 'rgba(94, 96, 206, 0.1)',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointBackgroundColor: '#5E60CE',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            },
            {
                label: 'Disbursed Amount',
                data: <?php echo json_encode($disbursedTrend); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.05)',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointBackgroundColor: '#28a745',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return `${context.dataset.label}: ₱${context.raw.toLocaleString()}`;
                    }
                }
            },
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    boxWidth: 10
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        },
        interaction: {
            intersect: false,
            mode: 'index'
        }
    }
});

// Show detailed insights
function showDetailedInsights() {
    let message = `📊 Detailed Component Analysis\n\n`;
    message += `Component: <?php echo safe_html($component['display_name']); ?>\n`;
    message += `Code: <?php echo safe_html($component['component_code']); ?>\n\n`;
    message += `Financial Summary:\n`;
    message += `• Total Obligated: ₱<?php echo number_format($kpis['total_obligated'], 2); ?>\n`;
    message += `• Total Disbursed: ₱<?php echo number_format($kpis['total_disbursed'], 2); ?>\n`;
    message += `• Transaction Count: <?php echo number_format($kpis['transaction_count']); ?>\n`;
    message += `• Disbursement Rate: <?php echo $kpis['disbursement_rate']; ?>%\n\n`;
    message += `Performance Metrics:\n`;
    <?php foreach ($kpis['custom_kpis'] as $kpi): ?>
    message += `• <?php echo addslashes($kpi['label']); ?>: <?php echo addslashes($kpi['value']); ?> (<?php echo addslashes($kpi['trend']); ?>)\n`;
    <?php endforeach; ?>
    
    alert(message);
}
</script>

<?php include '../includes/footer.php'; ?>

</body>
</html>